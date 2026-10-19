<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Permanent evidence that a report was generated, when, and by whom.
 *
 * The PDF itself is reproducible from the approved scores; this row records the
 * fingerprint and the audit trail. A bursar or inspector can ask "was this the
 * document issued on 12 December?" and the answer is a hash and a timestamp.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_archive')]
#[ORM\UniqueConstraint(name: 'uniq_report_archive', columns: ['student_id', 'term_id', 'kind'])]
class ReportArchive
{
    public const KIND_CARD = 'card';
    public const KIND_TRANSCRIPT = 'transcript';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?Student $student = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false, onDelete: 'CASCADE')]
    private ?Term $term = null;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_CARD;

    #[ORM\Column(name: 'sha256', length: 64)]
    private string $sha256;

    #[ORM\Column(name: 'generated_by', length: 180, nullable: true)]
    private ?string $generatedBy = null;

    #[ORM\Column(name: 'generated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }

    public function getSha256(): string { return $this->sha256; }
    public function setSha256(string $sha256): static { $this->sha256 = $sha256; return $this; }

    public function getGeneratedBy(): ?string { return $this->generatedBy; }
    public function setGeneratedBy(?string $generatedBy): static { $this->generatedBy = $generatedBy; return $this; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(\DateTimeImmutable $generatedAt): static { $this->generatedAt = $generatedAt; return $this; }
}
