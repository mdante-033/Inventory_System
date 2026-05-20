<?php
/**
 * Login page with secure session and CSRF handling.
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

$baseDir = __DIR__;
if (!file_exists($baseDir . '/db_connect.php')) {
    die('<h2 style="font-family:sans-serif;color:#c0392b">Error: db_connect.php not found.<br>Check your file structure.</h2>');
}

require_once $baseDir . '/db_connect.php';
/** @var PDO|null $pdo */
require_once $baseDir . '/includes/account_verification_helper.php';
require_once $baseDir . '/includes/logger.php';

function redirectPathForRole(string $role): string
{
    return in_array($role, ['admin', 'manager'], true) ? 'admin.php' : 'customer_dashboard.php';
}

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    $role = (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');
    header('Location: ' . redirectPathForRole($role));
    exit;
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$errors = [];
$inputUsername = '';
$csrfToken = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrfToken = $_POST['csrf_token'] ?? '';
    $inputUsername = trim($_POST['login'] ?? ($_POST['username'] ?? ''));
    $inputPassword = $_POST['password'] ?? '';

    Logger::info('Login attempt received', [
        'login' => $inputUsername,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    if (!validateCSRFToken((string) $postedCsrfToken)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
        Logger::warn('Login blocked by invalid CSRF token', [
            'login' => $inputUsername,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } elseif ($inputUsername === '' || $inputPassword === '') {
        $errors[] = 'Please enter both your email or username and password.';
    } elseif (!($pdo instanceof PDO)) {
        $errors[] = $db_connection_error ?: 'Database connection is unavailable. Please try again later.';
        Logger::error('Database connection unavailable during login', [
            'error' => $db_connection_error ?? 'unknown',
        ]);
    } else {
        try {
            ensureUsersRegistrationSchema($pdo);

            $stmt = $pdo->prepare("
                SELECT id, username, password, email, full_name, role, is_verified, account_status
                FROM users
                WHERE (LOWER(username) = LOWER(:login) OR LOWER(email) = LOWER(:login))
                  AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute(['login' => $inputUsername]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($inputPassword, $user['password'])) {
                $errors[] = 'Invalid email, username, or password.';
                Logger::warn('Invalid login credentials', [
                    'login' => $inputUsername,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
            } elseif (($user['account_status'] ?? 'active') === 'suspended') {
                $errors[] = 'Your account is suspended. Please contact support.';
                Logger::warn('Login blocked - account suspended', [
                    'login' => $inputUsername,
                    'user_id' => $user['id'],
                ]);
            } elseif (!isAccountVerified($user['is_verified']) || ($user['account_status'] ?? 'pending') === 'pending') {
                session_regenerate_id(true);
                refreshSessionSecurityMetadata();
                rotateCSRFToken();
                setPendingVerificationSession($user);
                $_SESSION['flash_success'] = 'Your account is not verified yet. Enter the 6-digit code we sent to your email.';
                Logger::info('Login requires verification', [
                    'login' => $inputUsername,
                    'user_id' => $user['id'],
                ]);
                header('Location: verify_code.php');
                exit;
            } else {
                createUserSession($user);

                $updateStmt = $pdo->prepare('UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $updateStmt->execute(['id' => $user['id']]);

                Logger::info('Login successful', [
                    'login' => $inputUsername,
                    'user_id' => $user['id'],
                    'role' => $user['role'],
                ]);

                header('Location: ' . redirectPathForRole((string) $user['role']));
                exit;
            }
        } catch (PDOException $e) {
            Logger::error('Login PDOException', ['message' => $e->getMessage()]);
            $errors[] = 'An error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Inventory System</title>
    <style>
        :root {
            --bg: #0f1117;
            --surface: #1a1d27;
            --border: #2a2d3e;
            --accent: #6c63ff;
            --accent-glow: rgba(108, 99, 255, 0.35);
            --text: #e8e9f0;
            --muted: #8b8fa8;
            --danger: #ff6b6b;
            --success: #51cf66;
            --input-bg: #12141e;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: "Segoe UI", system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: min(100%, 420px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 32px 80px rgba(0, 0, 0, 0.45);
        }
        .logo {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--accent), #8b7ff5);
            border-radius: 14px;
            display: grid;
            place-items: center;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px var(--accent-glow);
            font-weight: 800;
        }
        h1 { font-size: 1.6rem; margin-bottom: 6px; }
        .subtitle { color: var(--muted); font-size: 0.9rem; margin-bottom: 28px; }
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .alert-error { background: rgba(255, 107, 107, 0.1); border: 1px solid rgba(255, 107, 107, 0.25); color: var(--danger); }
        .alert-success { background: rgba(81, 207, 102, 0.1); border: 1px solid rgba(81, 207, 102, 0.25); color: var(--success); }
        .field { margin-bottom: 18px; }
        label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 7px;
            text-transform: uppercase;
        }
        input {
            width: 100%;
            padding: 12px 16px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.95rem;
            outline: none;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .btn {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--accent), #8b7ff5);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 0.96rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
        }
        .footer-links {
            margin-top: 22px;
            text-align: center;
            font-size: 0.87rem;
            color: var(--muted);
        }
        .footer-links a { color: var(--accent); text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">IS</div>
    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to your Inventory System account</p>

    <?php if (!($pdo instanceof PDO)): ?>
        <div class="alert alert-error">
            Database unavailable. Make sure DATABASE_URL is set correctly.
        </div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== '' || $errors !== []): ?>
        <div class="alert alert-error">
            <?php if ($flashError !== ''): ?><div><?= e($flashError) ?></div><?php endif; ?>
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="field">
            <label for="username">Username or Email</label>
            <input type="text" id="username" name="username" value="<?= e($inputUsername) ?>" autocomplete="username" required autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>

        <button class="btn" type="submit">Sign In</button>
    </form>

    <div class="footer-links">
        Don't have an account? <a href="register.php">Register</a>
    </div>
</div>
</body>
</html>
