<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt by a parent to pay an invoice online.
 *
 * This is deliberately separate from `Payment`:
 *
 *   • `Payment` is the **ledger** — money that has actually been received, and
 *     what the bursar's reports are built from.
 *   • `PaymentIntent` is the **attempt** — a parent who opens the Paystack page
 *     and walks away must not create a phantom receipt.
 *
 * A confirmed intent settles by creating exactly one `Payment`. The link back is
 * `payment`, and `status = paid` is the guard that makes settlement idempotent —
 * Paystack will re-deliver the same webhook.
 *
 * See docs/payments/PAYMENT-FLOW-DESIGN.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_intent')]
#[ORM\HasLifecycleCallbacks]
class PaymentIntent
{
    public const STATUS_PENDING   = 'pending';   // handed to the gateway, waiting on the parent
    public const STATUS_PAID      = 'paid';      // gateway confirmed — invoice settled
    public const STATUS_FAILED    = 'failed';    // gateway declined / parent's bank rejected
    public const STATUS_EXPIRED   = 'expired';   // window closed before payment
    public const STATUS_ABANDONED = 'abandoned'; // parent started over or gave up

    public const PROVIDER_PAYSTACK = 'paystack';
    public const PROVIDER_FAKE     = 'fake';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Our own reference, sent to the gateway and used to reconcile callbacks. */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 30, options: ['default' => self::PROVIDER_FAKE])]
    private string $provider = self::PROVIDER_FAKE;

    /** What the parent is charged: the invoice balance plus our processing fee. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $amount = null;

    /** The slice of `amount` that clears the invoice. */
    #[ORM\Column(name: 'invoice_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $invoiceAmount = null;

    /** The slice of `amount` that covers the gateway charge (parent-paid). */
    #[ORM\Column(name: 'processing_fee', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $processingFee = '0.00';

    #[ORM\Column(length: 3, options: ['default' => 'NGN'])]
    private string $currency = 'NGN';

    /** card | bank_transfer | ussd | … as reported by the gateway. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $channel = null;

    /** The gateway's own transaction reference. */
    #[ORM\Column(name: 'provider_reference', length: 190, nullable: true)]
    private ?string $providerReference = null;

    /** Where we sent the parent to pay. */
    #[ORM\Column(name: 'authorization_url', length: 500, nullable: true)]
    private ?string $authorizationUrl = null;

    #[ORM\Column(name: 'access_code', length: 190, nullable: true)]
    private ?string $accessCode = null;

    /** The raw gateway callback, kept as the audit trail for disputes. */
    #[ORM\Column(name: 'raw_payload', type: Types::TEXT, nullable: true)]
    private ?string $rawPayload = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'paid_at', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    // === RELATIONSHIPS ===

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: true)]
    private ?Student $student = null;

    /** Set once, when the intent settles — the link to the ledger. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true)]
    private ?Payment $payment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // === HELPERS ===

    /** PAY-2026-A1B2C3 — short, unambiguous, and safe to read over the phone. */
    public static function newReference(): string
    {
        return sprintf(
            'PAY-%s-%s',
            (new \DateTimeImmutable())->format('Y'),
            strtoupper(bin2hex(random_bytes(3)))
        );
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Only a pending intent may settle or fail — everything else is already decided. */
    public function isSettleable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getAmountInMinorUnits(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }

    // === GETTERS & SETTERS ===

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): static { $this->provider = $provider; return $this; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getInvoiceAmount(): ?string { return $this->invoiceAmount; }
    public function setInvoiceAmount(string $invoiceAmount): static { $this->invoiceAmount = $invoiceAmount; return $this; }

    public function getProcessingFee(): string { return $this->processingFee; }
    public function setProcessingFee(string $processingFee): static { $this->processingFee = $processingFee; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = $currency; return $this; }

    public function getChannel(): ?string { return $this->channel; }
    public function setChannel(?string $channel): static { $this->channel = $channel; return $this; }

    public function getProviderReference(): ?string { return $this->providerReference; }
    public function setProviderReference(?string $providerReference): static { $this->providerReference = $providerReference; return $this; }

    public function getAuthorizationUrl(): ?string { return $this->authorizationUrl; }
    public function setAuthorizationUrl(?string $authorizationUrl): static { $this->authorizationUrl = $authorizationUrl; return $this; }

    public function getAccessCode(): ?string { return $this->accessCode; }
    public function setAccessCode(?string $accessCode): static { $this->accessCode = $accessCode; return $this; }

    public function getRawPayload(): ?string { return $this->rawPayload; }
    public function setRawPayload(?string $rawPayload): static { $this->rawPayload = $rawPayload; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getPayment(): ?Payment { return $this->payment; }
    public function setPayment(?Payment $payment): static { $this->payment = $payment; return $this; }
}
