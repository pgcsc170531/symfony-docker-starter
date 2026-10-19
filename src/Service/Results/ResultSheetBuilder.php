<?php

namespace App\Service\Results;

use App\Entity\Tenant\BehaviourRating;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentTermRemark;
use App\Entity\Tenant\Term;
use App\Service\SchoolLife\AttendanceSummary;
use App\Service\SchoolLife\TermRelease;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything a report card needs, computed once.
 *
 * The screen and the PDF both read this exact structure, so a parent can never see
 * one set of figures and download another. Nothing is rounded differently in two
 * places, and no second implementation of "what is a class average" can drift.
 *
 * Positions use **competition ranking** (1, 2, 2, 4) — two pupils on 85 are both
 * second, and the next is fourth. The alternative (1, 2, 2, 3) invents a position
 * that nobody holds.
 */
final class ResultSheetBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private AttendanceSummary $attendance,
        private TermRelease $release,
        private AssessmentSchemeResolver $schemes,
        private ResultTrendBuilder $trend,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Student $student, Term $term): array
    {
        $classroom = $this->classroomFor($student, $term);
        if (!$classroom) {
            return ['empty' => true, 'student' => $student, 'term' => $term];
        }

        $school = $this->em->getRepository(School::class)->findOneBy([]);
        $grading = $this->gradingScale();

        $classSubjects = $this->em->getRepository(ClassSubject::class)
            ->createQueryBuilder('cs')
            ->addSelect('s')
            ->join('cs.subject', 's')
            ->andWhere('cs.classroom = :classroom')
            ->setParameter('classroom', $classroom)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $roster = $this->rosterIds($classroom, $term);
        $totalsBySubject = $this->totalsForClassroom($classroom, $term);

        // ── Subject rows ────────────────────────────────────────────────
        $subjects = [];
        $obtained = 0.0;
        $obtainable = 0.0;
        $subjectsWithResults = 0;

        foreach ($classSubjects as $classSubject) {
            /** @var ClassSubject $classSubject */
            $scheme = $this->schemes->resolve($classroom, $classSubject, $term);
            $subjectMax = $this->schemes->maximum($scheme);

            $csId = (int) $classSubject->getId();
            $mine = $totalsBySubject[$csId][(int) $student->getId()] ?? null;
            $total = $mine === null ? null : $mine['total'];

            $classTotals = [];
            foreach ($roster as $studentId) {
                $row = $totalsBySubject[$csId][$studentId] ?? null;
                if ($row !== null) {
                    $classTotals[$studentId] = $row['total'];
                }
            }

            $average = $classTotals === [] ? null : round(array_sum($classTotals) / count($classTotals), 1);
            $highest = $classTotals === [] ? null : max($classTotals);
            $position = ($total === null || $classTotals === []) ? null : $this->competitionRank($classTotals, $total);

            $grade = $total === null ? null : $this->grade($grading, $total);

            $subjects[] = [
                'name' => $classSubject->getSubject()?->getName() ?? '—',
                'components' => $mine['components'] ?? [],
                'ca_count' => $scheme['ca_count'],
                'exam_mode' => $scheme['exam_mode'],
                'total' => $total,
                'max' => $subjectMax,
                'grade' => $grade['grade'] ?? null,
                'remark' => $grade['remark'] ?? null,
                'class_average' => $average,
                'class_highest' => $highest,
                'position' => $position,
                'position_of' => count($classTotals),
            ];

            if ($total !== null) {
                $obtained += $total;
                $obtainable += $subjectMax;
                $subjectsWithResults++;
            }
        }

        $percentage = $obtainable > 0 ? round($obtained / $obtainable * 100, 1) : null;

        // ── Class standing across the whole term ────────────────────────
        $classPercentages = [];
        foreach ($roster as $studentId) {
            $sum = 0.0;
            $max = 0.0;
            foreach ($classSubjects as $classSubject) {
                // ⚠️ The maximum is only added for a subject this pupil actually sat.
                // Adding every subject's maximum while summing only the subjects with
                // results produced nonsense: a class mid-term average of 0.2%, and a
                // pupil who had sat one of ten subjects ranked first.
                $row = $totalsBySubject[(int) $classSubject->getId()][$studentId] ?? null;
                if ($row === null) {
                    continue;
                }

                $scheme = $this->schemes->resolve($classroom, $classSubject, $term);
                $max += $this->schemes->maximum($scheme);
                $sum += $row['total'];
            }
            if ($max > 0) {
                $classPercentages[$studentId] = round($sum / $max * 100, 1);
            }
        }

        $classAverage = $classPercentages === [] ? null : round(array_sum($classPercentages) / count($classPercentages), 1);
        $classHighest = $classPercentages === [] ? null : max($classPercentages);
        $classLowest = $classPercentages === [] ? null : min($classPercentages);
        $position = ($percentage === null || $classPercentages === []) ? null : $this->competitionRank($classPercentages, $percentage);

        return [
            'empty' => false,
            'student' => $student,
            'term' => $term,
            'classroom' => $classroom,
            'school' => $school,
            'subjects' => $subjects,
            'summary' => [
                'obtained' => round($obtained, 1),
                'obtainable' => round($obtainable, 1),
                'percentage' => $percentage,
                'grade' => $percentage === null ? null : ($this->grade($grading, $percentage)['grade'] ?? null),
                'position' => $position,
                'of' => count($classPercentages),
                'class_average' => $classAverage,
                'class_highest' => $classHighest,
                'class_lowest' => $classLowest,
                'subjects_entered' => $subjectsWithResults,
                'subjects_total' => count($classSubjects),
            ],
            'grading' => $grading,
            'attendance' => $this->attendance->forStudent($student, $term, $classroom),
            'behaviour' => $this->behaviourFor($student, $term),
            'remark' => $this->remarkFor($student, $term),
            'form_teacher' => $this->formTeacherFor($classroom, $term),
            // The term is "released" when every subject has passed the year-group
            // gate. A share link is only issued for a released term, so a parent
            // can never forward figures the school has not signed off.
            'released' => $this->release->isReleased($classroom, $term),
            'next_term' => $this->nextTerm($term),
            'pupil_count' => count($roster),
            'trend' => $this->trend->build($student, $term),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

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

    /** @return list<int> */
    private function rosterIds(Classroom $classroom, Term $term): array
    {
        $session = $term->getSession();
        $ids = [];

        if ($session) {
            foreach ($this->em->getRepository(Enrollment::class)->findBy([
                'classroom' => $classroom,
                'session' => $session,
            ]) as $enrollment) {
                if ($enrollment->getStudent()) {
                    $ids[] = (int) $enrollment->getStudent()->getId();
                }
            }
        }

        if ($ids === []) {
            foreach ($this->em->getRepository(Student::class)->findBy(['currentClassroom' => $classroom]) as $student) {
                $ids[] = (int) $student->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One query for the whole class: every component score, grouped by
     * class-subject → student → component.
     *
     * @return array<int, array<int, array{total: float, components: array<string, float>}>>
     */
    private function totalsForClassroom(Classroom $classroom, Term $term): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT rcs.class_subject_id AS cs, rcs.student_id AS sid, rcs.component AS c, rcs.score AS s
               FROM result_component_score rcs
               INNER JOIN class_subject cs2 ON cs2.id = rcs.class_subject_id
              WHERE cs2.classroom_id = :classroom AND rcs.term_id = :term',
            ['classroom' => $classroom->getId(), 'term' => $term->getId()]
        );

        $out = [];
        foreach ($rows as $row) {
            $cs = (int) $row['cs'];
            $sid = (int) $row['sid'];
            $out[$cs][$sid]['components'][(string) $row['c']] = (float) $row['s'];
        }

        foreach ($out as $cs => $students) {
            foreach ($students as $sid => $data) {
                $out[$cs][$sid]['total'] = array_sum($data['components']);
            }
        }

        return $out;
    }

    /**
     * Competition ranking: equal scores share a place, the next place skips.
     *
     * @param array<int, float> $scores
     */
    private function competitionRank(array $scores, float $mine): int
    {
        $better = 0;
        foreach ($scores as $score) {
            if ($score > $mine + 0.0001) {
                $better++;
            }
        }

        return $better + 1;
    }

    /** @return list<array{grade: string, min: float, max: float, remark: string|null}> */
    private function gradingScale(): array
    {
        $rows = $this->em->getRepository(GradingScale::class)->findBy([], ['minScore' => 'DESC']);

        $out = [];
        foreach ($rows as $scale) {
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

        // Fall back to the highest band the score clears, so a score above the top
        // of the scale still prints something defensible.
        foreach ($grading as $band) {
            if ($score >= $band['min']) {
                return ['grade' => $band['grade'], 'remark' => $band['remark']];
            }
        }

        return null;
    }

    /**
     * @return array{AFFECTIVE: list<array{label: string, score: int}>, PSYCHOMOTOR: list<array{label: string, score: int}>}
     */
    private function behaviourFor(Student $student, Term $term): array
    {
        $out = ['AFFECTIVE' => [], 'PSYCHOMOTOR' => []];

        $ratings = $this->em->getRepository(BehaviourRating::class)
            ->createQueryBuilder('r')
            ->addSelect('t')
            ->join('r.trait', 't')
            ->andWhere('r.student = :student')
            ->andWhere('r.term = :term')
            ->andWhere('t.isActive = :active')
            ->setParameter('student', $student)
            ->setParameter('term', $term)
            ->setParameter('active', true)
            ->orderBy('t.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($ratings as $rating) {
            $trait = $rating->getTrait();
            $domain = $trait?->getDomain();
            if ($domain === null || !isset($out[$domain])) {
                continue;
            }
            $out[$domain][] = ['label' => $trait->getLabel(), 'score' => $rating->getScore()];
        }

        return $out;
    }

    /**
     * @return array{
     *     text: string|null, author: string|null, updated_at: \DateTimeImmutable|null,
     *     hod: array{text: string|null, author: string|null, updated_at: \DateTimeImmutable|null},
     *     principal: array{text: string|null, author: string|null, updated_at: \DateTimeImmutable|null}
     * }|null
     */
    private function remarkFor(Student $student, Term $term): ?array
    {
        $remark = $this->em->getRepository(StudentTermRemark::class)->findOneBy([
            'student' => $student,
            'term' => $term,
        ]);

        if (!$remark) {
            return null;
        }

        return [
            'text' => $remark->getText(),
            'author' => $remark->getAuthor()?->getUser()?->getFullName(),
            'updated_at' => $remark->getUpdatedAt() ?? $remark->getCreatedAt(),
            'hod' => [
                'text' => $remark->getHodText(),
                'author' => $remark->getHodAuthor()?->getUser()?->getFullName(),
                'updated_at' => $remark->getHodUpdatedAt(),
            ],
            'principal' => [
                'text' => $remark->getPrincipalText(),
                'author' => $remark->getPrincipalAuthor()?->getUser()?->getFullName(),
                'updated_at' => $remark->getPrincipalUpdatedAt(),
            ],
        ];
    }

    /**
     * @return array{name: string, title: string|null}|null
     */
    private function formTeacherFor(Classroom $classroom, Term $term): ?array
    {
        $assignment = $this->em->getRepository(FormTeacher::class)->findOneBy([
            'classroom' => $classroom,
            'term' => $term,
        ]);

        $staff = $assignment?->getStaff();
        $name = $staff?->getUser()?->getFullName();
        if (!$name) {
            return null;
        }

        return ['name' => $name, 'title' => $staff?->getTitle()];
    }

    /** @return array{name: string, start: \DateTimeImmutable|null}|null */
    private function nextTerm(Term $term): ?array
    {
        $session = $term->getSession();
        if (!$session || !$term->getEndDate()) {
            return null;
        }

        $next = $this->em->getRepository(Term::class)
            ->createQueryBuilder('t')
            ->andWhere('t.session = :session')
            ->andWhere('t.startDate > :end')
            ->setParameter('session', $session)
            ->setParameter('end', $term->getEndDate())
            ->orderBy('t.startDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $next ? ['name' => (string) $next->getName(), 'start' => $next->getStartDate()] : null;
    }
}
