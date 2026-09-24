<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    private function getSchool(EntityManagerInterface $entityManager): ?School
    {
        // The tenant EntityManager is already connected to school_{subdomain}.
        return $entityManager->getRepository(School::class)->findOneBy([]);
    }

    /**
     * Convert the School entity into a plain array containing only scalar values.
     * This prevents Twig from receiving arrays/objects and triggering
     * "Array to string conversion" warnings.
     */
    private function schoolArray(?School $school): array
    {
        if (!$school) {
            return [];
        }

        $fields = [
            'name',
            'motto',
            'logoFilename',
            'address',
            'phoneNumber',
            'email',
            'website',
            'primaryColor',
            'heroTagline',
            'heroImageFilename',
            'aboutContent',
            'admissionsIntro',
            'mapEmbedUrl',
            'facebookUrl',
            'twitterUrl',
            'instagramUrl',
        ];

        $data = [];

        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);
            $value = method_exists($school, $getter) ? $school->$getter() : null;
            $data[$field] = is_scalar($value) ? $value : null;
        }

        return $data;
    }

    #[Route('/', name: 'app_tenant_home', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function index(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        // If no students exist, this returns 0 — no error.
        $studentCount = $entityManager->getRepository(Student::class)->count([]);

        return $this->render('tenant/home/index.html.twig', [
            'school' => $this->schoolArray($school),
            'student_count' => $studentCount,
            'subdomain' => $subdomain,
        ]);
    }

    #[Route('/about', name: 'app_tenant_about', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function about(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        return $this->render('tenant/home/about.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
        ]);
    }

    #[Route('/admissions', name: 'app_tenant_admissions', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function admissions(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        return $this->render('tenant/home/admissions.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
        ]);
    }

    #[Route('/contact', name: 'app_tenant_contact', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function contact(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        return $this->render('tenant/home/contact.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
        ]);
    }
}