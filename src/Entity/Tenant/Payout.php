<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment run to the school's bank account.
 *
 * A payout is the only thing allowed to move money **out** of `2000 School
 * payable`, and it may never exceed that balance. The row exists so the ledger
 * entry has something to point at (idempotency), and so a failed transfer can be
 * seen and retried rather than silently re-paid.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payout')]
#[ORM\UniqueConstraint(name: 'uniq_payout_reference', columns: ['reference'])]
class Payout
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $reference;

    /** Always positive, in kobo. */
    #[ORM\Column(name: 'amount_minor')]
    private int $amountMinor;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /** Paystack's transfer code, when there is one. */
    #[ORM\Column(name: 'provider_code', length: 80, nullable: true)]
    private ?string $providerCode = null;

    /**
     * True when this run never touched the provider — used while the ledger is
     * being proven and real money is not wanted. Kept in the data, not in a
     * comment, so nobody mistakes a simulation for a settlement.
     */
    #[ORM\Column]
    private bool $simulated = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'PO-' . strtoupper(bin2hex(random_bytes(5)));
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): string { return $this->reference; }

    public function getAmountMinor(): int { return $this->amountMinor; }
    public function setAmountMinor(int $minor): static { $this->amountMinor = $minor; return $this; }

    public function getStatus(): string { return $this->status; }

    public function getProviderCode(): ?string { return $this->providerCode; }

    public function isSimulated(): bool { return $this->simulated; }
    public function setSimulated(bool $simulated): static { $this->simulated = $simulated; return $this; }

    public function getNote(): ?string { return $this->note; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }

    public function markSuccess(?string $providerCode, ?string $note = null): static
    {
        $this->status = self::STATUS_SUCCESS;
        $this->providerCode = $providerCode;
        $this->note = $note;
        $this->paidAt = new \DateTimeImmutable();

        return $this;
    }

    public function markFailed(string $reason): static
    {
        $this->status = self::STATUS_FAILED;
        $this->note = $reason;

        return $this;
    }
}
