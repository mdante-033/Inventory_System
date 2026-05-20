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
function redirect($url) {
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
function generateRandomString($length = 10) {
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
function generateUUID() {
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
function formatDate($date, $format = 'M d, Y') {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Format datetime
 */
function formatDateTime($datetime, $format = 'M d, Y H:i:s') {
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    return date($format, $timestamp);
}

/**
 * Format currency
 */
function formatCurrency($amount, $currency = 'KES') {
    $symbol = $currency === 'KES' ? 'KSh ' : '$';
    return $symbol . number_format($amount, 2);
}

/**
 * Format phone number (Kenyan format)
 */
function formatPhoneNumber($phone) {
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
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Kenyan)
 */
function isValidKenyanPhone($phone) {
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
    if (isset($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
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
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Return success JSON response
 */
function jsonSuccess($message, $data = [], $statusCode = 200) {
    jsonResponse([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], $statusCode);
}

/**
 * Return error JSON response
 */
function jsonError($message, $statusCode = 400) {
    jsonResponse([
        'success' => false,
        'message' => $message
    ], $statusCode);
}

/**
 * Flash message
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get flash message
 */
function getFlashMessage($type) {
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
            echo "<div class='alert alert-$type'>$message</div>";
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
function validateCSRFToken($token) {
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
function getGravatar($email, $size = 80) {
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/$hash?s=$size&d=mp";
}

/**
 * Truncate string
 */
function truncate($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

/**
 * Calculate percentage
 */
function calculatePercentage($value, $total) {
    if ($total == 0) return 0;
    return round(($value / $total) * 100, 2);
}

/**
 * Get stock status
 */
function getStockStatus($quantity, $reorderLevel) {
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
function logActivity($userId, $action, $description) {
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
function logError($message, $context = []) {
    $logEntry = date('Y-m-d H:i:s') . " | Error: $message | Context: " . json_encode($context) . PHP_EOL;
    $logFile = LOG_PATH . 'error.log';
    
    if (LOG_ENABLED && is_writable(LOG_PATH)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

/**
 * Paginate array
 */
function paginate($items, $page = 1, $perPage = ITEMS_PER_PAGE) {
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
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowedTypes = ALLOWED_IMAGE_TYPES, $maxSize = MAX_UPLOAD_SIZE) {
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
function uploadFile($file, $destination, $prefix = '') {
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
function timeAgo($datetime) {
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

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Check if user has role
 */
function hasRole($role) {
    return getCurrentUserRole() === $role;
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasRole(ROLE_ADMIN);
}

/**
 * Check if user is manager or admin
 */
function isManager() {
    $role = getCurrentUserRole();
    return $role === ROLE_ADMIN || $role === ROLE_MANAGER;
}

// ==========================================
// Module-specific Helper Functions
// ==========================================

/**
 * Get total active products count
 */
function getTotalProducts($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE is_active = true");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get low stock products count
 */
function getLowStockCount($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products WHERE quantity <= reorder_level AND is_active = true");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get today's orders count
 */
function getTodayOrders($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURRENT_DATE");
    return $stmt->fetch()['count'] ?? 0;
}

/**
 * Get total revenue
 */
function getTotalRevenue($pdo) {
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE payment_status = 'paid'");
    return $stmt->fetch()['total'] ?? 0;
}

/**
 * Get recent orders
 */
function getRecentOrders($pdo, $limit = 10) {
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
function getTopProducts($pdo, $limit = 5) {
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
function getSalesChartData($pdo, $days = 7) {
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
function formatStatus($status, $type = 'badge') {
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
function getOrderStats($pdo) {
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
function getCategories($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories WHERE is_active = true ORDER BY name");
    return $stmt->fetchAll();
}

/**
 * Get all suppliers
 */
function getSuppliers($pdo) {
    $stmt = $pdo->query("SELECT * FROM suppliers WHERE is_active = true ORDER BY company_name");
    return $stmt->fetchAll();
}

/**
 * Get all customers
 */
function getCustomers($pdo) {
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'customer' AND is_active = true ORDER BY full_name");
    return $stmt->fetchAll();
}

/**
 * Get low stock products
 */
function getLowStockProducts($pdo, $limit = 10) {
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
function generatePagination($currentPage, $totalPages, $baseUrl) {
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
