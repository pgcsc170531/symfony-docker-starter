<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'department_head')]
#[ORM\UniqueConstraint(name: 'UNIQ_department_head', columns: ['staff_id', 'department_id', 'session_id'])]
class DepartmentHead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'staff_id', nullable: false)]
    private ?Staff $staff = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'department_id', nullable: false)]
    private ?Department $department = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    public function getId(): ?int { return $this->id; }
    public function getStaff(): ?Staff { return $this->staff; }
    public function setStaff(Staff $staff): static { $this->staff = $staff; return $this; }
    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(Department $department): static { $this->department = $department; return $this; }
    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): static { $this->session = $session; return $this; }
}