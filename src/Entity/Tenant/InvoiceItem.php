<?php

namespace App\Entity\Tenant;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_item')]
class InvoiceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null; // Price * Quantity

    #[ORM\Column(type: 'integer')]
    private ?int $quantity = 1;

    // ✅ ADDED: Snapshot field for the item name
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    // RELATIONSHIPS

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'fee_item_id', nullable: true)]
    private ?FeeItem $feeItem = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'product_id', nullable: true)]
    private ?Product $product = null;

    // GETTERS & SETTERS
    public function getId(): ?int { return $this->id; }

    public function getAmount(): ?string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getQuantity(): ?int { return $this->quantity; }
    public function setQuantity(int $quantity): static { $this->quantity = $quantity; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getFeeItem(): ?FeeItem { return $this->feeItem; }
    public function setFeeItem(?FeeItem $feeItem): static { $this->feeItem = $feeItem; return $this; }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): static { $this->product = $product; return $this; }

    // ✅ UPDATED: Getter now prefers the saved snapshot, falls back to dynamic name
    public function getDescription(): string
    {
        // 1. Return the saved snapshot if it exists (Fastest & Most Accurate)
        if ($this->description) {
            return $this->description;
        }

        // 2. Fallback logic for old items
        if ($this->product) {
            return $this->product->getName() . ' (x' . $this->quantity . ')';
        }
        return $this->feeItem ? $this->feeItem->getName() : 'Unknown Item';
    }

    // ✅ ADDED: Setter for the Controller to use
    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }
}