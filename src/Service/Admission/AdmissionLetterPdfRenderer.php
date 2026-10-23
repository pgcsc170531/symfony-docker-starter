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
        $entryInvoice = $this->em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->leftJoin('i.term', 't')->addSelect('t')
            ->leftJoin('i.classroom', 'ic')->addSelect('ic')
            ->where('i.student = :student')
            ->setParameter('student', $student)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $entryTerm = $entryInvoice?->getTerm();

        $session = $entry?->getSession() ?? $entryTerm?->getSession();

        /*
         * "Placed in" and the fee breakdown must name the SAME class — the class
         * the child was admitted into. The admission invoice is the record of what
         * the school actually billed for that class, so it is the first source; a
         * pupil who has since been promoted must not be quoted next year's fees on
         * a letter about admission.
         */
        $admittedClassroom = $entryInvoice?->getClassroom()
            ?? $entry?->getClassroom()
            ?? $student->getCurrentClassroom()
            ?? $student->getCurrentClass();

        $placedIn = $admittedClassroom?->getName()
            ?? $student->getCurrentClassroom()?->getName()
            ?? $student->getCurrentClass()?->getName();

        $admittedOn = $entry?->getEnrolledAt() ?? $student->getCreatedAt() ?? new \DateTimeImmutable();

        /*
         * The letter says "find attached the breakdown of school fees, rules and
         * regulations" — so it has to actually carry them.
         *
         * A new intake pays two things, and the school's own fee matrix says so:
         *   A. what EVERY pupil of the class pays this term (TERM, plus SESSION
         *      items in the first term), and
         *   B. the one-off items only a new pupil pays (ONETIME: uniform, books,
         *      ID card…).
         * A + B is "the exact fee structure" for this child.
         *
         * One one-off is deliberately NOT quoted: the admission / form fee. The
         * family already paid it with the application, and printing it here would
         * ask them for it twice. It is shown as a note instead, so the arithmetic on
         * the page still adds up.
         */
        $classroom = $admittedClassroom;
        $feeTerm = $entryTerm ?? $this->em->getRepository(Term::class)->findOneBy(['isActive' => true]);

        $recurringFees = [];
        $newIntakeFees = [];
        $alreadyPaid = [];

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
                $item = $row->getFeeItem();
                if ($item === null || $item->isOptional()) {
                    continue;
                }

                $line = [
                    'name' => $item->getName() ?? 'Fee',
                    'amount' => (float) ($row->getAmount() ?? 0),
                ];

                if ($item->getFrequency() !== 'ONETIME') {
                    $recurringFees[] = $line;
                } elseif ($this->isApplicationFormFee($item->getName())) {
                    // Paid at application — never re-billed on the letter.
                    $alreadyPaid[] = $line;
                } else {
                    $newIntakeFees[] = $line;
                }
            }
        }

        $fees = array_merge($recurringFees, $newIntakeFees);

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
            'fees_recurring' => $recurringFees,
            'fees_new_intake' => $newIntakeFees,
            'fees_already_paid' => $alreadyPaid,
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

    /**
     * Is this one-off item the admission / application form fee?
     *
     * That fee is paid with the application, before the child is admitted, so a
     * letter that quotes it again asks the family to pay twice. Matched on the name
     * because a school is free to call the item whatever it likes — the seeded
     * default is "Admission / Form Fee", and schools commonly write "Application
     * Fee" or "Form Fee".
     */
    private function isApplicationFormFee(?string $name): bool
    {
        return $name !== null
            && preg_match('/admission|application|form\s*fee/i', $name) === 1;
    }
}
