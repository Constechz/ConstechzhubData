<?php
require_once '../config/config.php';
require_once '../includes/order_status.php';

// Require agent role
requireRole('agent');

// Get agent ID
$current_user = getCurrentUser();
$agent_id = (int)($current_user['id'] ?? $_SESSION['user_id']);

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

$status_filter_groups = [
    'pending' => ['pending'],
    'processing' => ['processing'],
    'successful' => ['success', 'completed', 'delivered'],
    'success' => ['success', 'completed', 'delivered'],
    'failed' => ['failed', 'cancelled'],
];

// Build query
$where_conditions = ["(bo.agent_id = ? OR bo.user_id = ?)"];
$params = [$agent_id, $agent_id];
$types = "ii";

if (!empty($network_filter)) {
    $where_conditions[] = "n.name = ?";
    $params[] = $network_filter;
    $types .= "s";
}

if (!empty($status_filter)) {
    $status_values = $status_filter_groups[$status_filter] ?? [$status_filter];
    $status_placeholders = implode(', ', array_fill(0, count($status_values), '?'));
    $where_conditions[] = "bo.status IN ({$status_placeholders})";
    foreach ($status_values as $status_value) {
        $params[] = $status_value;
        $types .= "s";
    }
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
           t.balance_after,
           CASE WHEN bo.agent_id > 0 AND (bo.user_id IS NULL OR bo.user_id != bo.agent_id) THEN bo.amount ELSE COALESCE(NULLIF(bo.agent_cost, 0), NULLIF(pp.price, 0), bo.amount, dp.price, 0) END as agent_price,
           COALESCE(
               ap.profit_amount,
               CASE
                   WHEN bo.agent_id = ?
                        AND bo.agent_cost IS NOT NULL
                   THEN GREATEST(COALESCE(bo.amount, 0) - COALESCE(bo.agent_cost, 0), 0)
                   ELSE 0
               END,
               0
           ) as order_profit,
           CASE WHEN oir.id IS NULL THEN 0 ELSE 1 END AS has_open_issue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN transactions t ON t.id = bo.transaction_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN agent_profits ap ON ap.order_id = bo.id AND ap.agent_id = ? AND ap.status <> 'cancelled'
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'agent'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    LEFT JOIN order_issue_reports oir ON oir.order_id = bo.id AND oir.reporter_id = ? AND oir.status IN ('open','in_progress')
    $where_clause
    ORDER BY bo.created_at DESC
    LIMIT ? OFFSET ?
";

$orders_params = array_merge([$agent_id, $agent_id, $agent_id, $agent_id], $params, [$limit, $offset]);
$orders_types = "iiii" . $types . "ii";

$stmt = $db->prepare($orders_query);
$stmt->bind_param($orders_types, ...$orders_params);
$stmt->execute();
$orders_rs = $stmt->get_result();

$orders = [];
while ($row = $orders_rs->fetch_assoc()) { $orders[] = $row; }

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN LOWER(bo.status) IN ('success','completed','delivered') THEN 1 ELSE 0 END) as successful_orders,
        SUM(CASE WHEN LOWER(bo.status) = 'processing' THEN 1 ELSE 0 END) as processing_orders,
        SUM(CASE WHEN LOWER(bo.status) = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN LOWER(bo.status) IN ('failed','cancelled') THEN 1 ELSE 0 END) as failed_orders,
        SUM(
            CASE
                WHEN LOWER(bo.status) IN ('processing','success','completed','delivered') THEN
                    CASE WHEN bo.agent_id > 0 AND (bo.user_id IS NULL OR bo.user_id != bo.agent_id) THEN bo.amount ELSE COALESCE(NULLIF(bo.agent_cost, 0), NULLIF(pp.price, 0), bo.amount, dp.price, 0) END
                ELSE 0
            END
        ) as total_revenue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'agent'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    WHERE (bo.agent_id = ? OR bo.user_id = ?)
";

