<?php

namespace App\Service\Payment;

use App\Entity\Tenant\AccountCredit;
use App\Entity\Tenant\LedgerEntry;
use App\Entity\Tenant\LedgerLine;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The only way anything gets written into the ledger.
 *
 * Why a single door: every rule that makes a ledger trustworthy has to hold for
 * *every* posting, and they are cheap to state but easy to break if each caller
 * builds its own entry.
 *
 *   1. **Debits must equal credits.** An unbalanced entry throws before it is
 *      persisted — a half-written entry is worse than a failed one.
 *   2. **Idempotent.** `(sourceType, sourceId)` is unique; posting the same
 *      credit twice is a no-op, not a double count. That is what makes a replayed
 *      webhook harmless.
 *   3. **No updates, no deletes.** A correction is a new entry that reverses the
 *      old one.
 *   4. **Kobo, integers.** No floats touch the books.
 *
 * The accounts this class knows about are the platform's, not the school's: we
 * hold the school's money as a **liability** until we pay it out
 * (docs/payments/ONLINE-PAYMENT-LEDGER.md §5). That is why the payout debits
 * `2000 School payable` — and why it can never pay a school more than it holds.
 */
final class LedgerPoster
{
    /** Money in our Paystack balance. */
    public const CASH_AT_PAYSTACK = '1000';

    /** Held on behalf of the school — a liability, paid out later. */
    public const SCHOOL_PAYABLE = '2000';

    /** Received but not yet matched to a bill. */
    public const UNALLOCATED = '2100';

    /** The flat charge — Edus's revenue. */
    public const HANDLING_REVENUE = '4100';

    /** Paystack's fee. */
    public const GATEWAY_COST = '5000';

    /** Anything unmapped. Its balance should always be zero. */
    public const SUSPENSE = '9000';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The transfer arrived on a family account.
     *
     *   Dr 1000 Cash at Paystack     ₦65,250
     *      Cr 2100 Unallocated                  ₦65,250
     *
     * It sits in `2100` until it is matched, because at this instant we do not
     * know whose fee it is — that is the whole point of the unassigned inbox.
     */
    public function creditReceived(AccountCredit $credit): ?LedgerEntry
    {
        $id = $credit->getId();
        if ($id === null) {
            return null;
        }

        $minor = $this->toMinor($credit->getAmount());

        return $this->post(
            LedgerEntry::SOURCE_CREDIT_RECEIVED,
            $id,
            $credit->getProviderReference(),
            'Bank transfer received on ' . ($credit->getVirtualAccount()?->getAccountNumber() ?? 'a family account'),
            [LedgerLine::debit(self::CASH_AT_PAYSTACK, $minor), LedgerLine::credit(self::UNALLOCATED, $minor)],
            'webhook',
            $credit->getPaidAt(),
        );
    }

    /**
     * The transfer was matched to a bill.
     *
     *   Dr 2100 Unallocated          ₦65,250
     *      Cr 2000 School payable               ₦64,500
     *      Cr 4100 Handling charge                 ₦750
     *
     * The split comes from the payment that was written, not from a formula:
     * whatever the school was credited is theirs, and the remainder is our charge.
     */
    public function creditApplied(AccountCredit $credit): ?LedgerEntry
    {
        $id = $credit->getId();
        if ($id === null || $credit->getPayment() === null) {
            return null;
        }

        $total = $this->toMinor($credit->getAmount());
        $school = $this->toMinor($credit->getPayment()->getAmount());
        $charge = $total - $school;

        if ($school <= 0) {
            return null;
        }

        if ($charge < 0) {
            // A bill larger than the money received: the shortfall is the school's,
            // not ours — post what actually arrived and leave the difference due.
            $this->logger->warning('Credit {reference} is smaller than the payment it settled; posting what arrived.', [
                'reference' => $credit->getProviderReference(),
            ]);

            $school = $total;
            $charge = 0;
        }

        $lines = [LedgerLine::debit(self::UNALLOCATED, $total), LedgerLine::credit(self::SCHOOL_PAYABLE, $school)];

        if ($charge > 0) {
            $lines[] = LedgerLine::credit(self::HANDLING_REVENUE, $charge);
        }

        return $this->post(
            LedgerEntry::SOURCE_CREDIT_APPLIED,
            $id,
            $credit->getProviderReference(),
            'Matched to invoice ' . ($credit->getInvoice()?->getInvoiceNumber() ?? ''),
            $lines,
            'system',
        );
    }

