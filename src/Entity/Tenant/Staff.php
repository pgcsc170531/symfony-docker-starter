<?php


namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'staff')]
class Staff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'staff', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'employee_number', length: 50, nullable: true)]
    private ?string $employeeNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $qualification = null;

    #[ORM\Column(name: 'photo_filename', length: 255, nullable: true)]
    private ?string $photoFilename = null;

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): static { $this->gender = $gender; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getEmployeeNumber(): ?string { return $this->employeeNumber; }
    public function setEmployeeNumber(?string $employeeNumber): static { $this->employeeNumber = $employeeNumber; return $this; }

    public function getQualification(): ?string { return $this->qualification; }
    public function setQualification(?string $qualification): static { $this->qualification = $qualification; return $this; }

    public function getPhotoFilename(): ?string { return $this->photoFilename; }
    public function setPhotoFilename(?string $photoFilename): static { $this->photoFilename = $photoFilename; return $this; }

    public function __toString(): string
    {
        return $this->user?->getFullName() ?? (string) $this->id;
    }
}