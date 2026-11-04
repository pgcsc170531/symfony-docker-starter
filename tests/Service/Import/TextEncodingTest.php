<?php

namespace App\Tests\Service\Import;

use App\Service\Import\TextEncoding;
use PHPUnit\Framework\TestCase;

/**
 * The bytes a spreadsheet writes, without a database.
 *
 * The bug this locks down, seen in production on a student upload:
 *
 *     SQLSTATE[HY000]: General error: 1366 Incorrect string value:
 *     '\x92IL' for column 'full_name' at row 1
 *
 * Byte 0x92 is a right single quotation mark in Windows-1252 — what Excel writes
 * when it autocorrects an apostrophe and the office saves "CSV (Comma delimited)".
 * utf8mb4 has no such byte, so MySQL refused the INSERT and the whole import died.
 */
final class TextEncodingTest extends TestCase
{
    public function test_a_windows_1252_apostrophe_is_decoded_not_rejected(): void
    {
        // "A’isha" exactly as Excel saves it on a Windows machine.
        $raw = "A\x92isha";

        self::assertFalse(mb_check_encoding($raw, 'UTF-8'), 'the fixture must be invalid UTF-8');

        $clean = TextEncoding::toUtf8($raw);

        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertSame("A\u{2019}isha", $clean);
    }

    public function test_the_exact_bytes_from_the_error_message_are_storable(): void
    {
        // '\x92IL' — the fragment MySQL printed for the value "…’IL…".
        $clean = TextEncoding::toUtf8("\x92IL");

        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertSame("\u{2019}IL", $clean);
    }

    public function test_valid_utf8_is_left_alone(): void
    {
        $name = 'Nwa’kaego O’Brien';

        self::assertSame($name, TextEncoding::toUtf8($name));
    }

    public function test_a_byte_order_mark_does_not_become_part_of_the_first_heading(): void
    {
        self::assertSame('Full Name', TextEncoding::toUtf8("\xEF\xBB\xBFFull Name"));
    }

    public function test_invisible_padding_is_removed(): void
    {
        // Excel leaves a non-breaking space after a pasted value; left in place it
        // makes "Aisha" and "Aisha " two different people.
        self::assertSame('Aisha Bello', TextEncoding::toUtf8("Aisha\u{00A0}Bello"));
    }

    public function test_a_whole_row_is_decoded_cell_by_cell(): void
    {
        $row = TextEncoding::row(["A\x92isha", 'Bello', '08031234567']);

        self::assertSame("A\u{2019}isha", $row[0]);
        self::assertSame('Bello', $row[1]);
        self::assertSame('08031234567', $row[2]);
    }

    public function test_undecodable_bytes_are_replaced_rather_than_thrown(): void
    {
        // A truncated multi-byte sequence: no encoding can make this valid, and
        // losing one glyph must never mean losing the row.
        $clean = TextEncoding::toUtf8("Zubairu \xC3");

        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertStringStartsWith('Zubairu ', $clean);
    }

    public function test_repair_is_reported_so_the_office_can_be_told(): void
    {
        self::assertTrue(TextEncoding::neededRepair("A\x92isha"));
        self::assertTrue(TextEncoding::neededRepair("\xEF\xBB\xBFName"));
        self::assertTrue(TextEncoding::neededRepair("Aisha\u{00A0}Bello"));

        self::assertFalse(TextEncoding::neededRepair('Aisha Bello'));
        self::assertFalse(TextEncoding::neededRepair(''));
        self::assertFalse(TextEncoding::neededRepair(null));
    }

    public function test_non_string_cells_do_not_blow_up(): void
    {
        self::assertSame('', TextEncoding::toUtf8(null));
        self::assertSame('42', TextEncoding::toUtf8(42));
    }
}
