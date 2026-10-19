<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\TermRegistration;

/**
 * Turns a plan + a declared roll into the money a school will actually see.
 *
 * All amounts are whole naira. Rates are naira too, so integer maths is exact —
 * no float rounding at the boundary (₦750 × 1,500 pupils must be 1,125,000, not
 * 1,124,999.97).
 *
 * The result is a SNAPSHOT: the caller copies it onto the TermRegistration and
 * never recomputes it afterwards.
 */
final class TermQuoteService
{
    /**
     * @return array{
     *     track: string,
     *     cycle: string,
     *     students: int,
     *     unitPrice: int,
     *     unitLabel: string,
     *     rawSubtotal: int,
     *     minimum: int,
     *     minimumApplied: bool,
     *     subtotal: int,
     *     discount: int,
     *     total: int,
     *     savingsPercent: int,
     *     termlyYearCost: int
     * }
     */
    public function standardQuote(Plan $plan, int $students, string $cycle): array
    {
        if ($students < 1) {
            throw new \InvalidArgumentException('A registration needs at least one pupil.');
        }

        if (!in_array($cycle, [TermRegistration::CYCLE_TERMLY, TermRegistration::CYCLE_ANNUAL], true)) {
            throw new \InvalidArgumentException('Unknown billing cycle: ' . $cycle);
        }

        $termRate = (int) $plan->getTermPricePerStudent();
        $annualRate = (int) $plan->getAnnualPricePerStudent();

        if ($termRate < 1 || $annualRate < 1) {
            throw new \LogicException('This plan has no per-pupil rates configured.');
        }

        $minimumTerm = (int) ($plan->getMinimumTermInvoice() ?? 0);
        $minimumAnnual = (int) ($plan->getMinimumAnnualInvoice() ?? 0);

        // What the same roll would cost over a year if billed termly — the
        // baseline the annual discount is measured against.
        $termlyYearCost = max($students * $termRate, $minimumTerm) * 3;

        if ($cycle === TermRegistration::CYCLE_TERMLY) {
            $unitPrice = $termRate;
            $unitLabel = 'per pupil, per term';
            $minimum = $minimumTerm;
            $rawSubtotal = $students * $termRate;
            $subtotal = max($rawSubtotal, $minimum);
            $discount = 0;
        } else {
            $unitPrice = $annualRate * 3;
            $unitLabel = 'per pupil, per year';
            $minimum = $minimumAnnual;
            $rawSubtotal = $students * $annualRate * 3;
            $subtotal = max($rawSubtotal, $minimum);
            $discount = max(0, $termlyYearCost - $subtotal);
        }

        return [
            'track' => TermRegistration::TRACK_STANDARD,
            'cycle' => $cycle,
            'students' => $students,
            'unitPrice' => $unitPrice,
            'unitLabel' => $unitLabel,
            'rawSubtotal' => $rawSubtotal,
            'minimum' => $minimum,
            'minimumApplied' => $subtotal > $rawSubtotal,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $subtotal,
            'savingsPercent' => $termlyYearCost > 0
                ? (int) round($discount / $termlyYearCost * 100)
                : 0,
            'termlyYearCost' => $termlyYearCost,
        ];
    }

    /**
     * Enterprise is never priced by the roll; this is for display only.
     *
     * @return array{
     *     licenceFee: int,
     *     annualFee: int,
     *     implementationFee: int,
     *     yearOne: int,
     *     threeYear: int
     * }
     */
    public function enterpriseSummary(Plan $plan): array
    {
        $licence = (int) $plan->getLicenceFee();
        $annual = (int) $plan->getAnnualFee();
        $implementation = (int) $plan->getImplementationFee();

        return [
            'licenceFee' => $licence,
            'annualFee' => $annual,
            'implementationFee' => $implementation,
            'yearOne' => $licence + $annual + $implementation,
            'threeYear' => $licence + $implementation + ($annual * 3),
        ];
    }

    /**
     * A per-student plan is unusable until both rates and the term minimum exist.
     */
    public function isConfigured(Plan $plan): bool
    {
        return $plan->isPerStudent()
            && (int) $plan->getTermPricePerStudent() > 0
            && (int) $plan->getAnnualPricePerStudent() > 0;
    }
}
