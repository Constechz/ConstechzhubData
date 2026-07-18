<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';

// Require admin role
requireRole('admin');

$current_user = getCurrentUser();
$stats = getDashboardStats($current_user['id'], 'admin');
$gateway_label = getActivePaymentGateway() === 'moolre' ? 'Moolre' : 'Paystack';

// Get dynamic analytics data
$recent_transactions = getRecentTransactions(null, 'admin', 10);
$weekly_sales = getWeeklySalesData(null, 'admin');
$weekly_traffic = getWeeklyTrafficData(null, 'admin');
$sales_by_network = getSalesByNetworkData(null, 'admin', 30);
$top_agents = getTopPerformingAgents(5);
$top_networks = ['MTN', 'AT', 'Telecel'];
$top_agents_by_network_weekly = getTopAgentsByNetwork(7, $top_networks);
$top_agents_by_network_monthly = getTopAgentsByNetwork(30, $top_networks);
$topup_agents_daily = getTopupAgentsByPeriod(1, 5);
$topup_agents_weekly = getTopupAgentsByPeriod(7, 5);
$topup_agents_monthly = getTopupAgentsByPeriod(30, 5);
$daily_summary = getSalesOrdersSummary(null, 'admin', 1);
$weekly_summary = getSalesOrdersSummary(null, 'admin', 7);
$monthly_summary = getSalesOrdersSummary(null, 'admin', 30);

// Check for successful AFA registrations requiring admin action (status = 'processing')
$afa_pending_count = 0;
if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
    try {
        $afa_query_rs = $db->query("SELECT COUNT(*) AS total FROM afa_registrations WHERE status = 'processing'");
        if ($afa_query_rs && ($afa_query_row = $afa_query_rs->fetch_assoc())) {
            $afa_pending_count = (int) ($afa_query_row['total'] ?? 0);
        }
    } catch (Exception $e) {
        error_log('AFA alert count failed: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
    <meta name="theme-color" content="#541388">
    
    <!-- iOS PWA Support -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo SITE_NAME; ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-152.png')); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    <link rel="apple-touch-icon" sizes="167x167" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    
    <!-- Android PWA Support -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?php echo SITE_NAME; ?>">
    
    <!-- Microsoft PWA Support -->
    <meta name="msapplication-TileColor" content="#541388">
    <meta name="msapplication-TileImage" content="../assets/images/icon-192.png">
    <meta name="msapplication-config" content="none">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    
    <!-- Font Awesome Stylesheet (Loaded Directly for maximum reliability across all devices) -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>?v=<?php echo time(); ?>">
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- PWA Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('../sw.js')
                .then(function(registration) {
                    console.log('ServiceWorker registration successful with scope: ', registration.scope);
                })
                .catch(function(err) {
                    console.log('ServiceWorker registration failed: ', err);
                });
        });
    }
    </script>
