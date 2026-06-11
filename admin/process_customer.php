<?php

require_once __DIR__ . '/ajax/bootstrap.php';
require_once __DIR__ . '/../includes/security_logger.php';
require_once __DIR__ . '/../includes/session.php';

// 2. Enforce CSRF Token (Fixes vulnerability)
$action = $_POST['action'] ?? 'save';
$logger = new SecurityLogger($pdo);

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $logger->logFailedAuth('csrf_token', 'invalid_token');
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

// 3. Process safely
switch ($action) {
    case 'save':
        $result = adminSaveCustomer($pdo, $_POST);
        jsonResponse($result, $result['success'] ? 200 : 422);
        break;
}
$action = $_POST['action'] ?? 'save';

switch ($action) {
    case 'save':
        $result = adminSaveCustomer($pdo, $_POST);
        jsonResponse($result, $result['success'] ? 200 : 422);
        break;

    case 'view':
        $customer = adminGetCustomer($pdo, (int)($_POST['id'] ?? 0));
        if (!$customer) {
            jsonError('Customer not found.', 404);
        }
        jsonSuccess('Customer loaded.', ['customer' => $customer]);
        break;

    case 'restore':
        $result = adminRestoreCustomer($pdo, (int)($_POST['id'] ?? 0));
        jsonResponse($result, $result['success'] ? 200 : 422);
        break;

    default:
        jsonError('Unsupported customer action.', 400);
}
