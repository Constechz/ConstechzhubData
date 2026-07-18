<?php
require_once '../config/config.php';
require_once '../includes/api_providers.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require agent role
requireRole('agent');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);

$enabled_gateways = getEnabledPaymentGateways();
$enabled_gateways = array_values(array_filter($enabled_gateways, function ($name) {
    return in_array($name, ['paystack', 'moolre'], true);
}));

// Get MTN packages with agent pricing (allow multiple packages but prevent duplicates)
$mtn_packages = [];
$stmt = $db->prepare("
    SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.package_type, dp.agent_commission, dp.description,
           COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
    FROM data_packages dp 
    LEFT JOIN networks n ON n.id = dp.network_id 
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    WHERE n.name = 'MTN' AND dp.status = 'active' 
    GROUP BY dp.id, dp.name, dp.data_size, dp.validity_days, dp.package_type, dp.agent_commission, dp.description,
             COALESCE(pp_agent.price, pp_customer.price, dp.price)
    ORDER BY COALESCE(pp_agent.price, pp_customer.price, dp.price) ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $mtn_packages[] = $row;
}

if (!function_exists('normalizeMtnLocalPhone')) {
    function normalizeMtnLocalPhone($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strpos($digits, '233') === 0) {
            return '0' . substr($digits, 3);
        }
        return $digits;
    }
}

if (!function_exists('isMtnLocalPhone')) {
    function isMtnLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) {
            return false;
        }
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['024', '025', '053', '054', '055', '059'], true);
    }
}

$error = '';
$success = '';

