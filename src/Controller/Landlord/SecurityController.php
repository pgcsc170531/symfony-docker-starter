<?php

namespace App\Controller\Landlord;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/admin')]
class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_landlord_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If already logged in, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_landlord_dashboard');
        }

        // Get login error if any
        $error = $authenticationUtils->getLastAuthenticationError();
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('landlord/security/login.html.twig', [
            'last_username' => $lastUsername, 
            'error' => $error
        ]);
    }

    #[Route('/logout', name: 'app_landlord_logout')]
    public function logout(): void
    {
        // This code is never executed; the firewall intercepts it.
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}