<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'classroom')]
class Classroom
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // e.g. "JSS 1", "Primary 5"

    #[ORM\ManyToOne(inversedBy: 'classrooms')]
    #[ORM\JoinColumn(name: 'year_group_id', nullable: true)]
    private ?YearGroup $yearGroup = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $arm = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getYearGroup(): ?YearGroup { return $this->yearGroup; }
    public function setYearGroup(?YearGroup $yearGroup): static { $this->yearGroup = $yearGroup; return $this; }

    public function getArm(): ?string { return $this->arm; }
    public function setArm(?string $arm): static { $this->arm = $arm; return $this; }
}