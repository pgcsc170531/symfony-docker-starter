<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'session')]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'is_active')]
    private ?bool $isActive = false;

    // One Session has Many Terms.
    // 'orphanRemoval' means if you remove a Term from this list, it deletes from DB.
    // 'cascade: persist' ensures saving a Session can save its Terms too (optional but good).
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Term::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $terms;

    public function __construct()
    {
        $this->terms = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function isActive(): ?bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getTerms(): Collection { return $this->terms; }

    public function addTerm(Term $term): static
    {
        if (!$this->terms->contains($term)) {
            $this->terms->add($term);
            $term->setSession($this);
        }
        return $this;
    }

    public function removeTerm(Term $term): static
    {
        if ($this->terms->removeElement($term)) {
            if ($term->getSession() === $this) {
                $term->setSession(null);
            }
        }
        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        if ($this->terms->isEmpty()) {
            return null;
        }

        $latestDate = null;

        // Assuming the Term entity has a public method called getEndDate()
        foreach ($this->terms as $term) {
            $termEndDate = $term->getEndDate();
            
            if ($termEndDate instanceof \DateTimeImmutable) {
                if ($latestDate === null || $termEndDate > $latestDate) {
                    $latestDate = $termEndDate;
                }
            }
        }

        return $latestDate;
    }
}