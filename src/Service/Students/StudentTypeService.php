<?php

namespace App\Service\Students;

use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Student;
use App\Service\Finance\TuitionInvoiceBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * New intake ↔ returning pupil, and the bills that depend on it.
 *
 * The type is not a label on a profile: it decides whether a pupil is billed the
 * one-time new-intake items (uniform, books, ID card, admission form). Changing
 * it used to leave every bill already generated at the old price, so a returning
 * pupil kept paying for a uniform they bought two sessions ago — and the office
 * "fixed" it by hand, one invoice at a time.
 *
 * Two rules make this safe:
 *
 *  1. **Only a tenant super admin changes it.** A bursar takes money; who is new
 *     and who is returning is a pupil-record decision with money attached, and
 *     it is the one the office kept getting wrong.
 *  2. **Every academic bill for that pupil is re-cut**, not just the current
 *     term. A bill with no payments is rebuilt outright; a part-paid bill is
 *     rebuilt too, and cash already received is never rewritten. If the corrected
 *     bill ends up smaller than what was paid, the invoice reads as overpaid and
 *     the excess is named in the returned warnings for the bursary to refund or
 *     hold as credit.
 *
 * Rebuilding is driven by the fee schedule, so a bill is only re-cut when the
 * schedule still prices something for that class and term.
 */
final class StudentTypeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TuitionInvoiceBuilder $invoices,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Move a pupil to a new type and bring every bill in line with it.
     *
     * The caller must have already checked that the current user may do this —
     * see `StudentController::edit()`, which gates the form field and re-checks
     * the submitted value.
     *
     * @return array{from: string, to: string, changed: bool, invoices: int, repriced: int, from_total: float, to_total: float, warnings: list<string>}
     */
    public function change(Student $student, string $newType, string $actor): array
    {
        $to = strtoupper(trim($newType)) === Student::TYPE_RETURNING
            ? Student::TYPE_RETURNING
            : Student::TYPE_NEW;
        $from = $student->getStudentType();

        $result = [
            'from' => $from,
            'to' => $to,
            'changed' => $from !== $to,
            'invoices' => 0,
            'repriced' => 0,
            'from_total' => 0.0,
            'to_total' => 0.0,
            'warnings' => [],
        ];

        if (!$result['changed']) {
            return $result;
        }

        $student->setStudentType($to);

        foreach ($this->academicInvoices($student) as $invoice) {
            ++$result['invoices'];

            $before = (float) $invoice->getTotalAmount();

            if ($this->invoices->repriceForStudentType($invoice, $to)) {
                ++$result['repriced'];
                $result['from_total'] += $before;
                $result['to_total'] += (float) $invoice->getTotalAmount();
            }

            $paid = (float) $invoice->getPaidAmount();
            $total = (float) $invoice->getTotalAmount();

            if ($paid > $total + 0.005) {
                $result['warnings'][] = sprintf(
                    '%s was already paid ₦%s against a corrected bill of ₦%s — ₦%s above the bill is a credit to refund or hold.',
                    $invoice->getInvoiceNumber() ?? 'A bill',
                    number_format($paid, 2),
                    number_format($total, 2),
                    number_format($paid - $total, 2)
                );
            }
        }

        $this->em->flush();

        $result['from_total'] = round($result['from_total'], 2);
        $result['to_total'] = round($result['to_total'], 2);

        $this->logger->info(
            'Student #{student} changed from {from} to {to} by {actor}: {repriced} of {invoices} bills re-priced (₦{from_total} → ₦{to_total}).',
            [
                'student' => $student->getId(),
                'from' => $from,
                'to' => $to,
                'actor' => $actor,
                'repriced' => $result['repriced'],
                'invoices' => $result['invoices'],
                'from_total' => number_format($result['from_total'], 2),
                'to_total' => number_format($result['to_total'], 2),
            ],
        );

        return $result;
    }

    /**
     * Every bill the type can reach.
     *
     * `ACADEMIC` is the current type; `TUITION` is what older rows were written
     * with before the constant existed (the same pair `FeeRepricer` reads). A
     * pupil who joined before the rename would otherwise keep the wrong price on
     * half their history.
     *
     * @return list<Invoice>
     */
    private function academicInvoices(Student $student): array
    {
        $invoices = [];

        foreach ([Invoice::TYPE_ACADEMIC, 'TUITION'] as $type) {
            foreach ($this->em->getRepository(Invoice::class)->findBy([
                'student' => $student,
                'type' => $type,
            ]) as $invoice) {
                if ($invoice instanceof Invoice) {
                    $invoices[$invoice->getId() ?? spl_object_id($invoice)] = $invoice;
                }
            }
        }

        return array_values($invoices);
    }
}
