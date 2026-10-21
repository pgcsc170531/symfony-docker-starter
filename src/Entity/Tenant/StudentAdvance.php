<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * Money a family has paid ahead of the bills — one term, or three sessions.
 *
 * Some parents pay a year (or two, or three) in advance. Rather than park that
 * money in an invoice it does not belong to, the school records it here as a
 * *credit against the pupil*, and every invoice raised later for that pupil is
 * settled from it automatically.
 *
 * Why not just mark future invoices paid by hand:
 *
 *  - the credit has a balance, and a balance is the only thing that can answer
 *    "how much of this family's advance is left?" in March;
 *  - every draw-down writes a real `Payment` row with the remark, so an auditor
 *    sees *when* the money was taken, *which* invoice it settled and *why* —
 *    not a £0 invoice with no explanation;
 *  - a parent who leaves gets a refund of the remainder, which needs a figure to
 *    refund.
 *
 * The money is real cash (already in the school's account), so an advance is
 * recorded only by the bursary — never by a teacher, never automatically.
 */
#[ORM\Entity]
#[ORM\Table(name: 'student_advance')]
#[ORM\UniqueConstraint(name: 'uniq_student_advance_reference', columns: ['reference'])]
class StudentAdvance
{
    public const METHOD_CASH = 'CASH';
    public const METHOD_TRANSFER = 'TRANSFER';
    public const METHOD_POS = 'POS';
    public const METHOD_CHEQUE = 'CHEQUE';

    public const METHODS = [
        self::METHOD_CASH => 'Cash',
        self::METHOD_TRANSFER => 'Bank transfer',
        self::METHOD_POS => 'POS',
        self::METHOD_CHEQUE => 'Cheque',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    /** The whole sum handed over, before any invoice has drawn on it. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    /** Unique code printed on the invoice remark and the receipt. */
    #[ORM\Column(length: 40)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, options: ['default' => 'CASH'])]
    private string $method = self::METHOD_CASH;

    #[ORM\Column(name: 'received_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedOn = null;

    /** What the money is for, in the bursar's own words — printed on every draw-down. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remark = null;

    /** "1 year", "2 sessions", "until 2028/2029" — a note, not a rule. */
    #[ORM\Column(name: 'covers_note', length: 120, nullable: true)]
    private ?string $coversNote = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    /** A closed advance stops drawing on new invoices (refunded, or fully used). */
    #[ORM\Column(name: 'is_closed', options: ['default' => false])]
    private bool $isClosed = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->receivedOn = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): static
    {
        $this->method = array_key_exists($method, self::METHODS) ? $method : self::METHOD_CASH;
        return $this;
    }

    public function getReceivedOn(): ?\DateTimeImmutable { return $this->receivedOn; }
    public function setReceivedOn(?\DateTimeImmutable $receivedOn): static { $this->receivedOn = $receivedOn; return $this; }

    public function getRemark(): ?string { return $this->remark; }
    public function setRemark(?string $remark): static { $this->remark = $remark; return $this; }

    public function getCoversNote(): ?string { return $this->coversNote; }
    public function setCoversNote(?string $coversNote): static { $this->coversNote = $coversNote; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function isClosed(): bool { return $this->isClosed; }
    public function setIsClosed(bool $isClosed): static { $this->isClosed = $isClosed; return $this; }
}
