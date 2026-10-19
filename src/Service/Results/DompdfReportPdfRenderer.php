<?php

namespace App\Service\Results;

use App\Entity\Tenant\Student;
use App\Entity\Tenant\Term;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Dompdf implementation of the report-card renderer.
 *
 * The PDF deliberately does **not** reuse the screen template: the screen uses
 * Tailwind utilities with flex/grid, which Dompdf cannot lay out. The print
 * template is plain CSS and tables, and it can be rendered with no network access
 * (Dompdf ships DejaVu Sans/Serif, and the logo is embedded as a data URI).
 *
 * Paper, fonts and page geometry come from {@see A4PdfRenderer} — the report card
 * must be the same physical size as every other document the school prints.
 */
final class DompdfReportPdfRenderer implements ReportPdfRendererInterface
{
    public function __construct(
        private A4PdfRenderer $pdf,
        private ReportVerificationService $verifications,
        private QrCodeRenderer $qr,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function render(array $sheet): string
    {
        $school = $sheet['school'] ?? null;
        $verification = $this->verification($sheet['student'] ?? null, $sheet['term'] ?? null);

        return $this->pdf->render('tenant/reports/pdf/card.pdf.twig', [
            'sheet' => $sheet,
            'term' => $sheet['term'] ?? null,
            'logo' => $this->pdf->uploadedImageDataUri($school?->getLogoFilename()),
            'verification' => $verification,
        ]);
    }

    /**
     * @return array{code: string, url: string, qr: string}|null
     */
    private function verification(?Student $student, ?Term $term): ?array
    {
        if (!$student || !$term) {
            return null;
        }

        $code = $this->verifications->codeFor($student, $term);
        $url = $this->urls->generate(
            'app_tenant_verify',
            ['code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return ['code' => $code, 'url' => $url, 'qr' => $this->qr->dataUri($url)];
    }
}
