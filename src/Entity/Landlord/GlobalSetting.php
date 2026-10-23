<?php

namespace App\Entity\Landlord;

use App\Repository\Landlord\GlobalSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GlobalSettingRepository::class)]
#[ORM\Table(name: 'global_settings')]
class GlobalSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'setting_key', length: 100, unique: true)]
    private ?string $settingKey = null;

    /**
     * TEXT, not varchar(255): a secret is stored encrypted (nonce + tag + ciphertext),
     * and a value silently truncated at 255 characters is a key that fails at 3am.
     */
    #[ORM\Column(name: 'setting_value', type: Types::TEXT, nullable: true)]
    private ?string $settingValue = null;

    /**
     * A secret is never rendered back to the browser, never exported and never logged.
     * Its value is stored encrypted by App\Service\Settings\SecretCipher.
     */
    #[ORM\Column(name: 'is_secret', options: ['default' => false])]
    private bool $isSecret = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): ?string
    {
        return $this->settingKey;
    }

    public function setSettingKey(string $settingKey): static
    {
        $this->settingKey = $settingKey;
        return $this;
    }

    public function getSettingValue(): ?string
    {
        return $this->settingValue;
    }

    public function setSettingValue(string $settingValue): static
    {
        $this->settingValue = $settingValue;
        return $this;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function setIsSecret(bool $isSecret): static
    {
        $this->isSecret = $isSecret;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?string $updatedBy): static
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
}