<?php
/**
 * resend_code.php  –  POST handler: generates / resends a verification code
 *
 * This file is posted to by the "Resend Code" button on verify_code.php.
 * It:
 *   1. Validates the CSRF token.
 *   2. Looks up the pending user from the session.
 *   3. Calls sendAccountVerificationCode() which sends via SMTP port 25.
 *   4. Redirects back to verify_code.php with a flash message.
 */

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/account_verification_helper.php';

// ── Only accept POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: verify_code.php');
    exit;
}

// ── CSRF check ───────────────────────────────────────────────────────────────
$csrfToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['verify_csrf'] ?? '', $csrfToken)) {
    $_SESSION['flash_error'] = 'Your session expired. Refresh the verification page and try again.';
    header('Location: verify_code.php');
    exit;
}

// ── Database availability ────────────────────────────────────────────────────
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $_SESSION['flash_error'] = isset($db_connection_error)
        ? $db_connection_error
        : 'Database connection is unavailable right now.';
    header('Location: verify_code.php');
    exit;
}

// ── Ensure schema is up-to-date ──────────────────────────────────────────────
ensureUsersRegistrationSchema($pdo);

// ── Retrieve pending user id from session ────────────────────────────────────
$pendingUserId = getPendingVerificationUserId();
if ($pendingUserId <= 0) {
    $_SESSION['flash_error'] = 'Your verification session has expired. Please register again.';
    header('Location: register.php');
    exit;
}

// ── Load the user row ────────────────────────────────────────────────────────
$user = fetchVerificationUserById($pdo, $pendingUserId);
if (!$user) {
    clearPendingVerificationSession();
    $_SESSION['flash_error'] = 'We could not find your account. Please register again.';
    header('Location: register.php');
    exit;
}

// ── Send / resend the verification code via SMTP port 25 ─────────────────────
// Pass $forceNew = true so a fresh code is always generated on resend.
$result = sendAccountVerificationCode($pdo, $user, true);

if ($result['success']) {
    $_SESSION['flash_success'] = $result['message'];
} else {
    $_SESSION['flash_error'] = $result['message'];
}

header('Location: verify_code.php');
exit;
