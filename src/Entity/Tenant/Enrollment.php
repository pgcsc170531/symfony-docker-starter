<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'enrollment')]
class Enrollment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(name: 'is_repeating', options: ['default' => false])]
    private ?bool $isRepeating = false;

    // RELATIONSHIPS

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: false)]
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    #[ORM\Column(name: 'enrolled_at', nullable: true)]
    private ?\DateTimeImmutable $enrolledAt = null;

    public function __construct()
    {
        $this->enrolledAt = new \DateTimeImmutable();
    }

    // GETTERS AND SETTERS

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $session): static { $this->session = $session; return $this; }

    public function getEnrolledAt(): ?\DateTimeImmutable { return $this->enrolledAt; }
    public function setEnrolledAt(?\DateTimeImmutable $enrolledAt): static { $this->enrolledAt = $enrolledAt; return $this; }

    public function isRepeating(): ?bool
    {
        return $this->isRepeating;
    }

    public function setIsRepeating(bool $isRepeating): static
    {
        $this->isRepeating = $isRepeating;
        return $this;
    }
}