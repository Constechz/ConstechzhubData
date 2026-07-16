<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
ensureDataPackageStockStatusColumn();
$wallet_balance = getWalletBalance($current_user['id']);
$customer_pricing_type = getCustomerPricingUserType($current_user);
$is_vip_portal = (defined('VIP_PORTAL') && VIP_PORTAL) || $customer_pricing_type === 'vip';
$portal_role_label = $is_vip_portal ? 'VIP' : 'Customer';
$paystack_direct_enabled = isPaymentGatewayEnabled('paystack');
$customer_bundle_paystack_init_endpoint = '../api/customer_bundle_paystack_init.php';
$moolre_direct_enabled = isPaymentGatewayEnabled('moolre');
$customer_bundle_moolre_init_endpoint = '../api/customer_bundle_moolre_init.php';
$at_ghana_logo_png = dbh_asset('assets/images/airtel-tigo-logo.png');
$at_ghana_logo_svg = dbh_asset('assets/images/airtel-tigo-logo.png');
$mtn_logo_png = dbh_asset('assets/images/mtn-logo.png');
$mtn_logo_svg = dbh_asset('assets/images/mtn-logo.svg');
$telecel_logo_png = dbh_asset('assets/images/telecel-logo.png');
$telecel_logo_svg = dbh_asset('assets/images/telecel-logo.svg');

// Process order if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package_id'])) {
    // Debug customer purchase request
    error_log('Customer Purchase Debug - POST data: ' . print_r($_POST, true));
    error_log('Customer Purchase Debug - User ID: ' . $current_user['id']);
    error_log('Customer Purchase Debug - Wallet Balance: ' . $wallet_balance);
    
    // Clear any existing flash messages ONLY if they are error messages to prevent interference
    if (isset($_SESSION['flash_message']) && $_SESSION['flash_message']['type'] === 'error') {
        unset($_SESSION['flash_message']);
    }
    
    // Include the order processing logic
    require_once '../api/process_customer_order.php';
    exit; // process_customer_order.php handles redirect
}

// If no store context provided, redirect to the agent's active store when available
try {
    $store_slug_guard = $_GET['store'] ?? null;
    if (!$is_vip_portal && empty($store_slug_guard)) {
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'agent_id'");
        if ($colCheck && $colCheck->num_rows > 0) {
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
                // Only clear flash messages if it's not a purchase success message
                if (isset($_SESSION['flash_message']) && $_SESSION['flash_message']['type'] !== 'success') {
                    unset($_SESSION['flash_message']);
                }
                header("Location: " . SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']));
                exit;
            }
        } else {
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
                    // Only clear flash messages if it's not a purchase success message
                    if (isset($_SESSION['flash_message']) && $_SESSION['flash_message']['type'] !== 'success') {
                        unset($_SESSION['flash_message']);
                    }
                    header("Location: " . SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']));
                    exit;
                }
            }
        }
    }
} catch (Exception $e) { /* fail open */ }

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

// Get all available packages with pricing (agent custom pricing if available, otherwise customer pricing)
// Handle both new package_pricing table and legacy data_packages.price column
// Added GROUP BY to prevent duplicates
$packages_query = "
    SELECT dp.id, dp.name, 
           COALESCE(n.name, 'Unknown') AS network_name, 
           COALESCE(n.color, '#007bff') as network_color, 
           dp.package_type, dp.data_size, dp.validity_days,
           COALESCE(dp.stock_status, 'in_stock') AS stock_status,
           COALESCE(pp.price, pp_customer_fallback.price, dp.price, 0) as customer_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = ?
    LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = 'customer'
    WHERE (pp.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0) AND dp.status = 'active'
    GROUP BY dp.id, dp.name, COALESCE(n.name, 'Unknown'), COALESCE(n.color, '#007bff'),
             dp.package_type, dp.data_size, dp.validity_days, dp.stock_status, COALESCE(pp.price, pp_customer_fallback.price, dp.price, 0)
    ORDER BY COALESCE(n.name, 'Unknown'), dp.package_type
";

$packages_stmt = $db->prepare($packages_query);
$packages_stmt->bind_param('s', $customer_pricing_type);
$packages_stmt->execute();
$packages_rs = $packages_stmt->get_result();
$packages = [];
while ($row = $packages_rs->fetch_assoc()) {
    // Use agent custom pricing if available, otherwise use customer pricing
    if ($customer_pricing_type !== 'vip' && $agent_store && isset($agent_pricing[$row['id']])) {
        $row['display_price'] = $agent_pricing[$row['id']];
        $row['is_agent_price'] = true;
    } else {
        $row['display_price'] = $row['customer_price'];
        $row['is_agent_price'] = false;
    }
    $packages[] = $row;
}

// Sort packages using PHP comparison function to avoid database engine differences
if (function_exists('dbh_compare_packages')) {
    usort($packages, 'dbh_compare_packages');
}

// Group packages by network
$packages_by_network = [];
foreach ($packages as $package) {
    $packages_by_network[$package['network_name']][] = $package;
}

function isCustomerMtnStoreNetwork($networkName) {
    return stripos((string) $networkName, 'mtn') !== false;
}

function getCustomerMtnSubtitle($networkName, array $package) {
    $name = trim((string) ($package['name'] ?? ''));
    $dataSize = trim((string) ($package['data_size'] ?? ''));
    if ($name !== '' && strcasecmp($name, $dataSize) !== 0) {
        return $name;
    }

    return trim((string) $networkName) . ' Master Internet';
}

function isCustomerAtStoreNetwork($networkName) {
    $normalized = strtolower(trim((string) $networkName));
    return $normalized === 'at' || strpos($normalized, 'airtel') !== false || strpos($normalized, 'tigo') !== false;
}

function getCustomerAtSubtitleLines(array $package) {
    $name = trim((string) ($package['name'] ?? ''));
    $dataSize = trim((string) ($package['data_size'] ?? ''));
    if ($name === '' || strcasecmp($name, $dataSize) === 0) {
        $name = 'AIRTEL-TIGO Premium(iShare)';
    }

    $name = preg_replace('/\s+/', ' ', $name);
    $parts = preg_split('/\s+(?=premium)/i', $name, 2);
    if (count($parts) === 2) {
        return [trim($parts[0]), trim($parts[1])];
    }

    return ['AIRTEL-TIGO', trim($name)];
}

function isCustomerTelecelStoreNetwork($networkName) {
    $normalized = strtolower(trim((string) $networkName));
    return strpos($normalized, 'telecel') !== false || strpos($normalized, 'vodafone') !== false;
}

function getCustomerTelecelSubtitle($networkName) {
    if (stripos((string) $networkName, 'telecel') !== false || stripos((string) $networkName, 'vodafone') !== false) {
        return 'TELECEL';
    }

    return strtoupper(trim((string) $networkName));
}

function getCustomerNetworkViewKey($networkName) {
    if (isCustomerMtnStoreNetwork($networkName)) {
        return 'mtn';
    }
    if (isCustomerAtStoreNetwork($networkName)) {
        return 'at';
    }
    if (isCustomerTelecelStoreNetwork($networkName)) {
        return 'telecel';
    }
    return strtolower(trim((string) $networkName));
}

function getCustomerNetworkDisplayName($networkName) {
    if (isCustomerMtnStoreNetwork($networkName)) {
        return 'MTN';
    }
    if (isCustomerAtStoreNetwork($networkName)) {
        return 'Airtel Tigo';
    }
    if (isCustomerTelecelStoreNetwork($networkName)) {
        return 'Telecel';
    }
    return trim((string) $networkName);
}

$flash = getFlashMessage();

// Generate a single-use order submission token to prevent duplicate submissions on refresh
$order_submit_token = bin2hex(random_bytes(32));
$_SESSION['order_submit_token'] = $order_submit_token;

