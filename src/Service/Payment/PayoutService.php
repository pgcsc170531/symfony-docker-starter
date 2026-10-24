<?php

namespace App\Service\Payment;

use App\Entity\Tenant\Payout;
use App\Entity\Tenant\School;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pays the school what the ledger says we are holding for it — never a naira more.
 *
 * Rules enforced here rather than trusted to a caller:
 *
 *   1. **The amount comes from `2000 School payable`**, not from an invoice list.
 *      A school is owed exactly what it has been credited and not yet paid out.
 *   2. **A payout may not exceed that balance.** `LedgerPoster::payout()` refuses
 *      it too, so paying a school money that came from another school is
 *      impossible by construction.
 *   3. **The ledger entry is written only when the money actually left.** A refused
 *      or unknown transfer marks the payout failed and posts nothing — otherwise
 *      the books would say we had paid a school that never got the money.
 *   4. **One recipient, created once.** Paystack validates the account when the
 *      recipient is created; re-creating it per payout would silently accept a
 *      changed account number.
 *
 * `$simulate` proves the whole cycle without moving money: it posts the real
 * ledger entry and stores `simulated = true`, so it can never be mistaken for a
 * settlement.
 */
final class PayoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LedgerPoster $ledger,
        private readonly PaystackClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ok: bool, payout?: Payout, message?: string}
     */
    public function run(float $minimum = 0.0, bool $simulate = false, bool $live = false, ?string $note = null): array
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

        if ($simulate) {
            return $this->settle($payout, $note ?? 'Simulated payout — no money moved.');
        }

        if (!$live) {
            $payout->markFailed('Live payouts are not enabled for this run.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'Live payouts are off — run with --live to move money, or --simulate to test.'];
        }

        /** @var School|null $school */
        $school = $this->em->getRepository(School::class)->findOneBy([]);

        if ($school === null) {
            $payout->markFailed('No school record.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'No school record in this database.'];
        }

        $bankCode = trim((string) $school->getPaystackBankCode());
        $accountNumber = trim((string) $school->getAccountNumber());

        if ($bankCode === '' || $accountNumber === '') {
            $payout->markFailed('The school has no bank code / account number on file.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'Ask Edus to record this school\'s bank code and account number first.'];
        }

        $recipient = trim((string) $school->getPaystackRecipientCode());

        if ($recipient === '') {
            $created = $this->client->createTransferRecipient(
                (string) ($school->getAccountName() ?: $school->getName()),
                $bankCode,
                $accountNumber
            );

            if (!$created['ok'] || empty($created['code'])) {
                $payout->markFailed((string) ($created['message'] ?? 'Recipient could not be created.'));
                $this->em->flush();

                return ['ok' => false, 'message' => $created['message'] ?? 'Paystack would not register the school account.'];
            }

            $recipient = (string) $created['code'];
            $school->setPaystackRecipientCode($recipient);
            $this->em->flush();
        }

        $transfer = $this->client->initiateTransfer(
            $recipient,
            $amountMinor,
            'School fees collected online',
            $payout->getReference(),
        );

        if (!$transfer['ok']) {
            $payout->markFailed((string) ($transfer['message'] ?? 'Transfer refused.'));
            $this->em->flush();

            return ['ok' => false, 'message' => $transfer['message'] ?? 'The transfer was refused.'];
        }

        return $this->settle($payout, null, $transfer['transferCode'] ?? null, $transfer['status'] ?? null);
    }

    /**
     * Post the ledger entry and mark the payout paid. Only reached when money has
     * moved (or is explicitly simulated).
     */
    private function settle(Payout $payout, ?string $note, ?string $providerCode = null, ?string $providerStatus = null): array
    {
        $payout->markSuccess($providerCode ?? 'SIM-' . strtoupper(bin2hex(random_bytes(4))), $note);

        $entry = $this->ledger->payout(
            (int) $payout->getId(),
            $payout->getAmountMinor(),
            $payout->getReference(),
            'Payout to the school' . ($payout->isSimulated() ? ' (simulated)' : ''),
        );

        if ($entry === null) {
            $payout->markFailed('The ledger refused this payout: it exceeds what we hold.');
            $this->em->flush();

            return ['ok' => false, 'message' => 'The ledger refused this payout — it is larger than the amount held.'];
        }

        $this->em->flush();

        $this->logger->info('Payout {reference} of {amount} posted{simulated} (provider status: {status})', [
            'reference' => $payout->getReference(),
            'amount' => $payout->getAmountMinor() / 100,
            'simulated' => $payout->isSimulated() ? ' (simulated)' : '',
            'status' => $providerStatus ?? 'n/a',
        ]);

        return ['ok' => true, 'payout' => $payout];
    }
}
