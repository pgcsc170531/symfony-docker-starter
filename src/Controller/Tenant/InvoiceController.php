<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\School; // <--- 1. IMPORT ADDED
use Symfony\Component\HttpFoundation\Request; // 🟢 ADD THIS LINE!
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\Tenant\StudentDiscount;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/invoice')]
class InvoiceController extends AbstractController
{
    // ======================================================
    // 1. DEDICATED ACADEMIC LEDGER (SCHOOL FEES)
    // ======================================================
    #[Route('/academic', name: 'app_tenant_invoice_index', methods: ['GET'])]
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
    public function generate(Student $student, EntityManagerInterface $em): Response
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
        $invoice->setType('ACADEMIC');
        $invoice->setStatus('UNPAID');

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
        $studentDiscount = $em->getRepository(StudentDiscount::class)->findOneBy(['student' => $student]);

        if ($studentDiscount) {
            $discount = $studentDiscount->getDiscountType();
            $calcDiscount = 0;

            if ($discount->getMode() === 'PERCENTAGE') {
                $calcDiscount = $totalAmount * ($discount->getValue() / 100);
            } else {
                $calcDiscount = $discount->getValue();
            }

            if ($calcDiscount > $totalAmount) $calcDiscount = $totalAmount;

            $invoice->setDiscountApplied((string)$calcDiscount);
            $totalAmount -= $calcDiscount;
        }

        $invoice->setTotalAmount((string)$totalAmount);
        
        // F. Save Everything
        $em->persist($invoice);
        $em->flush();

        $this->addFlash('success', 'Invoice generated successfully!');
        return $this->redirectToRoute('app_tenant_invoice_show', ['id' => $invoice->getId()]);
    }

    // 2. The "View Invoice" Page
    #[Route('/{id}', name: 'app_tenant_invoice_show', methods: ['GET'])]
    public function show(Invoice $invoice, EntityManagerInterface $em): Response
    {
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