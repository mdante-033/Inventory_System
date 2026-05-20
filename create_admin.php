<?php
/**
 * create_admin.php
 * ─────────────────────────────────────────────────────────
 * Run ONCE by visiting:
 *   https://inventory-system-1-tggc.onrender.com/create_admin.php
 *
 * !! DELETE THIS FILE FROM GITHUB IMMEDIATELY AFTER USE !!
 * ─────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/db_connect.php';

// ── CHANGE THESE THREE VALUES BEFORE UPLOADING ────────────────────────────
$username = 'admin';
$email    = 'admin@example.com';
$password = 'Admin@1234!';          // ← pick a strong password
// ─────────────────────────────────────────────────────────────────────────

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('<h2 style="color:red"> Database connection failed. Check your DATABASE_URL environment variable on Render.</h2>');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Ensure users table exists with the minimum required columns
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            SERIAL PRIMARY KEY,
            username      VARCHAR(50)  UNIQUE NOT NULL,
            email         VARCHAR(100) UNIQUE NOT NULL,
            password      VARCHAR(255) NOT NULL,
            full_name     VARCHAR(100) NOT NULL DEFAULT 'Administrator',
            role          VARCHAR(20)  NOT NULL DEFAULT 'admin',
            is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
            is_verified   BOOLEAN      NOT NULL DEFAULT TRUE,
            account_status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Insert or update — safe to run multiple times
    $stmt = $pdo->prepare("
        INSERT INTO users
            (username, email, password, full_name, role, is_active, is_verified, account_status)
        VALUES
            (:u, :e, :p, 'Administrator', 'admin', TRUE, TRUE, 'active')
        ON CONFLICT (username)
        DO UPDATE SET
            password       = EXCLUDED.password,
            email          = EXCLUDED.email,
            role           = 'admin',
            is_active      = TRUE,
            is_verified    = TRUE,
            account_status = 'active',
            updated_at     = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash]);

    // Verify the user was actually saved
    $check = $pdo->prepare("SELECT id, username, email, role, is_verified, account_status FROM users WHERE username = :u");
    $check->execute([':u' => $username]);
    $user = $check->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo '<div style="font-family:monospace;background:#1a1a2e;color:#00ff88;padding:40px;border-radius:12px;max-width:600px;margin:60px auto;">';
        echo '<h2>✅ Admin user ready</h2>';
        echo '<table style="color:#ccc;margin-top:20px;border-collapse:collapse;width:100%">';
        echo '<tr><td style="padding:8px;color:#aaa">ID</td><td style="padding:8px">' . $user['id'] . '</td></tr>';
        echo '<tr><td style="padding:8px;color:#aaa">Username</td><td style="padding:8px">' . htmlspecialchars($user['username']) . '</td></tr>';
        echo '<tr><td style="padding:8px;color:#aaa">Email</td><td style="padding:8px">' . htmlspecialchars($user['email']) . '</td></tr>';
        echo '<tr><td style="padding:8px;color:#aaa">Role</td><td style="padding:8px">' . htmlspecialchars($user['role']) . '</td></tr>';
        echo '<tr><td style="padding:8px;color:#aaa">Verified</td><td style="padding:8px">' . ($user['is_verified'] ? 'Yes' : 'No') . '</td></tr>';
        echo '<tr><td style="padding:8px;color:#aaa">Status</td><td style="padding:8px">' . htmlspecialchars($user['account_status']) . '</td></tr>';
        echo '</table>';
        echo '<div style="margin-top:30px;padding:20px;background:#0d0d1a;border-radius:8px;border:1px solid #ff4444">';
        echo '<strong style="color:#ff4444">⚠️ SECURITY — DO THIS NOW:</strong><br><br>';
        echo '1. Go to your GitHub repo<br>';
        echo '2. Delete <code>create_admin.php</code><br>';
        echo '3. Commit the deletion<br>';
        echo '4. Then login at: <a href="login.php" style="color:#00aaff">login.php</a>';
        echo '</div>';
        echo '<div style="margin-top:20px;padding:16px;background:#0d1a0d;border-radius:8px;border:1px solid #00ff88">';
        echo '<strong>Login credentials:</strong><br>';
        echo 'Username: <strong>' . htmlspecialchars($username) . '</strong><br>';
        echo 'Password: <strong>' . htmlspecialchars($password) . '</strong>';
        echo '</div>';
        echo '</div>';
    } else {
        echo '<h2 style="color:orange"> Insert ran but user not found in DB. Check your DB connection.</h2>';
    }

} catch (PDOException $e) {
    echo '<div style="font-family:monospace;background:#1a0000;color:#ff6666;padding:40px;border-radius:12px;max-width:600px;margin:60px auto;">';
    echo '<h2> Database Error</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p style="color:#aaa;margin-top:20px">Common causes:<br>';
    echo '• DATABASE_URL not set in Render environment<br>';
    echo '• Wrong DB credentials<br>';
    echo '• users table has constraint violation</p>';
    echo '</div>';
}