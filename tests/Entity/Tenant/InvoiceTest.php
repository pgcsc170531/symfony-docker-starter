<?php

namespace App\Tests\Entity\Tenant;

use App\Entity\Tenant\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * Locks the invoice arithmetic decided in Phase 0
 * (docs/payments/FEES-REPORTING-DESIGN.md §3.2).
 *
 * `total_amount` is the amount the school expects to collect — NET of the
 * discount. Gross is derived. If any of these fail, every fee report is wrong.
 */
final class InvoiceTest extends TestCase
{
    public function testNetGrossAndOutstandingUseNetSemantics(): void
    {
        $invoice = new Invoice();
        $invoice->setTotalAmount('40000.00');   // ₦50,000 bill less ₦10,000 scholarship
        $invoice->setDiscountApplied('10000.00');
        $invoice->setPaidAmount('15000.00');

        self::assertSame(40000.0, $invoice->getNetBillable());
        self::assertSame(50000.0, $invoice->getGrossBilled());
        self::assertSame(25000.0, $invoice->getOutstanding());
    }

    public function testOutstandingIsNeverNegative(): void
    {
        $invoice = new Invoice();
        $invoice->setTotalAmount('1000.00');
        $invoice->setPaidAmount('1500.00');

        self::assertSame(0.0, $invoice->getOutstanding());
    }

    public function testNewInvoiceHasNoDiscountAndNoBalance(): void
    {
        $invoice = new Invoice();

        self::assertSame(0.0, $invoice->getNetBillable());
        self::assertSame(0.0, $invoice->getGrossBilled());
        self::assertSame(0.0, $invoice->getOutstanding());
    }
}
