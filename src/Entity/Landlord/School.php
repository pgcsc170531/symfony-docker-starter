<?php

namespace App\Entity\Landlord;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'school')]
#[UniqueEntity(fields: ['subdomain'], message: 'This subdomain is already being used by another school.')]
class School
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // This is the subdomain (e.g. "hopehigh")
    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 20)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+$/',
        message: 'Subdomain can only contain lowercase letters and numbers (no spaces).'
    )]
    private ?string $subdomain = null;

    // This is the specific database name for this school (e.g. "school_a")
    #[ORM\Column(length: 255)]
    private ?string $databaseName = null;

    #[ORM\Column]
    private bool $isActive = true;

    // Getters and Setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): self
    {
        $this->subdomain = $subdomain;
        return $this;
    }

    public function getDatabaseName(): ?string
    {
        return $this->databaseName;
    }

    public function setDatabaseName(string $databaseName): self
    {
        $this->databaseName = $databaseName;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }
}