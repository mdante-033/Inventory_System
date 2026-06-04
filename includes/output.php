<?php
/**
 * Output Escaping & Encoding Helper
 * Provides safe output methods for various contexts
 */

class OutputEscaper {
    /**
     * Escapes string for HTML context
     */
    public static function escapeHtml(mixed $text): string {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Alias for escapeHtml - shorter name for templates
     */
    public static function e(mixed $text): string {
        return self::escapeHtml($text);
    }

    /**
     * Escapes string for HTML attributes
     */
    public static function escapeAttr(mixed $text): string {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escapes for JavaScript context
     */
    public static function escapeJs(mixed $data): string {
        if (is_array($data) || is_object($data)) {
            $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } else {
            $json = json_encode((string) $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }
        return $json !== false ? $json : '""';
    }

    /**
     * Escapes for URL context
     */
    public static function escapeUrl(mixed $url): string {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escapes for CSS context (basic)
     */
    public static function escapeCss(string $css): string {
        return preg_replace_callback(
            '/[^a-zA-Z0-9\s\-_#%.,\/():;]/u',
            static fn($m) => '\\' . dechex(ord($m[0])),
            $css
        );
    }

    /**
     * Encodes data for JSON response
     */
    public static function encodeJson(mixed $data, int $options = 0): string {
        $options |= JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $encoded = json_encode($data, $options);
        return $encoded !== false ? $encoded : '{}';
    }

    /**
     * Escapes CSV field
     */
    public static function escapeCsv(string $field): string {
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }

    /**
     * Escapes for HTML comment
     */
    public static function escapeHtmlComment(string $text): string {
        return str_replace('--', '-', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Strips HTML tags safely
     */
    public static function stripHtml(string $text): string {
        return strip_tags($text);
    }

    /**
     * Removes all HTML and special characters
     */
    public static function plainText(string $text): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    /**
     * Escapes for SQL LIKE pattern (requires manual escaping in query)
     */
    public static function escapeLike(string $pattern): string {
        return addcslashes($pattern, '%_\\');
    }

    /**
     * Creates safe data attribute for HTML
     */
    public static function dataAttr(string $name, mixed $value): string {
        $name = preg_replace('/[^a-z0-9\-]/i', '', $name);
        return 'data-' . $name . '="' . self::escapeAttr($value) . '"';
    }

    /**
     * Converts array to safe HTML attributes string
     */
    public static function htmlAttributes(array $attributes): string {
        $result = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $result .= ' ' . self::escapeAttr((string) $key);
            } else {
                $result .= ' ' . self::escapeAttr((string) $key) . '="' . self::escapeAttr((string) $value) . '"';
            }
        }
        return $result;
    }

    /**
     * Sanitizes and formats user bio/description text
     */
    public static function formatUserText(string $text, int $maxLength = 500): string {
        $text = trim($text);
        $text = substr($text, 0, $maxLength);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return nl2br($text);
    }

    /**
     * Sanitizes URL for href attribute
     */
    public static function safeUrl(string $url): string {
        // Only allow http, https, mailto, and relative URLs
        if (preg_match('/^(https?:\/\/|mailto:|\/|\.\/|#)/i', $url)) {
            return self::escapeAttr($url);
        }
        return '';
    }

    /**
     * Creates safe option attributes for select elements
     */
    public static function selectOption(mixed $value, mixed $currentValue, string $label): string {
        $selected = ($value == $currentValue) ? ' selected' : '';
        return '<option value="' . self::escapeAttr($value) . '"' . $selected . '>' . self::escapeHtml($label) . '</option>';
    }

    /**
     * Truncates text safely with ellipsis
     */
    public static function truncate(string $text, int $length = 100): string {
        $text = self::escapeHtml($text);
        if (strlen($text) > $length) {
            return substr($text, 0, $length) . '...';
        }
        return $text;
    }

    /**
     * Converts array to JavaScript object notation
     */
    public static function jsArray(array $data): string {
        return 'const data = ' . self::escapeJs($data) . ';';
    }
}
?>
