<?php
require_once '../config/config.php';

// Require agent role
requireRole('agent');

$gateway_label = getActivePaymentGateway() === 'moolre' ? 'Moolre' : 'Paystack';
$current_user = getCurrentUser();
$agent_id = $current_user['id'];

// Fetch filters
$selected_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$selected_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get agent name for search & backward compatibility
$agent_name = $current_user['full_name'];

// Count Query for Pagination (Robust & Backward Compatible)
$count_query = "
    SELECT COUNT(*) as total
    FROM transactions t
    WHERE (
        (t.user_id = ? AND t.transaction_type = 'topup')
        OR 
        (t.initiated_by_id = ?)
        OR
        (t.initiated_by_id IS NULL AND t.transaction_type = 'topup' AND t.description LIKE CONCAT('%', ?, '%') AND t.description NOT LIKE '%Approved manually%' AND t.description NOT LIKE '%failed manually%' AND t.description NOT LIKE '%Moses Sedodey%' AND t.description NOT LIKE '%by Moses%')
    )
";

$count_params = [$agent_id, $agent_id, $agent_name];
$count_types = 'iis';

if ($selected_type !== '') {
    if ($selected_type === 'agent_topup') {
        $count_query .= " AND t.user_id = ? AND t.initiated_by_id IS NULL";
        $count_params[] = $agent_id;
        $count_types .= 'i';
    } elseif ($selected_type === 'customer_topup') {
        $count_query .= " AND (t.initiated_by_id = ? OR (t.initiated_by_id IS NULL AND t.description LIKE CONCAT('%', ?, '%') AND t.description NOT LIKE '%Approved manually%' AND t.description NOT LIKE '%failed manually%' AND t.description NOT LIKE '%Moses Sedodey%' AND t.description NOT LIKE '%by Moses%'))";
        $count_params[] = $agent_id;
        $count_params[] = $agent_name;
        $count_types .= 'is';
    }
}

if ($selected_status !== '') {
    $count_query .= " AND t.status = ?";
    $count_params[] = $selected_status;
    $count_types .= 's';
}

if ($date_from !== '') {
    $count_query .= " AND DATE(t.created_at) >= ?";
    $count_params[] = $date_from;
    $count_types .= 's';
}

if ($date_to !== '') {
    $count_query .= " AND DATE(t.created_at) <= ?";
    $count_params[] = $date_to;
    $count_types .= 's';
}

if ($search !== '') {
    $count_query .= " AND (t.reference LIKE ? OR t.description LIKE ? OR t.paystack_reference LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_types .= 'sss';
}

$stmt = $db->prepare($count_query);
$stmt->bind_param($count_types, ...$count_params);
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);

// Fetch monetary transactions for this agent
$query = "
    SELECT t.id, t.transaction_type as type, t.amount, t.status, t.reference, t.payment_method, 
           t.description, t.created_at, t.paystack_reference, u.full_name as customer_name,
           COALESCE(t.balance_after, wt.balance_after) as balance_after,
           CASE 
               WHEN t.user_id = ? AND t.transaction_type = 'topup' AND t.initiated_by_id IS NULL THEN 'agent_topup'
               WHEN t.initiated_by_id = ? OR (t.initiated_by_id IS NULL AND t.transaction_type = 'topup' AND t.description LIKE CONCAT('%', ?, '%') AND t.description NOT LIKE '%Approved manually%' AND t.description NOT LIKE '%failed manually%' AND t.description NOT LIKE '%Moses Sedodey%' AND t.description NOT LIKE '%by Moses%') THEN 'customer_topup'
               ELSE t.transaction_type
           END as display_type
    FROM transactions t
    LEFT JOIN users u ON t.target_user_id = u.id
    LEFT JOIN wallet_transactions wt ON wt.reference = t.reference
    WHERE (
        (t.user_id = ? AND t.transaction_type = 'topup')
        OR 
        (t.initiated_by_id = ?)
        OR
        (t.initiated_by_id IS NULL AND t.transaction_type = 'topup' AND t.description LIKE CONCAT('%', ?, '%') AND t.description NOT LIKE '%Approved manually%' AND t.description NOT LIKE '%failed manually%' AND t.description NOT LIKE '%Moses Sedodey%' AND t.description NOT LIKE '%by Moses%')
    )
