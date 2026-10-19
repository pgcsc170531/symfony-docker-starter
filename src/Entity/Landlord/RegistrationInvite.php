<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

/**
 * A one-click invitation for a school to register a term.
 *
 * The email carries the clear token; only its SHA-256 hash is stored here. The
 * token is single-use and expires, and the link still requires the school admin
 * to sign in — a forwarded email cannot change another school's roll.
 */
#[ORM\Entity]
#[ORM\Table(name: 'registration_invite')]
class RegistrationInvite
{
    /** How long an invitation stays usable. */
    public const LIFETIME_DAYS = 21;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: School::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?School $school = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d days', self::LIFETIME_DAYS));
        $this->tokenHash = '';
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(School $school): static { $this->school = $school; return $this; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): static { $this->tokenHash = $tokenHash; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function setUsedAt(?\DateTimeImmutable $usedAt): static { $this->usedAt = $usedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function isValid(): bool
    {
        return $this->usedAt === null && $this->expiresAt > new \DateTimeImmutable();
    }
}
