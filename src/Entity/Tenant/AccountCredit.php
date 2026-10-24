<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A transfer that arrived on a family's virtual account but is **not yet** tied
 * to an invoice.
 *
 * This is the safety valve for payments the parent made without opening a bill
 * first: a wrong amount, an expired intent, two invoices added together, or a
 * plain "I just sent the money". It is **not a wallet** — the row exists so the
 * money can be explained, and it is expected to leave this state:
 *
 *   unassigned → applied   (the parent or the bursar says which invoice)
 *              → refunded  (nobody claims it)
 *
 * A credit that sits here past the term is a defect on a report, not a balance
 * somebody may spend (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §3.1, §5.5).
 *
 * `providerReference` is UNIQUE: Paystack retries webhooks, and a replayed
 * delivery must not credit the family twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'account_credit')]
class AccountCredit
{
    public const STATUS_UNASSIGNED = 'unassigned';
    public const STATUS_APPLIED    = 'applied';
    public const STATUS_REFUNDED   = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VirtualAccount::class)]
    #[ORM\JoinColumn(name: 'virtual_account_id', nullable: false, onDelete: 'CASCADE')]
    private ?VirtualAccount $virtualAccount = null;

    /** Paystack's transaction reference/identifier — the idempotency key. */
    #[ORM\Column(name: 'provider_reference', length: 190, unique: true)]
    private ?string $providerReference = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $amount = null;

    /** Who actually sent it — may be an uncle or a church, not the guardian. */
    #[ORM\Column(name: 'sender_name', length: 150, nullable: true)]
    private ?string $senderName = null;

    #[ORM\Column(name: 'sender_bank', length: 100, nullable: true)]
    private ?string $senderBank = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_UNASSIGNED])]
    private string $status = self::STATUS_UNASSIGNED;

    /** The invoice this money was applied to, once somebody says so. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /** The ledger row written when it was applied. */
    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(name: 'payment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Payment $payment = null;

    /** Who decided: `parent`, `bursar`, or `system` (an exact intent match). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $appliedBy = null;

    #[ORM\Column(name: 'applied_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /** The provider's raw payload, kept for disputes. */
    #[ORM\Column(name: 'raw_payload', type: Types::TEXT, nullable: true)]
    private ?string $rawPayload = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->paidAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getVirtualAccount(): ?VirtualAccount { return $this->virtualAccount; }
    public function setVirtualAccount(VirtualAccount $account): static { $this->virtualAccount = $account; return $this; }

    public function getProviderReference(): ?string { return $this->providerReference; }
    public function setProviderReference(string $reference): static { $this->providerReference = $reference; return $this; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getSenderName(): ?string { return $this->senderName; }
    public function setSenderName(?string $name): static { $this->senderName = $name; return $this; }

    public function getSenderBank(): ?string { return $this->senderBank; }
    public function setSenderBank(?string $bank): static { $this->senderBank = $bank; return $this; }

    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(\DateTimeImmutable $at): static { $this->paidAt = $at; return $this; }

    public function getStatus(): string { return $this->status; }

    public function isUnassigned(): bool { return $this->status === self::STATUS_UNASSIGNED; }

    public function getInvoice(): ?Invoice { return $this->invoice; }

    public function getPayment(): ?Payment { return $this->payment; }

    public function getAppliedBy(): ?string { return $this->appliedBy; }

    public function getAppliedAt(): ?\DateTimeImmutable { return $this->appliedAt; }

    public function markApplied(Invoice $invoice, Payment $payment, string $by): static
    {
        $this->status = self::STATUS_APPLIED;
        $this->invoice = $invoice;
        $this->payment = $payment;
        $this->appliedBy = $by;
        $this->appliedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markRefunded(string $by): static
    {
        $this->status = self::STATUS_REFUNDED;
        $this->appliedBy = $by;
        $this->appliedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRawPayload(): ?string { return $this->rawPayload; }
    public function setRawPayload(?string $payload): static { $this->rawPayload = $payload; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
