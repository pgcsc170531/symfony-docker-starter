<?php

namespace App\Service\Import;

/**
 * Turns whatever a spreadsheet wrote into UTF-8 before it reaches the database.
 *
 * The error this exists for:
 *
 *     SQLSTATE[HY000]: General error: 1366 Incorrect string value:
 *     '\x92IL' for column 'full_name' at row 1
 *
 * `\x92` is byte 146 — a right single quotation mark (`’`) in **Windows-1252**.
 * Excel on Windows saves "CSV (Comma delimited)" in the machine's ANSI code page,
 * not UTF-8, so a parent typed as `A’ishatu` (Excel autocorrect replaces the
 * plain apostrophe) lands in the file as `41 92 69 …`. Every table in this
 * application is utf8mb4, where `0x92` is not a valid byte on its own, so MySQL
 * refuses the whole INSERT and — because the import flushes once at the end —
 * every good row in the file is lost with it.
 *
 * The fix is to decode the file as what it actually is rather than as what we
 * assumed. `mb_convert_encoding()` from Windows-1252 is lossless for everything
 * a school types, so the name is stored exactly as the office sees it on screen.
 */
final class TextEncoding
{
    /**
     * Invisible characters that would follow a name into the database and make two
     * identical people look different: non-breaking spaces (Excel loves them after
     * a pasted value), zero-width spaces and the byte-order mark.
     */
    private const INVISIBLES = ["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"];

    /**
     * Decode one cell as UTF-8, converting from Windows-1252 if that is what it is.
     *
     * Never throws and never returns invalid UTF-8: a byte sequence that cannot be
     * decoded at all is replaced character by character, because losing one odd
     * glyph beats losing the row — and the row is what carries a child's admission.
     */
    public static function toUtf8(mixed $value): string
    {
        if (!is_string($value)) {
            $value = $value === null ? '' : (string) $value;
        }

        if ($value === '') {
            return '';
        }

        // A UTF-8 BOM ("save as CSV UTF-8" writes one) would otherwise become part
        // of the first column heading and break every header lookup.
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            // Windows-1252 covers the punctuation Excel produces (0x92 is ’, 0x93/0x94
            // are “ ”, 0x96/0x97 are – and —), so it is the right first guess.
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

            if (!is_string($converted) || !mb_check_encoding($converted, 'UTF-8')) {
                // ISO-8859-1 can decode any byte, so this is the guaranteed floor.
                $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            }

            $value = is_string($converted) ? $converted : '';
        }

        // Anything still invalid (a truncated multi-byte sequence) gets substituted
        // rather than sent to MySQL, where it would abort the whole import.
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return str_replace(self::INVISIBLES, ' ', $value);
    }

    /**
     * A whole CSV row, decoded cell by cell.
     *
     * `fgetcsv()` gives back exactly the bytes that are in the file; there is no
     * encoding argument to give it, so every reader has to pass what it read
     * through here before it becomes an entity property.
     *
     * @param list<string|null> $row
     *
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_map(self::toUtf8(...), $row);
    }

    /**
     * True when the value had to be repaired — so an import can tell the office
     * "your file was not saved as UTF-8" instead of silently fixing it forever.
     */
    public static function neededRepair(mixed $raw): bool
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return !mb_check_encoding($raw, 'UTF-8')
            || str_starts_with($raw, "\xEF\xBB\xBF")
            || str_contains($raw, "\u{00A0}");
    }
}
