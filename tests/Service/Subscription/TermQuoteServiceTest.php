<?php

namespace App\Tests\Service\Subscription;

use App\Entity\Landlord\Plan;
use App\Entity\Landlord\TermRegistration;
use App\Service\Subscription\TermQuoteService;
use PHPUnit\Framework\TestCase;

/**
 * The quote is the number a school is asked to pay. These tests pin it to the
 * agreed rates in docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md §2C.
 */
final class TermQuoteServiceTest extends TestCase
{
    private TermQuoteService $quotes;

    protected function setUp(): void
    {
        $this->quotes = new TermQuoteService();
    }

    private function standardPlan(): Plan
    {
        return (new Plan())
            ->setName('Standard')
            ->setPricingModel('per_student')
            ->setTermPricePerStudent('750')
            ->setAnnualPricePerStudent('500')
            ->setMinimumTermInvoice('50000')
            ->setMinimumAnnualInvoice('100000');
    }

    public function test_termly_quote_is_rate_times_roll(): void
    {
        $quote = $this->quotes->standardQuote($this->standardPlan(), 500, TermRegistration::CYCLE_TERMLY);

        self::assertSame(750, $quote['unitPrice']);
        self::assertSame(375_000, $quote['subtotal']);
        self::assertSame(375_000, $quote['total']);
        self::assertSame(0, $quote['discount']);
        self::assertFalse($quote['minimumApplied']);
    }

    public function test_termly_minimum_protects_a_tiny_school(): void
    {
        $quote = $this->quotes->standardQuote($this->standardPlan(), 10, TermRegistration::CYCLE_TERMLY);

        self::assertSame(7_500, $quote['rawSubtotal']);
        self::assertSame(50_000, $quote['total']);
        self::assertTrue($quote['minimumApplied']);
    }

    public function test_annual_prepay_is_a_third_off_three_termly_invoices(): void
    {
        $quote = $this->quotes->standardQuote($this->standardPlan(), 500, TermRegistration::CYCLE_ANNUAL);

        self::assertSame(1_500, $quote['unitPrice']);          // per pupil, per year
        self::assertSame(750_000, $quote['subtotal']);
        self::assertSame(1_125_000, $quote['termlyYearCost']); // 3 × 375,000
        self::assertSame(375_000, $quote['discount']);
        self::assertSame(33, $quote['savingsPercent']);
    }

    public function test_annual_minimum_applies_before_the_discount_is_measured(): void
    {
        $quote = $this->quotes->standardQuote($this->standardPlan(), 10, TermRegistration::CYCLE_ANNUAL);

        self::assertSame(100_000, $quote['total']);
        self::assertSame(150_000, $quote['termlyYearCost']); // 50,000 minimum × 3
        self::assertSame(50_000, $quote['discount']);
    }

    public function test_enterprise_totals(): void
    {
        $plan = (new Plan())
            ->setName('Enterprise')
            ->setPricingModel('licence')
            ->setLicenceFee('1200000')
            ->setAnnualFee('550000')
            ->setImplementationFee('300000');

        $summary = $this->quotes->enterpriseSummary($plan);

        self::assertSame(1_200_000, $summary['licenceFee']);
        self::assertSame(550_000, $summary['annualFee']);
        self::assertSame(300_000, $summary['implementationFee']);
        self::assertSame(2_050_000, $summary['yearOne']);
        self::assertSame(3_150_000, $summary['threeYear']);
    }

    public function test_a_roll_of_zero_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->quotes->standardQuote($this->standardPlan(), 0, TermRegistration::CYCLE_TERMLY);
    }

    public function test_a_plan_without_rates_cannot_quote(): void
    {
        $this->expectException(\LogicException::class);

        $this->quotes->standardQuote((new Plan())->setPricingModel('per_student'), 50, TermRegistration::CYCLE_TERMLY);
    }

    public function test_is_configured_requires_both_rates(): void
    {
        self::assertTrue($this->quotes->isConfigured($this->standardPlan()));
        self::assertFalse($this->quotes->isConfigured((new Plan())->setPricingModel('per_student')));
        self::assertFalse($this->quotes->isConfigured((new Plan())->setPricingModel('flat')->setPrice('50000')));
    }
}
