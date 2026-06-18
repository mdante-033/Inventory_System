<?php

namespace App\Repositories;

use App\Models\StockAdjustment;
use App\Enums\AdjustmentReason;
use PDO;

class StockAdjustmentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(StockAdjustment $adjustment): bool
    {
        $reasonValue = (string) $adjustment->getReason();

        if ($adjustment->getId()) {
            $stmt = $this->pdo->prepare("
                UPDATE stock_adjustments 
                SET variant_id = :variant_id,
                    previous_quantity = :previous_quantity,
                    new_quantity = :new_quantity,
                    adjustment = :adjustment,
                    reason = :reason,
                    notes = :notes,
                    adjusted_by = :adjusted_by,
                    updated_at = NOW()
                WHERE id = :id
            ");
            return $stmt->execute([
                'id' => $adjustment->getId(),
                'variant_id' => $adjustment->getVariantId(),
                'previous_quantity' => $adjustment->getPreviousQuantity(),
                'new_quantity' => $adjustment->getNewQuantity(),
                'adjustment' => $adjustment->getAdjustment(),
                'reason' => $reasonValue,
                'notes' => $adjustment->getNotes(),
                'adjusted_by' => $adjustment->getAdjustedBy(),
            ]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO stock_adjustments 
            (variant_id, previous_quantity, new_quantity, adjustment, reason, notes, adjusted_by, created_at)
            VALUES (:variant_id, :previous_quantity, :new_quantity, :adjustment, :reason, :notes, :adjusted_by, NOW())
        ");
        return $stmt->execute([
            'variant_id' => $adjustment->getVariantId(),
            'previous_quantity' => $adjustment->getPreviousQuantity(),
            'new_quantity' => $adjustment->getNewQuantity(),
            'adjustment' => $adjustment->getAdjustment(),
            'reason' => $reasonValue,
            'notes' => $adjustment->getNotes(),
            'adjusted_by' => $adjustment->getAdjustedBy(),
        ]);
    }

    public function findRecentAdjustments(int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM stock_adjustments
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateStockAdjustment'], $rows);
    }

    public function findAdjustmentsByVariantId(int $variantId, int $days = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM stock_adjustments
            WHERE variant_id = :variant_id
              AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY created_at DESC
        ");
        $stmt->bindValue('variant_id', $variantId, PDO::PARAM_INT);
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateStockAdjustment'], $rows);
    }

    public function createReservation(int $variantId, int $orderId, int $quantity): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO reservations (variant_id, order_id, quantity, created_at)
            VALUES (:variant_id, :order_id, :quantity, NOW())
        ");
        return $stmt->execute([
            'variant_id' => $variantId,
            'order_id' => $orderId,
            'quantity' => $quantity,
        ]);
    }

    public function findReservation(int $variantId, int $orderId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM reservations
            WHERE variant_id = :variant_id
              AND order_id = :order_id
              AND released_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            'variant_id' => $variantId,
            'order_id' => $orderId,
        ]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ?: null;
    }

    public function releaseReservation(int $variantId, int $orderId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE reservations
            SET released_at = NOW()
            WHERE variant_id = :variant_id
              AND order_id = :order_id
              AND released_at IS NULL
        ");
        return $stmt->execute([
            'variant_id' => $variantId,
            'order_id' => $orderId,
        ]);
    }

    private function hydrateStockAdjustment(array $data): StockAdjustment
    {
        $adjustment = new StockAdjustment();
        $adjustment->setId($data['id'] ?? null)
            ->setVariantId((int) ($data['variant_id'] ?? 0))
            ->setPreviousQuantity((int) ($data['previous_quantity'] ?? 0))
            ->setNewQuantity((int) ($data['new_quantity'] ?? 0))
            ->setAdjustment((int) ($data['adjustment'] ?? 0))
            ->setReason(AdjustmentReason::from($data['reason']))
            ->setNotes($data['notes'] ?? '')
            ->setAdjustedBy($data['adjusted_by'] ?? 'system')
            ->setCreatedAt(new \DateTime($data['created_at'] ?? 'now'));

        return $adjustment;
    }
}