<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Enums\AdjustmentReason;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ValidationException;
use App\Repositories\ProductRepository;
use App\Repositories\StockAdjustmentRepository;
use DateTime;
use PDOException;

class InventoryService
{
    private ProductRepository $productRepository;
    private StockAdjustmentRepository $adjustmentRepository;
    private ValidationService $validationService;
    private ?object $logger;

    public function __construct(
        ProductRepository $productRepository,
        StockAdjustmentRepository $adjustmentRepository,
        ValidationService $validationService,
        ?object $logger = null
    ) {
        $this->productRepository = $productRepository;
        $this->adjustmentRepository = $adjustmentRepository;
        $this->validationService = $validationService;
        $this->logger = $logger;
    }

    /**
     * @throws ValidationException
     * @throws InsufficientStockException
     */
    public function adjustStock(
        string $barcode,
        int $quantity,
        string $reason,
        string $notes = '',
        string $adjustedBy = 'system'
    ): StockAdjustment {
        if ($quantity === 0) {
            throw new ValidationException('Adjustment quantity cannot be zero');
        }

        $reason = AdjustmentReason::from($reason);

        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Product variant not found for barcode: {$barcode}");
        }

        $this->validateAdjustmentRules($variant, $quantity, $reason);

        $previousQuantity = $variant->getQuantity() ?? 0;
        $newQuantity = $previousQuantity + $quantity;

        if ($newQuantity < 0) {
            throw new InsufficientStockException(
                "Cannot reduce stock below 0. Current: {$previousQuantity}, Adjustment: {$quantity}",
                0,
                null,
                $previousQuantity,
                abs($quantity),
                $barcode
            );
        }

        $variant->setQuantity($newQuantity);
        $variant->setUpdatedAt(new DateTime());

        try {
            if (!$this->productRepository->saveVariant($variant)) {
                throw new ValidationException('Failed to save product variant');
            }
        } catch (PDOException $e) {
            $this->log('error', 'Database error during stock adjustment', [
                'barcode' => $barcode,
                'error'   => $e->getMessage(),
                'code'    => $e->getCode(),
            ]);

            // 修复点：将 $e->getCode() 强制转换为字符串进行比较，避免类型不匹配警告
            // 同时检查 '40001' (SQLState) 或 5 (MySQL Code for deadlock)
            $errorCode = (string) $e->getCode();
            
            if (stripos($errorCode, '40001') !== false
                || stripos($e->getMessage(), 'lock') !== false
            ) {
                throw new ValidationException(
                    'Inventory was modified by another user. Please retry.'
                );
            }

            throw $e;
        }

        $variantId = $this->requireVariantId($variant);

        $adjustment = new StockAdjustment();
        $adjustment->setProductVariantId($variantId)
            ->setPreviousQuantity($previousQuantity)
            ->setNewQuantity($newQuantity)
            ->setAdjustment($quantity)
            ->setReason($reason)
            ->setNotes($notes)
            ->setAdjustedBy($adjustedBy)
            ->setCreatedAt(new DateTime());

        try {
            if (!$this->adjustmentRepository->save($adjustment)) {
                throw new ValidationException('Failed to save stock adjustment');
            }
        } catch (\Exception $e) {
            // Log critical inconsistency: Stock was updated but the audit log failed.
            // Ideally, this entire method should be inside a DB transaction.
            $this->log('critical', 'Inventory inconsistency: Stock updated but adjustment log failed', [
                'barcode' => $barcode,
                'error'   => $e->getMessage()
            ]);
            throw $e;
        }

        $product = $this->productRepository->findById($variant->getProductId());
        if ($product && $this->calculateAvailableStock($variant) <= ($product->getSafetyStock() ?? 0)) {
            $this->triggerLowStockAlert($variant, $product);
        }

