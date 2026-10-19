<?php

namespace App\Service\Results;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The child's whole session, not just this term.
 *
 * Produces one row per subject with a percentage for each term, the change from
 * the previous term, and the session cumulative. The parent card and the PDF's
 * page 2 both read this structure; the transcript uses the same data.
 */
final class ResultTrendBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssessmentSchemeResolver $schemes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Student $student, Term $currentTerm): array
    {
        $session = $currentTerm->getSession();
        $classroom = $this->classroomFor($student, $currentTerm);

        $empty = [
            'has_history' => false,
            'classroom' => $classroom,
            'session' => $session,
            'terms' => [],
            'subjects' => [],
            'term_totals' => [],
            'cumulative' => ['obtained' => 0.0, 'obtainable' => 0.0, 'percentage' => null, 'grade' => null],
            'session_average' => null,
        ];

        if (!$session || !$classroom) {
            return $empty;
        }

        $terms = $this->termsUpTo($currentTerm);
        if ($terms === []) {
            return $empty;
        }

        $classSubjects = $this->em->getRepository(ClassSubject::class)
            ->createQueryBuilder('cs')
            ->addSelect('s')
            ->join('cs.subject', 's')
            ->andWhere('cs.classroom = :classroom')
            ->andWhere('cs.session = :session')
            ->setParameter('classroom', $classroom)
            ->setParameter('session', $session)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $scores = $this->scores($student, $classroom, $terms);
        $grading = $this->gradingScale();

        $subjects = [];
        $termTotals = [];
        foreach ($terms as $term) {
            $termTotals[(int) $term->getId()] = ['obtained' => 0.0, 'obtainable' => 0.0, 'percentage' => null];
        }

        foreach ($classSubjects as $classSubject) {
            $csId = (int) $classSubject->getId();
            $points = [];
            $sawResult = false;

            foreach ($terms as $term) {
                $termId = (int) $term->getId();
                $components = $scores[$termId][$csId] ?? null;
                if ($components === null || $components === []) {
                    $points[$termId] = null;
                    continue;
                }

                $scheme = $this->schemes->resolve($classroom, $classSubject, $term);
                $max = $this->schemes->maximum($scheme);
                $total = array_sum($components);
                $percentage = $max > 0 ? round($total / $max * 100, 1) : null;

                $points[$termId] = [
                    'total' => round($total, 1),
                    'max' => round($max, 1),
                    'percentage' => $percentage,
                    'grade' => $percentage === null ? null : ($this->grade($grading, $percentage)['grade'] ?? null),
                ];

                if ($percentage !== null) {
                    $sawResult = true;
                    $termTotals[$termId]['obtained'] += $total;
                    $termTotals[$termId]['obtainable'] += $max;
                }
            }

            if (!$sawResult) {
                continue;
            }

            $current = $points[(int) $currentTerm->getId()] ?? null;
            $previous = null;
            foreach ($terms as $term) {
                $candidate = $points[(int) $term->getId()] ?? null;
                if ((int) $term->getId() === (int) $currentTerm->getId()) {
                    break;
                }
                if ($candidate !== null) {
                    $previous = $candidate;
                }
            }

            $subjects[] = [
                'name' => $classSubject->getSubject()?->getName() ?? '—',
                'points' => $points,
                'current' => $current,
                'previous' => $previous,
                'change' => ($current && $previous && $current['percentage'] !== null && $previous['percentage'] !== null)
                    ? round($current['percentage'] - $previous['percentage'], 1)
                    : null,
            ];
        }

        foreach ($termTotals as $termId => $row) {
            $termTotals[$termId]['obtained'] = round($row['obtained'], 1);
            $termTotals[$termId]['obtainable'] = round($row['obtainable'], 1);
            $termTotals[$termId]['percentage'] = $row['obtainable'] > 0
                ? round($row['obtained'] / $row['obtainable'] * 100, 1)
                : null;
        }

        $obtained = 0.0;
        $obtainable = 0.0;
        foreach ($termTotals as $row) {
            $obtained += $row['obtained'];
            $obtainable += $row['obtainable'];
        }

        $percentages = [];
        foreach ($termTotals as $row) {
            if ($row['percentage'] !== null) {
                $percentages[] = $row['percentage'];
            }
        }

        $cumulativePercentage = $obtainable > 0 ? round($obtained / $obtainable * 100, 1) : null;

        return [
            'has_history' => count($percentages) >= 2,
            'classroom' => $classroom,
            'session' => $session,
            'terms' => array_map(static fn (Term $term) => [
                'id' => (int) $term->getId(),
                'name' => (string) $term->getName(),
            ], $terms),
            'subjects' => $subjects,
            'term_totals' => $termTotals,
            'cumulative' => [
                'obtained' => round($obtained, 1),
                'obtainable' => round($obtainable, 1),
                'percentage' => $cumulativePercentage,
                'grade' => $cumulativePercentage === null ? null : ($this->grade($grading, $cumulativePercentage)['grade'] ?? null),
            ],
            'session_average' => $percentages === [] ? null : round(array_sum($percentages) / count($percentages), 1),
        ];
    }

    private function classroomFor(Student $student, Term $term): ?Classroom
    {
        $session = $term->getSession();
        if ($session) {
            $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(
                ['student' => $student, 'session' => $session],
                ['enrolledAt' => 'ASC']
            );
            if ($enrollment?->getClassroom()) {
                return $enrollment->getClassroom();
            }
        }

        return $student->getCurrentClassroom();
    }

    /** @return list<Term> */
    private function termsUpTo(Term $current): array
    {
        $session = $current->getSession();
        if (!$session) {
            return [];
        }

        $terms = $this->em->getRepository(Term::class)->findBy(
            ['session' => $session],
            ['startDate' => 'ASC', 'id' => 'ASC']
        );

        $currentStart = $current->getStartDate();
        $out = [];
        foreach ($terms as $term) {
            if ($currentStart && $term->getStartDate() && $term->getStartDate() > $currentStart) {
                continue;
            }
            if (!$currentStart && (int) $term->getId() > (int) $current->getId()) {
                continue;
            }
            $out[] = $term;
        }

        return $out;
    }

    /**
     * @param list<Term> $terms
     *
     * @return array<int, array<int, array<string, float>>> termId => subjectId => component => score
     */
    private function scores(Student $student, Classroom $classroom, array $terms): array
    {
        $termIds = array_map(static fn (Term $term) => (int) $term->getId(), $terms);
        if ($termIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT rcs.term_id AS tid, rcs.class_subject_id AS cs, rcs.component AS c, rcs.score AS s
               FROM result_component_score rcs
               INNER JOIN class_subject cs2 ON cs2.id = rcs.class_subject_id
              WHERE rcs.student_id = :student
                AND cs2.classroom_id = :classroom
                AND rcs.term_id IN (:terms)',
            [
                'student' => $student->getId(),
                'classroom' => $classroom->getId(),
                'terms' => $termIds,
            ],
            ['terms' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['tid']][(int) $row['cs']][(string) $row['c']] = (float) $row['s'];
        }

        return $out;
    }

    /** @return list<array{grade: string, min: float, max: float, remark: string|null}> */
    private function gradingScale(): array
    {
        $out = [];
        foreach ($this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']) as $scale) {
            $out[] = [
                'grade' => (string) $scale->getGrade(),
                'min' => (float) $scale->getMinScore(),
                'max' => (float) $scale->getMaxScore(),
                'remark' => $scale->getRemark(),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{grade: string, min: float, max: float, remark: string|null}> $grading
     *
     * @return array{grade: string, remark: string|null}|null
     */
    private function grade(array $grading, float $score): ?array
    {
        foreach ($grading as $band) {
            if ($score >= $band['min'] - 0.0001 && $score <= $band['max'] + 0.0001) {
                return ['grade' => $band['grade'], 'remark' => $band['remark']];
            }
        }

        return null;
    }
}
