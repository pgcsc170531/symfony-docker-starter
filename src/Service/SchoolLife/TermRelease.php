<?php

namespace App\Service\SchoolLife;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One lock for the whole term's record.
 *
 * Attendance and behaviour do **not** get their own locking rules. They freeze at
 * the same moment the results do: when every subject taught in the class has been
 * final-approved by the year-group master. One concept, one place to explain it, and
 * a parent cannot receive a card whose figures change underneath it.
 *
 * A class with no subjects, or with a subject still awaiting approval, is **not**
 * locked — so an unfinished term never locks by accident.
 */
final class TermRelease
{
    private const APPROVED = 'approved';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function isReleased(Classroom $classroom, Term $term): bool
    {
        $subjects = $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classroom]);
        if (!$subjects) {
            return false;
        }

        $approved = (int) $this->em->getRepository(ResultApproval::class)
            ->createQueryBuilder('ra')
            ->select('COUNT(ra.id)')
            ->andWhere('ra.classSubject IN (:subjects)')
            ->andWhere('ra.term = :term')
            ->andWhere('ra.yearGroupStatus = :approved')
            ->setParameter('subjects', $subjects)
            ->setParameter('term', $term)
            ->setParameter('approved', self::APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $approved >= count($subjects);
    }

    /** How many of the class's subjects are still awaiting final approval. */
    public function outstandingSubjects(Classroom $classroom, Term $term): int
    {
        $subjects = $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classroom]);
        if (!$subjects) {
            return 0;
        }

        $approved = (int) $this->em->getRepository(ResultApproval::class)
            ->createQueryBuilder('ra')
            ->select('COUNT(ra.id)')
            ->andWhere('ra.classSubject IN (:subjects)')
            ->andWhere('ra.term = :term')
            ->andWhere('ra.yearGroupStatus = :approved')
            ->setParameter('subjects', $subjects)
            ->setParameter('term', $term)
            ->setParameter('approved', self::APPROVED)
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, count($subjects) - $approved);
    }
}
