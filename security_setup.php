<?php
/**
 * Security Setup Script
 * Run this once to set up all security features and database tables
 * Usage: php security_setup.php
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/encryption.php';

/** @var PDO|null $pdo */
$pdo = getDBConnection();
$dbConnectionError = $db_connection_error ?? 'Unknown database connection error.';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  INVENTORY SYSTEM - SECURITY SETUP WIZARD                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Check database connection
if (!($pdo instanceof PDO)) {
    echo "❌ ERROR: Database connection failed\n";
    echo $dbConnectionError . "\n";
    exit(1);
}

echo "✅ Database connection successful\n\n";

// ========================================================================
// 1. GENERATE ENCRYPTION KEY
// ========================================================================
echo "Step 1: Generate Encryption Key\n";
echo "─────────────────────────────────────────────────────────────\n";

$encryptionKey = bin2hex(openssl_random_pseudo_bytes(32));
$encryptionKeyBase64 = base64_encode(openssl_random_pseudo_bytes(32));

echo "Generated Encryption Key (base64):\n";
echo "  " . $encryptionKeyBase64 . "\n\n";

echo "Add this to your .env file:\n";
echo "  ENCRYPTION_KEY=" . $encryptionKeyBase64 . "\n\n";

// ========================================================================
// 2. CREATE SECURITY DATABASE TABLES
// ========================================================================
echo "Step 2: Creating Security Database Tables\n";
echo "─────────────────────────────────────────────────────────────\n";

$sqlFile = __DIR__ . '/sql/security_migration.sql';

if (!file_exists($sqlFile)) {
    echo "❌ ERROR: security_migration.sql not found at $sqlFile\n";
    exit(1);
}

$sqlContent = file_get_contents($sqlFile);
$statements = array_filter(array_map('trim', preg_split('/;(?=\s*$)/m', $sqlContent)));

$createdTables = 0;

foreach ($statements as $sql) {
    if (empty($sql) || str_starts_with(trim($sql), '--')) {
        continue;
    }

    try {
        $pdo->exec($sql);
        $createdTables++;
    } catch (\PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') === false) {
            echo "⚠️  Warning: " . $e->getMessage() . "\n";
        }
    }
}

echo "✅ Database migration completed\n";
echo "   Tables created/verified: $createdTables\n\n";

// ========================================================================
// 3. UPDATE ENVIRONMENT VARIABLES
// ========================================================================
echo "Step 3: Environment Variable Configuration\n";
echo "─────────────────────────────────────────────────────────────\n";

$envFile = __DIR__ . '/.env';
$envExampleFile = __DIR__ . '/.env.example';

echo "Required environment variables:\n";
echo "  • ENCRYPTION_KEY (generated above)\n";
echo "  • APP_ENV (local, staging, or production)\n";
echo "  • APP_DEBUG (true or false)\n";
echo "  • SESSION_COOKIE_NAME (default: __Secure-INVSYS)\n";
echo "  • SESSION_IDLE_TIMEOUT (seconds, default: 1800)\n";
echo "  • SECURITY_LOG_PATH (file path for logs)\n\n";

if (file_exists($envFile)) {
    echo "ℹ️  .env file exists. Update with the encryption key above.\n\n";
} else if (file_exists($envExampleFile)) {
    echo "ℹ️  .env.example found. Copy it to .env and update with values.\n\n";
} else {
    echo "⚠️  No .env file found. Create one with the values above.\n\n";
}

// ========================================================================
// 4. TEST ENCRYPTION
// ========================================================================
echo "Step 4: Testing Encryption\n";
echo "─────────────────────────────────────────────────────────────\n";

