<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pupil's status on one register.
 *
 * Four statuses and no more — see ATTENDANCE-AND-BEHAVIOUR-DESIGN.md §2.4.
 * `Excused` counts as absent on the card but is reported separately, so the reason
 * is visible without turning into a fifth column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attendance_entry')]
#[ORM\UniqueConstraint(name: 'uniq_attendance_entry', columns: ['register_id', 'student_id'])]
#[ORM\Index(name: 'idx_attendance_entry_student', columns: ['student_id'])]
class AttendanceEntry
{
    public const PRESENT = 'P';
    public const ABSENT  = 'A';
    public const LATE    = 'L';
    public const EXCUSED = 'E';

    public const STATUSES = [
        self::PRESENT => 'Present',
        self::ABSENT  => 'Absent',
        self::LATE    => 'Late',
        self::EXCUSED => 'Excused',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttendanceRegister::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'register_id', nullable: false, onDelete: 'CASCADE')]
    private ?AttendanceRegister $register = null;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    /** P | A | L | E */
    #[ORM\Column(length: 1)]
    private string $status = self::PRESENT;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'first_marked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstMarkedAt;

    #[ORM\Column(name: 'last_changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastChangedAt = null;

    #[ORM\Column(name: 'last_changed_by', length: 180, nullable: true)]
    private ?string $lastChangedBy = null;

    public function __construct()
    {
        $this->firstMarkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRegister(): ?AttendanceRegister { return $this->register; }
    public function setRegister(?AttendanceRegister $register): static { $this->register = $register; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getFirstMarkedAt(): \DateTimeImmutable { return $this->firstMarkedAt; }
    public function setFirstMarkedAt(\DateTimeImmutable $at): static { $this->firstMarkedAt = $at; return $this; }

    public function getLastChangedAt(): ?\DateTimeImmutable { return $this->lastChangedAt; }
    public function getLastChangedBy(): ?string { return $this->lastChangedBy; }

    public function touch(string $by): static
    {
        $this->lastChangedAt = new \DateTimeImmutable();
        $this->lastChangedBy = $by;

        return $this;
    }

    /** Does this status count towards the "present" figure on the card? */
    public function countsAsPresent(): bool
    {
        return in_array($this->status, [self::PRESENT, self::LATE], true);
    }
}