$preselected_package_id = 0;
if (isset($_GET['package'])) {
    $preselected_package_id = (int) $_GET['package'];
} elseif (!empty($_SESSION['pending_guest_package_id'])) {
    $preselected_package_id = (int) $_SESSION['pending_guest_package_id'];
    unset($_SESSION['pending_guest_package_id']);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buy Data - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body class="customer-buy-data-page">
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
            <div class="sidebar-brand">
                <h3><?php echo $is_vip_portal ? htmlspecialchars(getSiteName()) : ($agent_store ? htmlspecialchars($agent_store['store_name']) : htmlspecialchars(getSiteName())); ?></h3>
                <?php if ($is_vip_portal): ?>
                    <small style="opacity: 0.7; font-size: 0.8rem;">VIP Portal</small>
                <?php elseif ($agent_store): ?>
                    <small style="opacity: 0.7; font-size: 0.8rem;">by <?php echo htmlspecialchars($agent_store['agent_name']); ?></small>
                <?php endif; ?>
            </div>
        
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item">
                    <a href="dashboard.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Services</div>
                <div class="nav-item">
                    <a href="buy-data.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link active">
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
                    <a href="support.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-life-ring"></i>
                        Support
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </li>
        </ul>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-mobile-alt"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Buy Data</div>
                </nav>
            </div>
            <div class="header-actions">
                <div class="wallet-balance">
                    <i class="fas fa-wallet"></i>
                    <span>Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?></span>
                </div>
                <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-sm btn-primary header-action-btn topup-btn">
                    <i class="fas fa-plus-circle"></i> Top Up
                </a>
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'] ?? $_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($portal_role_label); ?></div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
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

<?php echo renderNotificationSlides('customers'); ?>


        <div class="dashboard-content">
            <div class="page-title">
                <h1>Buy Data Bundles</h1>
                <p class="page-subtitle">
                    <?php if ($agent_store): ?>
                        Purchase data bundles from <?php echo htmlspecialchars($agent_store['store_name']); ?> for AT, MTN, and Telecel networks.
                    <?php else: ?>
                        Purchase data bundles for AT, MTN, and Telecel networks.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Wallet Balance Alert -->
            <?php if ($wallet_balance <= 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    Your wallet balance is low.
                    <?php if ($paystack_direct_enabled): ?>
                        You can still continue with Paystack checkout, or top up your wallet first.
                    <?php else: ?>
                        Please top up your wallet to purchase data bundles.
                    <?php endif; ?>
                    <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-sm btn-primary" style="margin-left: 1rem;">Top Up Wallet</a>
                </div>
            <?php endif; ?>

            <!-- Network Tabs -->
            <div class="network-tabs">
                <?php $first = true; foreach ($packages_by_network as $network => $network_packages): ?>
                    <?php $network_view_key = getCustomerNetworkViewKey($network); ?>
                    <button class="network-tab <?php echo $first ? 'active' : ''; ?>" 
                            data-network="<?php echo htmlspecialchars($network_view_key); ?>"
                            onclick="showNetwork('<?php echo htmlspecialchars($network_view_key); ?>')">
                        <?php if ($network_view_key === 'mtn'): ?>
                            <span class="network-tab-logo">
                                <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/mtn-logo.svg')); ?>" alt="MTN logo">
                            </span>
                        <?php elseif ($network_view_key === 'at'): ?>
                            <span class="network-tab-logo">
                                <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/at-logo.svg')); ?>" alt="Airtel Tigo logo">
                            </span>
                        <?php elseif ($network_view_key === 'telecel'): ?>
                            <span class="network-tab-logo">
                                <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/telecel-logo.svg')); ?>" alt="Telecel logo">
                            </span>
                        <?php else: ?>
                            <i class="fas fa-signal"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars(getCustomerNetworkDisplayName($network)); ?>
                        <span class="package-count"><?php echo count($network_packages); ?></span>
                    </button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>

            <!-- Package Grids by Network -->
            <?php $first = true; foreach ($packages_by_network as $network => $network_packages): ?>
                <?php
                    $network_view_key = getCustomerNetworkViewKey($network);
                    $network_display_name = getCustomerNetworkDisplayName($network);
                    $is_mtn_network = isCustomerMtnStoreNetwork($network);
                    $is_at_network = isCustomerAtStoreNetwork($network);
                    $is_telecel_network = isCustomerTelecelStoreNetwork($network);
                ?>
                <div id="<?php echo htmlspecialchars($network_view_key); ?>-packages" 
                     class="network-packages customer-network-group <?php echo $first ? 'active' : ''; ?><?php
                        echo $is_mtn_network
                            ? ' network-group-mtn'
                            : ($is_at_network
                                ? ' network-group-at'
                                : ($is_telecel_network ? ' network-group-telecel' : ''));
                     ?>">
                    <div class="service-page-header service-card-<?php echo htmlspecialchars($network_view_key); ?>">
                        <div class="service-page-copy">
                            <span class="service-selector-kicker">Service Page</span>
                            <h2><?php echo htmlspecialchars(strtoupper($network_display_name . ' Bundles')); ?></h2>
                            <p>
                                <?php if ($agent_store): ?>
                                    Browse and order only this service from <?php echo htmlspecialchars($agent_store['store_name']); ?>.
                                <?php else: ?>
                                    Browse and order only this service from your customer dashboard.
                                <?php endif; ?>
                            </p>
                        </div>
                        <button type="button" class="btn btn-outline service-selector-reset" onclick="document.querySelector('.network-tabs').scrollIntoView({ behavior: 'smooth', block: 'nearest' });">
                            <i class="fas fa-layer-group"></i>
                            Switch Network
                        </button>
                    </div>

                    <?php if ($network === 'MTN'): ?>
                        <div class="widget" style="margin-bottom: 1.5rem;">
                            <div class="widget-header">
                                <h3 class="widget-title">MTN Bulk Text Orders</h3>
                            </div>
                            <div class="widget-body">
                                <div class="form-group">
                                    <label class="form-label">Paste numbers and bundles</label>
                                    <textarea id="customerBulkTextInput" class="form-control" rows="6" placeholder="0240000000 1&#10;0540000000 2"></textarea>
                                    <small style="color: var(--text-muted);">One order per line. Format: phone and GB (space-separated). Example: 0240000000 1.</small>
                                </div>
                                <div class="form-group bulk-text-actions">
                                    <button type="button" class="btn btn-outline" onclick="previewCustomerBulkTextOrders()" style="flex: 1;">
                                        <i class="fas fa-eye"></i> Preview Orders
                                    </button>
                                    <button type="button" class="btn btn-primary" id="processCustomerBulkTextBtn" onclick="processCustomerBulkTextOrders()" style="flex: 1;" disabled>
                                        <i class="fas fa-paper-plane"></i> Process Orders
                                    </button>
                                </div>
                                <div id="customerBulkTextSummary" style="margin-top: 0.75rem; color: var(--text-muted);"></div>
                                <div id="customerBulkTextErrors" style="margin-top: 0.5rem; color: var(--accent-red);"></div>
                                <div id="customerBulkTextPreview" style="margin-top: 1rem; display: none;">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Phone</th>
                                                    <th>Bundle</th>
                                                    <th>Price</th>
                                                </tr>
                                            </thead>
                                            <tbody id="customerBulkTextPreviewBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div style="margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                    <strong>Help:</strong> One order per line. Use <code>0240000000 1</code>. MTN numbers only.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="packages-grid">
                        <?php foreach ($network_packages as $package): ?>
                            <?php
                                $package_badge_label = $package['is_agent_price'] ? ($agent_store ? 'Store Offer' : 'Agent Price') : '';
                                $mtn_subtitle = getCustomerMtnSubtitle($network, $package);
                                $at_subtitle_lines = getCustomerAtSubtitleLines($package);
                                $telecel_subtitle = getCustomerTelecelSubtitle($network);
                                $beneficiary_placeholder = 'e.g. 0241234567';
                                if (($package['network_name'] ?? '') === 'AT') {
                                    $beneficiary_placeholder = 'e.g. 0261234567';
                                } elseif (($package['network_name'] ?? '') === 'Telecel') {
                                    $beneficiary_placeholder = 'e.g. 0201234567';
                                }
                                $is_out_of_stock = (($package['stock_status'] ?? 'in_stock') === 'out_of_stock');
                            ?>
                            <div class="package-card<?php
                                echo $is_mtn_network
                                    ? ' package-card-mtn'
                                    : ($is_at_network
                                        ? ' package-card-at'
                                        : ($is_telecel_network ? ' package-card-telecel' : ''));
                                echo $is_out_of_stock ? ' package-card-out-of-stock' : '';
                            ?>" id="package-card-<?php echo $package['id']; ?>" data-stock-status="<?php echo htmlspecialchars($package['stock_status'] ?? 'in_stock'); ?>">
                                <?php if ($is_mtn_network): ?>
                                    <div class="package-card-mtn-head">
                                        <div class="package-card-mtn-logo">
                                            <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/mtn-logo.svg')); ?>" alt="MTN logo">
                                        </div>
                                        <div class="package-card-mtn-copy">
                                            <h4><?php echo htmlspecialchars($package['data_size']); ?></h4>
                                            <p><?php echo htmlspecialchars($mtn_subtitle); ?></p>
                                        </div>
                                    </div>
                                    <div class="package-price package-price-mtn">
                                        GHS <?php echo number_format((float) ($package['display_price'] ?? 0), 2); ?>
                                        <?php if ($package_badge_label !== ''): ?>
                                            <span class="custom-price-badge"><?php echo htmlspecialchars($package_badge_label); ?></span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($is_at_network): ?>
                                    <div class="package-card-at-head">
                                        <div class="package-card-at-logo">
                                            <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/at-logo.svg')); ?>" alt="AT logo">
                                        </div>
                                        <div class="package-card-at-copy">
                                            <h4><?php echo htmlspecialchars($package['data_size']); ?></h4>
                                            <p>
                                                <span><?php echo htmlspecialchars($at_subtitle_lines[0] ?? 'AIRTEL-TIGO'); ?></span>
                                                <span><?php echo htmlspecialchars($at_subtitle_lines[1] ?? 'Premium(iShare)'); ?></span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="package-price package-price-at">
                                        GHS <?php echo number_format((float) ($package['display_price'] ?? 0), 2); ?>
                                        <?php if ($package_badge_label !== ''): ?>
                                            <span class="custom-price-badge"><?php echo htmlspecialchars($package_badge_label); ?></span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($is_telecel_network): ?>
                                    <div class="package-card-telecel-head">
                                        <div class="package-card-telecel-logo">
                                            <img src="<?php echo htmlspecialchars(dbh_asset('assets/images/telecel-logo.svg')); ?>" alt="Telecel logo">
                                        </div>
                                        <div class="package-card-telecel-copy">
                                            <h4><?php echo htmlspecialchars($package['data_size']); ?></h4>
                                            <p><?php echo htmlspecialchars($telecel_subtitle); ?></p>
                                        </div>
                                    </div>
                                    <div class="package-price package-price-telecel">
                                        GHS <?php echo number_format((float) ($package['display_price'] ?? 0), 2); ?>
                                        <?php if ($package_badge_label !== ''): ?>
                                            <span class="custom-price-badge"><?php echo htmlspecialchars($package_badge_label); ?></span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="package-header">
                                        <div class="package-network">
                                            <span class="network-indicator" style="background-color: <?php echo htmlspecialchars($package['network_color'] ?? '#007bff'); ?>"></span>
                                            <span><?php echo htmlspecialchars($package['network_name']); ?></span>
                                        </div>
                                        <div class="package-type">
                                            <span class="badge badge-info"><?php echo ucfirst($package['package_type']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="package-body">
                                        <h3 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h3>
                                        <div class="package-details">
                                            <div class="detail-item">
                                                <i class="fas fa-database"></i>
                                                <span><?php echo htmlspecialchars($package['data_size']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <i class="fas fa-calendar"></i>
                                                <span><?php echo intval($package['validity_days']); ?> days</span>
                                            </div>
                                        </div>
                                        
                                        <div class="package-price">
                                            <span class="price-label">Price:</span>
                                            <span class="price-value">
                                                <?php echo CURRENCY . number_format((float)($package['display_price'] ?? 0), 2); ?>
                                                <?php if ($package_badge_label !== ''): ?>
                                                    <small class="agent-price-badge"><?php echo htmlspecialchars($package_badge_label); ?></small>
                                                <?php endif; ?>
                                                <?php if ($is_out_of_stock): ?>
                                                    <small class="stock-status-badge">Out of Stock</small>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="package-footer<?php echo ($is_mtn_network || $is_at_network || $is_telecel_network) ? ' package-footer-store' : ''; ?>">
                                    <button class="btn btn-primary btn-block<?php
                                        echo $is_mtn_network
                                            ? ' package-card-mtn-btn'
                                            : ($is_at_network
                                                ? ' package-card-at-btn'
                                                : ($is_telecel_network ? ' package-card-telecel-btn' : ''));
                                    ?>" 
                                            type="button"
                                            <?php echo $is_out_of_stock ? 'disabled' : ''; ?>
                                            onclick="buyPackage(<?php echo $package['id']; ?>)">
                                        <i class="fas <?php echo $is_out_of_stock ? 'fa-ban' : 'fa-shopping-cart'; ?>"></i> 
                                        <?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy Now'; ?>
                                    </button>
                                    <?php if (!$is_out_of_stock): ?>
                                    <form class="recipient-form<?php echo ($is_mtn_network || $is_at_network || $is_telecel_network) ? ' recipient-form-store' : ''; ?>" id="recipient-form-<?php echo $package['id']; ?>" method="post" action="" style="display:none;" data-network="<?php echo htmlspecialchars($package['network_name']); ?>" data-package-name="<?php echo htmlspecialchars($package['name']); ?>" data-package-size="<?php echo htmlspecialchars($package['data_size']); ?>" data-package-price="<?php echo htmlspecialchars(number_format((float) ($package['display_price'] ?? 0), 2, '.', '')); ?>">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                                        <?php if ($agent_store): ?>
                                            <input type="hidden" name="agent_id" value="<?php echo $agent_store['agent_id']; ?>">
                                            <input type="hidden" name="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
                                        <?php endif; ?>
                                        <input type="hidden" name="order_submit_token" value="<?php echo htmlspecialchars($order_submit_token); ?>">
                                        <div class="form-row">
                                            <label for="beneficiary-<?php echo $package['id']; ?>" class="form-label">Enter Recipient Number</label>
                                            <input 
                                                type="tel" 
                                                class="form-control" 
                                                id="beneficiary-<?php echo $package['id']; ?>" 
                                                name="beneficiary_number" 
                                                placeholder="<?php echo htmlspecialchars($beneficiary_placeholder); ?>" 
                                                pattern="[0-9]{10}"
                                                required
                                            >
                                        </div>
                                        <?php if ($is_mtn_network): ?>
                                            <label class="ported-mtn-confirm">
                                                <input type="checkbox" name="allow_ported_mtn" value="1">
                                                <span>This number has been ported to MTN</span>
                                            </label>
                                        <?php endif; ?>
                                        <div class="form-row" style="margin-top: 0.5rem;">
                                            <small class="purchase-option-hint">
                                                <?php if ($wallet_balance < $package['display_price']): ?>
                                                    <?php if ($paystack_direct_enabled || $moolre_direct_enabled): ?>
                                                        Wallet balance is below this package price. You can still continue with online checkout.
                                                    <?php else: ?>
                                                        Top up your wallet to complete this purchase.
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Choose wallet payment or continue with online checkout.
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="checkout-error checkout-inline-error" style="display:none; margin-top: 0.75rem;"></div>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-success btn-sm" data-wallet-submit="1" <?php echo $wallet_balance < $package['display_price'] ? 'disabled' : ''; ?>>
                                                <i class="fas fa-wallet"></i> <?php echo $wallet_balance < $package['display_price'] ? 'Wallet Too Low' : 'Pay From Wallet'; ?>
                                            </button>
                                            <?php if ($paystack_direct_enabled): ?>
                                                <button type="button" class="btn btn-sm customer-checkout-btn paystack-checkout-btn" style="background:#1d4ed8;color:#fff">
                                                    <i class="fas fa-credit-card"></i> Paystack
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($moolre_direct_enabled): ?>
                                                <button type="button" class="btn btn-sm customer-checkout-btn moolre-checkout-btn" style="background:#16a34a;color:#fff">
                                                    <i class="fas fa-mobile-alt"></i> Moolre
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="hideRecipientForm(<?php echo $package['id']; ?>)">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php $first = false; ?>
            <?php endforeach; ?>

            <?php if (empty($packages)): ?>
                <div class="widget">
                    <div class="widget-body text-center">
                        <i class="fas fa-box-open" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>No Packages Available</h3>
                        <p class="text-muted">No data packages are currently available for purchase.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal removed; using inline per-package recipient form -->

<script>
    const purchaseSuccess = <?php echo ($flash && $flash['type'] === 'success') ? 'true' : 'false'; ?>;
    const customerCurrency = <?php echo json_encode(CURRENCY); ?>;
    const customerWalletTopupUrl = <?php echo json_encode('wallet.php' . ($store_slug ? '?store=' . urlencode($store_slug) : '')); ?>;
    const customerStoreSlug = <?php echo json_encode((string) $store_slug); ?>;
    const customerStoreCheckoutBaseUrl = <?php echo json_encode('store-checkout.php'); ?>;
    const customerBundlePaystackEnabled = <?php echo $paystack_direct_enabled ? 'true' : 'false'; ?>;
    const customerBundlePaystackInitEndpoint = <?php echo json_encode($customer_bundle_paystack_init_endpoint); ?>;
    const customerBundleMoolreEnabled = <?php echo $moolre_direct_enabled ? 'true' : 'false'; ?>;
    const customerBundleMoolreInitEndpoint = <?php echo json_encode($customer_bundle_moolre_init_endpoint); ?>;

    function ensureOrderConfirmModal() {
        if (window.__orderConfirmModalState) return window.__orderConfirmModalState;

        const styleId = 'order-confirm-modal-style';
        if (!document.getElementById(styleId)) {
            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `
                .order-confirm-modal {
                    position: fixed;
                    inset: 0;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    z-index: 12000;
                    padding: 1rem;
                }
                .order-confirm-modal.show { display: flex; }
                .order-confirm-backdrop {
                    position: absolute;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.55);
                }
                .order-confirm-dialog {
                    position: relative;
                    width: min(520px, 100%);
                    background: var(--card-bg, #fff);
                    border: 1px solid var(--border-color, #d1d5db);
                    border-radius: 14px;
                    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
                    color: var(--text-primary, #111827);
                    overflow: hidden;
                }
                .order-confirm-header {
                    padding: 1rem 1.2rem 0.5rem;
                    font-weight: 700;
                    font-size: 1.05rem;
                }
                .order-confirm-subtitle {
                    padding: 0 1.2rem;
                    color: var(--text-muted, #6b7280);
                    font-size: 0.9rem;
                }
                .order-confirm-details {
                    margin: 0.9rem 1.2rem 0;
                    border: 1px solid var(--border-color, #e5e7eb);
                    border-radius: 10px;
                    overflow: hidden;
                }
                .order-confirm-row {
                    display: flex;
                    justify-content: space-between;
                    gap: 1rem;
                    padding: 0.7rem 0.85rem;
                    border-bottom: 1px solid var(--border-color, #e5e7eb);
                    font-size: 0.92rem;
                }
                .order-confirm-row:last-child { border-bottom: none; }
                .order-confirm-row span:first-child { color: var(--text-muted, #6b7280); }
                .order-confirm-row span:last-child { font-weight: 600; text-align: right; word-break: break-word; }
                .order-confirm-actions {
                    display: flex;
                    gap: 0.75rem;
                    justify-content: flex-end;
                    padding: 1rem 1.2rem 1.1rem;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-backdrop {
                    background: rgba(2, 6, 23, 0.72);
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-dialog {
                    background: #0f172a;
                    border-color: #334155;
                    color: #f8fafc;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-header,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:last-child {
                    color: #f8fafc;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-subtitle,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:first-child {
                    color: #cbd5e1;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-details,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row {
                    border-color: #334155;
                }
                html[data-theme="dark"] .order-confirm-modal .btn.btn-secondary,
                html[data-theme="dark"] .order-confirm-modal .btn.btn-outline {
                    background: #1e293b;
                    border-color: #475569;
                    color: #f8fafc;
                }
            `;
            document.head.appendChild(style);
        }

        const modal = document.createElement('div');
        modal.className = 'order-confirm-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="order-confirm-backdrop" data-close="1"></div>
            <div class="order-confirm-dialog" role="dialog" aria-modal="true" aria-label="Confirm order">
                <div class="order-confirm-header" id="orderConfirmTitle">Confirm Order</div>
                <div class="order-confirm-subtitle" id="orderConfirmSubtitle">Review details before submitting.</div>
                <div class="order-confirm-details" id="orderConfirmDetails"></div>
                <div class="order-confirm-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="orderConfirmCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="orderConfirmOkBtn">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const state = {
            modal: modal,
            title: modal.querySelector('#orderConfirmTitle'),
            subtitle: modal.querySelector('#orderConfirmSubtitle'),
            details: modal.querySelector('#orderConfirmDetails'),
            cancelBtn: modal.querySelector('#orderConfirmCancelBtn'),
            okBtn: modal.querySelector('#orderConfirmOkBtn'),
            resolver: null
        };

        function close(result) {
            if (document.activeElement && state.modal.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            state.modal.classList.remove('show');
            state.modal.setAttribute('aria-hidden', 'true');
            if (state.resolver) {
                const resolve = state.resolver;
                state.resolver = null;
                resolve(!!result);
            }
        }

        state.modal.addEventListener('click', function(event) {
            if (event.target && event.target.getAttribute('data-close') === '1') {
                close(false);
            }
        });
        state.cancelBtn.addEventListener('click', function() { close(false); });
        state.okBtn.addEventListener('click', function() { close(true); });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && state.modal.classList.contains('show')) {
                close(false);
            }
        });

        state.open = function(config) {
            if (state.resolver) {
                const prev = state.resolver;
                state.resolver = null;
                prev(false);
            }
            const showCancel = config.showCancel !== false;
            state.title.textContent = config.title || 'Confirm Order';
            state.subtitle.textContent = config.subtitle || 'Review details before submitting.';
            state.okBtn.textContent = config.confirmText || 'Confirm';
            state.cancelBtn.textContent = config.cancelText || 'Cancel';
            state.cancelBtn.style.display = showCancel ? '' : 'none';
            state.details.innerHTML = '';

            (config.details || []).forEach(function(item) {
                const row = document.createElement('div');
                row.className = 'order-confirm-row';
                const label = document.createElement('span');
                label.textContent = item.label || '';
                const value = document.createElement('span');
                value.textContent = item.value || '';
                row.appendChild(label);
                row.appendChild(value);
                state.details.appendChild(row);
            });

            state.modal.classList.add('show');
            state.modal.setAttribute('aria-hidden', 'false');
            setTimeout(function() { 
                if (state.modal.classList.contains('show')) {
                    state.okBtn.focus(); 
                }
            }, 50);
            return new Promise(function(resolve) {
                state.resolver = resolve;
            });
        };

        window.__orderConfirmModalState = state;
        return state;
    }

    function openOrderConfirmModal(config) {
        return ensureOrderConfirmModal().open(config || {});
    }

    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    
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
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Network tabs
    function showNetwork(network) {
        // Hide all network packages
        document.querySelectorAll('.network-packages').forEach(el => {
            el.classList.remove('active');
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.network-tab').forEach(el => {
            el.classList.remove('active');
        });
        
        // Show selected network packages
        document.getElementById(network + '-packages').classList.add('active');
        
        // Add active class to clicked tab
        const activeTab = document.querySelector('.network-tab[data-network="' + network + '"]');
        if (activeTab) {
            activeTab.classList.add('active');
        }
    }

    function normalizeCustomerLocalPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('233')) {
            return '0' + digits.slice(3);
        }
        return digits;
    }

    function isCustomerAtLocalPhone(localPhone) {
        if (!/^\d{10}$/.test(localPhone)) return false;
        const prefix = localPhone.slice(0, 3);
        return ['026', '027', '056', '057'].indexOf(prefix) !== -1;
    }

    function isCustomerTelecelLocalPhone(localPhone) {
        if (!/^\d{10}$/.test(localPhone)) return false;
        const prefix = localPhone.slice(0, 3);
        return ['020', '050'].indexOf(prefix) !== -1;
    }

    function resolveCustomerNetworkLabel(rawNetwork) {
        const label = String(rawNetwork || '').toLowerCase().trim();
        if (label === 'mtn' || label.indexOf('mtn') !== -1) {
            return 'mtn';
        }
        if (label === 'at' || label.indexOf('airtel') !== -1 || label.indexOf('tigo') !== -1 || label.indexOf('airteltigo') !== -1) {
            return 'at';
        }
        if (label === 'telecel' || label.indexOf('vodafone') !== -1 || label.indexOf('voda') !== -1) {
            return 'telecel';
        }
        return '';
    }

    function validateRecipientNumberForNetwork(input, networkLabel) {
        if (!input) return true;
        const localPhone = normalizeCustomerLocalPhone(input.value);
        const form = input.closest('form');
        const allowPortedMtn = form && form.querySelector('input[name="allow_ported_mtn"]:checked');
        let valid = true;
        let message = '';

        if (networkLabel === 'mtn') {
            valid = isCustomerMtnLocalPhone(localPhone);
            if (!valid && allowPortedMtn && /^\d{10}$/.test(localPhone)) {
                valid = true;
            }
            message = 'Please enter a valid MTN number (024/025/053/054/055/059), or confirm that this number has been ported to MTN.';
        } else if (networkLabel === 'at') {
            valid = isCustomerAtLocalPhone(localPhone);
            message = 'Please enter a valid AT number (026/027/056/057).';
        } else if (networkLabel === 'telecel') {
            valid = isCustomerTelecelLocalPhone(localPhone);
            message = 'Please enter a valid Telecel number (020/050).';
        }

        if (!valid) {
            input.setCustomValidity(message);
        } else {
            input.setCustomValidity('');
        }
        return valid;
    }

    // Package purchasing - inline recipient form
    function buyPackage(packageId) {
        const card = document.getElementById('package-card-' + packageId);
        if (card && card.dataset && card.dataset.stockStatus === 'out_of_stock') {
            window.alert('This package is currently out of stock.');
            return;
        }

        if (customerStoreSlug) {
            window.location.href = customerStoreCheckoutBaseUrl
                + '?store=' + encodeURIComponent(customerStoreSlug)
                + '&package_id=' + encodeURIComponent(packageId);
            return;
        }

        // Hide any other open forms
        document.querySelectorAll('.recipient-form').forEach(f => f.style.display = 'none');
        
        const form = document.getElementById('recipient-form-' + packageId);
        if (!form) return;
        form.style.display = 'block';
        // Focus input
        const input = form.querySelector('input[name="beneficiary_number"]');
        if (input) input.focus();
        // Scroll into view for better UX
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideRecipientForm(packageId) {
        const form = document.getElementById('recipient-form-' + packageId);
        if (form) form.style.display = 'none';
    }

    function setCheckoutError(form, message, type) {
        if (!form) return;
        const errorBox = form.querySelector('.checkout-error');
        if (!errorBox) return;

        if (!message) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
            errorBox.className = 'checkout-error';
            return;
        }

        errorBox.style.display = 'block';
        errorBox.textContent = message;
        errorBox.className = 'checkout-error alert alert-' + (type || 'danger');
    }

    function setCheckoutButtonsLoading(form, loading, activeButton) {
        if (!form) return;
        const actionButtons = form.querySelectorAll('.form-actions button');
        actionButtons.forEach(function(button) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            if (!button.hasAttribute('data-base-disabled')) {
                button.dataset.baseDisabled = button.disabled ? '1' : '0';
            }

            if (loading) {
                button.disabled = true;
                if (activeButton && button === activeButton) {
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                }
                return;
            }

            button.disabled = button.dataset.baseDisabled === '1';
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
            }
        });
    }

    function buildCheckoutSummary(form, recipientNumber) {
        const packageName = String(form.dataset.packageName || '').trim();
        const packageSize = String(form.dataset.packageSize || '').trim();
        const packageNetwork = String(form.dataset.network || '').trim();
        const packagePrice = parseFloat(form.dataset.packagePrice || 0) || 0;
        const packageLabel = [packageName, packageSize].filter(Boolean).join(' - ');

        return {
            packagePrice: packagePrice,
            details: [
                { label: 'Network', value: packageNetwork || 'N/A' },
                { label: 'Package', value: packageLabel || 'Selected package' },
                { label: 'Recipient', value: recipientNumber },
                { label: 'Amount', value: customerCurrency + packagePrice.toFixed(2) }
            ]
        };
    }

    async function startCustomerPaystackCheckout(form, input) {
        if (!customerBundlePaystackEnabled) {
            setCheckoutError(form, 'Paystack checkout is currently unavailable.', 'danger');
            return;
        }

        if (!form || !input) return;

        const networkLabel = resolveCustomerNetworkLabel(form.dataset.network || '');
        if (networkLabel && !validateRecipientNumberForNetwork(input, networkLabel)) {
            input.reportValidity();
            return;
        }

        const recipientNumber = normalizeCustomerLocalPhone(input.value);
        const summary = buildCheckoutSummary(form, recipientNumber);
        const confirmed = await openOrderConfirmModal({
            title: 'Continue to Paystack',
            subtitle: 'You will be redirected to Paystack to complete this order.',
            confirmText: 'Continue to Payment',
            details: summary.details
        });

        if (!confirmed) {
            return;
        }

        const paystackButton = form.querySelector('.paystack-checkout-btn');
        setCheckoutError(form, '', 'danger');
        setCheckoutButtonsLoading(form, true, paystackButton);

        try {
            const payload = {
                package_id: parseInt((form.querySelector('input[name="package_id"]') || {}).value || '0', 10),
                beneficiary_number: recipientNumber,
                allow_ported_mtn: form.querySelector('input[name="allow_ported_mtn"]:checked') ? '1' : '0',
                csrf_token: (form.querySelector('input[name="csrf_token"]') || {}).value || '',
                store_slug: (form.querySelector('input[name="store_slug"]') || {}).value || '',
                agent_id: parseInt((form.querySelector('input[name="agent_id"]') || {}).value || '0', 10),
                gateway: 'paystack'
            };

            const response = await fetch(customerBundlePaystackInitEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });

            const result = await response.json().catch(function() {
                return null;
            });

            if (!response.ok || !result || result.status !== 'success' || !result.data || !result.data.authorization_url) {
                const message = result && result.message ? result.message : 'Unable to initialize Paystack checkout right now.';
                throw new Error(message);
            }

            window.location.href = result.data.authorization_url;
        } catch (error) {
            setCheckoutButtonsLoading(form, false);
            setCheckoutError(form, error.message || 'Unable to initialize Paystack checkout right now.', 'danger');
        }
    }

    async function startCustomerMoolreCheckout(form, input) {
        if (!customerBundleMoolreEnabled) {
            setCheckoutError(form, 'Moolre checkout is currently unavailable.', 'danger');
            return;
        }

        if (!form || !input) return;

        const networkLabel = resolveCustomerNetworkLabel(form.dataset.network || '');
        if (networkLabel && !validateRecipientNumberForNetwork(input, networkLabel)) {
            input.reportValidity();
            return;
        }

        const recipientNumber = normalizeCustomerLocalPhone(input.value);
        const summary = buildCheckoutSummary(form, recipientNumber);
        const confirmed = await openOrderConfirmModal({
            title: 'Continue to Moolre',
            subtitle: 'You will be redirected to Moolre to complete this order.',
            confirmText: 'Continue to Payment',
            details: summary.details
        });

        if (!confirmed) {
            return;
        }

        const moolreButton = form.querySelector('.moolre-checkout-btn');
        setCheckoutError(form, '', 'danger');
        setCheckoutButtonsLoading(form, true, moolreButton);

        try {
            const payload = {
                package_id: parseInt((form.querySelector('input[name="package_id"]') || {}).value || '0', 10),
                beneficiary_number: recipientNumber,
                allow_ported_mtn: form.querySelector('input[name="allow_ported_mtn"]:checked') ? '1' : '0',
                csrf_token: (form.querySelector('input[name="csrf_token"]') || {}).value || '',
                store_slug: (form.querySelector('input[name="store_slug"]') || {}).value || '',
                agent_id: parseInt((form.querySelector('input[name="agent_id"]') || {}).value || '0', 10),
                gateway: 'moolre'
            };

            const response = await fetch(customerBundleMoolreInitEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });

            const result = await response.json().catch(function() {
                return null;
            });

            if (!response.ok || !result || result.status !== 'success' || !result.data || !result.data.authorization_url) {
                const message = result && result.message ? result.message : 'Unable to initialize Moolre checkout right now.';
                throw new Error(message);
            }

            window.location.href = result.data.authorization_url;
        } catch (error) {
            setCheckoutButtonsLoading(form, false);
            setCheckoutError(form, error.message || 'Unable to initialize Moolre checkout right now.', 'danger');
        }
    }

    const preselectedPackageId = <?php echo (int) $preselected_package_id; ?>;

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();

        // Clear recipient inputs after successful purchase
        if (purchaseSuccess) {
            document.querySelectorAll('.recipient-form').forEach(form => {
                form.reset();
                form.style.display = 'none';
            });
        }

        document.querySelectorAll('.recipient-form').forEach(form => {
            const input = form.querySelector('input[name="beneficiary_number"]');
            if (!input) return;
            const networkLabel = resolveCustomerNetworkLabel(form.dataset.network || '');
            if (!networkLabel) return;

            input.addEventListener('input', function() {
                input.setCustomValidity('');
            });

            form.addEventListener('submit', function(event) {
                if (!validateRecipientNumberForNetwork(input, networkLabel)) {
                    event.preventDefault();
                    input.reportValidity();
                    return;
                }

                const recipientNumber = normalizeCustomerLocalPhone(input.value);
                const summary = buildCheckoutSummary(form, recipientNumber);
                event.preventDefault();
                setCheckoutError(form, '', 'danger');
                openOrderConfirmModal({
                    title: 'Confirm Data Purchase',
                    subtitle: 'Review the order details before submitting.',
                    confirmText: 'Submit Order',
                    details: summary.details
                }).then(function(confirmed) {
                    if (!confirmed) return;
                    const submitBtn = form.querySelector('button[data-wallet-submit="1"]');
                    setCheckoutButtonsLoading(form, true, submitBtn);
                    form.submit();
                });
            });

            const paystackButton = form.querySelector('.paystack-checkout-btn');
            if (paystackButton) {
                paystackButton.addEventListener('click', function() {
                    startCustomerPaystackCheckout(form, input);
                });
            }

            const moolreButton = form.querySelector('.moolre-checkout-btn');
            if (moolreButton) {
                moolreButton.addEventListener('click', function() {
                    startCustomerMoolreCheckout(form, input);
                });
            }
        });

        if (preselectedPackageId) {
            const card = document.getElementById('package-card-' + preselectedPackageId);
            if (card) {
                const container = card.closest('.network-packages');
                if (container) {
                    const network = container.id.replace('-packages', '');
                    const tab = document.querySelector('.network-tab[data-network="' + network + '"]');
                    if (tab) {
                        tab.click();
                    }
                }
                setTimeout(() => {
                    buyPackage(preselectedPackageId);
                }, 200);
            }
        }
    });

    const customerBulkPackages = <?php echo json_encode($packages_by_network['MTN'] ?? []); ?>;
    const customerBulkPackageMap = {};
    const customerBulkSizeMap = {};
    customerBulkPackages.forEach(function(pkg) {
        const key = normalizeCustomerBulkVolumeKey(pkg.data_size || '');
        if (key) {
            customerBulkPackageMap[key] = pkg;
        }
        const sizeKey = normalizeCustomerBulkNumericKey(parseCustomerPackageSizeGb(pkg.data_size || ''));
        if (sizeKey) {
            if (!customerBulkSizeMap[sizeKey]) {
                customerBulkSizeMap[sizeKey] = pkg;
            } else {
                const currentPrice = parseFloat(customerBulkSizeMap[sizeKey].display_price || 0);
                const candidatePrice = parseFloat(pkg.display_price || 0);
                if (!isNaN(candidatePrice) && (isNaN(currentPrice) || candidatePrice < currentPrice)) {
                    customerBulkSizeMap[sizeKey] = pkg;
                }
            }
        }
    });

    const customerBulkState = {
        orders: [],
        hasErrors: true
    };

    const customerBulkContext = {
        agentId: <?php echo $agent_store ? (int)$agent_store['agent_id'] : 0; ?>,
        storeSlug: <?php echo json_encode($store_slug); ?>,
        csrfToken: <?php echo json_encode(generateCSRF()); ?>
    };

    function isCustomerInsufficientWalletMessage(message) {
        const text = String(message || '').toLowerCase();
        return text.indexOf('insufficient wallet balance') !== -1;
    }

    function normalizeCustomerBulkVolumeKey(value) {
        const raw = String(value || '').trim().toLowerCase();
        if (/^\d+(\.\d+)?$/.test(raw)) {
            const parsed = parseFloat(raw);
            if (!isNaN(parsed)) {
                return parsed.toString() + 'g';
            }
        }
        return raw
            .replace(/\s+/g, '')
            .replace('gb', 'g')
            .replace('mb', 'm');
    }

    function parseCustomerPackageSizeGb(value) {
        const raw = String(value || '').trim().toLowerCase();
        const match = raw.match(/([\d.]+)\s*(gb|g|mb|m)?/);
        if (!match) return 0;
        const amount = parseFloat(match[1]);
        if (isNaN(amount)) return 0;
        const unit = match[2] || 'g';
        if (unit === 'mb' || unit === 'm') {
            return amount / 1024;
        }
        return amount;
    }

    function normalizeCustomerBulkNumericKey(value) {
        const parsed = parseFloat(value);
        if (isNaN(parsed) || parsed <= 0) return '';
        return parsed.toFixed(2).replace(/\.?0+$/, '');
    }

    function normalizeCustomerBulkLocalPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('233')) {
            return '0' + digits.slice(3);
        }
        return digits;
    }

    function isCustomerMtnLocalPhone(localPhone) {
        if (!/^\d{10}$/.test(localPhone)) return false;
        const prefix = localPhone.slice(0, 3);
        return ['024', '025', '053', '054', '055', '059'].indexOf(prefix) !== -1;
    }

    function previewCustomerBulkTextOrders() {
        const input = document.getElementById('customerBulkTextInput');
        const preview = document.getElementById('customerBulkTextPreview');
        const previewBody = document.getElementById('customerBulkTextPreviewBody');
        const summary = document.getElementById('customerBulkTextSummary');
        const errorsBox = document.getElementById('customerBulkTextErrors');
        const processBtn = document.getElementById('processCustomerBulkTextBtn');

        if (!input || !previewBody || !summary || !errorsBox || !processBtn) return;

        const lines = String(input.value || '').split(/\r?\n/);
        const orders = [];
        const errors = [];
        let totalCost = 0;

        lines.forEach(function(rawLine, index) {
            const line = rawLine.trim();
            if (!line) return;
            let phone = '';
            let volume = '';

            if (line.indexOf(',') !== -1) {
                const parts = line.split(',');
                phone = (parts[0] || '').trim();
                volume = parts.slice(1).join(',').trim();
            } else {
                const parts = line.split(/\s+/);
                phone = (parts[0] || '').trim();
                volume = parts.slice(1).join(' ').trim();
            }

            if (volume) {
                const numericVolume = volume.replace(/\s+/g, '');
                if (/^\d+(\.\d+)?$/.test(numericVolume)) {
                    const parsedVolume = parseFloat(numericVolume);
                    if (!isNaN(parsedVolume)) {
                        volume = parsedVolume.toFixed(2);
                    }
                }
            }

            const localPhone = normalizeCustomerBulkLocalPhone(phone);
            if (!isCustomerMtnLocalPhone(localPhone)) {
                errors.push('Row ' + (index + 1) + ': Invalid MTN number');
                return;
            }
            if (!volume) {
                errors.push('Row ' + (index + 1) + ': Missing bundle size');
                return;
            }

            const numericCandidate = volume.replace(/\s+/g, '');
            const isNumericInput = /^\d+(\.\d+)?$/.test(numericCandidate);
            const volumeKey = normalizeCustomerBulkVolumeKey(volume);
            let pkg = null;
            if (isNumericInput) {
                const numericKey = normalizeCustomerBulkNumericKey(numericCandidate);
                if (numericKey && customerBulkSizeMap[numericKey]) {
                    pkg = customerBulkSizeMap[numericKey];
                }
            } else {
                pkg = customerBulkPackageMap[volumeKey] || null;
                if (!pkg) {
                    Object.keys(customerBulkPackageMap).some(function(key) {
                        if (volumeKey.indexOf(key) !== -1 || key.indexOf(volumeKey) !== -1) {
                            pkg = customerBulkPackageMap[key];
                            return true;
                        }
                        return false;
                    });
                }
            }

            if (!pkg) {
                errors.push('Row ' + (index + 1) + ': Bundle not found for "' + volume + '"');
                return;
            }

            const price = parseFloat(pkg.display_price || 0);
            totalCost += price;
            orders.push({
                phone: localPhone,
                volume: volume,
                price: price
            });
        });

        previewBody.innerHTML = '';
        orders.forEach(function(order) {
            const row = document.createElement('tr');
            row.innerHTML = '<td>' + order.phone + '</td><td>' + order.volume + '</td><td>' +
                order.price.toFixed(2) + '</td>';
            previewBody.appendChild(row);
        });

        customerBulkState.orders = orders;
        customerBulkState.hasErrors = errors.length > 0 || orders.length === 0;

        preview.style.display = orders.length ? 'block' : 'none';
        summary.textContent = orders.length
            ? (orders.length + ' valid orders. Total: ' + totalCost.toFixed(2))
            : 'No valid orders to preview.';
        errorsBox.textContent = errors.length ? errors.slice(0, 3).join(' | ') : '';
        processBtn.disabled = customerBulkState.hasErrors;
    }

    async function processCustomerBulkTextOrders() {
        const processBtn = document.getElementById('processCustomerBulkTextBtn');
        if (!processBtn || customerBulkState.hasErrors || customerBulkState.orders.length === 0) return;

        const totalCost = customerBulkState.orders.reduce(function(sum, order) {
            return sum + (parseFloat(order.price) || 0);
        }, 0);
        const previewLines = customerBulkState.orders.slice(0, 5).map(function(order) {
            return order.phone + ' - ' + order.volume;
        });
        const remainingCount = customerBulkState.orders.length - previewLines.length;
        const confirmed = await openOrderConfirmModal({
            title: 'Confirm Bulk Orders',
            subtitle: 'Please confirm these MTN bulk orders before submission.',
            confirmText: 'Process Orders',
            details: [
                { label: 'Total Orders', value: String(customerBulkState.orders.length) },
                { label: 'Total Amount', value: customerCurrency + totalCost.toFixed(2) },
                { label: 'Preview', value: previewLines.join(', ') + (remainingCount > 0 ? ', and ' + remainingCount + ' more' : '') }
            ]
        });

        if (!confirmed) {
            return;
        }

        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        fetch('process_bulk_text.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                network: 'mtn',
                orders: customerBulkState.orders.map(function(order) {
                    return { phone: order.phone, volume: order.volume };
                }),
                agent_id: customerBulkContext.agentId,
                store_slug: customerBulkContext.storeSlug,
                csrf_token: customerBulkContext.csrfToken
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            const responseMessage = String((data && data.message) || '');
            if (!data.success && isCustomerInsufficientWalletMessage(responseMessage)) {
                openOrderConfirmModal({
                    title: 'Insufficient Wallet Balance',
                    subtitle: 'Your balance cannot cover these bulk orders right now.',
                    confirmText: 'Top Up Wallet',
                    cancelText: 'Close',
                    details: [
                        { label: 'Amount Needed', value: customerCurrency + totalCost.toFixed(2) },
                        { label: 'Action', value: 'Add funds to continue this order' }
                    ]
                }).then(function(goToTopup) {
                    if (goToTopup) {
                        window.location.href = customerWalletTopupUrl;
                    }
                });
                return;
            }

            openOrderConfirmModal({
                title: data.success ? 'Bulk Orders Completed' : 'Bulk Order Failed',
                subtitle: responseMessage || (data.success ? 'Bulk orders completed successfully.' : 'Bulk order processing failed.'),
                confirmText: 'OK',
                showCancel: false,
                details: [
                    { label: 'Status', value: data.success ? 'Success' : 'Failed' }
                ]
            });
            if (data.success) {
                document.getElementById('customerBulkTextInput').value = '';
                document.getElementById('customerBulkTextPreviewBody').innerHTML = '';
                document.getElementById('customerBulkTextPreview').style.display = 'none';
                document.getElementById('customerBulkTextSummary').textContent = '';
            }
        })
        .catch(function() {
            openOrderConfirmModal({
                title: 'Bulk Order Failed',
                subtitle: 'Failed to process bulk orders. Please try again.',
                confirmText: 'OK',
                showCancel: false,
                details: [
                    { label: 'Status', value: 'Failed' }
                ]
            });
        })
        .finally(function() {
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Process Orders';
        });
    }
