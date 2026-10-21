<?php

namespace App\Tests\Service\Payment;

use App\Entity\Tenant\School;
use App\Service\Payment\PaymentChargePolicy;
use App\Service\Payment\ProcessingFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The owner's rule: the parent-paid online charge never exceeds ₦500, for any
 * amount, and a school can switch it off entirely.
 */
final class PaymentChargePolicyTest extends TestCase
{
    private PaymentChargePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PaymentChargePolicy(new ProcessingFeeCalculator());
    }

    public function testTheChargeIsNeverMoreThanFiveHundred(): void
    {
        $school = (new School())->setName('Test');

        foreach ([1_000, 5_000, 25_000, 52_500, 250_000, 1_000_000] as $amount) {
            $fee = $this->policy->feeFor($school, (float) $amount);

            self::assertLessThanOrEqual(500.0, $fee, sprintf('Charge on ₦%s must not exceed ₦500', number_format($amount)));
        }
    }

    public function testTheChargeHitsTheCeilingAndStops(): void
    {
        $school = (new School())->setName('Test');

        // 1% of ₦5,000 = ₦50 → +7.5% VAT + ₦200 handling.
        self::assertSame(253.75, $this->policy->feeFor($school, 5_000.0));

        // Past ₦30,000 the gateway cut is already at its ₦300 cap, so the
        // parent's charge is pinned at ₦500 and a bigger fee costs no more.
        self::assertSame(500.0, $this->policy->feeFor($school, 30_000.0));
        self::assertSame(500.0, $this->policy->feeFor($school, 300_000.0));
    }

    public function testTheSchoolCanAbsorbTheCharge(): void
    {
        $school = (new School())->setName('Test')->setOnlineProcessingFeeEnabled(false);

        self::assertFalse($this->policy->isEnabled($school));
        self::assertSame(0.0, $this->policy->feeFor($school, 5_000.0));
        self::assertSame(5_000.0, $this->policy->totalFor($school, 5_000.0));
    }

    public function testAnUnknownSchoolKeepsTheCharge(): void
    {
        self::assertTrue($this->policy->isEnabled(null));
        self::assertSame(253.75, $this->policy->feeFor(null, 5_000.0));
    }
}
