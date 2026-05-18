<?php
/**
 * verify_code.php
 *
 * Step 2 of registration: user enters the 6-digit code emailed to them.
 * On success, redirects to the role-appropriate dashboard via getDashboardForRole().
 */

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/account_verification_helper.php';

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$errors = [];
$statusMessage = $flashSuccess;
$user = null;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $errors[] = isset($db_connection_error) ? $db_connection_error
              : 'Database connection is unavailable right now. Please try again later.';
} else {
    ensureUsersRegistrationSchema($pdo);
    $pendingUserId = getPendingVerificationUserId();

    if ($pendingUserId <= 0) {
        // If already logged-in and verified, send straight to dashboard
        if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
            $currentUser = fetchVerificationUserById($pdo, (int) $_SESSION['user_id']);
            if ($currentUser && isAccountVerified($currentUser['is_verified'])) {
                header('Location: ' . getDashboardForRole($currentUser['role']));
                exit;
            }
        }
        $errors[] = 'Your verification session is missing or has expired. Please register again or sign in.';
    } else {
        $user = fetchVerificationUserById($pdo, $pendingUserId);

        if (!$user) {
            clearPendingVerificationSession();
            $errors[] = 'We could not find your pending account. Please register again.';
        } elseif (isAccountVerified($user['is_verified'])) {
            clearPendingVerificationSession();
            $_SESSION['flash_success'] = 'Your account is already verified. Please sign in.';
            header('Location: login.php');
            exit;
        } elseif (($user['account_status'] ?? 'pending') === 'suspended') {
            $errors[] = 'This account is suspended. Please contact support.';
        }
    }
}

if ($flashError !== '') {
    $errors[] = $flashError;
}

// Fresh CSRF token
if (empty($_SESSION['verify_csrf'])) {
    $_SESSION['verify_csrf'] = bin2hex(random_bytes(32));
}

// ── Handle POST (code submission) ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && $errors === []) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $code      = trim($_POST['verification_code'] ?? '');

    if (!hash_equals($_SESSION['verify_csrf'] ?? '', $csrfToken)) {
        $errors[] = 'Your verification session expired. Refresh the page and try again.';
    } else {
        $result = attemptAccountVerification($pdo, (int) $user['id'], $code);

        if ($result['success']) {
            clearPendingVerificationSession();
            unset($_SESSION['verify_csrf']);
            session_regenerate_id(true);

            // Reload the user to get the definitive role from the DB
            $verifiedUser = fetchVerificationUserById($pdo, (int) $user['id']);
            $dashboard    = getDashboardForRole($verifiedUser['role'] ?? 'customer');

            $_SESSION['flash_success'] = 'Account verified successfully! Welcome.';
            header('Location: ' . $dashboard);
            exit;
        }

        $errors[] = $result['message'];
        // Re-fetch so counters / lock state are current
        $user = fetchVerificationUserById($pdo, (int) $user['id']);
    }
}

