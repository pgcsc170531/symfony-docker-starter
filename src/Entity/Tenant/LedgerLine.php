<?php

namespace App\Entity\Tenant;

use Doctrine\ORM\Mapping as ORM;

/**
 * One side of an entry: an amount into or out of a named account.
 *
 * `amountMinor` is always positive — the **direction** carries the sign, so no
 * line can ever be written with a negative number (which is how a ledger quietly
 * becomes unreadable). Amounts are integers in kobo: ₦750.00 is 75000.
 *
 * There are no setters after construction on purpose: a posted line is history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_line')]
#[ORM\Index(name: 'idx_line_account', columns: ['account_code'])]
class LedgerLine
{
    public const DEBIT = 'D';
    public const CREDIT = 'C';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LedgerEntry::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'entry_id', nullable: false, onDelete: 'RESTRICT')]
    private ?LedgerEntry $entry = null;

    #[ORM\Column(name: 'account_code', length: 8)]
    private string $accountCode;

    #[ORM\Column(length: 1)]
    private string $direction;

    /** Positive, in kobo. */
    #[ORM\Column(name: 'amount_minor')]
    private int $amountMinor;

    public function __construct(string $accountCode, string $direction, int $amountMinor)
    {
        if ($direction !== self::DEBIT && $direction !== self::CREDIT) {
            throw new \InvalidArgumentException('A ledger line is either a debit or a credit.');
        }

        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A ledger line must carry a positive amount; direction carries the sign.');
        }

        $this->accountCode = $accountCode;
        $this->direction = $direction;
        $this->amountMinor = $amountMinor;
    }

    public static function debit(string $accountCode, int $amountMinor): self
    {
        return new self($accountCode, self::DEBIT, $amountMinor);
    }

    public static function credit(string $accountCode, int $amountMinor): self
    {
        return new self($accountCode, self::CREDIT, $amountMinor);
    }

    public function getId(): ?int { return $this->id; }

    public function getEntry(): ?LedgerEntry { return $this->entry; }
    public function setEntry(LedgerEntry $entry): static { $this->entry = $entry; return $this; }

    public function getAccountCode(): string { return $this->accountCode; }

    public function getDirection(): string { return $this->direction; }

    public function getAmountMinor(): int { return $this->amountMinor; }

    /** Debit positive, credit negative — the shape every balance sums. */
    public function signedMinor(): int
    {
        return $this->direction === self::DEBIT ? $this->amountMinor : -$this->amountMinor;
    }
}