$stmt = $db->prepare($stats_query);
$stmt->bind_param("iii", $agent_id, $agent_id, $agent_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];

$stats_defaults = [
    'total_orders'       => 0,
    'successful_orders'  => 0,
    'processing_orders'  => 0,
    'pending_orders'     => 0,
    'failed_orders'      => 0,
    'total_revenue'      => 0.0,
];

foreach ($stats_defaults as $key => $defaultValue) {
    $value = $stats[$key] ?? $defaultValue;
    if (!is_numeric($value)) {
        $value = $defaultValue;
    }
    $stats[$key] = $value + 0;
}

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
    <title>Order Histories - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/mobile-enhancements.js')); ?>""></script>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <?php renderAgentSidebar(); ?>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-history"></i></div>
                    <div class="breadcrumb-item">Transaction</div>
                    <div class="breadcrumb-item active">Histories</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i id="theme-icon" class="fas fa-moon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php
                                $displayName = (string)($current_user['full_name'] ?? $_SESSION['username'] ?? 'A');
                                echo strtoupper(substr(trim($displayName), 0, 1));
                            ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($displayName); ?></div>
                            <div class="user-role">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    <div id="userDropdown" class="user-dropdown-menu">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <hr class="dropdown-divider">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Order Histories</h1>
                <p class="page-subtitle">Track all your data bundle orders including AT iShare, MTN UP2U, and Telecel packages.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Summary Stats -->
            <div class="stats-grid responsive-stats-grid">
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
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format((int)($stats['processing_orders'] ?? 0)); ?></div>
                        <div class="stat-label">Processing</div>
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
                        <div class="stat-value"><?php echo CURRENCY . number_format((float)($stats['total_revenue'] ?? 0), 2); ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="widget">
                <div class="widget-header">
                    <h3>Filter Orders</h3>
                </div>
                <div class="widget-body">
                    <form method="GET" class="responsive-filter-form">
                        <div class="filter-row">
                            <div class="filter-group">
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
                            <div class="filter-group">
                                <label for="status">Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="successful" <?php echo in_array($status_filter, ['successful', 'success'], true) ? 'selected' : ''; ?>>Successful</option>
                                    <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="date_from">From Date</label>
                                <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="date_to">To Date</label>
                                <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="histories.php" class="btn btn-secondary">
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
                    <h3>Order History</h3>
                    <div class="widget-actions">
                        <span class="text-muted">Showing <?php echo count($orders); ?> of <?php echo number_format($total_orders); ?> orders</span>
                    </div>
                </div>
                <div class="widget-body">
                    <?php if (empty($orders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Orders Found</h3>
                            <p>You haven't made any orders yet or no orders match your filters.</p>
                            <a href="dashboard.php" class="btn btn-primary">Start Selling</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table mobile-responsive-orders-table">
                                <thead>
                                    <tr>
                                         <th>Order ID</th>
                                         <th>Network</th>
                                         <th>Package</th>
                                         <th>Beneficiary</th>
                                         <th>Amount</th>
                                         <th>Balance Remaining</th>
                                         <th>Profit</th>
                                         <th>Status</th>
                                         <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <?php $display_network_name = detectGhanaNetworkLabel($order['network_name'] ?? '', $order['beneficiary_number'] ?? ''); ?>
                                        <tr>
                                            <td data-label="Order ID">
                                                <span class="order-id">#<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                                <div class="text-muted">
                                                    <small>Placed: <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Network">
                                                <div class="network-badge">
                                                    <span class="network-indicator" style="background-color: <?php echo htmlspecialchars($order['network_color'] ?? '#007bff'); ?>"></span>
                                                    <?php echo htmlspecialchars($display_network_name); ?>
                                                </div>
                                            </td>
                                            <td data-label="Package">
                                                <div class="package-info">
                                                    <div class="package-name"><?php echo htmlspecialchars($order['package_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($order['data_size']); ?> &middot; <?php echo intval($order['validity_days']); ?> days</small>
                                                </div>
                                            </td>
                                            <td data-label="Beneficiary">
                                                <span class="phone-number"><?php echo htmlspecialchars($order['beneficiary_number']); ?></span>
                                            </td>
                                            <td data-label="Amount">
                                                <span class="amount"><?php echo CURRENCY . number_format($order['agent_price'], 2); ?></span>
                                            </td>
                                            <td data-label="Balance Remaining">
                                                <?php if (isset($order['balance_after']) && $order['balance_after'] !== null): ?>
                                                    <span class="amount text-muted"><?php echo CURRENCY . number_format((float)$order['balance_after'], 2); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">&mdash;</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Profit">
                                                <span class="amount text-success"><?php echo CURRENCY . number_format((float) ($order['order_profit'] ?? 0), 2); ?></span>
                                            </td>
                                            <?php $status_info = getOrderStatusDisplay($order['status']); ?>
                                            <td data-label="Status">
                                                <span class="badge" style="background-color: <?php echo $status_info['color']; ?>; color: white; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem;" title="<?php echo htmlspecialchars($status_info['description']); ?>">
                                                    <i class="fas <?php echo $status_info['icon']; ?>"></i> <?php echo $status_info['label']; ?>
                                                </span>
                                            </td>

                                            <?php
                                                $hasOpenIssue = !empty($order['has_open_issue']);
                                                $orderAgeMinutes = max(0, floor((time() - strtotime($order['created_at'])) / 60));
                                                $reportBlockedReason = '';
                                                if ($hasOpenIssue) {
                                                    $reportBlockedReason = 'You already reported this order. Support is reviewing it.';
                                                } elseif (in_array($order['status'], ['failed', 'cancelled'], true)) {
                                                    $reportBlockedReason = 'Failed/cancelled orders cannot be reported.';
                                                } elseif ($orderAgeMinutes < $order_report_delay_minutes) {
                                                    $minsLeft = max(1, $order_report_delay_minutes - $orderAgeMinutes);
                                                    $reportBlockedReason = "Wait {$minsLeft} more minute(s) before reporting.";
                                                }
                                                $canReportOrder = $reportBlockedReason === '';
                                                $orderPayload = [
                                                    'id' => (int) $order['id'],
                                                    'reference' => $order['order_reference'] ?? '',
                                                    'package' => $order['package_name'],
                                                    'network' => $display_network_name,
                                                    'phone' => $order['beneficiary_number'],
                                                    'status' => $status_info['label'],
                                                    'raw_status' => $order['status'],
                                                    'amount' => (float) ($order['agent_price'] ?? 0),
                                                    'amount_formatted' => CURRENCY . number_format((float) ($order['agent_price'] ?? 0), 2),
                                                    'profit' => (float) ($order['order_profit'] ?? 0),
                                                    'profit_formatted' => CURRENCY . number_format((float) ($order['order_profit'] ?? 0), 2),
                                                    'created_at' => date('M j, Y g:i A', strtotime($order['created_at']))
                                                ];
                                                $orderPayloadJson = htmlspecialchars(json_encode($orderPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <td data-label="Actions">
                                                <div class="action-buttons order-actions">
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            data-order-id="<?php echo (int) $order['id']; ?>"
                                                            onclick="viewOrderDetails(this)"
                                                            title="View details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (strtolower((string) ($order['status'] ?? '')) === 'failed'): ?>
                                                        <button class="btn btn-sm btn-outline-warning" onclick="retryOrder(this, <?php echo (int) $order['id']; ?>)" title="Retry order (no extra charge)">
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
        <p class="text-muted" style="margin-bottom: 0.5rem;">Let support know which order is stuck so we can act.</p>
        <div id="orderIssueDetails" class="order-issue-modal__details"></div>
        <div id="orderIssueFeedback" class="order-issue-modal__feedback"></div>
        <form id="orderIssueForm">
            <div class="form-group">
                <label for="orderIssueMessage">Issue description</label>
                <textarea id="orderIssueMessage" class="form-control" rows="4" placeholder="Example: Sent Telecel bundle to 020... 25 mins ago, not delivered." required></textarea>
            </div>
            <button type="submit" id="orderIssueSubmit" class="btn btn-primary w-100">Submit Report</button>
        </form>
    </div>
</div>

<!-- Order Details Modal -->
<div id="orderModal" class="modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="orderModalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="orderModalTitle">Order Details</h3>
            <button class="modal-close" onclick="closeOrderModal()">&times;</button>
        </div>
        <div class="modal-body" id="orderDetails">
            <!-- Order details will be loaded here -->
        </div>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
            });
        }
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
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        if (!dropdown || !toggle) {
            return;
        }
        const isOpen = dropdown.classList.toggle('show');
        toggle.classList.toggle('open', isOpen);
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
            toggle.classList.remove('open');
        }
    });

    const orderModal = document.getElementById('orderModal');
    const orderDetailsContainer = document.getElementById('orderDetails');

    function openOrderModal() {
        if (!orderModal) {
            return;
        }
        orderModal.classList.add('modal--visible');
        orderModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function viewOrderDetails(button) {
        if (!orderModal || !orderDetailsContainer) {
            return;
        }

        const orderId = button.getAttribute('data-order-id');
        if (!orderId) {
            alert('Order reference missing.');
            return;
        }

        openOrderModal();
        orderDetailsContainer.innerHTML = '<div class="loading">Loading order details...</div>';

        fetch(`get_order_details.php?order_id=${encodeURIComponent(orderId)}`)
            .then(response => response.json())
            .then(payload => {
                if (!payload.success) {
                    throw new Error(payload.message || 'Unable to load order.');
                }
                const order = payload.data;
                const status = order.status || 'Pending';
                const statusBadgeClass = /success|complete|deliver/i.test(status) ? 'badge-success'
                    : /fail|cancel/i.test(status) ? 'badge-danger'
                    : 'badge-warning';

                const amountFormatted = typeof order.amount === 'number'
                    ? '<?php echo CURRENCY; ?>' + Number(order.amount).toFixed(2)
                    : order.amount;
                const profitFormatted = typeof order.order_profit === 'number'
                    ? '<?php echo CURRENCY; ?>' + Number(order.order_profit).toFixed(2)
                    : (order.order_profit || 'N/A');

                orderDetailsContainer.innerHTML = `
                    <div class="order-detail-item"><strong>Order ID:</strong> #${String(order.id).padStart(6, '0')}</div>
                    <div class="order-detail-item"><strong>Status:</strong> <span class="badge ${statusBadgeClass}">${status}</span></div>
                    <div class="order-detail-item"><strong>Reference:</strong> ${order.order_reference || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Network:</strong> ${order.network_name || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Package:</strong> ${order.package_name || 'N/A'} (${order.data_size || 'N/A'})</div>
                    <div class="order-detail-item"><strong>Amount:</strong> ${amountFormatted || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Profit:</strong> ${profitFormatted || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Recipient:</strong> ${order.beneficiary_number || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Customer:</strong> ${order.customer_name || 'N/A'} (${order.customer_email || 'N/A'})</div>
                    <div class="order-detail-item"><strong>Transaction Ref:</strong> ${order.transaction_reference || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Payment Method:</strong> ${order.payment_method || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Created:</strong> ${order.created_at || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Updated:</strong> ${order.updated_at || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Delivered:</strong> ${order.delivered_at || 'N/A'}</div>
                `;

            })
            .catch(err => {
                console.error(err);
                orderDetailsContainer.innerHTML = `<div class="alert alert-danger">Unable to load order details. ${err.message || ''}</div>`;
            });
    }
    
    function closeOrderModal() {
        if (!orderModal) {
            return;
        }
        orderModal.classList.remove('modal--visible');
        orderModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }
    
    function retryOrder(button, orderId) {
        const retrySettings = window.ORDER_RETRY_SETTINGS || {};
        const apiUrl = retrySettings.apiUrl || 'retry_order.php';
        const csrfToken = retrySettings.csrfToken || '';

        if (!orderId) {
            alert('Invalid order selected for retry.');
            return;
        }

        if (!csrfToken) {
            alert('Session security token missing. Please refresh and try again.');
            return;
        }

        if (!confirm('Retry this failed order now? No additional wallet deduction will be made.')) {
            return;
        }

        const btn = button && button.tagName ? button : null;
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                order_id: Number(orderId),
                csrf_token: csrfToken
            })
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.success) {
                    const message = payload.message || 'Retry request failed.';
                    throw new Error(message);
                }
                alert(payload.message || 'Retry submitted successfully.');
                window.location.reload();
            })
            .catch((error) => {
                alert(error.message || 'Retry request failed.');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            });
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (orderModal && orderModal.classList.contains('modal--visible') && event.target === orderModal) {
            closeOrderModal();
        }
    }

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
        
        // Initialize mobile enhancements for tables
        if (typeof MobileEnhancements !== 'undefined') {
            new MobileEnhancements();
        }
    });
