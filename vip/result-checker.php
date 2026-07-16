<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require agent role
requireRole('vip');

ensureResultCheckerTables();

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
$csrf_token = generateCSRF();

// Load settings
$settings = [
    'bece_price' => 17.00,
    'wassce_price' => 17.00,
    'bece_enabled' => 0,
    'wassce_enabled' => 0
];
$settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
    $settings = array_merge($settings, $settings_row);
}

$bece_admin_price = (float) $settings['bece_price'];
$wassce_admin_price = (float) $settings['wassce_price'];
$bece_is_enabled = ((int) $settings['bece_enabled'] === 1) || ($bece_admin_price > 0);
$wassce_is_enabled = ((int) $settings['wassce_enabled'] === 1) || ($wassce_admin_price > 0);

// Handle pricing save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pricing') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: result-checker.php');
        exit();
    }

    $pricing_table_ready = function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing');
    if (!$pricing_table_ready) {
        setFlashMessage('error', 'Pricing table is missing. Please contact support.');
        header('Location: result-checker.php');
        exit();
    }

    $errors = [];
    $bece_custom = trim($_POST['bece_custom_price'] ?? '');
    $wassce_custom = trim($_POST['wassce_custom_price'] ?? '');

    $updates = [
        'BECE' => $bece_custom,
        'WASSCE' => $wassce_custom
    ];

    foreach ($updates as $type => $value) {
        $admin_price = $type === 'BECE' ? $bece_admin_price : $wassce_admin_price;
        if ($value !== '' && (float) $value < $admin_price) {
            $errors[] = $type . " price must be at least " . CURRENCY . ' ' . number_format($admin_price, 2);
        }
    }

    if (!empty($errors)) {
        setFlashMessage('error', implode('. ', $errors));
    } else {
        $has_is_active = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'is_active');
        $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'updated_at');
        foreach ($updates as $type => $value) {
            $is_active = $value !== '';
            $price_val = $is_active ? (float) $value : 0.0;
            $columns = ['agent_id', 'card_type', 'custom_price'];
            $types = 'isd';
            $values = [$current_user['id'], $type, $price_val];
            if ($has_is_active) {
                $columns[] = 'is_active';
                $types .= 'i';
                $values[] = $is_active ? 1 : 0;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnList = implode(', ', array_map(function($col) { return "`{$col}`"; }, $columns));
            $updatesSql = ['custom_price = VALUES(custom_price)'];
            if ($has_is_active) {
                $updatesSql[] = 'is_active = VALUES(is_active)';
            }
            if ($has_updated_at) {
                $updatesSql[] = 'updated_at = CURRENT_TIMESTAMP';
            }

            $sql = "INSERT INTO agent_result_checker_pricing ({$columnList}) VALUES ({$placeholders})";
            if (!empty($updatesSql)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updatesSql);
            }

            $stmt = $db->prepare($sql);
            if ($stmt) {
                $bindParams = [$types];
                foreach ($values as $index => $val) {
                    $bindParams[] = &$values[$index];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                if (!$stmt->execute()) {
                    $errors[] = $type . ' pricing update failed.';
                }
                $stmt->close();
            } else {
                $errors[] = $type . ' pricing update failed.';
            }
        }
        if (empty($errors)) {
            setFlashMessage('success', 'Customer pricing updated.');
        } else {
            setFlashMessage('error', implode(' ', $errors));
        }
    }

    header('Location: result-checker.php');
    exit();
}

