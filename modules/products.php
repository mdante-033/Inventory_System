<?php
/**
 * Products Module
 * Product management functionality
 */

require_once __DIR__ . '/../includes/product_image_helper.php';

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
if (basename($_SERVER['PHP_SELF']) == 'products.php') {
    header('Location: ../admin.php');
    exit;
}

if (!isset($productImageColumnAvailable)) {
    $productImageColumnAvailable = productImageColumnExists($pdo);
}

// Get categories for dropdown
$categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM categories WHERE is_active = true ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {}

// Get all products
$all_products = [];
try {
    $stmt = $pdo->query("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.is_active = true 
        ORDER BY p.name
    ");
    $all_products = $stmt->fetchAll();
} catch (PDOException $e) {}
?>

<!-- Products Page -->
<div class="page-section active" id="page-products">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Products</h3>
            <button class="btn btn-primary" onclick="showModal('productModal'); resetProductForm();">
                <i class="fas fa-plus"></i> Add Product
            </button>
        </div>

        <?php if (!$productImageColumnAvailable): ?>
        <div style="padding: 0 24px 16px; color: var(--text-muted); font-size: 0.92rem;">
            Product image uploads are not enabled yet. Ask an administrator to apply the product image database migration.
        </div>
        <?php endif; ?>
        
        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box" style="width: 250px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search products..." id="productSearch" onkeyup="filterProducts()">
                </div>
                <select class="form-control form-select" style="width: 150px;" id="categoryFilter" onchange="filterProducts()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-control form-select" style="width: 150px;" id="stockFilter" onchange="filterProducts()">
                    <option value="">All Stock</option>
                    <option value="in_stock">In Stock</option>
                    <option value="low_stock">Low Stock</option>
                    <option value="out_of_stock">Out of Stock</option>
                </select>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-outline" onclick="exportProducts()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="data-table" id="productsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_products as $product): ?>
                    <?php $productImage = resolveProductImagePath($product['image_path'] ?? null); ?>
                    <tr data-category="<?php echo $product['category_id']; ?>" data-stock="<?php echo $product['quantity']; ?>">
                        <td><?php echo $product['id']; ?></td>
                        <td>
                            <div class="d-flex align-center gap-sm">
                                <div class="product-image-mini">
                                    <img src="<?php echo htmlspecialchars($productImage); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                    <?php if ($product['description']): ?>
                                    <div class="text-muted text-sm"><?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($product['sku'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                        <td>$<?php echo number_format($product['unit_price'] ?? 0, 2); ?></td>
                        <td>
                            <?php 
                                $stock_class = $product['quantity'] > 5 ? 'text-success' : ($product['quantity'] > 0 ? 'text-warning' : 'text-danger');
                                echo "<span class='$stock_class font-semibold'>" . $product['quantity'] . "</span>";
                            ?>
                        </td>
                        <td>
                            <?php 
                                if ($product['quantity'] == 0) {
                                    echo '<span class="badge badge-danger">Out of Stock</span>';
                                } elseif ($product['quantity'] <= 5) {
                                    echo '<span class="badge badge-warning">Low Stock</span>';
                                } else {
                                    echo '<span class="badge badge-success">In Stock</span>';
                                }
                            ?>
                        </td>
                        <td>
                            <div class="action-icons">
                                <button class="action-icon" title="Edit" onclick="openEditProductById(<?php echo (int)$product['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-icon" title="Adjust Stock" onclick="openStockAdjustmentById(<?php echo (int)$product['id']; ?>)">
                                    <i class="fas fa-boxes"></i>
                                </button>
                                <button class="action-icon delete" title="Delete" onclick="deleteProduct(<?php echo (int)$product['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($all_products)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        No products found. <button onclick="showModal('productModal')" style="background:none;border:none;color:var(--primary);cursor:pointer;text-decoration:underline;">Add one</button>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Product Modal -->
<div class="modal-overlay" id="productModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="productModalTitle">Add Product</h3>
            <button class="modal-close" onclick="hideModal('productModal')">&times;</button>
        </div>
        <form id="productForm" onsubmit="saveProduct(event)" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" id="productId" name="id">
                <input type="hidden" id="productRemoveImage" name="remove_image" value="0">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" id="productName" name="name" required>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>SKU *</label>
                        <input type="text" id="productSku" name="sku" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select id="productCategory" name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="productDesc" name="description" rows="3"></textarea>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Unit Price *</label>
                        <input type="number" step="0.01" id="productPrice" name="unit_price" required>
                    </div>
                    <div class="form-group">
                        <label>Cost Price</label>
                        <input type="number" step="0.01" id="productCost" name="cost_price">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Initial Quantity *</label>
                        <input type="number" id="productQty" name="quantity" required value="0">
                    </div>
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" id="productReorder" name="reorder_level" value="10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Product Image <?php if ($productImageColumnAvailable): ?><span class="text-muted">(Admin upload)</span><?php endif; ?></label>
                    <div class="product-image-upload">
                        <div class="product-image-preview-wrap">
                            <img
                                id="productImagePreview"
                                src="<?php echo htmlspecialchars(getProductPlaceholderImage()); ?>"
                                alt="Product preview"
                                class="product-image-preview"
                            >
                        </div>
                        <div class="product-image-upload-fields">
                            <input
                                type="file"
                                id="productImage"
                                name="image"
                                accept=".jpg,.jpeg,.png,.gif,.webp"
                                onchange="updateProductImagePreviewFromFile(this)"
                                <?php echo $productImageColumnAvailable ? '' : 'disabled'; ?>
                            >
                            <small class="text-muted">Accepted: JPG, PNG, GIF, WEBP. Max size: 5 MB.</small>
                            <label class="image-remove-toggle <?php echo $productImageColumnAvailable ? '' : 'is-disabled'; ?>">
                                <input
                                    type="checkbox"
                                    id="productRemoveImageToggle"
                                    onchange="toggleProductImageRemoval(this)"
                                    <?php echo $productImageColumnAvailable ? '' : 'disabled'; ?>
                                >
                                Remove current image
                            </label>
                            <?php if (!$productImageColumnAvailable): ?>
                            <small class="text-danger">Image uploads are disabled until the database column is added.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideModal('productModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal-overlay" id="stockModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Adjust Stock</h3>
            <button class="modal-close" onclick="hideModal('stockModal')">&times;</button>
        </div>
        <form id="stockForm" onsubmit="saveStockAdjustment(event)">
            <div class="modal-body">
                <input type="hidden" id="stockProductId" name="product_id">
                <div class="form-group">
                    <label>Product</label>
                    <div id="stockProductName" class="font-semibold text-lg"></div>
                </div>
                <div class="form-group">
                    <label>Current Stock</label>
                    <div id="currentStock" class="font-semibold text-lg"></div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Adjustment Type</label>
                        <select id="adjustmentType" name="adjustment_type" required>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock</option>
                            <option value="set">Set Stock</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" id="adjustmentQty" name="quantity" required min="1" value="1">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="adjustmentNotes" name="notes" rows="2" placeholder="Reason for adjustment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideModal('stockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Adjust Stock</button>
            </div>
        </form>
    </div>
</div>

<style>
.product-image-mini {
    width: 40px;
    height: 40px;
    background: var(--bg-main);
    border-radius: var(--border-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    overflow: hidden;
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
}

.product-image-mini img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image-upload {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 16px;
    align-items: start;
}

.product-image-preview-wrap {
    width: 120px;
    height: 120px;
    border-radius: 12px;
    overflow: hidden;
    background: var(--bg-main);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
}

.product-image-preview {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.product-image-upload-fields {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.image-remove-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 0.92rem;
}

.image-remove-toggle.is-disabled {
    opacity: 0.6;
}

@media (max-width: 640px) {
    .product-image-upload {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
const productLookup = <?php
$productLookup = [];
foreach ($all_products as $product) {
    $productLookup[(string)$product['id']] = [
        'id' => (int)($product['id'] ?? 0),
        'name' => (string)($product['name'] ?? ''),
        'sku' => (string)($product['sku'] ?? ''),
        'description' => (string)($product['description'] ?? ''),
        'category_id' => (int)($product['category_id'] ?? 0),
        'unit_price' => (float)($product['unit_price'] ?? 0),
        'cost_price' => (float)($product['cost_price'] ?? 0),
        'quantity' => (int)($product['quantity'] ?? 0),
        'reorder_level' => (int)($product['reorder_level'] ?? 10),
        'image_path' => (string)($product['image_path'] ?? ''),
        'resolved_image_path' => resolveProductImagePath($product['image_path'] ?? null),
    ];
}
echo json_encode($productLookup);
?>;
const defaultProductImage = <?php echo json_encode(getProductPlaceholderImage()); ?>;

// Filter products
function filterProducts() {
    const search = document.getElementById('productSearch').value.toLowerCase();
    const category = document.getElementById('categoryFilter').value;
    const stock = document.getElementById('stockFilter').value;
    const rows = document.querySelectorAll('#productsTable tbody tr');
    
    rows.forEach(row => {
        let show = true;
        const name = row.cells[1].textContent.toLowerCase();
        const rowCategory = row.dataset.category;
        const quantity = parseInt(row.dataset.stock);
        
        if (search && !name.includes(search)) show = false;
        if (category && rowCategory !== category) show = false;
        if (stock === 'in_stock' && quantity <= 5) show = false;
        if (stock === 'low_stock' && (quantity > 5 || quantity === 0)) show = false;
        if (stock === 'out_of_stock' && quantity > 0) show = false;
        
        row.style.display = show ? '' : 'none';
    });
}

// Reset product form
function resetProductForm() {
    document.getElementById('productModalTitle').textContent = 'Add Product';
    document.getElementById('productForm').reset();
    document.getElementById('productId').value = '';
    document.getElementById('productRemoveImage').value = '0';
    const removeToggle = document.getElementById('productRemoveImageToggle');
    if (removeToggle) {
        removeToggle.checked = false;
    }
    setProductImagePreview(defaultProductImage);
}

// Edit product
function editProduct(id, name, sku, desc, categoryId, price, quantity, reorderLevel, costPrice, imagePath) {
    document.getElementById('productModalTitle').textContent = 'Edit Product';
    document.getElementById('productId').value = id;
    document.getElementById('productName').value = name;
    document.getElementById('productSku').value = sku;
    document.getElementById('productDesc').value = desc;
    document.getElementById('productCategory').value = categoryId;
    document.getElementById('productPrice').value = price;
    document.getElementById('productCost').value = costPrice || 0;
    document.getElementById('productQty').value = quantity;
    document.getElementById('productReorder').value = reorderLevel || 10;
    document.getElementById('productRemoveImage').value = '0';
    const removeToggle = document.getElementById('productRemoveImageToggle');
    if (removeToggle) {
        removeToggle.checked = false;
    }
    setProductImagePreview(imagePath || defaultProductImage);
    showModal('productModal');
}

function openEditProductById(productId) {
    const product = productLookup[String(productId)];
    if (!product) {
        return false;
    }

    editProduct(
        product.id,
        product.name,
        product.sku,
        product.description,
        product.category_id,
        product.unit_price,
        product.quantity,
        product.reorder_level,
        product.cost_price,
        product.resolved_image_path
    );

    return true;
}

function openStockAdjustmentById(productId) {
    const product = productLookup[String(productId)];
    if (!product) {
        return false;
    }

    openStockAdjustment(product.id, product.name, product.quantity);
    return true;
}

// Save product
function saveProduct(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.set('action', document.getElementById('productId').value ? 'update_product' : 'add_product');
    formData.set('remove_image', document.getElementById('productRemoveImage').value || '0');
    
    fetch('admin.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            showNotification(result.message, 'success');
            hideModal('productModal');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showNotification(result.message, 'error');
        }
    })
    .catch(err => {
        showNotification('An error occurred', 'error');
    });
}

function setProductImagePreview(src) {
    const preview = document.getElementById('productImagePreview');
    if (!preview) {
        return;
    }

    preview.src = src || defaultProductImage;
}

function toggleProductImageRemoval(checkbox) {
    document.getElementById('productRemoveImage').value = checkbox.checked ? '1' : '0';
    if (checkbox.checked) {
        setProductImagePreview(defaultProductImage);
    } else {
        const productId = document.getElementById('productId').value;
        const existingProduct = productLookup[String(productId)];
        setProductImagePreview(existingProduct?.resolved_image_path || defaultProductImage);
    }
}

function updateProductImagePreviewFromFile(input) {
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) {
        const productId = document.getElementById('productId').value;
        const existingProduct = productLookup[String(productId)];
        setProductImagePreview(existingProduct?.resolved_image_path || defaultProductImage);
        return;
    }

    const removeToggle = document.getElementById('productRemoveImageToggle');
    if (removeToggle) {
        removeToggle.checked = false;
    }
    document.getElementById('productRemoveImage').value = '0';

    const reader = new FileReader();
    reader.onload = event => setProductImagePreview(event.target?.result || defaultProductImage);
    reader.readAsDataURL(file);
}

// Delete product
function deleteProduct(id) {
    if (confirm('Are you sure you want to delete this product?')) {
        fetch('admin.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({ action: 'delete_product', id: id })
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

// Open stock adjustment
function openStockAdjustment(productId, productName, currentStock) {
    document.getElementById('stockProductId').value = productId;
    document.getElementById('stockProductName').textContent = productName;
    document.getElementById('currentStock').textContent = currentStock;
    showModal('stockModal');
}

// Save stock adjustment
function saveStockAdjustment(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    data.action = 'adjust_stock';
    
    fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            showNotification(result.message, 'success');
            hideModal('stockModal');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showNotification(result.message, 'error');
        }
    });
}

// Export products
function exportProducts() {
    showNotification('Exporting products...', 'info');
    // Implementation for export
    setTimeout(() => showNotification('Products exported successfully!', 'success'), 1500);
}

// Auto-open add product modal from dashboard links
(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('open') === 'add' && document.getElementById('productModal')) {
        resetProductForm();
        showModal('productModal');
        params.delete('open');
        const qs = params.toString();
        history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
    }

    const editId = params.get('edit');
    if (editId && document.getElementById('productModal') && openEditProductById(editId)) {
        params.delete('edit');
        const qs = params.toString();
        history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
    }
})();
</script>

