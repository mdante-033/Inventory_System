<?php
require_once __DIR__ . '/db_connect.php';

$username = 'admin';                 // change if you want
$email    = 'admin@yourdomain.com';  // any email
$password = 'YourStrongPassword123'; // replace with your own

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (username, email, password, full_name, role, is_active, is_verified, account_status)
    VALUES (:u, :e, :p, 'Administrator', 'admin', true, true, 'active')
    ON CONFLICT (username) DO UPDATE SET password = :p2, role = 'admin', is_active = true, is_verified = true, account_status = 'active'
");
$stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash, ':p2' => $hash]);

echo "Admin user ready. DELETE THIS FILE NOW!";
