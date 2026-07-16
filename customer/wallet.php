<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
$is_vip_portal = defined('VIP_PORTAL') && VIP_PORTAL;
$active_gateway = getActivePaymentGateway();
$enabled_gateways = getEnabledPaymentGateways();
$enabled_gateways = array_values(array_filter($enabled_gateways, function ($gateway) {
    return in_array($gateway, ['paystack', 'moolre'], true);
}));
if (empty($enabled_gateways)) {
    $enabled_gateways = ['paystack'];
}
if (!in_array($active_gateway, $enabled_gateways, true)) {
    $active_gateway = $enabled_gateways[0];
}

$gateway_labels = [
    'paystack' => 'Paystack',
    'moolre' => 'Moolre'
];
$gateway_init_endpoints = [
    'paystack' => '../api/paystack_init.php',
    'moolre' => '../api/moolre_init.php'
];
$gateway_label = $gateway_labels[$active_gateway] ?? ucfirst($active_gateway);
$gateway_init_endpoint = $gateway_init_endpoints[$active_gateway] ?? '../api/paystack_init.php';
$has_gateway_choice = count($enabled_gateways) > 1;

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
                // Clear any existing flash messages before redirect to prevent logout message from appearing
                unset($_SESSION['flash_message']);
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
                    // Clear any existing flash messages before redirect to prevent logout message from appearing
                    unset($_SESSION['flash_message']);
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
$agent_paystack_settings = null;
$is_paystack_active = in_array('paystack', $enabled_gateways, true);

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
        
        if ($is_paystack_active) {
            // Get agent's Paystack settings
            $stmt = $db->prepare("
                SELECT * FROM agent_paystack_settings 
                WHERE agent_id = ? AND is_active = 1
            ");
            $stmt->bind_param("i", $agent_store['agent_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $agent_paystack_settings = $row;
            }
        }
    }
}

// Effective limits for customer wallet top-up
$limits = getEffectiveTopupLimits($current_user['id'], 'customer');
$min_allowed = (float)$limits['min'];
$max_allowed = (float)$limits['max'];

// If agent has custom minimum, use the higher of the two
if ($is_paystack_active && $agent_paystack_settings && $agent_paystack_settings['min_topup_agent_customer'] !== null) {
    $agent_min = (float)$agent_paystack_settings['min_topup_agent_customer'];
    $min_allowed = max($min_allowed, $agent_min);
}

// Get wallet transactions
$wallet_transactions = [];
$stmt = $db->prepare("
    SELECT wt.*, w.user_id 
    FROM wallet_transactions wt 
    JOIN wallets w ON wt.wallet_id = w.id 
    WHERE w.user_id = ? 
    ORDER BY wt.created_at DESC 
    LIMIT 20
");
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $wallet_transactions[] = $row;
}

$pending_paystack_topups = [];
$stmt = $db->prepare("
    SELECT reference, amount, description, created_at
    FROM transactions
    WHERE user_id = ?
      AND transaction_type = 'topup'
      AND payment_method = 'paystack'
      AND status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pending_paystack_topups[] = $row;
}

$error = '';
$success = '';

// CSRF token for form (reserved for future POST flows)
$csrf_token = generateCSRF();

