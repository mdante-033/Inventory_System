<?php
/**
 * fix_suppliers_table.php
 * ─────────────────────────────────────────────────────────────────────────
 * Run ONCE by visiting:
 *   https://inventory-system-1-tggc.onrender.com/fix_suppliers_table.php
 *
 * !! DELETE THIS FILE FROM GITHUB IMMEDIATELY AFTER RUNNING !!
 */

require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('<h2 style="color:red;font-family:monospace">❌ No database connection. Check DATABASE_URL.</h2>');
}

$results = [];
function runSQL(PDO $pdo, string $label, string $sql): array {
    try {
        $pdo->exec($sql);
        return ['label' => $label, 'ok' => true, 'msg' => 'OK'];
    } catch (PDOException $e) {
        return ['label' => $label, 'ok' => false, 'msg' => $e->getMessage()];
    }
}

// 1. Create suppliers table
$results[] = runSQL($pdo, 'CREATE suppliers', "
    CREATE TABLE IF NOT EXISTS suppliers (
        id            SERIAL PRIMARY KEY,
        name          VARCHAR(100) NOT NULL,
        contact_name  VARCHAR(100),
        email         VARCHAR(100),
        phone         VARCHAR(20),
        address       TEXT,
        is_active     BOOLEAN NOT NULL DEFAULT TRUE,
        notes         TEXT,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// 2. Ensure all other key tables exist (products, orders, etc.)
$tables = [
    'products' => "CREATE TABLE IF NOT EXISTS products (
        id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL, sku VARCHAR(50) UNIQUE,
        unit_price NUMERIC(12,2) NOT NULL DEFAULT 0, quantity INT NOT NULL DEFAULT 0,
        category VARCHAR(50), image_path VARCHAR(255), supplier_id INT,
        is_active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'orders' => "CREATE TABLE IF NOT EXISTS orders (
        id SERIAL PRIMARY KEY, user_id INT NOT NULL,
        order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, status VARCHAR(20) DEFAULT 'pending',
        payment_status VARCHAR(20) DEFAULT 'pending', total_amount NUMERIC(12,2) DEFAULT 0,
        notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'order_items' => "CREATE TABLE IF NOT EXISTS order_items (
        id SERIAL PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL,
        quantity INT DEFAULT 1, unit_price NUMERIC(12,2) DEFAULT 0,
        subtotal NUMERIC(12,2) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'payments' => "CREATE TABLE IF NOT EXISTS payments (
        id SERIAL PRIMARY KEY, order_id INT, payment_method VARCHAR(50),
        payment_gateway VARCHAR(50), amount NUMERIC(12,2), status VARCHAR(20) DEFAULT 'pending',
        transaction_id VARCHAR(100), reference_number VARCHAR(100),
        gateway_response JSONB, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'mpesa_transactions' => "CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id SERIAL PRIMARY KEY, order_id INT,
        checkout_request_id VARCHAR(100), merchant_request_id VARCHAR(100),
        phone_number VARCHAR(20), amount NUMERIC(12,2),
        result_code VARCHAR(10), result_desc TEXT,
        mpesa_receipt_number VARCHAR(50), status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];
foreach ($tables as $name => $sql) {
    $results[] = runSQL($pdo, "CREATE $name", $sql);
}

// 3. Add missing columns in users table
$userCols = ['phone' => 'VARCHAR(20)', 'role' => "VARCHAR(20) DEFAULT 'customer'",
             'is_active' => 'BOOLEAN DEFAULT TRUE', 'is_verified' => 'BOOLEAN DEFAULT FALSE',
             'account_status' => "VARCHAR(20) DEFAULT 'pending'"];
foreach ($userCols as $col => $def) {
    $results[] = runSQL($pdo, "ADD users.$col",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS $col $def");
}

$ok = array_filter($results, fn($r) => $r['ok']);
$bad = array_filter($results, fn($r) => !$r['ok']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>DB Fix</title>
<style>body{font-family:monospace;background:#0d0d1a;color:#e0e0e0;padding:40px}
.ok{color:#0f0}.err{color:#f44}.card{background:#111;padding:10px;margin:10px 0;border-radius:4px}
</style></head><body>
<h2>✅ Database schema fixed</h2>
<p>Operations succeeded: <?= count($ok) ?></p>
<?php if($bad): ?><p>Issues: <?= count($bad) ?></p><?php endif; ?>
<p style="color:#f80">⚠️ DELETE this file now from your repository!</p>
</body></html>
