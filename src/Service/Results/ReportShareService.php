<?php

namespace App\Service\Results;

use App\Entity\Tenant\ReportShareToken;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and resolves the signed, expiring links a parent sends on WhatsApp.
 *
 * The token is 32 random bytes, returned once and never persisted in clear. Only
 * its SHA-256 hash is stored, so the database cannot be used to reconstruct a
 * working link. See docs/results/RESULT-CARD-DESIGN.md §6 and §9 item 3.
 */
final class ReportShareService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{token: string, share: ReportShareToken}
     */
    public function create(Student $student, Term $term, ?User $user = null, ?int $lifetimeDays = null): array
    {
        $token = bin2hex(random_bytes(32));

        $share = (new ReportShareToken())
            ->setTokenHash(hash('sha256', $token))
            ->setStudent($student)
            ->setTerm($term)
            ->setCreatedBy($user?->getUserIdentifier());

        if ($lifetimeDays !== null && $lifetimeDays > 0) {
            $share->setExpiresAt((new \DateTimeImmutable())->modify('+' . $lifetimeDays . ' days'));
        }

        $this->em->persist($share);
        $this->em->flush();

        return ['token' => $token, 'share' => $share];
    }

    public function resolve(string $token): ?ReportShareToken
    {
        if ($token === '' || !ctype_xdigit($token)) {
            return null;
        }

        return $this->em->getRepository(ReportShareToken::class)->findOneBy([
            'tokenHash' => hash('sha256', $token),
        ]);
    }

    /** The unexpired, unrevoked links for one pupil and term, newest first. */
    public function activeFor(Student $student, Term $term): array
    {
        return $this->em->getRepository(ReportShareToken::class)
            ->createQueryBuilder('s')
            ->andWhere('s.student = :student')
            ->andWhere('s.term = :term')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('student', $student)
            ->setParameter('term', $term)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function revoke(ReportShareToken $share): void
    {
        $share->revoke();
        $this->em->flush();
    }
}
