<?php

namespace App\Service\Finance;

/**
 * Turns fee rows into the level matrix the schedule prints.
 *
 * A fee is stored **per arm** (`fee_structure.classroom_id`), but it is read per
 * level, because a nursery whose arms are identical should print one column, not
 * five. The old code did that by overwriting the same cell for every arm
 * (`$matrix[$item][$level] = $amount`), so the last arm read won — silently. A
 * school that charged SS 1 Science more than SS 1 Arts saw one of the two numbers
 * presented as if it were "the SS 1 price", and the level totals were wrong by
 * the same amount.
 *
 * This class keeps every arm's amount and states the difference instead:
 *
 *   - arms agree  → one number, exactly as before
 *   - arms differ → `min` / `max` plus the per-arm amounts, so the screen can say
 *                   "from ₦X" and list the arms underneath
 *
 * Pure and static: no Doctrine, so the arithmetic is unit-tested without a database.
 */
final class FeeSchedule
{
    /**
     * @param list<array{item: string, itemId: int, level: string, arm: string, amount: float, oneTime: bool}> $rows
     * @param list<string>                                                                                    $levelNames
     *
     * @return array{
     *     cells: array<string, array<string, array{min: float, max: float, differs: bool, arms: array<string, float>}>>,
     *     totals: array<string, array{
     *         recurring: array{min: float, max: float, differs: bool},
     *         onetime: array{min: float, max: float, differs: bool},
     *         arms: int
     *     }>
     * }
     */
    public static function build(array $rows, array $levelNames): array
    {
        /** @var array<string, array<string, array<string, float>>> $cells item → level → arm → amount */
        $cells = [];

        /** @var array<string, array<string, array{recurring: float, onetime: float}>> $armTotals level → arm → sums */
        $armTotals = [];

        // One fee item is added to an arm's total once, even if the file holds a
        // stray duplicate row for it.
        $counted = [];

        foreach ($rows as $row) {
            $cells[$row['item']][$row['level']][$row['arm']] = (float) $row['amount'];

            $key = $row['level'] . '|' . $row['arm'] . '|' . $row['itemId'];
            if (isset($counted[$key])) {
                continue;
            }
            $counted[$key] = true;

            $armTotals[$row['level']][$row['arm']] ??= ['recurring' => 0.0, 'onetime' => 0.0];
            $armTotals[$row['level']][$row['arm']][$row['oneTime'] ? 'onetime' : 'recurring'] += (float) $row['amount'];
        }

        $out = [];
        foreach ($cells as $item => $byLevel) {
            foreach ($byLevel as $level => $byArm) {
                $values = array_values($byArm);

                $out[$item][$level] = [
                    'min' => min($values),
                    'max' => max($values),
                    'differs' => count(array_unique(array_map(self::key(...), $values))) > 1,
                    'arms' => $byArm,
                ];
            }
        }

        $totals = [];
        foreach ($levelNames as $level) {
            $arms = $armTotals[$level] ?? [];
            $recurring = array_column($arms, 'recurring');
            $oneTime = array_column($arms, 'onetime');

            $totals[$level] = [
                'recurring' => self::range($recurring),
                'onetime' => self::range($oneTime),
                'arms' => count($arms),
            ];
        }

        return ['cells' => $out, 'totals' => $totals];
    }

    /** @param list<float> $values */
    private static function range(array $values): array
    {
        if ($values === []) {
            return ['min' => 0.0, 'max' => 0.0, 'differs' => false];
        }

        $min = min($values);
        $max = max($values);

        return [
            'min' => $min,
            'max' => $max,
            'differs' => self::key($min) !== self::key($max),
        ];
    }

    /** Money compared at two decimals, so 40000.0 and 40000.004 are "the same". */
    private static function key(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