</head>
<body class="fa-ready">
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
                        <a href="dashboard.php" class="nav-link active">
                            <i class="fas fa-home"></i>
                            Dashboard
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <div class="nav-item">
                        <a href="packages.php" class="nav-link">
                            <i class="fas fa-box"></i>
                            Data Packages
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="pricing.php" class="nav-link">
                            <i class="fas fa-tags"></i>
                            Pricing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="afa-registration.php" class="nav-link">
                            <i class="fas fa-user-check"></i>
                            AFA Registration
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="fas fa-users"></i>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="agents.php" class="nav-link">
                            <i class="fas fa-user-tie"></i>
                            Agents
                        </a>
                    </div>
                
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Operations</div>
                    <div class="nav-item">
                        <a href="manual_topup.php" class="nav-link">
                            <i class="fas fa-plus-circle"></i>
                            Manual Top-up
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
                    <div class="nav-section-title">Analytics</div>
                    <div class="nav-item">
                        <a href="transactions.php" class="nav-link">
                            <i class="fas fa-history"></i>
                            Transactions
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="data-histories.php" class="nav-link">
                            <i class="fas fa-database"></i>
                            Data Histories
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="user-access.php" class="nav-link">
                            <i class="fas fa-user-shield"></i>
                            User Access
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="fas fa-chart-bar"></i>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
                    <div class="nav-item">
                        <a href="profit.php" class="nav-link">
                            <i class="fas fa-coins"></i>
                            Profit
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog"></i>
                            System Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="topup-settings.php" class="nav-link">
                            <i class="fas fa-university"></i>
                            Topup Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="topup-requests.php" class="nav-link">
                            <i class="fas fa-file-invoice"></i>
                            Topup Requests
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="commission-settings.php" class="nav-link">
                            <i class="fas fa-percentage"></i>
                            Commission Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="pwa-settings.php" class="nav-link">
                            <i class="fas fa-mobile-alt"></i>
                            PWA Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="sms-settings.php" class="nav-link">
                            <i class="fas fa-sms"></i>
                            SMS Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="sms-broadcast.php" class="nav-link">
                            <i class="fas fa-bullhorn"></i>
                            SMS Broadcasts
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="seo-settings.php" class="nav-link">
                            <i class="fas fa-globe"></i>
                            SEO Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="smtp-settings.php" class="nav-link">
                            <i class="fas fa-envelope"></i>
                            SMTP Email Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="email-broadcast.php" class="nav-link">
                            <i class="fas fa-paper-plane"></i>
                            Email Broadcasts
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="api-providers.php" class="nav-link">
                            <i class="fas fa-plug"></i>
                            API Providers
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="api-applications.php" class="nav-link">
                            <i class="fas fa-key"></i>
                            API Applications
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="system-reset.php" class="nav-link">
                            <i class="fas fa-broom"></i>
                            System Reset
                        </a>
                    </div>
                </li>
            </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <div class="breadcrumb-item">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div class="breadcrumb-item">Dashboard</div>
                        <div class="breadcrumb-item active">Home</div>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="page-title">
                    <h1>Today</h1>
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($current_user['full_name']); ?>!</p>
                </div>
                
                <?php if ($afa_pending_count > 0): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert" style="border-left: 5px solid #a88d00; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; color: #a88d00;"></i>
                        <div style="flex: 1;">
                            <strong style="font-size: 1.05rem;">AFA Action Required!</strong>
                            <div style="margin-top: 0.2rem;">
                                There are <strong><?php echo $afa_pending_count; ?></strong> successful AFA registration(s) that require your attention.
                                <a href="afa-registration.php" class="alert-link" style="text-decoration: underline; font-weight: 600; margin-left: 0.5rem;">Process Now &rarr;</a>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($stats['total_balance'] ?? 0); ?></h3>
                            <p>Current Balance</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                            <p>Total Orders</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['total_users'] ?? 0); ?></h3>
                            <p>Our Clients</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($stats['total_sales'] ?? 0); ?></h3>
                            <p>Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-sun"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($daily_summary['total_sales'] ?? 0); ?></h3>
                            <p>Daily Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($weekly_summary['total_sales'] ?? 0); ?></h3>
                            <p>Weekly Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($monthly_summary['total_sales'] ?? 0); ?></h3>
                            <p>Monthly Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($daily_summary['total_orders'] ?? 0); ?></h3>
                            <p>Daily Orders</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($weekly_summary['total_orders'] ?? 0); ?></h3>
                            <p>Weekly Orders</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($monthly_summary['total_orders'] ?? 0); ?></h3>
                            <p>Monthly Orders</p>
                        </div>
                    </div>
                </div>
                
                <!-- Charts and Tables -->
                <div class="dashboard-grid">
                    <!-- Weekly Sales Chart -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Weekly Sales</h3>
                            <div class="widget-actions">
                                <button class="btn btn-outline" style="padding: 0.5rem 1rem; font-size: 0.75rem;">
                                    Current Week
                                </button>
                                <button class="btn btn-outline" style="padding: 0.5rem 1rem; font-size: 0.75rem;">
                                    Previous Week
                                </button>
                            </div>
                        </div>
                        <div class="widget-body">
                            <div class="chart-container">
                                <canvas id="salesChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sales by Network -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Sales by Network (30 Days)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($sales_by_network)): ?>
                                <div class="network-sales-list">
                                    <?php foreach ($sales_by_network as $network): ?>
                                        <div class="network-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                            <div style="display: flex; align-items: center;">
                                                <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($network['color']); ?>; margin-right: 8px;"></div>
                                                <div>
                                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($network['network_name'] ?? 'Unknown Network'); ?></div>
                                                    <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo number_format($network['total_orders'] ?? 0); ?> orders</div>
                                                </div>
                                            </div>
                                            <div style="text-align: right;">
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCurrency((float) ($network['total_sales'] ?? 0))); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-chart-pie" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 0.5rem;"></i>
                                    <p>No sales data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Transactions and Traffic -->
                <div class="dashboard-grid">
                    <!-- Recent Transactions -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Recent AT Transactions</h3>
                        </div>
                        <div class="widget-body">
                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label class="form-label" for="recentAtSearch">Search by phone number or order ID</label>
                                <input type="search" id="recentAtSearch" class="form-control" placeholder="e.g. 0241234567 or 000123">
                            </div>
                            <div class="table-responsive">
                                <table class="table" id="recentAtTable">
                                      <thead>
                                          <tr>
                                              <th>Order ID</th>
                                              <th>MSISDN</th>
                                              <th>Value</th>
                                              <th>Status</th>
                                              <th>Date/Time</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <?php foreach ($recent_transactions as $transaction): ?>
                                            <?php
                                                $metadataArr = [];
                                                if (!empty($transaction['metadata']) && is_string($transaction['metadata'])) {
                                                    $decodedMeta = json_decode($transaction['metadata'], true);
                                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedMeta)) {
                                                        $metadataArr = $decodedMeta;
                                                    }
                                                }

                                                $msisdnSources = [
                                                    $transaction['beneficiary_number'] ?? null,
                                                    $transaction['metadata_beneficiary'] ?? null,
                                                    $transaction['metadata_msisdn'] ?? null,
                                                    $transaction['metadata_phone'] ?? null,
                                                    $metadataArr['beneficiary_number'] ?? null,
                                                    $metadataArr['msisdn'] ?? null,
                                                    $metadataArr['phone'] ?? null,
                                                ];
                                                $msisdn = '';
                                                foreach ($msisdnSources as $candidate) {
                                                    if ($candidate === null) {
                                                        continue;
                                                    }
                                                    $candidate = trim((string)$candidate);
                                                    if ($candidate === '' || strtoupper($candidate) === 'N/A') {
                                                        continue;
                                                    }
                                                    $msisdn = $candidate;
                                                    break;
                                                }
                                                if ($msisdn === '' && !empty($transaction['description'])) {
                                                    if (preg_match('/(233\\d{9}|0\\d{9})/', $transaction['description'], $match)) {
                                                        $msisdn = $match[0];
                                                    }
                                                }
                                                if ($msisdn !== '' && strlen($msisdn) === 12 && strpos($msisdn, '233') === 0) {
                                                    $msisdn = '0' . substr($msisdn, 3);
                                                }

                                                $valueSources = [
                                                    $transaction['order_amount'] ?? null,
                                                    $transaction['amount'] ?? null,
                                                    $transaction['metadata_amount'] ?? null,
                                                    $transaction['metadata_value'] ?? null,
                                                    $metadataArr['amount'] ?? null,
                                                    $metadataArr['value'] ?? null,
                                                ];
                                                $valueAmount = null;
                                                foreach ($valueSources as $candidate) {
                                                    if ($candidate === null || $candidate === '') {
                                                        continue;
                                                    }
                                                    $valueAmount = (float)$candidate;
                                                    break;
                                                }
                                                $valueDisplay = $valueAmount !== null ? formatCurrency($valueAmount) : 'N/A';

                                                $packageSources = [
                                                    $transaction['package_name'] ?? null,
                                                    $transaction['metadata_package'] ?? null,
                                                    $metadataArr['package_name'] ?? null,
                                                    $metadataArr['package'] ?? null,
                                                ];
                                                $packageLabel = '';
                                                foreach ($packageSources as $candidate) {
                                                    if (!empty($candidate)) {
                                                        $packageLabel = $candidate;
                                                        break;
                                                    }
                                                }
                                            ?>
                                        <?php
                                            $displayOrderId = $transaction['order_id'] ?? $transaction['id'];
                                            $orderIdDisplay = str_pad((int)$displayOrderId, 6, '0', STR_PAD_LEFT);
                                            $msisdnDigits = $msisdn !== '' ? preg_replace('/\D+/', '', $msisdn) : '';
                                        ?>
                                        <tr data-order-id="<?php echo (int) $displayOrderId; ?>" data-order-display="<?php echo htmlspecialchars($orderIdDisplay); ?>" data-msisdn="<?php echo htmlspecialchars($msisdnDigits); ?>">
                                            <td>
                                                <a href="transaction.php?id=<?php echo $transaction['id']; ?>" class="text-primary">
                                                    <?php echo $orderIdDisplay; ?>
                                                </a>
                                            </td>
                                            <td><?php echo $msisdn === '' ? 'N/A' : htmlspecialchars($msisdn); ?></td>
                                            <td>
                                                <?php echo $valueDisplay; ?>
                                                <?php if (!empty($packageLabel)): ?>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($packageLabel); ?></div>
                                                <?php endif; ?>
                                            </td>
                                              <td>
                                                  <?php $statusVal = $transaction['status_display'] ?? $transaction['status'] ?? 'pending'; ?>
                                                  <span class="badge badge-<?php echo $statusVal === 'success' ? 'success' : ($statusVal === 'failed' ? 'danger' : 'warning'); ?>">
                                                      <?php echo ucfirst($statusVal); ?>
                                                  </span>
                                              </td>
                                              <td>
                                                  <?php echo !empty($transaction['created_at']) ? date('M j, Y g:i A', strtotime($transaction['created_at'])) : 'N/A'; ?>
                                              </td>
                                          </tr>
                                          <?php endforeach; ?>
                                          
                                          <?php if (empty($recent_transactions)): ?>
                                          <tr id="recentAtEmpty">
                                              <td colspan="5" class="text-center text-muted">No transactions found</td>
                                          </tr>
                                          <?php else: ?>
                                          <tr id="recentAtEmpty" style="display: none;">
                                              <td colspan="5" class="text-center text-muted">No matching results</td>
                                          </tr>
                                          <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Traffic Chart -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Traffic for the Week</h3>
                        </div>
                        <div class="widget-body">
                            <div class="chart-container">
                                <canvas id="trafficChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Agents by Network -->
                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Agents by Network (Weekly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php foreach ($top_networks as $network_name): ?>
                                <?php $agent = $top_agents_by_network_weekly[$network_name] ?? null; ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                    <div style="display: flex; align-items: center;">
                                        <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($agent['color'] ?? '#F1E9DA'); ?>; margin-right: 8px;"></div>
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($network_name); ?></div>
                                            <?php if (!empty($agent)): ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                    <?php echo htmlspecialchars($agent['full_name'] ?? ''); ?>
                                                    <?php if (!empty($agent['email'])): ?>
                                                        &middot; <?php echo htmlspecialchars($agent['email']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);">No sales yet</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <?php if (!empty($agent)): ?>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCurrency((float) ($agent['total_sales'] ?? 0), 'GHS')); ?></div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                <?php echo number_format((int) ($agent['total_orders'] ?? 0)); ?> orders
                                            </div>
                                        <?php else: ?>
                                            <div style="font-weight: 500; color: var(--text-muted);"><?php echo htmlspecialchars(formatCurrency(0, 'GHS')); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Agents by Network (Monthly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php foreach ($top_networks as $network_name): ?>
                                <?php $agent = $top_agents_by_network_monthly[$network_name] ?? null; ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                    <div style="display: flex; align-items: center;">
                                        <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($agent['color'] ?? '#F1E9DA'); ?>; margin-right: 8px;"></div>
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($network_name); ?></div>
                                            <?php if (!empty($agent)): ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                    <?php echo htmlspecialchars($agent['full_name'] ?? ''); ?>
                                                    <?php if (!empty($agent['email'])): ?>
                                                        &middot; <?php echo htmlspecialchars($agent['email']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);">No sales yet</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <?php if (!empty($agent)): ?>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCurrency((float) ($agent['total_sales'] ?? 0), 'GHS')); ?></div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                <?php echo number_format((int) ($agent['total_orders'] ?? 0)); ?> orders
                                            </div>
                                        <?php else: ?>
                                            <div style="font-weight: 500; color: var(--text-muted);"><?php echo htmlspecialchars(formatCurrency(0, 'GHS')); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Up Agents -->
                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Up Agents (Daily)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($topup_agents_daily)): ?>
                                <?php foreach ($topup_agents_daily as $agent): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['full_name'] ?? ''); ?></div>
                                            <?php if (!empty($agent['email'])): ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($gateway_label); ?>: <?php echo htmlspecialchars(formatCurrency((float) ($agent['paystack_topup'] ?? 0), 'GHS')); ?>
                                                &middot; Manual: <?php echo htmlspecialchars(formatCurrency((float) ($agent['manual_topup'] ?? 0), 'GHS')); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(formatCurrency((float) ($agent['total_topup'] ?? 0), 'GHS')); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-wallet" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No top-ups recorded today</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Up Agents (Weekly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($topup_agents_weekly)): ?>
                                <?php foreach ($topup_agents_weekly as $agent): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['full_name'] ?? ''); ?></div>
                                            <?php if (!empty($agent['email'])): ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($gateway_label); ?>: <?php echo htmlspecialchars(formatCurrency((float) ($agent['paystack_topup'] ?? 0), 'GHS')); ?>
                                                &middot; Manual: <?php echo htmlspecialchars(formatCurrency((float) ($agent['manual_topup'] ?? 0), 'GHS')); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(formatCurrency((float) ($agent['total_topup'] ?? 0), 'GHS')); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-wallet" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No top-ups recorded this week</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Up Agents (Monthly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($topup_agents_monthly)): ?>
                                <?php foreach ($topup_agents_monthly as $agent): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div>
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['full_name'] ?? ''); ?></div>
                                            <?php if (!empty($agent['email'])): ?>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            <?php endif; ?>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo htmlspecialchars($gateway_label); ?>: <?php echo htmlspecialchars(formatCurrency((float) ($agent['paystack_topup'] ?? 0), 'GHS')); ?>
                                                &middot; Manual: <?php echo htmlspecialchars(formatCurrency((float) ($agent['manual_topup'] ?? 0), 'GHS')); ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars(formatCurrency((float) ($agent['total_topup'] ?? 0), 'GHS')); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-wallet" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No top-ups recorded this month</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
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
            // Show OPPOSITE icon: moon for light theme (to switch TO dark), sun for dark theme (to switch TO light)
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
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
            
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        // Mobile menu toggle
        document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
            const currencySymbol = <?php echo json_encode(CURRENCY); ?>;
            
            // Sales Chart with dynamic data
            const salesCtx = document.getElementById('salesChart').getContext('2d');
            const salesData = [<?php echo implode(',', array_column($weekly_sales, 'sales')); ?>];
            
            new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: [<?php echo "'" . implode("','", array_column($weekly_sales, 'short_day')) . "'"; ?>],
                    datasets: [{
                        label: 'Sales (' + currencySymbol + ')',
                        data: salesData,
                        borderColor: '#541388',
                        backgroundColor: 'rgba(84, 19, 136, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Sales: ' + currencySymbol + ' ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(46, 41, 78, 0.1)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return currencySymbol + ' ' + value.toLocaleString();
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            // Traffic Chart with dynamic data
            const trafficCtx = document.getElementById('trafficChart').getContext('2d');
            const trafficData = [<?php echo implode(',', array_column($weekly_traffic, 'visits')); ?>];

            new Chart(trafficCtx, {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("','", array_column($weekly_traffic, 'short_day')) . "'"; ?>],
                    datasets: [{
                        label: 'Visits',
                        data: trafficData,
                        backgroundColor: 'rgba(46, 41, 78, 0.35)',
                        borderColor: '#2E294E',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(46, 41, 78, 0.1)'
                            },
                            ticks: {
                                precision: 0
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });

            const searchInput = document.getElementById('recentAtSearch');
            if (searchInput) {
                const rows = Array.from(document.querySelectorAll('#recentAtTable tbody tr[data-order-id]'));
                const emptyRow = document.getElementById('recentAtEmpty');
                const normalizeDigits = (value) => value.replace(/[^0-9]/g, '');

                const filterRows = () => {
                    const query = searchInput.value.trim().toLowerCase();
                    const queryDigits = normalizeDigits(query);
                    let visible = 0;

                    rows.forEach((row) => {
                        const orderId = row.dataset.orderId || '';
                        const orderDisplay = row.dataset.orderDisplay || '';
                        const msisdn = row.dataset.msisdn || '';
                        let match = false;

                        if (queryDigits) {
                            match = orderId.includes(queryDigits) || orderDisplay.includes(queryDigits) || msisdn.includes(queryDigits);
                        } else if (query !== '') {
                            match = row.textContent.toLowerCase().includes(query);
                        } else {
                            match = true;
                        }

                        row.style.display = match ? '' : 'none';
                        if (match) {
                            visible += 1;
                        }
                    });

                    if (emptyRow) {
                        emptyRow.style.display = visible ? 'none' : '';
                    }
                };

                searchInput.addEventListener('input', filterRows);
                filterRows();
            }
        });
    </script>
    
    <!-- Mobile Enhancement Script -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/mobile-enhancements.js')); ?>""></script>
</body>
</html>




