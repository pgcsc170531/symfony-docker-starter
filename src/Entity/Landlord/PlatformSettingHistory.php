<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

/**
 * Who changed which platform setting, and when — never what it was changed to.
 *
 * A second copy of a secret is a second thing to leak, so this table records the
 * fact of the change (and whether it was set or cleared) and nothing else. It
 * answers the first question of any incident: "who rotated the live key, and when?"
 */
#[ORM\Entity]
#[ORM\Table(name: 'platform_setting_history')]
#[ORM\Index(name: 'idx_setting_history_key', columns: ['setting_key'])]
class PlatformSettingHistory
{
    public const ACTION_SET = 'set';
    public const ACTION_CLEARED = 'cleared';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 100)]
    private ?string $settingKey = null;

    #[ORM\Column(length: 20)]
    private string $action = self::ACTION_SET;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actor = null;

    #[ORM\Column(name: 'changed_at')]
    private \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSettingKey(): ?string { return $this->settingKey; }
    public function setSettingKey(string $settingKey): static { $this->settingKey = $settingKey; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): static { $this->action = $action; return $this; }

    public function getActor(): ?string { return $this->actor; }
    public function setActor(?string $actor): static { $this->actor = $actor; return $this; }

    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