</script>

<style>
.wallet-balance {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--success-color);
    color: white;
    border-radius: var(--border-radius);
    font-weight: 600;
    margin-right: 1rem;
}

.network-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 1px solid var(--border-color);
}

.network-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s ease;
}

.network-tab:hover,
.network-tab.active {
    color: var(--brand-primary);
    border-bottom-color: var(--brand-primary);
}

.package-count {
    background: var(--bg-tertiary);
    color: var(--text-primary);
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.network-packages {
    display: none;
}

.network-packages.active {
    display: block;
}

.ported-mtn-confirm {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.65rem;
    font-size: 0.9rem;
    color: var(--text-primary);
}

.ported-mtn-confirm input {
    width: 1rem;
    height: 1rem;
    flex: 0 0 auto;
}

.network-header {
    margin-bottom: 2rem;
}

.network-header h2 {
    margin: 0 0 0.5rem 0;
    color: var(--text-primary);
}

.network-description {
    color: var(--text-muted);
    margin: 0;
}

.packages-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}

/* Mobile Responsive Enhancements */
@media (max-width: 768px) {
    .packages-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
        padding: 0 0.5rem;
    }
    
    .network-tabs {
        display: flex;
        overflow-x: auto;
        gap: 0.25rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    
    .network-tabs::-webkit-scrollbar {
        display: none;
    }
    
    .network-tab {
        flex: 0 0 auto;
        padding: 0.75rem 1rem;
        white-space: nowrap;
        min-width: auto;
    }
    
    .package-count {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
    
    .package-card {
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .package-header {
        padding: 0.875rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .package-body {
        padding: 1rem 0.875rem;
    }
    
    .package-name {
        font-size: 1.125rem;
        line-height: 1.3;
        margin-bottom: 0.75rem;
    }
    
    .package-details {
        gap: 0.375rem;
        margin-bottom: 0.875rem;
    }
    
    .detail-item {
        font-size: 0.9rem;
        gap: 0.375rem;
    }
    
    .package-price {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        padding: 0.875rem;
        gap: 0.5rem;
    }
    
    .price-value {
        font-size: 1.375rem;
        align-items: center;
    }
    
    .package-footer {
        padding: 0.875rem;
    }
    
    .recipient-form {
        margin-top: 0.75rem;
        padding: 0.875rem;
        background: var(--bg-tertiary);
        border-radius: 8px;
    }
    
    .form-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }
    
    .form-actions .btn {
        flex: 1;
        font-size: 0.875rem;
        padding: 0.625rem 1rem;
    }
}

@media (max-width: 480px) {
    .dashboard-content {
        padding: 1rem 0.75rem;
    }
    
    .page-title h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .page-subtitle {
        font-size: 0.875rem;
        margin-bottom: 1rem;
    }
    
    .packages-grid {
        padding: 0;
        gap: 0.875rem;
    }
    
    .package-card {
        margin: 0 0.25rem;
    }
    
    .package-header {
        padding: 0.75rem;
    }
    
    .package-body {
        padding: 0.875rem 0.75rem;
    }
    
    .package-name {
        font-size: 1rem;
    }
    
    .package-price {
        padding: 0.75rem;
    }
    
    .price-value {
        font-size: 1.25rem;
    }
    
    .package-footer {
        padding: 0.75rem;
    }
    
    .wallet-balance {
        font-size: 0.875rem;
        padding: 0.5rem 0.875rem;
        margin-right: 0.5rem;
    }
    
    .network-header h2 {
        font-size: 1.25rem;
        margin-bottom: 0.375rem;
    }
    
    .network-description {
        font-size: 0.875rem;
    }

    .alert {
        margin: 0 0.25rem 1rem 0.25rem;
        padding: 0.875rem;
        border-radius: 8px;
    }

    .header-actions {
        gap: 0.25rem;
    }

    .mobile-menu-toggle {
        display: flex;
    }
}

.package-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    overflow: hidden;
    transition: all 0.2s ease;
}

.package-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.package-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.package-network {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.network-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.package-body {
    padding: 1.5rem 1rem;
}

.package-name {
    margin: 0 0 1rem 0;
    font-size: 1.25rem;
    color: var(--text-primary);
}

.package-details {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-muted);
}

.detail-item i {
    width: 1rem;
    text-align: center;
}

.package-price {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: var(--bg-tertiary);
    border-radius: var(--border-radius);
}

.price-label {
    color: var(--text-muted);
}

.price-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--brand-primary);
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.agent-price-badge {
    background: var(--success-color);
    color: white;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-top: 0.25rem;
}

