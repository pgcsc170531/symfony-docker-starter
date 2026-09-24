<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'student_subject')]
#[ORM\UniqueConstraint(name: 'UNIQ_student_subject', columns: ['student_id', 'class_subject_id'])]
class StudentSubject
{
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

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getClassSubject(): ?ClassSubject { return $this->classSubject; }
    public function setClassSubject(ClassSubject $classSubject): static { $this->classSubject = $classSubject; return $this; }
}