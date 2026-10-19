<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Landlord\TermRegistration;
use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\School as TenantSchool;
use App\Entity\Tenant\Session as TenantSession;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Form\TenantSubscriptionPaymentType;
use App\Service\Payment\PaystackClient;
use App\Service\Payment\ProcessingFeeCalculator;
use App\Service\Pricing\PricingCatalog;
use App\Service\Subscription\RegistrationInviteService;
use App\Service\Subscription\SubscriptionActivationService;
use App\Service\Subscription\BillingBankAccount;
use App\Service\Subscription\SubscriptionInvoicePdfRenderer;
use App\Service\Subscription\SubscriptionMailer;
use App\Service\Subscription\SubscriptionReceiptPdfRenderer;
use App\Service\Subscription\SubscriptionStatementPdfRenderer;
use App\Service\Subscription\TermQuoteService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/subscription')]
class SubscriptionController extends AbstractController
{
    public function __construct(private readonly TermQuoteService $quoteService)
    {
    }

    /**
     * The school's billing home: what they are on, what it costs at their roll,
     * and the two tracks side by side. Enterprise is deliberately not sold here —
     * it is a conversation, not a checkout.
     */
    #[Route('/', name: 'tenant_subscription_index', methods: ['GET'])]
    public function index(Request $request, ManagerRegistry $doctrine): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        $tenantEm = $doctrine->getManager();

        $school = $this->getLandlordSchool($doctrine);
        $subscription = $school->getSubscription();

        $roll = (int) $tenantEm->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $plans = $landlordEm->getRepository(Plan::class)->findBy(
            ['isPublic' => true],
            ['pricingModel' => 'ASC', 'price' => 'ASC']
        );

        $standard = null;
        $enterprise = null;
        foreach ($plans as $plan) {
            if ($standard === null && $plan->isPerStudent()) {
                $standard = $plan;
            }
            if ($enterprise === null && $plan->isLicence()) {
                $enterprise = $plan;
            }
        }

        $termlyQuote = $annualQuote = null;
        if ($standard !== null && $this->quoteService->isConfigured($standard)) {
            $termlyQuote = $this->quoteService->standardQuote($standard, max(1, $roll), TermRegistration::CYCLE_TERMLY);
            $annualQuote = $this->quoteService->standardQuote($standard, max(1, $roll), TermRegistration::CYCLE_ANNUAL);
        }

        // The toggle on the page is a plain link with ?cycle=… — it works on a
        // phone with no JavaScript and always agrees with the invoice.
        $cycle = $request->query->get('cycle') === TermRegistration::CYCLE_ANNUAL
            ? TermRegistration::CYCLE_ANNUAL
            : TermRegistration::CYCLE_TERMLY;

        $pendingPayment = $landlordEm->getRepository(SubscriptionPayment::class)
            ->createQueryBuilder('p')
            ->where('p.school = :school')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('school', $school)
            ->setParameter('statuses', ['PENDING', 'VERIFYING'])
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $registrations = $landlordEm->getRepository(TermRegistration::class)->findBy(
            ['school' => $school],
            ['createdAt' => 'DESC'],
            5
        );

