<?php

namespace App\Service\Payment;

use App\Entity\Tenant\Payout;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pays the school what the ledger says we are holding for it — never a naira more.
 *
 * Two rules, both enforced here rather than trusted to the caller:
 *
 *   1. **The amount comes from `2000 School payable`, not from an invoice list.**
 *      A school is owed exactly what it has been credited and not yet paid out;
 *      recomputing from bills would drift the moment a credit was unassigned or a
 *      parent part-paid.
 *   2. **A payout may not exceed that balance.** `LedgerPoster::payout()` refuses
 *      it as well, so paying a school money that came from another school is
 *      impossible by construction.
 *
 * `$simulate` exists for the same reason `app:payments:simulate-credit` does: the
 * books can be proven end to end before a real transfer is risked. A simulated
 * payout posts the real ledger entry but stores `simulated = true`, so it can
 * never be mistaken for money that left the account.
 */
final class PayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LedgerPoster $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ok: bool, payout?: Payout, message?: string}
     */
    public function run(float $minimum = 0.0, bool $simulate = false, ?string $note = null): array
    {
        $available = $this->ledger->schoolPayable();

        if ($available <= 0) {
            return ['ok' => false, 'message' => 'Nothing is held for this school — no payout needed.'];
        }

        if ($minimum > 0 && $available < $minimum) {
            return ['ok' => false, 'message' => sprintf(
                'Held ₦%s is below the ₦%s payout threshold.',
                number_format($available, 2),
                number_format($minimum, 2)
            )];
        }

        $amountMinor = (int) round($available * 100);

        $payout = new Payout();
        $payout->setAmountMinor($amountMinor)->setSimulated($simulate);
        $this->em->persist($payout);

        // The payout needs an id before the ledger entry can point at it.
        $this->em->flush();

        if (!$simulate) {
            // The real provider call lives here. It is deliberately not wired yet:
            // until `transferrecipient` + `transfer` are exercised against a test
            // school, a live key must not be able to move a school's money by
            // accident. Everything below is identical either way.
            $payout->markFailed('Real payouts are not enabled yet.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'Real payouts are not enabled yet — run with --simulate.'];
        }

        $payout->markSuccess('SIM-' . strtoupper(bin2hex(random_bytes(4))), $note ?? 'Simulated payout — no money moved.');

        $entry = $this->ledger->payout(
            (int) $payout->getId(),
            $amountMinor,
            $payout->getReference(),
            sprintf('Payout to the school%s', $simulate ? ' (simulated)' : ''),
        );

        if ($entry === null) {
            $payout->markFailed('The ledger refused this payout: it exceeds what we hold.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'The ledger refused this payout — it is larger than the amount held.'];
        }

        $this->em->flush();

        $this->logger->info('Payout {reference} of {amount} posted{simulated}', [
            'reference' => $payout->getReference(),
            'amount' => $available,
            'simulated' => $simulate ? ' (simulated)' : '',
        ]);

        return ['ok' => true, 'payout' => $payout];
    }
}
