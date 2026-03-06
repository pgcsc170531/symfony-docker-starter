<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Entity\Tenant\Classroom;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'invoice_number', length: 50, unique: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalAmount = '0.00';

    #[ORM\Column(name: 'paid_amount', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $paidAmount = '0.00';

    #[ORM\Column(name: 'discount_applied', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $discountApplied = '0.00';

    #[ORM\Column(name: 'status', length: 20)]
    private ?string $status = 'UNPAID'; // UNPAID, PARTIAL, PAID

    #[ORM\Column(name: 'created_at')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 20, options: ['default' => 'ACADEMIC'])]
    private ?string $type = 'ACADEMIC';

    // === SNAPSHOT FIELDS (School Details at time of invoice) ===
    #[ORM\Column(name: 'school_name', length: 255, nullable: true)]
    private ?string $schoolName = null;

    #[ORM\Column(name: 'school_address', length: 255, nullable: true)]
    private ?string $schoolAddress = null;

    #[ORM\Column(name: 'school_logo', length: 255, nullable: true)]
    private ?string $schoolLogo = null; // Stores filename

    #[ORM\Column(name: 'school_email', length: 255, nullable: true)]
    private ?string $schoolEmail = null;

    #[ORM\Column(name: 'school_phone', length: 50, nullable: true)]
    private ?string $schoolPhone = null;

    // === RELATIONSHIPS ===

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: true)]
    private ?Classroom $classroom = null;


    // One Invoice has Many Items (Tuition, Bus, etc.)
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceItem::class, cascade: ['persist', 'remove'])]
    private Collection $items;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: Payment::class, cascade: ['persist', 'remove'])]
    private Collection $payments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->invoiceNumber = strtoupper(uniqid('INV-'));
    }

    // GETTERS & SETTERS
    public function getId(): ?int { return $this->id; }
    
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getInvoiceNumber(): ?string { return $this->invoiceNumber; }
    public function setInvoiceNumber(string $invoiceNumber): static { $this->invoiceNumber = $invoiceNumber; return $this; }

    public function getTotalAmount(): ?string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getPaidAmount(): ?string { return $this->paidAmount; }
    public function setPaidAmount(string $paidAmount): static { $this->paidAmount = $paidAmount; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(?Term $term): static { $this->term = $term; return $this; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $session): static { $this->session = $session; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getDiscountApplied(): ?string { return $this->discountApplied; }
    public function setDiscountApplied(string $discountApplied): static { $this->discountApplied = $discountApplied; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    // === SNAPSHOT GETTERS & SETTERS ===

    public function getSchoolName(): ?string { return $this->schoolName; }
    public function setSchoolName(?string $schoolName): static { $this->schoolName = $schoolName; return $this; }

    public function getSchoolAddress(): ?string { return $this->schoolAddress; }
    public function setSchoolAddress(?string $schoolAddress): static { $this->schoolAddress = $schoolAddress; return $this; }

    public function getSchoolLogo(): ?string { return $this->schoolLogo; }
    public function setSchoolLogo(?string $schoolLogo): static { $this->schoolLogo = $schoolLogo; return $this; }

    public function getSchoolEmail(): ?string { return $this->schoolEmail; }
    public function setSchoolEmail(?string $schoolEmail): static { $this->schoolEmail = $schoolEmail; return $this; }

    public function getSchoolPhone(): ?string { return $this->schoolPhone; }
    public function setSchoolPhone(?string $schoolPhone): static { $this->schoolPhone = $schoolPhone; return $this; }

    // === COLLECTION LOGIC ===

    public function getItems(): Collection { return $this->items; }
    public function addItem(InvoiceItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setInvoice($this);
        }
        return $this;
    }

    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function getBalanceDue(): float
    {
        return (float)$this->totalAmount - (float)$this->paidAmount;
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }
}