<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The history of a corrected attendance entry.
 *
 * A parent dispute is answered by a record, not by a teacher's memory: every status
 * change after the first save is written here (old → new, who, when). Nothing is
 * ever deleted. See ATTENDANCE-AND-BEHAVIOUR-DESIGN.md §2.6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attendance_change')]
#[ORM\Index(name: 'idx_attendance_change_entry', columns: ['entry_id'])]
class AttendanceChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttendanceEntry::class)]
    #[ORM\JoinColumn(name: 'entry_id', nullable: false, onDelete: 'CASCADE')]
    private ?AttendanceEntry $entry = null;

    #[ORM\Column(name: 'from_status', length: 1)]
    private string $fromStatus;

    #[ORM\Column(name: 'to_status', length: 1)]
    private string $toStatus;

    #[ORM\Column(name: 'changed_by', length: 180)]
    private string $changedBy;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    public function __construct(
        AttendanceEntry $entry,
        string $fromStatus,
        string $toStatus,
        string $changedBy,
        ?string $reason = null,
    ) {
        $this->entry = $entry;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->changedBy = $changedBy;
        $this->reason = $reason;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEntry(): ?AttendanceEntry { return $this->entry; }
    public function getFromStatus(): string { return $this->fromStatus; }
    public function getToStatus(): string { return $this->toStatus; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
    public function getReason(): ?string { return $this->reason; }
}