try {
    putenv("ENCRYPTION_KEY=" . $encryptionKeyBase64);
    $encryptor = new EncryptionHandler();

    $testData = 'Test encryption data: ' . date('Y-m-d H:i:s');
    $encrypted = $encryptor->encrypt($testData);
    $decrypted = $encryptor->decrypt($encrypted);

    if ($decrypted === $testData) {
        echo "✅ Encryption working correctly\n";
        echo "   Original:  " . substr($testData, 0, 30) . "...\n";
        echo "   Encrypted: " . substr($encrypted, 0, 30) . "...\n";
        echo "   Decrypted: " . substr($decrypted, 0, 30) . "...\n\n";
    } else {
        echo "❌ Encryption test failed\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "❌ Encryption error: " . $e->getMessage() . "\n";
    exit(1);
}

// ========================================================================
// 5. VERIFY TABLES EXIST
// ========================================================================
echo "Step 5: Verifying Security Tables\n";
echo "─────────────────────────────────────────────────────────────\n";

$requiredTables = [
    'login_attempts',
    'api_requests',
    'audit_logs',
    'security_logs',
    'user_2fa',
    'password_history',
    'session_activity',
    'encryption_keys',
    'security_incidents',
    'api_keys'
];

$stmt = $pdo->query("
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = 'public'
");
$existingTables = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'table_name');

$missingTables = array_diff($requiredTables, $existingTables);

if (empty($missingTables)) {
    echo "✅ All security tables exist:\n";
    foreach ($requiredTables as $table) {
        echo "   ✓ $table\n";
    }
    echo "\n";
} else {
    echo "⚠️  Missing tables:\n";
    foreach ($missingTables as $table) {
        echo "   ✗ $table\n";
    }
    echo "\nRun the security_migration.sql manually to create them.\n\n";
}

// ========================================================================
// 6. CONFIGURATION SUMMARY
// ========================================================================
echo "Step 6: Configuration Summary\n";
echo "─────────────────────────────────────────────────────────────\n";

$summary = [
    'Security Headers' => 'includes/security_headers.php',
    'Input Validators' => 'includes/validators.php',
    'Output Escaping' => 'includes/output.php',
    'Encryption' => 'includes/encryption.php',
    'Rate Limiting' => 'includes/rate_limiter.php',
    'Security Logger' => 'includes/security_logger.php',
    'Web Server Config (Apache)' => '.htaccess',
    'Web Server Config (Nginx)' => 'config/nginx.conf.example',
    'PHP Configuration' => 'config/php.ini.example',
];

foreach ($summary as $feature => $file) {
    echo "  ✓ $feature\n";
    echo "    → $file\n";
}

echo "\n";

// ========================================================================
// 7. NEXT STEPS
// ========================================================================
echo "NEXT STEPS:\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "1. Add ENCRYPTION_KEY to your .env file\n";
echo "2. Configure APP_ENV, APP_DEBUG, and SESSION settings\n";
echo "3. Copy config/php.ini.example settings to your production php.ini\n";
echo "4. Deploy .htaccess or nginx.conf.example to your server\n";
echo "5. Verify HTTPS is enabled and working\n";
echo "6. Test login with rate limiting at http://localhost/login.php\n";
echo "7. Check security logs at: includes/security_headers.php\n";
echo "8. Schedule monthly: composer audit to check for vulnerabilities\n";
echo "\n";

// ========================================================================
// 8. SECURITY CHECKLIST
// ========================================================================
echo "SECURITY CHECKLIST:\n";
echo "─────────────────────────────────────────────────────────────\n";

$checklist = [
    '☐ Enable HTTPS/SSL on production server',
    '☐ Generate and add ENCRYPTION_KEY to .env',
    '☐ Configure PHP.ini with security settings',
    '☐ Enable .htaccess on Apache server',
    '☐ Deploy nginx.conf on Nginx server',
    '☐ Set APP_ENV=production',
    '☐ Disable APP_DEBUG=false',
    '☐ Set up log rotation for security logs',
    '☐ Configure database backup strategy',
    '☐ Test login rate limiting (5 attempts per 15 min)',
    '☐ Verify session timeout (30 minutes idle)',
    '☐ Enable MFA for admin accounts',
    '☐ Schedule monthly security audits',
    '☐ Run composer audit monthly',
];

foreach ($checklist as $item) {
    echo "  $item\n";
}

echo "\n";

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  ✅ SECURITY SETUP COMPLETED                                ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
?>
