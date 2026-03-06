<?php

namespace App\Entity\Tenant;

use App\Repository\Tenant\SchoolEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SchoolEventRepository::class)]
#[ORM\Table(name: 'school_event')]
class SchoolEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(name:'start_date', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(name:'end_date', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endDate = null;

    // Types: 'Academic', 'Holiday', 'Exam', 'Sport', 'Meeting'
    #[ORM\Column(length: 50)]
    private ?string $type = 'Academic';

    // If TRUE, this text appears in the colorful alert box at the top
    #[ORM\Column (name:'is_flash_notice', type: Types::BOOLEAN)]
    private bool $isFlashNotice = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getStartDate(): ?\DateTimeInterface { return $this->startDate; }
    public function setStartDate(\DateTimeInterface $startDate): static { $this->startDate = $startDate; return $this; }

    public function getEndDate(): ?\DateTimeInterface { return $this->endDate; }
    public function setEndDate(?\DateTimeInterface $endDate): static { $this->endDate = $endDate; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function isFlashNotice(): bool { return $this->isFlashNotice; }
    public function setIsFlashNotice(bool $isFlashNotice): static { $this->isFlashNotice = $isFlashNotice; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}