<?php

namespace App\Models;

class StockAdjustment
{
    private ?int $id = null;
    private int $productVariantId;
    private string $barcode;
    private int $previousQuantity;
    private int $newQuantity;
    private int $adjustment;
    private string $reason;
    private ?string $notes = null;
    private string $adjustedBy;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private array $metadata = [];
    private \DateTime $createdAt;

    public function setId(?int $id): self { $this->id = $id; return $this; }
    public function setProductVariantId(int $productVariantId): self { $this->productVariantId = $productVariantId; return $this; }
    public function setVariantId(int $variantId): self { return $this->setProductVariantId($variantId); }
    public function setBarcode(string $barcode): self { $this->barcode = $barcode; return $this; }
    public function setPreviousQuantity(int $previousQuantity): self { $this->previousQuantity = $previousQuantity; return $this; }
    public function setNewQuantity(int $newQuantity): self { $this->newQuantity = $newQuantity; return $this; }
    public function setAdjustment(int $adjustment): self { $this->adjustment = $adjustment; return $this; }
    public function setReason(string $reason): self { $this->reason = $reason; return $this; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function setAdjustedBy(string $adjustedBy): self { $this->adjustedBy = $adjustedBy; return $this; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    public function setMetadata(array $metadata): self { $this->metadata = $metadata; return $this; }
    public function setCreatedAt(\DateTime $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getProductVariantId(): int { return $this->productVariantId; }
    public function getBarcode(): string { return $this->barcode; }
    public function getPreviousQuantity(): int { return $this->previousQuantity; }
    public function getNewQuantity(): int { return $this->newQuantity; }
    public function getAdjustment(): int { return $this->adjustment; }
    public function getReason(): string { return $this->reason; }
    public function getNotes(): ?string { return $this->notes; }
    public function getAdjustedBy(): string { return $this->adjustedBy; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getMetadata(): array { return $this->metadata; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }

    // Alias for backward compatibility
    public function getVariantId(): int { return $this->productVariantId; }
}
