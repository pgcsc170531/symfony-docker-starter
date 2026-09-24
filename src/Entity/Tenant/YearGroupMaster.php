<?php


namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'year_group_master')]
#[ORM\UniqueConstraint(name: 'UNIQ_year_group_master', columns: ['staff_id', 'year_group_id', 'session_id'])]
class YearGroupMaster
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'staff_id', nullable: false)]
    private ?Staff $staff = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'year_group_id', nullable: false)]
    private ?YearGroup $yearGroup = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'session_id', nullable: false)]
    private ?Session $session = null;

    public function getId(): ?int { return $this->id; }
    public function getStaff(): ?Staff { return $this->staff; }
    public function setStaff(Staff $staff): static { $this->staff = $staff; return $this; }
    public function getYearGroup(): ?YearGroup { return $this->yearGroup; }
    public function setYearGroup(YearGroup $yearGroup): static { $this->yearGroup = $yearGroup; return $this; }
    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): static { $this->session = $session; return $this; }
}