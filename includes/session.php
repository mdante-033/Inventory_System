<?php
/**
 * Session Management
 * Handles secure session initialization, validation, and teardown.
 */

// Set conservative defaults before the session is started. The full cookie
// policy is applied in configureSecureSession().
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_samesite', 'Strict');

// Load security headers before any output.
require_once __DIR__ . '/security_headers.php';

function inventorySessionIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (($_SERVER['SERVER_PORT'] ?? null) === '443') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if (in_array('https', array_map('trim', explode(',', $forwardedProto)), true)) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

function inventorySessionCookieName(): string
{
    $configuredName = trim((string) getenv('SESSION_COOKIE_NAME'));

    if ($configuredName !== '') {
        return preg_replace('/[^A-Za-z0-9_,.-]/', '', $configuredName) ?: 'INVSYSSESSID';
    }

    return inventorySessionIsHttps() ? '__Secure-INVSYS' : 'INVSYSSESSID';
}

function inventorySessionSameSite(): string
{
    $sameSite = ucfirst(strtolower((string) (getenv('SESSION_SAMESITE') ?: 'Strict')));
    return in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Strict';
}

function configureSecureSession(): void
{
    static $configured = false;

    if ($configured || session_status() !== PHP_SESSION_NONE || headers_sent()) {
        return;
    }

    $secure = inventorySessionIsHttps();
    $sameSite = inventorySessionSameSite();

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', $sameSite);
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', (string) getSessionAbsoluteTimeout());

    session_name(inventorySessionCookieName());
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    $configured = true;
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    configureSecureSession();
    session_start();
}

function getSessionIdleTimeout(): int
{
    $configured = (int) (getenv('SESSION_IDLE_TIMEOUT') ?: 0);
    if ($configured > 0) {
        return $configured;
    }

    return defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 1800;
}

function getSessionAbsoluteTimeout(): int
{
    $configured = (int) (getenv('SESSION_ABSOLUTE_TIMEOUT') ?: 0);
    return $configured > 0 ? $configured : 3600;
}

function getSessionRotationInterval(): int
{
    $configured = (int) (getenv('SESSION_ROTATE_INTERVAL') ?: 0);
    return $configured > 0 ? $configured : 300;
}

function shouldBindSessionToIp(): bool
{
    return filter_var(getenv('SESSION_BIND_IP') ?: false, FILTER_VALIDATE_BOOLEAN);
}

