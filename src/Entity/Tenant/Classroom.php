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

    /**
     * What this arm is called inside its level: "A", "Science", "Chrome".
     *
     * 30 characters, not 10: schools name arms after streams and houses
     * ("Science", "Commercial", "Blue House"), and a 10-character column made
     * the onboarding wizard fail on names it had asked the school to type.
     */
    #[ORM\Column(length: 30, nullable: true)]
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