</script>

<script>
window.ORDER_ESCALATION_SETTINGS = {
    apiUrl: '../api/order_issues.php',
    csrfToken: '<?php echo $order_issue_token; ?>',
    whatsappNumber: '<?php echo $order_report_whatsapp_international; ?>',
    minDelayMinutes: <?php echo (int) $order_report_delay_minutes; ?>,
    role: 'agent'
};
</script>
<script>
window.ORDER_RETRY_SETTINGS = {
    apiUrl: 'retry_order.php',
    csrfToken: '<?php echo $order_issue_token; ?>'
};
</script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/order-escalation.js')); ?>""></script>

<style>
/* Responsive Styles for Data Histories Page */
@media (min-width: 769px) {
    html, body, .dashboard-wrapper, .main-content {
        overflow-x: hidden !important;
    }
    .main-content {
        width: calc(100% - 250px) !important;
        max-width: calc(100% - 250px) !important;
    }
    .mobile-responsive-orders-table th,
    .mobile-responsive-orders-table td {
        padding: 10px 8px !important;
        font-size: 0.9rem !important;
    }
    .table-responsive {
        overflow-x: hidden !important;
    }
    .mobile-responsive-orders-table {
        min-width: 100% !important;
        width: 100% !important;
    }
}

/* Header + profile actions alignment */
.dashboard-header .header-actions {
    margin-right: 0;
    gap: 0.75rem;
}

