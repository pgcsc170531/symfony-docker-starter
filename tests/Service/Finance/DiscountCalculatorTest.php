<?php

namespace App\Tests\Service\Finance;

use App\Entity\Tenant\DiscountType;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
use App\Service\Finance\DiscountCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * The owner's rule: a discount — percentage OR fixed — comes off TUITION only.
 *
 * The failure this guards against is silent and expensive: a 50% scholarship
 * measured against the whole bill quietly pays for uniforms, ID cards and
 * development levies as well.
 */
final class DiscountCalculatorTest extends TestCase
{
    private function calculator(?DiscountType $rule): DiscountCalculator
    {
        $student = (new Student())->setFirstName('Test')->setLastName('Pupil');

        $assignment = null;
        if ($rule !== null) {
            $assignment = (new StudentDiscount())
                ->setStudent($student)
                ->setDiscountType($rule);
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($assignment);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new DiscountCalculator($em);
    }

    private function item(string $description, float $amount, ?FeeItem $feeItem = null): InvoiceItem
    {
        $item = (new InvoiceItem())->setDescription($description)->setAmount((string) $amount);

        if ($feeItem !== null) {
            $item->setFeeItem($feeItem);
        }

        return $item;
    }

    public function testTheTuitionBaseIgnoresEverythingElse(): void
    {
        $calculator = $this->calculator(null);

        $base = $calculator->tuitionBaseFromItems([
            $this->item('Tuition Fee', 40_000, (new FeeItem())->setName('Tuition Fee')),
            $this->item('Development Levy', 5_000, (new FeeItem())->setName('Development Levy')),
            $this->item('Books & Stationery', 7_500, (new FeeItem())->setName('Books & Stationery')),
        ]);

        self::assertSame(40_000.0, $base);
    }

    public function testTheTuitionBaseStillWorksOnBillsWithNoFeeItemLink(): void
    {
        // Older invoices and importer-created bills store only a description.
        $calculator = $this->calculator(null);

        $base = $calculator->tuitionBaseFromItems([
            $this->item('Tuition Fee', 40_000),
            $this->item('Development Levy', 5_000),
        ]);

        self::assertSame(40_000.0, $base);
    }

    public function testAPercentageComesOffTuitionOnly(): void
    {
        $calculator = $this->calculator((new DiscountType())->setMode('PERCENTAGE')->setValue('50'));

        // 50% of the ₦40,000 tuition — NOT 50% of the ₦52,500 bill (₦26,250).
        self::assertSame(20_000.0, $calculator->calculateDiscount(new Student(), 40_000.0));
    }

    public function testAFixedAmountComesOffTuitionOnly(): void
    {
        $calculator = $this->calculator((new DiscountType())->setMode('FIXED')->setValue('5000'));

        self::assertSame(5_000.0, $calculator->calculateDiscount(new Student(), 40_000.0));
    }

    public function testAFixedAmountCannotExceedTheTuition(): void
    {
        $calculator = $this->calculator((new DiscountType())->setMode('FIXED')->setValue('90000'));

        // The tuition is ₦40,000; the rest of the bill is never discounted.
        self::assertSame(40_000.0, $calculator->calculateDiscount(new Student(), 40_000.0));
    }

    public function testNoTuitionMeansNoDiscount(): void
    {
        $calculator = $this->calculator((new DiscountType())->setMode('PERCENTAGE')->setValue('50'));

        self::assertSame(0.0, $calculator->calculateDiscount(new Student(), 0.0));
    }

    public function testTheStructureBaseSkipsOptionalAndNonTuitionLines(): void
    {
        $calculator = $this->calculator(null);

        $structures = [
            (new FeeStructure())->setAmount('50000')->setFeeItem((new FeeItem())->setName('Tuition Fee')),
            (new FeeStructure())->setAmount('12000')->setFeeItem((new FeeItem())->setName('Uniform & Sports Wear')->setIsOptional(true)),
            (new FeeStructure())->setAmount('5000')->setFeeItem((new FeeItem())->setName('Development Levy')),
        ];

        self::assertSame(50_000.0, $calculator->tuitionBaseFromStructures($structures));
    }
}
