<?php

namespace App\Controller\Landlord;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[Route('/agent', name: 'app_agent_')]
class AgentSecurityController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // 1. If already logged in, redirect to dashboard
        if ($this->getUser()) {
             return $this->redirectToRoute('app_agent_dashboard');
        }

        // 2. Get login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // 3. Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('landlord/agent/security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): void
    {
        // This method can be blank - it will be intercepted by the logout key on your firewall
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}