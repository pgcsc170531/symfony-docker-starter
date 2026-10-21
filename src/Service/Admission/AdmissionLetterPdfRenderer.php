<?php

namespace App\Service\Admission;

use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\School;
use App\Entity\Tenant\SchoolDocument;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Fees\FeeReportVerificationService;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The provisional admission letter, as an A4 PDF.
 *
 * It was a browser print view until now, which meant the school's letter came out
 * of Chrome, Safari and Android looking like three different documents — different
 * margins, different font, sometimes two pages. This renderer puts it on the same
 * paper as every other Edus document (.github/skills/pdf-documents): A4 portrait,
 * DejaVu fonts embedded, a verification code and QR in the footer.
 *
 * The academic facts are read from the pupil's FIRST enrolment — the record that
 * never moves — so a letter reprinted in three years still says the session, term
 * and class the child was actually admitted into.
 */
final class AdmissionLetterPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly FeeReportVerificationService $verifications,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed> scalars, entities and data URIs only
     */
    public function context(Student $student, School $school, ?string $subdomain = null): array
    {
        $entry = $this->em->getRepository(Enrollment::class)->findOneBy(
            ['student' => $student],
            ['id' => 'ASC']
        );

        // The term of entry is the term the pupil was first billed for; the
        // enrolment itself carries only the session.
        $entryTerm = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->leftJoin('i.term', 't')->addSelect('t')
            ->where('i.student = :student')
            ->setParameter('student', $student)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()?->getTerm();

        $session = $entry?->getSession() ?? $entryTerm?->getSession();

        $placedIn = $entry?->getClassroom()?->getName()
            ?? $student->getCurrentClassroom()?->getName()
            ?? $student->getCurrentClass()?->getName();

        $admittedOn = $entry?->getEnrolledAt() ?? $student->getCreatedAt() ?? new \DateTimeImmutable();

        /*
         * The letter says "find attached the breakdown of school fees, rules and
         * regulations" — so it has to actually carry them. The fees come from the
         * fee structure for the class the child was admitted into; the rules come
         * from the school's own documents, which the same text also publishes on
         * the website. Nothing is typed twice.
         */
        $classroom = $entry?->getClassroom() ?? $student->getCurrentClassroom() ?? $student->getCurrentClass();
        $feeTerm = $entryTerm ?? $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        $fees = [];
        if ($classroom !== null && $feeTerm !== null) {
            $rows = $this->em->getRepository(FeeStructure::class)->createQueryBuilder('f')
                ->leftJoin('f.feeItem', 'fi')->addSelect('fi')
                ->where('f.classroom = :classroom AND f.term = :term')
                ->setParameter('classroom', $classroom)
                ->setParameter('term', $feeTerm)
                ->orderBy('fi.position', 'ASC')
                ->addOrderBy('fi.name', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($rows as $row) {
                $fees[] = [
                    'name' => $row->getFeeItem()?->getName() ?? 'Fee',
                    'amount' => (float) ($row->getAmount() ?? 0),
                ];
            }
        }

        $appendices = $this->em->getRepository(SchoolDocument::class)->findBy(
            ['isPublished' => true, 'attachToAdmissionLetter' => true],
            ['sortOrder' => 'ASC', 'id' => 'ASC']
        );

        // One code per pupil, reused on every reprint so a copy already filed by
        // the family keeps verifying.
        $code = $this->verifications->codeFor(
            sprintf('ADM-%d', (int) $student->getId()),
            new \DateTimeImmutable(),
            $session?->getName(),
            'admission-letter'
        );

        $verifyUrl = $this->urls->generate(
            'tenant_fee_report_verify',
            array_filter([
                'code' => $code,
                'subdomain' => $subdomain,
            ], static fn ($value): bool => $value !== null && $value !== ''),
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return [
            'school' => $school,
            'logo' => $this->pdf->uploadedImageDataUri($school->getLogoFilename()),
            'signature' => $this->pdf->uploadedImageDataUri($school->getPrincipalSignatureFilename(), 'uploads/signatures'),
            'student' => $student,
            'guardian' => $student->getGuardian(),
            'placed_in' => $placedIn,
            'session_name' => $session?->getName(),
            'term_name' => $entryTerm?->getName(),
            'admitted_on' => $admittedOn,
            'reference' => $student->getAdmissionNumber() ?: sprintf('ADM-%d', (int) $student->getId()),
            'fees' => $fees,
            'fee_total' => array_sum(array_column($fees, 'amount')),
            'fee_class' => $placedIn,
            'fee_term' => $feeTerm?->getName(),
            'fee_session' => $feeTerm?->getSession()?->getName() ?? $session?->getName(),
            'appendices' => $appendices,
            'generated_at' => new \DateTimeImmutable(),
            'verification' => [
                'code' => $code,
                'url' => $verifyUrl,
                'qr' => $this->qr->dataUri($verifyUrl),
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    public function render(array $context): string
    {
        return $this->pdf->render('tenant/admission/letter.pdf.twig', $context);
    }
}
