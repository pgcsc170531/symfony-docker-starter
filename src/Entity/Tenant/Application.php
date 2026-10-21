<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Parent Application — one row per CHILD being applied for.
 *
 * A family with three children = three Application rows sharing one Guardian,
 * which is what lets the bursar see "applications from this family" (siblings).
 *
 * ⚠️ An Application is NOT a Student. The Student row is only created when a
 * bursar approves & enrols the application (see AdmissionService::admit()).
 * That guarantee keeps applicants out of the registry, results, store and
 * invoices until they are actually admitted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'application')]
#[ORM\UniqueConstraint(name: 'UNIQ_application_reference', columns: ['reference'])]
#[ORM\Index(name: 'IDX_application_status', columns: ['status'])]
#[ORM\Index(name: 'IDX_application_guardian', columns: ['guardian_id'])]
class Application
{
    // ── Workflow status ────────────────────────────────────────────────
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_AWAITING_PAYMENT  = 'awaiting_payment';
    public const STATUS_SUBMITTED         = 'submitted';
    public const STATUS_UNDER_REVIEW      = 'under_review';
    public const STATUS_NEEDS_INFO        = 'needs_info';
    public const STATUS_APPROVED          = 'approved';
    public const STATUS_ENROLLED          = 'enrolled';
    public const STATUS_REJECTED          = 'rejected';
    public const STATUS_WITHDRAWN         = 'withdrawn';
    public const STATUS_WAITLISTED        = 'waitlisted';

    // ── Where the application came from ────────────────────────────────
    public const SOURCE_ONLINE        = 'online';
    public const SOURCE_PARENT_PORTAL = 'parent_portal';
    public const SOURCE_BURSAR        = 'bursar_entry';

    // ── Application (form) fee state ───────────────────────────────────
    public const FEE_UNPAID                = 'unpaid';
    public const FEE_AWAITING_CONFIRMATION = 'awaiting_confirmation'; // offline proof uploaded
    public const FEE_PAID                  = 'paid';
    public const FEE_WAIVED                = 'waived';

    // ── How the fee was paid ───────────────────────────────────────────
    public const CHANNEL_OFFLINE = 'offline_transfer';
    public const CHANNEL_ONLINE  = 'online_gateway';
    public const CHANNEL_CASH    = 'cash';

    /** Number of steps in the parent-facing wizard (used by Alpine + progress bars). */
    public const TOTAL_STEPS = 5;

