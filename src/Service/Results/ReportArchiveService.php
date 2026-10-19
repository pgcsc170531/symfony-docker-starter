<?php

namespace App\Service\Results;

use App\Entity\Tenant\ReportArchive;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use Doctrine\ORM\EntityManagerInterface;

/** Records the fingerprint of every document the school produces. */
final class ReportArchiveService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function record(Student $student, Term $term, string $kind, string $pdf, ?string $generatedBy): void
    {
        $archive = $this->em->getRepository(ReportArchive::class)->findOneBy([
            'student' => $student,
            'term' => $term,
            'kind' => $kind,
        ]);

        if (!$archive) {
            $archive = (new ReportArchive())
                ->setStudent($student)
                ->setTerm($term)
                ->setKind($kind);
            $this->em->persist($archive);
        }

        // A re-render replaces the fingerprint but keeps the same archive row.
        // The generated-at timestamp moves too: it records the latest issued copy.
        $archive
            ->setSha256(hash('sha256', $pdf))
            ->setGeneratedBy($generatedBy)
            ->setGeneratedAt(new \DateTimeImmutable());

        $this->em->flush();
    }
}
