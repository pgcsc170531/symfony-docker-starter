<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/parents')]
#[IsGranted('ROLE_ADMIN')]
class ParentDirectoryController extends AbstractController
{
    #[Route('/', name: 'app_tenant_parent_index', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        // Fetch every guardian (parent) with their linked login account.
        // Students are lazy-loaded in the template to avoid duplicate rows.
        $qb = $em->getRepository(Guardian::class)
            ->createQueryBuilder('g')
            ->leftJoin('g.user', 'u')
            ->addSelect('u')
            ->orderBy('g.fullName', 'ASC');

        if ($q !== '') {
            $needle = '%' . mb_strtolower($q) . '%';

            // One field, because the person at the desk has one piece of
            // information: a name, a phone number, an email, or the child's name.
            // Phone numbers are stored with spaces/dashes/+234 in every shape
            // imaginable, so the normalising happens in SQL (DQL has no REPLACE).
            $where = 'LOWER(g.fullName) LIKE :q'
                . ' OR LOWER(g.email) LIKE :q'
                . ' OR LOWER(g.phoneNumber) LIKE :q'
                . ' OR LOWER(g.alternatePhoneNumber) LIKE :q'
                . ' OR LOWER(u.email) LIKE :q'
                . ' OR EXISTS (SELECT s.id FROM ' . Student::class . ' s'
                . ' WHERE s.guardian = g AND (LOWER(s.firstName) LIKE :q'
                . ' OR LOWER(s.lastName) LIKE :q OR LOWER(s.middleName) LIKE :q'
                . ' OR LOWER(s.admissionNumber) LIKE :q))';

            $digits = preg_replace('/\D+/', '', $q) ?? '';
            if (strlen($digits) >= 3) {
                $ids = $em->getConnection()->fetchFirstColumn(
                    "SELECT id FROM guardian
                      WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?
                         OR REPLACE(REPLACE(REPLACE(REPLACE(alternate_phone_number, ' ', ''), '-', ''), '+', ''), '.', '') LIKE ?",
                    ['%' . $digits . '%', '%' . $digits . '%']
                );

                if ($ids !== []) {
                    $where .= ' OR g.id IN (:ids)';
                    $qb->setParameter('ids', array_map('intval', $ids));
                }
            }

            $qb->andWhere($where)->setParameter('q', $needle);
        }

        return $this->render('tenant/parent/index.html.twig', [
            'guardians' => $qb->getQuery()->getResult(),
            'q' => $q,
        ]);
    }
}
