<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';
require_once '../includes/commission.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require vip role
requireRole('vip');

$current_user = getCurrentUser();
$vip_id = isset($current_user['id']) ? (int) $current_user['id'] : 0;
$agent_id = $vip_id; // Keep $agent_id for database compatibility with agent_custom_pricing table
if ($vip_id <= 0) {
    error_log('VIP dashboard accessed without a valid authenticated user record.');
    header('Location: ../login.php?session=invalid');
    exit();
}

$agent_full_name = trim((string) ($current_user['full_name'] ?? ''));
$agent_username = trim((string) ($current_user['username'] ?? ''));
$agent_display_name = $agent_full_name !== '' ? $agent_full_name : ($agent_username !== '' ? $agent_username : 'Agent');
$agent_initial = strtoupper(substr($agent_display_name, 0, 1));
$dashboard_wallet_balance = round((float) getWalletBalance($agent_id), 2);

if (!function_exists('agentDashboardSafeCall')) {
    function agentDashboardSafeCall($callback, $defaultValue, $contextLabel) {
        try {
            $value = $callback();
            return $value === null ? $defaultValue : $value;
        } catch (Throwable $e) {
            error_log('VIP dashboard ' . $contextLabel . ' failed: ' . $e->getMessage());
            return $defaultValue;
        }
    }
}

$stats = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getDashboardStats($agent_id, 'agent');
    },
    [
        'total_orders' => 0,
        'total_customers' => 0,
        'total_sales' => 0,
    ],
    'stats'
);

$stats['total_orders'] = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM bundle_orders
            WHERE agent_id = ? OR user_id = ?
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ii', $agent_id, $agent_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['total'] ?? 0);
    },
    (int) ($stats['total_orders'] ?? 0),
    'total order count'
);

// Get dynamic analytics data for agent
$weekly_sales = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getWeeklySalesData($agent_id, 'agent');
    },
    [],
    'weekly sales'
);
$previous_weekly_sales = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getWeeklySalesData($agent_id, 'agent', 1);
    },
    [],
    'previous weekly sales'
);
$recent_transactions = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getRecentTransactions($agent_id, 'agent', 10);
    },
    [],
    'recent transactions'
);
$dashboard_products = agentDashboardSafeCall(
    function () {
        return getDashboardProducts(true, 8);
    },
    [],
    'dashboard products'
);
$daily_summary = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getSalesOrdersSummary($agent_id, 'agent', 1);
    },
    ['total_sales' => 0],
    'daily summary'
);
$weekly_summary = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getSalesOrdersSummary($agent_id, 'agent', 7);
    },
    ['total_sales' => 0],
    'weekly summary'
);
$monthly_summary = agentDashboardSafeCall(
    function () use ($agent_id) {
        return getSalesOrdersSummary($agent_id, 'agent', 30);
    },
    ['total_sales' => 0],
    'monthly summary'
);

if (function_exists('ensureResultCheckerTables')) {
    ensureResultCheckerTables();
}
if (function_exists('ensureProfitWithdrawalTables')) {
    ensureProfitWithdrawalTables();
}

$recovered_profit_rows = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        if (!function_exists('recordOrderProfit')
            || !function_exists('dbh_table_exists')
            || !dbh_table_exists('agent_profits')
            || !dbh_table_exists('bundle_orders')) {
            return 0;
        }

        $stmt = $db->prepare("
            SELECT
                bo.id,
                bo.user_id,
                bo.package_id,
                bo.amount,
                bo.agent_cost,
                bo.order_reference,
                bo.status
            FROM bundle_orders bo
            LEFT JOIN agent_profits ap ON ap.order_id = bo.id
            WHERE bo.agent_id = ?
              AND ap.id IS NULL
              AND (bo.user_id IS NULL OR bo.user_id <> ?)
              AND LOWER(bo.status) IN ('pending', 'processing', 'success', 'delivered', 'completed')
              AND COALESCE(bo.agent_cost, 0) > 0
              AND bo.amount > COALESCE(bo.agent_cost, 0)
            ORDER BY bo.created_at DESC
            LIMIT 100
        ");
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('ii', $agent_id, $agent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $recovered = 0;
        while ($row = $result->fetch_assoc()) {
            $recorded = recordOrderProfit([
                'agent_id' => $agent_id,
                'order_id' => (int) $row['id'],
                'customer_id' => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                'package_id' => (int) $row['package_id'],
                'customer_paid' => (float) $row['amount'],
                'agent_cost' => (float) $row['agent_cost'],
                'profit_amount' => (float) $row['amount'] - (float) $row['agent_cost'],
                'reference' => (string) $row['order_reference'],
                'status' => 'earned',
            ]);
            if ($recorded) {
                $recovered++;
            }
        }
        $stmt->close();

        return $recovered;
    },
    0,
    'profit ledger recovery'
);

