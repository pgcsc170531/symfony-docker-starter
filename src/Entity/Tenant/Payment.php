<?php

namespace App\Entity\Tenant;

use App\Repository\Tenant\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
class Payment
{
    // ── Statuses ─────────────────────────────────────────────────────────
    // One vocabulary for every query, report and template. The old code
    // compared bare strings in a dozen places, which is how 'CANCELLED' ended
    // up missing from the comment above `status`.
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_DECLINED  = 'DECLINED';
    public const STATUS_CANCELLED = 'CANCELLED';

    // ── Methods ──────────────────────────────────────────────────────────
    public const METHOD_CASH     = 'CASH';
    public const METHOD_TRANSFER = 'TRANSFER';
    public const METHOD_POS      = 'POS';
    public const METHOD_CHEQUE   = 'CHEQUE';

    /** Settled from money the family paid ahead (see StudentAdvance). */
    public const METHOD_ADVANCE  = 'ADVANCE';

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

    #[ORM\Column(name: 'proof_filename', length: 255, nullable: true)]
    private ?string $proofFilename = null;

    #[ORM\Column(name: 'proof_uploaded_at', nullable: true)]
    private ?\DateTimeImmutable $proofUploadedAt = null;

    /**
     * The auditor's line: why this payment exists, in plain words.
     *
     * A draw-down from an advance reads "Paid from advance ADV-2026-0003 —
     * parent paid three sessions in advance on 12 Oct 2026 (cash)". Without it a
     * settled invoice looks like a ghost, and a physical audit cannot reconcile it.
     */
    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    

    // RELATIONSHIP
    //
    // Nullable on purpose: an application fee is real money that has been
    // received, but the applicant is not a pupil yet, so there is no invoice to
    // attach it to. The `Application` holds that link (`feePayment`).
    #[ORM\ManyToOne(inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /** Set only when this payment was drawn from a prepaid advance. */
    #[ORM\ManyToOne(targetEntity: StudentAdvance::class)]
    #[ORM\JoinColumn(name: 'advance_id', nullable: true, onDelete: 'SET NULL')]
    private ?StudentAdvance $advance = null;

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

    public function getProofFilename(): ?string { return $this->proofFilename; }
    public function setProofFilename(?string $proofFilename): static { $this->proofFilename = $proofFilename; return $this; }

    public function getProofUploadedAt(): ?\DateTimeImmutable { return $this->proofUploadedAt; }
    public function setProofUploadedAt(?\DateTimeImmutable $proofUploadedAt): static { $this->proofUploadedAt = $proofUploadedAt; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getAdvance(): ?StudentAdvance { return $this->advance; }
    public function setAdvance(?StudentAdvance $advance): static { $this->advance = $advance; return $this; }
}