.package-footer {
    padding: 1rem;
}

.bulk-text-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

@media (max-width: 768px) {
    html,
    body {
        overflow-x: hidden;
    }

    .main-content {
        padding-inline: 0.75rem;
    }

    .dashboard-wrapper,
    .main-content,
    .dashboard-content,
    .network-packages,
    .packages-grid,
    .package-card,
    .widget,
    .alert {
        max-width: 100%;
    }

    .dashboard-header {
        align-items: center;
        padding: 0.8rem 0.9rem;
        margin-inline: 0;
        border-radius: 14px;
    }

    .dashboard-content {
        padding: 1rem 0.9rem 1.25rem;
    }

    .header-actions {
        flex-wrap: nowrap;
        justify-content: flex-end;
        align-items: center;
        gap: 0.35rem;
        min-width: 0;
    }

    .wallet-balance {
        display: none;
    }

    .topup-btn {
        flex: 0 0 auto;
        justify-content: center;
        width: auto;
        white-space: nowrap;
        padding: 0.4rem 0.7rem;
        font-size: 0.8rem;
        min-height: 36px;
        min-width: 0;
        border-radius: 999px;
    }

    .header-action-btn,
    .topup-btn,
    .theme-toggle,
    .user-dropdown-toggle {
        flex-shrink: 0;
    }

    .theme-toggle {
        width: 38px;
        height: 38px;
        min-width: 38px;
        min-height: 38px;
    }

    .user-dropdown {
        min-width: 0;
    }

    .user-dropdown-toggle {
        min-height: 38px;
        padding: 0.25rem 0.45rem;
        gap: 0.4rem;
        max-width: min(52vw, 190px);
        min-width: 0;
    }

    .user-dropdown-toggle .user-info {
        display: flex;
        min-width: 0;
    }

    .user-dropdown-toggle .user-name {
        max-width: 110px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.85rem;
    }

    .user-dropdown-toggle .user-role {
        display: none;
    }

    .user-dropdown-toggle .dropdown-arrow {
        display: none;
    }

    .user-avatar {
        width: 30px;
        height: 30px;
        font-size: 0.85rem;
    }

    .bulk-text-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .bulk-text-actions .btn,
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .alert .btn {
        display: flex;
        width: 100%;
        margin: 0.75rem 0 0 !important;
        justify-content: center;
    }

    .network-tabs {
        flex-wrap: wrap;
        overflow-x: visible;
        border-bottom: none;
        padding-bottom: 0;
    }

    .network-tab {
        flex: 1 1 calc(50% - 0.25rem);
        min-width: 0;
        justify-content: center;
        border: 1px solid var(--border-color);
        border-bottom-width: 1px;
        border-radius: 12px;
    }

    .network-tab.active {
        border-color: var(--brand-primary);
    }

    .table-responsive {
        margin: 0 -0.25rem;
    }

    .price-value {
        align-items: flex-start;
        text-align: left;
    }
}

