<?php

namespace App\Service\Payment;

/**
 * The two charges a parent can be asked to pay. This is the ONE place the
 * numbers live.
 *
 * 1. INVOICE PAYMENTS (term fees, levies, balances) — a **flat ₦750 charge on
 *    every payment, part or full**. It funds both legs of the collection:
 *      • Paystack's cut on the incoming transfer — 1% capped ₦300 + 7.5% VAT
 *        (₦322.50 at the cap, less below it);
 *      • the payout that pays the school the next morning — ₦10–₦50 by amount
 *        band, + VAT.
 *    So the worst case is ₦750 − ₦322.50 − ₦53.75 ≈ **₦373.75 per payment**, and
 *    more than that in practice because payouts are batched per school rather
 *    than per parent. The transfer rail settles to Edus and pays the school out
 *    (docs/payments/ONLINE-PAYMENT-LEDGER.md §6–§7); the older ₦650 left nothing
 *    for that second leg. Owner's decision, 24 Oct 2026 — final.
 *
 * 2. THE ONLINE APPLICATION FEE (paid before the form, standard checkout) —
 *    unchanged: Paystack's card schedule (1.5% + ₦100, capped ₦2,000; ₦100
 *    waived under ₦2,500) + 7.5% VAT, then **₦350 added after it**, so Edus nets
 *    exactly ₦350. No payout is involved — the form fee is Edus's own money. §9.
 *
 * Both are grossed up: Paystack charges its percentage on the whole transfer,
 * including our charge, so computing on the bare fee would quietly donate a few
 * naira of margin on every payment.
 *
 * ⚠️ The ₦650 covers a TRANSFER (1% capped ₦300). If an invoice is ever paid by
 * **card** on hosted checkout, Paystack takes up to 1.5% + ₦100 (₦913.75 on
 * ₦50,000) — more than the ₦650 — and Edus absorbs the difference. Invoice
 * payments are therefore transfer-only once the dedicated account ships; the
 * school must not enable card checkout for them.
 *
 * Verified rates (paystack.com/pricing, Sep 2026; DVA docs 23 Oct 2026):
 *   • bank transfer / dedicated virtual account: 1%, capped ₦300
 *   • local card & USSD: 1.5% + ₦100, capped ₦2,000 (₦100 waived under ₦2,500)
 *   • VAT of 7.5% is charged ON TOP of the Paystack fee
 */
final class ProcessingFeeCalculator
{
    /** Flat charge on every invoice payment — part or full (owner, 24 Oct 2026). */
    public const TRANSFER_CHARGE = 750.0;

    /** Our net on an application fee, added AFTER Paystack's own charge. */
    public const APPLICATION_MARGIN = 350.0;

    /** Transfer / DVA pricing: 1% of the transaction… */
    private const TRANSFER_CAP_PERCENT = 0.01;

    /** …capped at ₦300. */
    private const TRANSFER_CAP_AMOUNT = 300.0;

    /** Local card & USSD pricing, used by the application fee and school bills. */
    private const CARD_PERCENT = 0.015;
    private const CARD_FLAT = 100.0;
    private const CARD_CAP = 2000.0;

    /** Paystack waives the ₦100 card fee below this transaction size. */
    private const CARD_FLAT_WAIVER_UNDER = 2500.0;

    /** VAT charged on the Paystack fee itself. */
    private const VAT_RATE = 0.075;

    /**
     * The Paystack charge on a subscription payment, borne by the school.
     *
     * This is not a parent charge: there is no transfer charge and no margin,
     * because the school is paying Edus, not a parent paying a school. It is
     * simply what Paystack will take (1.5% + ₦100, capped at ₦2,000) plus 7.5%
     * VAT on that charge, so Edus nets the invoice exactly.
     */
    public function schoolGatewayFeeFor(float $invoiceAmount): float
    {
        if ($invoiceAmount <= 0) {
            return 0.0;
        }

        $gatewayFee = min($invoiceAmount * self::CARD_PERCENT + self::CARD_FLAT, self::CARD_CAP);

        return round($gatewayFee * (1 + self::VAT_RATE), 2);
    }

    /** What the school's card is actually charged: invoice + gateway charge. */
    public function schoolTotalFor(float $invoiceAmount): float
    {
        return round($invoiceAmount + $this->schoolGatewayFeeFor($invoiceAmount), 2);
    }

    // ── 1. Invoice payments (term fees, levies, balances) ────────────────

