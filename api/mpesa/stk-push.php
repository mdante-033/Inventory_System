<?php

declare(strict_types=1);

header('Content-Type: application/json');

// 1. Include dependencies first
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../classes/Mpesa.php';
require_once __DIR__ . '/../../includes/mpesa_db_helper.php';
require_once __DIR__ . '/../../includes/rate_limiter.php';

// 2. Enforce Request Method (Prevents bots from spamming GET requests)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST for STK Push requests.']);
    exit;
}

// 3. Enforce Authentication (Prevents anonymous spam)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// 4. Initialize Database and Rate Limiter
if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$rateLimiter = new RateLimiter($pdo, 'mpesa:' . $userId);

// 5. Apply Rate Limiting safely (Max 5 requests per 10 minutes)
// 6. Define the limit parameters (5 attempts per 10 minutes)
$maxAttempts = 5;
$timeWindow = 10;

// 7. Check if the user is currently rate limited
if ($rateLimiter->isLimited($maxAttempts, $timeWindow)) {
    http_response_code(429);
    echo json_encode([
        'success' => false, 
        'message' => 'Too many requests. Please wait 10 minutes before trying again.'
    ]);
    exit;
}

// 8. Record the attempt so the counter increases
$rateLimiter->recordAttempt(false);

// 9. Proceed with standard validations (CSRF, Order ID, Phone Number)
if (!mpesaTransactionsTableExists($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Run sql/mpesa_postgresql.sql before using M-Pesa payments.']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if (!validateCSRFToken((string) ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session token is invalid. Refresh the page and try again.']);
    exit;
}

$orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$phoneNumber = trim((string) ($input['phone'] ?? ''));

if ($orderId === false || $phoneNumber === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'order_id and phone are required.']);
    exit;
}

// 7. Fetch Order & Verify Ownership
$orderStmt = $pdo->prepare("SELECT id, user_id, total_amount FROM orders WHERE id = ? LIMIT 1");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order || (int) ($order['user_id'] ?? 0) !== $userId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

// 8. Initiate STK Push
$mpesa = new Mpesa();
if (!$mpesa->validatePhoneNumber($phoneNumber)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Use a valid Safaricom number like 2547XXXXXXXX.']);
    exit;
}

$stkResult = $mpesa->stkPush($phoneNumber, (float) ($order['total_amount'] ?? 0), 'ORD-' . $orderId, 'Payment for order #' . $orderId);
if (!$stkResult['success']) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => $stkResult['message'] ?? 'Unable to initiate STK Push.']);
    exit;
}

// 9. Record Transaction
$insertStmt = $pdo->prepare("
    INSERT INTO mpesa_transactions (
        order_id, checkout_request_id, merchant_request_id, 
        phone_number, amount, result_desc, status
    ) VALUES (?, ?, ?, ?, ?, ?, 'pending')
");
$insertStmt->execute([
    $orderId,
    $stkResult['checkout_request_id'] ?? null,
    $stkResult['merchant_request_id'] ?? null,
    $mpesa->formatPhoneNumber($phoneNumber),
    (float) ($order['total_amount'] ?? 0),
    $stkResult['customer_message'] ?? $stkResult['message'] ?? 'STK Push sent',
]);

echo json_encode([
    'success' => true,
    'message' => $stkResult['customer_message'] ?? 'STK Push sent successfully.',
    'checkout_request_id' => $stkResult['checkout_request_id'] ?? null,
    'merchant_request_id' => $stkResult['merchant_request_id'] ?? null,
]);
