<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discount_type')]
class DiscountType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // e.g. "Staff Child"

    // PERCENTAGE or FIXED
    #[ORM\Column(length: 50)]
    private ?string $mode = 'PERCENTAGE'; 

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $value = null; // e.g. 50.00 (for 50%) or 5000.00 (for ₦5k)

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getMode(): ?string { return $this->mode; }
    public function setMode(string $mode): static { $this->mode = $mode; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }
}