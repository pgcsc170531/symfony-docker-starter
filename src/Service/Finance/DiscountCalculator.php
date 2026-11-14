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

        if ($rule->getMode() === 'PERCENTAGE') {
            $percentage = (float) $rule->getValue();
            $how = rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.') . '%';
        } else {
            $how = '₦' . number_format((float) $rule->getValue(), 0) . ' off';
        }

        // The office decides the scope — on the rule, or per pupil on the
        // Scholarship Board. Say it on the bill so the parent can see why a
        // levy or uniform was (or was not) reduced.
        if ($assignment->coversAllFeeItems()) {
            $how .= ' — all fees';
        }

        return sprintf('%s (%s)', $rule->getName(), $how);
    }

    /**
     * The base a discount may be taken from: **tuition only**.
     *
     * A 50% scholarship on the whole bill would quietly pay for uniforms, ID
     * cards and development levies as well — the owner's rule is that a waiver
     * is a tuition waiver, whatever its mode (percentage or fixed amount),
     * unless the office explicitly ticks **Apply to grand total**.
     *
     * Callers pass this tuition figure *and* the gross bill, so the calculator
     * can choose the right base without guessing.
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
        return $this->calculateDiscount(
            $student,
            $this->tuitionBaseFromItems($invoice->getItems()),
            $this->grossFromItems($invoice->getItems()),
        );
    }

    /**
     * The whole bill before any discount: every billed item, not just tuition.
     *
     * Only the 100% full-scholarship rule uses this base. It exists so a family
     * on a full scholarship is not left owing the uniform, books or a levy.
     *
     * @param iterable<\App\Entity\Tenant\InvoiceItem> $items
     */
    public function grossFromItems(iterable $items): float
    {
        $gross = 0.0;

        foreach ($items as $item) {
            $gross += (float) $item->getAmount();
        }

        return round($gross, 2);
    }

    /**
     * Returns the monetary value to subtract from the bill.
     *
     * A percentage or fixed rule is a TUITION waiver by default: `$tuitionBase`
     * is the limit. When the rule is marked **Apply to grand total**, the
     * whole bill — `$grossTotal` — is the base instead, so a full scholarship
     * does not leave the family owing the uniform or a levy.
     *
     * @param float $tuitionBase tuition lines only
     * @param float $grossTotal  every billed line before the discount
     */
    public function calculateDiscount(Student $student, float $tuitionBase, float $grossTotal): float
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

        $percentage = (float) $rule->getValue();

        // Only an explicitly scoped award may reach non-tuition lines. The
        // scope is the pupil's own choice when one was made on the Scholarship
        // Board, and the rule's setting otherwise.
        $base = $assignment->coversAllFeeItems() ? $grossTotal : $tuitionBase;

        if ($base <= 0) {
            return 0.00;
        }

        $discountValue = 0.00;

        // 2. Calculate based on Mode
        if ($rule->getMode() === 'PERCENTAGE') {
            // Formula: (Base * Percentage) / 100
            // Example: (40,000 tuition * 50) / 100 = 20,000
            $discountValue = ($base * $percentage) / 100;
        } else {
            // Formula: Flat Amount, and only ever against the tuition line
            $discountValue = (float) $rule->getValue();
        }

        // 3. Safety Check: the waiver cannot exceed the base it applies to
        // (we do not want to owe the family money).
        if ($discountValue > $base) {
            return $base;
        }

        return round(max(0.0, $discountValue), 2);
    }
}