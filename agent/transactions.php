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

// Fetch monetary transactions for this agent
$query = "
    SELECT t.id, t.transaction_type as type, t.amount, t.status, t.reference, t.payment_method, 
           t.description, t.created_at, t.paystack_reference, u.full_name as customer_name,
           CASE 
               WHEN t.user_id = ? AND t.transaction_type = 'topup' AND t.description LIKE '%Agent wallet top-up%' THEN 'agent_topup'
               WHEN t.transaction_type = 'topup' AND (
                   t.description LIKE CONCAT('%', ?, '%') OR 
                   t.description LIKE '%Customer wallet top-up%'
               ) THEN 'customer_topup'
               WHEN t.transaction_type = 'topup' THEN 'wallet_topup'
               ELSE t.transaction_type
           END as display_type
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    WHERE (
        (t.user_id = ? AND t.transaction_type = 'topup' 
         AND t.description LIKE '%wallet top-up%')
        OR 
        (t.transaction_type = 'topup' AND t.description LIKE CONCAT('%', ?, '%'))
    )
";

// Get agent name for search
$agent_name = $current_user['full_name'];

$params = [$agent_id, $agent_name, $agent_id, $agent_name];
$types = 'isii';

if ($selected_type !== '') {
    if ($selected_type === 'agent_topup') {
        $query .= " AND t.description LIKE '%Agent wallet top-up%'";
    } elseif ($selected_type === 'customer_topup') {
        $query .= " AND t.description LIKE '%Customer wallet top-up%'";
    } else {
        $query .= " AND t.transaction_type = ?";
        $params[] = $selected_type;
        $types .= 's';
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

$query .= " ORDER BY t.created_at DESC LIMIT 500";

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
        SUM(CASE WHEN status = 'success' AND user_id = ? AND description LIKE '%Agent wallet top-up%' THEN amount ELSE 0 END) as total_agent_topups,
        SUM(CASE WHEN status = 'success' AND transaction_type = 'topup' AND description LIKE CONCAT('%', ?, '%') THEN amount ELSE 0 END) as total_customer_topups,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
    FROM transactions
    WHERE (
        (user_id = ? AND transaction_type = 'topup'
         AND description LIKE '%wallet top-up%')
        OR 
        (transaction_type = 'topup' AND description LIKE CONCAT('%', ?, '%'))
    )
    AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
";
$stmt = $db->prepare($stats_query);
$stmt->bind_param("isis", $agent_id, $agent_name, $agent_id, $agent_name);
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
                    <a href="transactions.php" class="nav-link active">
                        <i class="fas fa-money-bill-wave"></i>
                        Transactions
                    </a>
                </div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link">
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
                    <a href="wallet.php" class="nav-link">
                        <i class="fas fa-wallet"></i>
                        Wallet
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

<?php echo renderNotificationSlides('agents'); ?>


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
                    <div class="stat-icon purple">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY; ?> <?php echo number_format($stats['total_agent_topups'], 2); ?></div>
                        <div class="stat-label">Agent Top-ups (30 days)</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY; ?> <?php echo number_format($stats['total_customer_topups'], 2); ?></div>
                        <div class="stat-label">Customer Top-ups (30 days)</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['pending_count']); ?></div>
                        <div class="stat-label">Pending Transactions</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green">
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
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 mobile-responsive-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Type</th>
                                        <th class="d-none d-sm-table-cell">Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th class="d-none d-md-table-cell">Payment Method</th>
                                        <th class="d-none d-lg-table-cell">Description</th>
                                        <th>Date</th>
                                        <th class="d-none d-xl-table-cell"><?php echo htmlspecialchars($gateway_label); ?> Ref</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td data-label="Reference">
                                                <code>
                                                    <?php echo htmlspecialchars($transaction['reference']); ?>
                                                </code>
                                            </td>
                                            <td data-label="Type">
                                                <?php 
                                                $type_labels = [
                                                    'agent_topup' => '<span class="badge badge-primary">Agent Top-up</span>',
                                                    'customer_topup' => '<span class="badge badge-success">Customer Top-up</span>',
                                                    'wallet_topup' => '<span class="badge badge-info">Wallet Top-up</span>',
                                                    'topup' => '<span class="badge badge-info">Top-up</span>'
                                                ];
                                                echo $type_labels[$transaction['display_type']] ?? '<span class="badge badge-secondary">' . htmlspecialchars($transaction['type']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="d-none d-sm-table-cell" data-label="Customer">
                                                <?php if ($transaction['customer_name'] && $transaction['display_type'] === 'customer_topup'): ?>
                                                    <div class="small">
                                                        <i class="fas fa-user" style="color: var(--brand-primary);"></i>
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
                                                <strong>
                                                    <?php echo CURRENCY; ?> <?php echo number_format($transaction['amount'], 2); ?>
                                                </strong>
                                            </td>
                                            <td data-label="Status">
                                                <?php 
                                                $status_labels = [
                                                    'success' => '<span class="badge badge-success">Success</span>',
                                                    'pending' => '<span class="badge badge-warning">Pending</span>',
                                                    'failed' => '<span class="badge badge-danger">Failed</span>'
                                                ];
                                                echo $status_labels[$transaction['status']] ?? '<span class="badge badge-secondary">' . htmlspecialchars($transaction['status']) . '</span>';
                                                ?>
                                            </td>
                                            <td class="d-none d-md-table-cell" data-label="Payment Method">
                                                <?php 
                                                $payment_labels = [
                                                    'paystack' => '<i class="fas fa-credit-card" style="color: var(--brand-primary);"></i> Paystack',
                                                    'moolre' => '<i class="fas fa-credit-card" style="color: var(--brand-primary);"></i> Moolre',
                                                    'wallet' => '<i class="fas fa-wallet" style="color: var(--brand-primary);"></i> Wallet',
                                                    'bank_transfer' => '<i class="fas fa-university" style="color: var(--accent-green);"></i> Bank Transfer'
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
                                                    <code>
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
                        
                        <?php if (count($transactions) >= 500): ?>
                            <div class="card-footer text-center">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    Showing latest 500 transactions. Use filters to narrow down results.
                                </small>
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
/* Custom color definitions for transactions */
:root {
    --accent-green-rgb: 46, 117, 89;
    --accent-green: #2e7559;
}
[data-theme="dark"] {
    --accent-green-rgb: 74, 222, 128;
    --accent-green: #4ade80;
}

/* Stat card icon variations */
.stat-icon.purple {
    background-color: rgba(84, 19, 136, 0.1) !important;
    color: var(--brand-primary) !important;
}
.stat-icon.blue {
    background-color: rgba(0, 180, 216, 0.1) !important;
    color: #00b4d8 !important;
}
.stat-icon.orange {
    background-color: rgba(255, 159, 67, 0.15) !important;
    color: #ff9f43 !important;
}
.stat-icon.green {
    background-color: rgba(46, 117, 89, 0.15) !important;
    color: #2e7559 !important;
}

[data-theme="dark"] .stat-icon.purple {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #c084fc !important;
}
[data-theme="dark"] .stat-icon.blue {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #38bdf8 !important;
}
[data-theme="dark"] .stat-icon.orange {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #fb923c !important;
}
[data-theme="dark"] .stat-icon.green {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #4ade80 !important;
}

/* Badge background colors for light theme */
.badge-primary, .badge.badge-primary {
    background-color: rgba(84, 19, 136, 0.1) !important;
    color: var(--brand-primary) !important;
}
.badge-success, .badge.badge-success {
    background-color: rgba(46, 117, 89, 0.1) !important;
    color: #2e7559 !important;
}
.badge-warning, .badge.badge-warning {
    background-color: rgba(255, 212, 0, 0.15) !important;
    color: #a88d00 !important;
}
.badge-danger, .badge.badge-danger {
    background-color: rgba(217, 3, 104, 0.1) !important;
    color: #d90368 !important;
}
.badge-info, .badge.badge-info {
    background-color: rgba(0, 180, 216, 0.1) !important;
    color: #00b4d8 !important;
}
.badge-secondary, .badge.badge-secondary {
    background-color: rgba(108, 117, 125, 0.1) !important;
    color: #6c757d !important;
}

/* Badge background colors overrides for dark theme */
[data-theme="dark"] .badge-primary, [data-theme="dark"] .badge.badge-primary {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #c084fc !important;
}
[data-theme="dark"] .badge-success, [data-theme="dark"] .badge.badge-success {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #4ade80 !important;
}
[data-theme="dark"] .badge-warning, [data-theme="dark"] .badge.badge-warning {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #fbbf24 !important;
}
[data-theme="dark"] .badge-danger, [data-theme="dark"] .badge.badge-danger {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #f87171 !important;
}
[data-theme="dark"] .badge-info, [data-theme="dark"] .badge.badge-info {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #38bdf8 !important;
}
[data-theme="dark"] .badge-secondary, [data-theme="dark"] .badge.badge-secondary {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: #9ca3af !important;
}

/* Table cell text overrides */
.table td strong {
    color: var(--accent-green) !important;
}
[data-theme="dark"] .table td strong {
    color: #4ade80 !important;
}

/* Table code tags */
.table code {
    background-color: var(--bg-secondary) !important;
    color: var(--brand-primary) !important;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
    font-size: 0.8rem;
}
[data-theme="dark"] .table code {
    color: var(--text-primary) !important;
    background-color: var(--bg-tertiary) !important;
}

/* Desktop grid rules for filter form layout */
@media (min-width: 992px) {
    .filter-form .row {
        display: flex;
        flex-direction: row;
        align-items: flex-end;
        gap: 0.5rem;
    }
    .filter-form .col-md-2 {
        flex: 1;
        min-width: 150px;
    }
    .filter-form .col-md-3 {
        flex: 1.5;
        min-width: 200px;
    }
    .filter-form .col-md-1 {
        flex: 0 0 60px;
        max-width: 60px;
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
        box-shadow: 0 2px 4px rgba(46, 41, 78, 0.1);
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
        overflow-wrap: anywhere;
        word-break: break-word;
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
    
    .mobile-responsive-table td:first-child {
        border-top: 0;
        font-weight: bold;
        background: var(--bg-secondary);
        margin: -1rem -1rem 0.5rem -1rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem 0.5rem 0 0;
        text-align: center;
    }
    
    .mobile-responsive-table td:first-child:before {
        display: none;
    }

    .mobile-responsive-table code {
        display: inline-block;
        max-width: 100%;
        white-space: normal !important;
        word-break: break-all;
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

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

