<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\ReportArchive;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The permanent register of PDFs the school has issued. */
#[Route('/reports/archive')]
#[IsGranted('ROLE_ADMIN')]
final class ReportArchiveController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_tenant_report_archive', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $terms = $this->em->getRepository(Term::class)->findBy([], ['startDate' => 'DESC', 'id' => 'DESC']);
        $termId = (int) $request->query->get('term', 0);
        $term = $termId > 0
            ? $this->em->getRepository(Term::class)->find($termId)
            : ($this->em->getRepository(Term::class)->findOneBy(['isActive' => true]) ?: ($terms[0] ?? null));

        $rows = [];
        if ($term) {
            $rows = $this->em->getRepository(ReportArchive::class)
                ->createQueryBuilder('a')
                ->addSelect('s')
                ->join('a.student', 's')
                ->andWhere('a.term = :term')
                ->setParameter('term', $term)
                ->orderBy('s.lastName', 'ASC')
                ->addOrderBy('s.firstName', 'ASC')
                ->addOrderBy('a.kind', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('tenant/reports/archive.html.twig', [
            'terms' => $terms,
            'term' => $term,
            'rows' => $rows,
        ]);
    }
}
