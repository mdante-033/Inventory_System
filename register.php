<?php
/**
 * Registration page with secure session, CSRF, and email verification.
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

$baseDir = __DIR__;
if (!file_exists($baseDir . '/db_connect.php')) {
    die('<h2 style="font-family:sans-serif;color:#c0392b">Error: db_connect.php not found. Check file structure.</h2>');
}

require_once $baseDir . '/db_connect.php';
require_once $baseDir . '/includes/account_verification_helper.php';

function registrationRedirectPath(string $role): string
{
    return in_array($role, ['admin', 'manager'], true) ? 'admin.php' : 'customer_dashboard.php';
}

if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    $role = (string) ($_SESSION['role'] ?? $_SESSION['user_role'] ?? 'customer');
    header('Location: ' . registrationRedirectPath($role));
    exit;
}

// Use centralized CSRF helpers to generate/validate tokens
$csrfToken = generateCSRFToken();

$errors = [];
$input = [
    'username' => '',
    'email' => '',
    'full_name' => '',
    'phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $input['username'] = trim($_POST['username'] ?? '');
    $input['email'] = trim($_POST['email'] ?? '');
    $input['full_name'] = trim($_POST['full_name'] ?? '');
    $input['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? ($_POST['confirm_password'] ?? '');

    if (!validateCSRFToken((string) $csrfToken)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if ($input['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $input['username'])) {
        $errors[] = 'Username must be 3 to 30 characters and use only letters, numbers, and underscores.';
    }

    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($input['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }

    if ($input['phone'] !== '' && !preg_match('/^\+?[0-9\s()-]{7,20}$/', $input['phone'])) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (strtolower($input['username']) === strtolower($input['email'])) {
        $errors[] = 'Username and email must be different.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!($pdo instanceof PDO)) {
        $errors[] = $db_connection_error ?: 'Database unavailable. Please try again shortly.';
    }

    if ($errors === []) {
        try {
            ensureUsersRegistrationSchema($pdo);

            $duplicate = $pdo->prepare("
                SELECT id, username, email, full_name, is_verified, account_status
                FROM users
                WHERE LOWER(username) = LOWER(:username)
                   OR LOWER(email) = LOWER(:email)
                LIMIT 1
            ");
            $duplicate->execute([
                'username' => $input['username'],
                'email' => $input['email'],
            ]);
            $existingUser = $duplicate->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                $sameEmail = strtolower((string) $existingUser['email']) === strtolower($input['email']);
                $sameUsername = strtolower((string) $existingUser['username']) === strtolower($input['username']);

                if ($sameEmail && isAccountVerified($existingUser['is_verified']) && ($existingUser['account_status'] ?? 'active') === 'active') {
                    $errors[] = 'An account with that email already exists. Please sign in instead.';
                } elseif ($sameUsername && !$sameEmail) {
                    $errors[] = 'That username is already taken.';
                } elseif (($existingUser['account_status'] ?? 'pending') === 'suspended') {
                    $errors[] = 'This account is suspended. Please contact support.';
                } else {
                    session_regenerate_id(true);
                    refreshSessionSecurityMetadata();
                    setPendingVerificationSession($existingUser);
                    $_SESSION['flash_success'] = 'Your account is waiting for verification. Enter the code we sent to your email, or request a new one.';
                    header('Location: verify_code.php');
                    exit;
                }
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO users (
                        username,
                        password,
                        email,
                        full_name,
                        phone,
                        role,
                        is_active,
                        is_verified,
                        account_status,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        :username,
                        :password,
                        :email,
                        :full_name,
                        :phone,
                        'customer',
                        TRUE,
                        FALSE,
                        'pending',
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                    RETURNING id, username, email, full_name, role, phone
                ");
                $insert->execute([
                    'username' => $input['username'],
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $input['email'],
                    'full_name' => $input['full_name'],
                    'phone' => $input['phone'] !== '' ? $input['phone'] : null,
                ]);

                $newUser = $insert->fetch(PDO::FETCH_ASSOC);
                unset($_SESSION['register_csrf']);
                session_regenerate_id(true);
                refreshSessionSecurityMetadata();
                clearPendingVerificationSession();

                $verificationResult = sendAccountVerificationCode($pdo, $newUser, false);
                if (!$verificationResult['success']) {
                    setPendingVerificationSession($newUser);
                    $errors[] = $verificationResult['message'];
                } else {
                    $_SESSION['flash_success'] = $verificationResult['message'];
                    header('Location: verify_code.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log('Register error: ' . $e->getMessage());
            if ($e->getCode() === '23505') {
                $errors[] = 'That username or email is already registered.';
            } else {
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
    <title>Create Account - Inventory System</title>
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
            width: min(100%, 460px);
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
            line-height: 1.6;
            margin-bottom: 20px;
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.25);
            color: var(--danger);
        }
        .field { margin-bottom: 16px; }
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
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
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
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .footer-links {
            margin-top: 22px;
            text-align: center;
            font-size: 0.87rem;
            color: var(--muted);
        }
        .footer-links a { color: var(--accent); text-decoration: none; }
        @media (max-width: 480px) {
            .row { grid-template-columns: 1fr; }
            .card { padding: 28px 20px; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">IS</div>
    <h1>Create account</h1>
    <p class="subtitle">Join the Inventory System</p>

    <?php if (!($pdo instanceof PDO)): ?>
        <div class="alert">Database unavailable. Make sure DATABASE_URL is set correctly.</div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert">
            <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

        <div class="field">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($input['full_name']) ?>" autocomplete="name" required>
        </div>

        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($input['username']) ?>" autocomplete="username" required>
        </div>

        <div class="field">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" value="<?= e($input['email']) ?>" autocomplete="email" required>
        </div>

        <div class="field">
            <label for="phone">Phone (optional)</label>
            <input type="tel" id="phone" name="phone" value="<?= e($input['phone']) ?>" autocomplete="tel">
        </div>

        <div class="row">
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            </div>
        </div>

        <button class="btn" type="submit" <?= !($pdo instanceof PDO) ? 'disabled' : '' ?>>Create Account</button>
    </form>

    <div class="footer-links">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>
</body>
</html>
