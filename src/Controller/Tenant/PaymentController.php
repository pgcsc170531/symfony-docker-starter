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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\ExpressionLanguage\Expression;
use App\Repository\Tenant\PaymentRepository;

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
    public function terminal(Student $student, EntityManagerInterface $em): Response
    {
        // 1. Get Active Term
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            $this->addFlash('error', 'No active term found.');
            return $this->redirectToRoute('app_tenant_dashboard');
        }

        // 2. Check for Existing Invoice
        $invoice = $em->getRepository(Invoice::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'type' => 'ACADEMIC'
        ]);

        // 3. Find Classroom via Enrollment
        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $term->getSession()
        ]);
        
        $classroom = $enrollment ? $enrollment->getClassroom() : null;

        // 4. Prepare Preview (If no invoice exists)
        $feePreview = [];
        $previewTotal = 0;

        if (!$invoice && $classroom) {
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

        // 1. FIX: Find the correct Class via Enrollment (Best Practice)
        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $term->getSession()
        ]);

        // Fallback: If no enrollment found, try the student's current class profile
        $classroom = $enrollment ? $enrollment->getClassroom() : $student->getCurrentClass();

        if (!$classroom) {
            $this->addFlash('error', 'Student is not assigned to a class for this session.');
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);
        }

        // Double check existence of invoice
        $exists = $em->getRepository(Invoice::class)->findOneBy(['student' => $student, 'term' => $term, 'type' => 'ACADEMIC']);
        if ($exists) return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $studentId]);

        // Create Invoice
        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($term);
        $invoice->setSession($term->getSession());
        $invoice->setClassroom($classroom); // <--- FIXED: Using the resolved classroom
        $invoice->setInvoiceNumber('INV-' . strtoupper(uniqid()));
        $invoice->setType('ACADEMIC');
        $invoice->setStatus('UNPAID');
        $invoice->setPaidAmount('0');

        // === SNAPSHOT: Save School Details ===
        $school = $em->getRepository(School::class)->findOneBy([]);
        if ($school) {
            $invoice->setSchoolName($school->getName());
            $invoice->setSchoolAddress($school->getAddress());
            $invoice->setSchoolLogo($school->getLogoFilename());
            $invoice->setSchoolEmail($school->getEmail());
        }
        
        // Add Items
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
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $invoice->getStudent()->getId()]);
        }

        // 1. Prepare the Cash Payment (Queue for Insert)
        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amount);
        $payment->setMethod('CASH');
        $payment->generateReferenceCode(); 
        
        $payment->setStatus('CONFIRMED');
        $payment->setConfirmedAt(new \DateTimeImmutable());
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        $em->persist($payment); // Queue it up

        // 2. Update Invoice Balance (Queue for Update)
        $newPaid = (float)$invoice->getPaidAmount() + (float)$amount;
        $total = (float)$invoice->getTotalAmount();
        
        $invoice->setPaidAmount((string)$newPaid);
        
        // Math Safety: Allow 0.01 margin for float errors
        $isFullyPaid = ($newPaid >= ($total - 0.01));
        $invoice->setStatus($isFullyPaid ? 'PAID' : 'PARTIAL');

        // 🛡️ 3. AUTO-CANCEL LOGIC (Queue for Update)
        // We do this BEFORE flushing so everything commits together.
        if ($isFullyPaid) {
            $pendingSlips = $em->getRepository(Payment::class)->findBy([
                'invoice' => $invoice,
                'status' => 'PENDING' // Uppercase matches your DB
            ]);

            $cancelledCount = 0;
            foreach ($pendingSlips as $slip) {
                $slip->setStatus('CANCELLED');
                $em->persist($slip); // ⚠️ CRITICAL: Forces Doctrine to notice the change
                $cancelledCount++;
            }

            if ($cancelledCount > 0) {
                $this->addFlash('warning', "System Notice: $cancelledCount pending transfer slip(s) were auto-cancelled.");
            }
        }

        // 4. EXECUTE ALL CHANGES
        // This runs the INSERT (Cash), UPDATE (Invoice), and UPDATES (Cancelled Slips) in one transaction.
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
    public function confirm(Payment $payment, Request $request, EntityManagerInterface $em): Response
    {
        // 🔒 1. STRICT SECURITY CHECK (Updated)
        // If the slip is CANCELLED, CONFIRMED, or FAILED, stop immediately.
        if ($payment->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Action Failed: This payment slip is ' . $payment->getStatus());
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $payment->getInvoice()->getStudent()->getId()]);
        }

        // 🛑 2. SAFETY GUARD: Check if Invoice is already PAID
        $invoice = $payment->getInvoice();
        
        // We check if status is PAID OR if the numbers say it's paid (double safety)
        if ($invoice->getStatus() === 'PAID' || $invoice->getPaidAmount() >= $invoice->getTotalAmount()) {
            $this->addFlash('error', 'Action Blocked: This invoice is already fully paid. This pending slip has been auto-cancelled.');
            
            // ✅ CHANGE STATUS TO CANCELLED
            $payment->setStatus('CANCELLED');
            
            // ✅ FORCE SAVE IMMEDIATELY (So the "Verify" button disappears forever)
            $em->persist($payment);
            $em->flush();
            
            return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $invoice->getStudent()->getId()]);
        }

        // 3. CHECK FOR AMOUNT ADJUSTMENT
        $actualAmount = $request->request->get('actual_amount');
        if ($actualAmount) {
            $originalAmount = (float) $payment->getAmount();
            $newAmount = (float) $actualAmount;

            if ($newAmount > 0 && $newAmount != $originalAmount) {
                $payment->setAmount((string)$newAmount); 
                $this->addFlash('warning', "Note: Payment slip adjusted from ₦" . number_format($originalAmount) . " to ₦" . number_format($newAmount));
            }
        }

        // 4. Confirm Payment
        $payment->setStatus('CONFIRMED');
        $payment->setConfirmedAt(new \DateTimeImmutable());
        $payment->setConfirmedBy($this->getUser()->getUserIdentifier());

        // 5. Update Invoice Totals
        $newPaid = (float)$invoice->getPaidAmount() + (float)$payment->getAmount();
        $invoice->setPaidAmount((string)$newPaid);
        
        // Use epsilon for float precision safety
        $isFullyPaid = ($newPaid >= ((float)$invoice->getTotalAmount() - 0.01));
        $invoice->setStatus($isFullyPaid ? 'PAID' : 'PARTIAL');

        $em->flush();

        // 🟢 TRIGGER: Successful Confirmation Alert
    $student = $invoice->getStudent();
    $landlordSchool = $this->getLandlordSchool($doctrine);
    $receiptUrl = $this->generateUrl('app_tenant_payment_receipt', ['id' => $payment->getId()], 0);

    $notifier->sendSms($landlordSchool, $student->getGuardian()->getPhoneNumber(), 
        "Payment Confirmed! ₦[amount] received for [student_name] ([class]). View receipt here: [url]", 
        'fees', 
        [
            '[amount]'       => number_format($payment->getAmount(), 2),
            '[student_name]' => $student->getFullName(),
            '[class]'        => $student->getCurrentClassroom()?->getName() ?? 'Unassigned',
            '[url]'          => $receiptUrl
        ]
    );
    
        $this->addFlash('success', 'Payment verified and confirmed.');
        
        return $this->redirectToRoute('app_tenant_payment_terminal', ['id' => $invoice->getStudent()->getId()]);
    }

    // ==========================================
    // 🟢 ZONE C: SHARED ZONES (SECURED FOR PARENTS)
    // ==========================================

    #[Route('/initiate/{invoice_id}', name: 'app_tenant_payment_initiate', methods: ['POST'])]
    // 1. FIX ACCESS: Explicitly allow Parents, Bursars, and Admins
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_ADMIN') or is_granted('ROLE_PARENT')"))]
    public function initiate(int $invoice_id, Request $request, EntityManagerInterface $em): Response
    {
        $invoice = $em->getRepository(Invoice::class)->find($invoice_id);
        if (!$invoice) throw $this->createNotFoundException();

        // Security Check (Ownership)
        $this->checkAccess($invoice->getStudent());

        // 2. CHECK FOR EXISTING PENDING TRANSFER
        $existingPayment = $em->getRepository(Payment::class)->findOneBy([
            'invoice' => $invoice,
            'status' => 'PENDING',
            'method' => 'TRANSFER' // Matches the method set below
        ]);

        if ($existingPayment) {
            $this->addFlash('info', 'You already have a pending transfer slip. Here it is.');
            return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $existingPayment->getId()]);
        }

        // 3. FIX THE CRASH: Calculate Amount Server-Side
        // Do not rely on $request->get('amount'). It is unsafe and caused the crash.
        $amountToPay = $invoice->getTotalAmount() - $invoice->getPaidAmount();

        if ($amountToPay <= 0) {
            $this->addFlash('success', 'This invoice is already fully paid.');
            return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $invoice->getId()]);
        }

        // Create New Payment
        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount((string)$amountToPay); // ✅ Calculated Value (Safe)
        $payment->setMethod('TRANSFER');           // ✅ Fixed: Matches the check above (was 'BANK_TRANSFER')
        $payment->setReferenceCode(strtoupper(uniqid('REF-')));
        $payment->setStatus('PENDING');

        $em->persist($payment);
        $em->flush();

        // 🟢 TRIGGER: Pending Verification Alert
    $student = $invoice->getStudent();
    $landlordSchool = $this->getLandlordSchool($doctrine);

    $notifier->sendSms($landlordSchool, $student->getGuardian()->getPhoneNumber(), 
        "Payment Received! ₦[amount] for [student_name] ([class]) is PENDING verification. We will notify you once confirmed.", 
        'fees', 
        [
            '[amount]'       => number_format($payment->getAmount(), 2),
            '[student_name]' => $student->getFullName(),
            '[class]'        => $student->getCurrentClassroom()?->getName() ?? 'Unassigned',
        ]
    );

        return $this->redirectToRoute('app_tenant_payment_slip', ['id' => $payment->getId()]);
    }

    // ==========================================
    // 🖨️ OPTIMIZED RECEIPT GENERATION
    // ==========================================
    #[Route('/receipt/{id}', name: 'app_tenant_payment_receipt')]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_PARENT')"))]
    public function receipt(
        int $id, 
        EntityManagerInterface $em
    ): Response
    {
        // 1. EAGER LOAD EVERYTHING (Solves N+1 Problem)
        // We manually fetch the payment and JOIN the invoice, student, and term immediately.
        $payment = $em->getRepository(Payment::class)->createQueryBuilder('p')
            ->addSelect('i', 's', 't') // Select the joined data
            ->innerJoin('p.invoice', 'i')
            ->innerJoin('i.student', 's')
            ->leftJoin('i.term', 't') 
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment) {
            throw $this->createNotFoundException('Receipt not found.');
        }

        // 2. OWNERSHIP CHECK
        // This is now safe because $payment->getInvoice()->getStudent() is already loaded in memory
        $this->checkAccess($payment->getInvoice()->getStudent());

        // 3. STATUS CHECK
        if ($payment->getStatus() !== 'CONFIRMED') {
            $this->addFlash('error', 'Receipt is not available yet. Payment is pending verification.');
            return $this->redirectToRoute('app_tenant_invoice_show', [
                'id' => $payment->getInvoice()->getId()
            ]);
        }

        // 4. SCHOOL DETAILS FALLBACK
        $liveSchool = $em->getRepository(School::class)->findOneBy([]);

        return $this->render('tenant/payment/receipt.html.twig', [
            'payment' => $payment,
            'student' => $payment->getInvoice()->getStudent(),
            'invoice' => $payment->getInvoice(),
            'liveSchool' => $liveSchool,
        ]);
    }
    
   #[Route('/slip/{id}', name: 'app_tenant_payment_slip')]
    public function paymentSlip(int $id, EntityManagerInterface $em): Response
    {
        // 1. EAGER LOAD (Payment + Invoice + Student)
        $payment = $em->getRepository(Payment::class)->createQueryBuilder('p')
            ->addSelect('i', 's')
            ->innerJoin('p.invoice', 'i')
            ->innerJoin('i.student', 's')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$payment) {
            throw $this->createNotFoundException('Payment slip not found.');
        }

        // 2. SECURITY CHECK
        $this->checkAccess($payment->getInvoice()->getStudent());

        // 3. FETCH SCHOOL SETTINGS
        $school = $em->getRepository(School::class)->findOneBy([]);

        return $this->render('tenant/payment/slip.html.twig', [
            'payment' => $payment,
            'student' => $payment->getInvoice()->getStudent(),
            'school'  => $school, // Pass the whole school object
            
            // OPTIONAL: Keep this helper array if your Twig expects it
            'bank_details' => [
                'bank' => $school ? $school->getBankName() : 'Not Set', 
                'account_number' => $school ? $school->getAccountNumber() : '', 
                'name' => $school ? $school->getAccountName() : ''
            ]
        ]);
    }
    
    // ==========================================
    // 🔒 SECURITY HELPER
    // ==========================================
    
    private function checkAccess(Student $student): void
    {
        // 1. If Bursar or Admin -> Allow
        if ($this->isGranted('ROLE_BURSAR') || $this->isGranted('ROLE_ADMIN')) {
            return;
        }

        // 2. If Parent -> Check Ownership
        $user = $this->getUser();
        
        // Ensure student has a guardian
        if ($student->getGuardian() === $user) {
             return;
        }

        // Also check if you use 'getGuardian' relationship
        $guardian = $student->getGuardian();
        if ($guardian && $guardian->getUser() === $user) {
            return;
        }

        // 3. Otherwise -> Deny
        throw $this->createAccessDeniedException('You do not have permission to view this payment.');
    }

    
}