    /**
     * We paid a school what we were holding for it.
     *
     *   Dr 2000 School payable      ₦X
     *      Cr 1000 Cash at Paystack             ₦X
     *
     * Refuses to post more than the payable balance — the one rule that makes
     * "we paid a school money that came from another school" impossible.
     */
    public function payout(int $payoutId, int $amountMinor, string $reference, string $memo): ?LedgerEntry
    {
        if ($amountMinor <= 0) {
            return null;
        }

        $available = -$this->balanceMinor(self::SCHOOL_PAYABLE);

        if ($amountMinor > $available) {
            $this->logger->error('Payout {reference} of {amount} exceeds the payable balance {available}.', [
                'reference' => $reference,
                'amount' => $amountMinor,
                'available' => $available,
            ]);

            return null;
        }

        return $this->post(
            LedgerEntry::SOURCE_PAYOUT,
            $payoutId,
            $reference,
            $memo,
            [LedgerLine::debit(self::SCHOOL_PAYABLE, $amountMinor), LedgerLine::credit(self::CASH_AT_PAYSTACK, $amountMinor)],
            'system:payout',
        );
    }

    // ── Balances ─────────────────────────────────────────────────────────

    /**
     * Debits minus credits, in kobo. Assets read positive, liabilities negative —
     * use the helpers below rather than remembering which is which.
     */
    public function balanceMinor(string $accountCode): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('SUM(CASE WHEN l.direction = :debit THEN l.amountMinor ELSE -l.amountMinor END)')
            ->from(LedgerLine::class, 'l')
            ->andWhere('l.accountCode = :code')
            ->setParameter('debit', LedgerLine::DEBIT)
            ->setParameter('code', $accountCode);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Debit and credit totals for one account, in kobo.
     *
     * Balances alone cannot answer "how much was ever collected" once money has
     * been paid out — the balance is back to zero. The operator's view needs the
     * movements, not just where the account stands.
     *
     * @return array{debit: int, credit: int}
     */
    public function movements(string $accountCode): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('l.direction AS dir', 'SUM(l.amountMinor) AS total')
            ->from(LedgerLine::class, 'l')
            ->andWhere('l.accountCode = :code')
            ->setParameter('code', $accountCode)
            ->groupBy('l.direction')
            ->getQuery()
            ->getArrayResult();

        $out = ['debit' => 0, 'credit' => 0];

        foreach ($rows as $row) {
            if (($row['dir'] ?? '') === LedgerLine::DEBIT) {
                $out['debit'] = (int) $row['total'];
            } elseif (($row['dir'] ?? '') === LedgerLine::CREDIT) {
                $out['credit'] = (int) $row['total'];
            }
        }

        return $out;
    }

    /** Money sitting in our Paystack balance, in naira. */
    public function cashAtPaystack(): float
    {
        return $this->balanceMinor(self::CASH_AT_PAYSTACK) / 100;
    }

    /** Received but not yet matched to a bill — the unassigned inbox, as one number. */
    public function unallocated(): float
    {
        return -$this->balanceMinor(self::UNALLOCATED) / 100;
    }

    /** What we are holding for this school right now, in naira. */
    public function schoolPayable(): float
    {
        return -$this->balanceMinor(self::SCHOOL_PAYABLE) / 100;
    }

    /** Edus's handling-charge revenue, in naira. */
    public function handlingRevenue(): float
    {
        return -$this->balanceMinor(self::HANDLING_REVENUE) / 100;
    }

    public function gatewayCost(): float
    {
        return $this->balanceMinor(self::GATEWAY_COST) / 100;
    }

    // ── The single door ──────────────────────────────────────────────────

    /**
     * @param list<LedgerLine> $lines
     */
    public function post(
        string $sourceType,
        int $sourceId,
        ?string $sourceRef,
        string $memo,
        array $lines,
        string $createdBy,
        ?\DateTimeImmutable $occurredAt = null,
    ): ?LedgerEntry {
        if ($lines === []) {
            return null;
        }

        $net = 0;
        foreach ($lines as $line) {
            $net += $line->signedMinor();
        }

        if ($net !== 0) {
            // Never persist an unbalanced entry: it would leave the books wrong in
            // a way nobody notices until a school asks where its money went.
            throw new \LogicException(sprintf(
                'Unbalanced ledger entry (%s #%d): debits and credits differ by %d kobo.',
                $sourceType,
                $sourceId,
                $net
            ));
        }

        $already = $this->em->getRepository(LedgerEntry::class)->findOneBy([
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
        ]);

        if ($already !== null) {
            return $already;
        }

        $entry = new LedgerEntry();
        $entry
            ->setSourceType($sourceType)
            ->setSourceId($sourceId)
            ->setSourceRef($sourceRef)
            ->setMemo($memo)
            ->setCreatedBy($createdBy);

        if ($occurredAt !== null) {
            $entry->setOccurredAt($occurredAt);
        }

        foreach ($lines as $line) {
            $entry->addLine($line);
        }

        $this->em->persist($entry);

        return $entry;
    }

    private function toMinor(?string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
