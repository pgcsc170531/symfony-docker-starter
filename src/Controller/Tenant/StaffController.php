<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Staff;
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
        // List only staff accounts (exclude parents and any non-staff users)
        $allUsers = $entityManager->getRepository(User::class)->findAll();
        $users = array_values(array_filter(
            $allUsers,
            fn(User $user) => $this->isStaffAccount($user)
        ));

        return $this->render('tenant/staff/index.html.twig', [
            'users' => $users,
        ]);
    }

    private function isStaffAccount(User $user): bool
    {
        foreach (['ROLE_ADMIN', 'ROLE_BURSAR', 'ROLE_STORE', 'ROLE_TEACHER', 'ROLE_HOD'] as $role) {
            if (in_array($role, $user->getRoles(), true)) {
                return true;
            }
        }

        return false;
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

            // Link a Staff profile so the user appears in academic assignments.
            $staff = new Staff();
            $staff->setUser($user);
            $entityManager->persist($staff);

            $entityManager->flush();

            $this->addFlash('success', 'New staff member created successfully!');

            return $this->redirectToRoute('app_tenant_staff_index');
        }

        return $this->render('tenant/staff/new.html.twig', [
            'form' => $form,
        ]);
    }
}