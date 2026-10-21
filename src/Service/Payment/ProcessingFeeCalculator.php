<?php

namespace App\Service\Payment;

/**
 * The gross-up: what a parent must pay so the school still nets the full fee.
 *
 * The school is never out of pocket — the parent covers the gateway charge. This
 * is the ONE place those numbers live, so a change in Paystack's pricing is a
 * change to these constants and nothing else.
 *
 * Verified rates (paystack.com/pricing, Sep 2026):
 *   • bank transfer / dedicated virtual account: 1%, capped ₦300
 *   • local card & USSD: 1.5% + ₦100, capped ₦2,000 (₦100 waived under ₦2,500)
 *   • VAT of 7.5% is charged ON TOP of the Paystack fee
 *
 * The owner's decision: the parent pays it, **capped at ₦500**. That cap is only
 * reachable at the 1% rate, which is why the 1% assumption is a constant and not
 * buried in the maths — if Paystack ever bills hosted checkout at the card rate
 * for large fees, `TRANSFER_CAP_PERCENT` + `TRANSFER_CAP_AMOUNT` are what change.
 */
final class ProcessingFeeCalculator
{
    /** Transfer/DVA pricing: 1% of the transaction… */
    private const TRANSFER_CAP_PERCENT = 0.01;

    /** …capped at ₦300. */
    private const TRANSFER_CAP_AMOUNT = 300.0;

    /** Our handling margin, added once. */
    private const HANDLING_FEE = 200.0;

    /** VAT charged on the Paystack fee itself. */
    private const VAT_RATE = 0.075;

    /** What the parent is never charged more than. */
    public const MAX_FEE = 500.0;

    /** Local card & USSD pricing, used when the SCHOOL pays its own bill. */
    private const CARD_PERCENT = 0.015;
    private const CARD_FLAT = 100.0;
    private const CARD_CAP = 2000.0;

    /**
     * The Paystack charge on a subscription payment, borne by the school.
     *
     * This is not the parent fee: there is no ₦200 handling margin and no ₦500
     * cap, because the school is paying Edus, not a parent paying a school. It is
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

    /**
     * The processing fee the parent pays on top of `$invoiceAmount`.
     */
    public function feeFor(float $invoiceAmount): float
    {
        if ($invoiceAmount <= 0) {
            return 0.0;
        }

        $gatewayFee = min($invoiceAmount * self::TRANSFER_CAP_PERCENT, self::TRANSFER_CAP_AMOUNT);
        $gatewayFeeWithVat = $gatewayFee * (1 + self::VAT_RATE);

        $total = $gatewayFeeWithVat + self::HANDLING_FEE;

        return round(min($total, self::MAX_FEE), 2);
    }

    /**
     * The invoice balance plus the fee — exactly what the parent is charged.
     */
    public function totalFor(float $invoiceAmount): float
    {
        return round($invoiceAmount + $this->feeFor($invoiceAmount), 2);
    }

    /** "₦500" — the ceiling, for copy that must not drift from MAX_FEE. */
    public function maxFeeLabel(): string
    {
        return '₦' . number_format(self::MAX_FEE, 2, '.', '');
    }

    /**
     * What each channel costs the SCHOOL, in words, straight from the constants.
     *
     * A parent chooses the channel on the provider's own page, so this is the only
     * lever we have: tell them before they get there. Card is materially dearer
     * (1.5% + ₦100, capped at ₦2,000 against 1% capped at ₦300 for a transfer), and
     * on a ₦68,600 invoice that is the difference between ₦1,136 and ₦322 — money
     * that comes out of the school's fee, because the parent's charge is capped.
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

        $rows = [
            ['label' => 'School fees', 'value' => '₦' . number_format($invoiceAmount, 2)],
            ['label' => 'Payment processing', 'value' => '₦' . number_format($fee, 2)],
        ];

        if ($fee >= self::MAX_FEE) {
            $rows[] = [
                'label' => 'Note',
                'value' => sprintf('Processing is capped at ₦%s', number_format(self::MAX_FEE, 2)),
            ];
        }

        return $rows;
    }
}
