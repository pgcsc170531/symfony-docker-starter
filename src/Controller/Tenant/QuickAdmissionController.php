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

use Symfony\Component\HttpFoundation\StreamedResponse;

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
        ini_set('auto_detect_line_endings', true);
        $file = $request->files->get('csv_file');
        $termId = $request->request->get('term_id');
        $term = $em->getRepository(Term::class)->find($termId);
        $generateInvoices = $request->request->get('generate_invoices') === 'yes';

        if (!$file || !$term) {
            $this->addFlash('error', 'Please select a CSV file and a Term.');
            return $this->redirectToRoute('app_tenant_quick_enroll');
        }

        $classMap = [];
        foreach ($em->getRepository(Classroom::class)->findAll() as $c) {
            $classMap[$this->normalizeString($c->getName())] = $c;
        }

        $rowsToSave = [];
        $errors = [];

        if (($handle = fopen($file->getPathname(), "r")) !== FALSE) {
            $header = fgetcsv($handle); 
            
            if (count($header) < 12) {
                $this->addFlash('error', "Format Error: Template requires 12 columns.");
                return $this->redirectToRoute('app_tenant_quick_enroll');
            }

            $rowNumber = 1; 
            while (($row = fgetcsv($handle)) !== FALSE) {
                $rowNumber++;
                if (!array_filter($row)) continue; 
                if (trim($row[0]) === 'John' && trim($row[2]) === 'Doe') continue; 

                $csvClassName = trim($row[10]); 
                $cleanClass = $this->normalizeString($csvClassName);
                
                if (!isset($classMap[$cleanClass])) {
                    $errors[] = "Row $rowNumber: Class '$csvClassName' not found.";
                    continue;
                }

               $rowsToSave[] = [
                'data' => [
                    'first_name'       => trim($row[0]),
                    'middle_name'      => trim($row[1]),
                    'last_name'        => trim($row[2]),
                    'gender'           => strtoupper(trim($row[3])),
                    'dob'              => trim($row[4]),
                    'religion'         => trim($row[5]),
                    'blood_group'      => trim($row[6]),
                    'genotype'         => trim($row[7]),
                    'home_town'        => trim($row[8]),
                    'admission_number' => trim($row[9]),  // 👈 NEW: Index 9
                    'parent_name'      => trim($row[11]), // 👈 SHIFTED: Index 11
                    'parent_phone'     => trim($row[12]), // 👈 SHIFTED: Index 12
                ],
                    'classroom' => $classMap[$cleanClass]
                ];
            }
            fclose($handle);
        }

        if (count($errors) > 0) {
            return $this->render('tenant/quick_enroll/index.html.twig', [
                'terms' => $em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC']),
                'classes' => $em->getRepository(Classroom::class)->findAll(),
                'csv_errors' => $errors, 
                'error_count' => count($errors)
            ]);
        }

       try {
            foreach ($rowsToSave as $item) {
                $this->processStudentRow($em, $hasher, $item['data'], $item['classroom'], $term, $generateInvoices);
            }

            // The flush happens once at the end. 
            // If any row fails a "Unique" constraint, the whole batch stops here.
            $em->flush();
            
            $this->addFlash('success', "Import Complete! " . count($rowsToSave) . " students are now linked to their parents.");
            
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Handle the Duplicate Admission Number error gracefully
            $this->addFlash('error', "Upload Stopped! One or more Admission Numbers in your file already exist in the database. Please check for duplicates and try again.");
            
            return $this->redirectToRoute('app_tenant_quick_enroll');

        } catch (\Exception $e) {
            // Handle any other unexpected errors (database connection, etc.)
            $this->addFlash('error', "An unexpected error occurred during the upload: " . $e->getMessage());
            
            return $this->redirectToRoute('app_tenant_quick_enroll');
        }

        return $this->redirectToRoute('app_tenant_student_index');
    }

    // ==========================================
    // 3. SHARED HELPER FUNCTIONS
    // ==========================================
    private function processStudentRow($em, $hasher, $data, $classroom, $term, $generateInvoice)
{
    // 1. GUARDIAN LOGIC (Find or Create Parent)
    $phone = trim(str_replace([' ', '-', '+'], '', $data['parent_phone'])); 
    $guardian = $em->getRepository(Guardian::class)->findOneBy(['phoneNumber' => $phone]);

    if (!$guardian) {
        $guardian = new Guardian();
        $guardian->setFullName($data['parent_name'] ?: 'Parent');
        $guardian->setPhoneNumber($phone);
        $guardian->setEmail($phone . '@edus.ng'); 
        
        $user = new User();
        $user->setEmail($guardian->getEmail());
        $user->setFullName($guardian->getFullName());
        $user->setRoles(['ROLE_PARENT']);
        
        // Default Password: 12345678
        $user->setPassword($hasher->hashPassword($user, '12345678'));
        
        $em->persist($user);
        $guardian->setUser($user);
        $em->persist($guardian);
    }

    // 2. STUDENT LOGIC
    $student = new Student();
    $student->setFirstName($data['first_name']);
    $student->setMiddleName($data['middle_name']);
    $student->setLastName($data['last_name']);
    $student->setGender($data['gender'] ?: 'M');
    $student->setReligion($data['religion']);
    $student->setBloodGroup($data['blood_group']);
    $student->setGenotype($data['genotype']);
    $student->setHomeTown($data['home_town']);
    $student->setGuardian($guardian); 
    
    // Handle Date of Birth (Using \DateTime for DateType compatibility)
    if (!empty($data['dob'])) {
        try { 
            $student->setDateOfBirth(new \DateTime($data['dob'])); 
        } catch (\Exception $e) {
            // Leave null if format is invalid
        }
    }

    // 👇 NEW: MANUAL VS AUTO ADMISSION NUMBER LOGIC
    if (!empty($data['admission_number'])) {
        // Use manual number from CSV if provided
        $student->setAdmissionNumber(trim($data['admission_number']));
    } else {
        // Fallback to auto-generation
        $uniqueId = strtoupper(substr(uniqid(), -5));
        $student->setAdmissionNumber(date('y') . '/' . $uniqueId);
    }

    $student->setCurrentClassroom($classroom);
    $student->setCurrentClass($classroom); 
    $em->persist($student);

    // 3. ENROLLMENT HISTORY
    $enrollment = new Enrollment();
    $enrollment->setStudent($student);
    $enrollment->setSession($term->getSession());
    $enrollment->setClassroom($classroom);
    $em->persist($enrollment);

    // 4. OPTIONAL INVOICE
    if ($generateInvoice) { 
        $this->createTuitionInvoice($em, $student, $classroom, $term); 
    }
}
    

    // ==========================================
    // 4. DOWNLOAD CSV TEMPLATE
    // ==========================================
    #[Route('/download-template', name: 'app_tenant_quick_enroll_template', methods: ['GET'])]
    public function downloadTemplate(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w+');
            // 1. THE HEADER ROW (13 Columns total now)
            fputcsv($handle, [
                'First Name', 
                'Middle Name', 
                'Last Name', 
                'Gender (M/F)', 
                'DOB (YYYY-MM-DD)', 
                'Religion', 
                'Blood Group', 
                'Genotype', 
                'Home Town', 
                'Admission Number (Leave blank to auto-generate)', // 👈 NEW COLUMN
                'Class Name', 
                'Parent Name', 
                'Parent Phone'
            ]);
            
            // 2. THE SAMPLE ROW (Matches the headers exactly)
            fputcsv($handle, [
                'John',          // First Name
                'Fitzgerald',    // Middle Name
                'Doe',           // Last Name
                'M',             // Gender
                '2015-01-01',    // DOB
                'Islam',         // Religion
                'O+',            // Blood Group
                'AA',            // Genotype
                'Katsina',       // Home Town
                '24/001',        // 👈 Sample Admission Number
                'JSS 1',         // Class Name
                'Mr. Richard Doe', 
                '08012345678'
            ]);
            
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="comprehensive_student_import.csv"');
        return $response;
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