<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

// Get date range for reports
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : date('Y-m-01'); // First day of current month
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : date('Y-m-d'); // Today

// Resolve legacy schema differences for transactions table
$transaction_type_column = 'transaction_type';
if (function_exists('dbh_table_has_column')) {
    if (dbh_table_has_column('transactions', 'transaction_type')) {
        $transaction_type_column = 'transaction_type';
    } elseif (dbh_table_has_column('transactions', 'type')) {
        $transaction_type_column = 'type';
    }
}
$transaction_type_column = in_array($transaction_type_column, ['transaction_type', 'type'], true)
    ? $transaction_type_column
    : 'transaction_type';
$has_commission_earned = function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'commission_earned');

$success_status_sql = "'success','completed'";
$debit_type_sql = "'debit','purchase','order_cost'";
$commission_sum_expr = $has_commission_earned
    ? "SUM(CASE WHEN t.status IN ({$success_status_sql}) THEN COALESCE(t.commission_earned, 0) ELSE 0 END)"
    : "SUM(CASE WHEN t.{$transaction_type_column} = 'commission' AND t.status IN ({$success_status_sql}) THEN t.amount ELSE 0 END)";
$has_order_id = function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'order_id');
$has_order_transaction_id = function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'transaction_id');
$order_join_condition = 't.order_id = bo.id';
if ($has_order_transaction_id && $has_order_id) {
    $order_join_condition = '(t.order_id = bo.id OR t.id = bo.transaction_id)';
} elseif ($has_order_transaction_id) {
    $order_join_condition = 't.id = bo.transaction_id';
} elseif ($has_order_id) {
    $order_join_condition = 't.order_id = bo.id';
}
$agent_transaction_conditions = ['t.user_id = u.id'];
if ($has_order_id) {
    $agent_transaction_conditions[] = 't.order_id = bo.id';
}
if ($has_order_transaction_id) {
    $agent_transaction_conditions[] = 't.id = bo.transaction_id';
}
$agent_transaction_join = implode(' OR ', $agent_transaction_conditions);

// Revenue Report
$revenue_query = "
    SELECT 
        DATE(t.created_at) as date,
        SUM(CASE WHEN t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql}) THEN t.amount ELSE 0 END) as daily_revenue,
        COUNT(CASE WHEN t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql}) THEN 1 END) as daily_orders
    FROM transactions t
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    GROUP BY DATE(t.created_at)
    ORDER BY DATE(t.created_at) DESC
";
$stmt = $db->prepare($revenue_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$revenue_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Network Performance
$network_query = "
    SELECT 
        n.name as network,
        COUNT(bo.id) as total_orders,
        SUM(t.amount) as total_revenue,
        AVG(t.amount) as avg_order_value
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    JOIN transactions t ON {$order_join_condition} AND t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql})
    WHERE DATE(bo.created_at) BETWEEN ? AND ?
    GROUP BY n.id, n.name
    ORDER BY total_revenue DESC
";
$stmt = $db->prepare($network_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$network_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Top Packages
$packages_query = "
    SELECT 
        dp.name as package_name,
        n.name as network,
        COUNT(bo.id) as total_orders,
        SUM(t.amount) as total_revenue
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    JOIN transactions t ON {$order_join_condition} AND t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql})
    WHERE DATE(bo.created_at) BETWEEN ? AND ?
    GROUP BY dp.id, dp.name, n.name
    ORDER BY total_orders DESC
    LIMIT 10
";
$stmt = $db->prepare($packages_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$packages_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Agent Performance
$agents_query = "
    SELECT 
        u.username,
        u.email,
        COUNT(bo.id) as total_orders,
        {$commission_sum_expr} as total_commissions,
        SUM(CASE WHEN t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql}) THEN t.amount ELSE 0 END) as total_sales
    FROM users u
    LEFT JOIN bundle_orders bo ON bo.agent_id = u.id
    LEFT JOIN transactions t ON ({$agent_transaction_join})
    WHERE u.role = 'agent' AND DATE(COALESCE(bo.created_at, t.created_at)) BETWEEN ? AND ?
    GROUP BY u.id, u.username, u.email
    HAVING total_orders > 0
    ORDER BY total_sales DESC
    LIMIT 10
";
$stmt = $db->prepare($agents_query);
$stmt->bind_param('ss', $date_from, $date_to);
$stmt->execute();
$agents_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Summary Statistics
$summary_query = "
    SELECT 
        COUNT(DISTINCT u.id) as total_users,
        COUNT(DISTINCT CASE WHEN u.role = 'customer' THEN u.id END) as total_customers,
        COUNT(DISTINCT CASE WHEN u.role = 'agent' THEN u.id END) as total_agents,
        COUNT(DISTINCT bo.id) as total_orders,
        SUM(CASE WHEN t.{$transaction_type_column} IN ({$debit_type_sql}) AND t.status IN ({$success_status_sql}) THEN t.amount ELSE 0 END) as total_revenue,
        {$commission_sum_expr} as total_commissions
    FROM users u
    LEFT JOIN bundle_orders bo ON (bo.user_id = u.id OR bo.agent_id = u.id) AND DATE(bo.created_at) BETWEEN ? AND ?
    LEFT JOIN transactions t ON t.user_id = u.id AND DATE(t.created_at) BETWEEN ? AND ?
