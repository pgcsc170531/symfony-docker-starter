<?php

namespace App\Service\Mail;

/**
 * Can this address actually receive mail?
 *
 * Bulk imports created addresses like `08033123456@placeholder.com` and
 * `08033123456@edus.ng` which nobody can read. Treating one of those as "your
 * account" is a dead end: the person is told a link was sent and nothing ever
 * arrives. Every recovery path asks this first.
 */
final class AddressCheck
{
    /** Domains that look like an address but are never a mailbox. */
    private const UNUSABLE = [
        'placeholder.com',
        'edus.ng',
        'edus.local',
        'example.com',
        'localhost',
        'invalid',
        'test',
    ];

    public static function isDeliverable(?string $email): bool
    {
        if (!$email || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));

        foreach (self::UNUSABLE as $unusable) {
            if ($domain === $unusable || str_ends_with($domain, '.' . $unusable)) {
                return false;
            }
        }

        // "...@gmail" style typos and .local cannot receive mail either.
        return str_contains($domain, '.');
    }
}
