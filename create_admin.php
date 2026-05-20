<?php
/**
 * create_admin.php – Creates/resets the admin user.
 * RUN ONCE, THEN DELETE THIS FILE IMMEDIATELY.
 */

require_once __DIR__ . '/db_connect.php';

// ── SET YOUR OWN CREDENTIALS HERE ──
$adminUser     = 'admin';                 // username for login
$adminEmail    = 'admin@example.com';     // any email
$adminPassword = 'YourStrongPassword123'; // choose a strong password

// Hash the password securely
$hash = password_hash($adminPassword, PASSWORD_DEFAULT);

// Insert or update the admin user
$sql = "
    INSERT INTO users (username, email, password, full_name, role, is_active, is_verified, account_status)
    VALUES (:u, :e, :p, 'Administrator', 'admin', true, true, 'active')
    ON CONFLICT (username) DO UPDATE
        SET password = :p2,
            role = 'admin',
            is_active = true,
            is_verified = true,
            account_status = 'active'
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':u'  => $adminUser,
    ':e'  => $adminEmail,
    ':p'  => $hash,
    ':p2' => $hash,
]);

echo " Admin user '<b>" . htmlspecialchars($adminUser) . "</b>' is ready.<br>";
echo " <b>DELETE this file NOW</b> for security!";
