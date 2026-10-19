<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

/**
 * One school's declaration of its roll for one term — the billing trigger.
 *
 * Every money field is a SNAPSHOT taken when the registration was submitted.
 * A later price change must never rewrite an issued invoice, so nothing here is
 * recomputed from `Plan` after submission.
 *
 * See docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md §8.2.
 */
#[ORM\Entity]
#[ORM\Table(name: 'term_registration')]
#[ORM\UniqueConstraint(name: 'uniq_term_registration_school_period', columns: ['school_id', 'session_name', 'term_name'])]
class TermRegistration
{
    public const TRACK_STANDARD = 'standard';
    public const TRACK_ENTERPRISE = 'enterprise';

    public const CYCLE_TERMLY = 'termly';
    public const CYCLE_ANNUAL = 'annual';

    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_INVOICED = 'INVOICED';
    public const STATUS_PAID = 'PAID';
    public const STATUS_VERIFIED = 'VERIFIED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: School::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Plan $plan = null;

    #[ORM\Column(length: 20)]
    private string $track = self::TRACK_STANDARD;

    /** termly | annual — null on Enterprise, which is not billed by the roll. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $billingCycle = null;

    #[ORM\Column(name: 'session_name', length: 80)]
    private string $sessionName;

    #[ORM\Column(name: 'term_name', length: 80)]
    private string $termName;

    /** The roll the school declares. Never used to bill Enterprise. */
    #[ORM\Column]
    private int $declaredStudents = 0;

    /** What Edus actually counted during the first-week check. */
    #[ORM\Column(nullable: true)]
    private ?int $verifiedStudents = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $verifiedBy = null;

    /** Naira per pupil, snapshot (termly rate, or annual rate per pupil for the year). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    /** The saving given for paying the year up front (annual only). */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $discount = '0.00';

    /** Enterprise only: the one-off set-up, snapshot. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $implementationFee = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_SUBMITTED;

    #[ORM\Column(length: 50, unique: true)]
    private string $reference;

    /** Only the hash is stored; the clear token is shown once, at creation. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $submittedBy = null;

    /** Why the bursar adjusted the roll, if they did. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'TRM-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(School $school): static { $this->school = $school; return $this; }

    public function getPlan(): ?Plan { return $this->plan; }
    public function setPlan(?Plan $plan): static { $this->plan = $plan; return $this; }

    public function getTrack(): string { return $this->track; }
    public function setTrack(string $track): static { $this->track = $track; return $this; }

    public function getBillingCycle(): ?string { return $this->billingCycle; }
    public function setBillingCycle(?string $billingCycle): static { $this->billingCycle = $billingCycle; return $this; }

    public function getSessionName(): string { return $this->sessionName; }
    public function setSessionName(string $sessionName): static { $this->sessionName = $sessionName; return $this; }

    public function getTermName(): string { return $this->termName; }
    public function setTermName(string $termName): static { $this->termName = $termName; return $this; }

    public function getDeclaredStudents(): int { return $this->declaredStudents; }
    public function setDeclaredStudents(int $declaredStudents): static { $this->declaredStudents = $declaredStudents; return $this; }

    public function getVerifiedStudents(): ?int { return $this->verifiedStudents; }
    public function setVerifiedStudents(?int $verifiedStudents): static { $this->verifiedStudents = $verifiedStudents; return $this; }

    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static { $this->verifiedAt = $verifiedAt; return $this; }

    public function getVerifiedBy(): ?string { return $this->verifiedBy; }
    public function setVerifiedBy(?string $verifiedBy): static { $this->verifiedBy = $verifiedBy; return $this; }

    /** Positive = under-declared, negative = over-declared. Null until verified. */
    public function getVariance(): ?int
    {
        return $this->verifiedStudents === null ? null : $this->verifiedStudents - $this->declaredStudents;
    }

    /**
     * The agreed tolerance: 5% or 10 pupils, whichever is larger. Inside it, no
     * supplementary invoice; outside it, the difference is billed next term.
     */
    public function isWithinVariance(): bool
    {
        $variance = $this->getVariance();

        return $variance === null || abs($variance) <= max(10, (int) ceil($this->declaredStudents * 0.05));
    }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(?string $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): static { $this->subtotal = $subtotal; return $this; }

    public function getDiscount(): string { return $this->discount; }
    public function setDiscount(string $discount): static { $this->discount = $discount; return $this; }

    public function getImplementationFee(): string { return $this->implementationFee; }
    public function setImplementationFee(string $implementationFee): static { $this->implementationFee = $implementationFee; return $this; }

    public function getTotal(): string { return $this->total; }
    public function setTotal(string $total): static { $this->total = $total; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getReference(): string { return $this->reference; }

    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function setTokenHash(?string $tokenHash): static { $this->tokenHash = $tokenHash; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    public function getSubmittedBy(): ?string { return $this->submittedBy; }
    public function setSubmittedBy(?string $submittedBy): static { $this->submittedBy = $submittedBy; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPeriod(): string
    {
        return trim($this->sessionName . ' · ' . $this->termName, ' ·');
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_VERIFIED], true);
    }
}
