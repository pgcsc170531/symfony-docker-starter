<?php

namespace App\Tests\Service\Payment;

use App\Service\Payment\ProcessingFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Two different gross-ups, deliberately kept apart:
 *  • the PARENT fee (₦200 handling, capped at ₦500) on school fees, and
 *  • the SCHOOL's gateway charge (1.5% + ₦100, capped at ₦2,000, plus VAT) when
 *    a school pays its Edus subscription by card.
 */
final class ProcessingFeeCalculatorTest extends TestCase
{
    private ProcessingFeeCalculator $fees;

    protected function setUp(): void
    {
        $this->fees = new ProcessingFeeCalculator();
    }

    public function test_parent_fee_is_capped_at_five_hundred(): void
    {
        self::assertSame(253.75, $this->fees->feeFor(5_000));
        self::assertSame(500.0, $this->fees->feeFor(30_000));
        self::assertSame(500.0, $this->fees->feeFor(250_000));
        self::assertSame(0.0, $this->fees->feeFor(0));
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
