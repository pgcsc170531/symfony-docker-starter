<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Invoice; 
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\FeeStructure;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/quick-enroll')]
class QuickAdmissionController extends AbstractController
{
    // ==========================================
    // 1. THE DASHBOARD (Manual Grid + CSV Form)
    // ==========================================
    #[Route('/', name: 'app_tenant_quick_enroll', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        // HANDLE MANUAL GRID SUBMISSION
        if ($request->isMethod('POST')) {
            $data = $request->request->all('students'); // Array from the Grid
            $termId = $request->request->get('term_id');
            $term = $em->getRepository(Term::class)->find($termId);
            $generateInvoices = $request->request->get('generate_invoices') === 'yes';

            if (!$data || !$term) {
                $this->addFlash('error', 'Please select a Term and enter student data.');
                return $this->redirectToRoute('app_tenant_quick_enroll');
            }

            $count = 0;
            foreach ($data as $row) {
                // Skip empty rows
                if (empty($row['student_name']) || empty($row['parent_phone'])) continue;

                $classId = $row['class_id'];
                $classroom = $em->getRepository(Classroom::class)->find($classId);

                // Prepare data for the shared processor
                $studentData = [
                    'student_name' => $row['student_name'],
                    'last_name'    => $row['last_name'],
                    'gender'       => $row['gender'],
                    'parent_name'  => $row['parent_name'],
                    'parent_phone' => $row['parent_phone']
                ];

                if ($classroom) {
                    $this->processStudentRow($em, $hasher, $studentData, $classroom, $term, $generateInvoices);
                    $count++;
                }
            }
            $em->flush();
            $this->addFlash('success', "Manually enrolled $count students!");
            return $this->redirectToRoute('app_tenant_student_index'); 
        }

        // RENDER VIEW
        $terms = $em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC']);
        $classes = $em->getRepository(Classroom::class)->findAll();