@media (max-width: 480px) {
    .dashboard-header {
        padding: 0.7rem 0.85rem;
    }

    .header-actions {
        width: auto;
        gap: 0.3rem;
    }

    .topup-btn {
        flex: 0 0 auto;
        width: auto;
        padding: 0.35rem 0.6rem;
        font-size: 0.76rem;
        min-height: 34px;
        border-radius: 999px;
    }

    .topup-btn i {
        font-size: 0.7rem;
    }

    .theme-toggle {
        width: 34px;
        height: 34px;
        min-width: 34px;
        min-height: 34px;
    }

    .user-dropdown-toggle {
        min-height: 34px;
        padding: 0.22rem 0.38rem;
        max-width: min(48vw, 150px);
    }

    .user-dropdown-toggle .user-name {
        max-width: 84px;
        font-size: 0.78rem;
    }

    .user-avatar {
        width: 26px;
        height: 26px;
        font-size: 0.75rem;
    }

    .wallet-balance {
        font-size: 0.8125rem;
        padding: 0.625rem 0.75rem;
    }

    .wallet-balance span {
        overflow-wrap: anywhere;
    }

    .network-tabs {
        margin-inline: 0;
        padding-inline: 0;
    }

    .dashboard-content {
        padding: 0.95rem 0.85rem 1.1rem;
    }

    .network-tab {
        flex-basis: 100%;
        padding: 0.7rem 0.85rem;
        font-size: 0.85rem;
    }

    .package-card {
        margin: 0;
    }

    .package-price {
        text-align: left;
    }

    .price-value {
        font-size: 1.2rem;
    }

    .alert {
        margin: 0 0 1rem;
    }
}

body.customer-buy-data-page .dashboard-content {
    background:
        radial-gradient(circle at top right, rgba(249, 115, 22, 0.12), transparent 26rem),
        radial-gradient(circle at top left, rgba(59, 130, 246, 0.1), transparent 24rem);
    border-radius: 28px;
}

body.customer-buy-data-page .buy-data-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.9fr);
    gap: 1.5rem;
    padding: 1.75rem;
    margin-bottom: 1.75rem;
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.2);
    background:
        linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.88)),
        linear-gradient(135deg, rgba(249, 115, 22, 0.18), rgba(59, 130, 246, 0.14));
    color: #f8fafc;
    overflow: hidden;
    position: relative;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
}

body.customer-buy-data-page .buy-data-hero::before,
body.customer-buy-data-page .buy-data-hero::after {
    content: '';
    position: absolute;
    border-radius: 999px;
    pointer-events: none;
}

body.customer-buy-data-page .buy-data-hero::before {
    width: 14rem;
    height: 14rem;
    right: -4rem;
    top: -5rem;
    background: rgba(249, 115, 22, 0.18);
}

body.customer-buy-data-page .buy-data-hero::after {
    width: 10rem;
    height: 10rem;
    left: -3rem;
    bottom: -4rem;
    background: rgba(59, 130, 246, 0.16);
}

body.customer-buy-data-page .buy-data-hero-copy,
body.customer-buy-data-page .buy-data-hero-panel {
    position: relative;
    z-index: 1;
}

body.customer-buy-data-page .buy-data-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.85rem;
    padding: 0.42rem 0.82rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    color: #fde68a;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

body.customer-buy-data-page .buy-data-hero h1 {
    margin: 0 0 0.85rem;
    font-size: clamp(2rem, 3vw, 3rem);
    color: #fff;
}

body.customer-buy-data-page .buy-data-hero .page-subtitle {
    margin: 0;
    max-width: 54rem;
    color: rgba(226, 232, 240, 0.92);
    font-size: 1rem;
    line-height: 1.7;
}

body.customer-buy-data-page .buy-data-hero-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.2rem;
}

body.customer-buy-data-page .buy-data-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.62rem 0.9rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    color: #e2e8f0;
    font-size: 0.88rem;
    font-weight: 600;
}

body.customer-buy-data-page .buy-data-hero-panel {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    justify-content: center;
}

body.customer-buy-data-page .hero-balance-card {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 1.2rem 1.25rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(8px);
}

body.customer-buy-data-page .hero-balance-label,
body.customer-buy-data-page .hero-balance-meta {
    color: rgba(226, 232, 240, 0.84);
}

body.customer-buy-data-page .hero-balance-card strong {
    font-size: clamp(1.6rem, 2vw, 2.1rem);
    line-height: 1.1;
}

body.customer-buy-data-page .hero-quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

body.customer-buy-data-page .hero-quick-actions .btn {
    flex: 1 1 0;
    justify-content: center;
    min-width: 150px;
    border-radius: 999px;
    padding: 0.85rem 1.1rem;
}

