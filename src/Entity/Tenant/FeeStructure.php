<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fee_structure')]
class FeeStructure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    // RELATIONSHIPS

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'classroom_id', nullable: false)] // <--- Explicit Name
    private ?Classroom $classroom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]      // <--- Explicit Name
    private ?Term $term = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'fee_item_id', nullable: false)]  // <--- Explicit Name
    private ?FeeItem $feeItem = null;

    // GETTERS AND SETTERS

    public function getId(): ?int { return $this->id; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getClassroom(): ?Classroom { return $this->classroom; }
    public function setClassroom(?Classroom $classroom): static { $this->classroom = $classroom; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(?Term $term): static { $this->term = $term; return $this; }

    public function getFeeItem(): ?FeeItem { return $this->feeItem; }
    public function setFeeItem(?FeeItem $feeItem): static { $this->feeItem = $feeItem; return $this; }
}