.dashboard-header .user-dropdown-toggle {
    min-width: 220px;
}

.dashboard-header .user-dropdown-menu {
    right: 0;
    left: auto;
}

.dashboard-header .user-dropdown-toggle .user-info {
    display: flex !important;
    min-width: 0;
    flex-direction: column;
}

.dashboard-header .user-dropdown-toggle > div:not(.user-avatar),
.dashboard-header .user-dropdown-toggle > span:not(.user-avatar) {
    display: block !important;
}

.dashboard-header .user-dropdown-toggle .user-name {
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dashboard-header .user-dropdown-toggle .user-role {
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Cleaner widget structure */
.widget-header h3,
.widget-header .widget-title {
    margin: 0;
}

.widget-actions {
    color: var(--text-muted);
    font-size: 0.9rem;
}

/* Better table readability on desktop */
.mobile-responsive-orders-table td {
    vertical-align: top;
}

.order-id {
    font-weight: 700;
    letter-spacing: 0.02em;
}

/* Filter Form Responsiveness */
.responsive-filter-form .filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: end;
}

.responsive-filter-form .filter-group {
    flex: 1;
    min-width: 150px;
}

.responsive-filter-form .filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: end;
}

/* Responsive Stats Grid */
.responsive-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.order-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.45rem;
    margin-bottom: 0;
}

