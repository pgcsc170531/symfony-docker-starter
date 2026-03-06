<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment')]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    // CASH, TRANSFER, POS, CHEQUE
    #[ORM\Column(name: 'method', length: 50)]
    private ?string $method = 'CASH';

    #[ORM\Column(name: 'reference_code', length: 20, unique: true, nullable: true)]
    private ?string $referenceCode = null;

    #[ORM\Column(name: 'reference', length: 255, nullable: true)]
    private ?string $reference = null; // Bank Transaction ID

    #[ORM\Column(length: 20, options: ['default' => 'CONFIRMED'])]
    private ?string $status = 'CONFIRMED'; // PENDING, CONFIRMED, DECLINED

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'confirmed_at', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'confirmed_by', length: 180, nullable: true)]
    private ?string $confirmedBy = null;

    

    // RELATIONSHIP
    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private ?Invoice $invoice = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        // Default existing payments to confirmed for backward compatibility
        $this->status = 'CONFIRMED';
    }

    // 💡 HELPER: Generates the unique code
    public function generateReferenceCode(): void
    {
        // Generates "PAY-A1B2" unique string
        $this->referenceCode = 'PAY-' . strtoupper(substr(uniqid(), -5)); 
    }


    // --- GETTERS & SETTERS ---
    public function getId(): ?int { return $this->id; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getMethod(): ?string { return $this->method; }
    public function setMethod(string $method): static { $this->method = $method; return $this; }

    public function getReferenceCode(): ?string { return $this->referenceCode; }
    public function setReferenceCode(?string $referenceCode): static { $this->referenceCode = $referenceCode; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = $reference; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    
    // Legacy support for your existing controller
    public function getPaidAt(): ?\DateTimeImmutable { return $this->confirmedAt ?? $this->createdAt; }
    public function setPaidAt(\DateTimeImmutable $paidAt): static { $this->confirmedAt = $paidAt; return $this; }

    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static { $this->confirmedAt = $confirmedAt; return $this; }

    public function getConfirmedBy(): ?string { return $this->confirmedBy; }
    public function setConfirmedBy(?string $confirmedBy): static { $this->confirmedBy = $confirmedBy; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }
}