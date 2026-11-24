<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\School;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Enrollment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\ExpressionLanguage\Expression;
use App\Repository\Tenant\PaymentRepository;
// Updated: Using your custom Notification Service
use App\Service\NotificationService; 
use App\Service\Finance\PaymentReferenceGenerator;
use App\Service\Finance\TuitionInvoiceBuilder;
use App\Service\SchoolMailer;

#[Route('/finance/payment')]
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly PaymentReferenceGenerator $paymentReferences,
    ) {
    }
    // ==========================================
    // 🟢 ZONE A: THE PAYMENT TERMINAL (BURSAR ONLY)
    // ==========================================

    #[Route('/', name: 'app_tenant_payment_index', methods: ['GET'])]
    #[IsGranted('ROLE_BURSAR')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $query = $request->query->get('q');
        $students = [];

        if ($query) {
            $students = $em->getRepository(Student::class)->createQueryBuilder('s')
                ->leftJoin('s.currentClassroom', 'c')
                ->addSelect('c')
                ->where('s.firstName LIKE :q OR s.middleName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
                ->setParameter('q', '%'.$query.'%')
                ->setMaxResults(10)
                ->getQuery()
                ->getResult();
        }

        return $this->render('tenant/payment/index.html.twig', [
            'students' => $students,
            'query' => $query
        ]);
    }

    #[Route('/history', name: 'app_tenant_payment_history', methods: ['GET'])]
    #[IsGranted('ROLE_BURSAR')]
    public function history(EntityManagerInterface $em): Response
    {
        // R5 replaced the old flat, unpaginated list. Keep the URL alive for
        // bookmarks and the browser back button.
        return $this->redirectToRoute('app_tenant_report_payments');
    }

    #[Route('/terminal/{id}', name: 'app_tenant_payment_terminal')]
    #[IsGranted('ROLE_BURSAR')]
    public function terminal(Student $student, Request $request, EntityManagerInterface $em): Response
    {
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            $this->addFlash('error', 'No active term found.');
            return $this->redirectToRoute('app_tenant_dashboard');
        }

        // ==========================================
        // 🟢 NEW LOGIC: Handle Specific vs Default Invoices
        // ==========================================
        $invoiceId = $request->query->get('invoice_id');

        if ($invoiceId) {
            // 1. If an invoice_id is passed (e.g., from the Store POS), load exactly that invoice
            $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
            
            // Security Check: Make sure the invoice actually belongs to this student!
            if ($invoice && $invoice->getStudent() !== $student) {
                $invoice = null; 
            }
        } else {
            // 2. Fallback: If no ID is passed, just load the default ACADEMIC School Fees
            $invoice = $em->getRepository(Invoice::class)->findOneBy([
                'student' => $student,
                'term' => $term,
                'type' => Invoice::TYPE_ACADEMIC
            ]);
        }
        // ==========================================

        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $term->getSession()
        ]);
        
        $classroom = $enrollment ? $enrollment->getClassroom() : null;

        $feePreview = [];
        $previewTotal = 0;

        // Only show the fee preview generation table if we are looking for ACADEMIC fees and none exist yet
        if (!$invoice && $classroom && !$invoiceId) {
            $structures = $em->getRepository(FeeStructure::class)->findBy([
                'term' => $term,
                'classroom' => $classroom
            ]);

            foreach ($structures as $fs) {
                $feePreview[] = $fs;
                $previewTotal += $fs->getAmount();
            }
        }

        return $this->render('tenant/payment/terminal.html.twig', [
            'student' => $student,
            'term' => $term,
            'invoice' => $invoice,
            'feePreview' => $feePreview,
            'previewTotal' => $previewTotal,
            'classroom' => $classroom,
            'school' => $em->getRepository(School::class)->findOneBy([]),
        ]);
    }

    #[Route('/generate-invoice/{studentId}', name: 'app_tenant_payment_generate_invoice', methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function generateInvoice(int $studentId, EntityManagerInterface $em, TuitionInvoiceBuilder $invoices): Response
    {
        $student = $em->getRepository(Student::class)->find($studentId);
        if (!$student) throw $this->createNotFoundException('Student not found');

        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            $this->addFlash('error', 'No active term found.');
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);
        }

        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $term->getSession()
        ]);

        $classroom = $enrollment ? $enrollment->getClassroom() : $student->getCurrentClass();

        if (!$classroom) {
            $this->addFlash('error', 'Student is not assigned to a class for this session.');
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);
        }

        $exists = $em->getRepository(Invoice::class)->findOneBy(['student' => $student, 'term' => $term, 'type' => Invoice::TYPE_ACADEMIC]);
        if ($exists) return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);

        // One shared biller. This screen used to walk the fee schedule on its
        // own, which billed a returning pupil for uniform, books and the ID card
        // and ignored the pupil's type entirely. Admissions, the student importer
        // and the fees import all use this builder for exactly that reason.
        $invoice = $invoices->build($student, $classroom, $term, $student->getStudentType());

        if ($invoice === null) {
            $this->addFlash('error', sprintf(
                'No fees are set for %s this term. Set the fee schedule first.',
                $classroom->getName()
            ));

            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);
        }

        // The document snapshot, so a reprint after the school renames itself
        // still shows what the parent was given.
        $school = $em->getRepository(School::class)->findOneBy([]);
        if ($school) {
            $invoice->setSchoolName($school->getName());
            $invoice->setSchoolAddress($school->getAddress());
            $invoice->setSchoolLogo($school->getLogoFilename());
            $invoice->setSchoolEmail($school->getEmail());
        }

        // build() already applied the pupil's advance and persisted the bill.
        $em->flush();

        $this->addFlash('success', 'Invoice generated successfully.');
        return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);
    }

    // ==========================================
    // 🟢 ZONE B: CASH PAYMENTS (BURSAR ONLY)
    // ==========================================

    #[Route('/pay-cash/{invoiceId}', name: 'app_tenant_payment_cash', methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function payCash(int $invoiceId, Request $request, EntityManagerInterface $em): Response
    {
        $invoice = $em->getRepository(Invoice::class)->find($invoiceId);
        if (!$invoice) throw $this->createNotFoundException();

        // A recorded receipt is a money movement; it must carry a token so a
        // third-party page cannot post one on the bursar's behalf.
        if (!$this->isCsrfTokenValid('payment_cash_' . $invoice->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Reload the terminal and try again.');

            return $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()->getId(),
                'invoice_id' => $invoice->getId(),
            ]);
        }

        $amount = $request->request->get('amount');
        $method = strtoupper(trim((string) $request->request->get('method', Payment::METHOD_CASH)));
        if (!in_array($method, [Payment::METHOD_CASH, Payment::METHOD_POS, Payment::METHOD_TRANSFER], true)) {
            $method = Payment::METHOD_CASH;
        }
        $reference = trim((string) $request->request->get('reference', ''));

        if (!$amount || $amount <= 0) {
            $this->addFlash('error', 'Invalid amount.');
            return $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()->getId(),
                'invoice_id' => $invoice->getId() // <-- Just add this line!
            ]);
        }

        /*
         * The money may have been handed over days before it reached the desk.
         * The bursar picks the day it was actually received so the cash book and
         * the daily collection report file it on the right date. The row's
         * created_at still records when it was entered — that audit trail is
         * never rewritten.
         */
        $paidOn = trim((string) $request->request->get('paid_on', ''));
        $paidAt = new \DateTimeImmutable();

        if ($paidOn !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);

            // createFromFormat happily rolls 2026-02-30 into March, so the
            // round-trip check is the only reliable validation.
            if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $paidOn) {
                $this->addFlash('error', 'That payment date is not valid.');

                return $this->redirectToRoute('app_tenant_payment_terminal', [
                    'id' => $invoice->getStudent()->getId(),
                    'invoice_id' => $invoice->getId(),
                ]);
            }

            if ($parsed > (new \DateTimeImmutable('today'))) {
                $this->addFlash('error', 'A payment cannot be dated in the future.');

                return $this->redirectToRoute('app_tenant_payment_terminal', [
                    'id' => $invoice->getStudent()->getId(),
                    'invoice_id' => $invoice->getId(),
                ]);
            }

            // Keep the time of day so several receipts for one day still order
            // sensibly; only the calendar date is under the bursar's control.
            $paidAt = $parsed->setTime(
                (int) $paidAt->format('H'),
                (int) $paidAt->format('i'),
                (int) $paidAt->format('s')
            );
        }

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amount);
        $payment->setMethod($method);
        $payment->setReference($reference !== '' ? mb_substr($reference, 0, 255) : null);
        
        // Unique short numeric reference (e.g. 2610529). Must not be derived from
        // the invoice id: a cash part payment and a slip for the same invoice both
        // produced the same code and the second insert hit the unique index.
        $payment->setReferenceCode($this->paymentReferences->next());
        
        $payment->setStatus(Payment::STATUS_CONFIRMED);
        $payment->setConfirmedAt($paidAt);
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        $em->persist($payment);

        $newPaid = (float)$invoice->getPaidAmount() + (float)$amount;
        $total = (float)$invoice->getTotalAmount();
        
        $invoice->setPaidAmount((string)$newPaid);
        $isFullyPaid = ($newPaid >= ($total - 0.01));
        $invoice->setStatus($isFullyPaid ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL);

        if ($isFullyPaid) {
            $pendingSlips = $em->getRepository(Payment::class)->findBy([
                'invoice' => $invoice,
                'status' => Payment::STATUS_PENDING
            ]);

            foreach ($pendingSlips as $slip) {
                $slip->setStatus(Payment::STATUS_CANCELLED);
                $em->persist($slip);
            }
        }

        $em->flush();

        $this->addFlash('success', sprintf('%s payment recorded successfully!', $payment->getMethodLabel()));
        return $this->redirectToRoute('app_tenant_payment_receipt', ['id' => $payment->getId()]);
    }

    /**
     * The bursar's verification queue.
     *
     * A parent transfers with a slip code, or uploads a receipt when the code
     * never made it into the narration. Either way the money is sitting in the
     * school's account and only a human can say "yes, that one is ours" — so
     * this page is a work queue, not a lookup box. It is the manual link in the
     * navigation and the destination of the dashboard counter.
     */
    #[Route('/verify', name: 'app_tenant_payment_verify')]
    #[IsGranted('ROLE_BURSAR')]
    public function verify(Request $request, EntityManagerInterface $em): Response
    {
        // `code` is the old deep-link parameter used by the collections and
        // reconciliation reports; it filters the queue to that slip.
        $q = trim((string) ($request->query->get('q') ?: $request->query->get('code') ?: ''));

        $qb = $em->getRepository(Payment::class)->createQueryBuilder('p')
            ->join('p.invoice', 'i')->addSelect('i')
            ->leftJoin('i.student', 's')->addSelect('s')
            ->leftJoin('s.guardian', 'g')->addSelect('g')
            ->andWhere('p.status = :pending')
            ->setParameter('pending', Payment::STATUS_PENDING)
            // Oldest first: a queue, so nothing quietly rots at the bottom.
            ->orderBy('p.createdAt', 'ASC');

        if ($q !== '') {
            // The bursar is reading a bank app. Whatever is on that screen — the
            // pupil's name, the admission number, the slip code, a parent's phone,
            // the invoice number or the amount — should find the row.
            $where = 'LOWER(s.firstName) LIKE :q'
                . ' OR LOWER(s.lastName) LIKE :q'
                . ' OR LOWER(s.middleName) LIKE :q'
                . ' OR LOWER(s.admissionNumber) LIKE :q'
                . ' OR LOWER(g.fullName) LIKE :q'
                . ' OR LOWER(g.phoneNumber) LIKE :q'
                . ' OR LOWER(p.referenceCode) LIKE :q'
                . ' OR LOWER(p.reference) LIKE :q'
                . ' OR LOWER(i.invoiceNumber) LIKE :q';

            $numeric = str_replace([',', ' '], '', $q);
            if (is_numeric($numeric)) {
                $where .= ' OR p.amount = :amount';
                $qb->setParameter('amount', number_format((float) $numeric, 2, '.', ''));
            }

            $qb->andWhere($where)->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $payments = $qb->getQuery()->getResult();

        $pendingTotal = 0.0;
        foreach ($payments as $pendingPayment) {
            $pendingTotal += (float) $pendingPayment->getAmount();
        }

        return $this->render('tenant/payment/verify.html.twig', [
            'payments' => $payments,
            'q' => $q,
            'pendingTotal' => $pendingTotal,
        ]);
    }

    #[Route('/confirm/{id}', name: 'app_tenant_payment_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function confirm(Payment $payment, Request $request, EntityManagerInterface $em, ManagerRegistry $doctrine, NotificationService $notifier, SchoolMailer $mailer): Response
    {
        $invoice = $payment->getInvoice(); // Grab the invoice early so we can use its ID

        // A confirmation is a money movement, so it must carry a token. It did
        // not before, which meant any page could POST another bursar's approval.
        if (!$this->isCsrfTokenValid('payment_confirm_' . $payment->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Reload the page and try again.');

            return $this->backFromConfirm($request, $invoice);
        }

        // Where the bursar came from decides where they go back to: the queue
        // should keep them in the queue, the terminal in the terminal.
        $back = $this->backFromConfirm($request, $invoice);

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            $this->addFlash('error', 'Action Failed: This payment slip is ' . $payment->getStatus());

            return $back;
        }

        // The second pair of eyes: the bursar must type what the bank actually
        // shows. A blank box is never an approval, and a figure that disagrees
        // with the slip needs an explicit acknowledgement.
        $rawAmount = trim((string) $request->request->get('actual_amount', ''));
        $actualAmount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;

        if ($actualAmount <= 0) {
            $this->addFlash('error', 'Enter the amount you saw in the bank before confirming this payment.');

            return $back;
        }

        $expectedAmount = round((float) $payment->getAmount(), 2);
        if (abs($actualAmount - $expectedAmount) > 0.01 && !$request->request->getBoolean('amount_differs')) {
            $this->addFlash('error', sprintf(
                'The slip says ₦%s but you entered ₦%s. If the bank really shows a different figure, tick “the amount differs”.',
                number_format($expectedAmount, 2),
                number_format($actualAmount, 2)
            ));

            return $back;
        }

        if ($invoice->getStatus() === Invoice::STATUS_PAID || $invoice->getPaidAmount() >= $invoice->getTotalAmount()) {
            $this->addFlash('error', 'Action Blocked: This invoice is already fully paid.');
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $em->flush();

            return $back;
        }

        $payment->setAmount(number_format($actualAmount, 2, '.', ''));

        $payment->setStatus(Payment::STATUS_CONFIRMED);
        $payment->setConfirmedAt(new \DateTimeImmutable());
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        $newPaid = (float)$invoice->getPaidAmount() + (float)$payment->getAmount();
        $invoice->setPaidAmount((string)$newPaid);
        $invoice->setStatus(($newPaid >= ((float)$invoice->getTotalAmount() - 0.01)) ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL);

        $em->flush();

        // TRIGGER: SMS Confirmation with Receipt URL using NotificationService
        $student = $invoice->getStudent();
        $school = $this->getTenantSchool($doctrine);

        if ($school) {
            $receiptUrl = $this->generateUrl('app_tenant_payment_receipt', [
                'id' => $payment->getId()
            ], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

            $notifier->sendSms(
                $school, 
                $student->getGuardian()->getPhoneNumber(), 
                "Payment Confirmed! ₦[amount] received for [student_name]. View your receipt here: [url]", 
                'fees', 
                [
                    '[amount]'       => number_format($payment->getAmount(), 2),
                    '[student_name]' => $student->getFirstName(),
                    '[url]'          => $receiptUrl
                ]
            );

            // Receipt email — parents keep these as proof of payment. Fails silently
            // if the school switched the trigger off, or if mail is down.
            $mailer->paymentReceipt($school, $payment, $receiptUrl);
        }
    
        $this->addFlash('success', sprintf(
            'Payment verified and confirmed: ₦%s recorded for %s.',
            number_format((float) $payment->getAmount(), 2),
            $invoice->getStudent()?->getFullName() ?? 'this pupil'
        ));

        return $back;
    }

    /**
     * Turn a pending slip down: the money never arrived, or it was not this
     * school's transfer.
     *
     * The row is kept (never deleted) as DECLINED with the bursar's reason in
     * the note, so the parent can read exactly why it was turned down from
     * their portal and the desk still has a full audit trail. No invoice balance
     * moves — a pending slip was never part of it.
     */
    #[Route('/reject/{id}', name: 'app_tenant_payment_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function reject(Payment $payment, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('payment_reject_' . $payment->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Reload the page and try again.');

            return $this->redirectToRoute('app_tenant_payment_verify');
        }

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            $this->addFlash('error', sprintf(
                'Only a pending payment can be rejected. This one is %s.',
                $payment->getStatus()
            ));

            return $this->redirectToRoute('app_tenant_payment_verify');
        }

        // The reason is the point: it is what the parent reads on the portal,
        // so an empty explanation is not a rejection. Trailing punctuation is
        // trimmed because the note adds its own full stop.
        $reason = rtrim(trim((string) $request->request->get('reason', '')), " .\t\n\r\0\x0B");
        if ($reason === '') {
            $this->addFlash('error', 'Give a reason — the parent will see it in their portal.');

            return $this->redirectToRoute('app_tenant_payment_verify');
        }

        $actor = $this->getUser()?->getUserIdentifier() ?? 'system';
        $existingNote = trim((string) ($payment->getNote() ?? ''));

        $payment->setStatus(Payment::STATUS_DECLINED);
        // The portal prints this column, so it holds the explanation alone;
        // the note keeps the full audit sentence for the school.
        $payment->setRejectionReason($reason);
        $payment->setNote(trim($existingNote . sprintf(
            ' Rejected by %s on %s: %s.',
            $actor,
            (new \DateTimeImmutable())->format('j M Y'),
            mb_substr($reason, 0, 500)
        )));

        $em->flush();

        $this->addFlash('success', sprintf(
            'Payment %s rejected. Its reason is now visible to the parent in their portal.',
            $payment->getReferenceCode() ?: '#' . $payment->getId()
        ));

        return $this->redirectToRoute('app_tenant_payment_verify');
    }

    /**
     * Return to the screen the bursar submitted from.
     */
    private function backFromConfirm(Request $request, Invoice $invoice): Response
    {
        return match ((string) $request->request->get('return_to', 'terminal')) {
            'queue' => $this->redirectToRoute('app_tenant_payment_verify'),
            'history' => $this->redirectToRoute('app_tenant_payment_history'),
            default => $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()?->getId(),
                'invoice_id' => $invoice->getId(),
            ]),
        };
    }

    // ==========================================
    // 🟢 ZONE C: SHARED ZONES (SECURED FOR PARENTS)
    // ==========================================

    #[Route('/initiate/{invoice_id}', name: 'app_tenant_payment_initiate', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_ADMIN') or is_granted('ROLE_PARENT')"))]
    public function initiate(int $invoice_id, EntityManagerInterface $em): Response
    {
        $invoice = $em->getRepository(Invoice::class)->find($invoice_id);
        if (!$invoice) throw $this->createNotFoundException();

        $this->checkAccess($invoice->getStudent());

        $existingPayment = $em->getRepository(Payment::class)->findOneBy([
            'invoice' => $invoice,
            'status' => Payment::STATUS_PENDING,
            'method' => Payment::METHOD_TRANSFER
        ]);

        if ($existingPayment) {
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $existingPayment->getId()]);
        }

        $amountToPay = (float)$invoice->getTotalAmount() - (float)$invoice->getPaidAmount();
        $shortRef = $this->paymentReferences->next();

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amountToPay);
        $payment->setMethod(Payment::METHOD_TRANSFER);
        $payment->setReferenceCode($shortRef);
        $payment->setStatus(Payment::STATUS_PENDING);

        $em->persist($payment);
        $em->flush();

        // ❌ SMS Notification removed on 'initiate' to save wallet charges.
        // Schools are only charged when the payment is officially CONFIRMED.

        return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $payment->getId()]);
    }

    /**
     * Reverse a payment without deleting or editing the original row.
     *
     * A wrong receipt is never erased: the row is kept and cancelled, the
     * invoice is recomputed from the money that is still confirmed, and the
     * desk records the correct payment fresh. No money is moved by this action
     * — a refund to the payer (card, transfer or cash) is paid out by hand.
     * Only the school's super administrator may do it.
     */
    #[Route('/{id}/reverse', name: 'app_tenant_payment_reverse', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TENANT_SUPER_ADMIN')]
    public function reverse(Payment $payment, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('reverse_payment_' . $payment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $back = $this->backFromReverse($request, $payment);

        if (!in_array($payment->getStatus(), [Payment::STATUS_CONFIRMED, Payment::STATUS_PENDING], true)) {
            $this->addFlash('info', 'This payment has already been reversed.');

            return $back;
        }

        // Only a CONFIRMED row is inside the invoice's paid total. Subtracting a
        // PENDING slip would take money off the bill that was never on it.
        $wasConfirmed = $payment->getStatus() === Payment::STATUS_CONFIRMED;
        $reason = rtrim(trim((string) $request->request->get('reason', '')), " .\t\n\r\0\x0B");
        $actor = $this->getUser()?->getUserIdentifier() ?? 'system';
        $existingNote = trim((string) ($payment->getNote() ?? ''));

        $payment->setStatus(Payment::STATUS_CANCELLED);
        $payment->setNote(trim($existingNote . sprintf(
            ' Reversed by %s on %s%s.',
            $actor,
            (new \DateTimeImmutable())->format('j M Y'),
            $reason !== '' ? ': ' . mb_substr($reason, 0, 500) : ''
        )));

        $invoice = $payment->getInvoice();
        if ($invoice && $wasConfirmed) {
            $confirmed = (float) $em->getRepository(Payment::class)->confirmedForInvoice($invoice);
            // The reversed row is still CONFIRMED in the database until the
            // flush below, so take its amount out of the recomputed total.
            $confirmed = max(0.0, $confirmed - (float) $payment->getAmount());
            $invoice->setPaidAmount(number_format($confirmed, 2, '.', ''));
            $invoice->setStatus(Invoice::statusFor($confirmed, (float) $invoice->getTotalAmount()));
        }

        $em->flush();

        $this->addFlash('success', sprintf(
            'Payment %s reversed. The row is kept as cancelled and the bill reopened — no money was sent back automatically, so any refund is paid out by hand.',
            $payment->getReferenceCode() ?: '#' . $payment->getId()
        ));

        return $back;
    }

    /** Where the super admin was standing when they reversed the payment. */
    private function backFromReverse(Request $request, Payment $payment): Response
    {
        $invoice = $payment->getInvoice();

        return match ((string) $request->request->get('return_to', '')) {
            'queue' => $this->redirectToRoute('app_tenant_payment_verify'),
            'invoice' => $invoice
                ? $this->redirectToRoute('app_tenant_invoice_show', ['id' => $invoice->getId()])
                : $this->redirectToRoute('app_tenant_report_payments'),
            default => $this->redirectToRoute('app_tenant_report_payments'),
        };
    }

    #[Route('/receipt/{id}', name: 'app_tenant_payment_receipt', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_PARENT')"))]
    public function receipt(int $id, EntityManagerInterface $em): Response
    {
        $payment = $this->loadReceiptPayment($id, $em);

        if ($payment->getStatus() !== Payment::STATUS_CONFIRMED) {
            $this->addFlash('error', 'Receipt is not available yet.');
            return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $payment->getInvoice()->getId()]);
        }

        return $this->render('tenant/payment/receipt.html.twig', [
            'payment' => $payment,
            'student' => $payment->getInvoice()->getStudent(),
            'invoice' => $payment->getInvoice(),
            'liveSchool' => $em->getRepository(School::class)->findOneBy([]),
            'classroom_name' => $this->classroomFor($payment),
        ]);
    }

    /** Load a fee payment with its invoice, pupil and term, and prove the caller may see it. */
    private function loadReceiptPayment(int $id, EntityManagerInterface $em): Payment
    {
        $payment = $em->getRepository(Payment::class)->createQueryBuilder('p')
            ->addSelect('i', 's', 't')
            ->innerJoin('p.invoice', 'i')
            ->innerJoin('i.student', 's')
            ->leftJoin('i.term', 't')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment instanceof Payment) {
            throw $this->createNotFoundException('Receipt not found.');
        }

        $this->checkAccess($payment->getInvoice()->getStudent());

        return $payment;
    }

    /** The arm billed on the invoice, falling back to where the pupil sits today. */
    private function classroomFor(Payment $payment): ?string
    {
        $invoice = $payment->getInvoice();
        $student = $invoice?->getStudent();

        return ($invoice?->getClassroom()
            ?? $student?->getCurrentClassroom()
            ?? $student?->getCurrentClass())?->getName();
    }

    #[Route('/slip/{id}', name: 'app_tenant_payment_slip')]
    public function paymentSlip(int $id, EntityManagerInterface $em): Response
    {
        $payment = $em->getRepository(Payment::class)->createQueryBuilder('p')
            ->addSelect('i', 's')
            ->innerJoin('p.invoice', 'i')
            ->innerJoin('i.student', 's')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment) throw $this->createNotFoundException('Payment slip not found.');

        $this->checkAccess($payment->getInvoice()->getStudent());
        $school = $em->getRepository(School::class)->findOneBy([]);

        return $this->render('tenant/payment/slip.html.twig', [
            'payment' => $payment,
            'student' => $payment->getInvoice()->getStudent(),
            'school'  => $school,
            'bank_details' => [
                'bank' => $school ? $school->getBankName() : 'Not Set', 
                'account_number' => $school ? $school->getAccountNumber() : '', 
                'name' => $school ? $school->getAccountName() : ''
            ]
        ]);
    }

    // ==========================================
    // 🟢 NEW: PARENT UPLOADS PROOF OF PAYMENT
    // (Used when the slip code does not appear in the
    //  school's bank credit description.)
    // ==========================================
    #[Route('/{id}/upload-proof', name: 'app_tenant_payment_upload_proof', methods: ['POST'])]
    public function uploadProof(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $payment = $em->getRepository(Payment::class)->find($id);
        if (!$payment) throw $this->createNotFoundException('Payment not found.');

        // Only the owner parent (or a bursar/admin) may upload for this payment
        $this->checkAccess($payment->getInvoice()->getStudent());

        if ($payment->getStatus() !== Payment::STATUS_PENDING) {
            $this->addFlash('error', 'This payment is no longer pending, so proof cannot be uploaded.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
        }

        if (!$this->isCsrfTokenValid('payment_proof_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
        }

        $file = $request->files->get('proof');
        if (!$file) {
            $this->addFlash('error', 'Please choose a proof file to upload.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
        $ext = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        if (!in_array($ext, $allowed, true)) {
            $this->addFlash('error', 'Invalid file type. Please upload a JPG, PNG, WEBP or PDF.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
        }

        $newFilename = 'payment-' . $id . '-' . uniqid() . '.' . $ext;
        try {
            $file->move($this->getParameter('proofs_directory'), $newFilename);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not save the proof. Please try again.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
        }

        // Remove any previously uploaded proof when re-uploading
        if ($payment->getProofFilename()) {
            $oldPath = $this->getParameter('proofs_directory') . '/' . $payment->getProofFilename();
            if (is_file($oldPath)) { @unlink($oldPath); }
        }

        $payment->setProofFilename($newFilename);
        $payment->setProofUploadedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Proof of payment uploaded! The bursar will verify it against the bank.');
        return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $id]);
    }
    
    private function checkAccess(Student $student): void
    {
        if ($this->isGranted('ROLE_BURSAR') || $this->isGranted('ROLE_ADMIN')) return;
        $user = $this->getUser();
        $guardian = $student->getGuardian();
        if ($guardian && $guardian->getUser() === $user) return;
        throw $this->createAccessDeniedException('Access Denied.');
    }

    private function getTenantSchool($doctrine): ?\App\Entity\Tenant\School 
    {
        return $doctrine->getManager()->getRepository(\App\Entity\Tenant\School::class)->findOneBy([]);
    }


    // ==========================================
    // 🟢 NEW: DEDICATED WALK-IN RECEIPT
    // ==========================================
    #[Route('/receipt/walk-in/{id}', name: 'app_tenant_payment_receipt_walkin')]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_STORE')"))] // Bursar, Store Keeper or Admin
    public function walkInReceipt(int $id, EntityManagerInterface $em): Response
    {
        $payment = $em->getRepository(Payment::class)->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Walk-in receipt not found.');
        }

        $invoice = $payment->getInvoice();

        // Security check: Make sure this is actually a Walk-in store invoice
        if ($invoice->getType() !== Invoice::TYPE_STORE || $invoice->getStudent() !== null) {
            $this->addFlash('error', 'Invalid walk-in receipt.');
            return $this->redirectToRoute('app_tenant_dashboard');
        }

        return $this->render('tenant/payment/walkin_receipt.html.twig', [
            'payment' => $payment,
            'invoice' => $invoice,
            'buyerName' => $invoice->getBuyerName(), // 🟢 Pass the Walk-In name explicitly!
            'liveSchool' => $em->getRepository(School::class)->findOneBy([]),
        ]);
    }
}