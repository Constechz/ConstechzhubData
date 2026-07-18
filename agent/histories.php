<?php
require_once '../config/config.php';
require_once '../includes/order_status.php';

// Require agent role
requireRole('agent');

// Get agent ID
$agent_id = $_SESSION['user_id'];
$current_user = getCurrentUser();

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
$where_conditions = ["(bo.agent_id = ? OR bo.user_id = ?)"];
$params = [$agent_id, $agent_id];
$types = "ii";

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
           COALESCE(acp.custom_price, pp.price, 0) as agent_price,
           CASE WHEN oir.id IS NULL THEN 0 ELSE 1 END AS has_open_issue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN transactions t ON t.id = bo.transaction_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ?
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'agent'
    LEFT JOIN order_issue_reports oir ON oir.order_id = bo.id AND oir.reporter_id = ? AND oir.status IN ('open','in_progress')
    $where_clause
    ORDER BY bo.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $agent_id;
$params[] = $agent_id;
$params[] = $limit;
$params[] = $offset;
$types .= "iiii";

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
        SUM(CASE WHEN LOWER(bo.status) IN ('success','completed','delivered') THEN 1 ELSE 0 END) as successful_orders,
        SUM(CASE WHEN LOWER(bo.status) IN ('pending','processing') THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN LOWER(bo.status) IN ('failed','cancelled') THEN 1 ELSE 0 END) as failed_orders,
        SUM(CASE WHEN LOWER(bo.status) IN ('success','completed','delivered') THEN COALESCE(acp.custom_price, pp.price, 0) ELSE 0 END) as total_revenue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ?
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'agent'
    WHERE (bo.agent_id = ? OR bo.user_id = ?)
";

