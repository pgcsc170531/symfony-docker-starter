<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'student_discount')]
class StudentDiscount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'discount_type_id', nullable: false)]
    private ?DiscountType $discountType = null;

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getDiscountType(): ?DiscountType { return $this->discountType; }
    public function setDiscountType(?DiscountType $discountType): static { $this->discountType = $discountType; return $this; }

    /**
     * Whether this pupil's discount reaches non-tuition lines.
     *
     * The scope is a property of the RULE, not of the award. One rule, one
     * answer, so a bursar cannot create two half-scholarships that look the same
     * on the board but price differently. This method exists only so the
     * calculator, the re-pricer and the templates share that answer.
     */
    public function coversAllFeeItems(): bool
    {
        return $this->discountType?->isAppliesToAll() ?? false;
    }
}