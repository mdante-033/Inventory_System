<?php
/**
 * Encryption Handler for Sensitive Data
 * Uses AES-256-GCM for authenticated encryption
 */

class EncryptionHandler {
    private string $key;
    private string $cipher = 'AES-256-GCM';
    private string $hashAlgo = 'sha256';

    /**
     * Initialize with encryption key from environment
     */
    public function __construct() {
        $keyBase64 = getenv('ENCRYPTION_KEY');

        if (!$keyBase64) {
            throw new \Exception(
                'ENCRYPTION_KEY environment variable not set. Generate with: '
                . 'php -r "echo base64_encode(openssl_random_pseudo_bytes(32));"'
            );
        }

        $this->key = base64_decode($keyBase64, true);

        if ($this->key === false || strlen($this->key) !== 32) {
            throw new \Exception('ENCRYPTION_KEY must be a valid base64-encoded 32-byte key');
        }
    }

    /**
     * Encrypts data using AES-256-GCM
     */
    public function encrypt(string $data): string {
        // Generate random 16-byte IV
        $iv = openssl_random_pseudo_bytes(16);

        if ($iv === false) {
            throw new \Exception('Failed to generate initialization vector');
        }

        $tag = '';

        // Encrypt with authentication tag
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            0,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new \Exception('Encryption failed');
        }

        // Return IV + tag + encrypted data (all base64 encoded)
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypts data using AES-256-GCM
     */
    public function decrypt(string $encryptedData): string {
        $data = base64_decode($encryptedData, true);

        if ($data === false || strlen($data) < 32) {
            throw new \Exception('Invalid encrypted data format');
        }

        // Extract IV (first 16 bytes), tag (next 16 bytes), and encrypted content
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $encrypted = substr($data, 32);

        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            0,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \Exception('Decryption failed - data may be corrupted or tampered');
        }

        return $decrypted;
    }

    /**
     * Hash password using bcrypt (preferred over raw encryption)
     */
    public static function hashPassword(string $password): string {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        if ($hash === false) {
            throw new \Exception('Password hashing failed');
        }

        return $hash;
    }

    /**
     * Verify password against bcrypt hash
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehashing (cost increased in config)
     */
    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Generate secure random token
     */
    public static function generateToken(int $length = 32): string {
        return bin2hex(openssl_random_pseudo_bytes($length));
    }

    /**
     * Generate secure random string (alphanumeric)
     */
    public static function generateRandomString(int $length = 32): string {
        $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
        return substr(bin2hex($bytes), 0, $length);
    }

    /**
     * Hash data for integrity verification (not for passwords!)
     */
    public static function hashData(string $data): string {
        return hash('sha256', $data);
    }

    /**
     * Verify HMAC signature
     */
    public static function verifyHmac(string $message, string $signature, string $key): bool {
        $expectedSignature = hash_hmac('sha256', $message, $key, true);
        return hash_equals($signature, $expectedSignature);
    }

    /**
     * Create HMAC signature
     */
    public static function createHmac(string $message, string $key): string {
        return hash_hmac('sha256', $message, $key, false);
    }

    /**
     * Securely clear sensitive strings from memory (limited effect)
     */
    public static function secureClear(string &$string): void {
        $string = str_repeat("\0", strlen($string));
    }
}
?>