.order-actions .btn {
    width: 40px;
    height: 40px;
    min-width: 40px;
    min-height: 40px;
    padding: 0;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-width: 1px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}

.order-actions .btn i {
    font-size: 0.95rem;
}

.order-actions .btn-outline-primary {
    background: rgba(59, 130, 246, 0.1);
    color: #2563eb;
    border-color: rgba(37, 99, 235, 0.22);
}

.order-actions .btn-outline-warning {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
    border-color: rgba(180, 83, 9, 0.22);
}

.order-actions .btn-outline-danger {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border-color: rgba(220, 38, 38, 0.22);
}

.order-actions .btn-outline-success {
    background: rgba(34, 197, 94, 0.1);
    color: #15803d;
    border-color: rgba(21, 128, 61, 0.22);
}

.order-actions .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 22px rgba(15, 23, 42, 0.12);
}

/* Mobile Responsive Orders Table */
@media (max-width: 991px) {
    .mobile-responsive-orders-table {
        font-size: 0.875rem;
    }
    
    .mobile-responsive-orders-table th,
    .mobile-responsive-orders-table td {
        padding: 0.5rem 0.25rem;
        vertical-align: middle;
    }
    
    .mobile-responsive-orders-table .badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
    
    .action-buttons {
        display: flex;
        gap: 0.25rem;
    }
    
    .action-buttons .btn {
        padding: 0.25rem 0.5rem;
    }

    .order-actions {
        gap: 0.35rem;
    }

    .order-actions .btn {
        width: 36px;
        height: 36px;
        min-width: 36px;
        min-height: 36px;
        padding: 0;
        border-radius: 10px;
        box-shadow: none;
    }
}

