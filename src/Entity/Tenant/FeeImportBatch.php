<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One opening-balance fees CSV, from preview to commit or reversal.
 *
 * The parsed rows are stored in `payload` so the preview screen, the commit and
 * a later reversal all read the same snapshot. Re-parsing the uploaded file at
 * commit time would make a preview that the bursar approved differ from what is
 * actually posted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fee_import_batch')]
#[ORM\Index(name: 'IDX_fee_import_batch_term', columns: ['term_id'])]
class FeeImportBatch
{
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_POSTED = 'posted';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Term::class)]
    #[ORM\JoinColumn(name: 'term_id', nullable: false)]
    private ?Term $term = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PREVIEW])]
    private string $status = self::STATUS_PREVIEW;

    /** SHA-256 of the uploaded file — re-uploading the same file is a no-op. */
    #[ORM\Column(name: 'file_checksum', length: 64)]
    private ?string $fileChecksum = null;

    #[ORM\Column(name: 'original_filename', length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'uploaded_by', length: 180, nullable: true)]
    private ?string $uploadedBy = null;

    #[ORM\Column(name: 'row_count', options: ['default' => 0])]
    private int $rowCount = 0;

    #[ORM\Column(name: 'posted_count', options: ['default' => 0])]
    private int $postedCount = 0;

    #[ORM\Column(name: 'skipped_count', options: ['default' => 0])]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'total_due', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalDue = '0.00';

    #[ORM\Column(name: 'total_paid', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalPaid = '0.00';

    #[ORM\Column(name: 'outstanding', type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $outstanding = '0.00';

    /** @var list<array<string, mixed>> The parsed and matched rows. */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(name: 'rolled_back_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rolledBackAt = null;

    #[ORM\Column(name: 'rolled_back_by', length: 180, nullable: true)]
    private ?string $rolledBackBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTerm(): ?Term { return $this->term; }
    public function setTerm(Term $term): static { $this->term = $term; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function isPreview(): bool { return $this->status === self::STATUS_PREVIEW; }
    public function isPosted(): bool { return $this->status === self::STATUS_POSTED; }
    public function isRolledBack(): bool { return $this->status === self::STATUS_ROLLED_BACK; }

    public function getFileChecksum(): ?string { return $this->fileChecksum; }
    public function setFileChecksum(string $fileChecksum): static { $this->fileChecksum = $fileChecksum; return $this; }

    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function setOriginalFilename(?string $originalFilename): static { $this->originalFilename = $originalFilename; return $this; }

    public function getUploadedBy(): ?string { return $this->uploadedBy; }
    public function setUploadedBy(?string $uploadedBy): static { $this->uploadedBy = $uploadedBy; return $this; }

    public function getRowCount(): int { return $this->rowCount; }
    public function setRowCount(int $rowCount): static { $this->rowCount = $rowCount; return $this; }

    public function getPostedCount(): int { return $this->postedCount; }
    public function setPostedCount(int $postedCount): static { $this->postedCount = $postedCount; return $this; }

    public function getSkippedCount(): int { return $this->skippedCount; }
    public function setSkippedCount(int $skippedCount): static { $this->skippedCount = $skippedCount; return $this; }

    public function getTotalDue(): string { return $this->totalDue; }
    public function setTotalDue(string $totalDue): static { $this->totalDue = $totalDue; return $this; }

    public function getTotalPaid(): string { return $this->totalPaid; }
    public function setTotalPaid(string $totalPaid): static { $this->totalPaid = $totalPaid; return $this; }

    public function getOutstanding(): string { return $this->outstanding; }
    public function setOutstanding(string $outstanding): static { $this->outstanding = $outstanding; return $this; }

    /** @return list<array<string, mixed>> */
    public function getPayload(): array { return $this->payload; }

    /** @param list<array<string, mixed>> $payload */
    public function setPayload(array $payload): static { $this->payload = $payload; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPostedAt(): ?\DateTimeImmutable { return $this->postedAt; }
    public function setPostedAt(?\DateTimeImmutable $postedAt): static { $this->postedAt = $postedAt; return $this; }

    public function getRolledBackAt(): ?\DateTimeImmutable { return $this->rolledBackAt; }
    public function setRolledBackAt(?\DateTimeImmutable $rolledBackAt): static { $this->rolledBackAt = $rolledBackAt; return $this; }

    public function getRolledBackBy(): ?string { return $this->rolledBackBy; }
    public function setRolledBackBy(?string $rolledBackBy): static { $this->rolledBackBy = $rolledBackBy; return $this; }
}
