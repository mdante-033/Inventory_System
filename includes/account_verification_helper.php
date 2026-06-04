<?php
/**
 * includes/account_verification_helper.php
 * PostgreSQL + Render.com + SMTP port 25
 *
 * FIX: mail_helper.php is now loaded safely — if the file is missing
 * the page will NOT crash. Login will still work; only email sending
 * will be disabled until mail_helper.php is added to the project root.
 */

// ── Safe load of mail_helper.php ──────────────────────────────────────────────
// __DIR__ is /var/www/html/includes  →  '/../mail_helper.php' resolves to
// /var/www/html/mail_helper.php  (project root, same folder as login.php)
$_mailHelperPath = __DIR__ . '/../mail_helper.php';
if (file_exists($_mailHelperPath)) {
    require_once $_mailHelperPath;
} else {
    // Log clearly so you can see it in Render logs, but DO NOT crash the page
    error_log(
        '[account_verification_helper] mail_helper.php not found at: ' .
        $_mailHelperPath . ' (email sending will be disabled)'
    );
}

// ── SCHEMA ────────────────────────────────────────────────────────────────────
if (!function_exists('ensureUsersRegistrationSchema')) {
    function ensureUsersRegistrationSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id                           SERIAL       PRIMARY KEY,
                username                     VARCHAR(50)  UNIQUE NOT NULL,
                password                     VARCHAR(255) NOT NULL,
                email                        VARCHAR(100) UNIQUE NOT NULL,
                full_name                    VARCHAR(100) NOT NULL,
                phone                        VARCHAR(20),
                customer_group               VARCHAR(50)  NOT NULL DEFAULT 'regular',
                role                         VARCHAR(20)  NOT NULL DEFAULT 'customer',
                is_active                    BOOLEAN      NOT NULL DEFAULT TRUE,
                is_verified                  BOOLEAN      NOT NULL DEFAULT FALSE,
                account_status               VARCHAR(20)  NOT NULL DEFAULT 'pending',
                verification_code            VARCHAR(255),
                code_expiry                  TIMESTAMP    NULL,
                verification_failed_attempts INT          NOT NULL DEFAULT 0,
                verification_locked_until    TIMESTAMP    NULL,
                verification_resend_count    INT          NOT NULL DEFAULT 0,
                last_verification_sent_at    TIMESTAMP    NULL,
                created_at                   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $cols = [
            'password'                     => 'VARCHAR(255)',
            'email'                        => 'VARCHAR(100)',
            'full_name'                    => 'VARCHAR(100)',
            'phone'                        => 'VARCHAR(20)',
            'customer_group'               => "VARCHAR(50) DEFAULT 'regular'",
            'role'                         => "VARCHAR(20) DEFAULT 'customer'",
            'is_active'                    => 'BOOLEAN DEFAULT TRUE',
            'is_verified'                  => 'BOOLEAN DEFAULT FALSE',
            'account_status'               => "VARCHAR(20) DEFAULT 'pending'",
            'verification_code'            => 'VARCHAR(255)',
            'code_expiry'                  => 'TIMESTAMP NULL',
            'verification_failed_attempts' => 'INT DEFAULT 0',
            'verification_locked_until'    => 'TIMESTAMP NULL',
            'verification_resend_count'    => 'INT DEFAULT 0',
            'last_verification_sent_at'    => 'TIMESTAMP NULL',
            'created_at'                   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at'                   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ];
        foreach ($cols as $col => $def) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS \"{$col}\" {$def}");
            } catch (\Throwable $e) {
                error_log("schema [{$col}]: " . $e->getMessage());
            }
        }

        $pdo->exec("UPDATE users SET
            full_name      = COALESCE(NULLIF(full_name,''), username),
            role           = COALESCE(NULLIF(role,''), 'customer'),
            customer_group = COALESCE(NULLIF(customer_group,''), 'regular'),
            is_active      = COALESCE(is_active, TRUE),
            is_verified    = COALESCE(is_verified, FALSE),
            account_status = COALESCE(NULLIF(account_status,''),
                                CASE WHEN COALESCE(is_verified,FALSE) THEN 'active' ELSE 'pending' END),
            verification_failed_attempts = COALESCE(verification_failed_attempts, 0),
            verification_resend_count    = COALESCE(verification_resend_count, 0),
            created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
            updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
        ");

        $pdo->exec("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check");
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT users_role_check
                    CHECK (role IN ('admin','manager','staff','customer','supplier'))");

        $pdo->exec("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_status_check");
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT users_account_status_check
                    CHECK (account_status IN ('pending','active','suspended'))");

        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique_ci_idx ON users (LOWER(email))");
    }
}

