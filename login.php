<?php
/**
 * login.php - Render-compatible login page
 * Blank page fixes:
 *  1. All require_once wrapped in file_exists checks
 *  2. session_start() at very top before any output
 *  3. Graceful DB-down handling (shows error, not blank)
 *  4. No BOM / no stray whitespace before <?php
 */

// ── Must be absolutely first line ──────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Safe includes ───────────────────────────────────────────
$baseDir = __DIR__;

if (!file_exists($baseDir . '/db_connect.php')) {
    die('<h2 style="font-family:sans-serif;color:#c0392b">Error: db_connect.php not found.<br>Check your file structure on Render.</h2>');
}
require_once $baseDir . '/db_connect.php';

// ── Already logged in? ──────────────────────────────────────
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'customer';
    header('Location: ' . ($role === 'admin' || $role === 'manager' ? 'admin/dashboard.php' : 'customer_dashboard.php'));
    exit;
}

// ── Helpers ─────────────────────────────────────────────────
function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── Flash messages ──────────────────────────────────────────
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ── CSRF token ──────────────────────────────────────────────
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$inputUsername = '';

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken     = $_POST['csrf_token'] ?? '';
    $inputUsername = trim($_POST['username'] ?? '');
    $inputPassword = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['login_csrf'] ?? '', $csrfToken)) {
        $errors[] = 'Session expired. Please refresh and try again.';
    } elseif ($inputUsername === '' || $inputPassword === '') {
        $errors[] = 'Please enter both username/email and password.';
    } elseif (!($pdo instanceof PDO)) {
        $errors[] = 'Database is temporarily unavailable. Please try again shortly.';
    } else {
        try {
            // Look up by username OR email
            $stmt = $pdo->prepare("
                SELECT id, username, password, email, full_name, role,
                       is_active, is_verified, account_status
                FROM users
                WHERE (username = :u OR email = :u)
                LIMIT 1
            ");
            $stmt->execute([':u' => $inputUsername]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($inputPassword, $user['password'])) {
                $errors[] = 'Invalid username or password.';
            } elseif (empty($user['is_active'])) {
                $errors[] = 'Your account has been deactivated. Contact support.';
            } elseif ($user['account_status'] === 'suspended') {
                $errors[] = 'Your account is suspended. Contact support.';
            } elseif (empty($user['is_verified'])) {
                // Unverified — send them to verify_code.php
                $_SESSION['pending_verification'] = [
                    'user_id' => $user['id'],
                    'email'   => $user['email'],
                ];
                $_SESSION['flash_error'] = 'Please verify your account first.';
                header('Location: verify_code.php');
                exit;
            } else {
                // ── Success ─────────────────────────────────
                session_regenerate_id(true);
                $_SESSION['logged_in']   = true;
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['username']    = $user['username'];
                $_SESSION['user_role']   = $user['role'];
                $_SESSION['user_name']   = $user['full_name'];
                $_SESSION['user_email']  = $user['email'];
                unset($_SESSION['login_csrf']);

                $dest = ($user['role'] === 'admin' || $user['role'] === 'manager')
                    ? 'admin/dashboard.php'
                    : 'customer_dashboard.php';
                header('Location: ' . $dest);
                exit;
            }
        } catch (\PDOException $e) {
            error_log('Login PDO error: ' . $e->getMessage());
            $errors[] = 'A database error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Inventory System</title>
    <style>
        :root {
            --bg:          #0f1117;
            --surface:     #1a1d27;
            --border:      #2a2d3e;
            --accent:      #6c63ff;
            --accent-glow: rgba(108,99,255,0.35);
            --text:        #e8e9f0;
            --muted:       #8b8fa8;
            --danger:      #ff6b6b;
            --success:     #51cf66;
            --input-bg:    #12141e;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(ellipse 60% 50% at 20% 20%, rgba(108,99,255,0.08), transparent),
                radial-gradient(ellipse 40% 60% at 80% 80%, rgba(108,99,255,0.06), transparent);
        }

        .card {
            width: min(100%, 420px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px 36px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.5), 0 0 0 1px rgba(108,99,255,0.08);
        }

        .logo {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--accent), #a78bfa);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; margin-bottom: 20px;
            box-shadow: 0 8px 24px var(--accent-glow);
        }

        h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 6px; }
        .subtitle { color: var(--muted); font-size: 0.9rem; margin-bottom: 28px; }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .alert-error   { background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.25); color: var(--danger); }
        .alert-success { background: rgba(81,207,102,0.1);  border: 1px solid rgba(81,207,102,0.25);  color: var(--success); }

        .field { margin-bottom: 18px; }
        label  { display: block; font-size: 0.82rem; font-weight: 600; color: var(--muted); margin-bottom: 7px; letter-spacing: 0.04em; text-transform: uppercase; }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

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
            transition: opacity 0.2s, transform 0.1s;
            letter-spacing: 0.02em;
        }
        .btn:hover  { opacity: 0.9; transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .footer-links {
            margin-top: 22px;
            text-align: center;
            font-size: 0.87rem;
            color: var(--muted);
        }
        .footer-links a { color: var(--accent); text-decoration: none; }
        .footer-links a:hover { text-decoration: underline; }

        .db-warning {
            padding: 14px 16px;
            background: rgba(255,107,107,0.08);
            border: 1px solid rgba(255,107,107,0.2);
            border-radius: 12px;
            color: var(--danger);
            font-size: 0.87rem;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">📦</div>
    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to your Inventory System account</p>

    <?php if (!($pdo instanceof PDO)): ?>
        <div class="db-warning">
            ⚠ Database unavailable. Make sure <code>DATABASE_URL</code> is set in your Render environment variables.
        </div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if ($errors !== [] || $flashError !== ''): ?>
        <div class="alert alert-error">
            <?php if ($flashError !== ''): ?><div><?= e($flashError) ?></div><?php endif; ?>
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['login_csrf'] ?? '') ?>">

        <div class="field">
            <label for="username">Username or Email</label>
            <input type="text" id="username" name="username"
                   value="<?= e($inputUsername) ?>"
                   autocomplete="username" required autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>
        </div>

        <button class="btn" type="submit">Sign In</button>
    </form>

    <div class="footer-links">
        Don't have an account? <a href="register.php">Register</a>
    </div>
</div>
</body>
</html>
