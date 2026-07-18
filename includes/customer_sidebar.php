<?php
// Shared Customer Sidebar template
// Accessible via: require_once '../includes/customer_sidebar.php';
?>
<!-- Sidebar -->
<nav class="sidebar">
    <div class="sidebar-brand">
        <h3><?php echo isset($agent_store) && $agent_store ? htmlspecialchars($agent_store['store_name']) : htmlspecialchars(getSiteName()); ?></h3>
        <?php if (isset($agent_store) && $agent_store && isset($agent_store['agent_name'])): ?>
            <small style="opacity: 0.7; font-size: 0.8rem;">by <?php echo htmlspecialchars($agent_store['agent_name']); ?></small>
        <?php endif; ?>
    </div>
    
    <ul class="sidebar-nav">
        <li class="nav-section">
            <div class="nav-section-title">Dashboard</div>
            <div class="nav-item">
                <a href="dashboard.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? ' active' : ''; ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </li>
        <li class="nav-section">
            <div class="nav-section-title">Services</div>
            <div class="nav-item">
                <a href="buy-data.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'buy-data.php' ? ' active' : ''; ?>">
                    <i class="fas fa-mobile-alt"></i> Buy Data
                </a>
            </div>
            <div class="nav-item">
                <a href="afa-registration.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'afa-registration.php' ? ' active' : ''; ?>">
                    <i class="fas fa-user-check"></i> AFA Registration
                </a>
            </div>
            <div class="nav-item">
                <a href="bulk-mtn.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'bulk-mtn.php' ? ' active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> Bulk MTN
                </a>
            </div>
            <div class="nav-item">
                <a href="result-checker.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo in_array(basename($_SERVER['PHP_SELF']), ['result-checker.php', 'result-checker-history.php'], true) ? ' active' : ''; ?>">
                    <i class="fas fa-award"></i> Result Checker
                </a>
            </div>
            <div class="nav-item">
                <a href="order-history.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'order-history.php' ? ' active' : ''; ?>">
                    <i class="fas fa-history"></i> Order History
                </a>
            </div>
            <div class="nav-item">
                <a href="reference.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'reference.php' ? ' active' : ''; ?>">
                    <i class="fas fa-search"></i> Reference
                </a>
            </div>
        </li>
        <li class="nav-section">
            <div class="nav-section-title">Account</div>
            <div class="nav-item">
                <a href="wallet.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'wallet.php' ? ' active' : ''; ?>">
                    <i class="fas fa-wallet"></i> Wallet
                </a>
            </div>
            <div class="nav-item">
                <a href="profile.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'profile.php' ? ' active' : ''; ?>">
                    <i class="fas fa-user"></i> Profile
                </a>
            </div>
            <div class="nav-item">
                <a href="support.php<?php echo isset($store_slug) && $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link<?php echo basename($_SERVER['PHP_SELF']) === 'support.php' ? ' active' : ''; ?>">
                    <i class="fas fa-life-ring"></i> Support
                </a>
            </div>
        </li>
        <li class="nav-section">
            <div class="nav-section-title">Settings</div>
            <div class="nav-item">
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </li>
    </ul>
</nav>
