<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\School;
use App\Entity\Landlord\StatementVerification;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Allocates and resolves the verification code on a Value & Billing statement.
 *
 * The code is random, not derived from the school or the month, so it cannot be
 * guessed from a statement number. It is allocated once per school per statement
 * number and reused afterwards, so re-printing a statement does not invalidate
 * the copy already sitting in a bursar's file.
 */
final class StatementVerificationService
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * @param \DateTimeImmutable $issuedAt the issue date of the statement being
     *                                     produced
     * @param string             $kind     statement | invoice | receipt
     */
    public function codeFor(School $school, string $reference, \DateTimeImmutable $issuedAt, ?string $period = null, string $kind = 'statement'): string
    {
        $em = $this->doctrine->getManager('landlord');
        $repository = $em->getRepository(StatementVerification::class);

        $existing = $repository->findOneBy(['school' => $school, 'reference' => $reference]);
        if ($existing instanceof StatementVerification) {
            return $existing->getCode();
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomCode();
            if ($repository->findOneBy(['code' => $code]) !== null) {
                continue;
            }

            $verification = (new StatementVerification())
                ->setSchool($school)
                ->setCode($code)
                ->setReference($reference)
                ->setPeriod($period)
                ->setIssuedAt($issuedAt)
                ->setKind($kind);

            $em->persist($verification);

            try {
                $em->flush();

                return $code;
            } catch (\Throwable) {
                // Unique collision under a race: detach and try another code.
                $em->detach($verification);
            }
        }

        throw new \RuntimeException('Could not allocate a statement verification code.');
    }

    public function find(string $code): ?StatementVerification
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 12) {
            return null;
        }

        return $this->doctrine->getManager('landlord')
            ->getRepository(StatementVerification::class)
            ->findOneBy(['code' => $code]);
    }

    private function randomCode(): string
    {
        $alphabet = StatementVerification::ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4);
    }
}
