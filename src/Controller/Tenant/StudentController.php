<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Student;
use App\Entity\Tenant\Enrollment; 
use App\Entity\Tenant\Invoice; 
use App\Entity\Tenant\Guardian; 
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Term;
use App\Form\StudentType; 
use App\Form\StudentAdmissionType;
use App\Form\PhotoUploadType; 

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface; 
use Symfony\Component\HttpFoundation\File\UploadedFile; 
use Symfony\Component\HttpFoundation\File\Exception\FileException; 
use Doctrine\ORM\Tools\Pagination\Paginator;

#[Route('/students')]
class StudentController extends AbstractController
{
    #[Route('/', name: 'app_tenant_student_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $searchQuery = $request->query->get('q', '');
        $classId = $request->query->get('class_id', ''); 
        $financeStatus = $request->query->get('finance_status', ''); 
        
        $currentPage = max(1, $request->query->getInt('page', 1));
        $limit = 20; 

        $classrooms = $em->getRepository(Classroom::class)->findAll();

        $qb = $em->getRepository(Student::class)->createQueryBuilder('s')
            ->orderBy('s.id', 'DESC'); 

        if ($searchQuery) {
            $qb->andWhere('s.firstName LIKE :query OR s.lastName LIKE :query OR s.admissionNumber LIKE :query')
               ->setParameter('query', '%' . $searchQuery . '%');
        }

        if ($classId) {
            $qb->andWhere('s.currentClass = :classId')
               ->setParameter('classId', $classId);
        }

        if ($financeStatus === 'debtor') {
            $qb->join('s.invoices', 'i')
               ->andWhere("i.status != 'PAID'");
        } elseif ($financeStatus === 'cleared') {
            $qb->leftJoin('s.invoices', 'i', \Doctrine\ORM\Query\Expr\Join::WITH, "i.status != 'PAID'")
               ->andWhere('i.id IS NULL');
        }

        $qb->setFirstResult(($currentPage - 1) * $limit)
           ->setMaxResults($limit);

        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = max(1, ceil($totalItems / $limit)); 

        // ======================================================
        // 🟢 NEW: SPLIT DEBT CALCULATOR FOR THE UI BADGES
        // ======================================================
        $studentDebts = [];
        
        foreach ($paginator as $student) {
            $academicDebt = 0.0;
            $storeDebt = 0.0;
            
            foreach ($student->getInvoices() as $invoice) {
                if ($invoice->getStatus() !== 'PAID') {
                    $due = (float)$invoice->getTotalAmount() - (float)$invoice->getPaidAmount();
                    
                    if ($invoice->getType() === 'ACADEMIC') {
                        $academicDebt += $due;
                    } elseif ($invoice->getType() === 'STORE') {
                        $storeDebt += $due;
                    }
                }
            }
            
            $studentDebts[$student->getId()] = [
                'academic' => $academicDebt,
                'store' => $storeDebt,
                'total' => $academicDebt + $storeDebt
            ];
        }
        // ======================================================

