<?php 
/**
 * StockFlow - Inventory Management System
 * Main Dashboard Index File (Modular) - Restyled
 */

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/admin/includes/auth-check.php';
require_once __DIR__ . '/includes/functions.php';

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

require_once 'db_connect.php';
require_once __DIR__ . '/includes/settings_helper.php';
require_once __DIR__ . '/includes/product_image_helper.php';

if (!isset($pdo) || $pdo === null) {
    die("Critical Error: Database connection failed. Please check your PostgreSQL setup.");
}

try {
    $currentAdminId = (int) ($_SESSION['user_id'] ?? 0);
    if ($currentAdminId > 0) {
        $sessionUserStmt = $pdo->prepare('SELECT username, email, full_name, role, is_active, account_status FROM users WHERE id = ? LIMIT 1');
        $sessionUserStmt->execute([$currentAdminId]);
        $sessionUser = $sessionUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sessionUser) {
            destroySession();
            header('Location: login.php?error=unauthorized');
            exit;
        }

        $sessionUserIsActive = is_bool($sessionUser['is_active'] ?? null)
            ? (bool) $sessionUser['is_active']
            : in_array(strtolower(trim((string) ($sessionUser['is_active'] ?? ''))), ['1', 'true', 't', 'yes', 'y'], true);

        if (!in_array($sessionUser['role'] ?? '', ['admin', 'manager'], true)
            || !$sessionUserIsActive
            || (($sessionUser['account_status'] ?? 'active') === 'suspended')) {
            destroySession();
            header('Location: login.php?error=unauthorized');
            exit;
        }

        $_SESSION['username'] = $sessionUser['username'];
        $_SESSION['email'] = $sessionUser['email'];
        $_SESSION['full_name'] = $sessionUser['full_name'];
        $_SESSION['role'] = $sessionUser['role'];
    }
} catch (PDOException $e) {
    error_log('Admin session verification failed: ' . $e->getMessage());
}

$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$user_role = $_SESSION['role'] ?? 'admin';
$canManageInventory = checkAdminPermission('products.edit') || checkAdminPermission('stock.manage');
$productImageColumnAvailable = productImageColumnExists($pdo);
$adminCsrfToken = generateCSRFToken();

// Notifications (lightweight summary)
$notifications = [];
try {
    $pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    if ($pendingOrders > 0) {
        $notifications[] = ['message' => $pendingOrders . ' pending orders', 'link' => 'admin.php?page=orders'];
    }
} catch (PDOException $e) {}
try {
    $lowStock = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity < reorder_level AND is_active = true")->fetchColumn();
    if ($lowStock > 0) {
        $notifications[] = ['message' => $lowStock . ' products low on stock', 'link' => 'admin.php?page=inventory'];
    }
} catch (PDOException $e) {}
try {
    $failedPayments = $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'failed'")->fetchColumn();
    if ($failedPayments > 0) {
        $notifications[] = ['message' => $failedPayments . ' failed payments', 'link' => 'admin.php?page=orders'];
    }
} catch (PDOException $e) {}

$notification_count = count($notifications);

