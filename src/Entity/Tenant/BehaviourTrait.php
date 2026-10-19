<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * A rateable trait — "Punctuality", "Handwriting", and so on.
 *
 * Seeded with the standard Nigerian affective + psychomotor lists, then owned by the
 * school: rename, reorder, disable, add. A trait is **never deleted**, only hidden,
 * because disabling one must not orphan the ratings already printed on last term's
 * cards. See ATTENDANCE-AND-BEHAVIOUR-DESIGN.md §3.5.
 */
#[ORM\Entity]
#[ORM\Table(name: 'behaviour_trait')]
#[ORM\Index(name: 'idx_behaviour_trait_domain', columns: ['domain', 'is_active'])]
class BehaviourTrait
{
    public const AFFECTIVE   = 'AFFECTIVE';
    public const PSYCHOMOTOR = 'PSYCHOMOTOR';

    public const DOMAINS = [
        self::AFFECTIVE   => 'Affective domain',
        self::PSYCHOMOTOR => 'Psychomotor domain',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $domain = self::AFFECTIVE;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function __construct(string $domain = self::AFFECTIVE, string $label = '', int $sortOrder = 0)
    {
        $this->domain = $domain;
        $this->label = $label;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }

    public function getDomain(): string { return $this->domain; }
    public function setDomain(string $domain): static { $this->domain = $domain; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
