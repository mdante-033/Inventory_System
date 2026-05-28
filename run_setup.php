<?php
/**
 * run_setup.php
 * Place in project ROOT — same folder as login.php
 * Visit once: https://inventory-system-1-tggc.onrender.com/run_setup.php
 * DELETE FROM GITHUB IMMEDIATELY AFTER
 */

// ── Direct DB connection (bypasses db_connect.php) ────────────────────────────
$url = getenv('DATABASE_URL');
if ($url) {
    $p   = parse_url($url);
    $dsn = "pgsql:host={$p['host']};port=" . ($p['port']??5432) . ";dbname=" . ltrim($p['path'],'/');
    try {
        $pdo = new PDO($dsn, $p['user'], $p['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    } catch (Exception $e) {
        die('<h2 style="color:red">DB connection failed: '.htmlspecialchars($e->getMessage()).'</h2>');
    }
} else {
    die('<h2 style="color:red">DATABASE_URL not set in Render environment.</h2>');
}

$log = [];

function x(PDO $pdo, string $label, string $sql): void {
    global $log;
    try { $pdo->exec($sql); $log[] = ['ok'=>true,  'msg'=>$label]; }
    catch (PDOException $e) { $log[] = ['ok'=>false, 'msg'=>"$label: ".$e->getMessage()]; }
}

// ── tables ────────────────────────────────────────────────────────────────────
x($pdo,'categories',    "CREATE TABLE IF NOT EXISTS categories (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, description TEXT, parent_id INTEGER REFERENCES categories(id) ON DELETE SET NULL, is_active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'suppliers',     "CREATE TABLE IF NOT EXISTS suppliers (id SERIAL PRIMARY KEY, company_name VARCHAR(200) NOT NULL, contact_person VARCHAR(100), email VARCHAR(100), phone VARCHAR(20), address TEXT, city VARCHAR(100), is_active BOOLEAN DEFAULT TRUE, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'products',      "CREATE TABLE IF NOT EXISTS products (id SERIAL PRIMARY KEY, sku VARCHAR(50) UNIQUE NOT NULL, barcode VARCHAR(50), name VARCHAR(200) NOT NULL, description TEXT, category_id INTEGER REFERENCES categories(id) ON DELETE RESTRICT, supplier_id INTEGER REFERENCES suppliers(id) ON DELETE SET NULL, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, quantity INTEGER NOT NULL DEFAULT 0, reorder_level INTEGER NOT NULL DEFAULT 10, is_active BOOLEAN DEFAULT TRUE, image_path VARCHAR(500), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'orders',        "CREATE TABLE IF NOT EXISTS orders (id SERIAL PRIMARY KEY, user_id INTEGER REFERENCES users(id), order_number VARCHAR(50), customer_name VARCHAR(100), customer_email VARCHAR(100), status VARCHAR(20) DEFAULT 'pending', payment_status VARCHAR(20) DEFAULT 'pending', payment_method VARCHAR(50), transaction_id VARCHAR(100), total_amount DECIMAL(10,2) DEFAULT 0.00, subtotal DECIMAL(10,2) DEFAULT 0.00, tax_amount DECIMAL(10,2) DEFAULT 0.00, shipping_address TEXT, notes TEXT, order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'order_items',   "CREATE TABLE IF NOT EXISTS order_items (id SERIAL PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE, product_id INTEGER REFERENCES products(id), product_name VARCHAR(200), quantity INTEGER NOT NULL DEFAULT 1, unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00, subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'payments',      "CREATE TABLE IF NOT EXISTS payments (id SERIAL PRIMARY KEY, order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL, payment_method VARCHAR(50), payment_gateway VARCHAR(50), amount DECIMAL(10,2) DEFAULT 0, status VARCHAR(20) DEFAULT 'pending', transaction_id VARCHAR(100), reference_number VARCHAR(100), gateway_response JSONB, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'payment_transactions', "CREATE TABLE IF NOT EXISTS payment_transactions (id SERIAL PRIMARY KEY, order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL, transaction_id VARCHAR(100), payment_gateway VARCHAR(50) DEFAULT 'manual', payment_method VARCHAR(50), amount DECIMAL(10,2), status VARCHAR(20) DEFAULT 'pending', reference_number VARCHAR(100), checkout_request_id VARCHAR(100), gateway_response TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'stock_logs',    "CREATE TABLE IF NOT EXISTS stock_logs (id SERIAL PRIMARY KEY, product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE, user_id INTEGER, action VARCHAR(20) NOT NULL, quantity_before INTEGER NOT NULL DEFAULT 0, quantity_after INTEGER NOT NULL DEFAULT 0, quantity_changed INTEGER NOT NULL DEFAULT 0, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'mpesa_transactions', "CREATE TABLE IF NOT EXISTS mpesa_transactions (id SERIAL PRIMARY KEY, order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL, checkout_request_id VARCHAR(100), merchant_request_id VARCHAR(100), phone_number VARCHAR(20), amount DECIMAL(10,2), result_code VARCHAR(10), result_desc TEXT, mpesa_receipt_number VARCHAR(50), status VARCHAR(20) DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
x($pdo,'audit_logs',    "CREATE TABLE IF NOT EXISTS audit_logs (id SERIAL PRIMARY KEY, user_id INTEGER, action VARCHAR(100), table_name VARCHAR(50), record_id INTEGER, details JSONB, ip_address VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// ── seed categories ───────────────────────────────────────────────────────────
x($pdo,'seed categories', "INSERT INTO categories (name,description) VALUES ('Electronics','Electronic devices'),('Computers','Laptops and accessories'),('Mobile Devices','Smartphones and tablets'),('Office Supplies','General office supplies'),('Furniture','Office and home furniture'),('Clothing','Apparel and fashion'),('General','Default category') ON CONFLICT (name) DO NOTHING");

// ── seed sample products ──────────────────────────────────────────────────────
x($pdo,'seed products', "INSERT INTO products (sku,name,description,category_id,unit_price,cost_price,quantity,reorder_level) VALUES ('ELEC001','Wireless Mouse','Ergonomic wireless mouse',1,25.99,15.00,100,20),('ELEC002','USB Keyboard','Mechanical RGB keyboard',1,45.99,28.00,75,15),('COMP001','Laptop Stand','Adjustable aluminum stand',2,35.99,20.00,50,10),('MOBI001','Phone Charger','Fast charging USB-C 20W',3,19.99,10.00,200,50),('OFFI001','Ballpoint Pens','Box of 50 blue pens',4,12.99,6.00,150,30),('FURN001','Office Chair','Ergonomic lumbar support',5,149.99,90.00,25,5),('CLTH001','T-Shirt Cotton','100% cotton black medium',6,15.99,8.00,100,25) ON CONFLICT (sku) DO NOTHING");

// ── default settings ──────────────────────────────────────────────────────────
x($pdo,'seed settings', "INSERT INTO app_settings (setting_key,setting_value) VALUES ('store_name','Inventory System'),('store_email','admin@inventorysystem.com'),('currency','KES'),('timezone','Africa/Nairobi'),('low_stock_threshold','5') ON CONFLICT (setting_key) DO NOTHING");

// ── verify ────────────────────────────────────────────────────────────────────
$required = ['categories','suppliers','products','orders','order_items',
             'payments','payment_transactions','stock_logs','mpesa_transactions',
             'audit_logs','app_settings','users'];
$status = [];
foreach ($required as $t) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        $status[$t] = ['ok'=>true, 'rows'=>$cnt];
    } catch (PDOException $e) {
        $status[$t] = ['ok'=>false, 'rows'=>0];
    }
}
$allOk = !in_array(false, array_column($status,'ok'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DB Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:monospace;background:#0d0d1a;color:#e0e0e0;padding:32px;min-height:100vh}
h2{color:#00ff88;margin-bottom:20px;font-size:1.3rem}
.card{background:#111122;border:1px solid #222244;border-radius:10px;padding:20px;margin-bottom:16px}
.card h3{color:#6688cc;font-size:11px;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}
.row{display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #1a1a2e;font-size:13px}
.row:last-child{border-bottom:none}
.ok{color:#00cc66;font-weight:bold}.err{color:#ff4444;font-weight:bold}
.badge{padding:1px 8px;border-radius:99px;font-size:11px}
.badge.ok{background:#0a1a0a;color:#88cc88}.badge.err{background:#2a1a1a;color:#cc8888}
.summary{border-radius:10px;padding:20px;margin-bottom:20px;border:1px solid}
.summary.pass{background:#0a1a0a;border-color:#00cc66;color:#00cc66}
.summary.fail{background:#1a0a0a;border-color:#ff4444;color:#ff4444}
.cta{background:#001a0a;border:1px solid #ff8800;border-radius:10px;padding:20px;margin-top:20px;color:#ffaa44;line-height:1.9}
.cta strong{color:#ff8800}
a{color:#00aaff}
code{background:#1a1000;color:#ffcc88;padding:2px 6px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<h2>Database Setup — Results</h2>

<div class="summary <?= $allOk ? 'pass' : 'fail' ?>">
    <?= $allOk
        ? '✅ All tables created successfully! Your database is ready.'
        : '❌ Some tables are still missing — see details below.' ?>
</div>

<div class="card">
    <h3>Operations</h3>
    <?php foreach ($log as $r): ?>
    <div class="row">
        <span class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? '✓' : '✗' ?></span>
        <span><?= htmlspecialchars($r['msg']) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Table Status</h3>
    <?php foreach ($status as $table => $info): ?>
    <div class="row">
        <span class="<?= $info['ok'] ? 'ok' : 'err' ?>"><?= $info['ok'] ? '✓' : '✗' ?></span>
        <span style="min-width:200px"><?= $table ?></span>
        <span class="badge <?= $info['ok'] ? 'ok' : 'err' ?>">
            <?= $info['ok'] ? $info['rows'].' rows' : 'MISSING' ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($allOk): ?>
<div class="card">
    <h3>Login Credentials</h3>
    <div class="row"><span class="ok">✓</span><span>URL: <a href="admin.php?page=products">admin.php?page=products</a> — add your products here</span></div>
    <div class="row"><span class="ok">✓</span><span>Customer store: <a href="customer_dashboard.php">customer_dashboard.php</a></span></div>
</div>
<?php endif; ?>

<div class="cta">
    <strong>⚠ DELETE THIS FILE NOW:</strong><br><br>
    Go to GitHub → find <code>run_setup.php</code> → click the trash icon → commit.<br><br>
    Or via terminal:<br>
    <code>git rm run_setup.php && git commit -m "Remove DB setup" && git push</code>
</div>
</body>
</html>
