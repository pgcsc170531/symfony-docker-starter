<?php

namespace App\Entity\Tenant;

use App\Repository\Tenant\ExpenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Table(name: 'expense')]
class Expense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null; // e.g. "Purchase of Diesel"

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null; // Detailed description

    #[ORM\Column(name: 'expense_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $expenseDate = null;

    /** Who was paid (vendor, staff member, utility). */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $payee = null;

    /** CASH, TRANSFER, POS, CHEQUE — see Payment::METHOD_*. */
    #[ORM\Column(length: 20, options: ['default' => 'CASH'])]
    private ?string $method = 'CASH';

    /** Uploaded receipt / invoice image, stored in public/uploads/receipts. */
    #[ORM\Column(name: 'receipt_filename', length: 255, nullable: true)]
    private ?string $receiptFilename = null;

    /** Account that entered the expense. */
    #[ORM\Column(name: 'recorded_by', length: 180, nullable: true)]
    private ?string $recordedBy = null;

    /** Optional second signature for schools that require one. */
    #[ORM\Column(name: 'approved_by', length: 180, nullable: true)]
    private ?string $approvedBy = null;

    #[ORM\Column(name: 'approved_at', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    // RELATIONSHIP
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'category_id', nullable: false)]
    private ?ExpenseCategory $category = null;

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expenseDate = new \DateTimeImmutable(); // Default to today
    }

    // GETTERS & SETTERS
    public function getId(): ?int { return $this->id; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getExpenseDate(): ?\DateTimeImmutable { return $this->expenseDate; }
    public function setExpenseDate(\DateTimeImmutable $expenseDate): static { $this->expenseDate = $expenseDate; return $this; }

    public function getPayee(): ?string { return $this->payee; }
    public function setPayee(?string $payee): static { $this->payee = $payee; return $this; }

    public function getMethod(): ?string { return $this->method; }
    public function setMethod(string $method): static { $this->method = $method; return $this; }

    public function getReceiptFilename(): ?string { return $this->receiptFilename; }
    public function setReceiptFilename(?string $receiptFilename): static { $this->receiptFilename = $receiptFilename; return $this; }

    public function getRecordedBy(): ?string { return $this->recordedBy; }
    public function setRecordedBy(?string $recordedBy): static { $this->recordedBy = $recordedBy; return $this; }

    public function getApprovedBy(): ?string { return $this->approvedBy; }
    public function setApprovedBy(?string $approvedBy): static { $this->approvedBy = $approvedBy; return $this; }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static { $this->approvedAt = $approvedAt; return $this; }

    public function getCategory(): ?ExpenseCategory { return $this->category; }
    public function setCategory(?ExpenseCategory $category): static { $this->category = $category; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}