// Handle form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'purchase') {
    $phone_number = sanitize($_POST['phone_number'] ?? '');
    $package_id = intval($_POST['package_id'] ?? 0);
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    
    // Debug: Log payment method received
    error_log('MTN Purchase Debug - Payment method received: ' . $payment_method);
    error_log('MTN Purchase Debug - POST data: ' . print_r($_POST, true));
    
    if (empty($phone_number)) {
        $error = 'Please enter beneficiary phone number';
    } elseif (!validatePhone($phone_number)) {
        $error = 'Please enter a valid phone number';
    } elseif (!isMtnLocalPhone(normalizeMtnLocalPhone($phone_number))) {
        $error = 'Use MTN numbers only (024/025/053/054/055/059) and 10 digits.';
    } elseif (empty($package_id)) {
        $error = 'Please select a data package';
    } else {
        // Get package details with pricing
        $stmt = $db->prepare("
            SELECT dp.*, 
                   COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
            FROM data_packages dp
            LEFT JOIN networks n ON n.id = dp.network_id
            LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
            LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
            WHERE dp.id = ? AND n.name = 'MTN' AND dp.status = 'active'
        ");
        $stmt->bind_param("i", $package_id);
        $stmt->execute();
        $package_result = $stmt->get_result();
        
        if ($package = $package_result->fetch_assoc()) {
            $payment_method = strtolower(trim((string) ($_POST['payment_method'] ?? 'wallet')));
            if (!in_array($payment_method, ['wallet', 'paystack', 'moolre'], true)) {
                $payment_method = 'wallet';
            }

            if ($payment_method === 'wallet') {
                // Check wallet balance
                $effective_price = $package['effective_price'];
                if ($wallet_balance < $effective_price) {
                    $error = 'Insufficient wallet balance. Please top up your wallet.';
                } else {
                    $network_id = 1; // MTN network ID
                    $endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false ||
                                     strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';
                    $availability = checkNetworkProviderAvailability($network_id, $endpoint_type);
                    if (!$availability['available']) {
                        $error = $availability['message'];
                        $wallet_balance = getWalletBalance($current_user['id']);
                    } else {
                    // Process purchase
                    $order_reference = generateReference('MTN');
                    $formatted_phone = formatPhone($phone_number);
                    $duplicate_order = findRecentDuplicateBundleOrder(
                        (int) $current_user['id'],
                        (int) $package_id,
                        $formatted_phone,
                        (float) $effective_price,
                        180
                    );

                    if ($duplicate_order) {
                        $dup_ref = $duplicate_order['order_reference'] ?? ('#' . (int) ($duplicate_order['id'] ?? 0));
                        $success = 'Similar order already received recently (Ref: ' . $dup_ref . '). Please wait before retrying.';
                        $wallet_balance = getWalletBalance($current_user['id']);
                    } else {
                    $bundle_orders_auto_increment = true;
                    $transactions_auto_increment = true;
                    $commissions_auto_increment = true;
                    if (function_exists('dbh_ensure_auto_increment')) {
                        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
                        $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
                        $commissions_auto_increment = dbh_ensure_auto_increment('commissions');
                    }
                    
                    $db->getConnection()->begin_transaction();
                    
                    try {
                        // Create bundle order
                        if ($bundle_orders_auto_increment) {
                            $stmt = $db->prepare("
                                INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status) 
                                VALUES (?, ?, ?, ?, ?, 'processing')
                            ");
                            $stmt->bind_param("iisds", $current_user['id'], $package_id, $formatted_phone, $effective_price, $order_reference);
                            $stmt->execute();
                            $order_id = $db->lastInsertId();
                        } else {
                            $manual_order_id = dbh_generate_next_id('bundle_orders');
                            $stmt = $db->prepare("
                                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status) 
                                VALUES (?, ?, ?, ?, ?, ?, 'processing')
                            ");
                            $stmt->bind_param("iiisds", $manual_order_id, $current_user['id'], $package_id, $formatted_phone, $effective_price, $order_reference);
                            $stmt->execute();
                            $order_id = $manual_order_id;
                        }
                        
                        // Create transaction
                        $transaction_ref = generateReference('TXN');
                        $description = "MTN " . $package['data_size'] . " bundle purchase for " . $formatted_phone;
                        if ($transactions_auto_increment) {
                            $stmt = $db->prepare("
                                INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) 
                                VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?)
                            ");
                            $stmt->bind_param("idss", $current_user['id'], $effective_price, $transaction_ref, $description);
                            $stmt->execute();
                            $transaction_id = $db->lastInsertId();
                        } else {
                            $manual_transaction_id = dbh_generate_next_id('transactions');
                            $stmt = $db->prepare("
                                INSERT INTO transactions (id, user_id, transaction_type, amount, status, reference, payment_method, description) 
                                VALUES (?, ?, 'purchase', ?, 'success', ?, 'wallet', ?)
                            ");
                            $stmt->bind_param("iidss", $manual_transaction_id, $current_user['id'], $effective_price, $transaction_ref, $description);
                            $stmt->execute();
                            $transaction_id = $manual_transaction_id;
                        }
                        
                        // Update order with transaction ID and set to processing
                        $stmt = $db->prepare("UPDATE bundle_orders SET transaction_id = ?, status = 'processing' WHERE id = ?");
                        $stmt->bind_param("ii", $transaction_id, $order_id);
                        $stmt->execute();
                        
                        // Deduct from wallet
                        updateWalletBalance($current_user['id'], $effective_price, 'debit', $transaction_ref, $description);
                        
                        // Call API provider to deliver the bundle
                        require_once '../includes/api_providers.php';
                        
                        // Convert data size to GB for API call
                        require_once '../includes/volume_converter.php';
                        $volume_gb = extractVolumeGB($package['data_size']);
                        $network_id = 1; // MTN network ID
                        
                        // Determine endpoint type
                        $endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false || 
                                         strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';
                        
                        $api_result = processBundlePurchase($order_id, $network_id, $formatted_phone, $volume_gb, $endpoint_type);
                        
                        if ($api_result['success']) {
                            // Update order status to delivered
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'delivered', api_response = ?, provider_reference = ?, delivered_at = NOW() WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $provider_ref = $api_result['reference'] ?? '';
                            $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                            $stmt->execute();

                            if (function_exists('applyMtnStatusPolicy')) {
                                applyMtnStatusPolicy($order_id, 'delivered');
                            }
                        } else {
                            // Update order status to failed
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();
                            
                            // Refund wallet
                            updateWalletBalance($current_user['id'], $effective_price, 'credit', $transaction_ref . '_REFUND', 'Refund: ' . $api_result['error']);
                            throw new Exception('API delivery failed: ' . $api_result['error']);
                        }
                        
                        // Calculate and record commission
                        $commission_amount = ($effective_price * $package['agent_commission']) / 100;
                        if ($commission_amount > 0) {
                            if ($commissions_auto_increment) {
                                $stmt = $db->prepare("
                                    INSERT INTO commissions (agent_id, order_id, amount, status) 
                                    VALUES (?, ?, ?, 'pending')
                                ");
                                $stmt->bind_param("iid", $current_user['id'], $order_id, $commission_amount);
                                $stmt->execute();
                            } else {
                                $manual_commission_id = dbh_generate_next_id('commissions');
                                $stmt = $db->prepare("
                                    INSERT INTO commissions (id, agent_id, order_id, amount, status) 
                                    VALUES (?, ?, ?, ?, 'pending')
                                ");
                                $stmt->bind_param("iiid", $manual_commission_id, $current_user['id'], $order_id, $commission_amount);
                                $stmt->execute();
                            }
                        }
                        
                        $db->getConnection()->commit();

                        sendAdminDataOrderNotification([
                            'order_reference' => $order_reference,
                            'order_id' => $order_id,
                            'user_id' => (int) $current_user['id'],
                            'customer_name' => $current_user['full_name'] ?? '',
                            'customer_email' => $current_user['email'] ?? '',
                            'beneficiary_number' => $formatted_phone,
                            'network_name' => 'MTN',
                            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                            'amount' => $effective_price,
                            'payment_method' => 'wallet',
                            'status' => 'delivered',
                            'agent_id' => (int) $current_user['id'],
                            'source' => 'agent_dashboard_mtn'
                        ]);
                        
                        // Log activity
                        logActivity($current_user['id'], 'bundle_purchase', "Purchased MTN {$package['data_size']} bundle for {$formatted_phone}");
                        
                        // Display phone in user-friendly local format
                        $display_phone = (strlen($formatted_phone) == 12 && substr($formatted_phone, 0, 3) == '233') 
                            ? '0' . substr($formatted_phone, 3) 
                            : $formatted_phone;
                        $success = "Bundle purchase successful! {$package['data_size']} has been sent to {$display_phone}";

                        // Clear form fields after a successful order for a fresh entry
                        $_POST = [];
                        
                        // Update wallet balance for display
                        $wallet_balance = getWalletBalance($current_user['id']);
                        
                    } catch (Exception $e) {
                        $db->getConnection()->rollback();
                        $error = stripos($e->getMessage(), 'Network is busy') !== false
                            ? $e->getMessage()
                            : 'Purchase failed: ' . $e->getMessage();
                        error_log('MTN Bundle Purchase Error: ' . $e->getMessage());
                    }
                    }
                    }
                }
            } else {
                // Direct payment checkout
                if (!isPaymentGatewayEnabled($payment_method)) {
                    $error = 'Selected payment gateway is currently unavailable.';
                } else {
                    $formatted_phone = formatPhone($phone_number);
                    if (function_exists('findRecentGuestBundleTransaction')) {
                        $recent_txn = findRecentGuestBundleTransaction($current_user['id'], $package_id, $formatted_phone, $package['effective_price'], 180);
                        if ($recent_txn) {
                            $tx_status = strtolower(trim((string) ($recent_txn['status'] ?? '')));
                            if ($tx_status === 'pending' || $tx_status === 'processing') {
                                $error = 'A similar payment is already in progress. Please complete it before starting another one.';
                            }
                        }
                    }
                    
                    if (empty($error)) {
                        $init_error = '';
                        $auth_url = initializeGatewayBundlePurchase(
                            $current_user['id'],
                            $current_user['email'],
                            $package_id,
                            $formatted_phone,
                            $package['effective_price'],
                            $package['effective_price'],
                            0,
                            '',
                            $payment_method,
                            $init_error
                        );
                        
                        if ($auth_url) {
                            header('Location: ' . $auth_url);
                            exit();
                        } else {
                            $error = $init_error ?: 'Failed to initialize gateway payment.';
                        }
                    }
                }
            }
        } else {
            $error = 'Invalid package selected';
        }
    }
}

// Get flash message
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MTN Business - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script>
        // Initialize theme immediately before body loads to prevent flicker
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
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
                    <div class="nav-section-title">Services</div>
                    <div class="nav-item">
                        <a href="at-business.php" class="nav-link">
                            <i class="fas fa-mobile-alt"></i>
                            AT Business
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="mtn-business.php" class="nav-link active">
                        <i class="fas fa-mobile-alt"></i>
                        MTN Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="afa-registration.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        AFA Registration
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php" class="nav-link">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="telecel-business.php" class="nav-link">
                        <i class="fas fa-signal"></i>
                        Telecel Business
                    </a>
                </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Transaction</div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link">
                        <i class="fas fa-history"></i>
                        Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
            </ul>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
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
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="breadcrumb-item">MTN Packages</div>
                        <div class="breadcrumb-item active">MTN Bundles</div>
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
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
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

<?php echo renderNotificationSlides('agents'); ?>

            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Page Header -->
                <div style="text-align: center; margin-bottom: 3rem;">
                    <h1 style="font-size: 2.5rem; margin-bottom: 1rem; color: var(--text-primary);">MTNUP2U Bundles</h1>
                    <p style="color: var(--text-secondary); margin-bottom: 2rem;">
                        Share with your loved ones. Huge data volumes for downloads and live streaming. Advanced bundles for your business.
                    </p>
                    <button class="btn btn-outline" style="padding: 0.75rem 2rem;">
                        Read More
                    </button>
                </div>
                
                <!-- Purchase Form -->
                <div style="max-width: 600px; margin: 0 auto;">
                    <div class="widget">
                        <div class="widget-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="widget-title">BUY MTN BUNDLES</h3>
                            <button class="btn btn-danger" style="padding: 0.5rem 1rem; font-size: 0.875rem;" onclick="openBulkUploadModal(); return false;">
                                Upload Excel
                            </button>
                        </div>
                        <div class="widget-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <?php echo htmlspecialchars($error); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <?php echo htmlspecialchars($success); ?>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="" id="purchaseForm">
                                <input type="hidden" name="action" value="purchase">
                                
                                <div class="form-group">
                                    <label for="phone_number" class="form-label">
                                        PHONE NUMBER <span style="color: var(--accent-red);">*</span>
                                    </label>
                                <input 
                                    type="tel" 
                                    class="form-control" 
                                    id="phone_number" 
                                    name="phone_number" 
                                    placeholder="Beneficiary Phone Number"
                                    value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>"
                                    required
                                >
                                <small class="form-help">Use MTN numbers only (024/025/053/054/055/059) and 10 digits.</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="package_id" class="form-label">
                                        SELECT MENU <span style="color: var(--accent-red);">*</span>
                                    </label>
                                    <select class="form-control form-select" id="package_id" name="package_id" required>
                                        <option value="">Select package</option>
                                        <?php foreach ($mtn_packages as $package): ?>
                                            <option 
                                                value="<?php echo $package['id']; ?>" 
                                                data-price="<?php echo $package['effective_price']; ?>"
                                                <?php echo (isset($_POST['package_id']) && $_POST['package_id'] == $package['id']) ? 'selected' : ''; ?>
                                            >
                                                MTN <?php echo $package['data_size']; ?> - <?php echo formatCurrency($package['effective_price']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="payment_method" class="form-label">
                                        PAYMENT METHOD <span style="color: var(--accent-red);">*</span>
                                    </label>
                                    <select class="form-control form-select" id="payment_method" name="payment_method" required>
                                        <option value="wallet">Wallet Balance (<?php echo formatCurrency($wallet_balance); ?>)</option>
                                        <?php foreach ($enabled_gateways as $gateway): ?>
                                            <option value="<?php echo htmlspecialchars($gateway); ?>"><?php echo ucfirst(htmlspecialchars($gateway)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <button 
                                        type="submit" 
                                        class="btn btn-primary" 
                                        style="width: 100%;"
                                        id="submitBtn"
                                    >
                                        Process Order
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Upload Modal -->
                <div id="bulkUploadModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(46, 41, 78, 0.5);">
                    <div class="modal-content" style="max-width: 600px; margin: 5% auto; background: var(--card-bg); border-radius: 12px; padding: 2rem; position: relative;">
                        <span class="close" onclick="closeBulkUploadModal()" style="position: absolute; top: 1rem; right: 1.5rem; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</span>
                        
                        <div style="text-align: center; margin-bottom: 2rem;">
                            <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">MTN Bulk Uploads</h2>
                            <p style="color: var(--text-secondary);">Share with your loved ones. Huge data volumes for downloads and live streaming. Advanced bundles for your business.</p>
                            <button class="btn btn-outline" style="padding: 0.5rem 1.5rem; margin-top: 1rem;">Read More</button>
                        </div>
                        
                        <div class="widget">
                            <div class="widget-header">
                                <h3 class="widget-title">UPLOAD MTN BULK BUNDLES</h3>
                            </div>
                            <div class="widget-body">
                                <form id="bulkUploadForm" enctype="multipart/form-data" onsubmit="handleBulkUpload(event)">
                                    <div class="form-group">
                                        <label class="form-label">UPLOAD YOUR FILE <span style="color: var(--accent-red);">*</span></label>
                                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                            <input type="file" id="bulkFile" name="bulk_file" accept=".xlsx,.xls,.csv" style="display: none;">
                                        <input type="hidden" name="network" value="mtn">
                                            <button type="button" class="btn btn-outline" onclick="document.getElementById('bulkFile').click()" style="flex: 1;">
                                                <i class="fas fa-upload"></i> Choose File
                                            </button>
                                            <span id="fileName" style="color: var(--text-muted); font-size: 0.875rem;">No file chosen</span>
                                            <a href="download_template.php?network=mtn" class="btn btn-link" style="color: var(--accent-red); text-decoration: none; font-size: 0.875rem;">View Template</a>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group" style="display: flex; gap: 1rem;">
                                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                                            <i class="fas fa-upload"></i> Upload Excel File
                                        </button>
                                        <button type="button" class="btn btn-success" style="flex: 1;" onclick="topupWallet()">
                                            <i class="fas fa-wallet"></i> Topup Wallet
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="widget" style="margin-top: 1.5rem;">
                            <div class="widget-header">
                                <h3 class="widget-title">MTN BULK TEXT ORDERS</h3>
                            </div>
                            <div class="widget-body">
                                <div class="form-group">
                                    <label class="form-label">Paste numbers and bundles</label>
                                    <textarea id="bulkTextInput" class="form-control" rows="6" placeholder="0240000000 1&#10;0540000000 2"></textarea>
                                    <small style="color: var(--text-muted);">One order per line. Format: phone and GB (space-separated). Example: 0240000000 1.</small>
                                </div>
                                <div class="form-group" style="display: flex; gap: 0.75rem; align-items: center;">
                                    <button type="button" class="btn btn-outline" onclick="previewBulkTextOrders()" style="flex: 1;">
                                        <i class="fas fa-eye"></i> Preview Orders
                                    </button>
                                    <button type="button" class="btn btn-primary" id="processBulkTextBtn" onclick="processBulkTextOrders()" style="flex: 1;" disabled>
                                        <i class="fas fa-paper-plane"></i> Process Orders
                                    </button>
                                </div>
                                <div id="bulkTextSummary" style="margin-top: 0.75rem; color: var(--text-muted);"></div>
                                <div id="bulkTextErrors" style="margin-top: 0.5rem; color: var(--accent-red);"></div>
                                <div id="bulkTextPreview" style="margin-top: 1rem; display: none;">
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Phone</th>
                                                    <th>Bundle</th>
                                                    <th>Price</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bulkTextPreviewBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                                <div style="margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                                    <strong>Help:</strong> One order per line. Use <code>0240000000 1</code>. MTN numbers only.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        const agentCurrency = <?php echo json_encode(CURRENCY); ?>;

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
            
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        
        // All DOM-dependent functionality in one consolidated listener
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    console.log('Hamburger menu clicked'); // Debug log
                    sidebar.classList.toggle('show');
                });
            } else {
                console.log('Mobile toggle or sidebar not found:', { mobileToggle, sidebar }); // Debug log
            }
            
            // Form validation
            const purchaseForm = document.getElementById('purchaseForm');
            if (purchaseForm) {
                purchaseForm.addEventListener('submit', function(e) {
                    const phoneNumber = document.getElementById('phone_number').value;
                    const packageId = document.getElementById('package_id').value;
                    const walletBalance = <?php echo $wallet_balance; ?>;
                    
                    if (!phoneNumber || !packageId) {
                        e.preventDefault();
                        alert('Please fill in all required fields');
                        return;
                    }

                    const localPhone = normalizeMtnLocalPhone(phoneNumber);
                    if (!isMtnLocalPhone(localPhone)) {
                        e.preventDefault();
                        alert('Use MTN numbers only (024/025/053/054/055/059) and 10 digits.');
                        return;
                    }
                    
                    // Get selected package price
                    const selectedOption = document.querySelector('#package_id option:checked');
                    const packagePrice = parseFloat(selectedOption ? selectedOption.dataset.price : 0) || 0;
                    const paymentMethodSelect = document.getElementById('payment_method');
                    const paymentMethod = paymentMethodSelect ? paymentMethodSelect.value : 'wallet';
                    
                    // Debug logging
                    console.log('Form validation:', {
                        phoneNumber: phoneNumber,
                        packageId: packageId,
                        selectedOption: selectedOption,
                        packagePrice: packagePrice,
                        walletBalance: walletBalance,
                        paymentMethod: paymentMethod
                    });
                    
                    if (paymentMethod === 'wallet' && packagePrice > 0 && packagePrice > walletBalance) {
                        e.preventDefault();
                        alert('Insufficient wallet balance. Please top up your wallet.');
                        return;
                    }

                    const packageLabel = selectedOption ? selectedOption.textContent.trim() : 'Selected package';
                    e.preventDefault();
                    
                    // Ensure payment_method is set correctly
                    if (!purchaseForm.querySelector('[name="payment_method"]')) {
                        // Create hidden payment method input if button-based submission fails
                        const paymentMethodInput = document.createElement('input');
                        paymentMethodInput.type = 'hidden';
                        paymentMethodInput.name = 'payment_method';
                        paymentMethodInput.value = 'wallet';
                        purchaseForm.appendChild(paymentMethodInput);
                    }
                    
                    openOrderConfirmModal({
                        title: 'Confirm MTN Purchase',
                        subtitle: 'Review the order details before submitting.',
                        confirmText: 'Submit Order',
                        details: [
                            { label: 'Network', value: 'MTN' },
                            { label: 'Package', value: packageLabel },
                            { label: 'Recipient', value: localPhone },
                            { label: 'Payment Method', value: paymentMethod.charAt(0).toUpperCase() + paymentMethod.slice(1) },
                            { label: 'Amount', value: agentCurrency + packagePrice.toFixed(2) }
                        ]
                    }).then(function(confirmed) {
                        if (!confirmed) return;
                        const btn = document.getElementById('payWithWalletBtn');
                        if (btn) {
                            btn.disabled = true;
                            btn.innerHTML = '<span class="spinner"></span> Processing...';
                        }
                        purchaseForm.submit();
                    });
                });
            }
            
            // Phone number formatting
            const phoneInput = document.getElementById('phone_number');
            if (phoneInput) {
                phoneInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
                    
                    // Keep local Ghana format (starting with 0)
                    if (value.length > 0) {
                        if (value.startsWith('233')) {
                            // Convert international to local format for display
                            e.target.value = '0' + value.substring(3);
                        } else if (!value.startsWith('0') && value.length > 0) {
                            // Add leading 0 if missing
                            e.target.value = '0' + value;
                        }
                    }
                });
            }

            function normalizeMtnLocalPhone(value) {
                const digits = String(value || '').replace(/\D/g, '');
                if (digits.startsWith('233')) {
                    return '0' + digits.slice(3);
                }
                return digits;
            }

            function isMtnLocalPhone(localPhone) {
                if (!/^\d{10}$/.test(localPhone)) return false;
                const prefix = localPhone.slice(0, 3);
                return ['024', '025', '053', '054', '055', '059'].indexOf(prefix) !== -1;
            }
            
            // File selection display
            const fileInput = document.getElementById('bulkFile');
            const fileName = document.getElementById('fileName');
            if (fileInput && fileName) {
                fileInput.addEventListener('change', function(e) {
                    if (e.target.files.length > 0) {
                        fileName.textContent = e.target.files[0].name;
                    } else {
                        fileName.textContent = 'No file chosen';
                    }
                });
            }
            
            // Bulk upload form submission
            const bulkUploadForm = document.getElementById('bulkUploadForm');
            if (bulkUploadForm) {
                bulkUploadForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const fileInput = document.getElementById('bulkFile');
                    
                    if (!fileInput.files[0]) {
                        alert('Please select an Excel file to upload.');
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('bulk_file', fileInput.files[0]);
                    formData.append('network', 'mtn');
                    formData.append('action', 'bulk_upload');
                    
                    // Show loading state
                    const submitBtn = e.target.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
                    
                    fetch('process_bulk_upload.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Bulk upload processed successfully! ' + data.message);
                            closeBulkUploadModal();
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while processing the upload.');
                    })
                    .finally(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }
            
            // Modal click outside to close
            const bulkUploadModal = document.getElementById('bulkUploadModal');
            if (bulkUploadModal) {
                bulkUploadModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeBulkUploadModal();
                    }
                });
            }
            
            // Initialize theme
            initTheme();
            
            // Ensure icon is updated after DOM is ready
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            updateThemeIcon(theme);
        });

        const bulkMtnPackages = <?php echo json_encode($mtn_packages); ?>;
        const bulkMtnPackageMap = {};
        const bulkMtnSizeMap = {};
        bulkMtnPackages.forEach(function(pkg) {
            const key = normalizeBulkVolumeKey(pkg.data_size || '');
            if (key) {
                bulkMtnPackageMap[key] = pkg;
            }
            const sizeKey = normalizeBulkNumericKey(parsePackageSizeGb(pkg.data_size || ''));
            if (sizeKey) {
                if (!bulkMtnSizeMap[sizeKey]) {
                    bulkMtnSizeMap[sizeKey] = pkg;
                } else {
                    const currentPrice = parseFloat(bulkMtnSizeMap[sizeKey].effective_price || 0);
                    const candidatePrice = parseFloat(pkg.effective_price || 0);
                    if (!isNaN(candidatePrice) && (isNaN(currentPrice) || candidatePrice < currentPrice)) {
                        bulkMtnSizeMap[sizeKey] = pkg;
                    }
                }
            }
        });

        const bulkTextState = {
            orders: [],
            hasErrors: true
        };

        function normalizeBulkVolumeKey(value) {
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

        function parsePackageSizeGb(value) {
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

        function normalizeBulkNumericKey(value) {
            const parsed = parseFloat(value);
            if (isNaN(parsed) || parsed <= 0) return '';
            return parsed.toFixed(2).replace(/\.?0+$/, '');
        }

        function normalizeBulkLocalPhone(value) {
            const digits = String(value || '').replace(/\D/g, '');
            if (digits.startsWith('233')) {
                return '0' + digits.slice(3);
            }
            return digits;
        }

        function isMtnLocalPhone(localPhone) {
            if (!/^\d{10}$/.test(localPhone)) return false;
            const prefix = localPhone.slice(0, 3);
            return ['024', '025', '053', '054', '055', '059'].indexOf(prefix) !== -1;
        }

        function previewBulkTextOrders() {
            const input = document.getElementById('bulkTextInput');
            const preview = document.getElementById('bulkTextPreview');
            const previewBody = document.getElementById('bulkTextPreviewBody');
            const summary = document.getElementById('bulkTextSummary');
            const errorsBox = document.getElementById('bulkTextErrors');
            const processBtn = document.getElementById('processBulkTextBtn');

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

                const localPhone = normalizeBulkLocalPhone(phone);
                if (!isMtnLocalPhone(localPhone)) {
                    errors.push('Row ' + (index + 1) + ': Invalid MTN number');
                    return;
                }
                if (!volume) {
                    errors.push('Row ' + (index + 1) + ': Missing bundle size');
                    return;
                }

                const numericCandidate = volume.replace(/\s+/g, '');
                const isNumericInput = /^\d+(\.\d+)?$/.test(numericCandidate);
                const volumeKey = normalizeBulkVolumeKey(volume);
                let pkg = null;
                if (isNumericInput) {
                    const numericKey = normalizeBulkNumericKey(numericCandidate);
                    if (numericKey && bulkMtnSizeMap[numericKey]) {
                        pkg = bulkMtnSizeMap[numericKey];
                    }
                } else {
                    pkg = bulkMtnPackageMap[volumeKey] || null;
                    if (!pkg) {
                        Object.keys(bulkMtnPackageMap).some(function(key) {
                            if (volumeKey.indexOf(key) !== -1 || key.indexOf(volumeKey) !== -1) {
                                pkg = bulkMtnPackageMap[key];
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

                const price = parseFloat(pkg.effective_price || 0);
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

            bulkTextState.orders = orders;
            bulkTextState.hasErrors = errors.length > 0 || orders.length === 0;

            preview.style.display = orders.length ? 'block' : 'none';
            summary.textContent = orders.length
                ? (orders.length + ' valid orders. Total: ' + totalCost.toFixed(2))
                : 'No valid orders to preview.';
            errorsBox.textContent = errors.length ? errors.slice(0, 3).join(' | ') : '';
            processBtn.disabled = bulkTextState.hasErrors;
        }

        async function processBulkTextOrders() {
            const processBtn = document.getElementById('processBulkTextBtn');
            if (!processBtn || bulkTextState.hasErrors || bulkTextState.orders.length === 0) return;

            const totalCost = bulkTextState.orders.reduce(function(sum, order) {
                return sum + (parseFloat(order.price) || 0);
            }, 0);
            const previewLines = bulkTextState.orders.slice(0, 5).map(function(order) {
                return order.phone + ' - ' + order.volume;
            });
            const remainingCount = bulkTextState.orders.length - previewLines.length;
            const confirmed = await openOrderConfirmModal({
                title: 'Confirm MTN Bulk Orders',
                subtitle: 'Please confirm these bulk orders before processing.',
                confirmText: 'Process Orders',
                details: [
                    { label: 'Total Orders', value: String(bulkTextState.orders.length) },
                    { label: 'Total Amount', value: agentCurrency + totalCost.toFixed(2) },
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
                    orders: bulkTextState.orders.map(function(order) {
                        return { phone: order.phone, volume: order.volume };
                    })
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                alert(data.message || 'Bulk orders completed.');
                if (data.success) {
                    document.getElementById('bulkTextInput').value = '';
                    document.getElementById('bulkTextPreviewBody').innerHTML = '';
                    document.getElementById('bulkTextPreview').style.display = 'none';
                    document.getElementById('bulkTextSummary').textContent = '';
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

        
        // Define modal functions in global scope immediately
        window.openBulkUploadModal = function() {
            console.log('Excel button clicked - opening modal');
            try {
                const modal = document.getElementById('bulkUploadModal');
                console.log('Modal element:', modal);
                if (modal) {
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                    console.log('Modal displayed successfully');
                } else {
                    console.error('bulkUploadModal element not found in DOM');
                }
            } catch (error) {
                console.error('Error opening modal:', error);
            }
        };
        
        window.closeBulkUploadModal = function() {
            console.log('Closing modal');
            try {
                const modal = document.getElementById('bulkUploadModal');
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                    console.log('Modal closed successfully');
                }
            } catch (error) {
                console.error('Error closing modal:', error);
            }
        };
        
        window.topupWallet = function() {
            window.location.href = 'wallet.php';
        };
        
        // Handle bulk upload form submission
        window.handleBulkUpload = function(event) {
            event.preventDefault();
            
            const fileInput = document.getElementById('bulkFile');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('Please select a file to upload');
                return;
            }
            
            const formData = new FormData();
            formData.append('bulk_file', file);
            formData.append('network', 'MTN');
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            fetch('process_bulk_upload.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeBulkUploadModal();
                    location.reload(); // Refresh to update wallet balance
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                alert('Upload failed. Please try again.');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        };
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

