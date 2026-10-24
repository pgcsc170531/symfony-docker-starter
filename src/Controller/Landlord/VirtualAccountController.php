<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\VirtualAccountIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every family payment account Edus has issued, across every school.
 *
 * Paystack caps how many dedicated virtual accounts one merchant may hold
 * (1,000 by default, raised on request), so the operator needs one number to
 * watch: **how many are taken, and how fast are they filling up**. Asking
 * Paystack for the list is not possible — its list endpoint is per customer — so
 * this screen reads `virtual_account_index`, the landlord-side routing table that
 * every issued account writes on the way through
 * (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §4.7, §6).
 *
 * Nothing here decides money: it is a count and a directory, not a ledger.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class VirtualAccountController extends AbstractController
{
    #[Route('/admin/virtual-accounts', name: 'landlord_virtual_accounts', methods: ['GET'])]
    public function index(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $em,
        #[Autowire('%paystack_dva_limit%')]
        int $limit,
    ): Response {
        $accounts = $em->getRepository(VirtualAccountIndex::class)->findBy([], ['id' => 'DESC']);

        // Counted per school, because the budget is spent per school: one school
        // with 800 pupils can eat four fifths of the allowance on its own.
        $perSchool = [];
        foreach ($accounts as $account) {
            $key = (string) ($account->getSchool()?->getName() ?? 'Unknown school');
            $perSchool[$key] = ($perSchool[$key] ?? 0) + 1;
        }
        arsort($perSchool);

        $used = count($accounts);
        $limit = max(1, $limit);
        $percent = (int) round($used / $limit * 100);

        // Three states, not a continuous gradient: under 70% is normal, 70–89%
        // means "ask Paystack soon", 90%+ means the next enrolments will fail.
        $state = $percent >= 90 ? 'critical' : ($percent >= 70 ? 'warning' : 'ok');

        return $this->render('landlord/virtual_accounts/index.html.twig', [
            'accounts' => $accounts,
            'used' => $used,
            'limit' => $limit,
            'percent' => $percent,
            'state' => $state,
            'perSchool' => $perSchool,
            'remaining' => max(0, $limit - $used),
        ]);
    }
}
