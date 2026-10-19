<?php

namespace App\Service\Fees;

use App\Entity\Tenant\FeeReportVerification;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Allocates and resolves the verification code on a Term Fee Summary.
 *
 * The code is random, not derived from the term, and reused on every reprint so
 * a copy already filed by the school stays valid. Works against the tenant
 * connection, so each school has its own table and its own codes.
 */
final class FeeReportVerificationService
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function codeFor(string $reference, \DateTimeImmutable $issuedAt, ?string $period = null, string $kind = 'fee-report'): string
    {
        $em = $this->doctrine->getManager();
        $repository = $em->getRepository(FeeReportVerification::class);

        $existing = $repository->findOneBy(['reference' => $reference]);
        if ($existing instanceof FeeReportVerification) {
            return $existing->getCode();
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomCode();
            if ($repository->findOneBy(['code' => $code]) !== null) {
                continue;
            }

            $verification = (new FeeReportVerification())
                ->setCode($code)
                ->setReference($reference)
                ->setPeriod($period)
                ->setKind($kind)
                ->setIssuedAt($issuedAt);

            $em->persist($verification);

            try {
                $em->flush();

                return $code;
            } catch (\Throwable) {
                // Unique collision under a race: detach and try another code.
                $em->detach($verification);
            }
        }

        throw new \RuntimeException('Could not allocate a fee-report verification code.');
    }

    public function find(string $code): ?FeeReportVerification
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 12) {
            return null;
        }

        return $this->doctrine->getManager()
            ->getRepository(FeeReportVerification::class)
            ->findOneBy(['code' => $code]);
    }

    private function randomCode(): string
    {
        $alphabet = FeeReportVerification::ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4);
    }
}
