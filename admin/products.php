<?php
require_once 'auth.php';
$adminAuth->requireLogin();
require_once '../config/constants.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'includes/action_helpers.php';

$products = adminFetchProducts($pdo);
$categoryIds = $pdo->query("
    SELECT DISTINCT category_id
    FROM products
    WHERE category_id IS NOT NULL
    ORDER BY category_id ASC
")->fetchAll(PDO::FETCH_COLUMN);
$csrfToken = $adminAuth->getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products</title>
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
                        <li class="nav-item"><a class="nav-link active" href="products.php"><i class="bi bi-box"></i> Products</a></li>
                        <li class="nav-item"><a class="nav-link" href="orders.php"><i class="bi bi-cart"></i> Orders</a></li>
                        <li class="nav-item"><a class="nav-link" href="users.php"><i class="bi bi-people"></i> Customers</a></li>
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
                        <h1 class="h2 mb-1">Products</h1>
                        <p class="text-muted mb-0">Edit product data, adjust stock, archive items, and duplicate catalog entries.</p>
                    </div>
                    <button class="btn btn-primary" id="addProductButton" type="button">
                        <i class="bi bi-plus"></i> Add Product
                    </button>
                </div>

                <div id="ajaxAlertContainer" class="mb-3"></div>

                <div class="card">
                    <div class="card-body">
                        <table id="productsTable" class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>SKU</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?= (int)$product['id'] ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string)$product['name']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars(truncate((string)($product['description'] ?? ''), 60)) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string)($product['sku'] ?? '')) ?></td>
                                    <td>$<?= number_format((float)($product['unit_price'] ?? 0), 2) ?></td>
                                    <td>
                                        <span class="badge <?= (int)($product['quantity'] ?? 0) <= (int)($product['reorder_level'] ?? 10) ? 'bg-warning text-dark' : 'bg-success' ?>">
                                            <?= (int)($product['quantity'] ?? 0) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string)($product['category_id'] ?? 'N/A')) ?></td>
                                    <td>
                                        <span class="badge <?= !empty($product['is_active']) ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= !empty($product['is_active']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="d-flex gap-2">
                                        <button class="btn btn-sm btn-info edit-product" data-id="<?= (int)$product['id'] ?>"><i class="bi bi-pencil"></i></button>
                                        <button class="btn btn-sm btn-warning inventory-product" data-id="<?= (int)$product['id'] ?>"><i class="bi bi-box-seam"></i></button>
                                        <button class="btn btn-sm btn-secondary toggle-product" data-id="<?= (int)$product['id'] ?>"><i class="bi bi-toggle-on"></i></button>
                                        <button class="btn btn-sm btn-primary duplicate-product" data-id="<?= (int)$product['id'] ?>"><i class="bi bi-files"></i></button>
                                        <button class="btn btn-sm btn-danger delete-product" data-id="<?= (int)$product['id'] ?>"><i class="bi bi-trash"></i></button>
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

    <div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="productForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="product_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" id="product_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SKU</label>
                                <input type="text" name="sku" id="product_sku" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="product_description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Price</label>
                                <input type="number" name="unit_price" id="product_price" step="0.01" min="0" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cost Price</label>
                                <input type="number" name="cost_price" id="product_cost_price" step="0.01" min="0" class="form-control">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="quantity" id="product_quantity" min="0" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category ID</label>
                                <select name="category_id" id="product_category" class="form-select">
                                    <option value="">None</option>
                                    <?php foreach ($categoryIds as $categoryId): ?>
                                    <option value="<?= (int)$categoryId ?>"><?= (int)$categoryId ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" id="product_reorder_level" min="0" class="form-control" value="10">
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="product_is_active" checked>
                            <label class="form-check-label" for="product_is_active">Active product</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="productSaveButton">Save Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="inventoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="inventoryForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Manage Inventory</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="inventory_product_id">
                        <div class="mb-3">
                            <label class="form-label">Quantity Change</label>
                            <input type="number" name="quantity_delta" id="inventory_quantity_delta" class="form-control" required>
                            <div class="form-text">Use positive numbers to add stock and negative numbers to reduce stock.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Reason</label>
                            <textarea name="reason" id="inventory_reason" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" id="inventorySaveButton">Update Inventory</button>
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
        const productModal = new bootstrap.Modal(document.getElementById('productModal'));
        const inventoryModal = new bootstrap.Modal(document.getElementById('inventoryModal'));

        function populateProductForm(product) {
            $('#product_id').val(product.id || '');
            $('#product_name').val(product.name || '');
            $('#product_sku').val(product.sku || '');
            $('#product_description').val(product.description || '');
            $('#product_price').val(product.unit_price || 0);
            $('#product_cost_price').val(product.cost_price || 0);
            $('#product_quantity').val(product.quantity || 0);
            $('#product_category').val(product.category_id || '');
            $('#product_reorder_level').val(product.reorder_level || 10);
            $('#product_is_active').prop('checked', !!Number(product.is_active));
        }

        $(function() {
            $('#productsTable').DataTable({ order: [[0, 'desc']] });

            $('#addProductButton').on('click', function() {
                $('#productForm')[0].reset();
                $('#product_id').val('');
                $('#product_reorder_level').val(10);
                $('#product_is_active').prop('checked', true);
                productModal.show();
            });

            $(document).on('click', '.edit-product', function() {
                AdminActions.request('ajax/product_details.php', { id: $(this).data('id') }).done(function(response) {
                    populateProductForm(response.data.product);
                    productModal.show();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to load product.');
                });
            });

            $(document).on('click', '.inventory-product', function() {
                $('#inventoryForm')[0].reset();
                $('#inventory_product_id').val($(this).data('id'));
                inventoryModal.show();
            });

            $(document).on('click', '.toggle-product', function() {
                AdminActions.request('ajax/toggle_product_status.php', { id: $(this).data('id') }).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Product updated.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to update product status.');
                });
            });

            $(document).on('click', '.duplicate-product', function() {
                AdminActions.request('ajax/duplicate_product.php', { id: $(this).data('id') }).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Product duplicated.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to duplicate product.');
                });
            });

            $(document).on('click', '.delete-product', function() {
                const id = $(this).data('id');
                if (!AdminActions.confirmAction('Archive this product? You can undo this action.')) {
                    return;
                }

                AdminActions.request('ajax/delete_product.php', { id: id }).done(function(response) {
                    AdminActions.showAlert('success', response.message || 'Product archived.', response.undo || null);
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to archive product.');
                });
            });

            $('#productForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('productSaveButton'), 'Saving...');
                AdminActions.request('ajax/edit_product.php', $(this).serializeArray()).done(function(response) {
                    productModal.hide();
                    AdminActions.showAlert('success', response.message || 'Product saved.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to save product.');
                }).always(function() {
                    restore();
                });
            });

            $('#inventoryForm').on('submit', function(event) {
                event.preventDefault();
                const restore = AdminActions.withLoading(document.getElementById('inventorySaveButton'), 'Updating...');
                AdminActions.request('ajax/adjust_inventory.php', $(this).serializeArray()).done(function(response) {
                    inventoryModal.hide();
                    AdminActions.showAlert('success', response.message || 'Inventory updated.');
                    window.location.reload();
                }).fail(function(xhr) {
                    AdminActions.showAlert('danger', xhr.responseJSON?.message || 'Unable to update inventory.');
                }).always(function() {
                    restore();
                });
            });
        });
    </script>
</body>
</html>
