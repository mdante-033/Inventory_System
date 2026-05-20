<?php
require_once 'auth.php';
$adminAuth->requireLogin();
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'includes/action_helpers.php';
require_once '../modules/payment_module.php';

$payments = adminFetchPayments($pdo);
$integrityReport = (new PaymentModule($pdo))->getIntegrityReport(10);
$csrfToken = $adminAuth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .sidebar { min-height: 100vh; background: #343a40; }
        .sidebar .nav-link { color: #fff; padding: 1rem; transition: all 0.3s; }
        .sidebar .nav-link:hover { background: #495057; }
        .sidebar .nav-link.active { background: #007bff; }
        .sidebar .nav-link i { margin-right: 10px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 d-md-block sidebar">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <h5 class="text-white">Admin Panel</h5>
                        <small class="text-white-50">Welcome, <?= htmlspecialchars($_SESSION['admin_username']) ?></small>
                    </div>
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="products.php"><i class="bi bi-box"></i> Products</a></li>
                        <li class="nav-item"><a class="nav-link" href="orders.php"><i class="bi bi-cart"></i> Orders</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Customers</a></li>
                        <li class="nav-item"><a class="nav-link active" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a></li>
                        <li class="nav-item"><a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
                        <li class="nav-item mt-4"><a class="nav-link text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2 mb-1">Payments</h1>
                        <p class="text-muted mb-0">Inspect transactions, capture pending payments, and process refunds.</p>
                    </div>
                </div>

                <div id="ajaxAlertContainer" class="mb-3"></div>

                <?php if (($integrityReport['summary']['orphan_orders'] ?? 0) > 0 || ($integrityReport['summary']['mismatches'] ?? 0) > 0): ?>
                <div class="alert alert-warning">
                    Integrity warnings:
                    <?= (int)($integrityReport['summary']['orphan_orders'] ?? 0) ?> orphaned orders,
                    <?= (int)($integrityReport['summary']['mismatches'] ?? 0) ?> mismatches,
                    <?= (int)($integrityReport['summary']['orphan_transactions'] ?? 0) ?> orphaned transactions.
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <table id="paymentsTable" class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Transaction</th>
                                    <th>Order</th>
                                    <th>Gateway</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                <?php $displayStatus = strtolower((string)$payment['status']) === 'completed' ? 'paid' : strtolower((string)$payment['status']); ?>
                                <tr>
                                    <td><?= (int)$payment['id'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($payment['transaction_id'] ?: 'Pending Reference')) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars((string)($payment['reference_number'] ?? '')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)($payment['order_number'] ?? 'N/A')) ?></td>
                                    <td><?= htmlspecialchars((string)($payment['payment_gateway'] ?? 'N/A')) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($displayStatus)) ?></td>
                                    <td>$<?= number_format((float)($payment['amount'] ?? 0), 2) ?></td>
                                    <td><?= !empty($payment['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($payment['created_at']))) : 'N/A' ?></td>
                                    <td class="d-flex gap-2">
                                        <button class="btn btn-sm btn-info view-payment" data-id="<?= (int)$payment['id'] ?>"><i class="bi bi-eye"></i></button>
                                        <button class="btn btn-sm btn-warning refund-payment" data-id="<?= (int)$payment['id'] ?>" <?= $displayStatus !== 'paid' ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise"></i></button>
                                        <button class="btn btn-sm btn-success capture-payment" data-id="<?= (int)$payment['id'] ?>" <?= $displayStatus !== 'pending' ? 'disabled' : '' ?>><i class="bi bi-cash-stack"></i></button>
                                        <button class="btn btn-sm btn-secondary receipt-payment" data-id="<?= (int)$payment['id'] ?>"><i class="bi bi-receipt"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="paymentDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="paymentDetailsBody" class="bg-light p-3 rounded small mb-0"></pre>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="refundModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="refundForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Refund Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="payment_id" id="refund_payment_id">
                        <div class="mb-0">
                            <label class="form-label">Reason</label>
                            <textarea name="reason" id="refund_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-warning" id="refundButton">Process Refund</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>window.ADMIN_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="js/admin-actions.js"></script>
    <script>
        const paymentDetailsModal = new bootstrap.Modal(document.getElementById('paymentDetailsModal'));
        const refundModal = new bootstrap.Modal(document.getElementById('refundModal'));

        $(function() {
            $('#paymentsTable').DataTable({ order: [[6, 'desc']] });

            $(document).on('click', '.view-payment', function() {
                AdminActions.request('ajax/payment_action.php', { action_type: 'details', payment_id: $(this).data('id') }).done(function(response) {
                    $('#paymentDetailsBody').text(JSON.stringify(response.data.payment, null, 2));
                    paymentDetailsModal.show();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to load payment.');
                });
            });

            $(document).on('click', '.refund-payment', function() {
                $('#refund_payment_id').val($(this).data('id'));
                $('#refund_reason').val('');
                refundModal.show();
            });

            $(document).on('click', '.capture-payment', function() {
                AdminActions.request('ajax/payment_action.php', { action_type: 'capture', payment_id: $(this).data('id') }).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Payment captured.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to capture payment.');
                });
            });

            $(document).on('click', '.receipt-payment', function() {
                AdminActions.request('ajax/payment_action.php', { action_type: 'details', payment_id: $(this).data('id') }).done(function(response) {
                    const payment = response.data.payment;
                    const receiptWindow = window.open('', '_blank');
                    receiptWindow.document.write('<html><head><title>Receipt</title></head><body>');
                    receiptWindow.document.write('<h1>Payment Receipt</h1>');
                    receiptWindow.document.write('<p>Order: ' + (payment.order_number || 'N/A') + '</p>');
                    receiptWindow.document.write('<p>Transaction: ' + (payment.transaction_id || 'Pending Reference') + '</p>');
                    receiptWindow.document.write('<p>Gateway: ' + (payment.payment_gateway || 'N/A') + '</p>');
                    receiptWindow.document.write('<p>Status: ' + (payment.status || 'pending') + '</p>');
                    receiptWindow.document.write('<p>Amount: $' + Number(payment.amount || 0).toFixed(2) + '</p>');
                    receiptWindow.document.write('<p>Date: ' + (payment.created_at || '') + '</p>');
                    receiptWindow.document.write('</body></html>');
                    receiptWindow.document.close();
                    receiptWindow.print();
                });
            });

            $('#refundForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('refundButton'), 'Refunding...');

                AdminActions.request('ajax/payment_action.php', {
                    action_type: 'refund',
                    payment_id: $('#refund_payment_id').val(),
                    reason: $('#refund_reason').val()
                }).done(function(response) {
                    refundModal.hide();
                    AdminActions.showAlert('success', response.message || 'Refund processed.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to refund payment.');
                }).always(function() {
                    restore();
                });
            });
        });
    </script>
</body>
</html>
