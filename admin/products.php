<?php
require_once '../config/config.php';

requireRole('admin');

ensureProductCatalogTables();

$csrf_token = generateCSRF();

if (!function_exists('adminProductsIsManagedUpload')) {
    function adminProductsIsManagedUpload($path) {
        $path = ltrim((string) $path, '/\\');
        return $path !== '' && strpos(str_replace('\\', '/', $path), 'uploads/products/') === 0;
    }
}

if (!function_exists('adminProductsDeleteImageFile')) {
    function adminProductsDeleteImageFile($path) {
        $path = ltrim((string) $path, '/\\');
        if (!adminProductsIsManagedUpload($path)) {
            return;
        }

        $baseDir = realpath(__DIR__ . '/../');
        $target = realpath(__DIR__ . '/../' . $path);

        if (!$baseDir || !$target) {
            return;
        }

        $normalizedBase = str_replace('\\', '/', $baseDir);
        $normalizedTarget = str_replace('\\', '/', $target);

        if (strpos($normalizedTarget, $normalizedBase . '/uploads/products/') !== 0) {
            return;
        }

        if (is_file($target)) {
            @unlink($target);
        }
    }
}

if (!function_exists('adminProductsUploadImage')) {
    function adminProductsUploadImage($file, &$error = null) {
        $error = null;

        if (!is_array($file) || empty($file['name'])) {
            return null;
        }

        $fileError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($fileError !== UPLOAD_ERR_OK) {
            $error = 'Failed to upload product image. Please try again.';
            return false;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $error = 'Uploaded product image is invalid.';
            return false;
        }

        $maxFileSize = 5 * 1024 * 1024;
        if ((int) ($file['size'] ?? 0) > $maxFileSize) {
            $error = 'Product image is too large. Maximum allowed size is 5MB.';
            return false;
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mimeType = $finfo ? finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        if (!isset($allowedMimeMap[$mimeType])) {
            $error = 'Invalid product image format. Allowed formats: JPG, PNG, WebP, GIF.';
            return false;
        }

        $uploadDir = __DIR__ . '/../uploads/products/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $error = 'Could not create products upload directory.';
            return false;
        }

        try {
            $token = bin2hex(random_bytes(4));
        } catch (Exception $e) {
            $token = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        }

        $extension = $allowedMimeMap[$mimeType];
        $filename = 'product_' . date('Ymd_His') . '_' . $token . '.' . $extension;
        $target = $uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $target)) {
            $error = 'Failed to save uploaded product image.';
            return false;
        }

        return 'uploads/products/' . $filename;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: products.php');
        exit();
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sizeLabel = trim((string) ($_POST['size_label'] ?? ''));
        $currentPriceInput = trim((string) ($_POST['current_price'] ?? ''));
        $oldPriceInput = trim((string) ($_POST['old_price'] ?? ''));
        $rating = max(0, min(5, (int) ($_POST['rating'] ?? 5)));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $removeImage = isset($_POST['remove_image']);

        $redirect = 'products.php' . ($action === 'update' && $productId > 0 ? '?edit=' . $productId : '');

        if ($name === '') {
            setFlashMessage('error', 'Product name is required.');
            header('Location: ' . $redirect);
            exit();
        }

        if ($currentPriceInput === '' || !is_numeric($currentPriceInput)) {
            setFlashMessage('error', 'Current price must be a valid number.');
            header('Location: ' . $redirect);
            exit();
        }

        $currentPrice = round((float) $currentPriceInput, 2);
        if ($currentPrice < 0) {
            setFlashMessage('error', 'Current price cannot be negative.');
            header('Location: ' . $redirect);
            exit();
        }

        $oldPrice = null;
        if ($oldPriceInput !== '') {
            if (!is_numeric($oldPriceInput)) {
                setFlashMessage('error', 'Old price must be a valid number.');
                header('Location: ' . $redirect);
                exit();
            }
            $oldPrice = round((float) $oldPriceInput, 2);
            if ($oldPrice < 0) {
                setFlashMessage('error', 'Old price cannot be negative.');
                header('Location: ' . $redirect);
                exit();
            }
        }

        $currentImagePath = null;
        if ($action === 'update') {
            if ($productId <= 0) {
                setFlashMessage('error', 'Invalid product selected for editing.');
                header('Location: products.php');
                exit();
            }

            $checkStmt = $db->prepare("SELECT image_path FROM dashboard_products WHERE id = ? LIMIT 1");
            if (!$checkStmt) {
                setFlashMessage('error', 'Failed to prepare product lookup.');
                header('Location: products.php');
                exit();
            }
            $checkStmt->bind_param('i', $productId);
            $checkStmt->execute();
            $currentRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$currentRow) {
                setFlashMessage('error', 'Product not found.');
                header('Location: products.php');
                exit();
            }

            $currentImagePath = $currentRow['image_path'] ?? null;
        }

        $imagePath = $action === 'update' ? $currentImagePath : null;
        if ($removeImage) {
            $imagePath = null;
        }

        $uploadError = null;
        $uploadedImagePath = adminProductsUploadImage($_FILES['image_file'] ?? null, $uploadError);
        if ($uploadedImagePath === false) {
            setFlashMessage('error', $uploadError ?: 'Failed to process the uploaded product image.');
            header('Location: ' . $redirect);
            exit();
        }
        if ($uploadedImagePath !== null) {
            $imagePath = $uploadedImagePath;
        }

        $sizeValue = $sizeLabel !== '' ? $sizeLabel : null;

        try {
            if ($action === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO dashboard_products
                    (name, description, size_label, current_price, old_price, rating, image_path, sort_order, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new RuntimeException('Failed to prepare product insert.');
                }

                $createdBy = (int) ($_SESSION['user_id'] ?? 0);
                $stmt->bind_param(
                    'sssddisiii',
                    $name,
                    $description,
                    $sizeValue,
                    $currentPrice,
                    $oldPrice,
                    $rating,
                    $imagePath,
                    $sortOrder,
                    $isActive,
                    $createdBy
                );
            } else {
                $stmt = $db->prepare("
                    UPDATE dashboard_products
                    SET name = ?, description = ?, size_label = ?, current_price = ?, old_price = ?, rating = ?, image_path = ?, sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                if (!$stmt) {
                    throw new RuntimeException('Failed to prepare product update.');
                }

                $stmt->bind_param(
                    'sssddisiiii',
                    $name,
                    $description,
                    $sizeValue,
                    $currentPrice,
                    $oldPrice,
                    $rating,
                    $imagePath,
                    $sortOrder,
                    $isActive,
                    $productId
                );
            }

            if (!$stmt->execute()) {
                throw new RuntimeException($action === 'create' ? 'Failed to save the new product.' : 'Failed to update the product.');
            }

            $stmt->close();

            if ($action === 'update' && $currentImagePath && $currentImagePath !== $imagePath) {
                adminProductsDeleteImageFile($currentImagePath);
            }

            setFlashMessage('success', $action === 'create' ? 'Product created successfully.' : 'Product updated successfully.');
        } catch (Throwable $e) {
            if (!empty($uploadedImagePath)) {
                adminProductsDeleteImageFile($uploadedImagePath);
            }
            setFlashMessage('error', $e->getMessage());
        }

        header('Location: products.php');
        exit();
    }

    if ($action === 'delete') {
        if ($productId <= 0) {
            setFlashMessage('error', 'Invalid product selected for deletion.');
            header('Location: products.php');
            exit();
        }

        try {
            $imagePath = null;
            $readStmt = $db->prepare("SELECT image_path FROM dashboard_products WHERE id = ? LIMIT 1");
            if ($readStmt) {
                $readStmt->bind_param('i', $productId);
                $readStmt->execute();
                $productRow = $readStmt->get_result()->fetch_assoc();
                $imagePath = $productRow['image_path'] ?? null;
                $readStmt->close();
            }

            $stmt = $db->prepare("DELETE FROM dashboard_products WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare product deletion.');
            }
            $stmt->bind_param('i', $productId);

            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to delete product.');
            }
            $stmt->close();

            if ($imagePath) {
                adminProductsDeleteImageFile($imagePath);
            }

            setFlashMessage('success', 'Product deleted successfully.');
        } catch (Throwable $e) {
            setFlashMessage('error', $e->getMessage());
        }

        header('Location: products.php');
        exit();
    }

    if ($action === 'toggle_status') {
        if ($productId <= 0) {
            setFlashMessage('error', 'Invalid product selected.');
            header('Location: products.php');
            exit();
        }

        try {
            $stmt = $db->prepare("UPDATE dashboard_products SET is_active = NOT is_active WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Failed to prepare status update.');
            }
            $stmt->bind_param('i', $productId);

            if (!$stmt->execute()) {
                throw new RuntimeException('Failed to update product status.');
            }
            $stmt->close();

            setFlashMessage('success', 'Product status updated successfully.');
        } catch (Throwable $e) {
            setFlashMessage('error', $e->getMessage());
        }

        header('Location: products.php');
        exit();
    }
}

