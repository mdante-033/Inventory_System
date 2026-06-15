<?php
/**
 * db_connect.php — Universal database connection
 *
 * Works on:
 *  • Local XAMPP / WAMP / MAMP  → reads .env file or system env vars
 *  • Render free/paid tier       → reads DATABASE_URL automatically
 *  • Railway / Fly.io / VPS      → reads DATABASE_URL or individual vars
 *  • Shared PHP hosting          → reads individual env vars or config/database.php
 *
 * Never produces a blank page. All errors stored in $db_connection_error.
 */

// ── Guard against double-inclusion ──────────────────────────────────────────
if (isset($pdo) && $pdo instanceof PDO) {
    // Already connected — define helpers if missing and return
    if (!function_exists('isDBConnected')) {
        function isDBConnected(): bool { global $pdo; return $pdo instanceof PDO; }
        function getDBConnection(): ?PDO { global $pdo; return $pdo; }
    }
    return;
}

$pdo                  = null;
$db_connection_failed = false;
$db_connection_error  = '';

// ── Load .env file for local development ────────────────────────────────────
// On production (Render, Railway, etc.) env vars are already set at system level.
// Locally you create a .env file and this loads it.
$envFile = __DIR__ . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and lines without =
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'"); // strip surrounding quotes
        if ($key !== '' && !getenv($key)) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// ── Also try config/database.php for shared hosting setups ──────────────────
$dbConfigFile = __DIR__ . '/config/database.php';
if (file_exists($dbConfigFile) && !getenv('DB_HOST') && !getenv('DATABASE_URL')) {
    @include_once $dbConfigFile;
}

try {
    // ── Priority 1: DATABASE_URL (Render, Railway, Heroku, Fly.io) ──────────
    $databaseUrl = getenv('DATABASE_URL');

    if ($databaseUrl && (str_starts_with($databaseUrl, 'postgres') || str_starts_with($databaseUrl, 'pgsql'))) {
        $p      = parse_url($databaseUrl);
        $host   = $p['host']           ?? 'localhost';
        $port   = $p['port']           ?? 5432;
        $dbname = ltrim($p['path'] ?? '/', '/');
        $user   = urldecode($p['user'] ?? '');
        $pass   = urldecode($p['pass'] ?? '');

        // Render internal DB connections don't need SSL; external ones do
        $ssl = (str_contains($host, 'render.com') || str_contains($host, 'amazonaws.com'))
             ? ';sslmode=require' : '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$ssl}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

    } else {
        // ── Priority 2: Individual env vars (local .env or production env) ──
        $host   = getenv('DB_HOST')     ?: getenv('PGHOST')     ?: 'localhost';
        $port   = getenv('DB_PORT')     ?: getenv('PGPORT')     ?: '5432';
        $dbname = getenv('DB_NAME')     ?: getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
        $user   = getenv('DB_USER')     ?: getenv('PGUSER')     ?: getenv('DB_USERNAME') ?: 'postgres';
        $pass   = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // ── Post-connect settings ────────────────────────────────────────────────
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec("SET search_path TO public");

    // ── Auto-migrations (safe: IF NOT EXISTS, silently skip if tables absent) 
    $migrations = [
        "ALTER TABLE users      ADD COLUMN IF NOT EXISTS verification_failed_attempts INTEGER DEFAULT 0",
        "ALTER TABLE users      ADD COLUMN IF NOT EXISTS verification_resend_count    INTEGER DEFAULT 0",
        "ALTER TABLE users      ADD COLUMN IF NOT EXISTS code_expiry                  TIMESTAMP",
        "ALTER TABLE users      ADD COLUMN IF NOT EXISTS account_status               VARCHAR(20) DEFAULT 'active'",
        "ALTER TABLE suppliers  ADD COLUMN IF NOT EXISTS is_active                    BOOLEAN DEFAULT true",
        "ALTER TABLE products   ADD COLUMN IF NOT EXISTS supplier_id                  INTEGER",
    ];
    foreach ($migrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $ignored) { /* table may not exist yet */ }
    }

} catch (PDOException $e) {
    $pdo                  = null;
    $db_connection_failed = true;
    $db_connection_error  = $e->getMessage();
    error_log('DB Connection Error: ' . $e->getMessage());
}

// ── Helper functions ─────────────────────────────────────────────────────────
if (!function_exists('isDBConnected')) {
    function isDBConnected(): bool {
        global $pdo;
        return $pdo instanceof PDO;
    }
}

if (!function_exists('getDBConnection')) {
    function getDBConnection(): ?PDO {
        global $pdo;
        return $pdo;
    }
}
