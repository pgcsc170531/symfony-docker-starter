<?php

namespace App\Service\Fees;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * One pupil's Statement of Account, on A4, for a parent or a bursar to print.
 *
 * Same paper, same masthead, same verification footer as the Term Fee Summary
 * (`.github/skills/pdf-documents`). The figures are never recalculated here:
 * they come from `FeeReportService::statement()`, which is the only place a
 * balance is worked out.
 *
 * The verification code is per pupil *and* per period (`STMT-{pupil}-{term}`),
 * so a statement already filed with a bank keeps verifying after a reprint,
 * while last term's statement cannot be passed off as this term's.
 */
final class StudentStatementPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly FeeReportVerificationService $verifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $statement  output of FeeReportService::statement()
     * @return array<string, mixed>
     */
    public function context(
        Student $student,
        School $school,
        array $statement,
        ?Term $term,
        ?Guardian $guardian,
        ?string $subdomain = null,
    ): array {
        $period = $term !== null
            ? trim(($term->getSession()?->getName() ?? '') . ' · ' . ($term->getName() ?? ''), ' ·')
            : 'All terms to date';

        $reference = sprintf('STMT-%d-%s', $student->getId() ?? 0, $term?->getId() ?? 'ALL');
        $code = $this->verifications->codeFor($reference, new \DateTimeImmutable(), $period, 'fee-statement');

        $url = $this->urls->generate(
            'tenant_fee_report_verify',
            array_filter([
                'code' => $code,
                'subdomain' => $subdomain,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return [
            'school'       => $school,
            'logo'         => $this->pdf->uploadedImageDataUri($school->getLogoFilename()),
            'student'      => $student,
            'guardian'     => $guardian,
            'term'         => $term,
            'period'       => $period,
            'classroom'    => $statement['classroom'] ?? ($student->getCurrentClassroom()?->getName()),
            'lines'        => $statement['lines'] ?? [],
            'totals'       => $statement['totals'] ?? ['net' => 0.0, 'paid' => 0.0, 'pending' => 0.0, 'outstanding' => 0.0],
            'generated_at' => new \DateTimeImmutable(),
            'verification' => [
                'code' => $code,
                'url'  => $url,
                'qr'   => $this->qr->dataUri($url),
            ],
        ];
    }

    /** @param array<string, mixed> $context */
    public function render(array $context): string
    {
        return $this->pdf->render('tenant/report/fees/statement.pdf.twig', $context);
    }
}
