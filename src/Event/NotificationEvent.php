<?php

namespace App\Event;

use App\Entity\Landlord\School;
use Symfony\Contracts\EventDispatcher\Event;

class NotificationEvent extends Event
{
    // The unique name of the "hook"
    public const NAME = 'app.notification';

    public function __construct(
        private School $school,       // Who is sending it? (For Wallet Debit)
        private string $recipient,    // Phone Number or Email
        private string $message,      // The text content
        private string $channel = 'SMS', // SMS, WHATSAPP, or EMAIL
        private ?string $emailSubject = null // Only needed if channel is EMAIL
    ) {}

    public function getSchool(): School { return $this->school; }
    public function getRecipient(): string { return $this->recipient; }
    public function getMessage(): string { return $this->message; }
    public function getChannel(): string { return $this->channel; }
    public function getEmailSubject(): ?string { return $this->emailSubject; }
}