<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'name', length: 255)]
    private ?string $name = null; // e.g. "Stabilo Pen", "JSS 1 Uniform"

    // UNIFORM, BOOK, STATIONERY, UTILITY
    #[ORM\Column(name: 'category', length: 50)]
    private ?string $category = 'STATIONERY';

    #[ORM\Column(name: 'stock_quantity')]
    private ?int $stockQuantity = 0;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    // Optional: Is this product specific to a class? (e.g. JSS 1 Maths)
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: true)]
    private ?Classroom $classroom = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }

    public function getStockQuantity(): ?int { return $this->stockQuantity; }
    public function setStockQuantity(int $stockQuantity): static { $this->stockQuantity = $stockQuantity; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }
}