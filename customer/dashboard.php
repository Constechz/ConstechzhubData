<?php
require_once '../config/config.php';
require_once '../includes/analytics.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
if (!$current_user) {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}
$customer_id = $current_user['id'];
$stats = getDashboardStats($customer_id, 'customer');

// Get dynamic analytics data for customer
$recent_transactions = getRecentTransactions($customer_id, 'customer', 10);

// If no store context provided, redirect to the agent's active store when available
// This keeps customers within the agent store context instead of the global dashboard
try {
    $store_slug = $_GET['store'] ?? null;
    // Only redirect when NO store parameter is provided in URL at all
    if (!isset($_GET['store'])) {
        // Check if users.agent_id exists and resolve an active agent store
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'agent_id'");
        if ($colCheck && $colCheck->num_rows > 0) {
            // Safely fetch agent store by the user's agent_id
            $stmt = $db->prepare(
                "SELECT ast.store_slug
                 FROM users u
                 JOIN agent_stores ast ON ast.agent_id = u.agent_id AND ast.is_active = 1
                 WHERE u.id = ?
                 LIMIT 1"
            );
            $stmt->bind_param("i", $current_user['id']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $redirectUrl = SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']);
                // Clear any existing flash messages before redirect to prevent logout message from appearing
                unset($_SESSION['flash_message']);
                header("Location: " . $redirectUrl);
                exit;
            }
        } else {
            // Fallback: attempt via user_referrals if present
            $tblCheck = $db->query("SHOW TABLES LIKE 'user_referrals'");
            if ($tblCheck && $tblCheck->num_rows > 0) {
                $stmt = $db->prepare(
                    "SELECT ast.store_slug
                     FROM user_referrals ur
                     JOIN agent_stores ast ON ast.agent_id = ur.agent_id AND ast.is_active = 1
                     WHERE ur.user_id = ?
                     ORDER BY ur.created_at DESC
                     LIMIT 1"
                );
                $stmt->bind_param("i", $current_user['id']);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $redirectUrl = SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']);
                    // Clear any existing flash messages before redirect to prevent logout message from appearing
                    unset($_SESSION['flash_message']);
                    header("Location: " . $redirectUrl);
                    exit;
                }
            }
        }
    }
} catch (Exception $e) {
    // Fail open: if schema pieces are missing, continue rendering dashboard normally
}

// Check if accessing via agent store link
$store_slug = $_GET['store'] ?? null;
$agent_store = null;
$agent_pricing = [];