$allowed_pages = [
    'dashboard', 'products', 'inventory', 'orders',
    'customers', 'suppliers', 'reports', 'settings', 'test'
];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!validateCSRFToken((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session token is invalid. Refresh the page and try again.']);
        exit;
    }

    $action = $_POST['action'];
    $result = ['success' => false, 'message' => 'Unknown action'];
    try {
        switch ($action) {
            case 'add_product':
                $name = trim((string) ($_POST['name'] ?? ''));
                $sku = trim((string) ($_POST['sku'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $unitPrice = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
                $costPrice = filter_var($_POST['cost_price'] ?? 0, FILTER_VALIDATE_FLOAT);
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $reorderLevel = filter_var($_POST['reorder_level'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $hasImageUpload = isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if ($name === '' || $sku === '' || $categoryId === false || $unitPrice === false || $quantity === false) {
                    $result = ['success' => false, 'message' => 'Please complete all required product fields.'];
                    break;
                }

                if ($costPrice === false) {
                    $costPrice = 0;
                }

                if ($reorderLevel === false) {
                    $reorderLevel = 10;
                }

                if ($hasImageUpload && !$productImageColumnAvailable) {
                    $result = ['success' => false, 'message' => "Run sql/add_product_image_path.sql before uploading product images."];
                    break;
                }

                $imagePath = null;
                if ($hasImageUpload) {
                    $uploadResult = handleProductImageUpload($_FILES['image']);
                    if (!$uploadResult['success']) {
                        $result = ['success' => false, 'message' => $uploadResult['message'] ?? 'Product image upload failed.'];
                        break;
                    }
                    $imagePath = $uploadResult['path'] ?? null;
                }

                if ($productImageColumnAvailable) {
                    $stmt = $pdo->prepare("
                        INSERT INTO products (
                            name, sku, description, category_id, unit_price, cost_price, quantity, reorder_level, image_path, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, true)
                    ");
                    $stmt->execute([$name, $sku, $description, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $imagePath]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO products (
                            name, sku, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, true)
                    ");
                    $stmt->execute([$name, $sku, $description, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel]);
                }

                $result = ['success' => true, 'message' => 'Product added successfully'];
                break;
            case 'update_product':
                $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = trim((string) ($_POST['name'] ?? ''));
                $sku = trim((string) ($_POST['sku'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $unitPrice = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
                $costPrice = filter_var($_POST['cost_price'] ?? 0, FILTER_VALIDATE_FLOAT);
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $reorderLevel = filter_var($_POST['reorder_level'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';
                $hasImageUpload = isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if ($productId === false || $name === '' || $sku === '' || $categoryId === false || $unitPrice === false || $quantity === false) {
                    $result = ['success' => false, 'message' => 'Please complete all required product fields.'];
                    break;
                }

                if ($costPrice === false) {
                    $costPrice = 0;
                }

                if ($reorderLevel === false) {
                    $reorderLevel = 10;
                }

                if (($removeImage || $hasImageUpload) && !$productImageColumnAvailable) {
                    $result = ['success' => false, 'message' => "Run sql/add_product_image_path.sql before managing product images."];
                    break;
                }

                $existingImagePath = null;
                if ($productImageColumnAvailable) {
                    $stmt = $pdo->prepare("SELECT image_path FROM products WHERE id = ?");
                    $stmt->execute([$productId]);
                    $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existingProduct) {
                        $result = ['success' => false, 'message' => 'Product not found.'];
                        break;
                    }

                    $existingImagePath = $existingProduct['image_path'] ?? null;
                }

                $imagePathToSave = $existingImagePath;

                if ($hasImageUpload) {
                    $uploadResult = handleProductImageUpload($_FILES['image'], $existingImagePath);
                    if (!$uploadResult['success']) {
                        $result = ['success' => false, 'message' => $uploadResult['message'] ?? 'Product image upload failed.'];
                        break;
                    }
                    $imagePathToSave = $uploadResult['path'] ?? null;
                } elseif ($removeImage) {
                    deleteProductImageFile($existingImagePath);
                    $imagePathToSave = null;
                }

                if ($productImageColumnAvailable) {
                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET name = ?, sku = ?, description = ?, category_id = ?, unit_price = ?, cost_price = ?, quantity = ?, reorder_level = ?, image_path = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $sku, $description, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $imagePathToSave, $productId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET name = ?, sku = ?, description = ?, category_id = ?, unit_price = ?, cost_price = ?, quantity = ?, reorder_level = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $sku, $description, $categoryId, $unitPrice, $costPrice, $quantity, $reorderLevel, $productId]);
                }

                $result = ['success' => true, 'message' => 'Product updated successfully'];
                break;
            case 'delete_product':
                $stmt = $pdo->prepare("UPDATE products SET is_active=false WHERE id=?");
                $stmt->execute([$_POST['id']]);
                $result = ['success' => true, 'message' => 'Product deleted successfully'];
                break;
            case 'adjust_stock':
                $product_id = $_POST['product_id'];
                $adjustment_type = $_POST['adjustment_type'];
                $quantity = intval($_POST['quantity']);
                $notes = $_POST['notes'] ?? '';
                $stmt = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $current_qty = $stmt->fetch()['quantity'] ?? 0;
                switch ($adjustment_type) {
                    case 'add': $new_qty = $current_qty + $quantity; break;
                    case 'remove': $new_qty = max(0, $current_qty - $quantity); break;
                    case 'set': $new_qty = $quantity; break;
                    default: $new_qty = $current_qty;
                }
                $stmt = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                $stmt->execute([$new_qty, $product_id]);
                $user_id = $_SESSION['user_id'] ?? 1;
                $stmt = $pdo->prepare("INSERT INTO stock_logs (product_id, user_id, action, quantity_before, quantity_after, quantity_changed, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$product_id, $user_id, $adjustment_type === 'set' ? 'adjust' : $adjustment_type, $current_qty, $new_qty, $new_qty - $current_qty, $notes]);
                $result = ['success' => true, 'message' => 'Stock adjusted successfully'];
                break;
            case 'add_customer':
                if (strtolower(trim((string) ($_POST['username'] ?? ''))) === strtolower(trim((string) ($_POST['email'] ?? '')))) {
                    $result = ['success' => false, 'message' => 'Username and email must be different.'];
                    break;
                }

                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, customer_group, role) VALUES (?, ?, ?, ?, ?, ?, 'customer')");
                $password = password_hash($_POST['password'] ?? 'password123', PASSWORD_DEFAULT);
                $stmt->execute([$_POST['username'], $password, $_POST['full_name'], $_POST['email'], $_POST['phone'] ?? '', $_POST['customer_group'] ?? 'regular']);
                $result = ['success' => true, 'message' => 'Customer added successfully'];
                break;
            case 'update_customer':
                $updates = []; $params = [];
                if (isset($_POST['full_name'])) { $updates[] = "full_name = ?"; $params[] = $_POST['full_name']; }
                if (isset($_POST['email'])) { $updates[] = "email = ?"; $params[] = $_POST['email']; }
                if (isset($_POST['phone'])) { $updates[] = "phone = ?"; $params[] = $_POST['phone']; }
                if (isset($_POST['customer_group'])) { $updates[] = "customer_group = ?"; $params[] = $_POST['customer_group']; }
                if (!empty($updates)) { $params[] = $_POST['id']; $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?"; $stmt = $pdo->prepare($sql); $stmt->execute($params); }
                $result = ['success' => true, 'message' => 'Customer updated successfully'];
                break;
            case 'delete_customer':
                $stmt = $pdo->prepare("UPDATE users SET is_active = false WHERE id = ? AND role = 'customer'");
                $stmt->execute([$_POST['id']]);
                $result = ['success' => true, 'message' => 'Customer deleted successfully'];
                break;
            case 'update_order_status':
                $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$_POST['status'], $_POST['id']]);
                $result = ['success' => true, 'message' => 'Order status updated'];
                break;
            case 'create_order':
                $order_number = 'ORD-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, shipping_address, notes, subtotal, tax_amount, total_amount, status, payment_status) VALUES (?, ?, ?, ?, ?, 0, 0, 0, 'pending', 'pending')");
                $stmt->execute([$order_number, $_POST['customer_name'], $_POST['customer_email'] ?? '', $_POST['shipping_address'] ?? '', $_POST['notes'] ?? '']);
                $result = ['success' => true, 'message' => 'Order created successfully'];
                break;
            case 'add_supplier':
                $stmt = $pdo->prepare("INSERT INTO suppliers (company_name, contact_person, email, phone, address, city) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_POST['company_name'], $_POST['contact_person'] ?? '', $_POST['email'] ?? '', $_POST['phone'] ?? '', $_POST['address'] ?? '', $_POST['city'] ?? '']);
                $result = ['success' => true, 'message' => 'Supplier added successfully'];
                break;
            case 'update_supplier':
                $stmt = $pdo->prepare("UPDATE suppliers SET company_name = ?, contact_person = ?, email = ?, phone = ?, address = ?, city = ? WHERE id = ?");
                $stmt->execute([$_POST['company_name'], $_POST['contact_person'] ?? '', $_POST['email'] ?? '', $_POST['phone'] ?? '', $_POST['address'] ?? '', $_POST['city'] ?? '', $_POST['id']]);
                $result = ['success' => true, 'message' => 'Supplier updated successfully'];
                break;
            case 'delete_supplier':
                $stmt = $pdo->prepare("UPDATE suppliers SET is_active = false WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $result = ['success' => true, 'message' => 'Supplier deleted successfully'];
                break;
            case 'get_customer_details':
                $customerId = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT id, username, email, full_name, phone, customer_group, created_at
                    FROM users
                    WHERE id = ? AND role = 'customer'
                    LIMIT 1
                ");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$customer) {
                    $result = ['success' => false, 'message' => 'Customer not found'];
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT id, order_number, status, payment_status, total_amount, COALESCE(order_date, created_at) AS order_date
                    FROM orders
                    WHERE user_id = ?
                    ORDER BY COALESCE(order_date, created_at) DESC
                    LIMIT 10
                ");
                $stmt->execute([$customerId]);
                $customer['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = ['success' => true, 'data' => ['customer' => $customer]];
                break;
            case 'get_order_details':
                $orderId = (int)($_POST['id'] ?? $_POST['order_id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT o.*, u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
                    FROM orders o
                    LEFT JOIN users u ON u.id = o.user_id
                    WHERE o.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    $result = ['success' => false, 'message' => 'Order not found'];
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT product_name, quantity, unit_price, subtotal
                    FROM order_items
                    WHERE order_id = ?
                    ORDER BY id ASC
                ");
                $stmt->execute([$orderId]);
                $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("
                    SELECT transaction_id, payment_gateway, amount, status, created_at
                    FROM payment_transactions
                    WHERE order_id = ?
                    ORDER BY created_at DESC
                ");
                $stmt->execute([$orderId]);
                $order['transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = ['success' => true, 'data' => ['order' => $order]];
                break;
            case 'get_stock_history':
                $productId = (int)($_POST['product_id'] ?? $_POST['id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT p.id, p.name, p.quantity, p.sku
                    FROM products p
                    WHERE p.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    $result = ['success' => false, 'message' => 'Product not found'];
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT sl.*, COALESCE(u.full_name, u.username, 'System') AS user_name
                    FROM stock_logs sl
                    LEFT JOIN users u ON u.id = sl.user_id
                    WHERE sl.product_id = ?
                    ORDER BY sl.created_at DESC
                    LIMIT 25
                ");
                $stmt->execute([$productId]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = ['success' => true, 'data' => ['product' => $product, 'history' => $history]];
                break;
            case 'get_supplier_products':
                $supplierId = (int)($_POST['supplier_id'] ?? $_POST['id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM suppliers
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$supplierId]);
                $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$supplier) {
                    $result = ['success' => false, 'message' => 'Supplier not found'];
                    break;
                }
                $stmt = $pdo->prepare("
                    SELECT id, name, sku, quantity, unit_price, is_active
                    FROM products
                    WHERE supplier_id = ?
                    ORDER BY name ASC
                ");
                $stmt->execute([$supplierId]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = ['success' => true, 'data' => ['supplier' => $supplier, 'products' => $products]];
                break;
            case 'add_user':
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $role = trim((string) ($_POST['role'] ?? 'staff'));
                $passwordInput = (string) ($_POST['password'] ?? '');
                $allowedRoles = ['admin', 'manager', 'staff'];

                if ($username === '' || $email === '' || $fullName === '' || $passwordInput === '') {
                    $result = ['success' => false, 'message' => 'Please complete all required user fields.'];
                    break;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result = ['success' => false, 'message' => 'Please enter a valid email address.'];
                    break;
                }

                if (strtolower($username) === strtolower($email)) {
                    $result = ['success' => false, 'message' => 'Username and email must be different.'];
                    break;
                }

                if (!in_array($role, $allowedRoles, true)) {
                    $result = ['success' => false, 'message' => 'Please select a valid user role.'];
                    break;
                }

                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role) VALUES (?, ?, ?, ?, ?)");
                $password = password_hash($passwordInput, PASSWORD_DEFAULT);
                $stmt->execute([$username, $password, $email, $fullName, $role]);
                $result = ['success' => true, 'message' => 'User added successfully'];
                break;
            case 'update_user':
                $userId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $role = trim((string) ($_POST['role'] ?? 'staff'));
                $passwordInput = trim((string) ($_POST['password'] ?? ''));
                $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] === '1';
                $allowedRoles = ['admin', 'manager', 'staff'];
                $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

                if ($userId === false || $username === '' || $email === '' || $fullName === '') {
                    $result = ['success' => false, 'message' => 'Please complete all required user fields.'];
                    break;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result = ['success' => false, 'message' => 'Please enter a valid email address.'];
                    break;
                }

                if (strtolower($username) === strtolower($email)) {
                    $result = ['success' => false, 'message' => 'Username and email must be different.'];
                    break;
                }

                if (!in_array($role, $allowedRoles, true)) {
                    $result = ['success' => false, 'message' => 'Please select a valid user role.'];
                    break;
                }

                if ($currentUserId > 0 && $currentUserId === $userId && !$isActive) {
                    $result = ['success' => false, 'message' => 'You cannot deactivate your own account.'];
                    break;
                }

                $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existingUser) {
                    $result = ['success' => false, 'message' => 'User not found.'];
                    break;
                }

                if (!in_array($existingUser['role'] ?? '', ['admin', 'manager', 'staff'], true)) {
                    $result = ['success' => false, 'message' => 'Only staff, manager, and admin accounts can be edited here.'];
                    break;
                }

                if (($existingUser['role'] ?? '') === 'admin' && $currentUserId > 0 && $currentUserId !== $userId && !$isActive) {
                    $result = ['success' => false, 'message' => 'Admin users can only be deactivated from their own account controls.'];
                    break;
                }

                $fields = ['username = ?', 'email = ?', 'full_name = ?', 'role = ?', 'is_active = ?', 'updated_at = CURRENT_TIMESTAMP'];
                $params = [$username, $email, $fullName, $role, $isActive];

                if ($passwordInput !== '') {
                    $fields[] = 'password = ?';
                    $params[] = password_hash($passwordInput, PASSWORD_DEFAULT);
                }

                $params[] = $userId;
                $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $result = ['success' => true, 'message' => 'User updated successfully'];
                break;
            case 'delete_user':
                $userId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

                if ($userId === false) {
                    $result = ['success' => false, 'message' => 'Invalid user selected.'];
                    break;
                }

                if ($currentUserId > 0 && $currentUserId === $userId) {
                    $result = ['success' => false, 'message' => 'You cannot delete your own account.'];
                    break;
                }

                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$userToDelete) {
                    $result = ['success' => false, 'message' => 'User not found.'];
                    break;
                }

                if (!in_array($userToDelete['role'] ?? '', ['admin', 'manager', 'staff'], true)) {
                    $result = ['success' => false, 'message' => 'Only staff, manager, and admin accounts can be deleted here.'];
                    break;
                }

                if (($userToDelete['role'] ?? '') === 'admin') {
                    $result = ['success' => false, 'message' => 'Admin users cannot be deleted from this page.'];
                    break;
                }

                $stmt = $pdo->prepare("UPDATE users SET is_active = false, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$userId]);
                $result = ['success' => true, 'message' => 'User deleted successfully'];
                break;
            case 'save_settings':
                $savedSettings = saveAppSettings($pdo, [
                    'store_name' => $_POST['store_name'] ?? '',
                    'store_email' => $_POST['store_email'] ?? '',
                    'currency' => $_POST['currency'] ?? '',
                    'timezone' => $_POST['timezone'] ?? '',
                    'low_stock_threshold' => $_POST['low_stock_threshold'] ?? '',
                    'date_format' => $_POST['date_format'] ?? 'Y-m-d',
                ]);
                $result = ['success' => true, 'message' => 'General settings saved successfully', 'settings' => $savedSettings];
                break;
            case 'save_notification_settings':
                $savedSettings = saveAppSettings($pdo, [
                    'notify_new_orders' => $_POST['notify_new_orders'] ?? '0',
                    'notify_low_stock' => $_POST['notify_low_stock'] ?? '0',
                    'notify_daily_sales_report' => $_POST['notify_daily_sales_report'] ?? '0',
                    'notify_weekly_summary' => $_POST['notify_weekly_summary'] ?? '0',
                    'notify_order_status_changes' => $_POST['notify_order_status_changes'] ?? '0',
                    'notify_inventory_updates' => $_POST['notify_inventory_updates'] ?? '0',
                    'notify_user_activity' => $_POST['notify_user_activity'] ?? '0',
                ]);
                $result = ['success' => true, 'message' => 'Notification preferences saved successfully', 'settings' => $savedSettings];
                break;
            case 'create_settings_backup':
                $timestamp = date('c');
                saveAppSettings($pdo, ['last_backup_at' => $timestamp]);
                $backupPayload = createSettingsBackupPayload($pdo);
                $filename = 'stockflow-settings-backup-' . date('Y-m-d-His') . '.json';
                $result = [
                    'success' => true,
                    'message' => 'Settings backup created successfully',
                    'filename' => $filename,
                    'backup' => $backupPayload,
                ];
                break;
            case 'restore_settings_backup':
                if (!isset($_FILES['backup_file']) || (int) ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $result = ['success' => false, 'message' => 'Please choose a valid backup file to restore.'];
                    break;
                }

                $backupJson = file_get_contents($_FILES['backup_file']['tmp_name']);
                $backupPayload = json_decode($backupJson ?: '', true);

                if (!is_array($backupPayload)) {
                    $result = ['success' => false, 'message' => 'The selected backup file is not valid JSON.'];
                    break;
                }

                $savedSettings = restoreSettingsBackupPayload($pdo, $backupPayload);
                $result = [
                    'success' => true,
                    'message' => 'Settings backup restored successfully',
                    'settings' => $savedSettings,
                ];
                break;
            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();
        $friendlyMessage = 'Database error occurred while processing your request.';
        if ($e->getCode() === '23505' || stripos($errorMessage, 'duplicate key value') !== false) {
            if (stripos($errorMessage, 'users_username_key') !== false) $friendlyMessage = 'Username already exists.';
            elseif (stripos($errorMessage, 'users_email_key') !== false) $friendlyMessage = 'Email already exists.';
            elseif (stripos($errorMessage, 'products_sku_key') !== false) $friendlyMessage = 'SKU already exists.';
            else $friendlyMessage = 'A record with these details already exists.';
        }
        $result = ['success' => false, 'message' => $friendlyMessage];
    } catch (Throwable $e) {
        $friendlyMessage = trim((string) $e->getMessage()) !== ''
            ? $e->getMessage()
            : 'Unable to complete the requested action.';
        $result = ['success' => false, 'message' => $friendlyMessage];
    }
    echo json_encode($result);
    exit;
}

$page_titles = [
    'dashboard' => 'Overview',
    'products'  => 'Products',
    'inventory' => 'Inventory',
    'orders'    => 'Orders',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers',
    'reports'   => 'Reports',
    'settings'  => 'Settings',
    'test'      => 'Diagnostics',
];
$page_title = $page_titles[$page] ?? ucfirst($page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StockFlow — <?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
    window.ADMIN_CSRF_TOKEN = <?= json_encode($adminCsrfToken) ?>;

    (function () {
        const nativeFetch = window.fetch ? window.fetch.bind(window) : null;
        if (!nativeFetch) return;

        window.fetch = function (input, init) {
            const options = Object.assign({}, init || {});
            const method = String(options.method || 'GET').toUpperCase();

            if (method === 'POST' && window.ADMIN_CSRF_TOKEN) {
                if (options.body instanceof FormData || options.body instanceof URLSearchParams) {
                    if (!options.body.has('csrf_token')) {
                        options.body.append('csrf_token', window.ADMIN_CSRF_TOKEN);
                    }
                } else if (typeof options.body === 'string') {
                    const separator = options.body.length ? '&' : '';
                    options.body += separator + 'csrf_token=' + encodeURIComponent(window.ADMIN_CSRF_TOKEN);
                } else if (!options.body) {
                    options.body = new URLSearchParams({ csrf_token: window.ADMIN_CSRF_TOKEN });
                }

                options.headers = Object.assign({}, options.headers || {}, {
                    'X-CSRF-Token': window.ADMIN_CSRF_TOKEN
                });
            }

            return nativeFetch(input, options);
        };
    })();

    // Apply the saved theme before styles paint to reduce flashing.
    (function () {
        const storageKey = 'admin-theme';
        const savedTheme = localStorage.getItem(storageKey);
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme === 'light' || savedTheme === 'dark'
            ? savedTheme
            : (systemPrefersDark ? 'dark' : 'light');

        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <style>
    /* ═══════════════════════════════════════════
       DESIGN TOKENS
    ═══════════════════════════════════════════ */
    :root {
        color-scheme: light;

        /* Core palette — clean cloud neutrals + electric indigo */
        --bg:          #f4f7fb;
        --bg-2:        #ebf0f7;
        --bg-3:        #dde7f3;
        --bg-4:        #d2dceb;
        --surface:     #ffffff;
        --surface-2:   #f8fbff;

        --accent:      #6366f1;
        --accent-glow: rgba(99,102,241,.18);
        --accent-dim:  rgba(99,102,241,.12);
        --accent-2:    #22d3ee;   /* cyan */
        --accent-3:    #f59e0b;   /* amber */
        --accent-4:    #10b981;   /* emerald */
        --accent-5:    #f43f5e;   /* rose */

        --text:        #172033;
        --text-2:      #556177;
        --text-3:      #7d8798;
        --border:      rgba(23,32,51,.10);
        --border-2:    rgba(23,32,51,.16);

        /* Semantic */
        --success:     #10b981;
        --warning:     #f59e0b;
        --danger:      #f43f5e;
        --info:        #22d3ee;

        --success-bg:  rgba(16,185,129,.12);
        --warning-bg:  rgba(245,158,11,.12);
        --danger-bg:   rgba(244,63,94,.12);
        --info-bg:     rgba(34,211,238,.12);

        /* Legacy compat vars (for module files) */
        --primary:        var(--accent);
        --secondary:      var(--accent-4);
        --bg-card:        var(--surface);
        --bg-main:        var(--bg-3);
        --text-primary:   var(--text);
        --text-secondary: var(--text-2);
        --text-muted:     var(--text-3);
        --border-color:   var(--border);
        --gradient-primary: linear-gradient(135deg, var(--accent), var(--accent-2));
        --border-radius-sm: 8px;
        --border-radius-md: 14px;
        --success-light: var(--success-bg);
        --warning-light: var(--warning-bg);
        --danger-light:  var(--danger-bg);
        --info-light:    var(--info-bg);
        --shadow-md:      0 14px 32px rgba(15,23,42,.08);
        --shadow-lg:      0 20px 48px rgba(15,23,42,.12);
        --header-bg:      rgba(255,255,255,.82);

        /* Sidebar */
        --sidebar-w:   240px;
        --sidebar-collapsed: 68px;
        --header-h:    64px;
        --radius:      12px;
        --radius-lg:   18px;
        --theme-toggle-bg: var(--surface);
        --theme-toggle-text: var(--text);
        --theme-shadow-focus: 0 0 0 3px rgba(99,102,241,.18);
        --theme-transition: .32s ease;
    }

    :root[data-theme="dark"] {
        color-scheme: dark;

        --bg:          #0d0f14;
        --bg-2:        #12151c;
        --bg-3:        #181c26;
        --bg-4:        #1e2330;
        --surface:     #242938;
        --surface-2:   #2c3244;

        --accent:      #6366f1;
        --accent-glow: rgba(99,102,241,.28);
        --accent-dim:  rgba(99,102,241,.12);
        --accent-2:    #22d3ee;
        --accent-3:    #f59e0b;
        --accent-4:    #10b981;
        --accent-5:    #f43f5e;

        --text:        #e8eaf0;
        --text-2:      #9ba3b8;
        --text-3:      #5b6480;
        --border:      rgba(255,255,255,.07);
        --border-2:    rgba(255,255,255,.12);

        --success:     #10b981;
        --warning:     #f59e0b;
        --danger:      #f43f5e;
        --info:        #22d3ee;

        --success-bg:  rgba(16,185,129,.12);
        --warning-bg:  rgba(245,158,11,.12);
        --danger-bg:   rgba(244,63,94,.12);
        --info-bg:     rgba(34,211,238,.12);

        --primary:        var(--accent);
        --secondary:      var(--accent-4);
        --bg-card:        var(--surface);
        --bg-main:        var(--bg-3);
        --text-primary:   var(--text);
        --text-secondary: var(--text-2);
        --text-muted:     var(--text-3);
        --border-color:   var(--border);
        --shadow-md:      0 4px 20px rgba(0,0,0,.4);
        --shadow-lg:      0 8px 40px rgba(0,0,0,.5);
        --gradient-primary: linear-gradient(135deg, var(--accent), var(--accent-2));
        --border-radius-sm: 8px;
        --border-radius-md: 14px;
        --success-light: var(--success-bg);
        --warning-light: var(--warning-bg);
        --danger-light:  var(--danger-bg);
        --info-light:    var(--info-bg);
        --header-bg:      rgba(13,15,20,.8);

        --sidebar-w:   240px;
        --sidebar-collapsed: 68px;
        --header-h:    64px;
        --radius:      12px;
        --radius-lg:   18px;
        --theme-toggle-bg: var(--surface);
        --theme-toggle-text: var(--text);
        --theme-shadow-focus: 0 0 0 3px rgba(99,102,241,.28);
        --theme-transition: .32s ease;
    }

    /* ═══════════════════════════════════════════
       RESET & BASE
    ═══════════════════════════════════════════ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { height: 100%; }
    body {
        font-family: 'Sora', sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        font-size: 14px;
        line-height: 1.6;
        -webkit-font-smoothing: antialiased;
        transition: background-color var(--theme-transition), color var(--theme-transition);
    }

    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: var(--bg-2); }
    ::-webkit-scrollbar-thumb { background: var(--surface-2); border-radius: 99px; }

    a { color: inherit; text-decoration: none; }
    button { font-family: inherit; }
    input, select, textarea { font-family: inherit; }
    .sidebar,
    .header,
    .search-box,
    .header-icon-btn,
    .notification-dropdown,
    .user-profile,
    .mobile-menu-btn,
    .card,
    .card-footer,
    .sidebar-toggle,
    .collapse-btn {
        transition:
            background-color var(--theme-transition),
            border-color var(--theme-transition),
            color var(--theme-transition),
            box-shadow var(--theme-transition);
    }
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* ═══════════════════════════════════════════
       LAYOUT SHELL
    ═══════════════════════════════════════════ */
    .app-container {
        display: flex;
        min-height: 100vh;
    }

    /* ═══════════════════════════════════════════
       SIDEBAR
    ═══════════════════════════════════════════ */
    .sidebar {
        width: var(--sidebar-w);
        background: var(--bg-2);
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0; left: 0; bottom: 0;
        z-index: 100;
        transition: width .3s cubic-bezier(.4,0,.2,1), transform .3s cubic-bezier(.4,0,.2,1);
        overflow: hidden;
    }

    .sidebar.collapsed { width: var(--sidebar-collapsed); }

    /* Ambient glow behind logo */
    .sidebar::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 200px;
        background: radial-gradient(ellipse at 50% -20%, var(--accent-glow) 0%, transparent 70%);
        pointer-events: none;
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 0 16px;
        height: var(--header-h);
        border-bottom: 1px solid var(--border);
        flex-shrink: 0;
        position: relative;
    }

    .sidebar-logo {
        width: 36px; height: 36px;
        background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
        border-radius: 10px;
        display: grid; place-items: center;
        font-size: 17px; color: #fff;
        flex-shrink: 0;
        box-shadow: 0 0 20px var(--accent-glow);
    }

    .sidebar-brand {
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: -.02em;
        color: var(--text);
        white-space: nowrap;
        opacity: 1;
        transition: opacity .2s;
    }
    .sidebar.collapsed .sidebar-brand { opacity: 0; pointer-events: none; }

    /* Nav */
    .sidebar-nav {
        flex: 1;
        padding: 16px 10px;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .nav { list-style: none; display: flex; flex-direction: column; gap: 2px; }
    .nav-item {}

    .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-radius: var(--radius);
        color: var(--text-3);
        font-weight: 500;
        font-size: .8rem;
        letter-spacing: .02em;
        transition: all .2s ease;
        white-space: nowrap;
        position: relative;
        cursor: pointer;
    }

    .nav-link i {
        width: 18px; font-size: 15px; text-align: center;
        flex-shrink: 0; transition: color .2s;
    }

    .nav-text { transition: opacity .2s; }
    .sidebar.collapsed .nav-text { opacity: 0; pointer-events: none; }

    .nav-link:hover {
        background: var(--surface);
        color: var(--text);
    }
    .nav-link:hover i { color: var(--accent); }

    .nav-link.active {
        background: var(--accent-dim);
        color: var(--accent);
        box-shadow: inset 3px 0 0 var(--accent);
    }
    .nav-link.active i { color: var(--accent); }

    /* Tooltip on collapsed */
    .sidebar.collapsed .nav-link::after {
        content: attr(data-label);
        position: absolute;
        left: calc(100% + 12px);
        top: 50%; transform: translateY(-50%);
        background: var(--surface-2);
        color: var(--text);
        padding: 6px 12px;
        border-radius: 8px;
        font-size: .8rem;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s;
        border: 1px solid var(--border-2);
        z-index: 200;
    }
    .sidebar.collapsed .nav-link:hover::after { opacity: 1; }

    /* Nav divider */
    .nav-divider {
        height: 1px;
        background: var(--border);
        margin: 10px 4px;
    }

    /* Sidebar footer */
    .sidebar-footer {
        padding: 12px 10px;
        border-top: 1px solid var(--border);
        flex-shrink: 0;
    }

    .sidebar-footer .collapse-btn {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px; border-radius: var(--radius);
        color: var(--text-3); font-size: .8rem; font-weight: 500;
        transition: all .2s; white-space: nowrap;
    }
    .sidebar-footer .collapse-btn:hover { background: var(--danger-bg); color: var(--danger); }
    .sidebar-footer .collapse-btn i { width: 18px; text-align: center; font-size: 15px; flex-shrink: 0; }
    .sidebar.collapsed .sidebar-footer .sidebar-text { opacity: 0; }

    /* Collapse toggle button */
    .sidebar-toggle {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px; border-radius: var(--radius);
        color: var(--text-3); font-size: .8rem; font-weight: 500;
        background: none; border: none; cursor: pointer;
        transition: all .2s; white-space: nowrap; width: 100%;
        margin-bottom: 4px;
    }
    .sidebar-toggle:hover { background: var(--surface); color: var(--text); }
    .sidebar-toggle i { width: 18px; text-align: center; font-size: 15px; flex-shrink: 0; transition: transform .3s; }
    .sidebar.collapsed .sidebar-toggle i { transform: rotate(180deg); }
    .sidebar.collapsed .sidebar-toggle span { opacity: 0; }

    /* ═══════════════════════════════════════════
       MAIN WRAPPER
    ═══════════════════════════════════════════ */
    .main-wrapper {
        flex: 1;
        margin-left: var(--sidebar-w);
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        transition: margin-left .3s cubic-bezier(.4,0,.2,1);
    }
    .sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

    /* ═══════════════════════════════════════════
       HEADER
    ═══════════════════════════════════════════ */
    .header {
        height: var(--header-h);
        background: var(--header-bg);
        backdrop-filter: blur(20px);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 28px;
        position: sticky; top: 0; z-index: 90;
        flex-shrink: 0;
    }

    .header-left { display: flex; align-items: center; gap: 16px; }

    /* Breadcrumb-style title */
    .page-title {
        display: flex; align-items: center; gap: 8px;
        font-size: 1rem; font-weight: 600; color: var(--text);
        letter-spacing: -.01em;
    }
    .page-title .crumb { color: var(--text-3); font-weight: 400; }
    .page-title .sep { color: var(--text-3); }

    .mobile-menu-btn {
        width: 36px; height: 36px; border: 1px solid var(--border); background: var(--surface);
        border-radius: 9px; display: none; align-items: center; justify-content: center;
        color: var(--text-2); cursor: pointer; transition: all .2s;
    }
    .mobile-menu-btn:hover { background: var(--surface-2); color: var(--text); }

    .header-right { display: flex; align-items: center; gap: 12px; }

    /* Search */
    .search-box {
        display: flex; align-items: center; gap: 10px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 0 14px;
        transition: border-color .2s, box-shadow .2s;
        height: 38px;
    }
    .search-box:focus-within {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-dim);
    }
    .search-box i { color: var(--text-3); font-size: 13px; }
    .search-box input {
        background: none; border: none; outline: none;
        color: var(--text); font-size: .85rem; width: 180px;
    }
    .search-box input::placeholder { color: var(--text-3); }

    /* Notification bell */
    .header-icon-btn {
        width: 38px; height: 38px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); display: grid; place-items: center;
        color: var(--text-2); cursor: pointer; transition: all .2s; position: relative;
    }
    .header-icon-btn:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-2); }
    .header-icon-btn:focus-visible {
        outline: none;
        box-shadow: var(--theme-shadow-focus);
    }
    .header-icon-btn .badge-dot {
        width: 7px; height: 7px; background: var(--accent); border-radius: 50%;
        position: absolute; top: 8px; right: 8px;
        border: 2px solid var(--bg-2);
    }
    .notification-dropdown {
        position: absolute;
        top: 48px; right: 0;
        width: 260px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-md);
        display: none;
        z-index: 200;
    }
    .notification-dropdown.active { display: block; }
    .notification-item {
        padding: 10px 12px;
        border-bottom: 1px solid var(--border);
        font-size: .8rem;
        color: var(--text-2);
    }
    .notification-item:last-child { border-bottom: none; }
    .notification-item a { color: var(--text); }
    .notification-empty { padding: 12px; color: var(--text-3); font-size: .8rem; }

    /* User profile */
    .user-profile {
        display: flex; align-items: center; gap: 10px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 5px 14px 5px 5px;
        cursor: pointer; transition: all .2s;
    }
    .user-profile:hover { background: var(--surface-2); border-color: var(--border-2); }
    .user-avatar {
        width: 30px; height: 30px; border-radius: 8px;
        background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
        display: grid; place-items: center;
        font-size: .7rem; font-weight: 700; color: #fff; letter-spacing: .05em;
    }
    .user-info { display: flex; flex-direction: column; }
    .user-name { font-size: .8rem; font-weight: 600; color: var(--text); line-height: 1.2; }
    .user-role { font-size: .7rem; color: var(--text-3); text-transform: capitalize; }

    /* Theme toggle */
    .theme-toggle {
        overflow: hidden;
    }
    .theme-toggle__icon {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        transition: opacity var(--theme-transition), transform var(--theme-transition);
        pointer-events: none;
    }
    .theme-toggle__icon svg {
        width: 18px;
        height: 18px;
    }
    .theme-toggle__icon--sun svg {
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .theme-toggle__icon--moon svg {
        fill: currentColor;
    }
    :root:not([data-theme="dark"]) .theme-toggle__icon--sun {
        opacity: 1;
        transform: scale(1) rotate(0deg);
    }
    :root:not([data-theme="dark"]) .theme-toggle__icon--moon {
        opacity: 0;
        transform: scale(.6) rotate(-25deg);
    }
    :root[data-theme="dark"] .theme-toggle__icon--sun {
        opacity: 0;
        transform: scale(.6) rotate(25deg);
    }
    :root[data-theme="dark"] .theme-toggle__icon--moon {
        opacity: 1;
        transform: scale(1) rotate(0deg);
    }

    /* ═══════════════════════════════════════════
       MAIN CONTENT
    ═══════════════════════════════════════════ */
    .main-content {
        flex: 1;
        padding: 28px;
        overflow-x: hidden;
    }

    /* ═══════════════════════════════════════════
       CARDS
    ═══════════════════════════════════════════ */
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }

    .card-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 20px 24px;
        border-bottom: 1px solid var(--border);
    }

    .card-title {
        font-size: .9rem; font-weight: 600; color: var(--text);
        display: flex; align-items: center; gap: 8px;
    }
    .card-title i { color: var(--accent); font-size: 15px; }

    .card-body { padding: 24px; }
    .card-footer { padding: 16px 24px; border-top: 1px solid var(--border); background: var(--bg-3); }

    /* ═══════════════════════════════════════════
       STAT CARDS
    ═══════════════════════════════════════════ */
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 28px;
    }
    @media (max-width: 1200px) { .dashboard-stats { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 768px)  { .dashboard-stats { grid-template-columns: 1fr; } }

    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 22px;
        display: flex; align-items: flex-start; gap: 16px;
        transition: border-color .2s, transform .2s, box-shadow .2s;
        position: relative; overflow: hidden;
    }
    .stat-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, var(--card-color, var(--accent)), transparent);
        opacity: 0; transition: opacity .3s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 36px rgba(0,0,0,.3); border-color: var(--border-2); }
    .stat-card:hover::before { opacity: 1; }

    .stat-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: grid; place-items: center; font-size: 20px; flex-shrink: 0;
    }
    .stat-icon.primary  { background: rgba(99,102,241,.15); color: var(--accent); --card-color: var(--accent); }
    .stat-icon.warning  { background: rgba(245,158,11,.15); color: var(--warning); --card-color: var(--warning); }
    .stat-icon.info     { background: rgba(34,211,238,.15); color: var(--info); --card-color: var(--info); }
    .stat-icon.success  { background: rgba(16,185,129,.15); color: var(--success); --card-color: var(--success); }
    .stat-icon.danger   { background: rgba(244,63,94,.15); color: var(--danger); --card-color: var(--danger); }

    .stat-content { flex: 1; }
    .stat-value { font-size: 1.7rem; font-weight: 700; color: var(--text); letter-spacing: -.03em; line-height: 1.1; }
    .stat-label { font-size: .78rem; color: var(--text-3); margin-top: 4px; text-transform: uppercase; letter-spacing: .08em; }
    .stat-trend { display: flex; align-items: center; gap: 4px; font-size: .75rem; margin-top: 10px; font-weight: 500; }
    .stat-trend.up      { color: var(--success); }
    .stat-trend.down    { color: var(--danger); }
    .stat-trend.neutral { color: var(--text-3); }

    /* ═══════════════════════════════════════════
       DASHBOARD GRID
    ═══════════════════════════════════════════ */
    .dashboard-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 28px;
    }
    @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }

    /* Quick Actions */
    .quick-actions { display: grid; grid-template-columns: repeat(2,1fr); gap: 12px; }

    .quick-action-btn {
        display: flex; flex-direction: column; align-items: center; gap: 8px;
        padding: 20px 12px;
        background: var(--bg-3); border: 1px solid var(--border);
        border-radius: var(--radius); cursor: pointer;
        transition: all .2s; font-family: 'Sora', sans-serif;
    }
    .quick-action-btn:hover { background: var(--surface-2); border-color: var(--accent); transform: translateY(-2px); }
    .quick-action-btn i { font-size: 20px; color: var(--accent); }
    .quick-action-btn span { font-size: .78rem; font-weight: 600; color: var(--text-2); }

    /* Top Products */
    .top-products { display: grid; grid-template-columns: repeat(2,1fr); gap: 12px; }
    @media (max-width: 768px) { .top-products { grid-template-columns: 1fr; } }

    .top-product-card {
        display: flex; align-items: center; gap: 12px;
        padding: 14px; background: var(--bg-3); border: 1px solid var(--border);
        border-radius: var(--radius); transition: all .2s;
    }
    .top-product-card:hover { background: var(--surface-2); border-color: var(--border-2); }
    .top-product-image {
        width: 44px; height: 44px; background: var(--surface);
        border-radius: 10px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0;
        border: 1px solid var(--border);
    }
    .top-product-info { flex: 1; min-width: 0; }
    .top-product-name { font-weight: 600; font-size: .82rem; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .top-product-sales { font-size: .75rem; color: var(--text-3); }
    .top-product-revenue { font-weight: 600; color: var(--success); font-size: .82rem; font-family: 'DM Mono', monospace; }

    /* ═══════════════════════════════════════════
       TABLES
    ═══════════════════════════════════════════ */
    .table-wrap { overflow-x: auto; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        padding: 12px 16px; text-align: left;
        background: var(--bg-3); font-weight: 600; color: var(--text-3);
        font-size: .72rem; text-transform: uppercase; letter-spacing: .1em;
        border-bottom: 1px solid var(--border);
    }
    .data-table td {
        padding: 14px 16px; border-bottom: 1px solid var(--border);
        color: var(--text-2); font-size: .84rem;
        transition: background .15s;
    }
    .data-table tbody tr:hover td { background: var(--bg-3); color: var(--text); }
    .data-table tbody tr:last-child td { border-bottom: none; }

    /* ═══════════════════════════════════════════
       BADGES
    ═══════════════════════════════════════════ */
    .badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; font-size: .7rem; font-weight: 600;
        border-radius: 99px; text-transform: uppercase; letter-spacing: .06em;
    }
    .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
    .badge-success { background: var(--success-bg); color: var(--success); }
    .badge-warning { background: var(--warning-bg); color: var(--warning); }
    .badge-info    { background: var(--info-bg); color: var(--info); }
    .badge-danger  { background: var(--danger-bg); color: var(--danger); }

    /* ═══════════════════════════════════════════
       BUTTONS
    ═══════════════════════════════════════════ */
    .btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 18px; border-radius: var(--radius);
        font-family: 'Sora', sans-serif; font-size: .82rem; font-weight: 600;
        cursor: pointer; transition: all .2s; border: none; white-space: nowrap;
    }
    .btn i { font-size: 13px; }

    .btn-primary {
        background: var(--accent); color: #fff;
        box-shadow: 0 4px 16px var(--accent-glow);
    }
    .btn-primary:hover { background: #7577f3; box-shadow: 0 6px 22px var(--accent-glow); transform: translateY(-1px); }

    .btn-secondary { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
    .btn-secondary:hover { background: var(--bg-4); color: var(--text); }

    .btn-outline {
        background: transparent;
        color: var(--text-2);
        border: 1px solid var(--border);
    }
    .btn-outline:hover { background: var(--surface-2); color: var(--text); border-color: var(--border-2); }
    .btn-outline.active { background: var(--accent-dim); color: var(--accent); border-color: var(--accent); }

    .btn-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(244,63,94,.2); }
    .btn-danger:hover { background: var(--danger); color: #fff; }

    .btn-sm { padding: 6px 13px; font-size: .76rem; }
    .btn-xs { padding: 4px 10px; font-size: .72rem; }

    /* ═══════════════════════════════════════════
       FORMS
    ═══════════════════════════════════════════ */
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 16px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-weight: 600; color: var(--text-2); margin-bottom: 7px; font-size: .78rem; text-transform: uppercase; letter-spacing: .07em; }
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%; padding: 10px 14px;
        background: var(--bg-3); border: 1px solid var(--border);
        border-radius: var(--radius); font-size: .85rem; color: var(--text);
        transition: border-color .2s, box-shadow .2s; outline: none;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-dim);
    }
    .form-group select option { background: var(--bg-3); }
    .form-group textarea { resize: vertical; min-height: 90px; }

    /* ═══════════════════════════════════════════
       ACTION ICONS
    ═══════════════════════════════════════════ */
    .action-icons { display: flex; gap: 4px; }
    .action-icon {
        width: 30px; height: 30px; border: none; background: transparent;
        cursor: pointer; border-radius: 8px; display: grid; place-items: center;
        color: var(--text-3); transition: all .2s;
    }
    .action-icon:hover { background: var(--surface-2); color: var(--accent); }
    .action-icon.delete:hover { background: var(--danger-bg); color: var(--danger); }
    .action-icon i { font-size: 13px; }

    /* ═══════════════════════════════════════════
       MODALS
    ═══════════════════════════════════════════ */
    .modal-overlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
        display: none; align-items: center; justify-content: center; z-index: 2000;
    }
    .modal-overlay.active { display: flex; }

    .modal {
        background: var(--bg-2); border: 1px solid var(--border-2);
        border-radius: var(--radius-lg); width: 90%; max-width: 520px;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 24px 64px rgba(0,0,0,.6);
        animation: modalIn .2s ease;
    }
    @keyframes modalIn { from { opacity: 0; transform: scale(.95) translateY(10px); } to { opacity: 1; transform: none; } }

    .modal-header {
        display: flex; justify-content: space-between; align-items: center;
        padding: 20px 24px; border-bottom: 1px solid var(--border);
    }
    .modal-title { font-size: .95rem; font-weight: 700; color: var(--text); }
    .modal-close {
        width: 30px; height: 30px; background: var(--surface); border: 1px solid var(--border);
        border-radius: 8px; cursor: pointer; font-size: 16px; color: var(--text-3);
        display: grid; place-items: center; transition: all .2s;
    }
    .modal-close:hover { background: var(--danger-bg); color: var(--danger); }

    .modal-body { padding: 24px; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 20px 24px; border-top: 1px solid var(--border); }

    /* ═══════════════════════════════════════════
       TAB NAVIGATION
    ═══════════════════════════════════════════ */
    .nav-tabs {
        display: flex; gap: 4px; margin-bottom: 24px;
        border-bottom: 1px solid var(--border); padding-bottom: 0; overflow-x: auto;
    }
    .nav-tab {
        display: flex; align-items: center; gap: 7px;
        padding: 9px 16px; background: none; border: none; border-bottom: 2px solid transparent;
        color: var(--text-3); font-weight: 500; cursor: pointer; white-space: nowrap;
        font-size: .82rem; font-family: 'Sora', sans-serif; transition: all .2s;
        margin-bottom: -1px; border-radius: var(--radius) var(--radius) 0 0;
    }
    .nav-tab:hover { color: var(--text); background: var(--bg-3); }
    .nav-tab.active { color: var(--accent); border-bottom-color: var(--accent); background: var(--accent-dim); }

    /* ═══════════════════════════════════════════
       CHARTS
    ═══════════════════════════════════════════ */
    .chart-container {
        height: 220px; display: flex; align-items: flex-end; justify-content: space-around;
        padding: 24px 8px 0; border-bottom: 1px solid var(--border); gap: 4px;
    }
    .chart-bar {
        flex: 1; max-width: 44px;
        background: linear-gradient(to top, var(--accent) 0%, var(--accent-2) 100%);
        border-radius: 6px 6px 0 0; transition: all .3s; position: relative;
        opacity: .85;
    }
    .chart-bar:hover { opacity: 1; filter: brightness(1.1); }
    .chart-bar::after {
        content: attr(data-value); position: absolute; top: -22px; left: 50%; transform: translateX(-50%);
        font-size: .68rem; font-weight: 700; color: var(--text-2); font-family: 'DM Mono', monospace; white-space: nowrap;
    }
    .chart-labels { display: flex; justify-content: space-around; padding: 10px 8px 0; gap: 4px; }
    .chart-label { flex: 1; max-width: 44px; text-align: center; font-size: .68rem; color: var(--text-3); }

    /* ═══════════════════════════════════════════
       SETTINGS
    ═══════════════════════════════════════════ */
    .settings-tabs { display: flex; gap: 6px; margin-bottom: 24px; flex-wrap: wrap; }
    .settings-tab {
        display: flex; align-items: center; gap: 7px;
        padding: 9px 16px; background: var(--surface); border: 1px solid var(--border);
        color: var(--text-3); font-weight: 500; cursor: pointer;
        border-radius: var(--radius); transition: all .2s; font-size: .82rem;
        font-family: 'Sora', sans-serif;
    }
    .settings-tab:hover { background: var(--surface-2); color: var(--text); }
    .settings-tab.active { background: var(--accent-dim); border-color: var(--accent); color: var(--accent); }
    .settings-panel { display: none; }
    .settings-panel.active { display: block; }
    .settings-section { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 20px; }
    .settings-section-title { font-size: .95rem; font-weight: 700; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); color: var(--text); }

    /* ═══════════════════════════════════════════
       REPORT CARDS
    ═══════════════════════════════════════════ */
    .report-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr)); gap: 16px; margin-bottom: 28px; }
    .report-card {
        display: flex; gap: 16px; padding: 22px;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg);
        cursor: pointer; transition: all .2s;
    }
    .report-card:hover { border-color: var(--accent); transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0,0,0,.3); }
    .report-icon { width: 52px; height: 52px; border-radius: 12px; display: grid; place-items: center; font-size: 22px; flex-shrink: 0; }
    .report-icon.sales     { background: rgba(99,102,241,.15); color: var(--accent); }
    .report-icon.inventory { background: rgba(16,185,129,.15); color: var(--success); }
    .report-icon.products  { background: rgba(245,158,11,.15); color: var(--warning); }
    .report-icon.customers { background: rgba(34,211,238,.15); color: var(--info); }
    .report-info h4 { font-size: .9rem; font-weight: 600; margin-bottom: 4px; color: var(--text); }
    .report-info p  { font-size: .8rem; color: var(--text-3); margin-bottom: 10px; line-height: 1.5; }
    .report-link { color: var(--accent); font-weight: 600; font-size: .78rem; }

    .export-section { padding: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); margin-top: 20px; }
    .export-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
    .date-range-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }

    /* ═══════════════════════════════════════════
       PAGE SECTIONS
    ═══════════════════════════════════════════ */
    .page-section { display: none; }
    .page-section.active { display: block; }

    /* ═══════════════════════════════════════════
       PAGE HEADER ROW
    ═══════════════════════════════════════════ */
    .page-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
    }
    .page-header h2 { font-size: 1.3rem; font-weight: 700; letter-spacing: -.02em; color: var(--text); }
    .page-header p  { font-size: .82rem; color: var(--text-3); margin-top: 2px; }

    /* ═══════════════════════════════════════════
       EMPTY STATE
    ═══════════════════════════════════════════ */
    .empty-state {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 60px 20px; color: var(--text-3); text-align: center; gap: 12px;
    }
    .empty-state i { font-size: 40px; opacity: .4; }
    .empty-state p { font-size: .85rem; }

    /* ═══════════════════════════════════════════
       LOADING SKELETON
    ═══════════════════════════════════════════ */
    @keyframes shimmer {
        0%   { background-position: -1000px 0; }
        100% { background-position:  1000px 0; }
    }
    .skeleton {
        background: linear-gradient(90deg, var(--surface) 25%, var(--surface-2) 50%, var(--surface) 75%);
        background-size: 1000px 100%;
        animation: shimmer 1.5s infinite;
        border-radius: 6px;
    }

    /* ═══════════════════════════════════════════
       NOTIFICATION TOAST
    ═══════════════════════════════════════════ */
    #notificationToast {
        position: fixed; top: calc(var(--header-h) + 16px); right: 20px;
        padding: 14px 18px; border-radius: var(--radius);
        color: #fff; box-shadow: 0 8px 28px rgba(0,0,0,.4); z-index: 3000;
        display: flex; align-items: center; gap: 12px; font-size: .84rem; font-weight: 500;
        border: 1px solid rgba(255,255,255,.15); backdrop-filter: blur(10px);
        animation: toastIn .25s ease;
    }
    @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }
    #notificationToast button { background: none; border: none; color: rgba(255,255,255,.7); cursor: pointer; font-size: 18px; line-height: 1; transition: color .2s; }
    #notificationToast button:hover { color: #fff; }

    /* ═══════════════════════════════════════════
       RESPONSIVE
    ═══════════════════════════════════════════ */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: var(--sidebar-w); }
        .sidebar.mobile-open { transform: translateX(0); }
        .sidebar.collapsed { width: var(--sidebar-w); transform: translateX(-100%); }
        .main-wrapper { margin-left: 0 !important; }
        .mobile-menu-btn { display: flex !important; }
        .main-content { padding: 16px; }
        .header { padding: 0 16px; }
        .search-box { display: none; }
        .quick-actions { grid-template-columns: repeat(2,1fr); }
        .top-products { grid-template-columns: 1fr; }
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation: none !important;
            transition: none !important;
            scroll-behavior: auto !important;
        }
    }

    /* ═══════════════════════════════════════════
       MONOSPACE NUMBERS
    ═══════════════════════════════════════════ */
    .mono { font-family: 'DM Mono', monospace; }

    /* ═══════════════════════════════════════════
       MISC UTILITIES
    ═══════════════════════════════════════════ */
    .text-muted { color: var(--text-3); }
    .text-success { color: var(--success); }
    .text-danger { color: var(--danger); }
    .text-warning { color: var(--warning); }
    .text-accent { color: var(--accent); }
    .flex { display: flex; }
    .items-center { align-items: center; }
    .gap-2 { gap: 8px; }
    .gap-3 { gap: 12px; }
    .mt-1 { margin-top: 4px; }
    .mt-2 { margin-top: 8px; }
    .mb-3 { margin-bottom: 12px; }
    .mb-4 { margin-bottom: 16px; }
    .mb-6 { margin-bottom: 24px; }
    .w-full { width: 100%; }
    </style>
