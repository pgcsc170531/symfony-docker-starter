<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'school_settings')]
class School
{
    /**
     * Values stored in `institution_type`. `combined` is a school that runs
     * both a primary and a secondary section — it uses the secondary calendar
     * and the word "student", because only a pure primary school says "pupil".
     */
    public const TYPE_NURSERY = 'nursery';
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_JUNIOR_SECONDARY = 'junior_secondary';
    public const TYPE_SENIOR_SECONDARY = 'senior_secondary';
    /** Legacy umbrella type: a school that runs both halves of secondary. */
    public const TYPE_SECONDARY = 'secondary';
    public const TYPE_COMBINED = 'combined';
    public const TYPE_TERTIARY = 'tertiary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = 'My School'; // Default name

    // 🟢 NEW: This is the field causing the error. 
    // It MUST have the getter and setter methods below.
    #[ORM\Column(name: "institution_type", length: 20, options: ['default' => 'secondary'])]
    private ?string $institutionType = 'secondary'; 

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'phone_number', length: 20, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motto = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'bank_name', length: 100, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(name: 'account_number', length: 20, nullable: true)]
    private ?string $accountNumber = null;

    #[ORM\Column(name: 'account_name', length: 255, nullable: true)]
    private ?string $accountName = null;

    /**
     * The school's Paystack subaccount (ACCT_xxxx). Money from an online payment
     * is split here at the moment of payment, so Edus never holds school funds.
     * Empty means "online payment is not available yet for this school".
     */
    #[ORM\Column(name: 'paystack_subaccount_code', length: 100, nullable: true)]
    private ?string $paystackSubaccountCode = null;

    #[ORM\Column(name: 'logo_filename', length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\Column(name: 'primary_color', length: 7, nullable: true)]
    private ?string $primaryColor = '#1e40af'; // Default Blue

    // ── Report card signatures & release settings (phase 2) ──────────
    #[ORM\Column(name: 'principal_name', length: 255, nullable: true)]
    private ?string $principalName = null;

    #[ORM\Column(name: 'principal_title', length: 100, nullable: true)]
    private ?string $principalTitle = 'Principal';

    #[ORM\Column(name: 'principal_signature_filename', length: 255, nullable: true)]
    private ?string $principalSignatureFilename = null;

    /** When true, a report is withheld while the pupil has an outstanding balance. */
    #[ORM\Column(name: 'report_fee_gate_enabled', options: ['default' => false])]
    private ?bool $reportFeeGateEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subdomain = null;

    // 🟢 NEW: Store the permanent ID from the Landlord Database
    #[ORM\Column(name: 'landlord_school_id', type: 'integer', nullable: true)]
    private ?int $landlordSchoolId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $heroTagline = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $heroImageFilename = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutContent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $admissionsIntro = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mapEmbedUrl = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $facebookUrl = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $twitterUrl = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $instagramUrl = null;

    // ============================================================
    // 🆕 PUBLIC SITE — see docs/websites/SCHOOL-PUBLIC-SITE.md §6
    // ============================================================

    /**
     * WhatsApp is the default contact channel in this market — a school site
     * without it loses the parent at the moment of highest intent. Falls back to
     * `phoneNumber` when empty.
     */
    #[ORM\Column(name: 'whatsapp_number', type: Types::STRING, length: 24, nullable: true)]
    private ?string $whatsappNumber = null;

    /**
     * A short answer to "how much?" — the most asked question before applying.
     * Free text on purpose: schools quote ranges differently (per term, per year,
     * by class), and a rigid schema would force them to lie.
     */
    #[ORM\Column(name: 'fees_summary', type: Types::TEXT, nullable: true)]
    private ?string $feesSummary = null;

    /**
     * OFF by default. Publishing the roll leaks it, and on a new school it prints
     * "0" — a claim that the school is empty. Never render a zero.
     */
    #[ORM\Column(name: 'show_student_count', options: ['default' => false])]
    private bool $showStudentCount = false;

    /**
     * The year the school opened — the one fact a parent uses to judge how
     * established a school is. Null means "not stated", and the website simply
     * omits the trust line rather than inventing a date.
     */
    #[ORM\Column(name: 'founded_year', type: Types::SMALLINT, nullable: true)]
    private ?int $foundedYear = null;

    /**
     * What the school actually has, one per line ("Science laboratory",
     * "Boarding house", "ICT suite"). Free text on purpose: a school knows its
     * own estate better than any fixed list of checkboxes.
     */
    #[ORM\Column(name: 'facilities', type: Types::TEXT, nullable: true)]
    private ?string $facilities = null;

    /**
     * The school's own values, one per line as "Title: what it means". This is
     * the school's words, not ours — the public About page falls back to four
     * sensible defaults only until the school writes its own.
     */
    #[ORM\Column(name: 'core_values', type: Types::TEXT, nullable: true)]
    private ?string $coreValues = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    #[ORM\Column(name: 'sms_on_enrollment', options: ["default" => true])]
    private ?bool $smsOnEnrollment = true;

    #[ORM\Column(name: 'sms_on_fee_payment', options: ["default" => true])]
    private ?bool $smsOnFeePayment = true;

    #[ORM\Column(name: 'sms_on_calendar_event', options: ["default" => true])]
    private ?bool $smsOnCalendarEvent = true;

    // ============================================================
    // 🆕 APPLICATION MODE — parent self-registration & child application
    //    See docs/parent-application/DESIGN.md
    // ============================================================
    public const PAYMENT_OFFLINE = 'offline'; // parent transfers to the school account + uploads proof
    public const PAYMENT_ONLINE  = 'online';  // parent pays through the payment gateway
    public const PAYMENT_BOTH    = 'both';    // school accepts either

    #[ORM\Column(name: 'applications_open', options: ['default' => false])]
    private ?bool $applicationsOpen = false;

    #[ORM\Column(name: 'applications_open_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $applicationsOpenAt = null;

    #[ORM\Column(name: 'applications_close_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $applicationsCloseAt = null;

    #[ORM\Column(name: 'application_instructions', type: 'text', nullable: true)]
    private ?string $applicationInstructions = null;

    #[ORM\Column(name: 'application_classes_note', length: 255, nullable: true)]
    private ?string $applicationClassesNote = null;

    #[ORM\Column(name: 'application_require_approval', options: ['default' => true])]
    private ?bool $applicationRequireApproval = true;

    /** Amount charged for the application form; 0/null = free. Processing waits until this is settled. */
    #[ORM\Column(name: 'application_fee_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $applicationFeeAmount = null;

    /** offline | online | both — see docs/payments/PAYMENT-COLLECTION.md */
    #[ORM\Column(name: 'payment_method', length: 10, options: ['default' => 'offline'])]
    private ?string $paymentMethod = self::PAYMENT_BOTH;

    /**
     * Does the parent pay the online payment charge on top of the fee?
     *
     * ON (default): the parent covers it — 1% capped at ₦300, plus 7.5% VAT on
     * that, plus a ₦200 handling margin, and **never more than ₦500** — so the
     * school receives its fee in full. OFF: the parent pays exactly the fee and
     * the school's settlement is short by whatever the gateway takes.
     *
     * The number lives in ProcessingFeeCalculator; this flag is only the switch.
     */
    #[ORM\Column(name: 'online_processing_fee_enabled', options: ['default' => true])]
    private ?bool $onlineProcessingFeeEnabled = true;

    /**
     * How this school writes admission numbers, e.g. "ADM/{YEAR}/{SEQ}" or
     * "SMC/26/{SEQ:3}". Only the final integer increments; see
     * AdmissionNumberGenerator.
     */
    #[ORM\Column(name: 'admission_number_pattern', length: 60, nullable: true)]
    private ?string $admissionNumberPattern = null;

    #[ORM\Column(name: 'sms_on_application', options: ['default' => true])]
    private ?bool $smsOnApplication = true;

    #[ORM\Column(name: 'notify_bursar_on_application', options: ['default' => true])]
    private ?bool $notifyBursarOnApplication = true;

    #[ORM\Column(name: 'bursar_notification_phone', length: 20, nullable: true)]
    private ?string $bursarNotificationPhone = null;

    // ============================================================
    // 📧 EMAIL NOTIFICATIONS — every trigger carries its own switch, so a
    //    school worried about sending costs can turn one off without losing
    //    the rest. See docs/operations/EMAIL-SETUP.md.
    // ============================================================
    public const EMAIL_APPLICATION_SUBMITTED = 'application_submitted';
    public const EMAIL_ADMISSION_DECISION    = 'admission_decision';
    public const EMAIL_INVOICE_ISSUED        = 'invoice_issued';
    public const EMAIL_PAYMENT_RECEIPT       = 'payment_receipt';
    public const EMAIL_RESULT_PUBLISHED      = 'result_published';
    /** Staff-facing: the end-of-term fee summary sent to the proprietor. */
    public const EMAIL_TERM_SUMMARY          = 'term_summary';
    /** Staff-facing: the weekly outstanding-fees position. */
    public const EMAIL_AGING_REMINDER        = 'aging_reminder';

    /** Master switch — when off, no notification email is sent at all. */
    #[ORM\Column(name: 'email_notifications_enabled', options: ['default' => true])]
    private ?bool $emailNotificationsEnabled = true;

    #[ORM\Column(name: 'email_on_application_submitted', options: ['default' => true])]
    private ?bool $emailOnApplicationSubmitted = true;

    #[ORM\Column(name: 'email_on_admission_decision', options: ['default' => true])]
    private ?bool $emailOnAdmissionDecision = true;

    #[ORM\Column(name: 'email_on_invoice_issued', options: ['default' => true])]
    private ?bool $emailOnInvoiceIssued = true;

    #[ORM\Column(name: 'email_on_payment_receipt', options: ['default' => true])]
    private ?bool $emailOnPaymentReceipt = true;

    #[ORM\Column(name: 'email_on_result_published', options: ['default' => true])]
    private ?bool $emailOnResultPublished = true;

    // ── Application Mode accessors ────────────────────────────────────
    public function isApplicationsOpen(): bool { return (bool) $this->applicationsOpen; }
    public function setApplicationsOpen(bool $applicationsOpen): static { $this->applicationsOpen = $applicationsOpen; return $this; }

    public function getApplicationsOpenAt(): ?\DateTimeImmutable { return $this->applicationsOpenAt; }
    public function setApplicationsOpenAt(?\DateTimeImmutable $applicationsOpenAt): static { $this->applicationsOpenAt = $applicationsOpenAt; return $this; }

    public function getApplicationsCloseAt(): ?\DateTimeImmutable { return $this->applicationsCloseAt; }
    public function setApplicationsCloseAt(?\DateTimeImmutable $applicationsCloseAt): static { $this->applicationsCloseAt = $applicationsCloseAt; return $this; }

    public function getApplicationInstructions(): ?string { return $this->applicationInstructions; }
    public function setApplicationInstructions(?string $applicationInstructions): static { $this->applicationInstructions = $applicationInstructions; return $this; }

    public function getApplicationClassesNote(): ?string { return $this->applicationClassesNote; }
    public function setApplicationClassesNote(?string $applicationClassesNote): static { $this->applicationClassesNote = $applicationClassesNote; return $this; }

    public function isApplicationRequireApproval(): bool { return (bool) $this->applicationRequireApproval; }
    public function setApplicationRequireApproval(bool $applicationRequireApproval): static { $this->applicationRequireApproval = $applicationRequireApproval; return $this; }

    public function getApplicationFeeAmount(): ?string { return $this->applicationFeeAmount; }
    public function setApplicationFeeAmount(?string $applicationFeeAmount): static { $this->applicationFeeAmount = $applicationFeeAmount; return $this; }

    public function getAdmissionNumberPattern(): ?string { return $this->admissionNumberPattern; }
    public function setAdmissionNumberPattern(?string $admissionNumberPattern): static { $this->admissionNumberPattern = $admissionNumberPattern; return $this; }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(string $paymentMethod): static { $this->paymentMethod = $paymentMethod; return $this; }

    public function acceptsOnlinePayment(): bool
    {
        return in_array($this->paymentMethod, [self::PAYMENT_ONLINE, self::PAYMENT_BOTH], true);
    }

    public function acceptsOfflinePayment(): bool
    {
        return in_array($this->paymentMethod, [self::PAYMENT_OFFLINE, self::PAYMENT_BOTH], true);
    }

    /** Read by PaymentChargePolicy — the parent pays the capped online charge. */
    public function isOnlineProcessingFeeEnabled(): bool { return (bool) $this->onlineProcessingFeeEnabled; }
    public function setOnlineProcessingFeeEnabled(bool $enabled): static { $this->onlineProcessingFeeEnabled = $enabled; return $this; }

    public function isSmsOnApplication(): bool { return (bool) $this->smsOnApplication; }
    public function setSmsOnApplication(bool $smsOnApplication): static { $this->smsOnApplication = $smsOnApplication; return $this; }

    public function isNotifyBursarOnApplication(): bool { return (bool) $this->notifyBursarOnApplication; }
    public function setNotifyBursarOnApplication(bool $notifyBursarOnApplication): static { $this->notifyBursarOnApplication = $notifyBursarOnApplication; return $this; }

    public function getBursarNotificationPhone(): ?string { return $this->bursarNotificationPhone; }
    public function setBursarNotificationPhone(?string $bursarNotificationPhone): static { $this->bursarNotificationPhone = $bursarNotificationPhone; return $this; }

    // ── Email notification accessors ──────────────────────────────────
    public function isEmailNotificationsEnabled(): bool { return (bool) $this->emailNotificationsEnabled; }
    public function setEmailNotificationsEnabled(bool $value): static { $this->emailNotificationsEnabled = $value; return $this; }

    public function isEmailOnApplicationSubmitted(): bool { return (bool) $this->emailOnApplicationSubmitted; }
    public function setEmailOnApplicationSubmitted(bool $value): static { $this->emailOnApplicationSubmitted = $value; return $this; }

    public function isEmailOnAdmissionDecision(): bool { return (bool) $this->emailOnAdmissionDecision; }
    public function setEmailOnAdmissionDecision(bool $value): static { $this->emailOnAdmissionDecision = $value; return $this; }

    public function isEmailOnInvoiceIssued(): bool { return (bool) $this->emailOnInvoiceIssued; }
    public function setEmailOnInvoiceIssued(bool $value): static { $this->emailOnInvoiceIssued = $value; return $this; }

    public function isEmailOnPaymentReceipt(): bool { return (bool) $this->emailOnPaymentReceipt; }
    public function setEmailOnPaymentReceipt(bool $value): static { $this->emailOnPaymentReceipt = $value; return $this; }

    public function isEmailOnResultPublished(): bool { return (bool) $this->emailOnResultPublished; }
    public function setEmailOnResultPublished(bool $value): static { $this->emailOnResultPublished = $value; return $this; }

    /**
     * The single gate every outgoing notification email passes through.
     * Both the master switch and the per-event switch must be on.
     */
    public function isEmailEnabledFor(string $event): bool
    {
        if (!$this->isEmailNotificationsEnabled()) {
            return false;
        }

        return match ($event) {
            self::EMAIL_APPLICATION_SUBMITTED => $this->isEmailOnApplicationSubmitted(),
            self::EMAIL_ADMISSION_DECISION    => $this->isEmailOnAdmissionDecision(),
            self::EMAIL_INVOICE_ISSUED        => $this->isEmailOnInvoiceIssued(),
            self::EMAIL_PAYMENT_RECEIPT       => $this->isEmailOnPaymentReceipt(),
            self::EMAIL_RESULT_PUBLISHED      => $this->isEmailOnResultPublished(),
            // Administrative report to the school's own admin users; the
            // master switch still applies, but there is no per-event toggle.
            self::EMAIL_TERM_SUMMARY          => true,
            self::EMAIL_AGING_REMINDER        => true,
            default => false,
        };
    }

    /**
     * Event => parent-facing label, for the settings screen and for docs.
     *
     * @return array<string, string>
     */
    public static function emailEventLabels(): array
    {
        return [
            self::EMAIL_APPLICATION_SUBMITTED => 'Application received',
            self::EMAIL_ADMISSION_DECISION    => 'Admission decision / child enrolled',
            self::EMAIL_INVOICE_ISSUED        => 'Invoice issued or fee reminder',
            self::EMAIL_PAYMENT_RECEIPT       => 'Payment receipt',
            self::EMAIL_RESULT_PUBLISHED      => 'Result published',
        ];
    }

    // 🟢 CRITICAL: These are the methods the form needs to "read" the property
    public function getInstitutionType(): ?string { 
        return $this->institutionType; 
    }
    
    public function setInstitutionType(string $institutionType): static { 
        $this->institutionType = $institutionType; 
        return $this; 
    }

    /**
     * The line under the school name on a report card — "Secondary Section".
     *
     * A school's report sheet names the section the pupil sits in, not just the
     * school. It is derived from the school type the owner already set, so no
     * extra configuration is needed; an unknown type returns null and the line is
     * simply omitted rather than printing something invented.
     */
    public function getSectionLabel(): ?string
    {
        return match ($this->institutionType) {
            self::TYPE_NURSERY => 'Nursery Section',
            self::TYPE_PRIMARY => 'Primary Section',
            self::TYPE_JUNIOR_SECONDARY => 'Junior Secondary Section',
            self::TYPE_SENIOR_SECONDARY => 'Senior Secondary Section',
            self::TYPE_SECONDARY => 'Secondary Section',
            self::TYPE_COMBINED => 'Primary & Secondary Section',
            self::TYPE_TERTIARY => 'College Section',
            default => null,
        };
    }

    /**
     * Young children are "pupils"; secondary and tertiary learners are "students".
     * Nursery and primary share the wording, which is why this is one check and not
     * a list repeated at every call site.
     */
    public function usesPupilWording(): bool
    {
        return \in_array($this->institutionType, [self::TYPE_NURSERY, self::TYPE_PRIMARY], true);
    }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): static { $this->address = $address; return $this; }

    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $phoneNumber): static { $this->phoneNumber = $phoneNumber; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getLogoFilename(): ?string { return $this->logoFilename; }
    public function setLogoFilename(?string $logoFilename): static { $this->logoFilename = $logoFilename; return $this; }

    public function getPrimaryColor(): ?string { return $this->primaryColor; }
    public function setPrimaryColor(?string $primaryColor): static { $this->primaryColor = $primaryColor; return $this; }

    public function getPrincipalName(): ?string { return $this->principalName; }
    public function setPrincipalName(?string $principalName): static { $this->principalName = $principalName; return $this; }

    public function getPrincipalTitle(): ?string { return $this->principalTitle; }
    public function setPrincipalTitle(?string $principalTitle): static { $this->principalTitle = $principalTitle; return $this; }

    public function getPrincipalSignatureFilename(): ?string { return $this->principalSignatureFilename; }
    public function setPrincipalSignatureFilename(?string $filename): static { $this->principalSignatureFilename = $filename; return $this; }

    public function isReportFeeGateEnabled(): bool { return (bool) $this->reportFeeGateEnabled; }
    public function setReportFeeGateEnabled(bool $enabled): static { $this->reportFeeGateEnabled = $enabled; return $this; }

    public function getMotto(): ?string { return $this->motto; }
    public function setMotto(?string $motto): static { $this->motto = $motto; return $this; }

    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $website): static { $this->website = $website; return $this; }

    public function getBankName(): ?string { return $this->bankName; }
    public function setBankName(?string $bankName): static { 
        $this->bankName = $bankName; 
        return $this; 
    }

    public function getAccountNumber(): ?string { return $this->accountNumber; }
    public function setAccountNumber(?string $accountNumber): static { 
        $this->accountNumber = $accountNumber; 
        return $this; 
    }

    public function getAccountName(): ?string { return $this->accountName; }
    public function setAccountName(?string $accountName): static { 
        $this->accountName = $accountName; 
        return $this; 
    }

    public function getPaystackSubaccountCode(): ?string { return $this->paystackSubaccountCode; }
    public function setPaystackSubaccountCode(?string $code): static { $this->paystackSubaccountCode = $code; return $this; }

    public function getLandlordSchoolId(): ?int 
    { 
        return $this->landlordSchoolId; 
    }

    public function setLandlordSchoolId(?int $id): static 
    { 
        $this->landlordSchoolId = $id; 
        return $this; 
    }

    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    public function setSubdomain(?string $subdomain): self
    {
        $this->subdomain = $subdomain;
        return $this;
    }

    public function isSmsOnEnrollment(): ?bool { return $this->smsOnEnrollment; }
    public function setSmsOnEnrollment(bool $val): self { $this->smsOnEnrollment = $val; return $this; }

    public function isSmsOnFeePayment(): ?bool { return $this->smsOnFeePayment; }
    public function setSmsOnFeePayment(bool $val): self { $this->smsOnFeePayment = $val; return $this; }

    public function isSmsOnCalendarEvent(): ?bool { return $this->smsOnCalendarEvent; }
    public function setSmsOnCalendarEvent(bool $val): self { $this->smsOnCalendarEvent = $val; return $this; }

    // ============================================================
    // 🆕 WEBSITE PERSONALISATION FIELDS
    // ============================================================
    public function getHeroTagline(): ?string { return $this->heroTagline; }
    public function setHeroTagline(?string $heroTagline): static { $this->heroTagline = $heroTagline; return $this; }

    public function getHeroImageFilename(): ?string { return $this->heroImageFilename; }
    public function setHeroImageFilename(?string $heroImageFilename): static { $this->heroImageFilename = $heroImageFilename; return $this; }

    public function getWhatsappNumber(): ?string { return $this->whatsappNumber; }
    public function setWhatsappNumber(?string $whatsappNumber): static { $this->whatsappNumber = $whatsappNumber; return $this; }

    public function getFeesSummary(): ?string { return $this->feesSummary; }
    public function setFeesSummary(?string $feesSummary): static { $this->feesSummary = $feesSummary; return $this; }

    public function isShowStudentCount(): bool { return $this->showStudentCount; }
    public function setShowStudentCount(bool $showStudentCount): static { $this->showStudentCount = $showStudentCount; return $this; }

    public function getFoundedYear(): ?int { return $this->foundedYear; }
    public function setFoundedYear(?int $foundedYear): static { $this->foundedYear = $foundedYear; return $this; }

    public function getFacilities(): ?string { return $this->facilities; }
    public function setFacilities(?string $facilities): static { $this->facilities = $facilities; return $this; }

    public function getCoreValues(): ?string { return $this->coreValues; }
    public function setCoreValues(?string $coreValues): static { $this->coreValues = $coreValues; return $this; }

    public function getAboutContent(): ?string { return $this->aboutContent; }
    public function setAboutContent(?string $aboutContent): static { $this->aboutContent = $aboutContent; return $this; }

    public function getAdmissionsIntro(): ?string { return $this->admissionsIntro; }
    public function setAdmissionsIntro(?string $admissionsIntro): static { $this->admissionsIntro = $admissionsIntro; return $this; }

    public function getMapEmbedUrl(): ?string { return $this->mapEmbedUrl; }
    public function setMapEmbedUrl(?string $mapEmbedUrl): static { $this->mapEmbedUrl = $mapEmbedUrl; return $this; }

    public function getFacebookUrl(): ?string { return $this->facebookUrl; }
    public function setFacebookUrl(?string $facebookUrl): static { $this->facebookUrl = $facebookUrl; return $this; }

    public function getTwitterUrl(): ?string { return $this->twitterUrl; }
    public function setTwitterUrl(?string $twitterUrl): static { $this->twitterUrl = $twitterUrl; return $this; }

    public function getInstagramUrl(): ?string { return $this->instagramUrl; }
    public function setInstagramUrl(?string $instagramUrl): static { $this->instagramUrl = $instagramUrl; return $this; }
}