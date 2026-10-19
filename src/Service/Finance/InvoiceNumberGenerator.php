<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Session;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One invoice-number series per school per year: `INV-2026-0001`.
 *
 * Before this existed, five different screens minted five different formats —
 * `INV-6A9699FED8A02` from the entity's constructor, `INV-2026-<hex>` from
 * enrolment and admission, `INV-AUTO-<hex>` from bulk import — so the number a
 * parent read aloud to the bursar did not look like the number the bursar had on
 * screen, and a 13-character hex string wrapped onto two lines on a phone.
 *
 * The series is human-readable and monotonic within a year. It is *not* a
 * document sequence with legal weight: the uniqueness that matters is the
 * `invoice.invoice_number` unique index, which this generator works with rather
 * than around.
 */
final class InvoiceNumberGenerator
{
    /**
     * The high-water mark issued by this process, per year.
     *
     * A bulk import creates hundreds of invoices before anything is flushed, so
     * reading `MAX()` from the database on every call would hand out the same
     * number dozens of times. This counter closes that gap.
     *
     * @var array<int, int>
     */
    private array $issued = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * The next number in the series, e.g. `INV-2026-0042`.
     */
    public function next(?int $year = null): string
    {
        $year ??= (int) (new \DateTimeImmutable())->format('Y');
        $prefix = sprintf('INV-%d-', $year);

        $sequence = max($this->issued[$year] ?? 0, $this->highestInDatabase($year));
        $sequence++;
        $this->issued[$year] = $sequence;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The year to number under.
     *
     * A session is named `2026/2027`, and invoices belong to the year the
     * session *started*, not the calendar year in which somebody happened to
     * click Generate. Falls back to today when the session is missing.
     */
    public function yearFor(?Session $session, ?\DateTimeInterface $fallback = null): int
    {
        $name = $session?->getName();

        if ($name !== null && preg_match('/^\s*(\d{4})/', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return (int) ($fallback ?? new \DateTimeImmutable())->format('Y');
    }

    /**
     * Highest sequence already stored for this year.
     *
     * Numbers from the old formats (`INV-2026-<hex>`) share the prefix but are
     * not sequences, so they are skipped rather than misread as a huge number.
     * The year is matched here as well as in the SQL prefix: trusting the query
     * alone means a caller asking for 2027 could be handed last year's high
     * water mark.
     */
    private function highestInDatabase(int $year): int
    {
        try {
            $numbers = $this->em->getConnection()->fetchFirstColumn(
                'SELECT invoice_number FROM invoice WHERE invoice_number LIKE ?',
                [sprintf('INV-%d-%%', $year)]
            );
        } catch (\Throwable) {
            // No table yet (fresh tenant, first invoice during onboarding).
            return 0;
        }

        $highest = 0;
        foreach ($numbers as $number) {
            if (preg_match('/^INV-' . $year . '-(\d{4,})$/', (string) $number, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }
}
