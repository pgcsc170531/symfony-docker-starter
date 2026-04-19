<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Entity\Tenant\Country;
use App\Entity\Tenant\State;
use App\Entity\Tenant\LGA;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Enrollment;
use App\Entity\Tenant\Invoice; // 🟢 ADDED: Invoice import

#[ORM\Entity]
#[ORM\Table(name: 'student')]
class Student
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // --- BASIC IDENTITY ---
    #[ORM\Column(name: 'first_name', length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'middle_name', length: 255, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(name: 'last_name', length: 255)]
    private ?string $lastName = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'admission_number', length: 20, unique: true, nullable: true)]
    private ?string $admissionNumber = null;

    #[ORM\Column(name: 'date_of_birth', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateOfBirth = null;

    #[ORM\Column(name: 'gender', length: 10, nullable: true)]
    private ?string $gender = null; 

    // --- DEMOGRAPHICS ---
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $religion = null; 

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: true)]
    private ?Country $nationality = null;

    #[ORM\ManyToOne(targetEntity: State::class)]
    #[ORM\JoinColumn(name: 'state_of_origin_id', referencedColumnName: 'id', nullable: true)]
    private ?State $stateOfOrigin = null;

    #[ORM\ManyToOne(targetEntity: LGA::class)]
    #[ORM\JoinColumn(name: 'lga_id', referencedColumnName: 'id', nullable: true)]
    private ?LGA $lga = null; 

    #[ORM\Column(name: 'home_town', length: 255, nullable: true)]
    private ?string $homeTown = null;

    #[ORM\Column(name: 'profile_picture_filename', length: 255, nullable: true)]
    private ?string $profilePictureFilename = null;

    // --- MEDICAL ---
    #[ORM\Column(name: 'blood_group', length: 10, nullable: true)]
    private ?string $bloodGroup = null; 

    #[ORM\Column(name: 'genotype', length: 10, nullable: true)]
    private ?string $genotype = null; 

    #[ORM\Column(name: 'medical_conditions', type: 'text', nullable: true)]
    private ?string $medicalConditions = null; 

    // --- ACADEMIC HISTORY ---
    #[ORM\Column(name: 'previous_school', length: 255, nullable: true)]
    private ?string $previousSchool = null;

    // --- RELATIONSHIPS ---
    #[ORM\ManyToOne(targetEntity: Classroom::class)]
    #[ORM\JoinColumn(name: 'current_class_id', referencedColumnName: 'id', nullable: true)]
    private ?Classroom $currentClass = null;

    #[ORM\ManyToOne(targetEntity: Classroom::class)]
    #[ORM\JoinColumn(name: 'current_classroom_id', nullable: true, onDelete: 'SET NULL')]
    private ?Classroom $currentClassroom = null;

    #[ORM\ManyToOne(inversedBy: 'students')]
    #[ORM\JoinColumn(name: 'guardian_id', referencedColumnName: 'id', nullable: true)]
    private ?Guardian $guardian = null;

    #[ORM\OneToMany(mappedBy: 'student', targetEntity: Enrollment::class)]
    private Collection $enrollments;

    // 🟢 ADDED: Invoices collection so the student knows what they owe
    #[ORM\OneToMany(mappedBy: 'student', targetEntity: Invoice::class)]
    private Collection $invoices;

    public function __construct()
    {
        $this->enrollments = new ArrayCollection();
        $this->invoices = new ArrayCollection(); // 🟢 ADDED: Initialize the collection
        $this->createdAt = new \DateTimeImmutable();
    }

    // --- GETTERS AND SETTERS ---

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getMiddleName(): ?string { return $this->middleName; }
    public function setMiddleName(?string $middleName): static { $this->middleName = $middleName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getFullName(): string {
        return $this->firstName . ' ' . ($this->middleName ? $this->middleName . ' ' : '') . $this->lastName;
    }

    public function getAdmissionNumber(): ?string { return $this->admissionNumber; }
    public function setAdmissionNumber(?string $admissionNumber): static { $this->admissionNumber = $admissionNumber; return $this; }

    public function getDateOfBirth(): ?\DateTimeInterface { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeInterface $dateOfBirth): static { $this->dateOfBirth = $dateOfBirth; return $this; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): static { $this->gender = $gender; return $this; }

    // Demographics
    public function getReligion(): ?string { return $this->religion; }
    public function setReligion(?string $religion): static { $this->religion = $religion; return $this; }

    public function getNationality(): ?Country { return $this->nationality; }
    public function setNationality(?Country $nationality): static { $this->nationality = $nationality; return $this; }

    public function getStateOfOrigin(): ?State { return $this->stateOfOrigin; }
    public function setStateOfOrigin(?State $stateOfOrigin): static { $this->stateOfOrigin = $stateOfOrigin; return $this; }

    public function getLga(): ?LGA { return $this->lga; }
    public function setLga(?LGA $lga): static { $this->lga = $lga; return $this; }

    public function getHomeTown(): ?string { return $this->homeTown; }
    public function setHomeTown(?string $homeTown): static { $this->homeTown = $homeTown; return $this; }

    // Medical
    public function getBloodGroup(): ?string { return $this->bloodGroup; }
    public function setBloodGroup(?string $bloodGroup): static { $this->bloodGroup = $bloodGroup; return $this; }

    public function getGenotype(): ?string { return $this->genotype; }
    public function setGenotype(?string $genotype): static { $this->genotype = $genotype; return $this; }

    public function getMedicalConditions(): ?string { return $this->medicalConditions; }
    public function setMedicalConditions(?string $medicalConditions): static { $this->medicalConditions = $medicalConditions; return $this; }

    public function getPreviousSchool(): ?string { return $this->previousSchool; }
    public function setPreviousSchool(?string $previousSchool): static { $this->previousSchool = $previousSchool; return $this; }

    // Relationships
    public function getEnrollments(): Collection { return $this->enrollments; }

    public function getCurrentClass(): ?Classroom { return $this->currentClass; }
    public function setCurrentClass(?Classroom $currentClass): static { $this->currentClass = $currentClass; return $this; }

    public function getGuardian(): ?Guardian { return $this->guardian; }
    public function setGuardian(?Guardian $guardian): static { $this->guardian = $guardian; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getProfilePictureFilename(): ?string
    {
        return $this->profilePictureFilename;
    }

    public function setProfilePictureFilename(?string $profilePictureFilename): static
    {
        $this->profilePictureFilename = $profilePictureFilename;
        return $this;
    }

    public function getCurrentClassroom(): ?Classroom
    {
        return $this->currentClassroom;
    }

    public function setCurrentClassroom(?Classroom $currentClassroom): self
    {
        $this->currentClassroom = $currentClassroom;
        return $this;
    }

    // ======================================================
    // 🟢 ADDED: FINANCE QUICK CHECK HELPER METHODS
    // ======================================================

    /**
     * Get all invoices belonging to this student
     */
    public function getInvoices(): Collection 
    { 
        return $this->invoices; 
    }

    /**
     * Calculates the total unpaid amount across all invoices for this student.
     */
    public function getOutstandingBalance(): float
    {
        $totalDebt = 0.0;

        if ($this->invoices->isEmpty()) {
            return 0.0;
        }

        foreach ($this->invoices as $invoice) {
            $status = trim(strtoupper($invoice->getStatus()));
            
            if ($status !== 'PAID') {
                $total = (float) $invoice->getTotalAmount();
                $paid = (float) $invoice->getPaidAmount();
                
                $totalDebt += ($total - $paid);
            }
        }

        return $totalDebt;
    }
}