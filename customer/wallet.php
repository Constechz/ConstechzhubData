<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
$active_gateway = getActivePaymentGateway();
$gateway_label = $active_gateway === 'moolre' ? 'Moolre' : 'Paystack';
$gateway_init_endpoint = $active_gateway === 'moolre' ? '../api/moolre_init.php' : '../api/paystack_init.php';

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
$is_paystack_active = $active_gateway === 'paystack';

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
    <?php if ($active_gateway === 'moolre'): ?>
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
        <?php require_once '../includes/customer_sidebar.php'; ?>

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

<?php echo renderNotificationSlides('customers'); ?>


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

                            <!-- Payment Method Selection -->
                            <div class="payment-method-selection" id="paymentMethodSelection">
                                <h4>Choose Payment Method</h4>
                                <div class="payment-methods">
                                    <div class="payment-method-card" id="gatewayMethod">
                                        <div class="method-icon">
                                            <i class="fas fa-credit-card"></i>
                                        </div>
                                        <div class="method-details">
                                            <h5><?php echo htmlspecialchars($gateway_label); ?> Payment</h5>
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
                                        <strong>Agent Store Payment:</strong> Your payment will go directly to <?php echo htmlspecialchars($agent_store['agent_name']); ?> via their <?php echo htmlspecialchars($gateway_label); ?> account.
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
            box-shadow: 0 4px 12px rgba(46, 41, 78, 0.1);
        }
        .payment-method-card.selected {
            border-color: var(--primary-color);
            background-color: var(--primary-color-light, rgba(84, 19, 136, 0.1));
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
            background: var(--widget-bg, #F1E9DA);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Dark mode specific styling */
        [data-theme="dark"] .payment-details {
            background: var(--widget-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }
        
        [data-theme="dark"] .detail-label {
            color: var(--text-color, #F1E9DA);
        }
        
        [data-theme="dark"] .detail-value {
            color: var(--primary-color, #F1E9DA);
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
        const gatewayLabel = '<?php echo htmlspecialchars($gateway_label); ?>';
        const gatewayInitUrl = '<?php echo $gateway_init_endpoint; ?>';
        let availableMethods = { [activeGateway]: true, topup_request: true };
        let selectedMethod = null;
        
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
                    availableMethods = data.payment_methods;
                    console.log('Available methods:', availableMethods);
                    if (data.debug) {
                        console.log('Debug info:', data.debug);
                    }
                    updateMethodAvailability();
                } else {
                    console.error('Failed to load payment methods:', data.message);
                    // Use defaults if API fails but user is authenticated
                    console.log('Using default payment methods as fallback');
                    availableMethods = { [activeGateway]: true, topup_request: true };
                    updateMethodAvailability();
                }
            } catch (err) {
                console.error('Failed to load payment methods:', err);
                // Use defaults if API fails but user appears to be authenticated
                console.log('Using default payment methods due to error:', err.message);
                availableMethods = { [activeGateway]: true, topup_request: true };
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
            
            if (!availableMethods[activeGateway]) {
                console.log('Disabling gateway method');
                gatewayCard.classList.add('disabled');
            }
            
            if (!availableMethods.topup_request) {
                console.log('Disabling topup request method');
                topupCard.classList.add('disabled');
            }
            
            console.log('Method availability updated. Gateway enabled:', availableMethods[activeGateway], 'Topup enabled:', availableMethods.topup_request);
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
            const methodCardId = method === activeGateway ? 'gatewayMethod' : 'topupRequestMethod';
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
            if (method === activeGateway) {
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
                    selectPaymentMethod(activeGateway);
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
            loadPaymentMethods();
            initializeEventHandlers();
        });
        
        document.querySelector('.mobile-menu-toggle').addEventListener('click',()=>document.querySelector('.sidebar').classList.toggle('show'));
        
        // Quick topup buttons
        document.querySelectorAll('.quick-topup').forEach(btn=>btn.addEventListener('click',function(){
            const amount = this.dataset.amount;
            if (selectedMethod === activeGateway) {
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
                const requestData = { amount: amount, type: 'customer_wallet_topup' };
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
                    window.location.href = data.data.authorization_url;
                } else {
                    alert(data.message || 'Failed to initialize payment.');
                    btn.disabled=false; 
                    btn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with ' + gatewayLabel;
                }
            } catch(err){
                alert('Network error. Please try again.');
                btn.disabled=false; 
                btn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with ' + gatewayLabel;
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

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
