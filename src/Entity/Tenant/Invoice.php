<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use App\Entity\Tenant\Classroom;
use App\Repository\Tenant\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
class Invoice
{
    // ── Document type ────────────────────────────────────────────────────
    public const TYPE_ACADEMIC = 'ACADEMIC';
    public const TYPE_STORE    = 'STORE';

    // ── Settlement status ────────────────────────────────────────────────
    public const STATUS_UNPAID  = 'UNPAID';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_PAID    = 'PAID';

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

    #[ORM\Column(name: 'discount_label', length: 120, nullable: true)]
    private ?string $discountLabel = null;

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
    private ?string $schoolLogo = null; 

    #[ORM\Column(name: 'school_email', length: 255, nullable: true)]
    private ?string $schoolEmail = null;

    #[ORM\Column(name: 'school_phone', length: 50, nullable: true)]
    private ?string $schoolPhone = null;

    // ==========================================
    // 🟢 NEW: WALK-IN CUSTOMER FIELD
    // ==========================================
    #[ORM\Column(name: 'buyer_name', length: 255, nullable: true)]
    private ?string $buyerName = null;

    // === RELATIONSHIPS ===

    // 🟢 UPDATED: nullable is now TRUE so walk-ins can buy without an ID!
    // `inversedBy` is what makes Student::$invoices the working inverse side —
    // without it Doctrine reports the mapping as invalid.
    #[ORM\ManyToOne(inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'student_id', nullable: true)]
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
        // No number here on purpose: `App\Service\Finance\InvoiceNumberGenerator`
        // owns the series so every screen mints the same shape. See setInvoiceNumber().
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

    // 🟢 NEW: Getter and Setter for Walk-in Name
    public function getBuyerName(): ?string { return $this->buyerName; }
    public function setBuyerName(?string $buyerName): static { $this->buyerName = $buyerName; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(?Term $term): static { $this->term = $term; return $this; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $session): static { $this->session = $session; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getDiscountApplied(): ?string { return $this->discountApplied; }
    public function setDiscountApplied(string $discountApplied): static { $this->discountApplied = $discountApplied; return $this; }

    /**
     * The name of the rule that produced `discount_applied`, captured when the
     * bill was priced.
     *
     * A parent must be able to read *why* the bill is lower — "Staff Child
     * Scholarship (10%)" — not just see a smaller number than the items add up
     * to. It is stored, not looked up, so renaming or retiring a rule later
     * cannot rewrite what a family was told at the time.
     */
    public function getDiscountLabel(): ?string { return $this->discountLabel; }
    public function setDiscountLabel(?string $discountLabel): static { $this->discountLabel = $discountLabel; return $this; }

    /**
     * What the school is actually entitled to collect.
     *
     * `total_amount` is stored NET of `discount_applied` — the invoice screen,
     * the payment terminal and the parent portal all compare payments against
     * this figure. Every invoice-creation path must subtract the discount
     * before calling setTotalAmount(); see docs/payments/FEES-REPORTING-DESIGN.md §3.2.
     */
    public function getNetBillable(): float
    {
        return (float) $this->totalAmount;
    }

    /** The value of the invoice lines before the discount was applied. */
    public function getGrossBilled(): float
    {
        return (float) $this->totalAmount + (float) $this->discountApplied;
    }

    /** Still owed on this invoice; never negative. */
    public function getOutstanding(): float
    {
        return max(0.0, $this->getNetBillable() - (float) $this->paidAmount);
    }

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