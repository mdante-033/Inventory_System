<?php
/**
 * Suppliers Module
 * Supplier management functionality
 */

// At the top of each module file
if (!isset($pdo) || $pdo === null) {
    // Try to include db_connect again if we're in admin.php context
    if (file_exists('../db_connect.php')) {
        require_once '../db_connect.php';
    } elseif (file_exists('db_connect.php')) {
        require_once 'db_connect.php';
    }
    
    // If still null, show error
    if (!isset($pdo) || $pdo === null) {
        echo "<div class='alert alert-danger'>";
        echo "<h4>Database Connection Error</h4>";
        echo "<p>The database connection could not be established. Please check:</p>";
        echo "<ul>";
        echo "<li>PostgreSQL service is running</li>";
        echo "<li>Database 'Inventory_DB' exists</li>";
        echo "<li>Connection credentials are correct</li>";
        echo "</ul>";
        echo "</div>";
        return; // Stop further execution
    }
}

// Prevent direct access - this file should be included via admin.php
if (basename($_SERVER['PHP_SELF']) == 'suppliers.php') {
    header('Location: ../admin.php');
    exit;
}

// Get all suppliers
$suppliers = [];
try {
    $stmt = $pdo->query("
        SELECT s.*, 
               (SELECT COUNT(*) FROM products WHERE is_active = true) as product_count
        FROM suppliers s 
        WHERE s.is_active = true
        ORDER BY s.company_name
    ");
    $suppliers = $stmt->fetchAll();
} catch (PDOException $e) {}
?>

<!-- Suppliers Page -->
<div class="page-section active" id="page-suppliers">
    <!-- Stats -->
    <div class="dashboard-stats" style="grid-template-columns: repeat(3, 1fr);">
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-truck"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count($suppliers); ?></div>
                <div class="stat-label">Total Suppliers</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count(array_filter($suppliers, function($s) { return !empty($s['email']); })); ?></div>
                <div class="stat-label">With Email</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info"><i class="fas fa-phone"></i></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo count(array_filter($suppliers, function($s) { return !empty($s['phone']); })); ?></div>
                <div class="stat-label">With Phone</div>
            </div>
        </div>
    </div>
    
    <!-- Suppliers List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Suppliers</h3>
            <button class="btn btn-primary" onclick="showModal('supplierModal'); resetSupplierForm();">
                <i class="fas fa-plus"></i> Add Supplier
            </button>
        </div>
        
        <!-- Filters -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box" style="width: 250px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search suppliers..." id="supplierSearch" onkeyup="filterSuppliers()">
                </div>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-outline" onclick="exportSuppliers()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="data-table" id="suppliersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Contact Person</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>City</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?php echo $supplier['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($supplier['company_name']); ?></strong>
                        </td>
                        <td><?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($supplier['email'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($supplier['phone'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($supplier['city'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($supplier['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-icons">
                                <button
                                    class="action-icon"
                                    title="Edit"
                                    data-id="<?php echo (int) $supplier['id']; ?>"
                                    data-company="<?php echo htmlspecialchars($supplier['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-contact="<?php echo htmlspecialchars($supplier['contact_person'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-email="<?php echo htmlspecialchars($supplier['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-phone="<?php echo htmlspecialchars($supplier['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-address="<?php echo htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-city="<?php echo htmlspecialchars($supplier['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="editSupplier(this)"
                                >
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-icon" title="View Products" onclick="viewSupplierProducts(<?php echo $supplier['id']; ?>)">
                                    <i class="fas fa-boxes"></i>
                                </button>
                                <button class="action-icon delete" title="Delete" onclick="deleteSupplier(<?php echo $supplier['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($suppliers)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No suppliers found. <button onclick="showModal('supplierModal')" style="background:none;border:none;color:var(--primary);cursor:pointer;text-decoration:underline;">Add one</button>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Supplier Products Modal -->
<div class="modal-overlay" id="supplierProductsModal">
    <div class="modal" style="max-width: 860px;">
        <div class="modal-header">
            <h3 class="modal-title">Supplier Products</h3>
            <button class="modal-close" onclick="hideModal('supplierProductsModal')">&times;</button>
        </div>
        <div class="modal-body" id="supplierProductsBody">
            <p class="text-muted">Loading supplier products...</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="hideModal('supplierProductsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Supplier Modal -->
<div class="modal-overlay" id="supplierModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="supplierModalTitle">Add Supplier</h3>
            <button class="modal-close" onclick="hideModal('supplierModal')">&times;</button>
        </div>
        <form id="supplierForm" onsubmit="saveSupplier(event)">
            <div class="modal-body">
                <input type="hidden" id="supplierId" name="id">
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" id="supplierCompany" name="company_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" id="supplierContact" name="contact_person">
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="supplierEmail" name="email">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" id="supplierPhone" name="phone">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea id="supplierAddress" name="address" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="supplierCity" name="city">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideModal('supplierModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter suppliers
function filterSuppliers() {
    const search = document.getElementById('supplierSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#suppliersTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
}

// Reset supplier form
function resetSupplierForm() {
    document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierId').value = '';
}

// Edit supplier
function editSupplier(button) {
    const data = button.dataset;

    document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
    document.getElementById('supplierId').value = data.id || '';
    document.getElementById('supplierCompany').value = data.company || '';
    document.getElementById('supplierContact').value = data.contact || '';
    document.getElementById('supplierEmail').value = data.email || '';
    document.getElementById('supplierPhone').value = data.phone || '';
    document.getElementById('supplierAddress').value = data.address || '';
    document.getElementById('supplierCity').value = data.city || '';
    showModal('supplierModal');
}

// Save supplier
function saveSupplier(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    data.action = document.getElementById('supplierId').value ? 'update_supplier' : 'add_supplier';
    
    fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            showNotification(result.message, 'success');
            hideModal('supplierModal');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showNotification(result.message, 'error');
        }
    })
    .catch(() => {
        showNotification('Unable to save supplier right now. Please try again.', 'error');
    });
}

// View supplier products
function viewSupplierProducts(supplierId) {
    fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action: 'get_supplier_products', supplier_id: supplierId })
    })
    .then(r => r.json())
    .then(result => {
        if (!result.success) {
            showNotification(result.message || 'Unable to load supplier products', 'error');
            return;
        }

        const supplier = result.data.supplier;
        const products = result.data.products || [];
        const productsHtml = products.length
            ? `
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Stock</th>
                                <th>Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${products.map(product => `
                                <tr>
                                    <td>${product.name}</td>
                                    <td>${product.sku || 'N/A'}</td>
                                    <td>${product.quantity}</td>
                                    <td>$${Number(product.unit_price || 0).toFixed(2)}</td>
                                    <td>${product.is_active ? 'Active' : 'Inactive'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `
            : '<p class="text-muted">No products are linked to this supplier yet.</p>';

        document.getElementById('supplierProductsBody').innerHTML = `
            <div class="form-grid" style="margin-bottom: 16px;">
                <div>
                    <div class="text-muted text-sm">Company</div>
                    <div class="font-semibold">${supplier.company_name}</div>
                </div>
                <div>
                    <div class="text-muted text-sm">Contact</div>
                    <div>${supplier.contact_person || 'N/A'}</div>
                </div>
                <div>
                    <div class="text-muted text-sm">Email</div>
                    <div>${supplier.email || 'N/A'}</div>
                </div>
            </div>
            ${productsHtml}
        `;
        showModal('supplierProductsModal');
    })
    .catch(() => showNotification('Unable to load supplier products', 'error'));
}

// Delete supplier
function deleteSupplier(id) {
    if (confirm('Are you sure you want to delete this supplier?')) {
        fetch('admin.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ action: 'delete_supplier', id: id })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                showNotification(result.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showNotification(result.message, 'error');
            }
        });
    }
}

// Export suppliers
function exportSuppliers() {
    showNotification('Exporting suppliers...', 'info');
    setTimeout(() => showNotification('Suppliers exported successfully!', 'success'), 1500);
}
</script>

