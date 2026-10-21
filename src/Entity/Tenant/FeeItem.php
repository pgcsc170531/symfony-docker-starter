<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fee_item')]
class FeeItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

   #[ORM\Column(name: 'is_optional')]
    private ?bool $isOptional = false;

    /**
     * Is this the tuition line?
     *
     * A scholarship is a waiver of tuition, never of uniforms, ID cards or
     * levies — so the discount engine has to know which line it may touch. The
     * flag is explicit, but a school that already has an item called "Tuition
     * Fee" needs no migration of habit: the name matches as well.
     */
    #[ORM\Column(name: 'is_tuition', options: ['default' => false])]
    private bool $isTuition = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = 0; // Default to 0

    // 🟢 NEW: "TERM" (Recurring) vs "ONETIME" (New Intake)
   #[ORM\Column(length: 20, options: ['default' => 'TERM'])]
    private ?string $frequency = 'TERM';

   #[ORM\Column(name: 'created_at', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function isTuition(): bool
    {
        return $this->isTuition || self::looksLikeTuition((string) $this->name);
    }

    /**
     * The name test, usable on a line that carries no fee-item link — older
     * invoices and importer-created bills store only a description.
     */
    public static function looksLikeTuition(?string $name): bool
    {
        return $name !== null && stripos($name, 'tuition') !== false;
    }

    public function setIsTuition(bool $isTuition): static
    {
        $this->isTuition = $isTuition;
        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function isOptional(): ?bool
    {
        return $this->isOptional;
    }

    public function setIsOptional(bool $isOptional): static
    {
        $this->isOptional = $isOptional;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
    public function getFrequency(): ?string { return $this->frequency; }
    public function setFrequency(string $frequency): static { $this->frequency = $frequency; return $this; }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position;
        return $this;
    }
}