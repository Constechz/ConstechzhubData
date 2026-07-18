<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';

// Require admin access for the profit intelligence view
requireRole('admin');

$current_user = getCurrentUser();
$today_profit = getProfitSummary(1);
$weekly_profit = getProfitSummary(7);
$monthly_profit = getProfitSummary(30);

$trend_window = 7;
$raw_profit_trends = getProfitTrends($trend_window);
$trend_map = [];
foreach ($raw_profit_trends as $row) {
    $trend_map[$row['profit_date']] = $row;
}

$profit_trends = [];
for ($i = $trend_window - 1; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $row = $trend_map[$date] ?? null;
    $profit_trends[] = [
        'date' => $date,
        'total_profit' => (float) ($row['total_profit'] ?? 0),
        'total_revenue' => (float) ($row['total_revenue'] ?? 0),
        'total_cost' => (float) ($row['total_cost'] ?? 0),
        'total_orders' => (int) ($row['total_orders'] ?? 0)
    ];
}

$profit_labels = array_map(function ($item) {
    return date('D, M j', strtotime($item['date']));
}, $profit_trends);
$profit_values = array_column($profit_trends, 'total_profit');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Intelligence - <?php echo SITE_NAME; ?></title>
    
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
                        <a href="reports.php" class="nav-link">
                            <i class="fas fa-chart-bar"></i>
                            Reports
                        </a>
                    </div>
                    <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
                    <div class="nav-item">
                        <a href="profit.php" class="nav-link active">
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
                        <div class="breadcrumb-item">Analytics</div>
                        <div class="breadcrumb-item active">Profit</div>
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
                    <h1>Profit Intelligence</h1>
                    <p class="page-subtitle">See how much the business keeps after wholesale costs each day.</p>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($today_profit['total_profit'] ?? 0); ?></h3>
                            <p>Today's Profit</p>
                            <p style="font-size: 0.85rem; color: var(--text-muted);">
                                Agent <?php echo formatCurrency($today_profit['agent_profit'] ?? 0); ?>
                                &middot; Customer <?php echo formatCurrency($today_profit['customer_profit'] ?? 0); ?>
                            </p>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">
                                Revenue <?php echo formatCurrency($today_profit['total_revenue'] ?? 0); ?>
                                &middot; Cost <?php echo formatCurrency($today_profit['total_cost'] ?? 0); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($weekly_profit['total_profit'] ?? 0); ?></h3>
                            <p>Weekly Profit</p>
                            <p style="font-size: 0.85rem; color: var(--text-muted);">
                                Agent <?php echo formatCurrency($weekly_profit['agent_profit'] ?? 0); ?>
                                &middot; Customer <?php echo formatCurrency($weekly_profit['customer_profit'] ?? 0); ?>
                            </p>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">
                                Orders <?php echo number_format($weekly_profit['total_orders'] ?? 0); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($monthly_profit['total_profit'] ?? 0); ?></h3>
                            <p>Monthly Profit</p>
                            <p style="font-size: 0.85rem; color: var(--text-muted);">
                                Agent <?php echo formatCurrency($monthly_profit['agent_profit'] ?? 0); ?>
                                &middot; Customer <?php echo formatCurrency($monthly_profit['customer_profit'] ?? 0); ?>
                            </p>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">
                                Revenue <?php echo formatCurrency($monthly_profit['total_revenue'] ?? 0); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Charts and Tables -->
                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Profit Trend (Last <?php echo $trend_window; ?> Days)</h3>
                            <div class="widget-actions">
                                <span style="font-size: 0.8rem; color: var(--text-muted);">
                                    Updated <?php echo date('F j, Y'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="widget-body">
                            <div class="chart-container">
                                <canvas id="profitChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Daily Profit Breakdown</h3>
                        </div>
                        <div class="widget-body">
                            <?php if (!empty($profit_trends)): ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Profit</th>
                                                <th>Revenue</th>
                                                <th>Cost</th>
                                                <th>Orders</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($profit_trends as $row): ?>
                                                <tr>
                                                    <td>
                                                        <div><?php echo date('D, M j', strtotime($row['date'])); ?></div>
                                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                            <?php echo $row['date']; ?>
                                                        </div>
                                                    </td>
                                                    <td><?php echo formatCurrency($row['total_profit']); ?></td>
                                                    <td><?php echo formatCurrency($row['total_revenue']); ?></td>
                                                    <td><?php echo formatCurrency($row['total_cost']); ?></td>
                                                    <td><?php echo number_format($row['total_orders']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 2rem; text-align: center;">
                                    <i class="fas fa-wallet" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                    <p style="color: var(--text-muted);">No profit data available yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
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
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
        
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }
        
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('show');
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
            const ctx = document.getElementById('profitChart').getContext('2d');
            const profitLabels = <?php echo json_encode($profit_labels); ?>;
            const profitData = <?php echo json_encode($profit_values); ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: profitLabels,
                    datasets: [{
                        label: 'Profit',
                        data: profitData,
                        borderColor: '#2E294E',
                        backgroundColor: 'rgba(46, 41, 78, 0.12)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 4,
                        pointBackgroundColor: '#2E294E'
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
                                    return 'Profit: ' + context.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(46, 41, 78, 0.08)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '???' + value.toLocaleString();
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
        });
    </script>
    
    <!-- Mobile Enhancement Script -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/mobile-enhancements.js')); ?>""></script>
</body>
</html>




