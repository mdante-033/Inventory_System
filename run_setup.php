<?php
/**
 * run_setup.php — Universal database installer
 *
 * Works on:
 *   • Render   — reads DATABASE_URL env var (set automatically when you
 *                link a PostgreSQL service to your web service)
 *   • Local    — reads DB_* / PG* env vars, or falls back to
 *                localhost / Inventory_DB / postgres / Root
 *
 * Visit this page once, confirm PASS, then delete the file and redeploy.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ── Environment detection ───────────────────────────────────────── */
$isRender = (bool) (getenv('RENDER') ?: getenv('RENDER_SERVICE_ID') ?: getenv('DATABASE_URL'));
$envLabel  = $isRender ? 'Render (Production)' : 'Local Development';

$results = [];

function setupLog(string $label, bool $ok, string $message = ''): void
{
    global $results;
    $results[] = ['label' => $label, 'ok' => $ok, 'message' => $message];
}

function runSQL(PDO $pdo, string $label, string $sql): bool
{
    try {
        $pdo->exec($sql);
        setupLog($label, true, 'OK');
        return true;
    } catch (Throwable $e) {
        setupLog($label, false, $e->getMessage());
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name   = ?
          AND column_name  = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    runSQL(
        $pdo,
        "column {$table}.{$column}",
        "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}"
    );
}

/* ── Connection ──────────────────────────────────────────────────── */
function connectToDatabase(): PDO
{
    $pdoOptions = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $databaseUrl = getenv('DATABASE_URL') ?: '';

    if ($databaseUrl !== '' && preg_match('/^postgres(ql)?:\/\//', $databaseUrl)) {
        /* ── Render: parse DATABASE_URL ── */
        $p = parse_url($databaseUrl);
        if (!is_array($p) || empty($p['host']) || empty($p['path'])) {
            throw new RuntimeException('DATABASE_URL is set but could not be parsed.');
        }
        $host   = $p['host'];
        $port   = (int) ($p['port'] ?? 5432);
        $dbname = ltrim((string) $p['path'], '/');
        $user   = rawurldecode((string) ($p['user'] ?? ''));
        $pass   = rawurldecode((string) ($p['pass'] ?? ''));

        // Always require SSL on Render (both internal and external hosts)
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $ssl     = $isLocal ? '' : ';sslmode=require';
        $dsn     = "pgsql:host={$host};port={$port};dbname={$dbname}{$ssl}";

    } else {
        /* ── Local / individual env vars ──
         *
         * Fallback chain:
         *   1. DB_* vars (set in .env or system environment)
         *   2. PG* vars  (set by pgAdmin or psql tooling)
         *   3. Hard-coded local defaults
         *
         * Default password is 'Root' — the standard XAMPP/local
         * PostgreSQL install password used by this project.
         */
        $host   = getenv('DB_HOST')     ?: getenv('PGHOST')     ?: 'localhost';
        $port   = (int) (getenv('DB_PORT') ?: getenv('PGPORT') ?: 5432);
        $dbname = getenv('DB_NAME')     ?: getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
        $user   = getenv('DB_USER')     ?: getenv('PGUSER')     ?: getenv('DB_USERNAME') ?: 'postgres';
        $pass   = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: 'Root';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    }

    $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
    $pdo->exec("SET search_path TO public");
    $pdo->exec("SET TIME ZONE 'UTC'");
    return $pdo;
}

/* ── Attempt connection ──────────────────────────────────────────── */
$pdo        = null;
$connectDsn = ''; // shown in error output (no password)

try {
    $pdo = connectToDatabase();
    $dbName = $pdo->query('SELECT current_database()')->fetchColumn() ?: 'unknown';
    setupLog('database connection', true, "Connected to \"{$dbName}\"");
} catch (Throwable $e) {
    $msg = $e->getMessage();

    // Friendly local-specific messages
    if (str_contains($msg, 'Connection refused')) {
        $friendly = 'PostgreSQL is not running on port 5432. '
            . 'Start the service: run  net start postgresql-x64-16  in Command Prompt (as Administrator), '
            . 'or open Services (services.msc) and start the postgresql-x64-16 service manually.';
    } elseif (str_contains($msg, 'password authentication failed')) {
        $friendly = 'Wrong password. '
            . 'The local default is "Root". '
            . 'Set DB_PASSWORD=YourPassword in your environment if it is different.';
    } elseif (str_contains($msg, 'does not exist')) {
        $friendly = 'Database "Inventory_DB" does not exist. '
            . 'Create it first: open Command Prompt and run  '
            . 'psql -U postgres -c "CREATE DATABASE \\"Inventory_DB\\";"  '
            . '(enter your PostgreSQL password when prompted).';
    } elseif (str_contains($msg, 'SSL')) {
        $friendly = 'SSL error. On Render make sure DATABASE_URL includes ?sslmode=require.';
    } else {
        $friendly = $msg;
    }

    setupLog('database connection', false, $friendly);
}

/* ══════════════════════════════════════════════════════════════════
   SCHEMA — only runs when connected
══════════════════════════════════════════════════════════════════ */
if ($pdo instanceof PDO) {

    /* ── users ── */
    runSQL($pdo, 'users table', "
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL DEFAULT '',
            phone VARCHAR(20),
            customer_group VARCHAR(50) DEFAULT 'regular',
            role VARCHAR(20) DEFAULT 'customer',
            is_active BOOLEAN DEFAULT TRUE,
            is_verified BOOLEAN DEFAULT FALSE,
            account_status VARCHAR(20) DEFAULT 'pending',
            verification_code VARCHAR(255),
            verification_code_expires_at TIMESTAMP,
            verification_attempts INTEGER DEFAULT 0,
            verification_failed_attempts INTEGER DEFAULT 0,
            verification_locked_until TIMESTAMP,
            verification_resend_count INTEGER DEFAULT 0,
            resend_count INTEGER DEFAULT 0,
            last_verification_sent_at TIMESTAMP,
            code_expiry TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'phone'                       => 'VARCHAR(20)',
        'customer_group'              => "VARCHAR(50) DEFAULT 'regular'",
        'role'                        => "VARCHAR(20) DEFAULT 'customer'",
        'is_active'                   => 'BOOLEAN DEFAULT TRUE',
        'is_verified'                 => 'BOOLEAN DEFAULT FALSE',
        'account_status'              => "VARCHAR(20) DEFAULT 'pending'",
        'verification_code'           => 'VARCHAR(255)',
        'verification_code_expires_at'=> 'TIMESTAMP',
        'verification_attempts'       => 'INTEGER DEFAULT 0',
        'verification_failed_attempts'=> 'INTEGER DEFAULT 0',
        'verification_locked_until'   => 'TIMESTAMP',
        'verification_resend_count'   => 'INTEGER DEFAULT 0',
        'resend_count'                => 'INTEGER DEFAULT 0',
        'last_verification_sent_at'   => 'TIMESTAMP',
        'code_expiry'                 => 'TIMESTAMP',
        'created_at'                  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'                  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'users', $col, $def); }

    runSQL($pdo, 'users defaults', "
        UPDATE users SET
            full_name              = COALESCE(NULLIF(full_name,''), username),
            customer_group         = COALESCE(NULLIF(customer_group,''), 'regular'),
            role                   = COALESCE(NULLIF(role,''), 'customer'),
            is_active              = COALESCE(is_active, TRUE),
            is_verified            = COALESCE(is_verified, FALSE),
            account_status         = COALESCE(NULLIF(account_status,''),
                                       CASE WHEN COALESCE(is_verified,FALSE) THEN 'active' ELSE 'pending' END),
            verification_attempts          = COALESCE(verification_attempts, 0),
            verification_failed_attempts   = COALESCE(verification_failed_attempts, 0),
            verification_resend_count      = COALESCE(verification_resend_count, 0),
            resend_count                   = COALESCE(resend_count, 0),
            created_at             = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at             = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");
    runSQL($pdo, 'users role constraint', "
        ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
        ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('admin','manager','staff','customer','supplier'))
    ");
    runSQL($pdo, 'users status constraint', "
        ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_status_check;
        ALTER TABLE users ADD CONSTRAINT users_account_status_check
            CHECK (account_status IN ('pending','active','suspended'))
    ");

    /* ── app_settings ── */
    runSQL($pdo, 'app_settings table', "
        CREATE TABLE IF NOT EXISTS app_settings (
            id SERIAL PRIMARY KEY,
            setting_key   VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT DEFAULT '',
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'setting_value' => "TEXT DEFAULT ''",
        'created_at'    => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'    => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'app_settings', $col, $def); }

    /* ── categories ── */
    runSQL($pdo, 'categories table', "
        CREATE TABLE IF NOT EXISTS categories (
            id          SERIAL PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            description TEXT,
            parent_id   INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            is_active   BOOLEAN DEFAULT TRUE,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'description' => 'TEXT',
        'parent_id'   => 'INTEGER',
        'is_active'   => 'BOOLEAN DEFAULT TRUE',
        'created_at'  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'categories', $col, $def); }

    /* ── suppliers ── */
    runSQL($pdo, 'suppliers table', "
        CREATE TABLE IF NOT EXISTS suppliers (
            id             SERIAL PRIMARY KEY,
            name           VARCHAR(200),
            company_name   VARCHAR(200) NOT NULL DEFAULT 'Unnamed Supplier',
            contact_person VARCHAR(100),
            email          VARCHAR(100),
            phone          VARCHAR(20),
            address        TEXT,
            city           VARCHAR(100),
            country        VARCHAR(100),
            postal_code    VARCHAR(30),
            is_active      BOOLEAN DEFAULT TRUE,
            notes          TEXT,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'name'           => 'VARCHAR(200)',
        'contact_person' => 'VARCHAR(100)',
        'email'          => 'VARCHAR(100)',
        'phone'          => 'VARCHAR(20)',
        'address'        => 'TEXT',
        'city'           => 'VARCHAR(100)',
        'country'        => 'VARCHAR(100)',
        'postal_code'    => 'VARCHAR(30)',
        'is_active'      => 'BOOLEAN DEFAULT TRUE',
        'notes'          => 'TEXT',
        'created_at'     => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'     => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'suppliers', $col, $def); }

    runSQL($pdo, 'supplier defaults', "
        UPDATE suppliers SET
            company_name = COALESCE(NULLIF(company_name,''), NULLIF(name,''), 'Unnamed Supplier'),
            name         = COALESCE(NULLIF(name,''), NULLIF(company_name,''), 'Unnamed Supplier'),
            is_active    = COALESCE(is_active, TRUE),
            created_at   = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at   = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    /* ── products ── */
    runSQL($pdo, 'products table', "
        CREATE TABLE IF NOT EXISTS products (
            id             SERIAL PRIMARY KEY,
            sku            VARCHAR(50) UNIQUE NOT NULL,
            barcode        VARCHAR(50),
            name           VARCHAR(200) NOT NULL,
            description    TEXT,
            category_id    INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            supplier_id    INTEGER REFERENCES suppliers(id)  ON DELETE SET NULL,
            category       VARCHAR(100),
            unit_price     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price          DECIMAL(12,2) DEFAULT 0.00,
            quantity       INTEGER NOT NULL DEFAULT 0,
            stock_quantity INTEGER DEFAULT 0,
            reorder_level  INTEGER NOT NULL DEFAULT 10,
            status         VARCHAR(20) DEFAULT 'active',
            is_active      BOOLEAN DEFAULT TRUE,
            image_path     VARCHAR(500),
            image_url      VARCHAR(500),
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'barcode'        => 'VARCHAR(50)',
        'description'    => 'TEXT',
        'category_id'    => 'INTEGER',
        'supplier_id'    => 'INTEGER',
        'category'       => 'VARCHAR(100)',
        'cost_price'     => 'DECIMAL(12,2) DEFAULT 0.00',
        'price'          => 'DECIMAL(12,2) DEFAULT 0.00',
        'stock_quantity' => 'INTEGER DEFAULT 0',
        'status'         => "VARCHAR(20) DEFAULT 'active'",
        'is_active'      => 'BOOLEAN DEFAULT TRUE',
        'image_path'     => 'VARCHAR(500)',
        'image_url'      => 'VARCHAR(500)',
        'created_at'     => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'     => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'products', $col, $def); }

    runSQL($pdo, 'product defaults', "
        UPDATE products SET
            unit_price     = COALESCE(NULLIF(unit_price,0), price, 0),
            price          = COALESCE(NULLIF(price,0), unit_price, 0),
            quantity       = COALESCE(quantity, stock_quantity, 0),
            stock_quantity = COALESCE(stock_quantity, quantity, 0),
            reorder_level  = COALESCE(reorder_level, 10),
            is_active      = COALESCE(is_active, TRUE),
            status         = COALESCE(NULLIF(status,''),
                               CASE WHEN COALESCE(is_active,TRUE) THEN 'active' ELSE 'inactive' END),
            created_at     = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at     = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    /* ── orders ── */
    runSQL($pdo, 'orders table', "
        CREATE TABLE IF NOT EXISTS orders (
            id                  SERIAL PRIMARY KEY,
            user_id             INTEGER REFERENCES users(id) ON DELETE SET NULL,
            order_number        VARCHAR(50),
            customer_name       VARCHAR(100),
            customer_email      VARCHAR(100),
            customer_phone      VARCHAR(30),
            status              VARCHAR(20) DEFAULT 'pending',
            payment_status      VARCHAR(20) DEFAULT 'pending',
            payment_method      VARCHAR(50),
            transaction_id      VARCHAR(100),
            mpesa_receipt_number VARCHAR(100),
            total_amount        DECIMAL(12,2) DEFAULT 0.00,
            subtotal            DECIMAL(12,2) DEFAULT 0.00,
            tax_amount          DECIMAL(12,2) DEFAULT 0.00,
            shipping_address    TEXT,
            billing_address     TEXT,
            notes               TEXT,
            order_date          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'user_id'              => 'INTEGER',
        'order_number'         => 'VARCHAR(50)',
        'customer_name'        => 'VARCHAR(100)',
        'customer_email'       => 'VARCHAR(100)',
        'customer_phone'       => 'VARCHAR(30)',
        'payment_method'       => 'VARCHAR(50)',
        'transaction_id'       => 'VARCHAR(100)',
        'mpesa_receipt_number' => 'VARCHAR(100)',
        'subtotal'             => 'DECIMAL(12,2) DEFAULT 0.00',
        'tax_amount'           => 'DECIMAL(12,2) DEFAULT 0.00',
        'shipping_address'     => 'TEXT',
        'billing_address'      => 'TEXT',
        'notes'                => 'TEXT',
        'order_date'           => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'created_at'           => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'           => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'orders', $col, $def); }

    runSQL($pdo, 'order defaults', "
        UPDATE orders SET
            status         = COALESCE(NULLIF(status,''), 'pending'),
            payment_status = COALESCE(NULLIF(payment_status,''), 'pending'),
            total_amount   = COALESCE(total_amount, 0),
            subtotal       = COALESCE(subtotal, total_amount, 0),
            tax_amount     = COALESCE(tax_amount, 0),
            order_date     = COALESCE(order_date, created_at, CURRENT_TIMESTAMP),
            created_at     = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at     = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    /* ── order_items ── */
    runSQL($pdo, 'order_items table', "
        CREATE TABLE IF NOT EXISTS order_items (
            id           SERIAL PRIMARY KEY,
            order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id   INTEGER REFERENCES products(id) ON DELETE SET NULL,
            product_name VARCHAR(200),
            quantity     INTEGER NOT NULL DEFAULT 1,
            unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price        DECIMAL(12,2) DEFAULT 0.00,
            subtotal     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'product_id'   => 'INTEGER',
        'product_name' => 'VARCHAR(200)',
        'price'        => 'DECIMAL(12,2) DEFAULT 0.00',
        'created_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'order_items', $col, $def); }

    /* ── payments ── */
    runSQL($pdo, 'payments table', "
        CREATE TABLE IF NOT EXISTS payments (
            id                  SERIAL PRIMARY KEY,
            order_id            INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            payment_method      VARCHAR(50),
            payment_gateway     VARCHAR(50),
            amount              DECIMAL(12,2) DEFAULT 0.00,
            status              VARCHAR(20) DEFAULT 'pending',
            transaction_id      VARCHAR(100),
            checkout_request_id VARCHAR(100),
            reference_number    VARCHAR(100),
            gateway_response    TEXT,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'payment_method'      => 'VARCHAR(50)',
        'payment_gateway'     => 'VARCHAR(50)',
        'checkout_request_id' => 'VARCHAR(100)',
        'reference_number'    => 'VARCHAR(100)',
        'gateway_response'    => 'TEXT',
        'created_at'          => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'          => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'payments', $col, $def); }

    /* ── payment_transactions ── */
    runSQL($pdo, 'payment_transactions table', "
        CREATE TABLE IF NOT EXISTS payment_transactions (
            id                  SERIAL PRIMARY KEY,
            order_id            INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            transaction_id      VARCHAR(100),
            payment_gateway     VARCHAR(50) DEFAULT 'manual',
            payment_method      VARCHAR(50),
            amount              DECIMAL(12,2) DEFAULT 0.00,
            status              VARCHAR(20) DEFAULT 'pending',
            reference_number    VARCHAR(100),
            checkout_request_id VARCHAR(100),
            gateway_response    TEXT,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'payment_method'      => 'VARCHAR(50)',
        'reference_number'    => 'VARCHAR(100)',
        'checkout_request_id' => 'VARCHAR(100)',
        'gateway_response'    => 'TEXT',
        'created_at'          => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'          => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'payment_transactions', $col, $def); }

    /* ── stock_logs ── */
    runSQL($pdo, 'stock_logs table', "
        CREATE TABLE IF NOT EXISTS stock_logs (
            id               SERIAL PRIMARY KEY,
            product_id       INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
            user_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action           VARCHAR(20) NOT NULL,
            quantity_before  INTEGER NOT NULL DEFAULT 0,
            quantity_after   INTEGER NOT NULL DEFAULT 0,
            quantity_changed INTEGER NOT NULL DEFAULT 0,
            reference_number VARCHAR(50),
            notes            TEXT,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'reference_number' => 'VARCHAR(50)',
        'notes'            => 'TEXT',
        'created_at'       => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'       => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'stock_logs', $col, $def); }

    /* ── mpesa_transactions ── */
    runSQL($pdo, 'mpesa_transactions table', "
        CREATE TABLE IF NOT EXISTS mpesa_transactions (
            id                   SERIAL PRIMARY KEY,
            order_id             INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            checkout_request_id  VARCHAR(100),
            merchant_request_id  VARCHAR(100),
            phone_number         VARCHAR(30),
            amount               DECIMAL(12,2),
            result_code          VARCHAR(20),
            result_desc          TEXT,
            mpesa_receipt_number VARCHAR(100),
            transaction_date     TIMESTAMP,
            status               VARCHAR(20) DEFAULT 'pending',
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'merchant_request_id'  => 'VARCHAR(100)',
        'result_code'          => 'VARCHAR(20)',
        'result_desc'          => 'TEXT',
        'mpesa_receipt_number' => 'VARCHAR(100)',
        'transaction_date'     => 'TIMESTAMP',
        'created_at'           => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at'           => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'mpesa_transactions', $col, $def); }

    /* ── audit_logs ── */
    runSQL($pdo, 'audit_logs table', "
        CREATE TABLE IF NOT EXISTS audit_logs (
            id         SERIAL PRIMARY KEY,
            user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action     VARCHAR(100),
            table_name VARCHAR(80),
            record_id  INTEGER,
            details    TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    foreach ([
        'action'     => 'VARCHAR(100)',
        'table_name' => 'VARCHAR(80)',
        'record_id'  => 'INTEGER',
        'details'    => 'TEXT',
        'ip_address' => 'VARCHAR(45)',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $col => $def) { addColumn($pdo, 'audit_logs', $col, $def); }

    /* ── admin_users ── */
    runSQL($pdo, 'admin_users table', "
        CREATE TABLE IF NOT EXISTS admin_users (
            id            SERIAL PRIMARY KEY,
            username      VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email         VARCHAR(100) UNIQUE NOT NULL,
            role          VARCHAR(20) DEFAULT 'admin',
            last_login    TIMESTAMP,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    /* ── updated_at trigger function ── */
    runSQL($pdo, 'updated_at function', "
        CREATE OR REPLACE FUNCTION update_updated_at_column()
        RETURNS TRIGGER AS \$\$
        BEGIN
            NEW.updated_at = CURRENT_TIMESTAMP;
            RETURN NEW;
        END;
        \$\$ LANGUAGE plpgsql
    ");

    foreach ([
        'users','app_settings','categories','suppliers','products',
        'orders','order_items','payments','payment_transactions',
        'stock_logs','mpesa_transactions','admin_users',
    ] as $table) {
        if (columnExists($pdo, $table, 'updated_at')) {
            runSQL($pdo, "trigger {$table}", "
                DROP TRIGGER IF EXISTS update_{$table}_updated_at ON {$table};
                CREATE TRIGGER update_{$table}_updated_at
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()
            ");
        }
    }

    /* ── Indexes ── */
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_users_username          ON users(username)',
        'CREATE INDEX IF NOT EXISTS idx_users_email             ON users(email)',
        'CREATE INDEX IF NOT EXISTS idx_users_role              ON users(role)',
        'CREATE INDEX IF NOT EXISTS idx_categories_name         ON categories(name)',
        'CREATE INDEX IF NOT EXISTS idx_products_sku            ON products(sku)',
        'CREATE INDEX IF NOT EXISTS idx_products_category_id    ON products(category_id)',
        'CREATE INDEX IF NOT EXISTS idx_products_supplier_id    ON products(supplier_id)',
        'CREATE INDEX IF NOT EXISTS idx_products_quantity       ON products(quantity)',
        'CREATE INDEX IF NOT EXISTS idx_orders_user_id          ON orders(user_id)',
        'CREATE INDEX IF NOT EXISTS idx_orders_status           ON orders(status)',
        'CREATE INDEX IF NOT EXISTS idx_orders_payment_status   ON orders(payment_status)',
        'CREATE INDEX IF NOT EXISTS idx_order_items_order_id    ON order_items(order_id)',
        'CREATE INDEX IF NOT EXISTS idx_payment_txn_order_id    ON payment_transactions(order_id)',
        'CREATE INDEX IF NOT EXISTS idx_payment_txn_checkout    ON payment_transactions(checkout_request_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_logs_product_id   ON stock_logs(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_mpesa_checkout          ON mpesa_transactions(checkout_request_id)',
    ] as $sql) { runSQL($pdo, 'index', $sql); }

    /* ── Admin user ── */
    $adminUsername = getenv('ADMIN_USERNAME') ?: 'admin';
    $adminEmail    = getenv('ADMIN_EMAIL')    ?: 'admin@inventorysystem.com';
    $adminName     = getenv('ADMIN_NAME')     ?: 'System Administrator';
    $adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin123';
    $adminHash     = password_hash($adminPassword, PASSWORD_DEFAULT);

    try {
        $pdo->prepare("
            INSERT INTO users
                (username, password, email, full_name, role,
                 is_active, is_verified, account_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'admin', TRUE, TRUE, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (username) DO UPDATE SET
                password       = EXCLUDED.password,
                email          = EXCLUDED.email,
                full_name      = EXCLUDED.full_name,
                role           = 'admin',
                is_active      = TRUE,
                is_verified    = TRUE,
                account_status = 'active',
                updated_at     = CURRENT_TIMESTAMP
        ")->execute([$adminUsername, $adminHash, $adminEmail, $adminName]);
        setupLog('admin user', true, "Login: {$adminUsername} / {$adminPassword}");
    } catch (Throwable $e) {
        // Username conflict with different email — try update by email
        try {
            $affected = $pdo->prepare("
                UPDATE users SET
                    password = ?, full_name = COALESCE(NULLIF(full_name,''), ?),
                    role = 'admin', is_active = TRUE, is_verified = TRUE,
                    account_status = 'active', updated_at = CURRENT_TIMESTAMP
                WHERE LOWER(email) = LOWER(?)
            ");
            $affected->execute([$adminHash, $adminName, $adminEmail]);
            if ($affected->rowCount() > 0) {
                setupLog('admin user', true, "Updated by email. Login: {$adminEmail} / {$adminPassword}");
            } else {
                setupLog('admin user', false, $e->getMessage());
            }
        } catch (Throwable $e2) {
            setupLog('admin user', false, $e->getMessage());
        }
    }

    try {
        $pdo->prepare("
            INSERT INTO admin_users (username, password_hash, email, role, created_at, updated_at)
            VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (username) DO UPDATE SET
                password_hash = EXCLUDED.password_hash,
                email         = EXCLUDED.email,
                role          = 'admin',
                updated_at    = CURRENT_TIMESTAMP
        ")->execute([$adminUsername, $adminHash, $adminEmail]);
        setupLog('admin_users mirror', true, 'OK');
    } catch (Throwable $e) {
        setupLog('admin_users mirror', false, $e->getMessage());
    }

    /* ── Seed data ── */
    runSQL($pdo, 'seed categories', "
        INSERT INTO categories (name, description, is_active)
        SELECT v.name, v.description, TRUE
        FROM (VALUES
            ('Electronics',    'Electronic devices and accessories'),
            ('Computers',      'Laptops and accessories'),
            ('Mobile Devices', 'Smartphones and tablets'),
            ('Office Supplies','General office supplies'),
            ('Furniture',      'Office and home furniture'),
            ('Clothing',       'Apparel and fashion'),
            ('General',        'Default category')
        ) AS v(name, description)
        WHERE NOT EXISTS (
            SELECT 1 FROM categories c WHERE LOWER(c.name) = LOWER(v.name)
        )
    ");

    runSQL($pdo, 'seed supplier', "
        INSERT INTO suppliers (name, company_name, contact_person, email, phone, city, is_active)
        SELECT 'Default Supplier','Default Supplier','Inventory Team',
               'supplier@inventorysystem.com','','',TRUE
        WHERE NOT EXISTS (
            SELECT 1 FROM suppliers WHERE LOWER(company_name) = 'default supplier'
        )
    ");

    runSQL($pdo, 'seed products', "
        INSERT INTO products (
            sku, barcode, name, description, category_id, supplier_id,
            category, unit_price, cost_price, price,
            quantity, stock_quantity, reorder_level, is_active, status
        )
        SELECT v.sku, v.barcode, v.name, v.description,
               c.id, s.id, v.cat,
               v.price::DECIMAL, v.cost::DECIMAL, v.price::DECIMAL,
               v.qty::INTEGER, v.qty::INTEGER, v.reorder::INTEGER,
               TRUE, 'active'
        FROM (VALUES
            ('ELEC001','1234567890123','Wireless Mouse',  'Ergonomic wireless mouse',  'Electronics',    '25.99','15.00','100','20'),
            ('ELEC002','1234567890124','USB Keyboard',    'Mechanical RGB keyboard',   'Electronics',    '45.99','28.00','75', '15'),
            ('COMP001','1234567890125','Laptop Stand',    'Adjustable aluminum stand', 'Computers',      '35.99','20.00','50', '10'),
            ('MOBI001','1234567890127','Phone Charger',   'Fast charging USB-C 20W',   'Mobile Devices', '19.99','10.00','200','50'),
            ('OFFI001','1234567890128','Ballpoint Pens',  'Box of 50 blue pens',       'Office Supplies','12.99','6.00', '150','30'),
            ('FURN001','1234567890129','Office Chair',    'Ergonomic lumbar support',  'Furniture',      '149.99','90.00','25','5'),
            ('CLTH001','1234567890130','T-Shirt Cotton',  '100% cotton black medium',  'Clothing',       '15.99','8.00', '100','25')
        ) AS v(sku, barcode, name, description, cat, price, cost, qty, reorder)
        LEFT JOIN categories c ON LOWER(c.name) = LOWER(v.cat)
        LEFT JOIN suppliers  s ON LOWER(s.company_name) = 'default supplier'
        WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.sku = v.sku)
    ");

    runSQL($pdo, 'seed settings', "
        INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
        SELECT v.k, v.val, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM (VALUES
            ('store_name',                 'Inventory System'),
            ('store_email',                'admin@inventorysystem.com'),
            ('currency',                   'KES'),
            ('timezone',                   'Africa/Nairobi'),
            ('low_stock_threshold',        '5'),
            ('date_format',                'Y-m-d'),
            ('notify_new_orders',          '1'),
            ('notify_low_stock',           '1'),
            ('notify_daily_sales_report',  '0'),
            ('notify_weekly_summary',      '0'),
            ('notify_order_status_changes','1'),
            ('notify_inventory_updates',   '1'),
            ('notify_user_activity',       '1')
        ) AS v(k, val)
        WHERE NOT EXISTS (
            SELECT 1 FROM app_settings s WHERE s.setting_key = v.k
        )
    ");

} // end if ($pdo instanceof PDO)

/* ══════════════════════════════════════════════════════════════════
   STATUS CHECK
══════════════════════════════════════════════════════════════════ */
$requiredTables = [
    'users','app_settings','categories','suppliers','products',
    'orders','order_items','payments','payment_transactions',
    'stock_logs','mpesa_transactions','audit_logs','admin_users',
];

$tableStatus = [];
if ($pdo instanceof PDO) {
    foreach ($requiredTables as $t) {
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            $tableStatus[$t] = ['ok' => true, 'rows' => $n];
        } catch (Throwable $e) {
            $tableStatus[$t] = ['ok' => false, 'rows' => 0, 'message' => $e->getMessage()];
        }
    }
}

$requiredColumns = [
    'products' => ['id','sku','name','category_id','supplier_id','unit_price','cost_price','quantity','reorder_level','is_active','image_path'],
    'orders'   => ['id','user_id','order_number','status','payment_status','payment_method','transaction_id','subtotal','tax_amount','total_amount'],
    'users'    => ['id','username','password','email','full_name','role','is_active','is_verified','account_status'],
    'mpesa_transactions' => ['id','order_id','checkout_request_id','merchant_request_id','phone_number','amount','result_code','result_desc','mpesa_receipt_number','transaction_date','status'],
];

$columnStatus = [];
if ($pdo instanceof PDO) {
    foreach ($requiredColumns as $t => $cols) {
        foreach ($cols as $c) {
            $columnStatus["{$t}.{$c}"] = columnExists($pdo, $t, $c);
        }
    }
}

$failedOps     = array_filter($results,     fn($r) => !$r['ok']);
$missingTables = array_filter($tableStatus, fn($r) => !$r['ok']);
$missingCols   = array_filter($columnStatus, fn($ok) => !$ok);
$allOk         = $pdo instanceof PDO && !$failedOps && !$missingTables && !$missingCols;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Setup — <?= htmlspecialchars($envLabel) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 28px; background: #101318; color: #e8edf2; font-family: Consolas, Monaco, monospace; line-height: 1.5; }
        main { max-width: 1120px; margin: 0 auto; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .env-badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 700; margin-bottom: 16px;
            background: <?= $isRender ? '#0e2a1a' : '#0e1e2a' ?>; color: <?= $isRender ? '#4ddb8a' : '#4db8ff' ?>;
            border: 1px solid <?= $isRender ? '#2c7a52' : '#2c6a9b' ?>; }
        h2 { margin: 0 0 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .1em; color: #9fb0c2; }
        .summary { padding: 16px 20px; border-radius: 8px; margin-bottom: 16px; border: 1px solid; font-weight: 700; font-size: 14px; }
        .summary.pass { color: #9ff3bd; background: #0d1f14; border-color: #2c9b58; }
        .summary.fail { color: #ffc1c1; background: #200f0f; border-color: #b84848; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; margin-bottom: 14px; }
        .panel { background: #171c23; border: 1px solid #28313c; border-radius: 8px; padding: 14px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        td, th { padding: 6px 5px; border-bottom: 1px solid #1e2730; text-align: left; vertical-align: top; }
        th { color: #7a8fa4; font-weight: 700; font-size: 11px; text-transform: uppercase; }
        .ok  { color: #6ee89a; font-weight: 700; }
        .bad { color: #ff8f8f; font-weight: 700; }
        .muted { color: #7a8fa4; }
        .msg { max-width: 520px; word-break: break-word; }
        .warning { margin-top: 16px; padding: 14px 16px; border-radius: 8px; background: #201a0c; border: 1px solid #9a6820; color: #ffc86a; font-size: 13px; }
        .steps { margin-top: 14px; padding: 14px 16px; border-radius: 8px; background: #0d1820; border: 1px solid #1e4060; color: #a8d4f5; font-size: 13px; line-height: 1.8; }
        .steps strong { color: #e8edf2; }
        code { display: inline-block; padding: 1px 6px; border-radius: 3px; background: #1e2830; color: #f0d060; font-size: 12px; }
        a { color: #7ab8ff; }
    </style>
</head>
<body>
<main>
    <h1>Inventory Database Setup</h1>
    <div class="env-badge">Environment: <?= htmlspecialchars($envLabel) ?></div>

    <div class="summary <?= $allOk ? 'pass' : 'fail' ?>">
        <?php if ($allOk): ?>
            ✓ PASS — All tables, columns, and seed data are in place.
        <?php elseif (!$pdo): ?>
            ✗ CONNECTION FAILED — Database is not reachable. See the error below.
        <?php else: ?>
            ✗ CHECK NEEDED — One or more operations failed. See rows marked FAIL below.
        <?php endif; ?>
    </div>

    <?php if (!$pdo): ?>
        <?php $connResult = array_filter($results, fn($r) => $r['label'] === 'database connection'); ?>
        <?php $connResult = reset($connResult); ?>
        <?php if ($connResult): ?>
        <div class="panel" style="margin-bottom:14px;">
            <h2>Connection Error</h2>
            <p style="color:#ff8f8f;margin:0 0 10px;"><?= nl2br(htmlspecialchars($connResult['message'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!$isRender): ?>
        <div class="steps">
            <strong>Local fix steps:</strong><br>
            1. Open Command Prompt as Administrator and run:<br>
            &nbsp;&nbsp;<code>net start postgresql-x64-16</code><br>
            &nbsp;&nbsp;(replace 16 with your version if different)<br><br>
            2. If PostgreSQL is not installed, download it from
               <a href="https://www.postgresql.org/download/windows/" target="_blank">postgresql.org/download/windows</a>
               and install with password <code>Root</code>.<br><br>
            3. Create the database (run once in Command Prompt):<br>
            &nbsp;&nbsp;<code>psql -U postgres -c "CREATE DATABASE \"Inventory_DB\";"</code><br><br>
            4. Refresh this page — setup will run automatically once PostgreSQL is running.
        </div>
        <?php else: ?>
        <div class="steps">
            <strong>Render fix steps:</strong><br>
            1. Go to your Render dashboard → your Web Service → Environment.<br>
            2. Confirm <code>DATABASE_URL</code> is set (it's set automatically when you link a PostgreSQL service).<br>
            3. If missing, go to your PostgreSQL service → Connect → copy the Internal Database URL → add it as <code>DATABASE_URL</code>.<br>
            4. Redeploy the web service, then visit this page again.
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($pdo): ?>
    <div class="grid">
        <section class="panel">
            <h2>Required Tables</h2>
            <table>
                <thead><tr><th>Status</th><th>Table</th><th>Rows</th></tr></thead>
                <tbody>
                <?php foreach ($requiredTables as $t): ?>
                    <?php $s = $tableStatus[$t] ?? ['ok'=>false,'rows'=>0,'message'=>'Not checked']; ?>
                    <tr>
                        <td class="<?= $s['ok'] ? 'ok' : 'bad' ?>"><?= $s['ok'] ? 'OK' : 'FAIL' ?></td>
                        <td><?= htmlspecialchars($t) ?></td>
                        <td class="<?= $s['ok'] ? '' : 'bad msg' ?>"><?= $s['ok'] ? $s['rows'] : htmlspecialchars($s['message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Important Columns</h2>
            <table>
                <thead><tr><th>Status</th><th>Column</th></tr></thead>
                <tbody>
                <?php foreach ($columnStatus as $col => $ok): ?>
                    <tr>
                        <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'FAIL' ?></td>
                        <td><?= htmlspecialchars($col) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="panel">
        <h2>Operations Log</h2>
        <table>
            <thead><tr><th>Status</th><th>Operation</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td class="<?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? 'OK' : 'FAIL' ?></td>
                    <td><?= htmlspecialchars($r['label']) ?></td>
                    <td class="msg <?= $r['ok'] ? 'muted' : 'bad' ?>"><?= htmlspecialchars($r['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($allOk): ?>
    <section class="panel" style="margin-top:14px;">
        <h2>Next Steps</h2>
        <p style="margin:0 0 8px;">
            Admin login: <code><?= htmlspecialchars(getenv('ADMIN_USERNAME') ?: 'admin') ?></code>
            / <code><?= htmlspecialchars(getenv('ADMIN_PASSWORD') ?: 'admin123') ?></code>
        </p>
        <p style="margin:0 0 6px;"><a href="admin.php">→ Open Admin Panel</a></p>
        <p style="margin:0;"><a href="login.php">→ Go to Login</a></p>
    </section>
    <?php endif; ?>

    <div class="warning">
        ⚠ Delete <code>run_setup.php</code> from GitHub immediately after this page shows PASS, then redeploy.
        Leaving this file online is a security risk — it can reset the admin password.
    </div>
</main>
</body>
</html>
