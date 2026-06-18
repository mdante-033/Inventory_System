<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use DateTime;

class Product
{
    private ?int $id = null;
    private string $sku = '';
    private string $barcode = '';
    private string $name = '';
    private ?string $description = null;
    private int $categoryId = 0;
    private float $unitPrice = 0.0;
    private float $costPrice = 0.0;
    private int $quantity = 0;
    private int $reorderLevel = 10;
    private int $safetyStock = 0;
    private ?ProductType $type = null;
    private bool $isActive = true;
    private ?DateTime $createdAt = null;
    private ?DateTime $updatedAt = null;

    /* ---------- Getters ---------- */

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getPrice(): float
    {
        return $this->getUnitPrice();
    }

    public function getCostPrice(): float
    {
        return $this->costPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getReorderLevel(): int
    {
        return $this->reorderLevel;
    }

    public function getSafetyStock(): int
    {
        return $this->safetyStock;
    }

    public function getType(): ?ProductType
    {
        return $this->type;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTime
    {
        return $this->updatedAt;
    }

    /* ---------- Setters (fluent) ---------- */

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    public function setBarcode(string $barcode): self
    {
        $this->barcode = $barcode;
        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setCategoryId(int $categoryId): self
    {
        $this->categoryId = $categoryId;
        return $this;
    }

    public function setUnitPrice(float $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function setPrice(float $price): self
    {
        return $this->setUnitPrice($price);
    }

    public function setCostPrice(float $costPrice): self
    {
        $this->costPrice = $costPrice;
        return $this;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function setReorderLevel(int $reorderLevel): self
    {
        $this->reorderLevel = $reorderLevel;
        return $this;
    }

    public function setSafetyStock(int $safetyStock): self
    {
        $this->safetyStock = $safetyStock;
        return $this;
    }

    public function setType(ProductType|string|null $type): self
    {
        if ($type instanceof ProductType) {
            $this->type = $type;
        } elseif ($type !== null) {
            $this->type = ProductType::from($type);
        } else {
            $this->type = null;
        }
        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function setUpdatedAt(DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->reorderLevel;
    }

    public function getTotalValue(): float
    {
        return $this->quantity * $this->unitPrice;
    }

    public function getProfitMargin(): float
    {
        if ($this->unitPrice == 0.0) {
            return 0.0;
        }

        return (($this->unitPrice - $this->costPrice) / $this->unitPrice) * 100;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'unit_price' => $this->unitPrice,
            'cost_price' => $this->costPrice,
            'quantity' => $this->quantity,
            'reorder_level' => $this->reorderLevel,
            'safety_stock' => $this->safetyStock,
            'type' => $this->type?->value,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
