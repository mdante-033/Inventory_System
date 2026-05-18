<?php
/**
 * db_connect.php - Render-compatible database connection
 * Never produces a blank page: all errors are caught and stored in
 * $db_connection_error so pages can display a friendly message.
 */

$pdo                  = $pdo ?? null;
$db_connection_failed = false;
$db_connection_error  = '';

try {
    if (!$pdo instanceof PDO) {

        // ── Priority 1: DATABASE_URL (Render sets this automatically
        //    when you link a PostgreSQL service to your web service)
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl && str_starts_with($databaseUrl, 'postgres')) {
            $p      = parse_url($databaseUrl);
            $host   = $p['host']            ?? 'localhost';
            $port   = $p['port']            ?? 5432;
            $dbname = ltrim($p['path'] ?? '/', '/');
            $user   = $p['user']            ?? '';
            $pass   = $p['pass']            ?? '';

            // Internal Render URLs don't need SSL; external ones do
            $ssl = str_contains($host, 'render.com') ? ';sslmode=require' : '';
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$ssl}";

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

        } else {
            // ── Priority 2: Individual env vars
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
    }

    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec("SET search_path TO public");

    // ── Auto-migrate: add any missing columns silently ──────────
    $migrations = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_failed_attempts INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_resend_count INTEGER DEFAULT 0",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS code_expiry TIMESTAMP",
        "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT true",
    ];
    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $ignored) {
            // Table may not exist yet — setup.php hasn't run
        }
    }

} catch (\PDOException $e) {
    $pdo                  = null;
    $db_connection_failed = true;
    $db_connection_error  = 'Database connection failed. Check DATABASE_URL in your Render environment variables.';
    error_log('PostgreSQL Connection Error: ' . $e->getMessage());
}

/**
 * Returns the PDO connection, or null if unavailable.
 */
function getDBConnection(): ?\PDO {
    global $pdo;
    return $pdo;
}

/**
 * Returns true if the database is connected.
 */
function isDBConnected(): bool {
    global $pdo;
    return $pdo instanceof \PDO;
}