function currentSessionUserAgent(): string
{
    if (function_exists('getUserAgent')) {
        return getUserAgent();
    }

    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

function currentSessionIp(): string
{
    if (function_exists('getUserIP')) {
        return getUserIP();
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function currentSessionFingerprint(): string
{
    return hash('sha256', currentSessionUserAgent());
}

function refreshSessionSecurityMetadata(): void
{
    $_SESSION['created'] = $_SESSION['created'] ?? time();
    $_SESSION['last_activity'] = time();
    $_SESSION['_last_regenerated'] = $_SESSION['_last_regenerated'] ?? time();
    $_SESSION['user_agent'] = currentSessionUserAgent();
    $_SESSION['session_fingerprint'] = currentSessionFingerprint();
    $_SESSION['ip_address'] = currentSessionIp();
    $_SESSION['initiated'] = true;
}

function rotateSessionCsrfToken(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['register_csrf'], $_SESSION['verify_csrf'], $_SESSION['admin_csrf_token']);
}

/**
 * Initialize session with security checks
 */
function initSession(): bool {
    startSecureSession();

    $now = time();

    if (isset($_SESSION['user_id'], $_SESSION['session_fingerprint'])
        && !hash_equals((string) $_SESSION['session_fingerprint'], currentSessionFingerprint())) {
        destroySession();
        return false;
    }

    if (isset($_SESSION['user_id'], $_SESSION['user_agent'])
        && !hash_equals((string) $_SESSION['user_agent'], currentSessionUserAgent())) {
        destroySession();
        return false;
    }

    if (shouldBindSessionToIp()
        && isset($_SESSION['user_id'], $_SESSION['ip_address'])
        && !hash_equals((string) $_SESSION['ip_address'], currentSessionIp())) {
        destroySession();
        return false;
    }
    
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        refreshSessionSecurityMetadata();
    }
    
    if (isset($_SESSION['last_activity'])) {
        $elapsed = $now - (int) $_SESSION['last_activity'];
        if ($elapsed > getSessionIdleTimeout()) {
            destroySession();
            return false;
        }
    }

    if (isset($_SESSION['created']) && ($now - (int) $_SESSION['created']) > getSessionAbsoluteTimeout()) {
        destroySession();
        return false;
    }

    if (isset($_SESSION['user_id'], $_SESSION['_last_regenerated'])
        && ($now - (int) $_SESSION['_last_regenerated']) > getSessionRotationInterval()) {
        session_regenerate_id(false);
        $_SESSION['_last_regenerated'] = $now;
    }

    refreshSessionSecurityMetadata();

    return true;
}

/**
 * Create session for logged in user
 */
function createUserSession(array $user): bool {
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    
    // Clear any existing session data
    $_SESSION = [];
    
    // Set user data
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    refreshSessionSecurityMetadata();
    
    $_SESSION['session_token'] = bin2hex(random_bytes(32));
    rotateSessionCsrfToken();
    
    return true;
}

/**
 * Destroy session
 */
function destroySession(bool $startFresh = true): void {
    // Clear session data
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? inventorySessionSameSite(),
        ]);
    }
    
    // Destroy session
    session_destroy();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // Start new session
    if ($startFresh && !headers_sent()) {
        startSecureSession();
        session_regenerate_id(true);
        refreshSessionSecurityMetadata();
    }
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['logged_in']) && 
           $_SESSION['logged_in'] === true && 
           isset($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function getCurrentUserId(): int|string|null {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current username
 */
function getCurrentUsername(): ?string {
    return $_SESSION['username'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole(): string {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * Check if user has specific role
 */
function hasRole(string $role): bool {
    return getCurrentUserRole() === $role;
}

/**
 * Check if user is admin
 */
function isAdmin(): bool {
    return hasRole('admin');
}

/**
 * Check if user is manager or admin
 */
function isManager(): bool {
    $role = getCurrentUserRole();
    return $role === 'admin' || $role === 'manager';
}

/**
 * Get session data
 */
function getSessionData(string|int $key, mixed $default = null): mixed {
    return $_SESSION[$key] ?? $default;
}

/**
 * Set session data
 */
function setSessionData(string|int $key, mixed $value): void {
    $_SESSION[$key] = $value;
}

/**
 * Flash session data (stored temporarily)
 */
function flash(string|int $key, mixed $value = null): mixed {
    if ($value === null) {
        // Get and clear flash data
        if (isset($_SESSION['flash'][$key])) {
            $value = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $value;
        }
        return null;
    }
    
    // Set flash data
    $_SESSION['flash'][$key] = $value;
    return true;
}

/**
 * Get all flash data
 */
function getAllFlashData(): array {
    $flashData = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashData;
}

/**
 * Validate session token
 */
function validateSessionToken(?string $token): bool {
    return is_string($token)
        && isset($_SESSION['session_token'])
        && is_string($_SESSION['session_token'])
        && hash_equals($_SESSION['session_token'], $token);
}

/**
 * Get session info
 */
function getSessionInfo(): array {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'login_time' => $_SESSION['login_time'] ?? null,
        'last_activity' => $_SESSION['last_activity'] ?? null,
        'ip_address' => $_SESSION['ip_address'] ?? null,
        'session_token' => $_SESSION['session_token'] ?? null
    ];
}

/**
 * Extend session
 */
function extendSession(): bool {
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Get session remaining time
 */
function getSessionRemainingTime(): int {
    if (!isset($_SESSION['last_activity'])) {
        return 0;
    }
    
    $elapsed = time() - $_SESSION['last_activity'];
    return max(0, getSessionIdleTimeout() - $elapsed);
}

/**
 * Set session message (for displaying to user)
 */
function setMessage(string $type, string $message): void {
    $_SESSION['messages'][$type] = $message;
}

/**
 * Get and clear session messages
 */
function getMessages(): array {
    $messages = $_SESSION['messages'] ?? [];
    unset($_SESSION['messages']);
    return $messages;
}

/**
 * Check if user IP changed (security)
 */
function checkIPChange(): bool {
    if (!isset($_SESSION['ip_address'])) {
        return true;
    }
    
    return hash_equals((string) $_SESSION['ip_address'], currentSessionIp());
}

// Initialize session on include
initSession();
