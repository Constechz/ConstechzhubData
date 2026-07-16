<?php
require_once '../config/config.php';

requireRole('admin');
ensureProductOrderTables();

$allowed_statuses = [
    'pending_payment',
    'processing',
    'shipped',
    'delivered',
    'cancelled',
    'payment_failed',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: product-orders.php');
        exit();
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $order_id = (int) ($_POST['order_id'] ?? 0);
    if ($action === 'update_status' && $order_id > 0) {
        $order_status = trim((string) ($_POST['order_status'] ?? ''));
        if (!in_array($order_status, $allowed_statuses, true)) {
            setFlashMessage('error', 'Invalid order status selected.');
        } else {
            $stmt = $db->prepare("UPDATE product_orders SET order_status = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $order_status, $order_id);
                $stmt->execute();
                $stmt->close();
                setFlashMessage('success', 'Product order status updated.');
            } else {
                setFlashMessage('error', 'Unable to prepare the status update.');
            }
        }
        header('Location: product-orders.php');
        exit();
    }
}

$filter_status = trim((string) ($_GET['status'] ?? ''));
$filter_payment = trim((string) ($_GET['payment'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT
        po.*,
        dp.name AS product_name,
        ast.store_name,
        u.full_name AS agent_name
    FROM product_orders po
    JOIN dashboard_products dp ON dp.id = po.product_id
    LEFT JOIN agent_stores ast ON ast.store_slug = po.store_slug
    LEFT JOIN users u ON u.id = po.agent_id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($filter_status !== '') {
    $sql .= " AND po.order_status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_payment !== '') {
    $sql .= " AND po.payment_status = ?";
    $params[] = $filter_payment;
    $types .= 's';
}

if ($search !== '') {
    $sql .= " AND (po.order_reference LIKE ? OR po.customer_name LIKE ? OR po.customer_phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$sql .= " ORDER BY po.created_at DESC LIMIT 250";

$orders = [];
$stmt = $db->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
}

$flash = getFlashMessage();
$csrf_token = generateCSRF();
$pageTitle = 'Product Orders';
require_once '../includes/admin_header.php';
?>
<style>
    .product-orders-admin .filters-form {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.85rem;
        align-items: end;
    }
    .product-orders-admin .filters-form label {
        display: grid;
        gap: 0.35rem;
        font-weight: 600;
    }
    .product-orders-admin .orders-table td {
        vertical-align: top;
    }
    .order-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.2rem 0.7rem;
        border-radius: 999px;
        font-size: 0.76rem;
        font-weight: 700;
    }
    .order-badge-payment-pending { background:#fef3c7; color:#92400e; }
    .order-badge-payment-paid { background:#dcfce7; color:#166534; }
    .order-badge-payment-failed { background:#fee2e2; color:#991b1b; }
    .order-badge-status-pending_payment { background:#e2e8f0; color:#334155; }
    .order-badge-status-processing { background:#dbeafe; color:#1d4ed8; }
    .order-badge-status-shipped { background:#ede9fe; color:#6d28d9; }
    .order-badge-status-delivered { background:#dcfce7; color:#166534; }
    .order-badge-status-cancelled,
    .order-badge-status-payment_failed { background:#fee2e2; color:#991b1b; }
    .order-meta {
        display: grid;
        gap: 0.3rem;
        color: var(--text-muted, #64748b);
        font-size: 0.9rem;
    }
    .order-meta strong {
        color: var(--text-color, #0f172a);
    }
    @media (max-width: 991px) {
        .product-orders-admin .filters-form {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 768px) {
        .product-orders-admin .filters-form {
            grid-template-columns: 1fr;
        }
        .product-orders-admin .table,
        .product-orders-admin .table thead,
        .product-orders-admin .table tbody,
        .product-orders-admin .table tr,
        .product-orders-admin .table th,
        .product-orders-admin .table td {
            display: block;
            width: 100%;
        }
        .product-orders-admin .table thead {
            display: none;
        }
        .product-orders-admin .table tbody {
            display: grid;
            gap: 1rem;
        }
        .product-orders-admin .table tbody tr {
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 16px;
            padding: 1rem;
            background: var(--bg-primary, #fff);
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.05);
        }
        .product-orders-admin .table tbody td {
            border: none;
            padding: 0.45rem 0;
        }
        .product-orders-admin .table tbody td::before {
            content: attr(data-label);
            display: block;
            margin-bottom: 0.2rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted, #64748b);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
    }
</style>

<div class="container-fluid product-orders-admin">
    <div class="page-title">
        <h1>Product Orders</h1>
        <p class="page-subtitle">Review product payments, delivery details, and fulfillment status across every store.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>" style="margin-bottom:1rem;">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="widget" style="margin-bottom:1.5rem;">
        <div class="widget-header">
            <h3 class="widget-title">Filters</h3>
        </div>
        <div class="widget-body">
            <form method="get" class="filters-form">
                <label>
                    Search
                    <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Reference, name, phone">
                </label>
                <label>
                    Order Status
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($allowed_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Payment
                    <select name="payment" class="form-control">
                        <option value="">All</option>
                        <?php foreach (['pending', 'paid', 'failed'] as $paymentStatus): ?>
                            <option value="<?php echo htmlspecialchars($paymentStatus); ?>" <?php echo $filter_payment === $paymentStatus ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($paymentStatus)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                    <a href="product-orders.php" class="btn btn-outline">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title">Orders</h3>
        </div>
        <div class="widget-body">
            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-shopping-bag"></i>
                    <p>No product orders match the current filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table orders-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Product</th>
                                <th>Customer</th>
                                <th>Delivery</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $payment_status = strtolower(trim((string) ($order['payment_status'] ?? 'pending')));
                                $order_status = strtolower(trim((string) ($order['order_status'] ?? 'pending_payment')));
                                ?>
                                <tr>
                                    <td data-label="Reference">
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string) ($order['order_reference'] ?? '')); ?></div>
                                        <div class="order-meta">
                                            <span><strong>Store:</strong> <?php echo htmlspecialchars((string) ($order['store_name'] ?? $order['store_slug'] ?? '')); ?></span>
                                            <span><strong>Agent:</strong> <?php echo htmlspecialchars((string) ($order['agent_name'] ?? '')); ?></span>
                                            <span><strong>Created:</strong> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($order['created_at'] ?? 'now')))); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Product">
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string) ($order['product_name'] ?? '')); ?></div>
                                        <div class="order-meta">
                                            <span><strong>Total:</strong> <?php echo htmlspecialchars(formatCurrency((float) ($order['total_amount'] ?? 0))); ?></span>
                                            <span><strong>Gateway:</strong> <?php echo htmlspecialchars((string) ($order['payment_gateway'] ?? 'paystack')); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Customer">
                                        <div class="order-meta">
                                            <span><strong>Name:</strong> <?php echo htmlspecialchars((string) ($order['customer_name'] ?? '')); ?></span>
                                            <span><strong>Email:</strong> <?php echo htmlspecialchars((string) ($order['customer_email'] ?? '')); ?></span>
                                            <span><strong>Phone:</strong> <?php echo htmlspecialchars((string) ($order['customer_phone'] ?? '')); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Delivery">
                                        <div class="order-meta">
                                            <span><strong>Address:</strong> <?php echo htmlspecialchars((string) ($order['delivery_address'] ?? '')); ?></span>
                                            <span><strong>City / Region:</strong> <?php echo htmlspecialchars(trim((string) (($order['city'] ?? '') . (trim((string) ($order['region_name'] ?? '')) !== '' ? ', ' . $order['region_name'] : '')))); ?></span>
                                            <?php if (!empty($order['landmark'])): ?><span><strong>Landmark:</strong> <?php echo htmlspecialchars((string) $order['landmark']); ?></span><?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Payment">
                                        <span class="order-badge order-badge-payment-<?php echo htmlspecialchars($payment_status); ?>"><?php echo htmlspecialchars(ucfirst($payment_status)); ?></span>
                                    </td>
                                    <td data-label="Status">
                                        <span class="order-badge order-badge-status-<?php echo htmlspecialchars($order_status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order_status))); ?></span>
                                    </td>
                                    <td data-label="Update">
                                        <form method="post" style="display:grid; gap:0.6rem; min-width:180px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?php echo (int) ($order['id'] ?? 0); ?>">
                                            <select name="order_status" class="form-control">
                                                <?php foreach ($allowed_statuses as $status): ?>
                                                    <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $order_status === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
                                        </form>
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
