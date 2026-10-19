<?php

namespace App\Service\Results;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whole-school performance, computed once for every analytics screen.
 *
 * This is the cohort equivalent of ResultSheetBuilder: one structure feeds the
 * executive brief, the subject view, the class view and — later — the PDF and
 * agency link. It deliberately works from the same approved scores and the same
 * subject maxima as the report card, so the school average and a child's
 * percentage can never use different arithmetic.
 *
 * Definitions live in docs/results/PERFORMANCE-ANALYTICS-DESIGN.md §7.
 */
final class SchoolPerformanceBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssessmentSchemeResolver $schemes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Term $term, ?int $yearGroupId = null, ?int $classroomId = null): array
    {
        $session = $term->getSession();
        if (!$session) {
            return $this->empty($term);
        }

        $classSubjects = $this->classSubjects($term, $yearGroupId, $classroomId);
        if ($classSubjects === []) {
            return $this->empty($term);
        }

        $grading = $this->gradingScale();
        $thresholds = $this->thresholds($grading);
        $rosters = $this->rosters($session->getId());
        $scores = $this->scores($term, array_map(static fn (ClassSubject $cs) => (int) $cs->getId(), $classSubjects));
        $approvals = $this->approvals($term, array_map(static fn (ClassSubject $cs) => (int) $cs->getId(), $classSubjects));

        $maxByClassSubject = [];
        $subjectRows = [];
        $expectedEntries = 0;
        $enteredEntries = 0;

        foreach ($classSubjects as $classSubject) {
            $classroom = $classSubject->getClassroom();
            if (!$classroom) {
                continue;
            }

            $csId = (int) $classSubject->getId();
            $classroomIdValue = (int) $classroom->getId();
            $roster = $rosters[$classroomIdValue] ?? [];
            $max = $this->schemes->maximum($this->schemes->resolve($classroom, $classSubject, $term));
            $maxByClassSubject[$csId] = $max;

            $expectedEntries += count($roster);
            $entered = 0;
            $sum = 0.0;
            $pass = 0;
            $credit = 0;
            $distinction = 0;

            foreach ($roster as $studentId) {
                $total = $scores[$csId][$studentId] ?? null;
                if ($total === null || $max <= 0) {
                    continue;
                }
                $percentage = $total / $max * 100;
                $entered++;
                $sum += $percentage;
                if ($percentage >= $thresholds['pass']) {
                    $pass++;
                }
                if ($percentage >= $thresholds['credit']) {
                    $credit++;
                }
                if ($percentage >= $thresholds['distinction']) {
                    $distinction++;
                }
            }

            $enteredEntries += $entered;

            if ($entered === 0) {
                continue;
            }

            $subjectRows[] = [
                'name' => $classSubject->getSubject()?->getName() ?? '—',
                'classroom' => $classroom->getName(),
                'year_group' => $classroom->getYearGroup()?->getName(),
                'entered' => $entered,
                'average' => round($sum / $entered, 1),
                'pass_rate' => round($pass / $entered * 100, 1),
                'credit_rate' => round($credit / $entered * 100, 1),
                'distinction_rate' => round($distinction / $entered * 100, 1),
                'approved' => ($approvals[$csId] ?? false),
            ];
        }

        $subjects = $this->mergeSubjects($subjectRows);
        $schoolAverage = $subjects === []
            ? null
            : round(array_sum(array_column($subjects, 'average')) / count($subjects), 1);

        // ── Per-pupil overall percentage across the subjects they sat ──────
        $classroomByStudent = [];
        foreach ($rosters as $classroomIdValue => $studentIds) {
            foreach ($studentIds as $studentId) {
                $classroomByStudent[$studentId] = $classroomIdValue;
            }
        }

        $classSubjectsByClassroom = [];
        foreach ($classSubjects as $classSubject) {
            $classSubjectsByClassroom[(int) $classSubject->getClassroom()?->getId()][] = $classSubject;
        }

        $pupilPercentages = [];
        $pupilRows = [];
        $classBuckets = [];
        foreach ($classroomByStudent as $studentId => $classroomIdValue) {
            $sum = 0.0;
            $max = 0.0;
            $credits = 0;
            $englishCredit = false;
            $mathCredit = false;
            $hasEnglish = false;
            $hasMath = false;
            $subjectPercentages = [];

            foreach ($classSubjectsByClassroom[$classroomIdValue] ?? [] as $classSubject) {
                $csId = (int) $classSubject->getId();
                $name = (string) $classSubject->getSubject()?->getName();
                $isEnglish = stripos($name, 'English') !== false;
                $isMath = stripos($name, 'Mathematics') !== false || stripos($name, 'Maths') !== false;
                $hasEnglish = $hasEnglish || $isEnglish;
                $hasMath = $hasMath || $isMath;

                $total = $scores[$csId][$studentId] ?? null;
                if ($total === null) {
                    continue;
                }
                $subjectMax = $maxByClassSubject[$csId] ?? 0;
                if ($subjectMax <= 0) {
                    continue;
                }

                $percentage = $total / $subjectMax * 100;
                $subjectPercentages[(int) $classSubject->getId()] = $percentage;
                $sum += $total;
                $max += $subjectMax;

                if ($percentage >= $thresholds['credit']) {
                    $credits++;
                    $englishCredit = $englishCredit || $isEnglish;
                    $mathCredit = $mathCredit || $isMath;
                }
            }

            if ($max <= 0) {
                continue;
            }

            $percentage = round($sum / $max * 100, 1);
            $pupilPercentages[$studentId] = $percentage;
            $classBuckets[$classroomIdValue][] = $percentage;

            $eligibleFiveCredits = $hasEnglish && $hasMath;
            $pupilRows[] = [
                'percentage' => $percentage,
                'pass' => $percentage >= $thresholds['pass'],
                'credit' => $percentage >= $thresholds['credit'],
                'distinction' => $percentage >= $thresholds['distinction'],
                'five_credit' => $eligibleFiveCredits ? ($credits >= 5 && $englishCredit && $mathCredit) : null,
                'classroom_id' => $classroomIdValue,
            ];
        }

        $pupils = count($pupilRows);
        $schoolAverage = $pupils > 0
            ? round(array_sum(array_column($pupilRows, 'percentage')) / $pupils, 1)
            : $schoolAverage;

        $passRate = $pupils > 0
            ? round(count(array_filter($pupilRows, static fn (array $r) => $r['pass'])) / $pupils * 100, 1)
            : null;
        $distinctionRate = $pupils > 0
            ? round(count(array_filter($pupilRows, static fn (array $r) => $r['distinction'])) / $pupils * 100, 1)
            : null;

        $eligible = array_values(array_filter($pupilRows, static fn (array $r) => $r['five_credit'] !== null));
        $fiveCreditRate = $eligible === []
            ? null
            : round(count(array_filter($eligible, static fn (array $r) => $r['five_credit'] === true)) / count($eligible) * 100, 1);

        $coverage = $expectedEntries > 0 ? round($enteredEntries / $expectedEntries * 100, 1) : null;
        $approvedCount = count(array_filter($approvals));
        $approvalRate = $classSubjects === [] ? null : round($approvedCount / count($classSubjects) * 100, 1);
        $provisional = ($coverage !== null && $coverage < 90.0) || ($approvalRate !== null && $approvalRate < 100.0);

        $attendance = $this->attendance($term);
        $attendanceRate = $attendance['opened'] > 0
            ? round($attendance['attended'] / $attendance['opened'] * 100, 1)
            : null;

        $classes = $this->classRows($classSubjects, $rosters, $classBuckets, $pupilRows, $attendance);
        $yearGroups = $this->yearGroupRows($classes);
        $gradeDistribution = $this->gradeDistribution($pupilRows, $thresholds);

        return [
            'term' => $term,
            'session' => $session,
            'generated_at' => new \DateTimeImmutable(),
            'pupil_count' => $pupils,
            'subject_count' => count($subjects),
            'class_count' => count($classes),
            'summary' => [
                'average' => $schoolAverage,
                'pass_rate' => $passRate,
                'five_credit_rate' => $fiveCreditRate,
                'distinction_rate' => $distinctionRate,
                'coverage' => $coverage,
                'attendance' => $attendanceRate,
                'entered' => $enteredEntries,
                'expected' => $expectedEntries,
                'approval_rate' => $approvalRate,
                'approved' => $approvedCount,
                'class_subjects' => count($classSubjects),
            ],
            'thresholds' => $thresholds,
            'subjects' => $subjects,
            'classes' => $classes,
            'year_groups' => $yearGroups,
            'grade_distribution' => $gradeDistribution,
            'findings' => $this->findings($subjects, $classes, $coverage, $attendanceRate, $provisional),
            'headline' => $this->headline($term, $pupils, count($subjects), $schoolAverage, $passRate, $subjects, $classes, $coverage, $provisional),
            'provisional' => $provisional,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Data loading
    // ──────────────────────────────────────────────────────────────────

    /** @return list<ClassSubject> */
    private function classSubjects(Term $term, ?int $yearGroupId, ?int $classroomId): array
    {
        $qb = $this->em->getRepository(ClassSubject::class)->createQueryBuilder('cs')
            ->addSelect('c', 's', 'yg')
            ->join('cs.classroom', 'c')
            ->join('cs.subject', 's')
            ->leftJoin('c.yearGroup', 'yg')
            ->andWhere('cs.session = :session')
            ->setParameter('session', $term->getSession())
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('s.name', 'ASC');

        if ($yearGroupId) {
            $qb->andWhere('yg.id = :yearGroup')->setParameter('yearGroup', $yearGroupId);
        }
        if ($classroomId) {
            $qb->andWhere('c.id = :classroom')->setParameter('classroom', $classroomId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<int, list<int>> classroomId => studentIds
     */
    private function rosters(?int $sessionId): array
    {
        if (!$sessionId) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT student_id, classroom_id FROM enrollment WHERE session_id = :session',
            ['session' => $sessionId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['classroom_id']][] = (int) $row['student_id'];
        }

        if ($out === []) {
            foreach ($this->em->getRepository(Student::class)->findBy([], ['lastName' => 'ASC']) as $student) {
                if ($student->getCurrentClassroom()) {
                    $out[(int) $student->getCurrentClassroom()->getId()][] = (int) $student->getId();
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $classSubjectIds
     *
     * @return array<int, array<int, float>> classSubjectId => studentId => total
     */
    private function scores(Term $term, array $classSubjectIds): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT rcs.class_subject_id AS cs, rcs.student_id AS sid, rcs.component AS c, rcs.score AS s
               FROM result_component_score rcs
              WHERE rcs.term_id = :term AND rcs.class_subject_id IN (:subjects)',
            ['term' => $term->getId(), 'subjects' => $classSubjectIds],
            ['subjects' => ArrayParameterType::INTEGER]
        );

        $out = [];
        foreach ($rows as $row) {
            $cs = (int) $row['cs'];
            $sid = (int) $row['sid'];
            $out[$cs][$sid] = ($out[$cs][$sid] ?? 0.0) + (float) $row['s'];
        }

        return $out;
    }

    /**
     * @param list<int> $classSubjectIds
     *
     * @return array<int, bool> classSubjectId => approved
     */
    private function approvals(Term $term, array $classSubjectIds): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT class_subject_id, year_group_status
               FROM result_approval
              WHERE term_id = :term AND class_subject_id IN (:subjects)",
            ['term' => $term->getId(), 'subjects' => $classSubjectIds],
            ['subjects' => ArrayParameterType::INTEGER]
        );

        $out = array_fill_keys($classSubjectIds, false);
        foreach ($rows as $row) {
            $out[(int) $row['class_subject_id']] = $row['year_group_status'] === 'approved';
        }

        return $out;
    }

    /**
     * @return array{opened: int, attended: int, by_classroom: array<int, array{opened: int, attended: int}>}
     */
    private function attendance(Term $term): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT r.classroom_id, COUNT(e.id) AS opened,
                    SUM(CASE WHEN e.status IN ('P', 'L') THEN 1 ELSE 0 END) AS attended
               FROM attendance_entry e
               INNER JOIN attendance_register r ON r.id = e.register_id
              WHERE r.term_id = :term
              GROUP BY r.classroom_id",
            ['term' => $term->getId()]
        );

        $out = ['opened' => 0, 'attended' => 0, 'by_classroom' => []];
        foreach ($rows as $row) {
            $opened = (int) $row['opened'];
            $attended = (int) $row['attended'];
            $out['opened'] += $opened;
            $out['attended'] += $attended;
            $out['by_classroom'][(int) $row['classroom_id']] = ['opened' => $opened, 'attended' => $attended];
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Aggregation
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $subjectRows
     *
     * @return list<array<string, mixed>>
     */
    private function mergeSubjects(array $subjectRows): array
    {
        $byName = [];
        foreach ($subjectRows as $row) {
            $name = $row['name'];
            if (!isset($byName[$name])) {
                $byName[$name] = [
                    'name' => $name,
                    'entered' => 0,
                    'weighted_sum' => 0.0,
                    'pass_weighted' => 0.0,
                    'credit_weighted' => 0.0,
                    'distinction_weighted' => 0.0,
                    'approved' => true,
                ];
            }
            $byName[$name]['entered'] += $row['entered'];
            $byName[$name]['weighted_sum'] += $row['average'] * $row['entered'];
            $byName[$name]['pass_weighted'] += $row['pass_rate'] * $row['entered'];
            $byName[$name]['credit_weighted'] += $row['credit_rate'] * $row['entered'];
            $byName[$name]['distinction_weighted'] += $row['distinction_rate'] * $row['entered'];
            $byName[$name]['approved'] = $byName[$name]['approved'] && $row['approved'];
        }

        $out = [];
        foreach ($byName as $row) {
            $entered = $row['entered'];
            $out[] = [
                'name' => $row['name'],
                'entered' => $entered,
                'average' => $entered > 0 ? round($row['weighted_sum'] / $entered, 1) : null,
                'pass_rate' => $entered > 0 ? round($row['pass_weighted'] / $entered, 1) : null,
                'credit_rate' => $entered > 0 ? round($row['credit_weighted'] / $entered, 1) : null,
                'distinction_rate' => $entered > 0 ? round($row['distinction_weighted'] / $entered, 1) : null,
                'approved' => $row['approved'],
            ];
        }

        usort($out, static fn (array $a, array $b) => ($b['average'] ?? 0) <=> ($a['average'] ?? 0));

        return $out;
    }

    /**
     * @param list<ClassSubject> $classSubjects
     * @param array<int, list<int>> $rosters
     * @param array<int, list<float>> $classBuckets
     * @param list<array<string, mixed>> $pupilRows
     * @param array<string, mixed> $attendance
     *
     * @return list<array<string, mixed>>
     */
    private function classRows(array $classSubjects, array $rosters, array $classBuckets, array $pupilRows, array $attendance): array
    {
        $classrooms = [];
        foreach ($classSubjects as $classSubject) {
            $classroom = $classSubject->getClassroom();
            if ($classroom) {
                $classrooms[(int) $classroom->getId()] = $classroom;
            }
        }

        $byClass = [];
        foreach ($pupilRows as $row) {
            $byClass[$row['classroom_id']][] = $row;
        }

        $out = [];
        foreach ($classrooms as $classroomId => $classroom) {
            $rows = $byClass[$classroomId] ?? [];
            $count = count($rows);
            $average = $count > 0 ? round(array_sum(array_column($rows, 'percentage')) / $count, 1) : null;
            $pass = $count > 0 ? round(count(array_filter($rows, static fn (array $r) => $r['pass'])) / $count * 100, 1) : null;
            $eligible = array_values(array_filter($rows, static fn (array $r) => $r['five_credit'] !== null));
            $fiveCredit = $eligible === []
                ? null
                : round(count(array_filter($eligible, static fn (array $r) => $r['five_credit'] === true)) / count($eligible) * 100, 1);
            $att = $attendance['by_classroom'][$classroomId] ?? ['opened' => 0, 'attended' => 0];
            $attendanceRate = $att['opened'] > 0 ? round($att['attended'] / $att['opened'] * 100, 1) : null;

            $out[] = [
                'id' => $classroomId,
                'name' => $classroom->getName(),
                'year_group' => $classroom->getYearGroup()?->getName(),
                'pupils' => $count,
                'roster' => count($rosters[$classroomId] ?? []),
                'average' => $average,
                'pass_rate' => $pass,
                'five_credit_rate' => $fiveCredit,
                'attendance' => $attendanceRate,
            ];
        }

        usort($out, static fn (array $a, array $b) => ($b['average'] ?? -1) <=> ($a['average'] ?? -1));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $classes
     *
     * @return list<array<string, mixed>>
     */
    private function yearGroupRows(array $classes): array
    {
        $groups = [];
        foreach ($classes as $class) {
            $name = $class['year_group'] ?? 'Unassigned';
            $groups[$name][] = $class;
        }

        $out = [];
        foreach ($groups as $name => $rows) {
            $pupils = array_sum(array_column($rows, 'pupils'));
            $average = $pupils > 0
                ? round(array_sum(array_map(static fn (array $r) => ($r['average'] ?? 0) * $r['pupils'], $rows)) / $pupils, 1)
                : null;
            $pass = $pupils > 0
                ? round(array_sum(array_map(static fn (array $r) => ($r['pass_rate'] ?? 0) * $r['pupils'], $rows)) / $pupils, 1)
                : null;
            $out[] = ['name' => $name, 'pupils' => $pupils, 'average' => $average, 'pass_rate' => $pass];
        }

        usort($out, static fn (array $a, array $b) => ($b['average'] ?? -1) <=> ($a['average'] ?? -1));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $pupilRows
     * @param array{pass: float, credit: float, distinction: float} $thresholds
     *
     * @return list<array{label: string, count: int, percentage: float}>
     */
    private function gradeDistribution(array $pupilRows, array $thresholds): array
    {
        $bands = [
            ['label' => 'A/B', 'min' => $thresholds['distinction']],
            ['label' => 'C', 'min' => $thresholds['credit']],
            ['label' => 'D/E', 'min' => $thresholds['pass']],
            ['label' => 'F', 'min' => 0],
        ];

        $total = count($pupilRows);
        $out = [];
        foreach ($bands as $index => $band) {
            $upper = $index === 0 ? 101 : $bands[$index - 1]['min'];
            $count = 0;
            foreach ($pupilRows as $row) {
                if ($row['percentage'] >= $band['min'] && $row['percentage'] < $upper) {
                    $count++;
                }
            }
            $out[] = [
                'label' => $band['label'],
                'count' => $count,
                'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Narrative
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $subjects
     * @param list<array<string, mixed>> $classes
     *
     * @return list<array{tone: string, text: string}>
     */
    private function findings(array $subjects, array $classes, ?float $coverage, ?float $attendance, bool $provisional): array
    {
        $out = [];

        $withData = array_values(array_filter($subjects, static fn (array $s) => ($s['entered'] ?? 0) >= 5));
        if ($withData !== []) {
            $strongest = $withData[0];
            $weakest = $withData[count($withData) - 1];
            $out[] = [
                'tone' => 'positive',
                'text' => sprintf('%s is the strongest subject at %.1f%%.', $strongest['name'], $strongest['average']),
            ];
            $out[] = [
                'tone' => 'negative',
                'text' => sprintf('%s is the weakest at %.1f%%.', $weakest['name'], $weakest['average']),
            ];
        }

        $classAverages = array_values(array_filter($classes, static fn (array $c) => $c['average'] !== null));
        if (count($classAverages) >= 2) {
            $best = $classAverages[0];
            $worst = $classAverages[count($classAverages) - 1];
            $gap = round($best['average'] - $worst['average'], 1);
            $out[] = [
                'tone' => $gap > 10 ? 'negative' : 'neutral',
                'text' => sprintf('%s is %.1f points above %s — the widest class gap.', $best['name'], $gap, $worst['name']),
            ];
        }

        if ($coverage !== null && $coverage < 90) {
            $out[] = [
                'tone' => 'negative',
                'text' => sprintf('Only %.1f%% of expected results are entered; the figures are provisional.', $coverage),
            ];
        }

        if ($attendance !== null) {
            $out[] = [
                'tone' => $attendance >= 90 ? 'positive' : 'negative',
                'text' => sprintf('Attendance is %.1f%% across the term.', $attendance),
            ];
        }

        return array_slice($out, 0, 3);
    }

    /**
     * @param list<array<string, mixed>> $subjects
     * @param list<array<string, mixed>> $classes
     */
    private function headline(
        Term $term,
        int $pupils,
        int $subjects,
        ?float $average,
        ?float $passRate,
        array $subjectsRows,
        array $classes,
        ?float $coverage,
        bool $provisional,
    ): string {
        if ($pupils === 0) {
            return sprintf('No results have been entered for %s yet.', $term->getName());
        }

        $parts = [];
        if ($provisional) {
            $parts[] = 'Provisional:';
        }
        $parts[] = sprintf(
            '%d pupils sat %d subjects in %s.',
            $pupils,
            $subjects,
            $term->getName()
        );
        if ($average !== null && $passRate !== null) {
            $parts[] = sprintf('The school averaged %.1f%% and %.1f%% reached the pass mark.', $average, $passRate);
        }

        $withData = array_values(array_filter($subjectsRows, static fn (array $s) => ($s['entered'] ?? 0) >= 5));
        if ($withData !== []) {
            $parts[] = sprintf(
                '%s is the strongest subject; %s is the weakest.',
                $withData[0]['name'],
                $withData[count($withData) - 1]['name']
            );
        }

        return implode(' ', $parts);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Configuration
    // ──────────────────────────────────────────────────────────────────

    /** @return list<array{grade: string, min: float, max: float, points: float|null, remark: string|null}> */
    private function gradingScale(): array
    {
        $out = [];
        foreach ($this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']) as $scale) {
            $out[] = [
                'grade' => (string) $scale->getGrade(),
                'min' => (float) $scale->getMinScore(),
                'max' => (float) $scale->getMaxScore(),
                'points' => $scale->getPoints() !== null ? (float) $scale->getPoints() : null,
                'remark' => $scale->getRemark(),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{grade: string, min: float, max: float, points: float|null, remark: string|null}> $grading
     *
     * @return array{pass: float, credit: float, distinction: float, pass_grade: string, credit_grade: string, distinction_grade: string}
     */
    private function thresholds(array $grading): array
    {
        $find = static function (string $grade, float $fallback) use ($grading): array {
            foreach ($grading as $band) {
                if (strcasecmp($band['grade'], $grade) === 0) {
                    return [$band['min'], $band['grade']];
                }
            }

            return [$fallback, (string) $fallback . '%'];
        };

        [$pass, $passGrade] = $find('D7', 40.0);
        [$credit, $creditGrade] = $find('C6', 50.0);
        [$distinction, $distinctionGrade] = $find('B3', 65.0);

        return [
            'pass' => $pass,
            'credit' => $credit,
            'distinction' => $distinction,
            'pass_grade' => $passGrade,
            'credit_grade' => $creditGrade,
            'distinction_grade' => $distinctionGrade,
        ];
    }

    /** @return array<string, mixed> */
    private function empty(Term $term): array
    {
        return [
            'term' => $term,
            'session' => $term->getSession(),
            'generated_at' => new \DateTimeImmutable(),
            'pupil_count' => 0,
            'subject_count' => 0,
            'class_count' => 0,
            'summary' => [
                'average' => null, 'pass_rate' => null, 'five_credit_rate' => null,
                'distinction_rate' => null, 'coverage' => null, 'attendance' => null,
                'entered' => 0, 'expected' => 0, 'approval_rate' => null, 'approved' => 0,
                'class_subjects' => 0,
            ],
            'thresholds' => ['pass' => 40.0, 'credit' => 50.0, 'distinction' => 65.0, 'pass_grade' => 'D7', 'credit_grade' => 'C6', 'distinction_grade' => 'B3'],
            'subjects' => [],
            'classes' => [],
            'year_groups' => [],
            'grade_distribution' => [],
            'findings' => [],
            'headline' => sprintf('No results have been entered for %s yet.', $term->getName()),
            'provisional' => true,
        ];
    }
}
