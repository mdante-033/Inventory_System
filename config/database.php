<?php

if (!function_exists('getEnvVar')) {
    /**
     * Get environment variable with fallback.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function getEnvVar($key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (is_string($value)) {
            $lowerValue = strtolower($value);
            if ($lowerValue === 'true') {
                return true;
            }
            if ($lowerValue === 'false') {
                return false;
            }
            if ($lowerValue === 'null') {
                return null;
            }
        }

        return $value;
    }
}

if (!function_exists('getDbEnvVar')) {
    /**
     * Resolve modern and legacy DB env names.
     *
     * @param string $primaryKey
     * @param string|null $legacyKey
     * @param mixed $default
     * @return mixed
     */
    function getDbEnvVar(string $primaryKey, ?string $legacyKey, $default = null)
    {
        $primaryValue = getEnvVar($primaryKey, null);
        if ($primaryValue !== null) {
            return $primaryValue;
        }

        if ($legacyKey !== null) {
            $legacyValue = getEnvVar($legacyKey, null);
            if ($legacyValue !== null) {
                return $legacyValue;
            }
        }

        return $default;
    }
}

$dbHost = (string) getDbEnvVar('DB_HOST', null, 'localhost');
$dbPort = (string) getDbEnvVar('DB_PORT', null, '5432');
$dbName = (string) getDbEnvVar('DB_NAME', 'DB_DATABASE', 'Inventory_DB');
$dbUser = (string) getDbEnvVar('DB_USER', 'DB_USERNAME', 'postgres');
$dbPassword = getenv('DB_PASSWORD');
if ($dbPassword === false || $dbPassword === null) {
    $dbPassword = in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true)
        ? 'Root'
        : '';
}

require_once __DIR__ . '/../db_connect.php';

$db_connection_failed = $db_connection_failed ?? false;
$db_connection_error = $db_connection_error ?? '';

$config = [
    'default' => getEnvVar('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getEnvVar('DB_HOST', '127.0.0.1'),
            'port' => getEnvVar('DB_PORT', '3306'),
            'database' => $dbName,
            'username' => getEnvVar('DB_USERNAME', 'root'),
            'password' => getEnvVar('DB_PASSWORD', ''),
            'unix_socket' => getEnvVar('DB_SOCKET', ''),
            'charset' => getEnvVar('DB_CHARSET', 'utf8mb4'),
            'collation' => getEnvVar('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => getEnvVar('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'strict' => (bool) getEnvVar('DB_STRICT_MODE', true),
            'engine' => getEnvVar('DB_ENGINE', 'InnoDB'),
            'timezone' => getEnvVar('DB_TIMEZONE', '+00:00'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
                PDO::MYSQL_ATTR_SSL_CA => getEnvVar('DB_SSL_CA'),
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => (bool) getEnvVar('DB_SSL_VERIFY', false),
            ]) : [],
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => $dbHost,
            'port' => $dbPort,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPassword,
            'charset' => getEnvVar('DB_CHARSET', 'utf8'),
            'prefix' => getEnvVar('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'schema' => getEnvVar('DB_SCHEMA', 'public'),
            'sslmode' => getEnvVar('DB_SSLMODE', 'prefer'),
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getEnvVar('DB_DATABASE', __DIR__ . '/../database/database.sqlite'),
            'prefix' => getEnvVar('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'foreign_key_constraints' => (bool) getEnvVar('DB_FOREIGN_KEYS', true),
        ],
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'host' => getEnvVar('DB_HOST', 'localhost'),
            'port' => getEnvVar('DB_PORT', '1433'),
            'database' => $dbName,
            'username' => getEnvVar('DB_USERNAME', 'sa'),
            'password' => getEnvVar('DB_PASSWORD', ''),
            'charset' => getEnvVar('DB_CHARSET', 'utf8'),
            'prefix' => getEnvVar('DB_PREFIX', ''),
            'prefix_indexes' => true,
            'encrypt' => getEnvVar('DB_ENCRYPT', 'no'),
            'trust_server_certificate' => (bool) getEnvVar('DB_TRUST_SERVER_CERTIFICATE', false),
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];

return $config;
