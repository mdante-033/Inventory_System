<?php
/**
 * Database Setup Script - Render Compatible Version
 * Run once then DELETE immediately
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Inventory System - Database Setup</h1>";
echo "<pre style='background:#f5f5f5;padding:15px;border-radius:5px;font-size:14px;'>";

// ── Read credentials from Render environment
$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl) {
    $parsed   = parse_url($databaseUrl);
    $host     = $parsed['host'];
    $port     = $parsed['port'] ?? 5432;
    $dbname   = ltrim($parsed['path'], '/');
    $username = $parsed['user'];
    $password = $parsed['pass'];
    echo "✓ Using DATABASE_URL from environment\n\n";
} else {
    $host     = getenv('DB_HOST')     ?: 'localhost';
    $port     = getenv('DB_PORT')     ?: '5432';
    $dbname   = getenv('DB_NAME')     ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
    $username = getenv('DB_USER')     ?: getenv('DB_USERNAME') ?: 'postgres';
    $password = getenv('DB_PASSWORD') ?: 'Root';
    echo "✓ Using individual DB env vars\n\n";
}

echo "Configuration:\n";
echo "  Host:     $host\n";
echo "  Port:     $port\n";
echo "  Database: $dbname\n";
echo "  Username: $username\n\n";

try {
    // ── Single DSN - connects directly to the database
    echo "Step 1: Connecting to database...\n";
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET search_path TO public");
    echo "✓ Connected successfully!\n\n";

    // ── USERS TABLE
    echo "Step 2: Creating tables...\n\n";
    echo "Creating users table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        customer_group VARCHAR(50) DEFAULT 'regular',
        role VARCHAR(20) DEFAULT 'staff'
            CHECK (role IN ('admin','manager','staff','customer')),
        is_active BOOLEAN DEFAULT true,
        is_verified BOOLEAN DEFAULT false,
        account_status VARCHAR(20) DEFAULT 'pending',
        verification_code VARCHAR(10),
        verification_code_expires_at TIMESTAMP,
        verification_attempts INTEGER DEFAULT 0,
        verification_failed_attempts INTEGER DEFAULT 0,
        verification_resend_count INTEGER DEFAULT 0,
        resend_count INTEGER DEFAULT 0,
        code_expiry TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ users table created\n";

    // ── CATEGORIES TABLE
    echo "Creating categories table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        parent_id INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
        is_active BOOLEAN DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ categories table created\n";

    // ── PRODUCTS TABLE
    echo "Creating products table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id SERIAL PRIMARY KEY,
        sku VARCHAR(50) UNIQUE NOT NULL,
        barcode VARCHAR(50),
        name VARCHAR(200) NOT NULL,
        description TEXT,
        category_id INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 10,
        is_active BOOLEAN DEFAULT true,
        image_path VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ products table created\n";

    // ── ORDERS TABLE
    echo "Creating orders table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id),
        order_number VARCHAR(50),
        customer_name VARCHAR(100),
        customer_email VARCHAR(100),
        status VARCHAR(20) DEFAULT 'pending',
        payment_status VARCHAR(20) DEFAULT 'pending',
        payment_method VARCHAR(50),
        transaction_id VARCHAR(100),
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        shipping_address TEXT,
        billing_address TEXT,
        notes TEXT,
        order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ orders table created\n";

    // ── ORDER ITEMS TABLE
    echo "Creating order_items table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id SERIAL PRIMARY KEY,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        product_id INTEGER REFERENCES products(id),
        product_name VARCHAR(200),
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price DECIMAL(10,2) DEFAULT 0.00,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ order_items table created\n";

    // ── PAYMENT TRANSACTIONS TABLE
    echo "Creating payment_transactions table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_transactions (
        id SERIAL PRIMARY KEY,
        order_id INTEGER REFERENCES orders(id),
        transaction_id VARCHAR(100),
        payment_gateway VARCHAR(50) DEFAULT 'manual',
        payment_method VARCHAR(50),
        amount DECIMAL(10,2),
        status VARCHAR(20) DEFAULT 'pending',
        reference_number VARCHAR(100),
        checkout_request_id VARCHAR(100),
        gateway_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ payment_transactions table created\n";

    // ── STOCK LOGS TABLE
    echo "Creating stock_logs table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_logs (
        id SERIAL PRIMARY KEY,
        product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
        action VARCHAR(20) NOT NULL
            CHECK (action IN ('add','remove','adjust','sale','return','transfer')),
        quantity_before INTEGER NOT NULL,
        quantity_after INTEGER NOT NULL,
        quantity_changed INTEGER NOT NULL,
        reference_number VARCHAR(50),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ stock_logs table created\n";

    // ── SUPPLIERS TABLE
    echo "Creating suppliers table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id SERIAL PRIMARY KEY,
        company_name VARCHAR(200) NOT NULL,
        contact_person VARCHAR(100),
        email VARCHAR(100),
        phone VARCHAR(20),
        address TEXT,
        city VARCHAR(100),
        is_active BOOLEAN DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ suppliers table created\n";

    // ── APP SETTINGS TABLE
    echo "Creating app_settings table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        id SERIAL PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ app_settings table created\n";

    // ── ADMIN USERS TABLE (separate admin panel)
    echo "Creating admin_users table...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id SERIAL PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        role VARCHAR(20) DEFAULT 'admin',
        last_login TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✓ admin_users table created\n";

    // ── INDEXES
    echo "\nCreating indexes...\n";
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_username ON users(username)",
        "CREATE INDEX IF NOT EXISTS idx_email ON users(email)",
        "CREATE INDEX IF NOT EXISTS idx_role ON users(role)",
        "CREATE INDEX IF NOT EXISTS idx_sku ON products(sku)",
        "CREATE INDEX IF NOT EXISTS idx_category ON products(category_id)",
        "CREATE INDEX IF NOT EXISTS idx_quantity ON products(quantity)",
        "CREATE INDEX IF NOT EXISTS idx_product_log ON stock_logs(product_id)",
        "CREATE INDEX IF NOT EXISTS idx_order_user ON orders(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_order_status ON orders(status)",
        "CREATE INDEX IF NOT EXISTS idx_payment_order ON payment_transactions(order_id)",
    ];
    foreach ($indexes as $sql) {
        $pdo->exec($sql);
    }
    echo "✓ Indexes created\n";

    // ── TRIGGERS
    echo "\nCreating triggers...\n";
    $pdo->exec("CREATE OR REPLACE FUNCTION update_updated_at_column()
        RETURNS TRIGGER AS \$\$
        BEGIN
            NEW.updated_at = CURRENT_TIMESTAMP;
            RETURN NEW;
        END;
        \$\$ language 'plpgsql'");

    $triggerTables = [
        'users', 'categories', 'products', 'orders',
        'stock_logs', 'suppliers', 'app_settings',
        'payment_transactions', 'admin_users'
    ];
    foreach ($triggerTables as $t) {
        $pdo->exec("DROP TRIGGER IF EXISTS update_{$t}_updated_at ON {$t}");
        $pdo->exec("CREATE TRIGGER update_{$t}_updated_at
            BEFORE UPDATE ON {$t}
            FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()");
    }
    echo "✓ Triggers created\n";

    // ── ADMIN USER in users table (for main app login)
    echo "\nStep 3: Creating admin user...\n";
    $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (
            username, password, email, full_name, role,
            is_active, is_verified, account_status
        ) VALUES (
            'admin', ?, 'admin@inventorysystem.com',
            'System Administrator', 'admin',
            true, true, 'active'
        ) ON CONFLICT (username) DO UPDATE SET
            is_verified    = true,
            is_active      = true,
            account_status = 'active',
            password       = EXCLUDED.password"
    )->execute([$passwordHash]);
    echo "✓ Admin user created in users table\n";

    // ── ADMIN USER in admin_users table (for admin panel login)
    $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO admin_users (username, password_hash, email, role)
        VALUES ('admin', ?, 'admin@inventorysystem.com', 'admin')
        ON CONFLICT (username) DO UPDATE SET
            password_hash = EXCLUDED.password_hash"
    )->execute([$adminHash]);
    echo "✓ Admin user created in admin_users table\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n";

    // ── SAMPLE CATEGORIES
    echo "\nStep 4: Inserting sample categories...\n";
    $pdo->exec("INSERT INTO categories (name, description, parent_id)
        SELECT v.name, v.description, v.parent_id::INTEGER
        FROM (VALUES
            ('Electronics',   'Electronic devices and accessories', NULL),
            ('Computers',     'Laptops and accessories',            '1'),
            ('Mobile Devices','Smartphones and tablets',            '1'),
            ('Office Supplies','General office supplies',           NULL),
            ('Furniture',     'Office and home furniture',          NULL),
            ('Clothing',      'Apparel and fashion items',          NULL)
        ) AS v(name, description, parent_id)
        WHERE NOT EXISTS (
            SELECT 1 FROM categories c WHERE c.name = v.name
        )");
    echo "✓ Sample categories inserted\n";

    // ── SAMPLE PRODUCTS
    echo "\nStep 5: Inserting sample products...\n";
    $pdo->exec("INSERT INTO products
        (sku, barcode, name, description, category_id,
         unit_price, cost_price, quantity, reorder_level)
        VALUES
        ('ELEC001','1234567890123','Wireless Mouse',
         'Ergonomic wireless mouse',1,25.99,15.00,100,20),
        ('ELEC002','1234567890124','USB Keyboard',
         'Mechanical RGB keyboard',1,45.99,28.00,75,15),
        ('COMP001','1234567890125','Laptop Stand',
         'Adjustable aluminum stand',2,35.99,20.00,50,10),
        ('MOBI001','1234567890127','Phone Charger',
         'Fast charging USB-C 20W',3,19.99,10.00,200,50),
        ('OFFI001','1234567890128','Ballpoint Pens (Box)',
         'Box of 50 blue pens',4,12.99,6.00,150,30),
        ('FURN001','1234567890129','Office Chair',
         'Ergonomic lumbar support',5,149.99,90.00,25,5),
        ('CLTH001','1234567890130','T-Shirt (Cotton)',
         '100% cotton Black M',6,15.99,8.00,100,25)
        ON CONFLICT (sku) DO NOTHING");
    echo "✓ Sample products inserted\n";

    // ── DEFAULT SETTINGS
    echo "\nStep 6: Inserting default settings...\n";
    $settings = [
        ['store_name',          'Inventory System'],
        ['store_email',         'admin@inventorysystem.com'],
        ['currency',            'USD'],
        ['timezone',            'Africa/Nairobi'],
        ['low_stock_threshold', '5'],
    ];
    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?) ON CONFLICT (setting_key) DO NOTHING");
    foreach ($settings as $s) {
        $stmt->execute($s);
    }
    echo "✓ Default settings inserted\n";

    // ── VERIFY ALL TABLES
    echo "\n========================================\n";
    echo "Tables in database:\n";
    $tables = $pdo->query("SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
        ORDER BY table_name")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        echo "  ✓ $t\n";
    }

    echo "\n========================================\n";
    echo "✓ DATABASE SETUP COMPLETED SUCCESSFULLY!\n";
    echo "========================================\n\n";
    echo "Admin login:  <a href='/login.php'>/login.php</a>\n";
    echo "  Username:   admin\n";
    echo "  Password:   admin123\n\n";
    echo "<strong style='color:red;font-size:16px;'>";
    echo "⚠ DELETE THIS FILE FROM GITHUB IMMEDIATELY AFTER USE!";
    echo "</strong>\n";

} catch (PDOException $e) {
    echo "✗ Setup FAILED!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Checklist:\n";
    echo "  1. Is DATABASE_URL set in Render environment variables?\n";
    echo "  2. Is your PostgreSQL service running on Render?\n";
    echo "  3. Are both services in the same Render region?\n";
}

echo "</pre>";
?>
