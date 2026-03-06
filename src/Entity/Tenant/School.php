<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'school_settings')]
class School
{
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

    #[ORM\Column(name: 'logo_filename', length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\Column(name: 'primary_color', length: 7, nullable: true)]
    private ?string $primaryColor = '#1e40af'; // Default Blue

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subdomain = null;

    // 🟢 NEW: Store the permanent ID from the Landlord Database
    #[ORM\Column(name: 'landlord_school_id', type: 'integer', nullable: true)]
    private ?int $landlordSchoolId = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    #[ORM\Column(name: 'sms_on_enrollment', options: ["default" => true])]
    private ?bool $smsOnEnrollment = true;

    #[ORM\Column(name: 'sms_on_fee_payment', options: ["default" => true])]
    private ?bool $smsOnFeePayment = true;

    #[ORM\Column(name: 'sms_on_calendar_event', options: ["default" => true])]
    private ?bool $smsOnCalendarEvent = true;

    // 🟢 CRITICAL: These are the methods the form needs to "read" the property
    public function getInstitutionType(): ?string { 
        return $this->institutionType; 
    }
    
    public function setInstitutionType(string $institutionType): static { 
        $this->institutionType = $institutionType; 
        return $this; 
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


}