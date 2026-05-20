<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/account_verification_helper.php';

if (isset($_SESSION['user_id'], $_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $redirect = in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)
        ? 'admin.php'
        : 'customer_dashboard.php';
    header('Location: ' . $redirect);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function stringLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value)
        : strlen($value);
}

function buildUsername(string $fullName, string $email): string
{
    $base = strtolower(trim($fullName));
    $base = preg_replace('/[^a-z0-9]+/', '_', $base);
    $base = trim((string) $base, '_');

    if ($base === '') {
        $emailBase = strtolower((string) strstr($email, '@', true));
        $base = preg_replace('/[^a-z0-9]+/', '_', $emailBase);
        $base = trim((string) $base, '_');
    }

    if ($base === '') {
        $base = 'user';
    }

    return substr($base, 0, 24);
}

function generateUniqueUsername(PDO $pdo, string $fullName, string $email): string
{
    $base = buildUsername($fullName, $email);
    $checkStmt = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');

    for ($i = 0; $i < 100; $i++) {
        $suffix = $i === 0 ? '' : '_' . $i;
        $candidate = substr($base, 0, 24 - strlen($suffix)) . $suffix;
        $checkStmt->execute(['username' => $candidate]);
        if (!$checkStmt->fetchColumn()) {
            return $candidate;
        }
    }

    return 'user_' . bin2hex(random_bytes(4));
}

$formValues = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
];
$errors = [];
$success_message = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

