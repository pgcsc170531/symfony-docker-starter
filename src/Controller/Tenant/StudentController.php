<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Student;
use App\Form\StudentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/student')]
class StudentController extends AbstractController
{
    #[Route('/new', name: 'app_tenant_student_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        // 1. Create a blank Student
        $student = new Student();
        
        // 2. Create the Form
        $form = $this->createForm(StudentType::class, $student);
        $form->handleRequest($request);

        // 3. Handle Submission
        if ($form->isSubmitted() && $form->isValid()) {
            // Note: $entityManager here is ALREADY switched to the correct school DB
            // because of your Listener!
            $entityManager->persist($student);
            $entityManager->flush();

            $this->addFlash('success', 'Student registered successfully!');

            return $this->redirectToRoute('app_tenant_student_new');
        }

        return $this->render('tenant/student/new.html.twig', [
            'student' => $student,
            'form' => $form,
        ]);
    }
}