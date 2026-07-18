<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';
require_once '../includes/commission.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require agent role
requireRole('agent');

$current_user = getCurrentUser();
$agent_id = $current_user['id'];
$stats = getDashboardStats($agent_id, 'agent');

// Get dynamic analytics data for agent
$weekly_sales = getWeeklySalesData($agent_id, 'agent');
$recent_transactions = getRecentTransactions($agent_id, 'agent', 10);
$sales_by_network = getSalesByNetworkData($agent_id, 'agent', 30);
$top_customers_weekly = getTopCustomersForAgent($agent_id, 7, 5);
$top_customers_monthly = getTopCustomersForAgent($agent_id, 30, 5);
$daily_summary = getSalesOrdersSummary($agent_id, 'agent', 1);
$weekly_summary = getSalesOrdersSummary($agent_id, 'agent', 7);
$monthly_summary = getSalesOrdersSummary($agent_id, 'agent', 30);

// Get commission data
$pending_commission = getAgentPendingCommission($agent_id);
$liquidated_commission = getAgentLiquidatedCommission($agent_id);
$commission_by_network = getAgentCommissionByNetwork($agent_id, 'pending');

// Local helper to generate URL-friendly slugs
if (!function_exists('generateStoreSlug')) {
    function generateStoreSlug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}

// Check if agent_stores table exists and handle store creation
$store = null;
$store_url = null;
$table_exists = false;

// Check if agent_stores table exists
try {
    $check_table = $db->query("SHOW TABLES LIKE 'agent_stores'");
    $table_exists = $check_table->num_rows > 0;
} catch (Exception $e) {
    $table_exists = false;
}

if ($table_exists) {
    // Table exists, proceed with store logic
    $stmt = $db->prepare("SELECT id, store_name, store_slug FROM agent_stores WHERE agent_id = ? AND is_active = TRUE LIMIT 1");
    $stmt->bind_param("i", $current_user['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $store = $row;
    } else {
        // Auto-generate default store details
        $default_name = trim($current_user['full_name']) !== '' ? $current_user['full_name'] . " Store" : (trim($current_user['username']) !== '' ? $current_user['username'] . " Store" : "Agent Store");
        $base_slug = generateStoreSlug($default_name);
        $slug = $base_slug !== '' ? $base_slug : ('agent-' . $current_user['id']);
        $suffix = 1;
        // Ensure unique slug across all stores
        $check = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ? LIMIT 1");
        do {
            $check->bind_param('s', $slug);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            if ($exists) { $slug = $base_slug . '-' . $suffix++; }
        } while ($exists);

        // Create the store with error handling
        try {
            $ins = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, is_active) VALUES (?, ?, ?, TRUE)");
            $ins->bind_param('iss', $current_user['id'], $default_name, $slug);
            if ($ins->execute()) {
                $store = [ 'id' => $db->lastInsertId(), 'store_name' => $default_name, 'store_slug' => $slug ];
            }
        } catch (mysqli_sql_exception $e) {
            // Handle foreign key constraint error
            if (strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                // Refresh user data to check if user still exists
                $fresh_user = getCurrentUser();
                if (!$fresh_user) {
                    // User no longer exists, redirect to login
                    header('Location: ../logout.php?error=invalid_user');
                    exit();
                } else {
                    // User exists but may not be an agent, check role
                    if ($fresh_user['role'] !== 'agent') {
                        header('Location: ../unauthorized.php');
                        exit();
                    } else {
                        // User is agent but FK constraint still fails, log error
                        error_log("Agent store creation FK error for valid agent ID {$current_user['id']}: " . $e->getMessage());
                        $store = null;
                    }
                }
            } else {
                // Other database error, log and show generic error
                error_log("Agent store creation error: " . $e->getMessage());
                $store = null;
            }
        }
    }

    if ($store) {
        $base = rtrim(SITE_URL, '/');
        $store_url = $base . '/store/index.php?store=' . urlencode($store['store_slug']);
    }
}

// Get flash message for display
$flash = getFlashMessage();

// Get recent transactions for this agent
$recent_transactions = [];
$stmt = $db->prepare("
    SELECT bo.*, dp.name as package_name, n.name as network 
    FROM bundle_orders bo 
    JOIN data_packages dp ON bo.package_id = dp.id 
    JOIN networks n ON n.id = dp.network_id 
    WHERE bo.user_id = ?
    ORDER BY bo.created_at DESC 
    LIMIT 10
");
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_transactions[] = $row;
}

