<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'credit_request')]
#[ORM\HasLifecycleCallbacks]
class CreditRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'creditRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $amount = null;

    // PENDING, APPROVED, REJECTED
    #[ORM\Column(length: 20)]
    private ?string $status = 'PENDING';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $proofFilename = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null; // Unique Ref

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null; // Rejection reason etc.

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->reference = 'CR-' . strtoupper(uniqid());
    }

    // --- HOOKS ---
    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- GETTERS & SETTERS ---
    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(?School $school): static { $this->school = $school; return $this; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getProofFilename(): ?string { return $this->proofFilename; }
    public function setProofFilename(?string $proofFilename): static { $this->proofFilename = $proofFilename; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $adminNote): static { $this->adminNote = $adminNote; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}