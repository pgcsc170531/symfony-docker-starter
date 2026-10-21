<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
use App\Service\Payment\OnlinePaymentAvailability;
use App\Service\Payment\PaymentGatewayInterface;
use App\Service\Payment\PaymentChargePolicy;
use App\Service\Payment\ProcessingFeeCalculator;
use App\Service\Payment\SettlementService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Online school-fee payment, from the parent's side.
 *
 * The journey is deliberately short — a parent on a phone must be able to pay in
 * two taps: "Pay now" on the invoice, then the provider's page. Everything the
 * parent needs to know (exactly what is owed, what the extra charge is for) is on
 * the screen before they are sent anywhere.
 *
 * See docs/payments/PAYMENT-FLOW-DESIGN.md.
 */
#[Route('/parent/payments')]
#[IsGranted('ROLE_PARENT')]
class OnlinePaymentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentGatewayInterface $gateway,
        private PaymentChargePolicy $charges,
        private ProcessingFeeCalculator $feeCalculator,
        private SettlementService $settlement,
        private LoggerInterface $logger,
        private OnlinePaymentAvailability $availability,
    ) {
    }

    /**
     * Start (or resume) a payment for an invoice.
     *
     * POST because it creates something — and because a link that moves money
     * should never be something a browser prefetches.
     */
    #[Route('/start/{id}', name: 'app_parent_payment_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(Invoice $invoice, Request $request): Response
    {
        $student = $invoice->getStudent();
        $this->assertParentOwnsStudent($student);

        if (!$this->isCsrfTokenValid('pay_start_' . $invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $backToFinance = $this->redirectToRoute('app_parent_finance_view', ['id' => $student?->getId()]);

        $school = $this->currentSchool();
        if (!$this->availability->isAvailable($school)) {
            $this->addFlash('error', 'Online payment is not switched on for this school yet.');

            return $backToFinance;
        }

        $balance = $invoice->getBalanceDue();
        if ($balance <= 0) {
            $this->addFlash('info', 'This invoice is already fully paid.');

            return $backToFinance;
        }

        // Resume an unfinished attempt instead of stacking up new ones — a parent
        // who taps Pay twice, or whose network drops, should not end up with two
        // live references at the provider.
        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy([
            'invoice' => $invoice,
            'status' => PaymentIntent::STATUS_PENDING,
        ]);

        if ($intent === null) {
            // Never worked out here: the school may have turned the charge off.
            $fee = $this->charges->feeFor($school, $balance);

            $intent = new PaymentIntent();
            $intent->setReference(PaymentIntent::newReference());
            $intent->setInvoice($invoice);
            $intent->setStudent($student);
            $intent->setProvider($this->gateway->name());
            $intent->setInvoiceAmount(number_format($balance, 2, '.', ''));
            $intent->setProcessingFee(number_format($fee, 2, '.', ''));
            $intent->setAmount(number_format($balance + $fee, 2, '.', ''));
            $this->em->persist($intent);
            $this->em->flush();
        }

        if ($intent->getAuthorizationUrl() === null) {
            $result = $this->gateway->initialize($intent, $school);

            if (!$result->ok || $result->authorizationUrl === null) {
                $this->logger->warning('Payment initialise failed for {reference}: {error}', [
                    'reference' => $intent->getReference(),
                    'error' => $result->error ?? 'unknown',
                ]);
                $this->addFlash('error', $result->error ?? 'We could not start the payment. Please try again.');

                return $this->redirectToRoute('app_parent_payment_status', ['reference' => $intent->getReference()]);
            }

            $intent->setAuthorizationUrl($result->authorizationUrl);
            $intent->setAccessCode($result->accessCode);
            $intent->setProviderReference($result->providerReference);
            $intent->setProvider($this->gateway->name());
            $this->em->flush();
        }

        return $this->redirect($intent->getAuthorizationUrl());
    }

    /**
     * Start (or resume) the application fee for one application.
     *
     * Same machinery as a school-fee payment — intent, gateway, callback,
     * webhook, settlement — with `purpose = application_fee`. The application
     * itself is the thing being paid for, so it is the ownership anchor.
     */
    #[Route('/application/{id}', name: 'app_parent_application_pay', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function payApplication(Application $application, Request $request): Response
    {
        $this->assertParentOwnsApplication($application);

        if (!$this->isCsrfTokenValid('pay_application_' . $application->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $back = $this->redirectToRoute('app_parent_applications_show', ['id' => $application->getId()]);

        $school = $this->currentSchool();
        if (!$this->availability->isAvailable($school)) {
            $this->addFlash('error', 'Online payment is not switched on for this school yet.');

            return $back;
        }

        if ($application->getStatus() !== Application::STATUS_AWAITING_PAYMENT) {
            $this->addFlash('info', 'This application is not waiting for a payment.');

            return $back;
        }

        if (in_array($application->getFeeStatus(), [Application::FEE_PAID, Application::FEE_WAIVED], true)) {
            $this->addFlash('info', 'The application fee is already settled.');

            return $back;
        }

        // A receipt already uploaded is waiting for a human. Paying again on top
        // of it is how a family ends up paying twice.
        if ($application->getFeeStatus() === Application::FEE_AWAITING_CONFIRMATION) {
            $this->addFlash('info', 'Your uploaded receipt is being checked by the school. Contact the office if you would rather pay online instead.');

            return $back;
        }

        $fee = (float) ($application->getFeeAmount() ?? $school->getApplicationFeeAmount() ?? 0);
        if ($fee <= 0) {
            $this->addFlash('info', 'This application has no fee to pay.');

            return $back;
        }

        // One live attempt per application, like one per invoice.
        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy([
            'application' => $application,
            'purpose' => PaymentIntent::PURPOSE_APPLICATION_FEE,
            'status' => PaymentIntent::STATUS_PENDING,
        ]);

        if ($intent === null) {
            $processing = $this->charges->feeFor($school, $fee);

            $intent = new PaymentIntent();
            $intent->setReference(PaymentIntent::newReference());
            $intent->setPurpose(PaymentIntent::PURPOSE_APPLICATION_FEE);
            $intent->setApplication($application);
            $intent->setProvider($this->gateway->name());
            $intent->setInvoiceAmount(number_format($fee, 2, '.', ''));
            $intent->setProcessingFee(number_format($processing, 2, '.', ''));
            $intent->setAmount(number_format($fee + $processing, 2, '.', ''));
            $this->em->persist($intent);
            $this->em->flush();
        }

        if ($intent->getAuthorizationUrl() === null) {
            $result = $this->gateway->initialize($intent, $school);

            if (!$result->ok || $result->authorizationUrl === null) {
                $this->logger->warning('Application fee initialise failed for {reference}: {error}', [
                    'reference' => $intent->getReference(),
                    'error' => $result->error ?? 'unknown',
                ]);
                $this->addFlash('error', $result->error ?? 'We could not start the payment. Please try again.');

                return $this->redirectToRoute('app_parent_payment_status', ['reference' => $intent->getReference()]);
            }

            $intent->setAuthorizationUrl($result->authorizationUrl);
            $intent->setAccessCode($result->accessCode);
            $intent->setProviderReference($result->providerReference);
            $intent->setProvider($this->gateway->name());
            $this->em->flush();
        }

        return $this->redirect($intent->getAuthorizationUrl());
    }

    /**
     * The payment's own page: what is owed, then the live result.
     *
     * Also the safe place to land when a parent abandons the provider's page —
     * they can come back and try again from here.
     */
    #[Route('/{reference}', name: 'app_parent_payment_status', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $intent = $this->findIntent($reference);
        $this->assertParentOwnsIntent($intent);

        // Coming back from the provider means we have a decision to make. Ask the
        // gateway — the browser's word is not evidence of payment.
        if ($intent->isSettleable() && $intent->getAuthorizationUrl() !== null) {
            $this->reconcile($intent);
        }

        return $this->render(
            $intent->isApplicationFee()
                ? 'tenant/parent/application_payment_status.html.twig'
                : 'tenant/parent/payment_status.html.twig',
            [
                'intent' => $intent,
                'invoice' => $intent->getInvoice(),
                'student' => $intent->getStudent(),
                'application' => $intent->getApplication(),
                'school' => $this->currentSchool(),
            ]
        );
    }

    /** Polled by the status page so a parent never has to refresh by hand. */
    #[Route('/{reference}/status.json', name: 'app_parent_payment_status_json', methods: ['GET'])]
    public function statusJson(string $reference): JsonResponse
    {
        $intent = $this->findIntent($reference);
        $this->assertParentOwnsIntent($intent);

        if ($intent->isSettleable()) {
            $this->reconcile($intent);
        }

        return new JsonResponse([
            'reference' => $intent->getReference(),
            'status' => $intent->getStatus(),
            'paid' => $intent->isPaid(),
            'settled' => $intent->isPaid(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ask the gateway what happened, and settle if it says paid.
     *
     * Wrapped so a provider hiccup shows the parent a calm "we're still
     * checking" rather than a stack trace.
     */
    private function reconcile(PaymentIntent $intent): void
    {
        if ($this->gateway->name() === PaymentIntent::PROVIDER_FAKE
            && $intent->getProviderReference() === null) {
            return; // nothing has been simulated yet
        }

        try {
            $result = $this->gateway->verify($intent);
        } catch (\Throwable $e) {
            $this->logger->error('Payment verify threw for {reference}: {message}', [
                'reference' => $intent->getReference(),
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $intent->setRawPayload(json_encode($result->raw) ?: null);

        if ($result->isPaid()) {
            // Settlement itself is idempotent; it also emails the receipt.
            $settled = $this->settlement->settle($intent, (string) $intent->getRawPayload());

            // A settlement that rolled back closes the EntityManager; flushing
            // after that throws a second, more confusing error. The status page
            // will retry, and the webhook is the backstop.
            if (!$this->em->isOpen()) {
                return;
            }

            if (!$settled && !$intent->isPaid()) {
                return;
            }
        } elseif ($result->status === PaymentIntent::STATUS_FAILED) {
            $intent->setStatus(PaymentIntent::STATUS_FAILED);
        }

        $this->em->flush();
    }

    private function findIntent(string $reference): PaymentIntent
    {
        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(['reference' => $reference]);

        if ($intent === null) {
            throw $this->createNotFoundException('That payment could not be found.');
        }

        return $intent;
    }

    private function currentSchool(): ?School
    {
        return $this->em->getRepository(School::class)->findOneBy([]);
    }

    /**
     * A parent may only pay for their own children — or their own application.
     */
    private function assertParentOwnsStudent(?object $student): void
    {
        if ($student === null) {
            throw $this->createAccessDeniedException('This invoice is not linked to a student.');
        }

        $guardian = $this->currentGuardian();

        if ($guardian === null || !$guardian->getStudents()->contains($student)) {
            throw $this->createAccessDeniedException('You are not authorised to pay for this student.');
        }
    }

    private function assertParentOwnsApplication(?Application $application): void
    {
        if ($application === null) {
            throw $this->createNotFoundException('That application could not be found.');
        }

        $guardian = $this->currentGuardian();

        if ($guardian === null || $application->getGuardian()?->getId() !== $guardian->getId()) {
            throw $this->createAccessDeniedException('You are not authorised to pay for this application.');
        }
    }

    /** The signed-in parent's guardian record (one per account). */
    private function currentGuardian(): ?Guardian
    {
        return $this->em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);
    }

    private function assertParentOwnsIntent(PaymentIntent $intent): void
    {
        if ($intent->isApplicationFee()) {
            $this->assertParentOwnsApplication($intent->getApplication());

            return;
        }

        $this->assertParentOwnsStudent($intent->getStudent());
    }
}
