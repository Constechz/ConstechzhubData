<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);

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
    if (empty($store_slug_guard)) {
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
           COALESCE(n.color, '#541388') as network_color, 
           dp.package_type, dp.data_size, dp.validity_days,
           COALESCE(pp.price, dp.price, 0) as customer_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    WHERE (pp.price IS NOT NULL OR dp.price > 0) AND dp.status = 'active'
    GROUP BY dp.id, dp.name, COALESCE(n.name, 'Unknown'), COALESCE(n.color, '#541388'),
             dp.package_type, dp.data_size, dp.validity_days, COALESCE(pp.price, dp.price, 0)
    ORDER BY COALESCE(n.name, 'Unknown'), dp.package_type, 
             CAST(REGEXP_REPLACE(dp.data_size, '[^0-9.]', '') AS DECIMAL(10,2))
";

$packages_rs = $db->query($packages_query);
$packages = [];
while ($row = $packages_rs->fetch_assoc()) {
    // Use agent custom pricing if available, otherwise use customer pricing
    if ($agent_store && isset($agent_pricing[$row['id']])) {
        $row['display_price'] = $agent_pricing[$row['id']];
        $row['is_agent_price'] = true;
    } else {
        $row['display_price'] = $row['customer_price'];
        $row['is_agent_price'] = false;
    }
    $packages[] = $row;
}

// Group packages by network
$packages_by_network = [];
foreach ($packages as $package) {
    $packages_by_network[$package['network_name']][] = $package;
}

$flash = getFlashMessage();

$enabled_gateways = getEnabledPaymentGateways();
$enabled_gateways = array_values(array_filter($enabled_gateways, function ($name) {
    return in_array($name, ['paystack', 'moolre'], true);
}));

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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body class="buy-data-page">
<div class="dashboard-wrapper">
    <?php require_once '../includes/customer_sidebar.php'; ?>
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
                <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="wallet-balance" style="text-decoration: none;">
                    <i class="fas fa-wallet"></i>
                    <span>Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?></span>
                </a>
                <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-sm btn-primary header-action-btn topup-btn">
                    <i class="fas fa-plus-circle"></i><span class="topup-text"> Top Up</span>
                </a>
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'] ?? $_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Customer</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
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
                    Your wallet balance is low. Please top up your wallet to purchase data bundles.
                    <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-sm btn-primary" style="margin-left: 1rem;">Top Up Wallet</a>
                </div>
            <?php endif; ?>

            <!-- Network Tabs -->
            <div class="network-tabs">
                <?php $first = true; foreach ($packages_by_network as $network => $network_packages): ?>
                    <button type="button" class="network-tab <?php echo $first ? 'active' : ''; ?>" 
                            data-network="<?php echo strtolower($network); ?>"
                            onclick="showNetwork(event, '<?php echo strtolower($network); ?>')">
                        <i class="fas fa-signal"></i>
                        <?php echo htmlspecialchars($network); ?>
                        <span class="package-count"><?php echo count($network_packages); ?></span>
                    </button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>

            <!-- Package Grids by Network -->
            <?php $first = true; foreach ($packages_by_network as $network => $network_packages): ?>
                <div id="<?php echo strtolower($network); ?>-packages" 
                     class="network-packages <?php echo $first ? 'active' : ''; ?>">
                    
                    <div class="network-header">
                        <h2><?php echo htmlspecialchars($network); ?> Data Bundles</h2>
                        <p class="network-description">
                            <?php if ($network === 'MTN'): ?>
                                MTN UP2U bundles with flexible validity periods.
                            <?php elseif ($network === 'AT'): ?>
                                AT iShare bundles for fast and reliable internet.
                            <?php elseif ($network === 'Telecel'): ?>
                                Telecel data bundles with nationwide coverage.
                            <?php endif; ?>
                        </p>
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
                                <div class="form-group bulk-order-actions" style="display: flex; gap: 0.75rem; align-items: center;">
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
                            <div class="package-card" id="package-card-<?php echo $package['id']; ?>">
                                <div class="package-header">
                                    <div class="package-network">
                                        <span class="network-indicator" style="background-color: <?php echo htmlspecialchars($package['network_color'] ?? '#541388'); ?>"></span>
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
                                            <?php if ($package['is_agent_price']): ?>
                                                <small class="agent-price-badge">Agent Price</small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="package-footer">
                                    <button class="btn btn-primary btn-block" 
                                            onclick="buyPackage(<?php echo $package['id']; ?>)">
                                        <i class="fas fa-shopping-cart"></i> Buy Now
                                    </button>
                                    
                                    <!-- Inline recipient form (hidden by default) -->
                                    <form class="recipient-form" id="recipient-form-<?php echo $package['id']; ?>" method="post" action="" style="display:none;" 
                                          data-network="<?php echo htmlspecialchars($package['network_name']); ?>" 
                                          data-package-name="<?php echo htmlspecialchars($package['name']); ?>" 
                                          data-package-size="<?php echo htmlspecialchars($package['data_size']); ?>" 
                                          data-package-price="<?php echo htmlspecialchars(number_format((float) ($package['display_price'] ?? 0), 2, '.', '')); ?>">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                        <input type="hidden" name="order_submit_token" value="<?php echo $order_submit_token; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRF(); ?>">
                                        <?php if ($agent_store): ?>
                                            <input type="hidden" name="agent_id" value="<?php echo (int) $agent_store['agent_id']; ?>">
                                            <input type="hidden" name="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
                                        <?php endif; ?>
                                        
                                        <div class="form-group" style="margin-top: 1rem;">
                                            <label class="form-label" for="phone-<?php echo $package['id']; ?>">Beneficiary Number</label>
                                            <input type="tel" class="form-control" id="phone-<?php echo $package['id']; ?>" name="beneficiary_number" required placeholder="e.g. 0244123456" pattern="^(0\d{9}|233\d{9})$">
                                        </div>
                                        
                                        <div class="form-group" style="margin-top: 1rem;">
                                            <label class="form-label" for="payment_method-<?php echo $package['id']; ?>">Payment Method</label>
                                            <select class="form-control payment-method-select" id="payment_method-<?php echo $package['id']; ?>" name="payment_method">
                                                <option value="wallet">Wallet Balance (<?php echo CURRENCY . number_format($wallet_balance, 2); ?>)</option>
                                                <?php foreach ($enabled_gateways as $gateway): ?>
                                                    <option value="<?php echo htmlspecialchars($gateway); ?>"><?php echo ucfirst(htmlspecialchars($gateway)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-actions" style="margin-top: 1.25rem; display: flex; gap: 0.5rem;">
                                            <button type="submit" class="btn btn-primary btn-sm" style="flex: 1;">Submit Order</button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="hideRecipientForm(<?php echo $package['id']; ?>)" style="flex: 1;">Cancel</button>
                                        </div>
                                    </form>
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

<script>
    const purchaseSuccess = <?php echo ($flash && $flash['type'] === 'success') ? 'true' : 'false'; ?>;
    const customerCurrency = <?php echo json_encode(CURRENCY); ?>;

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
                    background: rgba(46, 41, 78, 0.55);
                }
                .order-confirm-dialog {
                    position: relative;
                    width: min(520px, 100%);
                    background: var(--card-bg, #F1E9DA);
                    border: 1px solid var(--border-color, #F1E9DA);
                    border-radius: 14px;
                    box-shadow: 0 20px 45px rgba(46, 41, 78, 0.25);
                    color: var(--text-primary, #2E294E);
                    overflow: hidden;
                }
                .order-confirm-header {
                    padding: 1rem 1.2rem 0.5rem;
                    font-weight: 700;
                    font-size: 1.05rem;
                }
                .order-confirm-subtitle {
                    padding: 0 1.2rem;
                    color: var(--text-muted, #541388);
                    font-size: 0.9rem;
                }
                .order-confirm-details {
                    margin: 0.9rem 1.2rem 0;
                    border: 1px solid var(--border-color, #F1E9DA);
                    border-radius: 10px;
                    overflow: hidden;
                }
                .order-confirm-row {
                    display: flex;
                    justify-content: space-between;
                    gap: 1rem;
                    padding: 0.7rem 0.85rem;
                    border-bottom: 1px solid var(--border-color, #F1E9DA);
                    font-size: 0.92rem;
                }
                .order-confirm-row:last-child { border-bottom: none; }
                .order-confirm-row span:first-child { color: var(--text-muted, #541388); }
                .order-confirm-row span:last-child { font-weight: 600; text-align: right; word-break: break-word; }
                .order-confirm-actions {
                    display: flex;
                    gap: 0.75rem;
                    justify-content: flex-end;
                    padding: 1rem 1.2rem 1.1rem;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-backdrop {
                    background: rgba(46, 41, 78, 0.72);
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-dialog {
                    background: #2E294E;
                    border-color: #2E294E;
                    color: #F1E9DA;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-header,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:last-child {
                    color: #F1E9DA;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-subtitle,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:first-child {
                    color: #F1E9DA;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-details,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row {
                    border-color: #2E294E;
                }
                html[data-theme="dark"] .order-confirm-modal .btn.btn-secondary,
                html[data-theme="dark"] .order-confirm-modal .btn.btn-outline {
                    background: #2E294E;
                    border-color: #2E294E;
                    color: #F1E9DA;
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
            state.title.textContent = config.title || 'Confirm Order';
            state.subtitle.textContent = config.subtitle || 'Review details before submitting.';
            state.okBtn.textContent = config.confirmText || 'Confirm';
            state.cancelBtn.textContent = config.cancelText || 'Cancel';
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
            setTimeout(function() { state.okBtn.focus(); }, 0);
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
    function showNetwork(event, network) {
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
        const trigger = (event && event.currentTarget)
            ? event.currentTarget
            : document.querySelector('.network-tab[data-network="' + network + '"]');
        if (trigger) {
            trigger.classList.add('active');
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
        let valid = true;
        let message = '';

        if (networkLabel === 'mtn') {
            valid = isCustomerMtnLocalPhone(localPhone);
            message = 'Please enter a valid MTN number (024/025/053/054/055/059).';
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
        // Hide any other open forms
        document.querySelectorAll('.recipient-form').forEach(f => f.style.display = 'none');
        
        const form = document.getElementById('recipient-form-' + packageId);
        if (!form) return;
        form.style.display = 'block';
        // Focus input
        const input = form.querySelector('input[name="beneficiary_number"]');
        if (input) input.focus();
        // Scroll into view for better UX
        const card = document.getElementById('package-card-' + packageId);
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideRecipientForm(packageId) {
        const form = document.getElementById('recipient-form-' + packageId);
        if (form) form.style.display = 'none';
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
                const packageName = String(form.dataset.packageName || '').trim();
                const packageSize = String(form.dataset.packageSize || '').trim();
                const packageNetwork = String(form.dataset.network || '').trim();
                const packagePrice = parseFloat(form.dataset.packagePrice || 0) || 0;
                const packageLabel = [packageName, packageSize].filter(Boolean).join(' - ');
                const paymentMethodSelect = form.querySelector('select[name="payment_method"]');
                const paymentMethod = paymentMethodSelect ? paymentMethodSelect.options[paymentMethodSelect.selectedIndex].text : 'Wallet';
                event.preventDefault();
                openOrderConfirmModal({
                    title: 'Confirm Data Purchase',
                    subtitle: 'Review the order details before submitting.',
                    confirmText: 'Submit Order',
                    details: [
                        { label: 'Network', value: packageNetwork || 'N/A' },
                        { label: 'Package', value: packageLabel || 'Selected package' },
                        { label: 'Recipient', value: recipientNumber },
                        { label: 'Payment Method', value: paymentMethod },
                        { label: 'Amount', value: customerCurrency + packagePrice.toFixed(2) }
                    ]
                }).then(function(confirmed) {
                    if (!confirmed) return;
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                    }
                    form.submit();
                });
            });
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
            alert(data.message || 'Bulk orders completed.');
            if (data.success) {
                document.getElementById('customerBulkTextInput').value = '';
                document.getElementById('customerBulkTextPreviewBody').innerHTML = '';
                document.getElementById('customerBulkTextPreview').style.display = 'none';
                document.getElementById('customerBulkTextSummary').textContent = '';
            }
        })
        .catch(function() {
            alert('Failed to process bulk orders. Please try again.');
        })
        .finally(function() {
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Process Orders';
        });
    }
</script>

<style>
.buy-data-page,
.buy-data-page .dashboard-wrapper,
.buy-data-page .main-content,
.buy-data-page .dashboard-content {
    max-width: 100%;
    overflow-x: hidden;
}

.wallet-balance {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--success-color);
    color: #F1E9DA;
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
    .buy-data-page .dashboard-header {
        padding: 0.5rem 0.75rem;
        flex-wrap: nowrap !important;
        justify-content: space-between !important;
        align-items: center !important;
        height: 60px;
    }

    .buy-data-page .header-left {
        flex: 0 0 auto !important;
    }

    .buy-data-page .header-actions {
        width: auto !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        gap: 0.4rem !important;
        margin-left: auto !important;
    }

    .buy-data-page .wallet-balance {
        order: 1 !important;
        width: auto !important;
        padding: 0.4rem 0.6rem !important;
        font-size: 0.8rem !important;
        margin-right: 0 !important;
        white-space: nowrap !important;
        border-radius: 20px !important;
        background: var(--success-color, #2ec4b6) !important;
    }

    .buy-data-page .topup-btn {
        order: 2 !important;
        width: 34px !important;
        height: 34px !important;
        min-height: 34px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .buy-data-page .topup-text {
        display: none !important;
    }

    .buy-data-page .theme-toggle {
        order: 3 !important;
        width: 34px !important;
        height: 34px !important;
        min-width: 34px !important;
        min-height: 34px !important;
        padding: 0 !important;
        border-radius: 50% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .buy-data-page .user-dropdown {
        order: 4 !important;
        display: flex !important;
        align-items: center !important;
    }

    .buy-data-page .user-dropdown-toggle {
        padding: 0 !important;
        border-radius: 50% !important;
        min-width: 34px !important;
        min-height: 34px !important;
        border: none !important;
        background: transparent !important;
    }
    
    .buy-data-page .user-avatar {
        width: 34px !important;
        height: 34px !important;
        margin: 0 !important;
    }

    .buy-data-page .bulk-order-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .buy-data-page .bulk-order-actions .btn {
        width: 100%;
    }

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
        box-shadow: 0 2px 8px rgba(46, 41, 78, 0.1);
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

    .buy-data-page .wallet-balance {
        font-size: 0.8rem;
        padding: 0.45rem 0.65rem;
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
    box-shadow: 0 4px 12px rgba(46, 41, 78, 0.1);
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
    color: #F1E9DA;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-top: 0.25rem;
}

.package-footer {
    padding: 1rem;
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

