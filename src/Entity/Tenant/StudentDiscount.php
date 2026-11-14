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

    /**
     * Per-pupil scope, decided on the Scholarship Board when the rule is given.
     *
     * NULL means "follow the rule" — the normal case. TRUE means this pupil's
     * award is taken off the whole bill even when the rule itself is tuition
     * only, which is what a fully sponsored child needs.
     */
    #[ORM\Column(name: 'applies_to_all', nullable: true)]
    private ?bool $appliesToAll = null;

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getDiscountType(): ?DiscountType { return $this->discountType; }
    public function setDiscountType(?DiscountType $discountType): static { $this->discountType = $discountType; return $this; }

    public function getAppliesToAll(): ?bool { return $this->appliesToAll; }

    public function setAppliesToAll(?bool $appliesToAll): static
    {
        $this->appliesToAll = $appliesToAll;

        return $this;
    }

    /**
     * Whether this pupil's discount reaches non-tuition lines.
     *
     * The pupil-level choice wins when it was made; otherwise the rule's own
     * default applies. Kept in one place so the terminal, the admission engine
     * and the re-pricer can never disagree about it.
     */
    public function coversAllFeeItems(): bool
    {
        return $this->appliesToAll ?? $this->discountType?->isAppliesToAll() ?? false;
    }
}