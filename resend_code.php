<?php
/**
 * POST handler for the "Resend Code" button on verify_code.php.
 */
require_once __DIR__ . '/includes/session.php';
/**
 * resend_code.php  –  POST handler for "Resend Code" button on verify_code.php
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/account_verification_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: verify_code.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['verify_csrf'] ?? '', $csrfToken)) {
    $_SESSION['flash_error'] = 'Session expired. Refresh the page and try again.';
    header('Location: verify_code.php');
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $_SESSION['flash_error'] = isset($db_connection_error) ? $db_connection_error
                             : 'Database connection is unavailable right now.';
    header('Location: verify_code.php');
    exit;
}

ensureUsersRegistrationSchema($pdo);

$pendingUserId = getPendingVerificationUserId();
if ($pendingUserId <= 0) {
    $_SESSION['flash_error'] = 'Your verification session has expired. Please register again.';
    header('Location: register.php');
    exit;
}

$user = fetchVerificationUserById($pdo, $pendingUserId);
if (!$user) {
    clearPendingVerificationSession();
    $_SESSION['flash_error'] = 'We could not find your account. Please register again.';
    header('Location: register.php');
    exit;
}

// Force a fresh code on every resend
$result = sendAccountVerificationCode($pdo, $user, true);

if ($result['success']) {
    $_SESSION['flash_success'] = $result['message'];
} else {
    $_SESSION['flash_error'] = $result['message'];
}

header('Location: verify_code.php');
exit;
