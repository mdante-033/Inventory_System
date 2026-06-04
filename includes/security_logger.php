<?php
/**
 * Security Event Logger
 * Comprehensive logging for security-related events
 */

class SecurityLogger {
    private string $logPath;
    private \PDO $pdo;
    private bool $useDatabase;

    public function __construct(
        \PDO $pdo = null,
        string $logPath = '',
        bool $useDatabase = true
    ) {
        $this->pdo = $pdo;
        $this->useDatabase = $useDatabase && $pdo !== null;

        if (empty($logPath)) {
            $logPath = getenv('SECURITY_LOG_PATH') ?: (
                (PHP_OS_FAMILY === 'Windows')
                    ? getenv('TEMP') . '\\inventory_security.log'
                    : '/var/log/inventory/security.log'
            );
        }

        $this->logPath = $logPath;

        // Ensure log directory exists
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Log a security event
     */
    public function log(
        string $level,
        string $message,
        array $context = []
    ): void {
        $logEntry = $this->formatLogEntry($level, $message, $context);

        // Write to file
        $this->writeToFile($logEntry);

        // Write to database if available
        if ($this->useDatabase) {
            $this->writeToDB($level, $message, $context);
        }
    }

    /**
     * Log critical security event
     */
    public function critical(string $message, array $context = []): void {
        $this->log('CRITICAL', $message, $context);
    }

    /**
     * Log warning event
     */
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log informational event
     */
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log debug event (only in development)
     */
    public function debug(string $message, array $context = []): void {
        if (getenv('APP_DEBUG') === 'true' || getenv('APP_ENV') === 'local') {
            $this->log('DEBUG', $message, $context);
        }
    }

    /**
     * Log failed authentication attempt
     */
    public function logFailedAuth(string $username, string $reason = 'invalid_credentials'): void {
        $this->warning('Failed authentication attempt', [
            'username' => InputValidator::maskSensitive($username),
            'reason' => $reason,
            'ip' => $this->getIpAddress(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255),
        ]);
    }

    /**
     * Log successful authentication
     */
    public function logSuccessfulAuth(int $userId, string $username): void {
        $this->info('Successful authentication', [
            'user_id' => $userId,
            'username' => $username,
            'ip' => $this->getIpAddress(),
        ]);
    }

    /**
     * Log permission denied
     */
    public function logPermissionDenied(int $userId, string $action, string $resource): void {
        $this->warning('Permission denied', [
            'user_id' => $userId,
            'action' => $action,
            'resource' => $resource,
            'ip' => $this->getIpAddress(),
        ]);
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(string $type, array $details = []): void {
        $this->critical('Suspicious activity detected', array_merge([
            'type' => $type,
            'ip' => $this->getIpAddress(),
            'user_id' => $_SESSION['user_id'] ?? null,
        ], $details));
    }

    /**
     * Log SQL error
     */
    public function logSqlError(\PDOException $e): void {
        $this->critical('Database error', [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * Log file upload
     */
    public function logFileUpload(
        int $userId,
        string $filename,
        string $mimeType,
        int $size,
        bool $success = true
    ): void {
        $this->info(($success ? 'Successful' : 'Failed') . ' file upload', [
            'user_id' => $userId,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size' => $size,
            'ip' => $this->getIpAddress(),
        ]);
    }

    /**
     * Log API request
     */
    public function logApiRequest(string $method, string $endpoint, int $statusCode, array $extra = []): void {
        $this->info('API request', array_merge([
            'method' => $method,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'ip' => $this->getIpAddress(),
        ], $extra));
    }

    /**
     * Log configuration change
     */
    public function logConfigChange(int $userId, string $setting, mixed $oldValue, mixed $newValue): void {
        $this->warning('Configuration changed', [
            'user_id' => $userId,
            'setting' => $setting,
            'old_value' => self::maskValue($oldValue),
            'new_value' => self::maskValue($newValue),
        ]);
    }

    /**
     * Format log entry as JSON
     */
    private function formatLogEntry(string $level, string $message, array $context): string {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip_address' => $this->getIpAddress(),
            'session_id' => session_id() ?: null,
            'context' => $context,
        ];

        return json_encode($entry) . PHP_EOL;
    }

    /**
     * Write log entry to file
     */
    private function writeToFile(string $entry): void {
        try {
            @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            error_log('Failed to write security log: ' . $e->getMessage());
        }
    }

    /**
     * Write log entry to database
     */
    private function writeToDB(string $level, string $message, array $context): void {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO security_logs (level, message, context, user_id, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');

            $stmt->execute([
                $level,
                $message,
                json_encode($context),
                $_SESSION['user_id'] ?? null,
                $this->getIpAddress(),
            ]);
        } catch (\PDOException $e) {
            error_log('Failed to write security log to database: ' . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     */
    private function getIpAddress(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Mask sensitive values for logging
     */
    private static function maskValue(mixed $value): mixed {
        if (is_string($value) && strlen($value) > 4) {
            return InputValidator::maskSensitive($value);
        }
        return $value;
    }

    /**
     * Cleanup old logs (retention period)
     */
    public static function cleanup(\PDO $pdo, int $retentionDays = 90): void {
        try {
            $stmt = $pdo->prepare('
                DELETE FROM security_logs
                WHERE created_at < NOW() - INTERVAL ? DAY
            ');
            $stmt->execute([$retentionDays]);
        } catch (\PDOException $e) {
            error_log('Failed to cleanup security logs: ' . $e->getMessage());
        }
    }
}
?>
