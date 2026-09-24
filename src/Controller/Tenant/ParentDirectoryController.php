<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/parents')]
#[IsGranted('ROLE_ADMIN')]
class ParentDirectoryController extends AbstractController
{
    #[Route('/', name: 'app_tenant_parent_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        // Fetch every guardian (parent) with their linked login account.
        // Students are lazy-loaded in the template to avoid duplicate rows.
        $guardians = $em->getRepository(Guardian::class)
            ->createQueryBuilder('g')
            ->leftJoin('g.user', 'u')
            ->addSelect('u')
            ->orderBy('g.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('tenant/parent/index.html.twig', [
            'guardians' => $guardians,
        ]);
    }
}
