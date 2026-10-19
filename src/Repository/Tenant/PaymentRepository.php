<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Term;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every money figure the school sees is read through here.
 *
 * Rules (docs/payments/FEES-REPORTING-DESIGN.md §3.1):
 *   • only CONFIRMED payments count as collected;
 *   • PENDING is reported separately, never added to a total;
 *   • a cash-book day is based on when the money was confirmed, falling back
 *     to created_at for legacy rows.
 *
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /** Confirmed money received against one invoice. */
    public function confirmedForInvoice(Invoice $invoice): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->andWhere('p.invoice = :invoice')
            ->andWhere('p.status = :status')
            ->setParameter('invoice', $invoice)
            ->setParameter('status', Payment::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Claimed but unverified money for one invoice. */
    public function pendingForInvoice(Invoice $invoice): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->andWhere('p.invoice = :invoice')
            ->andWhere('p.status = :status')
            ->setParameter('invoice', $invoice)
            ->setParameter('status', Payment::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Confirmed totals keyed by invoice id — one query for a whole page,
     * instead of walking invoice->getPayments() in a loop.
     *
     * @param int[] $invoiceIds
     * @return array<int, float>
     */
    public function confirmedTotalsByInvoice(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.invoice) AS invoiceId, SUM(p.amount) AS total')
            ->andWhere('p.invoice IN (:ids)')
            ->andWhere('p.status = :status')
            ->setParameter('ids', $invoiceIds)
            ->setParameter('status', Payment::STATUS_CONFIRMED)
            ->groupBy('p.invoice')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['invoiceId']] = (float) $row['total'];
        }

        return $totals;
    }

    /** Confirmed money between two instants, across the whole school. */
    public function confirmedBetween(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->andWhere('p.status = :status')
            ->andWhere('(p.confirmedAt BETWEEN :from AND :to) OR (p.confirmedAt IS NULL AND p.createdAt BETWEEN :from AND :to)')
            ->setParameter('status', Payment::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Claimed-but-unverified money attached to invoices in a term. */
    public function pendingForTerm(\App\Entity\Tenant\Term $term, bool $includeStore = false): float
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->join('p.invoice', 'i')
            ->andWhere('i.term = :term')
            ->andWhere('p.status = :status')
            ->setParameter('term', $term)
            ->setParameter('status', Payment::STATUS_PENDING);

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The cash book's detail rows for one day.
     *
     * @return Payment[]
     */
    public function forDay(\DateTimeInterface $dayStart, \DateTimeInterface $dayEnd): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.invoice', 'i')->addSelect('i')
            ->leftJoin('i.student', 's')->addSelect('s')
            ->leftJoin('i.term', 't')->addSelect('t')
            ->andWhere('p.status IN (:statuses)')
            ->andWhere('(p.confirmedAt BETWEEN :from AND :to) OR (p.confirmedAt IS NULL AND p.createdAt BETWEEN :from AND :to)')
            ->setParameter('from', $dayStart)
            ->setParameter('to', $dayEnd)
            ->setParameter('statuses', [Payment::STATUS_CONFIRMED, Payment::STATUS_PENDING])
            ->orderBy('p.confirmedAt', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The cash book for one day: confirmed totals by method, plus a pending block.
     *
     * @return array{confirmed: array<string, float>, pending: array<string, float>, confirmedTotal: float, pendingTotal: float}
     */
    public function dailyByMethod(\DateTimeInterface $dayStart, \DateTimeInterface $dayEnd): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.method AS method, p.status AS status, SUM(p.amount) AS total')
            ->andWhere('(p.confirmedAt BETWEEN :from AND :to) OR (p.confirmedAt IS NULL AND p.createdAt BETWEEN :from AND :to)')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('from', $dayStart)
            ->setParameter('to', $dayEnd)
            ->setParameter('statuses', [Payment::STATUS_CONFIRMED, Payment::STATUS_PENDING])
            ->groupBy('p.method')
            ->addGroupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $confirmed = [];
        $pending = [];
        foreach ($rows as $row) {
            if ($row['status'] === Payment::STATUS_CONFIRMED) {
                $confirmed[(string) $row['method']] = (float) $row['total'];
            } else {
                $pending[(string) $row['method']] = (float) $row['total'];
            }
        }

        return [
            'confirmed'      => $confirmed,
            'pending'        => $pending,
            'confirmedTotal' => array_sum($confirmed),
            'pendingTotal'   => array_sum($pending),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  R5 payment register / R8 reconciliation filters
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array{from?: ?\DateTimeInterface, to?: ?\DateTimeInterface, classroom?: ?Classroom, q?: string, method?: string, status?: string, type?: string, cashier?: string} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $qb->andWhere('(p.confirmedAt BETWEEN :from AND :to) OR (p.confirmedAt IS NULL AND p.createdAt BETWEEN :from AND :to)')
               ->setParameter('from', $filters['from'])
               ->setParameter('to', $filters['to']);
        }
        if (!empty($filters['classroom'])) {
            $qb->andWhere('i.classroom = :classroom')->setParameter('classroom', $filters['classroom']);
        }
        if (!empty($filters['method'])) {
            $qb->andWhere('p.method = :method')->setParameter('method', $filters['method']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('i.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['cashier'])) {
            $qb->andWhere('p.confirmedBy = :cashier')->setParameter('cashier', $filters['cashier']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('s.firstName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q
                        OR i.buyerName LIKE :q OR i.invoiceNumber LIKE :q OR p.referenceCode LIKE :q')
               ->setParameter('q', '%' . $filters['q'] . '%');
        }
    }

    /**
     * Paginated payment register.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: Payment[], total: int, page: int, pages: int, perPage: int}
     */
    public function register(array $filters, int $page = 1, int $perPage = 50): array
    {
        $base = $this->createQueryBuilder('p')
            ->leftJoin('p.invoice', 'i')
            ->leftJoin('i.student', 's')
            ->leftJoin('i.classroom', 'c')
            ->leftJoin('i.term', 't');

        $this->applyFilters($base, $filters);

        $countQb = clone $base;
        $total = (int) $countQb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $base
            ->select('p', 'i', 's', 'c', 't')
            ->orderBy('p.confirmedAt', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /**
     * Confirmed/pending totals under the current register filters.
     *
     * @param array<string, mixed> $filters
     * @return array{confirmed: float, pending: float, byMethod: array<string, float>}
     */
    public function registerTotals(array $filters): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.invoice', 'i')
            ->leftJoin('i.student', 's')
            ->select('p.method AS method, p.status AS status, SUM(p.amount) AS total')
            ->groupBy('p.method')
            ->addGroupBy('p.status');

        $this->applyFilters($qb, $filters);

        $confirmed = 0.0;
        $pending = 0.0;
        $byMethod = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $amount = (float) $row['total'];
            if ($row['status'] === Payment::STATUS_CONFIRMED) {
                $confirmed += $amount;
                $method = (string) $row['method'];
                $byMethod[$method] = ($byMethod[$method] ?? 0.0) + $amount;
            } elseif ($row['status'] === Payment::STATUS_PENDING) {
                $pending += $amount;
            }
        }

        return ['confirmed' => $confirmed, 'pending' => $pending, 'byMethod' => $byMethod];
    }

    /**
     * R8 — every method × status combination for a term.
     *
     * @return array<int, array{method: string, status: string, entries: int, total: float}>
     */
    public function methodBreakdownForTerm(Term $term, bool $includeStore = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.invoice', 'i')
            ->select('p.method AS method, p.status AS status, COUNT(p.id) AS entries, SUM(p.amount) AS total')
            ->andWhere('i.term = :term')
            ->setParameter('term', $term)
            ->groupBy('p.method')
            ->addGroupBy('p.status')
            ->orderBy('p.method', 'ASC');

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        return array_map(static fn (array $row): array => [
            'method' => (string) $row['method'],
            'status' => (string) $row['status'],
            'entries' => (int) $row['entries'],
            'total' => (float) $row['total'],
        ], $qb->getQuery()->getArrayResult());
    }

    /**
     * Pending proofs older than the cutoff — the queue that causes arguments.
     *
     * @return Payment[]
     */
    public function stalePendingForTerm(Term $term, \DateTimeInterface $cutoff, bool $includeStore = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.invoice', 'i')->addSelect('i')
            ->leftJoin('i.student', 's')->addSelect('s')
            ->andWhere('i.term = :term')
            ->andWhere('p.status = :status')
            ->andWhere('p.createdAt < :cutoff')
            ->setParameter('term', $term)
            ->setParameter('status', Payment::STATUS_PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('p.createdAt', 'ASC');

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        return $qb->getQuery()->getResult();
    }
}
