<?php
/**
 * Admin Analytics Dashboard Page
 * Data visualization with charts, statistics, and insights
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

// Get date range filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Fetch analytics data
try {
    // Total products and categories
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = TRUE");
    $total_products = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = TRUE");
    $total_categories = $stmt->fetchColumn();
    
    // Inventory value
    $stmt = $pdo->query("SELECT COALESCE(SUM(quantity * unit_price), 0) FROM products WHERE is_active = TRUE");
    $total_inventory_value = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(quantity * cost_price), 0) FROM products WHERE is_active = TRUE");
    $total_cost_value = $stmt->fetchColumn();
    
    // Products by category (for pie chart)
    $stmt = $pdo->query("
        SELECT c.name, COUNT(p.id) as product_count, COALESCE(SUM(p.quantity), 0) as total_quantity
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.is_active = TRUE
        WHERE c.is_active = TRUE
        GROUP BY c.id, c.name
        ORDER BY product_count DESC
    ");
    $category_data = $stmt->fetchAll();
    
    // Low stock products
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM products 
        WHERE quantity <= reorder_level AND quantity > 0 AND is_active = TRUE
    ");
    $low_stock_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE quantity = 0 AND is_active = TRUE");
    $out_of_stock_count = $stmt->fetchColumn();
    
    // Stock status distribution
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN quantity = 0 THEN 'Out of Stock'
                WHEN quantity <= reorder_level THEN 'Low Stock'
                WHEN quantity > reorder_level * 3 THEN 'Overstocked'
                ELSE 'In Stock'
            END as status,
            COUNT(*) as count
        FROM products 
        WHERE is_active = TRUE
        GROUP BY status
    ");
    $stock_status = $stmt->fetchAll();
    
    // Top products by quantity
    $stmt = $pdo->query("
        SELECT p.name, p.quantity, c.name as category_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = TRUE
        ORDER BY p.quantity DESC
        LIMIT 10
    ");
    $top_products = $stmt->fetchAll();
    
    // Recent stock movements (last 30 days)
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            action,
            SUM(ABS(quantity_changed)) as total_qty,
            COUNT(*) as movements
        FROM stock_logs
        WHERE created_at >= NOW() - INTERVAL '30 days'
        GROUP BY DATE(created_at), action
        ORDER BY date DESC
    ");
    $stock_movements = $stmt->fetchAll();
    
    // User activity
    $stmt = $pdo->query("
        SELECT u.username, COUNT(sl.id) as action_count
        FROM users u
        LEFT JOIN stock_logs sl ON u.id = sl.user_id
        GROUP BY u.id, u.username
        ORDER BY action_count DESC
        LIMIT 5
    ");
    $user_activity = $stmt->fetchAll();
    
    // Monthly stock movements (last 6 months)
    $stmt = $pdo->query("
        SELECT 
            TO_CHAR(created_at, 'YYYY-MM') as month,
            action,
            SUM(ABS(quantity_changed)) as total_qty
        FROM stock_logs
        WHERE created_at >= NOW() - INTERVAL '6 months'
        GROUP BY TO_CHAR(created_at, 'YYYY-MM'), action
        ORDER BY month
    ");
    $monthly_movements = $stmt->fetchAll();
    
    // Products added this month
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM products 
        WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $new_products_month = $stmt->fetchColumn();
    
    // Total stock movements this month
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM stock_logs 
        WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE)
    ");
    $movements_month = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Analytics error: " . $e->getMessage());
    // Set default values
    $total_products = 0;
    $total_categories = 0;
    $total_inventory_value = 0;
    $total_cost_value = 0;
    $category_data = [];
    $low_stock_count = 0;
    $out_of_stock_count = 0;
    $stock_status = [];
    $top_products = [];
    $stock_movements = [];
    $user_activity = [];
    $monthly_movements = [];
    $new_products_month = 0;
    $movements_month = 0;
}

// Calculate profit margin
$profit_margin = $total_inventory_value > 0 ? (($total_inventory_value - $total_cost_value) / $total_inventory_value) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Inventory System</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Sidebar */
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

        /* Main Content */
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

        /* Filters */
        .filters-bar {
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

        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .form-input {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }

        .form-input:focus {
            outline: none;
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

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.primary::before { background: var(--primary-color); }
        .stat-card.success::before { background: var(--success-color); }
        .stat-card.warning::before { background: var(--warning-color); }
        .stat-card.danger::before { background: var(--danger-color); }
        .stat-card.info::before { background: var(--info-color); }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }

        .stat-sub {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .chart-card.full-width {
            grid-column: span 2;
        }

        .chart-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 600;
        }

        .chart-body {
            padding: 1.5rem;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* Tables */
        .data-table-container {
            background: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .data-table-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .data-table-title {
            font-size: 1rem;
            font-weight: 600;
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

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: var(--bg-color);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-fill.primary { background: var(--primary-color); }
        .progress-fill.success { background: var(--success-color); }
        .progress-fill.warning { background: var(--warning-color); }
        .progress-fill.danger { background: var(--danger-color); }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            .chart-card.full-width {
                grid-column: span 1;
            }
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
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
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
                    <a href="admin_operations.php" class="nav-item">
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
                    <a href="admin_analytics.php" class="nav-item active">
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

        <!-- Main Content -->
        <main class="main-content">
            <header class="header">
                <h2 class="page-title">Analytics Dashboard</h2>
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
                <!-- Filters -->
                <form class="filters-bar" method="GET">
                    <div class="filter-group">
                        <label class="filter-label">From:</label>
                        <input type="date" name="date_from" class="form-input" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">To:</label>
                        <input type="date" name="date_to" class="form-input" value="<?php echo $date_to; ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </form>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card primary">
                        <div class="stat-label">Total Products</div>
                        <div class="stat-value"><?php echo number_format($total_products); ?></div>
                        <div class="stat-sub"><?php echo $total_categories; ?> categories</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-label">Inventory Value</div>
                        <div class="stat-value">$<?php echo number_format($total_inventory_value, 2); ?></div>
                        <div class="stat-sub">Cost: $<?php echo number_format($total_cost_value, 2); ?></div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-label">Low Stock Items</div>
                        <div class="stat-value"><?php echo number_format($low_stock_count); ?></div>
                        <div class="stat-sub"><?php echo $out_of_stock_count; ?> out of stock</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-label">Profit Margin</div>
                        <div class="stat-value"><?php echo number_format($profit_margin, 1); ?>%</div>
                        <div class="stat-sub">Based on cost vs selling price</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-label">This Month</div>
                        <div class="stat-value"><?php echo number_format($movements_month); ?></div>
                        <div class="stat-sub"><?php echo $new_products_month; ?> new products</div>
                    </div>
                </div>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Category Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Products by Category</h3>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Stock Status -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Stock Status Distribution</h3>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="stockStatusChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Movements -->
                    <div class="chart-card full-width">
                        <div class="chart-header">
                            <h3 class="chart-title">Stock Movements (Last 6 Months)</h3>
                        </div>
                        <div class="chart-body">
                            <div class="chart-container">
                                <canvas id="movementsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Products Table -->
                <div class="data-table-container">
                    <div class="data-table-header">
                        <h3 class="data-table-title">Top Products by Quantity</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_products)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-secondary);">No products found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($top_products as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                    <td><?php echo number_format($product['quantity']); ?></td>
                                    <td>
                                        <?php if ($product['quantity'] == 0): ?>
                                        <span style="color: var(--danger-color); font-weight: 600;">Out of Stock</span>
                                        <?php elseif ($product['quantity'] <= 10): ?>
                                        <span style="color: var(--warning-color); font-weight: 600;">Low Stock</span>
                                        <?php else: ?>
                                        <span style="color: var(--success-color); font-weight: 600;">In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- User Activity -->
                <div class="data-table-container">
                    <div class="data-table-header">
                        <h3 class="data-table-title">User Activity</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Actions Performed</th>
                                <th>Activity Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($user_activity)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: var(--text-secondary);">No activity recorded</td>
                            </tr>
                            <?php else: ?>
                                <?php 
                                $max_activity = max(array_column($user_activity, 'action_count'));
                                foreach ($user_activity as $user): 
                                    $percentage = $max_activity > 0 ? ($user['action_count'] / $max_activity) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo number_format($user['action_count']); ?></td>
                                    <td style="width: 200px;">
                                        <div class="progress-bar">
                                            <div class="progress-fill <?php echo $percentage > 70 ? 'primary' : ($percentage > 30 ? 'success' : 'warning'); ?>" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Category Chart
        const categoryData = <?php echo json_encode($category_data); ?>;
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: categoryData.map(item => item.name),
                datasets: [{
                    data: categoryData.map(item => item.total_quantity),
                    backgroundColor: [
                        '#4f46e5', '#10b981', '#f59e0b', '#ef4444', 
                        '#3b82f6', '#8b5cf6', '#ec4899', '#06b6d4'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { padding: 15, usePointStyle: true }
                    }
                },
                cutout: '60%'
            }
        });

        // Stock Status Chart
        const stockStatusData = <?php echo json_encode($stock_status); ?>;
        const stockStatusCtx = document.getElementById('stockStatusChart').getContext('2d');
        new Chart(stockStatusCtx, {
            type: 'pie',
            data: {
                labels: stockStatusData.map(item => item.status),
                datasets: [{
                    data: stockStatusData.map(item => item.count),
                    backgroundColor: [
                        '#ef4444', '#f59e0b', '#10b981', '#4f46e5'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { padding: 15, usePointStyle: true }
                    }
                }
            }
        });

        // Monthly Movements Chart
        const monthlyData = <?php echo json_encode($monthly_movements); ?>;
        
        // Group by month
        const months = [...new Set(monthlyData.map(item => item.month))];
        const actions = ['add', 'remove', 'adjust'];
        const datasets = actions.map((action, index) => {
            const colors = { add: '#10b981', remove: '#ef4444', adjust: '#f59e0b' };
            return {
                label: action.charAt(0).toUpperCase() + action.slice(1),
                data: months.map(month => {
                    const item = monthlyData.find(m => m.month === month && m.action === action);
                    return item ? parseInt(item.total_qty) : 0;
                }),
                backgroundColor: colors[action],
                borderRadius: 4
            };
        });

        const movementsCtx = document.getElementById('movementsChart').getContext('2d');
        new Chart(movementsCtx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    </script>
</body>
</html>