$stmt = $db->prepare($stats_query);
$stmt->bind_param("iii", $agent_id, $agent_id, $agent_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc() ?: [];

$stats_defaults = [
    'total_orders'       => 0,
    'successful_orders'  => 0,
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
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Services</div>
                <div class="nav-item">
                    <a href="at-business.php" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        AT Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="mtn-business.php" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        MTN Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="afa-registration.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        AFA Registration
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php" class="nav-link">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="telecel-business.php" class="nav-link">
                        <i class="fas fa-signal"></i>
                        Telecel Business
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Transaction</div>
                <div class="nav-item">
                    <a href="transactions.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        Transactions
                    </a>
                </div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link active">
                        <i class="fas fa-history"></i>
                        Data Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Operations</div>
                <div class="nav-item">
                    <a href="customer_topup.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        Customer Top-up
                    </a>
                </div>
                <div class="nav-item">
                    <a href="support.php" class="nav-link">
                        <i class="fas fa-life-ring"></i>
                        Support
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Business</div>
                <div class="nav-item">
                    <a href="pricing.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        Custom Pricing
                    </a>
                </div>
                <div class="nav-item">
                    <a href="paystack.php" class="nav-link">
                        <i class="fas fa-credit-card"></i>
                        Payment Settings
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Users</div>
                <div class="nav-item">
                    <a href="customers.php" class="nav-link">
                        <i class="fas fa-user-friends"></i>
                        Customers
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Commission</div>
                <div class="nav-item">
                    <a href="commission.php" class="nav-link">
                        <i class="fas fa-percentage"></i>
                        Commission
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        Settings
                    </a>
                </div>
                <div class="nav-item">
                    <a href="api-access.php" class="nav-link">
                        <i class="fas fa-key"></i>
                        API Access
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="breadcrumb-item">Transaction</div>
                    <div class="breadcrumb-item active">Histories</div>
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
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('agents'); ?>


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
                                    <option value="success" <?php echo $status_filter === 'success' ? 'selected' : ''; ?>>Success</option>
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
                                        <th>Status</th>
                                        <th class="d-none d-md-table-cell">Date</th>
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
                                            <td data-label="Beneficiary">
                                                <span class="phone-number"><?php echo htmlspecialchars($order['beneficiary_number']); ?></span>
                                            </td>
                                            <td data-label="Amount">
                                                <span class="amount"><?php echo CURRENCY . number_format($order['agent_price'], 2); ?></span>
                                            </td>
                                            <?php
                                                $status_info = getOrderStatusDisplay($order['status']);
                                                $raw_status = strtolower($order['status']);
                                                if (in_array($raw_status, ['success', 'completed', 'delivered'], true)) {
                                                    $status_class = 'status-badge success';
                                                } elseif (in_array($raw_status, ['pending', 'processing'], true)) {
                                                    $status_class = 'status-badge pending';
                                                } else {
                                                    $status_class = 'status-badge failed';
                                                }
                                            ?>
                                            <td data-label="Status">
                                                <span class="<?php echo $status_class; ?>" title="<?php echo htmlspecialchars($status_info['description']); ?>">
                                                    <i class="fas <?php echo $status_info['icon']; ?>"></i> <?php echo $status_info['label']; ?>
                                                </span>
                                            </td>
                                            <td class="d-none d-md-table-cell" data-label="Date">
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
                                                    'network' => $order['network_name'],
                                                    'phone' => $order['beneficiary_number'],
                                                    'status' => $status_info['label'],
                                                    'raw_status' => $order['status'],
                                                    'amount' => (float) ($order['agent_price'] ?? 0),
                                                    'amount_formatted' => CURRENCY . number_format((float) ($order['agent_price'] ?? 0), 2),
                                                    'created_at' => date('M j, Y g:i A', strtotime($order['created_at']))
                                                ];
                                                $orderPayloadJson = htmlspecialchars(json_encode($orderPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <td data-label="Actions">
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            data-order-id="<?php echo (int) $order['id']; ?>"
                                                            onclick="viewOrderDetails(this)"
                                                            title="View details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($order['status'] === 'failed'): ?>
                                                        <button class="btn btn-sm btn-outline-warning" onclick="retryOrder(<?php echo $order['id']; ?>)" title="Retry order">
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

                orderDetailsContainer.innerHTML = `
                    <div class="order-detail-item"><strong>Order ID:</strong> #${String(order.id).padStart(6, '0')}</div>
                    <div class="order-detail-item"><strong>Status:</strong> <span class="badge ${statusBadgeClass}">${status}</span></div>
                    <div class="order-detail-item"><strong>Reference:</strong> ${order.order_reference || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Network:</strong> ${order.network_name || 'N/A'}</div>
                    <div class="order-detail-item"><strong>Package:</strong> ${order.package_name || 'N/A'} (${order.data_size || 'N/A'})</div>
                    <div class="order-detail-item"><strong>Amount:</strong> ${amountFormatted || 'N/A'}</div>
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
    
    function retryOrder(orderId) {
        if (confirm('Are you sure you want to retry this order?')) {
            // Implement retry logic
            alert('Retry functionality will be implemented');
        }
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
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/order-escalation.js')); ?>"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

/* Apply Outfit font to container */
.dashboard-content, .breadcrumb, .widget, .stat-card {
    font-family: 'Outfit', 'Inter', sans-serif !important;
}

/* Premium Title Block */
.page-title {
    margin-bottom: 2rem;
}
.page-title h1 {
    font-size: 2.25rem;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: -0.5px;
    margin-bottom: 0.5rem;
}
.page-subtitle {
    font-size: 1rem;
    color: var(--text-secondary);
    opacity: 0.85;
}

/* Premium Card & Widget Styles */
.widget {
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--border-color, #eaeaea);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.02);
    margin-bottom: 2rem;
    transition: all 0.3s ease;
    overflow: hidden;
}
[data-theme="dark"] .widget {
    background: #1e1b2f;
    border-color: rgba(255, 255, 255, 0.06);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.widget-header {
    border-bottom: 1px solid var(--border-color, #eaeaea);
    padding: 1.25rem 1.5rem;
    background: rgba(84, 19, 136, 0.02);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
[data-theme="dark"] .widget-header {
    border-bottom-color: rgba(255, 255, 255, 0.06) !important;
    background: rgba(255, 255, 255, 0.01);
}

.widget-header h3 {
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

/* Stats Grid & Cards */
.responsive-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}

.stat-card {
    background: var(--card-bg, #ffffff);
    border: 1px solid var(--border-color, #eaeaea);
    border-radius: 16px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.02);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s ease;
}
[data-theme="dark"] .stat-card {
    background: #1e1b2f;
    border-color: rgba(255, 255, 255, 0.06);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(84, 19, 136, 0.08);
}
[data-theme="dark"] .stat-card:hover {
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    background: rgba(84, 19, 136, 0.08);
    color: #541388;
}
[data-theme="dark"] .stat-icon {
    background: rgba(162, 105, 220, 0.15);
    color: #b388eb;
}

/* Colored Stats card variants */
.stat-card.success .stat-icon {
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
}
.stat-card.warning .stat-icon {
    background: rgba(241, 196, 15, 0.1);
    color: #f1c40f;
}
.stat-card.danger .stat-icon {
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
}
.stat-card.info .stat-icon {
    background: rgba(52, 152, 219, 0.1);
    color: #3498db;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 0.25rem;
}

/* Filters UI */
.responsive-filter-form .filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
    align-items: end;
}
.responsive-filter-form .filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.responsive-filter-form label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.responsive-filter-form .form-control {
    width: 100%;
    border-radius: 10px;
    padding: 0.65rem 1rem;
    border: 1px solid var(--border-color, #eaeaea);
    background: var(--bg-secondary, #f9f9f9);
    color: var(--text-primary);
    transition: all 0.25s ease;
}
[data-theme="dark"] .responsive-filter-form .form-control {
    background: #120e24;
    border-color: rgba(255, 255, 255, 0.08);
}
.responsive-filter-form .form-control:focus {
    border-color: #541388;
    box-shadow: 0 0 0 3px rgba(84, 19, 136, 0.15);
    background: var(--bg-primary, #ffffff);
}
.responsive-filter-form .filter-actions {
    display: flex;
    gap: 0.75rem;
}
.responsive-filter-form .filter-actions .btn {
    padding: 0.65rem 1.25rem;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.25s ease;
}

/* Table Design */
.table-responsive {
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border-color);
}
[data-theme="dark"] .table-responsive {
    border-color: rgba(255, 255, 255, 0.06);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    background: rgba(84, 19, 136, 0.03);
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 1.15rem 1.25rem;
    border-bottom: 2px solid var(--border-color);
    text-align: left;
}
[data-theme="dark"] .table th {
    background: rgba(255, 255, 255, 0.02);
    border-bottom-color: rgba(255, 255, 255, 0.06);
}

.table td {
    padding: 1.15rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
[data-theme="dark"] .table td {
    border-bottom-color: rgba(255, 255, 255, 0.04);
}

.table tbody tr {
    transition: background-color 0.2s ease;
}

.table tbody tr:hover {
    background-color: rgba(84, 19, 136, 0.02);
}
[data-theme="dark"] .table tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.01);
}

/* Row-specific column details */
.order-id {
    font-weight: 700;
    color: #541388;
}
[data-theme="dark"] .order-id {
    color: #b388eb;
}

.network-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

.network-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
}

.package-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95rem;
}

.phone-number {
    font-family: monospace;
    font-size: 0.95rem;
    font-weight: 500;
    color: var(--text-primary);
}

.amount {
    font-weight: 700;
    color: var(--text-primary);
    font-size: 1rem;
}

/* Premium Pill Badges for Order Statuses */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.8rem;
    border-radius: 30px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.status-badge.success {
    background: rgba(46, 204, 113, 0.12);
    color: #27ae60;
    border: 1px solid rgba(46, 204, 113, 0.2);
}
[data-theme="dark"] .status-badge.success {
    background: rgba(46, 204, 113, 0.18);
    color: #2ecc71;
}

.status-badge.pending {
    background: rgba(243, 156, 18, 0.12);
    color: #d35400;
    border: 1px solid rgba(243, 156, 18, 0.2);
}
[data-theme="dark"] .status-badge.pending {
    background: rgba(241, 196, 15, 0.18);
    color: #f1c40f;
}

.status-badge.failed {
    background: rgba(231, 76, 60, 0.12);
    color: #c0392b;
    border: 1px solid rgba(231, 76, 60, 0.2);
}
[data-theme="dark"] .status-badge.failed {
    background: rgba(231, 76, 60, 0.18);
    color: #ff7675;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.action-buttons .btn {
    border-radius: 50% !important;
    width: 32px !important;
    height: 32px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    transition: all 0.25s ease;
    border: 1px solid var(--border-color);
    background: transparent;
    cursor: pointer;
}

.btn-outline-primary { color: #541388; border-color: rgba(84, 19, 136, 0.3) !important; }
.btn-outline-primary:hover { background: #541388 !important; color: #fff !important; }

.btn-outline-warning { color: #f1c40f; border-color: rgba(241, 196, 15, 0.3) !important; }
.btn-outline-warning:hover { background: #f1c40f !important; color: #fff !important; }

.btn-outline-danger { color: #e74c3c; border-color: rgba(231, 76, 60, 0.3) !important; }
.btn-outline-danger:hover { background: #e74c3c !important; color: #fff !important; }

.btn-outline-success { color: #2ecc71; border-color: rgba(46, 204, 113, 0.3) !important; }
.btn-outline-success:hover { background: #2ecc71 !important; color: #fff !important; }

/* Pagination Styling */
.pagination-wrapper {
    padding: 1.25rem 1.5rem;
    border-top: 1px solid var(--border-color);
    background: rgba(84, 19, 136, 0.01);
}
[data-theme="dark"] .pagination-wrapper {
    border-top-color: rgba(255, 255, 255, 0.06);
}

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pagination-btn {
    padding: 0.55rem 1.25rem;
    border-radius: 30px;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.25s ease;
    background: var(--card-bg);
}

.pagination-btn:hover {
    background: #541388;
    color: #fff;
    border-color: #541388;
}

.pagination-info {
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* Modal Styling */
.modal {
    background: rgba(28, 24, 48, 0.5) !important;
    backdrop-filter: blur(8px);
}
.modal-content {
    background: var(--card-bg, #ffffff) !important;
    border: 1px solid var(--border-color);
    border-radius: 20px !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden;
}
[data-theme="dark"] .modal-content {
    background: #1e1b2f !important;
    border-color: rgba(255, 255, 255, 0.08);
}
.modal-header {
    border-bottom: 1px solid var(--border-color) !important;
    padding: 1.25rem 1.5rem !important;
    background: rgba(84, 19, 136, 0.02);
}
[data-theme="dark"] .modal-header {
    border-bottom-color: rgba(255, 255, 255, 0.06) !important;
}
.modal-close {
    font-size: 1.5rem;
    font-weight: 700;
    cursor: pointer;
    background: transparent;
    border: none;
    color: var(--text-secondary);
    transition: color 0.2s ease;
}
.modal-close:hover {
    color: #e74c3c;
}
.order-detail-item {
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.95rem;
}
[data-theme="dark"] .order-detail-item {
    border-bottom-color: rgba(255, 255, 255, 0.04);
}
.order-detail-item strong {
    color: var(--text-secondary);
    font-weight: 500;
}

/* Escalation Modal specifics */
.order-issue-modal {
    background: rgba(28, 24, 48, 0.5) !important;
    backdrop-filter: blur(8px);
}
.order-issue-modal__dialog {
    background: var(--card-bg, #ffffff) !important;
    border: 1px solid var(--border-color);
    border-radius: 20px !important;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15) !important;
    padding: 2rem !important;
}
[data-theme="dark"] .order-issue-modal__dialog {
    background: #1e1b2f !important;
    border-color: rgba(255, 255, 255, 0.08);
}

/* Empty state */
.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
}
.empty-state i {
    font-size: 3rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
}

/* Mobile Responsiveness & Stacking */
@media (max-width: 991px) {
    .responsive-filter-form .filter-row {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 767px) {
    .dashboard-content {
        padding: 1.25rem 1rem;
    }
    
    .responsive-stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }
    
    .stat-card {
        padding: 1rem;
        gap: 0.75rem;
    }
    
    .stat-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .stat-value {
        font-size: 1.35rem;
    }
    
    .responsive-filter-form .filter-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .responsive-filter-form .filter-actions {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .responsive-filter-form .filter-actions .btn {
        flex: 1;
    }
    
    /* Stacking Mobile Table Layout */
    .mobile-responsive-orders-table {
        border: none !important;
    }
    
    .mobile-responsive-orders-table thead {
        display: none !important;
    }
    
    .mobile-responsive-orders-table tbody,
    .mobile-responsive-orders-table tr,
    .mobile-responsive-orders-table td {
        display: block !important;
    }
    
    .mobile-responsive-orders-table tr {
        border: 1px solid var(--border-color) !important;
        border-radius: 14px !important;
        padding: 1.25rem !important;
        margin-bottom: 1.25rem !important;
        background: var(--card-bg) !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02) !important;
    }
    [data-theme="dark"] .mobile-responsive-orders-table tr {
        border-color: rgba(255, 255, 255, 0.06) !important;
        background: #1e1b2f !important;
    }
    
    .mobile-responsive-orders-table td {
        border: none !important;
        padding: 0.65rem 0 !important;
        position: relative !important;
        padding-left: 42% !important;
        text-align: right !important;
        font-size: 0.9rem !important;
    }
    
    .mobile-responsive-orders-table td:before {
        content: attr(data-label) ":";
        position: absolute !important;
        left: 0 !important;
        width: 38% !important;
        padding-right: 0.5rem !important;
        white-space: nowrap !important;
        font-weight: 600 !important;
        color: var(--text-secondary) !important;
        text-align: left !important;
    }
    
    .mobile-responsive-orders-table td:first-child {
        font-weight: bold !important;
        background: rgba(84, 19, 136, 0.03) !important;
        margin: -1.25rem -1.25rem 0.75rem -1.25rem !important;
        padding: 0.75rem 1.25rem !important;
        border-radius: 14px 14px 0 0 !important;
        text-align: center !important;
        padding-left: 1.25rem !important;
    }
    [data-theme="dark"] .mobile-responsive-orders-table td:first-child {
        background: rgba(255, 255, 255, 0.02) !important;
    }
    
    .mobile-responsive-orders-table td:first-child:before {
        display: none !important;
    }
    
    .network-badge, .package-info, .date-info, .action-buttons {
        justify-content: flex-end !important;
        text-align: right !important;
    }
    
    .action-buttons {
        gap: 0.5rem !important;
    }
}

@media (max-width: 480px) {
    .responsive-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .pagination {
        flex-direction: column;
        gap: 1rem;
    }
    
    .pagination-btn {
        width: 100%;
        text-align: center;
    }
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