";
$stmt = $db->prepare($summary_query);
$stmt->bind_param('ssss', $date_from, $date_to, $date_from, $date_to);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .report-filter-form {
            flex-wrap: wrap;
        }

        .report-charts,
        .report-tables {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .report-table {
            min-width: 0;
        }

        @media (max-width: 992px) {
            .report-charts,
            .report-tables {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body,
            .dashboard-wrapper,
            .main-content {
                overflow-x: hidden;
            }

            .report-filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .report-filter-form .form-group,
            .report-filter-form .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .table-responsive {
                overflow-x: hidden;
            }

            .report-table,
            .report-table thead,
            .report-table tbody,
            .report-table tr,
            .report-table td {
                display: block;
                width: 100%;
            }

            .report-table thead {
                display: none;
            }

            .report-table tbody tr {
                border: 1px solid var(--border-color, #F1E9DA);
                border-radius: 8px;
                padding: 0.75rem 1rem;
                margin-bottom: 1rem;
                background: var(--card-bg, #F1E9DA);
            }

            .report-table tbody td {
                border: none;
                padding: 0.45rem 0;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
                word-break: break-word;
            }

            .report-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-muted, #541388);
            }

            .report-charts canvas {
                max-width: 100%;
                height: auto !important;
            }

            [data-theme="dark"] .report-table tbody tr {
                background: #2E294E;
                border-color: #2E294E;
            }

            [data-theme="dark"] .report-table tbody td {
                color: #F1E9DA;
            }

            [data-theme="dark"] .report-table tbody td::before {
                color: #F1E9DA;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link active"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-chart-bar"></i></div>
                    <div class="breadcrumb-item">Transaction</div>
                    <div class="breadcrumb-item active">Reports</div>
                </nav>
            </div>
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
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

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Reports & Analytics</h1>
                <p class="page-subtitle">Business insights and performance metrics.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Date Range Filter -->
            <div class="widget" style="margin-bottom: 2rem;">
                <div class="widget-header">
                    <h3 class="widget-title">Date Range</h3>
                </div>
                <div class="widget-body">
                    <form method="get" class="form-inline report-filter-form" style="display:flex; gap: 1rem; align-items:center;">
                        <div class="form-group">
                            <label for="date_from">From:</label>
                            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="date_to">To:</label>
                            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Update Report
                        </button>
                    </form>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($summary['total_users']); ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($summary['total_orders']); ?></div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($summary['total_revenue'], 2); ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($summary['total_commissions'], 2); ?></div>
                        <div class="stat-label">Total Commissions</div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="report-charts" style="margin-bottom: 2rem;">
                <!-- Revenue Chart -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Daily Revenue</h3>
                    </div>
                    <div class="widget-body">
                        <canvas id="revenueChart" width="400" height="200"></canvas>
                    </div>
                </div>

                <!-- Network Performance Chart -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Network Performance</h3>
                    </div>
                    <div class="widget-body">
                        <canvas id="networkChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tables Row -->
            <div class="report-tables">
                <!-- Top Packages -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Top Packages</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>Package</th>
                                        <th>Network</th>
                                        <th>Orders</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($packages_data)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No data available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($packages_data as $pkg): ?>
                                        <tr>
                                            <td data-label="Package"><?php echo htmlspecialchars($pkg['package_name']); ?></td>
                                            <td data-label="Network"><span class="badge badge-info"><?php echo htmlspecialchars($pkg['network']); ?></span></td>
                                            <td data-label="Orders"><?php echo number_format($pkg['total_orders']); ?></td>
                                            <td data-label="Revenue"><?php echo CURRENCY . number_format($pkg['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Top Agents -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Top Agents</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table report-table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Orders</th>
                                        <th>Sales</th>
                                        <th>Commissions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($agents_data)): ?>
                                    <tr><td colspan="4" class="text-center text-muted">No data available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($agents_data as $agent): ?>
                                        <tr>
                                            <td data-label="Agent"><?php echo htmlspecialchars($agent['username']); ?></td>
                                            <td data-label="Orders"><?php echo number_format($agent['total_orders']); ?></td>
                                            <td data-label="Sales"><?php echo CURRENCY . number_format($agent['total_sales'], 2); ?></td>
                                            <td data-label="Commissions"><?php echo CURRENCY . number_format($agent['total_commissions'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
    
    // Theme management - consistent across all pages
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

    // Charts
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
        
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        const revenueData = <?php echo json_encode($revenue_data); ?>;
        
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueData.map(d => d.date),
                datasets: [{
                    label: 'Daily Revenue',
                    data: revenueData.map(d => parseFloat(d.daily_revenue)),
                    borderColor: '#F1E9DA',
                    backgroundColor: 'rgba(241, 233, 218, 0.2)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Network Chart
        const networkCtx = document.getElementById('networkChart').getContext('2d');
        const networkData = <?php echo json_encode($network_data); ?>;
        
        new Chart(networkCtx, {
            type: 'doughnut',
            data: {
                labels: networkData.map(d => d.network),
                datasets: [{
                    data: networkData.map(d => parseFloat(d.total_revenue)),
                    backgroundColor: [
                        'rgba(217, 3, 104, 0.8)',
                        'rgba(84, 19, 136, 0.8)',
                        'rgba(255, 212, 0, 0.8)',
                        'rgba(241, 233, 218, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



