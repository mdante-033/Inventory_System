<?php

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../classes/Mpesa.php';
require_once __DIR__ . '/../../includes/mpesa_db_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Use POST for STK Push requests.']);
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable.']);
    exit;
}

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

$orderStmt = $pdo->prepare("
    SELECT id, user_id, total_amount
    FROM orders
    WHERE id = ?
    LIMIT 1
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order || (int) ($order['user_id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

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

$insertStmt = $pdo->prepare("
    INSERT INTO mpesa_transactions (
        order_id,
        checkout_request_id,
        merchant_request_id,
        phone_number,
        amount,
        result_desc,
        status
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
