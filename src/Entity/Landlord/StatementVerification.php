<?php

namespace App\Entity\Landlord;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The short code and scan target printed on a Value & Billing statement.
 *
 * A statement is a financial document a school forwards to a board, a bank or
 * an auditor. The code lets any of them confirm that the paper in their hand was
 * really issued by Edus to that school for that statement number — while the
 * verification page shows **no amounts**, because the school's billing is not the
 * public's business, only its authenticity is.
 *
 * One code per school per statement number, created lazily the first time the
 * document is produced, so re-printing the same statement keeps the same code.
 */
#[ORM\Entity]
#[ORM\Table(name: 'statement_verification')]
#[ORM\UniqueConstraint(name: 'uniq_statement_verification_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_statement_verification_reference', columns: ['school_id', 'reference'])]
class StatementVerification
{
    /** No O/0 or I/1 — these are the characters people mistype off paper. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: School::class)]
    #[ORM\JoinColumn(name: 'school_id', nullable: false, onDelete: 'CASCADE')]
    private ?School $school = null;

    #[ORM\Column(length: 12)]
    private string $code;

    /** The printed statement number, e.g. `STMT-1-202610`. */
    #[ORM\Column(length: 40)]
    private string $reference;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $period = null;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    /**
     * Which document the code was printed on — `statement`, `invoice`,
     * `receipt`. The public page reads it so it can say "Genuine invoice"
     * instead of calling every paper a statement.
     */
    #[ORM\Column(length: 16, options: ['default' => 'statement'])]
    private string $kind = 'statement';

    public function __construct()
    {
        $this->issuedAt = new \DateTimeImmutable();
        $this->code = '';
        $this->reference = '';
    }

    public function getId(): ?int { return $this->id; }

    public function getKind(): string { return $this->kind; }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(School $school): static { $this->school = $school; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getPeriod(): ?string { return $this->period; }
    public function setPeriod(?string $period): static { $this->period = $period; return $this; }

    public function getIssuedAt(): \DateTimeImmutable { return $this->issuedAt; }
    public function setIssuedAt(\DateTimeImmutable $issuedAt): static { $this->issuedAt = $issuedAt; return $this; }
}
