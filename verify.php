<?php
require_once __DIR__ . '/includes/session.php';

$_SESSION['flash_success'] = 'Enter the 6-digit verification code from your email to activate your account.';
header('Location: verify_code.php');
exit;
