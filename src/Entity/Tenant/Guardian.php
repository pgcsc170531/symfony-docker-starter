<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guardian')]
class Guardian
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $title = null; // Mr, Mrs, Dr, Chief, Engr

    #[ORM\Column(name: 'full_name', length: 255)]
    private ?string $fullName = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'phone_number', length: 20)]
    private ?string $phoneNumber = null;

    #[ORM\Column(name: 'alternate_phone_number', length: 20, nullable: true)]
    private ?string $alternatePhoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null; // Residential Address

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $occupation = null;

    #[ORM\Column(name: 'office_address', type: 'text', nullable: true)]
    private ?string $officeAddress = null;

    #[ORM\Column(name: 'relationship_to_student', length: 50, nullable: true)]
    private ?string $relationshipToStudent = null; // Father, Mother, Uncle, Guardian

    // 🆕 Online application — how this parent record was created & verified
    #[ORM\Column(length: 20, options: ['default' => 'bursar'])]
    private ?string $source = 'bursar'; // bursar | self

    #[ORM\Column(name: 'is_verified', options: ['default' => false])]
    private ?bool $isVerified = false;

    #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'email_verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'last_application_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastApplicationAt = null;

    // Link to Login Account
    #[ORM\OneToOne(targetEntity: User::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\OneToMany(mappedBy: 'guardian', targetEntity: Student::class)]
    private Collection $students;

    /** @var Collection<int, Application> Every child this family has applied for (siblings). */
    #[ORM\OneToMany(mappedBy: 'guardian', targetEntity: Application::class)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $applications;

    public function __construct()
    {
        $this->students = new ArrayCollection();
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(string $phoneNumber): static { $this->phoneNumber = $phoneNumber; return $this; }

    public function getAlternatePhoneNumber(): ?string { return $this->alternatePhoneNumber; }
    public function setAlternatePhoneNumber(?string $alternatePhoneNumber): static { $this->alternatePhoneNumber = $alternatePhoneNumber; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): static { $this->address = $address; return $this; }

    public function getOccupation(): ?string { return $this->occupation; }
    public function setOccupation(?string $occupation): static { $this->occupation = $occupation; return $this; }

    public function getOfficeAddress(): ?string { return $this->officeAddress; }
    public function setOfficeAddress(?string $officeAddress): static { $this->officeAddress = $officeAddress; return $this; }

    public function getRelationshipToStudent(): ?string { return $this->relationshipToStudent; }
    public function setRelationshipToStudent(?string $relationshipToStudent): static { $this->relationshipToStudent = $relationshipToStudent; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    // ── Online application ────────────────────────────────────────────
    public function getSource(): ?string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    public function isVerified(): bool { return (bool) $this->isVerified; }
    public function setIsVerified(bool $isVerified): static { $this->isVerified = $isVerified; return $this; }

    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static { $this->verifiedAt = $verifiedAt; return $this; }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static { $this->emailVerifiedAt = $emailVerifiedAt; return $this; }

    public function getLastApplicationAt(): ?\DateTimeImmutable { return $this->lastApplicationAt; }
    public function setLastApplicationAt(?\DateTimeImmutable $lastApplicationAt): static { $this->lastApplicationAt = $lastApplicationAt; return $this; }

    /** @return Collection<int, Application> */
    public function getApplications(): Collection { return $this->applications; }

    public function addApplication(Application $application): static
    {
        if (!$this->applications->contains($application)) {
            $this->applications->add($application);
            $application->setGuardian($this);
        }
        return $this;
    }

    public function removeApplication(Application $application): static
    {
        // Application.guardian is NOT NULL, so we only detach it from this
        // collection — the Application entity owns the relationship.
        $this->applications->removeElement($application);

        return $this;
    }

    public function getStudents(): Collection { return $this->students; }

    public function addStudent(Student $student): static
    {
        if (!$this->students->contains($student)) {
            $this->students->add($student);
            $student->setGuardian($this);
        }
        return $this;
    }

    public function removeStudent(Student $student): static
    {
        if ($this->students->removeElement($student)) {
            if ($student->getGuardian() === $this) {
                $student->setGuardian(null);
            }
        }
        return $this;
    }
}