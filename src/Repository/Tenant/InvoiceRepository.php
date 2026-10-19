<?php

namespace App\Repository\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fee aggregates for the reporting section.
 *
 * `total_amount` is NET of `discount_applied` (see Invoice::getNetBillable()).
 * Gross is `total_amount + discount_applied`; do not add the discount again.
 *
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** @return Invoice[] */
    public function forTerm(Term $term, ?Classroom $classroom = null, bool $includeStore = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.student', 's')->addSelect('s')
            ->leftJoin('i.classroom', 'c')->addSelect('c')
            ->andWhere('i.term = :term')
            ->setParameter('term', $term)
            ->orderBy('i.createdAt', 'DESC');

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }
        if ($classroom !== null) {
            $qb->andWhere('i.classroom = :classroom')->setParameter('classroom', $classroom);
        }

        return $qb->getQuery()->getResult();
    }

    /** Net billable for a term (defaults to academic fees only). */
    public function netBillableForTerm(Term $term, bool $includeStore = false): float
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.totalAmount), 0)')
            ->andWhere('i.term = :term')
            ->setParameter('term', $term);

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    /** Discounts recorded on invoices for a term. */
    public function discountForTerm(Term $term): float
    {
        return (float) $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.discountApplied), 0)')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Confirmed money posted to term invoices.
     *
     * `invoice.paid_amount` is maintained by every payment path and only ever
     * counts confirmed money (PENDING slips are not added), which makes it the
     * cheap, consistent source for a term aggregate.
     */
    public function collectedForTerm(Term $term, bool $includeStore = false): float
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.paidAmount), 0)')
            ->andWhere('i.term = :term')
            ->setParameter('term', $term);

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * One row per guardian for the term.
     *
     * A pupil with no guardian is returned with a null guardian id so the
     * report can show a visible "No guardian assigned" group instead of
     * silently dropping the debt.
     *
     * @return array<int, array{guardianId: ?int, guardian: ?string, phone: ?string, invoiced: float, paid: float, outstanding: float, pupils: int}>
     */
    public function guardianBalancesForTerm(Term $term, ?Classroom $classroom = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select(
                'g.id AS guardianId',
                'g.fullName AS guardian',
                'g.phoneNumber AS phone',
                'SUM(i.totalAmount) AS invoiced',
                'SUM(i.paidAmount) AS paid',
                'SUM(i.totalAmount) - SUM(i.paidAmount) AS outstanding',
                'COUNT(DISTINCT s.id) AS pupils'
            )
            ->join('i.student', 's')
            ->leftJoin('s.guardian', 'g')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->groupBy('g.id')
            ->addGroupBy('g.fullName')
            ->addGroupBy('g.phoneNumber')
            ->orderBy('outstanding', 'DESC');

        if ($classroom !== null) {
            $qb->andWhere('i.classroom = :classroom')->setParameter('classroom', $classroom);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static function (array $row): array {
            $invoiced = (float) $row['invoiced'];
            $paid = (float) $row['paid'];

            return [
                'guardianId'  => $row['guardianId'] !== null ? (int) $row['guardianId'] : null,
                'guardian'    => $row['guardian'],
                'phone'       => $row['phone'],
                'invoiced'    => $invoiced,
                'paid'        => $paid,
                'outstanding' => max(0.0, $invoiced - $paid),
                'pupils'      => (int) $row['pupils'],
            ];
        }, $rows);
    }

    /**
     * Billed vs collected per class for a term.
     *
     * @return array<int, array{classId: ?int, class: ?string, pupils: int, billed: float, collected: float, outstanding: float}>
     */
    public function classCollectionForTerm(Term $term): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select(
                'c.id AS classId',
                'c.name AS class',
                'COUNT(DISTINCT s.id) AS pupils',
                'SUM(i.totalAmount) AS billed',
                'SUM(i.paidAmount) AS collected'
            )
            ->join('i.student', 's')
            ->leftJoin('i.classroom', 'c')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->groupBy('c.id')
            ->addGroupBy('c.name')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $billed = (float) $row['billed'];
            $collected = (float) $row['collected'];

            return [
                'classId'     => $row['classId'] !== null ? (int) $row['classId'] : null,
                'class'       => $row['class'],
                'pupils'      => (int) $row['pupils'],
                'billed'      => $billed,
                'collected'   => $collected,
                'outstanding' => max(0.0, $billed - $collected),
            ];
        }, $rows);
    }

    /**
     * One row per pupil for the term — the expandable rows under a guardian.
     *
     * @return array<int, array{studentId: int, pupil: string, admissionNumber: ?string, class: ?string, guardianId: ?int, guardian: ?string, phone: ?string, invoiced: float, paid: float, outstanding: float}>
     */
    public function childBalancesForTerm(Term $term, ?Classroom $classroom = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select(
                's.id AS studentId',
                "CONCAT(s.firstName, ' ', s.lastName) AS pupil",
                's.admissionNumber AS admissionNumber',
                'c.name AS class',
                'g.id AS guardianId',
                'g.fullName AS guardian',
                'g.phoneNumber AS phone',
                'SUM(i.totalAmount) AS invoiced',
                'SUM(i.paidAmount) AS paid'
            )
            ->join('i.student', 's')
            ->leftJoin('i.classroom', 'c')
            ->leftJoin('s.guardian', 'g')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->groupBy('s.id')
            ->addGroupBy('s.firstName')
            ->addGroupBy('s.lastName')
            ->addGroupBy('s.admissionNumber')
            ->addGroupBy('c.name')
            ->addGroupBy('g.id')
            ->addGroupBy('g.fullName')
            ->addGroupBy('g.phoneNumber')
            ->orderBy('s.firstName', 'ASC');

        if ($classroom !== null) {
            $qb->andWhere('i.classroom = :classroom')->setParameter('classroom', $classroom);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static function (array $row): array {
            $invoiced = (float) $row['invoiced'];
            $paid = (float) $row['paid'];

            return [
                'studentId'      => (int) $row['studentId'],
                'pupil'          => $row['pupil'],
                'admissionNumber' => $row['admissionNumber'],
                'class'          => $row['class'],
                'guardianId'     => $row['guardianId'] !== null ? (int) $row['guardianId'] : null,
                'guardian'       => $row['guardian'],
                'phone'          => $row['phone'],
                'invoiced'       => $invoiced,
                'paid'           => $paid,
                'outstanding'    => max(0.0, $invoiced - $paid),
            ];
        }, $rows);
    }

    /**
     * Every invoice for one pupil, oldest first.
     *
     * @return Invoice[]
     */
    public function forStudent(Student $student, ?Term $term = null, bool $includeStore = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.items', 'it')->addSelect('it')
            ->leftJoin('i.payments', 'p')->addSelect('p')
            ->leftJoin('i.term', 't')->addSelect('t')
            ->andWhere('i.student = :student')
            ->setParameter('student', $student)
            ->orderBy('i.createdAt', 'ASC');

        if (!$includeStore) {
            $qb->andWhere('i.type = :type')->setParameter('type', Invoice::TYPE_ACADEMIC);
        }

        if ($term !== null) {
            $qb->andWhere('i.term = :term')->setParameter('term', $term);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Invoices with a discount recorded, for the R7 report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discountRowsForTerm(Term $term): array
    {
        return $this->createQueryBuilder('i')
            ->select(
                'i.id AS invoiceId',
                'i.invoiceNumber AS invoiceNumber',
                'i.discountApplied AS discount',
                'i.totalAmount AS net',
                'i.createdAt AS createdAt',
                's.id AS studentId',
                's.firstName AS firstName',
                's.lastName AS lastName',
                's.admissionNumber AS admissionNumber',
                'g.fullName AS guardian',
                'g.phoneNumber AS phone',
                'c.name AS class'
            )
            ->join('i.student', 's')
            ->leftJoin('s.guardian', 'g')
            ->leftJoin('i.classroom', 'c')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->andWhere('i.discountApplied > 0')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->orderBy('i.discountApplied', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /** @return int[] */
    public function studentIdsWithInvoiceForTerm(Term $term): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.student) AS studentId')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['studentId'], $rows);
    }

    /**
     * Every enrolled pupil in a class for the term, with their invoice if one exists.
     *
     * Deliberately driven by `enrollment`, not `invoice`, so a pupil the school
     * forgot to bill shows up as NOT BILLED instead of vanishing.
     *
     * @return array<int, array{studentId: int, pupil: string, admissionNumber: ?string, invoiceId: ?int, invoiced: float, paid: float, invoiceStatus: ?string}>
     */
    public function matrixForClass(Term $term, Classroom $classroom): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select(
                's.id AS studentId',
                "CONCAT(s.firstName, ' ', s.lastName) AS pupil",
                's.admissionNumber AS admissionNumber',
                'i.id AS invoiceId',
                'i.totalAmount AS invoiced',
                'i.paidAmount AS paid',
                'i.status AS invoiceStatus'
            )
            ->from(Enrollment::class, 'e')
            ->join('e.student', 's')
            ->leftJoin('s.invoices', 'i', 'WITH', 'i.term = :term AND i.type = :type')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.session = :session')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->setParameter('classroom', $classroom)
            ->setParameter('session', $term->getSession())
            ->orderBy('s.lastName', 'ASC')
            ->addOrderBy('s.firstName', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int) $row['studentId']] = [
                'studentId'      => (int) $row['studentId'],
                'pupil'          => (string) $row['pupil'],
                'admissionNumber' => $row['admissionNumber'],
                'invoiceId'      => $row['invoiceId'] !== null ? (int) $row['invoiceId'] : null,
                'invoiced'       => (float) ($row['invoiced'] ?? 0),
                'paid'           => (float) ($row['paid'] ?? 0),
                'invoiceStatus'  => $row['invoiceStatus'],
            ];
        }

        // An invoice can exist for a pupil with no Enrollment row (manual
        // invoice, mid-session import). They must still appear in the matrix,
        // or the bursar will chase 40 pupils and miss the one who actually owes.
        $invoiceOnly = $this->createQueryBuilder('i')
            ->select(
                's.id AS studentId',
                "CONCAT(s.firstName, ' ', s.lastName) AS pupil",
                's.admissionNumber AS admissionNumber',
                'i.id AS invoiceId',
                'i.totalAmount AS invoiced',
                'i.paidAmount AS paid',
                'i.status AS invoiceStatus'
            )
            ->join('i.student', 's')
            ->andWhere('i.term = :term')
            ->andWhere('i.type = :type')
            ->andWhere('i.classroom = :classroom')
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->setParameter('classroom', $classroom)
            ->getQuery()
            ->getArrayResult();

        foreach ($invoiceOnly as $row) {
            $id = (int) $row['studentId'];
            if (isset($mapped[$id])) {
                continue;
            }
            $mapped[$id] = [
                'studentId'      => $id,
                'pupil'          => (string) $row['pupil'],
                'admissionNumber' => $row['admissionNumber'],
                'invoiceId'      => (int) $row['invoiceId'],
                'invoiced'       => (float) $row['invoiced'],
                'paid'           => (float) $row['paid'],
                'invoiceStatus'  => $row['invoiceStatus'],
            ];
        }

        usort($mapped, static fn (array $a, array $b): int => strcasecmp($a['pupil'], $b['pupil']));

        return array_values($mapped);
    }

    /**
     * Enrolled pupils with no academic invoice for the term.
     *
     * @return Student[]
     */
    public function pupilsWithoutInvoice(Term $term): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('s')
            ->from(Student::class, 's')
            ->andWhere('EXISTS (SELECT e.id FROM App\Entity\Tenant\Enrollment e WHERE e.student = s AND e.session = :session)')
            ->andWhere('NOT EXISTS (SELECT i.id FROM App\Entity\Tenant\Invoice i WHERE i.student = s AND i.term = :term AND i.type = :type)')
            ->setParameter('session', $term->getSession())
            ->setParameter('term', $term)
            ->setParameter('type', Invoice::TYPE_ACADEMIC)
            ->orderBy('s.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
