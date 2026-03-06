<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'lga')]
class LGA
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null; // e.g., Ikeja, Alimosho

    #[ORM\ManyToOne(inversedBy: 'lgas')]
    #[ORM\JoinColumn(nullable: false)]
    private ?State $state = null;

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getState(): ?State { return $this->state; }
    public function setState(?State $state): static { $this->state = $state; return $this; }

    public function __toString(): string { return $this->name; }
}