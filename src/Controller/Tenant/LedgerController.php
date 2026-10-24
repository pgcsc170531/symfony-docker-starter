<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\LedgerLine;
use App\Entity\Tenant\School;
use App\Service\Payment\LedgerPoster;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the school sees of online money: **its own money, and nothing else.**
 *
 * Deliberate omission: the parent's online-payment charge and Paystack's fee are
 * **not** shown here. The charge is the payer's contribution to the bank and the
 * API — it is not part of the school's fee, it is not deducted from it, and
 * displaying it invites "why am I being charged?" when the school is charged
 * nothing. The platform's revenue and cost lines (`4100`, `5000`) stay on the
 * platform's side of the books.
 *
 * This grows to a term's worth of payments, so it is **filtered and paged on the
 * server** (`from`, `to`, `q`, `page`) and the rows are also served as JSON, so the
 * page can refresh them without a reload. Both paths use the same query and the
 * same template fragment — there is no second copy of the row markup to drift.
 */
#[Route('/finance/online-payments')]
#[IsGranted('ROLE_BURSAR')]
class LedgerController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('', name: 'app_tenant_ledger_index', methods: ['GET'])]
    public function index(Request $request, LedgerPoster $ledger, EntityManagerInterface $em): Response
    {
        $filters = $this->filters($request);
        $lines = $this->fetch($em, $filters);
        $total = $this->count($em, $filters);
        [$collected, $paidOut] = $this->totals($em, $filters);

        return $this->render('tenant/finance/ledger.html.twig', [
            'lines' => $lines,
            'filters' => $filters,
            'total' => $total,
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'perPage' => self::PER_PAGE,
            'collectedMinor' => $collected,
            'paidOutMinor' => $paidOut,
            'heldMinor' => $collected - $paidOut,
            'payable' => $ledger->schoolPayable(),
            // Where the money actually goes. A bursar reconciling the bank
            // statement needs the account named on the screen, not "your bank".
            'school' => $em->getRepository(School::class)->findOneBy([]),
        ]);
    }

    /**
     * The same rows as JSON, including the rendered fragment — so the page can
     * filter and page without a reload, and without duplicating markup in JS.
     */
    #[Route('/rows', name: 'app_tenant_ledger_rows', methods: ['GET'])]
    public function rows(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $filters = $this->filters($request);
        $lines = $this->fetch($em, $filters);
        $total = $this->count($em, $filters);
        [$collected, $paidOut] = $this->totals($em, $filters);

        return $this->json([
            'html' => $this->renderView('tenant/finance/_statement_rows.html.twig', [
                'lines' => $lines,
                'school' => $em->getRepository(School::class)->findOneBy([]),
                // So the empty state can say "widen the date range" when a filter
                // is what emptied it, instead of the first-time message.
                'filters' => $filters,
            ]),
            'total' => $total,
            'page' => $filters['page'],
            'pageCount' => max(1, (int) ceil($total / self::PER_PAGE)),
            'collected' => number_format($collected / 100, 2),
            'paidOut' => number_format($paidOut / 100, 2),
            'held' => number_format(($collected - $paidOut) / 100, 2),
        ]);
    }

    /** @return array{q: string, from: ?string, to: ?string, page: int} */
    private function filters(Request $request): array
    {
        $from = trim((string) $request->query->get('from', ''));
        $to = trim((string) $request->query->get('to', ''));

        return [
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 60),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : null,
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : null,
            'page' => max(1, (int) $request->query->get('page', 1)),
        ];
    }

    /** @param array{q: string, from: ?string, to: ?string, page: int} $filters */
    private function fetch(EntityManagerInterface $em, array $filters): array
    {
        return $this->baseQuery($em, $filters)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($filters['page'] - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();
    }

    /** @param array{q: string, from: ?string, to: ?string, page: int} $filters */
    private function count(EntityManagerInterface $em, array $filters): int
    {
        return (int) $this->baseQuery($em, $filters)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array{q: string, from: ?string, to: ?string, page: int} $filters
     *
     * @return array{0: int, 1: int} collected and paid out, in kobo
     */
    private function totals(EntityManagerInterface $em, array $filters): array
    {
        $rows = $this->baseQuery($em, $filters)
            ->select('l.direction AS dir', 'SUM(l.amountMinor) AS total')
            ->groupBy('l.direction')
            ->getQuery()
            ->getArrayResult();

        $collected = 0;
        $paidOut = 0;

        foreach ($rows as $row) {
            if (($row['dir'] ?? '') === LedgerLine::CREDIT) {
                $collected = (int) $row['total'];
            } else {
                $paidOut = (int) $row['total'];
            }
        }

        return [$collected, $paidOut];
    }

    /** @param array{q: string, from: ?string, to: ?string, page: int} $filters */
    private function baseQuery(EntityManagerInterface $em, array $filters): QueryBuilder
    {
        $qb = $em->createQueryBuilder()
            ->from(LedgerLine::class, 'l')
            ->join('l.entry', 'e')
            ->select('l', 'e')
            ->andWhere('l.accountCode = :code')
            ->setParameter('code', LedgerPoster::SCHOOL_PAYABLE);

        if ($filters['from'] !== null) {
            $qb->andWhere('e.occurredAt >= :from')
                ->setParameter('from', new \DateTimeImmutable($filters['from'] . ' 00:00:00'));
        }

        if ($filters['to'] !== null) {
            $qb->andWhere('e.occurredAt <= :to')
                ->setParameter('to', new \DateTimeImmutable($filters['to'] . ' 23:59:59'));
        }

        if ($filters['q'] !== '') {
            // Matches an invoice number, a payout reference or the memo — the three
            // things a bursar actually types when chasing a payment.
            $qb->andWhere('e.memo LIKE :q OR e.sourceRef LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }

        return $qb;
    }
}
