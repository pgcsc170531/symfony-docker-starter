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
use App\Service\Finance\DiscountCalculator;
use App\Service\Finance\AdvancePaymentService;
use App\Service\Admissions\AdmissionNumberGenerator;
use App\Service\Finance\InvoiceNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use Symfony\Component\HttpFoundation\StreamedResponse;

#[Route('/quick-enroll')]
#[IsGranted('ROLE_BURSAR')]
class QuickAdmissionController extends AbstractController
{
    /**
     * Guardians created earlier in the current import, keyed by normalised phone.
     * Sibling rows share a phone number and the guardian is not flushed until the
     * end of the batch, so a database lookup alone would create a duplicate.
     *
     * @var array<string, \App\Entity\Tenant\Guardian>
     */
    private array $guardianCache = [];

    public function __construct(
        private readonly DiscountCalculator $discountCalculator,
        private readonly InvoiceNumberGenerator $invoiceNumbers,
        private readonly AdmissionNumberGenerator $admissionNumbers,
        private readonly AdvancePaymentService $advances,
    ) {
    }

    // ==========================================
    // 1. THE DASHBOARD (Manual Grid + CSV Form)
    // ==========================================
    #[Route('/', name: 'app_tenant_quick_enroll', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        set_time_limit(0);

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
        // 360 students = 360 parent logins. Hashing each default password with
        // bcrypt/argon2 blows past PHP's 30s limit, so lift the limit and hash the
        // shared default parent password ONCE (reused for every new parent).
        set_time_limit(0);

        // A 1,200-row file creates pupil + guardian + user + enrolment + invoice
        // (+ items) per row and keeps every entity in the unit of work until the
        // single flush at the end. With the profiler and the SQL logger on, that
        // ran into the 128 MB default and died mid-import. The batch is still one
        // transaction; only the memory ceiling moves.
        ini_set('memory_limit', '512M');
        ini_set('auto_detect_line_endings', true);
        $defaultParentHash = $hasher->hashPassword(new User(), '12345678');

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

        // Fresh cache for this upload: a previous request's guardians must not leak in.
        $this->guardianCache = [];

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

                // A number typed in the file that is already on roll used to abort
                // the whole upload at flush time — one bad row and every good row
                // had to be re-uploaded. Reject the row here, with its row number,
                // and keep the rest of the file.
                $typedNumber = trim($row[9]);
                if ($typedNumber !== '' && $em->getRepository(Student::class)->findOneBy(['admissionNumber' => $typedNumber])) {
                    $errors[] = "Row $rowNumber: admission number '$typedNumber' is already in use — that row was skipped.";
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

        // A bad row does not have to mean a bad file: report it and import the
        // rest. Only a file with nothing importable stops here.
        if (count($errors) > 0 && count($rowsToSave) === 0) {
            return $this->render('tenant/quick_enroll/index.html.twig', [
                'terms' => $em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC']),
                'classes' => $em->getRepository(Classroom::class)->findAll(),
                'csv_errors' => $errors,
                'error_count' => count($errors)
            ]);
        }

       try {
            foreach ($rowsToSave as $item) {
                $this->processStudentRow($em, $hasher, $item['data'], $item['classroom'], $term, $generateInvoices, $defaultParentHash);
            }

            // The flush happens once at the end. 
            // If any row fails a "Unique" constraint, the whole batch stops here.
            $em->flush();
            
            $this->addFlash('success', "Import Complete! " . count($rowsToSave) . " students are now linked to their parents.");

            if ($errors !== []) {
                $this->addFlash('warning', sprintf(
                    '%d row%s skipped — %s',
                    count($errors),
                    count($errors) === 1 ? '' : 's',
                    implode(' ', array_slice($errors, 0, 5))
                ));
            }
            
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Say what actually collided. "Duplicate admission number" was printed
            // for every unique violation, including sibling guardians sharing a
            // phone — which sent the school looking in the wrong place.
            $reason = stripos($e->getMessage(), 'admission') !== false
                ? 'One or more Admission Numbers in your file already exist in the database. Please check for duplicates and try again.'
                : 'A row in your file clashes with a record that already exists (a guardian phone/email or a pupil already on roll). Check the phone numbers and parent names in the file, then upload again.';

            $this->addFlash('error', 'Upload Stopped! ' . $reason);
            
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
    private function processStudentRow($em, $hasher, $data, $classroom, $term, $generateInvoice, ?string $defaultParentHash = null)
{
    // 1. GUARDIAN LOGIC (Find or Create Parent)
    $phone = trim(str_replace([' ', '-', '+'], '', $data['parent_phone'])); 

    // Siblings in one file share a phone number, and a guardian created earlier in
    // this loop is not flushed yet — so the database lookup below cannot see it.
    // Without this cache the second sibling tried to create a second guardian and
    // the batch died on a unique violation that was reported as "duplicate
    // admission number". The cache is reset at the start of each import.
    $guardian = $this->guardianCache[$phone] ?? null;

    if ($guardian === null) {
        $guardian = $em->getRepository(Guardian::class)->findOneBy(['phoneNumber' => $phone]);
    }

    if (!$guardian) {
        $guardian = new Guardian();
        $guardian->setFullName($data['parent_name'] ?: 'Parent');
        $guardian->setPhoneNumber($phone);
        $guardian->setEmail($phone . '@edus.ng'); 
        
        $user = new User();
        $user->setEmail($guardian->getEmail());
        $user->setFullName($guardian->getFullName());
        $user->setRoles(['ROLE_PARENT']);
        
        // Default Password: 12345678 (pre-hashed once for bulk imports for speed)
        $user->setPassword($defaultParentHash ?? $hasher->hashPassword($user, '12345678'));
        
        $em->persist($user);
        $guardian->setUser($user);
        $em->persist($guardian);
    }

    $this->guardianCache[$phone] = $guardian;

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

    // A number typed in the file is kept as-is. A blank cell gets the next
    // number in the school's own pattern — the import used to invent
    // `26/6EDD7` (year + random hex), which matched nothing else in the system
    // and could not be predicted by the school office.
    if (!empty($data['admission_number'])) {
        $student->setAdmissionNumber(trim($data['admission_number']));
    } else {
        $student->setAdmissionNumber($this->admissionNumbers->next($term?->getSession()));
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
        $invoice->setInvoiceNumber($this->invoiceNumbers->next($this->invoiceNumbers->yearFor($term->getSession())));
        $invoice->setStatus(Invoice::STATUS_UNPAID);
        $invoice->setPaidAmount("0");
        // Was 'TUITION', which every fee report ignores. Bulk-imported pupils
        // must land in the same academic ledger as walk-ins.
        $invoice->setType(Invoice::TYPE_ACADEMIC);

        $total = 0;
        foreach ($fees as $fee) {
            // ONLY bill compulsory items (Tuition, Exam, etc.)
            // Skip "Admission Form" or "Uniform" if you flagged them as 'isOneTime' in your DB
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
            $discount = $this->discountCalculator->calculateDiscount(
                $student,
                $this->discountCalculator->tuitionBaseFromStructures($fees)
            );
            $invoice->setDiscountApplied((string) $discount);
            $invoice->setDiscountLabel($this->discountCalculator->describe($student));
            $invoice->setTotalAmount((string) max(0.0, (float) $total - $discount));
            $em->persist($invoice);
            $this->advances->applyTo($invoice);
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