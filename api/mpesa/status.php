<?php

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/session.php';

require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../includes/mpesa_db_helper.php';

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

$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid order_id is required.']);
    exit;
}

$paymentMethodSelect = mpesaColumnExists($pdo, 'orders', 'payment_method') ? 'o.payment_method,' : "'mpesa' AS payment_method,";
$receiptSelect = mpesaColumnExists($pdo, 'orders', 'mpesa_receipt_number') ? 'o.mpesa_receipt_number,' : "NULL::VARCHAR AS mpesa_receipt_number,";

$sql = "
    SELECT
        o.id,
        o.user_id,
        o.total_amount,
        o.status,
        o.payment_status,
        {$paymentMethodSelect}
        {$receiptSelect}
        mt.checkout_request_id,
        mt.merchant_request_id,
        mt.phone_number,
        mt.amount AS mpesa_amount,
        mt.result_code,
        mt.result_desc,
        mt.status AS mpesa_status,
        mt.transaction_date,
        mt.updated_at AS mpesa_updated_at
    FROM orders o
    LEFT JOIN LATERAL (
        SELECT *
        FROM mpesa_transactions
        WHERE order_id = o.id
        ORDER BY id DESC
        LIMIT 1
    ) mt ON true
    WHERE o.id = ?
      AND o.user_id = ?
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$orderId, (int) ($_SESSION['user_id'] ?? 0)]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$paymentStatus = (string) ($order['payment_status'] ?? '');
$mpesaStatus = (string) ($order['mpesa_status'] ?? 'pending');
$isPaid = $paymentStatus === 'paid' || $mpesaStatus === 'completed';
$isFailed = $paymentStatus === 'failed' || $mpesaStatus === 'failed';

echo json_encode([
    'success' => true,
    'order_id' => (int) $order['id'],
    'order_status' => (string) ($order['status'] ?? 'pending'),
    'payment_status' => $isPaid ? 'paid' : ($isFailed ? 'failed' : 'pending'),
    'payment_method' => (string) ($order['payment_method'] ?? 'mpesa'),
    'total_amount' => (float) ($order['total_amount'] ?? 0),
    'mpesa' => [
        'status' => $mpesaStatus,
        'checkout_request_id' => $order['checkout_request_id'] ?? null,
        'merchant_request_id' => $order['merchant_request_id'] ?? null,
        'phone_number' => $order['phone_number'] ?? null,
        'amount' => isset($order['mpesa_amount']) ? (float) $order['mpesa_amount'] : null,
        'result_code' => isset($order['result_code']) ? (int) $order['result_code'] : null,
        'result_desc' => $order['result_desc'] ?? null,
        'receipt_number' => $order['mpesa_receipt_number'] ?? null,
        'transaction_date' => $order['transaction_date'] ?? null,
        'updated_at' => $order['mpesa_updated_at'] ?? null,
    ],
]);