        return $this->render('tenant/student/index.html.twig', [
            'students' => $paginator,
            'classrooms' => $classrooms, 
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'selected_class' => $classId, 
            'finance_status' => $financeStatus,
            'studentDebts' => $studentDebts // 🟢 Pass the calculated split debts to Twig!
        ]);
    }

    #[Route('/{id}/finance', name: 'app_tenant_student_finance', methods: ['GET'])]
    public function financeProfile(Student $student, EntityManagerInterface $em): Response
    {
        $invoices = $em->getRepository(Invoice::class)->findBy(
            ['student' => $student],
            ['id' => 'DESC']
        );

        return $this->render('tenant/student/finance.html.twig', [
            'student' => $student,
            'invoices' => $invoices
        ]);
    }

  #[Route('/{id}/profile', name: 'app_tenant_student_profile', methods: ['GET'])]
    public function profile(Student $student, EntityManagerInterface $em): Response
    {
        // 1. Fetch Enrollment
        $currentEnrollment = $em->getRepository(Enrollment::class)->findOneBy(
            ['student' => $student],
            ['id' => 'DESC'] 
        );
        
        // 2. Fetch Guardian and Siblings
        $guardian = $student->getGuardian();
        $siblings = [];
        
        if ($guardian) {
            $siblings = $em->getRepository(Student::class)->findBy(
                ['guardian' => $guardian]
            );
            
            $siblings = array_filter($siblings, function($sibling) use ($student) {
                return $sibling->getId() !== $student->getId();
            });
        }

        // 3. Fetch Recent Invoices
        $recentInvoices = $em->getRepository(Invoice::class)->findBy(
            ['student' => $student],
            ['createdAt' => 'DESC'],
            3
        );
        
        // 4. Calculate Total Balance
        $allInvoices = $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.student = :student')
            ->andWhere('i.status != :paidStatus')
            ->setParameter('student', $student)
            ->setParameter('paidStatus', 'PAID')
            ->getQuery()
            ->getResult();

        $totalBalance = 0;
        foreach ($allInvoices as $inv) {
            $totalBalance += ($inv->getTotalAmount() - $inv->getPaidAmount());
        }

        // ======================================================
        // 🟢 NEW: CHECK IF CURRENT TERM FEES ARE GENERATED
        // ======================================================
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $hasCurrentTermFee = false;

        if ($activeTerm) {
            $currentFeeInvoice = $em->getRepository(Invoice::class)->findOneBy([
                'student' => $student,
                'term' => $activeTerm,
                'type' => 'ACADEMIC'
            ]);
            $hasCurrentTermFee = $currentFeeInvoice !== null;
        }

        return $this->render('tenant/student/profile.html.twig', [
            'student' => $student,
            'enrollment' => $currentEnrollment,
            'guardian' => $guardian,
            'siblings' => $siblings,
            'recentInvoices' => $recentInvoices,
            'totalBalance' => $totalBalance, 
            'activeTerm' => $activeTerm,             // 🟢 Pass to Twig
            'hasCurrentTermFee' => $hasCurrentTermFee // 🟢 Pass to Twig
        ]);
    }

    #[Route('/{id}/print/idcard', name: 'app_tenant_student_print_idcard', methods: ['GET'])]
    public function printIdCard(Student $student, EntityManagerInterface $em): Response
    {
        $currentEnrollment = $em->getRepository(Enrollment::class)->findOneBy(
            ['student' => $student],
            ['id' => 'DESC'] 
        );

        return $this->render('tenant/student/print/idcard.html.twig', [
            'student' => $student,
            'enrollment' => $currentEnrollment, 
        ]);
    }

    #[Route('/{id}/upload/photo', name: 'app_tenant_student_upload_photo', methods: ['GET', 'POST'])]
    public function uploadPhoto(
        Student $student, 
        Request $request, 
        EntityManagerInterface $em,
        SluggerInterface $slugger 
    ): Response
    {
        $form = $this->createForm(PhotoUploadType::class); 
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            /** @var UploadedFile $photoFile */
            $photoFile = $form->get('photoFile')->getData();

            if ($photoFile) {
                $originalFilename = pathinfo($photoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.$student->getId().'-'.uniqid().'.'.$photoFile->guessExtension();

                try {
                    $photoFile->move(
                        $this->getParameter('student_photos_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', 'Could not save the photo file: ' . $e->getMessage());
                    return $this->redirectToRoute('app_tenant_student_profile', ['id' => $student->getId()]);
                }

                $student->setProfilePictureFilename($newFilename);
                $em->flush();

                $this->addFlash('success', 'Profile picture uploaded successfully!');
                return $this->redirectToRoute('app_tenant_student_profile', ['id' => $student->getId()]);
            }
        }

        return $this->render('tenant/student/upload_photo.html.twig', [
            'student' => $student,
            'form' => $form->createView(), 
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_student_edit', methods: ['GET', 'POST'])]
    public function edit(Student $student, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(StudentAdmissionType::class, $student);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'Student profile updated successfully!');
            return $this->redirectToRoute('app_tenant_student_profile', ['id' => $student->getId()]);
        }

        return $this->render('tenant/student/edit.html.twig', [
            'form' => $form->createView(),
            'student' => $student
        ]);
    }

    #[Route('/class/{id}/score-sheet', name: 'app_tenant_class_score_sheet', methods: ['GET'])]
    public function printScoreSheet(Classroom $classroom, EntityManagerInterface $em): Response
    {
        $students = $em->getRepository(Student::class)->findBy(
            ['currentClass' => $classroom],
            ['firstName' => 'ASC'] 
        );

        /** @var \App\Entity\Tenant\User $user */
        $tenant = $this->getUser()->getTenant();

        return $this->render('tenant/student/print_score_sheet.html.twig', [
            'classroom' => $classroom,
            'students' => $students,
            'tenant' => $tenant
        ]);
    }

    #[Route('/export', name: 'app_tenant_student_export', methods: ['GET'])]
    public function exportCsv(Request $request, EntityManagerInterface $em): Response
    {
        $searchQuery = $request->query->get('q', '');
        $classId = $request->query->get('class_id', ''); 
        $financeStatus = $request->query->get('finance_status', ''); 

        $qb = $em->getRepository(Student::class)->createQueryBuilder('s')
            ->orderBy('s.firstName', 'ASC'); 

        if ($searchQuery) {
            $qb->andWhere('s.firstName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }
        if ($classId) {
            $qb->andWhere('s.currentClass = :classId')
               ->setParameter('classId', $classId);
        }

        if ($financeStatus === 'debtor') {
            $qb->join('s.invoices', 'i')
               ->andWhere("i.status != 'PAID'");
        } elseif ($financeStatus === 'cleared') {
            $qb->leftJoin('s.invoices', 'i', \Doctrine\ORM\Query\Expr\Join::WITH, "i.status != 'PAID'")
               ->andWhere('i.id IS NULL');
        }

        $students = $qb->getQuery()->getResult();

        $fp = fopen('php://temp', 'w');

        // 🟢 UPDATED: Excel Columns now show exact split debts!
        fputcsv($fp, ['Admission No', 'First Name', 'Last Name', 'Gender', 'Class', 'Academic Debt', 'Store Debt', 'Total Debt', 'Guardian Name', 'Guardian Phone']);

        foreach ($students as $student) {
            $academicDebt = 0.0;
            $storeDebt = 0.0;

            // Calculate exact split debts for the Excel export
            foreach ($student->getInvoices() as $invoice) {
                if ($invoice->getStatus() !== 'PAID') {
                    $due = (float)$invoice->getTotalAmount() - (float)$invoice->getPaidAmount();
                    if ($invoice->getType() === 'ACADEMIC') $academicDebt += $due;
                    if ($invoice->getType() === 'STORE') $storeDebt += $due;
                }
            }

            fputcsv($fp, [
                $student->getAdmissionNumber() ?? 'PENDING',
                $student->getFirstName(),
                $student->getLastName(),
                $student->getGender(),
                $student->getCurrentClass() ? $student->getCurrentClass()->getName() : 'Unassigned',
                $academicDebt, // Academic Debt Column
                $storeDebt,    // Store Debt Column
                $academicDebt + $storeDebt, // Total Debt Column
                $student->getGuardian() ? $student->getGuardian()->getFullName() : 'N/A',
                $student->getGuardian() ? $student->getGuardian()->getPhoneNumber() : 'N/A',
            ]);
        }

        rewind($fp);
        $csvContent = stream_get_contents($fp);
        fclose($fp);

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="student_directory_export.csv"');

        return $response;
    }
}