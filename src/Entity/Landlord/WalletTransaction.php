<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wallet_transaction')]
#[ORM\HasLifecycleCallbacks] // 👈 Important for auto-timestamps
class WalletTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'walletTransactions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    // CREDIT (Add) or DEBIT (Subtract)
    #[ORM\Column(length: 10)]
    private ?string $type = null; 

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $amount = null;

    // What was the balance AFTER this transaction? (Crucial for debugging)
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $balanceAfter = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    // e.g., "SUB-REF-123" or "MANUAL-ADD"
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- GETTERS & SETTERS ---
    public function getId(): ?int { return $this->id; }
    
    public function getSchool(): ?School { return $this->school; }
    public function setSchool(?School $school): static { $this->school = $school; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getBalanceAfter(): ?string { return $this->balanceAfter; }
    public function setBalanceAfter(string $balanceAfter): static { $this->balanceAfter = $balanceAfter; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = $reference; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}