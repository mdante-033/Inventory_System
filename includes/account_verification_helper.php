<?php
/**
 * account_verification_helper.php
 *
 * Helpers for account email-verification flow.
 * Mail is sent via a direct SMTP socket on port 25 (no OpenSSL / STARTTLS
 * required) so it works even when php_openssl.dll is absent or the
 * Windows mail() function is not backed by a real MTA.
 *
 * php.ini settings used (already present in your file):
 *   SMTP       = localhost
 *   smtp_port  = 25
 */

// ─────────────────────────────────────────────────────────────────────────────
// SMTP CONFIGURATION  –  edit these to match your mail server
// ─────────────────────────────────────────────────────────────────────────────
define('SMTP_HOST',       ini_get('SMTP') ?: 'localhost');
define('SMTP_PORT',       (int)(ini_get('smtp_port') ?: 25));
define('SMTP_FROM_ADDR',  'no-reply@inventory-system.local');   // sender address
define('SMTP_FROM_NAME',  'Inventory System');                  // sender display name
define('SMTP_TIMEOUT',    10);                                  // seconds

// Verification code settings
define('VERIFY_CODE_LENGTH',   6);
define('VERIFY_CODE_TTL_MIN',  15);   // minutes until code expires
define('VERIFY_MAX_ATTEMPTS',  5);    // failed attempts before lockout
define('VERIFY_LOCK_MIN',      30);   // lockout duration in minutes
define('VERIFY_MAX_RESENDS',   3);    // max resend requests
define('VERIFY_RESEND_COOL',   2);    // minutes between resends


// ─────────────────────────────────────────────────────────────────────────────
// DATABASE SCHEMA HELPER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ensure the users table has all columns required for the verification flow.
 * Safe to call on every request – uses ALTER TABLE … ADD COLUMN IF NOT EXISTS.
 */
