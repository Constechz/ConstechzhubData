<?php
require_once '../config/config.php';

requireRole('vip');
preventBrowserCaching();
ensureProductOrderTables();

$current_user = getCurrentUser();
$agent_id = (int) ($current_user['id'] ?? 0);
if ($agent_id <= 0) {
    header('Location: ../login.php?session=invalid');
    exit();
}

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
            $stmt = $db->prepare("UPDATE product_orders SET order_status = ?, updated_at = NOW() WHERE id = ? AND agent_id = ?");
            if ($stmt) {
                $stmt->bind_param('sii', $order_status, $order_id, $agent_id);
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
        dp.name AS product_name
    FROM product_orders po
    JOIN dashboard_products dp ON dp.id = po.product_id
    WHERE po.agent_id = ?
";
$params = [$agent_id];
$types = 'i';

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

$sql .= " ORDER BY po.created_at DESC LIMIT 200";

$orders = [];
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
}

$csrf_token = generateCSRF();
$flash = getFlashMessage();
$agent_name = trim((string) ($current_user['full_name'] ?? $current_user['username'] ?? 'Agent'));
$agent_initial = strtoupper(substr($agent_name !== '' ? $agent_name : 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Orders - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .product-orders-agent .filters-form {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
            align-items: end;
        }
        .product-orders-agent .filters-form label {
            display: grid;
            gap: 0.35rem;
            font-weight: 600;
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
            gap: 0.28rem;
            color: var(--text-muted, #64748b);
            font-size: 0.9rem;
        }
        .order-meta strong {
            color: var(--text-color, #0f172a);
        }
        @media (max-width: 991px) {
            .product-orders-agent .filters-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 768px) {
            .product-orders-agent .filters-form {
                grid-template-columns: 1fr;
            }
            .product-orders-agent .table,
            .product-orders-agent .table thead,
            .product-orders-agent .table tbody,
            .product-orders-agent .table tr,
            .product-orders-agent .table th,
            .product-orders-agent .table td {
                display: block;
                width: 100%;
            }
            .product-orders-agent .table thead {
                display: none;
            }
            .product-orders-agent .table tbody {
                display: grid;
                gap: 1rem;
            }
            .product-orders-agent .table tbody tr {
                border: 1px solid var(--border-color, #2d3748);
                border-radius: 16px;
                padding: 1rem;
                background: var(--card-bg, #111827);
                box-shadow: 0 12px 26px rgba(0, 0, 0, 0.18);
            }
            .product-orders-agent .table tbody td {
                border: none;
                padding: 0.45rem 0;
            }
            .product-orders-agent .table tbody td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.2rem;
                font-size: 0.78rem;
                font-weight: 700;
                color: var(--text-muted, #94a3b8);
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <?php renderAgentSidebar('product-orders.php'); ?>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-shopping-bag"></i></div>
                    <div class="breadcrumb-item">Agent</div>
                    <div class="breadcrumb-item active">Product Orders</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode">
                    <i class="fas fa-moon theme-icon" id="theme-icon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" type="button" onclick="toggleUserDropdown()" aria-haspopup="true" aria-expanded="false">
                        <span class="user-avatar"><?php echo htmlspecialchars($agent_initial); ?></span>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($agent_name); ?></div>
                            <div class="user-role">Agent</div>
                        </div>
                        <span class="dropdown-arrow"><i class="fas fa-chevron-down"></i></span>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                        <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content product-orders-agent">
            <div class="page-title">
                <h1>Product Orders</h1>
                <p class="page-subtitle">Track paid product orders, review delivery details, and update fulfillment status for your store.</p>
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
                            <table class="table">
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
                                                    <span><strong>Created:</strong> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($order['created_at'] ?? 'now')))); ?></span>
                                                    <span><strong>Total:</strong> <?php echo htmlspecialchars(formatCurrency((float) ($order['total_amount'] ?? 0))); ?></span>
                                                </div>
                                            </td>
                                            <td data-label="Product">
                                                <div style="font-weight:700;"><?php echo htmlspecialchars((string) ($order['product_name'] ?? '')); ?></div>
                                                <div class="order-meta">
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
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function initMobileMenu() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const dashboardWrapper = document.querySelector('.dashboard-wrapper');
    if (!mobileToggle || !sidebar || !dashboardWrapper) return;

    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        dashboardWrapper.appendChild(overlay);
    }

    mobileToggle.addEventListener('click', function() {
        const isOpen = sidebar.classList.contains('show');
        sidebar.classList.toggle('show', !isOpen);
        overlay.classList.toggle('show', !isOpen);
    });

    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });
}

function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = savedTheme || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
    updateThemeIcon(theme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (!icon) return;
    icon.className = (theme === 'light' ? 'fas fa-moon' : 'fas fa-sun') + ' theme-icon';
}

function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    if (!dropdown || !toggle) return;
    const willShow = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willShow);
    toggle.classList.toggle('open', willShow);
    toggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    if (!dropdown || !toggle) return;
    if (!dropdown.contains(event.target) && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    initMobileMenu();
    initTheme();
});
</script>
</body>
</html>
