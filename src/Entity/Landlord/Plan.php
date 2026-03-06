<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plan')]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // e.g., "Starter"

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $price = null; // e.g., 50000.00

    #[ORM\Column(options: ["default" => 0])]
    private ?int $minStudents = 0;

    #[ORM\Column(options: ["default" => false])]
    private ?bool $isTrial = false;

    #[ORM\Column(nullable: true)]
    private ?int $maxStudents = null; // Null means Unlimited

    #[ORM\Column(options: ["default" => 4])]
    private ?int $durationMonths = 4; // Default to 1 Term

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ["default" => 0])]
    private ?string $freeCreditAmount = '0.00'; // The WhatsApp Bonus

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getPrice(): ?string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }

    public function getMinStudents(): ?int { return $this->minStudents; }
    public function setMinStudents(int $minStudents): static { $this->minStudents = $minStudents; return $this; }

    public function getMaxStudents(): ?int { return $this->maxStudents; }
    public function setMaxStudents(?int $maxStudents): static { $this->maxStudents = $maxStudents; return $this; }

    public function getDurationMonths(): ?int { return $this->durationMonths; }
    public function setDurationMonths(int $durationMonths): static { $this->durationMonths = $durationMonths; return $this; }

    public function isTrial(): ?bool {return $this->isTrial;}
    public function setIsTrial(bool $isTrial): static {$this->isTrial = $isTrial; return $this;}

    public function getFreeCreditAmount(): ?string { return $this->freeCreditAmount; }
    public function setFreeCreditAmount(string $freeCreditAmount): static { $this->freeCreditAmount = $freeCreditAmount; return $this; }
}