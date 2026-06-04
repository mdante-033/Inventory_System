<?php
/**
 * Health Check Endpoint for Deployment Monitoring
 * Render (or any deployment platform) can use this to verify the app is running
 * Returns JSON with status and diagnostic information
 */

header('Content-Type: application/json; charset=utf-8');

$health = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'database' => [
        'connected' => false,
        'error' => null,
    ],
    'services' => [],
];

// Check database connection
try {
    require_once __DIR__ . '/db_connect.php';
    
    if (isDBConnected()) {
        $pdo = getDBConnection();
        $result = $pdo->query('SELECT NOW() as server_time');
        $health['database']['connected'] = true;
        $health['database']['server_time'] = $result->fetchColumn();
    } else {
        $health['status'] = 'degraded';
        $health['database']['error'] = 'Database connection unavailable';
        http_response_code(503);
    }
} catch (Exception $e) {
    $health['status'] = 'degraded';
    $health['database']['connected'] = false;
    $health['database']['error'] = $e->getMessage();
    http_response_code(503);
}

// Check required extensions
$required_extensions = ['pdo', 'pdo_pgsql', 'zip'];
foreach ($required_extensions as $ext) {
    $health['services'][$ext] = extension_loaded($ext) ? 'available' : 'missing';
    if (!extension_loaded($ext)) {
        $health['status'] = 'degraded';
    }
}

// Check file permissions
$check_dirs = ['uploads', 'logs'];
foreach ($check_dirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    $health['services'][$dir] = is_writable($path) ? 'writable' : 'not_writable';
    if (!is_writable($path)) {
        $health['status'] = 'degraded';
    }
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
