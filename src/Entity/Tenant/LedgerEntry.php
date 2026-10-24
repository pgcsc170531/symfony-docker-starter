<?php

namespace App\Entity\Tenant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One event on the school's money, written once and never changed.
 *
 * A journal **entry** is not a payment: it is the accounting consequence of one,
 * carrying the lines that move money between named accounts. The rules live in
 * `App\Service\Payment\LedgerPoster`:
 *
 *   • debits must equal credits, or the entry is not written at all;
 *   • nothing is updated or deleted — a mistake is corrected by a reversing entry
 *     that points at the original (`reversesEntry`);
 *   • `(sourceType, sourceId)` is unique, so a replayed webhook cannot post twice.
 *
 * See docs/payments/ONLINE-PAYMENT-LEDGER.md §4, §8.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_entry')]
#[ORM\UniqueConstraint(name: 'uniq_ledger_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'idx_ledger_occurred', columns: ['occurred_at'])]
class LedgerEntry
{
    public const SOURCE_CREDIT_RECEIVED = 'credit_received';
    public const SOURCE_CREDIT_APPLIED  = 'credit_applied';
    public const SOURCE_PAYOUT          = 'payout';
    public const SOURCE_GATEWAY_COST    = 'gateway_cost';
    public const SOURCE_MANUAL          = 'manual';
    public const SOURCE_REVERSAL        = 'reversal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** When the money moved — the bank's clock, not ours. */
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** When we wrote it down. */
    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(name: 'source_type', length: 32)]
    private string $sourceType;

    /** Id of the row that caused it (credit, payout…). Paired with the type for idempotency. */
    #[ORM\Column(name: 'source_id')]
    private int $sourceId;

    /** The provider's reference, for a human chasing a bank. */
    #[ORM\Column(name: 'source_ref', length: 120, nullable: true)]
    private ?string $sourceRef = null;

    /** A sentence the bursar can read. */
    #[ORM\Column(length: 255)]
    private string $memo;

    #[ORM\Column(name: 'created_by', length: 80)]
    private string $createdBy;

    #[ORM\Column(length: 3)]
    private string $currency = 'NGN';

    #[ORM\Column(name: 'reverses_entry_id', nullable: true)]
    private ?int $reversesEntryId = null;

    /** @var Collection<int, LedgerLine> */
    #[ORM\OneToMany(mappedBy: 'entry', targetEntity: LedgerLine::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->occurredAt = new \DateTimeImmutable();
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $at): static { $this->occurredAt = $at; return $this; }

    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }

    public function getSourceType(): string { return $this->sourceType; }
    public function setSourceType(string $type): static { $this->sourceType = $type; return $this; }

    public function getSourceId(): int { return $this->sourceId; }
    public function setSourceId(int $id): static { $this->sourceId = $id; return $this; }

    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function setSourceRef(?string $ref): static { $this->sourceRef = $ref; return $this; }

    public function getMemo(): string { return $this->memo; }
    public function setMemo(string $memo): static { $this->memo = $memo; return $this; }

    public function getCreatedBy(): string { return $this->createdBy; }
    public function setCreatedBy(string $by): static { $this->createdBy = $by; return $this; }

    public function getCurrency(): string { return $this->currency; }

    public function getReversesEntryId(): ?int { return $this->reversesEntryId; }
    public function setReversesEntryId(?int $id): static { $this->reversesEntryId = $id; return $this; }

    /** @return Collection<int, LedgerLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(LedgerLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setEntry($this);
        }

        return $this;
    }

    /** Debits minus credits, in kobo. Zero for a balanced entry. */
    public function netMinor(): int
    {
        $net = 0;

        foreach ($this->lines as $line) {
            $net += $line->signedMinor();
        }

        return $net;
    }
}
