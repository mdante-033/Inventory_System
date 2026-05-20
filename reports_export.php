<?php
require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/admin/includes/auth-check.php';
require_once __DIR__ . '/db_connect.php';

if (!checkAdminPermission('reports.export')) {
    http_response_code(403);
    echo 'You do not have permission to export reports.';
    exit;
}

if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo $db_connection_error ?: 'Database connection is unavailable right now.';
    exit;
}

function buildReportDateFilter(string $range, ?string $customStart, ?string $customEnd): array
{
    $dateExpr = "COALESCE(o.order_date, o.created_at)";
    $dateFilterSql = '';
    $dateParams = [];

    switch ($range) {
        case 'today':
            $dateFilterSql = "$dateExpr BETWEEN ? AND ?";
            $dateParams = [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
            break;
        case 'month':
            $dateFilterSql = "$dateExpr BETWEEN ? AND ?";
            $dateParams = [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
            break;
        case 'year':
            $dateFilterSql = "$dateExpr BETWEEN ? AND ?";
            $dateParams = [date('Y-01-01 00:00:00'), date('Y-12-31 23:59:59')];
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                $dateFilterSql = "$dateExpr BETWEEN ? AND ?";
                $dateParams = [$customStart . ' 00:00:00', $customEnd . ' 23:59:59'];
            }
            break;
        case 'week':
        default:
            $dateFilterSql = "$dateExpr BETWEEN ? AND ?";
            $dateParams = [
                date('Y-m-d 00:00:00', strtotime('-6 days')),
                date('Y-m-d 23:59:59'),
            ];
            break;
    }

    return [$dateExpr, $dateFilterSql, $dateParams];
}

function fetchReportData(PDO $pdo, string $type, string $dateExpr, string $dateFilterSql, array $dateParams): array
{
    $headers = [];
    $rows = [];
    static $productColumns = null;

    if ($productColumns === null) {
        $productColumns = [];
        try {
            $colStmt = $pdo->query("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = 'products'
            ");
            foreach ($colStmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
                $productColumns[$col] = true;
            }
        } catch (PDOException $e) {
            $productColumns = [];
        }
    }

    if ($type === 'sales') {
        $headers = ['Date', 'Orders', 'Total Sales'];
        $sql = "
            SELECT DATE($dateExpr) AS day, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS total
            FROM orders o
            WHERE o.payment_status IN ('paid', 'completed')
        ";
        if ($dateFilterSql !== '') {
            $sql .= " AND $dateFilterSql";
        }
        $sql .= " GROUP BY DATE($dateExpr) ORDER BY day";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll();
    } elseif ($type === 'inventory') {
        $hasName = isset($productColumns['name']);
        $hasCategory = isset($productColumns['category']);
        $hasCategoryId = isset($productColumns['category_id']);
        $hasType = isset($productColumns['type']);
        $hasQuantity = isset($productColumns['quantity']);
        $hasStockQuantity = isset($productColumns['stock_quantity']);
        $hasReorderLevel = isset($productColumns['reorder_level']);
        $hasSafetyStock = isset($productColumns['safety_stock']);
        $hasIsActive = isset($productColumns['is_active']);

        $nameSelect = $hasName ? 'p.name' : 'p.id::text';
        if ($hasCategory) {
            $categorySelect = 'p.category';
            $categoryHeader = 'Category';
        } elseif ($hasCategoryId) {
            $categorySelect = 'p.category_id::text';
            $categoryHeader = 'Category ID';
        } elseif ($hasType) {
            $categorySelect = 'p.type';
            $categoryHeader = 'Type';
        } else {
            $categorySelect = "''";
            $categoryHeader = 'Category';
        }

        if ($hasQuantity) {
            $quantitySelect = 'p.quantity';
        } elseif ($hasStockQuantity) {
            $quantitySelect = 'p.stock_quantity';
        } elseif ($hasSafetyStock) {
            $quantitySelect = 'p.safety_stock';
        } else {
            $quantitySelect = '0';
        }

        if ($hasReorderLevel) {
            $reorderSelect = 'p.reorder_level';
        } elseif ($hasSafetyStock) {
            $reorderSelect = 'p.safety_stock';
        } else {
            $reorderSelect = '0';
        }

        $headers = ['Product', $categoryHeader, 'Quantity', 'Reorder Level'];
        $sql = "
            SELECT
                {$nameSelect} AS name,
                {$categorySelect} AS category,
                {$quantitySelect} AS quantity,
                {$reorderSelect} AS reorder_level
            FROM products p
        ";
        if ($hasIsActive) {
            $sql .= " WHERE p.is_active = true";
        }
        $sql .= " ORDER BY quantity ASC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
    } elseif ($type === 'products') {
        $headers = ['Product', 'Units Sold', 'Revenue'];
        $sql = "
            SELECT p.name,
                   COALESCE(SUM(oi.quantity), 0) AS units_sold,
                   COALESCE(SUM(oi.subtotal), 0) AS revenue
            FROM products p
            LEFT JOIN order_items oi ON oi.product_id = p.id
            LEFT JOIN orders o ON o.id = oi.order_id
            WHERE 1=1
        ";
        if ($dateFilterSql !== '') {
            $sql .= " AND ($dateFilterSql)";
        }
        $sql .= " GROUP BY p.id ORDER BY units_sold DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll();
    } elseif ($type === 'customers') {
        $headers = ['Customer', 'Email', 'Orders', 'Total Spent'];
        $sql = "
            SELECT u.full_name, u.email,
                   COUNT(o.id) AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS spent
            FROM users u
            LEFT JOIN orders o ON o.user_id = u.id AND o.payment_status IN ('paid', 'completed')
        ";
        if ($dateFilterSql !== '') {
            $sql .= " AND ($dateFilterSql)";
        }
        $sql .= " GROUP BY u.id ORDER BY spent DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dateParams);
        $rows = $stmt->fetchAll();
    }

    return ['headers' => $headers, 'rows' => $rows];
}

$reportType = $_GET['report'] ?? 'sales';
$range = $_GET['range'] ?? 'week';
$export = $_GET['export'] ?? 'csv';
$customStart = $_GET['start'] ?? null;
$customEnd = $_GET['end'] ?? null;
$allowedReports = ['sales', 'inventory', 'products', 'customers'];
$allowedExports = ['csv', 'excel', 'pdf'];

if (!in_array($reportType, $allowedReports, true)) {
    http_response_code(400);
    echo 'Invalid report type.';
    exit;
}

if (!in_array($export, $allowedExports, true)) {
    http_response_code(400);
    echo 'Invalid export format.';
    exit;
}

[$dateExpr, $dateFilterSql, $dateParams] = buildReportDateFilter($range, $customStart, $customEnd);
$report = fetchReportData($pdo, $reportType, $dateExpr, $dateFilterSql, $dateParams);
$filename = $reportType . '_report_' . date('Ymd');

if ($export === 'csv' || $export === 'excel') {
    $contentType = $export === 'excel' ? 'application/vnd.ms-excel' : 'text/csv; charset=utf-8';
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $report['headers']);
    foreach ($report['rows'] as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(ucfirst($reportType)); ?> Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
        h1 { margin-bottom: 8px; }
        p { color: #4b5563; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 10px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1><?php echo htmlspecialchars(ucfirst($reportType)); ?> Report</h1>
    <p>Range: <?php echo htmlspecialchars(ucfirst($range)); ?></p>
    <table>
        <thead>
            <tr>
                <?php foreach ($report['headers'] as $header): ?>
                    <th><?php echo htmlspecialchars($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($report['rows'] === []): ?>
                <tr>
                    <td colspan="<?php echo count($report['headers']) ?: 1; ?>">No data available for this report.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?php echo htmlspecialchars((string) $cell); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
