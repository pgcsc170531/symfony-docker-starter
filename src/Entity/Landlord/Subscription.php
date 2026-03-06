<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subscription')]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'subscription', targetEntity: School::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    // 🟢 CHANGE: From string to Relation
    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Plan $plan = null;

    // 🟢 NEW: Track start date
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $startDate = null;

    // 🟢 RENAMED: expiresAt -> endDate
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $endDate = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'ACTIVE'; // ACTIVE, EXPIRED, GRACE

    // --- LOGIC ---
    public function isValid(): bool
    {
        return $this->endDate > new \DateTime();
    }

    public function getDaysRemaining(): int
    {
        $now = new \DateTime();
        if ($this->endDate < $now) return 0;
        return $now->diff($this->endDate)->days;
    }

    // --- GETTERS & SETTERS ---
    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(School $school): static { $this->school = $school; return $this; }

    public function getPlan(): ?Plan { return $this->plan; }
    public function setPlan(?Plan $plan): static { $this->plan = $plan; return $this; }

    public function getStartDate(): ?\DateTimeInterface { return $this->startDate; }
    public function setStartDate(\DateTimeInterface $startDate): static { $this->startDate = $startDate; return $this; }

    public function getEndDate(): ?\DateTimeInterface { return $this->endDate; }
    public function setEndDate(\DateTimeInterface $endDate): static { $this->endDate = $endDate; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
}