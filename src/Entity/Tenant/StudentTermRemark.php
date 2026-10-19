<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The form teacher's remark for one pupil, one term.
 *
 * Phase 1 of the report card only needs the form teacher's remark; the HOD and
 * principal remarks are phase 2 (docs/results/RESULT-CARD-DESIGN.md §11). Keeping
 * the text on its own row means a remark can be written before the term is
 * released, corrected afterwards by an administrator, and always dated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'student_term_remark')]
#[ORM\UniqueConstraint(name: 'uniq_student_term_remark', columns: ['student_id', 'term_id'])]
#[ORM\Index(name: 'idx_student_term_remark_term', columns: ['term_id'])]
class StudentTermRemark
{
    /**
     * The printed card has room for two lines of 9pt text (about 180 characters,
     * leaving headroom for wider lettering). Enforced by every remark save
     * endpoint and mirrored by the PDF renderer.
     */
    public const TEXT_MAX = 180;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Student::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?Student $student = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false, onDelete: 'CASCADE')]
    private ?Term $term = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(name: 'hod_text', type: Types::TEXT, nullable: true)]
    private ?string $hodText = null;

    #[ORM\ManyToOne(targetEntity: Staff::class)]
    #[ORM\JoinColumn(name: 'hod_author_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $hodAuthor = null;

    #[ORM\Column(name: 'hod_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hodUpdatedAt = null;

    #[ORM\Column(name: 'principal_text', type: Types::TEXT, nullable: true)]
    private ?string $principalText = null;

    #[ORM\ManyToOne(targetEntity: Staff::class)]
    #[ORM\JoinColumn(name: 'principal_author_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $principalAuthor = null;

    #[ORM\Column(name: 'principal_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $principalUpdatedAt = null;

    /** The member of staff who last wrote it — printed under the signature line. */
    #[ORM\ManyToOne(targetEntity: Staff::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?Staff $author = null;

    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?Student { return $this->student; }
    public function setStudent(Student $student): static { $this->student = $student; return $this; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getText(): ?string { return $this->text; }
    public function setText(?string $text): static
    {
        $text = $text === null ? null : trim($text);
        $this->text = $text === '' ? null : $text;

        return $this;
    }

    public function getAuthor(): ?Staff { return $this->author; }
    public function setAuthor(?Staff $author): static { $this->author = $author; return $this; }

    public function getHodText(): ?string { return $this->hodText; }
    public function setHodText(?string $text): static
    {
        $text = $text === null ? null : trim($text);
        $this->hodText = $text === '' ? null : $text;

        return $this;
    }

    public function getHodAuthor(): ?Staff { return $this->hodAuthor; }
    public function getHodUpdatedAt(): ?\DateTimeImmutable { return $this->hodUpdatedAt; }

    public function getPrincipalText(): ?string { return $this->principalText; }
    public function setPrincipalText(?string $text): static
    {
        $text = $text === null ? null : trim($text);
        $this->principalText = $text === '' ? null : $text;

        return $this;
    }

    public function getPrincipalAuthor(): ?Staff { return $this->principalAuthor; }
    public function getPrincipalUpdatedAt(): ?\DateTimeImmutable { return $this->principalUpdatedAt; }

    public function getUpdatedBy(): ?string { return $this->updatedBy; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function touch(string $by, ?Staff $author = null): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
        if ($author) {
            $this->author = $author;
        }

        return $this;
    }

    public function touchHod(string $by, ?Staff $author = null): static
    {
        $this->updatedBy = $by;
        $this->hodUpdatedAt = new \DateTimeImmutable();
        if ($author) {
            $this->hodAuthor = $author;
        }

        return $this;
    }

    public function touchPrincipal(string $by, ?Staff $author = null): static
    {
        $this->updatedBy = $by;
        $this->principalUpdatedAt = new \DateTimeImmutable();
        if ($author) {
            $this->principalAuthor = $author;
        }

        return $this;
    }

    public function isWritten(): bool
    {
        return $this->text !== null && $this->text !== '';
    }
}
