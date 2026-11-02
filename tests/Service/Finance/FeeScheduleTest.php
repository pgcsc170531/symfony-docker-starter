<?php

namespace App\Tests\Service\Finance;

use App\Service\Finance\FeeSchedule;
use PHPUnit\Framework\TestCase;

/**
 * The fee schedule's arithmetic, without a database.
 *
 * The bug this locks down: a level's price used to be "whichever arm was read
 * last", and its total was one arm's total presented as the level's. SS 1
 * Science costing more than SS 1 Arts was silently rounded away.
 */
final class FeeScheduleTest extends TestCase
{
    public function test_identical_arms_keep_printing_one_number(): void
    {
        $result = FeeSchedule::build([
            $this->row('Tuition', 1, 'Nursery 1', 'Nursery 1 A', 25_000),
            $this->row('Tuition', 1, 'Nursery 1', 'Nursery 1 B', 25_000),
        ], ['Nursery 1']);

        self::assertFalse($result['cells']['Tuition']['Nursery 1']['differs']);
        self::assertSame(25_000.0, $result['cells']['Tuition']['Nursery 1']['min']);
        self::assertSame(25_000.0, $result['cells']['Tuition']['Nursery 1']['max']);

        $totals = $result['totals']['Nursery 1']['recurring'];
        self::assertFalse($totals['differs'], 'A level whose arms agree must not print a range');
        self::assertSame(25_000.0, $totals['min']);
        self::assertSame(25_000.0, $totals['max']);
    }

    public function test_arms_that_differ_are_stated_not_rounded_away(): void
    {
        $result = FeeSchedule::build([
            $this->row('Tuition', 1, 'SS 1', 'SS 1 Science', 40_000),
            $this->row('Tuition', 1, 'SS 1', 'SS 1 Arts', 35_000),
        ], ['SS 1']);

        $cell = $result['cells']['Tuition']['SS 1'];

        self::assertTrue($cell['differs']);
        self::assertSame(35_000.0, $cell['min']);
        self::assertSame(40_000.0, $cell['max']);
        self::assertSame(
            ['SS 1 Science' => 40_000.0, 'SS 1 Arts' => 35_000.0],
            $cell['arms'],
            'Every arm amount must survive, so the screen can list them'
        );
    }

    public function test_level_totals_are_a_range_when_the_arms_totals_differ(): void
    {
        $result = FeeSchedule::build([
            $this->row('Tuition', 1, 'SS 1', 'SS 1 Science', 40_000),
            $this->row('Science levy', 2, 'SS 1', 'SS 1 Science', 5_000, oneTime: true),
            $this->row('Tuition', 1, 'SS 1', 'SS 1 Arts', 35_000),
        ], ['SS 1']);

        $recurring = $result['totals']['SS 1']['recurring'];
        self::assertSame(35_000.0, $recurring['min']);
        self::assertSame(40_000.0, $recurring['max']);
        self::assertTrue($recurring['differs']);

        $oneTime = $result['totals']['SS 1']['onetime'];
        self::assertSame(0.0, $oneTime['min'], 'The arts arm pays no science levy');
        self::assertSame(5_000.0, $oneTime['max']);
        self::assertTrue($oneTime['differs']);
    }

    public function test_a_duplicate_row_for_one_arm_is_counted_once(): void
    {
        $result = FeeSchedule::build([
            $this->row('Tuition', 1, 'JSS 1', 'JSS 1 A', 30_000),
            $this->row('Tuition', 1, 'JSS 1', 'JSS 1 A', 30_000),
        ], ['JSS 1']);

        self::assertSame(30_000.0, $result['totals']['JSS 1']['recurring']['max']);
    }

    public function test_a_level_with_no_fees_reports_zero_without_a_range(): void
    {
        $result = FeeSchedule::build([], ['JSS 2']);

        self::assertSame(['min' => 0.0, 'max' => 0.0, 'differs' => false], $result['totals']['JSS 2']['recurring']);
        self::assertSame(0, $result['totals']['JSS 2']['arms']);
        self::assertSame([], $result['cells']);
    }

    /**
     * @return array{item: string, itemId: int, level: string, arm: string, amount: float, oneTime: bool}
     */
    private function row(string $item, int $itemId, string $level, string $arm, float $amount, bool $oneTime = false): array
    {
        return [
            'item' => $item,
            'itemId' => $itemId,
            'level' => $level,
            'arm' => $arm,
            'amount' => $amount,
            'oneTime' => $oneTime,
        ];
    }
}
