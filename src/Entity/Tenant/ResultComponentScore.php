<?php


namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'result_component_score')]
#[ORM\UniqueConstraint(name: 'UNIQ_result_component_score', columns: ['student_id', 'class_subject_id', 'term_id', 'component'])]
class ResultComponentScore
{
    public const COMPONENT_CA1 = 'ca1';
    public const COMPONENT_CA2 = 'ca2';
    public const COMPONENT_CA3 = 'ca3';
    public const COMPONENT_THEORY = 'theory';
    public const COMPONENT_PRACTICAL = 'practical';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'class_subject_id', nullable: false)]
    private ?ClassSubject $classSubject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\Column(length: 20)]
    private ?string $component = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private ?string $score = null;

    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'entered_by_id', nullable: true)]
    private ?Staff $enteredBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getClassSubject(): ?ClassSubject { return $this->classSubject; }
    public function setClassSubject(ClassSubject $classSubject): static { $this->classSubject = $classSubject; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getComponent(): ?string { return $this->component; }
    public function setComponent(string $component): static { $this->component = $component; return $this; }

    public function getScore(): ?string { return $this->score; }
    public function setScore(string $score): static { $this->score = $score; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getEnteredBy(): ?Staff { return $this->enteredBy; }
    public function setEnteredBy(?Staff $staff): static { $this->enteredBy = $staff; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(?\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}