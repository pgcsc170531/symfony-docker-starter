<?php

namespace App\Service\Results;

use App\Entity\Tenant\School;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\YearGroup;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;

/**
 * The shareable whole-school performance report.
 *
 * The screen is for navigating; this PDF is the document a proprietor forwards
 * to a parent association, an inspector, a bank or an accreditation body. It is
 * deliberately plain CSS and tables because Dompdf cannot lay out Tailwind.
 *
 * Paper and fonts come from {@see A4PdfRenderer}, so this sheet is physically
 * identical to a report card or a billing statement.
 */
final class SchoolPerformancePdfRenderer
{
    public function __construct(
        private A4PdfRenderer $pdf,
        private QrCodeRenderer $qr,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        array $data,
        Term $term,
        ?YearGroup $yearGroup = null,
        ?School $school = null,
        ?string $verifyUrl = null,
        ?string $verifyCode = null,
    ): string {
        $verification = null;
        if ($verifyUrl) {
            if (!$verifyCode) {
                parse_str((string) parse_url($verifyUrl, PHP_URL_QUERY), $query);
                $hash = (string) ($query['_hash'] ?? hash('sha256', $verifyUrl));
                $code = strtoupper(substr($hash, 0, 8));
                $verifyCode = substr($code, 0, 4) . '-' . substr($code, 4, 4);
            }

            $verification = [
                'url' => $verifyUrl,
                'code' => $verifyCode,
                'qr' => $this->qr->dataUri($verifyUrl),
            ];
        }

        return $this->pdf->render('tenant/reports/pdf/performance.pdf.twig', [
            'data' => $data,
            'term' => $term,
            'yearGroup' => $yearGroup,
            'school' => $school,
            'logo' => $this->pdf->uploadedImageDataUri($school?->getLogoFilename()),
            'verification' => $verification,
        ]);
    }
}
