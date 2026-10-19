<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pupil's score on one trait, for one term.
 *
 * Deliberately has no "default". An unrated pupil must look unrated — if 3 were
 * written as a starting value the completeness bar on My Class would lie, and a
 * parent would be shown a "Good" the teacher never gave.
 * See ATTENDANCE-AND-BEHAVIOUR-DESIGN.md §3.4.
 */
#[ORM\Entity]
#[ORM\Table(name: 'behaviour_rating')]
#[ORM\UniqueConstraint(name: 'uniq_behaviour_rating', columns: ['student_id', 'term_id', 'trait_id'])]
#[ORM\Index(name: 'idx_behaviour_rating_student_term', columns: ['student_id', 'term_id'])]
class BehaviourRating
{
    public const MIN = 1;
    public const MAX = 5;

    /** The five labels a school may rename in settings. */
    public const LABELS = [
        5 => 'Excellent',
        4 => 'Very good',
        3 => 'Good',
        2 => 'Fair',
        1 => 'Poor',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?Student $student = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\ManyToOne(targetEntity: BehaviourTrait::class)]
    #[ORM\JoinColumn(name: 'trait_id', nullable: false)]
    private ?BehaviourTrait $trait = null;

    #[ORM\Column]
    private int $score = 3;

    #[ORM\ManyToOne(targetEntity: Staff::class)]
    #[ORM\JoinColumn(name: 'rated_by_id', nullable: true)]
    private ?Staff $ratedBy = null;

    #[ORM\Column(name: 'rated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $ratedAt;

    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->ratedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(?Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(?Term $term): static { $this->term = $term; return $this; }

    public function getTrait(): ?BehaviourTrait { return $this->trait; }
    public function setTrait(?BehaviourTrait $trait): static { $this->trait = $trait; return $this; }

    public function getScore(): int { return $this->score; }
    public function setScore(int $score): static
    {
        $this->score = max(self::MIN, min(self::MAX, $score));
        return $this;
    }

    public function getRatedBy(): ?Staff { return $this->ratedBy; }
    public function setRatedBy(?Staff $ratedBy): static { $this->ratedBy = $ratedBy; return $this; }

    public function getRatedAt(): \DateTimeImmutable { return $this->ratedAt; }

    public function getUpdatedBy(): ?string { return $this->updatedBy; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function touch(string $by): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function label(): string
    {
        return self::LABELS[$this->score] ?? '';
    }
}
