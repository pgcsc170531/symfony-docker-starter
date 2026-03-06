<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\User;
use App\Form\StaffType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/staff')]
#[IsGranted('ROLE_ADMIN')] // Only Principal can access
class StaffController extends AbstractController
{
    #[Route('/', name: 'app_tenant_staff_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        // List all users
        $users = $entityManager->getRepository(User::class)->findAll();

        return $this->render('tenant/staff/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/new', name: 'app_tenant_staff_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request, 
        EntityManagerInterface $entityManager, 
        UserPasswordHasherInterface $passwordHasher
    ): Response
    {
        $user = new User();
        $form = $this->createForm(StaffType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            
            // Hash the password
            $plainPassword = $form->get('plainPassword')->getData();
            $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'New staff member created successfully!');

            return $this->redirectToRoute('app_tenant_staff_index');
        }

        return $this->render('tenant/staff/new.html.twig', [
            'form' => $form,
        ]);
    }
}