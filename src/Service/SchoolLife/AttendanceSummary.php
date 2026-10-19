<?php

namespace App\Service\SchoolLife;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-pupil attendance totals for one class and one term.
 *
 * Two deliberate rules, both from ATTENDANCE-AND-BEHAVIOUR-DESIGN.md:
 *
 *  1. **`opened` means registers actually taken**, never the length of the term.
 *     If a form master skipped three days the card says 59, not 62 — we do not
 *     invent attendance nobody recorded, and we do not punish a pupil for the
 *     school's missing paperwork.
 *
 *  2. **A pupil admitted mid-term is measured from their enrolment date**, so a
 *     child who joined in week six is not shown as 12% attendance.
 *
 * Days where a pupil has no entry at all are reported as `unmarked` rather than
 * being silently counted as present or absent.
 */
final class AttendanceSummary
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<int, array{student: \App\Entity\Tenant\Student, from: \DateTimeImmutable}> $roster
     *
     * @return array{
     *     opened: int,
     *     rows: array<int, array{
     *         student: \App\Entity\Tenant\Student,
     *         present: int, late: int, absent: int, excused: int,
     *         opened: int, unmarked: int, rate: float|null
     *     }>
     * }
     */
    public function forClassroomTerm(Classroom $classroom, Term $term, array $roster): array
    {
        [$registerDates, $entriesByStudent] = $this->load($classroom, $term);

        $openedTotal = count($registerDates);
        $rows = [];

        foreach ($roster as $studentId => $info) {
            $student = $info['student'];
            $from = $info['from'];

            $present = $late = $absent = $excused = $unmarked = $opened = 0;

            foreach ($registerDates as $dateKey) {
                if ($dateKey < $from->format('Y-m-d')) {
                    continue; // before this pupil joined
                }
                $opened++;

                $status = $entriesByStudent[$studentId][$dateKey] ?? null;
                match ($status) {
                    'P' => $present++,
                    'L' => $late++,
                    'A' => $absent++,
                    'E' => $excused++,
                    default => $unmarked++,
                };
            }

            $attended = $present + $late;
            $rows[$studentId] = [
                'student' => $student,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'excused' => $excused,
                'opened' => $opened,
                'unmarked' => $unmarked,
                'rate' => $opened > 0 ? round($attended / $opened * 100, 1) : null,
            ];
        }

        return ['opened' => $openedTotal, 'rows' => $rows];
    }

    /** @return array<int, array{present: int, late: int, absent: int, excused: int, opened: int, unmarked: int, rate: float|null}> */
    public function forStudent(\App\Entity\Tenant\Student $student, Term $term, ?Classroom $classroom = null): array
    {
        $classroom ??= $student->getCurrentClassroom();
        if (!$classroom) {
            return ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'opened' => 0, 'unmarked' => 0, 'rate' => null];
        }

        $summary = $this->forClassroomTerm($classroom, $term, [
            (int) $student->getId() => ['student' => $student, 'from' => $term->getStartDate() ?? new \DateTimeImmutable()],
        ]);

        return $summary['rows'][(int) $student->getId()] ?? [
            'present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0,
            'opened' => 0, 'unmarked' => 0, 'rate' => null,
        ];
    }

    /**
     * @return array{0: list<string>, 1: array<int, array<string, string>>}
     *         [ordered register dates, studentId => dateKey => status]
     */
    private function load(Classroom $classroom, Term $term): array
    {
        $conn = $this->em->getConnection();

        $registerRows = $conn->fetchAllAssociative(
            'SELECT id, register_date FROM attendance_register
              WHERE classroom_id = :c AND term_id = :t
              ORDER BY register_date ASC',
            ['c' => $classroom->getId(), 't' => $term->getId()]
        );

        $dateByRegister = [];
        $dates = [];
        foreach ($registerRows as $row) {
            $key = substr((string) $row['register_date'], 0, 10);
            $dateByRegister[(int) $row['id']] = $key;
            $dates[] = $key;
        }

        if (!$dateByRegister) {
            return [[], []];
        }

        $entryRows = $conn->fetchAllAssociative(
            'SELECT e.student_id, e.register_id, e.status
               FROM attendance_entry e
               INNER JOIN attendance_register r ON r.id = e.register_id
              WHERE r.classroom_id = :c AND r.term_id = :t',
            ['c' => $classroom->getId(), 't' => $term->getId()]
        );

        $byStudent = [];
        foreach ($entryRows as $row) {
            $dateKey = $dateByRegister[(int) $row['register_id']] ?? null;
            if ($dateKey === null) {
                continue;
            }
            $byStudent[(int) $row['student_id']][$dateKey] = (string) $row['status'];
        }

        return [$dates, $byStudent];
    }
}
