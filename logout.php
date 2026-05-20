<?php
/**
 * Logout Script
 * Destroys session and redirects to the homepage
 * 
 * @package InventorySystem
 */

require_once __DIR__ . '/includes/session.php';

$wasLoggedIn = !empty($_SESSION['logged_in']) || !empty($_SESSION['user_id']);

destroySession();
$_SESSION['flash_success'] = $wasLoggedIn
    ? 'You have been logged out successfully.'
    : 'You are already signed out.';

header('Location: index.php');
exit;
