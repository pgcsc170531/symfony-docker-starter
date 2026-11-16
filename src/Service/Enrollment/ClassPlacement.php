<?php

namespace App\Service\Enrollment;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves a pupil to a class — completely.
 *
 * `student` carries **two** class columns and a pupil can also be linked to a
 * class through an `enrollment` row. Screens read different ones, so a "class
 * change" that touched only one of them left the pupil in the old class as well:
 * he sat on two blank score sheets, two mark sheets and two subject lists, and
 * nothing on either screen said which one was real.
 *
 * Every class change must therefore go through here, so all three links agree:
 *
 *   - `current_class_id` and `current_classroom_id` are written together — the
 *     legacy column and the modern one, never one of them;
 *   - the pupil's enrolment for the **active session** is moved to the new class
 *     rather than a second one being created (a roster that reads enrolments must
 *     not keep seeing the class he left);
 *   - history is never rewritten: enrolments in *past* sessions stay exactly as
 *     they were, because they are the record of where the pupil sat that year.
 */
final class ClassPlacement
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Put the pupil in `$classroom`, or clear his class when `$classroom` is null.
     *
     * @param Session|null $session which session's enrolment should follow him;
     *                              defaults to the active one. Pass the session
     *                              being written when the caller is promoting, so
     *                              last year's row is never rewritten.
     */
    public function place(Student $student, ?Classroom $classroom, ?Session $session = null): void
    {
        // Both columns or neither: a half-written pupil is a pupil in two classes.
        $student->setCurrentClass($classroom);
        $student->setCurrentClassroom($classroom);

        if ($classroom === null) {
            return;
        }

        foreach ($this->enrolments($student, $session) as $enrolment) {
            $enrolment->setClassroom($classroom);
        }
    }

    /**
     * The pupil's enrolment(s) for the session being written.
     *
     * @return list<Enrollment>
     */
    private function enrolments(Student $student, ?Session $session): array
    {
        $session ??= $this->em->getRepository(Session::class)->findOneBy(['isActive' => true]);

        if (!$session instanceof Session) {
            return [];
        }

        /** @var list<Enrollment> $rows */
        $rows = $this->em->getRepository(Enrollment::class)->findBy([
            'student' => $student,
            'session' => $session,
        ]);

        return $rows;
    }
}
