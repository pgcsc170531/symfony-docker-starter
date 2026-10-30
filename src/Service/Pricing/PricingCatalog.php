<?php

namespace App\Service\Pricing;

/**
 * The public price list — ONE source for the /pricing page, its calculator and
 * any billing code that follows.
 *
 * Every figure here is a commercial decision recorded in
 * docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md §2C/§2D. Do not type a price
 * into a template: the website and the invoice must never be able to disagree.
 *
 * Standard  — per pupil, per 4-month term (3 terms a year).
 * Enterprise — licence + annual (email included) + implementation.
 */
final class PricingCatalog
{
    public const CURRENCY = '₦';

    // ── Standard (per pupil) ────────────────────────────────────────────────
    /** Per pupil, per 4-month term, when the school pays termly. */
    public const TERM_RATE = 750;

    /** Per pupil, per term, when the whole year is paid up front (33% off). */
    public const ANNUAL_TERM_RATE = 500;

    public const TERMS_PER_YEAR = 3;

    /** Smallest invoice we are willing to raise (protects a tiny school). */
    public const TERMLY_MINIMUM = 50_000;
    public const ANNUAL_MINIMUM = 100_000;

    // ── Enterprise (licence) ────────────────────────────────────────────────
    public const LICENCE_FEE = 1_200_000;

    /** The renewal before email became mandatory. */
    public const ENTERPRISE_BASE_ANNUAL_FEE = 550_000;

    /** Email notifications are mandatory on Enterprise — never sold as an option. */
    public const EMAIL_SERVICE_FEE = 100_000;

    public const ENTERPRISE_ANNUAL_FEE = self::ENTERPRISE_BASE_ANNUAL_FEE + self::EMAIL_SERVICE_FEE; // ₦650,000

    public const IMPLEMENTATION_FEE = 300_000;

    public const ENTERPRISE_TERM_YEARS = 3;

    // ── The roll rule (the sentence the pricing page exists to say) ─────────
    /** Below this roll, annual Standard is cheaper than Enterprise. */
    public const STANDARD_BELOW = 600;

    /** From this roll, Enterprise is cheaper than even annual Standard. */
    public const ENTERPRISE_FROM = 800;

    /** One term's invoice on the Standard track, including the minimum. */
    public function termlyInvoice(int $pupils): int
    {
        if ($pupils <= 0) {
            return 0;
        }

        return max($pupils * self::TERM_RATE, self::TERMLY_MINIMUM);
    }

    /** What a termly school pays over a whole year (3 invoices). */
    public function termlyYearCost(int $pupils): int
    {
        return $this->termlyInvoice($pupils) * self::TERMS_PER_YEAR;
    }

    /** One annual-prepay invoice, including the minimum. */
    public function annualPrepay(int $pupils): int
    {
        if ($pupils <= 0) {
            return 0;
        }

        return max($pupils * self::ANNUAL_TERM_RATE * self::TERMS_PER_YEAR, self::ANNUAL_MINIMUM);
    }

    public function termlyPerPupilYear(): int
    {
        return self::TERM_RATE * self::TERMS_PER_YEAR; // 2,250
    }

    public function annualPerPupilYear(): int
    {
        return self::ANNUAL_TERM_RATE * self::TERMS_PER_YEAR; // 1,500
    }

    /** Licence + implementation + the first annual fee. */
    public function enterpriseYearOne(): int
    {
        return self::LICENCE_FEE + self::IMPLEMENTATION_FEE + self::ENTERPRISE_ANNUAL_FEE;
    }

    /** Year one plus two renewals — the comparison basis for the roll rule. */
    public function enterpriseThreeYear(): int
    {
        return $this->enterpriseYearOne()
            + (self::ENTERPRISE_TERM_YEARS - 1) * self::ENTERPRISE_ANNUAL_FEE;
    }

    /**
     * Roll at which Enterprise stops being dearer than Standard over the term.
     *
     * At exactly this number the two are usually within a naira of each other
     * (annual prepay: 767 pupils × ₦4,500 = ₦3,451,500), so the page describes it
     * as the crossover, not as a saving.
     */
    public function breakEvenPupils(bool $annualPrepay): int
    {
        $perPupilPerYear = $annualPrepay ? $this->annualPerPupilYear() : $this->termlyPerPupilYear();

        return (int) ceil($this->enterpriseThreeYear() / ($perPupilPerYear * self::ENTERPRISE_TERM_YEARS));
    }

    /**
     * Which headline a roll falls under.
     *
     * @return 'standard'|'compare'|'enterprise'
     */
    public function recommendation(int $pupils): string
    {
        return match (true) {
            $pupils < self::STANDARD_BELOW => 'standard',
            $pupils < self::ENTERPRISE_FROM => 'compare',
            default => 'enterprise',
        };
    }

    /**
     * Everything the pricing page and its calculator need, in one shape.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'termRate' => self::TERM_RATE,
            'annualTermRate' => self::ANNUAL_TERM_RATE,
            'termsPerYear' => self::TERMS_PER_YEAR,
            'termlyMinimum' => self::TERMLY_MINIMUM,
            'annualMinimum' => self::ANNUAL_MINIMUM,
            'termlyPerPupilYear' => $this->termlyPerPupilYear(),
            'annualPerPupilYear' => $this->annualPerPupilYear(),
            'licenceFee' => self::LICENCE_FEE,
            'enterpriseAnnualFee' => self::ENTERPRISE_ANNUAL_FEE,
            'emailServiceFee' => self::EMAIL_SERVICE_FEE,
            'implementationFee' => self::IMPLEMENTATION_FEE,
            'enterpriseTermYears' => self::ENTERPRISE_TERM_YEARS,
            'enterpriseYearOne' => $this->enterpriseYearOne(),
            'enterpriseThreeYear' => $this->enterpriseThreeYear(),
            'standardBelow' => self::STANDARD_BELOW,
            'enterpriseFrom' => self::ENTERPRISE_FROM,
            'breakEvenTermly' => $this->breakEvenPupils(false),
            'breakEvenAnnual' => $this->breakEvenPupils(true),
        ];
    }
}
