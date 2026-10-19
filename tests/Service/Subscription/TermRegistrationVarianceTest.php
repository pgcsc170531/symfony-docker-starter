<?php

namespace App\Tests\Service\Subscription;

use App\Entity\Landlord\TermRegistration;
use PHPUnit\Framework\TestCase;

/**
 * The overage rule the landlord screen relies on: 5% of the declared roll or 10
 * pupils, whichever is larger. Inside it, nothing extra is billed.
 */
final class TermRegistrationVarianceTest extends TestCase
{
    private function registration(int $declared, ?int $verified): TermRegistration
    {
        return (new TermRegistration())
            ->setDeclaredStudents($declared)
            ->setVerifiedStudents($verified);
    }

    public function test_variance_is_verified_minus_declared(): void
    {
        self::assertSame(4, $this->registration(8, 12)->getVariance());
        self::assertSame(-3, $this->registration(30, 27)->getVariance());
        self::assertNull($this->registration(30, null)->getVariance());
    }

    public function test_small_rolls_get_a_ten_pupil_floor(): void
    {
        // 5% of 8 is 0.4, so the tolerance is the 10-pupil floor.
        self::assertTrue($this->registration(8, 18)->isWithinVariance());
        self::assertFalse($this->registration(8, 19)->isWithinVariance());
    }

    public function test_large_rolls_use_five_percent_when_that_is_bigger(): void
    {
        // 5% of 1,000 = 50 pupils.
        self::assertTrue($this->registration(1_000, 1_050)->isWithinVariance());
        self::assertFalse($this->registration(1_000, 1_051)->isWithinVariance());
    }

    public function test_under_declaration_uses_the_same_band(): void
    {
        // Over-declaring is also a variance; neither side is billed inside tolerance.
        self::assertTrue($this->registration(1_000, 960)->isWithinVariance());
        self::assertFalse($this->registration(1_000, 900)->isWithinVariance());
    }

    public function test_unverified_registration_is_not_outside_tolerance(): void
    {
        self::assertTrue($this->registration(500, null)->isWithinVariance());
    }
}
