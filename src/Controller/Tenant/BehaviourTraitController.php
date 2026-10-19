<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\BehaviourRating;
use App\Entity\Tenant\BehaviourTrait;
use App\Service\SchoolLife\BehaviourTraitSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school owns the wording of its card.
 *
 * "Attitude to work" versus "Attitude to learning" is an argument we should not be
 * having, so traits are renameable, reorderable and disable-able. A trait is never
 * deleted: disabling hides it from new ratings while every rating already recorded
 * stays intact — which is what keeps last term's cards reproducible.
 */
#[Route('/settings/behaviour-traits')]
#[IsGranted('ROLE_ADMIN')]
final class BehaviourTraitController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private BehaviourTraitSeeder $seeder,
    ) {
    }

    #[Route('', name: 'app_tenant_behaviour_trait_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->seeder->ensureSeeded();

        $traits = $this->em->getRepository(BehaviourTrait::class)->findBy(
            [],
            ['domain' => 'ASC', 'sortOrder' => 'ASC', 'label' => 'ASC']
        );

        // How many ratings each trait carries — proof that disabling is not deleting.
        $usage = [];
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(r.trait) AS tid, COUNT(r.id) AS n')
            ->from(BehaviourRating::class, 'r')
            ->groupBy('r.trait')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $usage[(int) $row['tid']] = (int) $row['n'];
        }

        $grouped = [BehaviourTrait::AFFECTIVE => [], BehaviourTrait::PSYCHOMOTOR => []];
        foreach ($traits as $trait) {
            $grouped[$trait->getDomain()][] = $trait;
        }

        return $this->render('tenant/settings/behaviour_traits.html.twig', [
            'grouped' => $grouped,
            'usage' => $usage,
            'domains' => BehaviourTrait::DOMAINS,
            'labels' => BehaviourRating::LABELS,
        ]);
    }

    #[Route('/add', name: 'app_tenant_behaviour_trait_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('behaviour_trait', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        $label = trim((string) $request->request->get('label'));
        $domain = (string) $request->request->get('domain');

        if ($label === '' || !isset(BehaviourTrait::DOMAINS[$domain])) {
            $this->addFlash('error', 'Give the trait a name and pick a domain.');
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        $max = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(MAX(t.sortOrder), 0)')
            ->from(BehaviourTrait::class, 't')
            ->andWhere('t.domain = :d')
            ->setParameter('d', $domain)
            ->getQuery()
            ->getSingleScalarResult();

        $this->em->persist(new BehaviourTrait($domain, $label, $max + 10));
        $this->em->flush();

        $this->addFlash('success', sprintf('“%s” added.', $label));

        return $this->redirectToRoute('app_tenant_behaviour_trait_index');
    }

    #[Route('/{id}', name: 'app_tenant_behaviour_trait_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(BehaviourTrait $trait, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('behaviour_trait_' . $trait->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        $label = trim((string) $request->request->get('label'));
        if ($label !== '') {
            $trait->setLabel($label);
        }

        $trait->setIsActive($request->request->getBoolean('is_active'));
        $this->em->flush();

        $this->addFlash('success', 'Trait updated.');

        return $this->redirectToRoute('app_tenant_behaviour_trait_index');
    }

    /** Move one place up (-1) or down (+1) inside its own domain. */
    #[Route('/{id}/move/{direction}', name: 'app_tenant_behaviour_trait_move', methods: ['POST'], requirements: ['id' => '\d+', 'direction' => 'up|down'])]
    public function move(BehaviourTrait $trait, string $direction, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('behaviour_trait_move_' . $trait->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Please try again.');
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        $siblings = $this->em->getRepository(BehaviourTrait::class)->findBy(
            ['domain' => $trait->getDomain()],
            ['sortOrder' => 'ASC', 'label' => 'ASC']
        );

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling->getId() === $trait->getId()) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($siblings)) {
            return $this->redirectToRoute('app_tenant_behaviour_trait_index');
        }

        // Normalise both orders so a repeated move can never swap two equal values forever.
        $ordered = $siblings;
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
        $order = 10;
        foreach ($ordered as $item) {
            $item->setSortOrder($order);
            $order += 10;
        }

        $this->em->flush();

        return $this->redirectToRoute('app_tenant_behaviour_trait_index');
    }
}