$products = getDashboardProducts(false, null);
$editProduct = null;

if (isset($_GET['edit']) && (int) $_GET['edit'] > 0) {
    $editId = (int) $_GET['edit'];
    $stmt = $db->prepare("
        SELECT
            id,
            name,
            description,
            size_label,
            current_price,
            old_price,
            rating,
            image_path,
            sort_order,
            is_active
        FROM dashboard_products
        WHERE id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editProduct = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$flash = getFlashMessage();
$pageTitle = 'Products';
require_once '../includes/admin_header.php';
?>

<style>
    .products-page .widget-header {
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .products-page .widget-header .btn {
        max-width: 100%;
    }

    .products-page .form-grid > div {
        min-width: 0;
    }

    .products-page .form-label {
        display: inline-block;
        margin-bottom: 0.45rem;
        font-weight: 600;
    }

    .product-admin-thumb {
        width: 72px;
        height: 72px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-secondary, #f8fafc);
    }

    .product-admin-thumb-placeholder {
        width: 72px;
        height: 72px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        border: 1px dashed var(--border-color, #e2e8f0);
        color: var(--text-muted, #64748b);
        background: var(--bg-secondary, #f8fafc);
    }

    .product-form-preview {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.9rem 1rem;
        border: 1px dashed var(--border-color, #d0d7e2);
        border-radius: 12px;
        background: var(--bg-secondary, #f8fafc);
        margin-top: 0.75rem;
    }

    .product-form-preview img {
        width: 92px;
        height: 92px;
        object-fit: cover;
        border-radius: 12px;
        border: 1px solid var(--border-color, #e2e8f0);
    }

    .product-table-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .product-rating {
        color: #f59e0b;
        letter-spacing: 0.08em;
        white-space: nowrap;
    }

    .product-price-stack {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .product-price-stack .old-price {
        text-decoration: line-through;
        color: var(--text-muted, #64748b);
        font-size: 0.875rem;
    }

    .products-page .table td {
        vertical-align: middle;
    }

    .products-page .table td:last-child,
    .products-page .table th:last-child {
        white-space: normal;
    }

    @media (max-width: 992px) {
        .products-page .page-subtitle {
            max-width: 100%;
        }

        .products-page .widget-header {
            align-items: flex-start;
        }
    }

    @media (max-width: 768px) {
        html, body {
            overflow-x: hidden;
        }

        .products-page,
        .products-page .dashboard-wrapper,
        .products-page .main-content,
        .products-page .dashboard-content,
        .products-page .container-fluid {
            overflow-x: hidden;
        }

        .products-page .widget-header .btn {
            width: 100%;
            justify-content: center;
        }

        .product-form-preview {
            flex-direction: column;
            align-items: flex-start;
        }

        .product-form-preview img {
            width: 100%;
            max-width: 220px;
            height: auto;
            aspect-ratio: 1 / 1;
        }

        .product-table-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .products-page .product-table-actions .btn,
        .products-page .product-table-actions button {
            width: 100%;
            justify-content: center;
        }

        .products-page .table-responsive {
            overflow-x: hidden;
            border: none;
        }

        .products-page .table,
        .products-page .table thead,
        .products-page .table tbody,
        .products-page .table tr,
        .products-page .table th,
        .products-page .table td {
            display: block;
            width: 100%;
            min-width: 0;
        }

        .products-page .table {
            min-width: 0;
        }

        .products-page .table thead {
            display: none;
        }

        .products-page .table tbody {
            display: grid;
            gap: 1rem;
        }

        .products-page .table tbody tr {
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 14px;
            padding: 0.9rem 1rem;
            background: var(--bg-primary, #fff);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        }

        .products-page .table tbody td {
            border: none;
            padding: 0.45rem 0;
            white-space: normal;
        }

        .products-page .table tbody td::before {
            content: attr(data-label);
            display: block;
            margin-bottom: 0.2rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .products-page .table tbody td[data-label="Image"]::before {
            margin-bottom: 0.55rem;
        }

        .products-page .product-admin-thumb,
        .products-page .product-admin-thumb-placeholder {
            width: 88px;
            height: 88px;
        }
    }

    @media (max-width: 480px) {
        .products-page .container-fluid {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }

        .products-page .table tbody tr {
            padding: 0.8rem 0.85rem;
        }

        .products-page .product-admin-thumb,
        .products-page .product-admin-thumb-placeholder {
            width: 76px;
            height: 76px;
        }
    }
</style>

<div class="container-fluid products-page">
    <div class="page-title">
        <h1>Products</h1>
        <p class="page-subtitle">Manage the catalog used across the agent dashboard and store checkout. Update the image, description, price, rating, order, and visibility here.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>" style="margin-bottom: 1rem;">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <h3 class="widget-title"><?php echo $editProduct ? 'Edit Product' : 'Add Product'; ?></h3>
            <?php if ($editProduct): ?>
                <a href="products.php" class="btn btn-outline">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <div class="widget-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="<?php echo $editProduct ? 'update' : 'create'; ?>">
                <input type="hidden" name="product_id" value="<?php echo (int) ($editProduct['id'] ?? 0); ?>">

                <div class="form-grid">
                    <div>
                        <label for="name" class="form-label">Product Name</label>
                        <input type="text" id="name" name="name" class="form-control" required value="<?php echo htmlspecialchars((string) ($editProduct['name'] ?? '')); ?>" placeholder="CAT 4 UNIVERSAL ROUTER">
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <label for="description" class="form-label">Product Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4" placeholder="Describe what the buyer gets, delivery notes, and important product details."><?php echo htmlspecialchars((string) ($editProduct['description'] ?? '')); ?></textarea>
                    </div>

                    <div>
                        <label for="size_label" class="form-label">Size Label</label>
                        <input type="text" id="size_label" name="size_label" class="form-control" value="<?php echo htmlspecialchars((string) ($editProduct['size_label'] ?? '')); ?>" placeholder="Large">
                    </div>

                    <div>
                        <label for="current_price" class="form-label">Current Price</label>
                        <input type="number" id="current_price" name="current_price" class="form-control" min="0" step="0.01" required value="<?php echo htmlspecialchars(isset($editProduct['current_price']) ? number_format((float) $editProduct['current_price'], 2, '.', '') : '0.00'); ?>">
                    </div>

                    <div>
                        <label for="old_price" class="form-label">Old Price</label>
                        <input type="number" id="old_price" name="old_price" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(isset($editProduct['old_price']) && $editProduct['old_price'] !== null ? number_format((float) $editProduct['old_price'], 2, '.', '') : ''); ?>" placeholder="Optional">
                    </div>

                    <div>
                        <label for="rating" class="form-label">Rating</label>
                        <select id="rating" name="rating" class="form-control">
                            <?php for ($stars = 0; $stars <= 5; $stars++): ?>
                                <option value="<?php echo $stars; ?>" <?php echo (int) ($editProduct['rating'] ?? 5) === $stars ? 'selected' : ''; ?>>
                                    <?php echo $stars; ?> star<?php echo $stars === 1 ? '' : 's'; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label for="sort_order" class="form-label">Display Order</label>
                        <input type="number" id="sort_order" name="sort_order" class="form-control" step="1" value="<?php echo htmlspecialchars((string) ($editProduct['sort_order'] ?? '0')); ?>">
                    </div>

                    <div>
                        <label for="image_file" class="form-label">Product Image</label>
                        <input type="file" id="image_file" name="image_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
                        <small class="text-muted">Upload JPG, PNG, WebP, or GIF up to 5MB.</small>
                    </div>

                    <div style="display: flex; flex-direction: column; justify-content: end; gap: 0.75rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="is_active" value="1" <?php echo !isset($editProduct['is_active']) || (int) $editProduct['is_active'] === 1 ? 'checked' : ''; ?>>
                            <span>Visible on agent dashboard</span>
                        </label>
                        <?php if (!empty($editProduct['image_path'])): ?>
                            <label style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                <input type="checkbox" name="remove_image" value="1">
                                <span>Remove current image</span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($editProduct['image_path'])): ?>
                    <div class="product-form-preview">
                        <img src="<?php echo htmlspecialchars(dbh_asset($editProduct['image_path'])); ?>" alt="<?php echo htmlspecialchars($editProduct['name']); ?>">
                        <div>
                            <div style="font-weight: 600; margin-bottom: 0.25rem;">Current image</div>
                            <div style="color: var(--text-muted); font-size: 0.9rem;">Upload a new image to replace it, or tick “Remove current image”.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-actions" style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?php echo $editProduct ? 'Update Product' : 'Create Product'; ?>
                    </button>
                    <?php if ($editProduct): ?>
                        <a href="products.php" class="btn btn-outline">Reset Form</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title">Catalog Products</h3>
        </div>
        <div class="widget-body">
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No products have been added yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Rating</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php
                                $currentPrice = (float) ($product['current_price'] ?? 0);
                                $oldPrice = $product['old_price'] !== null ? (float) $product['old_price'] : null;
                                $rating = max(0, min(5, (int) ($product['rating'] ?? 5)));
                                ?>
                                <tr>
                                    <td data-label="Image">
                                        <?php if (!empty($product['image_path'])): ?>
                                            <img class="product-admin-thumb" src="<?php echo htmlspecialchars(dbh_asset($product['image_path'])); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                                        <?php else: ?>
                                            <span class="product-admin-thumb-placeholder"><i class="fas fa-image"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Product">
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div style="color: var(--text-muted); font-size: 0.875rem;">
                                            <?php echo htmlspecialchars((string) ($product['size_label'] ?: 'No size label')); ?>
                                        </div>
                                    </td>
                                    <td data-label="Price">
                                        <div class="product-price-stack">
                                            <strong><?php echo htmlspecialchars(formatCurrency($currentPrice)); ?></strong>
                                            <?php if ($oldPrice !== null && $oldPrice > 0): ?>
                                                <span class="old-price"><?php echo htmlspecialchars(formatCurrency($oldPrice)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Rating"><span class="product-rating"><?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?></span></td>
                                    <td data-label="Order"><?php echo number_format((int) ($product['sort_order'] ?? 0)); ?></td>
                                    <td data-label="Status">
                                        <span class="badge <?php echo (int) ($product['is_active'] ?? 0) === 1 ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo (int) ($product['is_active'] ?? 0) === 1 ? 'Active' : 'Hidden'; ?>
                                        </span>
                                    </td>
                                    <td data-label="Updated"><?php echo !empty($product['updated_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($product['updated_at']))) : 'N/A'; ?></td>
                                    <td data-label="Actions">
                                        <div class="product-table-actions">
                                            <a href="products.php?edit=<?php echo (int) $product['id']; ?>" class="btn btn-outline btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>

                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                                <button type="submit" class="btn btn-outline btn-sm">
                                                    <i class="fas <?php echo (int) ($product['is_active'] ?? 0) === 1 ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                    <?php echo (int) ($product['is_active'] ?? 0) === 1 ? 'Hide' : 'Show'; ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display: inline;" onsubmit="return confirm('Delete this product?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" style="color: #dc2626; border-color: rgba(220, 38, 38, 0.3);">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
