<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'assessment_scheme')]
class AssessmentScheme
{
    public const EXAM_MODE_SINGLE = 'single';
    public const EXAM_MODE_DUAL = 'dual';

    public const CA_COUNT_TWO = 2;
    public const CA_COUNT_THREE = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: true)]
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', nullable: true)]
    private ?Subject $subject = null;

    #[ORM\Column(name: 'ca_count')]
    private ?int $caCount = self::CA_COUNT_THREE;

    #[ORM\Column(name: 'exam_mode', length: 20)]
    private ?string $examMode = self::EXAM_MODE_SINGLE;

    #[ORM\Column(name: 'ca_max')]
    private ?int $caMax = 20;

    #[ORM\Column(name: 'theory_max')]
    private ?int $theoryMax = 60;

    #[ORM\Column(name: 'practical_max')]
    private ?int $practicalMax = 40;

    public function getId(): ?int { return $this->id; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): static { $this->session = $session; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(?Subject $subject): static { $this->subject = $subject; return $this; }

    public function getCaCount(): ?int { return $this->caCount; }
    public function setCaCount(int $caCount): static { $this->caCount = $caCount; return $this; }

    public function getExamMode(): ?string { return $this->examMode; }
    public function setExamMode(string $examMode): static { $this->examMode = $examMode; return $this; }

    public function getCaMax(): ?int { return $this->caMax; }
    public function setCaMax(int $caMax): static { $this->caMax = $caMax; return $this; }

    public function getTheoryMax(): ?int { return $this->theoryMax; }
    public function setTheoryMax(int $theoryMax): static { $this->theoryMax = $theoryMax; return $this; }

    public function getPracticalMax(): ?int { return $this->practicalMax; }
    public function setPracticalMax(int $practicalMax): static { $this->practicalMax = $practicalMax; return $this; }
}