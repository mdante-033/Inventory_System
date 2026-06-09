<?php
/**
 * db_connect.php - Render-compatible PostgreSQL connection
 *
 * Priority 1: DATABASE_URL  (Render sets this automatically when you
 *             link a PostgreSQL service to your web service)
 * Priority 2: Individual DB_* / PG* env vars
 * Priority 3: Local XAMPP defaults (localhost / Inventory_DB / postgres / Root)
 *
 * Errors are caught and stored — never produces a blank page.
 */

$pdo                  = $pdo ?? null;
$db_connection_failed = false;
$db_connection_error  = '';

try {
    if (!$pdo instanceof PDO) {

        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl && (
            str_starts_with($databaseUrl, 'postgres://') ||
            str_starts_with($databaseUrl, 'postgresql://')
        )) {
            /* ── Priority 1: DATABASE_URL ────────────────────────────
             *
             * Render always provides DATABASE_URL for linked Postgres
             * services. Both internal (dpg-…) and external hostnames
             * require SSL on Render — we always add sslmode=require
             * unless the host is literally localhost/127.0.0.1.
             */
            $p    = parse_url($databaseUrl);
            $host = $p['host']             ?? 'localhost';
            $port = (int) ($p['port']      ?? 5432);
            $db   = ltrim($p['path'] ?? '/', '/');
            $user = rawurldecode($p['user'] ?? '');
            $pass = rawurldecode($p['pass'] ?? '');

            $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            $ssl     = $isLocal ? '' : ';sslmode=require';

            $dsn = "pgsql:host={$host};port={$port};dbname={$db}{$ssl}";
            $pdo = new PDO($dsn, $user, $pass, $pdoOptions);

        } else {
            /* ── Priority 2 & 3: individual env vars / local defaults ─
             *
             * On Render without DATABASE_URL (shouldn't normally happen)
             * or on your local XAMPP machine where no env vars are set.
             */
            $host = getenv('DB_HOST')     ?: getenv('PGHOST')     ?: 'localhost';
            $port = getenv('DB_PORT')     ?: getenv('PGPORT')     ?: '5432';
            $db   = getenv('DB_NAME')     ?: getenv('PGDATABASE') ?: getenv('DB_DATABASE') ?: 'Inventory_DB';
            $user = getenv('DB_USER')     ?: getenv('PGUSER')     ?: getenv('DB_USERNAME') ?: 'postgres';
            $pass = getenv('DB_PASSWORD') ?: getenv('PGPASSWORD') ?: 'Root';

            $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            $ssl     = $isLocal ? '' : ';sslmode=require';

            $dsn = "pgsql:host={$host};port={$port};dbname={$db}{$ssl}";
            $pdo = new PDO($dsn, $user, $pass, $pdoOptions);
        }

        // Shared post-connect setup
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec("SET search_path TO public");

        // ── Auto-migrate: add missing columns silently ───────────────
        $migrations = [
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_failed_attempts INTEGER DEFAULT 0",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_resend_count INTEGER DEFAULT 0",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS code_expiry TIMESTAMP",
            "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT true",
        ];
        foreach ($migrations as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $ignored) {
                // Table may not exist yet — setup.php hasn't run yet
            }
        }
    }

} catch (PDOException $e) {
    $pdo                  = null;
    $db_connection_failed = true;

    // Safe error message — never expose raw DSN or credentials
    $msg = $e->getMessage();
    if (str_contains($msg, 'Connection refused')) {
        $db_connection_error = 'PostgreSQL is not running or the port is blocked. '
            . 'On Render: check that a PostgreSQL service is linked to this web service '
            . 'and that DATABASE_URL is set in Environment Variables.';
    } elseif (str_contains($msg, 'password authentication failed')) {
        $db_connection_error = 'Database credentials are incorrect. '
            . 'Check DB_USER and DB_PASSWORD (or DATABASE_URL) in your environment variables.';
    } elseif (str_contains($msg, 'does not exist')) {
        $db_connection_error = 'The database does not exist. '
            . 'Check the database name in DATABASE_URL or DB_NAME.';
    } elseif (str_contains($msg, 'SSL')) {
        $db_connection_error = 'SSL connection to the database failed. '
            . 'Ensure sslmode=require is set in DATABASE_URL on Render.';
    } else {
        $db_connection_error = 'Database connection failed. '
            . 'Check DATABASE_URL in your Render Environment Variables.';
    }

    error_log('[db_connect] PostgreSQL connection error: ' . $msg);
}

/**
 * Returns the active PDO connection, or null if unavailable.
 */
function getDBConnection(): ?PDO
{
    global $pdo;
    return $pdo;
}

/**
 * Returns true if the database connected successfully.
 */
function isDBConnected(): bool
{
    global $pdo;
    return $pdo instanceof PDO;
}
