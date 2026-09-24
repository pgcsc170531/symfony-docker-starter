<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'grading_scale')]
class GradingScale
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $grade = null;

    #[ORM\Column(name: 'min_score')]
    private ?float $minScore = null;

    #[ORM\Column(name: 'max_score')]
    private ?float $maxScore = null;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    private ?string $points = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $remark = null;

    #[ORM\Column(name: 'sort_order', nullable: true)]
    private ?int $sortOrder = null;

    public function getId(): ?int { return $this->id; }

    public function getGrade(): ?string { return $this->grade; }
    public function setGrade(string $grade): static { $this->grade = $grade; return $this; }

    public function getMinScore(): ?float { return $this->minScore; }
    public function setMinScore(float $minScore): static { $this->minScore = $minScore; return $this; }

    public function getMaxScore(): ?float { return $this->maxScore; }
    public function setMaxScore(float $maxScore): static { $this->maxScore = $maxScore; return $this; }

    public function getPoints(): ?string { return $this->points; }
    public function setPoints(?string $points): static { $this->points = $points; return $this; }

    public function getRemark(): ?string { return $this->remark; }
    public function setRemark(?string $remark): static { $this->remark = $remark; return $this; }

    public function getSortOrder(): ?int { return $this->sortOrder; }
    public function setSortOrder(?int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }

    public function __toString(): string { return (string) $this->grade; }
}