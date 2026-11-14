<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "A pupil has been added to your family."
 *
 * Linking a pupil to a parent is administrative work, but the family has to hear
 * about it: a wrong link puts another family's child on their bills and in their
 * portal. The notice is therefore shown in the parent portal with two answers —
 * "this is correct" or a note that goes back to the school office.
 *
 * It is deliberately a tenant table separate from {@see NotificationLog}, which
 * only records outbound SMS/email. This row is read by a human on a screen and
 * can be answered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'family_link_notice')]
#[ORM\Index(name: 'IDX_family_link_notice_guardian', columns: ['guardian_id'])]
class FamilyLinkNotice
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REPORTED  = 'reported';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'familyNotices')]
    #[ORM\JoinColumn(name: 'guardian_id', nullable: false, onDelete: 'CASCADE')]
    private ?Guardian $guardian = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?Student $student = null;

    /** Who made the change, for the audit trail ("bursar@school", "admin"). */
    #[ORM\Column(name: 'linked_by', length: 180, nullable: true)]
    private ?string $linkedBy = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /** The parent's answer when they think the link is wrong. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'reported_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reportedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getGuardian(): ?Guardian { return $this->guardian; }
    public function setGuardian(Guardian $guardian): static { $this->guardian = $guardian; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getLinkedBy(): ?string { return $this->linkedBy; }
    public function setLinkedBy(?string $linkedBy): static { $this->linkedBy = $linkedBy; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getStatus(): string { return $this->status; }

    public function getNote(): ?string { return $this->note; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }

    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }

    public function getReportedAt(): ?\DateTimeImmutable { return $this->reportedAt; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    public function isReported(): bool { return $this->status === self::STATUS_REPORTED; }

    public function markRead(): static
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function confirm(): static
    {
        $this->status = self::STATUS_CONFIRMED;
        $this->confirmedAt = new \DateTimeImmutable();
        $this->markRead();

        return $this;
    }

    /** The parent says the link is wrong and leaves a note for the office. */
    public function report(string $note): static
    {
        $this->status = self::STATUS_REPORTED;
        $this->note = trim($note);
        $this->reportedAt = new \DateTimeImmutable();
        $this->markRead();

        return $this;
    }
}
