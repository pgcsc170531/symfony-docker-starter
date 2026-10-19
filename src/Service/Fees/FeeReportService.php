<?php

namespace App\Service\Fees;

use App\Entity\Tenant\Application;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Expense;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentDiscount;
use App\Entity\Tenant\Term;
use App\Repository\Tenant\ExpenseRepository;
use App\Repository\Tenant\InvoiceRepository;
use App\Repository\Tenant\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place every fee figure is calculated.
 *
 * Controllers and Twig must never add up money themselves: the day a report
 * and the invoice screen disagree about a balance, the school stops trusting
 * the whole system. Definitions live in
 * docs/payments/FEES-REPORTING-DESIGN.md §3.
 *
 * `invoice.total_amount` is NET of `discount_applied`; gross is
 * `total_amount + discount_applied` (Invoice::getGrossBilled()).
 */
final class FeeReportService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly ExpenseRepository $expenses,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Headline numbers for a term.
     *
     * @return array{
     *     netBillable: float, discount: float, collected: float,
     *     outstanding: float, credit: float, pending: float, collectionRate: ?float
     * }
     */
    public function termTotals(Term $term, bool $includeStore = false): array
    {
        $net = $this->invoices->netBillableForTerm($term, $includeStore);
        $collected = $this->invoices->collectedForTerm($term, $includeStore);

        return [
            'netBillable'    => $net,
            'discount'       => $includeStore ? 0.0 : $this->invoices->discountForTerm($term),
            'collected'      => $collected,
            'outstanding'    => max(0.0, $net - $collected),
            'credit'         => max(0.0, $collected - $net),
            'pending'        => $this->payments->pendingForTerm($term, $includeStore),
            'collectionRate' => $net > 0.0 ? round($collected / $net * 100, 1) : null,
        ];
    }

    /**
     * Families that still owe money for the term, largest balance first.
     *
     * Pupils with no guardian come back as one group with `guardianId = null`
     * and `guardian = 'No guardian assigned'` so they cannot disappear.
     *
     * @return array<int, array{guardianId: ?int, guardian: ?string, phone: ?string, invoiced: float, paid: float, outstanding: float, pupils: int}>
     */
    public function guardianDebtors(Term $term, ?Classroom $classroom = null): array
    {
        $children = $this->invoices->childBalancesForTerm($term, $classroom);

        $families = [];
        foreach ($children as $child) {
            $key = $child['guardianId'] ?? 0;

            if (!isset($families[$key])) {
                $families[$key] = [
                    'guardianId'  => $child['guardianId'],
                    'guardian'    => $child['guardian'] ?? null,
                    'phone'       => $child['phone'] ?? null,
                    'invoiced'    => 0.0,
                    'paid'        => 0.0,
                    'outstanding' => 0.0,
                    'pupils'      => 0,
                    'children'    => [],
                ];
            }

            $families[$key]['invoiced'] += $child['invoiced'];
            $families[$key]['paid'] += $child['paid'];
            $families[$key]['outstanding'] += $child['outstanding'];
            $families[$key]['pupils']++;
            $families[$key]['children'][] = $child;
        }

        $debtors = [];
        foreach ($families as $family) {
            if ($family['outstanding'] <= 0.0) {
                continue;
            }
            if ($family['guardianId'] === null) {
                $family['guardian'] = 'No guardian assigned';
            }
            $debtors[] = $family;
        }

        usort($debtors, static fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        return $debtors;
    }

    /**
     * One pupil's whole ledger, newest invoice first, with a running balance.
     *
     * @return array{student: Student, term: ?Term, invoices: Invoice[], lines: array<int, array<string, mixed>>, totals: array{net: float, paid: float, pending: float, outstanding: float}}
     */
    public function statement(Student $student, ?Term $term = null, bool $includeStore = false): array
    {
        $invoices = $this->invoices->forStudent($student, $term, $includeStore);

        $lines = [];
        $totals = ['net' => 0.0, 'paid' => 0.0, 'pending' => 0.0, 'outstanding' => 0.0];

        foreach ($invoices as $invoice) {
            $net = $invoice->getNetBillable();
            $paid = (float) $invoice->getPaidAmount();
            $pending = 0.0;

            $lines[] = [
                'date'   => $invoice->getCreatedAt(),
                'label'  => 'Invoice ' . $invoice->getInvoiceNumber(),
                'detail' => ($invoice->getType() === Invoice::TYPE_STORE ? 'Store · ' : '')
                    . ($invoice->getTerm()?->getName() ?? ''),
                'debit'  => $net,
                'credit' => 0.0,
            ];

            $payments = $invoice->getPayments()->toArray();
            usort($payments, static fn (Payment $a, Payment $b): int => ($a->getPaidAt()?->getTimestamp() ?? 0) <=> ($b->getPaidAt()?->getTimestamp() ?? 0));

            foreach ($payments as $payment) {
                if ($payment->getStatus() === Payment::STATUS_PENDING) {
                    $pending += (float) $payment->getAmount();
                    continue;
                }
                if ($payment->getStatus() !== Payment::STATUS_CONFIRMED) {
                    continue;
                }

                $lines[] = [
                    'date'   => $payment->getPaidAt(),
                    'label'  => 'Payment ' . ($payment->getReferenceCode() ?? '#' . $payment->getId()),
                    'detail' => trim(($payment->getMethod() ?? '') . ' ' . ($payment->getReference() ?? '')),
                    'debit'  => 0.0,
                    'credit' => (float) $payment->getAmount(),
                ];
            }

            $totals['net'] += $net;
            $totals['paid'] += $paid;
            $totals['pending'] += $pending;
        }

        usort($lines, static fn (array $a, array $b): int => ($a['date']?->getTimestamp() ?? 0) <=> ($b['date']?->getTimestamp() ?? 0));

        $running = 0.0;
        foreach ($lines as &$line) {
            $running += (float) $line['debit'] - (float) $line['credit'];
            $line['balance'] = $running;
        }
        unset($line);

        $totals['outstanding'] = max(0.0, $totals['net'] - $totals['paid']);

        // A pupil may not have `current_classroom` set (mid-session import), but
        // the invoice itself knows the class they were billed in.
        $classroom = $student->getCurrentClassroom()?->getName();
        if ($classroom === null) {
            foreach (array_reverse($invoices) as $invoice) {
                if ($invoice->getClassroom() !== null) {
                    $classroom = $invoice->getClassroom()->getName();
                    break;
                }
            }
        }

        return [
            'student'   => $student,
            'term'      => $term,
            'invoices'  => $invoices,
            'lines'     => $lines,
            'totals'    => $totals,
            'classroom' => $classroom,
        ];
    }

    /** @return Payment[] */
    public function dailyPayments(\DateTimeImmutable $day): array
    {
        return $this->payments->forDay($day->setTime(0, 0, 0), $day->setTime(23, 59, 59));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 2 — register, reconciliation, matrix, expenses
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: Payment[], total: int, page: int, pages: int, perPage: int}
     */
    public function paymentRegister(array $filters, int $page = 1, int $perPage = 50): array
    {
        return $this->payments->register($filters, $page, $perPage);
    }

    /** @param array<string, mixed> $filters */
    public function paymentRegisterTotals(array $filters): array
    {
        return $this->payments->registerTotals($filters);
    }

    /**
     * R8 — method × status, plus proofs that have waited more than 48 hours.
     *
     * @return array{rows: array<int, array{method: string, status: string, entries: int, total: float}>, stale: Payment[]}
     */
    public function reconciliation(Term $term): array
    {
        $cutoff = (new \DateTimeImmutable())->modify('-48 hours');

        return [
            'rows'  => $this->payments->methodBreakdownForTerm($term),
            'stale' => $this->payments->stalePendingForTerm($term, $cutoff),
        ];
    }

    /**
     * R6 — every pupil in a class, with PAID / PARTIAL / UNPAID / NOT BILLED.
     *
     * Status is derived from the amounts, not trusted from the stored column:
     * a drifted `invoice.status` must not tell a parent they are cleared.
     *
     * @return array{rows: array<int, array{studentId: int, pupil: string, admissionNumber: ?string, invoiceId: ?int, invoiced: float, paid: float, outstanding: float, status: string}>, summary: array{paid: int, partial: int, unpaid: int, notBilled: int, total: int}}
     */
    public function classMatrix(Term $term, Classroom $classroom): array
    {
        $rows = [];
        $summary = ['paid' => 0, 'partial' => 0, 'unpaid' => 0, 'notBilled' => 0, 'total' => 0];

        foreach ($this->invoices->matrixForClass($term, $classroom) as $row) {
            $invoiced = $row['invoiced'];
            $paid = $row['paid'];

            if ($row['invoiceId'] === null) {
                $status = 'NOT BILLED';
                $summary['notBilled']++;
            } elseif ($paid >= $invoiced - 0.01 && $invoiced > 0) {
                $status = 'PAID';
                $summary['paid']++;
            } elseif ($paid > 0) {
                $status = 'PARTIAL';
                $summary['partial']++;
            } else {
                $status = 'UNPAID';
                $summary['unpaid']++;
            }

            $row['status'] = $status;
            $row['outstanding'] = max(0.0, $invoiced - $paid);
            $rows[] = $row;
            $summary['total']++;
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: Expense[], total: int, page: int, pages: int, perPage: int}
     */
    public function expenseRegister(array $filters, int $page = 1, int $perPage = 50): array
    {
        return $this->expenses->register($filters, $page, $perPage);
    }

    /** @param array<string, mixed> $filters */
    public function expenseRegisterTotals(array $filters): array
    {
        return $this->expenses->totals($filters);
    }

    /**
     * R7 — every scholarship/discount, what it actually waived, and which
     * assignments never produced an invoice (a likely data-entry mistake).
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array{waived: float, beneficiaries: int, assigned: int, missingInvoice: int}}
     */
    public function discountReport(Term $term): array
    {
        $invoiceRows = $this->invoices->discountRowsForTerm($term);
        $billedStudentIds = $this->invoices->studentIdsWithInvoiceForTerm($term);

        $byStudent = [];
        foreach ($invoiceRows as $row) {
            $byStudent[(int) $row['studentId']] = $row;
        }

        $rows = [];
        $assignedIds = [];

        foreach ($this->em->getRepository(StudentDiscount::class)->findAll() as $assignment) {
            $student = $assignment->getStudent();
            if ($student === null) {
                continue;
            }

            $id = (int) $student->getId();
            $assignedIds[$id] = true;
            $rule = $assignment->getDiscountType();
            $invoice = $byStudent[$id] ?? null;

            $rows[] = [
                'studentId'      => $id,
                'pupil'          => $student->getFullName(),
                'admissionNumber' => $student->getAdmissionNumber(),
                'guardian'       => $student->getGuardian()?->getFullName(),
                'phone'          => $student->getGuardian()?->getPhoneNumber(),
                'class'          => $invoice['class'] ?? null,
                'rule'           => $rule?->getName() ?? 'Discount',
                'mode'           => $rule?->getMode() ?? '—',
                'value'          => $rule?->getValue() ?? '0',
                'applied'        => $invoice ? (float) $invoice['discount'] : 0.0,
                'invoiceId'      => $invoice['invoiceId'] ?? null,
                'invoiceNumber'  => $invoice['invoiceNumber'] ?? null,
                'billed'         => in_array($id, $billedStudentIds, true),
            ];
        }

        // An invoice can carry a discount even if the assignment row was later
        // removed. Show it rather than silently dropping real money.
        foreach ($byStudent as $id => $row) {
            if (isset($assignedIds[$id])) {
                continue;
            }
            $rows[] = [
                'studentId'      => $id,
                'pupil'          => trim($row['firstName'] . ' ' . $row['lastName']),
                'admissionNumber' => $row['admissionNumber'],
                'guardian'       => $row['guardian'],
                'phone'          => $row['phone'],
                'class'          => $row['class'],
                'rule'           => 'Applied on invoice',
                'mode'           => '—',
                'value'          => '0',
                'applied'        => (float) $row['discount'],
                'invoiceId'      => $row['invoiceId'],
                'invoiceNumber'  => $row['invoiceNumber'],
                'billed'         => true,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['applied'] <=> $a['applied']);

        $waived = array_sum(array_column($rows, 'applied'));
        $beneficiaries = count(array_filter($rows, static fn (array $r): bool => $r['applied'] > 0));

        return [
            'rows'   => $rows,
            'totals' => [
                'waived'         => $waived,
                'beneficiaries'  => $beneficiaries,
                'assigned'       => count($rows),
                'missingInvoice' => count(array_filter($rows, static fn (array $r): bool => !$r['billed'])),
            ],
        ];
    }

    /**
     * R10 — applications with their fee state in a period.
     *
     * @return Application[]
     */
    public function applicationFeeRegister(\DateTimeInterface $from, \DateTimeInterface $to, string $status = '', string $channel = ''): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Application::class, 'a')
            ->andWhere('(a.feePaidAt BETWEEN :from AND :to) OR (a.feePaidAt IS NULL AND a.createdAt BETWEEN :from AND :to)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(500);

        if ($status !== '') {
            $qb->andWhere('a.feeStatus = :status')->setParameter('status', $status);
        }
        if ($channel !== '') {
            $qb->andWhere('a.feeChannel = :channel')->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getResult();
    }

    /** The term immediately before this one, by start date (or id when undated). */
    public function previousTerm(Term $term): ?Term
    {
        $qb = $this->em->getRepository(Term::class)->createQueryBuilder('t')
            ->andWhere('t.id != :id')
            ->setParameter('id', $term->getId())
            ->setMaxResults(1);

        if ($term->getStartDate() !== null) {
            $qb->andWhere('t.startDate < :start')
               ->setParameter('start', $term->getStartDate())
               ->orderBy('t.startDate', 'DESC');
        } else {
            $qb->orderBy('t.id', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @return array<int, array{classId: ?int, class: ?string, pupils: int, billed: float, collected: float, outstanding: float}> */
    public function classCollection(Term $term): array
    {
        return $this->invoices->classCollectionForTerm($term);
    }

    /** @return array{confirmed: array<string, float>, pending: array<string, float>, confirmedTotal: float, pendingTotal: float} */
    public function dailyCollections(\DateTimeImmutable $day): array
    {
        return $this->payments->dailyByMethod(
            $day->setTime(0, 0, 0),
            $day->setTime(23, 59, 59)
        );
    }

    /**
     * Expenses in a period, grouped by category.
     *
     * @return array{total: float, entries: int, byCategory: array<string, float>}
     */
    public function expenseTotals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('c.name AS category, COUNT(e.id) AS entries, SUM(e.amount) AS total')
            ->from(Expense::class, 'e')
            ->join('e.category', 'c')
            ->andWhere('e.expenseDate BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('c.id')
            ->addGroupBy('c.name')
            ->orderBy('SUM(e.amount)', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $byCategory = [];
        $total = 0.0;
        $entries = 0;
        foreach ($rows as $row) {
            $amount = (float) $row['total'];
            $byCategory[(string) $row['category']] = $amount;
            $total += $amount;
            $entries += (int) $row['entries'];
        }

        return ['total' => $total, 'entries' => $entries, 'byCategory' => $byCategory];
    }

    /**
     * Application (form) fees in a period, by status and channel.
     *
     * The money lives on `application.fee_*`, not in `payment` — see
     * FEES-REPORTING-DESIGN §3.2a. Count it exactly once.
     *
     * @return array{paid: float, waived: float, awaiting: float, unpaid: float, byChannel: array<string, float>, applications: int}
     */
    public function applicationFeeTotals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('a.feeStatus AS status, a.feeChannel AS channel, COUNT(a.id) AS applications, SUM(COALESCE(a.feeAmount, 0)) AS total')
            ->from(Application::class, 'a')
            ->andWhere('COALESCE(a.feePaidAt, a.createdAt) BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('a.feeStatus')
            ->addGroupBy('a.feeChannel')
            ->getQuery()
            ->getArrayResult();

        $totals = [
            'paid'         => 0.0,
            'waived'       => 0.0,
            'awaiting'     => 0.0,
            'unpaid'       => 0.0,
            'byChannel'    => [],
            'applications' => 0,
        ];

        foreach ($rows as $row) {
            $amount = (float) $row['total'];
            $status = (string) $row['status'];
            $totals['applications'] += (int) $row['applications'];

            if ($status === Application::FEE_PAID) {
                $totals['paid'] += $amount;
                $channel = (string) ($row['channel'] ?? 'unknown');
                $totals['byChannel'][$channel] = ($totals['byChannel'][$channel] ?? 0.0) + $amount;
            } elseif ($status === Application::FEE_WAIVED) {
                $totals['waived'] += $amount;
            } elseif ($status === Application::FEE_AWAITING_CONFIRMATION) {
                $totals['awaiting'] += $amount;
            } else {
                $totals['unpaid'] += $amount;
            }
        }

        return $totals;
    }

    /**
     * Income statement for a term.
     *
     * Two bases on purpose:
     *   • accrual — what the term was worth (billed, net of discounts);
     *   • cash    — what actually arrived (confirmed payments).
     * Expenses are the same in both. A school can be profitable on paper and
     * unable to pay salaries; the cash column is the one that pays them.
     *
     * @return array<string, mixed>
     */
    public function incomeStatement(Term $term): array
    {
        $start = $term->getStartDate();
        $end = $term->getEndDate();

        $academicNet = $this->invoices->netBillableForTerm($term);
        $academicCollected = $this->invoices->collectedForTerm($term);
        $allNet = $this->invoices->netBillableForTerm($term, true);
        $allCollected = $this->invoices->collectedForTerm($term, true);

        $periodMissing = $start === null || $end === null;

        $applicationFees = $periodMissing
            ? ['paid' => 0.0, 'waived' => 0.0, 'awaiting' => 0.0, 'unpaid' => 0.0, 'byChannel' => [], 'applications' => 0]
            : $this->applicationFeeTotals($start, $end);

        $expenses = $periodMissing
            ? ['total' => 0.0, 'entries' => 0, 'byCategory' => []]
            : $this->expenseTotals($start, $end);

        $storeNet = max(0.0, $allNet - $academicNet);
        $storeCollected = max(0.0, $allCollected - $academicCollected);

        $accrualIncome = $academicNet + $storeNet + $applicationFees['paid'] + $applicationFees['waived'];
        $cashIncome = $academicCollected + $storeCollected + $applicationFees['paid'];

        return [
            'periodMissing'  => $periodMissing,
            'period'         => ['start' => $start, 'end' => $end],
            'academic'       => [
                'accrued'   => $academicNet,
                'collected' => $academicCollected,
                'outstanding' => max(0.0, $academicNet - $academicCollected),
                'discount'  => $this->invoices->discountForTerm($term),
            ],
            'store'          => [
                'accrued'   => $storeNet,
                'collected' => $storeCollected,
            ],
            'applicationFees' => $applicationFees,
            'income'         => [
                'accrual' => $accrualIncome,
                'cash'    => $cashIncome,
            ],
            'expenses'       => $expenses,
            'net'            => [
                'accrual' => $accrualIncome - $expenses['total'],
                'cash'    => $cashIncome - $expenses['total'],
            ],
        ];
    }
}
