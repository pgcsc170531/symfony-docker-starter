<?php

namespace App\Controller\Tenant;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RootController extends AbstractController
{
    #[Route('/', name: 'app_root', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_tenant_dashboard');
    }
}