$dashboard_store_profit = 0.0;
$data_profit = 0.0;
$pending_withdrawals = 0.0;
$paid_out_withdrawals = 0.0;
$data_profit = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_profits')) {
            return 0.0;
        }
        $profitStmt = $db->prepare("
            SELECT COALESCE(SUM(profit_amount), 0) AS total
            FROM agent_profits ap
            LEFT JOIN bundle_orders bo ON bo.id = ap.order_id
            WHERE ap.agent_id = ?
              AND ap.status = 'earned'
              AND (bo.id IS NULL OR bo.user_id IS NULL OR bo.user_id <> ?)
        ");
        if (!$profitStmt) {
            return 0.0;
        }
        $profitStmt->bind_param('ii', $agent_id, $agent_id);
        $profitStmt->execute();
        $profitRow = $profitStmt->get_result()->fetch_assoc();
        $profitStmt->close();
        return (float) ($profitRow['total'] ?? 0);
    },
    0.0,
    'data profit'
);

$profitWithdrawalTotals = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        if (!function_exists('dbh_table_exists') || !dbh_table_exists('profit_withdrawals')) {
            return ['pending' => 0.0, 'paid' => 0.0];
        }
        $withdrawalSumColumn = 'amount';
        if (function_exists('dbh_table_has_column') && dbh_table_has_column('profit_withdrawals', 'total_debit')) {
            $withdrawalSumColumn = 'CASE WHEN total_debit IS NULL OR total_debit <= 0 THEN amount WHEN total_debit > amount THEN amount ELSE total_debit END';
        }
        $withdrawalStmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS pending_total,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS paid_total
            FROM profit_withdrawals
            WHERE agent_id = ?
        ");
        if (!$withdrawalStmt) {
            return ['pending' => 0.0, 'paid' => 0.0];
        }
        $withdrawalStmt->bind_param('i', $agent_id);
        $withdrawalStmt->execute();
        $withdrawalRow = $withdrawalStmt->get_result()->fetch_assoc();
        $withdrawalStmt->close();
        return [
            'pending' => (float) ($withdrawalRow['pending_total'] ?? 0),
            'paid' => (float) ($withdrawalRow['paid_total'] ?? 0),
        ];
    },
    ['pending' => 0.0, 'paid' => 0.0],
    'profit withdrawals'
);
$pending_withdrawals = (float) ($profitWithdrawalTotals['pending'] ?? 0);
$paid_out_withdrawals = (float) ($profitWithdrawalTotals['paid'] ?? 0);

$dashboard_total_profit = round($data_profit, 2);
$dashboard_store_profit = round(max(0, $dashboard_total_profit - $pending_withdrawals - $paid_out_withdrawals), 2);
$dashboard_wallet_float = $dashboard_wallet_balance;

// Get commission data
$dashboard_total_commission = 0.0;
$pending_commission = agentDashboardSafeCall(
    function () use ($agent_id) {
        return function_exists('getAgentPendingCommission') ? (float) getAgentPendingCommission($agent_id) : 0.0;
    },
    0.0,
    'pending commission'
);
$liquidated_commission = agentDashboardSafeCall(
    function () use ($agent_id) {
        return function_exists('getAgentLiquidatedCommission') ? (float) getAgentLiquidatedCommission($agent_id) : 0.0;
    },
    0.0,
    'liquidated commission'
);
$commission_by_network = agentDashboardSafeCall(
    function () use ($agent_id) {
        return function_exists('getAgentCommissionByNetwork') ? (array) getAgentCommissionByNetwork($agent_id, 'pending') : [];
    },
    [],
    'commission by network'
);

