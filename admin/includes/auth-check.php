<?php
/**
 * Admin Authentication Check
 * Fixed: POST/AJAX requests now get JSON errors instead of redirect responses,
 * which was causing all admin buttons to silently fail.
 */
require_once __DIR__ . '/../../includes/session.php';

/* ── Helper: detect AJAX / fetch POST so we return JSON not a redirect ── */
function _isAjaxOrPost(): bool {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return true;
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strtolower($xrw) === 'xmlhttprequest';
}

function _authJsonError(string $message, int $code = 401): void {
    // Discard any buffered output so JSON stays clean
    if (ob_get_level() > 0) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function _authRedirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/* ── 1. Must be logged in ─────────────────────────────────────────────── */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (_isAjaxOrPost()) {
        _authJsonError('Your session has expired. Please refresh the page and log in again.', 401);
    }
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    _authRedirect((defined('APP_URL') ? APP_URL : '') . '/login.php');
}

/* ── 2. Session timeout ───────────────────────────────────────────────── */
if (isset($_SESSION['last_activity'])) {
    $timeout = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 1800;
    if ((time() - $_SESSION['last_activity']) > $timeout) {
        destroySession();
        if (_isAjaxOrPost()) {
            _authJsonError('Your session timed out. Please refresh the page and log in again.', 401);
        }
        _authRedirect((defined('APP_URL') ? APP_URL : '') . '/login.php?timeout=1');
    }
}
$_SESSION['last_activity'] = time();

/* ── 3. Role check ───────────────────────────────────────────────────── */
$allowedRoles = ['admin', 'manager'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles, true)) {
    if (_isAjaxOrPost()) {
        _authJsonError('Access denied. You do not have permission to perform this action.', 403);
    }
    http_response_code(403);
    echo 'Access denied';
    exit;
}

/* ── 4. IP binding (only if both helper functions exist) ─────────────── */
if (function_exists('shouldBindSessionToIp') && function_exists('currentSessionIp')) {
    if (shouldBindSessionToIp()
        && isset($_SESSION['ip_address'])
        && !hash_equals((string) $_SESSION['ip_address'], currentSessionIp())
    ) {
        destroySession();
        if (_isAjaxOrPost()) {
            _authJsonError('Security check failed. Please log in again.', 401);
        }
        _authRedirect((defined('APP_URL') ? APP_URL : '') . '/login.php?error=security');
    }
}

/* ── 5. checkAdminPermission ─────────────────────────────────────────── */
if (!function_exists('checkAdminPermission')) {
    function checkAdminPermission(string $requiredPermission): bool {
        $role = $_SESSION['role'] ?? 'guest';
        if ($role === 'admin') return true;

        $permissions = [
            'manager' => [
                'users.view', 'users.edit',
                'products.manage', 'products.add', 'products.edit',
                'categories.manage',
                'stock.manage',
                'orders.manage',
                'reports.view', 'reports.export',
                'analytics.view',
            ],
            'staff' => [
                'products.view', 'products.add', 'products.edit',
                'stock.view', 'stock.manage',
                'orders.view', 'orders.create',
            ],
        ];

        $userPermissions = $permissions[$role] ?? [];
        return in_array('*', $userPermissions, true)
            || in_array($requiredPermission, $userPermissions, true);
    }
}

/* ── 6. getCurrentAdminUser ──────────────────────────────────────────── */
if (!function_exists('getCurrentAdminUser')) {
    function getCurrentAdminUser(): array {
        return [
            'id'        => $_SESSION['user_id']  ?? null,
            'username'  => $_SESSION['username']  ?? null,
            'email'     => $_SESSION['email']     ?? null,
            'full_name' => $_SESSION['full_name'] ?? null,
            'role'      => $_SESSION['role']      ?? null,
        ];
    }
}
