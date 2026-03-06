<?php

namespace App\Entity\Tenant;

use App\Repository\Tenant\NotificationLogRepository; // Optional, can be removed if you don't have a repo class yet
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_log')]
class NotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $channel = null; // SMS, WHATSAPP, EMAIL

    #[ORM\Column(length: 255)]
    private ?string $recipient = null;

    #[ORM\Column(length: 255)]
    private ?string $message = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null; // SENT, FAILED, QUEUED

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $cost = '0.00';

    #[ORM\Column(name: 'created_at',type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // --- GETTERS AND SETTERS ---

    public function getId(): ?int { return $this->id; }

    public function getChannel(): ?string { return $this->channel; }
    public function setChannel(string $channel): static { $this->channel = $channel; return $this; }

    public function getRecipient(): ?string { return $this->recipient; }
    public function setRecipient(string $recipient): static { $this->recipient = $recipient; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCost(): ?string { return $this->cost; }
    public function setCost(string $cost): static { $this->cost = $cost; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
}