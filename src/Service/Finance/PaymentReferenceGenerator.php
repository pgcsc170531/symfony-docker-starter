<?php

namespace App\Service\Finance;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Short, numeric, unique payment references — the code a parent writes on a
 * transfer narration and the bursar types into the verify screen.
 *
 * It exists because the reference used to be `date('ym') . $invoice->getId()`
 * on **both** the cash and the transfer-slip paths. A part payment in cash and a
 * slip for the balance therefore produced the *same* code, and the second one
 * died on `payment.reference_code`'s unique index with a 500 — the exact flow a
 * bursar uses every day.
 *
 * Shape is preserved (7 digits, month-prefixed) so existing printed slips and the
 * verify box stay familiar: October 2026 issues `2610529`, `2610530`, …
 */
final class PaymentReferenceGenerator
{
    /** High-water mark per month prefix, for payments not yet flushed. */
    private array $issued = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function next(): string
    {
        $prefix = date('ym');
        // Floor: the first sane number in this month (e.g. 2610001), so the very
        // first reference is not a single digit.
        $floor = (int) ($prefix . '000') + 1;
        $next = max($floor, $this->highestInDatabase($prefix) + 1, $this->issued[$prefix] ?? 0);

        $this->issued[$prefix] = $next;

        return (string) $next;
    }

    private function highestInDatabase(string $prefix): int
    {
        try {
            $highest = $this->em->getConnection()->fetchOne(
                'SELECT MAX(CAST(reference_code AS UNSIGNED)) FROM payment WHERE reference_code LIKE ?',
                [$prefix . '%']
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) $highest;
    }
}