$dashboard_total_commission = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_commissions')) {
            return 0.0;
        }
        $commissionStmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM agent_commissions
            WHERE agent_id = ? AND status <> 'cancelled'
        ");
        if (!$commissionStmt) {
            return 0.0;
        }
        $commissionStmt->bind_param('i', $agent_id);
        $commissionStmt->execute();
        $commissionRow = $commissionStmt->get_result()->fetch_assoc();
        $commissionStmt->close();
        return (float) ($commissionRow['total'] ?? 0);
    },
    0.0,
    'total commission'
);

if ($dashboard_total_commission <= 0) {
    $dashboard_total_commission = round($pending_commission + $liquidated_commission, 2);
}

$commissionLiquidationTotals = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        if (!function_exists('dbh_table_exists') || !dbh_table_exists('commission_liquidations')) {
            return ['pending' => 0.0, 'completed' => 0.0];
        }
        $liquidationSummaryStmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN liquidated_amount ELSE 0 END), 0) AS pending_total,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN liquidated_amount ELSE 0 END), 0) AS completed_total
            FROM commission_liquidations
            WHERE agent_id = ?
        ");
        if (!$liquidationSummaryStmt) {
            return ['pending' => 0.0, 'completed' => 0.0];
        }
        $liquidationSummaryStmt->bind_param('i', $agent_id);
        $liquidationSummaryStmt->execute();
        $liquidationSummary = $liquidationSummaryStmt->get_result()->fetch_assoc();
        $liquidationSummaryStmt->close();
        return [
            'pending' => (float) ($liquidationSummary['pending_total'] ?? 0),
            'completed' => (float) ($liquidationSummary['completed_total'] ?? 0),
        ];
    },
    ['pending' => 0.0, 'completed' => 0.0],
    'commission liquidations'
);
$pending_commission = agentDashboardSafeCall(
    function () use ($agent_id) {
        return function_exists('getAgentPendingCommission') ? (float) getAgentPendingCommission($agent_id) : 0.0;
    },
    $pending_commission,
    'available commission'
);
$liquidated_commission = max(
    $liquidated_commission,
    (float) ($commissionLiquidationTotals['completed'] ?? 0)
);
$dashboard_current_commission = $pending_commission;

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
    $table_exists = $check_table && $check_table->num_rows > 0;
} catch (Throwable $e) {
    $table_exists = false;
}

