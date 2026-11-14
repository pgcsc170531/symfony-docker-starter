<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\InvoiceItem;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that turns a fee schedule into a term invoice.
 *
 * Admissions, the bulk student importer and the opening-balance fees import all
 * need the same arithmetic. Three copies of the loop had already drifted once
 * (the importer billed one-time items to returning pupils), so the arithmetic
 * lives here and the callers only decide *who* and *which term*.
 *
 * A returning pupil is billed the recurring items only. `ONETIME` is the
 * school's marker for new-intake items (uniform, books, ID card, admission
 * form); `SESSION` items belong to the first term of the session.
 */
final class TuitionInvoiceBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceNumberGenerator $invoiceNumbers,
        private readonly DiscountCalculator $discountCalculator,
        private readonly AdvancePaymentService $advances,
    ) {
    }

    /**
     * Build the invoice, or return the one that already exists for this pupil
     * and term. A historical bill is never recalculated by this method.
     */
    public function build(
        Student $student,
        Classroom $classroom,
        Term $term,
        string $studentType = Student::TYPE_NEW,
        bool $rebuildUnpaid = false,
    ): ?Invoice {
        $existing = $this->em->getRepository(Invoice::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'type' => Invoice::TYPE_ACADEMIC,
        ]);

        if ($existing instanceof Invoice) {
            // The student importer may have billed a returning pupil as a new
            // intake. With no money against the bill it is safe to correct the
            // lines; once a payment exists the bill is history.
            if ($rebuildUnpaid && $this->isUnpaid($existing)) {
                $quote = $this->quote($student, $classroom, $term, $studentType);

                if ($quote['lines'] !== []) {
                    $this->replaceLines($existing, $quote);
                }
            }

            return $existing;
        }

        $quote = $this->quote($student, $classroom, $term, $studentType);

        if ($quote['lines'] === []) {
            return null;
        }

        $invoice = new Invoice();
        $invoice->setStudent($student);
        $invoice->setTerm($term);
        $invoice->setSession($term->getSession());
        $invoice->setClassroom($classroom);
        $invoice->setInvoiceNumber($this->invoiceNumbers->next(
            $this->invoiceNumbers->yearFor($term->getSession())
        ));
        $invoice->setStatus(Invoice::STATUS_UNPAID);
        $invoice->setType(Invoice::TYPE_ACADEMIC);
        $invoice->setPaidAmount('0');
        $invoice->setDiscountApplied((string) $quote['discount']);
        $invoice->setDiscountLabel($quote['discountLabel']);
        $invoice->setTotalAmount((string) $quote['total']);

        foreach ($quote['lines'] as $line) {
            $item = new InvoiceItem();
            $item->setInvoice($invoice);
            $item->setFeeItem($line['feeItem']);
            $item->setDescription($line['name']);
            $item->setAmount((string) $line['amount']);
            $this->em->persist($item);
        }

        $this->em->persist($invoice);

        // A family that paid ahead should not be shown a bill they settled.
        $this->advances->applyTo($invoice);

        return $invoice;
    }

    /**
     * The same arithmetic without writing anything — used by the import preview.
     *
     * @return array{
     *   lines: list<array{name: string, amount: float, feeItem: \App\Entity\Tenant\FeeItem}>,
     *   gross: float,
     *   discount: float,
     *   discountLabel: ?string,
     *   total: float
     * }
     */
    public function quote(
        Student $student,
        Classroom $classroom,
        Term $term,
        string $studentType = Student::TYPE_NEW,
    ): array {
        $isReturning = $studentType === Student::TYPE_RETURNING;
        $isFirstTerm = $this->isFirstTerm($term);

        /** @var list<FeeStructure> $billable */
        $billable = [];
        $lines = [];
        $gross = 0.0;

        foreach ($this->em->getRepository(FeeStructure::class)->findBy([
            'classroom' => $classroom,
            'term' => $term,
        ]) as $fee) {
            $item = $fee->getFeeItem();

            if ($item === null || $item->isOptional()) {
                continue;
            }

            $frequency = strtoupper((string) ($item->getFrequency() ?: 'TERM'));

            if ($frequency === 'SESSION' && !$isFirstTerm) {
                continue;
            }

            if ($frequency === 'ONETIME' && $isReturning) {
                continue;
            }

            $amount = (float) $fee->getAmount();
            $billable[] = $fee;
            $lines[] = [
                'name' => $item->getName() ?? 'Fee',
                'amount' => $amount,
                'feeItem' => $item,
            ];
            $gross += $amount;
        }

        $gross = round($gross, 2);
        $discount = $this->discountCalculator->calculateDiscount(
            $student,
            $this->discountCalculator->tuitionBaseFromStructures($billable)
        );

        return [
            'lines' => $lines,
            'gross' => $gross,
            'discount' => round($discount, 2),
            'discountLabel' => $this->discountCalculator->describe($student),
            'total' => round(max(0.0, $gross - $discount), 2),
        ];
    }

    /** A bill nobody has paid against can be recalculated safely. */
    private function isUnpaid(Invoice $invoice): bool
    {
        if ((float) $invoice->getPaidAmount() > 0.005) {
            return false;
        }

        foreach ($invoice->getPayments() as $payment) {
            if ($payment->getStatus() === Payment::STATUS_CONFIRMED) {
                return false;
            }
        }

        return true;
    }

    /** @param array{lines: list<array{name: string, amount: float, feeItem: \App\Entity\Tenant\FeeItem}>, gross: float, discount: float, discountLabel: ?string, total: float} $quote */
    private function replaceLines(Invoice $invoice, array $quote): void
    {
        foreach ($invoice->getItems()->toArray() as $oldItem) {
            $this->em->remove($oldItem);
        }
        $invoice->getItems()->clear();

        foreach ($quote['lines'] as $line) {
            $item = new InvoiceItem();
            $item->setInvoice($invoice);
            $item->setFeeItem($line['feeItem']);
            $item->setDescription($line['name']);
            $item->setAmount((string) $line['amount']);
            $this->em->persist($item);
            $invoice->addItem($item);
        }

        $invoice->setDiscountApplied((string) $quote['discount']);
        $invoice->setDiscountLabel($quote['discountLabel']);
        $invoice->setTotalAmount((string) $quote['total']);
        $invoice->setStatus(Invoice::STATUS_UNPAID);
    }

    /** The same rule the fee schedule uses to show or hide SESSION items. */
    private function isFirstTerm(Term $term): bool
    {
        $name = mb_strtolower(trim((string) $term->getName()));

        return str_contains($name, '1st')
            || str_contains($name, 'first')
            || str_starts_with($name, '1');
    }
}
