<?php
// create_admin.php – RUN ONCE, THEN DELETE IMMEDIATELY
require_once __DIR__ . '/db_connect.php';

$username = 'admin';          // change as you like
$email    = 'admin@example.com';
$password = 'YourStrongPassword123!';  // REPLACE with your own password

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, is_active, is_verified, account_status)
                       VALUES (:u, :e, :p, 'Administrator', 'admin', true, true, 'active')");
$stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash]);

echo "Admin user created. Now DELETE this file!";