if ($table_exists) {
    // Table exists, proceed with store logic
    $stmt = $db->prepare("SELECT id, store_name, store_slug FROM agent_stores WHERE agent_id = ? AND is_active = TRUE LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $agent_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $store = $row;
        } else {
            // Auto-generate default store details
            $default_name = $agent_full_name !== '' ? $agent_full_name . " Store" : ($agent_username !== '' ? $agent_username . " Store" : "Agent Store");
            $base_slug = generateStoreSlug($default_name);
            $slug = $base_slug !== '' ? $base_slug : ('agent-' . $agent_id);
            $suffix = 1;
            // Ensure unique slug across all stores
            $check = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ? LIMIT 1");
            if ($check) {
                do {
                    $check->bind_param('s', $slug);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    if ($exists) { $slug = $base_slug . '-' . $suffix++; }
                } while ($exists);
                $check->close();
            }

            // Create the store with error handling
            try {
                $ins = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, is_active) VALUES (?, ?, ?, TRUE)");
                if ($ins) {
                    $ins->bind_param('iss', $agent_id, $default_name, $slug);
                    if ($ins->execute()) {
                        $store = [
                            'id' => method_exists($db, 'lastInsertId') ? $db->lastInsertId() : 0,
                            'store_name' => $default_name,
                            'store_slug' => $slug
                        ];
                    }
                    $ins->close();
                }
            } catch (Throwable $e) {
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
                        if (($fresh_user['role'] ?? '') !== 'agent') {
                            header('Location: ../unauthorized.php');
                            exit();
                        } else {
                            // User is agent but FK constraint still fails, log error
                            error_log("Agent store creation FK error for valid agent ID {$agent_id}: " . $e->getMessage());
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
        $stmt->close();
    }

    if ($store) {
        $base = rtrim(SITE_URL, '/');
        $store_url = $base . '/s/' . rawurlencode($store['store_slug']);
    }
}

// Get flash message for display
$flash = getFlashMessage();

// Get recent transactions for this agent
$recent_transactions = agentDashboardSafeCall(
    function () use ($db, $agent_id) {
        $rows = [];
        $stmt = $db->prepare("
            SELECT
                bo.*,
                bo.order_reference AS reference_display,
                bo.status AS status_display,
                'purchase' AS transaction_type_display,
                CASE
                    WHEN bo.user_id IS NULL OR bo.user_id = 0 THEN 'Guest User'
                    ELSE COALESCE(NULLIF(cu.full_name, ''), NULLIF(cu.username, ''), 'Customer')
                END AS buyer_display,
                CASE
                    WHEN bo.user_id IS NULL OR bo.user_id = 0 THEN 'guest'
                    ELSE 'customer'
                END AS buyer_type,
                CASE
                    WHEN bo.agent_id = ? THEN COALESCE(NULLIF(acp.custom_price, 0), pp_customer.price, NULLIF(bo.amount, 0), dp.price, 0)
                    ELSE COALESCE(NULLIF(pp_vip.price, 0), NULLIF(bo.amount, 0), dp.price, 0)
                END AS display_amount,
                dp.name as package_name,
                n.name as network,
                n.name as network_name
            FROM bundle_orders bo
            JOIN data_packages dp ON bo.package_id = dp.id
            JOIN networks n ON n.id = dp.network_id
            LEFT JOIN users cu ON cu.id = bo.user_id
            LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
            LEFT JOIN package_pricing pp_vip ON pp_vip.package_id = dp.id AND pp_vip.user_type = 'vip'
            LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
            WHERE bo.agent_id = ? OR bo.user_id = ?
            ORDER BY bo.created_at DESC
            LIMIT 10
        ");
        if (!$stmt) {
            return $rows;
        }
        $stmt->bind_param("iiii", $agent_id, $agent_id, $agent_id, $agent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    },
    $recent_transactions,
    'recent transaction fallback query'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VIP Dashboard - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    <meta name="theme-color" content="#6366f1">
    
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
    <meta name="msapplication-TileColor" content="#6366f1">
    <meta name="msapplication-TileImage" content="../assets/images/icon-192.png">
    <meta name="msapplication-config" content="none">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
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
            
            <?php renderAgentSidebar(); ?>
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
                                <?php echo htmlspecialchars($agent_initial); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($agent_display_name); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">VIP</div>
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
                    <p class="page-subtitle">Welcome back, <?php echo htmlspecialchars($agent_display_name); ?>!</p>
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
                            <h3><?php echo formatCurrency($dashboard_wallet_float); ?></h3>
                            <p>Current Balance</p>
                            <div style="margin-top: 0.35rem; font-size: 0.75rem; color: var(--text-muted);">Excludes store profit</div>
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
                        <div class="stat-icon danger">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($dashboard_store_profit); ?></h3>
                            <p>Store Profit</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($dashboard_current_commission); ?></h3>
                            <p>Current Commission</p>
                            <div style="margin-top: 0.35rem; font-size: 0.75rem; color: var(--text-muted);">Available to request</div>
                        </div>
                    </div>

                </div>

                <?php if (!empty($dashboard_products)): ?>
                <div class="widget" style="margin-bottom: 1.5rem;">
                    <div class="widget-header">
                        <h3 class="widget-title">Products</h3>
                    </div>
                    <div class="widget-body">
                        <div class="product-catalog-grid">
                            <?php foreach ($dashboard_products as $product): ?>
                                <?php
                                $productName = trim((string) ($product['name'] ?? 'Product'));
                                $sizeLabel = trim((string) ($product['size_label'] ?? ''));
                                $currentPrice = (float) ($product['current_price'] ?? 0);
                                $oldPrice = isset($product['old_price']) && $product['old_price'] !== null ? (float) $product['old_price'] : null;
                                $rating = max(0, min(5, (int) ($product['rating'] ?? 5)));
                                $savings = ($oldPrice !== null && $oldPrice > $currentPrice) ? ($oldPrice - $currentPrice) : null;
                                $imagePath = trim((string) ($product['image_path'] ?? ''));
                                $imageUrl = $imagePath !== '' ? dbh_asset($imagePath) : '';
                                $productCheckoutUrl = (!empty($store['store_slug']) && !empty($product['id']))
                                    ? rtrim((string) SITE_URL, '/') . '/store/product-checkout.php?store=' . urlencode((string) $store['store_slug']) . '&product_id=' . (int) $product['id']
                                    : '';
                                ?>
                                <article class="product-card">
                                    <div class="product-card-media">
                                        <?php if ($imageUrl !== ''): ?>
                                            <img class="product-card-image" src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($productName); ?>">
                                        <?php else: ?>
                                            <div class="product-card-placeholder">
                                                <i class="fas fa-box-open"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-card-body">
                                        <h4 class="product-card-title"><?php echo htmlspecialchars($productName); ?></h4>
                                        <?php if ($sizeLabel !== ''): ?>
                                            <div class="product-card-size">Size: <?php echo htmlspecialchars($sizeLabel); ?></div>
                                        <?php endif; ?>
                                        <div class="product-card-rating" aria-label="<?php echo $rating; ?> out of 5 stars">
                                            <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                                                <i class="fas fa-star<?php echo $starIndex <= $rating ? '' : ' product-card-rating-muted'; ?>"></i>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="product-card-price"><?php echo htmlspecialchars(formatCurrency($currentPrice)); ?></div>
                                        <?php if ($savings !== null): ?>
                                            <div class="product-card-savings">Save <?php echo htmlspecialchars(formatCurrency($savings)); ?></div>
                                            <div class="product-card-old-price"><?php echo htmlspecialchars(formatCurrency($oldPrice)); ?></div>
                                        <?php endif; ?>
                                        <?php if ($productCheckoutUrl !== ''): ?>
                                            <a class="product-card-cta" href="<?php echo htmlspecialchars($productCheckoutUrl); ?>">
                                                <i class="fas fa-shopping-cart"></i>
                                                Buy Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Charts and Commission Info -->
                <div class="dashboard-grid">
                    <!-- Weekly Sales Chart -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Weekly Sales</h3>
                            <div class="widget-actions">
                                <button
                                    type="button"
                                    class="btn btn-outline weekly-sales-toggle active"
                                    data-week="current"
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem;"
                                >
                                    Current Week
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline weekly-sales-toggle"
                                    data-week="previous"
                                    style="padding: 0.5rem 1rem; font-size: 0.75rem;"
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
                                            ₵<?php echo number_format($pending_commission, 2); ?>
                                        </h3>
                                        <p style="color: var(--text-muted); font-size: 0.875rem; margin: 0.5rem 0;">
                                            Pending Commission
                                        </p>
                                    </div>
                                    <div style="text-align: center;">
                                        <h3 style="margin: 0; color: var(--success-color);">
                                            ₵<?php echo number_format($liquidated_commission, 2); ?>
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
                                        <span style="color: var(--brand-primary);">₵<?php echo number_format($network['total_commission'], 2); ?></span>
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
                                            <th>Buyer</th>
                                            <th>Number</th>
                                            <th>Network</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Order Status</th>
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
                                                    $buyerType = strtolower((string) ($transaction['buyer_type'] ?? 'customer'));
                                                    $buyerName = $transaction['buyer_display'] ?? ($buyerType === 'guest' ? 'Guest User' : 'Customer');
                                                ?>
                                                <div><?php echo htmlspecialchars($buyerName); ?></div>
                                                <span class="badge badge-<?php echo $buyerType === 'guest' ? 'warning' : 'secondary'; ?>" style="margin-top: 0.25rem;">
                                                    <?php echo $buyerType === 'guest' ? 'Guest order' : 'Customer order'; ?>
                                                </span>
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
                                                <?php
                                                    $networkName = detectGhanaNetworkLabel(
                                                        $transaction['network_name'] ?? '',
                                                        $transaction['beneficiary_number'] ?? '',
                                                        $transaction['metadata_beneficiary'] ?? '',
                                                        $transaction['metadata_msisdn'] ?? '',
                                                        $transaction['metadata_phone'] ?? ''
                                                    );
                                                    echo htmlspecialchars($networkName);
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary">
                                                    <?php
                                                    $type = $transaction['transaction_type_display'] ?? $transaction['transaction_type'] ?? 'purchase';
                                                    echo ucfirst(str_replace('_', ' ', $type));
                                                    ?>
                                                </span>
                                                <?php if (($transaction['buyer_type'] ?? '') === 'guest'): ?>
                                                    <div class="text-muted" style="font-size: 0.75rem; margin-top: 0.2rem;">
                                                        Store link guest
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo formatCurrency((float) ($transaction['display_amount'] ?? $transaction['amount'] ?? 0)); ?></td>
                                            <td>
                                                <?php
                                                    $statusRaw = strtolower($transaction['status_display'] ?? $transaction['status'] ?? 'pending');
                                                    if (in_array($statusRaw, ['success', 'completed', 'delivered'], true)) {
                                                        $statusLabel = 'Delivered';
                                                        $statusClass = 'success';
                                                    } elseif ($statusRaw === 'processing') {
                                                        $statusLabel = 'Processing';
                                                        $statusClass = 'primary';
                                                    } elseif (in_array($statusRaw, ['failed', 'cancelled'], true)) {
                                                        $statusLabel = ucfirst($statusRaw);
                                                        $statusClass = 'danger';
                                                    } else {
                                                        $statusLabel = ucfirst($statusRaw ?: 'pending');
                                                        $statusClass = 'warning';
                                                    }
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
                                            <td>
                                                <?php
                                                    $orderStatusRaw = strtolower($transaction['status_display'] ?? $transaction['status'] ?? 'pending');
                                                    if (in_array($orderStatusRaw, ['success', 'completed', 'delivered'], true)) {
                                                        $orderStatusLabel = 'Delivered';
                                                        $orderStatusClass = 'success';
                                                    } elseif ($orderStatusRaw === 'processing') {
                                                        $orderStatusLabel = 'Processing';
                                                        $orderStatusClass = 'primary';
                                                    } elseif (in_array($orderStatusRaw, ['failed', 'cancelled'], true)) {
                                                        $orderStatusLabel = ucfirst($orderStatusRaw);
                                                        $orderStatusClass = 'danger';
                                                    } else {
                                                        $orderStatusLabel = ucfirst($orderStatusRaw ?: 'pending');
                                                        $orderStatusClass = 'warning';
                                                    }
                                                ?>
                                                <span class="badge badge-<?php echo $orderStatusClass; ?>">
                                                    <?php echo $orderStatusLabel; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, H:i', strtotime($transaction['created_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($recent_transactions)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">No transactions found</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (false): ?>
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
                                                ₵<?php echo number_format($network['total_sales'] ?? 0, 2); ?>
                                            </div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                ₵<?php echo number_format($network['commission_earned'] ?? 0, 2); ?> commission
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
                    <?php endif; ?>
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
            const salesCanvas = document.getElementById('salesChart');
            if (!salesCanvas) return;

            const salesCtx = salesCanvas.getContext('2d');
            const weeklySalesSets = {
                current: <?php echo json_encode($weekly_sales); ?>,
                previous: <?php echo json_encode($previous_weekly_sales); ?>
            };
            const toggleButtons = document.querySelectorAll('.weekly-sales-toggle');

            const salesChart = new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: (weeklySalesSets.current || []).map(d => d.short_day || d.day || ''),
                    datasets: [{
                        label: 'Sales (₵)',
                        data: (weeklySalesSets.current || []).map(d => d.sales || 0),
                        borderColor: '#8B5CF6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
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
                                    return 'Sales: ₵' + context.parsed.y.toLocaleString();
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
                                    return '₵' + value.toLocaleString();
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

            const applyWeeklySalesView = function(weekKey) {
                const selectedData = weeklySalesSets[weekKey] || [];
                salesChart.data.labels = selectedData.map(d => d.short_day || d.day || '');
                salesChart.data.datasets[0].data = selectedData.map(d => d.sales || 0);
                salesChart.update();

                toggleButtons.forEach(function(button) {
                    const isActive = button.dataset.week === weekKey;
                    button.classList.toggle('active', isActive);
                    button.classList.toggle('btn-primary', isActive);
                    button.classList.toggle('btn-outline', !isActive);
                });
            };

            toggleButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    applyWeeklySalesView(button.dataset.week || 'current');
                });
            });

            applyWeeklySalesView('current');
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