// ── URL HELPER ────────────────────────────────────────────────────────────────
if (!function_exists('buildAppUrl')) {
    function buildAppUrl(string $path = ''): string
    {
        $c = trim((string)(getenv('APP_URL') ?: ''));
        if ($c !== '') return rtrim($c, '/') . '/' . ltrim($path, '/');
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host . '/' . ltrim($path, '/');
    }
}

// ── ROLE → DASHBOARD ─────────────────────────────────────────────────────────
if (!function_exists('getDashboardForRole')) {
    /**
     * Returns the correct dashboard filename for a given role.
     * Call after successful login OR after successful email verification.
     *
     *   header('Location: ' . getDashboardForRole($user['role']));  exit;
     */
    function getDashboardForRole(string $role): string
    {
        return match (strtolower(trim($role))) {
            'admin', 'manager' => 'admin.php',
            default => 'customer_dashboard.php',
        };
    }
}

// ── CODE GENERATION ───────────────────────────────────────────────────────────
if (!function_exists('generateEmailVerificationCode')) {
    function generateEmailVerificationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('getVerificationExpiryDateTime')) {
    function getVerificationExpiryDateTime(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now'))->modify('+15 minutes');
    }
}

if (!function_exists('formatCountdownSeconds')) {
    function formatCountdownSeconds(int $s): int { return max(0, $s); }
}

// ── SESSION ───────────────────────────────────────────────────────────────────
if (!function_exists('setPendingVerificationSession')) {
    function setPendingVerificationSession(array $user): void
    {
        $_SESSION['pending_verification'] = [
            'user_id'   => (int)    ($user['id']        ?? 0),
            'email'     => (string) ($user['email']     ?? ''),
            'full_name' => (string) ($user['full_name'] ?? ''),
        ];
    }
}

if (!function_exists('clearPendingVerificationSession')) {
    function clearPendingVerificationSession(): void
    {
        unset($_SESSION['pending_verification']);
    }
}

if (!function_exists('getPendingVerificationUserId')) {
    function getPendingVerificationUserId(): int
    {
        return (int) ($_SESSION['pending_verification']['user_id'] ?? 0);
    }
}

