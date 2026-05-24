<?php
/**
 * setup_database.php
 * ─────────────────────────────────────────────────────────────────────────
 * Run ONCE by visiting:
 *   https://inventory-system-1-tggc.onrender.com/setup_database.php
 *
 * Creates ALL missing tables for the Inventory System:
 *   categories, suppliers, products, stock_logs, orders,
 *   order_items, payments, payment_transactions, mpesa_transactions,
 *   app_settings, audit_logs
 *
 * Safe to run multiple times — uses CREATE TABLE IF NOT EXISTS
 * !! DELETE THIS FILE FROM GITHUB IMMEDIATELY AFTER RUNNING !!
 * ─────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/db_connect.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('<h2 style="color:red;font-family:monospace">❌ No DB connection. Check DATABASE_URL in Render environment.</h2>');
}

$results = [];

function run(PDO $pdo, string $label, string $sql): void {
    global $results;
    try {
        $pdo->exec($sql);
        $results[] = ['ok' => true,  'label' => $label];
    } catch (PDOException $e) {
        $results[] = ['ok' => false, 'label' => $label, 'msg' => $e->getMessage()];
    }
}

// ── 1. categories ─────────────────────────────────────────────────────────
run($pdo, 'CREATE categories', "
    CREATE TABLE IF NOT EXISTS categories (
        id          SERIAL       PRIMARY KEY,
        name        VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 2. suppliers ──────────────────────────────────────────────────────────
run($pdo, 'CREATE suppliers', "
    CREATE TABLE IF NOT EXISTS suppliers (
        id             SERIAL       PRIMARY KEY,
        company_name   VARCHAR(100) NOT NULL,
        contact_person VARCHAR(100),
        email          VARCHAR(100),
        phone          VARCHAR(20),
        address        TEXT,
        city           VARCHAR(100),
        is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
        notes          TEXT,
        created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 3. products ───────────────────────────────────────────────────────────
run($pdo, 'CREATE products', "
    CREATE TABLE IF NOT EXISTS products (
        id            SERIAL         PRIMARY KEY,
        name          VARCHAR(100)   NOT NULL,
        description   TEXT,
        sku           VARCHAR(50)    UNIQUE,
        category_id   INT            REFERENCES categories(id) ON DELETE SET NULL,
        supplier_id   INT            REFERENCES suppliers(id)  ON DELETE SET NULL,
        unit_price    NUMERIC(12,2)  NOT NULL DEFAULT 0,
        cost_price    NUMERIC(12,2)  NOT NULL DEFAULT 0,
        quantity      INT            NOT NULL DEFAULT 0,
        reorder_level INT            NOT NULL DEFAULT 10,
        image_path    VARCHAR(255),
        is_active     BOOLEAN        NOT NULL DEFAULT TRUE,
        created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 4. stock_logs ─────────────────────────────────────────────────────────
run($pdo, 'CREATE stock_logs', "
    CREATE TABLE IF NOT EXISTS stock_logs (
        id               SERIAL        PRIMARY KEY,
        product_id       INT           NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id          INT,
        action           VARCHAR(50)   NOT NULL,
        quantity_before  INT           NOT NULL DEFAULT 0,
        quantity_after   INT           NOT NULL DEFAULT 0,
        quantity_changed INT           NOT NULL DEFAULT 0,
        notes            TEXT,
        created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 5. orders ─────────────────────────────────────────────────────────────
run($pdo, 'CREATE orders', "
    CREATE TABLE IF NOT EXISTS orders (
        id               SERIAL        PRIMARY KEY,
        order_number     VARCHAR(50),
        user_id          INT,
        customer_name    VARCHAR(100),
        customer_email   VARCHAR(100),
        shipping_address TEXT,
        notes            TEXT,
        subtotal         NUMERIC(12,2) NOT NULL DEFAULT 0,
        tax_amount       NUMERIC(12,2) NOT NULL DEFAULT 0,
        total_amount     NUMERIC(12,2) NOT NULL DEFAULT 0,
        status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
        payment_status   VARCHAR(20)   NOT NULL DEFAULT 'pending',
        order_date       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 6. order_items ────────────────────────────────────────────────────────
run($pdo, 'CREATE order_items', "
    CREATE TABLE IF NOT EXISTS order_items (
        id           SERIAL        PRIMARY KEY,
        order_id     INT           NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        product_id   INT,
        product_name VARCHAR(100),
        quantity     INT           NOT NULL DEFAULT 1,
        unit_price   NUMERIC(12,2) NOT NULL DEFAULT 0,
        subtotal     NUMERIC(12,2) NOT NULL DEFAULT 0,
        created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 7. payments ───────────────────────────────────────────────────────────
run($pdo, 'CREATE payments', "
    CREATE TABLE IF NOT EXISTS payments (
        id               SERIAL        PRIMARY KEY,
        order_id         INT           REFERENCES orders(id) ON DELETE SET NULL,
        payment_method   VARCHAR(50),
        payment_gateway  VARCHAR(50),
        amount           NUMERIC(12,2) NOT NULL DEFAULT 0,
        status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
        transaction_id   VARCHAR(100),
        reference_number VARCHAR(100),
        gateway_response JSONB,
        created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 8. payment_transactions ───────────────────────────────────────────────
run($pdo, 'CREATE payment_transactions', "
    CREATE TABLE IF NOT EXISTS payment_transactions (
        id               SERIAL        PRIMARY KEY,
        order_id         INT           REFERENCES orders(id) ON DELETE SET NULL,
        transaction_id   VARCHAR(100),
        payment_gateway  VARCHAR(50),
        amount           NUMERIC(12,2) NOT NULL DEFAULT 0,
        status           VARCHAR(20)   NOT NULL DEFAULT 'pending',
        gateway_response JSONB,
        created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 9. mpesa_transactions ─────────────────────────────────────────────────
run($pdo, 'CREATE mpesa_transactions', "
    CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id                   SERIAL        PRIMARY KEY,
        order_id             INT           REFERENCES orders(id) ON DELETE SET NULL,
        checkout_request_id  VARCHAR(100),
        merchant_request_id  VARCHAR(100),
        phone_number         VARCHAR(20),
        amount               NUMERIC(12,2),
        result_code          VARCHAR(10),
        result_desc          TEXT,
        mpesa_receipt_number VARCHAR(50),
        status               VARCHAR(20)   NOT NULL DEFAULT 'pending',
        created_at           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 10. app_settings ──────────────────────────────────────────────────────
run($pdo, 'CREATE app_settings', "
    CREATE TABLE IF NOT EXISTS app_settings (
        id         SERIAL       PRIMARY KEY,
        key        VARCHAR(100) NOT NULL UNIQUE,
        value      TEXT,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 11. audit_logs ────────────────────────────────────────────────────────
run($pdo, 'CREATE audit_logs', "
    CREATE TABLE IF NOT EXISTS audit_logs (
        id         SERIAL       PRIMARY KEY,
        user_id    INT,
        action     VARCHAR(100),
        table_name VARCHAR(50),
        record_id  INT,
        details    JSONB,
        ip_address VARCHAR(45),
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 12. Seed one default category so products can be added immediately ────
run($pdo, 'Seed default category', "
    INSERT INTO categories (name, description)
    VALUES ('General', 'Default category')
    ON CONFLICT (name) DO NOTHING
");

// ── 13. Verify all tables now exist ───────────────────────────────────────
$tableCheck = [];
$tables = ['users','categories','suppliers','products','stock_logs',
           'orders','order_items','payments','payment_transactions',
           'mpesa_transactions','app_settings','audit_logs'];
foreach ($tables as $t) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $tableCheck[$t] = ['exists' => true, 'rows' => $cnt];
    } catch (PDOException $e) {
        $tableCheck[$t] = ['exists' => false, 'rows' => 0];
    }
}

// ── Render HTML report ────────────────────────────────────────────────────
$ok  = array_filter($results, fn($r) => $r['ok']);
$bad = array_filter($results, fn($r) => !$r['ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:monospace;background:#0d0d1a;color:#e0e0e0;padding:40px;min-height:100vh}
h2{color:#00ff88;margin-bottom:4px;font-size:1.4rem}
.sub{color:#666;font-size:13px;margin-bottom:24px}
.summary{background:#0a1a0a;border:1px solid #00cc66;border-radius:10px;padding:20px;margin-bottom:20px}
.summary .ok{color:#00cc66;font-size:15px;font-weight:bold}
.summary .err{color:#ff4444;font-size:13px;margin-top:6px}
.card{background:#111122;border:1px solid #222244;border-radius:10px;padding:20px;margin-bottom:16px}
.card h3{color:#6688cc;font-size:11px;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}
.row{display:flex;align-items:baseline;gap:10px;padding:5px 0;border-bottom:1px solid #1a1a2e;font-size:13px}
.row:last-child{border-bottom:none}
.tick{color:#00cc66;font-weight:bold;min-width:14px}
.cross{color:#ff4444;font-weight:bold;min-width:14px}
.rows-badge{background:#1a2a1a;color:#88cc88;padding:1px 8px;border-radius:99px;font-size:11px}
.rows-badge.zero{background:#2a1a1a;color:#cc8888}
.err-msg{color:#664444;font-size:11px;margin-left:4px}
.cta{background:#001a0a;border:1px solid #ff8800;border-radius:10px;padding:20px;margin-top:24px;color:#ffaa44;line-height:1.8}
.cta strong{color:#ff8800}
.cta code{color:#ffcc88;background:#1a1000;padding:2px 6px;border-radius:4px}
a{color:#00aaff}
</style>
</head>
<body>
<h2>Database Setup Complete</h2>
<div class="sub"><?= date('Y-m-d H:i:s') ?> UTC</div>

<div class="summary">
    <div class="ok">✅ <?= count($ok) ?> operations succeeded</div>
    <?php if (count($bad)): ?>
    <div class="err">⚠️ <?= count($bad) ?> had issues — check details below</div>
    <?php endif; ?>
</div>

<?php if (count($bad)): ?>
<div class="card">
    <h3>Issues</h3>
    <?php foreach ($bad as $r): ?>
    <div class="row">
        <span class="cross">✗</span>
        <span><?= htmlspecialchars($r['label']) ?></span>
        <span class="err-msg"><?= htmlspecialchars(substr($r['msg'] ?? '', 0, 120)) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>Table Status</h3>
    <?php foreach ($tableCheck as $table => $info): ?>
    <div class="row">
        <span class="<?= $info['exists'] ? 'tick' : 'cross' ?>"><?= $info['exists'] ? '✓' : '✗' ?></span>
        <span style="min-width:200px"><?= $table ?></span>
        <span class="rows-badge <?= $info['rows'] == 0 ? 'zero' : '' ?>">
            <?= $info['exists'] ? $info['rows'] . ' rows' : 'MISSING' ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<div class="cta">
    <strong> What to do now:</strong><br><br>
    <?php
    $allOk = !in_array(false, array_column($tableCheck, 'exists'));
    if ($allOk): ?>
         All tables created successfully!<br><br>
        1. Go to <a href="admin.php">admin.php</a> and add products via the Products page.<br>
        2. <strong>Delete this file from GitHub immediately:</strong><br>
        <code>git rm setup_database.php && git commit -m "Remove DB setup script" && git push</code>
    <?php else: ?>
         Some tables are still missing. Check the Issues section above.<br>
        The most common cause is a permissions error — your DB user may not have CREATE TABLE rights.<br>
        Contact Render support or check your PostgreSQL role permissions.
    <?php endif; ?>
</div>
</body>
</html>
