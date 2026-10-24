<?php

namespace App\Tests\Service\Payment;

use App\Service\Payment\ProcessingFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Three schedules, deliberately kept apart (VIRTUAL-ACCOUNTS-DESIGN.md §8/§9):
 *  • invoice payments — a flat ₦750 transfer charge, part or full, which has to
 *    cover Paystack's incoming cut AND the payout to the school next morning;
 *  • the online application fee — Paystack's card cost, then + ₦350;
 *  • the school's own subscription bill — card cost, no margin at all.
 */
final class ProcessingFeeCalculatorTest extends TestCase
{
    private ProcessingFeeCalculator $fees;

    protected function setUp(): void
    {
        $this->fees = new ProcessingFeeCalculator();
    }

    public function test_every_invoice_payment_carries_the_same_flat_charge(): void
    {
        // Part-payment or full settlement — the charge does not vary.
        self::assertSame(750.0, $this->fees->feeFor(5_000));
        self::assertSame(750.0, $this->fees->feeFor(20_000));
        self::assertSame(750.0, $this->fees->feeFor(250_000));
        self::assertSame(0.0, $this->fees->feeFor(0));
    }

    public function test_total_is_the_balance_plus_the_charge(): void
    {
        self::assertSame(5_750.0, $this->fees->totalFor(5_000));
        self::assertSame(0.0, $this->fees->totalFor(0));
    }

    public function test_the_platform_never_loses_on_a_transfer(): void
    {
        // Paystack's worst case is its ₦300 cap plus VAT.
        self::assertSame(322.5, $this->fees->transferCostFor(50_750));

        foreach ([5_000, 50_000, 1_000_000] as $amount) {
            self::assertGreaterThan(
                $this->fees->transferCostFor($this->fees->totalFor($amount)),
                ProcessingFeeCalculator::TRANSFER_CHARGE,
                'The ₦750 must always exceed Paystack\'s cut'
            );
        }
    }

    public function test_application_fee_mirrors_the_card_waiver_below_two_thousand_five_hundred(): void
    {
        // ₦2,000 form: Paystack waives the ₦100, so only 1.5% + VAT + ₦350.
        self::assertSame(388.51, $this->fees->applicationFeeFor(2_000));
    }

    public function test_application_fee_is_card_cost_then_three_hundred_and_fifty(): void
    {
        // ₦5,000 form: 1.5% + ₦100, + VAT on that, then + ₦350.
        self::assertSame(546.94, $this->fees->applicationFeeFor(5_000));
        self::assertSame(5_546.94, $this->fees->applicationTotalFor(5_000));
        self::assertSame(0.0, $this->fees->applicationFeeFor(0));
    }

    public function test_application_fee_stops_growing_at_the_card_cap(): void
    {
        // ₦2,000 card cap + VAT + ₦350 = ₦2,500, however large the form fee.
        self::assertSame(2_500.0, $this->fees->applicationFeeFor(2_000_000));
    }

    public function test_school_gateway_charge_uses_the_card_rate(): void
    {
        // 1.5% of 50,000 = 750, + ₦100 = 850, + 7.5% VAT = 913.75
        self::assertSame(913.75, $this->fees->schoolGatewayFeeFor(50_000));
    }

    public function test_school_gateway_charge_is_capped_before_vat(): void
    {
        // Paystack's cap is ₦2,000 on the fee; VAT is added on top of that cap.
        self::assertSame(2_150.0, $this->fees->schoolGatewayFeeFor(2_050_000));
        self::assertSame(2_150.0, $this->fees->schoolGatewayFeeFor(10_000_000));
    }

    public function test_school_total_adds_the_charge_to_the_invoice(): void
    {
        self::assertSame(50_913.75, $this->fees->schoolTotalFor(50_000));
        self::assertSame(0.0, $this->fees->schoolTotalFor(0));
    }
}
