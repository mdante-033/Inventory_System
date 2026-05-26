<?php
/**
 * setup_database.php
 * ─────────────────────────────────────────────────────────────────────────
 * Place in project ROOT (same folder as login.php)
 * Run ONCE by visiting:
 *   https://inventory-system-1-tggc.onrender.com/setup_database.php
 *
 * !! DELETE THIS FILE FROM GITHUB IMMEDIATELY AFTER RUNNING !!
 * ─────────────────────────────────────────────────────────────────────────
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Database Setup</title>
<style>
body { font-family: monospace; background: #0d0d1a; color: #e0e0e0; padding: 40px; margin: 0; }
h1   { color: #00ff88; margin-bottom: 24px; font-size: 1.6rem; }
pre  { background: #111122; border: 1px solid #222244; border-radius: 10px; padding: 24px; font-size: 13px; line-height: 1.8; white-space: pre-wrap; }
.ok  { color: #00cc66; }
.err { color: #ff4444; }
.hd  { color: #6688cc; font-weight: bold; }
.cta { background: #001a0a; border: 1px solid #ff8800; border-radius: 10px; padding: 20px; margin-top: 24px; color: #ffaa44; line-height: 1.9; }
.cta strong { color: #ff8800; }
a { color: #00aaff; }
code { background: #1a1000; color: #ffcc88; padding: 2px 6px; border-radius: 4px; }
</style>
</head>
<body>
<h1>Inventory System — Database Setup</h1>
<pre>
<?php

// ── 1. Connect to database ────────────────────────────────────────────────────
echo "<span class='hd'>STEP 1 — Connecting to database</span>\n";

$databaseUrl = getenv('DATABASE_URL');
$pdo = null;

if ($databaseUrl) {
    $p        = parse_url($databaseUrl);
    $host     = $p['host'];
    $port     = $p['port'] ?? 5432;
    $dbname   = ltrim($p['path'], '/');
    $username = $p['user'];
    $password = $p['pass'];
    echo "<span class='ok'>✓ Using DATABASE_URL from Render environment</span>\n";
} else {
    $host     = getenv('DB_HOST')     ?: 'localhost';
    $port     = getenv('DB_PORT')     ?: '5432';
    $dbname   = getenv('DB_NAME')     ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
    $username = getenv('DB_USER')     ?: getenv('DB_USERNAME') ?: 'postgres';
    $password = getenv('DB_PASSWORD') ?: '';
    echo "<span class='ok'>✓ Using individual DB_* environment variables</span>\n";
}

echo "  Host:     $host\n";
echo "  Port:     $port\n";
echo "  Database: $dbname\n";
echo "  Username: $username\n\n";

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET search_path TO public");
    echo "<span class='ok'>✓ Connected successfully!</span>\n\n";
} catch (PDOException $e) {
    echo "<span class='err'>✗ Connection FAILED: " . htmlspecialchars($e->getMessage()) . "</span>\n\n";
    echo "<span class='err'>Check that DATABASE_URL is set in Render → Environment variables.</span>\n";
    echo "</pre></body></html>";
    exit;
}

// ── helper ────────────────────────────────────────────────────────────────────
function runSQL(PDO $pdo, string $label, string $sql): bool {
    try {
        $pdo->exec($sql);
        echo "<span class='ok'>✓ $label</span>\n";
        return true;
    } catch (PDOException $e) {
        echo "<span class='err'>✗ $label — " . htmlspecialchars($e->getMessage()) . "</span>\n";
        return false;
    }
}

// ── 2. Create tables ──────────────────────────────────────────────────────────
echo "<span class='hd'>STEP 2 — Creating tables</span>\n";

runSQL($pdo, 'users table', "
    CREATE TABLE IF NOT EXISTS users (
        id                           SERIAL        PRIMARY KEY,
        username                     VARCHAR(50)   UNIQUE NOT NULL,
        password                     VARCHAR(255)  NOT NULL,
        email                        VARCHAR(100)  UNIQUE NOT NULL,
        full_name                    VARCHAR(100)  NOT NULL,
        phone                        VARCHAR(20),
        customer_group               VARCHAR(50)   DEFAULT 'regular',
        role                         VARCHAR(20)   DEFAULT 'customer'
            CHECK (role IN ('admin','manager','staff','customer','supplier')),
        is_active                    BOOLEAN       DEFAULT TRUE,
        is_verified                  BOOLEAN       DEFAULT FALSE,
        account_status               VARCHAR(20)   DEFAULT 'pending',
        verification_code            VARCHAR(255),
        verification_code_expires_at TIMESTAMP,
        verification_attempts        INTEGER       DEFAULT 0,
        verification_failed_attempts INTEGER       DEFAULT 0,
        verification_resend_count    INTEGER       DEFAULT 0,
        resend_count                 INTEGER       DEFAULT 0,
        code_expiry                  TIMESTAMP,
        created_at                   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        updated_at                   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'categories table', "
    CREATE TABLE IF NOT EXISTS categories (
        id          SERIAL       PRIMARY KEY,
        name        VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        parent_id   INTEGER      REFERENCES categories(id) ON DELETE SET NULL,
        is_active   BOOLEAN      DEFAULT TRUE,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'suppliers table', "
    CREATE TABLE IF NOT EXISTS suppliers (
        id             SERIAL       PRIMARY KEY,
        company_name   VARCHAR(200) NOT NULL,
        contact_person VARCHAR(100),
        email          VARCHAR(100),
        phone          VARCHAR(20),
        address        TEXT,
        city           VARCHAR(100),
        is_active      BOOLEAN      DEFAULT TRUE,
        notes          TEXT,
        created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'products table', "
    CREATE TABLE IF NOT EXISTS products (
        id            SERIAL          PRIMARY KEY,
        sku           VARCHAR(50)     UNIQUE NOT NULL,
        barcode       VARCHAR(50),
        name          VARCHAR(200)    NOT NULL,
        description   TEXT,
        category_id   INTEGER         REFERENCES categories(id) ON DELETE RESTRICT,
        supplier_id   INTEGER         REFERENCES suppliers(id)  ON DELETE SET NULL,
        unit_price    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        cost_price    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        quantity      INTEGER         NOT NULL DEFAULT 0,
        reorder_level INTEGER         NOT NULL DEFAULT 10,
        is_active     BOOLEAN         DEFAULT TRUE,
        image_path    VARCHAR(500),
        created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'orders table', "
    CREATE TABLE IF NOT EXISTS orders (
        id               SERIAL         PRIMARY KEY,
        user_id          INTEGER        REFERENCES users(id),
        order_number     VARCHAR(50),
        customer_name    VARCHAR(100),
        customer_email   VARCHAR(100),
        status           VARCHAR(20)    DEFAULT 'pending',
        payment_status   VARCHAR(20)    DEFAULT 'pending',
        payment_method   VARCHAR(50),
        transaction_id   VARCHAR(100),
        total_amount     DECIMAL(10,2)  DEFAULT 0.00,
        subtotal         DECIMAL(10,2)  DEFAULT 0.00,
        tax_amount       DECIMAL(10,2)  DEFAULT 0.00,
        shipping_address TEXT,
        billing_address  TEXT,
        notes            TEXT,
        order_date       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'order_items table', "
    CREATE TABLE IF NOT EXISTS order_items (
        id           SERIAL         PRIMARY KEY,
        order_id     INTEGER        NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        product_id   INTEGER        REFERENCES products(id),
        product_name VARCHAR(200),
        quantity     INTEGER        NOT NULL DEFAULT 1,
        unit_price   DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        subtotal     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
        created_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'payments table', "
    CREATE TABLE IF NOT EXISTS payments (
        id               SERIAL         PRIMARY KEY,
        order_id         INTEGER        REFERENCES orders(id) ON DELETE SET NULL,
        payment_method   VARCHAR(50),
        payment_gateway  VARCHAR(50),
        amount           DECIMAL(10,2)  DEFAULT 0,
        status           VARCHAR(20)    DEFAULT 'pending',
        transaction_id   VARCHAR(100),
        reference_number VARCHAR(100),
        gateway_response JSONB,
        created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'payment_transactions table', "
    CREATE TABLE IF NOT EXISTS payment_transactions (
        id                  SERIAL         PRIMARY KEY,
        order_id            INTEGER        REFERENCES orders(id) ON DELETE SET NULL,
        transaction_id      VARCHAR(100),
        payment_gateway     VARCHAR(50)    DEFAULT 'manual',
        payment_method      VARCHAR(50),
        amount              DECIMAL(10,2),
        status              VARCHAR(20)    DEFAULT 'pending',
        reference_number    VARCHAR(100),
        checkout_request_id VARCHAR(100),
        gateway_response    TEXT,
        created_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'stock_logs table', "
    CREATE TABLE IF NOT EXISTS stock_logs (
        id               SERIAL      PRIMARY KEY,
        product_id       INTEGER     NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id          INTEGER,
        action           VARCHAR(20) NOT NULL,
        quantity_before  INTEGER     NOT NULL DEFAULT 0,
        quantity_after   INTEGER     NOT NULL DEFAULT 0,
        quantity_changed INTEGER     NOT NULL DEFAULT 0,
        reference_number VARCHAR(50),
        notes            TEXT,
        created_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'mpesa_transactions table', "
    CREATE TABLE IF NOT EXISTS mpesa_transactions (
        id                   SERIAL         PRIMARY KEY,
        order_id             INTEGER        REFERENCES orders(id) ON DELETE SET NULL,
        checkout_request_id  VARCHAR(100),
        merchant_request_id  VARCHAR(100),
        phone_number         VARCHAR(20),
        amount               DECIMAL(10,2),
        result_code          VARCHAR(10),
        result_desc          TEXT,
        mpesa_receipt_number VARCHAR(50),
        status               VARCHAR(20)    DEFAULT 'pending',
        created_at           TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        updated_at           TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'app_settings table', "
    CREATE TABLE IF NOT EXISTS app_settings (
        id            SERIAL       PRIMARY KEY,
        setting_key   VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

runSQL($pdo, 'audit_logs table', "
    CREATE TABLE IF NOT EXISTS audit_logs (
        id         SERIAL       PRIMARY KEY,
        user_id    INTEGER,
        action     VARCHAR(100),
        table_name VARCHAR(50),
        record_id  INTEGER,
        details    JSONB,
        ip_address VARCHAR(45),
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    )
");

// ── 3. Indexes ────────────────────────────────────────────────────────────────
echo "\n<span class='hd'>STEP 3 — Creating indexes</span>\n";
foreach ([
    "CREATE INDEX IF NOT EXISTS idx_users_username  ON users(username)",
    "CREATE INDEX IF NOT EXISTS idx_users_email     ON users(email)",
    "CREATE INDEX IF NOT EXISTS idx_users_role      ON users(role)",
    "CREATE INDEX IF NOT EXISTS idx_products_sku    ON products(sku)",
    "CREATE INDEX IF NOT EXISTS idx_products_cat    ON products(category_id)",
    "CREATE INDEX IF NOT EXISTS idx_products_qty    ON products(quantity)",
    "CREATE INDEX IF NOT EXISTS idx_stock_product   ON stock_logs(product_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_user     ON orders(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_status   ON orders(status)",
    "CREATE INDEX IF NOT EXISTS idx_payments_order  ON payment_transactions(order_id)",
] as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) {}
}
echo "<span class='ok'>✓ All indexes created</span>\n";

// ── 4. Auto-update trigger ────────────────────────────────────────────────────
echo "\n<span class='hd'>STEP 4 — Creating triggers</span>\n";
runSQL($pdo, 'updated_at trigger function', "
    CREATE OR REPLACE FUNCTION update_updated_at_column()
    RETURNS TRIGGER AS \$\$
    BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END;
    \$\$ LANGUAGE plpgsql
");
foreach (['users','categories','suppliers','products','orders',
          'payments','payment_transactions','stock_logs','app_settings'] as $t) {
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS trg_{$t}_updated_at ON {$t}");
        $pdo->exec("CREATE TRIGGER trg_{$t}_updated_at
            BEFORE UPDATE ON {$t}
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()");
    } catch (PDOException $e) {}
}
echo "<span class='ok'>✓ Triggers created for all tables</span>\n";

// ── 5. Seed data ──────────────────────────────────────────────────────────────
echo "\n<span class='hd'>STEP 5 — Seeding sample data</span>\n";

// Admin user (password: admin123)
$hash = password_hash('admin123', PASSWORD_DEFAULT);
try {
    $pdo->prepare("
        INSERT INTO users (username,password,email,full_name,role,is_active,is_verified,account_status)
        VALUES ('admin',?,'admin@inventorysystem.com','System Administrator','admin',TRUE,TRUE,'active')
        ON CONFLICT (username) DO UPDATE SET
            password=EXCLUDED.password, is_verified=TRUE,
            is_active=TRUE, account_status='active', role='admin'
    ")->execute([$hash]);
    echo "<span class='ok'>✓ Admin user ready (username: admin / password: admin123)</span>\n";
} catch (PDOException $e) {
    echo "<span class='err'>✗ Admin user — " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Sample categories
try {
    $pdo->exec("
        INSERT INTO categories (name, description) VALUES
        ('Electronics',    'Electronic devices and accessories'),
        ('Computers',      'Laptops and computer accessories'),
        ('Mobile Devices', 'Smartphones and tablets'),
        ('Office Supplies','General office supplies'),
        ('Furniture',      'Office and home furniture'),
        ('Clothing',       'Apparel and fashion items'),
        ('General',        'Default category')
        ON CONFLICT (name) DO NOTHING
    ");
    echo "<span class='ok'>✓ Sample categories inserted</span>\n";
} catch (PDOException $e) {
    echo "<span class='err'>✗ Categories — " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Sample products
try {
    $pdo->exec("
        INSERT INTO products (sku,name,description,category_id,unit_price,cost_price,quantity,reorder_level) VALUES
        ('ELEC001','Wireless Mouse',      'Ergonomic wireless mouse',      1, 25.99, 15.00, 100, 20),
        ('ELEC002','USB Keyboard',        'Mechanical RGB keyboard',       1, 45.99, 28.00,  75, 15),
        ('COMP001','Laptop Stand',        'Adjustable aluminum stand',     2, 35.99, 20.00,  50, 10),
        ('MOBI001','Phone Charger',       'Fast charging USB-C 20W',       3, 19.99, 10.00, 200, 50),
        ('OFFI001','Ballpoint Pens (Box)','Box of 50 blue pens',           4, 12.99,  6.00, 150, 30),
        ('FURN001','Office Chair',        'Ergonomic lumbar support',      5,149.99, 90.00,  25,  5),
        ('CLTH001','T-Shirt (Cotton)',    '100% cotton black medium',      6, 15.99,  8.00, 100, 25)
        ON CONFLICT (sku) DO NOTHING
    ");
    echo "<span class='ok'>✓ Sample products inserted</span>\n";
} catch (PDOException $e) {
    echo "<span class='err'>✗ Products — " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// Default app settings
try {
    $pdo->exec("
        INSERT INTO app_settings (setting_key, setting_value) VALUES
        ('store_name',          'Inventory System'),
        ('store_email',         'admin@inventorysystem.com'),
        ('currency',            'KES'),
        ('timezone',            'Africa/Nairobi'),
        ('low_stock_threshold', '5')
        ON CONFLICT (setting_key) DO NOTHING
    ");
    echo "<span class='ok'>✓ Default settings inserted</span>\n";
} catch (PDOException $e) {
    echo "<span class='err'>✗ Settings — " . htmlspecialchars($e->getMessage()) . "</span>\n";
}

// ── 6. Verify ─────────────────────────────────────────────────────────────────
echo "\n<span class='hd'>STEP 6 — Verifying all tables</span>\n";
$tables = $pdo->query("
    SELECT table_name FROM information_schema.tables
    WHERE table_schema='public' AND table_type='BASE TABLE'
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN);

$required = ['users','categories','suppliers','products','orders','order_items',
             'payments','payment_transactions','stock_logs','mpesa_transactions',
             'app_settings','audit_logs'];
$allOk = true;
foreach ($required as $t) {
    if (in_array($t, $tables)) {
        try {
            $cnt = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            echo "<span class='ok'>✓ $t ($cnt rows)</span>\n";
        } catch (PDOException $e) {
            echo "<span class='ok'>✓ $t</span>\n";
        }
    } else {
        echo "<span class='err'>✗ $t — MISSING</span>\n";
        $allOk = false;
    }
}

echo "\n";
if ($allOk) {
    echo "<span class='ok' style='font-size:15px'>════════════════════════════════════
✓ DATABASE SETUP COMPLETED SUCCESSFULLY!
════════════════════════════════════</span>\n\n";
    echo "Login at: <a href='login.php'>login.php</a>\n";
    echo "  Username: <strong>admin</strong>\n";
    echo "  Password: <strong>admin123</strong>\n";
} else {
    echo "<span class='err'>✗ Some tables are missing — check errors above.</span>\n";
}
?>
</pre>

<div class="cta">
    <strong>⚠ SECURITY — DO THIS IMMEDIATELY AFTER RUNNING:</strong><br><br>
    1. Log in at <a href="login.php">login.php</a> with <code>admin</code> / <code>admin123</code><br>
    2. Change the admin password immediately after logging in<br>
    3. Delete this file from GitHub:<br>
    <code>git rm setup_database.php &amp;&amp; git commit -m "Remove setup script" &amp;&amp; git push</code>
</div>
</body>
</html>
