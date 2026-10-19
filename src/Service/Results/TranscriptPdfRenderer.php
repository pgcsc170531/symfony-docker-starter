<?php

namespace App\Service\Results;

use App\Service\Pdf\A4PdfRenderer;

/**
 * A session transcript: every term, every subject, one printable document.
 *
 * Paper and fonts come from {@see A4PdfRenderer} — a transcript is as much a
 * document as a report card, and the two must print at the same physical size.
 */
final class TranscriptPdfRenderer
{
    public function __construct(private A4PdfRenderer $pdf)
    {
    }

    public function render(array $sheet): string
    {
        $school = $sheet['school'] ?? null;

        return $this->pdf->render('tenant/reports/pdf/transcript.pdf.twig', [
            'sheet' => $sheet,
            'term' => $sheet['term'] ?? null,
            'logo' => $this->pdf->uploadedImageDataUri($school?->getLogoFilename()),
        ]);
    }
}
