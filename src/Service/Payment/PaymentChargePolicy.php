<?php

namespace App\Service\Payment;

use App\Entity\Tenant\School;

/**
 * The switch on top of the charge: does the parent pay it at all?
 *
 * `ProcessingFeeCalculator` owns the maths — 1% capped at ₦300, plus 7.5% VAT on
 * that, plus a ₦200 handling margin, and **never more than ₦500 whatever the
 * amount**. That ceiling is the owner's rule and it is the same for every fee in
 * the system: an application form fee and a term's school fees are charged the
 * same way, because the gateway's own cut stops growing long before ours does.
 *
 * This class is only the per-school decision:
 *
 *   • ON  (default) — the parent pays the fee plus the charge. The school
 *     receives its fee in full.
 *   • OFF — the parent pays exactly the fee. The gateway still takes its cut,
 *     so the school's settlement is short by that amount.
 *
 * Every path that shows a parent a price must ask this class, not the
 * calculator, or the button and the checkout end up disagreeing.
 */
final class PaymentChargePolicy
{
    public function __construct(private readonly ProcessingFeeCalculator $calculator)
    {
    }

    /**
     * A missing school means "we cannot know yet" — keep the historical
     * behaviour rather than quietly making the school pay.
     */
    public function isEnabled(?School $school): bool
    {
        return $school === null || $school->isOnlineProcessingFeeEnabled();
    }

    /** The charge the parent pays on top of `$amount` — 0 when the school absorbs it. */
    public function feeFor(?School $school, float $amount): float
    {
        return $this->isEnabled($school) ? $this->calculator->feeFor($amount) : 0.0;
    }

    /** Exactly what the parent is charged. */
    public function totalFor(?School $school, float $amount): float
    {
        return round($amount + $this->feeFor($school, $amount), 2);
    }
}