function ensureUsersRegistrationSchema(PDO $pdo): void
{
    // Create table if it does not exist at all
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username            VARCHAR(80)  NOT NULL,
            email               VARCHAR(191) NOT NULL UNIQUE,
            password_hash       VARCHAR(255) NOT NULL,
            is_verified         TINYINT(1)   NOT NULL DEFAULT 0,
            verification_code   VARCHAR(10)  DEFAULT NULL,
            code_expires_at     DATETIME     DEFAULT NULL,
            failed_attempts     TINYINT      NOT NULL DEFAULT 0,
            locked_until        DATETIME     DEFAULT NULL,
            resend_count        TINYINT      NOT NULL DEFAULT 0,
            last_resend_at      DATETIME     DEFAULT NULL,
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add any missing columns to an existing table (MySQL 8+ / MariaDB 10.3+)
    $additions = [
        'is_verified'       => "TINYINT(1)  NOT NULL DEFAULT 0 AFTER password_hash",
        'verification_code' => "VARCHAR(10) DEFAULT NULL        AFTER is_verified",
        'code_expires_at'   => "DATETIME    DEFAULT NULL        AFTER verification_code",
        'failed_attempts'   => "TINYINT     NOT NULL DEFAULT 0  AFTER code_expires_at",
        'locked_until'      => "DATETIME    DEFAULT NULL        AFTER failed_attempts",
        'resend_count'      => "TINYINT     NOT NULL DEFAULT 0  AFTER locked_until",
        'last_resend_at'    => "DATETIME    DEFAULT NULL        AFTER resend_count",
    ];

    // Fetch existing columns once
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users") as $row) {
        $existing[] = strtolower($row['Field']);
    }

    foreach ($additions as $col => $definition) {
        if (!in_array(strtolower($col), $existing, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `{$col}` {$definition}");
        }
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// SESSION HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/** Return the pending-verification user-id stored in session, or 0. */
function getPendingVerificationUserId(): int
{
    return isset($_SESSION['pending_verification_user_id'])
        ? (int) $_SESSION['pending_verification_user_id']
        : 0;
}

/** Remove all verification-related session keys. */
function clearPendingVerificationSession(): void
{
    unset(
        $_SESSION['pending_verification_user_id'],
        $_SESSION['verify_csrf']
    );
}


// ─────────────────────────────────────────────────────────────────────────────
// DATABASE FETCH HELPER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch a user row by id.
 * Returns an associative array or null if not found.
 *
 * @return array<string,mixed>|null
 */
function fetchVerificationUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, username, email, is_verified,
                verification_code, code_expires_at,
                failed_attempts, locked_until,
                resend_count, last_resend_at
         FROM   users
         WHERE  id = :id
         LIMIT  1"
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


// ─────────────────────────────────────────────────────────────────────────────
// CORE: GENERATE AND SEND VERIFICATION CODE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate a new verification code (or reuse an unexpired one when
 * $forceNew = false), persist it, and email it to the user.
 *
 * @param  PDO                  $pdo
 * @param  array<string,mixed>  $user       Row from fetchVerificationUserById()
 * @param  bool                 $forceNew   true = always generate a fresh code
 * @return array{success:bool, message:string}
 */
function sendAccountVerificationCode(PDO $pdo, array $user, bool $forceNew = false): array
{
    // ── Already verified? ────────────────────────────────────────────────────
    if (!empty($user['is_verified'])) {
        return ['success' => false, 'message' => 'This account is already verified.'];
    }

    // ── Lock check ───────────────────────────────────────────────────────────
    if (!empty($user['locked_until'])) {
        $lockTs = strtotime($user['locked_until']);
        if ($lockTs && $lockTs > time()) {
            $mins = (int) ceil(($lockTs - time()) / 60);
            return [
                'success' => false,
                'message' => "Account is locked due to too many failed attempts. "
                           . "Try again in {$mins} minute(s).",
            ];
        }
    }

    // ── Resend-limit check ───────────────────────────────────────────────────
    if ((int)$user['resend_count'] >= VERIFY_MAX_RESENDS) {
        return [
            'success' => false,
            'message' => 'Maximum resend limit reached. Please contact support.',
        ];
    }

    // ── Resend cooldown ──────────────────────────────────────────────────────
    if (!empty($user['last_resend_at'])) {
        $coolSec = VERIFY_RESEND_COOL * 60;
        $lastTs  = strtotime($user['last_resend_at']);
        if ($lastTs && (time() - $lastTs) < $coolSec) {
            $wait = (int) ceil($coolSec - (time() - $lastTs));
            return [
                'success' => false,
                'message' => "Please wait {$wait} second(s) before requesting another code.",
            ];
        }
    }

    // ── Decide whether to reuse existing code or generate a new one ──────────
    $reuseExisting = !$forceNew
        && !empty($user['verification_code'])
        && !empty($user['code_expires_at'])
        && strtotime($user['code_expires_at']) > time();

    if ($reuseExisting) {
        $code      = $user['verification_code'];
        $expiresAt = $user['code_expires_at'];
    } else {
        $code      = _generateVerificationCode();
        $expiresAt = date('Y-m-d H:i:s', time() + VERIFY_CODE_TTL_MIN * 60);
    }

    // ── Persist to DB ────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        UPDATE users
        SET    verification_code = :code,
               code_expires_at   = :expires,
               failed_attempts   = 0,
               locked_until      = NULL,
               resend_count      = resend_count + 1,
               last_resend_at    = NOW()
        WHERE  id = :id
    ");
    $stmt->execute([
        ':code'    => $code,
        ':expires' => $expiresAt,
        ':id'      => $user['id'],
    ]);

    // ── Send email via SMTP on port 25 ───────────────────────────────────────
    $sent = _smtpSendVerificationEmail(
        $user['email'],
        $user['username'],
        $code,
        $expiresAt
    );

    if (!$sent) {
        // Roll back the resend-count increment so the user can try again
        $pdo->prepare("UPDATE users SET resend_count = resend_count - 1 WHERE id = :id")
            ->execute([':id' => $user['id']]);

        return [
            'success' => false,
            'message' => 'We could not send the verification email right now. '
                       . 'Please try again in a moment.',
        ];
    }

    return [
        'success' => true,
        'message' => "A verification code has been sent to {$user['email']}. "
                   . "It expires at {$expiresAt}.",
    ];
}


// ─────────────────────────────────────────────────────────────────────────────
// CORE: VERIFY THE CODE SUBMITTED BY THE USER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate the code the user typed on the verify_code.php form.
 *
 * @return array{success:bool, message:string}
 */
function verifySubmittedCode(PDO $pdo, array $user, string $submittedCode): array
{
    // ── Already verified ─────────────────────────────────────────────────────
    if (!empty($user['is_verified'])) {
        return ['success' => false, 'message' => 'Account is already verified.'];
    }

    // ── Lock check ───────────────────────────────────────────────────────────
    if (!empty($user['locked_until'])) {
        $lockTs = strtotime($user['locked_until']);
        if ($lockTs && $lockTs > time()) {
            $mins = (int) ceil(($lockTs - time()) / 60);
            return [
                'success' => false,
                'message' => "Account locked. Try again in {$mins} minute(s).",
            ];
        }
        // Lock has expired – reset it
        $pdo->prepare("UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE id = :id")
            ->execute([':id' => $user['id']]);
        $user['locked_until']   = null;
        $user['failed_attempts'] = 0;
    }

    // ── No code stored ───────────────────────────────────────────────────────
    if (empty($user['verification_code'])) {
        return [
            'success' => false,
            'message' => 'No verification code found. Please request a new one.',
        ];
    }

    // ── Code expired ─────────────────────────────────────────────────────────
    if (empty($user['code_expires_at']) || strtotime($user['code_expires_at']) <= time()) {
        return [
            'success' => false,
            'message' => 'Your verification code has expired. Please request a new one.',
        ];
    }

    // ── Wrong code ───────────────────────────────────────────────────────────
    if (!hash_equals((string)$user['verification_code'], trim($submittedCode))) {
        $newAttempts = (int)$user['failed_attempts'] + 1;
        $remaining   = VERIFY_MAX_ATTEMPTS - $newAttempts;

        if ($newAttempts >= VERIFY_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + VERIFY_LOCK_MIN * 60);
            $pdo->prepare("
                UPDATE users
                SET failed_attempts = :attempts, locked_until = :locked
                WHERE id = :id
            ")->execute([
                ':attempts' => $newAttempts,
                ':locked'   => $lockedUntil,
                ':id'       => $user['id'],
            ]);
            return [
                'success' => false,
                'message' => "Too many incorrect attempts. "
                           . "Account locked for " . VERIFY_LOCK_MIN . " minutes.",
            ];
        }

        $pdo->prepare("UPDATE users SET failed_attempts = :attempts WHERE id = :id")
            ->execute([':attempts' => $newAttempts, ':id' => $user['id']]);

        return [
            'success' => false,
            'message' => "Incorrect code. {$remaining} attempt(s) remaining.",
        ];
    }

    // ── SUCCESS – mark verified ───────────────────────────────────────────────
    $pdo->prepare("
        UPDATE users
        SET is_verified       = 1,
            verification_code = NULL,
            code_expires_at   = NULL,
            failed_attempts   = 0,
            locked_until      = NULL
        WHERE id = :id
    ")->execute([':id' => $user['id']]);

    clearPendingVerificationSession();

    return ['success' => true, 'message' => 'Your account has been verified successfully!'];
}


