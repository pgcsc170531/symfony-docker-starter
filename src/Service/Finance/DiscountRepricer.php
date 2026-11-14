<?php

namespace App\Service\Finance;

use App\Entity\Tenant\DiscountType;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
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
 *     total;
 *   - the base is tuition, except a 100% full scholarship, which covers every
 *     fee item.
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
            $paid = (float) $invoice->getPaidAmount();

            // Skip a bill that real money has settled. A 100% scholarship also
            // makes the status PAID but leaves paid_amount at zero; removing or
            // changing that rule must be able to re-open and re-price it.
            if ($invoice->getStatus() === Invoice::STATUS_PAID && $paid > 0.005) {
                continue;
            }

            $oldTotal = (float) $invoice->getTotalAmount();
            $gross = $this->gross($invoice);
            $discount = $this->calculator->calculateDiscount(
                $student,
                $this->calculator->tuitionBaseFromItems($invoice->getItems()),
                $gross,
            );
            $label = $discount > 0.005 ? $this->calculator->describe($student) : null;
            $labelChanged = (string) $invoice->getDiscountLabel() !== (string) $label;

            // Never bill less than the family has already handed over.
            $newTotal = max($paid, max(0.0, $gross - $discount));

            if (abs($newTotal - $oldTotal) < 0.005
                && abs((float) $invoice->getDiscountApplied() - $discount) < 0.005
                && !$labelChanged) {
                continue;
            }

            $invoice->setDiscountApplied(number_format($discount, 2, '.', ''));
            $invoice->setDiscountLabel($label);
            $invoice->setTotalAmount(number_format($newTotal, 2, '.', ''));
            $invoice->setStatus(Invoice::statusFor($paid, $newTotal));

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
     * Re-price every open bill that uses a rule, after the rule itself changes.
     *
     * Editing a rule's name, mode or value must not leave families on the old
     * figure. Only open/zero-paid bills are touched; a bill with real money
     * against it stays history.
     *
     * @return array{students: int, invoices: int}
     */
    public function repriceRule(DiscountType $rule): array
    {
        $assignments = $this->em->getRepository(StudentDiscount::class)->findBy([
            'discountType' => $rule,
        ]);

        $students = 0;
        $invoices = 0;

        foreach ($assignments as $assignment) {
            $student = $assignment->getStudent();

            if ($student === null) {
                continue;
            }

            ++$students;
            $invoices += count($this->repriceOpenInvoices($student));
        }

        return ['students' => $students, 'invoices' => $invoices];
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
            $paid = (float) $invoice->getPaidAmount();

            // A zero-paid PAID bill is a fully discounted bill, not a settled
            // one; the scholarship board still needs to show its effect.
            if ($invoice->getStatus() !== Invoice::STATUS_PAID || $paid <= 0.005) {
                $open = $invoice;
                break;
            }
        }

        if (!$open) {
            return $empty;
        }

        $gross = $this->gross($open);
        $discount = $this->calculator->calculateDiscount(
            $student,
            $this->calculator->tuitionBaseFromItems($open->getItems()),
            $gross,
        );
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
        return Invoice::statusFor($paid, $total);
    }
}