    /** Statuses the parent may still edit/resubmit. */
    public const PARENT_EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_NEEDS_INFO];

    /** Statuses that end the lifecycle. */
    public const FINAL_STATUSES = [self::STATUS_ENROLLED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Assigned on submit: APP/2026/0001 — null while the parent is still drafting. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20)]
    private ?string $source = self::SOURCE_ONLINE;

    // ── Applicant (all nullable: a draft is an incomplete form) ────────
    #[ORM\Column(name: 'first_name', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'middle_name', length: 100, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(name: 'last_name', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(name: 'date_of_birth', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateOfBirth = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $religion = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'nationality_id', nullable: true, onDelete: 'SET NULL')]
    private ?Country $nationality = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'state_of_origin_id', nullable: true, onDelete: 'SET NULL')]
    private ?State $stateOfOrigin = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'lga_id', nullable: true, onDelete: 'SET NULL')]
    private ?LGA $lga = null;

    #[ORM\Column(name: 'home_town', length: 100, nullable: true)]
    private ?string $homeTown = null;

    // ── Health ─────────────────────────────────────────────────────────
    #[ORM\Column(name: 'blood_group', length: 10, nullable: true)]
    private ?string $bloodGroup = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $genotype = null;

    #[ORM\Column(name: 'medical_conditions', type: 'text', nullable: true)]
    private ?string $medicalConditions = null;

    // ── Previous school ────────────────────────────────────────────────
    #[ORM\Column(name: 'previous_school', length: 255, nullable: true)]
    private ?string $previousSchool = null;

    #[ORM\Column(name: 'previous_class', length: 50, nullable: true)]
    private ?string $previousClass = null;

    /**
     * The class the parent is applying for — a request, not a guarantee.
     *
     * Parents choose a **level** (`JSS 1`), never an arm (`JSS 1A`). The arm is
     * the principal's decision at admission, so `requestedYearGroup` is what the
     * wizard collects. `requestedClassroom` is kept for applications created
     * before that rule existed, and as the principal's chosen arm once enrolled.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_year_group_id', nullable: true, onDelete: 'SET NULL')]
    private ?YearGroup $requestedYearGroup = null;

    /** @deprecated legacy parent requests; the wizard now collects requestedYearGroup. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_classroom_id', nullable: true, onDelete: 'SET NULL')]
    private ?Classroom $requestedClassroom = null;

    // ── Guardian details supplied with THIS application (snapshot) ─────
    #[ORM\Column(name: 'relationship_to_student', length: 50, nullable: true)]
    private ?string $relationshipToStudent = null;

    #[ORM\Column(name: 'guardian_name', length: 255, nullable: true)]
    private ?string $guardianName = null;

    #[ORM\Column(name: 'guardian_phone', length: 20, nullable: true)]
    private ?string $guardianPhone = null;

    #[ORM\Column(name: 'guardian_alternate_phone', length: 20, nullable: true)]
    private ?string $guardianAlternatePhone = null;

    #[ORM\Column(name: 'guardian_email', length: 255, nullable: true)]
    private ?string $guardianEmail = null;

    #[ORM\Column(name: 'guardian_occupation', length: 255, nullable: true)]
    private ?string $guardianOccupation = null;

    #[ORM\Column(name: 'guardian_address', length: 255, nullable: true)]
    private ?string $guardianAddress = null;

    #[ORM\Column(name: 'guardian_office_address', type: 'text', nullable: true)]
    private ?string $guardianOfficeAddress = null;

    // ── Documents (stored under uploads/applications/{reference}/) ─────
    #[ORM\Column(name: 'passport_photo_filename', length: 255, nullable: true)]
    private ?string $passportPhotoFilename = null;

    #[ORM\Column(name: 'birth_certificate_filename', length: 255, nullable: true)]
    private ?string $birthCertificateFilename = null;

    #[ORM\Column(name: 'previous_result_filename', length: 255, nullable: true)]
    private ?string $previousResultFilename = null;

    /** Proof of payment for the application fee when the school collects offline. */
    #[ORM\Column(name: 'fee_proof_filename', length: 255, nullable: true)]
    private ?string $feeProofFilename = null;

    // ── Links ──────────────────────────────────────────────────────────
    #[ORM\ManyToOne(inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'guardian_id', nullable: false, onDelete: 'CASCADE')]
    private ?Guardian $guardian = null;

    /** Set only when the application is enrolled. */
    #[ORM\OneToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'reviewed_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    // ── Workflow ───────────────────────────────────────────────────────
    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'submitted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'reviewed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(name: 'enrolled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $enrolledAt = null;

    #[ORM\Column(name: 'offer_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $offerExpiresAt = null;

    #[ORM\Column(name: 'decision_notes', type: 'text', nullable: true)]
    private ?string $decisionNotes = null;

    /** Shown to the parent when the bursar asks for more information. */
    #[ORM\Column(name: 'info_requested_note', type: 'text', nullable: true)]
    private ?string $infoRequestedNote = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    // ── Application (form) fee — this is what gates processing ─────────
    // 30, not 20: FEE_AWAITING_CONFIRMATION is 21 characters, so the old
    // length made an offline proof upload fail with "Data too long".
    #[ORM\Column(name: 'fee_status', length: 30)]
    private ?string $feeStatus = self::FEE_UNPAID;

    #[ORM\Column(name: 'fee_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $feeAmount = null;

    #[ORM\Column(name: 'fee_channel', length: 20, nullable: true)]
    private ?string $feeChannel = null;

    /** Gateway reference or bank teller/reference number entered by the parent. */
    #[ORM\Column(name: 'fee_reference', length: 100, nullable: true)]
    private ?string $feeReference = null;

    #[ORM\Column(name: 'fee_paid_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $feePaidAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'fee_payment_id', nullable: true, onDelete: 'SET NULL')]
    private ?Payment $feePayment = null;

    // ── Draft support (resume the wizard where the parent stopped) ─────
    #[ORM\Column(name: 'last_completed_step', type: 'smallint', options: ['default' => 1])]
    private ?int $lastCompletedStep = 1;

    // ── Audit / consent ────────────────────────────────────────────────
    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'consent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    #[ORM\Column(name: 'consent_version', length: 20, nullable: true)]
    private ?string $consentVersion = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, ApplicationEvent> */
    #[ORM\OneToMany(mappedBy: 'application', targetEntity: ApplicationEvent::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $events;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->events = new ArrayCollection();
    }

    // ── Identity ───────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = $reference; return $this; }

    public function getSource(): ?string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }

    // ── Applicant ──────────────────────────────────────────────────────
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getMiddleName(): ?string { return $this->middleName; }
    public function setMiddleName(?string $middleName): static { $this->middleName = $middleName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getDateOfBirth(): ?\DateTimeImmutable { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeImmutable $dateOfBirth): static { $this->dateOfBirth = $dateOfBirth; return $this; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): static { $this->gender = $gender; return $this; }

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

    public function getBloodGroup(): ?string { return $this->bloodGroup; }
    public function setBloodGroup(?string $bloodGroup): static { $this->bloodGroup = $bloodGroup; return $this; }

    public function getGenotype(): ?string { return $this->genotype; }
    public function setGenotype(?string $genotype): static { $this->genotype = $genotype; return $this; }

    public function getMedicalConditions(): ?string { return $this->medicalConditions; }
    public function setMedicalConditions(?string $medicalConditions): static { $this->medicalConditions = $medicalConditions; return $this; }

    public function getPreviousSchool(): ?string { return $this->previousSchool; }
    public function setPreviousSchool(?string $previousSchool): static { $this->previousSchool = $previousSchool; return $this; }

    public function getPreviousClass(): ?string { return $this->previousClass; }
    public function setPreviousClass(?string $previousClass): static { $this->previousClass = $previousClass; return $this; }

    public function getRequestedYearGroup(): ?YearGroup { return $this->requestedYearGroup; }
    public function setRequestedYearGroup(?YearGroup $requestedYearGroup): static { $this->requestedYearGroup = $requestedYearGroup; return $this; }

    public function getRequestedClassroom(): ?Classroom { return $this->requestedClassroom; }
    public function setRequestedClassroom(?Classroom $requestedClassroom): static { $this->requestedClassroom = $requestedClassroom; return $this; }

    /**
     * What to show as "class applied for": the level the parent chose, or the
     * arm recorded on an older application.
     */
    public function getRequestedClassName(): ?string
    {
        return $this->requestedYearGroup?->getName() ?? $this->requestedClassroom?->getName();
    }

    /** True when the fee no longer blocks the wizard. */
    public function hasPassedFeeGate(): bool
    {
        return in_array($this->feeStatus, [self::FEE_PAID, self::FEE_WAIVED], true);
    }

    // ── Guardian snapshot ──────────────────────────────────────────────
    public function getRelationshipToStudent(): ?string { return $this->relationshipToStudent; }
    public function setRelationshipToStudent(?string $relationshipToStudent): static { $this->relationshipToStudent = $relationshipToStudent; return $this; }

    public function getGuardianName(): ?string { return $this->guardianName; }
    public function setGuardianName(?string $guardianName): static { $this->guardianName = $guardianName; return $this; }

    public function getGuardianPhone(): ?string { return $this->guardianPhone; }
    public function setGuardianPhone(?string $guardianPhone): static { $this->guardianPhone = $guardianPhone; return $this; }

    public function getGuardianAlternatePhone(): ?string { return $this->guardianAlternatePhone; }
    public function setGuardianAlternatePhone(?string $guardianAlternatePhone): static { $this->guardianAlternatePhone = $guardianAlternatePhone; return $this; }

    public function getGuardianEmail(): ?string { return $this->guardianEmail; }
    public function setGuardianEmail(?string $guardianEmail): static { $this->guardianEmail = $guardianEmail; return $this; }

    public function getGuardianOccupation(): ?string { return $this->guardianOccupation; }
    public function setGuardianOccupation(?string $guardianOccupation): static { $this->guardianOccupation = $guardianOccupation; return $this; }

    public function getGuardianAddress(): ?string { return $this->guardianAddress; }
    public function setGuardianAddress(?string $guardianAddress): static { $this->guardianAddress = $guardianAddress; return $this; }

    public function getGuardianOfficeAddress(): ?string { return $this->guardianOfficeAddress; }
    public function setGuardianOfficeAddress(?string $guardianOfficeAddress): static { $this->guardianOfficeAddress = $guardianOfficeAddress; return $this; }

    // ── Documents ──────────────────────────────────────────────────────
    public function getPassportPhotoFilename(): ?string { return $this->passportPhotoFilename; }
    public function setPassportPhotoFilename(?string $passportPhotoFilename): static { $this->passportPhotoFilename = $passportPhotoFilename; return $this; }

    public function getBirthCertificateFilename(): ?string { return $this->birthCertificateFilename; }
    public function setBirthCertificateFilename(?string $birthCertificateFilename): static { $this->birthCertificateFilename = $birthCertificateFilename; return $this; }

    public function getPreviousResultFilename(): ?string { return $this->previousResultFilename; }
    public function setPreviousResultFilename(?string $previousResultFilename): static { $this->previousResultFilename = $previousResultFilename; return $this; }

    public function getFeeProofFilename(): ?string { return $this->feeProofFilename; }
    public function setFeeProofFilename(?string $feeProofFilename): static { $this->feeProofFilename = $feeProofFilename; return $this; }

    // ── Links ──────────────────────────────────────────────────────────
    public function getGuardian(): ?Guardian { return $this->guardian; }
    public function setGuardian(?Guardian $guardian): static { $this->guardian = $guardian; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $reviewedBy): static { $this->reviewedBy = $reviewedBy; return $this; }

    // ── Workflow ───────────────────────────────────────────────────────
    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; $this->touch(); return $this; }

    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static { $this->reviewedAt = $reviewedAt; return $this; }

    public function getEnrolledAt(): ?\DateTimeImmutable { return $this->enrolledAt; }
    public function setEnrolledAt(?\DateTimeImmutable $enrolledAt): static { $this->enrolledAt = $enrolledAt; return $this; }

    public function getOfferExpiresAt(): ?\DateTimeImmutable { return $this->offerExpiresAt; }
    public function setOfferExpiresAt(?\DateTimeImmutable $offerExpiresAt): static { $this->offerExpiresAt = $offerExpiresAt; return $this; }

    public function getDecisionNotes(): ?string { return $this->decisionNotes; }
    public function setDecisionNotes(?string $decisionNotes): static { $this->decisionNotes = $decisionNotes; return $this; }

    public function getInfoRequestedNote(): ?string { return $this->infoRequestedNote; }
    public function setInfoRequestedNote(?string $infoRequestedNote): static { $this->infoRequestedNote = $infoRequestedNote; return $this; }

    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $rejectionReason): static { $this->rejectionReason = $rejectionReason; return $this; }

    // ── Fee gate ───────────────────────────────────────────────────────
    public function getFeeStatus(): ?string { return $this->feeStatus; }
    public function setFeeStatus(string $feeStatus): static { $this->feeStatus = $feeStatus; return $this; }

    public function getFeeAmount(): ?string { return $this->feeAmount; }
    public function setFeeAmount(?string $feeAmount): static { $this->feeAmount = $feeAmount; return $this; }

    public function getFeeChannel(): ?string { return $this->feeChannel; }
    public function setFeeChannel(?string $feeChannel): static { $this->feeChannel = $feeChannel; return $this; }

    public function getFeeReference(): ?string { return $this->feeReference; }
    public function setFeeReference(?string $feeReference): static { $this->feeReference = $feeReference; return $this; }

    public function getFeePaidAt(): ?\DateTimeImmutable { return $this->feePaidAt; }
    public function setFeePaidAt(?\DateTimeImmutable $feePaidAt): static { $this->feePaidAt = $feePaidAt; return $this; }

    public function getFeePayment(): ?Payment { return $this->feePayment; }
    public function setFeePayment(?Payment $feePayment): static { $this->feePayment = $feePayment; return $this; }

    // ── Draft ──────────────────────────────────────────────────────────
    public function getLastCompletedStep(): ?int { return $this->lastCompletedStep; }
    public function setLastCompletedStep(?int $lastCompletedStep): static { $this->lastCompletedStep = $lastCompletedStep; return $this; }

    // ── Audit / consent ────────────────────────────────────────────────
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getConsentAt(): ?\DateTimeImmutable { return $this->consentAt; }
    public function setConsentAt(?\DateTimeImmutable $consentAt): static { $this->consentAt = $consentAt; return $this; }

    public function getConsentVersion(): ?string { return $this->consentVersion; }
    public function setConsentVersion(?string $consentVersion): static { $this->consentVersion = $consentVersion; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    // ── Timeline ───────────────────────────────────────────────────────
    /** @return Collection<int, ApplicationEvent> */
    public function getEvents(): Collection { return $this->events; }

    public function addEvent(ApplicationEvent $event): static
    {
        if (!$this->events->contains($event)) {
            $this->events->add($event);
            $event->setApplication($this);
        }
        return $this;
    }

    // ── Behaviour helpers ──────────────────────────────────────────────
    public function getFullName(): string
    {
        return trim(implode(' ', array_filter([$this->firstName, $this->middleName, $this->lastName])));
    }

    public function isEditableByParent(): bool
    {
        return in_array($this->status, self::PARENT_EDITABLE_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** True when the school requires no fee, or the fee has been settled/waived/confirmed. */
    public function isFeeSettled(): bool
    {
        return in_array($this->feeStatus, [self::FEE_PAID, self::FEE_WAIVED], true);
    }

    /** Ready for the bursar's queue. */
    public function isAwaitingReview(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW], true);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT            => 'Draft',
            self::STATUS_AWAITING_PAYMENT => 'Awaiting payment',
            self::STATUS_SUBMITTED        => 'Submitted',
            self::STATUS_UNDER_REVIEW     => 'Under review',
            self::STATUS_NEEDS_INFO       => 'Needs information',
            self::STATUS_APPROVED         => 'Approved',
            self::STATUS_ENROLLED         => 'Enrolled',
            self::STATUS_REJECTED         => 'Rejected',
            self::STATUS_WITHDRAWN        => 'Withdrawn',
            self::STATUS_WAITLISTED       => 'Waitlisted',
            default                       => ucfirst((string) $this->status),
        };
    }

    public function __toString(): string
    {
        return ($this->reference ?? 'Draft') . ' — ' . $this->getFullName();
    }
}
