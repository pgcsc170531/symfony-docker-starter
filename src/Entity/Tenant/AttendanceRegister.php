<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One class, one date, one list of pupils — the register a form master takes.
 *
 * `slot` exists from day one even though phase 1 only ever writes FULL: a school
 * that insists on a morning and an afternoon sitting can be switched on without a
 * schema migration. See docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md §2.9.
 */
#[ORM\Entity]
#[ORM\Table(name: 'attendance_register')]
#[ORM\UniqueConstraint(name: 'uniq_attendance_register', columns: ['classroom_id', 'term_id', 'register_date', 'slot'])]
#[ORM\Index(name: 'idx_attendance_register_class_date', columns: ['classroom_id', 'register_date'])]
class AttendanceRegister
{
    public const SLOT_FULL = 'FULL';
    public const SLOT_AM   = 'AM';
    public const SLOT_PM   = 'PM';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Classroom::class)]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: false)]
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    /**
     * Date only, immutable on purpose.
     *
     * ⚠️ `Student::$dateOfBirth` is mapped `type: 'date'` and rejects a
     * DateTimeImmutable; that trap is avoided here by using DATE_IMMUTABLE, which
     * expects exactly what the property declares.
     */
    #[ORM\Column(name: 'register_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 8)]
    private string $slot = self::SLOT_FULL;

    #[ORM\ManyToOne(targetEntity: Staff::class)]
    #[ORM\JoinColumn(name: 'taken_by_id', nullable: true)]
    private ?Staff $takenBy = null;

    #[ORM\Column(name: 'taken_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $takenAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** Set when the term's results are released; entries become read-only. */
    #[ORM\Column(name: 'locked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(name: 'reopened_by', length: 180, nullable: true)]
    private ?string $reopenedBy = null;

    #[ORM\Column(name: 'reopen_reason', type: Types::TEXT, nullable: true)]
    private ?string $reopenReason = null;

    #[ORM\Column(name: 'reopened_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reopenedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, AttendanceEntry> */
    #[ORM\OneToMany(mappedBy: 'register', targetEntity: AttendanceEntry::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(?Term $term): static { $this->term = $term; return $this; }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getSlot(): string { return $this->slot; }
    public function setSlot(string $slot): static { $this->slot = $slot; return $this; }

    public function getTakenBy(): ?Staff { return $this->takenBy; }
    public function setTakenBy(?Staff $takenBy): static { $this->takenBy = $takenBy; return $this; }

    public function getTakenAt(): ?\DateTimeImmutable { return $this->takenAt; }
    public function setTakenAt(?\DateTimeImmutable $takenAt): static { $this->takenAt = $takenAt; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getLockedAt(): ?\DateTimeImmutable { return $this->lockedAt; }
    public function setLockedAt(?\DateTimeImmutable $lockedAt): static { $this->lockedAt = $lockedAt; return $this; }

    public function isLocked(): bool { return $this->lockedAt !== null; }

    public function getReopenedBy(): ?string { return $this->reopenedBy; }
    public function getReopenReason(): ?string { return $this->reopenReason; }
    public function getReopenedAt(): ?\DateTimeImmutable { return $this->reopenedAt; }

    public function reopen(string $by, string $reason): static
    {
        $this->lockedAt = null;
        $this->reopenedBy = $by;
        $this->reopenReason = $reason;
        $this->reopenedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, AttendanceEntry> */
    public function getEntries(): Collection { return $this->entries; }

    public function addEntry(AttendanceEntry $entry): static
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
            $entry->setRegister($this);
        }

        return $this;
    }
}
