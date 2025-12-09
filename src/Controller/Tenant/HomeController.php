<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_tenant_home')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        // This query runs on WHICHEVER database the Listener selected!
        // If we are on faith.localhost, it queries school_faith.
        $studentCount = $entityManager->getRepository(Student::class)->count([]);

        return $this->render('tenant/home/index.html.twig', [
            'student_count' => $studentCount,
        ]);
    }
}