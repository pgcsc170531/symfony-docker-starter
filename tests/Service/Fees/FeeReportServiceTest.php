<?php

namespace App\Tests\Service\Fees;

use App\Repository\Tenant\ExpenseRepository;
use App\Repository\Tenant\InvoiceRepository;
use App\Repository\Tenant\PaymentRepository;
use App\Service\Fees\FeeReportService;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The guardian-debtors shape is the whole point of R3: one family, one total,
 * one phone call — and a pupil with no guardian must still be visible.
 */
final class FeeReportServiceTest extends TestCase
{
    public function testDebtorsGroupChildrenByGuardianAndKeepOrphansVisible(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $payments = $this->createStub(PaymentRepository::class);
        $expenses = $this->createStub(ExpenseRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);

        $invoices->method('childBalancesForTerm')->willReturn([
            // Family 5 — one child part-paid, one fully paid.
            ['studentId' => 1, 'pupil' => 'Aisha Bello', 'admissionNumber' => 'A1', 'class' => 'JSS 1A', 'guardianId' => 5, 'guardian' => 'Mrs Bello', 'phone' => '08030000000', 'invoiced' => 40000.0, 'paid' => 10000.0, 'outstanding' => 30000.0],
            ['studentId' => 2, 'pupil' => 'Musa Bello', 'admissionNumber' => 'A2', 'class' => 'JSS 2A', 'guardianId' => 5, 'guardian' => 'Mrs Bello', 'phone' => '08030000000', 'invoiced' => 30000.0, 'paid' => 30000.0, 'outstanding' => 0.0],
            // No guardian — must not disappear.
            ['studentId' => 3, 'pupil' => 'Orphan Pupil', 'admissionNumber' => 'A3', 'class' => 'JSS 3A', 'guardianId' => null, 'guardian' => null, 'phone' => null, 'invoiced' => 20000.0, 'paid' => 0.0, 'outstanding' => 20000.0],
            // Fully cleared family — excluded.
            ['studentId' => 4, 'pupil' => 'Cleared Pupil', 'admissionNumber' => 'A4', 'class' => 'JSS 1A', 'guardianId' => 9, 'guardian' => 'Mr Cleared', 'phone' => '08039999999', 'invoiced' => 10000.0, 'paid' => 10000.0, 'outstanding' => 0.0],
        ]);

        $service = new FeeReportService($invoices, $payments, $expenses, $em);
        $rows = $service->guardianDebtors(new \App\Entity\Tenant\Term());

        self::assertCount(2, $rows, 'Only families with a balance should be returned.');

        // Largest family first.
        self::assertSame(5, $rows[0]['guardianId']);
        self::assertSame(30000.0, $rows[0]['outstanding']);
        self::assertSame(2, $rows[0]['pupils']);
        self::assertCount(2, $rows[0]['children'], 'The cleared child stays under the family for context.');

        self::assertNull($rows[1]['guardianId']);
        self::assertSame('No guardian assigned', $rows[1]['guardian']);
        self::assertSame(20000.0, $rows[1]['outstanding']);
    }

    public function testMatrixStatusIsDerivedFromAmountsNotTheStoredColumn(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('matrixForClass')->willReturn([
            ['studentId' => 1, 'pupil' => 'Paid Pupil', 'admissionNumber' => 'A1', 'invoiceId' => 10, 'invoiced' => 100.0, 'paid' => 100.0, 'invoiceStatus' => 'PAID'],
            // Stored column says UNPAID, the money says part-paid.
            ['studentId' => 2, 'pupil' => 'Part Pupil', 'admissionNumber' => 'A2', 'invoiceId' => 11, 'invoiced' => 100.0, 'paid' => 40.0, 'invoiceStatus' => 'UNPAID'],
            // Stored column says PAID, the money says nothing arrived.
            ['studentId' => 3, 'pupil' => 'Stale Pupil', 'admissionNumber' => 'A3', 'invoiceId' => 12, 'invoiced' => 100.0, 'paid' => 0.0, 'invoiceStatus' => 'PAID'],
            ['studentId' => 4, 'pupil' => 'Unbilled Pupil', 'admissionNumber' => 'A4', 'invoiceId' => null, 'invoiced' => 0.0, 'paid' => 0.0, 'invoiceStatus' => null],
        ]);

        $service = new FeeReportService(
            $invoices,
            $this->createStub(PaymentRepository::class),
            $this->createStub(ExpenseRepository::class),
            $this->createStub(EntityManagerInterface::class),
        );

        $data = $service->classMatrix(new Term(), new Classroom());

        self::assertSame(
            ['PAID', 'PARTIAL', 'UNPAID', 'NOT BILLED'],
            array_column($data['rows'], 'status')
        );
        self::assertSame(1, $data['summary']['paid']);
        self::assertSame(1, $data['summary']['partial']);
        self::assertSame(1, $data['summary']['unpaid']);
        self::assertSame(1, $data['summary']['notBilled']);
        self::assertSame(4, $data['summary']['total']);
    }
}
