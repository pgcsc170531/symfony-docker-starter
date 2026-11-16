<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns an exception into something the person holding the phone can act on.
 *
 * The error pages used to say only "We could not find that page". A teacher who
 * was told *that* about `/my-class` had nothing to report — the real cause was
 * "The active term has no start or end date, so there is no school calendar.",
 * a sentence a bursar can fix in a minute. The message was thrown deliberately
 * and then thrown away by the template.
 *
 * So: show the message when it is a sentence we wrote, and stay silent when it is
 * framework noise ("No route found for GET /x") or an internal detail (a
 * namespace, a SQL error, a path with a line number) — those help an attacker and
 * confuse a bursar. Nothing here ever prints a stack trace.
 */
final class ErrorExtension extends AbstractExtension
{
    /** Phrases that mean "the framework broke", never "the office did something". */
    private const NOISE = [
        'No route found',
        'Invalid CSRF token',
        'SQLSTATE',
        'Doctrine',
        'App\\',
        'vendor/',
        'Stack trace',
    ];

    public function getFilters(): array
    {
        return [
            new TwigFilter('error_reason', $this->reason(...)),
            new TwigFilter('error_reference', $this->reference(...)),
        ];
    }

    /**
     * A deliberate, human sentence — or null.
     *
     * The argument is whatever the error renderer passes: a `FlattenException` in
     * production (that is what TwigErrorRenderer hands the template) or a live
     * `Throwable` on the kernel.exception path. Both answer `getMessage()`.
     */
    public function reason(mixed $exception): ?string
    {
        $message = $this->messageOf($exception, 12, 220);

        if ($message === null) {
            return null;
        }

        foreach (self::NOISE as $noise) {
            if (str_contains($message, $noise)) {
                return null;
            }
        }

        // "at App\Foo::bar() in /app/src/Foo.php:42" — an internal trace fragment.
        if (preg_match('/\b(in|at) [^\s]+:\d+/', $message) === 1) {
            return null;
        }

        // Everything a person wrote to a person starts with a capital letter and
        // reads like a sentence. A lowercase or code-shaped string is not for them.
        if (preg_match('/^[A-Z"“\'`]/u', $message) !== 1) {
            return null;
        }

        return $message;
    }

    /**
     * A short code the office can quote, derived from the failure itself.
     *
     * Two people hitting the same broken screen get the same code, which is what
     * makes it useful in a phone call — and it reveals nothing about the server.
     */
    public function reference(mixed $exception, ?string $path = null): ?string
    {
        $message = $this->messageOf($exception, 0, null);

        if ($message === null) {
            return null;
        }

        $seed = get_class($exception) . '|' . $message . '|' . ($path ?? '');

        return 'ED-' . strtoupper(substr(hash('sha256', $seed), 0, 6));
    }

    /**
     * The message of a throwable-like object, trimmed and length-checked.
     */
    private function messageOf(mixed $exception, int $min, ?int $max): ?string
    {
        if (!is_object($exception) || !method_exists($exception, 'getMessage')) {
            return null;
        }

        $message = trim((string) $exception->getMessage());

        if ($message === '' || mb_strlen($message) < $min) {
            return null;
        }

        if ($max !== null && mb_strlen($message) > $max) {
            return null;
        }

        return $message;
    }
}
