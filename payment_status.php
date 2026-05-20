<?php
require_once __DIR__ . '/includes/session.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/includes/mpesa_db_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';
$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($orderId === false || !$pdo instanceof PDO) {
    header('Location: customer_dashboard.php');
    exit;
}

$paymentMethodSelect = mpesaColumnExists($pdo, 'orders', 'payment_method') ? 'o.payment_method,' : "'mpesa' AS payment_method,";
$receiptSelect = mpesaColumnExists($pdo, 'orders', 'mpesa_receipt_number') ? 'o.mpesa_receipt_number,' : "NULL::VARCHAR AS mpesa_receipt_number,";

$stmt = $pdo->prepare("
    SELECT
        o.id,
        o.total_amount,
        o.status,
        o.payment_status,
        {$paymentMethodSelect}
        {$receiptSelect}
        mt.phone_number,
        mt.result_desc,
        mt.status AS mpesa_status,
        mt.transaction_date
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
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: customer_dashboard.php');
    exit;
}

$paymentFeedback = $_SESSION['payment_feedback'] ?? '';
unset($_SESSION['payment_feedback']);

$isPaid = ($order['payment_status'] ?? '') === 'paid' || ($order['mpesa_status'] ?? '') === 'completed';
$isFailed = ($order['payment_status'] ?? '') === 'failed' || ($order['mpesa_status'] ?? '') === 'failed';
$statusLabel = $isPaid ? 'Payment confirmed' : ($isFailed ? 'Payment failed' : 'Awaiting payment');
$statusClass = $isPaid ? 'paid' : ($isFailed ? 'failed' : 'pending');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LuxeStore - Payment Status</title>
    <style>
        :root {
            --bg: #f7f1e7;
            --card: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --gold: #c78b2a;
            --gold-soft: #f7e8c6;
            --success: #0f9d58;
            --success-soft: #e8f7ef;
            --danger: #c0392b;
            --danger-soft: #fdecea;
            --pending: #9a6700;
            --pending-soft: #fff4d6;
            --border: #eadfcb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at top left, rgba(199,139,42,.18), transparent 28%),
                linear-gradient(180deg, #fbf7ef 0%, var(--bg) 100%);
            font-family: Arial, sans-serif;
            color: var(--ink);
        }
        .card {
            width: min(100%, 720px);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 24px 70px rgba(49, 34, 11, 0.08);
        }
        .eyebrow {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .eyebrow.pending { background: var(--pending-soft); color: var(--pending); }
        .eyebrow.paid { background: var(--success-soft); color: var(--success); }
        .eyebrow.failed { background: var(--danger-soft); color: var(--danger); }
        h1 {
            margin: 0 0 10px;
            font-size: clamp(1.8rem, 4vw, 2.6rem);
        }
        p {
            margin: 0 0 14px;
            color: var(--muted);
            line-height: 1.6;
        }
        .summary {
            margin: 24px 0;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: #fffdfa;
            display: grid;
            gap: 12px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .label {
            color: var(--muted);
            font-size: 0.92rem;
        }
        .value {
            font-weight: 700;
            color: var(--ink);
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 18px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .btn-primary {
            background: var(--gold);
            color: #fff;
        }
        .btn-secondary {
            background: transparent;
            color: var(--ink);
            border-color: var(--border);
        }
        .hint {
            font-size: 0.92rem;
            color: var(--muted);
        }
        @media (max-width: 600px) {
            .card { padding: 24px; border-radius: 18px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="eyebrow <?= htmlspecialchars($statusClass) ?>" id="statusBadge"><?= htmlspecialchars($statusLabel) ?></div>
        <h1 id="statusTitle"><?= $isPaid ? 'Your order has been paid.' : ($isFailed ? 'We could not complete the payment.' : 'Check your phone for the M-Pesa prompt.') ?></h1>
        <p><?= $paymentFeedback !== '' ? htmlspecialchars($paymentFeedback) : 'Hi ' . htmlspecialchars($userName) . ', we are tracking your M-Pesa payment in real time.' ?></p>
        <p class="hint" id="statusHint">
            <?= $isPaid
                ? 'Payment received and your order is now being prepared.'
                : ($isFailed ? htmlspecialchars((string) ($order['result_desc'] ?? 'The transaction was cancelled or declined.')) : 'Enter your PIN on your phone to complete the transaction. This page refreshes automatically.') ?>
        </p>

        <div class="summary">
            <div class="row">
                <span class="label">Order ID</span>
                <span class="value">#<?= (int) $order['id'] ?></span>
            </div>
            <div class="row">
                <span class="label">Amount</span>
                <span class="value">KES <?= number_format((float) ($order['total_amount'] ?? 0), 2) ?></span>
            </div>
            <div class="row">
                <span class="label">Phone</span>
                <span class="value" id="phoneValue"><?= htmlspecialchars((string) ($order['phone_number'] ?? 'Pending')) ?></span>
            </div>
            <div class="row">
                <span class="label">Receipt</span>
                <span class="value" id="receiptValue"><?= htmlspecialchars((string) ($order['mpesa_receipt_number'] ?? 'Waiting for payment')) ?></span>
            </div>
            <div class="row">
                <span class="label">Order Status</span>
                <span class="value" id="orderStatusValue"><?= htmlspecialchars((string) ($order['status'] ?? 'pending')) ?></span>
            </div>
        </div>

        <div class="actions">
            <a class="btn btn-primary" href="customer_dashboard.php">Back to Dashboard</a>
            <?php if ($isFailed): ?>
            <a class="btn btn-secondary" href="customer_dashboard.php">Try Again</a>
            <?php else: ?>
            <a class="btn btn-secondary" href="customer_dashboard.php#orders">View Orders</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        (function () {
            const orderId = <?php echo json_encode((int) $order['id']); ?>;
            let pollTimer = null;

            function setState(data) {
                const paid = data.payment_status === 'paid';
                const failed = data.payment_status === 'failed';
                const badge = document.getElementById('statusBadge');
                const title = document.getElementById('statusTitle');
                const hint = document.getElementById('statusHint');

                badge.className = 'eyebrow ' + (paid ? 'paid' : (failed ? 'failed' : 'pending'));
                badge.textContent = paid ? 'Payment confirmed' : (failed ? 'Payment failed' : 'Awaiting payment');
                title.textContent = paid
                    ? 'Your order has been paid.'
                    : (failed ? 'We could not complete the payment.' : 'Check your phone for the M-Pesa prompt.');
                hint.textContent = paid
                    ? 'Payment received and your order is now being prepared.'
                    : (failed ? (data.mpesa.result_desc || 'The transaction was cancelled or declined.') : 'Enter your PIN on your phone to complete the transaction. This page refreshes automatically.');

                document.getElementById('receiptValue').textContent = data.mpesa.receipt_number || 'Waiting for payment';
                document.getElementById('phoneValue').textContent = data.mpesa.phone_number || 'Pending';
                document.getElementById('orderStatusValue').textContent = data.order_status || 'pending';

                if (paid || failed) {
                    window.clearInterval(pollTimer);
                }
            }

            <?php if (!$isPaid && !$isFailed): ?>
            pollTimer = window.setInterval(function () {
                fetch('api/mpesa/status.php?order_id=' + encodeURIComponent(orderId), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        setState(data);
                    }
                })
                .catch(() => {});
            }, 5000);
            <?php endif; ?>
        })();
    </script>
</body>
</html>
