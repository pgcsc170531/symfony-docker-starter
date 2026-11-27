<?php

namespace App\Tests\Service\Results;

use App\Entity\Tenant\AssessmentScheme;
use App\Service\Results\AssessmentSchemeResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * CA Total — the column a teacher expects straight after CA 1 / CA 2 / CA 3.
 *
 * One rule, used by the blank score sheet, the entry grid and the printed mark
 * sheet, so the three can never disagree about what the CAs add up to.
 */
final class AssessmentSchemeCaTotalTest extends TestCase
{
    private function resolver(): AssessmentSchemeResolver
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return new AssessmentSchemeResolver($em);
    }

    private function scheme(int $caCount, int $caMax = 20): array
    {
        return [
            'ca_count' => $caCount,
            'exam_mode' => AssessmentScheme::EXAM_MODE_SINGLE,
            'ca_max' => $caMax,
            'theory_max' => 60,
            'practical_max' => 0,
        ];
    }

    public function testThreeCasAreAddedTogether(): void
    {
        $total = $this->resolver()->caTotal(
            $this->scheme(3),
            ['ca1' => 14, 'ca2' => 16, 'ca3' => 18, 'theory' => 30]
        );

        // Only the CAs — the exam is its own column.
        self::assertSame(48.0, $total);
    }

    public function testAnUnmarkedCaIsLeftOutRatherThanCountedAsZero(): void
    {
        $total = $this->resolver()->caTotal($this->scheme(3), ['ca1' => 10, 'ca2' => '']);

        self::assertSame(10.0, $total);
    }

    public function testAPupilWithNoCasAtAllIsBlankNotZero(): void
    {
        // "—" on the sheet, because an unmarked pupil has not scored zero.
        self::assertNull($this->resolver()->caTotal($this->scheme(3), ['theory' => 40]));
    }

    public function testASingleCaNeedsNoSubtotalColumn(): void
    {
        self::assertNull($this->resolver()->caTotal($this->scheme(1), ['ca1' => 15]));
    }

    public function testTheColumnIsOutOfTheMarkPerCaTimesTheCount(): void
    {
        $resolver = $this->resolver();

        self::assertSame(60.0, $resolver->caTotalMaximum($this->scheme(3, 20)));
        self::assertSame(30.0, $resolver->caTotalMaximum($this->scheme(2, 15)));
    }
}