if (empty($_SESSION['register_csrf'])) {
    $_SESSION['register_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues['full_name'] = trim($_POST['full_name'] ?? '');
    $formValues['email'] = trim($_POST['email'] ?? '');
    $formValues['phone'] = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['register_csrf'] ?? '', $csrfToken)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if ($formValues['full_name'] === '' || stringLength($formValues['full_name']) < 2) {
        $errors[] = 'Please enter your full name.';
    } elseif (stringLength($formValues['full_name']) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }

    if (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($formValues['phone'] !== '' && !preg_match('/^\+?[0-9\s()-]{7,20}$/', $formValues['phone'])) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match.';
    }

    if ($pdo === null) {
        $errors[] = $db_connection_error ?: 'Database connection is unavailable right now. Please try again later.';
    }

    if ($errors === []) {
        try {
            ensureUsersRegistrationSchema($pdo);

            $emailCheck = $pdo->prepare("
                SELECT id, email, full_name, is_verified, account_status
                FROM users
                WHERE LOWER(email) = LOWER(:email)
                LIMIT 1
            ");
            $emailCheck->execute(['email' => $formValues['email']]);
            $existingUser = $emailCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                if (isAccountVerified($existingUser['is_verified']) && ($existingUser['account_status'] ?? 'active') === 'active') {
                    $errors[] = 'An account with that email already exists. Please sign in instead.';
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
                $username = generateUniqueUsername($pdo, $formValues['full_name'], $formValues['email']);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare("
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
                $insertStmt->execute([
                    'username' => $username,
                    'password' => $passwordHash,
                    'email' => $formValues['email'],
                    'full_name' => $formValues['full_name'],
                    'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
                ]);
                $newUser = $insertStmt->fetch();

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
            error_log('Registration error: ' . $e->getMessage());

            if ($e->getCode() === '23505') {
                $errors[] = 'That email is already registered. Please sign in instead.';
            } else {
                $errors[] = 'We could not create your account right now. Please try again.';
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
    <meta name="description" content="Create your Inventory System account.">
    <title>Create Account - Inventory System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f1115;
            --bg-soft: #171a21;
            --panel: rgba(20, 24, 31, 0.92);
            --border: rgba(255, 255, 255, 0.1);
            --text: #f7f3ed;
            --muted: rgba(247, 243, 237, 0.72);
            --accent: #d9b35d;
            --accent-strong: #f0ca75;
            --danger: #f08a85;
            --success: #8fd3a8;
            --shadow: 0 28px 60px rgba(0, 0, 0, 0.42);
            --serif: 'Cormorant Garamond', Georgia, serif;
            --sans: 'Outfit', sans-serif;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--sans);
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(217, 179, 93, 0.18), transparent 30%),
                radial-gradient(circle at bottom right, rgba(109, 79, 41, 0.2), transparent 28%),
                linear-gradient(145deg, #0b0d11 0%, #12161d 55%, #0d1014 100%);
        }

        a { color: inherit; }

        .page-shell {
            width: min(1120px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 2rem 0 3rem;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            text-decoration: none;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 1px solid rgba(217, 179, 93, 0.7);
            display: grid;
            place-items: center;
            color: var(--accent);
            font-family: var(--serif);
            font-size: 1.2rem;
        }

        .brand-text {
            font-family: var(--serif);
            font-size: 1.7rem;
            letter-spacing: 0.06em;
        }

        .brand-text em {
            font-style: italic;
            color: var(--accent);
        }

        .signin-link {
            color: var(--muted);
            text-decoration: none;
            font-size: 0.95rem;
        }

        .signin-link strong { color: var(--accent); }

        .layout {
            display: grid;
            grid-template-columns: 1.1fr 0.95fr;
            gap: 2rem;
            align-items: stretch;
        }

        .intro-panel,
        .form-panel {
            border: 1px solid var(--border);
            border-radius: 28px;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
        }

        .intro-panel {
            padding: 3rem;
            position: relative;
            overflow: hidden;
        }

        .intro-panel::after {
            content: '';
            position: absolute;
            inset: auto -20% -35% auto;
            width: 340px;
            height: 340px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(217, 179, 93, 0.22), transparent 68%);
            pointer-events: none;
        }

        .eyebrow {
            margin: 0 0 1rem;
            color: var(--accent);
            letter-spacing: 0.2em;
            text-transform: uppercase;
            font-size: 0.78rem;
        }

        .intro-panel h1 {
            margin: 0;
            font-family: var(--serif);
            font-size: clamp(2.8rem, 4vw, 4.2rem);
            line-height: 0.95;
            font-weight: 500;
        }

        .intro-panel p {
            margin: 1.4rem 0 0;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.8;
            max-width: 34rem;
        }

        .perk-list {
            display: grid;
            gap: 1rem;
            margin: 2.2rem 0 0;
        }

        .perk {
            padding: 1rem 1.1rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.03);
        }

        .perk strong {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text);
            font-size: 1rem;
        }

        .perk span {
            color: var(--muted);
            font-size: 0.93rem;
        }

        .form-panel {
            padding: 2rem;
        }

        .panel-title {
            margin: 0;
            font-size: 1.7rem;
            font-weight: 600;
        }

        .panel-copy {
            margin: 0.6rem 0 1.6rem;
            color: var(--muted);
            line-height: 1.7;
        }

        .alert {
            border-radius: 16px;
            padding: 1rem 1.1rem;
            margin-bottom: 1.2rem;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .alert-error {
            border: 1px solid rgba(240, 138, 133, 0.35);
            background: rgba(240, 138, 133, 0.12);
            color: #ffd8d6;
        }

        .alert-success {
            border: 1px solid rgba(143, 211, 168, 0.35);
            background: rgba(143, 211, 168, 0.12);
            color: #d5f5df;
        }

        .alert ul {
            margin: 0;
            padding-left: 1.25rem;
        }

        .form-grid {
            display: grid;
            gap: 1rem;
        }

        .form-group {
            display: grid;
            gap: 0.55rem;
        }

        label {
            font-size: 0.94rem;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 0.95rem 1rem;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            font: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        input:focus {
            outline: none;
            border-color: rgba(217, 179, 93, 0.8);
            box-shadow: 0 0 0 4px rgba(217, 179, 93, 0.15);
            background: rgba(255, 255, 255, 0.06);
        }

        .helper-text {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .strength-row {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.25rem;
        }

        .strength-bar {
            flex: 1;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .strength-fill {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: var(--danger);
            transition: width 0.2s ease, background 0.2s ease;
        }

        .strength-text {
            min-width: 92px;
            text-align: right;
            font-size: 0.83rem;
            color: var(--muted);
        }

        .submit-btn {
            margin-top: 0.4rem;
            border: none;
            border-radius: 999px;
            padding: 1rem 1.3rem;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
            color: #16120a;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
            box-shadow: 0 16px 30px rgba(217, 179, 93, 0.22);
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(217, 179, 93, 0.28);
        }

        .form-footer {
            margin-top: 1.3rem;
            color: var(--muted);
            font-size: 0.94rem;
        }

        .form-footer a {
            color: var(--accent);
            font-weight: 600;
            text-decoration: none;
        }

        @media (max-width: 920px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .intro-panel,
            .form-panel {
                padding: 1.5rem;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="topbar">
            <a class="brand" href="index.php" aria-label="Go to homepage">
                <span class="brand-mark" aria-hidden="true">L</span>
                <span class="brand-text">Luxe<em>Store</em></span>
            </a>
            <a class="signin-link" href="login.php">Already have an account? <strong>Sign In</strong></a>
        </div>

        <div class="layout">
            <section class="intro-panel" aria-labelledby="register-heading">
                <p class="eyebrow">Create Your Account</p>
                <h1 id="register-heading">Join the experience behind every seamless order.</h1>
                <p>Create your customer account to shop faster, track orders, and keep your details ready for the next purchase.</p>

                <div class="perk-list">
                    <div class="perk">
                        <strong>Fast checkout</strong>
                        <span>Your details stay ready for a quicker purchase flow.</span>
                    </div>
                    <div class="perk">
                        <strong>Order visibility</strong>
                        <span>Keep track of account activity and purchase history in one place.</span>
                    </div>
                    <div class="perk">
                        <strong>Secure sign-in</strong>
                        <span>Passwords are hashed with PHP’s `password_hash()` before storage.</span>
                    </div>
                </div>
            </section>

            <section class="form-panel" aria-label="Registration form">
                <h2 class="panel-title">Create Account</h2>
                <p class="panel-copy">Fill in your details below. We’ll automatically create a username for your account behind the scenes.</p>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-error" role="alert">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($success_message !== ''): ?>
                    <div class="alert alert-success" role="status">
                        <?= e($success_message) ?>
                    </div>
                <?php endif; ?>

                <form method="post" id="registerForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['register_csrf']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                value="<?= e($formValues['full_name']) ?>"
                                maxlength="100"
                                minlength="2"
                                autocomplete="name"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= e($formValues['email']) ?>"
                                maxlength="100"
                                autocomplete="email"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?= e($formValues['phone']) ?>"
                                maxlength="20"
                                autocomplete="tel"
                                placeholder="+2547XXXXXXXX"
                            >
                            <span class="helper-text">Optional, but recommended for order updates and future login security.</span>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >
                            <span class="helper-text">Use at least 6 characters. A longer password is safer.</span>
                            <div class="strength-row" aria-live="polite">
                                <div class="strength-bar" aria-hidden="true">
                                    <div class="strength-fill" id="strengthFill"></div>
                                </div>
                                <span class="strength-text" id="strengthText">Too short</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                minlength="6"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <button class="submit-btn" type="submit">Register Now</button>
                    </div>
                </form>

                <p class="form-footer">Already have an account? <a href="login.php">Sign in here</a>.</p>
            </section>
        </div>
    </div>

    <script>
        const registerForm = document.getElementById('registerForm');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');

        function updatePasswordStrength() {
            const value = passwordInput.value;
            let score = 0;

            if (value.length >= 6) score += 1;
            if (/[A-Z]/.test(value)) score += 1;
            if (/[a-z]/.test(value)) score += 1;
            if (/\d/.test(value)) score += 1;
            if (/[^A-Za-z0-9]/.test(value)) score += 1;

            const states = [
                { width: 10, label: 'Too short', color: '#f08a85' },
                { width: 28, label: 'Weak', color: '#f08a85' },
                { width: 52, label: 'Fair', color: '#d9b35d' },
                { width: 76, label: 'Good', color: '#d9b35d' },
                { width: 100, label: 'Strong', color: '#8fd3a8' },
            ];

            const state = states[Math.max(0, Math.min(score, states.length - 1))];
            strengthFill.style.width = state.width + '%';
            strengthFill.style.background = state.color;
            strengthText.textContent = value.length === 0 ? 'Too short' : state.label;
        }

        function syncPasswordValidity() {
            if (confirmPasswordInput.value !== '' && confirmPasswordInput.value !== passwordInput.value) {
                confirmPasswordInput.setCustomValidity('Passwords do not match.');
            } else {
                confirmPasswordInput.setCustomValidity('');
            }
        }

        passwordInput.addEventListener('input', () => {
            updatePasswordStrength();
            syncPasswordValidity();
        });

        confirmPasswordInput.addEventListener('input', syncPasswordValidity);

        registerForm.addEventListener('submit', (event) => {
            syncPasswordValidity();

            if (!registerForm.checkValidity()) {
                event.preventDefault();
                registerForm.reportValidity();
            }
        });

        updatePasswordStrength();
    </script>
</body>
</html>
