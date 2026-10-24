<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\School as LandlordSchool;
use App\Service\Payment\LedgerPoster;
use App\Service\Tenant\TenantConnectionSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Edus's own view of the money: what we are holding, what we earned, what the
 * bank cost, and which school each naira belongs to.
 *
 * The ledgers live in the tenant databases (one book per school, §4), so this
 * gathers them by switching **read-only** into each tenant. That is fine for a
 * handful of schools and the honest limit of this screen — past a hundred, this
 * belongs in a nightly roll-up table rather than a request (ONLINE-PAYMENT-LEDGER
 * .md §13 D3).
 *
 * The number at the top is the one that matters: **float held** is a liability. If
 * it is wrong, a school is about to be short-paid or over-paid.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class PaymentLedgerController extends AbstractController
{
    #[Route('/admin/payment-ledger', name: 'landlord_payment_ledger', methods: ['GET'])]
    public function index(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        TenantConnectionSwitcher $switcher,
        LedgerPoster $ledger,
    ): Response {
        /** @var LandlordSchool[] $schools */
        $schools = $landlordEm->getRepository(LandlordSchool::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        $rows = [];
        $float = 0.0;
        $collected = 0.0;
        $revenue = 0.0;
        $cost = 0.0;
        $cash = 0.0;
        $unallocated = 0.0;

        foreach ($schools as $school) {
            $switcher->switchTo($school);

            try {
                $payableMovements = $ledger->movements(LedgerPoster::SCHOOL_PAYABLE);
                $held = $ledger->schoolPayable();
                $collectedHere = $payableMovements['credit'] / 100;
                $paidOutHere = $payableMovements['debit'] / 100;
                $revenueHere = -$ledger->balanceMinor(LedgerPoster::HANDLING_REVENUE) / 100;
                $costHere = $ledger->gatewayCost();
                $cashHere = $ledger->cashAtPaystack();
                $unallocatedHere = $ledger->unallocated();

                $rows[] = [
                    'school' => $school,
                    'collected' => $collectedHere,
                    'paidOut' => $paidOutHere,
                    'held' => $held,
                    'revenue' => $revenueHere,
                    'cost' => $costHere,
                    'unallocated' => $unallocatedHere,
                ];

                $float += $held;
                $collected += $collectedHere;
                $revenue += $revenueHere;
                $cost += $costHere;
                $cash += $cashHere;
                $unallocated += $unallocatedHere;
            } catch (\Throwable) {
                // A tenant whose database is not reachable must not blank the
                // platform's view of everyone else's money.
                $rows[] = ['school' => $school, 'unreachable' => true];
            } finally {
                $switcher->restore();
            }
        }

        return $this->render('landlord/payment_ledger/index.html.twig', [
            'rows' => $rows,
            'float' => $float,
            'collected' => $collected,
            'revenue' => $revenue,
            'cost' => $cost,
            'net' => $revenue - $cost,
            'cash' => $cash,
            'unallocated' => $unallocated,
        ]);
    }
}
