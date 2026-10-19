<?php

namespace App\Service\Branding;

/**
 * Makes an arbitrary school colour safe to put text on.
 *
 * A school picks its own colour in settings, and nothing stops it picking
 * `#57E64C` — a bright green on which white text has a contrast ratio of
 * **1.64:1**. That is not a matter of taste: buttons, the hero and the login
 * panel all render white text on that colour, so they become unreadable
 * (WCAG AA needs 4.5:1 for normal text).
 *
 * See docs/websites/SCHOOL-PUBLIC-SITE.md §5 and §9 item 7. The decision there
 * was: keep the school's colour for tints and decoration, but **darken it for
 * any surface that carries white text**. This class performs that darkening.
 */
final class BrandColor
{
    /** WCAG AA for normal-size text. */
    private const MIN_CONTRAST = 4.5;

    /**
     * The colour to use behind white text, or as a link colour on white.
     *
     * The hue is preserved: we multiply the RGB channels down in small steps
     * until the contrast passes, so `#57E64C` becomes a deep green rather than
     * an unrelated colour.
     */
    public static function onWhite(string $hex, string $fallback = '#1e40af'): string
    {
        $rgb = self::parse($hex);
        if ($rgb === null) {
            return $fallback;
        }

        // Already readable? Leave it exactly as the school chose it.
        if (self::ratio($rgb) >= self::MIN_CONTRAST) {
            return self::format($rgb);
        }

        for ($factor = 0.92; $factor > 0.10; $factor -= 0.04) {
            $darkened = [
                (int) round($rgb[0] * $factor),
                (int) round($rgb[1] * $factor),
                (int) round($rgb[2] * $factor),
            ];

            if (self::ratio($darkened) >= self::MIN_CONTRAST) {
                return self::format($darkened);
            }
        }

        return '#111827';
    }

    /**
     * Contrast ratio of white text against the given colour (1–21).
     */
    public static function contrastWithWhite(string $hex): float
    {
        $rgb = self::parse($hex);

        return $rgb === null ? 1.0 : self::ratio($rgb);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private static function ratio(array $rgb): float
    {
        $luminance = self::luminance($rgb);

        return (1.05) / ($luminance + 0.05);
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private static function luminance(array $rgb): float
    {
        $channels = array_map(static function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function parse(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        // Accept `abc` as well as `aabbcc`.
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     */
    private static function format(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }
}
