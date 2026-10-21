<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\School; // <--- 1. IMPORT ADDED
use App\Service\Finance\DiscountCalculator;
use App\Service\Finance\AdvancePaymentService;
use App\Service\Finance\InvoiceNumberGenerator;
use App\Service\SchoolMailer;
use Symfony\Component\HttpFoundation\Request; // 🟢 ADD THIS LINE!
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/finance/invoice')]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly DiscountCalculator $discountCalculator,
        private readonly InvoiceNumberGenerator $invoiceNumbers,
        private readonly AdvancePaymentService $advances,
    ) {
    }

    // ======================================================
    // 1. DEDICATED ACADEMIC LEDGER (SCHOOL FEES)
    // ======================================================
    #[Route('/academic', name: 'app_tenant_invoice_index', methods: ['GET'])]
    #[IsGranted('ROLE_BURSAR')]
    public function academicLedger(Request $request, EntityManagerInterface $em): Response
    {
        $status = $request->query->get('status', '');
        $searchQuery = $request->query->get('q', '');

        // Strictly fetch ACADEMIC invoices only
        $qb = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->leftJoin('i.student', 's')
            ->where('i.type = :type')
            ->setParameter('type', 'ACADEMIC')
            ->orderBy('i.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        
        if ($searchQuery) {
            $qb->andWhere('s.firstName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q OR i.invoiceNumber LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }

        $invoices = $qb->getQuery()->getResult();

        // Calculate Totals
        $sumTotal = 0.0; $sumPaid = 0.0; $sumBalance = 0.0;
        foreach ($invoices as $invoice) {
            $total = (float) $invoice->getTotalAmount();
            $paid = (float) $invoice->getPaidAmount();
            $sumTotal += $total; $sumPaid += $paid; $sumBalance += ($total - $paid);
        }

        return $this->render('tenant/invoice/index.html.twig', [
            'page_title' => 'Academic Fees Ledger',
            'ledger_type' => 'academic',
            'invoices' => $invoices,
            'sum_total' => $sumTotal,
            'sum_paid' => $sumPaid,
            'sum_balance' => $sumBalance,
        ]);
    }

    // ======================================================
    // 2. DEDICATED STORE LEDGER (POS SALES & WALK-INS)
    // ======================================================
    #[Route('/store', name: 'app_tenant_invoice_store', methods: ['GET'])]
    #[IsGranted('ROLE_STORE')]
    public function storeLedger(Request $request, EntityManagerInterface $em): Response
    {
        $status = $request->query->get('status', '');
        $searchQuery = $request->query->get('q', '');

        // Strictly fetch STORE invoices only
        $qb = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->leftJoin('i.student', 's')
            ->where('i.type = :type')
            ->setParameter('type', 'STORE')
            ->orderBy('i.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }
        
        if ($searchQuery) {
            // 🟢 CRITICAL FIX: Include 'buyerName' so you can search for Walk-In customers!
            $qb->andWhere('s.firstName LIKE :q OR s.lastName LIKE :q OR i.buyerName LIKE :q OR i.invoiceNumber LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }

        $invoices = $qb->getQuery()->getResult();

        // Calculate Totals
        $sumTotal = 0.0; $sumPaid = 0.0; $sumBalance = 0.0;
        foreach ($invoices as $invoice) {
            $total = (float) $invoice->getTotalAmount();
            $paid = (float) $invoice->getPaidAmount();
            $sumTotal += $total; $sumPaid += $paid; $sumBalance += ($total - $paid);
        }

        return $this->render('tenant/invoice/index.html.twig', [
            'page_title' => 'Store Sales Ledger',
            'ledger_type' => 'store',
            'invoices' => $invoices,
            'sum_total' => $sumTotal,
            'sum_paid' => $sumPaid,
            'sum_balance' => $sumBalance,
        ]);
    }

    // 1. The "Generate" Button Action
    #[Route('/generate/{id}', name: 'app_tenant_invoice_generate', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_BURSAR')]
    public function generate(Student $student, EntityManagerInterface $em, SchoolMailer $mailer): Response
    {
        // A. Find the Active Term
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        
        if (!$activeTerm) {
            $this->addFlash('error', 'No active term found! Please activate a term first.');
            return $this->redirectToRoute('app_tenant_student_index');
        }

        // B. Find the Student's Class for this Session
        $enrollment = $em->getRepository(Enrollment::class)->findOneBy([
            'student' => $student,
            'session' => $activeTerm->getSession()
        ]);

        if (!$enrollment) {
            $this->addFlash('error', 'Student is not enrolled in the active session!');
            return $this->redirectToRoute('app_tenant_student_index');
        }

        // C. Check if Invoice already exists
        $existingInvoice = $em->getRepository(Invoice::class)->findOneBy([
            'student' => $student,
            'term' => $activeTerm,
            'type' => 'ACADEMIC'
        ]);

        if ($existingInvoice) {
            $this->addFlash('warning', 'Invoice already exists for this term.');
            return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $existingInvoice->getId()]);
        }

        // D. Fetch the Fee Structure
        $fees = $em->getRepository(FeeStructure::class)->findBy([
            'classroom' => $enrollment->getClassroom(),
            'term' => $activeTerm
        ]);

        if (count($fees) === 0) {
            $this->addFlash('error', 'No fee structure defined for this class (' . $enrollment->getClassroom()->getName() . ') yet.');
            return $this->redirectToRoute('app_tenant_student_index');
        }

        // E. Create the Invoice
        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($activeTerm);
        $invoice->setSession($activeTerm->getSession());
        $invoice->setClassroom($enrollment->getClassroom());
        $invoice->setInvoiceNumber($this->invoiceNumbers->next($this->invoiceNumbers->yearFor($activeTerm->getSession())));
        $invoice->setType(Invoice::TYPE_ACADEMIC);
        $invoice->setStatus(Invoice::STATUS_UNPAID);

        // === 2. SNAPSHOT LOGIC ADDED HERE ===
        // Fetch current school settings and freeze them into this invoice
        $schoolSettings = $em->getRepository(School::class)->findOneBy([]);
        
        if ($schoolSettings) {
            $invoice->setSchoolName($schoolSettings->getName());
            $invoice->setSchoolAddress($schoolSettings->getAddress());
            $invoice->setSchoolLogo($schoolSettings->getLogoFilename());
            $invoice->setSchoolEmail($schoolSettings->getEmail());
            // $invoice->setSchoolPhone($schoolSettings->getPhone()); // Uncomment if you added phone
        }
        // ====================================

        $totalAmount = 0;

        // 1. Add Regular Fees
        foreach ($fees as $fee) {
            $item = new InvoiceItem();
            $item->setInvoice($invoice);
            $item->setFeeItem($fee->getFeeItem());
            $item->setAmount($fee->getAmount());
            $em->persist($item);
            
            $totalAmount += $fee->getAmount();
        }

        // 2. CHECK FOR DISCOUNTS
        // One rule, one service. The base is the TUITION lines only — a waiver
        // never reaches uniforms, ID cards or levies.
        $discountAmount = $this->discountCalculator->discountFor($student, $invoice);
        $invoice->setDiscountApplied((string) $discountAmount);
        $invoice->setDiscountLabel($this->discountCalculator->describe($student));

        // `total_amount` is NET (see Invoice::getNetBillable). The discount is
        // kept separately so reports can show what was waived.
        $invoice->setTotalAmount((string) max(0.0, (float) $totalAmount - $discountAmount));
        
        // F. Save Everything
        $em->persist($invoice);

        // Paid-ahead families: settle the new bill from their credit, with a
        // real ADVANCE payment row carrying the audit remark.
        $this->advances->applyTo($invoice);

        $em->flush();

        // Tell the parent what is owed. Skipped silently when the school has
        // switched this trigger off, or when mail is down.
        if ($schoolSettings !== null) {
            $mailer->invoiceIssued(
                $schoolSettings,
                $invoice,
                $this->generateUrl(
                    'app_tenant_invoice_show',
                    ['id' => $invoice->getId()],
                    \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
                )
            );
        }

        $this->addFlash('success', 'Invoice generated successfully!');
        return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $invoice->getId()]);
    }

    // 2. The "View Invoice" Page
    #[Route('/{id}', name: 'app_tenant_invoice_show', methods: ['GET'])]
    public function show(Invoice $invoice, EntityManagerInterface $em): Response
    {
        // Parents reach this page from their own fee screen, so an id in the URL
        // must not be enough to read another family's invoice.
        if (!$this->isGranted('ROLE_BURSAR') && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STORE')) {
            $guardian = $em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);
            $student = $invoice->getStudent();

            if ($guardian === null || $student === null || !$guardian->getStudents()->contains($student)) {
                throw $this->createAccessDeniedException('This invoice belongs to another family.');
            }
        }

        // === 3. FALLBACK LOGIC ADDED HERE ===
        // We fetch the live school settings just in case the invoice
        // is old and doesn't have the snapshot data.
        $liveSchool = $em->getRepository(School::class)->findOneBy([]);

        return $this->render('tenant/invoice/show.html.twig', [
            'invoice' => $invoice,
            'liveSchool' => $liveSchool, // Passed as backup
        ]);
    }
}