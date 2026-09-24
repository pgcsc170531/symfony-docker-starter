<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'result_approval')]
#[ORM\UniqueConstraint(name: 'UNIQ_result_approval', columns: ['class_subject_id', 'term_id'])]
class ResultApproval
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'class_subject_id', nullable: false)]
    private ?ClassSubject $classSubject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\Column(name: 'form_teacher_status', length: 20)]
    private string $formTeacherStatus = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'form_teacher_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $formTeacher = null;

    #[ORM\Column(name: 'form_teacher_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $formTeacherAt = null;

    #[ORM\Column(name: 'hod_status', length: 20)]
    private string $hodStatus = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'hod_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $hod = null;

    #[ORM\Column(name: 'hod_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hodAt = null;

    #[ORM\Column(name: 'year_group_status', length: 20)]
    private string $yearGroupStatus = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'year_group_master_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $yearGroupMaster = null;

    #[ORM\Column(name: 'year_group_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $yearGroupAt = null;

    public function getId(): ?int { return $this->id; }

    public function getClassSubject(): ?ClassSubject { return $this->classSubject; }
    public function setClassSubject(ClassSubject $classSubject): static { $this->classSubject = $classSubject; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getFormTeacherStatus(): string { return $this->formTeacherStatus; }
    public function setFormTeacherStatus(string $formTeacherStatus): static { $this->formTeacherStatus = $formTeacherStatus; return $this; }

    public function getFormTeacher(): ?Staff { return $this->formTeacher; }
    public function setFormTeacher(?Staff $formTeacher): static { $this->formTeacher = $formTeacher; return $this; }

    public function getFormTeacherAt(): ?\DateTimeImmutable { return $this->formTeacherAt; }
    public function setFormTeacherAt(?\DateTimeImmutable $formTeacherAt): static { $this->formTeacherAt = $formTeacherAt; return $this; }

    public function getHodStatus(): string { return $this->hodStatus; }
    public function setHodStatus(string $hodStatus): static { $this->hodStatus = $hodStatus; return $this; }

    public function getHod(): ?Staff { return $this->hod; }
    public function setHod(?Staff $hod): static { $this->hod = $hod; return $this; }

    public function getHodAt(): ?\DateTimeImmutable { return $this->hodAt; }
    public function setHodAt(?\DateTimeImmutable $hodAt): static { $this->hodAt = $hodAt; return $this; }

    public function getYearGroupStatus(): string { return $this->yearGroupStatus; }
    public function setYearGroupStatus(string $yearGroupStatus): static { $this->yearGroupStatus = $yearGroupStatus; return $this; }

    public function getYearGroupMaster(): ?Staff { return $this->yearGroupMaster; }
    public function setYearGroupMaster(?Staff $yearGroupMaster): static { $this->yearGroupMaster = $yearGroupMaster; return $this; }

    public function getYearGroupAt(): ?\DateTimeImmutable { return $this->yearGroupAt; }
    public function setYearGroupAt(?\DateTimeImmutable $yearGroupAt): static { $this->yearGroupAt = $yearGroupAt; return $this; }
}
