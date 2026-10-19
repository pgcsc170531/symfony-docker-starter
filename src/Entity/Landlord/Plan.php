<?php

namespace App\Entity\Landlord;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'plan')]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // e.g., "Starter"

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $price = null; // e.g., 50000.00

    #[ORM\Column(options: ["default" => 0])]
    private ?int $minStudents = 0;

    #[ORM\Column(options: ["default" => false])]
    private ?bool $isTrial = false;

    #[ORM\Column(nullable: true)]
    private ?int $maxStudents = null; // Null means Unlimited

    #[ORM\Column(options: ["default" => 4])]
    private ?int $durationMonths = 4; // Default to 1 Term

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ["default" => 0])]
    private ?string $freeCreditAmount = '0.00'; // The WhatsApp Bonus

    // ── Pricing model (docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md §8.1) ──
    // `flat` keeps the legacy Starter/Growth/Scale contracts working. New plans
    // are `per_student` (Standard) or `licence` (Enterprise); `price` stays 0 for
    // those and must never be overloaded as a per-pupil rate.
    #[ORM\Column(length: 20, options: ['default' => 'flat'])]
    private ?string $pricingModel = 'flat';

    /** Standard, termly: naira per pupil per 4-month term (e.g. 750). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $termPricePerStudent = null;

    /** Standard, annual prepay: naira per pupil per term when the year is paid (e.g. 500). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $annualPricePerStudent = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $minimumTermInvoice = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $minimumAnnualInvoice = null;

    /** Enterprise: one-off licence. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $licenceFee = null;

    /** Enterprise: yearly fee from year 1, email included. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $annualFee = null;

    /** Enterprise: one-off server set-up, migration and training. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $implementationFee = null;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $isPublic = true;

    /** What the plan unlocks; shown on the pricing screen. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $features = null;

    /** Grandfather an old flat price until this date (renewal converts it). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $legacyUntil = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getPrice(): ?string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }

    public function getMinStudents(): ?int { return $this->minStudents; }
    public function setMinStudents(int $minStudents): static { $this->minStudents = $minStudents; return $this; }

    public function getMaxStudents(): ?int { return $this->maxStudents; }
    public function setMaxStudents(?int $maxStudents): static { $this->maxStudents = $maxStudents; return $this; }

    public function getDurationMonths(): ?int { return $this->durationMonths; }
    public function setDurationMonths(int $durationMonths): static { $this->durationMonths = $durationMonths; return $this; }

    public function isTrial(): ?bool {return $this->isTrial;}
    public function setIsTrial(bool $isTrial): static {$this->isTrial = $isTrial; return $this;}

    public function getFreeCreditAmount(): ?string { return $this->freeCreditAmount; }
    public function setFreeCreditAmount(string $freeCreditAmount): static { $this->freeCreditAmount = $freeCreditAmount; return $this; }

    // ── Pricing model ───────────────────────────────────────────────────────

    public function getPricingModel(): ?string { return $this->pricingModel; }
    public function setPricingModel(string $pricingModel): static { $this->pricingModel = $pricingModel; return $this; }

    public function isPerStudent(): bool { return $this->pricingModel === 'per_student'; }
    public function isLicence(): bool { return $this->pricingModel === 'licence'; }
    public function isFlat(): bool { return $this->pricingModel === 'flat'; }

    public function getTermPricePerStudent(): ?string { return $this->termPricePerStudent; }
    public function setTermPricePerStudent(?string $termPricePerStudent): static { $this->termPricePerStudent = $termPricePerStudent; return $this; }

    public function getAnnualPricePerStudent(): ?string { return $this->annualPricePerStudent; }
    public function setAnnualPricePerStudent(?string $annualPricePerStudent): static { $this->annualPricePerStudent = $annualPricePerStudent; return $this; }

    public function getMinimumTermInvoice(): ?string { return $this->minimumTermInvoice; }
    public function setMinimumTermInvoice(?string $minimumTermInvoice): static { $this->minimumTermInvoice = $minimumTermInvoice; return $this; }

    public function getMinimumAnnualInvoice(): ?string { return $this->minimumAnnualInvoice; }
    public function setMinimumAnnualInvoice(?string $minimumAnnualInvoice): static { $this->minimumAnnualInvoice = $minimumAnnualInvoice; return $this; }

    public function getLicenceFee(): ?string { return $this->licenceFee; }
    public function setLicenceFee(?string $licenceFee): static { $this->licenceFee = $licenceFee; return $this; }

    public function getAnnualFee(): ?string { return $this->annualFee; }
    public function setAnnualFee(?string $annualFee): static { $this->annualFee = $annualFee; return $this; }

    public function getImplementationFee(): ?string { return $this->implementationFee; }
    public function setImplementationFee(?string $implementationFee): static { $this->implementationFee = $implementationFee; return $this; }

    public function isPublic(): ?bool { return $this->isPublic; }
    public function setIsPublic(bool $isPublic): static { $this->isPublic = $isPublic; return $this; }

    public function getFeatures(): ?array { return $this->features; }
    public function setFeatures(?array $features): static { $this->features = $features; return $this; }

    public function getLegacyUntil(): ?\DateTimeImmutable { return $this->legacyUntil; }
    public function setLegacyUntil(?\DateTimeImmutable $legacyUntil): static { $this->legacyUntil = $legacyUntil; return $this; }
}