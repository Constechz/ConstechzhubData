<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';

// Require admin role
requireRole('admin');

$current_user = getCurrentUser();
$current_hour = (int) date('G');
$admin_dashboard_greeting = 'Good Evening';
if ($current_hour < 12) {
    $admin_dashboard_greeting = 'Good Morning';
} elseif ($current_hour < 17) {
    $admin_dashboard_greeting = 'Good Afternoon';
}
$gateway_label = getActivePaymentGateway() === 'moolre' ? 'Moolre' : 'Paystack';
$top_networks = ['MTN', 'AT', 'Telecel'];
$dashboard_warnings = [];
$pending_admin_topup_requests = 0;
$new_afa_registrations = 0;
$pending_profit_withdrawals = 0;
$pending_profit_withdrawal_amount = 0.0;

try {
    $pendingStmt = $db->prepare("SELECT COUNT(*) AS total_pending FROM topup_requests WHERE target_type = 'admin' AND status = 'pending'");
    if ($pendingStmt && $pendingStmt->execute()) {
        $pendingRow = $pendingStmt->get_result()->fetch_assoc();
        $pending_admin_topup_requests = (int) ($pendingRow['total_pending'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('Admin dashboard topup pending count failed: ' . $e->getMessage());
}

try {
    $new_afa_registrations = function_exists('getNewAfaRegistrationCount') ? getNewAfaRegistrationCount() : 0;
} catch (Throwable $e) {
    error_log('Admin dashboard new AFA registration count failed: ' . $e->getMessage());
}

try {
    if (function_exists('ensureProfitWithdrawalTables')) {
        ensureProfitWithdrawalTables();
    }
    $withdrawalPendingStmt = $db->prepare("
        SELECT COUNT(*) AS total_pending, COALESCE(SUM(amount), 0) AS total_amount
        FROM profit_withdrawals
        WHERE payout_method = 'momo' AND status = 'pending'
    ");
    if ($withdrawalPendingStmt && $withdrawalPendingStmt->execute()) {
        $withdrawalPendingRow = $withdrawalPendingStmt->get_result()->fetch_assoc();
        $pending_profit_withdrawals = (int) ($withdrawalPendingRow['total_pending'] ?? 0);
        $pending_profit_withdrawal_amount = (float) ($withdrawalPendingRow['total_amount'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('Admin dashboard profit withdrawal pending count failed: ' . $e->getMessage());
}

$zeroWeekSeries = function ($key, $weekOffset = 0) {
    $series = [];
    $today = new DateTimeImmutable('today');
    $startOfWeek = $today
        ->modify('-' . (int) $today->format('w') . ' days')
        ->modify('-' . (max(0, (int) $weekOffset) * 7) . ' days');

    for ($i = 0; $i < 7; $i++) {
        $dayDate = $startOfWeek->modify('+' . $i . ' days');
        $date = $dayDate->format('Y-m-d');
        $series[] = [
            'day' => $dayDate->format('l'),
            'short_day' => $dayDate->format('D'),
            'date' => $date,
            $key => 0
        ];
    }
    return $series;
};

$safeAnalyticsCall = function ($label, $defaultValue, callable $callback) use (&$dashboard_warnings) {
    try {
        $value = $callback();
        return $value === null ? $defaultValue : $value;
    } catch (Throwable $e) {
        $dashboard_warnings[] = $label;
        error_log('Admin dashboard analytics error [' . $label . ']: ' . $e->getMessage());
        return $defaultValue;
    }
};

$defaults = [
    'stats' => [
        'total_users' => 0,
        'total_agents' => 0,
        'total_customers' => 0,
        'total_sales' => 0,
        'total_orders' => 0,
        'total_balance' => 0
    ],
    'recent_transactions' => [],
    'weekly_sales' => $zeroWeekSeries('sales', 0),
    'previous_weekly_sales' => $zeroWeekSeries('sales', 1),
    'weekly_traffic' => $zeroWeekSeries('visits'),
    'sales_by_network' => [],
    'top_agents_by_network_weekly' => [],
    'top_agents_by_network_monthly' => [],
    'top_sales_agents_daily' => [],
    'top_sales_agents_monthly' => [],
    'topup_agents_daily' => [],
    'topup_agents_weekly' => [],
    'topup_agents_monthly' => [],
    'daily_summary' => ['total_sales' => 0, 'total_orders' => 0],
    'weekly_summary' => ['total_sales' => 0, 'total_orders' => 0],
    'monthly_summary' => ['total_sales' => 0, 'total_orders' => 0],
    'profit_daily' => ['total_profit' => 0, 'total_revenue' => 0, 'total_cost' => 0, 'agent_profit' => 0, 'customer_profit' => 0, 'total_orders' => 0],
    'profit_monthly' => ['total_profit' => 0, 'total_revenue' => 0, 'total_cost' => 0, 'agent_profit' => 0, 'customer_profit' => 0, 'total_orders' => 0],
    'profit_lifetime' => ['total_profit' => 0, 'total_revenue' => 0, 'total_cost' => 0, 'agent_profit' => 0, 'customer_profit' => 0, 'total_orders' => 0],
    'commission_daily' => ['total_commission' => 0, 'pending_commission' => 0, 'liquidated_commission' => 0, 'total_entries' => 0],
    'commission_monthly' => ['total_commission' => 0, 'pending_commission' => 0, 'liquidated_commission' => 0, 'total_entries' => 0],
    'commission_lifetime' => ['total_commission' => 0, 'pending_commission' => 0, 'liquidated_commission' => 0, 'total_entries' => 0]
];

$cacheKey = 'admin_dashboard_payload_v8';
$cacheTtlSeconds = 120;
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$payload = null;

if (
    !$forceRefresh &&
    isset($_SESSION[$cacheKey]) &&
    is_array($_SESSION[$cacheKey]) &&
    isset($_SESSION[$cacheKey]['user_id'], $_SESSION[$cacheKey]['time'], $_SESSION[$cacheKey]['payload']) &&
    (int)$_SESSION[$cacheKey]['user_id'] === (int)($current_user['id'] ?? 0) &&
    (time() - (int)$_SESSION[$cacheKey]['time']) <= $cacheTtlSeconds
) {
    $payload = $_SESSION[$cacheKey]['payload'];
}

if (!is_array($payload)) {
    $payload = [];
    $payload['stats'] = $safeAnalyticsCall('getDashboardStats', $defaults['stats'], function () use ($current_user) {
        return getDashboardStats($current_user['id'], 'admin');
    });
    $payload['recent_transactions'] = $safeAnalyticsCall('getRecentTransactions', $defaults['recent_transactions'], function () {
        return getRecentTransactions(null, 'admin', 10);
    });
    $payload['weekly_sales'] = $safeAnalyticsCall('getWeeklySalesData', $defaults['weekly_sales'], function () {
        return getWeeklySalesData(null, 'admin', 0);
    });
    $payload['previous_weekly_sales'] = $safeAnalyticsCall('getWeeklySalesData-previous', $defaults['previous_weekly_sales'], function () {
        return getWeeklySalesData(null, 'admin', 1);
    });
    $payload['weekly_traffic'] = $safeAnalyticsCall('getWeeklyTrafficData', $defaults['weekly_traffic'], function () {
        return getWeeklyTrafficData(null, 'admin');
    });
    $payload['sales_by_network'] = $safeAnalyticsCall('getSalesByNetworkData', $defaults['sales_by_network'], function () {
        return getSalesByNetworkData(null, 'admin', 30);
    });
    $payload['top_agents_by_network_weekly'] = $safeAnalyticsCall('getTopAgentsByNetwork-weekly', $defaults['top_agents_by_network_weekly'], function () use ($top_networks) {
        return getTopAgentsByNetwork(7, $top_networks);
    });
    $payload['top_agents_by_network_monthly'] = $safeAnalyticsCall('getTopAgentsByNetwork-monthly', $defaults['top_agents_by_network_monthly'], function () use ($top_networks) {
        return getTopAgentsByNetwork(30, $top_networks);
    });
    $payload['top_sales_agents_monthly'] = $safeAnalyticsCall('getTopSalesAgentsByPeriod-monthly', $defaults['top_sales_agents_monthly'] ?? [], function () {
        return getTopSalesAgentsByPeriod(30, 5);
    });
    $payload['top_sales_agents_daily'] = $safeAnalyticsCall('getTopSalesAgentsByPeriod-daily', $defaults['top_sales_agents_daily'], function () {
        return getTopSalesAgentsByPeriod(1, 5);
    });
    $payload['topup_agents_daily'] = $safeAnalyticsCall('getTopupAgentsByPeriod-daily', $defaults['topup_agents_daily'], function () {
        return getTopupAgentsByPeriod(1, 5);
    });
    $payload['topup_agents_weekly'] = $safeAnalyticsCall('getTopupAgentsByPeriod-weekly', $defaults['topup_agents_weekly'], function () {
        return getTopupAgentsByPeriod(7, 5);
    });
    $payload['topup_agents_monthly'] = $safeAnalyticsCall('getTopupAgentsByPeriod-monthly', $defaults['topup_agents_monthly'], function () {
        return getTopupAgentsByPeriod(30, 5);
    });
    $payload['daily_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-daily', $defaults['daily_summary'], function () {
        return getSalesOrdersSummary(null, 'admin', 1);
    });
    $payload['weekly_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-weekly', $defaults['weekly_summary'], function () {
        return getSalesOrdersSummary(null, 'admin', 7);
    });
    $payload['monthly_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-monthly', $defaults['monthly_summary'], function () {
        return getSalesOrdersSummary(null, 'admin', 30);
    });
    $payload['profit_daily'] = $safeAnalyticsCall('getProfitSummary-daily', $defaults['profit_daily'], function () {
        return getProfitSummary(1);
    });
    $payload['profit_monthly'] = $safeAnalyticsCall('getProfitSummary-monthly', $defaults['profit_monthly'], function () {
        return getProfitSummary(30);
    });
    $payload['profit_lifetime'] = $safeAnalyticsCall('getProfitSummary-lifetime', $defaults['profit_lifetime'], function () {
        return getProfitSummary(0);
    });
    $payload['commission_daily'] = $safeAnalyticsCall('getCommissionSummary-daily', $defaults['commission_daily'], function () {
        return getCommissionSummary(1);
    });
    $payload['commission_monthly'] = $safeAnalyticsCall('getCommissionSummary-monthly', $defaults['commission_monthly'], function () {
        return getCommissionSummary(30);
    });
    $payload['commission_lifetime'] = $safeAnalyticsCall('getCommissionSummary-lifetime', $defaults['commission_lifetime'], function () {
        return getCommissionSummary(0);
    });

    $_SESSION[$cacheKey] = [
        'user_id' => (int)($current_user['id'] ?? 0),
        'time' => time(),
        'payload' => $payload
    ];
}

// Keep order counters live even when the rest of the dashboard payload is session-cached.
$payload['stats'] = $safeAnalyticsCall('getDashboardStats-live', $payload['stats'] ?? $defaults['stats'], function () use ($current_user) {
    return getDashboardStats($current_user['id'], 'admin');
});
$payload['daily_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-daily-live', $payload['daily_summary'] ?? $defaults['daily_summary'], function () {
    return getSalesOrdersSummary(null, 'admin', 1);
});
$payload['weekly_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-weekly-live', $payload['weekly_summary'] ?? $defaults['weekly_summary'], function () {
    return getSalesOrdersSummary(null, 'admin', 7);
});
$payload['monthly_summary'] = $safeAnalyticsCall('getSalesOrdersSummary-monthly-live', $payload['monthly_summary'] ?? $defaults['monthly_summary'], function () {
    return getSalesOrdersSummary(null, 'admin', 30);
});
$payload['profit_daily'] = $safeAnalyticsCall('getProfitSummary-daily-live', $payload['profit_daily'] ?? $defaults['profit_daily'], function () {
    return getProfitSummary(1);
});
$payload['profit_monthly'] = $safeAnalyticsCall('getProfitSummary-monthly-live', $payload['profit_monthly'] ?? $defaults['profit_monthly'], function () {
    return getProfitSummary(30);
});
$payload['profit_lifetime'] = $safeAnalyticsCall('getProfitSummary-lifetime-live', $payload['profit_lifetime'] ?? $defaults['profit_lifetime'], function () {
    return getProfitSummary(0);
});
$payload['commission_daily'] = $safeAnalyticsCall('getCommissionSummary-daily-live', $payload['commission_daily'] ?? $defaults['commission_daily'], function () {
    return getCommissionSummary(1);
});
$payload['commission_monthly'] = $safeAnalyticsCall('getCommissionSummary-monthly-live', $payload['commission_monthly'] ?? $defaults['commission_monthly'], function () {
    return getCommissionSummary(30);
});
$payload['commission_lifetime'] = $safeAnalyticsCall('getCommissionSummary-lifetime-live', $payload['commission_lifetime'] ?? $defaults['commission_lifetime'], function () {
    return getCommissionSummary(0);
});

$stats = array_merge($defaults['stats'], (array)($payload['stats'] ?? []));
$recent_transactions = is_array($payload['recent_transactions'] ?? null) ? $payload['recent_transactions'] : $defaults['recent_transactions'];
$weekly_sales = is_array($payload['weekly_sales'] ?? null) ? $payload['weekly_sales'] : $defaults['weekly_sales'];
$previous_weekly_sales = is_array($payload['previous_weekly_sales'] ?? null) ? $payload['previous_weekly_sales'] : $defaults['previous_weekly_sales'];
$weekly_traffic = is_array($payload['weekly_traffic'] ?? null) ? $payload['weekly_traffic'] : $defaults['weekly_traffic'];
$sales_by_network = is_array($payload['sales_by_network'] ?? null) ? $payload['sales_by_network'] : $defaults['sales_by_network'];
$top_agents_by_network_weekly = is_array($payload['top_agents_by_network_weekly'] ?? null) ? $payload['top_agents_by_network_weekly'] : $defaults['top_agents_by_network_weekly'];
$top_agents_by_network_monthly = is_array($payload['top_agents_by_network_monthly'] ?? null) ? $payload['top_agents_by_network_monthly'] : $defaults['top_agents_by_network_monthly'];
$top_sales_agents_daily = is_array($payload['top_sales_agents_daily'] ?? null) ? $payload['top_sales_agents_daily'] : $defaults['top_sales_agents_daily'];
$top_sales_agents_monthly = is_array($payload['top_sales_agents_monthly'] ?? null) ? $payload['top_sales_agents_monthly'] : ($defaults['top_sales_agents_monthly'] ?? []);
$topup_agents_daily = is_array($payload['topup_agents_daily'] ?? null) ? $payload['topup_agents_daily'] : $defaults['topup_agents_daily'];
$topup_agents_weekly = is_array($payload['topup_agents_weekly'] ?? null) ? $payload['topup_agents_weekly'] : $defaults['topup_agents_weekly'];
$topup_agents_monthly = is_array($payload['topup_agents_monthly'] ?? null) ? $payload['topup_agents_monthly'] : $defaults['topup_agents_monthly'];
$daily_summary = array_merge($defaults['daily_summary'], (array)($payload['daily_summary'] ?? []));
$weekly_summary = array_merge($defaults['weekly_summary'], (array)($payload['weekly_summary'] ?? []));
$monthly_summary = array_merge($defaults['monthly_summary'], (array)($payload['monthly_summary'] ?? []));
$profit_daily = array_merge($defaults['profit_daily'], (array)($payload['profit_daily'] ?? []));
$profit_monthly = array_merge($defaults['profit_monthly'], (array)($payload['profit_monthly'] ?? []));
$profit_lifetime = array_merge($defaults['profit_lifetime'], (array)($payload['profit_lifetime'] ?? []));
$commission_daily = array_merge($defaults['commission_daily'], (array)($payload['commission_daily'] ?? []));
$commission_monthly = array_merge($defaults['commission_monthly'], (array)($payload['commission_monthly'] ?? []));
$commission_lifetime = array_merge($defaults['commission_lifetime'], (array)($payload['commission_lifetime'] ?? []));
$top_sales_agent_today = !empty($top_sales_agents_daily[0]) && is_array($top_sales_agents_daily[0]) ? $top_sales_agents_daily[0] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    <meta name="theme-color" content="#6366f1">
    
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
    <meta name="msapplication-TileColor" content="#6366f1">
    <meta name="msapplication-TileImage" content="../assets/images/icon-192.png">
    <meta name="msapplication-config" content="none">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
    
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
            
                        <?php renderAdminSidebar(); ?>
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
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($admin_dashboard_greeting . ' ' . ($current_user['full_name'] ?? 'Admin')); ?>!</p>
                </div>
                <?php if (!empty($dashboard_warnings)): ?>
                    <div class="alert alert-warning" style="margin-bottom: 1rem;">
                        Some analytics widgets were temporarily unavailable and loaded with fallback values.
                    </div>
                <?php endif; ?>
                <?php if ($pending_admin_topup_requests > 0): ?>
                    <div class="alert alert-info" style="margin-bottom: 1rem;">
                        You have <?php echo number_format($pending_admin_topup_requests); ?> pending topup request(s). <a href="topup-requests.php">Review now</a>.
                    </div>
                <?php endif; ?>
                <?php if ($new_afa_registrations > 0): ?>
                    <div class="alert alert-warning" style="margin-bottom: 1rem;">
                        You have <?php echo number_format($new_afa_registrations); ?> new AFA registration(s) awaiting review. <a href="afa-registration.php">Review now</a>.
                    </div>
                <?php endif; ?>
                <?php if ($pending_profit_withdrawals > 0): ?>
                    <div class="alert alert-danger" style="margin-bottom: 1rem;">
                        You have <?php echo number_format($pending_profit_withdrawals); ?> pending MoMo profit withdrawal request(s) worth <?php echo formatCurrency($pending_profit_withdrawal_amount); ?>. <a href="profit-withdrawals.php">Review now</a>.
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
                            <p>Tracked Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-sun"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($daily_summary['total_sales'] ?? 0); ?></h3>
                            <p>Tracked Daily Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($weekly_summary['total_sales'] ?? 0); ?></h3>
                            <p>Tracked Weekly Sales</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-calendar"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($monthly_summary['total_sales'] ?? 0); ?></h3>
                            <p>Tracked Monthly Sales</p>
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

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($profit_daily['total_profit'] ?? 0); ?></h3>
                            <p>Store Profit Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($profit_lifetime['available_profit'] ?? 0); ?></h3>
                            <p>Available Store Profit</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($commission_daily['total_commission'] ?? 0); ?></h3>
                            <p>Commission Today</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-content">
                            <h3>
                                <?php if ($top_sales_agent_today): ?>
                                    <?php echo formatCurrency((float) ($top_sales_agent_today['total_sales'] ?? 0)); ?>
                                <?php else: ?>
                                    <?php echo formatCurrency(0); ?>
                                <?php endif; ?>
                            </h3>
                            <p>Highest Sales Agent Today</p>
                            <div style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.35;">
                                <?php if ($top_sales_agent_today): ?>
                                    <?php echo htmlspecialchars((string) ($top_sales_agent_today['full_name'] ?? '')); ?>
                                    <?php if (!empty($top_sales_agent_today['total_orders'])): ?>
                                        &middot; <?php echo number_format((int) $top_sales_agent_today['total_orders']); ?> orders
                                    <?php endif; ?>
                                <?php else: ?>
                                    No tracked sales yet today
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-crown"></i>
                        </div>
                        <div class="stat-content">
                            <h3>
                                <?php 
                                    $top_sales_agent_monthly = !empty($top_sales_agents_monthly[0]) && is_array($top_sales_agents_monthly[0]) ? $top_sales_agents_monthly[0] : null;
                                    if ($top_sales_agent_monthly): ?>
                                    <?php echo formatCurrency((float) ($top_sales_agent_monthly['total_sales'] ?? 0)); ?>
                                <?php else: ?>
                                    <?php echo formatCurrency(0); ?>
                                <?php endif; ?>
                            </h3>
                            <p>Highest Sales Agent Monthly</p>
                            <div style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.35;">
                                <?php if ($top_sales_agent_monthly): ?>
                                    <?php echo htmlspecialchars((string) ($top_sales_agent_monthly['full_name'] ?? '')); ?>
                                    <?php if (!empty($top_sales_agent_monthly['total_orders'])): ?>
                                        &middot; <?php echo number_format((int) $top_sales_agent_monthly['total_orders']); ?> orders
                                    <?php endif; ?>
                                <?php else: ?>
                                    No tracked sales yet this month
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Highest Sales Agents Today</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($top_sales_agents_daily)): ?>
                                <?php foreach ($top_sales_agents_daily as $index => $agent): ?>
                                    <div class="responsive-flex-item">
                                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                            <div style="width: 1.75rem; height: 1.75rem; border-radius: 999px; background: var(--bg-secondary); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: var(--text-primary); flex-shrink: 0;">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars((string) ($agent['full_name'] ?? '')); ?></div>
                                                <?php if (!empty($agent['email'])): ?>
                                                    <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars((string) $agent['email']); ?></div>
                                                <?php endif; ?>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    <?php echo number_format((int) ($agent['total_orders'] ?? 0)); ?> orders today
                                                </div>
                                            </div>
                                        </div>
                                        <div style="text-align: right; flex-shrink: 0;">
                                            <div style="font-weight: 600;"><?php echo formatCurrency((float) ($agent['total_sales'] ?? 0)); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-chart-line" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No tracked sales recorded today</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Highest Sales Agents Monthly</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($top_sales_agents_monthly)): ?>
                                <?php foreach ($top_sales_agents_monthly as $index => $agent): ?>
                                    <div class="responsive-flex-item">
                                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                            <div style="width: 1.75rem; height: 1.75rem; border-radius: 999px; background: var(--bg-secondary); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: var(--text-primary); flex-shrink: 0;">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars((string) ($agent['full_name'] ?? '')); ?></div>
                                                <?php if (!empty($agent['email'])): ?>
                                                    <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars((string) $agent['email']); ?></div>
                                                <?php endif; ?>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    <?php echo number_format((int) ($agent['total_orders'] ?? 0)); ?> orders this month
                                                </div>
                                            </div>
                                        </div>
                                        <div style="text-align: right; flex-shrink: 0;">
                                            <div style="font-weight: 600;"><?php echo formatCurrency((float) ($agent['total_sales'] ?? 0)); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-chart-line" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No tracked sales recorded this month</p>
                                </div>
                            <?php endif; ?>
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
                                <button
                                    type="button"
                                    id="salesCurrentWeekBtn"
                                    class="btn btn-primary"
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem;"
                                    aria-pressed="true"
                                >
                                    Current Week
                                </button>
                                <button
                                    type="button"
                                    id="salesPreviousWeekBtn"
                                    class="btn btn-outline"
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem;"
                                    aria-pressed="false"
                                >
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
                                        <div class="network-item responsive-flex-item">
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
                            <h3 class="widget-title">Recent Transactions</h3>
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
                                          <th>Order Status</th>
                                          <th>Date/Time</th>
                                          </tr>                                      </thead>
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
                                                    $transaction['agent_cost'] ?? null,
                                                    $transaction['metadata_admin_price'] ?? null,
                                                    $transaction['order_amount'] ?? null,
                                                    $transaction['amount'] ?? null,
                                                    $transaction['metadata_amount'] ?? null,
                                                    $transaction['metadata_value'] ?? null,
                                                    $metadataArr['admin_price'] ?? null,
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
                                                <a href="transactions.php?search=<?php echo urlencode($transaction['reference'] ?? $transaction['id']); ?>" class="text-primary">
                                                    <?php echo $orderIdDisplay; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php
                                                    $transactionType = strtolower(trim((string) ($transaction['transaction_type_display'] ?? $transaction['transaction_type'] ?? '')));
                                                    $msisdnDisplay = $msisdn === ''
                                                        ? ($transactionType === 'topup' ? 'Wallet Top Up' : 'N/A')
                                                        : $msisdn;
                                                    echo htmlspecialchars($msisdnDisplay);
                                                ?>
                                            </td>
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
                                                  <?php $orderStatus = strtolower($transaction['status_display'] ?? $transaction['status'] ?? 'pending'); ?>
                                                  <span class="badge badge-<?php 
                                                      echo in_array($orderStatus, ['success', 'delivered', 'completed'], true) ? 'success' : 
                                                          ($orderStatus === 'failed' ? 'danger' : 'warning'); 
                                                  ?>">
                                                      <?php echo ucfirst($orderStatus); ?>
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
                                <div class="responsive-flex-item">
                                    <div style="display: flex; align-items: center;">
                                        <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($agent['color'] ?? '#9ca3af'); ?>; margin-right: 8px;"></div>
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
                                <div class="responsive-flex-item">
                                    <div style="display: flex; align-items: center;">
                                        <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($agent['color'] ?? '#9ca3af'); ?>; margin-right: 8px;"></div>
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
                                    <div class="responsive-flex-item">
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
                                    <div class="responsive-flex-item">
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
                                    <div class="responsive-flex-item">
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
            const salesSeries = {
                current: <?php echo json_encode($weekly_sales, JSON_UNESCAPED_SLASHES); ?>,
                previous: <?php echo json_encode($previous_weekly_sales, JSON_UNESCAPED_SLASHES); ?>
            };
             
            // Sales Chart with dynamic data
            const salesCtx = document.getElementById('salesChart').getContext('2d');
            const salesChart = new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: salesSeries.current.map((item) => item.short_day),
                    datasets: [{
                        label: 'Sales (' + currencySymbol + ')',
                        data: salesSeries.current.map((item) => Number(item.sales || 0)),
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
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
                                color: 'rgba(0,0,0,0.1)'
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

            const salesCurrentWeekBtn = document.getElementById('salesCurrentWeekBtn');
            const salesPreviousWeekBtn = document.getElementById('salesPreviousWeekBtn');

            function setSalesPeriod(period) {
                const series = salesSeries[period] || salesSeries.current;
                salesChart.data.labels = series.map((item) => item.short_day);
                salesChart.data.datasets[0].data = series.map((item) => Number(item.sales || 0));
                salesChart.update();

                const isCurrent = period === 'current';
                if (salesCurrentWeekBtn) {
                    salesCurrentWeekBtn.classList.toggle('btn-primary', isCurrent);
                    salesCurrentWeekBtn.classList.toggle('btn-outline', !isCurrent);
                    salesCurrentWeekBtn.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
                }
                if (salesPreviousWeekBtn) {
                    salesPreviousWeekBtn.classList.toggle('btn-primary', !isCurrent);
                    salesPreviousWeekBtn.classList.toggle('btn-outline', isCurrent);
                    salesPreviousWeekBtn.setAttribute('aria-pressed', !isCurrent ? 'true' : 'false');
                }
            }

            if (salesCurrentWeekBtn) {
                salesCurrentWeekBtn.addEventListener('click', function () {
                    setSalesPeriod('current');
                });
            }

            if (salesPreviousWeekBtn) {
                salesPreviousWeekBtn.addEventListener('click', function () {
                    setSalesPeriod('previous');
                });
            }

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
                        backgroundColor: 'rgba(16, 185, 129, 0.35)',
                        borderColor: '#10B981',
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
                                color: 'rgba(0,0,0,0.1)'
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
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/mobile-enhancements.js')); ?>"></script>
</body>
</html>



