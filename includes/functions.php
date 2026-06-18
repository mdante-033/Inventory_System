<?php
/**
 * Helper Functions
 * Global helper functions for the Inventory System
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/session.php';

/**
 * Redirect to a specific URL
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Get current URL
 */
function currentUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri = $_SERVER['REQUEST_URI'];
    return "$protocol://$host$uri";
}

/**
 * Sanitize input data
 *
 * @param mixed $data
 * @return mixed
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Shortcut for escaping output in templates
 */
if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Sanitize for database input
 *
 * @param mixed $data
 * @return mixed
 */
function sanitizeDb($data) {
    if (is_array($data)) {
        return array_map('sanitizeDb', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate random string
 */
function generateRandomString(int $length = 10): string {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Generate UUID
 */
function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Format date
 */
function formatDate(?string $date = null, string $format = 'M d, Y'): string {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Format datetime
 */
function formatDateTime(?string $datetime = null, string $format = 'M d, Y H:i:s'): string {
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    return date($format, $timestamp);
}

/**
 * Format currency
 */
function formatCurrency(float|int $amount, string $currency = 'KES'): string {
    $symbol = $currency === 'KES' ? 'KSh ' : '$';
    return $symbol . number_format($amount, 2);
}

/**
 * Format phone number (Kenyan format)
 */
function formatPhoneNumber(string $phone): string {
    // Remove any non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // If starts with 0, replace with 254
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }
    
    // If doesn't start with 254, add it
    if (substr($phone, 0, 3) !== '254') {
        $phone = '254' . $phone;
    }
    
    return $phone;
}

/**
 * Validate email
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Kenyan)
 */
function isValidKenyanPhone(string $phone): bool {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if it's a valid Kenyan phone number
    // Starts with 07 or 01 or +254
    if (preg_match('/^(07|01)/', $phone) && strlen($phone) === 10) {
        return true;
    }
    if (preg_match('/^254/', $phone) && strlen($phone) === 12) {
        return true;
    }
    return false;
}

/**
 * Get user IP address
 */
function getUserIP() {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $trustProxyHeaders = filter_var(getenv('TRUST_PROXY_HEADERS') ?: false, FILTER_VALIDATE_BOOLEAN);

    if ($trustProxyHeaders && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIps = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $candidate = $forwardedIps[0] ?? '';
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    if ($trustProxyHeaders && !empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }

    return $remoteAddress;
}

/**
 * Get user agent
 */
function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

/**
 * Check if request is AJAX
 */
function isAjax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Return JSON response
 */
function jsonResponse(mixed $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Return success JSON response
 */
function jsonSuccess(string $message, array $data = [], int $statusCode = 200): void {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], $statusCode);
}

/**
 * Return error JSON response
 */
function jsonError(string $message, int $statusCode = 400): void {
    jsonResponse([
        'success' => false,
        'message' => $message
    ], $statusCode);
}

/**
 * Flash message
 */
function setFlashMessage(string $type, string $message): void {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get flash message
 */
function getFlashMessage(string $type): ?string {
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

/**
 * Display flash messages
 */
function displayFlashMessages() {
    if (isset($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $type => $message) {
            $safeType = preg_replace('/[^a-z0-9_-]/i', '', (string) $type) ?: 'info';
            echo "<div class='alert alert-" . htmlspecialchars($safeType, ENT_QUOTES, 'UTF-8') . "'>"
                . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
                . "</div>";
        }
        unset($_SESSION['flash']);
    }
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    $tokenName = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : 'csrf_token';
    $tokenBytes = defined('CSRF_TOKEN_LENGTH') ? max(32, (int) CSRF_TOKEN_LENGTH) : 32;

    if (empty($_SESSION[$tokenName]) || !is_string($_SESSION[$tokenName]) || strlen($_SESSION[$tokenName]) < 64) {
        $_SESSION[$tokenName] = bin2hex(random_bytes($tokenBytes));
    }

    return $_SESSION[$tokenName];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken(string $token): bool {
    $tokenName = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : 'csrf_token';

    if (!isset($_SESSION[$tokenName]) || !is_string($token)) {
        return false;
    }

    return hash_equals((string) $_SESSION[$tokenName], $token);
}

/**
 * Rotate CSRF token after privilege changes.
 */
function rotateCSRFToken() {
    $tokenName = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : 'csrf_token';
    $_SESSION[$tokenName] = bin2hex(random_bytes(32));
    return $_SESSION[$tokenName];
}

/**
 * Get Gravatar image
 */
function getGravatar(string $email, int $size = 80): string {
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/$hash?s=$size&d=mp";
}

/**
 * Truncate string
 */
function truncate(string $text, int $length = 100, string $suffix = '...'): string {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

/**
 * Calculate percentage
 */
function calculatePercentage(float|int $value, float|int $total): float {
    if ($total == 0) return 0;
    return round(($value / $total) * 100, 2);
}

/**
 * Get stock status
 */
function getStockStatus(float|int $quantity, float|int $reorderLevel): array {
    if ($quantity == 0) {
        return ['status' => 'out_of_stock', 'label' => 'Out of Stock', 'class' => 'danger'];
    } elseif ($quantity <= $reorderLevel) {
        return ['status' => 'low_stock', 'label' => 'Low Stock', 'class' => 'warning'];
    } elseif ($quantity > ($reorderLevel * 3)) {
        return ['status' => 'overstocked', 'label' => 'Overstocked', 'class' => 'info'];
    } else {
        return ['status' => 'in_stock', 'label' => 'In Stock', 'class' => 'success'];
    }
}

/**
 * Log activity
 */
function logActivity(int|string|null $userId, string $action, string $description): void {
    // This would typically write to a log file or database
    $logEntry = date('Y-m-d H:i:s') . " | User: $userId | Action: $action | Description: $description" . PHP_EOL;
    $logFile = LOG_PATH . 'activity.log';
    
    if (LOG_ENABLED && is_writable(LOG_PATH)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

/**
 * Log error
 */
function logError(string $message, array $context = []): void {
    $logEntry = date('Y-m-d H:i:s') . " | Error: $message | Context: " . json_encode($context) . PHP_EOL;
    $logFile = LOG_PATH . 'error.log';
    
    if (LOG_ENABLED && is_writable(LOG_PATH)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

/**
 * Paginate array
 */
function paginate(array $items, int $page = 1, int $perPage = ITEMS_PER_PAGE): array {
    $offset = ($page - 1) * $perPage;
    $total = count($items);
    $totalPages = ceil($total / $perPage);
    $paginatedItems = array_slice($items, $offset, $perPage);
    
    return [
        'items' => $paginatedItems,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'has_prev' => $page > 1,
        'has_next' => $page < $totalPages
    ];
}

/**
 * Get file extension
 */
function getFileExtension(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Validate file upload
 */
function validateFileUpload(array $file, array $allowedTypes = ALLOWED_IMAGE_TYPES, int $maxSize = MAX_UPLOAD_SIZE): array {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
        return ['valid' => false, 'errors' => $errors];
    }
    
    if ($file['size'] > $maxSize) {
        $errors[] = "File size exceeds maximum allowed size of " . ($maxSize / 1048576) . "MB";
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $errors[] = "File type not allowed. Allowed types: " . implode(', ', $allowedTypes);
    }
    
    return ['valid' => empty($errors), 'errors' => $errors];
}

/**
 * Upload file
 */
function uploadFile(array $file, string $destination, string $prefix = ''): array {
    $extension = getFileExtension($file['name']);
    $filename = $prefix . generateRandomString(16) . '.' . $extension;
    $targetPath = $destination . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $targetPath];
    }
    
    return ['success' => false, 'error' => 'Failed to upload file'];
}

/**
 * Get time ago
 */
function timeAgo(string $datetime): string {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return formatDate($datetime);
    }
}

// Session functions moved to includes/session.php to avoid redeclaration
// Use isLoggedIn(), getCurrentUserId(), getCurrentUserRole(), hasRole(), isAdmin(), isManager()
// from includes/session.php

// ==========================================
// Module-specific Helper Functions
// ==========================================

/**
 * Get total active products count
 */
function getTotalProducts(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE is_active = true");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get low stock products count
 */
function getLowStockCount(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE quantity <= reorder_level AND is_active = true");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get today's orders count
 */
function getTodayOrders(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURRENT_DATE");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get total revenue
 */
function getTotalRevenue(PDO $pdo): float {
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE payment_status = 'paid'");
    return $stmt->fetch()['total'] ?? 0;
}

/**
 * Get recent orders
 */
function getRecentOrders(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name as customer_name 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        ORDER BY o.order_date DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Get top selling products
 */
function getTopProducts(PDO $pdo, int $limit = 5): array {
    $stmt = $pdo->prepare("
        SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold, COALESCE(SUM(oi.subtotal), 0) as revenue
        FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        WHERE p.is_active = true
        GROUP BY p.id
        ORDER BY revenue DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Get sales chart data
 */
function getSalesChartData(PDO $pdo, int $days = 7): array {
    $data = [];
    $labels = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) = ? AND payment_status = 'paid'");
        $stmt->execute([$date]);
        $data[] = floatval($stmt->fetch()['total']);
        $labels[] = date('D', strtotime($date));
    }
    return ['data' => $data, 'labels' => $labels];
}

/**
 * Format status badge
 */
function formatStatus(string $status, string $type = 'badge'): string|array {
    $statuses = [
        'active' => ['class' => 'success', 'icon' => 'check-circle'],
        'inactive' => ['class' => 'secondary', 'icon' => 'times-circle'],
        'pending' => ['class' => 'warning', 'icon' => 'clock'],
        'processing' => ['class' => 'info', 'icon' => 'cog'],
        'shipped' => ['class' => 'primary', 'icon' => 'truck'],
        'delivered' => ['class' => 'success', 'icon' => 'check-double'],
        'cancelled' => ['class' => 'danger', 'icon' => 'times'],
        'paid' => ['class' => 'success', 'icon' => 'check'],
        'unpaid' => ['class' => 'danger', 'icon' => 'times'],
        'failed' => ['class' => 'danger', 'icon' => 'exclamation-triangle'],
        'low_stock' => ['class' => 'warning', 'icon' => 'exclamation-triangle'],
        'out_of_stock' => ['class' => 'danger', 'icon' => 'times-circle'],
        'in_stock' => ['class' => 'success', 'icon' => 'check-circle']
    ];
    
    $status_key = strtolower($status);
    $config = $statuses[$status_key] ?? ['class' => 'secondary', 'icon' => 'circle'];
    
    if ($type === 'badge') {
        return "<span class='badge badge-{$config['class']}'><i class='fas fa-{$config['icon']}'></i> " . ucfirst($status) . "</span>";
    }
    return $config;
}

/**
 * Get order statistics
 */
function getOrderStats(PDO $pdo): array {
    $stats = [
        'pending' => 0,
        'processing' => 0,
        'shipped' => 0,
        'delivered' => 0,
        'cancelled' => 0,
        'total' => 0
    ];
    
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
    while ($row = $stmt->fetch()) {
        $status = $row['status'];
        if (isset($stats[$status])) {
            $stats[$status] = $row['count'];
        }
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM orders");
    $stats['total'] = $stmt->fetch()['count'] ?? 0;
    
    return $stats;
}

/**
 * Get all categories
 */
function getCategories(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM categories WHERE is_active = true ORDER BY name");
    return $stmt->fetchAll();
}

/**
 * Get all suppliers
 */
function getSuppliers(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM suppliers WHERE is_active = true ORDER BY company_name");
    return $stmt->fetchAll();
}

/**
 * Get all customers
 */
function getCustomers(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'customer' AND is_active = true ORDER BY full_name");
    return $stmt->fetchAll();
}

/**
 * Get low stock products
 */
function getLowStockProducts(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = true AND p.quantity <= p.reorder_level
        ORDER BY p.quantity ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Generate pagination HTML
 */
function generatePagination(int $currentPage, int $totalPages, string $baseUrl): string {
    $html = '<div class="pagination">';
    
    // Previous button
    if ($currentPage > 1) {
        $html .= '<a href="' . $baseUrl . '&p=' . ($currentPage - 1) . '" class="page-link"><i class="fas fa-chevron-left"></i></a>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $html .= '<a href="' . $baseUrl . '&p=1" class="page-link">1</a>';
        if ($start > 2) {
            $html .= '<span class="page-ellipsis">...</span>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $currentPage ? 'active' : '';
        $html .= '<a href="' . $baseUrl . '&p=' . $i . '" class="page-link ' . $active . '">' . $i . '</a>';
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="page-ellipsis">...</span>';
        }
        $html .= '<a href="' . $baseUrl . '&p=' . $totalPages . '" class="page-link">' . $totalPages . '</a>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $baseUrl . '&p=' . ($currentPage + 1) . '" class="page-link"><i class="fas fa-chevron-right"></i></a>';
    }
    
    $html .= '</div>';
    return $html;
}

