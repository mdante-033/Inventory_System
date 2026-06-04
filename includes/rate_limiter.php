<?php
/**
 * Rate Limiter for Brute-Force Protection
 * Tracks login attempts and API requests per IP/identifier
 */

class RateLimiter {
    private \PDO $pdo;
    private string $identifier;
    private string $ipAddress;

    public function __construct(\PDO $pdo, string $identifier = '', string $ipAddress = '') {
        $this->pdo = $pdo;
        $this->identifier = $identifier ?: ($_POST['login'] ?? $_POST['username'] ?? 'unknown');
        $this->ipAddress = $ipAddress ?: ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Check if identifier is currently rate-limited
     */
    public function isLimited(
        int $maxAttempts = 5,
        int $windowMinutes = 15
    ): bool {
        try {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) as attempts
                FROM login_attempts
                WHERE identifier = ?
                AND ip_address = ?
                AND created_at > NOW() - INTERVAL ? MINUTE
                AND success = FALSE
            ');

            $stmt->execute([$this->identifier, $this->ipAddress, $windowMinutes]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return ($result['attempts'] ?? 0) >= $maxAttempts;
        } catch (\PDOException $e) {
            error_log('RateLimiter check error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get remaining attempts before lockout
     */
    public function getRemainingAttempts(
        int $maxAttempts = 5,
        int $windowMinutes = 15
    ): int {
        try {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) as attempts
                FROM login_attempts
                WHERE identifier = ?
                AND ip_address = ?
                AND created_at > NOW() - INTERVAL ? MINUTE
                AND success = FALSE
            ');

            $stmt->execute([$this->identifier, $this->ipAddress, $windowMinutes]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $attempts = $result['attempts'] ?? 0;

            return max(0, $maxAttempts - $attempts);
        } catch (\PDOException $e) {
            error_log('RateLimiter remaining attempts error: ' . $e->getMessage());
            return $maxAttempts;
        }
    }

    /**
     * Record a login attempt
     */
    public function recordAttempt(bool $success = false): void {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO login_attempts (identifier, ip_address, success, created_at)
                VALUES (?, ?, ?, NOW())
            ');

            $stmt->execute([
                $this->identifier,
                $this->ipAddress,
                $success ? 1 : 0
            ]);

            // If successful, clear previous failed attempts
            if ($success) {
                $this->clearAttempts();
            }
        } catch (\PDOException $e) {
            error_log('RateLimiter record attempt error: ' . $e->getMessage());
        }
    }

    /**
     * Clear all attempts for this identifier
     */
    public function clearAttempts(): void {
        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM login_attempts
                WHERE identifier = ? AND ip_address = ?
            ');

            $stmt->execute([$this->identifier, $this->ipAddress]);
        } catch (\PDOException $e) {
            error_log('RateLimiter clear attempts error: ' . $e->getMessage());
        }
    }

    /**
     * Get lockout time remaining (if any)
     */
    public function getLockoutTime(
        int $maxAttempts = 5,
        int $windowMinutes = 15
    ): ?int {
        try {
            $stmt = $this->pdo->prepare('
                SELECT MIN(created_at) as first_attempt
                FROM login_attempts
                WHERE identifier = ?
                AND ip_address = ?
                AND created_at > NOW() - INTERVAL ? MINUTE
                AND success = FALSE
            ');

            $stmt->execute([$this->identifier, $this->ipAddress, $windowMinutes]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result['first_attempt']) {
                return null;
            }

            // Calculate remaining lockout time in seconds
            $lockoutUntil = strtotime($result['first_attempt']) + ($windowMinutes * 60);
            $timeRemaining = $lockoutUntil - time();

            return max(0, $timeRemaining);
        } catch (\PDOException $e) {
            error_log('RateLimiter lockout time error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check API rate limit (requests per minute)
     */
    public function checkApiLimit(
        string $apiKey,
        int $requestsPerMinute = 60
    ): bool {
        try {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) as count
                FROM api_requests
                WHERE api_key = ?
                AND ip_address = ?
                AND created_at > NOW() - INTERVAL 1 MINUTE
            ');

            $stmt->execute([$apiKey, $this->ipAddress]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return ($result['count'] ?? 0) < $requestsPerMinute;
        } catch (\PDOException $e) {
            error_log('RateLimiter API check error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Record API request
     */
    public function recordApiRequest(string $apiKey, string $endpoint): void {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO api_requests (api_key, ip_address, endpoint, created_at)
                VALUES (?, ?, ?, NOW())
            ');

            $stmt->execute([$apiKey, $this->ipAddress, $endpoint]);
        } catch (\PDOException $e) {
            error_log('RateLimiter record API request error: ' . $e->getMessage());
        }
    }

    /**
     * Cleanup old records (should be run periodically)
     */
    public static function cleanup(\PDO $pdo): void {
        try {
            // Delete records older than 30 days
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
            $pdo->exec('DELETE FROM api_requests WHERE created_at < NOW() - INTERVAL 30 DAY');
        } catch (\PDOException $e) {
            error_log('RateLimiter cleanup error: ' . $e->getMessage());
        }
    }
}
?>
