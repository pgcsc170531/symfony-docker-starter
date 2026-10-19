<?php

namespace App\Service\Fees;

use App\Entity\Tenant\School;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Term Fee Summary PDF — R9.
 *
 * One A4 document a proprietor can hand to a board or a bank: the term's
 * expected/collected/outstanding figures, the income statement, the class
 * breakdown and the largest family balances.
 *
 * Context is built once and used by both the PDF and `?format=html`, so the
 * verification QR can never be missing from one of them.
 */
final class FeeReportPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly FeeReportVerificationService $verifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function context(array $data, ?School $school): array
    {
        $data['school'] = $school;
        $data['logo'] = $this->pdf->uploadedImageDataUri($school?->getLogoFilename());

        $reference = 'TERM-' . (string) ($data['term_id'] ?? '0');
        $period = isset($data['period']) ? mb_substr((string) $data['period'], 0, 40) : null;
        $code = $this->verifications->codeFor($reference, new \DateTimeImmutable(), $period);
        $url = $this->urls->generate(
            'tenant_fee_report_verify',
            array_filter([
                'code' => $code,
                'subdomain' => $data['subdomain'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $data['verification'] = [
            'code' => $code,
            'url'  => $url,
            'qr'   => $this->qr->dataUri($url),
        ];

        return $data;
    }

    /** @param array<string, mixed> $context */
    public function render(array $context): string
    {
        return $this->pdf->render('tenant/report/fees/summary.pdf.twig', $context);
    }
}
