<?php
/**
 * admin_debug.php — Drop this in your root, visit it once while logged in
 * to see exactly what's failing in the POST handler.
 * DELETE after debugging.
 */
session_start();
header('Content-Type: text/plain');

echo "=== ADMIN POST DEBUG ===\n\n";

// Simulate what admin.php checks
echo "SESSION user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
echo "SESSION role: "    . ($_SESSION['role']    ?? 'NOT SET') . "\n\n";

// Test CSRF
require_once __DIR__ . '/includes/functions.php';
$token = generateCSRFToken();
echo "Generated CSRF token: " . $token . "\n";
echo "Token valid: " . (validateCSRFToken($token) ? 'YES' : 'NO') . "\n\n";

// Check db
require_once __DIR__ . '/db_connect.php';
echo "DB connected: " . (isDBConnected() ? 'YES' : 'NO') . "\n";

if (isDBConnected()) {
    global $pdo;
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = true")->fetchColumn();
        echo "Active products: $count\n";
    } catch (Exception $e) {
        echo "Products query error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== MODULE FILES ===\n";
$modules = ['dashboard','products','inventory','orders','customers','suppliers','reports','settings','test'];
foreach ($modules as $m) {
    $path = __DIR__ . "/modules/{$m}.php";
    echo ($path && file_exists($path) ? "OK" : "MISSING") . ": modules/{$m}.php\n";
}

echo "\n=== FETCH PATTERN CHECK ===\n";
echo "Checking if modules call admin.php correctly...\n";
foreach ($modules as $m) {
    $path = __DIR__ . "/modules/{$m}.php";
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    // Check for common fetch patterns
    if (str_contains($content, "fetch('admin.php'") || str_contains($content, 'fetch("admin.php"')) {
        echo "OK fetch target: modules/{$m}.php → admin.php\n";
    } elseif (str_contains($content, 'fetch(')) {
        // Extract the fetch URL
        preg_match_all("/fetch\(['\"]([^'\"]+)['\"]/", $content, $matches);
        foreach ($matches[1] as $url) {
            echo "fetch target in {$m}.php: $url\n";
        }
    }
    // Check for action field
    if (str_contains($content, "'action'") || str_contains($content, '"action"')) {
        echo "  has action field: YES\n";
    }
    // Check for JSON body (this breaks $_POST)
    if (str_contains($content, 'JSON.stringify') && str_contains($content, "Content-Type': 'application/json")) {
        echo "  WARNING: uses JSON body — \$_POST['action'] will be EMPTY on server!\n";
    }
}
