<?php
// admin/orders.php
require_once 'auth.php';
$adminAuth->requireLogin();
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../modules/order_module.php';
require_once '../modules/payment_module.php';

$orderModule = new OrderModule($pdo);
$paymentModule = new PaymentModule($pdo);
$csrfToken = $adminAuth->getCsrfToken();
$message = '';
$error = '';

$validOrderStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
$validPaymentStatuses = ['pending', 'paid', 'failed', 'refunded'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['update_status', 'update'], true)) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? 'pending';
    $paymentStatus = $_POST['payment_status'] ?? 'pending';

    if (!$adminAuth->validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session token is invalid. Refresh the page and try again.';
    } elseif ($orderId <= 0) {
        $error = 'Invalid order selected.';
    } elseif (!in_array($status, $validOrderStatuses, true) || !in_array($paymentStatus, $validPaymentStatuses, true)) {
        $error = 'Invalid order status provided.';
    } else {
        $result = $paymentModule->updatePaymentStatusFromAdmin($orderId, $paymentStatus, [
            'order_status' => $status,
            'transaction_id' => trim($_POST['transaction_id'] ?? '') ?: null,
            'payment_gateway' => trim($_POST['payment_gateway'] ?? '') ?: 'admin',
            'notes' => trim($_POST['notes'] ?? ''),
            'source' => 'admin/orders.php',
            'generate_transaction_id' => $paymentStatus === 'paid',
        ]);

        if ($result['success']) {
            $message = 'Order updated successfully.';
        } else {
            $error = 'Failed to update order.';
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$paymentFilter = $_GET['payment'] ?? '';
$selectedOrderId = (int)($_GET['id'] ?? 0);

if ($statusFilter !== '' && !in_array($statusFilter, $validOrderStatuses, true)) {
    $statusFilter = '';
}

if ($paymentFilter !== '' && !in_array($paymentFilter, $validPaymentStatuses, true)) {
    $paymentFilter = '';
}

$orders = $orderModule->getOrders(null, $statusFilter ?: null, $paymentFilter ?: null);
$selectedOrder = null;

if ($selectedOrderId > 0) {
    $selectedOrder = $orderModule->getOrderDetails($selectedOrderId);
    if (!$selectedOrder) {
        $error = 'Order not found.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: #343a40;
        }
        .sidebar .nav-link {
            color: #fff;
            padding: 1rem;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover {
            background: #495057;
        }
        .sidebar .nav-link.active {
            background: #007bff;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .detail-label {
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .detail-value {
            font-size: 1rem;
            font-weight: 600;
        }
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
                        <li class="nav-item"><a class="nav-link active" href="orders.php"><i class="bi bi-cart"></i> Orders</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Users</a></li>
                        <li class="nav-item"><a class="nav-link" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a></li>
                        <li class="nav-item"><a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
                        <li class="nav-item mt-4"><a class="nav-link text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2 mb-1">Orders</h1>
                        <p class="text-muted mb-0">Customer orders and payment activity in one place.</p>
                    </div>
                    <?php if ($selectedOrder): ?>
                    <a href="orders.php" class="btn btn-outline-secondary btn-sm">Clear Selection</a>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div id="ajaxAlertContainer" class="mb-3"></div>

                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Order Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All statuses</option>
                                    <?php foreach ($validOrderStatuses as $statusOption): ?>
                                    <option value="<?= $statusOption ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                                        <?= ucfirst($statusOption) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Payment Status</label>
                                <select name="payment" class="form-select">
                                    <option value="">All payments</option>
                                    <?php foreach ($validPaymentStatuses as $paymentOption): ?>
                                    <option value="<?= $paymentOption ?>" <?= $paymentFilter === $paymentOption ? 'selected' : '' ?>>
                                        <?= ucfirst($paymentOption) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Apply Filters</button>
                                <a href="orders.php" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <table id="ordersTable" class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <?php
                                    $orderNumber = $order['order_number'] ?: ('ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT));
                                    $customerName = $order['customer_name'] ?? ($order['username'] ?? 'Guest');
                                    $displayDate = $order['display_date'] ?? $order['created_at'] ?? null;
                                    $paymentStatus = strtolower((string)($order['payment_status'] ?? 'pending'));
                                    $paymentLabel = ucfirst($paymentStatus);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($orderNumber) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($customerName) ?></div>
                                        <?php if (!empty($order['email'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($order['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)($order['item_count'] ?? 0) ?></td>
                                    <td>$<?= number_format((float)($order['total_amount'] ?? 0), 2) ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($paymentLabel) ?></div>
                                        <?php if (!empty($order['latest_transaction_id'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($order['latest_transaction_id']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['payment_gateway'])): ?>
                                        <div class="text-muted small"><?= htmlspecialchars($order['payment_gateway']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['payment_status_mismatch'])): ?>
                                        <span class="badge bg-danger mt-1">Mismatch</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(ucfirst((string)($order['status'] ?? 'pending'))) ?></td>
                                    <td><?= $displayDate ? htmlspecialchars(date('Y-m-d H:i', strtotime($displayDate))) : 'N/A' ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-info" href="orders.php?id=<?= (int)$order['id'] ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button
                                            class="btn btn-sm btn-success"
                                            onclick="openStatusModal(<?= (int)$order['id'] ?>, <?= json_encode((string)($order['status'] ?? 'pending')) ?>, <?= json_encode($paymentStatus) ?>)"
                                            type="button"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning" type="button" onclick="openEditOrderModal(<?= (int)$order['id'] ?>)">
                                            <i class="bi bi-card-checklist"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" type="button" onclick="openCancelModal(<?= (int)$order['id'] ?>)">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                        <button class="btn btn-sm btn-secondary" type="button" onclick="printInvoice(<?= (int)$order['id'] ?>)">
                                            <i class="bi bi-printer"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($selectedOrder): ?>
                <?php
                    $selectedOrderNumber = $selectedOrder['order_number'] ?: ('ORD-' . str_pad((string)$selectedOrder['id'], 6, '0', STR_PAD_LEFT));
                    $selectedCustomerName = $selectedOrder['customer_name'] ?? ($selectedOrder['username'] ?? 'Guest');
                    $selectedDate = $selectedOrder['display_date'] ?? $selectedOrder['created_at'] ?? null;
                ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Order Details</h5>
                        <span class="badge bg-primary"><?= htmlspecialchars($selectedOrderNumber) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="detail-label">Customer</div>
                                <div class="detail-value"><?= htmlspecialchars($selectedCustomerName) ?></div>
                                <?php if (!empty($selectedOrder['email'])): ?>
                                <div class="text-muted"><?= htmlspecialchars($selectedOrder['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($selectedOrder['phone'])): ?>
                                <div class="text-muted"><?= htmlspecialchars($selectedOrder['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2">
                                <div class="detail-label">Order Status</div>
                                <div class="detail-value"><?= htmlspecialchars(ucfirst((string)($selectedOrder['status'] ?? 'pending'))) ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="detail-label">Payment</div>
                                <div class="detail-value"><?= htmlspecialchars(ucfirst((string)($selectedOrder['payment_status'] ?? 'pending'))) ?></div>
                                <?php if (!empty($selectedOrder['payment_status_mismatch'])): ?>
                                <div class="text-danger small">Latest transaction disagrees with stored order payment status.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2">
                                <div class="detail-label">Total</div>
                                <div class="detail-value">$<?= number_format((float)($selectedOrder['total_amount'] ?? 0), 2) ?></div>
                            </div>
                            <div class="col-md-2">
                                <div class="detail-label">Date</div>
                                <div class="detail-value"><?= $selectedDate ? htmlspecialchars(date('Y-m-d H:i', strtotime($selectedDate))) : 'N/A' ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Order Items</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($selectedOrder['items'] ?? []) as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($item['product_name'] ?? 'Unnamed Product')) ?></td>
                                        <td><?= (int)($item['quantity'] ?? 0) ?></td>
                                        <td>$<?= number_format((float)($item['price'] ?? 0), 2) ?></td>
                                        <td>$<?= number_format((float)($item['subtotal'] ?? 0), 2) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($selectedOrder['items'])): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No order items found.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Payment Transactions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($selectedOrder['transactions'])): ?>
                            <p class="mb-0 text-muted">No transactions found.</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Transaction ID</th>
                                        <th>Gateway</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedOrder['transactions'] as $transaction): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($transaction['transaction_id'] ?? 'N/A')) ?></td>
                                        <td><?= htmlspecialchars((string)($transaction['payment_gateway'] ?? 'N/A')) ?></td>
                                        <td><?= htmlspecialchars((string)($transaction['status'] ?? 'pending')) ?></td>
                                        <td>$<?= number_format((float)($transaction['amount'] ?? 0), 2) ?></td>
                                        <td><?= !empty($transaction['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($transaction['created_at']))) : 'N/A' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="statusUpdateForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="order_id" id="modal_order_id">
                        <div class="mb-3">
                            <label class="form-label">Order Status</label>
                            <select name="status" id="modal_status" class="form-select">
                                <?php foreach ($validOrderStatuses as $statusOption): ?>
                                <option value="<?= $statusOption ?>"><?= ucfirst($statusOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" id="modal_payment_status" class="form-select">
                                <?php foreach ($validPaymentStatuses as $paymentOption): ?>
                                <option value="<?= $paymentOption ?>"><?= ucfirst($paymentOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Transaction ID</label>
                            <input type="text" name="transaction_id" id="modal_transaction_id" class="form-control" placeholder="Optional transaction reference">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gateway</label>
                            <input type="text" name="payment_gateway" id="modal_payment_gateway" class="form-control" value="admin">
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="modal_notes" class="form-control" rows="3" placeholder="Reason for manual payment update"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="editOrderForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="order_id" id="edit_order_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" id="edit_customer_name" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Email</label>
                                <input type="email" name="customer_email" id="edit_customer_email" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Order Status</label>
                                <select name="status" id="edit_order_status" class="form-select">
                                    <?php foreach ($validOrderStatuses as $statusOption): ?>
                                    <option value="<?= $statusOption ?>"><?= ucfirst($statusOption) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Status</label>
                                <select name="payment_status" id="edit_payment_status" class="form-select">
                                    <?php foreach ($validPaymentStatuses as $paymentOption): ?>
                                    <option value="<?= $paymentOption ?>"><?= ucfirst($paymentOption) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Shipping Address</label>
                            <textarea name="shipping_address" id="edit_shipping_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Billing Address</label>
                            <textarea name="billing_address" id="edit_billing_address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Admin Notes</label>
                            <textarea name="notes" id="edit_order_notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="editOrderSaveButton">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="cancelOrderForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Cancel Order</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="order_id" id="cancel_order_id">
                        <div class="mb-0">
                            <label class="form-label">Reason</label>
                            <textarea name="reason" id="cancel_reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger" id="cancelOrderButton">Cancel Order</button>
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
        $(document).ready(function() {
            $('#ordersTable').DataTable({
                order: [[6, 'desc']]
            });

            $('#statusUpdateForm').on('submit', function(event) {
                event.preventDefault();
                const submitButton = this.querySelector('button[type="submit"]');
                const restore = AdminActions.withLoading(submitButton, 'Updating...');

                AdminActions.request('process_order.php', $(this).serializeArray()).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Order updated.');
                    bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to update order.');
                }).always(function() {
                    restore();
                });
            });

            $('#editOrderForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('editOrderSaveButton'), 'Saving...');

                AdminActions.request('process_order.php', $(this).serializeArray()).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Order saved.');
                    bootstrap.Modal.getInstance(document.getElementById('editOrderModal')).hide();
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to save order.');
                }).always(function() {
                    restore();
                });
            });

            $('#cancelOrderForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('cancelOrderButton'), 'Cancelling...');

                AdminActions.request('ajax/cancel_order.php', $(this).serializeArray()).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Order cancelled.');
                    bootstrap.Modal.getInstance(document.getElementById('cancelOrderModal')).hide();
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to cancel order.');
                }).always(function() {
                    restore();
                });
            });
        });

        function openStatusModal(orderId, status, paymentStatus) {
            document.getElementById('modal_order_id').value = orderId;
            document.getElementById('modal_status').value = status || 'pending';
            document.getElementById('modal_payment_status').value = paymentStatus || 'pending';
            document.getElementById('modal_transaction_id').value = '';
            document.getElementById('modal_payment_gateway').value = 'admin';
            document.getElementById('modal_notes').value = '';
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        function openEditOrderModal(orderId) {
            AdminActions.request('ajax/order_details.php', { order_id: orderId }).done(function(response) {
                const order = response.data.order;
                document.getElementById('edit_order_id').value = order.id || orderId;
                document.getElementById('edit_customer_name').value = order.customer_name || '';
                document.getElementById('edit_customer_email').value = order.email || order.customer_email || '';
                document.getElementById('edit_order_status').value = order.status || 'pending';
                document.getElementById('edit_payment_status').value = order.payment_status || 'pending';
                document.getElementById('edit_shipping_address').value = order.shipping_address || '';
                document.getElementById('edit_billing_address').value = order.billing_address || '';
                document.getElementById('edit_order_notes').value = order.notes || '';
                new bootstrap.Modal(document.getElementById('editOrderModal')).show();
            }).fail(function(xhr) {
                AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to load order.');
            });
        }

        function openCancelModal(orderId) {
            document.getElementById('cancel_order_id').value = orderId;
            document.getElementById('cancel_reason').value = '';
            new bootstrap.Modal(document.getElementById('cancelOrderModal')).show();
        }

        function printInvoice(orderId) {
            window.open('orders.php?id=' + orderId + '&print=1', '_blank');
        }

        <?php if (!empty($_GET['print']) && $selectedOrder): ?>
        window.addEventListener('load', function() {
            window.print();
        });
        <?php endif; ?>
    </script>
</body>
</html>