    /**
     * The transfer charge on ONE invoice payment — full or part-payment alike.
     *
     * Flat ₦750 on every payment, not once per invoice: the simpler rule, and it
     * has to cover both Paystack's incoming cut and the payout to the school the
     * following morning.
     */
    public function feeFor(float $invoiceAmount): float
    {
        return $invoiceAmount > 0 ? self::TRANSFER_CHARGE : 0.0;
    }

    /**
     * The invoice balance plus the charge — exactly what the parent transfers.
     */
    public function totalFor(float $invoiceAmount): float
    {
        return round($invoiceAmount + $this->feeFor($invoiceAmount), 2);
    }

    /** What Paystack keeps, VAT included, on a transfer of `$gross`. */
    public function transferCostFor(float $gross): float
    {
        if ($gross <= 0) {
            return 0.0;
        }

        return round(min($gross * self::TRANSFER_CAP_PERCENT, self::TRANSFER_CAP_AMOUNT) * (1 + self::VAT_RATE), 2);
    }

    // ── 2. The online application fee (paid before the form) ─────────────

    /**
     * Paystack's card charge, VAT included, on a checkout of `$gross`.
     *
     * The ₦100 is waived below ₦2,500 — mirroring Paystack rather than charging
     * for a fee they do not take (decision D11).
     */
    public function cardCostFor(float $gross): float
    {
        if ($gross <= 0) {
            return 0.0;
        }

        $flat = $gross < self::CARD_FLAT_WAIVER_UNDER ? 0.0 : self::CARD_FLAT;
        $fee = min($gross * self::CARD_PERCENT + $flat, self::CARD_CAP);

        return round($fee * (1 + self::VAT_RATE), 2);
    }

    /**
     * The charge on an application fee: Paystack's cost, THEN our ₦350.
     *
     * The cost depends on the total, and the total includes the charge, so it is
     * solved as a fixed point — two or three passes converge to the kobo.
     */
    public function applicationFeeFor(float $formFee): float
    {
        if ($formFee <= 0) {
            return 0.0;
        }

        $charge = self::APPLICATION_MARGIN;

        for ($pass = 0; $pass < 10; $pass++) {
            $next = round($this->cardCostFor($formFee + $charge) + self::APPLICATION_MARGIN, 2);

            if (abs($next - $charge) < 0.005) {
                return $next;
            }

            $charge = $next;
        }

        return round($charge, 2);
    }

    /** The form fee plus its charge — exactly what the parent pays at checkout. */
    public function applicationTotalFor(float $formFee): float
    {
        return round($formFee + $this->applicationFeeFor($formFee), 2);
    }

    /** "₦650.00" — the flat charge, for copy that must not drift from the constant. */
    public function maxFeeLabel(): string
    {
        return '₦' . number_format(self::TRANSFER_CHARGE, 2, '.', '');
    }

    /**
     * What each channel costs EDUS, in words, straight from the constants.
     *
     * A parent chooses the channel on the provider's own page, so this is the
     * only lever we have: tell them before they get there. Card is materially
     * dearer (1.5% + ₦100, capped ₦2,000, against 1% capped ₦300 for a transfer)
     * — which is why invoice payments are transfer-only: a ₦650 charge cannot
     * absorb a card fee of ₦913.75 on a ₦50,000 bill.
     *
     * @return array{card: string, transfer: string}
     */
    public function channelRates(): array
    {
        return [
            'card' => sprintf(
                '%s%% + ₦%s (up to ₦%s)',
                rtrim(rtrim(number_format(self::CARD_PERCENT * 100, 1), '0'), '.'),
                number_format(self::CARD_FLAT, 0),
                number_format(self::CARD_CAP, 0)
            ),
            'transfer' => sprintf(
                '%s%% (capped at ₦%s)',
                rtrim(rtrim(number_format(self::TRANSFER_CAP_PERCENT * 100, 1), '0'), '.'),
                number_format(self::TRANSFER_CAP_AMOUNT, 0)
            ),
        ];
    }

    /**
     * Human-readable explanation, shown on the pay screen.
     *
     * @return array{label: string, value: string}[]
     */
    public function breakdownFor(float $invoiceAmount): array
    {
        $fee = $this->feeFor($invoiceAmount);

        return [
            ['label' => 'School fees', 'value' => '₦' . number_format($invoiceAmount, 2)],
            ['label' => 'Transfer charge', 'value' => '₦' . number_format($fee, 2)],
            ['label' => 'Total to transfer', 'value' => '₦' . number_format($invoiceAmount + $fee, 2)],
        ];
    }
}
