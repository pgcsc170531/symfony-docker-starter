<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
use Doctrine\ORM\EntityManagerInterface;

class DiscountCalculator
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * The rule currently attached to a pupil, if any.
     */
    public function assignmentFor(Student $student): ?StudentDiscount
    {
        return $this->em->getRepository(StudentDiscount::class)->findOneBy(['student' => $student]);
    }

    /**
     * How the rule should be written on a bill.
     *
     * A parent is told *why* the money is lower, so the wording carries the rule
     * name and what the rule does: "Staff Child Scholarship (10%)", "Sibling
     * Rebate (₦5,000 off)". Captured on the invoice when it is priced.
     */
    public function describe(Student $student): ?string
    {
        $assignment = $this->assignmentFor($student);
        $rule = $assignment?->getDiscountType();

        if (!$rule) {
            return null;
        }

        $how = $rule->getMode() === 'PERCENTAGE'
            ? rtrim(rtrim(number_format((float) $rule->getValue(), 2, '.', ''), '0'), '.') . '%'
            : '₦' . number_format((float) $rule->getValue(), 0) . ' off';

        return sprintf('%s (%s)', $rule->getName(), $how);
    }

    /**
     * The base a discount may be taken from: **tuition only**.
     *
     * A 50% scholarship on the whole bill would quietly pay for uniforms, ID
     * cards and development levies as well — the owner's rule is that a waiver
     * is a tuition waiver, whatever its mode (percentage or fixed amount). Every
     * caller must hand the calculator this figure, never the invoice total.
     *
     * @param iterable<\App\Entity\Tenant\InvoiceItem> $items
     */
    public function tuitionBaseFromItems(iterable $items): float
    {
        $base = 0.0;

        foreach ($items as $item) {
            $feeItem = $item->getFeeItem();

            // A hand-written or imported bill may have no fee-item link; the
            // description is then the only thing that says "this is tuition".
            $isTuition = $feeItem !== null
                ? $feeItem->isTuition()
                : \App\Entity\Tenant\FeeItem::looksLikeTuition($item->getDescription());

            if ($isTuition) {
                $base += (float) $item->getAmount();
            }
        }

        return round($base, 2);
    }

    /**
     * The same figure while the bill is still being built, before any
     * InvoiceItem exists.
     *
     * @param iterable<\App\Entity\Tenant\FeeStructure> $feeStructures
     */
    public function tuitionBaseFromStructures(iterable $feeStructures): float
    {
        $base = 0.0;

        foreach ($feeStructures as $fee) {
            $item = $fee->getFeeItem();
            if ($item === null || $item->isOptional() || !$item->isTuition()) {
                continue;
            }

            $base += (float) $fee->getAmount();
        }

        return round($base, 2);
    }

    /** The discount on a saved invoice, measured against its tuition lines. */
    public function discountFor(Student $student, \App\Entity\Tenant\Invoice $invoice): float
    {
        return $this->calculateDiscount($student, $this->tuitionBaseFromItems($invoice->getItems()));
    }

    /**
     * Returns the monetary value to subtract from the bill.
     *
     * `$tuitionBase` must be the TUITION total, not the invoice total — use
     * {@see tuitionBaseFromItems()} / {@see tuitionBaseFromStructures()} to get
     * it. A fixed rebate larger than the tuition is capped at the tuition.
     */
    public function calculateDiscount(Student $student, float $tuitionBase): float
    {
        // 1. Check if student has an active scholarship
        $assignment = $this->assignmentFor($student);

        // No scholarship? Deduct 0.
        if (!$assignment) {
            return 0.00;
        }

        $rule = $assignment->getDiscountType();

        if (!$rule) {
            return 0.00;
        }

        // No tuition on the bill — nothing for a tuition waiver to reduce.
        if ($tuitionBase <= 0) {
            return 0.00;
        }

        $discountValue = 0.00;

        // 2. Calculate based on Mode
        if ($rule->getMode() === 'PERCENTAGE') {
            // Formula: (Tuition * Percentage) / 100
            // Example: (50,000 * 50) / 100 = 25,000
            $percentage = (float) $rule->getValue();
            $discountValue = ($tuitionBase * $percentage) / 100;
        } else {
            // Formula: Flat Amount, and only ever against the tuition line
            $discountValue = (float) $rule->getValue();
        }

        // 3. Safety Check: the waiver cannot exceed the tuition it applies to
        // (we do not want to owe the family money, and we never discount the
        // uniform, the ID card or a levy).
        if ($discountValue > $tuitionBase) {
            return $tuitionBase;
        }

        return round(max(0.0, $discountValue), 2);
    }
}