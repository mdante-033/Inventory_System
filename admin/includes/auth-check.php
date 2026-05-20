<?php
/**
 * Admin Authentication Check
 * Ensures user is logged in and has admin privileges
 */

require_once __DIR__ . '/../../includes/session.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Store intended URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    
    // Redirect to login page
    header('Location: ' . (defined('APP_URL') ? APP_URL : '') . '/login.php');
    exit;
}

// Check session timeout
if (isset($_SESSION['last_activity'])) {
    $timeout = 1800; // 30 minutes
    $elapsed = time() - $_SESSION['last_activity'];
    
    if ($elapsed > $timeout) {
        // Session expired
        destroySession();
        header('Location: ' . (defined('APP_URL') ? APP_URL : '') . '/login.php?timeout=1');
        exit;
    }
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Check if user has admin role (optional - can be removed if you want managers to access admin)
$allowedRoles = ['admin', 'manager'];

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
    // User doesn't have permission
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit;
}

// Additional security check - verify IP address (optional)
if (shouldBindSessionToIp() && isset($_SESSION['ip_address']) && !hash_equals((string) $_SESSION['ip_address'], currentSessionIp())) {
    // IP address changed - possible session hijacking
    destroySession();
    header('Location: ' . (defined('APP_URL') ? APP_URL : '') . '/login.php?error=security');
    exit;
}

// Function to check specific permission
function checkAdminPermission($requiredPermission) {
    $role = $_SESSION['role'] ?? 'guest';
    
    // Admin has all permissions
    if ($role === 'admin') {
        return true;
    }
    
    // Define role-based permissions
    $permissions = [
        'admin' => ['*'],
        'manager' => [
            'users.view', 'users.edit',
            'products.manage', 'products.add', 'products.edit',
            'categories.manage',
            'stock.manage',
            'orders.manage',
            'reports.view', 'reports.export',
            'analytics.view'
        ],
        'staff' => [
            'products.view', 'products.add', 'products.edit',
            'stock.view', 'stock.manage',
            'orders.view', 'orders.create'
        ]
    ];
    
    $userPermissions = $permissions[$role] ?? [];
    
    // Check if user has the permission
    return in_array('*', $userPermissions) || in_array($requiredPermission, $userPermissions);
}

// Helper function to get current user info
function getCurrentAdminUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}