        return $adjustment;
    }

    private function validateAdjustmentRules(
        ProductVariant $variant,
        int $quantity,
        string $reason
    ): void {
        $error = match ($reason) {
            AdjustmentReason::DAMAGED       => $quantity > 0 ? 'Damaged items cannot increase stock' : null,
            AdjustmentReason::RETURNED      => $quantity < 0 ? 'Returns cannot decrease stock' : null,
            AdjustmentReason::INITIAL_COUNT => $quantity < 0 ? 'Initial count must be positive' : null,
            AdjustmentReason::SOLD          => $quantity > 0 ? 'Sales cannot increase stock' : null,
            AdjustmentReason::TRANSFER_OUT  => $quantity > 0 ? 'Transfer out cannot increase stock' : null,
            AdjustmentReason::TRANSFER_IN   => $quantity < 0 ? 'Transfer in cannot decrease stock' : null,
            AdjustmentReason::AUDIT         => null,
            AdjustmentReason::RESTOCK       => $quantity < 0 ? 'Restock must be positive' : null,
            AdjustmentReason::LOST         => $quantity > 0 ? 'Lost items cannot increase stock' : null,
            AdjustmentReason::FOUND        => $quantity < 0 ? 'Found items cannot decrease stock' : null,
            default                         => null,
        };

        if ($error !== null) {
            throw new ValidationException($error);
        }
    }

    public function scanItem(string $barcode): array
    {
        $this->validationService->validateBarcode($barcode);

        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Item not found in inventory: {$barcode}");
        }

        $product = $this->productRepository->findById($variant->getProductId());
        if (!$product) {
            throw new ValidationException("Product not found for variant: {$barcode}");
        }

        $updatedAt = $variant->getUpdatedAt();

        return [
            'barcode'        => $barcode,
            'sku'            => $product->getSku(),
            'name'           => $product->getName(),
            'type'           => $product->getType()?->value ?? 'unknown',
            'color'          => $variant->getColor()?->value ?? 'unknown',
            'quantity'       => $variant->getQuantity() ?? 0,
            'available'      => $this->calculateAvailableStock($variant),
            'low_stock'      => $this->isLowStock($variant),
            'last_restocked' => $updatedAt instanceof \DateTimeInterface
                ? $updatedAt->format('Y-m-d H:i:s')
                : null,
        ];
    }

    public function getLowStockAlerts(?int $threshold = null): array
    {
        $variants = $this->productRepository->findLowStockVariants($threshold);

        $alerts = [];
        foreach ($variants as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            $product = $this->productRepository->findById($variant->getProductId());
            if (!$product) {
                continue;
            }

            $alerts[] = [
                'product_id'     => $product->getId(),
                'sku'            => $product->getSku(),
                'name'           => $product->getName(),
                'barcode'        => $variant->getBarcode(),
                'current_stock'  => $variant->getQuantity() ?? 0,
                'safety_stock'   => $product->getSafetyStock() ?? 0,
                'available'      => $this->calculateAvailableStock($variant),
                'days_of_supply' => $this->calculateDaysOfSupply($variant),
                'urgency'        => $this->calculateUrgency($variant),
            ];
        }

        usort($alerts, fn($a, $b) => $this->compareUrgency($a['urgency'], $b['urgency']));

        return $alerts;
    }

    private function compareUrgency(string $urgencyA, string $urgencyB): int
    {
        $order = [
            'CRITICAL' => 0,
            'HIGH'     => 1,
            'MEDIUM'   => 2,
            'LOW'      => 3,
            'NORMAL'   => 4,
        ];

        return ($order[$urgencyA] ?? 5) <=> ($order[$urgencyB] ?? 5);
    }

    public function bulkAdjustStock(array $adjustments, string $adjustedBy = 'system'): array
    {
        $results = [];

        foreach ($adjustments as $index => $adjustment) {
            try {
                if (!isset($adjustment['barcode'], $adjustment['quantity'], $adjustment['reason'])) {
                    throw new ValidationException('Invalid adjustment data: missing required fields');
                }

                $reason = AdjustmentReason::from((string) $adjustment['reason']);

                $result = $this->adjustStock(
                    $adjustment['barcode'],
                    $adjustment['quantity'],
                    $reason,
                    $adjustment['notes'] ?? '',
                    $adjustedBy
                );

                $results[] = [
                    'success'       => true,
                    'barcode'       => $adjustment['barcode'],
                    'adjustment_id' => $result->getId(),
                    'new_quantity'  => $result->getNewQuantity(),
                ];
            } catch (\Exception $e) {
                $this->log('warning', 'Bulk adjustment failed for item', [
                    'index'   => $index,
                    'barcode' => $adjustment['barcode'] ?? 'unknown',
                    'error'   => $e->getMessage(),
                ]);

                $results[] = [
                    'success' => false,
                    'barcode' => $adjustment['barcode'] ?? 'unknown',
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function transferStock(
        string $sourceBarcode,
        string $destinationBarcode,
        int $quantity,
        string $notes = '',
        string $transferredBy = 'system'
    ): array {
        if ($quantity <= 0) {
            throw new ValidationException('Transfer quantity must be positive');
        }

        if ($sourceBarcode === $destinationBarcode) {
            throw new ValidationException('Source and destination barcodes must be different');
        }

        $sourceAdjustment = $this->adjustStock(
            $sourceBarcode,
            -$quantity,
            AdjustmentReason::TRANSFER_OUT,
            "Transfer to {$destinationBarcode}: {$notes}",
            $transferredBy
        );

        try {
            $destinationAdjustment = $this->adjustStock(
                $destinationBarcode,
                $quantity,
                AdjustmentReason::TRANSFER_IN,
                "Transfer from {$sourceBarcode}: {$notes}",
                $transferredBy
            );
        } catch (\Exception $e) {
            $this->log('error', 'Transfer failed at destination; attempting rollback', [
                'source'      => $sourceBarcode,
                'destination' => $destinationBarcode,
                'quantity'    => $quantity,
            ]);

            try {
                $this->adjustStock(
                    $sourceBarcode,
                    $quantity,
                    AdjustmentReason::TRANSFER_IN,
                    "Rollback of failed transfer to {$destinationBarcode}",
                    'system'
                );
            } catch (\Exception $rollbackException) {
                $this->log('critical', 'Transfer rollback failed', [
                    'source'         => $sourceBarcode,
                    'original_error' => $e->getMessage(),
                    'rollback_error' => $rollbackException->getMessage(),
                ]);

                throw new ValidationException(
                    'Transfer failed and rollback failed. Manual intervention required: ' . $e->getMessage()
                );
            }

            throw new ValidationException('Transfer failed: ' . $e->getMessage());
        }

        return [
            'source_adjustment'      => $sourceAdjustment,
            'destination_adjustment' => $destinationAdjustment,
            'transfer_complete'      => true,
        ];
    }

    public function reserveStock(string $barcode, int $quantity, int $orderId): bool
    {
        if ($quantity <= 0) {
            throw new ValidationException('Reservation quantity must be positive');
        }

        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Product variant not found for barcode: {$barcode}");
        }

        $availableStock = $this->calculateAvailableStock($variant);
        if ($availableStock < $quantity) {
            throw new InsufficientStockException(
                "Cannot reserve {$quantity} items. Available: {$availableStock}",
                0,
                null,
                $availableStock,
                $quantity,
                $barcode
            );
        }

        $variantId = $this->requireVariantId($variant);
        $currentReserved = $variant->getReservedQuantity() ?? 0;
        $variant->setReservedQuantity($currentReserved + $quantity);
        $variant->setUpdatedAt(new DateTime());

        if (!$this->productRepository->saveVariant($variant)) {
            throw new ValidationException('Failed to save reservation');
        }

        if (!$this->adjustmentRepository->createReservation($variantId, $orderId, $quantity)) {
            throw new ValidationException('Failed to create reservation record');
        }

        return true;
    }

    public function releaseStock(string $barcode, int $orderId): bool
    {
        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Product variant not found for barcode: {$barcode}");
        }

        $variantId = $this->requireVariantId($variant);
        $reservation = $this->adjustmentRepository->findReservation($variantId, $orderId);
        if (!is_array($reservation) || !isset($reservation['quantity'])) {
            throw new ValidationException("No reservation found for order: {$orderId}");
        }

        $currentReserved = $variant->getReservedQuantity() ?? 0;
        $reservedQty = (int) $reservation['quantity'];
        $newReserved = max(0, $currentReserved - $reservedQty);

        $variant->setReservedQuantity($newReserved);
        $variant->setUpdatedAt(new DateTime());

        if (!$this->productRepository->saveVariant($variant)) {
            throw new ValidationException('Failed to update variant after releasing reservation');
        }

        if (!$this->adjustmentRepository->releaseReservation($variantId, $orderId)) {
            throw new ValidationException('Failed to release reservation');
        }

        return true;
    }

    public function getInventorySummary(): array
    {
        return [
            'total_variants'      => $this->productRepository->countAllVariants(),
            'total_stock_value'   => round($this->productRepository->calculateTotalStockValue() ?? 0.0, 2),
            'low_stock_count'     => $this->productRepository->countLowStockVariants(),
            'out_of_stock_count'  => $this->productRepository->countOutOfStockVariants(),
            'stock_turnover_rate' => $this->calculateTurnoverRate(),
            'recent_adjustments'  => $this->adjustmentRepository->findRecentAdjustments(10),
            'top_selling'         => $this->productRepository->findTopSellingProducts(5),
        ];
    }

    public function getStockHistory(string $barcode, int $days = 30): array
    {
        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Product variant not found for barcode: {$barcode}");
        }

        $adjustments = $this->adjustmentRepository->findAdjustmentsByVariantId(
            $this->requireVariantId($variant),
            $days
        );

        $history = [];
        foreach ($adjustments as $adjustment) {
            if (!$adjustment instanceof StockAdjustment) {
                continue;
            }

            $createdAt = $adjustment->getCreatedAt();

            $history[] = [
                'date'              => $createdAt instanceof \DateTimeInterface
                    ? $createdAt->format('Y-m-d H:i:s')
                    : null,
                'type'              => $adjustment->getReason(),
                'adjustment'        => $adjustment->getAdjustment(),
                'previous_quantity' => $adjustment->getPreviousQuantity(),
                'new_quantity'      => $adjustment->getNewQuantity(),
                'notes'             => $adjustment->getNotes(),
                'adjusted_by'       => $adjustment->getAdjustedBy(),
            ];
        }

        usort($history, fn($a, $b) => strtotime($b['date'] ?? '') <=> strtotime($a['date'] ?? ''));

        return [
            'current_stock'   => $variant->getQuantity() ?? 0,
            'available_stock' => $this->calculateAvailableStock($variant),
            'reserved_stock'  => $variant->getReservedQuantity() ?? 0,
            'history'         => $history,
        ];
    }

    private function calculateAvailableStock(ProductVariant $variant): int
    {
        return max(0, ($variant->getQuantity() ?? 0) - ($variant->getReservedQuantity() ?? 0));
    }

    private function requireVariantId(ProductVariant $variant): int
    {
        $variantId = $variant->getId();
        if ($variantId <= 0) {
            throw new ValidationException('Product variant has not been saved yet');
        }

        return $variantId;
    }

    private function isLowStock(ProductVariant $variant): bool
    {
        $product = $this->productRepository->findById($variant->getProductId());
        if (!$product) {
            return false;
        }

        return $this->calculateAvailableStock($variant) <= $product->getSafetyStock();
    }

    /**
     * Triggers a warning log for low stock.
     * Optimized to accept an optional Product object to avoid redundant DB hits.
     */
    private function triggerLowStockAlert(ProductVariant $variant, ?Product $product = null): void
    {
        $product = $product ?? $this->productRepository->findById($variant->getProductId());
        if (!$product) {
            return;
        }

        $this->log('warning', 'Low stock alert', [
            'product'      => $product->getName(),
            'barcode'      => $variant->getBarcode(),
            'stock'        => $variant->getQuantity(),
            'available'    => $this->calculateAvailableStock($variant),
            'safety_stock' => $product->getSafetyStock() ?? 0,
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null && method_exists($this->logger, $level)) {
            $this->logger->{$level}($message, $context);
            return;
        }

        $encodedContext = $context === []
            ? ''
            : ' ' . json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR);

        error_log('[InventoryService][' . strtoupper($level) . '] ' . $message . $encodedContext);
    }

    private function calculateDaysOfSupply(ProductVariant $variant): ?int
    {
        $product = $this->productRepository->findById($variant->getProductId());
        if (!$product) {
            return null;
        }

        $dailyAverage = $this->productRepository->getAverageDailySales($product->getId());
        if ($dailyAverage <= 0) {
            return null;
        }

        return max(0, (int) floor($this->calculateAvailableStock($variant) / $dailyAverage));
    }

    private function calculateUrgency(ProductVariant $variant): string
    {
        $product = $this->productRepository->findById($variant->getProductId());
        if (!$product) {
            return 'NORMAL';
        }

        $availableStock = $this->calculateAvailableStock($variant);
        $safetyStock = $product->getSafetyStock() ?? 0;

        if ($safetyStock <= 0) {
            return 'NORMAL';
        }

        $ratio = $availableStock / $safetyStock;

        return match (true) {
            $ratio <= 0.2 => 'CRITICAL',
            $ratio <= 0.5 => 'HIGH',
            $ratio <= 0.8 => 'MEDIUM',
            $ratio <= 1.0 => 'LOW',
            default       => 'NORMAL',
        };
    }

    private function calculateTurnoverRate(): ?float
    {
        $totalSales   = $this->productRepository->getTotalSalesLastMonth() ?? 0.0;
        $averageStock = $this->productRepository->getAverageStockValue() ?? 0.0;

        if ($averageStock <= 0) {
            return null;
        }

        return round($totalSales / $averageStock, 2);
    }

    public function reconcileInventory(string $barcode, int $physicalCount, string $notes = ''): array
    {
        $variant = $this->productRepository->findVariantByBarcode($barcode);
        if (!$variant instanceof ProductVariant) {
            throw new ValidationException("Product variant not found for barcode: {$barcode}");
        }

        $systemCount = $variant->getQuantity() ?? 0;
        $difference = $physicalCount - $systemCount;

        if ($difference !== 0) {
            $adjustment = $this->adjustStock(
                $barcode,
                $difference,
                AdjustmentReason::AUDIT,
                "Reconciliation: System={$systemCount}, Physical={$physicalCount}. {$notes}",
                'system'
            );

            return [
                'reconciled'    => true,
                'difference'    => $difference,
                'adjustment_id' => $adjustment->getId(),
                'new_quantity'  => $adjustment->getNewQuantity(),
            ];
        }

        return [
            'reconciled'    => true,
            'difference'    => 0,
            'adjustment_id' => null,
            'new_quantity'  => $systemCount,
        ];
    }

    public function setSafetyStock(int $productId, int $safetyStock): bool
    {
        if ($safetyStock < 0) {
            throw new ValidationException('Safety stock cannot be negative');
        }

        $product = $this->productRepository->findById($productId);
        if (!$product) {
            throw new ValidationException("Product not found: {$productId}");
        }

        $product->setSafetyStock($safetyStock);

        if (!$this->productRepository->save($product)) {
            throw new ValidationException('Failed to update safety stock');
        }

        foreach ($this->productRepository->findVariantsByProductId($productId) as $variant) {
            if ($variant instanceof ProductVariant && $this->isLowStock($variant)) {
                $this->triggerLowStockAlert($variant);
            }
        }

        return true;
    }

    public function getVariantByBarcode(string $barcode): ?ProductVariant
    {
        return $this->productRepository->findVariantByBarcode($barcode);
    }

    public function getProductVariants(int $productId): array
    {
        return $this->productRepository->findVariantsByProductId($productId);
    }

    public function itemExists(string $barcode): bool
    {
        return $this->productRepository->findVariantByBarcode($barcode) !== null;
    }

    public function getTotalInventoryValue(): float
    {
        return $this->productRepository->calculateTotalStockValue() ?? 0.0;
    }

    public function getInventoryValueByCategory(): array
    {
        return $this->productRepository->getStockValueByCategory();
    }
}
