<?php

namespace App\Service\Subjects;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentSubject;
use App\Entity\Tenant\StudentSubjectExemption;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that answers "what does this pupil actually study?".
 *
 * The class list (`ClassSubject`) says what an arm offers. Two things narrow it
 * per pupil:
 *
 *   - an **elective** class subject is opt-in (`student_subject` rows), which is
 *     how the mark sheet has always worked;
 *   - an **exemption** (`student_subject_exemption`) is opt-out — the form teacher
 *     unticks the subject the child does not offer, e.g. IRS for a pupil who takes
 *     CRK.
 *
 * Every screen that lists subjects or pupils must come through here, or a subject
 * will be honoured on the report card and ignored on the HOD's board — the exact
 * bug class this codebase has already paid for with the CA count.
 */
final class PupilSubjects
{
    /** @var array<int, array<int, true>> classroom id → student id → class subject id → true */
    private array $exemptIndex = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Every pupil in an arm, however they were linked to it.
     *
     * Three links exist in this schema and different screens wrote different
     * ones: `current_classroom_id` (quick admission), `current_class_id` (older
     * admissions) and an `enrollment` row per session. Taking only one of them
     * silently empties a roster — a mark sheet with no pupils — so the union is
     * the honest answer.
     *
     * @return list<Student>
     */
    public function roster(Classroom $classroom): array
    {
        /** @var list<Student> $students */
        $students = $this->em->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->leftJoin(Enrollment::class, 'e', 'WITH', 'e.student = s AND e.classroom = :classroom')
            ->andWhere('s.currentClassroom = :classroom OR s.currentClass = :classroom OR e.id IS NOT NULL')
            ->setParameter('classroom', $classroom)
            ->orderBy('s.firstName', 'ASC')
            ->addOrderBy('s.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        $unique = [];
        foreach ($students as $student) {
            $unique[(int) $student->getId()] = $student;
        }

        return array_values($unique);
    }

    /**
     * The pupils who are examined on this class subject, in the roster order the
     * caller gave us.
     *
     * @param list<Student> $roster every pupil in the arm
     *
     * @return list<Student>
     */
    public function takers(ClassSubject $classSubject, array $roster): array
    {
        $exempt = $this->exemptIdsFor($classSubject);

        if ($exempt === []) {
            return $roster;
        }

        return array_values(array_filter(
            $roster,
            static fn (Student $student): bool => !isset($exempt[(int) $student->getId()])
        ));
    }

    /** Does this pupil sit this subject? */
    public function takes(Student $student, ClassSubject $classSubject): bool
    {
        $classroom = $classSubject->getClassroom();
        if ($classroom === null || $student->getId() === null || $classSubject->getId() === null) {
            return true;
        }

        if ($classSubject->isElective() && !isset($this->optedInIds($student)[(int) $classSubject->getId()])) {
            return false;
        }

        return !isset($this->exemptIds($student, $classroom)[(int) $classSubject->getId()]);
    }

    /**
     * The pupils who sit this subject, by id — a class subject nobody takes is not
     * a subject the HOD has to approve, and not a reason to hold a term open.
     *
     * @param list<Student> $roster every pupil in the arm (fetched when empty)
     *
     * @return array<int, true> student id → true
     */
    public function takerIds(ClassSubject $classSubject, array $roster = []): array
    {
        $exempt = $this->exemptIdsFor($classSubject);

        if ($classSubject->isElective()) {
            $ids = [];
            foreach ($this->em->getRepository(StudentSubject::class)->findBy(['classSubject' => $classSubject]) as $row) {
                $id = (int) $row->getStudent()?->getId();
                if ($id > 0 && !isset($exempt[$id])) {
                    $ids[$id] = true;
                }
            }

            return $ids;
        }

        $classroom = $classSubject->getClassroom();
        if ($classroom === null) {
            return [];
        }

        $ids = [];
        foreach ($roster !== [] ? $roster : $this->roster($classroom) as $student) {
            $ids[(int) $student->getId()] = true;
        }

        foreach (array_keys($exempt) as $studentId) {
            unset($ids[$studentId]);
        }

        return $ids;
    }

    /**
     * The subjects this pupil is examined on, for a report card, a transcript or a
     * trend strip.
     *
     * @return list<ClassSubject>
     */
    public function subjectsFor(Student $student, Classroom $classroom): array
    {
        /** @var list<ClassSubject> $classSubjects */
        $classSubjects = $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classroom]);
        $optedIn = $this->optedInIds($student);
        $exempt = $this->exemptIds($student, $classroom);

