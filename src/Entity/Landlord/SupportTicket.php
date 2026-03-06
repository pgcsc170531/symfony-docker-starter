<?php

namespace App\Entity\Landlord;

use App\Entity\Landlord\School;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// 🟢 FIX: Remove repositoryClass
#[ORM\Entity]
#[ORM\Table(name: 'support_ticket')]
class SupportTicket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?School $school = null;

    #[ORM\Column(length: 255)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'open';

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ... (Keep your Getters and Setters) ...
    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(?School $school): static { $this->school = $school; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}