        return $this->render('tenant/quick_enroll/index.html.twig', [
            'terms' => $terms,
            'classes' => $classes
        ]);
    }

    // ==========================================
    // 2. THE CSV UPLOADER (Smart Match)
    // ==========================================
    #[Route('/upload-csv', name: 'app_tenant_quick_enroll_csv', methods: ['POST'])]
    public function uploadCsv(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $file = $request->files->get('csv_file');
        $termId = $request->request->get('term_id');
        $term = $em->getRepository(Term::class)->find($termId);
        $generateInvoices = $request->request->get('generate_invoices') === 'yes';

        if (!$file || !$term) {
            $this->addFlash('error', 'Please select a CSV file and a Term.');
            return $this->redirectToRoute('app_tenant_quick_enroll');
        }

        // 1. BUILD MAP (Smart Lookup)
        $allClasses = $em->getRepository(Classroom::class)->findAll();
        $classMap = [];
        foreach ($allClasses as $class) {
            $classMap[$this->normalizeString($class->getName())] = $class;
        }

        // 2. SCREENING PHASE (Validate in Memory)
        $rowsToSave = [];
        $errors = [];

        if (($handle = fopen($file->getPathname(), "r")) !== FALSE) {
            $header = fgetcsv($handle); // Read Header
            
            // Basic Header Validation (Optional safety check)
            if (count($header) < 6) {
                $this->addFlash('error', "Invalid CSV Format. File must have at least 6 columns.");
                return $this->redirectToRoute('app_tenant_quick_enroll');
            }

            $rowNumber = 1; // Start at 1 (because header was 0)

            while (($row = fgetcsv($handle)) !== FALSE) {
                $rowNumber++;
                
                // Skip completely empty rows
                if (!array_filter($row)) continue;

                // A. Check Required Fields
                // [0]Name, [1]Last, [2]Gender, [3]Class, [4]Parent, [5]Phone
                if (empty($row[0]) || empty($row[5])) {
                    $errors[] = "Row $rowNumber: Missing Student Name or Parent Phone.";
                    continue;
                }

                // B. Validate Class Name
                $csvClassName = trim($row[3]);
                $cleanName = $this->normalizeString($csvClassName);
                
                if (empty($csvClassName)) {
                    $errors[] = "Row $rowNumber: Class Name is empty.";
                    continue;
                }

                if (!isset($classMap[$cleanName])) {
                    $errors[] = "Row $rowNumber: Class '$csvClassName' not found in system.";
                    continue;
                }

                // Data is valid! Queue it for processing.
                $rowsToSave[] = [
                    'data' => [
                        'student_name' => $row[0],
                        'last_name'    => $row[1],
                        'gender'       => strtoupper(trim($row[2])),
                        'parent_name'  => $row[4],
                        'parent_phone' => $row[5]
                    ],
                    'classroom' => $classMap[$cleanName]
                ];
            }
            fclose($handle);
        }

        // 3. DECISION TIME
        if (count($errors) > 0) {
            // 🛑 STOP! Do not save anything.
            // Render the page again with the Error Report
            $terms = $em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC']);
            $classes = $em->getRepository(Classroom::class)->findAll();
            
            return $this->render('tenant/quick_enroll/index.html.twig', [
                'terms' => $terms,
                'classes' => $classes,
                'csv_errors' => $errors, // Pass errors to view
                'error_count' => count($errors)
            ]);
        }

        // 4. EXECUTION PHASE (Save all or nothing)
        $savedCount = 0;
        foreach ($rowsToSave as $item) {
            $this->processStudentRow($em, $hasher, $item['data'], $item['classroom'], $term, $generateInvoices);
            $savedCount++;
        }

        $em->flush();
        $this->addFlash('success', "Screening Passed! Successfully imported $savedCount students.");
        
        return $this->redirectToRoute('app_tenant_student_index');
    }

    // ==========================================
    // 3. SHARED HELPER FUNCTIONS
    // ==========================================

    private function processStudentRow($em, $hasher, $data, $classroom, $term, $generateInvoice)
    {
        // 1. Parent Logic (Check duplicates by Phone)
        $phone = trim(str_replace([' ', '-', '+'], '', $data['parent_phone'])); // Ultra clean phone
        
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['phoneNumber' => $phone]);

        if (!$guardian) {
            $guardian = new Guardian();
            $guardian->setFullName($data['parent_name'] ?? 'Parent');
            $guardian->setPhoneNumber($phone);
            // Generate dummy email if needed
            $guardian->setEmail($phone . '@placeholder.com'); 

            // Create Login
            $user = new User();
            $user->setEmail($guardian->getEmail());
            $user->setFullName($guardian->getFullName());
            $user->setRoles(['ROLE_PARENT']);
            $user->setPassword($hasher->hashPassword($user, $phone)); // Pass = Phone

            $em->persist($user);
            $guardian->setUser($user);
            $em->persist($guardian);
        }

        // 2. Student Logic
        $student = new Student();
        $student->setFirstName($data['student_name']);
        $student->setLastName($data['last_name']);
        $student->setGender($data['gender'] ?? 'M');
        $student->setGuardian($guardian);
        $student->setAdmissionNumber(date('y') . '/' . rand(10000, 99999));
        
        // Link Class
        $student->setCurrentClassroom($classroom);
        $student->setCurrentClass($classroom); // Legacy field

        $em->persist($student);

        // 3. Enrollment History
        $enrollment = new Enrollment();
        $enrollment->setStudent($student);
        $enrollment->setSession($term->getSession());
        $enrollment->setClassroom($classroom);
        $em->persist($enrollment);

        // 4. Optional Invoice Generation
        if ($generateInvoice) {
            $this->createTuitionInvoice($em, $student, $classroom, $term);
        }
    }

    private function createTuitionInvoice($em, $student, $classroom, $term)
    {
        // Fetch fees for this class
        $fees = $em->getRepository(FeeStructure::class)->findBy([
            'classroom' => $classroom, 
            'term' => $term
        ]);

        if (!$fees) return;

        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($term);
        $invoice->setSession($term->getSession());
        $invoice->setClassroom($classroom);
        $invoice->setInvoiceNumber('INV-AUTO-' . uniqid());
        $invoice->setStatus('UNPAID');
        $invoice->setPaidAmount("0");
        $invoice->setType('TUITION'); // Tag it clearly

        $total = 0;
        foreach ($fees as $fee) {
            // ONLY bill compulsory items (Tuition, Exam, etc.)
            // Skip "Admission Form" or "Uniform" if you flagged them as 'isOneTime' in your DB
            if (!$fee->getFeeItem()->isOptional()) { 
                $item = new InvoiceItem();
                $item->setInvoice($invoice);
                $item->setDescription($fee->getFeeItem()->getName());
                $item->setAmount((string)$fee->getAmount());
                $em->persist($item);
                $total += $fee->getAmount();
            }
        }

        if ($total > 0) {
            $invoice->setTotalAmount((string)$total);
            $em->persist($invoice);
        }
    }

    // 👇 THE MAGIC STRING CLEANER
    private function normalizeString(?string $input): string
    {
        if (!$input) return '';
        // Remove dots, dashes, underscores, slashes
        $input = str_replace(['.', '-', '_', '/'], '', $input);
        // Remove spaces
        $input = str_replace(' ', '', $input);
        // Lowercase
        return strtolower($input);
    }
}