";

$params = [$agent_id, $agent_id, $agent_name, $agent_id, $agent_id, $agent_name];
$types = 'iisiis';

if ($selected_type !== '') {
    if ($selected_type === 'agent_topup') {
        $query .= " AND t.user_id = ? AND t.initiated_by_id IS NULL";
        $params[] = $agent_id;
        $types .= 'i';
    } elseif ($selected_type === 'customer_topup') {
        $query .= " AND (t.initiated_by_id = ? OR (t.initiated_by_id IS NULL AND t.description LIKE CONCAT('%', ?, '%') AND t.description NOT LIKE '%Approved manually%' AND t.description NOT LIKE '%failed manually%' AND t.description NOT LIKE '%Moses Sedodey%' AND t.description NOT LIKE '%by Moses%'))";
        $params[] = $agent_id;
        $params[] = $agent_name;
        $types .= 'is';
    }
}

if ($selected_status !== '') {
    $query .= " AND t.status = ?";
    $params[] = $selected_status;
    $types .= 's';
}

if ($date_from !== '') {
    $query .= " AND DATE(t.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $query .= " AND DATE(t.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($search !== '') {
    $query .= " AND (t.reference LIKE ? OR t.description LIKE ? OR t.paystack_reference LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$query .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $db->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions_rs = $stmt->get_result();

$transactions = [];
while ($row = $transactions_rs->fetch_assoc()) { 
    $transactions[] = $row; 
}

// Get summary stats for this agent
$stats_query = "
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN status = 'success' AND user_id = ? AND transaction_type = 'topup' AND initiated_by_id IS NULL THEN amount ELSE 0 END) as total_agent_topups,
        SUM(CASE WHEN status = 'success' AND (initiated_by_id = ? OR (initiated_by_id IS NULL AND transaction_type = 'topup' AND description LIKE CONCAT('%', ?, '%') AND description NOT LIKE '%Approved manually%' AND description NOT LIKE '%failed manually%' AND description NOT LIKE '%Moses Sedodey%' AND description NOT LIKE '%by Moses%')) THEN amount ELSE 0 END) as total_customer_topups,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
    FROM transactions
    WHERE (
        (user_id = ? AND transaction_type = 'topup')
        OR 
        (initiated_by_id = ?)
        OR
        (initiated_by_id IS NULL AND transaction_type = 'topup' AND description LIKE CONCAT('%', ?, '%') AND description NOT LIKE '%Approved manually%' AND description NOT LIKE '%failed manually%' AND description NOT LIKE '%Moses Sedodey%' AND description NOT LIKE '%by Moses%')
    )
    AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
";
$stmt = $db->prepare($stats_query);
$stmt->bind_param("iisiis", $agent_id, $agent_id, $agent_name, $agent_id, $agent_id, $agent_name);
$stmt->execute();
$stats_rs = $stmt->get_result();
$stats = $stats_rs->fetch_assoc() ?: [];

$stat_defaults = [
    'total_transactions'   => 0,
    'total_agent_topups'   => 0.0,
    'total_customer_topups'=> 0.0,
    'pending_count'        => 0,
    'success_count'        => 0,
    'failed_count'         => 0,
];
foreach ($stat_defaults as $statKey => $defaultValue) {
    $value = $stats[$statKey] ?? $defaultValue;
    if (!is_numeric($value)) {
        $value = $defaultValue;
    }
    $stats[$statKey] = $value + 0; // force numeric type (float/int)
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monetary Transactions - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
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
                    <div class="breadcrumb-item"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="breadcrumb-item">Transaction</div>
                    <div class="breadcrumb-item active">Monetary Transactions</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php" class="dropdown-item">
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

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Monetary Transactions</h1>
                <p class="page-subtitle">Track your wallet top-ups and customer payment transactions.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: rgba(139, 92, 246, 0.1); color: var(--brand-primary);">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY; ?> <?php echo number_format($stats['total_agent_topups'], 2); ?></div>
                        <div class="stat-label">Agent Top-ups (30 days)</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: rgba(115, 237, 63, 0.1); color: var(--accent-green);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY; ?> <?php echo number_format($stats['total_customer_topups'], 2); ?></div>
                        <div class="stat-label">Customer Top-ups (30 days)</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: rgba(230, 59, 44, 0.1); color: var(--accent-red);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['pending_count']); ?></div>
                        <div class="stat-label">Pending Transactions</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: rgba(34, 197, 94, 0.1); color: #22c55e;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['success_count']); ?></div>
                        <div class="stat-label">Successful Transactions</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card-body">
                    <form method="GET" action="transactions.php" class="filter-form">
                        <div class="row">
                            <div class="col-md-2">
                                <label class="form-label">Transaction Type</label>
                                <select name="type" class="form-control">
                                    <option value="">All Types</option>
                                    <option value="agent_topup" <?php echo $selected_type === 'agent_topup' ? 'selected' : ''; ?>>Agent Top-ups</option>
                                    <option value="customer_topup" <?php echo $selected_type === 'customer_topup' ? 'selected' : ''; ?>>Customer Top-ups</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="success" <?php echo $selected_status === 'success' ? 'selected' : ''; ?>>Success</option>
                                    <option value="pending" <?php echo $selected_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="failed" <?php echo $selected_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Reference, description..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary" style="width: 100%;">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Transaction Category Tabs -->
            <div class="transaction-tabs" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0px; flex-wrap: wrap;">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => '', 'page' => 1])); ?>" 
                   style="text-decoration: none; padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.95rem; color: <?php echo $selected_type === '' ? 'var(--brand-primary)' : 'var(--text-muted)'; ?>; border-bottom: 3px solid <?php echo $selected_type === '' ? 'var(--brand-primary)' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s ease;">
                    <i class="fas fa-list me-1"></i> All Transactions
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => 'agent_topup', 'page' => 1])); ?>" 
                   style="text-decoration: none; padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.95rem; color: <?php echo $selected_type === 'agent_topup' ? 'var(--brand-primary)' : 'var(--text-muted)'; ?>; border-bottom: 3px solid <?php echo $selected_type === 'agent_topup' ? 'var(--brand-primary)' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s ease;">
                    <i class="fas fa-wallet me-1"></i> My Wallet Funding (Inflows)
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['type' => 'customer_topup', 'page' => 1])); ?>" 
                   style="text-decoration: none; padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.95rem; color: <?php echo $selected_type === 'customer_topup' ? 'var(--brand-primary)' : 'var(--text-muted)'; ?>; border-bottom: 3px solid <?php echo $selected_type === 'customer_topup' ? 'var(--brand-primary)' : 'transparent'; ?>; margin-bottom: -2px; transition: all 0.2s ease;">
                    <i class="fas fa-users me-1"></i> Customer Distributions (Outflows)
                </a>
            </div>

            <!-- Transactions Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        Monetary Transaction History
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($transactions)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-money-bill-slash fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No transactions found</h5>
                            <p class="text-muted">
                                <?php if ($selected_type || $selected_status || $date_from || $date_to || $search): ?>
                                    Try adjusting your filters to see more results.
                                <?php else: ?>
                                    You haven't made any monetary transactions yet.
                                <?php endif; ?>
                            </p>
                            <?php if (!$selected_type && !$selected_status && !$date_from && !$date_to && !$search): ?>
                                <a href="wallet.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i>Top-up Wallet
                                </a>
                                <a href="paystack-order-recovery.php" class="btn btn-secondary">
                                    <i class="fas fa-sync-alt me-1"></i>Recover Paystack Order
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 mobile-responsive-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 1%; white-space: nowrap;">Reference</th>
                                        <th style="width: 1%; white-space: nowrap;">Type</th>
                                        <th class="d-none d-sm-table-cell" style="width: 1%; white-space: nowrap;">Customer</th>
                                        <th style="width: 1%; white-space: nowrap;">Amount</th>
                                        <th style="width: 1%; white-space: nowrap;">Wallet Balance</th>
                                        <th style="width: 1%; white-space: nowrap;">Status</th>
                                        <th class="d-none d-md-table-cell" style="width: 1%; white-space: nowrap;">Payment Method</th>
                                        <th class="d-none d-lg-table-cell" style="width: 80%;">Description</th>
                                        <th style="width: 15%; white-space: nowrap;">Date</th>
                                        <th class="d-none d-xl-table-cell" style="width: 1%; white-space: nowrap;"><?php echo htmlspecialchars($gateway_label); ?> Ref</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td data-label="Reference" style="white-space: nowrap;">
                                                <code style="background: var(--bg-secondary); padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.8rem;">
                                                    <?php echo htmlspecialchars($transaction['reference']); ?>
                                                </code>
                                            </td>
                                            <td data-label="Type" style="white-space: nowrap;">
                                                <?php 
                                                 $display_type = $transaction['display_type'];
                                                 if (stripos($transaction['description'] ?? '', 'profit withdrawal') !== false) {
                                                     $display_type = 'profit_withdrawal';
                                                 }
                                                 $type_labels = [
                                                     'agent_topup' => '<span class="badge bg-primary">Agent Top-up</span>',
                                                     'customer_topup' => '<span class="badge bg-success">Customer Top-up</span>',
                                                     'wallet_topup' => '<span class="badge bg-info">Wallet Top-up</span>',
                                                     'topup' => '<span class="badge bg-info">Top-up</span>',
                                                     'profit_withdrawal' => '<span class="badge bg-warning text-dark">Profit to Wallet</span>'
                                                 ];
                                                 echo $type_labels[$display_type] ?? '<span class="badge bg-secondary">' . htmlspecialchars($transaction['type']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="d-none d-sm-table-cell" data-label="Customer">
                                                <?php if ($transaction['customer_name'] && $transaction['display_type'] === 'customer_topup'): ?>
                                                    <div class="small">
                                                        <i class="fas fa-user text-primary"></i>
                                                        <?php echo htmlspecialchars($transaction['customer_name']); ?>
                                                    </div>
                                                <?php elseif ($transaction['display_type'] === 'agent_topup'): ?>
                                                    <div class="small text-muted">
                                                        <i class="fas fa-user-tie"></i>
                                                        You (Agent)
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Amount">
                                                <strong style="color: var(--accent-green);">
                                                    <?php echo CURRENCY; ?> <?php echo number_format($transaction['amount'], 2); ?>
                                                </strong>
                                            </td>
                                            <td data-label="Wallet Balance" style="white-space: nowrap;">
                                                <?php if (isset($transaction['balance_after']) && $transaction['balance_after'] !== null): ?>
                                                    <strong>
                                                        <?php echo CURRENCY; ?> <?php echo number_format($transaction['balance_after'], 2); ?>
                                                    </strong>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Status">
                                                <?php 
                                                $status_labels = [
                                                    'success' => '<span class="badge bg-success">Success</span>',
                                                    'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
                                                    'failed' => '<span class="badge bg-danger">Failed</span>'
                                                ];
                                                echo $status_labels[$transaction['status']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($transaction['status']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="d-none d-md-table-cell" data-label="Payment Method">
                                                <?php 
                                                $payment_labels = [
                                                    'paystack' => '<i class="fas fa-credit-card text-primary"></i> Paystack',
                                                    'moolre' => '<i class="fas fa-credit-card text-primary"></i> Moolre',
                                                    'wallet' => '<i class="fas fa-wallet text-info"></i> Wallet',
                                                    'bank_transfer' => '<i class="fas fa-university text-success"></i> Bank Transfer'
                                                ];
                                                echo $payment_labels[$transaction['payment_method']] ?? htmlspecialchars($transaction['payment_method']);
                                                ?>
                                            </td>
                                            <td class="small d-none d-lg-table-cell" data-label="Description">
                                                <?php echo htmlspecialchars($transaction['description']); ?>
                                            </td>
                                            <td class="small" data-label="Date">
                                                <div><?php echo date('M j, Y', strtotime($transaction['created_at'])); ?></div>
                                                <div class="text-muted"><?php echo date('H:i:s', strtotime($transaction['created_at'])); ?></div>
                                            </td>
                                            <td class="small d-none d-xl-table-cell" data-label="<?php echo htmlspecialchars($gateway_label); ?> Ref">
                                                <?php if ($transaction['paystack_reference']): ?>
                                                    <code style="background: var(--bg-secondary); padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem;">
                                                        <?php echo htmlspecialchars($transaction['paystack_reference']); ?>
                                                    </code>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="card-footer d-flex justify-content-between align-items-center" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-top: 1px solid var(--border-color); flex-wrap: wrap; gap: 1rem;">
                                <div class="text-muted small">
                                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> transactions.
                                </div>
                                <nav aria-label="Page navigation">
                                    <ul class="pagination mb-0" style="display: flex; gap: 0.25rem; list-style: none; padding: 0; margin: 0;">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link btn btn-sm btn-outline-secondary" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" style="text-decoration: none;">
                                                    <i class="fas fa-chevron-left"></i> Prev
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        for ($i = $start_page; $i <= $end_page; $i++):
                                        ?>
                                            <li class="page-item">
                                                <a class="page-link btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" style="text-decoration: none; font-weight: <?php echo $i === $page ? 'bold' : 'normal'; ?>;">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link btn btn-sm btn-outline-secondary" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" style="text-decoration: none;">
                                                    Next <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Mobile menu toggle
document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('show');
});

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
    // Show moon icon for light theme (to switch TO dark)
    // Show sun icon for dark theme (to switch TO light)
    if (icon) icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

function toggleUserDropdown() {
    document.getElementById('userDropdown').classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!dropdown.contains(event.target) && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

document.addEventListener('DOMContentLoaded', function(){ 
    initTheme();
    
    // Initialize mobile enhancements for tables
    if (typeof MobileEnhancements !== 'undefined') {
        new MobileEnhancements();
    }
});
</script>

<style>
/* Fix desktop width overflow caused by fixed sidebar */
@media (min-width: 992px) {
    .main-content {
        max-width: calc(100% - 250px);
    }
}

/* Responsive table styles for transactions */
@media (max-width: 991px) {
    .mobile-responsive-table {
        font-size: 0.875rem;
    }
    
    .mobile-responsive-table th,
    .mobile-responsive-table td {
        padding: 0.5rem 0.25rem;
        vertical-align: middle;
    }
    
    .mobile-responsive-table code {
        font-size: 0.7rem !important;
        padding: 0.1rem 0.25rem !important;
    }
    
    .mobile-responsive-table .badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
}

@media (max-width: 767px) {
    body {
        font-size: 0.9rem;
    }

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
        width: 100%;
        max-width: 100%;
        font-size: 0.82rem;
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
    }
    
    .mobile-responsive-table tr {
        border: 1px solid var(--border-color);
        border-radius: 0.5rem;
        padding: 1rem;
        margin-bottom: 1rem;
        background: var(--bg-primary);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        width: 100%;
        max-width: 100%;
    }
    
    .mobile-responsive-table td {
        border: none;
        padding: 0.5rem 0;
        position: relative;
        padding-left: 0 !important;
        text-align: left;
        width: 100%;
        max-width: 100%;
        overflow-wrap: anywhere !important;
        word-break: break-all !important;
        white-space: normal !important;
    }
    
    .mobile-responsive-table td:before {
        content: attr(data-label) ":";
        position: static;
        display: block;
        width: auto;
        padding-right: 0;
        margin-bottom: 0.25rem;
        white-space: normal;
        font-weight: 600;
        color: var(--text-muted);
        text-align: left;
    }

    .mobile-responsive-table code {
        display: inline-block !important;
        max-width: 100% !important;
        white-space: normal !important;
        word-break: break-all !important;
        overflow-wrap: anywhere !important;
    }
}

/* Filter form responsiveness */
@media (max-width: 991px) {
    .filter-form .row {
        gap: 0.5rem;
    }
    
    .filter-form .col-md-2,
    .filter-form .col-md-3,
    .filter-form .col-md-1 {
        flex: 1;
        min-width: 120px;
    }
}

@media (max-width: 767px) {
    .filter-form .row {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        margin-left: 0;
        margin-right: 0;
    }
    
    .filter-form .col-md-2,
    .filter-form .col-md-3,
    .filter-form .col-md-1 {
        width: 100%;
        min-width: auto;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .stat-value {
        font-size: 1.1rem;
    }

    .breadcrumb {
        flex-wrap: wrap;
        row-gap: 0.25rem;
        font-size: 0.85rem;
    }
}

@media (max-width: 575px) {
    body {
        font-size: 0.85rem;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .page-title h1 {
        font-size: 1.4rem;
    }

    .page-subtitle {
        font-size: 0.85rem;
    }
    
    .dashboard-content {
        padding: 1rem;
    }
    
    .card {
        margin-bottom: 1rem;
    }
}
</style>

    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


