<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-prices the bills a pupil already has when a discount rule is assigned or
 * removed.
 *
 * The terminal and the admission engine both consult DiscountCalculator while
 * they *build* an invoice, so a scholarship given after the term was billed used
 * to change nothing at all — the bursar assigned a 10% award and the pupil's
 * bill stayed at the full amount, silently. This service closes that gap for the
 * bills that are still open:
 *
 *   - only ACADEMIC invoices are touched (a store sale is not a fee);
 *   - only invoices that are not fully settled are touched (money already
 *     collected must never be re-opened);
 *   - the gross is taken from the invoice items, so the discount is always a
 *     percentage of the fees actually billed, never of an already discounted
 *     total.
 *
 * Part-paid invoices are re-priced too, but the total is never pushed below what
 * the family has already paid.
 */
class DiscountRepricer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiscountCalculator $calculator,
    ) {
    }

    /**
     * @return list<array{number: string, from: float, to: float, discount: float}>
     *         One row per invoice whose total changed.
     */
    public function repriceOpenInvoices(Student $student): array
    {
        $invoices = $this->em->getRepository(Invoice::class)->findBy([
            'student' => $student,
            'type'    => Invoice::TYPE_ACADEMIC,
        ]);

        $changes = [];

        foreach ($invoices as $invoice) {
            if ($invoice->getStatus() === Invoice::STATUS_PAID) {
                continue;
            }

            $paid = (float) $invoice->getPaidAmount();
            $oldTotal = (float) $invoice->getTotalAmount();
            $gross = $this->gross($invoice);
            $discount = $this->calculator->calculateDiscount($student, $gross);

            // Never bill less than the family has already handed over.
            $newTotal = max($paid, max(0.0, $gross - $discount));

            if (abs($newTotal - $oldTotal) < 0.005
                && abs((float) $invoice->getDiscountApplied() - $discount) < 0.005) {
                continue;
            }

            $invoice->setDiscountApplied(number_format($discount, 2, '.', ''));
            $invoice->setDiscountLabel($discount > 0.005 ? $this->calculator->describe($student) : null);
            $invoice->setTotalAmount(number_format($newTotal, 2, '.', ''));
            $invoice->setStatus($this->statusFor($paid, $newTotal));

            $changes[] = [
                'number'   => (string) $invoice->getInvoiceNumber(),
                'from'     => $oldTotal,
                'to'       => $newTotal,
                'discount' => $discount,
            ];
        }

        if ($changes !== []) {
            $this->em->flush();
        }

        return $changes;
    }

    /**
     * What this pupil's money currently looks like, for the scholarship board.
     *
     * "The rule is assigned" and "the bill was actually reduced" are two
     * different facts, and only the second one pays a family back. The board has
     * to state both, or a bursar cannot tell whether a scholarship took effect.
     *
     * @return array{
     *     invoice: ?string, id: ?int, gross: float, discount: float, net: float,
     *     stored: float, total: float, applied: bool, inSync: bool, status: ?string
     * }
     */
    public function summary(Student $student): array
    {
        $empty = [
            'invoice' => null, 'id' => null, 'gross' => 0.0, 'discount' => 0.0,
            'net' => 0.0, 'stored' => 0.0, 'total' => 0.0, 'applied' => false,
            'inSync' => false, 'status' => null,
        ];

        // The most recent academic bill that still has money on it.
        $invoices = $this->em->getRepository(Invoice::class)->findBy(
            ['student' => $student, 'type' => Invoice::TYPE_ACADEMIC],
            ['id' => 'DESC'],
        );

        $open = null;
        foreach ($invoices as $invoice) {
            if ($invoice->getStatus() !== Invoice::STATUS_PAID) {
                $open = $invoice;
                break;
            }
        }

        if (!$open) {
            return $empty;
        }

        $gross = $this->gross($open);
        $discount = $this->calculator->calculateDiscount($student, $gross);
        $net = max((float) $open->getPaidAmount(), max(0.0, $gross - $discount));
        $stored = (float) $open->getDiscountApplied();

        return [
            'invoice' => $open->getInvoiceNumber(),
            'id'      => $open->getId(),
            'gross'   => $gross,
            'discount' => $discount,
            'net'     => $net,
            'stored'  => $stored,
            'total'   => (float) $open->getTotalAmount(),
            'applied' => $stored > 0.005,
            'inSync'  => abs((float) $open->getTotalAmount() - $net) < 0.005,
            'status'  => $open->getStatus(),
        ];
    }

    /**
     * The fees the pupil was billed, before any discount.
     *
     * Items are the source of truth. A hand-written invoice has no items, so the
     * gross is reconstructed from the total plus whatever was already taken off.
     */
    private function gross(Invoice $invoice): float
    {
        $sum = 0.0;
        /** @var InvoiceItem $item */
        foreach ($invoice->getItems() as $item) {
            $sum += (float) $item->getAmount();
        }

        if ($sum > 0) {
            return $sum;
        }

        return (float) $invoice->getTotalAmount() + (float) $invoice->getDiscountApplied();
    }

    private function statusFor(float $paid, float $total): string
    {
        if ($paid <= 0.0) {
            return Invoice::STATUS_UNPAID;
        }

        if ($paid + 0.005 >= $total) {
            return Invoice::STATUS_PAID;
        }

        return Invoice::STATUS_PARTIAL;
    }
}
