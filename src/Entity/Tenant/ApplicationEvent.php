<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * Application timeline — one row per status change.
 *
 * This is the accountability trail behind the parent's status page and the
 * bursar's review screen: who moved the application, when, and why.
 */
#[ORM\Entity]
#[ORM\Table(name: 'application_event')]
#[ORM\Index(name: 'IDX_application_event_application', columns: ['application_id'])]
class ApplicationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')]
    private ?Application $application = null;

    #[ORM\Column(name: 'from_status', length: 20, nullable: true)]
    private ?string $fromStatus = null;

    #[ORM\Column(name: 'to_status', length: 20)]
    private ?string $toStatus = null;

    /** Null when the actor is the system (e.g. auto-expiry). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'actor_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Human label kept even if the user is later deleted: "Parent", "Bursar: A. Musa", "System". */
    #[ORM\Column(name: 'actor_label', length: 100)]
    private ?string $actorLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getApplication(): ?Application { return $this->application; }
    public function setApplication(?Application $application): static { $this->application = $application; return $this; }

    public function getFromStatus(): ?string { return $this->fromStatus; }
    public function setFromStatus(?string $fromStatus): static { $this->fromStatus = $fromStatus; return $this; }

    public function getToStatus(): ?string { return $this->toStatus; }
    public function setToStatus(string $toStatus): static { $this->toStatus = $toStatus; return $this; }

    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): static { $this->actor = $actor; return $this; }

    public function getActorLabel(): ?string { return $this->actorLabel; }
    public function setActorLabel(?string $actorLabel): static { $this->actorLabel = $actorLabel; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    /** Label shown on the timeline. */
    public function getLabel(): string
    {
        $labels = [
            Application::STATUS_DRAFT            => 'Started',
            Application::STATUS_AWAITING_PAYMENT => 'Awaiting application fee',
            Application::STATUS_SUBMITTED        => 'Submitted',
            Application::STATUS_UNDER_REVIEW     => 'Under review',
            Application::STATUS_NEEDS_INFO       => 'Information requested',
            Application::STATUS_APPROVED         => 'Approved',
            Application::STATUS_ENROLLED         => 'Enrolled',
            Application::STATUS_REJECTED         => 'Rejected',
            Application::STATUS_WITHDRAWN        => 'Withdrawn',
            Application::STATUS_WAITLISTED       => 'Waitlisted',
        ];

        return $labels[$this->toStatus] ?? ucfirst((string) $this->toStatus);
    }
}
