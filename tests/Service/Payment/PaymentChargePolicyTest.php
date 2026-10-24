<?php

namespace App\Tests\Service\Payment;

use App\Entity\Tenant\School;
use App\Service\Payment\PaymentChargePolicy;
use App\Service\Payment\ProcessingFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The owner's rule: a flat ₦750 on every invoice payment, the application fee
 * charged as Paystack's cost plus ₦350, and a school can switch either off.
 */
final class PaymentChargePolicyTest extends TestCase
{
    private PaymentChargePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PaymentChargePolicy(new ProcessingFeeCalculator());
    }

    public function testTheInvoiceChargeIsFlatAtSevenHundredAndFifty(): void
    {
        $school = (new School())->setName('Test');

        foreach ([1_000, 5_000, 25_000, 52_500, 250_000, 1_000_000] as $amount) {
            self::assertSame(750.0, $this->policy->feeFor($school, (float) $amount));
        }
    }

    public function testTheApplicationFeeUsesItsOwnSchedule(): void
    {
        $school = (new School())->setName('Test');

        self::assertSame(546.94, $this->policy->applicationFeeFor($school, 5_000.0));
        self::assertSame(5_546.94, $this->policy->applicationTotalFor($school, 5_000.0));

        // It must NOT be the invoice charge.
        self::assertNotSame($this->policy->feeFor($school, 5_000.0), $this->policy->applicationFeeFor($school, 5_000.0));
    }

    public function testTheSchoolCanAbsorbTheCharge(): void
    {
        $school = (new School())->setName('Test')->setOnlineProcessingFeeEnabled(false);

        self::assertFalse($this->policy->isEnabled($school));
        self::assertSame(0.0, $this->policy->feeFor($school, 5_000.0));
        self::assertSame(5_000.0, $this->policy->totalFor($school, 5_000.0));
        self::assertSame(0.0, $this->policy->applicationFeeFor($school, 5_000.0));
        self::assertSame(5_000.0, $this->policy->applicationTotalFor($school, 5_000.0));
    }

    public function testAnUnknownSchoolKeepsTheCharge(): void
    {
        self::assertTrue($this->policy->isEnabled(null));
        self::assertSame(750.0, $this->policy->feeFor(null, 5_000.0));
        self::assertSame(546.94, $this->policy->applicationFeeFor(null, 5_000.0));
    }
}