// Flash messages
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
    <title>Wallet - <?php echo SITE_NAME; ?></title>
    <?php if (in_array('moolre', $enabled_gateways, true)): ?>
        <link rel="preconnect" href="https://api.moolre.com">
        <link rel="dns-prefetch" href="https://api.moolre.com">
        <link rel="preconnect" href="https://pos.moolre.com">
        <link rel="dns-prefetch" href="https://pos.moolre.com">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
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
                        <a href="dashboard.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
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
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link active">
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
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                            <i class="fas fa-user"></i>
                            Profile
                        </a>
                        <div class="breadcrumb-item active">Wallet</div>
                    </nav>
                </div>
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    <div class="user-dropdown">
                        <a href="../logout.php" class="btn btn-outline">Logout</a>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <div class="page-title">
                    <h1>Wallet Management</h1>
                    <p class="page-subtitle">Top-up and view your recent wallet transactions</p>
                </div>

                <div class="stats-grid" style="margin-bottom: 2rem;">
                    <div class="stat-card" style="grid-column: span 2;">
                        <div class="stat-icon success">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo formatCurrency($wallet_balance); ?></h3>
                            <p>Current Wallet Balance</p>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <!-- Top-up Form -->
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Top-up Wallet</h3>
                        </div>
                        <div class="widget-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                            <?php endif; ?>

                            <?php if (!empty($pending_paystack_topups)): ?>
                                <div class="alert alert-warning" id="pendingPaystackAlert" style="margin-bottom: 1.5rem;">
                                    <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                                        <div>
                                            <strong>Verify Missing Payment</strong>
                                            <div style="margin-top: 0.35rem;">
                                                If Paystack debited you but your wallet was not credited, the system can recheck your pending Paystack top-up automatically.
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline verify-missing-payment-btn" id="verifyLatestPendingPaymentBtn" data-reference="<?php echo htmlspecialchars($pending_paystack_topups[0]['reference']); ?>">
                                            <i class="fas fa-sync-alt"></i> Verify Latest Payment
                                        </button>
                                    </div>
                                    <div class="form-group" style="margin-top: 1rem; margin-bottom: 0;">
                                        <label for="latestPendingPaystackReference" class="form-label">Latest Pending Paystack Reference</label>
                                        <input
                                            type="text"
                                            id="latestPendingPaystackReference"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($pending_paystack_topups[0]['reference']); ?>"
                                            readonly
                                        >
                                        <small class="text-muted">This field is filled automatically from pending Paystack top-ups created on this customer account.</small>
                                    </div>
                                    <div class="table-responsive" style="margin-top: 1rem;">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Reference</th>
                                                    <th>Amount</th>
                                                    <th>Started</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pending_paystack_topups as $pending_topup): ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars($pending_topup['reference']); ?></code></td>
                                                        <td><?php echo formatCurrency((float) $pending_topup['amount']); ?></td>
                                                        <td><small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($pending_topup['created_at'])); ?></small></td>
                                                        <td>
                                                            <button type="button" class="btn btn-outline verify-missing-payment-btn" data-reference="<?php echo htmlspecialchars($pending_topup['reference']); ?>">
                                                                Verify Missing Payment
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div id="verifyMissingPaymentStatus" class="text-muted" style="margin-top: 0.75rem;">
                                        Pending references shown here were initiated from this customer account only.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Payment Method Selection -->
                            <div class="payment-method-selection" id="paymentMethodSelection">
                                <h4>Choose Payment Method</h4>
                                <div class="payment-methods">
                                    <div class="payment-method-card" id="gatewayMethod">
                                        <div class="method-icon">
                                            <i class="fas fa-credit-card"></i>
                                        </div>
                                        <div class="method-details">
                                            <h5>Online Payment</h5>
                                            <p>Pay instantly with card, bank transfer, or mobile money</p>
                                        </div>
                                        <div class="method-status">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                    <div class="payment-method-card" id="topupRequestMethod">
                                        <div class="method-icon">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                        <div class="method-details">
                                            <h5>Topup Request</h5>
                                            <p>Send payment manually and request wallet credit</p>
                                        </div>
                                        <div class="method-status">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Gateway Form -->
                            <div class="payment-form" id="gatewayForm" style="display: none;">
                                <form method="POST" action="" id="topupForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <?php if ($has_gateway_choice): ?>
                                        <div class="form-group">
                                            <label for="gatewayChoice" class="form-label">Payment Gateway</label>
                                            <select id="gatewayChoice" name="gateway_choice" class="form-control" required>
                                                <?php foreach ($enabled_gateways as $gateway): ?>
                                                    <option value="<?php echo htmlspecialchars($gateway); ?>" <?php echo $gateway === $active_gateway ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($gateway_labels[$gateway] ?? ucfirst($gateway)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted">Choose your preferred gateway for this top-up.</small>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" id="gatewayChoice" name="gateway_choice" value="<?php echo htmlspecialchars($active_gateway); ?>">
                                    <?php endif; ?>
                                    <div class="form-group">
                                        <label for="amount" class="form-label">Amount (<?php echo CURRENCY; ?>)</label>
                                        <input type="number" class="form-control" id="amount" name="amount" 
                                               min="<?php echo htmlspecialchars(number_format($min_allowed, 2, '.', '')); ?>" 
                                               max="<?php echo htmlspecialchars(number_format($max_allowed, 2, '.', '')); ?>" 
                                               step="0.01" required>
                                        <small class="text-muted">Minimum: <?php echo CURRENCY; ?> <?php echo htmlspecialchars(number_format($min_allowed, 2)); ?>, Maximum: <?php echo CURRENCY; ?> <?php echo htmlspecialchars(number_format($max_allowed, 2)); ?></small>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="width:100%;" id="topupBtn">
                                        <i class="fas fa-credit-card"></i> Pay with <?php echo htmlspecialchars($gateway_label); ?>
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="goBackBtn">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </button>
                                </form>
                            </div>

                            <!-- Topup Request Form -->
                            <div class="payment-form" id="topupRequestForm" style="display: none;">
                                <div class="request-info">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>NOTE:</strong> Please send the money to the account number below before you add your account email to the field provided and submit.
                                    </div>
                                </div>
                                
                                <div class="payment-details" id="paymentDetails">
                                    <!-- Payment details will be loaded here -->
                                </div>
                                
                                <form id="topupRequestFormData">
                                    <div class="form-group">
                                        <label for="requestAmount">Amount (<?php echo CURRENCY; ?>)</label>
                                        <input type="number" id="requestAmount" class="form-control" 
                                               min="<?php echo htmlspecialchars(number_format($min_allowed, 2, '.', '')); ?>" 
                                               max="<?php echo htmlspecialchars(number_format($max_allowed, 2, '.', '')); ?>" 
                                               step="0.01" required>
                                        <small class="text-muted">Minimum: <?php echo CURRENCY; ?> <?php echo htmlspecialchars(number_format($min_allowed, 2)); ?>, Maximum: <?php echo CURRENCY; ?> <?php echo htmlspecialchars(number_format($max_allowed, 2)); ?></small>
                                    </div>
                                    <div class="form-group">
                                        <label for="userEmail">Your Email Address</label>
                                        <input type="email" id="userEmail" class="form-control" value="<?php echo htmlspecialchars($current_user['email']); ?>" required>
                                        <small class="text-muted">Email address used for payment confirmation</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="senderNetwork">Payment Network Used</label>
                                        <select id="senderNetwork" class="form-control" required>
                                            <option value="">Select Network</option>
                                            <option value="MTN MOMO">MTN Mobile Money</option>
                                            <option value="VODAFONE CASH">Vodafone Cash</option>
                                            <option value="AIRTELTIGO MONEY">AirtelTigo Money</option>
                                            <option value="BANK TRANSFER">Bank Transfer</option>
                                            <option value="OTHER">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="senderName">Sender Name</label>
                                        <input type="text" id="senderName" class="form-control" value="<?php echo htmlspecialchars($current_user['full_name']); ?>" required>
                                        <small class="text-muted">Name on the payment account</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="senderNumber">Sender Phone/Account Number</label>
                                        <input type="text" id="senderNumber" class="form-control" required>
                                        <small class="text-muted">Phone number or account number used for payment</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary" id="submitRequestBtn">
                                        <i class="fas fa-paper-plane"></i> Submit Topup Request
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="goBackBtn">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </button>
                                </form>
                            </div>
                            <div class="quick-topup-section" style="margin-top:2rem;padding-top:1rem;border-top:1px solid var(--border-color);">
                                <?php if ($is_paystack_active && $agent_store && $agent_paystack_settings): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Agent Store Payment:</strong> If you choose Paystack, your payment will go directly to <?php echo htmlspecialchars($agent_store['agent_name']); ?> via their Paystack account.
                                    </div>
                                <?php endif; ?>
                                <h4 style="margin-bottom:1rem;">Quick Top-up Amounts</h4>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:.5rem;">
                                    <button class="btn btn-outline quick-topup" data-amount="10"><?php echo CURRENCY; ?> 10</button>
                                    <button class="btn btn-outline quick-topup" data-amount="20"><?php echo CURRENCY; ?> 20</button>
                                    <button class="btn btn-outline quick-topup" data-amount="50"><?php echo CURRENCY; ?> 50</button>
                                    <button class="btn btn-outline quick-topup" data-amount="100"><?php echo CURRENCY; ?> 100</button>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Balance</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($wallet_transactions as $transaction): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-<?php echo $transaction['transaction_type'] === 'credit' ? 'success' : 'danger'; ?>">
                                                    <?php echo $transaction['transaction_type'] === 'credit' ? 'Credit' : 'Debit'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="color: <?php echo $transaction['transaction_type'] === 'credit' ? 'var(--accent-green)' : 'var(--accent-red)'; ?>">
                                                    <?php echo $transaction['transaction_type'] === 'credit' ? '+' : '-'; ?><?php echo formatCurrency($transaction['amount']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatCurrency($transaction['balance_after']); ?></td>
                                            <td><small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($wallet_transactions)): ?>
                                        <tr><td colspan="4" class="text-center text-muted">No transactions found</td></tr>
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

    <style>
        .payment-method-selection {
            margin-bottom: 2rem;
        }
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .payment-method-card {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .payment-method-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .payment-method-card.selected {
            border-color: var(--primary-color);
            background-color: var(--primary-color-light, rgba(0,123,255,0.1));
        }
        .method-icon {
            font-size: 2rem;
            color: var(--primary-color);
        }
        .method-details h5 {
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }
        .method-details p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .method-status {
            margin-left: auto;
            color: var(--success-color);
            font-size: 1.5rem;
            display: none;
        }
        .payment-method-card.selected .method-status {
            display: block;
        }
        .payment-form {
            margin-top: 2rem;
        }
        .payment-details {
            background: var(--widget-bg, #f8f9fa);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Dark mode specific styling */
        [data-theme="dark"] .payment-details {
            background: var(--widget-bg, #2a2a2a);
            border: 1px solid var(--border-color, #444);
            color: var(--text-color, #f0f0f0);
        }
        
        [data-theme="dark"] .detail-label {
            color: var(--text-color, #f0f0f0);
        }
        
        [data-theme="dark"] .detail-value {
            color: var(--primary-color, #4dabf7);
        }
        .payment-detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .payment-detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: var(--text-color);
        }
        .detail-value {
            font-family: 'Courier New', monospace;
            color: var(--primary-color);
            font-weight: 600;
        }
        .payment-method-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .payment-method-card.disabled:hover {
            transform: none;
            border-color: var(--border-color);
        }
    </style>

    <script>
        const activeGateway = '<?php echo $active_gateway; ?>';
        const enabledGateways = <?php echo json_encode($enabled_gateways); ?>;
        const gatewayLabels = <?php echo json_encode($gateway_labels); ?>;
        const gatewayInitUrls = <?php echo json_encode($gateway_init_endpoints); ?>;
        const pendingPaystackTopups = <?php echo json_encode($pending_paystack_topups); ?>;
        const csrfToken = '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>';
        const gatewayChoiceSelect = document.getElementById('gatewayChoice');
        const latestPendingPaystackReferenceInput = document.getElementById('latestPendingPaystackReference');
        const verifyMissingPaymentStatus = document.getElementById('verifyMissingPaymentStatus');
        const verifyMissingPaymentButtons = Array.from(document.querySelectorAll('.verify-missing-payment-btn'));
        const pendingPaystackReferenceSet = new Set(pendingPaystackTopups.map(item => String(item.reference || '')));
        let availableMethods = {
            paystack: enabledGateways.includes('paystack'),
            moolre: enabledGateways.includes('moolre'),
            gateway: enabledGateways.length > 0,
            topup_request: true
        };
        let selectedMethod = null;

        function getSelectedGateway() {
            if (gatewayChoiceSelect && gatewayChoiceSelect.value) {
                return gatewayChoiceSelect.value;
            }
            return activeGateway;
        }

        function getGatewayLabel(gateway) {
            return gatewayLabels[gateway] || gateway || 'Gateway';
        }

        function getGatewayInitUrl(gateway) {
            return gatewayInitUrls[gateway] || gatewayInitUrls[activeGateway] || '';
        }

        function updateGatewayButtonLabel() {
            const btn = document.getElementById('topupBtn');
            if (!btn) return;
            const selectedGateway = getSelectedGateway();
            btn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with ' + getGatewayLabel(selectedGateway);
        }

        function setVerifyMissingPaymentStatus(message, isError = false) {
            if (!verifyMissingPaymentStatus) {
                return;
            }
            verifyMissingPaymentStatus.textContent = message;
            verifyMissingPaymentStatus.style.color = isError ? 'var(--accent-red)' : 'var(--text-muted)';
        }

        function setLatestPendingReference(reference) {
            if (!latestPendingPaystackReferenceInput) {
                return;
            }
            latestPendingPaystackReferenceInput.value = String(reference || '').trim();
        }

        function setVerifyButtonsBusy(reference, isBusy) {
            verifyMissingPaymentButtons.forEach(function(button) {
                const buttonReference = String(button.dataset.reference || '');
                if (reference !== '' && buttonReference !== reference) {
                    return;
                }
                if (isBusy) {
                    button.dataset.originalHtml = button.dataset.originalHtml || button.innerHTML;
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner"></span> Verifying...';
                } else {
                    button.disabled = false;
                    if (button.dataset.originalHtml) {
                        button.innerHTML = button.dataset.originalHtml;
                    }
                }
            });
        }

        async function verifyMissingPayment(reference, options = {}) {
            reference = String(reference || '').trim();
            if (!reference) {
                setVerifyMissingPaymentStatus('No pending Paystack reference was found to verify.', true);
                return;
            }
            if (!pendingPaystackReferenceSet.has(reference)) {
                setVerifyMissingPaymentStatus('Only pending references created from this customer account can be verified here.', true);
                return;
            }

            const isAutomatic = !!options.automatic;
            setVerifyButtonsBusy(reference, true);
            setLatestPendingReference(reference);
            setVerifyMissingPaymentStatus(
                isAutomatic
                    ? 'Checking your latest Paystack payment automatically...'
                    : 'Verifying your Paystack payment...'
            );

            try {
                const res = await fetch('../api/verify_missing_paystack_payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    cache: 'no-store',
                    body: JSON.stringify({
                        reference: reference,
                        csrf_token: csrfToken
                    })
                });
                const data = await res.json();

                if (!res.ok || !data || data.status !== 'success') {
                    const message = data && data.message ? data.message : 'Unable to verify this payment right now.';
                    throw new Error(message);
                }

                if (data.next_url) {
                    sessionStorage.setItem('customerPendingPaystackReference', reference);
                    window.location.href = data.next_url;
                    return;
                }

                if (data.transaction_status === 'pending') {
                    setVerifyMissingPaymentStatus(data.message || 'This payment is still pending on Paystack. If you just paid, wait a moment and try again.');
                } else if (data.transaction_status === 'failed') {
                    setVerifyMissingPaymentStatus(data.message || 'Paystack reported this transaction as unsuccessful.', true);
                    sessionStorage.removeItem('customerPendingPaystackReference');
                } else if (data.transaction_status === 'success') {
                    setVerifyMissingPaymentStatus(data.message || 'Payment was already processed.');
                    sessionStorage.removeItem('customerPendingPaystackReference');
                    if (data.redirect_path) {
                        window.location.href = '<?php echo SITE_URL; ?>' + data.redirect_path;
                    } else {
                        window.location.reload();
                    }
                    return;
                } else {
                    setVerifyMissingPaymentStatus(data.message || 'Verification completed, but the payment is not yet ready to be credited.');
                }
            } catch (err) {
                setVerifyMissingPaymentStatus(err.message || 'Unable to verify this payment right now.', true);
            } finally {
                setVerifyButtonsBusy(reference, false);
            }
        }

        function initializeMissingPaymentVerification() {
            if (!verifyMissingPaymentButtons.length) {
                sessionStorage.removeItem('customerPendingPaystackReference');
                return;
            }

            verifyMissingPaymentButtons.forEach(function(button) {
                button.dataset.originalHtml = button.innerHTML;
                button.addEventListener('click', function() {
                    verifyMissingPayment(button.dataset.reference || '');
                });
            });

            const storedReference = String(sessionStorage.getItem('customerPendingPaystackReference') || '').trim();
            const latestReference = pendingPaystackTopups.length > 0 ? String(pendingPaystackTopups[0].reference || '') : '';
            const referenceToVerify = pendingPaystackReferenceSet.has(storedReference) ? storedReference : latestReference;

            if (referenceToVerify) {
                setLatestPendingReference(referenceToVerify);
                sessionStorage.setItem('customerPendingPaystackReference', referenceToVerify);
                verifyMissingPayment(referenceToVerify, { automatic: true });
            }
        }
        
        // Load available payment methods
        async function loadPaymentMethods() {
            console.log('Loading payment methods...');
            try {
                let url = '../api/payment_methods.php?action=get_payment_methods';
                
                // Add store context if available
                const urlParams = new URLSearchParams(window.location.search);
                const store = urlParams.get('store');
                if (store) {
                    url += '&store=' + encodeURIComponent(store);
                }
                
                const res = await fetch(url);
                
                if (!res.ok) {
                    if (res.status === 401) {
                        console.error('Authentication required - redirecting to login');
                        alert('Your session has expired. Please log in again.');
                        window.location.href = '<?php echo SITE_URL; ?>/login.php';
                        return;
                    }
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                
                const data = await res.json();
                console.log('Payment methods response:', data);
                if (data.status === 'success') {
                    availableMethods = data.payment_methods || {};
                    availableMethods.gateway = !!(availableMethods.paystack || availableMethods.moolre);
                    console.log('Available methods:', availableMethods);
                    if (data.debug) {
                        console.log('Debug info:', data.debug);
                    }
                    updateMethodAvailability();
                } else {
                    console.error('Failed to load payment methods:', data.message);
                    // Use defaults if API fails but user is authenticated
                    console.log('Using default payment methods as fallback');
                    availableMethods = {
                        paystack: enabledGateways.includes('paystack'),
                        moolre: enabledGateways.includes('moolre'),
                        gateway: enabledGateways.length > 0,
                        topup_request: true
                    };
                    updateMethodAvailability();
                }
            } catch (err) {
                console.error('Failed to load payment methods:', err);
                // Use defaults if API fails but user appears to be authenticated
                console.log('Using default payment methods due to error:', err.message);
                availableMethods = {
                    paystack: enabledGateways.includes('paystack'),
                    moolre: enabledGateways.includes('moolre'),
                    gateway: enabledGateways.length > 0,
                    topup_request: true
                };
                updateMethodAvailability();
            }
        }
        
        function updateMethodAvailability() {
            console.log('Updating method availability...');
            const gatewayCard = document.getElementById('gatewayMethod');
            const topupCard = document.getElementById('topupRequestMethod');
            
            if (!gatewayCard || !topupCard) {
                console.error('Payment method cards not found!');
                return;
            }
            
            // Remove existing classes first
            gatewayCard.classList.remove('disabled');
            topupCard.classList.remove('disabled');
            
            if (!availableMethods.gateway) {
                console.log('Disabling gateway method');
                gatewayCard.classList.add('disabled');
            }
            
            if (!availableMethods.topup_request) {
                console.log('Disabling topup request method');
                topupCard.classList.add('disabled');
            }

            if (gatewayChoiceSelect) {
                Array.from(gatewayChoiceSelect.options).forEach(function(option) {
                    const gateway = String(option.value || '');
                    const enabled = !!availableMethods[gateway];
                    option.disabled = !enabled;
                });
                if (gatewayChoiceSelect.options[gatewayChoiceSelect.selectedIndex] && gatewayChoiceSelect.options[gatewayChoiceSelect.selectedIndex].disabled) {
                    const fallback = Array.from(gatewayChoiceSelect.options).find(function(option) { return !option.disabled; });
                    if (fallback) {
                        gatewayChoiceSelect.value = fallback.value;
                    }
                }
                updateGatewayButtonLabel();
            }
            
            console.log('Method availability updated. Gateway enabled:', availableMethods.gateway, 'Topup enabled:', availableMethods.topup_request);
        }
        
        function selectPaymentMethod(method) {
            console.log('=== selectPaymentMethod called ===');
            console.log('Method:', method);
            console.log('Available methods:', availableMethods);
            
            // Check if method is available
            if (!availableMethods[method]) {
                console.warn('Payment method', method, 'is not available');
                alert('This payment method is currently not available.');
                return;
            }
            
            // Check if method card is disabled
            const methodCardId = method === 'gateway' ? 'gatewayMethod' : 'topupRequestMethod';
            const methodCard = document.getElementById(methodCardId);
            if (!methodCard) {
                console.error('Method card not found:', methodCardId);
                return;
            }
            
            if (methodCard.classList.contains('disabled')) {
                console.warn('Payment method', method, 'is disabled');
                alert('This payment method is currently disabled.');
                return;
            }
            
            console.log('Selecting payment method:', method);
            selectedMethod = method;
            
            // Update UI - remove all selections first
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to chosen method
            methodCard.classList.add('selected');
            
            // Hide selection panel
            const selectionPanel = document.getElementById('paymentMethodSelection');
            if (selectionPanel) {
                selectionPanel.style.display = 'none';
            }
            
            // Show appropriate form
            if (method === 'gateway') {
                console.log('Showing gateway form');
                const gatewayForm = document.getElementById('gatewayForm');
                if (gatewayForm) {
                    gatewayForm.style.display = 'block';
                } else {
                    console.error('Gateway form not found');
                }
            } else if (method === 'topup_request') {
                console.log('Showing topup request form');
                loadPaymentDetails();
                const topupForm = document.getElementById('topupRequestForm');
                if (topupForm) {
                    topupForm.style.display = 'block';
                } else {
                    console.error('Topup request form not found');
                }
            }
            
            console.log('Payment method selection completed');
        }
        
        function goBackToSelection() {
            console.log('Going back to payment method selection');
            
            // Show selection panel
            const selectionPanel = document.getElementById('paymentMethodSelection');
            if (selectionPanel) {
                selectionPanel.style.display = 'block';
            }
            
            // Hide all forms
            const gatewayForm = document.getElementById('gatewayForm');
            const topupForm = document.getElementById('topupRequestForm');
            
            if (gatewayForm) {
                gatewayForm.style.display = 'none';
            }
            
            if (topupForm) {
                topupForm.style.display = 'none';
            }
            
            // Reset selection
            selectedMethod = null;
            
            // Remove selected class from all cards
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
        }
        
        // Initialize all event handlers
        function initializeEventHandlers() {
            console.log('Initializing event handlers...');
            
            // Payment method card click handlers
            const gatewayCard = document.getElementById('gatewayMethod');
            const topupCard = document.getElementById('topupRequestMethod');
            
            if (gatewayCard) {
                gatewayCard.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Gateway card clicked');
                    selectPaymentMethod('gateway');
                });
                console.log('Gateway card event listener added');
            } else {
                console.error('Gateway card not found!');
            }
            
            if (topupCard) {
                topupCard.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Topup request card clicked');
                    selectPaymentMethod('topup_request');
                });
                console.log('Topup card event listener added');
            } else {
                console.error('Topup request card not found!');
            }
            
            // Back button handlers
            const backButtons = document.querySelectorAll('#goBackBtn');
            backButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    console.log('Back button clicked');
                    goBackToSelection();
                });
            });
            
            console.log('Event handlers initialized successfully');
        }
        
        async function loadPaymentDetails() {
            try {
                const res = await fetch('../api/topup_requests.php?action=get_payment_details');
                const data = await res.json();
                if (data.status === 'success') {
                    const details = data.payment_details;
                    document.getElementById('paymentDetails').innerHTML = `
                        <h5 style="margin-bottom: 1rem;">Payment Details</h5>
                        <div class="payment-detail-item">
                            <span class="detail-label">Network:</span>
                            <span class="detail-value">${details.network}</span>
                        </div>
                        <div class="payment-detail-item">
                            <span class="detail-label">Wallet Name:</span>
                            <span class="detail-value">${details.wallet_name}</span>
                        </div>
                        <div class="payment-detail-item">
                            <span class="detail-label">Wallet Number:</span>
                            <span class="detail-value">${details.wallet_number}</span>
                        </div>
                    `;
                }
            } catch (err) {
                console.error('Failed to load payment details:', err);
            }
        }
        function initTheme(){
            const saved=localStorage.getItem('theme');
            const prefersDark=window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme=saved || (prefersDark?'dark':'light');
            document.documentElement.setAttribute('data-theme',theme);
            document.getElementById('theme-icon').className= theme==='dark'?'fas fa-sun':'fas fa-moon';
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== DOM Content Loaded ===');
            initTheme();
            if (gatewayChoiceSelect) {
                gatewayChoiceSelect.addEventListener('change', updateGatewayButtonLabel);
            }
            updateGatewayButtonLabel();
            initializeMissingPaymentVerification();
            loadPaymentMethods();
            initializeEventHandlers();
        });
        
        document.querySelector('.mobile-menu-toggle').addEventListener('click',()=>document.querySelector('.sidebar').classList.toggle('show'));
        
        // Quick topup buttons
        document.querySelectorAll('.quick-topup').forEach(btn=>btn.addEventListener('click',function(){
            const amount = this.dataset.amount;
            if (selectedMethod === 'gateway') {
                document.getElementById('amount').value = amount;
            } else if (selectedMethod === 'topup_request') {
                document.getElementById('requestAmount').value = amount;
            } else {
                // If no method selected, show selection first
                document.getElementById('paymentMethodSelection').style.display = 'block';
            }
        }));

        // Gateway form submission
        document.getElementById('topupForm').addEventListener('submit', async function(e){
            e.preventDefault();
            const amount = parseFloat(document.getElementById('amount').value);
            const minAllowed = parseFloat('<?php echo number_format($min_allowed, 2, '.', ''); ?>');
            const maxAllowed = parseFloat('<?php echo number_format($max_allowed, 2, '.', ''); ?>');
            
            if (!amount || amount < minAllowed || amount > maxAllowed) { 
                alert('Please enter a valid amount between <?php echo CURRENCY; ?> ' + minAllowed.toFixed(2) + ' and <?php echo CURRENCY; ?> ' + maxAllowed.toFixed(2)); 
                return; 
            }
            
            const btn = document.getElementById('topupBtn');
            btn.disabled = true; 
            btn.innerHTML = '<span class="spinner"></span> Redirecting...';
            
            try {
                const selectedGateway = getSelectedGateway();
                const gatewayInitUrl = getGatewayInitUrl(selectedGateway);
                if (!gatewayInitUrl) {
                    alert('No payment gateway endpoint is configured.');
                    btn.disabled = false;
                    updateGatewayButtonLabel();
                    return;
                }

                const requestData = { amount: amount, type: 'customer_wallet_topup' };
                requestData.gateway = selectedGateway;
                <?php if ($store_slug): ?>
                requestData.store_slug = '<?php echo htmlspecialchars($store_slug); ?>';
                <?php endif; ?>
                
                const res = await fetch(gatewayInitUrl, { 
                    method:'POST', 
                    headers:{'Content-Type':'application/json'}, 
                    body: JSON.stringify(requestData) 
                });
                const data = await res.json();
                
                if (data.status === 'success' && data.data && data.data.authorization_url) {
                    if (selectedGateway === 'paystack' && data.data.reference) {
                        sessionStorage.setItem('customerPendingPaystackReference', data.data.reference);
                    }
                    window.location.href = data.data.authorization_url;
                } else {
                    alert(data.message || 'Failed to initialize payment.');
                    btn.disabled=false; 
                    updateGatewayButtonLabel();
                }
            } catch(err){
                alert('Network error. Please try again.');
                btn.disabled=false; 
                updateGatewayButtonLabel();
            }
        });
        
        // Topup request form submission
        document.getElementById('topupRequestFormData').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('requestAmount').value);
            const userEmail = document.getElementById('userEmail').value.trim();
            const network = document.getElementById('senderNetwork').value;
            const walletName = document.getElementById('senderName').value.trim();
            const walletNumber = document.getElementById('senderNumber').value.trim();
            
            const minAllowed = parseFloat('<?php echo number_format($min_allowed, 2, '.', ''); ?>');
            const maxAllowed = parseFloat('<?php echo number_format($max_allowed, 2, '.', ''); ?>');
            
            // Validation
            if (!amount || amount < minAllowed || amount > maxAllowed) {
                alert('Please enter a valid amount between <?php echo CURRENCY; ?> ' + minAllowed.toFixed(2) + ' and <?php echo CURRENCY; ?> ' + maxAllowed.toFixed(2));
                return;
            }
            
            if (!userEmail || !network || !walletName || !walletNumber) {
                alert('Please fill in all required fields.');
                return;
            }
            
            const btn = document.getElementById('submitRequestBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Submitting...';
            
            try {
                const res = await fetch('../api/topup_requests.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?php echo htmlspecialchars($csrf_token); ?>'
                    },
                    body: JSON.stringify({
                        action: 'submit_request',
                        amount: amount,
                        user_email: userEmail,
                        network: network,
                        wallet_name: walletName,
                        wallet_number: walletNumber
                    })
                });
                
                const data = await res.json();
                
                if (data.status === 'success') {
                    alert('Topup request submitted successfully! Request ID: ' + data.request_id + '\n\nYou will receive an email confirmation once your request is processed.');
                    document.getElementById('topupRequestFormData').reset();
                    goBackToSelection();
                } else {
                    alert(data.message || 'Failed to submit topup request.');
                }
            } catch (err) {
                alert('Network error. Please try again.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Topup Request';
            }
        });
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>