@media (max-width: 767px) {
    .dashboard-header .header-actions {
        margin-left: auto;
        gap: 0.5rem;
    }

    .dashboard-header .user-dropdown-toggle {
        min-width: 154px;
        padding: 0.45rem 0.65rem;
        gap: 0.45rem;
    }

    .dashboard-header .user-avatar {
        width: 30px;
        height: 30px;
        font-size: 0.82rem;
    }

    .dashboard-header .user-dropdown-toggle .user-name {
        font-size: 0.78rem;
    }

    .dashboard-header .user-dropdown-toggle .user-role {
        font-size: 0.66rem;
    }

    .dashboard-header .dropdown-arrow {
        display: inline-flex !important;
    }

    .dashboard-header .user-dropdown-menu {
        min-width: 190px;
    }

    .widget-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .widget-actions {
        width: 100%;
    }

    /* Filter Form Mobile Layout */
    .responsive-filter-form .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .responsive-filter-form .filter-group,
    .responsive-filter-form .filter-actions {
        width: 100%;
    }
    
    .responsive-filter-form .filter-actions {
        margin-top: 1rem;
        justify-content: center;
    }
    
    /* Stats Grid Mobile Layout */
    .responsive-stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }
    
    /* Mobile Table Card Layout */
    .mobile-responsive-orders-table {
        border: 0;
    }
    
    .mobile-responsive-orders-table thead {
        border: none;
        clip: rect(0 0 0 0);
        height: 1px;
        margin: -1px;
        overflow: hidden;
        padding: 0;
        position: absolute;
        width: 1px;
    }
    
    .mobile-responsive-orders-table tbody,
    .mobile-responsive-orders-table tr,
    .mobile-responsive-orders-table td {
        display: block;
    }
    
    .mobile-responsive-orders-table tr {
        border: 1px solid var(--border-color);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
        background: var(--bg-primary);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .mobile-responsive-orders-table td {
        border: none;
        padding: 0.5rem 0;
        position: relative;
        padding-left: 40% !important;
        text-align: right;
    }
    
    .mobile-responsive-orders-table td:before {
        content: attr(data-label) ":";
        position: absolute;
        left: 0;
        width: 35%;
        padding-right: 0.5rem;
        white-space: nowrap;
        font-weight: 600;
        color: var(--text-muted);
        text-align: left;
    }
    
    .mobile-responsive-orders-table td:first-child {
        border-top: 0;
        font-weight: bold;
        background: var(--bg-secondary);
        margin: -1rem -1rem 0.5rem -1rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem 0.5rem 0 0;
        text-align: center;
    }
    
    .mobile-responsive-orders-table td:first-child:before {
        display: none;
    }
    
    /* Specific mobile adaptations */
    .network-badge {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        justify-content: flex-end;
    }
    
    .network-indicator {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    
    .package-info {
        text-align: right;
    }
    
    .package-name {
        font-weight: 500;
        margin-bottom: 0.25rem;
    }
    
    .action-buttons {
        justify-content: flex-end;
    }

    .mobile-responsive-orders-table td[data-label="Actions"] {
        padding-top: 0.75rem;
    }

    .mobile-responsive-orders-table td[data-label="Actions"]:before {
        margin-bottom: 0.45rem;
    }

    .mobile-responsive-orders-table td[data-label="Actions"] .order-actions {
        display: inline-flex;
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: 0.4rem;
    }
    
    .date-info {
        text-align: right;
    }
    
    .amount {
        font-weight: bold;
        color: var(--accent-green);
    }
}

@media (max-width: 575px) {
    /* Ultra-small screens */
    .responsive-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .page-title h1 {
        font-size: 1.75rem;
    }
    
    .dashboard-content {
        padding: 1rem;
    }
    
    .widget {
        margin-bottom: 1rem;
    }
    
    .responsive-filter-form .filter-actions .btn {
        flex: 1;
        min-width: 0;
    }
    
    .pagination-wrapper {
        padding: 1rem;
    }
    
    .pagination {
        flex-direction: column;
        gap: 0.5rem;
        align-items: center;
    }
    
    .pagination-btn {
        padding: 0.5rem 1rem;
        width: 200px;
        text-align: center;
    }
}

/* Modal Responsiveness */
@media (max-width: 767px) {
    .modal-content {
        margin: 1rem;
        width: calc(100% - 2rem);
    }
    
    .modal-header {
        padding: 1rem;
    }
    
    .modal-body {
        padding: 1rem;
    }
}

/* Dark theme adjustments for mobile */
[data-theme="dark"] .mobile-responsive-orders-table tr {
    background: var(--dark-bg-secondary);
    border-color: var(--dark-border-color);
}

[data-theme="dark"] .mobile-responsive-orders-table td:first-child {
    background: var(--dark-bg-tertiary);
}

@media (max-width: 767px) {
    body,
    .dashboard-wrapper,
    .main-content {
        overflow-x: hidden;
    }

    .table-responsive {
        overflow-x: hidden;
    }

    .mobile-responsive-orders-table tr {
        overflow-wrap: anywhere;
    }

    .mobile-responsive-orders-table td {
        padding-left: 0 !important;
        text-align: left;
        word-break: break-word;
    }

    .mobile-responsive-orders-table td:before {
        position: static;
        width: auto;
        display: block;
        padding-right: 0;
        white-space: normal;
        margin-bottom: 0.25rem;
    }

    .mobile-responsive-orders-table td:first-child {
        text-align: left;
    }

    .network-badge,
    .package-info,
    .date-info {
        text-align: left;
        justify-content: flex-start;
    }

    .action-buttons {
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .mobile-responsive-orders-table td[data-label="Actions"] .order-actions {
        justify-content: flex-start;
    }

    .mobile-responsive-orders-table td[data-label="Actions"] .order-actions .btn {
        width: 34px;
        height: 34px;
        min-width: 34px;
        min-height: 34px;
        border-radius: 9px;
    }

    .mobile-responsive-orders-table td[data-label="Actions"] .order-actions .btn i {
        font-size: 0.82rem;
    }
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