body.customer-buy-data-page .network-tabs {
    gap: 0.75rem;
    border-bottom: none;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

body.customer-buy-data-page .network-tab {
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 999px;
    padding: 0.9rem 1.15rem;
    background: rgba(255, 255, 255, 0.76);
    color: var(--text-primary);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

body.customer-buy-data-page .network-tab:hover,
body.customer-buy-data-page .network-tab.active {
    background: linear-gradient(135deg, #f97316, #ea580c);
    color: #fff;
    border-color: transparent;
    transform: translateY(-1px);
}

body.customer-buy-data-page .network-tab:hover .package-count,
body.customer-buy-data-page .network-tab.active .package-count {
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
}

body.customer-buy-data-page .package-count {
    background: rgba(15, 23, 42, 0.08);
}

body.customer-buy-data-page .network-header {
    margin-bottom: 1.2rem;
    padding: 0 0.25rem;
}

body.customer-buy-data-page .network-header h2 {
    font-size: 1.7rem;
    margin-bottom: 0.35rem;
}

body.customer-buy-data-page .network-description {
    max-width: 48rem;
    line-height: 1.6;
}

body.customer-buy-data-page .widget {
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
}

body.customer-buy-data-page .packages-grid {
    gap: 1.35rem;
}

body.customer-buy-data-page .package-card {
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 24px;
    padding: 0;
    overflow: hidden;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
}

body.customer-buy-data-page .package-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 28px 56px rgba(15, 23, 42, 0.14);
}

body.customer-buy-data-page .package-header {
    padding: 1rem 1.1rem;
    background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(59, 130, 246, 0.08));
}

body.customer-buy-data-page .package-body {
    padding: 1.2rem 1.1rem 1rem;
}

body.customer-buy-data-page .package-name {
    font-size: 1.22rem;
    margin-bottom: 0.9rem;
}

body.customer-buy-data-page .package-details {
    gap: 0.6rem;
}

body.customer-buy-data-page .detail-item {
    color: var(--text-secondary);
}

body.customer-buy-data-page .package-price {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.35rem;
    padding: 1rem 1.05rem;
    background: rgba(15, 23, 42, 0.04);
    border-radius: 18px;
}

body.customer-buy-data-page .price-value {
    align-items: flex-start;
    text-align: left;
    font-size: 1.45rem;
}

body.customer-buy-data-page .package-footer {
    padding: 0 1.1rem 1.2rem;
}

body.customer-buy-data-page .package-footer .btn {
    width: 100%;
    justify-content: center;
    border-radius: 14px;
    min-height: 48px;
}

body.customer-buy-data-page .recipient-form {
    margin-top: 0.95rem;
    padding: 1rem;
    border-radius: 18px;
    background: rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(148, 163, 184, 0.18);
}

body.customer-buy-data-page .alert {
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

@media (max-width: 992px) {
    body.customer-buy-data-page .buy-data-hero {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    body.customer-buy-data-page .dashboard-content {
        border-radius: 22px;
        padding-top: 1rem;
    }

    body.customer-buy-data-page .buy-data-hero {
        padding: 1.2rem;
        border-radius: 22px;
    }

    body.customer-buy-data-page .buy-data-hero h1 {
        font-size: 1.8rem;
    }

    body.customer-buy-data-page .hero-quick-actions .btn {
        flex-basis: 100%;
        min-width: 0;
    }

    body.customer-buy-data-page .network-tab {
        flex: 1 1 calc(50% - 0.4rem);
    }
}

@media (max-width: 480px) {
    body.customer-buy-data-page .buy-data-hero {
        padding: 1rem;
    }

    body.customer-buy-data-page .buy-data-hero-tags {
        gap: 0.55rem;
    }

    body.customer-buy-data-page .buy-data-tag {
        width: 100%;
        justify-content: center;
    }

    body.customer-buy-data-page .network-tab {
        flex-basis: 100%;
    }

    body.customer-buy-data-page .package-header,
    body.customer-buy-data-page .package-body,
    body.customer-buy-data-page .package-footer {
        padding-left: 0.95rem;
        padding-right: 0.95rem;
    }
}

body.customer-buy-data-page {
    background: #040914;
    color: #f8fafc;
}

body.customer-buy-data-page .dashboard-wrapper {
    background: #040914;
}

body.customer-buy-data-page .sidebar {
    background: linear-gradient(180deg, #0a1220 0%, #08111d 100%);
    border-right: 1px solid rgba(59, 130, 246, 0.14);
}

body.customer-buy-data-page .sidebar-brand,
body.customer-buy-data-page .nav-section-title,
body.customer-buy-data-page .nav-link,
body.customer-buy-data-page .sidebar-brand h3,
body.customer-buy-data-page .sidebar-brand small {
    color: #dbe7ff;
}

body.customer-buy-data-page .nav-link.active {
    background: linear-gradient(90deg, rgba(37, 99, 235, 0.45), rgba(37, 99, 235, 0.18));
    border-left: 4px solid #fbbf24;
    color: #fff;
}

body.customer-buy-data-page .dashboard-header {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 1rem 0 0.75rem;
    margin-bottom: 0.2rem;
}

body.customer-buy-data-page .breadcrumb-item,
body.customer-buy-data-page .breadcrumb-item i {
    color: #94a3b8;
}

body.customer-buy-data-page .breadcrumb-item.active {
    color: #ffffff;
}

body.customer-buy-data-page .wallet-balance {
    background: linear-gradient(135deg, #2f9b78, #56b68f);
    color: #fff;
    border-radius: 8px;
    padding: 0.75rem 1.2rem;
    margin-right: 0;
}

body.customer-buy-data-page .topup-btn {
    background: linear-gradient(135deg, #2953d9, #1940ba);
    border-color: transparent;
    border-radius: 10px;
}

body.customer-buy-data-page .theme-toggle {
    background: #081224;
    border: 1px solid rgba(59, 130, 246, 0.18);
    color: #fff;
}

body.customer-buy-data-page .user-dropdown-toggle {
    background: #0c1627;
    border: 1px solid rgba(148, 163, 184, 0.18);
    color: #fff;
    border-radius: 999px;
    padding: 0.45rem 0.7rem;
}

body.customer-buy-data-page .user-name,
body.customer-buy-data-page .user-role {
    color: #fff;
}

body.customer-buy-data-page .user-role {
    opacity: 0.72;
}

body.customer-buy-data-page .dashboard-content {
    background: transparent;
    border-radius: 0;
    padding: var(--spacing-xl);
    max-width: none;
    margin: 0;
    width: 100%;
}

body.customer-buy-data-page .buy-data-heading {
    margin-bottom: 2.25rem;
    padding: 2.2rem 0 0;
}

body.customer-buy-data-page .buy-data-heading h1 {
    margin: 0 0 0.7rem;
    font-size: clamp(2.2rem, 3vw, 3.15rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    color: #ffffff;
}

body.customer-buy-data-page .buy-data-heading .page-subtitle {
    margin: 0;
    color: #a9b9d0;
    font-size: 1.05rem;
    line-height: 1.6;
}

body.customer-buy-data-page .alert {
    background: linear-gradient(135deg, rgba(30, 58, 138, 0.92), rgba(30, 64, 175, 0.86));
    border: 1px solid rgba(96, 165, 250, 0.2);
    border-left: 4px solid #fbbf24;
    color: #ffffff;
    box-shadow: none;
}

body.customer-buy-data-page .alert-warning {
    background: linear-gradient(135deg, rgba(120, 53, 15, 0.92), rgba(146, 64, 14, 0.86));
}

body.customer-buy-data-page .network-tabs {
    gap: 1.25rem;
    margin-bottom: 1.9rem;
    padding-bottom: 0.8rem;
    border-bottom: 1px solid rgba(51, 65, 85, 0.9);
    align-items: flex-end;
}

body.customer-buy-data-page .network-tab {
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    border-radius: 0;
    box-shadow: none;
    color: #94a3b8;
    padding: 0.35rem 0.2rem 0.9rem;
    font-size: 0.98rem;
    font-weight: 600;
    gap: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 0.01em;
}

body.customer-buy-data-page .network-tab.active,
body.customer-buy-data-page .network-tab:hover {
    color: #e6edf8;
    background: transparent;
    border-bottom-color: #2f65ff;
    transform: none;
}

body.customer-buy-data-page .network-tab-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 0.45rem;
    background: rgba(255, 255, 255, 0.96);
    color: #1e3a8a;
    font-size: 0.82rem;
    font-weight: 900;
    text-transform: lowercase;
}

body.customer-buy-data-page .network-tab-mark img,
body.customer-buy-data-page .package-logo-badge img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: inherit;
    background: transparent;
    padding: 0;
    border: none;
    outline: none;
    box-shadow: none;
}

body.customer-buy-data-page .network-mark-at {
    color: #1d4ed8;
    background: rgba(255, 255, 255, 0.96);
    overflow: hidden;
}

body.customer-buy-data-page .network-mark-mtn {
    background: #fff7cc;
    color: #111827;
    overflow: hidden;
}

body.customer-buy-data-page .network-mark-telecel {
    background: #fff1f2;
    color: #fff;
    overflow: hidden;
}

body.customer-buy-data-page .package-count {
    background: rgba(37, 99, 235, 0.18);
    color: #e2e8f0;
    min-width: 1.75rem;
    text-align: center;
    font-size: 0.72rem;
}

body.customer-buy-data-page .network-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    margin-bottom: 2rem;
    padding: 1.35rem 1.45rem;
    border-radius: 24px;
    border: 1px solid rgba(59, 130, 246, 0.2);
    background: rgba(5, 10, 21, 0.88);
    box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.6);
}

body.customer-buy-data-page .network-header-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.44rem 0.88rem;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.12);
    color: #2f65ff;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.74rem;
    font-weight: 800;
    margin-bottom: 0.9rem;
}

body.customer-buy-data-page .network-header h2 {
    margin: 0 0 0.55rem;
    color: #ffffff;
    font-size: clamp(1.8rem, 2.4vw, 2.65rem);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: -0.03em;
}

body.customer-buy-data-page .network-description {
    margin: 0;
    color: #9cb0c8;
    font-size: 1rem;
}

body.customer-buy-data-page .network-switch-btn {
    border-radius: 14px;
    border-color: rgba(96, 165, 250, 0.22);
    color: #ffffff;
    background: rgba(7, 15, 28, 0.9);
    min-width: 190px;
    min-height: 48px;
    justify-content: center;
    font-weight: 700;
}

body.customer-buy-data-page .network-switch-btn:hover {
    background: rgba(29, 78, 216, 0.2);
}

body.customer-buy-data-page .widget {
    background: #08111d;
    border: 1px solid rgba(59, 130, 246, 0.18);
    box-shadow: none;
}

body.customer-buy-data-page .widget-title,
body.customer-buy-data-page .widget-body,
body.customer-buy-data-page .widget-header,
body.customer-buy-data-page .form-label {
    color: #e5eefb;
}

body.customer-buy-data-page .form-control {
    background: #0b1727;
    border-color: rgba(148, 163, 184, 0.2);
    color: #fff;
}

body.customer-buy-data-page .packages-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.1rem;
}

body.customer-buy-data-page .package-card {
    border: 1px solid rgba(96, 165, 250, 0.16);
    border-radius: 24px;
    background: linear-gradient(180deg, #2b55d7 0%, #1f45b5 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
    min-height: 270px;
    display: flex;
    flex-direction: column;
}

body.customer-buy-data-page .package-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 18px 34px rgba(10, 24, 60, 0.34);
}

body.customer-buy-data-page .package-header {
    padding: 1.35rem 1.35rem 0.55rem;
    background: transparent;
    border-bottom: none;
}

body.customer-buy-data-page .package-branding {
    display: flex;
    align-items: flex-start;
    gap: 0.9rem;
}

body.customer-buy-data-page .package-logo-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.95);
    font-size: 1.28rem;
    font-weight: 900;
    text-transform: lowercase;
    box-shadow: 0 10px 18px rgba(15, 23, 42, 0.16);
}

body.customer-buy-data-page .package-title-block {
    min-width: 0;
}

body.customer-buy-data-page .package-name {
    margin: 0 0 0.2rem;
    font-size: 1.22rem;
    line-height: 1.05;
    color: #ffffff;
    font-weight: 800;
}

body.customer-buy-data-page .package-provider,
body.customer-buy-data-page .package-provider-sub {
    color: rgba(255, 255, 255, 0.92);
    font-weight: 700;
    text-transform: uppercase;
}

body.customer-buy-data-page .package-provider {
    font-size: 0.72rem;
    letter-spacing: 0.03em;
    margin-bottom: 0.2rem;
}

body.customer-buy-data-page .package-provider-sub {
    font-size: 0.7rem;
    opacity: 0.9;
}

