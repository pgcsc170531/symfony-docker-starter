<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\Expense;
use App\Entity\Tenant\ExpenseCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * R11 — the expense register.
 *
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * @param array{from?: ?\DateTimeInterface, to?: ?\DateTimeInterface, category?: ?ExpenseCategory, q?: string, min?: ?float} $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['from'])) {
            $qb->andWhere('e.expenseDate >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('e.expenseDate <= :to')->setParameter('to', $filters['to']);
        }
        if (!empty($filters['category'])) {
            $qb->andWhere('e.category = :category')->setParameter('category', $filters['category']);
        }
        if (isset($filters['min']) && $filters['min'] !== null && $filters['min'] !== '') {
            $qb->andWhere('e.amount >= :min')->setParameter('min', (float) $filters['min']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('e.title LIKE :q OR e.note LIKE :q OR e.payee LIKE :q')
               ->setParameter('q', '%' . $filters['q'] . '%');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: Expense[], total: int, page: int, pages: int, perPage: int}
     */
    public function register(array $filters, int $page = 1, int $perPage = 50): array
    {
        $base = $this->createQueryBuilder('e')
            ->leftJoin('e.category', 'c');

        $this->applyFilters($base, $filters);

        $countQb = clone $base;
        $total = (int) $countQb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $base
            ->select('e', 'c')
            ->orderBy('e.expenseDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{total: float, entries: int, byCategory: array<string, float>}
     */
    public function totals(array $filters): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.category', 'c')
            ->select('c.name AS category, COUNT(e.id) AS entries, SUM(e.amount) AS total')
            ->groupBy('c.id')
            ->addGroupBy('c.name')
            ->orderBy('SUM(e.amount)', 'DESC');

        $this->applyFilters($qb, $filters);

        $byCategory = [];
        $total = 0.0;
        $entries = 0;

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $amount = (float) $row['total'];
            $byCategory[(string) $row['category']] = $amount;
            $total += $amount;
            $entries += (int) $row['entries'];
        }

        return ['total' => $total, 'entries' => $entries, 'byCategory' => $byCategory];
    }
}