</head>
<body>
<div class="app-container">

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo"><i class="fas fa-boxes-stacked"></i></div>
            <span class="sidebar-brand">StockFlow</span>
        </div>

        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a href="?page=dashboard" class="nav-link <?= $page==='dashboard'?'active':'' ?>" data-label="Dashboard">
                        <i class="fas fa-house"></i><span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=products" class="nav-link <?= $page==='products'?'active':'' ?>" data-label="Products">
                        <i class="fas fa-box"></i><span class="nav-text">Products</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=inventory" class="nav-link <?= $page==='inventory'?'active':'' ?>" data-label="Inventory">
                        <i class="fas fa-warehouse"></i><span class="nav-text">Inventory</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=orders" class="nav-link <?= $page==='orders'?'active':'' ?>" data-label="Orders">
                        <i class="fas fa-shopping-cart"></i><span class="nav-text">Orders</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=customers" class="nav-link <?= $page==='customers'?'active':'' ?>" data-label="Customers">
                        <i class="fas fa-users"></i><span class="nav-text">Customers</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=suppliers" class="nav-link <?= $page==='suppliers'?'active':'' ?>" data-label="Suppliers">
                        <i class="fas fa-truck"></i><span class="nav-text">Suppliers</span>
                    </a>
                </li>
                <div class="nav-divider"></div>
                <li class="nav-item">
                    <a href="?page=reports" class="nav-link <?= $page==='reports'?'active':'' ?>" data-label="Reports">
                        <i class="fas fa-chart-bar"></i><span class="nav-text">Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=settings" class="nav-link <?= $page==='settings'?'active':'' ?>" data-label="Settings">
                        <i class="fas fa-cog"></i><span class="nav-text">Settings</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="?page=test" class="nav-link <?= $page==='test'?'active':'' ?>" data-label="Diagnostics" style="color:var(--warning)">
                        <i class="fas fa-stethoscope"></i><span class="nav-text">Diagnostics</span>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
                <i class="fas fa-chevrons-left"></i>
                <span>Collapse</span>
            </button>
            <a href="logout.php" class="collapse-btn">
                <i class="fas fa-arrow-right-from-bracket"></i>
                <span class="sidebar-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- ── MAIN WRAPPER ── -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- HEADER -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn" style="display:none">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title">
                    <span class="crumb">StockFlow</span>
                    <span class="sep">/</span>
                    <?= htmlspecialchars($page_title) ?>
                </h1>
            </div>

            <div class="header-right">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search anything…" id="globalSearch">
                </div>

                <button
                    type="button"
                    class="header-icon-btn theme-toggle"
                    id="themeToggle"
                    aria-label="Switch to dark mode"
                    aria-pressed="false"
                    title="Toggle color theme"
                >
                    <span class="theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="4.25"></circle>
                            <path d="M12 2v2.25M12 19.75V22M4.93 4.93l1.59 1.59M17.48 17.48l1.59 1.59M2 12h2.25M19.75 12H22M4.93 19.07l1.59-1.59M17.48 6.52l1.59-1.59"></path>
                        </svg>
                    </span>
                    <span class="theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M21 13.15A8.95 8.95 0 1 1 10.85 3 7.15 7.15 0 0 0 21 13.15Z"></path>
                        </svg>
                    </span>
                    <span class="sr-only">Toggle dark and light mode</span>
                </button>

                <div class="header-icon-btn" id="notificationBtn" title="Notifications">
                    <i class="fas fa-bell" style="font-size:15px"></i>
                    <?php if ($notification_count > 0): ?>
                        <span class="badge-dot"></span>
                    <?php endif; ?>
                    <div class="notification-dropdown" id="notificationDropdown">
                        <?php if ($notification_count === 0): ?>
                            <div class="notification-empty">No new notifications</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $note): ?>
                                <div class="notification-item">
                                    <a href="<?php echo htmlspecialchars($note['link']); ?>">
                                        <?php echo htmlspecialchars($note['message']); ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="user-profile">
                    <div class="user-avatar"><?= strtoupper(substr($user_name,0,2)) ?></div>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($user_name) ?></span>
                        <span class="user-role"><?= ucfirst($user_role) ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <?php
            $module_file = "modules/{$page}.php";
            if (file_exists($module_file)) {
                include $module_file;
            } else {
                echo "<div class='card'><div class='card-body'>";
                echo "<div class='empty-state'><i class='fas fa-circle-exclamation'></i><p>Page <strong>{$page}</strong> could not be found.</p></div>";
                echo "</div></div>";
            }
            ?>
        </main>
    </div>
