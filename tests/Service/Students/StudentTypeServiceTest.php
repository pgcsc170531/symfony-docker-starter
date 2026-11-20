<?php

namespace App\Tests\Service\Students;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
use App\Entity\Tenant\Term;
use App\Service\Finance\AdvancePaymentService;
use App\Service\Finance\DiscountCalculator;
use App\Service\Finance\InvoiceNumberGenerator;
use App\Service\Finance\PaymentReferenceGenerator;
use App\Service\Finance\TuitionInvoiceBuilder;
use App\Service\Students\StudentTypeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Changing a pupil from new intake to returning must reach the bills that were
 * already generated for them — including a bill that already has money on it.
 *
 * Written after the owner reported that a pupil corrected to "returning" kept
 * the uniform, books and ID-card lines on the invoice the payment terminal had
 * already produced.
 */
final class StudentTypeServiceTest extends TestCase
{
    private Classroom $classroom;
    private Term $term;
    private Student $student;

    protected function setUp(): void
    {
        $this->classroom = (new Classroom())->setName('JSS 1A');
        $this->term = (new Term())->setName('First Term')->setSession(new \App\Entity\Tenant\Session());
        $this->student = (new Student())->setFirstName('Aisha')->setLastName('Bello');
    }

    /**
     * @param list<FeeStructure> $structures the schedule for the arm and term
     * @param list<Invoice>      $invoices   the bills on file
     */
    private function service(array $structures, array $invoices): StudentTypeService
    {
        $structureRepo = $this->createMock(EntityRepository::class);
        $structureRepo->method('findBy')->willReturn($structures);

        $discountRepo = $this->createMock(EntityRepository::class);
        $discountRepo->method('findOneBy')->willReturn(null);

        $invoiceRepo = $this->createMock(EntityRepository::class);
        $invoiceRepo->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(
                $invoices,
                static fn (Invoice $invoice): bool => $invoice->getType() === $criteria['type']
            ))
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class): EntityRepository => match ($class) {
                FeeStructure::class => $structureRepo,
                StudentDiscount::class => $discountRepo,
                Invoice::class => $invoiceRepo,
                default => $this->createMock(EntityRepository::class),
            }
        );

        $builder = new TuitionInvoiceBuilder(
            $em,
            new InvoiceNumberGenerator($em),
            new DiscountCalculator($em),
            new AdvancePaymentService($em, new PaymentReferenceGenerator($em), new NullLogger()),
        );

        return new StudentTypeService($em, $builder, new NullLogger());
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
            ->setStudent($this->student)
            ->setTotalAmount(number_format($total, 2, '.', ''))
            ->setPaidAmount(number_format($paid, 2, '.', ''));

        foreach ($items as $item) {
            $invoice->addItem($item);
        }

        return $invoice;
    }

    private function tuition(): FeeItem
    {
        return (new FeeItem())->setName('Tuition Fee');
    }

    private function uniform(): FeeItem
    {
        return (new FeeItem())->setName('Uniform & Sports Wear')->setFrequency('ONETIME');
    }

    public function test_a_pupil_moved_to_returning_loses_the_one_time_lines_on_an_unpaid_bill(): void
    {
        $tuition = $this->tuition();
        $uniform = $this->uniform();

        $invoice = $this->invoice(52_000, 0, $this->item($tuition, 40_000), $this->item($uniform, 12_000));

        $result = $this->service(
            [$this->structure($tuition, 40_000), $this->structure($uniform, 12_000)],
            [$invoice]
        )->change($this->student, Student::TYPE_RETURNING, 'super.admin');

        self::assertTrue($result['changed']);
        self::assertSame(1, $result['repriced']);
        self::assertSame(40_000.0, (float) $invoice->getTotalAmount());
        self::assertCount(1, $invoice->getItems(), 'the uniform line must be gone');
        self::assertSame(52_000.0, $result['from_total']);
        self::assertSame(40_000.0, $result['to_total']);
        self::assertSame([], $result['warnings']);
    }

    public function test_a_part_paid_bill_is_repriced_without_touching_the_money_received(): void
    {
        $tuition = $this->tuition();
        $uniform = $this->uniform();

        $invoice = $this->invoice(52_000, 20_000, $this->item($tuition, 40_000), $this->item($uniform, 12_000));

        $result = $this->service(
            [$this->structure($tuition, 40_000), $this->structure($uniform, 12_000)],
            [$invoice]
        )->change($this->student, Student::TYPE_RETURNING, 'super.admin');

        self::assertSame(1, $result['repriced']);
        self::assertSame(40_000.0, (float) $invoice->getTotalAmount());
        self::assertSame(20_000.0, (float) $invoice->getPaidAmount(), 'cash already received is never rewritten');
        self::assertSame(Invoice::STATUS_PARTIAL, $invoice->getStatus());
        self::assertSame([], $result['warnings']);
    }

    public function test_a_bill_paid_in_full_is_not_pushed_below_the_cash_and_the_credit_is_named(): void
    {
        $tuition = $this->tuition();
        $uniform = $this->uniform();

        $invoice = $this->invoice(52_000, 52_000, $this->item($tuition, 40_000), $this->item($uniform, 12_000));
        $invoice->setStatus(Invoice::STATUS_PAID);

        $result = $this->service(
            [$this->structure($tuition, 40_000), $this->structure($uniform, 12_000)],
            [$invoice]
        )->change($this->student, Student::TYPE_RETURNING, 'super.admin');

        self::assertSame(40_000.0, (float) $invoice->getTotalAmount());
        self::assertSame(52_000.0, (float) $invoice->getPaidAmount());
        self::assertCount(1, $result['warnings'], 'the excess must be reported, not silently swallowed');
        self::assertStringContainsString('12,000.00', $result['warnings'][0]);
    }

    public function test_correcting_back_to_new_intake_bills_the_one_time_lines_again(): void
    {
        $tuition = $this->tuition();
        $uniform = $this->uniform();

        $invoice = $this->invoice(40_000, 0, $this->item($tuition, 40_000));

        // The pupil is on record as returning — the correction is back to new.
        $this->student->setStudentType(Student::TYPE_RETURNING);

        $result = $this->service(
            [$this->structure($tuition, 40_000), $this->structure($uniform, 12_000)],
            [$invoice]
        )->change($this->student, Student::TYPE_NEW, 'super.admin');

        self::assertTrue($result['changed']);
        self::assertSame(1, $result['repriced']);
        self::assertSame(52_000.0, (float) $invoice->getTotalAmount());
        self::assertCount(2, $invoice->getItems());
    }

    public function test_the_same_type_is_a_no_op(): void
    {
        $tuition = $this->tuition();
        $invoice = $this->invoice(40_000, 0, $this->item($tuition, 40_000));

        $result = $this->service(
            [$this->structure($tuition, 40_000)],
            [$invoice]
        )->change($this->student, Student::TYPE_NEW, 'super.admin');

        self::assertFalse($result['changed']);
        self::assertSame(0, $result['repriced']);
        self::assertSame(40_000.0, (float) $invoice->getTotalAmount());
        self::assertCount(1, $invoice->getItems());
    }

    public function test_an_empty_schedule_never_wipes_a_bill_the_family_has_seen(): void
    {
        $uniform = $this->uniform();

        $invoice = $this->invoice(12_000, 0, $this->item($uniform, 12_000));

        $result = $this->service([], [$invoice])->change($this->student, Student::TYPE_RETURNING, 'super.admin');

        self::assertSame(0, $result['repriced']);
        self::assertSame(12_000.0, (float) $invoice->getTotalAmount());
        self::assertCount(1, $invoice->getItems());
    }
}
