<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subject_teacher')]
#[ORM\UniqueConstraint(name: 'UNIQ_subject_teacher', columns: ['staff_id', 'subject_id', 'classroom_id', 'term_id'])]
class SubjectTeacher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'staff_id', nullable: false)]
    private ?Staff $staff = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', nullable: false)]
    private ?Subject $subject = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: false)]
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    public function getId(): ?int { return $this->id; }
    public function getStaff(): ?Staff { return $this->staff; }
    public function setStaff(Staff $staff): static { $this->staff = $staff; return $this; }
    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(Subject $subject): static { $this->subject = $subject; return $this; }
    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(Classroom $classroom): static { $this->classroom = $classroom; return $this; }
    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }
}