// Existing custom prices
$agent_prices = ['BECE' => null, 'WASSCE' => null];
$pricing_table_ready = function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing');
if ($pricing_table_ready) {
    $has_is_active = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'is_active');
    $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'updated_at');
    $has_created_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'created_at');

    $where = "WHERE agent_id = ?";
    if ($has_is_active) {
        $where .= " AND is_active = 1";
    }

    $orderBy = '';
    if ($has_updated_at) {
        $orderBy = ' ORDER BY updated_at DESC';
    } elseif ($has_created_at) {
        $orderBy = ' ORDER BY created_at DESC';
    }

    $stmt = $db->prepare("SELECT card_type, custom_price FROM agent_result_checker_pricing {$where}{$orderBy}");
    if ($stmt) {
        $stmt->bind_param('i', $current_user['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = $row['card_type'] ?? '';
            if ($type === '' || !array_key_exists($type, $agent_prices)) {
                continue;
            }
            if ($agent_prices[$type] === null) {
                $agent_prices[$type] = (float) $row['custom_price'];
            }
        }
        $stmt->close();
    }
}

// Available inventory counts
$available_counts = ['BECE' => 0, 'WASSCE' => 0];
$count_rs = $db->query("
    SELECT card_type, COUNT(*) AS total_count
    FROM result_checker_cards
    WHERE status = 'available'
    GROUP BY card_type
");
if ($count_rs) {
    while ($row = $count_rs->fetch_assoc()) {
        $available_counts[$row['card_type']] = (int) $row['total_count'];
    }
}

$gateway = getActivePaymentGateway();
$enabled_gateways = getEnabledPaymentGateways();
$enabled_gateways = array_values(array_filter($enabled_gateways, function ($name) {
    return in_array($name, ['paystack', 'moolre'], true);
}));
if (empty($enabled_gateways)) {
    $enabled_gateways = ['paystack'];
}
if (!in_array($gateway, $enabled_gateways, true)) {
    $gateway = $enabled_gateways[0];
}
$gateway_labels = [
    'paystack' => 'Paystack',
    'moolre' => 'Moolre'
];
$gateway_label = $gateway_labels[$gateway] ?? ucfirst($gateway);
$gateway_mode_label = count($enabled_gateways) > 1 ? 'Paystack + Moolre' : $gateway_label;
$has_gateway_choice = count($enabled_gateways) > 1;

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result Checker Cards - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        :root {
            --rc-card: #ffffff;
            --rc-text: #0f172a;
            --rc-muted: #6b7280;
            --rc-border: #e5e7eb;
            --rc-primary: #4f46e5;
            --rc-accent: #10b981;
        }
        .rc-shell {
            max-width: 980px;
            margin: 0 auto;
        }
        .rc-hero {
            background: linear-gradient(135deg, #2f5bea, #7c3aed);
            color: #fff;
            border-radius: 18px;
            padding: 1.75rem 1.5rem;
            text-align: center;
            box-shadow: 0 18px 36px rgba(56, 74, 195, 0.24);
        }
        .rc-hero h2 {
            margin: 0 0 0.4rem;
            font-size: 1.4rem;
            letter-spacing: 0.5px;
        }
        .rc-hero p {
            margin: 0;
            opacity: 0.9;
        }
        .rc-price-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .rc-price-card {
            background: var(--rc-card);
            border: 1px solid var(--rc-border);
            border-radius: 16px;
            padding: 1rem;
            display: flex;
            gap: 0.85rem;
            align-items: center;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }
        .rc-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            font-size: 1.2rem;
        }
        .rc-icon.blue { background: #e0e7ff; color: #3b82f6; }
        .rc-icon.pink { background: #fde2e2; color: #ef4444; }
        .rc-price-meta h3 {
            margin: 0 0 0.3rem;
            font-size: 1rem;
        }
        .rc-price-meta span {
            color: #10b981;
            font-weight: 700;
            display: block;
        }
        .rc-price-meta small {
            color: var(--rc-muted);
        }
        .rc-form {
            background: var(--rc-card);
            border: 1px solid var(--rc-border);
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
        }
        .rc-form h4 {
            margin: 0 0 1rem;
            font-size: 0.95rem;
            letter-spacing: 0.08rem;
            text-transform: uppercase;
            color: var(--rc-muted);
        }
        .rc-field {
            margin-bottom: 1rem;
        }
        .rc-field label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }
        .rc-help {
            display: block;
            margin-top: 0.35rem;
            font-size: 0.8rem;
            color: var(--rc-muted);
        }
        .rc-select, .rc-input {
            width: 100%;
            padding: 0.85rem 0.9rem;
            border-radius: 12px;
            border: 1px solid var(--rc-border);
            font-size: 0.95rem;
            background: #fff;
        }
        .rc-balance-card {
            background: #eef4ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 0.85rem 0.9rem;
            color: #1d4ed8;
            font-weight: 600;
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .rc-submit {
            width: 100%;
            margin-top: 0.75rem;
            padding: 0.85rem 1rem;
            border-radius: 999px;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .rc-submit.active {
            background: var(--rc-primary);
            color: #fff;
            cursor: pointer;
        }
        .rc-notes {
            margin-top: 1.25rem;
            display: grid;
            gap: 0.65rem;
        }
        .rc-note {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--rc-border);
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: var(--rc-muted);
        }
        .rc-history {
            margin-top: 1.5rem;
            text-align: center;
        }
        .rc-history a {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.7rem 1.4rem;
            border-radius: 999px;
            border: 1px solid #93c5fd;
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            background: #f8fbff;
        }
        .rc-result {
            margin-top: 1rem;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 12px;
            padding: 1rem;
            display: none;
        }
        .rc-result h5 {
            margin: 0 0 0.5rem;
        }
        .rc-result code {
            display: inline-block;
            background: #dcfce7;
            padding: 0.2rem 0.4rem;
            border-radius: 6px;
        }
        .rc-flash {
            margin-bottom: 1rem;
            padding: 0.8rem 1rem;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--rc-border);
        }
        .rc-flash.success { border-color: #bbf7d0; color: #166534; }
        .rc-flash.error { border-color: #fecaca; color: #991b1b; }
        [data-theme="dark"] .rc-flash {
            color: #000;
        }
        [data-theme="dark"] .rc-form,
        [data-theme="dark"] .rc-form * {
            color: #000000;
        }
        [data-theme="dark"] .rc-input,
        [data-theme="dark"] .rc-select {
            color: #000000;
            background: #ffffff;
        }
        [data-theme="dark"] .rc-input::placeholder,
        [data-theme="dark"] .rc-select::placeholder {
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <nav class="sidebar">
            <div class="sidebar-brand">
                <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
            </div>
            <?php renderAgentSidebar(); ?>
        </nav>
        
        <main class="main-content">
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <div class="breadcrumb-item">
                            <i class="fas fa-award"></i>
                        </div>
                        <div class="breadcrumb-item">Result Checker</div>
                        <div class="breadcrumb-item active">Cards</div>
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
            
            <div class="dashboard-content">
                <?php if ($flash): ?>
                    <div class="rc-flash <?php echo htmlspecialchars($flash['type']); ?>">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <div class="page-title">
                    <h1>Result Checker Cards</h1>
                    <p class="page-subtitle">Buy cards and set your customer pricing.</p>
                </div>

                <div class="rc-shell">
                    <div class="rc-hero">
                        <h2><i class="fas fa-award"></i> RESULT CHECKER CARDS</h2>
                        <p>Purchase BECE and WASSCE result checker cards instantly</p>
                    </div>

                    <div class="rc-price-grid">
                        <div class="rc-price-card">
                            <div class="rc-icon blue"><i class="fas fa-book"></i></div>
                            <div class="rc-price-meta">
                                <h3>BECE Cards</h3>
                                <span><?php echo CURRENCY . ' ' . number_format($bece_admin_price, 2); ?></span>
                                <small>Your customer price: <?php echo CURRENCY . ' ' . number_format($agent_prices['BECE'] ?? $bece_admin_price, 2); ?></small>
                            </div>
                        </div>
                        <div class="rc-price-card">
                            <div class="rc-icon pink"><i class="fas fa-graduation-cap"></i></div>
                            <div class="rc-price-meta">
                                <h3>WASSCE Cards</h3>
                                <span><?php echo CURRENCY . ' ' . number_format($wassce_admin_price, 2); ?></span>
                                <small>Your customer price: <?php echo CURRENCY . ' ' . number_format($agent_prices['WASSCE'] ?? $wassce_admin_price, 2); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="rc-form">
                        <h4>BUY FOR YOURSELF</h4>
                        <div class="rc-field">
                            <label for="cardType">Card Type *</label>
                            <select id="cardType" class="rc-select">
                                <option value="">Choose card type</option>
                                <?php if ($bece_is_enabled): ?>
                                    <option value="BECE">BECE (<?php echo $available_counts['BECE']; ?> available)</option>
                                <?php endif; ?>
                                <?php if ($wassce_is_enabled): ?>
                                    <option value="WASSCE">WASSCE (<?php echo $available_counts['WASSCE']; ?> available)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="rc-field">
                            <label for="cardQuantity">Quantity *</label>
                            <input id="cardQuantity" class="rc-input" type="number" min="1" max="20" value="1">
                            <span class="rc-help" id="quantityHint">Enter how many cards to buy (max 20 per purchase).</span>
                        </div>
                        <div class="rc-field">
                            <label for="smsPhone">SMS Phone Number *</label>
                            <input id="smsPhone" class="rc-input" type="tel" placeholder="e.g. 0240000000">
                            <span class="rc-help">We will send the PIN, Serial, and checker link to this number.</span>
                        </div>
                        <div class="rc-field">
                            <label for="notifyEmail">Email Address *</label>
                            <input id="notifyEmail" class="rc-input" type="email" placeholder="name@example.com" required>
                            <span class="rc-help">Card details will also be sent to this email address.</span>
                        </div>
                        <div class="rc-field">
                            <label for="paymentMethod">Payment Method *</label>
                            <select id="paymentMethod" class="rc-select">
                                <option value="wallet">Wallet</option>
                                <option value="gateway">Pay with Gateway (<?php echo htmlspecialchars($gateway_mode_label); ?>)</option>
                            </select>
                        </div>
                        <div class="rc-field" id="gatewayField" style="display:none;">
                            <label for="gatewayChoice">Select Gateway *</label>
                            <?php if ($has_gateway_choice): ?>
                                <select id="gatewayChoice" class="rc-select">
                                    <?php foreach ($enabled_gateways as $gateway_name): ?>
                                        <option value="<?php echo htmlspecialchars($gateway_name); ?>" <?php echo $gateway_name === $gateway ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($gateway_labels[$gateway_name] ?? ucfirst($gateway_name)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input id="gatewayChoice" class="rc-input" type="text" value="<?php echo htmlspecialchars($gateway_label); ?>" readonly>
                            <?php endif; ?>
                        </div>
                        <div class="rc-balance-card">
                            <i class="fas fa-wallet"></i>
                            Available balance: <?php echo CURRENCY . ' ' . number_format($wallet_balance, 2); ?>
                        </div>
                        <button id="rcSubmit" class="rc-submit" type="button"><i class="fas fa-shopping-cart"></i> Continue</button>
                        <div class="rc-result" id="rcResult"></div>
                    </div>

                    <div class="rc-form" style="margin-top:1.5rem;">
                        <h4>SET CUSTOMER PRICES</h4>
                        <form method="post">
                            <input type="hidden" name="action" value="save_pricing">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="rc-field">
                                <label>BECE Customer Price (min <?php echo CURRENCY . ' ' . number_format($bece_admin_price, 2); ?>)</label>
                                <input class="rc-input" type="number" step="0.01" min="<?php echo htmlspecialchars($bece_admin_price); ?>" name="bece_custom_price" value="<?php echo $agent_prices['BECE'] !== null ? htmlspecialchars($agent_prices['BECE']) : ''; ?>" placeholder="Leave blank to use admin price">
                            </div>
                            <div class="rc-field">
                                <label>WASSCE Customer Price (min <?php echo CURRENCY . ' ' . number_format($wassce_admin_price, 2); ?>)</label>
                                <input class="rc-input" type="number" step="0.01" min="<?php echo htmlspecialchars($wassce_admin_price); ?>" name="wassce_custom_price" value="<?php echo $agent_prices['WASSCE'] !== null ? htmlspecialchars($agent_prices['WASSCE']) : ''; ?>" placeholder="Leave blank to use admin price">
                            </div>
                            <button class="rc-submit active" type="submit" style="cursor:pointer;">Save Prices</button>
                        </form>
                    </div>

                    <div class="rc-notes">
                        <div class="rc-note"><i class="fas fa-bolt"></i> Instant delivery after purchase</div>
                        <div class="rc-note"><i class="fas fa-shield-alt"></i> Valid for three-time use only</div>
                        <div class="rc-note"><i class="fas fa-history"></i> View history anytime</div>
                    </div>

                    <div class="rc-history">
                        <a href="result-checker-history.php">
                            <i class="fas fa-clock"></i> View Purchase History
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const rcEnabled = {
            BECE: <?php echo json_encode($bece_is_enabled); ?>,
            WASSCE: <?php echo json_encode($wassce_is_enabled); ?>
        };
        const rcSubmit = document.getElementById('rcSubmit');
        const cardTypeSelect = document.getElementById('cardType');
        const methodSelect = document.getElementById('paymentMethod');
        const resultBox = document.getElementById('rcResult');
        const smsPhoneInput = document.getElementById('smsPhone');
        const notifyEmailInput = document.getElementById('notifyEmail');
        const quantityInput = document.getElementById('cardQuantity');
        const quantityHint = document.getElementById('quantityHint');
        const gatewayField = document.getElementById('gatewayField');
        const gatewayChoice = document.getElementById('gatewayChoice');
        const availableByType = {
            BECE: <?php echo (int) $available_counts['BECE']; ?>,
            WASSCE: <?php echo (int) $available_counts['WASSCE']; ?>
        };
        const defaultGateway = <?php echo json_encode($gateway); ?>;

        function getSelectedGateway() {
            if (!gatewayChoice) {
                return defaultGateway;
            }
            const value = String(gatewayChoice.value || '').trim().toLowerCase();
            if (!value || value === 'paystack' || value === 'moolre') {
                return value || defaultGateway;
            }
            return defaultGateway;
        }

        function updateGatewayFieldVisibility() {
            if (!gatewayField) {
                return;
            }
            const isGateway = methodSelect.value === 'gateway';
            gatewayField.style.display = isGateway ? 'block' : 'none';
            if (gatewayChoice && gatewayChoice.tagName === 'SELECT') {
                gatewayChoice.disabled = !isGateway;
            }
        }

        async function parseJsonResponse(res) {
            const text = await res.text();
            if (!text) {
                return { ok: res.ok, data: null, error: 'Empty response from server. Please try again.' };
            }
            try {
                return { ok: res.ok, data: JSON.parse(text) };
            } catch (err) {
                console.error('Result checker response parse failed:', text);
                return { ok: res.ok, data: null, error: 'Invalid response from server. Please try again.' };
            }
        }

        function updateButtonState() {
            const type = cardTypeSelect.value;
            const phoneOk = smsPhoneInput.value.trim().length > 0;
            const emailOk = notifyEmailInput.value.trim().length > 0;
            const qty = parseInt(quantityInput.value, 10);
            const qtyOk = Number.isFinite(qty) && qty >= 1 && qty <= 20;
            const stockOk = !type || !qtyOk ? false : qty <= (availableByType[type] || 0);
            const enabled = type && rcEnabled[type] && phoneOk && emailOk && qtyOk && stockOk;
            if (type) {
                quantityHint.textContent = 'Available: ' + (availableByType[type] || 0) + ' cards. Max 20 per purchase.';
                if (qtyOk && !stockOk) {
                    quantityHint.textContent = 'Requested quantity exceeds available stock for ' + type + '.';
                }
            } else {
                quantityHint.textContent = 'Enter how many cards to buy (max 20 per purchase).';
            }
            rcSubmit.classList.toggle('active', !!enabled);
            rcSubmit.disabled = !enabled;
        }
        updateButtonState();
        updateGatewayFieldVisibility();
        cardTypeSelect.addEventListener('change', updateButtonState);
        quantityInput.addEventListener('input', updateButtonState);
        smsPhoneInput.addEventListener('input', updateButtonState);
        notifyEmailInput.addEventListener('input', updateButtonState);
        methodSelect.addEventListener('change', updateGatewayFieldVisibility);

        rcSubmit.addEventListener('click', async () => {
            const cardType = cardTypeSelect.value;
            const paymentMethod = methodSelect.value;
            const quantity = parseInt(quantityInput.value, 10) || 1;
            const smsPhone = smsPhoneInput.value.trim();
            const notifyEmail = notifyEmailInput.value.trim();
            if (!cardType) return;
            if (!smsPhone) {
                alert('Please enter an SMS phone number.');
                updateButtonState();
                return;
            }
            if (!notifyEmail) {
                alert('Please enter an email address.');
                updateButtonState();
                return;
            }
            if (quantity < 1 || quantity > 20) {
                alert('Quantity must be between 1 and 20.');
                updateButtonState();
                return;
            }
            if (quantity > (availableByType[cardType] || 0)) {
                alert('Requested quantity exceeds available stock.');
                updateButtonState();
                return;
            }

            rcSubmit.textContent = 'Processing...';
            rcSubmit.classList.remove('active');
            rcSubmit.disabled = true;
            resultBox.style.display = 'none';

            try {
                const res = await fetch('../api/result_checker_purchase.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        card_type: cardType,
                        quantity: quantity,
                        payment_method: paymentMethod,
                        gateway: paymentMethod === 'gateway' ? getSelectedGateway() : '',
                        sms_phone: smsPhone,
                        notification_email: notifyEmail,
                        csrf_token: <?php echo json_encode($csrf_token); ?>
                    })
                });
                const parsed = await parseJsonResponse(res);
                if (!parsed.data) {
                    throw new Error(parsed.error || 'Purchase failed');
                }
                const data = parsed.data;
                if (data.status === 'success' && data.data && data.data.authorization_url) {
                    window.location.href = data.data.authorization_url;
                    return;
                }
                if (data.status !== 'success') {
                    throw new Error(data.message || 'Purchase failed');
                }
                const details = data.data || {};
                const cards = Array.isArray(details.cards) ? details.cards : [];
                let cardHtml = '';
                if (cards.length > 0) {
                    cardHtml = cards.map((item, index) => (
                        '<div style="margin-top:0.5rem;">#' + (index + 1) +
                        ' PIN: <code>' + (item.pin || '') + '</code> | Serial: <code>' + (item.serial_number || '') + '</code></div>'
                    )).join('');
                } else {
                    cardHtml =
                        '<div>PIN: <code>' + (details.pin || '') + '</code></div>' +
                        '<div>Serial: <code>' + (details.serial_number || '') + '</code></div>';
                }
                resultBox.innerHTML =
                    '<h5>Card Details</h5>' +
                    '<div>Type: <strong>' + (details.card_type || cardType) + '</strong></div>' +
                    '<div>Quantity: <strong>' + (details.quantity || quantity) + '</strong></div>' +
                    '<div>Total Amount: <strong><?php echo addslashes(CURRENCY); ?> ' + Number(details.amount || 0).toFixed(2) + '</strong></div>' +
                    cardHtml +
                    '<div style="margin-top:0.5rem;">Reference: <strong>' + (details.reference || '') + '</strong></div>';
                resultBox.style.display = 'block';
            } catch (err) {
                alert(err.message || 'Purchase failed');
            } finally {
                rcSubmit.textContent = 'Continue';
                updateButtonState();
            }
        });

        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
        });
        
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

        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (dropdown && toggle && !toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
        });
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
</body>
</html>

