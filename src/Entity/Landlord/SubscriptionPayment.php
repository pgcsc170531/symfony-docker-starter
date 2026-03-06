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

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

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
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getVerifiedAt(): ?\DateTimeInterface { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static { $this->verifiedAt = $verifiedAt; return $this; }
}