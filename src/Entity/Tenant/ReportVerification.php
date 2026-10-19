<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The public verification code printed on a report card.
 *
 * The code identifies the pupil and term, never the marks. A third party (a
 * prospective school, an embassy, an inspector) can confirm that the document
 * is genuine without being handed a child's results.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_verification')]
#[ORM\UniqueConstraint(name: 'uniq_report_verification_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_report_verification_student_term', columns: ['student_id', 'term_id'])]
class ReportVerification
{
    /** No O/0 or I/1 — these are the characters people mistype from paper. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

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

    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = strtoupper($code); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
