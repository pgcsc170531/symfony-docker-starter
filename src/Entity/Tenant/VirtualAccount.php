<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The family's dedicated virtual account at this school — one per guardian.
 *
 * **Payment only.** This is a NUBAN a parent transfers school fees into; it is
 * NOT a wallet. There is no balance column here and there never should be — money
 * that cannot be tied to an invoice goes to `AccountCredit`, which is an
 * exception queue with an owner and a deadline, not a stored value.
 *
 * Why the guardian and not the pupil (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §4):
 *   • Paystack issues the account to the **payer**, and validation (BVN + a bank
 *     account in that name) is only possible for an adult;
 *   • `Guardian` → many `Student` is already the shape of our data;
 *   • one account per family instead of one per child saves a third of the
 *     account budget, which Paystack caps per merchant (1,000 by default).
 *
 * A parent with children in two of a group's schools has **one account per
 * school** — each school is a separate tenant with its own subaccount, and a
 * single account cannot split two ways (§4.4).
 *
 * Two compliance fields are deliberate:
 *   • `consentAt` — Paystack requires the customer's **express consent** before an
 *     account is generated; the timestamp is the proof.
 *   • `bvnMasked` — the last three digits only, for support calls. **The BVN is
 *     never stored**; it is passed to Paystack once and forgotten.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virtual_account')]
#[ORM\UniqueConstraint(name: 'uniq_virtual_account_guardian', columns: ['guardian_id'])]
class VirtualAccount
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_DORMANT = 'dormant';
    public const STATUS_CLOSED  = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Guardian::class)]
    #[ORM\JoinColumn(name: 'guardian_id', nullable: false, onDelete: 'CASCADE')]
    private ?Guardian $guardian = null;

    /** Paystack's `CUS_…` code. May be shared by a parent across a group's schools. */
    #[ORM\Column(name: 'paystack_customer_code', length: 64, nullable: true)]
    private ?string $paystackCustomerCode = null;

    #[ORM\Column(name: 'account_number', length: 20, unique: true)]
    private ?string $accountNumber = null;

    #[ORM\Column(name: 'bank_name', length: 100, nullable: true)]
    private ?string $bankName = null;

    /** Paystack's "PRODUCT / CUSTOMER" wording, as the payer's bank shows it. */
    #[ORM\Column(name: 'account_name', length: 150, nullable: true)]
    private ?string $accountName = null;

    /** The school's `ACCT_…` this account settles to. */
    #[ORM\Column(name: 'subaccount_code', length: 64, nullable: true)]
    private ?string $subaccountCode = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'consent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    /** Set when Paystack validated the parent's identity; null where it is optional. */
    #[ORM\Column(name: 'identity_validated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $identityValidatedAt = null;

    /** `***456` — for support calls only. **Never the BVN itself.** */
    #[ORM\Column(name: 'bvn_masked', length: 12, nullable: true)]
    private ?string $bvnMasked = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Drives the dormancy sweep (§7, F7). */
    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getGuardian(): ?Guardian { return $this->guardian; }
    public function setGuardian(Guardian $guardian): static { $this->guardian = $guardian; return $this; }

    public function getPaystackCustomerCode(): ?string { return $this->paystackCustomerCode; }
    public function setPaystackCustomerCode(?string $code): static { $this->paystackCustomerCode = $code; return $this; }

    public function getAccountNumber(): ?string { return $this->accountNumber; }
    public function setAccountNumber(string $number): static { $this->accountNumber = $number; return $this; }

    public function getBankName(): ?string { return $this->bankName; }
    public function setBankName(?string $bank): static { $this->bankName = $bank; return $this; }

    public function getAccountName(): ?string { return $this->accountName; }
    public function setAccountName(?string $name): static { $this->accountName = $name; return $this; }

    public function getSubaccountCode(): ?string { return $this->subaccountCode; }
    public function setSubaccountCode(?string $code): static { $this->subaccountCode = $code; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }

    public function getConsentAt(): ?\DateTimeImmutable { return $this->consentAt; }
    public function setConsentAt(?\DateTimeImmutable $at): static { $this->consentAt = $at; return $this; }

    public function getIdentityValidatedAt(): ?\DateTimeImmutable { return $this->identityValidatedAt; }
    public function setIdentityValidatedAt(?\DateTimeImmutable $at): static { $this->identityValidatedAt = $at; return $this; }

    public function getBvnMasked(): ?string { return $this->bvnMasked; }
    public function setBvnMasked(?string $masked): static { $this->bvnMasked = $masked; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function touch(): static { $this->lastUsedAt = new \DateTimeImmutable(); return $this; }
}
