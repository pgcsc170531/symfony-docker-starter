<?php

namespace App\Entity\Landlord;

use Doctrine\Common\Collections\ArrayCollection; // 🟢 ADDED: For OneToMany relations
use Doctrine\Common\Collections\Collection;      // 🟢 ADDED
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'school')]
#[UniqueEntity(fields: ['subdomain'], message: 'This subdomain is already being used by another school.')]
class School
{
    // --- EXISTING FIELDS (KEPT) ---
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customDomain = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 20)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+$/',
        message: 'Subdomain can only contain lowercase letters and numbers (no spaces).'
    )]
    private ?string $subdomain = null;

    #[ORM\Column(length: 255)]
    private ?string $databaseName = null;

    #[ORM\Column(length: 255)]
    private ?string $dbUser = 'root';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dbPassword = 'root';

    #[ORM\Column(length: 255)]
    private ?string $dbHost = 'database';

    #[ORM\Column(length: 255)]
    private ?string $dbDriver = 'pdo_mysql';

    #[ORM\Column]
    private bool $isActive = true;
    
    #[ORM\ManyToOne(inversedBy: 'schools')]
    private ?Agent $agent = null;

    #[ORM\Column(nullable: true)] 
    private ?\DateTimeImmutable $createdAt = null;
    
    #[ORM\Column(length: 255)]
    private ?string $principalName = null;

    #[ORM\Column(length: 255)]
    private ?string $principalEmail = null;

    #[ORM\Column(length: 255)]
    private ?string $principalPassword = null;

    // --- 🟢 NEW FIELDS ADDED FOR SUBSCRIPTION & WALLET ---

    // 1. Wallet for WhatsApp Credits
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ["default" => 0])]
    private ?string $walletBalance = '0.00';

    // 2. Active Subscription Link (OneToOne)
    #[ORM\OneToOne(mappedBy: 'school', targetEntity: Subscription::class, cascade: ['persist', 'remove'])]
    private ?Subscription $subscription = null;

    // 3. Payment History (Payslips)
    #[ORM\OneToMany(mappedBy: 'school', targetEntity: SubscriptionPayment::class)]
    private Collection $subscriptionPayments;

    // 4. Wallet History (Credits/Debits)
    #[ORM\OneToMany(mappedBy: 'school', targetEntity: WalletTransaction::class)]
    private Collection $walletTransactions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        
        // 🟢 Initialize Collections
        $this->subscriptionPayments = new ArrayCollection();
        $this->walletTransactions = new ArrayCollection();
    }

    // --- GETTERS AND SETTERS ---

    // 🟢 NEW METHODS
    public function getWalletBalance(): ?string { return $this->walletBalance; }
    public function setWalletBalance(string $walletBalance): static { $this->walletBalance = $walletBalance; return $this; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(Subscription $subscription): static
    {
        if ($subscription->getSchool() !== $this) {
            $subscription->setSchool($this);
        }
        $this->subscription = $subscription;
        return $this;
    }

    /**
     * @return Collection<int, SubscriptionPayment>
     */
    public function getSubscriptionPayments(): Collection { return $this->subscriptionPayments; }

    /**
     * @return Collection<int, WalletTransaction>
     */
    public function getWalletTransactions(): Collection { return $this->walletTransactions; }


    // --- EXISTING METHODS (KEPT) ---
    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSubdomain(): ?string { return $this->subdomain; }
    public function setSubdomain(string $subdomain): self { $this->subdomain = $subdomain; return $this; }
    public function getDatabaseName(): ?string { return $this->databaseName; }
    public function setDatabaseName(string $databaseName): self { $this->databaseName = $databaseName; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getDbUser(): ?string { return $this->dbUser; }
    public function setDbUser(string $dbUser): self { $this->dbUser = $dbUser; return $this; }
    public function getDbPassword(): ?string { return $this->dbPassword; }
    public function setDbPassword(?string $dbPassword): self { $this->dbPassword = $dbPassword; return $this; }
    public function getDbHost(): ?string { return $this->dbHost; }
    public function setDbHost(string $dbHost): self { $this->dbHost = $dbHost; return $this; }
    public function getDbDriver(): ?string { return $this->dbDriver; }
    public function setDbDriver(string $dbDriver): self { $this->dbDriver = $dbDriver; return $this; }
    public function getPrincipalName(): ?string { return $this->principalName; }
    public function setPrincipalName(string $principalName): self { $this->principalName = $principalName; return $this; }
    public function getPrincipalEmail(): ?string { return $this->principalEmail; }
    public function setPrincipalEmail(string $principalEmail): self { $this->principalEmail = $principalEmail; return $this; }
    public function getPrincipalPassword(): ?string { return $this->principalPassword; }
    public function setPrincipalPassword(string $principalPassword): self { $this->principalPassword = $principalPassword; return $this; }
    public function getAgent(): ?Agent { return $this->agent; }
    public function setAgent(?Agent $agent): self { $this->agent = $agent; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
    public function getCustomDomain(): ?string { return $this->customDomain; }
    public function setCustomDomain(?string $customDomain): self { $this->customDomain = $customDomain; return $this; }
}