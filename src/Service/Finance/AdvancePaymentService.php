<?php

namespace App\Service\Finance;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentAdvance;
use App\Service\Finance\PaymentReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Money paid ahead, and the invoices it settles.
 *
 * A family hands over a term — or three sessions — in advance. That money is
 * recorded once as a {@see StudentAdvance}, and every invoice raised afterwards
 * for that pupil is **paid from it automatically**, with a real `Payment` row
 * that says so.
 *
 * Two rules keep this auditable:
 *
 *  1. **A draw-down is a payment, not a discount.** The invoice shows the full
 *     fee and a payment against it, because that is what happened: the school
 *     banked the cash months ago. Zeroing the bill instead would hide revenue.
 *  2. **Every draw-down carries the remark.** `Payment.note` states the advance
 *     reference, when the money was received, how, and what it covers — so a
 *     physical audit can match one bank credit to several terms of invoices.
 */
final class AdvancePaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentReferenceGenerator $references,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** What is left of an advance: what was paid, minus what has been drawn. */
    public function balance(StudentAdvance $advance): float
    {
        $used = (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.amount), 0)')
            ->from(Payment::class, 'p')
            ->andWhere('p.advance = :advance')
            ->andWhere('p.status = :status')
            ->setParameter('advance', $advance)
            ->setParameter('status', Payment::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();

        return round(max(0.0, (float) $advance->getAmount() - $used), 2);
    }

    /**
     * The advance an invoice should be settled from, if any.
     *
     * Only an open advance with money left qualifies; a closed one stops drawing
     * straight away, which is how a refund or a departure is handled.
     */
    public function openAdvanceFor(Student $student): ?StudentAdvance
    {
        $advances = $this->em->getRepository(StudentAdvance::class)->findBy(
            ['student' => $student, 'isClosed' => false],
            ['id' => 'ASC']
        );

        foreach ($advances as $advance) {
            if ($this->balance($advance) > 0.005) {
                return $advance;
            }
        }

        return null;
    }

    /**
     * Settle an invoice from the pupil's advance.
     *
     * Draws down as much as the credit holds — in full when it covers the bill,
     * partially when it does not. Returns null, and changes nothing, when the
     * pupil has no open advance, has spent it, or the invoice is already paid.
     */
    public function applyTo(Invoice $invoice): ?Payment
    {
        $student = $invoice->getStudent();
        if (!$student instanceof Student) {
            return null;
        }

        $balanceDue = (float) $invoice->getBalanceDue();
        if ($balanceDue <= 0.005) {
            return null;
        }

        $advance = $this->openAdvanceFor($student);
        if ($advance === null) {
            return null;
        }

        $available = $this->balance($advance);
        if ($available <= 0.005) {
            return null;
        }

        // Draw what the credit can cover, never more. A family who paid ₦80,000
        // ahead of an ₦84,600 bill has ₦4,600 left to pay — the school must see
        // that balance rather than an invoice it cannot explain.
        $draw = round(min($available, $balanceDue), 2);
        $remaining = round($balanceDue - $draw, 2);

        if ($remaining > 0.005) {
            $this->logger->info('Advance {ref} part-covers invoice {invoice}: ₦{draw} of ₦{due}.', [
                'ref' => $advance->getReference(),
                'invoice' => $invoice->getInvoiceNumber(),
                'draw' => $draw,
                'due' => $balanceDue,
            ]);
        }

        $payment = (new Payment())
            ->setInvoice($invoice)
            ->setAdvance($advance)
            ->setAmount(number_format($draw, 2, '.', ''))
            ->setMethod(Payment::METHOD_ADVANCE)
            ->setReferenceCode($this->references->next())
            ->setStatus(Payment::STATUS_CONFIRMED)
            ->setConfirmedAt(new \DateTimeImmutable())
            ->setConfirmedBy($advance->getCreatedBy() ?: 'ADVANCE')
            ->setNote($this->remark($advance, $invoice, $draw, $balanceDue, $remaining));

        $this->em->persist($payment);

        $invoice->setPaidAmount((string) ((float) $invoice->getPaidAmount() + $draw));
        $invoice->setStatus($remaining > 0.005 ? Invoice::STATUS_PARTIAL : Invoice::STATUS_PAID);

        return $payment;
    }

    /** The line an auditor reads on the invoice. */
    private function remark(StudentAdvance $advance, Invoice $invoice, float $amount, float $due, float $remaining): string
    {
        $received = $advance->getReceivedOn()?->format('j M Y') ?? 'date not recorded';
        $how = StudentAdvance::METHODS[$advance->getMethod()] ?? $advance->getMethod();

        $parts = [
            sprintf('Paid from advance %s', $advance->getReference()),
            sprintf('received %s by %s', $received, strtolower($how)),
        ];

        if ($advance->getCoversNote()) {
            $parts[] = 'covers ' . $advance->getCoversNote();
        }

        if ($advance->getRemark()) {
            $parts[] = trim($advance->getRemark());
        }

        $parts[] = $remaining > 0.005
            ? sprintf(
                'part-covers invoice %s (%s of %s; %s left to pay)',
                $invoice->getInvoiceNumber() ?? 'new invoice',
                '₦' . number_format($amount, 2),
                '₦' . number_format($due, 2),
                '₦' . number_format($remaining, 2)
            )
            : sprintf('settles invoice %s in full (%s)', $invoice->getInvoiceNumber() ?? 'new invoice', '₦' . number_format($amount, 2));

        return implode(' · ', $parts);
    }

    /** Allocate the next advance reference, e.g. ADV-2026-0004. */
    public function nextReference(): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $count = (int) $this->em->getRepository(StudentAdvance::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        do {
            $count++;
            $reference = sprintf('ADV-%s-%04d', $year, $count);
        } while ($this->em->getRepository(StudentAdvance::class)->findOneBy(['reference' => $reference]) !== null);

        return $reference;
    }
}
