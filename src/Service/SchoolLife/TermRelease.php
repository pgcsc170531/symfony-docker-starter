<?php

namespace App\Service\SchoolLife;

use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\ResultApproval;
use App\Entity\Tenant\Term;
use App\Service\Subjects\PupilSubjects;
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

    public function __construct(
        private EntityManagerInterface $em,
        private PupilSubjects $pupils,
    ) {
    }

    public function isReleased(Classroom $classroom, Term $term): bool
    {
        $subjects = $this->subjectsWithTakers($classroom);
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
        $subjects = $this->subjectsWithTakers($classroom);
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

    /**
     * The class's subjects that somebody actually sits.
     *
     * A subject every pupil has been exempted from is not a sheet waiting on a
     * teacher, and must not hold the term open: the class subject stays on the
     * books (it is part of the arm's catalogue) but it is nobody's work.
     *
     * @return list<ClassSubject>
     */
    private function subjectsWithTakers(Classroom $classroom): array
    {
        $subjects = $this->em->getRepository(ClassSubject::class)->findBy(['classroom' => $classroom]);
        $roster = $this->pupils->roster($classroom);

        return array_values(array_filter(
            $subjects,
            fn (ClassSubject $classSubject): bool => $this->pupils->takerIds($classSubject, $roster) !== []
        ));
    }
}
