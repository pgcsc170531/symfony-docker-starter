<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * "This pupil does not take this subject."
 *
 * The class says what the arm offers; this row says what one child does not.
 * An empty set means the pupil takes the class list, so nothing has to be
 * backfilled and a school that never uses the screen sees no change at all.
 *
 * Keyed to the ClassSubject rather than the Subject: that row already carries
 * the classroom and the session, so an exemption for "IRS in SS 1 Arts,
 * 2025/2026" cannot leak into another arm, another level or next year.
 */
#[ORM\Entity]
#[ORM\Table(name: 'student_subject_exemption')]
#[ORM\UniqueConstraint(name: 'UNIQ_student_subject_exemption', columns: ['student_id', 'class_subject_id'])]
class StudentSubjectExemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'class_subject_id', nullable: false, onDelete: 'CASCADE')]
    private ?ClassSubject $classSubject = null;

    /** Who unticked it — an exemption is a decision a parent may query in March. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by', nullable: true)]
    private ?Staff $recordedBy = null;

    #[ORM\Column(name: 'recorded_at')]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getClassSubject(): ?ClassSubject { return $this->classSubject; }
    public function setClassSubject(ClassSubject $classSubject): static { $this->classSubject = $classSubject; return $this; }

    public function getRecordedBy(): ?Staff { return $this->recordedBy; }
    public function setRecordedBy(?Staff $recordedBy): static { $this->recordedBy = $recordedBy; return $this; }

    public function getRecordedAt(): ?\DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason; return $this; }
}