        $subjects = [];
        foreach ($classSubjects as $classSubject) {
            $id = (int) $classSubject->getId();

            if ($classSubject->isElective() && !isset($optedIn[$id])) {
                continue;
            }

            if (isset($exempt[$id])) {
                continue;
            }

            $subjects[] = $classSubject;
        }

        return $subjects;
    }

    /** @return array<int, true> class subject id → true */
    public function exemptIds(Student $student, Classroom $classroom): array
    {
        $index = $this->exemptIndex((int) $classroom->getId());

        return $index[(int) $student->getId()] ?? [];
    }

    /** @return array<int, true> student id → true, for the pupils exempt from this subject */
    public function exemptIdsFor(ClassSubject $classSubject): array
    {
        $classroom = $classSubject->getClassroom();
        if ($classroom === null) {
            return [];
        }

        $ids = [];
        foreach ($this->exemptIndex((int) $classroom->getId()) as $studentId => $subjects) {
            if (isset($subjects[(int) $classSubject->getId()])) {
                $ids[$studentId] = true;
            }
        }

        return $ids;
    }

    public function exempt(Student $student, ClassSubject $classSubject, ?Staff $by = null, ?string $reason = null): void
    {
        $existing = $this->em->getRepository(StudentSubjectExemption::class)
            ->findOneBy(['student' => $student, 'classSubject' => $classSubject]);

        if ($existing !== null) {
            return;
        }

        $exemption = (new StudentSubjectExemption())
            ->setStudent($student)
            ->setClassSubject($classSubject)
            ->setRecordedBy($by)
            ->setReason($reason);

        $this->em->persist($exemption);
        $this->exemptIndex = [];
    }

    public function include(Student $student, ClassSubject $classSubject): void
    {
        $existing = $this->em->getRepository(StudentSubjectExemption::class)
            ->findOneBy(['student' => $student, 'classSubject' => $classSubject]);

        if ($existing !== null) {
            $this->em->remove($existing);
            $this->exemptIndex = [];
        }
    }

    /** An elective is opt-in: a row in `student_subject` means "this pupil takes it". */
    public function optIn(Student $student, ClassSubject $classSubject): void
    {
        $existing = $this->em->getRepository(StudentSubject::class)
            ->findOneBy(['student' => $student, 'classSubject' => $classSubject]);

        if ($existing === null) {
            $this->em->persist((new StudentSubject())->setStudent($student)->setClassSubject($classSubject));
        }
    }

    public function optOut(Student $student, ClassSubject $classSubject): void
    {
        $existing = $this->em->getRepository(StudentSubject::class)
            ->findOneBy(['student' => $student, 'classSubject' => $classSubject]);

        if ($existing !== null) {
            $this->em->remove($existing);
        }
    }

    /** @return array<int, true> class subject id → true */
    private function optedInIds(Student $student): array
    {
        $ids = [];
        foreach ($this->em->getRepository(StudentSubject::class)->findBy(['student' => $student]) as $row) {
            $ids[(int) $row->getClassSubject()?->getId()] = true;
        }

        return $ids;
    }

    /**
     * One query per classroom per request: student id → class subject id → true.
     *
     * @return array<int, array<int, true>>
     */
    private function exemptIndex(int $classroomId): array
    {
        if (isset($this->exemptIndex[$classroomId])) {
            return $this->exemptIndex[$classroomId];
        }

        $index = [];
        foreach ($this->em->getRepository(StudentSubjectExemption::class)->createQueryBuilder('e')
                     ->join('e.classSubject', 'cs')
                     ->andWhere('cs.classroom = :classroom')
                     ->setParameter('classroom', $classroomId)
                     ->getQuery()
                     ->getResult() as $exemption) {
            $index[(int) $exemption->getStudent()?->getId()][(int) $exemption->getClassSubject()?->getId()] = true;
        }

        return $this->exemptIndex[$classroomId] = $index;
    }
}
