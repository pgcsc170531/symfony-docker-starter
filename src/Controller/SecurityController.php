<?php

namespace App\Controller;

use App\Entity\Tenant\School; // 💡 Import your School Entity
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, EntityManagerInterface $em): Response
    {
        // 1. Get standard login details
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        // 2. 💡 FETCH SCHOOL BRANDING
        // Since the DB is already swapped based on subdomain, we just fetch the first row.
        $school = null;

        try {
            // Check if the School settings table exists and has data.
            // We use findAll() because there is only 1 school row per tenant database.
            $settings = $em->getRepository(School::class)->findAll();
            
            if (count($settings) > 0) {
                $school = $settings[0]; // Get the first (and only) row
            }
        } catch (\Exception $e) {
            // This catches errors if:
            // - We are on the Landlord domain (table doesn't exist)
            // - The tenant database is empty or new
            $school = null;
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'school' => $school, // 💡 Pass this to the template
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}