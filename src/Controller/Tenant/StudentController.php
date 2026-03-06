<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Student;
use App\Entity\Tenant\Enrollment; // Used in profile()
use App\Entity\Tenant\Invoice; // Used in profile() and financeProfile()
use App\Entity\Tenant\Guardian; // Used in profile()
use App\Form\StudentType; // Used in index()
use App\Form\PhotoUploadType; // 💡 CRITICAL: Used in uploadPhoto()

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface; // 💡 CRITICAL: Used in uploadPhoto()
use Symfony\Component\HttpFoundation\File\UploadedFile; // 💡 CRITICAL: Used in uploadPhoto()
use Symfony\Component\HttpFoundation\File\Exception\FileException; // 💡 CRITICAL: Used in uploadPhoto()


#[Route('/students')]
class StudentController extends AbstractController
{
    #[Route('/', name: 'app_tenant_student_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        // 1. Handle "Add New Student"
        $student = new Student();
        $form = $this->createForm(StudentType::class, $student);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($student);
            $em->flush();

            $this->addFlash('success', 'Student registered successfully!');
            return $this->redirectToRoute('app_tenant_student_index');
        }

        // 2. List Students
        $students = $em->getRepository(Student::class)->findAll();

        return $this->render('tenant/student/index.html.twig', [
            'students' => $students,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/finance', name: 'app_tenant_student_finance', methods: ['GET'])]
    public function financeProfile(Student $student, EntityManagerInterface $em): Response
    {
        // Fetch all invoices (Academic AND Store)
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
            
            // Remove current student from siblings list
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
        
        // 🟢 4. CALCULATE TOTAL BALANCE (This was missing)
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

        // 5. Final Return
        return $this->render('tenant/student/profile.html.twig', [
            'student' => $student,
            'enrollment' => $currentEnrollment,
            'guardian' => $guardian,
            'siblings' => $siblings,
            'recentInvoices' => $recentInvoices,
            'totalBalance' => $totalBalance, // 🟢 THIS KEY MUST BE HERE
        ]);
    }

    #[Route('/{id}/print/idcard', name: 'app_tenant_student_print_idcard', methods: ['GET'])]
    public function printIdCard(Student $student, EntityManagerInterface $em): Response // 💡 CRITICAL: Add EntityManagerInterface
    {
        // 💡 NEW LOGIC: Fetch current/latest enrollment details
        $currentEnrollment = $em->getRepository(Enrollment::class)->findOneBy(
            ['student' => $student],
            ['id' => 'DESC'] // Assuming the highest ID is the latest enrollment
        );

        // This controller action loads a specific TWIG template designed 
        // for printing (e.g., small size, minimal styling).
        return $this->render('tenant/student/print/idcard.html.twig', [
            'student' => $student,
            'enrollment' => $currentEnrollment, // 💡 CRITICAL: Pass the enrollment object
        ]);
    }

    #[Route('/{id}/upload/photo', name: 'app_tenant_student_upload_photo', methods: ['GET', 'POST'])]
    public function uploadPhoto(
        Student $student, 
        Request $request, 
        EntityManagerInterface $em,
        SluggerInterface $slugger // 💡 CRITICAL: Injection added here
    ): Response
    {
        $form = $this->createForm(PhotoUploadType::class); // 💡 CRITICAL: Form created here
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            /** @var UploadedFile $photoFile */
            $photoFile = $form->get('photoFile')->getData();

            if ($photoFile) {
                // 1. Create a safe, unique filename
                $originalFilename = pathinfo($photoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.$student->getId().'-'.uniqid().'.'.$photoFile->guessExtension();

                // 2. Move the file to the target directory
                try {
                    $photoFile->move(
                        $this->getParameter('student_photos_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', 'Could not save the photo file: ' . $e->getMessage());
                    return $this->redirectToRoute('app_tenant_student_profile', ['id' => $student->getId()]);
                }

                // 3. Update the Student entity with the filename and save to DB
                $student->setProfilePictureFilename($newFilename);
                $em->flush();

                $this->addFlash('success', 'Profile picture uploaded successfully!');
                return $this->redirectToRoute('app_tenant_student_profile', ['id' => $student->getId()]);
            }
        }

        return $this->render('tenant/student/upload_photo.html.twig', [
            'student' => $student,
            'form' => $form->createView(), // Pass the form view to the template
        ]);
    }

    
}