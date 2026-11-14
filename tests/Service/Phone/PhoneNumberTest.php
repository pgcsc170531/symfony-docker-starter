<?php

namespace App\Tests\Service\Phone;

use App\Service\Phone\PhoneNumber;
use PHPUnit\Framework\TestCase;

/**
 * Phone search must find the family whichever way the number was typed.
 *
 * The bursar reads the number off a printed form (usually with the trunk zero)
 * while an import may have stored it without one. A search that only matches
 * the typed string silently reports "no parent found".
 */
final class PhoneNumberTest extends TestCase
{
    public function test_a_number_typed_with_the_trunk_zero_also_searches_the_stored_form(): void
    {
        $variants = PhoneNumber::searchVariants('08031234567');

        self::assertContains('8031234567', $variants);
        self::assertContains('08031234567', $variants);
        self::assertContains('2348031234567', $variants);
    }

    public function test_a_number_typed_without_the_trunk_zero_also_searches_the_zero_form(): void
    {
        self::assertContains('08031234567', PhoneNumber::searchVariants('8031234567'));
    }

    public function test_international_spacing_is_ignored(): void
    {
        $variants = PhoneNumber::searchVariants('+234 803 123 4567');

        self::assertContains('8031234567', $variants);
        self::assertContains('08031234567', $variants);
    }

    public function test_a_partial_search_covers_both_spellings(): void
    {
        self::assertContains('803', PhoneNumber::searchVariants('0803'));
        self::assertContains('0803', PhoneNumber::searchVariants('803'));
    }

    public function test_a_name_with_no_digits_produces_no_phone_search(): void
    {
        self::assertSame([], PhoneNumber::searchVariants('Aisha Bello'));
    }

    public function test_variants_are_unique(): void
    {
        self::assertSame(
            array_values(array_unique(PhoneNumber::searchVariants('08031234567'))),
            PhoneNumber::searchVariants('08031234567')
        );
    }
}