// Get weekly sales data for this agent
$weekly_sales = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as daily_sales 
        FROM transactions 
        WHERE user_id = ? AND DATE(created_at) = ? AND status = 'success' AND transaction_type = 'purchase'
    ");
    $stmt->bind_param("is", $current_user['id'], $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $daily_data = $result->fetch_assoc();
    
    $weekly_sales[] = [
        'day' => date('l', strtotime($date)),
        'sales' => floatval($daily_data['daily_sales'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
    <meta name="theme-color" content="#541388">
    
    <!-- iOS PWA Support -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo SITE_NAME; ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>"">
    <link rel="apple-touch-icon" sizes="152x152" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-152.png')); ?>"">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>"">
    <link rel="apple-touch-icon" sizes="167x167" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>"">
    
    <!-- Android PWA Support -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?php echo SITE_NAME; ?>">
    
    <!-- Microsoft PWA Support -->
    <meta name="msapplication-TileColor" content="#541388">
    <meta name="msapplication-TileImage" content="../assets/images/icon-192.png">
    <meta name="msapplication-config" content="none">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    
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
                        <a href="dashboard.php" class="nav-link active">
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
                        <a href="topup-requests.php" class="nav-link">
                            <i class="fas fa-hand-holding-usd"></i>
                            Topup Requests
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
                        <a href="payment-settings.php" class="nav-link">
                            <i class="fas fa-university"></i>
                            Payment Settings
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Flash Messages -->
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Notifications Slider -->
                <?php echo renderNotificationSlides('agents'); ?>
                
                <div class="page-title">
                    <h1>Today</h1>
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($current_user['full_name']); ?>!</p>
                </div>

                <?php if (!empty($store_url)): ?>
                <!-- Store Link Widget -->
                <div class="widget" style="margin-bottom: 1rem;">
                    <div class="widget-header">
                        <h3 class="widget-title"><i class="fas fa-store" style="margin-right:.5rem;"></i>Your Store Link</h3>
                    </div>
                    <div class="widget-body">
                        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap: wrap;">
                            <input type="text" id="agentStoreLink" class="form-control" value="<?php echo htmlspecialchars($store_url); ?>" readonly style="flex:1; min-width:260px;">
                            <button class="btn btn-outline" onclick="copyToClipboard('agentStoreLink')" title="Copy link"><i class="fas fa-copy"></i> Copy</button>
                            <a class="btn btn-primary" href="<?php echo htmlspecialchars($store_url); ?>" target="_blank" rel="noopener" title="Open store"><i class="fas fa-external-link-alt"></i> Open</a>
                        </div>
                        <?php if (!empty($store['store_name'])): ?>
                        <div style="margin-top:.5rem; color: var(--text-muted); font-size:.875rem;">Store: <strong><?php echo htmlspecialchars($store['store_name']); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency(getWalletBalance($current_user['id'])); ?></h3>
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
                            <h3><?php echo number_format($stats['total_customers'] ?? 0); ?></h3>
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
                
                <!-- Charts and Commission Info -->
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
                    
                    <!-- Commission Info -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Commission Summary</h3>
                        </div>
                        <div class="widget-body">
                            <div style="padding: 1.5rem 0;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                                    <div style="text-align: center;">
                                        <h3 style="margin: 0; color: var(--brand-primary);">
                                            <?php echo formatCurrency((float) $pending_commission); ?>
                                        </h3>
                                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0.5rem 0;">
                                            Pending Commission
                                        </p>
                                    </div>
                                    <div style="text-align: center;">
                                        <h3 style="margin: 0; color: var(--success-color);">
                                            <?php echo formatCurrency((float) $liquidated_commission); ?>
                                        </h3>
                                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0.5rem 0;">
                                            Total Liquidated
                                        </p>
                                    </div>
                                </div>
                                
                                <?php if (!empty($commission_by_network)): ?>
                                <div style="margin-bottom: 2rem;">
                                    <h4 style="margin-bottom: 1rem; font-size: 0.875rem; color: var(--text-muted);">Commission by Network</h4>
                                    <?php foreach ($commission_by_network as $network): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                                        <span style="font-weight: 500;"><?php echo htmlspecialchars($network['network_name']); ?></span>
                                        <span style="color: var(--brand-primary);"><?php echo formatCurrency((float) ($network['total_commission'] ?? 0)); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                    <a href="commission.php" class="btn btn-primary" style="text-decoration: none; text-align: center;">
                                        <i class="fas fa-percentage"></i> View Commission
                                    </a>
                                    <a href="wallet.php" class="btn btn-outline" style="text-decoration: none; text-align: center;">
                                        <i class="fas fa-wallet"></i> Topup Wallet
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Transactions and Traffic -->
                <div class="dashboard-grid">
                    <!-- Recent Transactions -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Recent Transactions</h3>
                        </div>
                        <div class="widget-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Number</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_transactions as $transaction): ?>
                                            <?php
                                                $displayReference = $transaction['reference_display'] ?? $transaction['reference'] ?? '';
                                                if (!$displayReference || strtoupper($displayReference) === 'N/A') {
                                                    if (!empty($transaction['order_id'])) {
                                                        $displayReference = '#' . str_pad((int) $transaction['order_id'], 6, '0', STR_PAD_LEFT);
                                                    } elseif (!empty($transaction['id'])) {
                                                        $displayReference = 'TXN-' . str_pad((int) $transaction['id'], 6, '0', STR_PAD_LEFT);
                                                    }
                                                }
                                            ?>
                                        <tr>
                                            <td>
                                                <code><?php echo htmlspecialchars($displayReference ?: 'N/A'); ?></code>
                                            </td>
                                            <td>
                                                <?php
                                                    $beneficiary = $transaction['beneficiary_number'] ?? '';
                                                    if (empty($beneficiary) && !empty($transaction['description'])) {
                                                        if (preg_match('/(233\\d{9}|0\\d{9})/', $transaction['description'], $m)) {
                                                            $beneficiary = $m[0];
                                                        }
                                                    }
                                                    echo htmlspecialchars($beneficiary ?: 'N/A');
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary">
                                                    <?php
                                                        $type = $transaction['transaction_type_display'] ?? $transaction['transaction_type'] ?? 'purchase';
                                                        echo ucfirst(str_replace('_', ' ', $type));
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatCurrency($transaction['amount']); ?></td>
                                            <td>
                                                <?php
                                                    $statusRaw = strtolower($transaction['status_display'] ?? $transaction['status'] ?? 'pending');
                                                    $isDelivered = in_array($statusRaw, ['success', 'completed', 'delivered'], true);
                                                    $statusLabel = $isDelivered ? 'Delivered' : 'Pending';
                                                    $statusClass = $isDelivered ? 'success' : 'warning';
                                                    $statusTime = !empty($transaction['created_at']) ? date('g:i A', strtotime($transaction['created_at'])) : '';
                                                ?>
                                                <span class="badge badge-<?php echo $statusClass; ?>">
                                                    <?php echo $statusLabel; ?>
                                                </span>
                                                <?php if ($statusTime): ?>
                                                    <div class="text-muted" style="font-size: 0.75rem; margin-top: 0.2rem;">
                                                        <?php echo $statusTime; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, H:i', strtotime($transaction['created_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($recent_transactions)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No transactions found</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sales by Network -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Sales by Network (Last 30 Days)</h3>
                        </div>
                        <div class="widget-body">
                            <?php
                            $visible_sales_by_network = array_filter(
                                $sales_by_network,
                                function ($network) {
                                    $orders = (int)($network['total_orders'] ?? 0);
                                    $sales = (float)($network['total_sales'] ?? 0);
                                    $commission = (float)($network['commission_earned'] ?? 0);
                                    return $orders > 0 || $sales > 0 || $commission > 0;
                                }
                            );
                            ?>
                            <?php if (!empty($visible_sales_by_network)): ?>
                                <div style="padding: 1rem 0;">
                                    <?php foreach ($visible_sales_by_network as $network): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div>
                                            <div style="font-weight: 500; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($network['network_name'] ?? 'Unknown Network'); ?>
                                            </div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                <?php echo number_format($network['total_orders'] ?? 0); ?> orders
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 500; color: var(--brand-primary);">
                                                <?php echo formatCurrency((float) ($network['total_sales'] ?? 0)); ?>
                                            </div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                <?php echo formatCurrency((float) ($network['commission_earned'] ?? 0)); ?> commission
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-chart-bar" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No sales data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Customers -->
                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Customers (Weekly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($top_customers_weekly)): ?>
                                <?php foreach ($top_customers_weekly as $customer): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                    <div>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($customer['full_name'] ?? ''); ?></div>
                                        <?php if (!empty($customer['email'])): ?>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCurrency((float) ($customer['total_sales'] ?? 0), 'GHS')); ?></div>
                                        <div style="font-size: 0.875rem; color: var(--text-muted);">
                                            <?php echo number_format((int) ($customer['total_orders'] ?? 0)); ?> orders
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-users" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No customer sales yet</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top Customers (Monthly)</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($top_customers_monthly)): ?>
                                <?php foreach ($top_customers_monthly as $customer): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                    <div>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($customer['full_name'] ?? ''); ?></div>
                                        <?php if (!empty($customer['email'])): ?>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars(formatCurrency((float) ($customer['total_sales'] ?? 0), 'GHS')); ?></div>
                                        <div style="font-size: 0.875rem; color: var(--text-muted);">
                                            <?php echo number_format((int) ($customer['total_orders'] ?? 0)); ?> orders
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-users" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No customer sales yet</p>
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
            
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
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
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
            initCharts();
        });
        
        function initCharts() {
            // Sales Chart with dynamic data
            const salesCtx = document.getElementById('salesChart').getContext('2d');
            const salesData = <?php echo json_encode($weekly_sales); ?>;
            
            new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: salesData.map(d => d.short_day),
                    datasets: [{
                        label: 'Sales (GH\\u20B5)',
                        data: salesData.map(d => d.sales),
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
                                    return 'Sales: GH\\u20B5' + context.parsed.y.toLocaleString();
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
                                    return 'GH\\u20B5' + value.toLocaleString();
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
        }

        // Copy helper
        function copyToClipboard(inputId) {
            const el = document.getElementById(inputId);
            if (!el) return;
            el.select();
            el.setSelectionRange(0, 99999);
            try {
                const ok = document.execCommand('copy');
                if (!ok && navigator.clipboard) {
                    navigator.clipboard.writeText(el.value);
                }
            } catch (e) {
                if (navigator.clipboard) navigator.clipboard.writeText(el.value);
            }
        }
    </script>
    
    <!-- Notification Slider JavaScript -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>""></script>
</body>
</html>


