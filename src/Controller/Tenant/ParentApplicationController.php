<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\ApplicationEvent;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\State;
use App\Form\Tenant\ParentApplicationType;
use App\Service\Payment\OnlinePaymentAvailability;
use App\Service\Payment\PaymentChargePolicy;
use App\Service\Payment\ProcessingFeeCalculator;
use App\Service\SchoolMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The parent portal surface of the online application module
 * (docs/parent-application/DESIGN.md §4, §7.2, §8 items 4-6).
 *
 * Lives under ^/parent (not ^/apply) on purpose: ^/apply stays entirely PUBLIC_ACCESS
 * and ^/parent entirely ROLE_PARENT, so there is no fragile rule ordering in
 * security.yaml — the exact class of bug that broke /admin.
 *
 * Every action below re-checks ownership: an application is only ever reachable by the
 * guardian who created it (same guard style as app_parent_finance_view).
 */
#[Route('/parent/applications')]
#[IsGranted('ROLE_PARENT')]
class ParentApplicationController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    private const MAX_UPLOAD_BYTES = 4_194_304; // 4 MB
    private const CONSENT_VERSION = 'v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly SchoolMailer $mailer,
        private readonly OnlinePaymentAvailability $onlinePayments,
        private readonly PaymentChargePolicy $charges,
        private readonly ProcessingFeeCalculator $feeCalculator,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────
    // List
    // ──────────────────────────────────────────────────────────────────────

    #[Route('', name: 'app_parent_applications_index', methods: ['GET'])]
    public function index(): Response
    {
        $guardian = $this->currentGuardian();
        $school = $this->currentSchool();

        $applications = $this->em->getRepository(Application::class)->findBy(
            ['guardian' => $guardian],
            ['createdAt' => 'DESC']
        );

        return $this->render('tenant/parent/applications/index.html.twig', [
            'applications' => $applications,
            'school' => $school,
            'guardian' => $guardian,
            'applicationsOpen' => $school?->isApplicationsOpen() ?? true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Create / edit the child's application
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Start an application.
     *
     * **Payment comes first.** A parent pays the form fee before they can fill
     * anything in, so an application is created here as a fee-bearing shell —
     * no child details, no reference number, and no place in the school's queue.
     * The wizard unlocks only after the fee is paid or waived.
     */
    #[Route('/new', name: 'app_parent_applications_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $guardian = $this->currentGuardian();
        $school = $this->currentSchool();

        if ($school && !$school->isApplicationsOpen()) {
            $this->addFlash('error', 'Applications are currently closed for this school.');

            return $this->redirectToRoute('app_parent_applications_index');
        }

        if ($guardian === null) {
            throw $this->createAccessDeniedException('No parent profile is linked to this account.');
        }

        // One unpaid shell at a time: paying twice for one child, or stacking
        // empty applications, is never what the parent meant.
        $unpaid = $this->em->getRepository(Application::class)->findOneBy([
            'guardian' => $guardian,
            'status' => Application::STATUS_AWAITING_PAYMENT,
        ]);

        if ($unpaid !== null) {
            return $this->redirectToRoute('app_parent_applications_show', ['id' => $unpaid->getId()]);
        }

        $application = new Application();
        $application->setSource(Application::SOURCE_PARENT_PORTAL);
        $application->setGuardian($guardian);
        $this->snapshotGuardian($application, $guardian);

        $fee = (float) ($school?->getApplicationFeeAmount() ?? 0);

        if ($fee <= 0) {
            // A free application has nothing to pay for: open the form.
            $application->setStatus(Application::STATUS_DRAFT);
            $application->setFeeStatus(Application::FEE_WAIVED);
            $application->setFeeAmount(null);
            $this->em->persist($application);
            $this->em->flush();

            return $this->redirectToRoute('app_parent_applications_edit', ['id' => $application->getId()]);
        }

        $application->setStatus(Application::STATUS_AWAITING_PAYMENT);
        $application->setFeeStatus(Application::FEE_UNPAID);
        $application->setFeeAmount(number_format($fee, 2, '.', ''));
        $this->em->persist($application);
        $this->em->flush();

        return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
    }

    #[Route('/{id}/edit', name: 'app_parent_applications_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Application $application, Request $request): Response
    {
        $this->assertOwnedByCurrentParent($application);

        // The form is behind the fee. This is the whole point of pay-first: no
        // application can be filled, let alone submitted, before the money is in.
        if (!$application->hasPassedFeeGate()) {
            // Applications started under the old flow may still be `draft` with
            // an unpaid fee. Move them to the pay screen rather than leaving
            // them in a state with no way forward.
            if ($application->getStatus() === Application::STATUS_DRAFT) {
                $application->setStatus(Application::STATUS_AWAITING_PAYMENT);
                $this->em->flush();
            }

            $this->addFlash('info', 'Pay the application fee first — the form opens as soon as it is confirmed.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        if (!$application->isEditableByParent()) {
            $this->addFlash('error', 'This application can no longer be edited.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        // Keep the guardian snapshot in step with the verified account details.
        $this->snapshotGuardian($application, $this->currentGuardian());

        return $this->handleWizard($request, $application, isNew: false);
    }

    /**
     * State → LGA cascade for the wizard's step 2.
     *
     * The staff equivalent (app_tenant_api_lgas in AdmissionController) sits behind
     * #[IsGranted('ROLE_BURSAR')], so a parent's fetch was rejected with a 403 — which the
     * client swallowed, leaving the LGA dropdown unfiltered. This is public reference
     * geography, so a parent-scoped copy is the right place for it.
     */
    #[Route('/api/lgas/{id}', name: 'app_parent_applications_api_lgas', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function lgasByState(State $state): JsonResponse
    {
        $lgas = [];
        foreach ($state->getLgas() as $lga) {
            $lgas[] = ['id' => $lga->getId(), 'name' => $lga->getName()];
        }

        return new JsonResponse($lgas);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Detail + timeline
    // ──────────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'app_parent_applications_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Application $application): Response
    {
        $this->assertOwnedByCurrentParent($application);

        $school = $this->currentSchool();

        // The parent must see the exact total before leaving for the provider —
        // the same rule the school-fee payment screen follows.
        $fee = (float) ($application->getFeeAmount() ?? $school?->getApplicationFeeAmount() ?? 0);
        $feeProcessing = $this->charges->feeFor($school, $fee);

        return $this->render('tenant/parent/applications/show.html.twig', [
            'application' => $application,
            'school' => $school,
            'events' => $application->getEvents()->toArray(),
            'onlinePaymentAvailable' => $this->onlinePayments->isAvailable($school),
            'feeProcessing' => $feeProcessing,
            'feeTotal' => $fee + $feeProcessing,
            'feeGatesPassed' => $application->hasPassedFeeGate(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // State transitions
    // ──────────────────────────────────────────────────────────────────────

    /** Submit a saved draft straight from the list or the detail page. */
    #[Route('/{id}/submit', name: 'app_parent_applications_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(Application $application, Request $request): Response
    {
        $this->assertOwnedByCurrentParent($application);

        if (!$application->isEditableByParent()) {
            $this->addFlash('error', 'This application has already been submitted.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        if (!$this->isCsrfTokenValid('app_submit_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $this->finalizeSubmission($application, $request);

        return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
    }

    #[Route('/{id}/withdraw', name: 'app_parent_applications_withdraw', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function withdraw(Application $application, Request $request): Response
    {
        $this->assertOwnedByCurrentParent($application);

        if (!$this->isCsrfTokenValid('app_withdraw_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        if ($application->isFinal() || $application->getStatus() === Application::STATUS_ENROLLED) {
            $this->addFlash('error', 'This application can no longer be withdrawn.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        $from = $application->getStatus();
        $application->setStatus(Application::STATUS_WITHDRAWN);
        $this->logEvent($application, $from, Application::STATUS_WITHDRAWN, 'Withdrawn by the parent.');
        $this->em->flush();

        $this->addFlash('success', 'Application withdrawn.');

        return $this->redirectToRoute('app_parent_applications_index');
    }

    /** Drafts only — once submitted it is a school record, so deleting is off the table. */
    #[Route('/{id}/delete', name: 'app_parent_applications_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Application $application, Request $request): Response
    {
        $this->assertOwnedByCurrentParent($application);

        if (!$this->isCsrfTokenValid('app_delete_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        if ($application->getStatus() !== Application::STATUS_DRAFT) {
            $this->addFlash('error', 'Only drafts can be deleted.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        foreach ($application->getEvents() as $event) {
            $this->em->remove($event);
        }
        $this->em->remove($application);
        $this->em->flush();

        $this->addFlash('success', 'Draft deleted.');

        return $this->redirectToRoute('app_parent_applications_index');
    }

    /**
     * Offline fee proof (DESIGN §6.2): the parent transfers, then uploads the evidence.
     * feeStatus goes unpaid → awaiting_confirmation; the bursar confirms it in Phase 4.
     */
    #[Route('/{id}/fee-proof', name: 'app_parent_applications_fee_proof', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function feeProof(Application $application, Request $request): Response
    {
        $this->assertOwnedByCurrentParent($application);

        // Proof belongs to the fee shell. A submitted application must not be
        // pushed back into "awaiting confirmation" by an upload.
        if ($application->getStatus() !== Application::STATUS_AWAITING_PAYMENT) {
            $this->addFlash('error', 'This application is no longer waiting for a fee.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        if (!$this->isCsrfTokenValid('app_feeproof_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $file = $request->files->get('feeProof');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Please choose the transfer receipt first.');

            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        $stored = $this->storeUpload($file, 'feeproof');
        if ($stored === null) {
            return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
        }

        $application->setFeeProofFilename($stored);
        $application->setFeeStatus(Application::FEE_AWAITING_CONFIRMATION);
        $application->setFeeChannel(Application::CHANNEL_OFFLINE);
        $application->setFeeReference((string) $request->request->get('feeReference', ''));
        $this->logEvent(
            $application,
            $application->getStatus(),
            (string) $application->getStatus(),
            'Payment proof uploaded — waiting for the bursar to confirm.'
        );
        $this->em->flush();

        $this->addFlash('success', 'Thank you — your payment proof was uploaded and is waiting to be confirmed.');

        return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // The wizard
    // ──────────────────────────────────────────────────────────────────────

    private function handleWizard(Request $request, Application $application, bool $isNew): Response
    {
        $form = $this->createForm(ParentApplicationType::class, $application);
        $form->handleRequest($request);

        $initialStep = max(1, min(Application::TOTAL_STEPS, $application->getLastCompletedStep() ?? 1));
        $errors = [];

        if ($form->isSubmitted()) {
            $intent = (string) $request->request->get('wizard_action', 'draft');
            $step = (int) $request->request->get('current_step', 1);
            $application->setLastCompletedStep(max(1, min(Application::TOTAL_STEPS, $step)));

            if ($intent === 'submit') {
                if ($form->isValid()) {
                    $this->handleUploads($request, $application);
                    $this->finalizeSubmission($application, $request);

                    return $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);
                }

                // Send the parent straight back to the tab that needs attention.
                $initialStep = $this->firstStepWithErrors($form) ?? $step;
                $errors[] = 'Some details need your attention before this can be submitted.';
            } else {
                // Save as draft: no validation, an incomplete form is expected.
                $this->handleUploads($request, $application);
                $this->em->persist($application);
                $this->em->flush();

                $this->addFlash('success', $isNew
                    ? 'Draft saved. You can finish it any time.'
                    : 'Changes saved.');

                return $this->redirectToRoute('app_parent_applications_edit', ['id' => $application->getId()]);
            }
        }

        return $this->render('tenant/parent/applications/form.html.twig', [
            'form' => $form->createView(),
            'application' => $application,
            'school' => $this->currentSchool(),
            'isNew' => $isNew,
            'initialStep' => $initialStep,
            'totalSteps' => Application::TOTAL_STEPS,
            'wizardErrors' => $errors,
        ]);
    }

    /**
     * Stamp the application as submitted and decide where the fee gate leaves it
     * (DESIGN §6.2). The reference is only assigned once, on the first submit.
     */
    private function finalizeSubmission(Application $application, Request $request): void
    {
        $from = (string) $application->getStatus();
        $school = $this->currentSchool();
        $isResubmission = $application->getReference() !== null;

        $application->setSubmittedAt(new \DateTimeImmutable());
        $application->setIpAddress($request->getClientIp());
        $application->setConsentAt(new \DateTimeImmutable());
        $application->setConsentVersion(self::CONSENT_VERSION);

        // The fee is settled before this form is reachable (`new()` creates the
        // shell and `edit()` refuses until it is paid). This is the last line of
        // defence: an application must never become a submission unpaid.
        if (!$application->hasPassedFeeGate()) {
            throw $this->createAccessDeniedException('The application fee has not been settled.');
        }

        if ($isResubmission) {
            $application->setStatus(Application::STATUS_SUBMITTED);
            $this->logEvent($application, $from, Application::STATUS_SUBMITTED, 'Updated and resubmitted by the parent.');
        } else {
            // A school can switch approval off and let submissions land as approved.
            $status = ($school?->isApplicationRequireApproval() ?? true)
                ? Application::STATUS_SUBMITTED
                : Application::STATUS_APPROVED;
            $application->setStatus($status);
            $this->logEvent($application, $from, $status, 'Submitted by the parent. Application fee settled.');
        }

        $this->em->persist($application);

        // Reference generation and the insert/update must be atomic, hence one transaction
        // around both (generateReference() relies on the lock the SELECT … FOR UPDATE takes).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            if (!$isResubmission) {
                $application->setReference($this->generateReference());
            }
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->logger->info('Application submitted', [
            'reference' => $application->getReference(),
            'guardianId' => $application->getGuardian()?->getId(),
            'status' => $application->getStatus(),
        ]);

        // Acknowledgement email — the parent's proof of submission. Fires once,
        // on the first submission. A school can switch it off in settings, and a
        // mail outage must never undo a submission (SchoolMailer never throws).
        if (!$isResubmission && $school !== null) {
            $this->mailer->applicationSubmitted(
                $school,
                $application,
                $this->generateUrl(
                    'app_parent_applications_show',
                    ['id' => $application->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                ($application->getFeeAmount() !== null && (float) $application->getFeeAmount() > 0)
                    ? (float) $application->getFeeAmount()
                    : null
            );
        }

        $this->addFlash('success', $isResubmission
            ? 'Your changes were sent back to the school.'
            : 'Application received. Keep your reference number safe.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Copy the verified account details onto the application.
     *
     * The wizard renders these fields disabled, so a parent cannot change the phone number
     * the school uses for SMS by tampering with the request — this method wins, every save.
     */
    private function snapshotGuardian(Application $application, Guardian $guardian): void
    {
        $application->setGuardian($guardian);
        $application->setGuardianName($guardian->getFullName());
        $application->setGuardianPhone($guardian->getPhoneNumber());
        $application->setGuardianEmail($guardian->getEmail());
        $application->setRelationshipToStudent($guardian->getRelationshipToStudent());
    }

    /**
     * APP/2026/0001 — highest sequence for this year + 1.
     *
     * MUST be called inside an open transaction (see finalizeSubmission): the
     * SELECT … FOR UPDATE takes a lock on the "APP/<year>/%" range so two parents
     * submitting at the same instant cannot be handed the same number. The
     * UNIQ_application_reference index is the hard backstop if that ever slips.
     *
     * This is deliberately not the pattern used by the ADM/ generator
     * (`lastStudent->getId() + 1`), which is not race-safe — see DESIGN.md §9.3.
     */
    private function generateReference(): string
    {
        $prefix = 'APP/' . (new \DateTimeImmutable())->format('Y') . '/';

        $last = $this->em->getConnection()->fetchOne(
            'SELECT reference FROM application WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1 FOR UPDATE',
            [$prefix . '%']
        );

        $next = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
            $next = ((int) $matches[1]) + 1;
        }

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function logEvent(Application $application, ?string $from, string $to, string $note): void
    {
        $event = new ApplicationEvent();
        $event->setFromStatus($from);
        $event->setToStatus($to);
        $event->setNote($note);
        $event->setActorLabel($this->getUser()?->getUserIdentifier() ?? 'parent');
        $event->setCreatedAt(new \DateTimeImmutable());

        $application->addEvent($event);
    }

    /** Pulls the three document uploads off the request, if present. */
    private function handleUploads(Request $request, Application $application): void
    {
        $map = [
            'passportPhoto' => fn (string $name) => $application->setPassportPhotoFilename($name),
            'birthCertificate' => fn (string $name) => $application->setBirthCertificateFilename($name),
            'previousResult' => fn (string $name) => $application->setPreviousResultFilename($name),
        ];

        foreach ($map as $field => $setter) {
            $file = $request->files->get($field);
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $stored = $this->storeUpload($file, $field);
            if ($stored !== null) {
                $setter($stored);
            }
        }
    }

    private function storeUpload(UploadedFile $file, string $prefix): ?string
    {
        if (!$file->isValid()) {
            $this->addFlash('error', sprintf('“%s” could not be uploaded, please try again.', $file->getClientOriginalName()));

            return null;
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            $this->addFlash('error', sprintf('“%s” is larger than 4 MB and was not uploaded.', $file->getClientOriginalName()));

            return null;
        }

        $extension = strtolower($file->guessExtension() ?: (string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->addFlash('error', sprintf('“%s” must be a JPG, PNG or PDF file.', $file->getClientOriginalName()));

            return null;
        }

        $filename = sprintf('%s-%s.%s', $prefix, bin2hex(random_bytes(8)), $extension);
        $file->move((string) $this->getParameter('applications_directory'), $filename);

        return $filename;
    }

    private function currentGuardian(): Guardian
    {
        $guardian = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);

        if (!$guardian) {
            throw $this->createAccessDeniedException('No parent profile is linked to your account.');
        }

        return $guardian;
    }

    private function currentSchool(): ?School
    {
        return $this->em->getRepository(School::class)->findOneBy([]);
    }

    /** Ownership guard: a parent may only ever touch their own applications. */
    private function assertOwnedByCurrentParent(Application $application): void
    {
        if ($application->getGuardian()?->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You are not authorised to view this application.');
        }
    }

    /** Which wizard tab does the first error belong to, so we can jump there. */
    private function firstStepWithErrors(FormInterface $form): ?int
    {
        $step = null;

        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            if ($origin === null || $origin->getParent() === null) {
                continue;
            }

            $candidate = $this->stepForField($origin->getName());
            $step = $step === null ? $candidate : min($step, $candidate);
        }

        return $step;
    }

    private function stepForField(string $name): int
    {
        return match (true) {
            in_array($name, ['firstName', 'middleName', 'lastName', 'dateOfBirth', 'gender', 'religion', 'requestedYearGroup'], true) => 1,
            in_array($name, ['nationality', 'stateOfOrigin', 'lga', 'homeTown', 'bloodGroup', 'genotype', 'medicalConditions'], true) => 2,
            in_array($name, ['previousSchool', 'previousClass'], true) => 3,
            str_starts_with($name, 'guardian') => 4,
            default => 5,
        };
    }
}
