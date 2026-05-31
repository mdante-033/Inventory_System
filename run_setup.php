<?php
/**
 * One-time Render database installer for Inventory_System.
 *
 * Push this file, visit /run_setup.php once on Render, confirm every required
 * table is OK, then delete this file from GitHub and redeploy.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$results = [];

function setupLog(string $label, bool $ok, string $message = ''): void
{
    global $results;
    $results[] = [
        'label' => $label,
        'ok' => $ok,
        'message' => $message,
    ];
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
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    runSQL($pdo, "column {$table}.{$column}", "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}");
}

function connectToDatabase(): PDO
{
    $databaseUrl = getenv('DATABASE_URL') ?: '';

    if ($databaseUrl !== '' && preg_match('/^postgres(ql)?:\/\//', $databaseUrl)) {
        $parts = parse_url($databaseUrl);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL is set, but it could not be parsed.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 5432;
        $dbname = ltrim((string) $parts['path'], '/');
        $user = urldecode((string) ($parts['user'] ?? ''));
        $pass = urldecode((string) ($parts['pass'] ?? ''));
        $ssl = str_contains($host, 'render.com') ? ';sslmode=require' : '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$ssl}";
    } else {
        $host = getenv('DB_HOST') ?: getenv('PGHOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: getenv('PGPORT') ?: '5432';
        $dbname = getenv('DB_NAME') ?: getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
        $user = getenv('DB_USER') ?: getenv('PGUSER') ?: getenv('DB_USERNAME') ?: 'postgres';
        $pass = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: '';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET search_path TO public");
    $pdo->exec("SET TIME ZONE 'UTC'");

    return $pdo;
}

try {
    $pdo = connectToDatabase();
    setupLog('database connection', true, 'Connected to ' . ($pdo->query('SELECT current_database()')->fetchColumn() ?: 'database'));
} catch (Throwable $e) {
    setupLog('database connection', false, $e->getMessage());
    $pdo = null;
}

if ($pdo instanceof PDO) {
    runSQL($pdo, 'users table', "
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
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
        'password' => 'VARCHAR(255)',
        'email' => 'VARCHAR(100)',
        'full_name' => 'VARCHAR(100)',
        'phone' => 'VARCHAR(20)',
        'customer_group' => "VARCHAR(50) DEFAULT 'regular'",
        'role' => "VARCHAR(20) DEFAULT 'customer'",
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'is_verified' => 'BOOLEAN DEFAULT FALSE',
        'account_status' => "VARCHAR(20) DEFAULT 'pending'",
        'verification_code' => 'VARCHAR(255)',
        'verification_code_expires_at' => 'TIMESTAMP',
        'verification_attempts' => 'INTEGER DEFAULT 0',
        'verification_failed_attempts' => 'INTEGER DEFAULT 0',
        'verification_locked_until' => 'TIMESTAMP',
        'verification_resend_count' => 'INTEGER DEFAULT 0',
        'resend_count' => 'INTEGER DEFAULT 0',
        'last_verification_sent_at' => 'TIMESTAMP',
        'code_expiry' => 'TIMESTAMP',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'users', $column, $definition);
    }

    runSQL($pdo, 'users defaults', "
        UPDATE users
        SET full_name = COALESCE(NULLIF(full_name, ''), username),
            customer_group = COALESCE(NULLIF(customer_group, ''), 'regular'),
            role = COALESCE(NULLIF(role, ''), 'customer'),
            is_active = COALESCE(is_active, TRUE),
            is_verified = COALESCE(is_verified, FALSE),
            account_status = COALESCE(NULLIF(account_status, ''), CASE WHEN COALESCE(is_verified, FALSE) THEN 'active' ELSE 'pending' END),
            verification_attempts = COALESCE(verification_attempts, 0),
            verification_failed_attempts = COALESCE(verification_failed_attempts, 0),
            verification_resend_count = COALESCE(verification_resend_count, 0),
            resend_count = COALESCE(resend_count, 0),
            created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    runSQL($pdo, 'users role constraint', "
        ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
        ALTER TABLE users ADD CONSTRAINT users_role_check
        CHECK (role IN ('admin', 'manager', 'staff', 'customer', 'supplier'))
    ");

    runSQL($pdo, 'users status constraint', "
        ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_status_check;
        ALTER TABLE users ADD CONSTRAINT users_account_status_check
        CHECK (account_status IN ('pending', 'active', 'suspended'))
    ");

    runSQL($pdo, 'app_settings table', "
        CREATE TABLE IF NOT EXISTS app_settings (
            id SERIAL PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'setting_key' => 'VARCHAR(100)',
        'setting_value' => "TEXT DEFAULT ''",
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'app_settings', $column, $definition);
    }

    runSQL($pdo, 'categories table', "
        CREATE TABLE IF NOT EXISTS categories (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            parent_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'name' => 'VARCHAR(100)',
        'description' => 'TEXT',
        'parent_id' => 'INTEGER',
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'categories', $column, $definition);
    }

    runSQL($pdo, 'suppliers table', "
        CREATE TABLE IF NOT EXISTS suppliers (
            id SERIAL PRIMARY KEY,
            name VARCHAR(200),
            company_name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(100),
            country VARCHAR(100),
            postal_code VARCHAR(30),
            is_active BOOLEAN DEFAULT TRUE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'name' => 'VARCHAR(200)',
        'company_name' => 'VARCHAR(200)',
        'contact_person' => 'VARCHAR(100)',
        'email' => 'VARCHAR(100)',
        'phone' => 'VARCHAR(20)',
        'address' => 'TEXT',
        'city' => 'VARCHAR(100)',
        'country' => 'VARCHAR(100)',
        'postal_code' => 'VARCHAR(30)',
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'notes' => 'TEXT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'suppliers', $column, $definition);
    }

    runSQL($pdo, 'supplier defaults', "
        UPDATE suppliers
        SET company_name = COALESCE(NULLIF(company_name, ''), NULLIF(name, ''), 'Unnamed Supplier'),
            name = COALESCE(NULLIF(name, ''), NULLIF(company_name, ''), 'Unnamed Supplier'),
            is_active = COALESCE(is_active, TRUE),
            created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    runSQL($pdo, 'products table', "
        CREATE TABLE IF NOT EXISTS products (
            id SERIAL PRIMARY KEY,
            sku VARCHAR(50) UNIQUE NOT NULL,
            barcode VARCHAR(50),
            name VARCHAR(200) NOT NULL,
            description TEXT,
            category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL,
            category VARCHAR(100),
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price DECIMAL(12,2) DEFAULT 0.00,
            quantity INTEGER NOT NULL DEFAULT 0,
            stock_quantity INTEGER DEFAULT 0,
            reorder_level INTEGER NOT NULL DEFAULT 10,
            status VARCHAR(20) DEFAULT 'active',
            is_active BOOLEAN DEFAULT TRUE,
            image_path VARCHAR(500),
            image_url VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'sku' => 'VARCHAR(50)',
        'barcode' => 'VARCHAR(50)',
        'name' => 'VARCHAR(200)',
        'description' => 'TEXT',
        'category_id' => 'INTEGER',
        'supplier_id' => 'INTEGER',
        'category' => 'VARCHAR(100)',
        'unit_price' => 'DECIMAL(12,2) DEFAULT 0.00',
        'cost_price' => 'DECIMAL(12,2) DEFAULT 0.00',
        'price' => 'DECIMAL(12,2) DEFAULT 0.00',
        'quantity' => 'INTEGER DEFAULT 0',
        'stock_quantity' => 'INTEGER DEFAULT 0',
        'reorder_level' => 'INTEGER DEFAULT 10',
        'status' => "VARCHAR(20) DEFAULT 'active'",
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'image_path' => 'VARCHAR(500)',
        'image_url' => 'VARCHAR(500)',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'products', $column, $definition);
    }

    runSQL($pdo, 'product defaults', "
        UPDATE products
        SET unit_price = COALESCE(NULLIF(unit_price, 0), price, 0),
            price = COALESCE(NULLIF(price, 0), unit_price, 0),
            quantity = COALESCE(quantity, stock_quantity, 0),
            stock_quantity = COALESCE(stock_quantity, quantity, 0),
            reorder_level = COALESCE(reorder_level, 10),
            is_active = COALESCE(is_active, TRUE),
            status = COALESCE(NULLIF(status, ''), CASE WHEN COALESCE(is_active, TRUE) THEN 'active' ELSE 'inactive' END),
            created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    runSQL($pdo, 'orders table', "
        CREATE TABLE IF NOT EXISTS orders (
            id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            order_number VARCHAR(50),
            customer_name VARCHAR(100),
            customer_email VARCHAR(100),
            customer_phone VARCHAR(30),
            status VARCHAR(20) DEFAULT 'pending',
            payment_status VARCHAR(20) DEFAULT 'pending',
            payment_method VARCHAR(50),
            transaction_id VARCHAR(100),
            mpesa_receipt_number VARCHAR(100),
            total_amount DECIMAL(12,2) DEFAULT 0.00,
            subtotal DECIMAL(12,2) DEFAULT 0.00,
            tax_amount DECIMAL(12,2) DEFAULT 0.00,
            shipping_address TEXT,
            billing_address TEXT,
            notes TEXT,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'user_id' => 'INTEGER',
        'order_number' => 'VARCHAR(50)',
        'customer_name' => 'VARCHAR(100)',
        'customer_email' => 'VARCHAR(100)',
        'customer_phone' => 'VARCHAR(30)',
        'status' => "VARCHAR(20) DEFAULT 'pending'",
        'payment_status' => "VARCHAR(20) DEFAULT 'pending'",
        'payment_method' => 'VARCHAR(50)',
        'transaction_id' => 'VARCHAR(100)',
        'mpesa_receipt_number' => 'VARCHAR(100)',
        'total_amount' => 'DECIMAL(12,2) DEFAULT 0.00',
        'subtotal' => 'DECIMAL(12,2) DEFAULT 0.00',
        'tax_amount' => 'DECIMAL(12,2) DEFAULT 0.00',
        'shipping_address' => 'TEXT',
        'billing_address' => 'TEXT',
        'notes' => 'TEXT',
        'order_date' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'orders', $column, $definition);
    }

    runSQL($pdo, 'order defaults', "
        UPDATE orders
        SET status = COALESCE(NULLIF(status, ''), 'pending'),
            payment_status = COALESCE(NULLIF(payment_status, ''), 'pending'),
            total_amount = COALESCE(total_amount, 0),
            subtotal = COALESCE(subtotal, total_amount, 0),
            tax_amount = COALESCE(tax_amount, 0),
            order_date = COALESCE(order_date, created_at, CURRENT_TIMESTAMP),
            created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
    ");

    runSQL($pdo, 'order_items table', "
        CREATE TABLE IF NOT EXISTS order_items (
            id SERIAL PRIMARY KEY,
            order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
            product_name VARCHAR(200),
            quantity INTEGER NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price DECIMAL(12,2) DEFAULT 0.00,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'order_id' => 'INTEGER',
        'product_id' => 'INTEGER',
        'product_name' => 'VARCHAR(200)',
        'quantity' => 'INTEGER DEFAULT 1',
        'unit_price' => 'DECIMAL(12,2) DEFAULT 0.00',
        'price' => 'DECIMAL(12,2) DEFAULT 0.00',
        'subtotal' => 'DECIMAL(12,2) DEFAULT 0.00',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'order_items', $column, $definition);
    }

    runSQL($pdo, 'payments table', "
        CREATE TABLE IF NOT EXISTS payments (
            id SERIAL PRIMARY KEY,
            order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            payment_method VARCHAR(50),
            payment_gateway VARCHAR(50),
            amount DECIMAL(12,2) DEFAULT 0.00,
            status VARCHAR(20) DEFAULT 'pending',
            transaction_id VARCHAR(100),
            checkout_request_id VARCHAR(100),
            reference_number VARCHAR(100),
            gateway_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'order_id' => 'INTEGER',
        'payment_method' => 'VARCHAR(50)',
        'payment_gateway' => 'VARCHAR(50)',
        'amount' => 'DECIMAL(12,2) DEFAULT 0.00',
        'status' => "VARCHAR(20) DEFAULT 'pending'",
        'transaction_id' => 'VARCHAR(100)',
        'checkout_request_id' => 'VARCHAR(100)',
        'reference_number' => 'VARCHAR(100)',
        'gateway_response' => 'TEXT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'payments', $column, $definition);
    }

    runSQL($pdo, 'payment_transactions table', "
        CREATE TABLE IF NOT EXISTS payment_transactions (
            id SERIAL PRIMARY KEY,
            order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            transaction_id VARCHAR(100),
            payment_gateway VARCHAR(50) DEFAULT 'manual',
            payment_method VARCHAR(50),
            amount DECIMAL(12,2) DEFAULT 0.00,
            status VARCHAR(20) DEFAULT 'pending',
            reference_number VARCHAR(100),
            checkout_request_id VARCHAR(100),
            gateway_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'order_id' => 'INTEGER',
        'transaction_id' => 'VARCHAR(100)',
        'payment_gateway' => "VARCHAR(50) DEFAULT 'manual'",
        'payment_method' => 'VARCHAR(50)',
        'amount' => 'DECIMAL(12,2) DEFAULT 0.00',
        'status' => "VARCHAR(20) DEFAULT 'pending'",
        'reference_number' => 'VARCHAR(100)',
        'checkout_request_id' => 'VARCHAR(100)',
        'gateway_response' => 'TEXT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'payment_transactions', $column, $definition);
    }

    runSQL($pdo, 'stock_logs table', "
        CREATE TABLE IF NOT EXISTS stock_logs (
            id SERIAL PRIMARY KEY,
            product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action VARCHAR(20) NOT NULL,
            quantity_before INTEGER NOT NULL DEFAULT 0,
            quantity_after INTEGER NOT NULL DEFAULT 0,
            quantity_changed INTEGER NOT NULL DEFAULT 0,
            reference_number VARCHAR(50),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'product_id' => 'INTEGER',
        'user_id' => 'INTEGER',
        'action' => 'VARCHAR(20)',
        'quantity_before' => 'INTEGER DEFAULT 0',
        'quantity_after' => 'INTEGER DEFAULT 0',
        'quantity_changed' => 'INTEGER DEFAULT 0',
        'reference_number' => 'VARCHAR(50)',
        'notes' => 'TEXT',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'stock_logs', $column, $definition);
    }

    runSQL($pdo, 'mpesa_transactions table', "
        CREATE TABLE IF NOT EXISTS mpesa_transactions (
            id SERIAL PRIMARY KEY,
            order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
            checkout_request_id VARCHAR(100),
            merchant_request_id VARCHAR(100),
            phone_number VARCHAR(30),
            amount DECIMAL(12,2),
            result_code VARCHAR(20),
            result_desc TEXT,
            mpesa_receipt_number VARCHAR(100),
            transaction_date TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'order_id' => 'INTEGER',
        'checkout_request_id' => 'VARCHAR(100)',
        'merchant_request_id' => 'VARCHAR(100)',
        'phone_number' => 'VARCHAR(30)',
        'amount' => 'DECIMAL(12,2)',
        'result_code' => 'VARCHAR(20)',
        'result_desc' => 'TEXT',
        'mpesa_receipt_number' => 'VARCHAR(100)',
        'transaction_date' => 'TIMESTAMP',
        'status' => "VARCHAR(20) DEFAULT 'pending'",
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'mpesa_transactions', $column, $definition);
    }

    runSQL($pdo, 'audit_logs table', "
        CREATE TABLE IF NOT EXISTS audit_logs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action VARCHAR(100),
            table_name VARCHAR(80),
            record_id INTEGER,
            details TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    foreach ([
        'user_id' => 'INTEGER',
        'action' => 'VARCHAR(100)',
        'table_name' => 'VARCHAR(80)',
        'record_id' => 'INTEGER',
        'details' => 'TEXT',
        'ip_address' => 'VARCHAR(45)',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        addColumn($pdo, 'audit_logs', $column, $definition);
    }

    runSQL($pdo, 'admin_users table', "
        CREATE TABLE IF NOT EXISTS admin_users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            role VARCHAR(20) DEFAULT 'admin',
            last_login TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    runSQL($pdo, 'updated_at function', "
        CREATE OR REPLACE FUNCTION update_updated_at_column()
        RETURNS TRIGGER AS $$
        BEGIN
            NEW.updated_at = CURRENT_TIMESTAMP;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    ");

    foreach ([
        'users',
        'app_settings',
        'categories',
        'suppliers',
        'products',
        'orders',
        'order_items',
        'payments',
        'payment_transactions',
        'stock_logs',
        'mpesa_transactions',
        'admin_users',
    ] as $table) {
        if (columnExists($pdo, $table, 'updated_at')) {
            runSQL($pdo, "trigger {$table}", "
                DROP TRIGGER IF EXISTS update_{$table}_updated_at ON {$table};
                CREATE TRIGGER update_{$table}_updated_at
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                EXECUTE FUNCTION update_updated_at_column()
            ");
        }
    }

    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_users_username ON users(username)',
        'CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)',
        'CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)',
        'CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name)',
        'CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku)',
        'CREATE INDEX IF NOT EXISTS idx_products_category_id ON products(category_id)',
        'CREATE INDEX IF NOT EXISTS idx_products_supplier_id ON products(supplier_id)',
        'CREATE INDEX IF NOT EXISTS idx_products_quantity ON products(quantity)',
        'CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id)',
        'CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)',
        'CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders(payment_status)',
        'CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id)',
        'CREATE INDEX IF NOT EXISTS idx_payment_transactions_order_id ON payment_transactions(order_id)',
        'CREATE INDEX IF NOT EXISTS idx_payment_transactions_checkout ON payment_transactions(checkout_request_id)',
        'CREATE INDEX IF NOT EXISTS idx_stock_logs_product_id ON stock_logs(product_id)',
        'CREATE INDEX IF NOT EXISTS idx_mpesa_checkout ON mpesa_transactions(checkout_request_id)',
    ] as $indexSql) {
        runSQL($pdo, 'index', $indexSql);
    }

    $adminUsername = getenv('ADMIN_USERNAME') ?: 'admin';
    $adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@inventorysystem.com';
    $adminName = getenv('ADMIN_NAME') ?: 'System Administrator';
    $adminPassword = getenv('ADMIN_PASSWORD') ?: 'admin123';
    $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (
                username, password, email, full_name, role,
                is_active, is_verified, account_status,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, 'admin',
                TRUE, TRUE, 'active',
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT (username) DO UPDATE SET
                password = EXCLUDED.password,
                email = EXCLUDED.email,
                full_name = EXCLUDED.full_name,
                role = 'admin',
                is_active = TRUE,
                is_verified = TRUE,
                account_status = 'active',
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$adminUsername, $adminHash, $adminEmail, $adminName]);
        setupLog('admin user', true, "Login {$adminUsername} / {$adminPassword}");
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET password = ?,
                    full_name = COALESCE(NULLIF(full_name, ''), ?),
                    role = 'admin',
                    is_active = TRUE,
                    is_verified = TRUE,
                    account_status = 'active',
                    updated_at = CURRENT_TIMESTAMP
                WHERE LOWER(email) = LOWER(?)
            ");
            $stmt->execute([$adminHash, $adminName, $adminEmail]);

            if ($stmt->rowCount() > 0) {
                setupLog('admin user', true, "Existing email updated. Login {$adminEmail} / {$adminPassword}");
            } else {
                setupLog('admin user', false, $e->getMessage());
            }
        } catch (Throwable $fallbackError) {
            setupLog('admin user', false, $e->getMessage() . ' | fallback: ' . $fallbackError->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_users (username, password_hash, email, role, created_at, updated_at)
            VALUES (?, ?, ?, 'admin', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (username) DO UPDATE SET
                password_hash = EXCLUDED.password_hash,
                email = EXCLUDED.email,
                role = 'admin',
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$adminUsername, $adminHash, $adminEmail]);
        setupLog('admin_users seed', true, 'OK');
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET password_hash = ?,
                    role = 'admin',
                    updated_at = CURRENT_TIMESTAMP
                WHERE LOWER(email) = LOWER(?)
            ");
            $stmt->execute([$adminHash, $adminEmail]);

            if ($stmt->rowCount() > 0) {
                setupLog('admin_users seed', true, 'Existing admin email updated');
            } else {
                setupLog('admin_users seed', false, $e->getMessage());
            }
        } catch (Throwable $fallbackError) {
            setupLog('admin_users seed', false, $e->getMessage() . ' | fallback: ' . $fallbackError->getMessage());
        }
    }

    runSQL($pdo, 'seed categories', "
        INSERT INTO categories (name, description, is_active)
        SELECT v.name, v.description, TRUE
        FROM (VALUES
            ('Electronics', 'Electronic devices and accessories'),
            ('Computers', 'Laptops and accessories'),
            ('Mobile Devices', 'Smartphones and tablets'),
            ('Office Supplies', 'General office supplies'),
            ('Furniture', 'Office and home furniture'),
            ('Clothing', 'Apparel and fashion'),
            ('General', 'Default category')
        ) AS v(name, description)
        WHERE NOT EXISTS (
            SELECT 1 FROM categories c WHERE LOWER(c.name) = LOWER(v.name)
        )
    ");

    runSQL($pdo, 'seed supplier', "
        INSERT INTO suppliers (name, company_name, contact_person, email, phone, city, is_active)
        SELECT 'Default Supplier', 'Default Supplier', 'Inventory Team', 'supplier@inventorysystem.com', '', '', TRUE
        WHERE NOT EXISTS (
            SELECT 1 FROM suppliers WHERE LOWER(company_name) = LOWER('Default Supplier')
        )
    ");

    runSQL($pdo, 'seed products', "
        INSERT INTO products (
            sku, barcode, name, description, category_id, supplier_id,
            category, unit_price, cost_price, price,
            quantity, stock_quantity, reorder_level, is_active, status
        )
        SELECT v.sku, v.barcode, v.name, v.description, c.id, s.id,
               v.category, v.unit_price, v.cost_price, v.unit_price,
               v.quantity, v.quantity, v.reorder_level, TRUE, 'active'
        FROM (VALUES
            ('ELEC001', '1234567890123', 'Wireless Mouse', 'Ergonomic wireless mouse', 'Electronics', 25.99, 15.00, 100, 20),
            ('ELEC002', '1234567890124', 'USB Keyboard', 'Mechanical RGB keyboard', 'Electronics', 45.99, 28.00, 75, 15),
            ('COMP001', '1234567890125', 'Laptop Stand', 'Adjustable aluminum stand', 'Computers', 35.99, 20.00, 50, 10),
            ('MOBI001', '1234567890127', 'Phone Charger', 'Fast charging USB-C 20W', 'Mobile Devices', 19.99, 10.00, 200, 50),
            ('OFFI001', '1234567890128', 'Ballpoint Pens', 'Box of 50 blue pens', 'Office Supplies', 12.99, 6.00, 150, 30),
            ('FURN001', '1234567890129', 'Office Chair', 'Ergonomic lumbar support', 'Furniture', 149.99, 90.00, 25, 5),
            ('CLTH001', '1234567890130', 'T-Shirt Cotton', '100% cotton black medium', 'Clothing', 15.99, 8.00, 100, 25)
        ) AS v(sku, barcode, name, description, category, unit_price, cost_price, quantity, reorder_level)
        LEFT JOIN categories c ON LOWER(c.name) = LOWER(v.category)
        LEFT JOIN suppliers s ON LOWER(s.company_name) = LOWER('Default Supplier')
        WHERE NOT EXISTS (
            SELECT 1 FROM products p WHERE p.sku = v.sku
        )
    ");

    runSQL($pdo, 'seed settings', "
        INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
        SELECT v.setting_key, v.setting_value, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        FROM (VALUES
            ('store_name', 'Inventory System'),
            ('store_email', 'admin@inventorysystem.com'),
            ('currency', 'KES'),
            ('timezone', 'Africa/Nairobi'),
            ('low_stock_threshold', '5'),
            ('date_format', 'Y-m-d'),
            ('notify_new_orders', '1'),
            ('notify_low_stock', '1'),
            ('notify_daily_sales_report', '0'),
            ('notify_weekly_summary', '0'),
            ('notify_order_status_changes', '1'),
            ('notify_inventory_updates', '1'),
            ('notify_user_activity', '1')
        ) AS v(setting_key, setting_value)
        WHERE NOT EXISTS (
            SELECT 1 FROM app_settings s WHERE s.setting_key = v.setting_key
        )
    ");
}

$requiredTables = [
    'users',
    'app_settings',
    'categories',
    'suppliers',
    'products',
    'orders',
    'order_items',
    'payments',
    'payment_transactions',
    'stock_logs',
    'mpesa_transactions',
    'audit_logs',
    'admin_users',
];

$tableStatus = [];
if ($pdo instanceof PDO) {
    foreach ($requiredTables as $table) {
        try {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $tableStatus[$table] = ['ok' => true, 'rows' => $count, 'message' => 'OK'];
        } catch (Throwable $e) {
            $tableStatus[$table] = ['ok' => false, 'rows' => 0, 'message' => $e->getMessage()];
        }
    }
}

$requiredColumns = [
    'products' => ['id', 'sku', 'name', 'category_id', 'supplier_id', 'unit_price', 'cost_price', 'quantity', 'reorder_level', 'is_active', 'image_path'],
    'orders' => ['id', 'user_id', 'order_number', 'status', 'payment_status', 'payment_method', 'transaction_id', 'subtotal', 'tax_amount', 'total_amount'],
    'users' => ['id', 'username', 'password', 'email', 'full_name', 'role', 'is_active', 'is_verified', 'account_status'],
    'mpesa_transactions' => ['id', 'order_id', 'checkout_request_id', 'merchant_request_id', 'phone_number', 'amount', 'result_code', 'result_desc', 'mpesa_receipt_number', 'transaction_date', 'status'],
];

$columnStatus = [];
if ($pdo instanceof PDO) {
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnStatus["{$table}.{$column}"] = columnExists($pdo, $table, $column);
        }
    }
}

$failedOperations = array_filter($results, fn(array $row): bool => !$row['ok']);
$missingTables = array_filter($tableStatus, fn(array $row): bool => !$row['ok']);
$missingColumns = array_filter($columnStatus, fn(bool $ok): bool => !$ok);
$allOk = $pdo instanceof PDO && $failedOperations === [] && $missingTables === [] && $missingColumns === [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory DB Setup</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px;
            background: #101318;
            color: #e8edf2;
            font-family: Consolas, Monaco, monospace;
            line-height: 1.5;
        }
        main { max-width: 1120px; margin: 0 auto; }
        h1 { margin: 0 0 16px; font-size: 24px; }
        h2 { margin: 0 0 12px; font-size: 14px; text-transform: uppercase; letter-spacing: .08em; color: #9fb0c2; }
        .summary {
            padding: 18px 20px;
            border-radius: 8px;
            margin-bottom: 18px;
            border: 1px solid;
            font-weight: 700;
        }
        .summary.pass { color: #9ff3bd; background: #102018; border-color: #2c9b58; }
        .summary.fail { color: #ffc1c1; background: #261717; border-color: #b84848; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .panel {
            background: #171c23;
            border: 1px solid #28313c;
            border-radius: 8px;
            padding: 16px;
            overflow: auto;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        td, th { padding: 7px 6px; border-bottom: 1px solid #28313c; text-align: left; vertical-align: top; }
        th { color: #9fb0c2; font-weight: 700; }
        .ok { color: #82e6a4; font-weight: 700; }
        .bad { color: #ff9e9e; font-weight: 700; }
        .muted { color: #9fb0c2; }
        .message { max-width: 580px; word-break: break-word; }
        .warning {
            margin-top: 18px;
            padding: 16px;
            border-radius: 8px;
            background: #241d10;
            border: 1px solid #b27624;
            color: #ffd596;
        }
        code {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            background: #252b33;
            color: #f6d365;
        }
        a { color: #8ecaff; }
    </style>
</head>
<body>
<main>
    <h1>Inventory Database Setup</h1>

    <div class="summary <?php echo $allOk ? 'pass' : 'fail'; ?>">
        <?php echo $allOk
            ? 'PASS: database setup completed. The admin pages should now find products, categories, suppliers, orders, and payments.'
            : 'CHECK NEEDED: one or more setup operations failed. Read the rows marked FAIL below.'; ?>
    </div>

    <div class="grid">
        <section class="panel">
            <h2>Required Tables</h2>
            <table>
                <thead>
                    <tr><th>Status</th><th>Table</th><th>Rows</th></tr>
                </thead>
                <tbody>
                <?php foreach ($requiredTables as $table): ?>
                    <?php $status = $tableStatus[$table] ?? ['ok' => false, 'rows' => 0, 'message' => 'Not checked']; ?>
                    <tr>
                        <td class="<?php echo $status['ok'] ? 'ok' : 'bad'; ?>"><?php echo $status['ok'] ? 'OK' : 'FAIL'; ?></td>
                        <td><?php echo htmlspecialchars($table); ?></td>
                        <td><?php echo $status['ok'] ? (int) $status['rows'] : htmlspecialchars($status['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Important Columns</h2>
            <table>
                <thead>
                    <tr><th>Status</th><th>Column</th></tr>
                </thead>
                <tbody>
                <?php foreach ($columnStatus as $column => $ok): ?>
                    <tr>
                        <td class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? 'OK' : 'FAIL'; ?></td>
                        <td><?php echo htmlspecialchars($column); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="panel" style="margin-top:16px;">
        <h2>Operations</h2>
        <table>
            <thead>
                <tr><th>Status</th><th>Operation</th><th>Message</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td class="<?php echo $row['ok'] ? 'ok' : 'bad'; ?>"><?php echo $row['ok'] ? 'OK' : 'FAIL'; ?></td>
                    <td><?php echo htmlspecialchars($row['label']); ?></td>
                    <td class="message <?php echo $row['ok'] ? 'muted' : 'bad'; ?>"><?php echo htmlspecialchars($row['message']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($allOk): ?>
        <section class="panel" style="margin-top:16px;">
            <h2>Next Links</h2>
            <p><a href="admin.php?page=products">Open admin products</a></p>
            <p><a href="modules/test.php">Run module diagnostic</a></p>
            <p>Default admin login is <code><?php echo htmlspecialchars(getenv('ADMIN_USERNAME') ?: 'admin'); ?></code> /
                <code><?php echo htmlspecialchars(getenv('ADMIN_PASSWORD') ?: 'admin123'); ?></code>.</p>
        </section>
    <?php endif; ?>

    <div class="warning">
        Delete <code>run_setup.php</code> from GitHub immediately after this page shows PASS, then redeploy.
        Leaving this file online is a security risk because it can reset/create the admin account.
    </div>
</main>
</body>
</html>
