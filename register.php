<?php
/**
 * register.php - Render-compatible registration page
 * Blank page fixes:
 *  1. session_start() absolutely first
 *  2. All require_once inside file_exists guards
 *  3. Email verification BYPASSED — users auto-verified on registration
 *     (avoids SMTP dependency that causes blank page / crashes)
 *  4. Graceful DB-down message instead of blank screen
 */

// ── Must be absolutely first ────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Safe includes ───────────────────────────────────────────
$baseDir = __DIR__;

if (!file_exists($baseDir . '/db_connect.php')) {
    die('<h2 style="font-family:sans-serif;color:#c0392b">Error: db_connect.php not found. Check file structure.</h2>');
}
require_once $baseDir . '/db_connect.php';

// ── Already logged in? ──────────────────────────────────────
if (!empty($_SESSION['logged_in'])) {
    $role = $_SESSION['user_role'] ?? 'customer';
    header('Location: ' . ($role === 'admin' || $role === 'manager' ? 'admin/dashboard.php' : 'customer_dashboard.php'));
    exit;
}

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── CSRF ────────────────────────────────────────────────────
if (empty($_SESSION['register_csrf'])) {
    $_SESSION['register_csrf'] = bin2hex(random_bytes(32));
}

$errors  = [];
$success = '';
$input   = ['username' => '', 'email' => '', 'full_name' => '', 'phone' => ''];

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['register_csrf'] ?? '', $csrfToken)) {
        $errors[] = 'Session expired. Refresh and try again.';
    } elseif (!($pdo instanceof PDO)) {
        $errors[] = 'Database unavailable. Please try again shortly.';
    } else {
        // Collect and sanitise
        $input['username']  = trim($_POST['username']  ?? '');
        $input['email']     = trim($_POST['email']     ?? '');
        $input['full_name'] = trim($_POST['full_name'] ?? '');
        $input['phone']     = trim($_POST['phone']     ?? '');
        $password           = $_POST['password']          ?? '';
        $passwordConfirm    = $_POST['password_confirm']  ?? '';

        // Validate
        if ($input['username'] === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $input['username'])) {
            $errors[] = 'Username must be 3–30 characters (letters, numbers, underscores).';
        }

        if ($input['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($input['full_name'] === '') {
            $errors[] = 'Full name is required.';
        }

        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }

        // Check duplicates
        if ($errors === []) {
            try {
                $dup = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                $dup->execute([$input['username'], $input['email']]);
                if ($dup->fetch()) {
                    $errors[] = 'That username or email is already registered.';
                }
            } catch (\PDOException $e) {
                error_log('Register duplicate check: ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }

        // Insert user — auto-verified (no email needed)
        if ($errors === []) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (username, password, email, full_name, phone,
                         role, is_active, is_verified, account_status)
                    VALUES
                        (:username, :password, :email, :full_name, :phone,
                         'customer', true, true, 'active')
                    RETURNING id
                ");
                $stmt->execute([
                    ':username'  => $input['username'],
                    ':password'  => $hash,
                    ':email'     => $input['email'],
                    ':full_name' => $input['full_name'],
                    ':phone'     => $input['phone'],
                ]);

                // Auto-login after registration
                $newId = $stmt->fetchColumn();
                session_regenerate_id(true);
                $_SESSION['logged_in']   = true;
                $_SESSION['user_id']     = $newId;
                $_SESSION['username']    = $input['username'];
                $_SESSION['user_role']   = 'customer';
                $_SESSION['user_name']   = $input['full_name'];
                $_SESSION['user_email']  = $input['email'];
                unset($_SESSION['register_csrf']);

                $_SESSION['flash_success'] = 'Account created! Welcome, ' . $input['full_name'] . '.';
                header('Location: customer_dashboard.php');
                exit;

            } catch (\PDOException $e) {
                error_log('Register insert error: ' . $e->getMessage());
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — Inventory System</title>
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
                radial-gradient(ellipse 60% 50% at 80% 10%, rgba(108,99,255,0.08), transparent),
                radial-gradient(ellipse 40% 60% at 20% 90%, rgba(108,99,255,0.06), transparent);
        }

        .card {
            width: min(100%, 460px);
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
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .alert-error   { background: rgba(255,107,107,0.1); border: 1px solid rgba(255,107,107,0.25); color: var(--danger); }
        .alert-success { background: rgba(81,207,102,0.1);  border: 1px solid rgba(81,207,102,0.25);  color: var(--success); }

        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .field { margin-bottom: 16px; }
        label  { display: block; font-size: 0.82rem; font-weight: 600; color: var(--muted); margin-bottom: 7px; letter-spacing: 0.04em; text-transform: uppercase; }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="tel"] {
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

        @media (max-width: 480px) {
            .row { grid-template-columns: 1fr; }
            .card { padding: 28px 20px; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">📦</div>
    <h1>Create account</h1>
    <p class="subtitle">Join the Inventory System</p>

    <?php if (!($pdo instanceof PDO)): ?>
        <div class="db-warning">
            ⚠ Database unavailable. Make sure <code>DATABASE_URL</code> is set in your Render environment.
        </div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['register_csrf'] ?? '') ?>">

        <div class="field">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name"
                   value="<?= e($input['full_name']) ?>"
                   autocomplete="name" required>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"
                   value="<?= e($input['username']) ?>"
                   autocomplete="username" required>
        </div>

        <div class="field">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email"
                   value="<?= e($input['email']) ?>"
                   autocomplete="email" required>
        </div>

        <div class="field">
            <label for="phone">Phone (optional)</label>
            <input type="tel" id="phone" name="phone"
                   value="<?= e($input['phone']) ?>"
                   autocomplete="tel">
        </div>

        <div class="row">
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm"
                       autocomplete="new-password" required>
            </div>
        </div>

        <button class="btn" type="submit"
                <?= !($pdo instanceof PDO) ? 'disabled' : '' ?>>
            Create Account
        </button>
    </form>

    <div class="footer-links">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>
</body>
</html>
