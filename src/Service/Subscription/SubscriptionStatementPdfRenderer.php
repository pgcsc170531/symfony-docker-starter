<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\School as BillingSchool;
use App\Entity\Tenant\School as TenantSchool;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The Value & Billing statement: what the school generated, what it owes, what
 * it paid, and when it renews — one A4 sheet a bursar can hand to a board.
 *
 * Layout and paper come from the shared document engine
 * ({@see A4PdfRenderer}), so this is the same physical page as a report card,
 * a transcript or a performance report. The template is deliberately plain
 * CSS/tables: Dompdf has no flexbox or grid, and this is a document, not a screen.
 *
 * The footer carries a verification code and a QR that resolves to a public page
 * confirming the statement is genuine — without disclosing the school's amounts.
 */
final class SubscriptionStatementPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly StatementVerificationService $verifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $data          scalar figures prepared by the controller
     * @param BillingSchool|null   $billingSchool the landlord-side school row
     *                                            (owner of the statement)
     * @param TenantSchool|null    $tenantSchool  used only for the letterhead
     *                                            fallback
     */
    public function render(
        array $data,
        ?BillingSchool $billingSchool = null,
        ?TenantSchool $tenantSchool = null,
    ): string {
        return $this->pdf->render(
            'tenant/subscription/statement.pdf.twig',
            $this->context($data, $billingSchool, $tenantSchool)
        );
    }

    /**
     * The complete template context, including the letterhead logo and the
     * verification code.
     *
     * Built in one place so the printed document can never drift from what the
     * template renders — an earlier version assembled the context twice and the
     * second copy silently lost the QR code.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function context(
        array $data,
        ?BillingSchool $billingSchool = null,
        ?TenantSchool $tenantSchool = null,
    ): array {
        $data['logo'] = $this->pdf->uploadedImageDataUri($tenantSchool?->getLogoFilename());
        $data['verification'] = $this->verification($billingSchool, $data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{code: string, url: string, qr: string}|null
     */
    private function verification(?BillingSchool $school, array $data): ?array
    {
        if (!$school instanceof BillingSchool) {
            return null;
        }

        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return null;
        }

        $issuedAt = $data['generated_at'] ?? new \DateTimeImmutable();
        if (!$issuedAt instanceof \DateTimeImmutable) {
            $issuedAt = new \DateTimeImmutable();
        }

        $code = $this->verifications->codeFor(
            $school,
            $reference,
            $issuedAt,
            isset($data['period']) ? mb_substr((string) $data['period'], 0, 40) : null
        );

        $url = $this->urls->generate(
            'tenant_statement_verify',
            ['code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return ['code' => $code, 'url' => $url, 'qr' => $this->qr->dataUri($url)];
    }
}
