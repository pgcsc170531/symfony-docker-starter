<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\School as BillingSchool;
use App\Entity\Landlord\SubscriptionPayment;
use App\Entity\Tenant\School as TenantSchool;
use App\Service\Pdf\A4PdfRenderer;
use App\Service\Pdf\QrCodeRenderer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The subscription invoice as a document: what the school is being asked to pay,
 * for which term, on which reference — on the same A4 sheet as every other Edus
 * paper, with the same verification footer.
 *
 * The pay page in the portal is for paying. This is for filing: a bursar prints
 * it, a proprietor signs it, and accounts keep it. That is why the bank details
 * and the transfer remark are on the paper itself rather than only on screen.
 */
final class SubscriptionInvoicePdfRenderer
{
    public function __construct(
        private readonly A4PdfRenderer $pdf,
        private readonly QrCodeRenderer $qr,
        private readonly StatementVerificationService $verifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $bank [name, account, account_name]
     */
    public function render(
        SubscriptionPayment $payment,
        ?TenantSchool $tenantSchool = null,
        array $bank = [],
    ): string {
        $data = $this->context($payment, $tenantSchool, $bank);

        return $this->pdf->render('tenant/subscription/invoice.pdf.twig', $data);
    }

    /**
     * @param array<string, mixed> $bank
     *
     * @return array<string, mixed>
     */
    public function context(SubscriptionPayment $payment, ?TenantSchool $tenantSchool = null, array $bank = []): array
    {
        $school = $payment->getSchool();
        $registration = $payment->getTermRegistration();

        $bankName = trim((string) ($bank['name'] ?? ''));
        $bankAccount = trim((string) ($bank['account'] ?? ''));
        $bankAccountName = trim((string) ($bank['account_name'] ?? ''));

        $status = (string) $payment->getStatus();
        $reference = (string) $payment->getReference();
        $issuedAt = $payment->getCreatedAt() ?? new \DateTimeImmutable();

        return [
            'school_name' => $tenantSchool?->getName() ?? $school?->getName() ?? 'School',
            'school_address' => $tenantSchool?->getAddress(),
            'reference' => $reference,
            'period' => $registration?->getPeriod()
                ?? ($payment->getPlan()?->getName() ?? 'Subscription'),
            'plan_name' => $payment->getPlan()?->getName() ?? 'Subscription',
            'status' => $status,
            'status_label' => match ($status) {
                'APPROVED' => 'Paid',
                'CANCELLED' => 'Cancelled',
                'DECLINED' => 'Declined',
                'VERIFYING' => 'Checking receipt',
                default => 'Due',
            },
            'amount' => (float) $payment->getAmount(),
            'supplementary' => $payment->isSupplementary(),
            'declared_students' => $registration?->getDeclaredStudents(),
            'unit_price' => $registration === null ? null : (float) $registration->getUnitPrice(),
            'subtotal' => $registration === null ? null : (float) $registration->getSubtotal(),
            'discount' => $registration === null ? null : (float) $registration->getDiscount(),
            'implementation_fee' => $registration === null ? null : (float) $registration->getImplementationFee(),
            'billing_cycle' => $registration?->getBillingCycle(),
            'issued_at' => $issuedAt,
            'pay_url' => $this->urls->generate(
                'tenant_subscription_slip',
                ['id' => $payment->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'bank' => [
                'name' => $bankName,
                'account' => $bankAccount,
                'account_name' => $bankAccountName,
            ],
            'logo' => $this->pdf->uploadedImageDataUri($tenantSchool?->getLogoFilename()),
            'verification' => $this->verification($school, $reference, $issuedAt, $registration?->getPeriod()),
        ];
    }

    /**
     * @return array{code: string, url: string, qr: string}|null
     */
    private function verification(
        ?BillingSchool $school,
        string $reference,
        \DateTimeImmutable $issuedAt,
        ?string $period
    ): ?array {
        if (!$school instanceof BillingSchool || $reference === '') {
            return null;
        }

        $code = $this->verifications->codeFor(
            $school,
            $reference,
            $issuedAt,
            $period === null ? null : mb_substr($period, 0, 40),
            'invoice'
        );

        $url = $this->urls->generate(
            'tenant_statement_verify',
            ['code' => $code],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return ['code' => $code, 'url' => $url, 'qr' => $this->qr->dataUri($url)];
    }
}
