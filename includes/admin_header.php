<?php
require_once __DIR__ . '/seo.php';
$faviconUrl = htmlspecialchars(getSeoFaviconUrl(), ENT_QUOTES, 'UTF-8');
$pendingAdminTopupCount = 0;
try {
    if (isset($db)) {
        $pendingTopupStmt = $db->prepare("SELECT COUNT(*) AS total_pending FROM topup_requests WHERE target_type = 'admin' AND status = 'pending'");
        if ($pendingTopupStmt && $pendingTopupStmt->execute()) {
            $pendingTopupRow = $pendingTopupStmt->get_result()->fetch_assoc();
            $pendingAdminTopupCount = (int) ($pendingTopupRow['total_pending'] ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('Admin header topup pending count failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/png" href="<?php echo $faviconUrl; ?>">
    <link rel="shortcut icon" href="<?php echo $faviconUrl; ?>">
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>?v=1.3">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>"></script>
    
    <!-- PWA Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('<?php echo htmlspecialchars(dbh_asset('sw.js'), ENT_QUOTES, 'UTF-8'); ?>')
                .then(function(registration) {
                    console.log('ServiceWorker registration successful with scope: ', registration.scope);
                })
                .catch(function(err) {
                    console.log('ServiceWorker registration failed: ', err);
                });
        });
    }

    // Defensive cleanup: remove stale System Reset sidebar links from any cached templates.
    document.addEventListener('DOMContentLoaded', function() {
        try {
            var staleLinks = document.querySelectorAll('.sidebar a[href*="system-reset.php"]');
            staleLinks.forEach(function(link) {
                var navItem = link.closest('.nav-item');
                if (navItem) {
                    navItem.remove();
                } else {
                    link.remove();
                }
            });
        } catch (e) {
            console.log('Sidebar cleanup skipped:', e);
        }
    });
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
                        <span class="user-avatar"><?php echo strtoupper(substr(getCurrentUser()['full_name'], 0, 1)); ?></span>
                        <span class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars(getCurrentUser()['full_name']); ?></span>
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