// ─────────────────────────────────────────────────────────────────────────────
// PRIVATE HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/** Generate a zero-padded numeric code of VERIFY_CODE_LENGTH digits. */
function _generateVerificationCode(): string
{
    $max = (int) str_repeat('9', VERIFY_CODE_LENGTH);
    return str_pad((string) random_int(0, $max), VERIFY_CODE_LENGTH, '0', STR_PAD_LEFT);
}

/**
 * Send the verification email using a raw SMTP socket on port 25.
 * No STARTTLS / AUTH required – suitable for a local or trusted relay.
 *
 * Returns true on success, false on any SMTP or socket error.
 */
function _smtpSendVerificationEmail(
    string $toEmail,
    string $toName,
    string $code,
    string $expiresAt
): bool {
    $fromAddr = SMTP_FROM_ADDR;
    $fromName = SMTP_FROM_NAME;
    $subject  = 'Your Verification Code';

    // ── Build MIME message ───────────────────────────────────────────────────
    $boundary  = '----=_Boundary_' . bin2hex(random_bytes(8));
    $plainBody = "Hello {$toName},\r\n\r\n"
               . "Your verification code is: {$code}\r\n\r\n"
               . "It expires at: {$expiresAt}\r\n\r\n"
               . "If you did not register, ignore this email.\r\n";

    $htmlBody  = "<!DOCTYPE html><html><body style='font-family:sans-serif;'>"
               . "<h2>Activate your account</h2>"
               . "<p>Hello <strong>" . htmlspecialchars($toName, ENT_QUOTES) . "</strong>,</p>"
               . "<p>Your 6-digit verification code is:</p>"
               . "<h1 style='letter-spacing:8px;color:#b8860b;'>{$code}</h1>"
               . "<p>Expires at: <strong>" . htmlspecialchars($expiresAt, ENT_QUOTES) . "</strong></p>"
               . "<p style='color:#888;font-size:12px;'>If you did not register, ignore this email.</p>"
               . "</body></html>";

    $headers = "From: =?UTF-8?B?" . base64_encode("{$fromName}") . "?= <{$fromAddr}>\r\n"
             . "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
             . "X-Mailer: InventorySystem/1.0\r\n";

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($plainBody)) . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($htmlBody)) . "\r\n"
          . "--{$boundary}--\r\n";

    // ── Open raw TCP socket to SMTP on port 25 ───────────────────────────────
    $errNo  = 0;
    $errStr = '';
    $sock   = @fsockopen(SMTP_HOST, SMTP_PORT, $errNo, $errStr, SMTP_TIMEOUT);

    if ($sock === false) {
        error_log("SMTP connect failed ({$errNo}): {$errStr}");
        return false;
    }

    stream_set_timeout($sock, SMTP_TIMEOUT);

    /**
     * Read one or more response lines from the SMTP server.
     * Returns the last line received, or false on timeout / error.
     */
    $read = static function ($sock): string|false {
        $response = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // A line without a dash after the 3-digit code is the last line
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }
        return $response ?: false;
    };

    /**
     * Send one SMTP command and return the server response.
     */
    $cmd = static function ($sock, string $line) use ($read): string|false {
        fwrite($sock, $line . "\r\n");
        return $read($sock);
    };

    // ── SMTP conversation ────────────────────────────────────────────────────
    try {
        // 220 greeting
        $resp = $read($sock);
        if (!$resp || !str_starts_with($resp, '2')) {
            throw new \RuntimeException("No 220 greeting: {$resp}");
        }

        // EHLO
        $resp = $cmd($sock, 'EHLO ' . (gethostname() ?: 'localhost'));
        if (!$resp || !str_starts_with($resp, '2')) {
            // Fall back to HELO
            $resp = $cmd($sock, 'HELO ' . (gethostname() ?: 'localhost'));
            if (!$resp || !str_starts_with($resp, '2')) {
                throw new \RuntimeException("HELO failed: {$resp}");
            }
        }

        // MAIL FROM
        $resp = $cmd($sock, "MAIL FROM:<{$fromAddr}>");
        if (!$resp || !str_starts_with($resp, '2')) {
            throw new \RuntimeException("MAIL FROM rejected: {$resp}");
        }

        // RCPT TO
        $resp = $cmd($sock, "RCPT TO:<{$toEmail}>");
        if (!$resp || !str_starts_with($resp, '2')) {
            throw new \RuntimeException("RCPT TO rejected: {$resp}");
        }

        // DATA
        $resp = $cmd($sock, 'DATA');
        if (!$resp || !str_starts_with($resp, '3')) {
            throw new \RuntimeException("DATA command rejected: {$resp}");
        }

        // Message (headers + blank line + body) – end with \r\n.\r\n
        fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
        $resp = $read($sock);
        if (!$resp || !str_starts_with($resp, '2')) {
            throw new \RuntimeException("Message rejected: {$resp}");
        }

        // QUIT
        $cmd($sock, 'QUIT');

    } catch (\Throwable $e) {
        error_log('SMTP send error: ' . $e->getMessage());
        fclose($sock);
        return false;
    }

    fclose($sock);
    return true;
}
