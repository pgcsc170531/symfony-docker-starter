<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The short code printed on a school's Term Fee Summary PDF.
 *
 * A summary is handed to a board, a bank or an inspector. The code lets the
 * holder confirm that the paper was issued by *this school* for *that term*,
 * while the public page shows no amounts. One code per term, reused on reprint.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fee_report_verification')]
#[ORM\UniqueConstraint(name: 'uniq_fee_report_verification_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_fee_report_verification_reference', columns: ['reference'])]
class FeeReportVerification
{
    /** No O/0 or I/1 — people retype these off paper. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $code;

    /** Stable document reference, e.g. `TERM-1`. */
    #[ORM\Column(length: 40)]
    private string $reference;

    /** Human-readable period, e.g. `2026/2027 · First Term`. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $period = null;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(length: 16, options: ['default' => 'fee-report'])]
    private string $kind = 'fee-report';

    public function __construct()
    {
        $this->issuedAt = new \DateTimeImmutable();
        $this->code = '';
        $this->reference = '';
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getPeriod(): ?string { return $this->period; }
    public function setPeriod(?string $period): static { $this->period = $period; return $this; }

    public function getIssuedAt(): \DateTimeImmutable { return $this->issuedAt; }
    public function setIssuedAt(\DateTimeImmutable $issuedAt): static { $this->issuedAt = $issuedAt; return $this; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
}
