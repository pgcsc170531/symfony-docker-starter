<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Classroom;

/**
 * Which classes share a fee level.
 *
 * A fee is set per LEVEL — Nursery 1, JSS 2 — and one amount is written to every
 * arm of that level (Nursery 1 Chrome, Garnet, Jade…). The level was previously
 * guessed from the class name ("the last word is one or two letters, or a
 * colour"), which is right for "JSS 1 Gold" and completely wrong for
 * "Nursery 1 Chrome": every gemstone arm became its own level, so a four-level
 * nursery printed sixteen fee columns.
 *
 * The classroom already stores its level — Classroom::$yearGroup — set when the
 * class was created. Use that. The old guess survives only for classes created
 * before year groups existed.
 *
 * Three screens needed this (the fee schedule, the price-entry matrix and the
 * onboarding summary) and each had its own copy; they are now one call, so they
 * cannot disagree about how many columns a school has.
 */
final class ClassLevels
{
    /**
     * @param iterable<Classroom> $classrooms
     * @return array<string, list<Classroom>> level name → its classes
     */
    public static function group(iterable $classrooms): array
    {
        $levels = [];

        foreach ($classrooms as $classroom) {
            $levels[self::of($classroom)][] = $classroom;
        }

        ksort($levels, SORT_NATURAL);

        return $levels;
    }

    /**
     * classroom id → level name, so a fee row can be placed without comparing
     * name prefixes (which puts "Nursery 10" under "Nursery 1").
     *
     * @param array<string, list<Classroom>> $levels
     * @return array<int, string>
     */
    public static function index(array $levels): array
    {
        $index = [];

        foreach ($levels as $levelName => $classes) {
            foreach ($classes as $classroom) {
                $index[(int) $classroom->getId()] = $levelName;
            }
        }

        return $index;
    }

    public static function of(Classroom $classroom): string
    {
        $yearGroup = trim((string) $classroom->getYearGroup()?->getName());

        return $yearGroup !== '' ? $yearGroup : self::guessFromName((string) $classroom->getName());
    }

    /**
     * Last resort for classes that predate year groups: "JSS 1 Gold" → "JSS 1".
     * Never used for a class that has a year group.
     */
    private static function guessFromName(string $name): string
    {
        $parts = explode(' ', trim($name));

        if (count($parts) < 2) {
            return trim($name);
        }

        $last = (string) end($parts);

        if ((mb_strlen($last) <= 2 && !is_numeric($last))
            || in_array(mb_strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'], true)) {
            array_pop($parts);

            return implode(' ', $parts);
        }

        return trim($name);
    }
}
