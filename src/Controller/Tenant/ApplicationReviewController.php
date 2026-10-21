<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\ApplicationEvent;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Service\AdmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The application review queue — Phase 4 of
 * `docs/parent-application/DESIGN.md` (§7.3).
 *
 * Until this existed, a parent could register, pay and submit an application
 * that **no member of staff could ever see**: `/admission/` is the walk-in
 * form and `/quick-enroll/` is the bulk importer, so `APP/2026/0001` sat in
 * the database visible only to the parent who filed it, while the public
 * `/apply` page promised "the school reviews it".
 *
 * The final action delegates to {@see AdmissionService::admit()} — the same
 * engine the walk-in form uses — so an online applicant and a walk-in student
 * get an identical admission number, Enrollment, invoice and SMS.
 *
 * ⚠️ **ROLE_ADMIN, not ROLE_BURSAR.** Deciding who is admitted and in which
 * arm is the administrator's / principal's call (the principal signs the
 * register, the bursar collects the money). A bursar who can approve an
 * application can also enrol a child, raise their invoice and move a pupil into
 * an arm — which is not a finance decision. The bursar keeps the *money* side:
 * the payment verification queue, fee reports and the application-fees register.
 */
#[Route('/admission/applications')]
#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_ADMISSION_OFFICER')"))]
final class ApplicationReviewController extends AbstractController
{
    /** Statuses that belong in the working queue (everything not final). */
    private const QUEUE_STATUSES = [
        Application::STATUS_SUBMITTED,
        Application::STATUS_UNDER_REVIEW,
        Application::STATUS_NEEDS_INFO,
        Application::STATUS_APPROVED,
        Application::STATUS_WAITLISTED,
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    // ──────────────────────────────────────────────────────────────────
    //  Queue
    // ──────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_tenant_application_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = (string) $request->query->get('status', 'queue');
        $q      = trim((string) $request->query->get('q', ''));

        $qb = $this->em->getRepository(Application::class)->createQueryBuilder('a')
            ->leftJoin('a.requestedClassroom', 'c')->addSelect('c')
            ->leftJoin('a.requestedYearGroup', 'yg')->addSelect('yg')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(200);

        if ($status === 'queue') {
            $qb->andWhere('a.status IN (:st)')->setParameter('st', self::QUEUE_STATUSES);
        } elseif ($status !== 'all' && $status !== '') {
            $qb->andWhere('a.status = :st')->setParameter('st', $status);
        }

        if ($q !== '') {
            $qb->andWhere('a.reference LIKE :q
                        OR a.firstName LIKE :q
                        OR a.lastName LIKE :q
                        OR a.guardianName LIKE :q
                        OR a.guardianPhone LIKE :q
                        OR a.guardianEmail LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        return $this->render('tenant/admission/applications/index.html.twig', [
            'applications' => $qb->getQuery()->getResult(),
            'status'       => $status,
            'q'            => $q,
            'counters'     => $this->counters(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Review screen
    // ──────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'app_tenant_application_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Application $application): Response
    {
        // Opening a submitted application moves it to "under review" so the
        // queue shows who is actually working on what. Deliberately silent:
        // a bursar just clicking around should not spam the timeline.
        if ($application->getStatus() === Application::STATUS_SUBMITTED) {
            $this->transition($application, Application::STATUS_UNDER_REVIEW, null, false);
            $this->em->flush();
        }

        return $this->render('tenant/admission/applications/show.html.twig', [
            'application' => $application,
            // The arm is the school's choice, so the enrol form offers every
            // class and pre-selects the level the parent asked for.
            'classrooms'  => $this->em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'suggestedClassroom' => $application->getRequestedYearGroup()
                ? $this->em->getRepository(Classroom::class)->findOneBy(
                    ['yearGroup' => $application->getRequestedYearGroup()],
                    ['name' => 'ASC']
                )
                : $application->getRequestedClassroom(),
            'siblings'    => $this->em->getRepository(Application::class)
                ->createQueryBuilder('a')
                ->andWhere('a.id != :id')
                ->andWhere('(a.guardianEmail = :e OR a.guardianPhone = :p)')
                ->setParameter('id', $application->getId())
                ->setParameter('e', $application->getGuardianEmail())
                ->setParameter('p', $application->getGuardianPhone())
                ->getQuery()->getResult(),
        ]);
    }

    /**
     * Stream an uploaded document to a reviewer.
     *
     * `$applications_directory` sits under `public/`, so a bare link like
     * `/uploads/applications/abc123.jpg` is served by the web server to
     * anybody who guesses the name — fine for a school badge, not fine for a
     * child's birth certificate. This route keeps the check in the app.
     *
     * **Preview by default, download on request.** The review page opens the file
     * in place, so the reviewer reads the certificate without losing the form they
     * are reading it against; `?download=1` is the exception, for the odd case
     * where the office needs the file on disk.
     */
    #[Route('/{id}/document/{kind}', name: 'app_tenant_application_document', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function document(Application $application, string $kind, Request $request): Response
    {
        $files = [
            'photo'  => $application->getPassportPhotoFilename(),
            'birth'  => $application->getBirthCertificateFilename(),
            'result' => $application->getPreviousResultFilename(),
            'fee'    => $application->getFeeProofFilename(),
        ];

        $filename = $files[$kind] ?? null;
        if (!$filename) {
            throw $this->createNotFoundException('No such document.');
        }

        // basename() matters: a filename of "../../.env" must never escape the
        // upload directory.
        $path = rtrim((string) $this->getParameter('applications_directory'), '/')
              . '/' . basename($filename);

        if (!is_file($path)) {
            throw $this->createNotFoundException('Document missing from disk.');
        }

        $response = $this->file($path);
        $mime = (string) $response->getFile()->getMimeType();

        // Only the two shapes a browser renders safely are ever shown inline; a
        // stray .html or .svg is forced to download instead.
        $previewable = $mime === 'application/pdf' || str_starts_with($mime, 'image/');
        $inline = $previewable && !$request->query->getBoolean('download');

        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($filename)
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Decisions
    // ──────────────────────────────────────────────────────────────────

    /**
     * Approve & enrol. This is the one action that creates a real pupil.
     */
    #[Route('/{id}/enrol', name: 'app_tenant_application_enrol', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function enrol(Application $application, Request $request, AdmissionService $admissions): Response
    {
        if (!$this->isCsrfTokenValid('application_enrol_' . $application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if ($application->isFinal()) {
            $this->addFlash('error', 'This application has already been closed.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        // The parent applied for a LEVEL ("JSS 1"); the arm ("JSS 1A") is the
        // principal's decision, taken here at the moment of admission.
        $classroom = null;
        $requestedClassroomId = (int) $request->request->get('classroom', 0);
        if ($requestedClassroomId > 0) {
            $classroom = $this->em->getRepository(Classroom::class)->find($requestedClassroomId);
        }
        $classroom ??= $application->getRequestedClassroom();

        if (!$classroom) {
            $this->addFlash('error', 'Choose the class and arm to admit into before enrolling.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        $level = $application->getRequestedYearGroup();
        if ($level !== null && $classroom->getYearGroup()?->getId() !== $level->getId()) {
            $this->addFlash('error', sprintf(
                'The parent applied for %s. Choose an arm of that class.',
                $level->getName()
            ));
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        // Re-using the guardian keeps the family together: the second child of
        // the same parent must not create a second Guardian row.
        $guardian = $application->getGuardian() ?? $this->findOrCreateGuardian($application);

        $student = new Student();
        $student->setFirstName($application->getFirstName() ?? '');
        $student->setMiddleName($application->getMiddleName());
        $student->setLastName($application->getLastName() ?? '');
        // Student::$dateOfBirth is mapped `type: 'date'`, i.e. Doctrine's
        // DateType, which refuses a DateTimeImmutable ("Could not convert PHP
        // value of type DateTimeImmutable … Expected null, DateTime") — it is
        // what a Symfony DateType form field hands back, so the walk-in flow
        // never hit this. The Application stores an immutable, so convert.
        $student->setDateOfBirth(
            $application->getDateOfBirth() instanceof \DateTimeInterface
                ? \DateTime::createFromInterface($application->getDateOfBirth())
                : null
        );
        $student->setGender($application->getGender());
        $student->setReligion($application->getReligion());
        $student->setNationality($application->getNationality());
        $student->setStateOfOrigin($application->getStateOfOrigin());
        $student->setLga($application->getLga());
        $student->setHomeTown($application->getHomeTown());
        $student->setBloodGroup($application->getBloodGroup());
        $student->setGenotype($application->getGenotype());
        $student->setMedicalConditions($application->getMedicalConditions());
        $student->setPreviousSchool($application->getPreviousSchool());
        $student->setGuardian($guardian);
        $student->setCurrentClassroom($classroom);
        $student->setCreatedAt(new \DateTimeImmutable());

        // admission number → Enrollment → invoice + discount → SMS
        $admissions->admit($student, $classroom);

        $notes = trim((string) $request->request->get('notes', ''));
        $application->setStudent($student);
        $application->setGuardian($guardian);
        $application->setEnrolledAt(new \DateTimeImmutable());
        $application->setDecisionNotes($notes !== '' ? $notes : $application->getDecisionNotes());
        $this->transition($application, Application::STATUS_ENROLLED, $notes !== '' ? $notes : 'Approved and enrolled.');

        $guardian->setLastApplicationAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s admitted as %s — enrollment and first invoice created, guardian notified.',
            $application->getFullName(),
            $student->getAdmissionNumber() ?? '(no admission number)'
        ));

        return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
    }

    /** Request more information / reject / waitlist — no pupil is created. */
    #[Route('/{id}/status', name: 'app_tenant_application_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(Application $application, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('application_status_' . $application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        $to = (string) $request->request->get('status');
        $note = trim((string) $request->request->get('note', ''));

        $allowed = [
            Application::STATUS_UNDER_REVIEW => 'Application moved back to review.',
            Application::STATUS_NEEDS_INFO   => 'Information requested from the parent.',
            Application::STATUS_WAITLISTED   => 'Application placed on the waiting list.',
            Application::STATUS_REJECTED     => 'Application rejected.',
        ];

        if (!isset($allowed[$to])) {
            $this->addFlash('error', 'Unknown status.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if (in_array($to, [Application::STATUS_NEEDS_INFO, Application::STATUS_REJECTED], true) && $note === '') {
            $this->addFlash('error', 'Please write the reason — the parent sees this text.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if ($to === Application::STATUS_NEEDS_INFO) {
            $application->setInfoRequestedNote($note);
        }
        if ($to === Application::STATUS_REJECTED) {
            $application->setRejectionReason($note);
        }

        $this->transition($application, $to, $note !== '' ? $note : null);
        $this->em->flush();

        $this->addFlash('success', $allowed[$to]);

        return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
    }

    /**
     * Confirm an offline application-fee proof.
     *
     * This is the step that was missing: the parent could upload a transfer
     * proof, but no member of staff could ever confirm it, so the application
     * sat in `awaiting_payment` forever and the fee could not be reported as
     * income (docs/payments/FEES-REPORTING-DESIGN.md §3.2a).
     */
    #[Route('/{id}/fee/confirm', name: 'app_tenant_application_fee_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirmFee(Application $application, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('application_fee_confirm_' . $application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if ($application->getFeeStatus() !== Application::FEE_AWAITING_CONFIRMATION) {
            $this->addFlash('error', 'There is no fee proof waiting for confirmation on this application.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        // A bursar may correct the amount actually received (bank charges,
        // a partial transfer) before confirming.
        $actual = (float) $request->request->get('actual_amount', 0);
        if ($actual > 0) {
            $application->setFeeAmount(number_format($actual, 2, '.', ''));
        }

        $application->setFeeStatus(Application::FEE_PAID);
        $application->setFeePaidAt(new \DateTimeImmutable());
        if (!$application->getFeeChannel()) {
            $application->setFeeChannel(Application::CHANNEL_OFFLINE);
        }

        $this->advancePastFeeGate($application, 'Application fee confirmed.');

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Application fee of ₦%s confirmed. The parent can now fill in the application form.',
            number_format((float) $application->getFeeAmount(), 2)
        ));

        return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
    }

    /** Send a proof back to the parent: the fee is unpaid again, but the file is kept. */
    #[Route('/{id}/fee/reject', name: 'app_tenant_application_fee_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rejectFee(Application $application, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('application_fee_reject_' . $application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if ($application->getFeeStatus() !== Application::FEE_AWAITING_CONFIRMATION) {
            $this->addFlash('error', 'There is no fee proof waiting for confirmation on this application.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        $application->setFeeStatus(Application::FEE_UNPAID);
        $this->note($application, 'Application fee proof rejected. The parent must upload a new proof or pay online.');

        $this->em->flush();

        $this->addFlash('success', 'Fee proof rejected. The parent can now upload another proof.');

        return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
    }

    /** A school may decide to waive the fee at the desk (staff child, hardship). */
    #[Route('/{id}/fee/waive', name: 'app_tenant_application_fee_waive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function waiveFee(Application $application, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('application_fee_waive_' . $application->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        if ($application->getFeeStatus() === Application::FEE_PAID) {
            $this->addFlash('error', 'This fee is already paid; it cannot be waived.');
            return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
        }

        $application->setFeeStatus(Application::FEE_WAIVED);
        $this->advancePastFeeGate($application, 'Application fee waived at the school\'s discretion.');

        $this->em->flush();

        $this->addFlash('success', 'Application fee waived. The parent can now fill in the application form.');

        return $this->redirectToRoute('app_tenant_application_show', ['id' => $application->getId()]);
    }

    /**
     * Once the fee is settled, the application opens for the parent to fill.
     *
     * Pay-first means this transition goes to `draft`, not to the school's
     * queue: the money has been received, the child's details have not been
     * entered yet. The parent's own submit is what puts it in the queue.
     */
    private function advancePastFeeGate(Application $application, string $note): void
    {
        if ($application->getStatus() !== Application::STATUS_AWAITING_PAYMENT) {
            $this->note($application, $note);
            return;
        }

        $this->transition(
            $application,
            Application::STATUS_DRAFT,
            $note . ' The parent can now complete the application form.'
        );
    }

    /** Record a timeline note without changing the workflow status. */
    private function note(Application $application, string $text): void
    {
        $event = new ApplicationEvent();
        $event->setFromStatus($application->getStatus());
        $event->setToStatus($application->getStatus());
        $event->setNote($text);
        $event->setActor($this->getUser() instanceof User ? $this->getUser() : null);
        $event->setActorLabel($this->getUser()?->getUserIdentifier() ?? 'staff');
        $event->setCreatedAt(new \DateTimeImmutable());
        $application->addEvent($event);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

    /** @param bool $stampReview record `reviewedBy` / `reviewedAt` for this action */
    private function transition(Application $application, string $to, ?string $note, bool $stampReview = true): void
    {
        $from = $application->getStatus();

        $application->setStatus($to);

        if ($stampReview) {
            /** @var User|null $user */
            $user = $this->getUser();
            $application->setReviewedBy($user);
            $application->setReviewedAt(new \DateTimeImmutable());
        }

        $event = new ApplicationEvent();
        $event->setFromStatus($from);
        $event->setToStatus($to);
        $event->setNote($note);
        $event->setActor($this->getUser() instanceof User ? $this->getUser() : null);
        $event->setActorLabel($this->getUser()?->getUserIdentifier() ?? 'staff');
        $event->setCreatedAt(new \DateTimeImmutable());

        $application->addEvent($event);
    }

    /**
     * Match an existing Guardian by email, then by phone, before creating one.
     */
    private function findOrCreateGuardian(Application $application): Guardian
    {
        $repo = $this->em->getRepository(Guardian::class);

        $guardian = null;
        if ($application->getGuardianEmail()) {
            $guardian = $repo->findOneBy(['email' => $application->getGuardianEmail()]);
        }
        if (!$guardian && $application->getGuardianPhone()) {
            $guardian = $repo->findOneBy(['phoneNumber' => $application->getGuardianPhone()]);
        }
        if ($guardian) {
            return $guardian;
        }

        $guardian = new Guardian();
        $guardian->setTitle(null);
        $guardian->setFullName($application->getGuardianName() ?? 'Guardian of ' . $application->getFullName());
        if ($application->getGuardianEmail()) {
            $guardian->setEmail($application->getGuardianEmail());
        }
        if ($application->getGuardianPhone()) {
            $guardian->setPhoneNumber($application->getGuardianPhone());
        }
        $guardian->setAlternatePhoneNumber($application->getGuardianAlternatePhone());
        $guardian->setAddress($application->getGuardianAddress());
        $guardian->setOccupation($application->getGuardianOccupation());
        $guardian->setOfficeAddress($application->getGuardianOfficeAddress());
        $guardian->setRelationshipToStudent($application->getRelationshipToStudent());
        $guardian->setSource('self');
        $guardian->setLastApplicationAt(new \DateTimeImmutable());

        $this->em->persist($guardian);

        return $guardian;
    }

    /** @return array<string,int> */
    private function counters(): array
    {
        $rows = $this->em->getRepository(Application::class)
            ->createQueryBuilder('a')
            ->select('a.status AS status, COUNT(a.id) AS n')
            ->groupBy('a.status')
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['n'];
        }

        $queue = 0;
        foreach (self::QUEUE_STATUSES as $s) {
            $queue += $out[$s] ?? 0;
        }
        $out['queue'] = $queue;
        $out['all']   = array_sum($out) - $queue;

        return $out;
    }
}
