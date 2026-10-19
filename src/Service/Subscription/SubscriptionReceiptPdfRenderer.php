<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\School as BillingSchool;
use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Tenant\School as TenantSchool;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The receipt: proof that money arrived, on the same A4 sheet as every other
 * Edus document.
 *
 * It exists because the invoice answers "what do I owe?" and this answers "did
 * I pay?" — the question a bursar is actually asked by a proprietor or an
 * auditor months later. Only an APPROVED payment can produce one.
 */
final class SubscriptionReceiptPdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly StatementVerificationService $verifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function render(SubscriptionPayment $payment, ?TenantSchool $tenantSchool = null): string
    {
        return $this->pdf->render(
            'tenant/subscription/receipt.pdf.twig',
            $this->context($payment, $tenantSchool)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(SubscriptionPayment $payment, ?TenantSchool $tenantSchool = null): array
    {
        $school = $payment->getSchool();
        $registration = $payment->getTermRegistration();

        $reference = (string) $payment->getReference();
        $paidAt = $payment->getVerifiedAt() ?? $payment->getCreatedAt() ?? new \DateTimeImmutable();

        // `getVerifiedAt()` is a mutable DateTime — the verification service and
        // the template both expect an immutable, so normalise rather than cast.
        if (!$paidAt instanceof \DateTimeImmutable) {
            $paidAt = \DateTimeImmutable::createFromInterface($paidAt);
        }

        // How the money arrived, stated plainly: a gateway reference means the
        // provider took it, otherwise a human checked the uploaded transfer slip.
        $method = $payment->getGatewayReference()
            ? 'Paid online (card, transfer or USSD)'
            : 'Bank transfer — receipt verified by Edus';

        return [
            'school_name' => $tenantSchool?->getName() ?? $school?->getName() ?? 'School',
            'school_address' => $tenantSchool?->getAddress(),
            'reference' => $reference,
            'receipt_no' => 'RCPT-' . $reference,
            'period' => $registration?->getPeriod() ?? ($payment->getPlan()?->getName() ?? 'Subscription'),
            'plan_name' => $payment->getPlan()?->getName() ?? 'Subscription',
            'amount' => (float) $payment->getAmount(),
            'supplementary' => $payment->isSupplementary(),
            'declared_students' => $registration?->getDeclaredStudents(),
            'billing_cycle' => $registration?->getBillingCycle(),
            'paid_at' => $paidAt,
            'method' => $method,
            'gateway_reference' => $payment->getGatewayReference(),
            'logo' => $this->pdf->uploadedImageDataUri($tenantSchool?->getLogoFilename()),
            'verification' => $this->verification($school, $reference, $paidAt, $registration?->getPeriod()),
        ];
    }

    /**
     * @return array{code: string, url: string, qr: string}|null
     */
    private function verification(
        ?BillingSchool $school,
        string $reference,
        \DateTimeImmutable $paidAt,
        ?string $period
    ): ?array {
        if (!$school instanceof BillingSchool || $reference === '') {
            return null;
        }

        $code = $this->verifications->codeFor(
            $school,
            'RCPT-' . $reference,
            $paidAt,
            $period === null ? null : mb_substr($period, 0, 40),
            'receipt'
        );

        $url = $this->urls->generate(
            'tenant_statement_verify',
            ['code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return ['code' => $code, 'url' => $url, 'qr' => $this->qr->dataUri($url)];
    }
}
