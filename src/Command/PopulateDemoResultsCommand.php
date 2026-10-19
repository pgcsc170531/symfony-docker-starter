<?php

namespace App\Command;

use App\Entity\Tenant\Term;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills a demo term with realistic, deterministic results for every pupil and
 * subject. Development/demo only — it overwrites existing component scores.
 *
 * Run:
 *   php bin/console app:demo:populate-results --term=1 --approve
 */
#[AsCommand(
    name: 'app:demo:populate-results',
    description: 'Populate realistic demo scores for all pupils and subjects in a term',
)]
final class PopulateDemoResultsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('term', null, InputOption::VALUE_REQUIRED, 'Term id (defaults to the active term)')
            ->addOption('classroom', null, InputOption::VALUE_REQUIRED, 'Limit to one classroom id')
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Final-approve every class subject after populating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        $termId = (int) $input->getOption('term');
        $term = $termId > 0
            ? $this->em->getRepository(Term::class)->find($termId)
            : $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        if (!$term) {
            $io->error('No term found. Pass --term=<id> or activate a term.');
            return Command::FAILURE;
        }

        $sessionId = $term->getSession()?->getId();
        if (!$sessionId) {
            $io->error('The term has no session.');
            return Command::FAILURE;
        }

        $classroomFilter = (int) $input->getOption('classroom');

        /** @var list<array{id: int, classroom_id: int, subject_id: int, subject_name: string}> $classSubjects */
        $sql = 'SELECT cs.id, cs.classroom_id, cs.subject_id, s.name AS subject_name
                  FROM class_subject cs
                  INNER JOIN subject s ON s.id = cs.subject_id
                 WHERE cs.session_id = :session';
        $params = ['session' => $sessionId];
        if ($classroomFilter > 0) {
            $sql .= ' AND cs.classroom_id = :classroom';
            $params['classroom'] = $classroomFilter;
        }
        $classSubjects = $conn->fetchAllAssociative($sql, $params);

        if ($classSubjects === []) {
            $io->error('No class subjects found for this session.');
            return Command::FAILURE;
        }

        $rosters = $this->rosters($conn, (int) $sessionId);
        $schemes = $this->schemes($conn, (int) $term->getId());
        $subjectOffsets = [
            'Mathematics' => 6,
            'Basic Science' => -6,
            'Computer Studies' => 2,
            'Agricultural Science' => -3,
            'English Language' => 3,
            'Hausa Language' => 1,
            'Social Studies' => -1,
            'Civic Education' => 2,
            'Business Studies' => 0,
            'Islamic Studies' => 1,
        ];

        $conn->beginTransaction();
        try {
            $statement = $conn->prepare(
                'INSERT INTO result_component_score
                    (student_id, class_subject_id, term_id, component, score, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE score = VALUES(score), status = VALUES(status), updated_at = NOW()'
            );

            $rows = 0;
            $uniqueStudents = [];
            foreach ($classSubjects as $classSubject) {
                $classroomId = (int) $classSubject['classroom_id'];
                $students = $rosters[$classroomId] ?? [];
                if ($students === []) {
                    continue;
                }

                $scheme = $this->resolveScheme($schemes, $classroomId, (int) $classSubject['subject_id']);
                $components = $this->components($scheme);
                $offset = $subjectOffsets[$classSubject['subject_name']] ?? 0;

                foreach ($students as $studentId) {
                    $uniqueStudents[$studentId] = true;
                    // A stable base ability, so rerunning the command gives the same story.
                    $base = 38 + (crc32('student-' . $studentId) % 41); // 38..78

                    foreach ($components as $component => $max) {
                        $noise = (crc32($studentId . '-' . $classSubject['id'] . '-' . $component) % 13) - 6;
                        $percent = max(18, min(98, $base + $offset + $noise));
                        $score = round($percent / 100 * $max, 2);

                        $statement->bindValue(1, $studentId);
                        $statement->bindValue(2, (int) $classSubject['id']);
                        $statement->bindValue(3, (int) $term->getId());
                        $statement->bindValue(4, $component);
                        $statement->bindValue(5, $score);
                        $statement->bindValue(6, 'submitted');
                        $statement->executeStatement();
                        $rows++;
                    }
                }
            }

            if ($input->getOption('approve')) {
                $approve = $conn->prepare(
                    "INSERT INTO result_approval
                        (class_subject_id, term_id, form_teacher_status, hod_status, year_group_status,
                         form_teacher_at, hod_at, year_group_at)
                     VALUES (?, ?, 'approved', 'approved', 'approved', NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        form_teacher_status = 'approved', hod_status = 'approved', year_group_status = 'approved',
                        form_teacher_at = NOW(), hod_at = NOW(), year_group_at = NOW()"
                );
                foreach ($classSubjects as $classSubject) {
                    $approve->bindValue(1, (int) $classSubject['id']);
                    $approve->bindValue(2, (int) $term->getId());
                    $approve->executeStatement();
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Population failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Populated %d component scores for %d pupils across %d class subjects in %s.%s',
            $rows,
            count($uniqueStudents),
            count($classSubjects),
            $term->getName(),
            $input->getOption('approve') ? ' Term final-approved.' : ''
        ));

        return Command::SUCCESS;
    }

    /** @return array<int, list<int>> classroomId => studentIds */
    private function rosters(Connection $conn, int $sessionId): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT student_id, classroom_id FROM enrollment WHERE session_id = :session',
            ['session' => $sessionId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['classroom_id']][] = (int) $row['student_id'];
        }

        if ($out === []) {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, current_classroom_id FROM student WHERE current_classroom_id IS NOT NULL'
            );
            foreach ($rows as $row) {
                $out[(int) $row['current_classroom_id']][] = (int) $row['id'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    private function schemes(Connection $conn, int $termId): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT classroom_id, subject_id, ca_count, exam_mode, ca_max, theory_max, practical_max
               FROM assessment_scheme
              WHERE term_id = :term',
            ['term' => $termId]
        );

        $out = [];
        foreach ($rows as $row) {
            $key = ($row['classroom_id'] ?? 'all') . ':' . ($row['subject_id'] ?? 'all');
            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, array<string, int|string>> $schemes
     *
     * @return array{ca_count: int, exam_mode: string, ca_max: int, theory_max: int, practical_max: int}
     */
    private function resolveScheme(array $schemes, int $classroomId, int $subjectId): array
    {
        $candidates = [
            $classroomId . ':' . $subjectId,
            $classroomId . ':all',
            'all:all',
        ];

        foreach ($candidates as $key) {
            if (isset($schemes[$key])) {
                $row = $schemes[$key];
                return [
                    'ca_count' => (int) $row['ca_count'],
                    'exam_mode' => (string) $row['exam_mode'],
                    'ca_max' => (int) $row['ca_max'],
                    'theory_max' => (int) $row['theory_max'],
                    'practical_max' => (int) $row['practical_max'],
                ];
            }
        }

        return [
            'ca_count' => 3,
            'exam_mode' => 'single',
            'ca_max' => 20,
            'theory_max' => 60,
            'practical_max' => 40,
        ];
    }

    /**
     * @param array{ca_count: int, exam_mode: string, ca_max: int, theory_max: int, practical_max: int} $scheme
     *
     * @return array<string, int>
     */
    private function components(array $scheme): array
    {
        $out = [];
        for ($i = 1; $i <= $scheme['ca_count']; $i++) {
            $out['ca' . $i] = $scheme['ca_max'];
        }
        $out['theory'] = $scheme['theory_max'];
        if ($scheme['exam_mode'] === 'dual') {
            $out['practical'] = $scheme['practical_max'];
        }

        return $out;
    }
}