        return $this->render('tenant/subscription/index.html.twig', [
            'plans' => $plans,
            'standard' => $standard,
            'enterprise' => $enterprise,
            'standard_ready' => $standard !== null && $this->quoteService->isConfigured($standard),
            'enterprise_summary' => $enterprise !== null
                ? $this->quoteService->enterpriseSummary($enterprise)
                : null,
            'subscription' => $subscription,
            'roll' => $roll,
            'cycle' => $cycle,
            'quote' => $cycle === TermRegistration::CYCLE_ANNUAL ? $annualQuote : $termlyQuote,
            'termly_quote' => $termlyQuote,
            'annual_quote' => $annualQuote,
            'pending_payment' => $pendingPayment,
            'registrations' => $registrations,
            'period' => $this->currentPeriod($tenantEm),
            'standard_below' => PricingCatalog::STANDARD_BELOW,
            'enterprise_from' => PricingCatalog::ENTERPRISE_FROM,
        ]);
    }

    /**
     * Value & Billing statement — one printable page: plan, roll, invoices,
     * settled, outstanding, renewal date. PDF only, by design.
     */
    #[Route('/statement.pdf', name: 'tenant_subscription_statement', methods: ['GET'])]
    public function statement(
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionStatementPdfRenderer $renderer
    ): Response {
        $landlordEm = $doctrine->getManager('landlord');
        $tenantEm = $doctrine->getManager();

        $school = $this->getLandlordSchool($doctrine);
        $tenantSchool = $this->getUser()?->getSchool();

        $roll = (int) $tenantEm->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $payments = $landlordEm->getRepository(SubscriptionPayment::class)->findBy(
            ['school' => $school],
            ['createdAt' => 'DESC'],
            60
        );

        $invoiced = $paid = $outstanding = 0.0;
        $invoices = [];

        foreach ($payments as $payment) {
            $amount = (float) $payment->getAmount();

            // A cancelled/declined invoice was never money owed, so it must not
            // inflate "total invoiced" on a document a board will read.
            if (!in_array($payment->getStatus(), ['CANCELLED', 'DECLINED'], true)) {
                $invoiced += $amount;
            }

            if ($payment->getStatus() === 'APPROVED') {
                $paid += $amount;
            } elseif (in_array($payment->getStatus(), ['PENDING', 'VERIFYING'], true)) {
                $outstanding += $amount;
            }

            $invoices[] = [
                'reference' => $payment->getReference(),
                'supplementary' => $payment->isSupplementary(),
                'period' => $payment->getTermRegistration()?->getPeriod(),
                'date' => $payment->getCreatedAt(),
                'amount' => $amount,
                'status' => (string) $payment->getStatus(),
            ];
        }

        $subscription = $school->getSubscription();
        $period = $this->currentPeriod($tenantEm);

        $data = [
            'school_name' => $tenantSchool?->getName() ?? $school->getName(),
            'school_address' => $tenantSchool?->getAddress(),
            'reference' => sprintf('STMT-%d-%s', (int) $school->getId(), date('Ym')),
            'period' => $period['session'] . ' · ' . $period['term'],
            'plan_name' => $subscription?->getPlan()?->getName() ?? 'No plan',
            'status' => $subscription === null ? 'Trial' : ($subscription->isValid() ? 'Active' : 'Expired'),
            'roll' => $roll,
            'renews_on' => $subscription?->getEndDate()?->format('j M Y') ?? '—',
            'invoiced' => $invoiced,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'invoices' => $invoices,
            'generated_at' => new \DateTimeImmutable(),
        ];

        $billingSchool = $school;
        $letterheadSchool = $tenantSchool instanceof TenantSchool ? $tenantSchool : null;

        $pdf = $renderer->render($data, $billingSchool, $letterheadSchool);

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($school->getName() ?? 'school')) ?: 'school';
        $filename = sprintf('edus-statement-%s-%s.pdf', $slug, date('Y-m-d'));

        // This URL *is* the statement. Opening it displays the PDF in the
        // browser's viewer — there is no HTML variant to fall back to, because a
        // statement is one document and it must not exist in two renderings.
        // `?download=1` saves it instead of displaying it.
        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Length', (string) strlen($pdf));
        $response->headers->set('Content-Disposition', $disposition . '; filename="' . $filename . '"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Every invoice this school has ever had, newest first. Paying one opens the
     * slip; settled ones stay visible as the audit trail.
     */
    #[Route('/invoices', name: 'tenant_subscription_invoices', methods: ['GET'])]
    public function invoices(ManagerRegistry $doctrine): Response
    {
        $school = $this->getLandlordSchool($doctrine);

        $payments = $doctrine->getManager('landlord')
            ->getRepository(SubscriptionPayment::class)
            ->findBy(['school' => $school], ['createdAt' => 'DESC'], 50);

        return $this->render('tenant/subscription/invoices.html.twig', [
            'payments' => $payments,
        ]);
    }

    /**
     * The invoice as a document — the paper a bursar files and a proprietor
     * signs. Same A4 sheet, same footer and same contract as the statement:
     * it opens inline, `?download=1` saves it.
     */
    #[Route('/invoice/{id}.pdf', name: 'tenant_subscription_invoice_pdf', methods: ['GET'])]
    public function invoicePdf(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionInvoicePdfRenderer $renderer,
        BillingBankAccount $bank
    ): Response {
        $payment = $doctrine->getManager('landlord')
            ->getRepository(SubscriptionPayment::class)
            ->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Invoice not found');
        }

        $school = $this->getLandlordSchool($doctrine);
        if ($payment->getSchool()?->getId() !== $school->getId()) {
            // One school's invoice is not another school's business.
            throw $this->createAccessDeniedException('This invoice belongs to another school.');
        }

        $tenantSchool = $this->getUser()?->getSchool();
        $pdf = $renderer->render(
            $payment,
            $tenantSchool instanceof TenantSchool ? $tenantSchool : null,
            $bank->toArray()
        );

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($school->getName() ?? 'school')) ?: 'school';
        $reference = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payment->getReference()) ?: 'invoice';
        $filename = sprintf('edus-invoice-%s-%s.pdf', $slug, strtolower($reference));

        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Length', (string) strlen($pdf));
        $response->headers->set('Content-Disposition', $disposition . '; filename="' . $filename . '"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The receipt for a settled invoice. Only an APPROVED payment has one —
     * a receipt is proof that money arrived, so there is nothing to print for a
     * pending, cancelled or declined invoice.
     */
    #[Route('/receipt/{id}.pdf', name: 'tenant_subscription_receipt_pdf', methods: ['GET'])]
    public function receiptPdf(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionReceiptPdfRenderer $renderer
    ): Response {
        $payment = $doctrine->getManager('landlord')
            ->getRepository(SubscriptionPayment::class)
            ->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Invoice not found');
        }

        $school = $this->getLandlordSchool($doctrine);
        if ($payment->getSchool()?->getId() !== $school->getId()) {
            throw $this->createAccessDeniedException('This receipt belongs to another school.');
        }

        if ($payment->getStatus() !== 'APPROVED') {
            // Not an error the bursar should ever see twice: send them to the
            // invoice, which is the document that exists at this stage.
            $this->addFlash('warning', 'That invoice is not settled yet, so there is no receipt for it.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
        }

        $tenantSchool = $this->getUser()?->getSchool();
        $pdf = $renderer->render(
            $payment,
            $tenantSchool instanceof TenantSchool ? $tenantSchool : null
        );

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($school->getName() ?? 'school')) ?: 'school';
        $reference = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payment->getReference()) ?: 'invoice';
        $filename = sprintf('edus-receipt-%s-%s.pdf', $slug, strtolower($reference));

        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Length', (string) strlen($pdf));
        $response->headers->set('Content-Disposition', $disposition . '; filename="' . $filename . '"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The landing page for the emailed invitation. It only redirects — the
     * actual form needs the school to be signed in, which Symfony's firewall
     * already enforces before we get here.
     */
    #[Route('/term/{token}', name: 'tenant_subscription_term', methods: ['GET'])]
    public function term(string $token, ManagerRegistry $doctrine, RegistrationInviteService $invites): Response
    {
        $invite = $invites->findValid($token);

        if ($invite === null) {
            $this->addFlash('warning', 'That registration link has expired or has already been used.');

            return $this->redirectToRoute('tenant_subscription_index');
        }

        $school = $this->getLandlordSchool($doctrine);
        if ($invite->getSchool()?->getId() !== $school->getId()) {
            // A token for another school must not open this school's form.
            throw $this->createAccessDeniedException('This invitation belongs to another school.');
        }

        return $this->redirectToRoute('tenant_subscription_register', ['invite' => $token]);
    }

    /**
     * Declare the roll for this term and generate the invoice.
     *
     * GET  → the quote, the arithmetic, and a confirm button.
     * POST → one TermRegistration + one SubscriptionPayment. Submitting twice
     *        redirects to the existing invoice instead of creating a second one.
     */
    #[Route('/register', name: 'tenant_subscription_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        ManagerRegistry $doctrine,
        RegistrationInviteService $invites,
        SubscriptionInvoicePdfRenderer $invoices,
        SubscriptionMailer $mailer,
        BillingBankAccount $bank
    ): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        $tenantEm = $doctrine->getManager();

        $school = $this->getLandlordSchool($doctrine);
        $period = $this->currentPeriod($tenantEm);

        $inviteToken = (string) ($request->request->get('invite') ?? $request->query->get('invite', ''));
        $invite = $inviteToken !== '' ? $invites->findValid($inviteToken) : null;
        if ($invite !== null && $invite->getSchool()?->getId() !== $school->getId()) {
            $invite = null;
        }

        $standard = $landlordEm->getRepository(Plan::class)->findOneBy(['pricingModel' => 'per_student']);
        if ($standard === null || !$this->quoteService->isConfigured($standard)) {
            $this->addFlash('error', 'The Standard plan is not configured yet. Please contact support.');

            return $this->redirectToRoute('tenant_subscription_index');
        }

        $roll = (int) $tenantEm->getRepository(Student::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $cycle = (string) $request->request->get('cycle', $request->query->get('cycle', TermRegistration::CYCLE_TERMLY));
        if (!in_array($cycle, [TermRegistration::CYCLE_TERMLY, TermRegistration::CYCLE_ANNUAL], true)) {
            $cycle = TermRegistration::CYCLE_TERMLY;
        }

        $students = (int) $request->request->get('students', $request->query->get('students', (string) max(1, $roll)));
        $students = max(1, min($students, 100_000));

        $quote = $this->quoteService->standardQuote($standard, $students, $cycle);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('term_register', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }

            // One live registration per (school, session, term).
            $existing = $landlordEm->getRepository(TermRegistration::class)->findOneBy([
                'school' => $school,
                'sessionName' => $period['session'],
                'termName' => $period['term'],
            ]);

            if ($existing !== null && $existing->getStatus() !== TermRegistration::STATUS_CANCELLED) {
                $this->addFlash('warning', 'This term is already registered. Reference: ' . $existing->getReference());

                return $this->redirectToRoute('tenant_subscription_index');
            }

            // One open invoice at a time (legacy behaviour, kept).
            $pending = $landlordEm->getRepository(SubscriptionPayment::class)
                ->createQueryBuilder('p')
                ->where('p.school = :school')
                ->andWhere('p.status IN (:statuses)')
                ->setParameter('school', $school)
                ->setParameter('statuses', ['PENDING', 'VERIFYING'])
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($pending !== null) {
                $this->addFlash('warning', 'You already have an open invoice. Pay it or cancel it before registering again.');

                return $this->redirectToRoute('tenant_subscription_slip', ['id' => $pending->getId()]);
            }

            $registration = (new TermRegistration())
                ->setSchool($school)
                ->setPlan($standard)
                ->setTrack(TermRegistration::TRACK_STANDARD)
                ->setBillingCycle($cycle)
                ->setSessionName($period['session'])
                ->setTermName($period['term'])
                ->setDeclaredStudents($students)
                ->setUnitPrice((string) $quote['unitPrice'])
                ->setSubtotal((string) $quote['subtotal'])
                ->setDiscount((string) $quote['discount'])
                ->setImplementationFee('0.00')
                ->setTotal((string) $quote['total'])
                ->setStatus(TermRegistration::STATUS_INVOICED)
                ->setSubmittedAt(new \DateTimeImmutable())
                ->setSubmittedBy($this->getUser()?->getUserIdentifier())
                ->setNotes($request->request->get('notes') ?: null);

            $payment = new SubscriptionPayment();
            $payment->setSchool($school);
            $payment->setPlan($standard);
            $payment->setAmount((string) $quote['total']);
            $payment->setStatus('PENDING');
            $payment->setTermRegistration($registration);

            $landlordEm->persist($registration);
            $landlordEm->persist($payment);
            $landlordEm->flush();

            if ($invite !== null) {
                $invites->markUsed($invite);
            }

            // The school should not have to log in to find out it owes money.
            // A mail failure must never undo a created invoice, so it is caught
            // and reported rather than allowed to bubble.
            $emailed = false;
            try {
                $tenantSchool = $this->getUser()?->getSchool();
                $pdf = $invoices->render(
                    $payment,
                    $tenantSchool instanceof TenantSchool ? $tenantSchool : null,
                    $bank->toArray()
                );
                $filename = sprintf(
                    'edus-invoice-%s.pdf',
                    strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payment->getReference()) ?: 'invoice')
                );
                $emailed = $mailer->sendInvoice($payment, $pdf, $filename, $bank->isConfigured());
            } catch (\Throwable $e) {
                if ($this->getParameter('kernel.debug')) {
                    $this->addFlash('warning', 'Invoice email failed: ' . $e->getMessage());
                }
            }

            $this->addFlash('success', sprintf(
                'Invoice %s generated for %s: %s pupils, ₦%s.%s',
                $payment->getReference(),
                $period['session'] . ' · ' . $period['term'],
                number_format($students),
                number_format($quote['total']),
                $emailed ? ' A copy has been emailed to your billing address.' : ''
            ));

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
        }

        return $this->render('tenant/subscription/register.html.twig', [
            'standard' => $standard,
            'period' => $period,
            'roll' => $roll,
            'students' => $students,
            'cycle' => $cycle,
            'quote' => $quote,
            'invite' => $invite,
            // The raw token has to travel with the form: the invite is only
            // burned when the invoice exists, so both forms echo it back.
            'invite_token' => $invite !== null ? $inviteToken : null,
            'annual_quote' => $this->quoteService->standardQuote($standard, $students, TermRegistration::CYCLE_ANNUAL),
        ]);
    }

    /**
     * Start an online payment for an invoice.
     *
     * The school bears the gateway charge: it pays invoice + fee, so Edus nets
     * the invoice. No handling margin is added — this is not a parent payment.
     */
    #[Route('/pay/{id}', name: 'tenant_subscription_pay', methods: ['POST'])]
    public function pay(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        PaystackClient $paystack,
        ProcessingFeeCalculator $feeCalculator
    ): Response {
        if (!$paystack->isConfigured()) {
            $this->addFlash('error', 'Online payment is not available right now. Upload your transfer receipt instead.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        if (!$this->isCsrfTokenValid('pay'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $landlordEm = $doctrine->getManager('landlord');
        $payment = $landlordEm->getRepository(SubscriptionPayment::class)->find($id);
        $school = $this->getLandlordSchool($doctrine);

        if (!$payment || $payment->getSchool()->getId() !== $school->getId()) {
            throw $this->createAccessDeniedException('This invoice belongs to another school.');
        }

        if ($payment->getStatus() !== 'PENDING') {
            $this->addFlash('warning', 'This invoice is no longer awaiting payment.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $charge = $feeCalculator->schoolTotalFor((float) $payment->getAmount());
        $gatewayReference = $payment->getReference() . '-' . strtoupper(bin2hex(random_bytes(4)));
        $payment->setGatewayReference($gatewayReference);
        $landlordEm->flush();

        // The receipt goes to the school's principal/billing address. A login
        // such as `qa-billing@edus.test` is not a deliverable address and the
        // gateway rejects it, so the school record comes first.
        $email = (string) $school->getPrincipalEmail();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) $this->getUser()?->getUserIdentifier();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'No valid billing email is on record for this school. Add one in your school settings.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $result = $paystack->initialize(
            email: $email,
            amountMinorUnits: (int) round($charge * 100),
            reference: $gatewayReference,
            callbackUrl: $this->generateUrl(
                'tenant_subscription_payment_callback',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            metadata: [
                'type' => 'edus_subscription',
                'invoice' => $payment->getReference(),
                'school' => $school->getName(),
            ],
        );

        if (!$result['ok']) {
            $this->addFlash('error', $result['message'] ?? 'Could not start the payment.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        return $this->redirect((string) $result['url']);
    }

    /**
     * Where Paystack sends the school after checkout. The callback is a hint:
     * `verify()` decides, and a wrong amount is refused rather than settled.
     */
    #[Route('/payment/callback', name: 'tenant_subscription_payment_callback', methods: ['GET'])]
    public function callback(
        Request $request,
        ManagerRegistry $doctrine,
        PaystackClient $paystack,
        ProcessingFeeCalculator $feeCalculator,
        SubscriptionActivationService $activation
    ): Response {
        $reference = (string) ($request->query->get('reference') ?? $request->query->get('trxref') ?? '');

        $landlordEm = $doctrine->getManager('landlord');
        $payment = $reference === ''
            ? null
            : $landlordEm->getRepository(SubscriptionPayment::class)->findOneBy(['gatewayReference' => $reference]);

        if ($payment === null) {
            $this->addFlash('error', 'We could not find that payment.');

            return $this->redirectToRoute('tenant_subscription_index');
        }

        $verified = $paystack->verify($reference);
        $expected = (int) round($feeCalculator->schoolTotalFor((float) $payment->getAmount()) * 100);

        if ($verified['status'] !== 'success') {
            $this->addFlash(
                'error',
                $verified['status'] === 'pending' ? 'Payment is still pending.' : 'The payment was not completed.'
            );

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
        }

        if ($verified['amount'] !== $expected) {
            $this->addFlash('error', 'The amount paid does not match this invoice. Please contact support.');

            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
        }

        $activation->activate($payment);

        $this->addFlash('success', 'Payment received. Your subscription is active — no manual verification needed.');

        return $this->redirectToRoute('tenant_subscription_index');
    }

    /**
     * Paystack's safety net: the school may close the tab before the callback.
     * Signature-verified, idempotent, and it never trusts the amount in the body.
     */
    #[Route('/payment/webhook', name: 'tenant_subscription_payment_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        ManagerRegistry $doctrine,
        PaystackClient $paystack,
        ProcessingFeeCalculator $feeCalculator,
        SubscriptionActivationService $activation,
        LoggerInterface $logger
    ): Response {
        $payload = $paystack->parseWebhook($request->getContent(), $request->headers->all());

        if ($payload === null) {
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        if (($payload['event'] ?? '') !== 'charge.success') {
            return new Response('Ignored', Response::HTTP_OK);
        }

        $reference = (string) ($payload['data']['reference'] ?? '');
        $amount = (int) ($payload['data']['amount'] ?? 0);

        $landlordEm = $doctrine->getManager('landlord');
        $payment = $reference === ''
            ? null
            : $landlordEm->getRepository(SubscriptionPayment::class)->findOneBy(['gatewayReference' => $reference]);

        if ($payment === null) {
            $logger->warning('Subscription webhook: unknown reference {reference}', ['reference' => $reference]);

            return new Response('OK', Response::HTTP_OK);
        }

        $expected = (int) round($feeCalculator->schoolTotalFor((float) $payment->getAmount()) * 100);
        if ($amount !== $expected) {
            $logger->error('Subscription webhook amount mismatch on {reference}: expected {expected}, got {got}', [
                'reference' => $reference,
                'expected' => $expected,
                'got' => $amount,
            ]);

            return new Response('OK', Response::HTTP_OK);
        }

        $activated = $activation->activate($payment);
        $logger->info('Subscription webhook {reference}: {result}', [
            'reference' => $reference,
            'result' => $activated ? 'activated' : 'already approved',
        ]);

        return new Response('OK', Response::HTTP_OK);
    }

    /**
     * The session/term a registration belongs to. Falls back to the newest rows
     * because a school may not have marked a term active yet.
     *
     * @return array{session: string, term: string, termStart: ?\DateTimeImmutable, termEnd: ?\DateTimeImmutable}
     */
    private function currentPeriod(\Doctrine\ORM\EntityManagerInterface $tenantEm): array
    {
        $session = $tenantEm->getRepository(TenantSession::class)->findOneBy(['isActive' => true])
            ?? $tenantEm->getRepository(TenantSession::class)->findOneBy([], ['id' => 'DESC']);

        $term = $tenantEm->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if ($term === null && $session !== null) {
            $term = $tenantEm->getRepository(Term::class)->findOneBy(
                ['session' => $session],
                ['id' => 'DESC']
            );
        }

        return [
            'session' => $session?->getName() ?? 'Current session',
            'term' => $term?->getName() ?? 'Current term',
            'termStart' => $term?->getStartDate(),
            'termEnd' => $term?->getEndDate(),
        ];
    }

    // 🟢 STEP 1: GENERATE SLIP (User Clicks "Choose Plan")
    // ... existing imports ...

    // 🟢 MODIFIED: INITIATE (With Duplicate Check)
    #[Route('/initiate/{planId}', name: 'tenant_subscription_initiate', methods: ['POST'])]
    public function initiate(int $planId, ManagerRegistry $doctrine): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        $landlordSchool = $this->getLandlordSchool($doctrine);

        // 1. 🛡️ GUARDRAIL: Check for existing unfinished payments
        $existingPayment = $landlordEm->getRepository(SubscriptionPayment::class)->createQueryBuilder('p')
            ->where('p.school = :school')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('school', $landlordSchool)
            ->setParameter('statuses', ['PENDING', 'VERIFYING']) // Block if Pending or Verifying
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingPayment) {
            $this->addFlash('warning', 'You already have an active invoice. Please pay it or cancel it to create a new one.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $existingPayment->getId()]);
        }

        // 2. Proceed if clear
        $plan = $landlordEm->getRepository(Plan::class)->find($planId);
        if (!$plan) throw $this->createNotFoundException('Plan not found');
        if (!$plan->isFlat() || (int) $plan->getPrice() <= 0) {
            $this->addFlash('error', 'This plan is priced by your roll. Use “Register this term”.');
            return $this->redirectToRoute('tenant_subscription_index');
        }

        $payment = new SubscriptionPayment();
        $payment->setSchool($landlordSchool);
        $payment->setPlan($plan);
        $payment->setAmount($plan->getPrice());
        $payment->setStatus('PENDING');

        $landlordEm->persist($payment);
        $landlordEm->flush();

        $this->addFlash('success', 'Invoice generated. Reference: ' . $payment->getReference());
        return $this->redirectToRoute('tenant_subscription_slip', ['id' => $payment->getId()]);
    }

    // 🟢 NEW: CANCEL PAYMENT (The Escape Hatch)
    #[Route('/cancel/{id}', name: 'tenant_subscription_cancel', methods: ['POST'])]
    public function cancel(int $id, ManagerRegistry $doctrine, Request $request): Response
    {
        // CSRF Protection (Optional but recommended)
        if (!$this->isCsrfTokenValid('cancel'.$id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid action.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $landlordEm = $doctrine->getManager('landlord');
        $payment = $landlordEm->getRepository(SubscriptionPayment::class)->find($id);
        $currentSchool = $this->getLandlordSchool($doctrine);

        if (!$payment || $payment->getSchool()->getId() !== $currentSchool->getId()) {
            throw $this->createAccessDeniedException();
        }

        // Only allow cancelling PENDING payments (not approved ones)
        if ($payment->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'You cannot cancel a payment that is being verified or already approved.');
            return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
        }

        $payment->setStatus('CANCELLED');
        if ($payment->getTermRegistration() !== null) {
            $payment->getTermRegistration()->setStatus(TermRegistration::STATUS_CANCELLED);
        }
        $landlordEm->flush();

        $this->addFlash('success', 'Invoice cancelled. You can now register the term again.');
        return $this->redirectToRoute('tenant_subscription_index');
    }

    // 🟢 STEP 2: VIEW SLIP & UPLOAD PROOF (User returns from Bank)
    #[Route('/slip/{id}', name: 'tenant_subscription_slip', methods: ['GET', 'POST'])]
    public function slip(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SluggerInterface $slugger,
        ProcessingFeeCalculator $feeCalculator,
        PaystackClient $paystack,
        #[Autowire('%proofs_directory%')] string $proofsDirectory,
        BillingBankAccount $bank
    ): Response
    {
        $landlordEm = $doctrine->getManager('landlord');
        
        // Fetch the EXISTING payment, don't create a new one
        $payment = $landlordEm->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException('Payment slip not found');

        // Security Check: Does this school own this slip?
        $currentSchool = $this->getLandlordSchool($doctrine);
        if ($payment->getSchool()->getId() !== $currentSchool->getId()) {
             throw $this->createAccessDeniedException('This slip belongs to another school.');
        }

        $form = $this->createForm(TenantSubscriptionPaymentType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            $uploadedFile = $form->get('proofOfPayment')->getData();
            
            if ($uploadedFile) {
                $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$uploadedFile->guessExtension();

                try {
                    $uploadedFile->move($proofsDirectory, $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Error uploading receipt.');
                    return $this->redirectToRoute('tenant_subscription_slip', ['id' => $id]);
                }

                // Update Data
               $payment->setProofOfPayment($newFilename); // Matches Entity logic
                $payment->setStatus('VERIFYING'); // Move to next stage

                $landlordEm->flush();

                $this->addFlash('success', 'Receipt uploaded! Admin will verify shortly.');
                return $this->redirectToRoute('tenant_subscription_index');
            }
        }
        
        return $this->render('tenant/subscription/pay.html.twig', [
            'payment' => $payment, // Pass Payment (which has Plan inside it)
            'form' => $form->createView(),
            'gateway_fee' => $feeCalculator->schoolGatewayFeeFor((float) $payment->getAmount()),
            'gateway_total' => $feeCalculator->schoolTotalFor((float) $payment->getAmount()),
            'gateway_ready' => $paystack->isConfigured() && $payment->getStatus() === 'PENDING',
            // Only rendered when a real account is configured — see
            // config/services.yaml. A placeholder number here would be worse
            // than no number: a school could actually transfer to it.
            'bank' => $bank->toArray(),
        ]);
    }

    /**
     * 🟢 FINAL ROBUST FIX: Handles Empty DB Fields by checking URL
     */
   /**
     * 🟢 HELPER: Finds Landlord School & Tells you HOW it found it.
     */
    private function getLandlordSchool(ManagerRegistry $doctrine): LandlordSchool
    {
        $landlordEm = $doctrine->getManager('landlord');
        
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $tenantSchool = $user->getSchool();

        if (!$tenantSchool) throw $this->createAccessDeniedException();

        // ---------------------------------------------------------
        // 🔍 STEP 1: TRY DATABASE (The "Right" Way)
        // ---------------------------------------------------------
        $subdomain = null;
        $source = 'Unknown';

        // Check if the getter exists and returns a value
        if (method_exists($tenantSchool, 'getSubdomain') && $tenantSchool->getSubdomain()) {
            $subdomain = $tenantSchool->getSubdomain();
            $source = '✅ Database Column';
        } 
        elseif (method_exists($tenantSchool, 'getSlug') && $tenantSchool->getSlug()) {
            $subdomain = $tenantSchool->getSlug();
            $source = '✅ Database Slug';
        }

        // ---------------------------------------------------------
        // 🔍 STEP 2: TRY URL FALLBACK (If Database failed)
        // ---------------------------------------------------------
        if (empty($subdomain)) {
            // Get URL: e.g., "divine-wisdom.edus.ng"
            $host = $_SERVER['HTTP_HOST'] ?? ''; 
            $parts = explode('.', $host);

            // If we have parts (e.g. [divine-wisdom, edus, ng]) take the first one
            if (count($parts) > 1) {
                $subdomain = $parts[0];
                $source = '⚠️ URL Fallback (Database was empty)';
            } else {
                // Handle Localhost (e.g. "localhost:8000")
                // You might want to hardcode a test school for development
                if (str_contains($host, 'localhost')) {
                    $subdomain = 'default'; // Change this to a real school subdomain in your DB
                    $source = '🔧 Localhost Default';
                }
            }
        }

        // ---------------------------------------------------------
        // 🔍 STEP 3: FINAL VERIFICATION
        // ---------------------------------------------------------
        if (empty($subdomain)) {
            throw $this->createNotFoundException('CRITICAL: Could not find School Subdomain in DB or URL.');
        }

        $landlordSchool = $landlordEm->getRepository(LandlordSchool::class)->findOneBy([
            'subdomain' => $subdomain
        ]);

        if (!$landlordSchool) {
            throw $this->createNotFoundException("Configuration Error: System found subdomain '{$subdomain}' via [{$source}], but no Landlord Account matches it.");
        }

        // 💡 DEBUG INFO: Show the user how we found it (Remove this line in Production)
        // $this->addFlash('info', "System connected to School: {$subdomain} (Source: {$source})");

        return $landlordSchool;
    }
}