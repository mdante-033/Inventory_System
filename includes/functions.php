<?php
/**
 * Helper Functions
 * Global helper functions for the Inventory System
 *
 * FIX: All functions that are also defined in includes/session.php are now
 * wrapped with if (!function_exists()) to prevent "Cannot redeclare" fatal errors.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/session.php';

// ── Output / redirect helpers ─────────────────────────────────────────────

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function currentUrl(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $uri  = $_SERVER['REQUEST_URI'];
    return "$protocol://$host$uri";
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

if (!function_exists('e')) {
    function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

function sanitizeDb($data) {
    if (is_array($data)) {
        return array_map('sanitizeDb', $data);
    }
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function generateRandomString(int $length = 10): string {
    $characters       = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString     = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function formatDate(?string $date = null, string $format = 'M d, Y'): string {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime = null, string $format = 'M d, Y H:i:s'): string {
    if (empty($datetime)) return '';
    return date($format, strtotime($datetime));
}

function formatCurrency(float|int $amount, string $currency = 'KES'): string {
    $symbol = $currency === 'KES' ? 'KSh ' : '$';
    return $symbol . number_format($amount, 2);
}

function formatPhoneNumber(string $phone): string {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }
    if (substr($phone, 0, 3) !== '254') {
        $phone = '254' . $phone;
    }
    return $phone;
}

function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidKenyanPhone(string $phone): bool {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (preg_match('/^(07|01)/', $phone) && strlen($phone) === 10) return true;
    if (preg_match('/^254/', $phone) && strlen($phone) === 12) return true;
    return false;
}

function getUserIP(): string {
    if (isset($_SERVER['HTTP_CLIENT_IP']))       return $_SERVER['HTTP_CLIENT_IP'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function getUserAgent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

function isAjax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function jsonResponse($data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function jsonSuccess(string $message, array $data = [], int $statusCode = 200): void {
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data], $statusCode);
}

function jsonError(string $message, int $statusCode = 400): void {
    jsonResponse(['success' => false, 'message' => $message], $statusCode);
}

function setFlashMessage(string $type, string $message): void {
    $_SESSION['flash'][$type] = $message;
}

function getFlashMessage(string $type): ?string {
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

function displayFlashMessages(): void {
    if (isset($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $type => $message) {
            echo "<div class='alert alert-$type'>$message</div>";
        }
        unset($_SESSION['flash']);
    }
}

function generateCSRFToken(): string {
    $tokenName  = defined('CSRF_TOKEN_NAME')   ? CSRF_TOKEN_NAME   : 'csrf_token';
    $tokenBytes = defined('CSRF_TOKEN_LENGTH')  ? max(32, (int) CSRF_TOKEN_LENGTH) : 32;
    if (empty($_SESSION[$tokenName]) || !is_string($_SESSION[$tokenName]) || strlen($_SESSION[$tokenName]) < 64) {
        $_SESSION[$tokenName] = bin2hex(random_bytes($tokenBytes));
    }
    return $_SESSION[$tokenName];
}

function validateCSRFToken(string $token): bool {
    $tokenName = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : 'csrf_token';
    if (!isset($_SESSION[$tokenName]) || !is_string($token)) return false;
    return hash_equals((string) $_SESSION[$tokenName], $token);
}

function rotateCSRFToken(): string {
    $tokenName = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : 'csrf_token';
    $_SESSION[$tokenName] = bin2hex(random_bytes(32));
    return $_SESSION[$tokenName];
}

function getGravatar(string $email, int $size = 80): string {
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/$hash?s=$size&d=mp";
}

function truncate(string $text, int $length = 100, string $suffix = '...'): string {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . $suffix;
}

function calculatePercentage(float|int $value, float|int $total): float {
    if ($total == 0) return 0;
    return round(($value / $total) * 100, 2);
}

function getStockStatus(float|int $quantity, float|int $reorderLevel): array {
    if ($quantity == 0)
        return ['status' => 'out_of_stock', 'label' => 'Out of Stock', 'class' => 'danger'];
    if ($quantity <= $reorderLevel)
        return ['status' => 'low_stock', 'label' => 'Low Stock', 'class' => 'warning'];
    if ($quantity > ($reorderLevel * 3))
        return ['status' => 'overstocked', 'label' => 'Overstocked', 'class' => 'info'];
    return ['status' => 'in_stock', 'label' => 'In Stock', 'class' => 'success'];
}

function logActivity($userId, string $action, string $description): void {
    $logEntry = date('Y-m-d H:i:s') . " | User: $userId | Action: $action | Description: $description" . PHP_EOL;
    $logFile  = LOG_PATH . 'activity.log';
    if (defined('LOG_ENABLED') && LOG_ENABLED && is_writable(LOG_PATH)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

function logError(string $message, array $context = []): void {
    $logEntry = date('Y-m-d H:i:s') . " | Error: $message | Context: " . json_encode($context) . PHP_EOL;
    $logFile  = LOG_PATH . 'error.log';
    if (defined('LOG_ENABLED') && LOG_ENABLED && is_writable(LOG_PATH)) {
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

function paginate(array $items, int $page = 1, int $perPage = 15): array {
    $perPage      = defined('ITEMS_PER_PAGE') ? ITEMS_PER_PAGE : $perPage;
    $offset       = ($page - 1) * $perPage;
    $total        = count($items);
    $totalPages   = (int) ceil($total / $perPage);
    return [
        'items'       => array_slice($items, $offset, $perPage),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

function getFileExtension(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function validateFileUpload(array $file, array $allowedTypes = [], int $maxSize = 0): array {
    if (empty($allowedTypes) && defined('ALLOWED_IMAGE_TYPES')) $allowedTypes = ALLOWED_IMAGE_TYPES;
    if ($maxSize === 0 && defined('MAX_UPLOAD_SIZE'))            $maxSize      = MAX_UPLOAD_SIZE;

    $errors = [];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $file['error'];
        return ['valid' => false, 'errors' => $errors];
    }
    if ($file['size'] > $maxSize)
        $errors[] = "File size exceeds maximum allowed size of " . ($maxSize / 1048576) . "MB";

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowedTypes))
        $errors[] = "File type not allowed. Allowed types: " . implode(', ', $allowedTypes);

    return ['valid' => empty($errors), 'errors' => $errors];
}

function uploadFile(array $file, string $destination, string $prefix = ''): array {
    $extension  = getFileExtension($file['name']);
    $filename   = $prefix . generateRandomString(16) . '.' . $extension;
    $targetPath = $destination . $filename;
    if (move_uploaded_file($file['tmp_name'], $targetPath))
        return ['success' => true, 'filename' => $filename, 'path' => $targetPath];
    return ['success' => false, 'error' => 'Failed to upload file'];
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  { $m = floor($diff/60);   return $m . ' minute'  . ($m>1?'s':'') . ' ago'; }
    if ($diff < 86400) { $h = floor($diff/3600);  return $h . ' hour'    . ($h>1?'s':'') . ' ago'; }
    if ($diff < 604800){ $d = floor($diff/86400); return $d . ' day'     . ($d>1?'s':'') . ' ago'; }
    return formatDate($datetime);
}

// ── Auth helpers — guarded because session.php declares these too ─────────

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool {
        return isset($_SESSION['logged_in']) &&
               $_SESSION['logged_in'] === true &&
               isset($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole(): string {
        return $_SESSION['role'] ?? 'guest';
    }
}

if (!function_exists('hasRole')) {
    function hasRole(string $role): bool {
        return getCurrentUserRole() === $role;
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin(): bool {
        return hasRole(defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin');
    }
}

if (!function_exists('isManager')) {
    function isManager(): bool {
        $role = getCurrentUserRole();
        $admin   = defined('ROLE_ADMIN')   ? ROLE_ADMIN   : 'admin';
        $manager = defined('ROLE_MANAGER') ? ROLE_MANAGER : 'manager';
        return $role === $admin || $role === $manager;
    }
}

// ── DB query helpers ──────────────────────────────────────────────────────

function getTotalProducts(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = true");
    return (int) $stmt->fetchColumn();
}

function getLowStockCount(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= reorder_level AND is_active = true");
    return (int) $stmt->fetchColumn();
}

function getTodayOrders(PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURRENT_DATE");
    return (int) $stmt->fetchColumn();
}

function getTotalRevenue(PDO $pdo): float {
    $stmt = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE payment_status = 'paid'");
    return (float) $stmt->fetchColumn();
}

function getRecentOrders(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name AS customer_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.order_date DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function getTopProducts(PDO $pdo, int $limit = 5): array {
    $stmt = $pdo->prepare("
        SELECT p.*,
               COALESCE(SUM(oi.quantity),0) AS total_sold,
               COALESCE(SUM(oi.subtotal),0) AS revenue
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

function getSalesChartData(PDO $pdo, int $days = 7): array {
    $data = []; $labels = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(order_date)=? AND payment_status='paid'");
        $stmt->execute([$date]);
        $data[]   = (float) $stmt->fetchColumn();
        $labels[] = date('D', strtotime($date));
    }
    return ['data' => $data, 'labels' => $labels];
}

function formatStatus(string $status, string $type = 'badge'): string {
    $map = [
        'active'      => ['class'=>'success',   'icon'=>'check-circle'],
        'inactive'    => ['class'=>'secondary',  'icon'=>'times-circle'],
        'pending'     => ['class'=>'warning',    'icon'=>'clock'],
        'processing'  => ['class'=>'info',       'icon'=>'cog'],
        'shipped'     => ['class'=>'primary',    'icon'=>'truck'],
        'delivered'   => ['class'=>'success',    'icon'=>'check-double'],
        'cancelled'   => ['class'=>'danger',     'icon'=>'times'],
        'paid'        => ['class'=>'success',    'icon'=>'check'],
        'unpaid'      => ['class'=>'danger',     'icon'=>'times'],
        'failed'      => ['class'=>'danger',     'icon'=>'exclamation-triangle'],
        'low_stock'   => ['class'=>'warning',    'icon'=>'exclamation-triangle'],
        'out_of_stock'=> ['class'=>'danger',     'icon'=>'times-circle'],
        'in_stock'    => ['class'=>'success',    'icon'=>'check-circle'],
    ];
    $cfg = $map[strtolower($status)] ?? ['class'=>'secondary','icon'=>'circle'];
    if ($type === 'badge')
        return "<span class='badge badge-{$cfg['class']}'><i class='fas fa-{$cfg['icon']}'></i> " . ucfirst($status) . "</span>";
    return $cfg;
}

function getOrderStats(PDO $pdo): array {
    $stats = ['pending'=>0,'processing'=>0,'shipped'=>0,'delivered'=>0,'cancelled'=>0,'total'=>0];
    $stmt  = $pdo->query("SELECT status, COUNT(*) AS count FROM orders GROUP BY status");
    while ($row = $stmt->fetch()) {
        if (isset($stats[$row['status']])) $stats[$row['status']] = (int) $row['count'];
    }
    $stats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    return $stats;
}

function getCategories(PDO $pdo): array {
    return $pdo->query("SELECT * FROM categories WHERE is_active = true ORDER BY name")->fetchAll();
}

function getSuppliers(PDO $pdo): array {
    // company_name falls back to name for compatibility
    return $pdo->query("SELECT * FROM suppliers WHERE is_active = true ORDER BY name")->fetchAll();
}

function getCustomers(PDO $pdo): array {
    return $pdo->query("SELECT * FROM users WHERE role = 'customer' AND is_active = true ORDER BY full_name")->fetchAll();
}

function getLowStockProducts(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = true AND p.quantity <= p.reorder_level
        ORDER BY p.quantity ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function generatePagination(int $currentPage, int $totalPages, string $baseUrl): string {
    $html  = '<div class="pagination">';
    if ($currentPage > 1)
        $html .= '<a href="'.$baseUrl.'&p='.($currentPage-1).'" class="page-link"><i class="fas fa-chevron-left"></i></a>';
    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);
    if ($start > 1) {
        $html .= '<a href="'.$baseUrl.'&p=1" class="page-link">1</a>';
        if ($start > 2) $html .= '<span class="page-ellipsis">...</span>';
    }
    for ($i = $start; $i <= $end; $i++)
        $html .= '<a href="'.$baseUrl.'&p='.$i.'" class="page-link'.($i==$currentPage?' active':'').'">'.$i.'</a>';
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="page-ellipsis">...</span>';
        $html .= '<a href="'.$baseUrl.'&p='.$totalPages.'" class="page-link">'.$totalPages.'</a>';
    }
    if ($currentPage < $totalPages)
        $html .= '<a href="'.$baseUrl.'&p='.($currentPage+1).'" class="page-link"><i class="fas fa-chevron-right"></i></a>';
    $html .= '</div>';
    return $html;
}
