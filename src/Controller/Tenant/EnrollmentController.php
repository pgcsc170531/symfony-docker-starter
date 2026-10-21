<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Term;
use App\Service\Finance\DiscountCalculator; // 👈 ENSURE THIS IS HERE
use App\Service\Finance\AdvancePaymentService;
use App\Service\Finance\InvoiceNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/enrollment')]
#[IsGranted('ROLE_BURSAR')]
class EnrollmentController extends AbstractController
{
    // ✅ CORRECT INJECTION
    public function __construct(
        private DiscountCalculator $discountCalculator,
        private InvoiceNumberGenerator $invoiceNumbers,
        private AdvancePaymentService $advances,
    ) {}

    #[Route('/promote', name: 'app_tenant_enrollment_promote', methods: ['GET', 'POST'])]
    public function promote(Request $request, EntityManagerInterface $em): Response
    {
        // 1. Fetch Lists for Dropdowns
        $sessions = $em->getRepository(Session::class)->findBy([], ['id' => 'DESC']);
        $classrooms = $em->getRepository(Classroom::class)->findAll();

        if ($request->isMethod('POST')) {
            // 2. Get Form Data
            $sourceSessionId = $request->request->get('source_session');
            $targetSessionId = $request->request->get('target_session');
            $sourceClassId = $request->request->get('source_class');
            $targetClassId = $request->request->get('target_class');
            $selectedStudentIds = $request->request->all('students');

            // 3. Validation
            if (!$sourceSessionId || !$targetSessionId || !$sourceClassId || !$targetClassId) {
                $this->addFlash('error', 'Please select all sessions and classes.');
                return $this->redirectToRoute('app_tenant_enrollment_promote');
            }

            // 4. Load Entities
            $targetSession = $em->getRepository(Session::class)->find($targetSessionId);
            $sourceClass = $em->getRepository(Classroom::class)->find($sourceClassId);
            $targetClass = $em->getRepository(Classroom::class)->find($targetClassId);

            // 5. Fetch ALL students currently in the Source Class
            $currentEnrollments = $em->getRepository(Enrollment::class)->findBy([
                'session' => $sourceSessionId,
                'classroom' => $sourceClassId
            ]);

            $promotedCount = 0;
            $repeatedCount = 0;

            foreach ($currentEnrollments as $oldEnrollment) {
                $student = $oldEnrollment->getStudent();
                
                // Prevent duplicate enrollment in the target session
                $existing = $em->getRepository(Enrollment::class)->findOneBy([
                    'student' => $student,
                    'session' => $targetSession
                ]);
                
                if ($existing) continue;

                $newEnrollment = new Enrollment();
                $newEnrollment->setStudent($student);
                $newEnrollment->setSession($targetSession);
                $newEnrollment->setEnrolledAt(new \DateTimeImmutable());

                // CHECK: Is this student in the "Selected" list?
                if (in_array($student->getId(), $selectedStudentIds)) {
                    // YES -> Promote to Target Class
                    $newEnrollment->setClassroom($targetClass);
                    $newEnrollment->setIsRepeating(false);
                    
                    // 🟢 SYNC: Update Student's "Quick Access" field
                    $student->setCurrentClassroom($targetClass);

                    // 🚀 AUTOMATION: Bill them for the new class
                    $this->autoGenerateInvoice($em, $student, $targetClass, $targetSession);

                    $promotedCount++;
                } else {
                    // NO -> Repeat Source Class
                    $newEnrollment->setClassroom($sourceClass);
                    $newEnrollment->setIsRepeating(true); 

                    // 🟢 SYNC: Update Student's "Quick Access" field
                    $student->setCurrentClassroom($sourceClass);

                    // 🚀 AUTOMATION: Bill them for the repeating class
                    $this->autoGenerateInvoice($em, $student, $sourceClass, $targetSession);

                    $repeatedCount++;
                }

                $em->persist($newEnrollment);
                $em->persist($student);
            }

            $em->flush();

            $this->addFlash('success', "Process Complete! Promoted: $promotedCount, Repeating: $repeatedCount. Invoices Generated.");
            return $this->redirectToRoute('app_tenant_student_index');
        }

        return $this->render('tenant/enrollment/promote.html.twig', [
            'sessions' => $sessions,
            'classrooms' => $classrooms,
        ]);
    }

    #[Route('/api/students/{sessionId}/{classId}', name: 'app_tenant_api_students', methods: ['GET'])]
    public function getStudents(int $sessionId, int $classId, EntityManagerInterface $em): Response
    {
        $enrollments = $em->getRepository(Enrollment::class)->findBy([
            'session' => $sessionId,
            'classroom' => $classId
        ]);

        $data = [];
        foreach ($enrollments as $enrol) {
            $data[] = [
                'id' => $enrol->getStudent()->getId(),
                'name' => $enrol->getStudent()->getFullName(),
                'adm' => $enrol->getStudent()->getAdmissionNumber(),
            ];
        }

        return $this->json($data);
    }

    // 👇 NEW HELPER METHOD TO GENERATE INVOICES AUTOMATICALLY
    private function autoGenerateInvoice(EntityManagerInterface $em, $student, $classroom, $session): void
    {
        // 1. Find the Active Term in the TARGET session
        $term = $em->getRepository(Term::class)->findOneBy([
            'session' => $session, 
            'isActive' => true
        ]);
        
        // Fallback: If no term is active, find the first term
        if (!$term) {
            $term = $em->getRepository(Term::class)->findOneBy([
                'session' => $session
            ], ['startDate' => 'ASC']);
        }

        if (!$term) return;

        // 2. Prevent Duplicates (Don't bill twice)
        $exists = $em->getRepository(Invoice::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'type' => Invoice::TYPE_ACADEMIC
        ]);
        if ($exists) return;

        // 3. Get Fees for this Class
        $fees = $em->getRepository(FeeStructure::class)->findBy([
            'classroom' => $classroom,
            'term' => $term
        ]);

        if (!$fees) return;

        // 4. Create Invoice
        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($term);
        $invoice->setSession($session);
        $invoice->setClassroom($classroom);
        $invoice->setInvoiceNumber($this->invoiceNumbers->next($this->invoiceNumbers->yearFor($session)));
        $invoice->setStatus(Invoice::STATUS_UNPAID);
        $invoice->setType(Invoice::TYPE_ACADEMIC);
        $invoice->setPaidAmount("0");
        $invoice->setCreatedAt(new \DateTimeImmutable());

        $total = 0;
        foreach ($fees as $fee) {
            // Only bill COMPULSORY items automatically
            if (!$fee->getFeeItem()->isOptional()) { 
                $item = new InvoiceItem();
                $item->setInvoice($invoice);
                $item->setFeeItem($fee->getFeeItem());
                $item->setDescription($fee->getFeeItem()->getName());
                $item->setAmount((string)$fee->getAmount());
                $em->persist($item);
                $total += $fee->getAmount();
            }
        }

        if ($total > 0) {
            // A scholarship is a TUITION waiver: the base is the tuition lines
            // only, never the total of the bill.
            $discountValue = $this->discountCalculator->calculateDiscount(
                $student,
                $this->discountCalculator->tuitionBaseFromStructures($fees)
            );

            // `total_amount` is NET of the discount (Invoice::getNetBillable()).
            $invoice->setDiscountApplied((string) $discountValue);
            $invoice->setDiscountLabel($this->discountCalculator->describe($student));
            $invoice->setTotalAmount((string) max(0.0, $total - $discountValue));

            $em->persist($invoice);
            $this->advances->applyTo($invoice);
        }
    }
}