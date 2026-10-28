<?php

namespace App\Service\Phone;

/**
 * One phone number, three jobs — and until now each job had its own rules.
 *
 *   national()      8039658200       what we STORE (no trunk zero, no +234)
 *   international() 2348039658200    what an SMS gateway (Termii) needs
 *   loginEmail()    8039658200@edus.ng   the parent's sign-in address
 *
 * A Nigerian mobile is the same subscriber whether it is written 0803 965
 * 8200, 8039658200, +234 803 965 8200 or with Excel's leading apostrophe.
 * Every caller uses this class so the importer, the login page and the SMS
 * transport can never disagree about what a number is.
 */
final class PhoneNumber
{
    /** The domain every phone-derived login is built on. */
    public const LOGIN_DOMAIN = 'edus.ng';

    /** Digits only: "0803 965 8200" → "08039658200". */
    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * What we store: 8039658200 — no leading zero, no country code.
     *
     * The SMS gateway wants the country code and never the trunk zero, so the
     * zero is noise in the database; keeping it out means one stored form for
     * every record, old or new.
     */
    public static function national(string $raw): string
    {
        $digits = self::digits($raw);

        if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
            return substr($digits, 3);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /** What Termii expects: 2348039658200. */
    public static function international(string $raw): string
    {
        $national = self::national($raw);

        if (strlen($national) === 10 && preg_match('/^[7-9]/', $national) === 1) {
            return '234' . $national;
        }

        // Already carries a country code, or is not a Nigerian mobile.
        return $national === '' ? '' : $national;
    }

    /** The parent's sign-in address: 8039658200@edus.ng. */
    public static function loginEmail(string $raw): string
    {
        return self::national($raw) . '@' . self::LOGIN_DOMAIN;
    }

    /**
     * Every spelling of a phone-derived login, in the order we try them.
     *
     * Records created before the national form was adopted still hold
     * 08039658200@edus.ng, so both must resolve. Anything that is not a
     * digits-only address on the platform domain is returned untouched.
     *
     * @return list<string>
     */
    public static function loginCandidates(string $identifier): array
    {
        // strtolower, not mb_strtolower: an e-mail address is ASCII, and this
        // helper must not depend on mbstring being loaded.
        $identifier = strtolower(trim($identifier));
        $at = strrpos($identifier, '@');

        if ($at === false) {
            return [$identifier];
        }

        $local = substr($identifier, 0, $at);
        $domain = substr($identifier, $at + 1);

        if ($domain !== self::LOGIN_DOMAIN || $local === '' || !ctype_digit($local)) {
            return [$identifier];
        }

        $national = self::national($local);

        return array_values(array_unique([
            $national . '@' . $domain,           // 8039658200@edus.ng  — the form written now
            '0' . $national . '@' . $domain,     // 08039658200@edus.ng — older records
            '234' . $national . '@' . $domain,   // 2348039658200@edus.ng — typed with country code
        ]));
    }
}