body.customer-buy-data-page .package-body {
    padding: 0 1.35rem 1rem;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

body.customer-buy-data-page .package-details {
    display: flex;
    gap: 0.55rem;
    margin-bottom: 1.45rem;
}

body.customer-buy-data-page .detail-item {
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

body.customer-buy-data-page .package-price {
    padding: 0;
    background: transparent;
    border-radius: 0;
    gap: 0.2rem;
}

body.customer-buy-data-page .price-label {
    display: none;
}

body.customer-buy-data-page .price-value {
    color: #ffffff;
    font-size: clamp(1.75rem, 2.1vw, 2.3rem);
    font-weight: 800;
    line-height: 1.05;
}

body.customer-buy-data-page .agent-price-badge {
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
    margin-top: 0.45rem;
}

body.customer-buy-data-page .package-footer {
    padding: 0 1.15rem 1.15rem;
}

body.customer-buy-data-page .package-footer .btn {
    min-height: 40px;
    padding: 0.65rem 0.95rem;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: linear-gradient(180deg, rgba(103, 146, 255, 0.9), rgba(76, 116, 238, 0.9));
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    line-height: 1.2;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15);
}

body.customer-buy-data-page .package-footer .btn:disabled {
    background: rgba(148, 163, 184, 0.34);
    color: rgba(255, 255, 255, 0.8);
}

body.customer-buy-data-page .recipient-form {
    background: rgba(6, 15, 29, 0.52);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #fff;
}

body.customer-buy-data-page .btn-outline {
    border-color: rgba(96, 165, 250, 0.22);
    color: #fff;
}

body.customer-buy-data-page .empty-state {
    background: #08111d;
    border: 1px solid rgba(59, 130, 246, 0.18);
    border-radius: 20px;
    color: #dbe7ff;
}

@media (max-width: 1200px) {
    body.customer-buy-data-page .packages-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    body.customer-buy-data-page .network-header {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 768px) {
    body.customer-buy-data-page .dashboard-header {
        padding-top: 0.5rem;
    }

    body.customer-buy-data-page .dashboard-content {
        padding: 1rem 0.9rem 1.25rem;
    }

    body.customer-buy-data-page .network-tabs {
        gap: 0.75rem;
        padding-bottom: 0.55rem;
    }

    body.customer-buy-data-page .network-tab {
        flex: 0 0 auto;
        border-radius: 0;
        padding-bottom: 0.65rem;
    }

    body.customer-buy-data-page .packages-grid {
        grid-template-columns: 1fr;
    }

    body.customer-buy-data-page .network-header {
        padding: 1.1rem;
        border-radius: 18px;
    }

    body.customer-buy-data-page .buy-data-heading {
        padding-top: 1rem;
        margin-bottom: 1.6rem;
    }

    body.customer-buy-data-page .package-card {
        min-height: 0;
    }
}

/* Re-align this page with the default website theme colors. */
body.customer-buy-data-page {
    background: var(--bg-primary);
    color: var(--text-primary);
}

body.customer-buy-data-page .dashboard-wrapper,
body.customer-buy-data-page .dashboard-content {
    background: transparent;
}

body.customer-buy-data-page .main-content {
    min-height: 100vh;
    overflow: visible;
}

body.customer-buy-data-page .sidebar {
    background: var(--bg-secondary);
    border-right: 1px solid var(--border-color);
}

body.customer-buy-data-page .sidebar-brand,
body.customer-buy-data-page .nav-section-title,
body.customer-buy-data-page .nav-link,
body.customer-buy-data-page .sidebar-brand h3,
body.customer-buy-data-page .sidebar-brand small {
    color: inherit;
}

body.customer-buy-data-page .nav-link.active {
    background: var(--bg-tertiary);
    border-left: none;
    color: var(--brand-primary);
}

body.customer-buy-data-page .dashboard-header {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 18px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}

body.customer-buy-data-page .breadcrumb-item,
body.customer-buy-data-page .breadcrumb-item i {
    color: var(--text-muted);
}

body.customer-buy-data-page .breadcrumb-item.active,
body.customer-buy-data-page .buy-data-heading h1 {
    color: var(--text-primary);
}

body.customer-buy-data-page .buy-data-heading .page-subtitle {
    color: var(--text-muted);
}

body.customer-buy-data-page .wallet-balance {
    background: var(--success-color);
    color: white;
}

body.customer-buy-data-page .topup-btn {
    background: var(--brand-primary);
    border-color: var(--brand-primary);
}

body.customer-buy-data-page .theme-toggle,
body.customer-buy-data-page .user-dropdown-toggle {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

body.customer-buy-data-page .user-name,
body.customer-buy-data-page .user-role {
    color: var(--text-primary);
}

body.customer-buy-data-page .alert {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--brand-primary);
    color: var(--text-primary);
}

body.customer-buy-data-page .network-tabs {
    border-bottom: 1px solid var(--border-color);
}

body.customer-buy-data-page .network-tab {
    color: var(--text-muted);
}

body.customer-buy-data-page .network-tab.active,
body.customer-buy-data-page .network-tab:hover {
    color: var(--brand-primary);
    border-bottom-color: var(--brand-primary);
}

body.customer-buy-data-page .package-count {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

body.customer-buy-data-page .network-tab-mark {
    background: var(--bg-secondary);
    color: var(--brand-primary);
    border: 1px solid var(--border-color);
}

body.customer-buy-data-page .network-mark-mtn {
    background: var(--bg-secondary);
    color: var(--brand-primary);
}

body.customer-buy-data-page .network-mark-telecel {
    background: var(--bg-secondary);
    color: var(--brand-primary);
}

body.customer-buy-data-page .network-header {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    box-shadow: none;
}

body.customer-buy-data-page .network-header-badge {
    background: rgba(99, 102, 241, 0.12);
    color: var(--brand-primary);
}

body.customer-buy-data-page .network-header h2 {
    color: var(--text-primary);
}

body.customer-buy-data-page .network-description {
    color: var(--text-muted);
}

body.customer-buy-data-page .network-switch-btn {
    background: transparent;
    color: var(--text-primary);
    border-color: var(--border-color);
}

body.customer-buy-data-page .network-switch-btn:hover {
    background: var(--bg-tertiary);
}

body.customer-buy-data-page .widget {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

body.customer-buy-data-page .widget-title,
body.customer-buy-data-page .widget-body,
body.customer-buy-data-page .widget-header,
body.customer-buy-data-page .form-label {
    color: var(--text-primary);
}

body.customer-buy-data-page .form-control {
    background: var(--bg-primary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

body.customer-buy-data-page .package-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}

body.customer-buy-data-page .package-card:hover {
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12);
}

body.customer-buy-data-page .package-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(59, 130, 246, 0.06));
}

body.customer-buy-data-page .package-logo-badge {
    background: var(--bg-primary);
    color: var(--brand-primary);
    box-shadow: none;
    border: 1px solid var(--border-color);
}

body.customer-buy-data-page .network-tab-mark.network-mark-at {
    width: 1.35rem;
    height: 1.35rem;
    padding: 0.08rem;
    border: none;
    box-shadow: none;
    background: rgba(255, 255, 255, 0.96);
}

body.customer-buy-data-page .package-logo-badge.network-mark-at {
    width: 3.1rem;
    height: 2.1rem;
    padding: 0.22rem 0.42rem;
    border: none;
    box-shadow: none;
    background: rgba(255, 255, 255, 0.96);
    border-radius: 999px;
}

body.customer-buy-data-page .network-tab-mark.network-mark-at img {
    padding: 0;
    background: transparent;
    border: none;
    outline: none;
    box-shadow: none;
    filter: none;
}

body.customer-buy-data-page .package-logo-badge.network-mark-at img {
    padding: 0;
    background: transparent;
    border: none;
    outline: none;
    box-shadow: none;
    filter: none;
}

body.customer-buy-data-page .package-name,
body.customer-buy-data-page .package-provider,
body.customer-buy-data-page .package-provider-sub {
    color: var(--text-primary);
}

body.customer-buy-data-page .detail-item {
    color: var(--text-muted);
}

body.customer-buy-data-page .price-value {
    color: var(--brand-primary);
}

body.customer-buy-data-page .agent-price-badge {
    background: var(--success-color);
    color: white;
}

body.customer-buy-data-page .package-footer .btn {
    background: var(--brand-primary);
    border-color: var(--brand-primary);
    color: white;
    box-shadow: none;
}

body.customer-buy-data-page .form-actions {
    gap: 0.5rem;
    flex-wrap: wrap;
}

body.customer-buy-data-page .form-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    flex: 1 1 140px;
    min-width: min(140px, 100%);
    min-height: 38px;
    padding: 0.55rem 0.85rem;
    font-size: 0.88rem;
    line-height: 1.15;
    text-align: center;
    white-space: normal;
}

body.customer-buy-data-page .package-footer .btn:disabled {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
    color: var(--text-muted);
}

body.customer-buy-data-page .package-card.package-card-at {
    background: linear-gradient(180deg, #1f3f86 0%, #17306a 100%);
    border: 1px solid rgba(20, 41, 91, 0.28);
    box-shadow: 0 14px 26px rgba(31, 63, 134, 0.2);
    min-height: 286px;
}

body.customer-buy-data-page .package-card.package-card-at:hover {
    box-shadow: 0 18px 30px rgba(31, 63, 134, 0.26);
}

body.customer-buy-data-page .package-card.package-card-at .package-header {
    background: transparent;
    padding: 1.45rem 1.5rem 0.35rem;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-at .package-branding,
body.customer-buy-data-page .package-card.package-card-at .package-title-block,
body.customer-buy-data-page .package-card.package-card-at .package-body,
body.customer-buy-data-page .package-card.package-card-at .package-details,
body.customer-buy-data-page .package-card.package-card-at .package-price,
body.customer-buy-data-page .package-card.package-card-at .package-footer {
    align-items: center;
    text-align: center;
    justify-content: center;
}

body.customer-buy-data-page .package-card.package-card-at .package-branding {
    flex-direction: column;
    gap: 0.7rem;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-at .package-title-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-at .package-name,
body.customer-buy-data-page .package-card.package-card-at .package-provider,
body.customer-buy-data-page .package-card.package-card-at .package-provider-sub,
body.customer-buy-data-page .package-card.package-card-at .detail-item,
body.customer-buy-data-page .package-card.package-card-at .price-value {
    color: #ffffff;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-at .package-provider {
    font-size: 0.88rem;
    letter-spacing: 0;
    margin-bottom: 0.12rem;
    font-weight: 800;
}

body.customer-buy-data-page .package-card.package-card-at .package-provider-sub {
    font-size: 0.88rem;
    opacity: 0.95;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-at .package-name {
    font-size: clamp(1.5rem, 2vw, 1.9rem);
    line-height: 0.96;
    margin-bottom: 0.35rem;
    letter-spacing: -0.03em;
}

body.customer-buy-data-page .package-card.package-card-at .package-body {
    padding: 0 1.5rem 1.15rem;
    justify-content: flex-start;
}

body.customer-buy-data-page .package-card.package-card-at .package-details {
    flex-direction: column;
    gap: 0.45rem;
    margin-bottom: 1.15rem;
}

body.customer-buy-data-page .package-card.package-card-at .detail-item {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    font-size: 0.65rem;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-at .price-value {
    font-size: clamp(1.35rem, 2vw, 1.8rem);
    font-weight: 900;
    letter-spacing: -0.04em;
}

body.customer-buy-data-page .package-card.package-card-at .package-logo-badge {
    width: 3.1rem;
    height: 2.1rem;
    border-radius: 999px;
    padding: 0.22rem 0.42rem;
    background: rgba(255, 255, 255, 0.96);
    border: none;
    box-shadow: none;
}

body.customer-buy-data-page .package-card.package-card-at .package-logo-badge img {
    background: transparent;
    padding: 0;
}

body.customer-buy-data-page .package-card.package-card-at .agent-price-badge {
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
}

body.customer-buy-data-page .package-card.package-card-at .package-footer {
    padding: 0 1.5rem 1.35rem;
    margin-top: auto;
}

body.customer-buy-data-page .package-card.package-card-at .package-footer .btn {
    min-height: 46px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.28);
    color: #ffffff;
    box-shadow: none;
    font-weight: 800;
    border-radius: 14px;
}

body.customer-buy-data-page .package-card.package-card-at .package-footer .btn:hover {
    background: rgba(255, 255, 255, 0.18);
}

body.customer-buy-data-page .package-card.package-card-at .package-footer .btn:disabled {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.18);
    color: rgba(255, 255, 255, 0.65);
}

body.customer-buy-data-page .package-card.package-card-at .recipient-form {
    background: rgba(18, 34, 73, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.16);
    color: #ffffff;
}

body.customer-buy-data-page .package-card.package-card-at .recipient-form .form-label,
body.customer-buy-data-page .package-card.package-card-at .recipient-form small {
    color: #ffffff;
}

body.customer-buy-data-page .package-card.package-card-mtn {
    background: linear-gradient(180deg, #ffd644 0%, #ffc800 100%);
    border: 1px solid rgba(117, 90, 0, 0.18);
    box-shadow: 0 14px 26px rgba(117, 90, 0, 0.1);
    min-height: 286px;
}

body.customer-buy-data-page .package-card.package-card-mtn:hover {
    box-shadow: 0 18px 30px rgba(117, 90, 0, 0.14);
}

body.customer-buy-data-page .package-card.package-card-mtn .package-header {
    background: transparent;
    padding: 1.45rem 1.5rem 0.35rem;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-branding,
body.customer-buy-data-page .package-card.package-card-mtn .package-title-block,
body.customer-buy-data-page .package-card.package-card-mtn .package-body,
body.customer-buy-data-page .package-card.package-card-mtn .package-details,
body.customer-buy-data-page .package-card.package-card-mtn .package-price,
body.customer-buy-data-page .package-card.package-card-mtn .package-footer {
    align-items: center;
    text-align: center;
    justify-content: center;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-branding {
    flex-direction: column;
    gap: 0.7rem;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-title-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-name,
body.customer-buy-data-page .package-card.package-card-mtn .package-provider,
body.customer-buy-data-page .package-card.package-card-mtn .package-provider-sub,
body.customer-buy-data-page .package-card.package-card-mtn .detail-item,
body.customer-buy-data-page .package-card.package-card-mtn .price-value {
    color: #0b1b3a;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-provider {
    font-size: 0.88rem;
    letter-spacing: 0;
    margin-bottom: 0.12rem;
    font-weight: 800;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-provider-sub {
    font-size: 0.88rem;
    opacity: 1;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-name {
    font-size: clamp(1.5rem, 2vw, 1.9rem);
    line-height: 0.96;
    margin-bottom: 0.35rem;
    letter-spacing: -0.03em;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-body {
    padding: 0 1.5rem 1.15rem;
    justify-content: flex-start;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-details {
    flex-direction: column;
    gap: 0.45rem;
    margin-bottom: 1.15rem;
}

body.customer-buy-data-page .package-card.package-card-mtn .detail-item {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    font-size: 0.65rem;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-mtn .price-value {
    font-size: clamp(1.35rem, 2vw, 1.8rem);
    font-weight: 900;
    letter-spacing: -0.04em;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-logo-badge {
    width: 3.1rem;
    height: 2.1rem;
    border-radius: 999px;
    padding: 0.22rem 0.42rem;
    background: transparent;
    border: none;
    box-shadow: none;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-logo-badge img {
    background: transparent;
    padding: 0;
}

body.customer-buy-data-page .package-card.package-card-mtn .agent-price-badge {
    background: rgba(11, 27, 58, 0.12);
    color: #0b1b3a;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-footer {
    padding: 0 1.5rem 1.35rem;
    margin-top: auto;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-footer .btn {
    min-height: 46px;
    background: rgba(255, 204, 0, 0.08);
    border: 1px solid rgba(117, 90, 0, 0.22);
    color: #0b1b3a;
    box-shadow: none;
    font-weight: 800;
    border-radius: 14px;
}

body.customer-buy-data-page .package-card.package-card-mtn .package-footer .btn:hover {
    background: rgba(255, 204, 0, 0.16);
}

body.customer-buy-data-page .package-card.package-card-mtn .package-footer .btn:disabled {
    background: rgba(11, 27, 58, 0.08);
    border-color: rgba(11, 27, 58, 0.14);
    color: rgba(11, 27, 58, 0.55);
}

body.customer-buy-data-page .package-card.package-card-mtn .recipient-form {
    background: rgba(255, 246, 204, 0.72);
    border: 1px solid rgba(117, 90, 0, 0.16);
    color: #0b1b3a;
}

body.customer-buy-data-page .package-card.package-card-mtn .recipient-form .form-label,
body.customer-buy-data-page .package-card.package-card-mtn .recipient-form small {
    color: #0b1b3a;
}

body.customer-buy-data-page .package-card.package-card-telecel {
    background: linear-gradient(180deg, #ff4b55 0%, #e61e2a 100%);
    border: 1px solid rgba(126, 7, 17, 0.2);
    box-shadow: 0 14px 26px rgba(126, 7, 17, 0.14);
    min-height: 286px;
}

body.customer-buy-data-page .package-card.package-card-telecel:hover {
    box-shadow: 0 18px 30px rgba(126, 7, 17, 0.18);
}

body.customer-buy-data-page .package-card.package-card-telecel .package-header {
    background: transparent;
    padding: 1.45rem 1.5rem 0.35rem;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-branding,
body.customer-buy-data-page .package-card.package-card-telecel .package-title-block,
body.customer-buy-data-page .package-card.package-card-telecel .package-body,
body.customer-buy-data-page .package-card.package-card-telecel .package-details,
body.customer-buy-data-page .package-card.package-card-telecel .package-price,
body.customer-buy-data-page .package-card.package-card-telecel .package-footer {
    align-items: center;
    text-align: center;
    justify-content: center;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-branding {
    flex-direction: column;
    gap: 0.7rem;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-title-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-name,
body.customer-buy-data-page .package-card.package-card-telecel .package-provider,
body.customer-buy-data-page .package-card.package-card-telecel .package-provider-sub,
body.customer-buy-data-page .package-card.package-card-telecel .detail-item,
body.customer-buy-data-page .package-card.package-card-telecel .price-value {
    color: #ffffff;
    text-align: center;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-provider {
    font-size: 0.88rem;
    letter-spacing: 0;
    margin-bottom: 0.12rem;
    font-weight: 800;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-provider-sub {
    font-size: 0.88rem;
    opacity: 0.95;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-name {
    font-size: clamp(1.5rem, 2vw, 1.9rem);
    line-height: 0.96;
    margin-bottom: 0.35rem;
    letter-spacing: -0.03em;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-body {
    padding: 0 1.5rem 1.15rem;
    justify-content: flex-start;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-details {
    flex-direction: column;
    gap: 0.45rem;
    margin-bottom: 1.15rem;
}

body.customer-buy-data-page .package-card.package-card-telecel .detail-item {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    font-size: 0.65rem;
    font-weight: 700;
}

body.customer-buy-data-page .package-card.package-card-telecel .price-value {
    font-size: clamp(1.35rem, 2vw, 1.8rem);
    font-weight: 900;
    letter-spacing: -0.04em;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-logo-badge {
    width: 3.1rem;
    height: 2.1rem;
    border-radius: 999px;
    padding: 0.22rem 0.42rem;
    background: transparent;
    border: none;
    box-shadow: none;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-logo-badge img {
    background: transparent;
    padding: 0;
}

body.customer-buy-data-page .package-card.package-card-telecel .agent-price-badge {
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-footer {
    padding: 0 1.5rem 1.35rem;
    margin-top: auto;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-footer .btn {
    min-height: 46px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #ffffff;
    box-shadow: none;
    font-weight: 800;
    border-radius: 14px;
}

body.customer-buy-data-page .package-card.package-card-telecel .package-footer .btn:hover {
    background: rgba(255, 255, 255, 0.14);
}

body.customer-buy-data-page .package-card.package-card-telecel .package-footer .btn:disabled {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(255, 255, 255, 0.16);
    color: rgba(255, 255, 255, 0.65);
}

body.customer-buy-data-page .package-card.package-card-telecel .recipient-form {
    background: rgba(126, 7, 17, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.14);
    color: #ffffff;
}

body.customer-buy-data-page .package-card.package-card-telecel .recipient-form .form-label,
body.customer-buy-data-page .package-card.package-card-telecel .recipient-form small {
    color: #ffffff;
}

@media (max-width: 768px) {
    body.customer-buy-data-page .package-card.package-card-mtn .package-header {
        padding: 1.2rem 1.15rem 0.25rem;
    }

    body.customer-buy-data-page .package-card.package-card-mtn .package-body {
        padding: 0 1.15rem 1rem;
    }

    body.customer-buy-data-page .package-card.package-card-mtn .package-footer {
        padding: 0 1.15rem 1.15rem;
    }

    body.customer-buy-data-page .package-card.package-card-mtn .package-name {
        font-size: clamp(1.35rem, 7vw, 1.7rem);
    }

    body.customer-buy-data-page .package-card.package-card-mtn .price-value {
        font-size: clamp(1.25rem, 8vw, 1.65rem);
    }

    body.customer-buy-data-page .package-card.package-card-telecel .package-header {
        padding: 1.2rem 1.15rem 0.25rem;
    }

    body.customer-buy-data-page .package-card.package-card-telecel .package-body {
        padding: 0 1.15rem 1rem;
    }

    body.customer-buy-data-page .package-card.package-card-telecel .package-footer {
        padding: 0 1.15rem 1.15rem;
    }

    body.customer-buy-data-page .package-card.package-card-telecel .package-name {
        font-size: clamp(1.35rem, 7vw, 1.7rem);
    }

    body.customer-buy-data-page .package-card.package-card-telecel .price-value {
        font-size: clamp(1.25rem, 8vw, 1.65rem);
    }
}

body.customer-buy-data-page .recipient-form,
body.customer-buy-data-page .empty-state {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

body.customer-buy-data-page .btn-outline {
    border-color: var(--border-color);
    color: var(--text-primary);
}

@media (max-width: 768px) {
    body.customer-buy-data-page .package-footer {
        padding: 0 1rem 1rem;
    }

    body.customer-buy-data-page .package-footer .btn {
        min-height: 36px;
        padding: 0.58rem 0.85rem;
        border-radius: 11px;
        font-size: 0.9rem;
    }

    body.customer-buy-data-page .form-actions .btn {
        flex: 1 1 calc(50% - 0.5rem);
        min-width: 0;
        font-size: 0.84rem;
    }
}

@media (max-width: 480px) {
    body.customer-buy-data-page .package-footer {
        padding: 0 0.9rem 0.9rem;
    }

    body.customer-buy-data-page .package-footer .btn {
        min-height: 34px;
        padding: 0.52rem 0.78rem;
        font-size: 0.86rem;
    }

    body.customer-buy-data-page .form-actions .btn {
        flex: 1 1 100%;
        width: 100%;
    }
}

/* Final spacing alignment with customer/dashboard.php */
body.customer-buy-data-page .dashboard-header {
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
}

body.customer-buy-data-page .dashboard-content {
    padding: var(--spacing-xl);
}

body.customer-buy-data-page .buy-data-heading {
    padding-top: 0;
    margin-bottom: var(--spacing-xl);
}

@media (max-width: 768px) {
    body.customer-buy-data-page .dashboard-header {
        padding: var(--spacing-md);
        margin-bottom: var(--spacing-md);
        border-radius: 14px;
    }

    body.customer-buy-data-page .dashboard-content {
        padding: var(--spacing-md);
    }

    body.customer-buy-data-page .buy-data-heading {
        padding-top: 0;
        margin-bottom: var(--spacing-lg);
    }
}

.customer-network-group {
    display: none;
    gap: 1.5rem;
}

.customer-network-group.active {
    display: grid;
}

.network-tab-logo {
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 20px;
}

.network-tab-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.service-selector-kicker {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(99, 102, 241, 0.12);
    color: var(--brand-primary);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08rem;
    text-transform: uppercase;
}

.service-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1.6rem;
    border-radius: 24px;
    border: 1px solid var(--border-color);
    background: var(--card-bg);
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
}

.service-page-copy {
    display: grid;
    gap: 0.45rem;
}

.service-page-copy h2 {
    margin: 0;
    color: var(--text-primary);
    font-size: clamp(1.5rem, 3vw, 2rem);
    line-height: 1.1;
}

.service-page-copy p {
    margin: 0;
    color: var(--text-muted);
    line-height: 1.55;
}

.service-selector-reset {
    white-space: nowrap;
}

.network-tabs .network-tab {
    text-transform: uppercase;
}

.network-group-mtn .packages-grid,
.network-group-at .packages-grid,
.network-group-telecel .packages-grid {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.package-card-mtn,
.package-card-at,
.package-card-telecel {
    border-radius: 22px;
    padding: 1.6rem;
    border: 1px solid transparent;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
    display: grid;
    gap: 1rem;
    overflow: hidden;
}

.package-card-mtn {
    background: linear-gradient(180deg, #ffcd16 0%, #ffc400 100%);
    border-color: rgba(214, 163, 0, 0.65);
    color: #111827;
}

.package-card-at {
    background: linear-gradient(180deg, #173fae 0%, #153da8 100%);
    border-color: rgba(125, 160, 255, 0.22);
    color: #f8fbff;
}

.package-card-telecel {
    background: linear-gradient(180deg, #f20505 0%, #eb0000 100%);
    border-color: rgba(255, 170, 170, 0.18);
    color: #ffffff;
}

.package-card-mtn-head,
.package-card-at-head,
.package-card-telecel-head {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    gap: 0.75rem;
    min-height: 120px;
    padding-top: 0.35rem;
    text-align: center;
}

.package-card-mtn-logo,
.package-card-at-logo,
.package-card-telecel-logo {
    flex: 0 0 52px;
    width: 52px;
    height: 52px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.package-card-mtn-logo {
    background: rgba(255, 235, 140, 0.45);
    box-shadow: inset 0 0 0 1px rgba(17, 24, 39, 0.12);
}

.package-card-at-logo {
    background: rgba(255, 255, 255, 0.96);
    box-shadow: inset 0 0 0 1px rgba(12, 37, 106, 0.08);
}

.package-card-telecel-logo {
    background: rgba(255, 42, 42, 0.92);
    box-shadow: inset 0 0 0 1px rgba(255, 205, 205, 0.35);
}

.package-card-mtn-logo img,
.package-card-at-logo img,
.package-card-telecel-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.package-card-mtn-copy,
.package-card-at-copy,
.package-card-telecel-copy {
    display: grid;
    gap: 0.32rem;
    align-content: start;
    justify-items: center;
    text-align: center;
}

.package-card-mtn-copy h4,
.package-card-at-copy h4,
.package-card-telecel-copy h4 {
    margin: 0;
    font-size: 2rem;
    line-height: 1;
    letter-spacing: -0.04em;
    font-family: "Space Grotesk", "Work Sans", sans-serif;
}

.package-card-mtn-copy h4 {
    color: #111827;
}

.package-card-at-copy h4,
.package-card-telecel-copy h4 {
    color: #ffffff;
}

.package-card-mtn-copy p,
.package-card-at-copy p,
.package-card-telecel-copy p {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.35;
}

.package-card-mtn-copy p {
    color: rgba(17, 24, 39, 0.9);
}

.package-card-at-copy p {
    color: rgba(243, 247, 255, 0.96);
}

.package-card-at-copy p span {
    display: block;
}

.package-card-telecel-copy p {
    color: rgba(255, 244, 244, 0.96);
    letter-spacing: 0.02em;
}

.package-price-mtn,
.package-price-at,
.package-price-telecel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    width: 100%;
    margin-top: auto;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    font-family: "Space Grotesk", "Work Sans", sans-serif;
    text-align: center;
}

.package-price-mtn {
    color: #111827;
    background: transparent;
    border-radius: 0;
    padding: 0;
}

.package-price-at,
.package-price-telecel {
    color: #ffffff;
    background: transparent;
    border-radius: 0;
    padding: 0;
}

.custom-price-badge,
.stock-status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.28rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    margin-left: 0.55rem;
    vertical-align: middle;
}

.package-card-mtn .custom-price-badge {
    background: rgba(17, 24, 39, 0.82);
    color: #fff7c2;
}

.package-card-at .custom-price-badge,
.package-card-telecel .custom-price-badge {
    background: rgba(255, 255, 255, 0.18);
    color: #ffffff;
}

.stock-status-badge {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.package-footer-store {
    display: grid;
    gap: 0.85rem;
}

.package-card-mtn-btn,
.package-card-at-btn,
.package-card-telecel-btn {
    min-height: 50px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    font-weight: 800;
    width: 100%;
}

.package-card-mtn-btn {
    border: 1px solid rgba(186, 140, 0, 0.58);
    background: linear-gradient(180deg, #f8c800 0%, #f0be00 100%);
    color: #111827;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35);
}

.package-card-at-btn {
    border: 1px solid rgba(118, 154, 255, 0.45);
    background: linear-gradient(180deg, rgba(71, 112, 220, 0.92) 0%, rgba(56, 98, 205, 0.92) 100%);
    color: #ffffff;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
}

.package-card-telecel-btn {
    border: 1px solid rgba(255, 167, 167, 0.32);
    background: linear-gradient(180deg, #ff2323 0%, #f91c1c 100%);
    color: #ffffff;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
}

.package-card-mtn-btn:hover {
    background: linear-gradient(180deg, #ffd321 0%, #f4c400 100%);
    color: #111827;
}

.package-card-at-btn:hover,
.package-card-at-btn:focus-visible {
    background: linear-gradient(180deg, rgba(82, 123, 231, 0.96) 0%, rgba(63, 105, 214, 0.96) 100%);
    color: #ffffff;
}

.package-card-telecel-btn:hover,
.package-card-telecel-btn:focus-visible {
    background: linear-gradient(180deg, #ff3838 0%, #ff2323 100%);
    color: #ffffff;
}

.package-card-mtn-btn[disabled],
.package-card-at-btn[disabled],
.package-card-telecel-btn[disabled] {
    opacity: 0.72;
    cursor: not-allowed;
    transform: none;
}

.recipient-form-store {
    padding: 1rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(8px);
}

.package-card-mtn .recipient-form-store .form-label,
.package-card-mtn .recipient-form-store .btn-secondary,
.package-card-mtn .recipient-form-store .btn-success {
    color: #111827;
}

.package-card-mtn .recipient-form-store .form-control {
    background: rgba(255, 255, 255, 0.95);
    border-color: rgba(17, 24, 39, 0.12);
    color: #111827;
}

.package-card-at .recipient-form-store,
.package-card-telecel .recipient-form-store {
    background: rgba(255, 255, 255, 0.12);
}

.package-card-at .recipient-form-store .form-label,
.package-card-telecel .recipient-form-store .form-label,
.package-card-at .purchase-option-hint,
.package-card-telecel .purchase-option-hint {
    color: rgba(255, 255, 255, 0.92);
}

.package-card-at .recipient-form-store .form-control,
.package-card-telecel .recipient-form-store .form-control {
    background: rgba(255, 255, 255, 0.95);
    border-color: rgba(255, 255, 255, 0.25);
    color: #111827;
}

.package-card-at .recipient-form-store .btn-secondary,
.package-card-telecel .recipient-form-store .btn-secondary {
    background: rgba(15, 23, 42, 0.2);
    border-color: rgba(255, 255, 255, 0.22);
    color: #ffffff;
}

.purchase-option-hint {
    display: block;
    margin-top: 0.2rem;
    color: rgba(15, 23, 42, 0.72);
    font-size: 0.82rem;
    line-height: 1.45;
}

.checkout-inline-error {
    margin-top: 0.25rem;
    padding: 0.7rem 0.85rem;
    border-radius: 12px;
    border: 1px solid rgba(239, 68, 68, 0.22);
    background: rgba(254, 226, 226, 0.92);
    color: #b91c1c;
    font-size: 0.85rem;
    line-height: 1.45;
}

.customer-checkout-btn {
    border: 1px solid rgba(15, 23, 42, 0.16);
    background: rgba(255, 255, 255, 0.96);
    color: #0f172a;
    min-height: 40px;
}

.customer-checkout-btn:hover,
.customer-checkout-btn:focus-visible {
    background: #ffffff;
    color: #0f172a;
    transform: translateY(-1px);
}

.package-card-at .customer-checkout-btn,
.package-card-telecel .customer-checkout-btn {
    border-color: rgba(255, 255, 255, 0.28);
    background: rgba(255, 255, 255, 0.96);
    color: #0f172a;
}

@media (max-width: 768px) {
    .service-page-header {
        padding: 1.25rem;
        border-radius: 18px;
        flex-direction: column;
        align-items: stretch;
    }

    .service-selector-reset {
        width: 100%;
        justify-content: center;
    }

    .package-card-mtn,
    .package-card-at,
    .package-card-telecel {
        padding: 1.45rem 1.25rem 1.25rem;
        border-radius: 18px;
    }

    .package-card-mtn-head,
    .package-card-at-head,
    .package-card-telecel-head {
        gap: 0.65rem;
        min-height: 108px;
    }

    .package-card-mtn-logo,
    .package-card-at-logo,
    .package-card-telecel-logo {
        width: 48px;
        height: 48px;
        border-radius: 12px;
    }

    .package-card-mtn-copy h4,
    .package-price-mtn,
    .package-card-at-copy h4,
    .package-price-at,
    .package-card-telecel-copy h4,
    .package-price-telecel {
        font-size: 1.7rem;
    }
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

