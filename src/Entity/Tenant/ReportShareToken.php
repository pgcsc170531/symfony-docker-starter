<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A capability to view one pupil's report, held by whoever has the link.
 *
 * The clear token is returned once, when it is created, and never stored: only
 * its SHA-256 hash is kept. A database leak therefore cannot be replayed against
 * the public view. Links expire (30 days by default) and can be revoked.
 *
 * See docs/results/RESULT-CARD-DESIGN.md §6 and §9.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_share_token')]
#[ORM\UniqueConstraint(name: 'uniq_report_share_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_report_share_student_term', columns: ['student_id', 'term_id'])]
class ReportShareToken
{
    public const DEFAULT_LIFETIME_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?Student $student = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false, onDelete: 'CASCADE')]
    private ?Term $term = null;

    /** The account that created it, for the audit trail a bursar will ask for. */
    #[ORM\Column(name: 'created_by', length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'view_count', options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(name: 'last_viewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastViewedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+' . self::DEFAULT_LIFETIME_DAYS . ' days');
    }

    public function getId(): ?int { return $this->id; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): static { $this->tokenHash = $tokenHash; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }

    public function getViewCount(): int { return $this->viewCount; }
    public function getLastViewedAt(): ?\DateTimeImmutable { return $this->lastViewedAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    public function revoke(): static
    {
        if ($this->revokedAt === null) {
            $this->revokedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function recordView(): static
    {
        $this->viewCount++;
        $this->lastViewedAt = new \DateTimeImmutable();

        return $this;
    }
}
