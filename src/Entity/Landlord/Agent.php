<?php

namespace App\Entity\Landlord;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

// 👇 UPDATED: Removed (repositoryClass: ...) logic
#[ORM\Entity]
// 👇 KEPT: This ensures email uniqueness checks the correct database
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email', em: 'landlord')]
class Agent implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: "full_name", length: 255)]
    private ?string $fullName = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(name: "phone_number", length: 20, nullable: true)]
    private ?string $phoneNumber = null;

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    // 💰 COMMISSION CONFIG
    #[ORM\Column(name: "commission_percentage", type: Types::DECIMAL, precision: 5, scale: 2)]
    private ?string $commissionPercentage = '10.00'; 

    // 🏦 BANK DETAILS
    #[ORM\Column(name: "bank_details", type: Types::TEXT, nullable: true)]
    private ?string $bankDetails = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    // 🔗 RELATIONSHIP
    #[ORM\OneToMany(mappedBy: 'agent', targetEntity: School::class)]
    private Collection $schools;

    public function __construct()
    {
        $this->schools = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = ['ROLE_AGENT'];
    }

    // --- GETTERS & SETTERS ---

    public function getId(): ?int { return $this->id; }
    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(string $fullName): static { $this->fullName = $fullName; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getUserIdentifier(): string { return (string) $this->email; }
    public function getRoles(): array { 
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}
    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $phoneNumber): static { $this->phoneNumber = $phoneNumber; return $this; }
    public function getCommissionPercentage(): ?string { return $this->commissionPercentage; }
    public function setCommissionPercentage(string $commissionPercentage): static { $this->commissionPercentage = $commissionPercentage; return $this; }
    public function getBankDetails(): ?string { return $this->bankDetails; }
    public function setBankDetails(?string $bankDetails): static { $this->bankDetails = $bankDetails; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getSchools(): Collection { return $this->schools; }
    public function addSchool(School $school): static {
        if (!$this->schools->contains($school)) {
            $this->schools->add($school);
            $school->setAgent($this);
        }
        return $this;
    }
    public function removeSchool(School $school): static {
        if ($this->schools->removeElement($school)) {
            if ($school->getAgent() === $this) {
                $school->setAgent(null);
            }
        }
        return $this;
    }
}