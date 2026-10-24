<?php

namespace App\Service\Payment;

use App\Entity\Tenant\School;

/**
 * The switch on top of the charge: does the parent pay it at all?
 *
 * `ProcessingFeeCalculator` owns the maths — two schedules, deliberately
 * different (see its docblock, and VIRTUAL-ACCOUNTS-DESIGN.md §8/§9):
 *
 *   • invoice payments — a flat **₦750** transfer charge on every payment,
 *     part or full (it funds Paystack's incoming cut *and* the payout to the
 *     school the next morning);
 *   • the online application fee — Paystack's card cost, **then + ₦350**.
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

    /** The charge on an INVOICE payment — 0 when the school absorbs it. */
    public function feeFor(?School $school, float $amount): float
    {
        return $this->isEnabled($school) ? $this->calculator->feeFor($amount) : 0.0;
    }

    /** Exactly what the parent transfers for an invoice. */
    public function totalFor(?School $school, float $amount): float
    {
        return round($amount + $this->feeFor($school, $amount), 2);
    }

    /** The charge on the online APPLICATION FEE, paid before the form. */
    public function applicationFeeFor(?School $school, float $formFee): float
    {
        return $this->isEnabled($school) ? $this->calculator->applicationFeeFor($formFee) : 0.0;
    }

    /** Exactly what the parent pays at checkout for an application. */
    public function applicationTotalFor(?School $school, float $formFee): float
    {
        return round($formFee + $this->applicationFeeFor($school, $formFee), 2);
    }
}