$resendCooldown    = $user ? getVerificationResendSecondsRemaining($user) : 0;
$lockCooldown      = $user ? getVerificationLockSecondsRemaining($user)   : 0;
$remainingAttempts = $user ? max(0, 5 - (int)($user['verification_failed_attempts'] ?? 0)) : 5;
$resendsUsed       = $user ? (int)($user['verification_resend_count'] ?? 0) : 0;
$codeExpiry        = !empty($user['code_expiry']) ? strtotime((string)$user['code_expiry']) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Verify your account with the code sent to your email.">
    <title>Verify Account – Inventory System</title>
    <style>
        :root {
            --bg:#f8f3e8; --card:rgba(255,255,255,.94); --border:#eadfcb;
            --text:#1e1f24; --muted:#6d6458; --accent:#c59031;
            --accent-dark:#8a5f16; --danger:#b9483e; --success:#1f8a52;
            --shadow:0 24px 60px rgba(93,67,28,.14);
        }
        *{box-sizing:border-box;}
        body{margin:0;min-height:100vh;font-family:Arial,sans-serif;color:var(--text);
             background:radial-gradient(circle at top left,rgba(197,144,49,.16),transparent 28%),
                        radial-gradient(circle at bottom right,rgba(137,95,22,.16),transparent 22%),
                        linear-gradient(180deg,#fdf9f2 0%,var(--bg) 100%);
             display:grid;place-items:center;padding:24px;}
        .shell{width:min(100%,960px);display:grid;grid-template-columns:1fr 1fr;gap:24px;}
        .panel,.card{background:var(--card);border:1px solid var(--border);border-radius:26px;box-shadow:var(--shadow);}
        .panel{padding:34px;background:linear-gradient(180deg,rgba(255,255,255,.92),rgba(255,255,255,.88)),
                                       linear-gradient(135deg,rgba(197,144,49,.1),transparent);}
        .eyebrow{margin:0 0 12px;color:var(--accent-dark);font-size:.8rem;
                 text-transform:uppercase;letter-spacing:.16em;font-weight:700;}
        h1{margin:0;font-size:clamp(2rem,4vw,3rem);line-height:1.02;}
        .lead{margin:18px 0 0;line-height:1.75;color:var(--muted);}
        .facts{margin-top:24px;display:grid;gap:12px;}
        .fact{padding:14px 16px;border-radius:16px;border:1px solid var(--border);background:rgba(255,249,240,.72);}
        .fact strong{display:block;margin-bottom:4px;}
        .card{padding:28px;}
        .card h2{margin:0;font-size:1.5rem;}
        .copy{margin:10px 0 18px;color:var(--muted);line-height:1.65;}
        .alert{padding:14px 16px;border-radius:16px;margin-bottom:16px;line-height:1.6;}
        .alert-success{background:rgba(31,138,82,.09);border:1px solid rgba(31,138,82,.2);color:var(--success);}
        .alert-error{background:rgba(185,72,62,.08);border:1px solid rgba(185,72,62,.18);color:var(--danger);}
        .meta{margin-bottom:20px;color:var(--muted);font-size:.94rem;}
        .code-input{width:100%;padding:16px;border-radius:18px;border:1px solid var(--border);
                    font-size:1.6rem;text-align:center;letter-spacing:.5rem;font-weight:700;}
        .code-input:focus{outline:none;border-color:rgba(197,144,49,.8);
                          box-shadow:0 0 0 4px rgba(197,144,49,.12);}
        .helper{margin-top:10px;color:var(--muted);font-size:.92rem;}
        .actions{display:grid;gap:12px;margin-top:18px;}
        .btn,.btn-link{display:inline-flex;align-items:center;justify-content:center;
                       gap:8px;width:100%;border:none;border-radius:16px;padding:14px 18px;
                       font-size:.96rem;font-weight:700;cursor:pointer;text-decoration:none;}
        .btn-primary{background:linear-gradient(135deg,var(--accent),#e6bc67);color:#1f1a11;}
        .btn-secondary{background:transparent;border:1px solid var(--border);color:var(--text);}
        .btn[disabled]{opacity:.55;cursor:not-allowed;}
        .status-grid{display:grid;gap:10px;margin-top:18px;}
        .status-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;
                    border-bottom:1px solid rgba(234,223,203,.75);font-size:.94rem;}
        .status-row:last-child{border-bottom:none;}
        .muted-link{color:var(--accent-dark);}
        @media(max-width:860px){.shell{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<div class="shell">

    <section class="panel">
        <p class="eyebrow">Step 2 of 2</p>
        <h1>Activate your account with the code in your inbox.</h1>
        <p class="lead">
            We sent a 6-digit verification code to
            <strong><?= e((string)($user['email'] ?? ($_SESSION['pending_verification']['email'] ?? 'your email address'))) ?></strong>.
            Your account stays pending until that code is verified.
        </p>
        <div class="facts">
            <div class="fact">
                <strong>Code expiry</strong>
                <span><?= $codeExpiry ? e(date('M j, Y g:i A', $codeExpiry)) : 'Waiting for your next verification code.' ?></span>
            </div>
            <div class="fact">
                <strong>Failed attempts remaining</strong>
                <span><?= (int)$remainingAttempts ?> of 5</span>
            </div>
            <div class="fact">
                <strong>Resends used</strong>
                <span><?= (int)$resendsUsed ?> of 3</span>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Verify Code</h2>
        <p class="copy">Enter the 6-digit code from your email. After 5 invalid attempts, the account locks for 30 minutes.</p>

        <?php if ($statusMessage !== ''): ?>
            <div class="alert alert-success"><?= e($statusMessage) ?></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
            <div class="meta">Verifying account for <strong><?= e((string)$user['full_name']) ?></strong></div>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['verify_csrf']) ?>">
                <label for="verification_code" class="helper">6-digit code</label>
                <input class="code-input" type="text" id="verification_code" name="verification_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code" placeholder="000000" required>
                <div class="helper">Codes expire after 15 minutes. Use only the latest code we emailed you.</div>
                <div class="actions">
                    <button class="btn btn-primary" type="submit" <?= $lockCooldown > 0 ? 'disabled' : '' ?>>
                        Verify Account
                    </button>
                </div>
            </form>

            <form method="post" action="resend_code.php" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['verify_csrf']) ?>">
                <button id="resendButton" class="btn btn-secondary" type="submit"
                    <?= ($resendCooldown > 0 || $resendsUsed >= 3 || $lockCooldown > 0) ? 'disabled' : '' ?>>
                    Resend Code
                </button>
            </form>

            <div class="status-grid">
                <div class="status-row">
                    <span>Resend cooldown</span>
                    <strong id="resendCountdown"><?= $resendCooldown > 0 ? (int)$resendCooldown . 's' : 'Ready now' ?></strong>
                </div>
                <div class="status-row">
                    <span>Lock status</span>
                    <strong id="lockCountdown"><?= $lockCooldown > 0 ? (int)ceil($lockCooldown/60) . ' min remaining' : 'No lock active' ?></strong>
                </div>
            </div>
        <?php endif; ?>

        <p class="copy" style="margin-top:20px;">
            Already verified or used the wrong email?
            <a class="muted-link" href="login.php">Go to login</a> or
            <a class="muted-link" href="register.php">register again</a>.
        </p>
    </section>

</div>
<script>
(function(){
    let resendSeconds = <?= json_encode((int)$resendCooldown) ?>;
    let lockSeconds   = <?= json_encode((int)$lockCooldown)   ?>;
    const resendLimitReached = <?= json_encode($resendsUsed >= 3) ?>;
    const resendBtn  = document.getElementById('resendButton');
    const resendDisp = document.getElementById('resendCountdown');
    const lockDisp   = document.getElementById('lockCountdown');

    function tick() {
        if (resendDisp && resendSeconds > 0) {
            resendSeconds--;
            resendDisp.textContent = resendSeconds > 0 ? resendSeconds + 's' : 'Ready now';
        }
        if (lockDisp && lockSeconds > 0) {
            lockSeconds--;
            lockDisp.textContent = lockSeconds > 0
                ? Math.ceil(lockSeconds / 60) + ' min remaining'
                : 'No lock active';
        }
        if (resendBtn) {
            resendBtn.disabled = lockSeconds > 0 || resendLimitReached || resendSeconds > 0;
        }
    }

    if (resendSeconds > 0 || lockSeconds > 0) setInterval(tick, 1000);
})();
</script>
</body>
</html>
