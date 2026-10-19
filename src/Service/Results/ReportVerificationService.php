<?php

namespace App\Service\Results;

use App\Entity\Tenant\ReportVerification;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and resolves the short verification code on a report card.
 *
 * One code per pupil per term, created lazily the first time a PDF is produced.
 * The code is random rather than derived, so it cannot be guessed from an
 * admission number or a term id.
 */
final class ReportVerificationService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function codeFor(Student $student, Term $term): string
    {
        $existing = $this->em->getRepository(ReportVerification::class)->findOneBy([
            'student' => $student,
            'term' => $term,
        ]);
        if ($existing) {
            return $existing->getCode();
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomCode();
            if ($this->em->getRepository(ReportVerification::class)->findOneBy(['code' => $code])) {
                continue;
            }

            $verification = (new ReportVerification())
                ->setStudent($student)
                ->setTerm($term)
                ->setCode($code);

            $this->em->persist($verification);
            try {
                $this->em->flush();
                return $code;
            } catch (\Throwable) {
                // Unique collision under a race: try another code.
                $this->em->detach($verification);
            }
        }

        throw new \RuntimeException('Could not allocate a verification code.');
    }

    public function find(string $code): ?ReportVerification
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return $this->em->getRepository(ReportVerification::class)->findOneBy(['code' => $code]);
    }

    private function randomCode(): string
    {
        $alphabet = ReportVerification::ALPHABET;
        $max = strlen($alphabet) - 1;
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }

        return substr($raw, 0, 4) . '-' . substr($raw, 4);
    }
}
