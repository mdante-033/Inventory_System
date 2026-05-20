<?php
/**
 * Admin Operations Page
 * Inventory management with CRUD operations, stock adjustments, and filtering
 * 
 * @package InventorySystem
 */

require_once __DIR__ . '/../includes/session.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Include database connection
require_once 'db_connect.php';

$user_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$user_role = $_SESSION['role'] ?? 'staff';
$user_id = $_SESSION['user_id'] ?? 0;

// Handle form submissions
$message = '';
$message_type = '';

// Create new product
if (isset($_POST['action']) && $_POST['action'] === 'create_product') {
    try {
        $sku = trim($_POST['sku'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = $_POST['category_id'] ?? 0;
        $unit_price = $_POST['unit_price'] ?? 0;
        $cost_price = $_POST['cost_price'] ?? 0;
        $quantity = $_POST['quantity'] ?? 0;
        $reorder_level = $_POST['reorder_level'] ?? 10;
        
        // Validation
        if (empty($sku) || empty($name) || empty($category_id)) {
            throw new Exception('SKU, Name, and Category are required.');
        }
        
        // Check if SKU exists
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        if ($stmt->fetch()) {
            throw new Exception('SKU already exists.');
        }
        
        // Insert product
        $stmt = $pdo->prepare("
            INSERT INTO products (sku, barcode, name, description, category_id, unit_price, cost_price, quantity, reorder_level, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, true, NOW(), NOW())
        ");
        $stmt->execute([$sku, $barcode, $name, $description, $category_id, $unit_price, $cost_price, $quantity, $reorder_level]);
        
        $product_id = $pdo->lastInsertId();
        
        // Log stock if quantity > 0
        if ($quantity > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO stock_logs (product_id, user_id, action, quantity_before, quantity_after, quantity_changed, reference_number, notes, created_at)
                VALUES (?, ?, 'add', 0, ?, ?, 'INIT', 'Initial stock', NOW())
            ");
            $stmt->execute([$product_id, $user_id, $quantity, $quantity]);
        }
        
        $message = 'Product created successfully.';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

// Update product
if (isset($_POST['action']) && $_POST['action'] === 'update_product') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        $sku = trim($_POST['sku'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = $_POST['category_id'] ?? 0;
        $unit_price = $_POST['unit_price'] ?? 0;
        $cost_price = $_POST['cost_price'] ?? 0;
        $reorder_level = $_POST['reorder_level'] ?? 10;
        $is_active = isset($_POST['is_active']) ? true : false;
        
        // Validation
        if (empty($sku) || empty($name) || empty($category_id)) {
            throw new Exception('SKU, Name, and Category are required.');
        }
        
        // Check if SKU exists (excluding current product)
        $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND id != ?");
        $stmt->execute([$sku, $product_id]);
        if ($stmt->fetch()) {
            throw new Exception('SKU already exists.');
        }
        
        // Update product
        $stmt = $pdo->prepare("
            UPDATE products 
            SET sku = ?, barcode = ?, name = ?, description = ?, category_id = ?, unit_price = ?, cost_price = ?, reorder_level = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$sku, $barcode, $name, $description, $category_id, $unit_price, $cost_price, $reorder_level, $is_active, $product_id]);
        
        $message = 'Product updated successfully.';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

// Delete product
if (isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        
        $message = 'Product deleted successfully.';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

// Stock adjustment
if (isset($_POST['action']) && $_POST['action'] === 'stock_adjustment') {
    try {
        $product_id = $_POST['product_id'] ?? 0;
        $adjustment_type = $_POST['adjustment_type'] ?? 'add';
        $quantity = abs($_POST['quantity'] ?? 0);
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($product_id) || $quantity <= 0) {
            throw new Exception('Product and quantity are required.');
        }
        
        // Get current quantity
        $stmt = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception('Product not found.');
        }
        
        $quantity_before = $product['quantity'];
        
        if ($adjustment_type === 'add') {
            $quantity_after = $quantity_before + $quantity;
            $quantity_changed = $quantity;
        } elseif ($adjustment_type === 'remove') {
            if ($quantity > $quantity_before) {
                throw new Exception('Cannot remove more than available stock.');
            }
            $quantity_after = $quantity_before - $quantity;
            $quantity_changed = -$quantity;
        } else {
            // Set to specific quantity
            $quantity_after = $quantity;
            $quantity_changed = $quantity - $quantity_before;
        }
        
        // Update product quantity
        $stmt = $pdo->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$quantity_after, $product_id]);
        
        // Log the adjustment
        $stmt = $pdo->prepare("
            INSERT INTO stock_logs (product_id, user_id, action, quantity_before, quantity_after, quantity_changed, reference_number, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$product_id, $user_id, $adjustment_type, $quantity_before, $quantity_after, $quantity_changed, $reference_number, $notes]);
        
        $message = 'Stock adjusted successfully.';
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'danger';
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

// Build query
$where_clauses = ["p.is_active = TRUE"];
$params = [];

if ($filter === 'low_stock') {
    $where_clauses[] = "p.quantity <= p.reorder_level AND p.quantity > 0";
} elseif ($filter === 'out_of_stock') {
    $where_clauses[] = "p.quantity = 0";
} elseif ($filter === 'overstocked') {
    $where_clauses[] = "p.quantity > (p.reorder_level * 3)";
}

if (!empty($search)) {
    $where_clauses[] = "(p.name ILIKE ? OR p.sku ILIKE ? OR p.barcode ILIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($category_filter)) {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $category_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch products
try {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE {$where_sql}
        ORDER BY p.updated_at DESC
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // Fetch categories for dropdown
    $stmt = $pdo->query("SELECT id, name FROM categories WHERE is_active = TRUE ORDER BY name");
    $categories = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $products = [];
    $categories = [];
    error_log("Error fetching products: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Inventory System</title>
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --sidebar-width: 260px;
            --header-height: 64px;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-color);
            color: var(--text-primary);
            line-height: 1.5;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e1b4b 0%, #312e81 100%);
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: white;
        }

        .sidebar-brand svg {
            width: 36px;
            height: 36px;
            color: #818cf8;
        }

        .sidebar-brand h1 {
            font-size: 1.125rem;
            font-weight: 700;
        }

        .sidebar-brand span {
            font-size: 0.75rem;
            color: #818cf8;
            text-transform: uppercase;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-section {
            margin-bottom: 1.5rem;
        }

        .nav-section-title {
            padding: 0.5rem 1.5rem;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255, 255, 255, 0.4);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1.5rem;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .nav-item.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: #818cf8;
        }

        .nav-item svg {
            width: 20px;
            height: 20px;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            min-height: 100vh;
        }

        .header {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: var(--header-height);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-info {
            text-align: left;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: capitalize;
        }

        .dashboard-content {
            padding: 2rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .toolbar {
            background: var(--card-bg);
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.5rem 1rem 0.5rem 2.5rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }

        .search-box svg {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--text-secondary);
        }

        .filter-group {
            display: flex;
            gap: 0.5rem;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            background: white;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            color: var(--text-secondary);
        }

        .filter-btn:hover, .filter-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn svg {
            width: 16px;
            height: 16px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
        }

        .card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
        }

        .card-body {
            padding: 0;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            background: var(--bg-color);
        }

        .data-table td {
            font-size: 0.875rem;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-flex;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #059669;
        }

        .badge-warning {
            background: #fef3c7;
            color: #d97706;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #2563eb;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--card-bg);
            border-radius: 0.75rem;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.95);
            transition: transform 0.2s ease;
        }

        .modal-overlay.active .modal {
            transform: scale(1);
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.125rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0.25rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            transition: border-color 0.15s ease;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-checkbox input {
            width: 1rem;
            height: 1rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .dashboard-content {
                padding: 1rem;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    </svg>
                    <div>
                        <h1>Inventory</h1>
                        <span>Admin Panel</span>
                    </div>
                </a>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="dashboard.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Dashboard
                    </a>
                    <a href="admin_operations.php" class="nav-item active">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
                        Inventory
                    </a>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <a href="admin_users.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        User Management
                    </a>
                    <a href="admin_analytics.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        Analytics
                    </a>
                    <a href="admin_reports.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                        Reports
                    </a>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <a href="admin_settings.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        Settings
                    </a>
                    <a href="../logout.php" class="nav-item">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                        Logout
                    </a>
                </div>
            </nav>
        </aside>

        <main class="main-content">
            <header class="header">
                <h2 class="page-title">Inventory Management</h2>
                <div class="header-right">
                    <div class="user-menu">
                        <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?php if ($message_type === 'success'): ?>
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        <?php else: ?>
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                        <?php endif; ?>
                    </svg>
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <div class="toolbar">
                    <div class="search-box">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <form method="GET" style="display: contents;">
                            <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        </form>
                    </div>
                    <div class="filter-group">
                        <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                        <a href="?filter=low_stock" class="filter-btn <?php echo $filter === 'low_stock' ? 'active' : ''; ?>">Low Stock</a>
                        <a href="?filter=out_of_stock" class="filter-btn <?php echo $filter === 'out_of_stock' ? 'active' : ''; ?>">Out of Stock</a>
                        <a href="?filter=overstocked" class="filter-btn <?php echo $filter === 'overstocked' ? 'active' : ''; ?>">Overstocked</a>
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addProductModal')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add Product
                    </button>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Products (<?php echo count($products); ?>)</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-secondary); padding: 2rem;">No products found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                            <td>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <?php if ($product['barcode']): ?>
                                                <div style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo htmlspecialchars($product['barcode']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                            <td><?php echo number_format($product['quantity']); ?></td>
                                            <td>$<?php echo number_format($product['unit_price'], 2); ?></td>
                                            <td>
                                                <?php if ($product['quantity'] == 0): ?>
                                                <span class="badge badge-danger">Out of Stock</span>
                                                <?php elseif ($product['quantity'] <= $product['reorder_level']): ?>
                                                <span class="badge badge-warning">Low Stock</span>
                                                <?php elseif ($product['quantity'] > ($product['reorder_level'] * 3)): ?>
                                                <span class="badge badge-info">Overstocked</span>
                                                <?php else: ?>
                                                <span class="badge badge-success">In Stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="actions">
                                                    <button class="btn btn-secondary btn-sm" onclick="adjustStock(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>', <?php echo $product['quantity']; ?>)">Adjust</button>
                                                    <button class="btn btn-secondary btn-sm" onclick="editProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['sku']); ?>', '<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>', '<?php echo htmlspecialchars($product['name']); ?>', '<?php echo htmlspecialchars($product['description'] ?? ''); ?>', <?php echo $product['category_id']; ?>, <?php echo $product['unit_price']; ?>, <?php echo $product['cost_price']; ?>, <?php echo $product['reorder_level']; ?>, <?php echo $product['quantity']; ?>, <?php echo $product['is_active'] ? 'true' : 'false'; ?>)">Edit</button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Product Modal -->
    <div class="modal-overlay" id="addProductModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add New Product</h3>
                <button class="modal-close" onclick="closeModal('addProductModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_product">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SKU *</label>
                            <input type="text" name="sku" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Unit Price *</label>
                            <input type="number" name="unit_price" class="form-input" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cost Price</label>
                            <input type="number" name="cost_price" class="form-input" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Initial Quantity</label>
                            <input type="number" name="quantity" class="form-input" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-input" min="0" value="10">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal-overlay" id="editProductModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Edit Product</h3>
                <button class="modal-close" onclick="closeModal('editProductModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_product">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SKU *</label>
                            <input type="text" name="sku" id="edit_sku" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" id="edit_barcode" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Name *</label>
                        <input type="text" name="name" id="edit_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category_id" id="edit_category_id" class="form-select" required>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Unit Price *</label>
                            <input type="number" name="unit_price" id="edit_unit_price" class="form-input" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cost Price</label>
                            <input type="number" name="cost_price" id="edit_cost_price" class="form-input" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" id="edit_reorder_level" class="form-input" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Current Stock (Read-only)</label>
                            <input type="text" id="edit_quantity" class="form-input" readonly>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="is_active" id="edit_is_active">
                            <span>Active</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock Adjustment Modal -->
    <div class="modal-overlay" id="adjustStockModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Adjust Stock</h3>
                <button class="modal-close" onclick="closeModal('adjustStockModal')">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="stock_adjustment">
                    <input type="hidden" name="product_id" id="adjust_product_id">
                    <div class="form-group">
                        <label class="form-label">Product</label>
                        <input type="text" id="adjust_product_name" class="form-input" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Current Stock</label>
                        <input type="text" id="adjust_current_stock" class="form-input" readonly>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Adjustment Type</label>
                            <select name="adjustment_type" class="form-select" id="adjustment_type">
                                <option value="add">Add Stock</option>
                                <option value="remove">Remove Stock</option>
                                <option value="set">Set to Specific Quantity</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-input" min="1" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference Number</label>
                        <input type="text" name="reference_number" class="form-input" placeholder="e.g., PO-12345">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" placeholder="Reason for adjustment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('adjustStockModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Adjust Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        function editProduct(id, sku, barcode, name, description, category_id, unit_price, cost_price, reorder_level, quantity, is_active) {
            document.getElementById('edit_product_id').value = id;
            document.getElementById('edit_sku').value = sku;
            document.getElementById('edit_barcode').value = barcode;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_category_id').value = category_id;
            document.getElementById('edit_unit_price').value = unit_price;
            document.getElementById('edit_cost_price').value = cost_price;
            document.getElementById('edit_reorder_level').value = reorder_level;
            document.getElementById('edit_quantity').value = quantity;
            document.getElementById('edit_is_active').checked = is_active;
            openModal('editProductModal');
        }

        function adjustStock(id, name, quantity) {
            document.getElementById('adjust_product_id').value = id;
            document.getElementById('adjust_product_name').value = name;
            document.getElementById('adjust_current_stock').value = quantity;
            openModal('adjustStockModal');
        }

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
