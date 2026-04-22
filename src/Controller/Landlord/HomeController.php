<?php

namespace App\Controller\Landlord;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// 🟢 FIX: We add the 'host' requirement to the CLASS or METHODS.
// This ensures these pages ONLY load on 'localhost' (the main site),
// allowing subdomains (tenant.localhost) to fall through to your Tenant logic.

class HomeController extends AbstractController
{
    // Using %app.base_domain% allows this to work on both your laptop AND the server
    #[Route('/', name: 'app_home', methods: ['GET'], host: '%app.base_domain%')]
    public function index(): Response
    {
        return $this->render('landlord/home/index.html.twig', [
            'app_name' => 'Edus',
        ]);
    }

    #[Route('/features', name: 'app_features', methods: ['GET'], host: '%app.base_domain%')]
    public function features(): Response
    {
        return $this->render('landlord/home/features.html.twig');
    }

    #[Route('/partners', name: 'app_partners', methods: ['GET'], host: '%app.base_domain%')]
    public function partners(): Response
    {
        return $this->render('landlord/home/partners.html.twig');
    }

    // 🟢 NEW: Add the route for the User Manual
    #[Route('/manual', name: 'app_manual', methods: ['GET'], host: '%app.base_domain%')]
    public function manual(): Response
    {
        return $this->render('landlord/home/manual.html.twig');
    }
}