<?php
require_once '../config/config.php';
require_once '../includes/order_status.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();

// Store-context redirect guard: if customer visits without ?store, redirect to their agent's active store if exists
try {
    $store_slug_guard = $_GET['store'] ?? null;
    if (empty($store_slug_guard)) {
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'agent_id'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $stmt = $db->prepare(
                "SELECT ast.store_slug
                 FROM users u
                 JOIN agent_stores ast ON ast.agent_id = u.agent_id AND ast.is_active = 1
                 WHERE u.id = ?
                 LIMIT 1"
            );
            $stmt->bind_param("i", $current_user['id']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                // Clear any existing flash messages before redirect to prevent logout message from appearing
                unset($_SESSION['flash_message']);
                header("Location: " . SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']));
                exit;
            }
        } else {
            $tblCheck = $db->query("SHOW TABLES LIKE 'user_referrals'");
            if ($tblCheck && $tblCheck->num_rows > 0) {
                $stmt = $db->prepare(
                    "SELECT ast.store_slug
                     FROM user_referrals ur
                     JOIN agent_stores ast ON ast.agent_id = ur.agent_id AND ast.is_active = 1
                     WHERE ur.user_id = ?
                     ORDER BY ur.created_at DESC
                     LIMIT 1"
                );
                $stmt->bind_param("i", $current_user['id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    // Clear any existing flash messages before redirect to prevent logout message from appearing
                    unset($_SESSION['flash_message']);
                    header("Location: " . SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']));
                    exit;
                }
            }
        }
    }
} catch (Exception $e) { /* fail open */ }

// Determine store context for UI links
$store_slug = $_GET['store'] ?? null;
$agent_store = null;
if ($store_slug) {
    $stmt = $db->prepare("
        SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON u.id = ast.agent_id
        WHERE ast.store_slug = ? AND ast.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $agent_store = $stmt->get_result()->fetch_assoc();
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$order_report_delay_minutes = max(1, (int) getSetting('order_report_delay_minutes', 20));
$order_report_whatsapp_number = getSetting('order_report_whatsapp_number', '0249020304');
$order_issue_token = generateCSRF();

$whatsapp_digits = preg_replace('/\D+/', '', $order_report_whatsapp_number);
if (strpos($whatsapp_digits, '233') === 0) {
    $order_report_whatsapp_international = $whatsapp_digits;
} elseif (strpos($whatsapp_digits, '0') === 0) {
    $order_report_whatsapp_international = '233' . substr($whatsapp_digits, 1);
} elseif ($whatsapp_digits) {
    $order_report_whatsapp_international = '233' . ltrim($whatsapp_digits, '0');
} else {
    $order_report_whatsapp_international = '233249020304';
}

// Filters
$network_filter = isset($_GET['network']) ? $_GET['network'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$where_conditions = ["bo.user_id = ?"];
$params = [$current_user['id']];
$types = "i";

if (!empty($network_filter)) {
    $where_conditions[] = "n.name = ?";
    $params[] = $network_filter;
    $types .= "s";
}

if (!empty($status_filter)) {
    $where_conditions[] = "bo.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(bo.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(bo.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total count
$count_query = "
    SELECT COUNT(*) as total
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    $where_clause
";

$stmt = $db->prepare($count_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_orders = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_orders / $limit);

// Get orders with pagination
$orders_query = "
    SELECT bo.*, dp.name as package_name, dp.data_size, dp.validity_days,
           n.name as network_name, n.color as network_color,
           t.amount as transaction_amount,
           COALESCE(pp.price, 0) as customer_price,
           CASE WHEN oir.id IS NULL THEN 0 ELSE 1 END AS has_open_issue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN transactions t ON t.id = bo.transaction_id
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    LEFT JOIN order_issue_reports oir ON oir.order_id = bo.id AND oir.reporter_id = ? AND oir.status IN ('open','in_progress')
    $where_clause
    ORDER BY bo.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $current_user['id'];
$params[] = $limit;
$params[] = $offset;
$types .= "iii";

$stmt = $db->prepare($orders_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders_rs = $stmt->get_result();

$orders = [];
while ($row = $orders_rs->fetch_assoc()) { $orders[] = $row; }

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN bo.status = 'success' THEN 1 ELSE 0 END) as successful_orders,
        SUM(CASE WHEN bo.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN bo.status = 'failed' THEN 1 ELSE 0 END) as failed_orders,
        SUM(CASE WHEN bo.status = 'success' THEN COALESCE(pp.price, 0) ELSE 0 END) as total_spent
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    WHERE bo.user_id = ?
";

$stmt = $db->prepare($stats_query);
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Get networks for filter
$networks_query = "SELECT DISTINCT n.name FROM networks n JOIN data_packages dp ON n.id = dp.network_id WHERE n.is_active = 1 ORDER BY n.name";
$networks_rs = $db->query($networks_query);
$networks = [];
while ($row = $networks_rs->fetch_assoc()) { $networks[] = $row; }

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        /* Prevent body/html horizontal scrollbar */
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }

        @media (max-width: 768px) {
            body,
            .dashboard-wrapper,
            .main-content {
                overflow-x: hidden;
            }

            .table-responsive {
                overflow-x: visible;
            }

            .mobile-responsive-table {
                border: 0;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                font-size: 0.85rem;
            }

            .mobile-responsive-table thead {
                border: none;
                clip: rect(0 0 0 0);
                height: 1px;
                margin: -1px;
                overflow: hidden;
                padding: 0;
                position: absolute;
                width: 1px;
            }

            .mobile-responsive-table tbody,
            .mobile-responsive-table tr,
            .mobile-responsive-table td {
                display: block;
                width: 100%;
            }

            .mobile-responsive-table tr {
                border: 1px solid var(--border-color);
                border-radius: 0.75rem;
                padding: 1rem;
                margin-bottom: 1rem;
                background: var(--bg-primary);
                box-shadow: 0 2px 6px rgba(46, 41, 78, 0.08);
            }

            .mobile-responsive-table td {
                border: none;
                padding: 0.5rem 0;
                text-align: left;
                overflow-wrap: anywhere;
                word-break: break-word;
            }

            .mobile-responsive-table td:before {
                content: attr(data-label) ":";
                display: block;
                margin-bottom: 0.25rem;
                font-weight: 600;
                color: var(--text-muted);
            }

            .mobile-responsive-table td:first-child {
                font-weight: 600;
                background: var(--bg-secondary);
                margin: -1rem -1rem 0.75rem -1rem;
                padding: 0.75rem 1rem;
                border-radius: 0.75rem 0.75rem 0 0;
                text-align: center;
            }

            .mobile-responsive-table td:first-child:before {
                display: none;
            }

            .action-buttons {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .action-buttons .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                padding: 0;
                border-radius: 8px;
            }

            .action-buttons .btn i {
                font-size: 1rem;
            }


            .filter-form .form-row {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }

            .filter-form .form-group {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .stat-value {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .page-title h1 {
                font-size: 1.5rem;
            }

        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php require_once '../includes/customer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-history"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Order History</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'] ?? $_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;">&nbsp;<?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Customer</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-wallet"></i> Wallet
                        </a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('customers'); ?>


        <div class="dashboard-content">
            <div class="page-title">
                <h1>Order History</h1>
                <p class="page-subtitle">Track your data bundle purchases including AT iShare, MTN UP2U, and Telecel packages.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Summary Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format((int)($stats['total_orders'] ?? 0)); ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format((int)($stats['successful_orders'] ?? 0)); ?></div>
                        <div class="stat-label">Successful</div>
                    </div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format((int)($stats['pending_orders'] ?? 0)); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format((int)($stats['failed_orders'] ?? 0)); ?></div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format((float)($stats['total_spent'] ?? 0), 2); ?></div>
                        <div class="stat-label">Total Spent</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="widget">
                <div class="widget-header">
                    <h3>Filter Orders</h3>
                </div>
                <div class="widget-body">
                    <form method="GET" class="filter-form">
                        <?php if (!empty($store_slug)): ?>
                            <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                        <?php endif; ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="network">Network</label>
                                <select name="network" id="network" class="form-control">
                                    <option value="">All Networks</option>
                                    <?php foreach ($networks as $network): ?>
                                        <option value="<?php echo htmlspecialchars($network['name']); ?>" 
                                                <?php echo $network_filter === $network['name'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($network['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="success" <?php echo $status_filter === 'success' ? 'selected' : ''; ?>>Success</option>
                                    <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="date_from">From Date</label>
                                <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="form-group">
                                <label for="date_to">To Date</label>
                                <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="order-history.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="widget">
                <div class="widget-header">
                    <h3>Your Order History</h3>
                    <div class="widget-actions">
                        <span class="text-muted">Showing <?php echo count($orders); ?> of <?php echo number_format((int)($total_orders ?? 0)); ?> orders</span>
                    </div>
                </div>
                <div class="widget-body">
                    <?php if (empty($orders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Orders Found</h3>
                            <p>You haven't made any orders yet or no orders match your filters.</p>
                            <a href="buy-data.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-primary">Buy Data Bundle</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table mobile-responsive-table">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Network</th>
                                        <th>Package</th>
                                        <th>Phone Number</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td data-label="Order ID">
                                                <span class="order-id">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                                <div class="text-muted">
                                                    <small>Placed: <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Network">
                                                <div class="network-badge">
                                                    <span class="network-indicator" style="background-color: <?php echo htmlspecialchars($order['network_color'] ?? '#541388'); ?>"></span>
                                                    <?php echo htmlspecialchars($order['network_name']); ?>
                                                </div>
                                            </td>
                                            <td data-label="Package">
                                                <div class="package-info">
                                                    <div class="package-name"><?php echo htmlspecialchars($order['package_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($order['data_size']); ?> &bull; <?php echo intval($order['validity_days']); ?> days</small>
                                                </div>
                                            </td>
                                            <td data-label="Phone Number">
                                                <span class="phone-number"><?php echo htmlspecialchars($order['beneficiary_number']); ?></span>
                                            </td>
                                            <td data-label="Amount">
                                                <span class="amount"><?php echo CURRENCY . number_format((float)($order['customer_price'] ?? 0), 2); ?></span>
                                            </td>
                                            <?php $status_info = getOrderStatusDisplay($order['status']); ?>
                                            <td data-label="Status">
                                                <span class="badge" style="background-color: <?php echo $status_info['color']; ?>; color: #F1E9DA; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem;" title="<?php echo htmlspecialchars($status_info['description']); ?>">
                                                    <i class="fas <?php echo $status_info['icon']; ?>"></i> <?php echo $status_info['label']; ?>
                                                </span>
                                            </td>
                                            <td data-label="Date">
                                                <div class="date-info">
                                                    <div><?php echo date('M j, Y', strtotime($order['created_at'])); ?></div>
                                                    <small class="text-muted"><?php echo date('g:i A', strtotime($order['created_at'])); ?></small>
                                                </div>
                                            </td>
                                            <?php
                                                $hasOpenIssue = !empty($order['has_open_issue']);
                                                $orderAgeMinutes = max(0, floor((time() - strtotime($order['created_at'])) / 60));
                                                $reportBlockedReason = '';
                                                if ($hasOpenIssue) {
                                                    $reportBlockedReason = 'You already reported this order. Our team is on it.';
                                                } elseif (in_array($order['status'], ['failed', 'cancelled'], true)) {
                                                    $reportBlockedReason = 'Only active/pending orders can be reported.';
                                                } elseif ($orderAgeMinutes < $order_report_delay_minutes) {
                                                    $minsLeft = max(1, $order_report_delay_minutes - $orderAgeMinutes);
                                                    $reportBlockedReason = "You can report this order in {$minsLeft} minute(s).";
                                                }
                                                $canReportOrder = $reportBlockedReason === '';
                                                $orderPayload = [
                                                    'id' => (int) $order['id'],
                                                    'reference' => $order['order_reference'] ?? '',
                                                    'package' => $order['package_name'],
                                                    'network' => $order['network_name'],
                                                    'phone' => $order['beneficiary_number'],
                                                    'status' => $status_info['label'],
                                                    'raw_status' => $order['status'],
                                                    'amount' => (float) ($order['customer_price'] ?? 0),
                                                    'amount_formatted' => CURRENCY . number_format((float) ($order['customer_price'] ?? 0), 2),
                                                    'created_at' => date('M j, Y g:i A', strtotime($order['created_at']))
                                                ];
                                                $orderPayloadJson = htmlspecialchars(json_encode($orderPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(<?php echo $order['id']; ?>)" title="View details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($order['status'] === 'success'): ?>
                                                        <button class="btn btn-sm btn-outline-success" onclick="reorderPackage(<?php echo $order['package_id']; ?>)" title="Reorder package">
                                                            <i class="fas fa-redo"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button
                                                        class="btn btn-sm btn-outline-danger"
                                                        data-order-info="<?php echo $orderPayloadJson; ?>"
                                                        data-can-report="<?php echo $canReportOrder ? '1' : '0'; ?>"
                                                        data-report-blocked="<?php echo htmlspecialchars($reportBlockedReason, ENT_QUOTES, 'UTF-8'); ?>"
                                                        onclick="OrderEscalation.handleReportClick(this)"
                                                        title="Report not delivered"
                                                    >
                                                        <i class="fas fa-exclamation-circle"></i>
                                                    </button>
                                                    <button
                                                        class="btn btn-sm btn-outline-success"
                                                        data-order-info="<?php echo $orderPayloadJson; ?>"
                                                        onclick="OrderEscalation.handleWhatsAppClick(this)"
                                                        title="Send order via WhatsApp"
                                                    >
                                                        <i class="fab fa-whatsapp"></i>
                                                    </button>
                                                </div>
                                                <?php if ($hasOpenIssue): ?>
                                                    <small class="text-warning d-block mt-1"><i class="fas fa-info-circle"></i> Issue reported</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-wrapper">
                                <nav class="pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET, function($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>" class="pagination-btn">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    <?php endif; ?>
                                    
                                    <span class="pagination-info">
                                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                                    </span>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET, function($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>" class="pagination-btn">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Order Escalation Modal -->
<div id="orderIssueModal" class="order-issue-modal" aria-hidden="true">
    <div class="order-issue-modal__dialog">
        <button type="button" class="order-issue-modal__close" id="orderIssueClose" aria-label="Close">&times;</button>
        <h3 style="margin-top:0;">Report Not Delivered</h3>
        <p class="text-muted" style="margin-bottom: 0.5rem;">Share a brief note so we can follow up with the network.</p>
        <div id="orderIssueDetails" class="order-issue-modal__details"></div>
        <div id="orderIssueFeedback" class="order-issue-modal__feedback"></div>
        <form id="orderIssueForm">
            <div class="form-group">
                <label for="orderIssueMessage">Issue description</label>
                <textarea id="orderIssueMessage" class="form-control" rows="4" placeholder="Example: Ordered 5GB MTN bundle 30 mins ago but nothing delivered yet." required></textarea>
            </div>
            <button type="submit" id="orderIssueSubmit" class="btn btn-primary w-100">Submit Report</button>
        </form>
    </div>
</div>

<!-- Order Details Modal -->
<div id="orderModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Order Details</h3>
            <button class="modal-close" onclick="closeOrderModal()">&times;</button>
        </div>
        <div class="modal-body" id="orderDetails">
            <!-- Order details will be loaded here -->
        </div>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    
    // Theme management
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
        if (icon) {
            // Show OPPOSITE icon: moon for light theme (to switch TO dark), sun for dark theme (to switch TO light)
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
    }

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Order details modal
    function viewOrderDetails(orderId) {
        document.getElementById('orderModal').style.display = 'block';
        document.getElementById('orderDetails').innerHTML = '<div class="loading">Loading order details...</div>';
        
        // Example implementation - replace with actual AJAX call
        setTimeout(() => {
            document.getElementById('orderDetails').innerHTML = `
                <div class="order-detail-item">
                    <strong>Order ID:</strong> #${String(orderId).padStart(6, '0')}
                </div>
                <div class="order-detail-item">
                    <strong>Status:</strong> <span class="badge badge-success">Success</span>
                </div>
                <div class="order-detail-item">
                    <strong>Transaction Reference:</strong> TXN${orderId}${Date.now()}
                </div>
                <div class="order-detail-item">
                    <strong>Payment Method:</strong> Wallet Payment
                </div>
            `;
        }, 500);
    }
    
    function closeOrderModal() {
        document.getElementById('orderModal').style.display = 'none';
    }
    
    function reorderPackage(packageId) {
        if (confirm('Would you like to purchase this package again?')) {
            var url = 'buy-data.php' + (<?php echo json_encode(!empty($store_slug)); ?> ? ('?store=' + encodeURIComponent('<?php echo $store_slug ?? ''; ?>')) : '');
            window.location.href = url + '#package-' + packageId;
        }
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('orderModal');
        if (event.target === modal) {
            closeOrderModal();
        }
    }

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
    });
</script>
<script>
window.ORDER_ESCALATION_SETTINGS = {
    apiUrl: '../api/order_issues.php',
    csrfToken: '<?php echo $order_issue_token; ?>',
    whatsappNumber: '<?php echo $order_report_whatsapp_international; ?>',
    minDelayMinutes: <?php echo (int) $order_report_delay_minutes; ?>,
    role: 'customer'
};
</script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/order-escalation.js')); ?>""></script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

