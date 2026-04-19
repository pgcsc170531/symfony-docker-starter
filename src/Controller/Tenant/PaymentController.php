<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\School;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\StudentDiscount;
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

#[Route('/finance/payment')]
class PaymentController extends AbstractController
{
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
                ->where('s.firstName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
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
        $payments = $em->getRepository(Payment::class)->findBy([], ['createdAt' => 'DESC']);
        return $this->render('tenant/payment/history.html.twig', ['payments' => $payments]);
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
                'type' => 'ACADEMIC'
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
        ]);
    }

    #[Route('/generate-invoice/{studentId}', name: 'app_tenant_payment_generate_invoice', methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function generateInvoice(int $studentId, EntityManagerInterface $em): Response
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

        $exists = $em->getRepository(Invoice::class)->findOneBy(['student' => $student, 'term' => $term, 'type' => 'ACADEMIC']);
        if ($exists) return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);

        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($term);
        $invoice->setSession($term->getSession());
        $invoice->setClassroom($classroom);
        $invoice->setInvoiceNumber('INV-' . strtoupper(uniqid()));
        $invoice->setType('ACADEMIC');
        $invoice->setStatus('UNPAID');
        $invoice->setPaidAmount('0');

        $school = $em->getRepository(School::class)->findOneBy([]);
        if ($school) {
            $invoice->setSchoolName($school->getName());
            $invoice->setSchoolAddress($school->getAddress());
            $invoice->setSchoolLogo($school->getLogoFilename());
            $invoice->setSchoolEmail($school->getEmail());
        }
        
        $fees = $em->getRepository(FeeStructure::class)->findBy([
            'classroom' => $classroom,
            'term' => $term
        ]);

        $total = 0;
        foreach ($fees as $fee) {
            $item = new InvoiceItem();
            $item->setInvoice($invoice);
            $item->setDescription($fee->getFeeItem()->getName());
            $item->setAmount((string)$fee->getAmount());
            $em->persist($item);
            $total += $fee->getAmount();
        }

        $invoice->setTotalAmount((string)$total);
        $em->persist($invoice);
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

        $amount = $request->request->get('amount');

        if (!$amount || $amount <= 0) {
            $this->addFlash('error', 'Invalid amount.');
            return $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()->getId(),
                'invoice_id' => $invoice->getId() // <-- Just add this line!
            ]);
        }

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amount);
        $payment->setMethod('CASH');
        
        // Use our short numeric reference logic (e.g. 260492)
        $datePrefix = date('ym'); 
        $payment->setReferenceCode($datePrefix . $invoice->getId());
        
        $payment->setStatus('CONFIRMED');
        $payment->setConfirmedAt(new \DateTimeImmutable());
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        $em->persist($payment);

        $newPaid = (float)$invoice->getPaidAmount() + (float)$amount;
        $total = (float)$invoice->getTotalAmount();
        
        $invoice->setPaidAmount((string)$newPaid);
        $isFullyPaid = ($newPaid >= ($total - 0.01));
        $invoice->setStatus($isFullyPaid ? 'PAID' : 'PARTIAL');

        if ($isFullyPaid) {
            $pendingSlips = $em->getRepository(Payment::class)->findBy([
                'invoice' => $invoice,
                'status' => 'PENDING'
            ]);

            foreach ($pendingSlips as $slip) {
                $slip->setStatus('CANCELLED');
                $em->persist($slip);
            }
        }

        $em->flush();

        $this->addFlash('success', 'Cash payment recorded successfully!');
        return $this->redirectToRoute('app_tenant_payment_receipt', ['id' => $payment->getId()]);
    }

    #[Route('/verify', name: 'app_tenant_payment_verify')]
    #[IsGranted('ROLE_BURSAR')]
    public function verify(Request $request, EntityManagerInterface $em): Response
    {
        $code = $request->query->get('code');
        $payment = null;
        if ($code) {
             $cleanCode = trim(str_replace(' ', '', $code));
             $payment = $em->getRepository(Payment::class)->findOneBy(['referenceCode' => $cleanCode]);
        }
        return $this->render('tenant/payment/verify.html.twig', [
            'payment' => $payment, 
            'search_code' => $code
        ]);
    }

    #[Route('/confirm/{id}', name: 'app_tenant_payment_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function confirm(Payment $payment, Request $request, EntityManagerInterface $em, ManagerRegistry $doctrine, NotificationService $notifier): Response
    {
        $invoice = $payment->getInvoice(); // Grab the invoice early so we can use its ID

        if ($payment->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Action Failed: This payment slip is ' . $payment->getStatus());
            // 🟢 FIX: Added 'invoice_id' to the redirect
            return $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()->getId(),
                'invoice_id' => $invoice->getId() 
            ]);
        }
        
        if ($invoice->getStatus() === 'PAID' || $invoice->getPaidAmount() >= $invoice->getTotalAmount()) {
            $this->addFlash('error', 'Action Blocked: This invoice is already fully paid.');
            $payment->setStatus('CANCELLED');
            $em->flush();
            // 🟢 FIX: Added 'invoice_id' to the redirect
            return $this->redirectToRoute('app_tenant_payment_terminal', [
                'id' => $invoice->getStudent()->getId(),
                'invoice_id' => $invoice->getId()
            ]);
        }

        $actualAmount = $request->request->get('actual_amount');
        if ($actualAmount && (float)$actualAmount > 0) {
            $payment->setAmount((string)$actualAmount);
        }

        $payment->setStatus('CONFIRMED');
        $payment->setConfirmedAt(new \DateTimeImmutable());
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        $newPaid = (float)$invoice->getPaidAmount() + (float)$payment->getAmount();
        $invoice->setPaidAmount((string)$newPaid);
        $invoice->setStatus(($newPaid >= ((float)$invoice->getTotalAmount() - 0.01)) ? 'PAID' : 'PARTIAL');

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
        }
    
        $this->addFlash('success', 'Payment verified and confirmed.');
        
        // 🟢 FIX: Added 'invoice_id' to the success redirect
        return $this->redirectToRoute('app_tenant_payment_terminal', [
            'id' => $invoice->getStudent()->getId(),
            'invoice_id' => $invoice->getId()
        ]);
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
            'status' => 'PENDING',
            'method' => 'TRANSFER'
        ]);

        if ($existingPayment) {
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $existingPayment->getId()]);
        }

        $amountToPay = (float)$invoice->getTotalAmount() - (float)$invoice->getPaidAmount();
        $shortRef = date('ym') . $invoice->getId();

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amountToPay);
        $payment->setMethod('TRANSFER');
        $payment->setReferenceCode($shortRef);
        $payment->setStatus('PENDING');

        $em->persist($payment);
        $em->flush();

        // ❌ SMS Notification removed on 'initiate' to save wallet charges.
        // Schools are only charged when the payment is officially CONFIRMED.

        return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $payment->getId()]);
    }

    #[Route('/receipt/{id}', name: 'app_tenant_payment_receipt')]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_PARENT')"))]
    public function receipt(int $id, EntityManagerInterface $em): Response
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

        if (!$payment) throw $this->createNotFoundException('Receipt not found.');

        $this->checkAccess($payment->getInvoice()->getStudent());

        if ($payment->getStatus() !== 'CONFIRMED') {
            $this->addFlash('error', 'Receipt is not available yet.');
            return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $payment->getInvoice()->getId()]);
        }

        return $this->render('tenant/payment/receipt.html.twig', [
            'payment' => $payment,
            'student' => $payment->getInvoice()->getStudent(),
            'invoice' => $payment->getInvoice(),
            'liveSchool' => $em->getRepository(School::class)->findOneBy([]),
        ]);
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
    #[IsGranted('ROLE_BURSAR')] // Only Bursars/Admins view this, since walk-ins don't have accounts
    public function walkInReceipt(int $id, EntityManagerInterface $em): Response
    {
        $payment = $em->getRepository(Payment::class)->find($id);

        if (!$payment) {
            throw $this->createNotFoundException('Walk-in receipt not found.');
        }

        $invoice = $payment->getInvoice();

        // Security check: Make sure this is actually a Walk-in store invoice
        if ($invoice->getType() !== 'STORE' || $invoice->getStudent() !== null) {
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