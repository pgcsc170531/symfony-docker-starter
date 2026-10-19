<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subscription_payment')]
class SubscriptionPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: School::class, inversedBy: 'subscriptionPayments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Plan $plan = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $reference = null; // e.g., SUB-8829

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'PENDING'; // PENDING, APPROVED, DECLINED

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofOfPayment = null; // Filename of uploaded receipt

    /**
     * The reference sent to the gateway. One invoice can have several attempts,
     * so this differs from `reference` (the invoice number).
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $gatewayReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    /**
     * The roll declaration this payment settles, when it came from a term
     * registration rather than a legacy flat plan. Null on old invoices.
     */
    #[ORM\ManyToOne(targetEntity: TermRegistration::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TermRegistration $termRegistration = null;

    /**
     * An overage invoice for a verified roll above tolerance. Paying it settles
     * money owed; it must NOT grant another term, so activation stops early.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $supplementary = false;

    /** Set by the daily reminder command; prevents two reminders in one day. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReminderSentAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'SUB-' . strtoupper(substr(uniqid(), -6));
    }

    public function getId(): ?int { return $this->id; }
    public function getSchool(): ?School { return $this->school; }
    public function setSchool(?School $school): static { $this->school = $school; return $this; }
    public function getPlan(): ?Plan { return $this->plan; }
    public function setPlan(?Plan $plan): static { $this->plan = $plan; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }
    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getProofOfPayment(): ?string { return $this->proofOfPayment; }
    public function setProofOfPayment(?string $proofOfPayment): static { $this->proofOfPayment = $proofOfPayment; return $this; }

    public function getGatewayReference(): ?string { return $this->gatewayReference; }
    public function setGatewayReference(?string $gatewayReference): static { $this->gatewayReference = $gatewayReference; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getVerifiedAt(): ?\DateTimeInterface { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static { $this->verifiedAt = $verifiedAt; return $this; }

    public function getTermRegistration(): ?TermRegistration { return $this->termRegistration; }
    public function setTermRegistration(?TermRegistration $termRegistration): static { $this->termRegistration = $termRegistration; return $this; }

    public function isSupplementary(): bool { return $this->supplementary; }
    public function setSupplementary(bool $supplementary): static { $this->supplementary = $supplementary; return $this; }

    public function getLastReminderSentAt(): ?\DateTimeImmutable { return $this->lastReminderSentAt; }
    public function setLastReminderSentAt(?\DateTimeImmutable $lastReminderSentAt): static { $this->lastReminderSentAt = $lastReminderSentAt; return $this; }
}