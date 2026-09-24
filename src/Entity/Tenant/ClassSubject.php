<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'class_subject')]
#[ORM\UniqueConstraint(name: 'UNIQ_class_subject', columns: ['subject_id', 'classroom_id', 'session_id'])]
class ClassSubject
{
    public const CATEGORY_CORE = 'core';
    public const CATEGORY_ELECTIVE = 'elective';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', nullable: false)]
    private ?Subject $subject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: false)]
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    #[ORM\Column(length: 20)]
    private ?string $category = self::CATEGORY_CORE;

    public function getId(): ?int { return $this->id; }

    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(Subject $subject): static { $this->subject = $subject; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): static { $this->session = $session; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }

    public function isCore(): bool { return $this->category === self::CATEGORY_CORE; }
    public function isElective(): bool { return $this->category === self::CATEGORY_ELECTIVE; }

    public function __toString(): string
    {
        return $this->subject?->getName() . ' — ' . $this->classroom?->getArm();
    }
}