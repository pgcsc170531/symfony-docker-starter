<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'country')]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null; // e.g., Nigeria, Ghana

    #[ORM\Column(name: 'iso_code', length: 5)]
    private ?string $isoCode = null; // e.g., NG, GH

    /**
     * What this country calls its first-level division: "State" (Nigeria),
     * "Governorate" (Iraq), "Province" (South Africa), "Region" (Ghana).
     * Rendered as the label of the state-of-origin field, so a school abroad is
     * never asked for a "State" it does not have.
     */
    #[ORM\Column(name: 'region_label', length: 40)]
    private string $regionLabel = 'Region';

    #[ORM\OneToMany(mappedBy: 'country', targetEntity: State::class, cascade: ['persist', 'remove'])]
    private Collection $states;

    public function __construct() {
        $this->states = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getIsoCode(): ?string { return $this->isoCode; }
    public function setIsoCode(string $isoCode): static { $this->isoCode = $isoCode; return $this; }

    public function getRegionLabel(): string
    {
        return trim($this->regionLabel) !== '' ? $this->regionLabel : 'Region';
    }

    public function setRegionLabel(string $regionLabel): static
    {
        $this->regionLabel = trim($regionLabel) !== '' ? trim($regionLabel) : 'Region';

        return $this;
    }

    /**
     * @return Collection<int, State>
     */
    public function getStates(): Collection
    {
        return $this->states;
    }

    public function addState(State $state): static
    {
        if (!$this->states->contains($state)) {
            $this->states->add($state);
            $state->setCountry($this);
        }

        return $this;
    }

    // Helper for dropdowns
    public function __toString(): string { return $this->name ?? ''; }
}