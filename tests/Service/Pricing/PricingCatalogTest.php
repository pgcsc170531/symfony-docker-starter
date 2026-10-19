<?php

namespace App\Tests\Service\Pricing;

use App\Service\Pricing\PricingCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Locks the published price list to the commercial decisions in
 * docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md.
 *
 * If one of these fails, either the decision changed or the website is lying —
 * both need a human, not a silent edit.
 */
final class PricingCatalogTest extends TestCase
{
    private PricingCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new PricingCatalog();
    }

    public function test_email_is_mandatory_and_inside_the_enterprise_annual_fee(): void
    {
        self::assertSame(100_000, PricingCatalog::EMAIL_SERVICE_FEE);
        self::assertSame(450_000, PricingCatalog::ENTERPRISE_BASE_ANNUAL_FEE);
        self::assertSame(550_000, PricingCatalog::ENTERPRISE_ANNUAL_FEE);
    }

    public function test_enterprise_totals_match_the_published_offer(): void
    {
        self::assertSame(2_050_000, $this->catalog->enterpriseYearOne());
        self::assertSame(3_150_000, $this->catalog->enterpriseThreeYear());
    }

    public function test_annual_prepay_is_a_third_off_the_termly_rate(): void
    {
        foreach ([100, 400, 800, 1_200] as $pupils) {
            $termly = $this->catalog->termlyYearCost($pupils);
            $annual = $this->catalog->annualPrepay($pupils);

            self::assertSame(intdiv($termly * 2, 3), $annual, "Pupils: {$pupils}");
        }
    }

    public function test_minimum_invoices_protect_small_schools(): void
    {
        self::assertSame(50_000, $this->catalog->termlyInvoice(10));
        self::assertSame(100_000, $this->catalog->annualPrepay(10));
        self::assertSame(0, $this->catalog->termlyInvoice(0));
        self::assertSame(0, $this->catalog->annualPrepay(0));
    }

    public function test_standard_pupil_costs(): void
    {
        self::assertSame(2_250, $this->catalog->termlyPerPupilYear());
        self::assertSame(1_500, $this->catalog->annualPerPupilYear());
    }

    public function test_break_even_pupils_match_the_roll_rule(): void
    {
        self::assertSame(467, $this->catalog->breakEvenPupils(false));
        self::assertSame(700, $this->catalog->breakEvenPupils(true));
    }

    public function test_enterprise_is_cheaper_just_above_each_crossover(): void
    {
        // Termly: 466 pupils is still cheaper on Standard, 467 is not.
        self::assertLessThan($this->catalog->enterpriseThreeYear(), 466 * 6_750);
        self::assertGreaterThan($this->catalog->enterpriseThreeYear(), 467 * 6_750);

        // Annual: at 700 the two are exactly level; from 701 Enterprise wins.
        self::assertSame($this->catalog->enterpriseThreeYear(), 700 * 4_500);
        self::assertGreaterThan($this->catalog->enterpriseThreeYear(), 701 * 4_500);
    }

    public function test_roll_rule_bands(): void
    {
        self::assertSame('standard', $this->catalog->recommendation(599));
        self::assertSame('compare', $this->catalog->recommendation(600));
        self::assertSame('compare', $this->catalog->recommendation(799));
        self::assertSame('enterprise', $this->catalog->recommendation(800));
    }

    public function test_payload_has_every_number_the_calculator_reads(): void
    {
        $payload = $this->catalog->toArray();

        foreach ([
            'termRate', 'annualTermRate', 'termsPerYear', 'termlyMinimum', 'annualMinimum',
            'termlyPerPupilYear', 'annualPerPupilYear', 'licenceFee', 'enterpriseAnnualFee',
            'emailServiceFee', 'implementationFee', 'enterpriseTermYears', 'enterpriseYearOne',
            'enterpriseThreeYear', 'standardBelow', 'enterpriseFrom', 'breakEvenTermly', 'breakEvenAnnual',
        ] as $key) {
            self::assertArrayHasKey($key, $payload, "Missing pricing key: {$key}");
            self::assertIsInt($payload[$key], "Pricing key {$key} must be an int so the JS maths stays exact");
        }
    }
}