</div>

<!-- NOTIFICATION TOAST -->
<div id="notificationToast" style="display:none">
    <span id="notificationMessage"></span>
    <button onclick="hideNotification()" aria-label="Close">&times;</button>
</div>

<script>
    const storageKey = 'admin-theme';
    const root = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

    function getSavedTheme() {
        const savedTheme = localStorage.getItem(storageKey);
        return savedTheme === 'light' || savedTheme === 'dark' ? savedTheme : null;
    }

    function getSystemTheme() {
        return systemTheme.matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        root.setAttribute('data-theme', theme);
        themeToggle?.setAttribute('aria-pressed', String(isDark));
        themeToggle?.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        themeToggle?.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    applyTheme(getSavedTheme() || getSystemTheme());

    themeToggle?.addEventListener('click', () => {
        const nextTheme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem(storageKey, nextTheme);
        applyTheme(nextTheme);
    });

    const handleSystemThemeChange = (event) => {
        if (!getSavedTheme()) {
            applyTheme(event.matches ? 'dark' : 'light');
        }
    };

    if (typeof systemTheme.addEventListener === 'function') {
        systemTheme.addEventListener('change', handleSystemThemeChange);
    } else if (typeof systemTheme.addListener === 'function') {
        systemTheme.addListener(handleSystemThemeChange);
    }

    // ── Sidebar collapse
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('collapsed');
    }

    // ── Mobile menu
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('mobile-open');
    });

    // ── Close sidebar on outside click (mobile)
    document.addEventListener('click', (e) => {
        const sidebar = document.getElementById('sidebar');
        const btn = document.getElementById('mobileMenuBtn');
        if (window.innerWidth <= 768 && sidebar.classList.contains('mobile-open')) {
            if (!sidebar.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });

    // ── Notification
    function showNotification(message, type = 'success') {
        const toast = document.getElementById('notificationToast');
        const msg   = document.getElementById('notificationMessage');
        const colors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--accent)', warning: 'var(--warning)' };
        toast.style.background = colors[type] || colors.success;
        msg.textContent = message;
        toast.style.display = 'flex';
        clearTimeout(toast._t);
        toast._t = setTimeout(hideNotification, 3500);
    }

    function hideNotification() {
        document.getElementById('notificationToast').style.display = 'none';
    }

    // ── Modal helpers
    function showModal(id) {
        document.getElementById(id)?.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function hideModal(id) {
        document.getElementById(id)?.classList.remove('active');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // ── Global search
    function applyGlobalSearch(query) {
        const tables = document.querySelectorAll('.data-table, table');
        if (!tables.length) return;
        const q = query.toLowerCase();
        tables.forEach(table => {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }

    document.getElementById('globalSearch')?.addEventListener('input', function(e) {
        applyGlobalSearch(e.target.value);
    });

    // Notifications dropdown
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    notificationBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        notificationDropdown?.classList.toggle('active');
    });
    document.addEventListener('click', () => {
        notificationDropdown?.classList.remove('active');
    });

    // ── Sidebar collapse (legacy support for any collapse-btn that isn't logout)
    document.querySelector('.collapse-btn')?.addEventListener('click', function(e) {
        // Only toggle if it's not a logout link
        if (!this.href || this.href.includes('logout')) return;
        e.preventDefault();
        toggleSidebar();
    });
</script>
</body>
</html>
