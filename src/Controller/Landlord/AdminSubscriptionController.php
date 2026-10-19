<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Landlord\TermRegistration;
use App\Service\Subscription\SubscriptionActivationService;
use App\Service\Subscription\RegistrationInviteService;
use App\Service\Subscription\BillingBankAccount;
use App\Service\Subscription\SubscriptionInvoicePdfRenderer;
use App\Service\Subscription\SubscriptionMailer;
use App\Entity\Landlord\Plan; // 🟢 ADD THIS
use App\Entity\Landlord\School; // 🟢 ADD THIS
use App\Entity\Landlord\Subscription; // 🟢 ADD THIS
use App\Service\SubscriptionService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request; // 🟢 ADD THIS
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/subscription')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminSubscriptionController extends AbstractController
{
    // 1. DASHBOARD: LIST PENDING PAYMENTS
    #[Route('/pending', name: 'landlord_subscription_pending')]
    public function pending(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        
        // Fetch only payments waiting for approval
        $payments = $em->getRepository(SubscriptionPayment::class)->findBy(
            ['status' => 'VERIFYING'], 
            ['createdAt' => 'ASC']
        );

        return $this->render('landlord/subscription/pending.html.twig', [
            'payments' => $payments
        ]);
    }

    // 2. ACTION: APPROVE & TRIGGER SERVICE
    #[Route('/verify/{id}', name: 'landlord_subscription_verify', methods: ['POST'])]
    public function verify(
        int $id,
        ManagerRegistry $doctrine,
        SubscriptionActivationService $activation // shared with online payments
    ): Response {
        $em = $doctrine->getManager('landlord');
        $payment = $em->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException();

        // One activation path for manual approval AND gateway callbacks, so a
        // school gets the same period whichever way the money arrived.
        $activation->activate($payment);

        $this->addFlash('success', 'Payment verified! Subscription has been updated successfully.');

        return $this->redirectToRoute('landlord_subscription_pending');
    }

    // 3. ACTION: REJECT
    #[Route('/reject/{id}', name: 'landlord_subscription_reject', methods: ['POST'])]
    public function reject(int $id, ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        $payment = $em->getRepository(SubscriptionPayment::class)->find($id);

        if (!$payment) throw $this->createNotFoundException();

        $payment->setStatus('DECLINED');
        $em->flush();

        $this->addFlash('error', 'Payment declined.');

        return $this->redirectToRoute('landlord_subscription_pending');
    }



    /**
     * Every term registration, declared vs verified.
     *
     * This is the screen the design promised: Edus can see what a school declared,
     * what was actually enrolled in week one, and whether the difference is inside
     * the agreed tolerance.
     */
    #[Route('/registrations', name: 'landlord_subscription_registrations', methods: ['GET'])]
    public function registrations(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');

        $registrations = $em->getRepository(TermRegistration::class)->findBy(
            [],
            ['createdAt' => 'DESC'],
            200
        );

        return $this->render('landlord/subscription/registrations.html.twig', [
            'registrations' => $registrations,
        ]);
    }

    #[Route('/registration/{id}/verify-roll', name: 'landlord_subscription_verify_roll', methods: ['POST'])]
    public function verifyRoll(int $id, Request $request, ManagerRegistry $doctrine): Response
    {
        if (!$this->isCsrfTokenValid('verify_roll'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $em = $doctrine->getManager('landlord');
        $registration = $em->getRepository(TermRegistration::class)->find($id);
        if (!$registration) {
            throw $this->createNotFoundException();
        }

        $verified = max(0, (int) $request->request->get('verified_students'));

        $registration
            ->setVerifiedStudents($verified)
            ->setVerifiedAt(new \DateTimeImmutable())
            ->setVerifiedBy($this->getUser()?->getUserIdentifier());

        $em->flush();

        $this->addFlash('success', sprintf(
            'Roll verified: %s declared, %s counted%s.',
            number_format($registration->getDeclaredStudents()),
            number_format($verified),
            $registration->isWithinVariance() ? ' — within tolerance' : ' — outside tolerance, bill the difference'
        ));

        return $this->redirectToRoute('landlord_subscription_registrations');
    }

    /**
     * Every invoice every school has ever had — the revenue ledger.
     *
     * Searchable by school or reference, filterable by status, and totalled in
     * naira so "what have we billed and what has actually landed" is one screen.
     */
    #[Route('/invoices', name: 'landlord_subscription_invoices', methods: ['GET'])]
    public function invoices(Request $request, ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        $repository = $em->getRepository(SubscriptionPayment::class);

        $q = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $allowedStatuses = ['PENDING', 'VERIFYING', 'APPROVED', 'DECLINED', 'CANCELLED'];

        $qb = $repository->createQueryBuilder('p')
            ->leftJoin('p.school', 's')
            ->addSelect('s')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(300);

        if ($q !== '') {
            $qb->andWhere('s.name LIKE :q OR p.reference LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }
        if (in_array($status, $allowedStatuses, true)) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        $payments = $qb->getQuery()->getResult();

        $totalsQuery = $repository->createQueryBuilder('p')
            ->leftJoin('p.school', 's')
            ->select('p.status AS status, COUNT(p.id) AS invoices, COALESCE(SUM(p.amount), 0) AS total')
            ->groupBy('p.status');
        if ($q !== '') {
            $totalsQuery->andWhere('s.name LIKE :q OR p.reference LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $byStatus = [];
        foreach ($totalsQuery->getQuery()->getArrayResult() as $row) {
            $byStatus[$row['status']] = [
                'count' => (int) $row['invoices'],
                'total' => (float) $row['total'],
            ];
        }

        return $this->render('landlord/subscription/invoices.html.twig', [
            'payments' => $payments,
            'q' => $q,
            'status' => $status,
            'by_status' => $byStatus,
            'collected' => $byStatus['APPROVED']['total'] ?? 0.0,
            'outstanding' => ($byStatus['PENDING']['total'] ?? 0.0) + ($byStatus['VERIFYING']['total'] ?? 0.0),
        ]);
    }

    #[Route('/invoice/{id}/remind', name: 'landlord_subscription_remind', methods: ['POST'])]
    public function remind(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionMailer $mailer
    ): Response {
        if (!$this->isCsrfTokenValid('remind'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $payment = $doctrine->getManager('landlord')
            ->getRepository(SubscriptionPayment::class)
            ->find($id);

        if (!$payment) {
            throw $this->createNotFoundException();
        }

        if (!in_array($payment->getStatus(), ['PENDING', 'VERIFYING'], true)) {
            $this->addFlash('warning', 'That invoice is already settled.');

            return $this->redirectToRoute('landlord_subscription_invoices');
        }

        if (!$mailer->sendReminder($payment)) {
            $this->addFlash('error', 'This school has no valid billing email on record.');

            return $this->redirectToRoute('landlord_subscription_invoices');
        }

        $this->addFlash('success', sprintf('Reminder sent to %s for %s.', $payment->getSchool()->getName(), $payment->getReference()));

        return $this->redirectToRoute('landlord_subscription_invoices');
    }

    /**
     * Overage billing: only for a Standard registration whose verified roll is
     * outside tolerance. The amount is the difference at the rate the school
     * already agreed, and the invoice never grants another term.
     */
    #[Route('/registration/{id}/supplementary', name: 'landlord_subscription_supplementary', methods: ['POST'])]
    public function supplementary(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionInvoicePdfRenderer $invoices,
        SubscriptionMailer $mailer,
        BillingBankAccount $bank
    ): Response
    {
        if (!$this->isCsrfTokenValid('supplementary'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $em = $doctrine->getManager('landlord');
        $registration = $em->getRepository(TermRegistration::class)->find($id);

        if (!$registration) {
            throw $this->createNotFoundException();
        }

        if ($registration->getTrack() !== TermRegistration::TRACK_STANDARD) {
            $this->addFlash('warning', 'Enterprise is never billed on the roll.');

            return $this->redirectToRoute('landlord_subscription_registrations');
        }

        $variance = $registration->getVariance();
        if ($variance === null || $variance <= 0 || $registration->isWithinVariance()) {
            $this->addFlash('warning', 'This roll is within tolerance — no supplementary invoice is due.');

            return $this->redirectToRoute('landlord_subscription_registrations');
        }

        $unitPrice = (int) $registration->getUnitPrice();
        if ($unitPrice <= 0) {
            $this->addFlash('error', 'This registration has no unit price to bill against.');

            return $this->redirectToRoute('landlord_subscription_registrations');
        }

        $existing = $em->getRepository(SubscriptionPayment::class)->findOneBy([
            'termRegistration' => $registration,
            'supplementary' => true,
            'status' => 'PENDING',
        ]);

        if ($existing !== null) {
            $this->addFlash('warning', 'A supplementary invoice for this term is already awaiting payment.');

            return $this->redirectToRoute('landlord_subscription_invoices');
        }

        $amount = $variance * $unitPrice;

        $payment = new SubscriptionPayment();
        $payment->setSchool($registration->getSchool());
        $payment->setPlan($registration->getPlan());
        $payment->setAmount((string) $amount);
        $payment->setStatus('PENDING');
        $payment->setSupplementary(true);
        $payment->setTermRegistration($registration);

        $em->persist($payment);
        $em->flush();

        // An overage charge the school never hears about is a charge they find
        // three months later. Send it, with the same PDF they can print.
        $emailed = false;
        try {
            $pdf = $invoices->render($payment, null, $bank->toArray());
            $filename = sprintf(
                'edus-invoice-%s.pdf',
                strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payment->getReference()) ?: 'invoice')
            );
            $emailed = $mailer->sendInvoice($payment, $pdf, $filename, $bank->isConfigured());
        } catch (\Throwable) {
            // Never lose the invoice because the mail relay is down.
        }

        $this->addFlash('success', sprintf(
            'Supplementary invoice %s created: %s extra pupils at ₦%s = ₦%s.%s',
            $payment->getReference(),
            number_format($variance),
            number_format($unitPrice),
            number_format($amount),
            $emailed ? ' Emailed to the school.' : ''
        ));

        return $this->redirectToRoute('landlord_subscription_invoices');
    }

    /**
     * Email a one-click registration link to a school.
     *
     * The link points at the school's own subdomain and carries a single-use
     * token; the school still signs in before anything is submitted.
     */
    #[Route('/school/{id}/invite', name: 'landlord_subscription_invite', methods: ['POST'])]
    public function invite(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        RegistrationInviteService $invites,
        SubscriptionMailer $mailer
    ): Response {
        if (!$this->isCsrfTokenValid('invite'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $school = $doctrine->getManager('landlord')
            ->getRepository(School::class)
            ->find($id);

        if (!$school) {
            throw $this->createNotFoundException();
        }

        ['token' => $token] = $invites->create($school, $this->getUser()?->getUserIdentifier());

        $baseDomain = (string) $this->getParameter('app.base_domain');
        $scheme = str_contains($baseDomain, 'localhost') ? 'http' : 'https';
        $url = sprintf('%s://%s.%s/subscription/term/%s', $scheme, $school->getSubdomain(), $baseDomain, $token);

        if (!$mailer->sendRegistrationInvite($school, $url)) {
            // The clear token exists only in this variable — once stored, only
            // its hash survives. So if the mail cannot go out the admin must get
            // the link here, or the invitation is unusable.
            $this->addFlash('error', sprintf(
                '%s has no valid billing email, so nothing was sent. Send this link yourself — it expires in %d days and works once: %s',
                $school->getName(),
                \App\Entity\Landlord\RegistrationInvite::LIFETIME_DAYS,
                $url
            ));

            return $this->redirectToRoute('landlord_subscription_active');
        }

        $this->addFlash('success', sprintf(
            'Registration link sent to %s. It expires in %d days and can be used once.',
            $school->getName(),
            \App\Entity\Landlord\RegistrationInvite::LIFETIME_DAYS
        ));

        return $this->redirectToRoute('landlord_subscription_active');
    }

    // 🟢 4. MONITOR: ACTIVE SUBSCRIPTIONS
    #[Route('/active', name: 'landlord_subscription_active')]
    public function active(ManagerRegistry $doctrine): Response
    {
        $em = $doctrine->getManager('landlord');
        // Fetch all subscriptions to monitor expiry dates
        $subscriptions = $em->getRepository(Subscription::class)->findAll();

        return $this->render('landlord/subscription/active.html.twig', [
            'subscriptions' => $subscriptions
        ]);
    }

    // 🟢 5. ACTION: GENERATE SLIP ON BEHALF
    #[Route('/generate-slip/{schoolId}', name: 'landlord_subscription_generate_slip', methods: ['GET', 'POST'])]
    public function generateSlip(
        int $schoolId,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionInvoicePdfRenderer $invoices,
        SubscriptionMailer $mailer,
        BillingBankAccount $bank
    ): Response
    {
        $em = $doctrine->getManager('landlord');
        $school = $em->getRepository(School::class)->find($schoolId);
        
        if (!$school) throw $this->createNotFoundException('School not found');

        // Handle Form Submission
        if ($request->isMethod('POST')) {
            $planId = $request->request->get('plan_id');
            $plan = $em->getRepository(Plan::class)->find($planId);
            
            // Double-check on server side
            if ($plan && $plan->getPrice() > 0) {
                $payment = new SubscriptionPayment();
                $payment->setSchool($school);
                $payment->setPlan($plan);
                $payment->setAmount($plan->getPrice());
                $payment->setStatus('PENDING');

                $em->persist($payment);
                $em->flush();

                $emailed = false;
                try {
                    $pdf = $invoices->render($payment, null, $bank->toArray());
                    $filename = sprintf(
                        'edus-invoice-%s.pdf',
                        strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $payment->getReference()) ?: 'invoice')
                    );
                    $emailed = $mailer->sendInvoice($payment, $pdf, $filename, $bank->isConfigured());
                } catch (\Throwable) {
                    // The invoice exists either way; a mail outage must not lose it.
                }

                $this->addFlash('success', sprintf(
                    'Invoice %s generated.%s',
                    $payment->getReference(),
                    $emailed ? ' Emailed to the school.' : ' No billing email on file, so nothing was sent.'
                ));

                return $this->redirectToRoute('landlord_subscription_active');
            }
        }

        // 🟢 FIX: Only fetch Paid Plans
        $plans = $em->createQueryBuilder()
            ->select('p')
            ->from(Plan::class, 'p')
            ->where('p.price > 0') // 👈 Removes Free Trial
            ->orderBy('p.price', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('landlord/subscription/generate_slip.html.twig', [
            'school' => $school,
            'plans' => $plans
        ]);
    }

    // 🟢 6. ACTION: EXPIRY REMINDER (subscription without an open invoice)
    #[Route('/active/{id}/remind', name: 'landlord_subscription_expiry_remind', methods: ['POST'])]
    public function remindExpiry(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SubscriptionMailer $mailer
    ): Response {
        if (!$this->isCsrfTokenValid('remind_subscription'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid form token.');
        }

        $subscription = $doctrine->getManager('landlord')
            ->getRepository(Subscription::class)
            ->find($id);

        if (!$subscription) {
            throw $this->createNotFoundException();
        }

        if (!$mailer->sendExpiryReminder($subscription)) {
            $this->addFlash('error', 'This school has no valid billing email on record.');
        } else {
            $this->addFlash('success', sprintf('Renewal reminder sent to %s.', $subscription->getSchool()?->getName()));
        }

        return $this->redirectToRoute('landlord_subscription_active');
    }

}