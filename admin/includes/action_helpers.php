<?php

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../modules/order_module.php';
require_once __DIR__ . '/../../modules/payment_module.php';

function adminLogAction($action, array $context = []): void
{
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $entry = '[' . date('Y-m-d H:i:s') . '] ' . $action . ' ' . json_encode([
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'admin_username' => $_SESSION['admin_username'] ?? null,
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    @file_put_contents($logDir . '/admin_actions.log', $entry . PHP_EOL, FILE_APPEND);
}

function adminRequireCsrf(): void
{
    global $adminAuth;

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $isValidAdminToken = isset($adminAuth)
        && method_exists($adminAuth, 'validateCsrfToken')
        && $adminAuth->validateCsrfToken((string) $token);

    if (!$isValidAdminToken) {
        jsonError('Your session token is invalid. Refresh the page and try again.', 419);
    }
}

function adminFetchCustomers(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.username,
            u.email,
            u.full_name,
            u.phone,
            u.customer_group,
            u.is_active,
            u.created_at,
            COUNT(o.id) AS order_count,
            COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS total_spent
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.role = 'customer'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminGetCustomer(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, username, email, full_name, phone, customer_group, is_active, created_at
        FROM users
        WHERE id = ? AND role = 'customer'
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        return null;
    }

    $orderStmt = $pdo->prepare("
        SELECT id, order_number, status, payment_status, total_amount, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $orderStmt->execute([$customerId]);
    $customer['orders'] = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

    return $customer;
}

function adminSaveCustomer(PDO $pdo, array $data): array
{
    $id = (int)($data['id'] ?? 0);
    $username = trim((string)($data['username'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $group = trim((string)($data['customer_group'] ?? 'regular')) ?: 'regular';
    $password = (string)($data['password'] ?? '');

    if ($username === '' || $fullName === '' || $email === '') {
        return ['success' => false, 'message' => 'Name, username, and email are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Enter a valid email address.'];
    }

    if ($phone !== '' && !isValidKenyanPhone($phone)) {
        return ['success' => false, 'message' => 'Enter a valid phone number.'];
    }

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $fields = [
                'username = ?',
                'full_name = ?',
                'email = ?',
                'phone = ?',
                'customer_group = ?',
                'updated_at = CURRENT_TIMESTAMP',
            ];
            $params = [$username, $fullName, $email, $phone, $group];

            if ($password !== '') {
                $fields[] = 'password = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $params[] = $id;
            $stmt = $pdo->prepare("
                UPDATE users
                SET " . implode(', ', $fields) . "
                WHERE id = ? AND role = 'customer'
            ");
            $stmt->execute($params);
            adminLogAction('customer.updated', ['customer_id' => $id]);
        } else {
            if ($password === '') {
                return ['success' => false, 'message' => 'Password is required for new customers.'];
            }

            if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        username, email, full_name, password, role, is_active, created_at, updated_at, phone, customer_group
                    ) VALUES (?, ?, ?, ?, 'customer', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([
                    $username,
                    $email,
                    $fullName,
                    password_hash($password, PASSWORD_DEFAULT),
                    $phone,
                    $group,
                ]);
                $id = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        username, email, full_name, password, role, is_active, created_at, updated_at, phone, customer_group
                    ) VALUES (?, ?, ?, ?, 'customer', true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?, ?)
                ");
                $stmt->execute([
                    $username,
                    $email,
                    $fullName,
                    password_hash($password, PASSWORD_DEFAULT),
                    $phone,
                    $group,
                ]);
                $id = (int)$pdo->lastInsertId();
            }
            adminLogAction('customer.created', ['customer_id' => $id]);
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Customer saved successfully.', 'id' => $id];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer save error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to save customer.'];
    }
}

function adminDeleteCustomer(PDO $pdo, int $customerId): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
        $stmt->execute([$customerId]);
        $hasOrders = (int)$stmt->fetchColumn() > 0;

        $stmt = $pdo->prepare("
            UPDATE users
            SET is_active = false, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND role = 'customer'
        ");
        $stmt->execute([$customerId]);

        $pdo->commit();
        adminLogAction('customer.deleted', ['customer_id' => $customerId, 'had_orders' => $hasOrders]);

        return [
            'success' => true,
            'message' => $hasOrders
                ? 'Customer archived because they have existing orders.'
                : 'Customer archived successfully.',
            'undo' => ['type' => 'customer', 'id' => $customerId],
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Customer delete error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to archive customer.'];
    }
}

function adminRestoreCustomer(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare("UPDATE users SET is_active = true, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$customerId]);
    adminLogAction('customer.restored', ['customer_id' => $customerId]);
    return ['success' => true, 'message' => 'Customer restored successfully.'];
}

function adminFetchProducts(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, sku, name, description, category_id, unit_price, quantity, reorder_level, is_active, created_at
        FROM products
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminGetProduct(PDO $pdo, int $productId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, sku, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    return $product ?: null;
}

function adminSaveProduct(PDO $pdo, array $data): array
{
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $sku = trim((string)($data['sku'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $categoryId = ($data['category_id'] ?? '') === '' ? null : (int)$data['category_id'];
    $unitPrice = (float)($data['unit_price'] ?? 0);
    $costPrice = (float)($data['cost_price'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 0);
    $reorderLevel = (int)($data['reorder_level'] ?? 10);
    $isActive = !empty($data['is_active']);

    if ($name === '' || $unitPrice < 0 || $quantity < 0) {
        return ['success' => false, 'message' => 'Name, price, and quantity are required.'];
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE products
                SET sku = ?, name = ?, description = ?, category_id = ?, unit_price = ?, cost_price = ?,
                    quantity = ?, reorder_level = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$sku ?: null, $name, $description ?: null, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $isActive, $id]);
            adminLogAction('product.updated', ['product_id' => $id]);
            return ['success' => true, 'message' => 'Product updated successfully.', 'id' => $id];
        }

        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $stmt = $pdo->prepare("
                INSERT INTO products (
                    sku, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                RETURNING id
            ");
            $stmt->execute([$sku ?: null, $name, $description ?: null, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $isActive]);
            $id = (int)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO products (
                    sku, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$sku ?: null, $name, $description ?: null, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        adminLogAction('product.created', ['product_id' => $id]);
        return ['success' => true, 'message' => 'Product created successfully.', 'id' => $id];
    } catch (PDOException $e) {
        error_log('Product save error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to save product.'];
    }
}

function adminDeleteProduct(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_id = ? AND o.status IN ('pending', 'processing', 'shipped')
    ");
    $stmt->execute([$productId]);

    if ((int)$stmt->fetchColumn() > 0) {
        return ['success' => false, 'message' => 'This product is attached to active orders and cannot be archived.'];
    }

    $stmt = $pdo->prepare("UPDATE products SET is_active = false, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$productId]);
    adminLogAction('product.deleted', ['product_id' => $productId]);

    return [
        'success' => true,
        'message' => 'Product archived successfully.',
        'undo' => ['type' => 'product', 'id' => $productId],
    ];
}

function adminRestoreProduct(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("UPDATE products SET is_active = true, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$productId]);
    adminLogAction('product.restored', ['product_id' => $productId]);
    return ['success' => true, 'message' => 'Product restored successfully.'];
}

function adminToggleProduct(PDO $pdo, int $productId): array
{
    $product = adminGetProduct($pdo, $productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found.'];
    }

    $newStatus = empty($product['is_active']);
    $stmt = $pdo->prepare("UPDATE products SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$newStatus, $productId]);
    adminLogAction('product.toggled', ['product_id' => $productId, 'is_active' => $newStatus]);

    return [
        'success' => true,
        'message' => $newStatus ? 'Product activated successfully.' : 'Product deactivated successfully.',
        'is_active' => $newStatus,
    ];
}

function adminDuplicateProduct(PDO $pdo, int $productId): array
{
    $product = adminGetProduct($pdo, $productId);
    if (!$product) {
        return ['success' => false, 'message' => 'Product not found.'];
    }

    $newSku = $product['sku'] ? $product['sku'] . '-COPY-' . strtoupper(substr(generateRandomString(4), 0, 4)) : null;
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $stmt = $pdo->prepare("
            INSERT INTO products (
                sku, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            $newSku,
            $product['name'] . ' Copy',
            $product['description'],
            $product['category_id'],
            $product['unit_price'],
            $product['cost_price'],
            0,
            $product['reorder_level'],
            false,
        ]);
        $newId = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO products (
                sku, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $newSku,
            $product['name'] . ' Copy',
            $product['description'],
            $product['category_id'],
            $product['unit_price'],
            $product['cost_price'],
            0,
            $product['reorder_level'],
            false,
        ]);
        $newId = (int)$pdo->lastInsertId();
    }
    adminLogAction('product.duplicated', ['source_product_id' => $productId, 'new_product_id' => $newId]);
    return ['success' => true, 'message' => 'Product duplicated successfully.', 'id' => $newId];
}

function adminAdjustInventory(PDO $pdo, int $productId, int $quantityDelta, string $reason = ''): array
{
    $stmt = $pdo->prepare("
        UPDATE products
        SET quantity = quantity + ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$quantityDelta, $productId]);
    adminLogAction('product.inventory_adjusted', ['product_id' => $productId, 'delta' => $quantityDelta, 'reason' => $reason]);
    return ['success' => true, 'message' => 'Inventory updated successfully.'];
}

function adminFetchOrders(PDO $pdo, ?string $status = null, ?string $paymentStatus = null): array
{
    $orderModule = new OrderModule($pdo);
    return $orderModule->getOrders(null, $status, $paymentStatus);
}

function adminGetOrder(PDO $pdo, int $orderId): ?array
{
    $orderModule = new OrderModule($pdo);
    return $orderModule->getOrderDetails($orderId);
}

function adminUpdateOrder(PDO $pdo, array $data): array
{
    $orderId = (int)($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        return ['success' => false, 'message' => 'Invalid order selected.'];
    }

    $status = trim((string)($data['status'] ?? 'pending'));
    $paymentStatus = trim((string)($data['payment_status'] ?? 'pending'));
    $notes = trim((string)($data['notes'] ?? ''));
    $shippingAddress = trim((string)($data['shipping_address'] ?? ''));
    $billingAddress = trim((string)($data['billing_address'] ?? ''));
    $customerName = trim((string)($data['customer_name'] ?? ''));
    $customerEmail = trim((string)($data['customer_email'] ?? ''));

    $paymentModule = new PaymentModule($pdo);
    $syncResult = $paymentModule->updatePaymentStatusFromAdmin($orderId, $paymentStatus, [
        'order_status' => $status,
        'transaction_id' => trim((string)($data['transaction_id'] ?? '')) ?: null,
        'payment_gateway' => trim((string)($data['payment_gateway'] ?? '')) ?: 'admin',
        'notes' => $notes,
        'source' => 'admin/process_order.php',
        'generate_transaction_id' => $paymentStatus === 'paid',
    ]);

    if (!$syncResult['success']) {
        return $syncResult;
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET customer_name = COALESCE(NULLIF(?, ''), customer_name),
            customer_email = COALESCE(NULLIF(?, ''), customer_email),
            shipping_address = COALESCE(NULLIF(?, ''), shipping_address),
            billing_address = COALESCE(NULLIF(?, ''), billing_address),
            notes = COALESCE(NULLIF(?, ''), notes),
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$customerName, $customerEmail, $shippingAddress, $billingAddress, $notes, $orderId]);
    adminLogAction('order.updated', ['order_id' => $orderId, 'status' => $status, 'payment_status' => $paymentStatus]);

    return ['success' => true, 'message' => 'Order updated successfully.'];
}

function adminCancelOrder(PDO $pdo, int $orderId, string $reason): array
{
    $paymentModule = new PaymentModule($pdo);
    $order = adminGetOrder($pdo, $orderId);
    if (!$order) {
        return ['success' => false, 'message' => 'Order not found.'];
    }

    $result = $paymentModule->updatePaymentStatusFromAdmin($orderId, $order['payment_status'] ?? 'pending', [
        'order_status' => 'cancelled',
        'notes' => $reason,
        'source' => 'admin.cancel_order',
    ]);

    if (!$result['success']) {
        return $result;
    }

    $existingNotes = trim((string)($order['notes'] ?? ''));
    $updatedNotes = $existingNotes === ''
        ? 'Cancelled: ' . $reason
        : $existingNotes . PHP_EOL . 'Cancelled: ' . $reason;
    $stmt = $pdo->prepare("UPDATE orders SET notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$updatedNotes, $orderId]);
    adminLogAction('order.cancelled', ['order_id' => $orderId, 'reason' => $reason]);
    return ['success' => true, 'message' => 'Order cancelled successfully.'];
}

function adminFetchPayments(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            pt.*,
            o.order_number,
            o.status AS order_status,
            o.payment_status AS order_payment_status,
            o.total_amount AS order_total,
            u.username,
            u.email
        FROM payment_transactions pt
        LEFT JOIN orders o ON pt.order_id = o.id
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY pt.created_at DESC, pt.id DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminGetPayment(PDO $pdo, int $paymentId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            pt.*,
            o.order_number,
            o.status AS order_status,
            o.payment_status AS order_payment_status,
            o.total_amount AS order_total,
            o.customer_name,
            o.customer_email
        FROM payment_transactions pt
        LEFT JOIN orders o ON o.id = pt.order_id
        WHERE pt.id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    return $payment ?: null;
}

function adminRefundPayment(PDO $pdo, int $paymentId, string $reason): array
{
    $payment = adminGetPayment($pdo, $paymentId);
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment not found.'];
    }

    $paymentModule = new PaymentModule($pdo);
    $result = $paymentModule->updatePaymentStatusFromAdmin((int)$payment['order_id'], 'refunded', [
        'transaction_id' => $payment['transaction_id'] ?? null,
        'payment_gateway' => $payment['payment_gateway'] ?? 'admin',
        'reference_number' => $payment['reference_number'] ?? null,
        'notes' => $reason,
        'source' => 'admin.refund_payment',
    ]);

    if ($result['success']) {
        adminLogAction('payment.refunded', ['payment_id' => $paymentId, 'order_id' => $payment['order_id'], 'reason' => $reason]);
    }

    return $result;
}

function adminCapturePayment(PDO $pdo, int $paymentId): array
{
    $payment = adminGetPayment($pdo, $paymentId);
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment not found.'];
    }

    $paymentModule = new PaymentModule($pdo);
    $result = $paymentModule->updatePaymentStatusFromAdmin((int)$payment['order_id'], 'paid', [
        'transaction_id' => $payment['transaction_id'] ?? null,
        'payment_gateway' => $payment['payment_gateway'] ?? 'admin',
        'reference_number' => $payment['reference_number'] ?? null,
        'source' => 'admin.capture_payment',
        'generate_transaction_id' => empty($payment['transaction_id']),
    ]);

    if ($result['success']) {
        adminLogAction('payment.captured', ['payment_id' => $paymentId, 'order_id' => $payment['order_id']]);
    }

    return $result;
}