// ── DB FETCH ──────────────────────────────────────────────────────────────────
if (!function_exists('fetchVerificationUserById')) {
    function fetchVerificationUserById(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, username, email, full_name, phone, role,
                   is_active, is_verified, account_status,
                   verification_code, code_expiry,
                   verification_failed_attempts, verification_locked_until,
                   verification_resend_count, last_verification_sent_at
            FROM   users WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

// ── COOLDOWN / LOCK ───────────────────────────────────────────────────────────
if (!function_exists('getVerificationResendSecondsRemaining')) {
    function getVerificationResendSecondsRemaining(array $user): int
    {
        if (empty($user['last_verification_sent_at'])) return 0;
        $ts = strtotime((string) $user['last_verification_sent_at']);
        return $ts === false ? 0 : max(0, 60 - (time() - $ts));
    }
}

if (!function_exists('getVerificationLockSecondsRemaining')) {
    function getVerificationLockSecondsRemaining(array $user): int
    {
        if (empty($user['verification_locked_until'])) return 0;
        $ts = strtotime((string) $user['verification_locked_until']);
        return $ts === false ? 0 : max(0, $ts - time());
    }
}

// ── is_verified NORMALISER (PostgreSQL returns 't'/'f') ───────────────────────
if (!function_exists('isAccountVerified')) {
    function isAccountVerified(mixed $value): bool
    {
        if (is_bool($value))  return $value;
        if ($value === null)  return false;
        return in_array(strtolower(trim((string)$value)), ['1','true','t','yes','y'], true);
    }
}

// ── SEND / RESEND VERIFICATION CODE ───────────────────────────────────────────
if (!function_exists('sendAccountVerificationCode')) {
    function sendAccountVerificationCode(PDO $pdo, array $user, bool $isResend = false): array
    {
        $cu = fetchVerificationUserById($pdo, (int)($user['id'] ?? 0));
        if (!$cu) return ['success' => false, 'message' => 'Account not found.'];

        if (isAccountVerified($cu['is_verified']))
            return ['success' => false, 'message' => 'This account is already verified.', 'already_verified' => true];

        if (($cu['account_status'] ?? 'pending') === 'suspended')
            return ['success' => false, 'message' => 'Account suspended. Contact support.'];

        $lockSec = getVerificationLockSecondsRemaining($cu);
        if ($lockSec > 0)
            return ['success' => false, 'message' => 'Too many failed attempts. Try again in ' . (int)ceil($lockSec/60) . ' min.', 'locked' => true, 'lock_seconds' => $lockSec];

        if ($isResend) {
            $cool = getVerificationResendSecondsRemaining($cu);
            if ($cool > 0) return ['success' => false, 'message' => 'Please wait before requesting another code.', 'retry_after' => $cool];
            if ((int)($cu['verification_resend_count'] ?? 0) >= 3)
                return ['success' => false, 'message' => 'Resend limit reached. Contact support.', 'resend_limit_reached' => true];
        }

        $code       = generateEmailVerificationCode();
        $hashedCode = password_hash($code, PASSWORD_DEFAULT);
        $expiry     = getVerificationExpiryDateTime();
        $verifyUrl  = buildAppUrl('verify_code.php');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE users SET
                    verification_code            = :code,
                    code_expiry                  = :expiry,
                    verification_failed_attempts = 0,
                    verification_locked_until    = NULL,
                    verification_resend_count    = :rc,
                    last_verification_sent_at    = CURRENT_TIMESTAMP,
                    updated_at                   = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([
                'code'  => $hashedCode,
                'expiry'=> $expiry->format('Y-m-d H:i:s'),
                'rc'    => $isResend ? ((int)($cu['verification_resend_count']??0)+1) : 0,
                'id'    => $cu['id'],
            ]);

            $mail   = new MailHelper();
            $mailed = $mail->sendRegistrationVerificationCode(
                (string)$cu['email'], (string)$cu['full_name'],
                $code, $expiry, $verifyUrl
            );

            $pdo->commit();
            setPendingVerificationSession($cu);

            return [
                'success'      => true,
                'message'      => $mailed['success']
                    ? 'A verification code has been sent to your email.'
                    : 'Account created, but the email could not be delivered. Use Resend to try again.',
                'mail_success' => $mailed['success'],
                'mail_message' => $mailed['message'] ?? '',
                'expires_at'   => $expiry->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sendAccountVerificationCode: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not generate a verification code. Please try again.'];
        }
    }
}

// ── ATTEMPT VERIFICATION ──────────────────────────────────────────────────────
if (!function_exists('attemptAccountVerification')) {
    function attemptAccountVerification(PDO $pdo, int $userId, string $code): array
    {
        $user = fetchVerificationUserById($pdo, $userId);
        if (!$user) return ['success' => false, 'message' => 'Could not find your pending account. Please register again.'];

        if (isAccountVerified($user['is_verified']))
            return ['success' => false, 'message' => 'This account is already verified.', 'already_verified' => true];

        if (($user['account_status'] ?? 'pending') === 'suspended')
            return ['success' => false, 'message' => 'Account suspended. Contact support.'];

        $lockSec = getVerificationLockSecondsRemaining($user);
        if ($lockSec > 0)
            return ['success' => false, 'message' => 'Account locked. Try again in ' . (int)ceil($lockSec/60) . ' min.', 'locked' => true, 'lock_seconds' => $lockSec];

        if (trim($code) === '' || !preg_match('/^\d{6}$/', $code))
            return ['success' => false, 'message' => 'Enter the 6-digit code from your email.'];

        if (empty($user['verification_code']) || empty($user['code_expiry']))
            return ['success' => false, 'message' => 'No active code found. Please request a new one.', 'expired' => true];

        $expiresAt = strtotime((string)$user['code_expiry']);
        if ($expiresAt === false || $expiresAt < time())
            return ['success' => false, 'message' => 'Your code has expired. Please request a new one.', 'expired' => true];

        if (!password_verify($code, (string)$user['verification_code'])) {
            $fa   = (int)($user['verification_failed_attempts'] ?? 0) + 1;
            $lock = $fa >= 5
                ? (new DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s')
                : null;

            $pdo->prepare("UPDATE users SET verification_failed_attempts=:a, verification_locked_until=:l, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['a' => $fa, 'l' => $lock, 'id' => $user['id']]);

            $rem = max(0, 5 - $fa);
            return [
                'success'            => false,
                'message'            => $rem > 0
                    ? "Invalid code. {$rem} attempt" . ($rem === 1 ? '' : 's') . ' remaining.'
                    : 'Too many invalid attempts. Account locked for 30 minutes.',
                'remaining_attempts' => $rem,
                'locked'             => $rem === 0,
            ];
        }

        // ── SUCCESS ───────────────────────────────────────────────────────────
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE users SET
                    is_verified                  = TRUE,
                    account_status               = 'active',
                    verification_code            = NULL,
                    code_expiry                  = NULL,
                    verification_failed_attempts = 0,
                    verification_locked_until    = NULL,
                    verification_resend_count    = 0,
                    last_verification_sent_at    = NULL,
                    updated_at                   = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute(['id' => $user['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('attemptAccountVerification: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not verify your account right now. Please try again.'];
        }

        return ['success' => true, 'message' => 'Your account has been verified successfully.'];
    }
}
