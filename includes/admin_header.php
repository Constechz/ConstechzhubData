<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>?v=<?php echo time(); ?>">
    
    <!-- Font Awesome Stylesheet (Loaded Directly for maximum reliability across all devices) -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>?v=<?php echo time(); ?>">
    
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
                <div class="nav-item"><a href="dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'pricing.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'afa-registration.php' ? 'active' : ''; ?>"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agents.php' ? 'active' : ''; ?>"><i class="fas fa-user-tie"></i> Agents</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'transactions.php' ? 'active' : ''; ?>"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'epayment.php' ? 'active' : ''; ?>"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="notifications.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>"><i class="fas fa-bell"></i> Notification Settings</a></div>
                <div class="nav-item"><a href="settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-change-requests.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'email-change-requests.php' ? 'active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Email Change Requests</a></div>
                <div class="nav-item"><a href="seo-settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'seo-settings.php' ? 'active' : ''; ?>"><i class="fas fa-globe"></i> SEO Settings</a></div>
                <div class="nav-item"><a href="smtp-settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'smtp-settings.php' ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> SMTP Email Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'email-broadcast.php' ? 'active' : ''; ?>"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="api-providers.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'api-providers.php' ? 'active' : ''; ?>"><i class="fas fa-plug"></i> API Providers</a></div>
                <?php if (file_exists('pwa-settings.php')): ?>
                <div class="nav-item"><a href="pwa-settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'pwa-settings.php' ? 'active' : ''; ?>"><i class="fas fa-mobile-alt"></i> PWA Settings</a></div>
                <?php endif; ?>
                <?php if (file_exists('sms-settings.php')): ?>
                <div class="nav-item"><a href="sms-settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sms-settings.php' ? 'active' : ''; ?>"><i class="fas fa-sms"></i> SMS Settings</a></div>
                <?php endif; ?>
                <div class="nav-item"><a href="sms-broadcast.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sms-broadcast.php' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> SMS Broadcasts</a></div>
                <?php if (file_exists('health-check.php')): ?>
                <div class="nav-item"><a href="health-check.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'health-check.php' ? 'active' : ''; ?>"><i class="fas fa-heartbeat"></i> System Health</a></div>
                <?php endif; ?>
                <?php if (file_exists('wallet-reset.php')): ?>
                <div class="nav-item"><a href="wallet-reset.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'wallet-reset.php' ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> Wallet Reset</a></div>
                <?php endif; ?>
                <?php if (file_exists('system-reset.php')): ?>
                <div class="nav-item"><a href="system-reset.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'system-reset.php' ? 'active' : ''; ?>"><i class="fas fa-broom"></i> System Reset</a></div>
                <?php endif; ?>
                <?php if (file_exists('commission-payouts.php')): ?>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'commission-payouts.php' ? 'active' : ''; ?>"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
                <?php endif; ?>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="breadcrumb-item">Admin</div>
                    <?php if (isset($pageTitle)): ?>
                    <div class="breadcrumb-item active"><?php echo htmlspecialchars($pageTitle); ?></div>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode">
                    <i class="fas fa-moon theme-icon" id="theme-icon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" type="button" onclick="toggleUserDropdown()" aria-haspopup="true" aria-expanded="false">
                        <?php 
                        $headerUser = getCurrentUser();
                        $headerUserName = !empty($headerUser['full_name']) ? $headerUser['full_name'] : (!empty($_SESSION['username']) ? $_SESSION['username'] : 'Admin');
                        $headerUserInitial = strtoupper(substr($headerUserName, 0, 1));
                        ?>
                        <span class="user-avatar"><?php echo htmlspecialchars($headerUserInitial); ?></span>
                        <span class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($headerUserName); ?></span>
                            <span class="user-role">Administrator</span>
                        </span>
                        <span class="dropdown-arrow"><i class="fas fa-chevron-down"></i></span>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown" role="menu">
                        <a href="profile.php" class="dropdown-item" role="menuitem"><i class="fas fa-user"></i> Profile</a>
                        <a href="settings.php" class="dropdown-item" role="menuitem"><i class="fas fa-cog"></i> Settings</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content"><?php // Page content starts here ?>

