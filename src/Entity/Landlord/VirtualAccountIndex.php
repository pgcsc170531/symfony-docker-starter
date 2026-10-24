<?php

namespace App\Entity\Landlord;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which school owns a Paystack customer code.
 *
 * Paystack sends **one** webhook stream for the whole merchant account, so the
 * receiver runs on the public domain where there is no school subdomain to key
 * off. This is the routing table that answers "which tenant database does this
 * transfer belong to?" before any tenant table is touched.
 *
 * The tenant row (`virtual_account`) remains the source of truth for the account
 * itself; nothing here may be used to decide an amount or an invoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'virtual_account_index')]
class VirtualAccountIndex
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: School::class)]
    #[ORM\JoinColumn(name: 'school_id', nullable: false, onDelete: 'CASCADE')]
    private ?School $school = null;

    /** Paystack's `CUS_…`. One customer may hold several accounts, but one index row per code. */
    #[ORM\Column(name: 'customer_code', length: 64, unique: true)]
    private ?string $customerCode = null;

    #[ORM\Column(name: 'account_number', length: 20, unique: true)]
    private ?string $accountNumber = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSchool(): ?School { return $this->school; }
    public function setSchool(School $school): static { $this->school = $school; return $this; }

    public function getCustomerCode(): ?string { return $this->customerCode; }
    public function setCustomerCode(string $code): static { $this->customerCode = $code; return $this; }

    public function getAccountNumber(): ?string { return $this->accountNumber; }
    public function setAccountNumber(string $number): static { $this->accountNumber = $number; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