if ($store_slug) {
    // Get agent store information
    $stmt = $db->prepare("
        SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $agent_store = $row;
        
        // Get agent's custom pricing
        $stmt = $db->prepare("
            SELECT package_id, custom_price 
            FROM agent_custom_pricing 
            WHERE agent_id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $agent_store['agent_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $agent_pricing[$row['package_id']] = $row['custom_price'];
        }
    }
}

// Get wallet balance
$wallet_balance = getWalletBalance($current_user['id']);

// Get flash message for display
$flash = getFlashMessage();

// Get recent orders for this customer
$recent_orders = [];
try {
    $stmt = $db->prepare("
        SELECT bo.*, dp.name as package_name, n.name as network_name, dp.price 
        FROM bundle_orders bo 
        JOIN data_packages dp ON bo.package_id = dp.id 
        JOIN networks n ON n.id = dp.network_id
        WHERE bo.user_id = ?
        ORDER BY bo.created_at DESC 
        LIMIT 5
    ");
    $stmt->bind_param("i", $current_user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_orders[] = $row;
    }
} catch (Exception $e) {
    // Handle case where tables don't exist yet
    $recent_orders = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard - <?php echo SITE_NAME; ?></title>
    
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
    
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
                <h3><?php echo $agent_store ? htmlspecialchars($agent_store['store_name']) : htmlspecialchars(getSiteName()); ?></h3>
                <?php if ($agent_store): ?>
                    <small style="opacity: 0.7; font-size: 0.8rem;">by <?php echo htmlspecialchars($agent_store['agent_name']); ?></small>
                <?php endif; ?>
            </div>
            
            <ul class="sidebar-nav">
                <li class="nav-section">
                    <div class="nav-section-title">Dashboard</div>
                    <div class="nav-item">
                        <a href="dashboard.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link active">
                            <i class="fas fa-home"></i>
                            Dashboard
                        </a>
                    </div>
                </li>
                
            <li class="nav-section">
                <div class="nav-section-title">Services</div>
                <div class="nav-item">
                    <a href="buy-data.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        Buy Data
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                <div class="nav-item">
                    <a href="result-checker.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-award"></i>
                        Result Checker
                    </a>
                </div>
                <div class="nav-item">
                    <a href="afa-registration.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        AFA Registration
                    </a>
                </div>
                <div class="nav-item">
                    <a href="order-history.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-history"></i>
                        Order History
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item">
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Wallet
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                            <i class="fas fa-user"></i>
                            Profile
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="constchat.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                            <i class="fas fa-comments"></i>
                            Constchat
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="support.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                            <i class="fas fa-life-ring"></i>
                            Support
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
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Customer</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>
                        
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="#" class="dropdown-item">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="#" class="dropdown-item">
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
                <?php echo renderNotificationSlides('customers'); ?>
                
                <div class="page-title">
                    <h1>Welcome back!</h1>
                    <p class="page-subtitle">Hello, <?php echo htmlspecialchars($current_user['full_name']); ?>!</p>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency(getWalletBalance($current_user['id'])); ?></h3>
                            <p>Wallet Balance</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                            <p>Total Orders</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($stats['total_spent'] ?? 0); ?></h3>
                            <p>Total Spent</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Active</h3>
                            <p>Account Status</p>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="dashboard-grid">
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Quick Actions</h3>
                        </div>
                        <div class="widget-body">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1rem;">
                                <a href="buy-data.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-primary" style="text-decoration: none; text-align: center; padding: 1rem;">
                                    <i class="fas fa-mobile-alt" style="display: block; font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
                                    Buy Data Bundle
                                </a>
                                <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-outline" style="text-decoration: none; text-align: center; padding: 1rem;">
                                    <i class="fas fa-plus" style="display: block; font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
                                    Fund Wallet
                                </a>
                                <a href="order-history.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-outline" style="text-decoration: none; text-align: center; padding: 1rem;">
                                    <i class="fas fa-history" style="display: block; font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
                                    View History
                                </a>
                            </div>
                        </div>
                    </div>

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
                                        <?php if (empty($recent_transactions)): ?>
                                            <tr><td colspan="6" class="text-center text-muted">No transactions found</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_transactions as $transaction): ?>
                                                <?php
                                                    $reference = $transaction['reference_display'] ?? $transaction['reference'] ?? '';
                                                    if (!$reference || strtoupper($reference) === 'N/A') {
                                                        if (!empty($transaction['order_id'])) {
                                                            $reference = '#' . str_pad((int) $transaction['order_id'], 6, '0', STR_PAD_LEFT);
                                                        } elseif (!empty($transaction['id'])) {
                                                            $reference = 'TXN-' . str_pad((int) $transaction['id'], 6, '0', STR_PAD_LEFT);
                                                        }
                                                    }
                                                    $beneficiary = $transaction['beneficiary_number'] ?? '';
                                                    if (empty($beneficiary) && !empty($transaction['description'])) {
                                                        if (preg_match('/(233\\d{9}|0\\d{9})/', $transaction['description'], $m)) {
                                                            $beneficiary = $m[0];
                                                        }
                                                    }
                                                    $statusRaw = strtolower($transaction['status_display'] ?? $transaction['status'] ?? 'pending');
                                                    $isDelivered = in_array($statusRaw, ['success', 'completed', 'delivered'], true);
                                                    $statusVal = $isDelivered ? 'Delivered' : 'Pending';
                                                    $statusClass = $isDelivered ? 'success' : 'warning';
                                                    $statusTime = !empty($transaction['created_at']) ? date('g:i A', strtotime($transaction['created_at'])) : '';
                                                    $typeLabel = $transaction['transaction_type_display'] ?? $transaction['transaction_type'] ?? 'purchase';
                                                ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars($reference ?: 'N/A'); ?></code></td>
                                                    <td><?php echo htmlspecialchars($beneficiary ?: 'N/A'); ?></td>
                                                    <td><span class="badge badge-secondary"><?php echo ucfirst(str_replace('_', ' ', $typeLabel)); ?></span></td>
                                                    <td><?php echo formatCurrency($transaction['amount']); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $statusClass; ?>">
                                                            <?php echo $statusVal; ?>
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
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Orders -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Recent Orders</h3>
                        </div>
                        <div class="widget-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Order ID</th>
                                            <th>Package</th>
                                            <th>Network</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_orders)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No orders found</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_orders as $order): ?>
                                            <tr>
                                                <td><?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                <td><?php echo htmlspecialchars($order['package_name']); ?></td>
                                                <td><?php echo htmlspecialchars($order['network_name']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $order['status'] === 'success' ? 'success' : ($order['status'] === 'failed' ? 'danger' : 'warning'); ?>">
                                                        <?php echo ucfirst($order['status']); ?>
                                                    </span>
                                                </td>
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
        
        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
        });
    </script>
    
    <!-- Notification Slider JavaScript -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>""></script>
</body>
</html>

