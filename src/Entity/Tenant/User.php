<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\School;

#[ORM\Entity]
#[ORM\Table(name: 'user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_TEACHER = 'ROLE_TEACHER';
    public const ROLE_HOD = 'ROLE_HOD';

    /**
     * The admissions desk — the second person, beside the principal, who may
     * admit a child.
     *
     * A school usually has a principal who signs the register and an admissions
     * officer who runs it. Either may review, approve and enrol a new intake.
     * Deliberately OUTSIDE the ROLE_ADMIN hierarchy: this seat opens the
     * admissions screens and nothing else, so it cannot touch fees, results,
     * staff or the school's settings.
     */
    public const ROLE_ADMISSION_OFFICER = 'ROLE_ADMISSION_OFFICER';

    /**
     * The overseer of one school, created by that school's administrator.
     *
     * Deliberately NOT the same constant as the landlord's ROLE_SUPER_ADMIN:
     * that one is a platform account that lives in landlord_db, carries
     * ROLE_ALLOWED_TO_SWITCH and gets the landlord navigation in the shell.
     * A school's overseer is a tenant account and must keep the school shell.
     */
    public const ROLE_TENANT_SUPER_ADMIN = 'ROLE_TENANT_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?School $school = null;

    // --- THIS IS THE COLUMN THAT WAS MISSING ---
    #[ORM\Column(name: 'full_name', length: 100, nullable: true)]
    private ?string $fullName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Student $student = null;

    // 🆕 Phase 2 — Staff profile (one-to-one inverse, owned by Staff)
    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Staff::class)]
    private ?Staff $staff = null;

    // 🆕 Two-factor authentication (TOTP / Google Authenticator compatible).
    // See docs/authentication/VERIFICATION-AND-2FA.md — replaces paid SMS OTP where possible.
    #[ORM\Column(name: 'totp_secret', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'totp_enabled', options: ['default' => false])]
    private ?bool $totpEnabled = false;

    #[ORM\Column(name: 'totp_enabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** JSON array of hashed single-use recovery codes. */
    #[ORM\Column(name: 'totp_recovery_codes', type: 'text', nullable: true)]
    private ?string $totpRecoveryCodes = null;

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return (string) $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getStaff(): ?Staff { return $this->staff; }
    public function setStaff(?Staff $staff): static { $this->staff = $staff; return $this; }

    // ── Two-factor authentication (TOTP) ──────────────────────────────
    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function setTotpSecret(?string $totpSecret): static { $this->totpSecret = $totpSecret; return $this; }

    public function isTotpEnabled(): bool { return (bool) $this->totpEnabled; }
    public function setTotpEnabled(bool $totpEnabled): static { $this->totpEnabled = $totpEnabled; return $this; }

    public function getTotpEnabledAt(): ?\DateTimeImmutable { return $this->totpEnabledAt; }
    public function setTotpEnabledAt(?\DateTimeImmutable $totpEnabledAt): static { $this->totpEnabledAt = $totpEnabledAt; return $this; }

    /** @return string[] hashed recovery codes */
    public function getTotpRecoveryCodes(): array
    {
        return $this->totpRecoveryCodes ? (json_decode($this->totpRecoveryCodes, true) ?: []) : [];
    }

    /** @param string[] $hashedCodes */
    public function setTotpRecoveryCodes(array $hashedCodes): static
    {
        $this->totpRecoveryCodes = $hashedCodes ? json_encode(array_values($hashedCodes)) : null;
        return $this;
    }

   // 💡 2. ADD GETTER AND SETTER FOR SCHOOL
    public function getSchool(): ?School
    {
        return $this->school;
    }

    public function setSchool(?School $school): static
    {
        $this->school = $school;
        return $this;
    }

    // 💡 3. CRITICAL: Add this alias method so 'app.user.tenant' works in Twig
    public function getTenant(): ?School
    {
        return $this->school;
    }
}