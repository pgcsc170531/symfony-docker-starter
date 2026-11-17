<?php

namespace App\Tests\Service\Finance;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Finance\DiscountCalculator;
use App\Service\Finance\FeeRepricer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Changing a price must be able to reach bills that already exist — without
 * inventing money or burying a line the family was already shown.
 *
 * Written after the owner asked for a checkbox on the fee screen: "when checked
 * the change should be effective in all already generated invoices".
 */
final class FeeRepricerTest extends TestCase
{
    private Classroom $classroom;
    private Term $term;

    protected function setUp(): void
    {
        $this->classroom = (new Classroom())->setName('JSS 1A');
        $this->term = (new Term())->setSession(new \App\Entity\Tenant\Session());
    }

    /**
     * @param list<FeeStructure>     $structures the schedule as saved
     * @param list<Invoice>          $invoices   the bills on file
     */
    private function repricer(array $structures, array $invoices): FeeRepricer
    {
        $structureRepo = $this->createMock(EntityRepository::class);
        $structureRepo->method('findBy')->willReturn($structures);

        $invoiceRepo = $this->createMock(EntityRepository::class);
        $invoiceRepo->method('findBy')->willReturn($invoices);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => match ($class) {
                FeeStructure::class => $structureRepo,
                Invoice::class => $invoiceRepo,
                default => $this->createMock(EntityRepository::class),
            }
        );

        return new FeeRepricer($em, new DiscountCalculator($em));
    }

    private function structure(FeeItem $item, float $amount): FeeStructure
    {
        return (new FeeStructure())
            ->setClassroom($this->classroom)
            ->setTerm($this->term)
            ->setFeeItem($item)
            ->setAmount(number_format($amount, 2, '.', ''));
    }

    private function item(FeeItem $feeItem, float $amount): InvoiceItem
    {
        return (new InvoiceItem())
            ->setFeeItem($feeItem)
            ->setDescription($feeItem->getName())
            ->setAmount(number_format($amount, 2, '.', ''));
    }

    private function invoice(float $total, float $paid, InvoiceItem ...$items): Invoice
    {
        $invoice = (new Invoice())
            ->setInvoiceNumber('INV-2026-0001')
            ->setClassroom($this->classroom)
            ->setTerm($this->term)
            ->setType(Invoice::TYPE_ACADEMIC)
            ->setStudent((new Student())->setFirstName('Test')->setLastName('Pupil'))
            ->setTotalAmount(number_format($total, 2, '.', ''))
            ->setPaidAmount(number_format($paid, 2, '.', ''));

        foreach ($items as $item) {
            $invoice->addItem($item);
        }

        return $invoice;
    }

    public function testARaisedPriceReachesBillsAlreadyGenerated(): void
    {
        $tuition = (new FeeItem())->setName('Tuition Fee');
        $levy = (new FeeItem())->setName('Development Levy');

        $invoice = $this->invoice(45_000, 0, $this->item($tuition, 40_000), $this->item($levy, 5_000));

        $result = $this->repricer(
            [$this->structure($tuition, 50_000), $this->structure($levy, 5_000)],
            [$invoice]
        )->repriceTerm($this->term, [$this->classroom]);

        self::assertSame(1, $result['changed']);
        self::assertSame(55_000.0, (float) $invoice->getTotalAmount());
        self::assertSame(45_000.0, $result['from']);
        self::assertSame(55_000.0, $result['to']);
    }

    public function testALineTheScheduleNoLongerPricesKeepsWhatTheFamilyWasBilled(): void
    {
        $tuition = (new FeeItem())->setName('Tuition Fee');
        $uniform = (new FeeItem())->setName('Uniform & Sports Wear');

        $invoice = $this->invoice(52_000, 0, $this->item($tuition, 40_000), $this->item($uniform, 12_000));

        // The school cleared the uniform price for this arm; the bill must keep it.
        $result = $this->repricer([$this->structure($tuition, 40_000)], [$invoice])
            ->repriceTerm($this->term, [$this->classroom]);

        self::assertSame(1, $result['kept_lines']);
        self::assertSame(52_000.0, (float) $invoice->getTotalAmount());
        self::assertSame(0, $result['changed']);
    }

    public function testABillIsNeverPushedBelowWhatHasAlreadyBeenPaid(): void
    {
        $tuition = (new FeeItem())->setName('Tuition Fee');

        // The family paid ₦40,000 against a ₦45,000 bill; the price then drops to ₦30,000.
        $invoice = $this->invoice(45_000, 40_000, $this->item($tuition, 45_000))
            ->setStatus(Invoice::STATUS_PARTIAL);

        $this->repricer([$this->structure($tuition, 30_000)], [$invoice])
            ->repriceTerm($this->term, [$this->classroom]);

        // The floor is what they paid — the excess is a credit the office settles,
        // never a bill that reads as owing them money.
        self::assertSame(40_000.0, (float) $invoice->getTotalAmount());
        self::assertSame(Invoice::STATUS_PAID, $invoice->getStatus());
    }

    public function testAnUnchangedBillsIsLeftAlone(): void
    {
        $tuition = (new FeeItem())->setName('Tuition Fee');
        $invoice = $this->invoice(40_000, 10_000, $this->item($tuition, 40_000));

        $result = $this->repricer([$this->structure($tuition, 40_000)], [$invoice])
            ->repriceTerm($this->term, [$this->classroom]);

        self::assertSame(0, $result['changed']);
        self::assertSame(1, $result['untouched']);
    }
}
