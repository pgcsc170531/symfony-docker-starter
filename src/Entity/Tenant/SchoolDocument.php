<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * A school document a parent is expected to read: rules and regulations, the
 * fees policy, the uniform list, the acceptable-use policy…
 *
 * It exists because the admission letter used to say "find attached the
 * breakdown of school fees, rules and regulations" and attach nothing. Rather
 * than paste that text into every letter, a school writes it once here — and the
 * same words appear on its website (`/p/{slug}`) and appended to the PDF, so the
 * paper and the site can never drift apart.
 *
 * `body` is plain text, one paragraph per blank line. Deliberately not HTML: the
 * same string is rendered by Twig on the web and by Dompdf on paper, and neither
 * should have to sanitise a school's markup.
 */
#[ORM\Entity]
#[ORM\Table(name: 'school_document')]
#[ORM\UniqueConstraint(name: 'uniq_school_document_slug', columns: ['slug'])]
class SchoolDocument
{
    public const CATEGORY_RULES = 'rules';
    public const CATEGORY_FEES = 'fees';
    public const CATEGORY_POLICY = 'policy';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_RULES => 'Rules and regulations',
        self::CATEGORY_FEES => 'Fees policy',
        self::CATEGORY_POLICY => 'Policy',
        self::CATEGORY_OTHER => 'Other',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private ?string $title = null;

    /** URL segment on the school's public site. */
    #[ORM\Column(length: 160)]
    private ?string $slug = null;

    #[ORM\Column(length: 20, options: ['default' => 'rules'])]
    private string $category = self::CATEGORY_RULES;

    #[ORM\Column(type: 'text')]
    private ?string $body = null;

    /** Published documents are readable on the school's website. */
    #[ORM\Column(name: 'is_published', options: ['default' => true])]
    private bool $isPublished = true;

    /**
     * Printed as an appendix to every admission letter. Only the rules document
     * usually wants this on; a staff handbook would not.
     */
    #[ORM\Column(name: 'attach_to_admission_letter', options: ['default' => false])]
    private bool $attachToAdmissionLetter = false;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): static
    {
        $this->category = array_key_exists($category, self::CATEGORIES) ? $category : self::CATEGORY_OTHER;
        return $this;
    }

    public function getBody(): ?string { return $this->body; }
    public function setBody(string $body): static { $this->body = $body; return $this; }

    public function isPublished(): bool { return $this->isPublished; }
    public function setIsPublished(bool $published): static { $this->isPublished = $published; return $this; }

    public function attachesToAdmissionLetter(): bool { return $this->attachToAdmissionLetter; }
    public function setAttachToAdmissionLetter(bool $attach): static { $this->attachToAdmissionLetter = $attach; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): static { $this->sortOrder = $sortOrder; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** The body split into paragraphs, ready for Twig or Dompdf. */
    public function paragraphs(): array
    {
        $blocks = preg_split('/\R{2,}/', trim((string) $this->body)) ?: [];

        return array_values(array_filter(array_map('trim', $blocks), static fn (string $b): bool => $b !== ''));
    }

    public static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'document';
    }
}
