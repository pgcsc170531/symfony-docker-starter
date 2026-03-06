<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
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

    public function getCategory(): ?ExpenseCategory { return $this->category; }
    public function setCategory(?ExpenseCategory $category): static { $this->category = $category; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}