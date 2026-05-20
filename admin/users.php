<?php
require_once 'auth.php';
$adminAuth->requireLogin();
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'includes/action_helpers.php';

$customers = adminFetchCustomers($pdo);
$csrfToken = $adminAuth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
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
                        <li class="nav-item"><a class="nav-link active" href="users.php"><i class="bi bi-people"></i> Customers</a></li>
                        <li class="nav-item"><a class="nav-link" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a></li>
                        <li class="nav-item"><a class="nav-link" href="reports.php"><i class="bi bi-graph-up"></i> Reports</a></li>
                        <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
                        <li class="nav-item mt-4"><a class="nav-link text-danger" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-10 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2 mb-1">Customers</h1>
                        <p class="text-muted mb-0">View, add, edit, and archive customer accounts.</p>
                    </div>
                    <button class="btn btn-primary" type="button" id="addCustomerButton">
                        <i class="bi bi-plus"></i> Add Customer
                    </button>
                </div>

                <div id="ajaxAlertContainer" class="mb-3"></div>

                <div class="card">
                    <div class="card-body">
                        <table id="customersTable" class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Group</th>
                                    <th>Orders</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td><?= (int)$customer['id'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($customer['full_name'] ?: $customer['username'])) ?></div>
                                        <div class="text-muted small">@<?= htmlspecialchars((string)$customer['username']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)$customer['email']) ?></td>
                                    <td><?= htmlspecialchars((string)($customer['phone'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($customer['customer_group'] ?? 'regular')) ?></td>
                                    <td><?= (int)($customer['order_count'] ?? 0) ?></td>
                                    <td>
                                        <span class="badge <?= !empty($customer['is_active']) ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= !empty($customer['is_active']) ? 'Active' : 'Archived' ?>
                                        </span>
                                    </td>
                                    <td class="d-flex gap-2">
                                        <button class="btn btn-sm btn-info view-customer" data-id="<?= (int)$customer['id'] ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-warning edit-customer" data-id="<?= (int)$customer['id'] ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-customer" data-id="<?= (int)$customer['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

    <div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="customerForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Customer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" id="customer_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" id="customer_full_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" id="customer_username" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="customer_email" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" id="customer_phone" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Group</label>
                                <select name="customer_group" id="customer_group" class="form-select">
                                    <option value="regular">Regular</option>
                                    <option value="vip">VIP</option>
                                    <option value="wholesale">Wholesale</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" id="customer_password" class="form-control">
                                <div class="form-text">Required for new customers. Leave blank to keep existing password.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="customerSaveButton">Save Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="customerViewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Customer Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="customerViewBody" class="text-muted">Loading...</div>
                </div>
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
        const customerModal = new bootstrap.Modal(document.getElementById('customerModal'));
        const customerViewModal = new bootstrap.Modal(document.getElementById('customerViewModal'));

        $(function() {
            $('#customersTable').DataTable({ order: [[0, 'desc']] });

            $('#addCustomerButton').on('click', function() {
                $('#customerForm')[0].reset();
                $('#customer_id').val('');
                customerModal.show();
            });

            $(document).on('click', '.edit-customer', function() {
                const id = $(this).data('id');
                AdminActions.request('process_customer.php', { action: 'view', id: id }).done(function(response) {
                    const customer = response.data.customer;
                    $('#customer_id').val(customer.id);
                    $('#customer_full_name').val(customer.full_name || '');
                    $('#customer_username').val(customer.username || '');
                    $('#customer_email').val(customer.email || '');
                    $('#customer_phone').val(customer.phone || '');
                    $('#customer_group').val(customer.customer_group || 'regular');
                    $('#customer_password').val('');
                    customerModal.show();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to load customer.');
                });
            });

            $(document).on('click', '.view-customer', function() {
                const id = $(this).data('id');
                $('#customerViewBody').html('Loading...');
                AdminActions.request('process_customer.php', { action: 'view', id: id }).done(function(response) {
                    const customer = response.data.customer;
                    let ordersHtml = '<p class="text-muted">No recent orders.</p>';
                    if (customer.orders && customer.orders.length) {
                        ordersHtml = '<ul class="list-group">';
                        customer.orders.forEach(function(order) {
                            ordersHtml += '<li class="list-group-item d-flex justify-content-between align-items-center">'
                                + '<span>' + (order.order_number || ('Order #' + order.id)) + '</span>'
                                + '<span>' + order.status + ' / ' + order.payment_status + ' / $' + Number(order.total_amount || 0).toFixed(2) + '</span>'
                                + '</li>';
                        });
                        ordersHtml += '</ul>';
                    }

                    $('#customerViewBody').html(
                        '<div class="mb-3"><strong>Name:</strong> ' + (customer.full_name || customer.username) + '</div>'
                        + '<div class="mb-3"><strong>Email:</strong> ' + (customer.email || '') + '</div>'
                        + '<div class="mb-3"><strong>Phone:</strong> ' + (customer.phone || 'N/A') + '</div>'
                        + '<div class="mb-3"><strong>Group:</strong> ' + (customer.customer_group || 'regular') + '</div>'
                        + '<h6>Recent Orders</h6>' + ordersHtml
                    );
                    customerViewModal.show();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to load customer details.');
                });
            });

            $(document).on('click', '.delete-customer', function() {
                const id = $(this).data('id');
                if (!AdminActions.confirmAction('Archive this customer account? You can undo this action.')) {
                    return;
                }

                AdminActions.request('ajax/delete_customer.php', { id: id }).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Customer archived.', response.undo || null);
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to archive customer.');
                });
            });

            $('#customerForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('customerSaveButton'), 'Saving...');

                AdminActions.request('process_customer.php', $(this).serializeArray()).done(function(response) {
                    customerModal.hide();
                    AdminActions.showAlert('success', response.message || 'Customer saved.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to save customer.');
                }).always(function() {
                    restore();
                });
            });
        });
    </script>
</body>
</html>
