<?php
/**
 * Input Validation & Sanitization Helper
 * Provides comprehensive validation methods for user inputs
 */

class InputValidator {
    /**
     * Sanitizes a string input
     */
    public static function sanitizeString(string $input, int $maxLength = 255): string {
        $input = trim($input);
        $input = substr($input, 0, $maxLength);
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validates email format
     */
    public static function validateEmail(string $email): bool {
        $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
        return $email !== false && strlen($email) <= 255;
    }

    /**
     * Sanitizes email
     */
    public static function sanitizeEmail(string $email): string {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: '';
    }

    /**
     * Validates username format (alphanumeric, underscore, dash, dot)
     */
    public static function validateUsername(string $username): bool {
        return preg_match('/^[a-zA-Z0-9._\-]{3,50}$/', $username) === 1;
    }

    /**
     * Sanitizes username
     */
    public static function sanitizeUsername(string $username): string {
        return preg_replace('/[^a-zA-Z0-9._\-]/', '', substr($username, 0, 50));
    }

    /**
     * Validates password strength
     * Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 special char
     */
    public static function validatePassword(string $password): array {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&*, etc.)';
        }

        return $errors;
    }

    /**
     * Validates integer within range
     */
    public static function validateInt(mixed $value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int {
        $val = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max]
        ]);
        return $val !== false ? $val : null;
    }

    /**
     * Validates positive integer
     */
    public static function validatePositiveInt(mixed $value): ?int {
        return self::validateInt($value, 1, PHP_INT_MAX);
    }

    /**
     * Validates URL format
     */
    public static function validateUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validates IP address
     */
    public static function validateIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Sanitizes filename for safe storage
     */
    public static function sanitizeFilename(string $filename): string {
        $filename = basename($filename);
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        return substr($filename, 0, 255);
    }

    /**
     * Validates file upload
     */
    public static function validateFileUpload(
        array $file,
        array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'],
        int $maxSize = 5242880
    ): array {
        $errors = [];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds maximum upload size',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
            ];
            $errors[] = $errorMessages[$file['error']] ?? 'Unknown upload error';
        }

        if ($file['size'] > $maxSize) {
            $errors[] = 'File exceeds maximum size of ' . ($maxSize / 1048576) . 'MB';
        }

        if (!empty($file['name']) && !in_array($file['type'], $allowedMimes, true)) {
            $errors[] = 'File type not allowed';
        }

        // Verify actual MIME type
        if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $actualMimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($actualMimeType, $allowedMimes, true)) {
                $errors[] = 'File content type does not match allowed types (actual: ' . $actualMimeType . ')';
            }
        }

        return $errors;
    }

    /**
     * Validates phone number (basic international format)
     */
    public static function validatePhone(string $phone): bool {
        return preg_match('/^\+?[1-9]\d{1,14}$/', preg_replace('/\D/', '', $phone)) === 1;
    }

    /**
     * Sanitizes phone number to E.164 format
     */
    public static function sanitizePhone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) >= 10) {
            if (strlen($phone) == 10) {
                $phone = '1' . $phone;
            }
            return '+' . $phone;
        }
        return '';
    }

    /**
     * Validates credit card number (Luhn algorithm)
     */
    public static function validateCreditCard(string $cardNumber): bool {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            return false;
        }

        $sum = 0;
        $isEven = false;

        for ($i = strlen($cardNumber) - 1; $i >= 0; $i--) {
            $digit = (int) $cardNumber[$i];

            if ($isEven) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $isEven = !$isEven;
        }

        return $sum % 10 === 0;
    }

    /**
     * Masks sensitive data for logging
     */
    public static function maskSensitive(string $data, int $visibleChars = 4): string {
        if (strlen($data) <= $visibleChars) {
            return str_repeat('*', strlen($data));
        }
        return substr($data, 0, $visibleChars) . str_repeat('*', strlen($data) - $visibleChars);
    }

    /**
     * Validates CSRF token format (should be 32+ character hex string)
     */
    public static function validateTokenFormat(string $token): bool {
        return preg_match('/^[a-f0-9]{32,}$/', $token) === 1;
    }

    /**
     * Sanitizes SQL LIKE pattern
     */
    public static function sanitizeLike(string $pattern): string {
        return str_replace(['%', '_'], ['\%', '\_'], $pattern);
    }
}
?>
