<?php

namespace App\Service;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Admissions\AdmissionNumberGenerator;
use App\Service\Finance\TuitionInvoiceBuilder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single admission engine.
 *
 * Both admission paths call this so they can never drift apart:
 *   • the bursar's walk-in admission  → AdmissionController::register()
 *   • the parent's online application → ApplicationReviewController::enrol()
 *
 * It owns three things and nothing else:
 *   1. the admission number,
 *   2. the official Enrollment record,
 *   3. the first academic invoice (+ the discount calculation),
 *   4. the "admission successful" SMS.
 */
final class AdmissionService
{
    /** @var array<string,int> admission numbers handed out during this process */
    private array $issued = [];

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationService $notifier,
        private SchoolMailer $mailer,
        private readonly AdmissionNumberGenerator $admissionNumbers,
        private readonly TuitionInvoiceBuilder $invoices,
    ) {
    }

    /**
     * Admit a student: number → enrol → bill → notify. Safe to call for a
     * walk-in admission or for an approved online application.
     *
     * @param Classroom|null $classroom the class to enrol into (defaults to the student's current class)
     * @param Term|null      $term      the term to bill for (defaults to the active term)
     */
    public function admit(Student $student, ?Classroom $classroom = null, ?Term $term = null): Student
    {
        $classroom ??= $student->getCurrentClassroom();
        $term ??= $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        // The transaction guarantees that we never end up with a student who is
        // enrolled but not billed (or vice-versa).
        $this->em->wrapInTransaction(function () use ($student, $classroom, $term): void {
            if (!$student->getAdmissionNumber()) {
                $student->setAdmissionNumber($this->generateAdmissionNumber($term?->getSession()));
            }

            $this->em->persist($student);

            if ($classroom && $term) {
                $this->enroll($student, $classroom, $term);
                $this->billStudent($student, $classroom, $term);
            }
        });

        $this->notifyAdmission($student);

        return $student;
    }

    /**
     * ADM/2026/0042 — the sequence is derived from the highest number already
     * issued THIS YEAR, not from the row id.
     *
     * The old implementation used `lastStudent->getId() + 1`, which produced
     * wrong (and duplicate) numbers as soon as any student row was deleted or
     * any bulk import ran — and it could hand the same number to two bursars
     * admitting at the same moment.
     */
    public function generateAdmissionNumber(?Session $session = null): string
    {
        return $this->admissionNumbers->next($session);
    }

    /** Official enrolment record for the session/term. */
    public function enroll(Student $student, Classroom $classroom, Term $term): Enrollment
    {
        $enrollment = new Enrollment();
        $enrollment->setStudent($student);
        $enrollment->setSession($term->getSession());
        $enrollment->setClassroom($classroom);
        $enrollment->setEnrolledAt(new \DateTimeImmutable());

        $this->em->persist($enrollment);

        return $enrollment;
    }

    /**
     * Build the student's first academic invoice from the fee structure for
     * this class + term, applying any discounts. The shared builder also
     * excludes one-time new-intake items for a returning pupil.
     *
     * @return Invoice|null null when the class/term has no fee items configured
     */
    public function billStudent(Student $student, Classroom $classroom, Term $term): ?Invoice
    {
        return $this->invoices->build(
            $student,
            $classroom,
            $term,
            $student->getStudentType(),
        );
    }

    /** "Admission successful" SMS + email — each honours its own school toggle. */
    public function notifyAdmission(Student $student): void
    {
        $school = $this->em->getRepository(School::class)->findOneBy([]);
        if (!$school) {
            return;
        }

        $guardian = $student->getGuardian();

        if ($guardian?->getPhoneNumber()) {
            $this->notifier->sendSms(
                $school,
                $guardian->getPhoneNumber(),
                'Admission Successful! [student_name] has been admitted to [class] at [school_name]. Adm No: [adm_no]',
                'enrollment',
                [
                    '[student_name]' => $student->getFullName(),
                    '[class]'        => $student->getCurrentClassroom()?->getName() ?? 'New Intake',
                    '[school_name]'  => $school->getName(),
                    '[adm_no]'       => $student->getAdmissionNumber(),
                ]
            );
        }

        // The email rides the same event but is independent of the phone number:
        // a parent with only an email address still gets the good news.
        $this->mailer->admissionDecision($school, $student, approved: true);
    }
}
