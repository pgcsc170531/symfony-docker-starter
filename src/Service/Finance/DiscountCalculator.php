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
     * Returns the monetary value to subtract from the bill.
     * e.g., if Bill is 100k and Discount is 50%, returns 50,000.
     */
    public function calculateDiscount(Student $student, float $currentBillTotal): float
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

        $discountValue = 0.00;

        // 2. Calculate based on Mode
        if ($rule->getMode() === 'PERCENTAGE') {
            // Formula: (Total * Percentage) / 100
            // Example: (100,000 * 50) / 100 = 50,000
            $percentage = (float) $rule->getValue();
            $discountValue = ($currentBillTotal * $percentage) / 100;
        } else {
            // Formula: Flat Amount
            // Example: ₦5,000 off
            $discountValue = (float) $rule->getValue();
        }

        // 3. Safety Check: Discount cannot be more than the bill itself
        // (We don't want to owe the student money!)
        if ($discountValue > $currentBillTotal) {
            return $currentBillTotal;
        }

        return $discountValue;
    }
}