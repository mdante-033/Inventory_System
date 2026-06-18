<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductVariant;
use PDO;

class ProductRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /* ================================================================
       PRODUCT METHODS
       ================================================================ */

    public function findById(int $id): ?Product
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->hydrateProduct($data) : null;
    }

    public function findByBarcode(string $barcode): ?Product
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE barcode = :barcode");
        $stmt->execute(['barcode' => $barcode]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->hydrateProduct($data) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE sku = :sku");
        $stmt->execute(['sku' => $sku]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->hydrateProduct($data) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM products WHERE is_active = TRUE ORDER BY name");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    public function findByCategoryId(int $categoryId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM products WHERE category_id = :category_id AND is_active = TRUE ORDER BY name"
        );
        $stmt->execute(['category_id' => $categoryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    public function findLowStock(?int $threshold = null): array
    {
        if ($threshold === null) {
            $stmt = $this->pdo->query(
                "SELECT * FROM products WHERE quantity <= reorder_level AND is_active = TRUE ORDER BY quantity"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM products WHERE quantity <= :threshold AND is_active = TRUE ORDER BY quantity"
            );
            $stmt->execute(['threshold' => $threshold]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    public function save(Product $product): bool
    {
        if ($product->getId()) {
            $stmt = $this->pdo->prepare("
                UPDATE products 
                SET sku = :sku, 
                    barcode = :barcode,
                    name = :name,
                    description = :description,
                    category_id = :category_id,
                    unit_price = :unit_price,
                    cost_price = :cost_price,
                    quantity = :quantity,
                    reorder_level = :reorder_level,
                    safety_stock = :safety_stock,
                    type = :type,
                    is_active = :is_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            return $stmt->execute([
                'id' => $product->getId(),
                'sku' => $product->getSku(),
                'barcode' => $product->getBarcode(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'category_id' => $product->getCategoryId(),
                'unit_price' => $product->getUnitPrice(),
                'cost_price' => $product->getCostPrice(),
                'quantity' => $product->getQuantity(),
                'reorder_level' => $product->getReorderLevel(),
                'safety_stock' => $product->getSafetyStock(),
                'type' => $product->getType()?->value,
                'is_active' => $product->isActive() ? 1 : 0,
            ]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO products 
            (sku, barcode, name, description, category_id, unit_price, cost_price, quantity, reorder_level, safety_stock, type, is_active, created_at, updated_at)
            VALUES (:sku, :barcode, :name, :description, :category_id, :unit_price, :cost_price, :quantity, :reorder_level, :safety_stock, :type, :is_active, NOW(), NOW())
        ");
        return $stmt->execute([
            'sku' => $product->getSku(),
            'barcode' => $product->getBarcode(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'category_id' => $product->getCategoryId(),
            'unit_price' => $product->getUnitPrice(),
            'cost_price' => $product->getCostPrice(),
            'quantity' => $product->getQuantity(),
            'reorder_level' => $product->getReorderLevel(),
            'safety_stock' => $product->getSafetyStock(),
            'type' => $product->getType()?->value,
            'is_active' => $product->isActive() ? 1 : 0,
        ]);
    }

    public function updateQuantity(int $productId, int $newQuantity): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE products SET quantity = :quantity, updated_at = NOW() WHERE id = :id
        ");
        return $stmt->execute(['quantity' => $newQuantity, 'id' => $productId]);
    }

    public function adjustQuantity(int $productId, int $adjustment): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE products SET quantity = quantity + :adjustment, updated_at = NOW() WHERE id = :id
        ");
        return $stmt->execute(['adjustment' => $adjustment, 'id' => $productId]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE products SET is_active = FALSE, updated_at = NOW() WHERE id = :id
        ");
        return $stmt->execute(['id' => $id]);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM products WHERE is_active = TRUE");
        return (int) $stmt->fetchColumn();
    }

    public function countLowStock(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM products WHERE quantity <= reorder_level AND is_active = TRUE
        ");
        return (int) $stmt->fetchColumn();
    }

    public function calculateTotalValue(): ?float
    {
        $stmt = $this->pdo->query("
            SELECT COALESCE(SUM(quantity * unit_price), 0) FROM products WHERE is_active = TRUE
        ");
        return (float) $stmt->fetchColumn();
    }

    public function findTopSelling(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.id,
                p.sku,
                p.name,
                p.quantity,
                p.unit_price,
                COALESCE(SUM(sl.quantity_changed), 0) as total_sold
            FROM products p
            LEFT JOIN stock_logs sl ON p.id = sl.product_id AND sl.action = 'sale'
            WHERE p.is_active = TRUE
            GROUP BY p.id, p.sku, p.name, p.quantity, p.unit_price
            ORDER BY total_sold DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(string $searchTerm): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM products 
            WHERE (name LIKE :search OR sku LIKE :search OR barcode LIKE :search)
            AND is_active = TRUE 
            ORDER BY name
        ");
        $pattern = "%{$searchTerm}%";
        $stmt->execute(['search' => $pattern]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProduct'], $rows);
    }

    /* ================================================================
       VARIANT METHODS
       ================================================================ */

    public function findVariantByBarcode(string $barcode): ?ProductVariant
    {
        $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE barcode = :barcode LIMIT 1");
        $stmt->execute(['barcode' => $barcode]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->hydrateProductVariant($data) : null;
    }

    public function findVariantsByProductId(int $productId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM product_variants WHERE product_id = :product_id");
        $stmt->execute(['product_id' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProductVariant'], $rows);
    }

    public function saveVariant(ProductVariant $variant): bool
    {
        if ($variant->getId()) {
            $stmt = $this->pdo->prepare("
                UPDATE product_variants 
                SET product_id = :product_id,
                    barcode = :barcode,
                    color = :color,
                    quantity = :quantity,
                    reserved_quantity = :reserved_quantity,
                    updated_at = NOW()
                WHERE id = :id
            ");
            return $stmt->execute([
                'id' => $variant->getId(),
                'product_id' => $variant->getProductId(),
                'barcode' => $variant->getBarcode(),
                'color' => $variant->getColor()?->value,
                'quantity' => $variant->getQuantity(),
                'reserved_quantity' => $variant->getReservedQuantity() ?? 0,
            ]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO product_variants 
            (product_id, barcode, color, quantity, reserved_quantity, created_at, updated_at)
            VALUES (:product_id, :barcode, :color, :quantity, :reserved_quantity, NOW(), NOW())
        ");
        return $stmt->execute([
            'product_id' => $variant->getProductId(),
            'barcode' => $variant->getBarcode(),
            'color' => $variant->getColor()?->value,
            'quantity' => $variant->getQuantity(),
            'reserved_quantity' => $variant->getReservedQuantity() ?? 0,
        ]);
    }

    public function findLowStockVariants(?int $threshold = null): array
    {
        if ($threshold === null) {
            $stmt = $this->pdo->query("
                SELECT pv.* FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                WHERE (pv.quantity - COALESCE(pv.reserved_quantity, 0)) <= p.safety_stock
                  AND p.is_active = TRUE
            ");
        } else {
            $stmt = $this->pdo->prepare("
                SELECT pv.* FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                WHERE (pv.quantity - COALESCE(pv.reserved_quantity, 0)) <= :threshold
                  AND p.is_active = TRUE
            ");
            $stmt->execute(['threshold' => $threshold]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([$this, 'hydrateProductVariant'], $rows);
    }

    public function countAllVariants(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM product_variants");
        return (int) $stmt->fetchColumn();
    }

    public function calculateTotalStockValue(): ?float
    {
        return $this->calculateTotalValue();
    }

    public function countLowStockVariants(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE (pv.quantity - COALESCE(pv.reserved_quantity, 0)) <= p.safety_stock
              AND p.is_active = TRUE
        ");
        return (int) $stmt->fetchColumn();
    }

    public function countOutOfStockVariants(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.quantity <= 0 AND p.is_active = TRUE
        ");
        return (int) $stmt->fetchColumn();
    }

    public function findTopSellingProducts(int $limit): array
    {
        return $this->findTopSelling($limit);
    }

    public function getAverageDailySales(int $productId): float
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(AVG(quantity_changed), 0)
            FROM stock_logs
            WHERE product_id = :product_id
              AND action = 'sale'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute(['product_id' => $productId]);
        return (float) $stmt->fetchColumn();
    }

    public function getTotalSalesLastMonth(): ?float
    {
        $stmt = $this->pdo->query("
            SELECT COALESCE(SUM(sl.quantity_changed * p.unit_price), 0)
            FROM stock_logs sl
            JOIN products p ON sl.product_id = p.id
            WHERE sl.action = 'sale'
              AND sl.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
        ");
        return (float) $stmt->fetchColumn();
    }

    public function getAverageStockValue(): ?float
    {
        $stmt = $this->pdo->query("
            SELECT COALESCE(AVG(quantity * cost_price), 0) FROM products WHERE is_active = TRUE
        ");
        return (float) $stmt->fetchColumn();
    }

    public function getStockValueByCategory(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                c.name as category,
                COALESCE(SUM(p.quantity * p.unit_price), 0) as value
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.is_active = TRUE
            GROUP BY c.id, c.name
            ORDER BY value DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================================================================
       HYDRATORS
       ================================================================ */

    private function hydrateProduct(array $data): Product
    {
        $product = new Product();
        $product->setId($data['id'] ?? null)
            ->setSku($data['sku'] ?? '')
            ->setBarcode($data['barcode'] ?? '')
            ->setName($data['name'] ?? '')
            ->setDescription($data['description'] ?? null)
            ->setCategoryId($data['category_id'] ?? 0)
            ->setUnitPrice((float) ($data['unit_price'] ?? 0))
            ->setCostPrice((float) ($data['cost_price'] ?? 0))
            ->setQuantity((int) ($data['quantity'] ?? 0))
            ->setReorderLevel((int) ($data['reorder_level'] ?? 10))
            ->setSafetyStock((int) ($data['safety_stock'] ?? 0))
            ->setType($data['type'] ?? null)
            ->setIsActive((bool) ($data['is_active'] ?? true))
            ->setCreatedAt(new \DateTime($data['created_at'] ?? 'now'))
            ->setUpdatedAt(new \DateTime($data['updated_at'] ?? 'now'));

        return $product;
    }

    private function hydrateProductVariant(array $data): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setId($data['id'] ?? null)
            ->setProductId((int) ($data['product_id'] ?? 0))
            ->setBarcode($data['barcode'] ?? '')
            ->setColor($data['color'] ?? null)
            ->setQuantity((int) ($data['quantity'] ?? 0))
            ->setReservedQuantity((int) ($data['reserved_quantity'] ?? 0))
            ->setCreatedAt(new \DateTime($data['created_at'] ?? 'now'))
            ->setUpdatedAt(new \DateTime($data['updated_at'] ?? 'now'));

        return $variant;
    }
}