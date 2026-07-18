<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

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

$bece_is_enabled = ((int) $settings['bece_enabled'] === 1) || ((float) $settings['bece_price'] > 0);
$wassce_is_enabled = ((int) $settings['wassce_enabled'] === 1) || ((float) $settings['wassce_price'] > 0);

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

// Resolve store + linked agent
$store_slug = sanitize($_GET['store'] ?? '');
$agent_id = 0;
$store_name = '';
$agent_store = null;
$agent_name = '';

if ($store_slug !== '') {
    $stmt = $db->prepare("
        SELECT ast.agent_id, ast.store_name, u.full_name AS agent_name
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = 1 AND u.status = 'active'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $store_slug);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $agent_store = $row;
            $agent_id = (int) $row['agent_id'];
            $store_name = (string) ($row['store_name'] ?? '');
            $agent_name = (string) ($row['agent_name'] ?? '');
        }
    }
}

if ($agent_id <= 0) {
    $agent_id = getLinkedAgentId($current_user['id']);
}

// Agent custom pricing (if linked)
$agent_prices = [];
if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing')) {
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
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = $row['card_type'] ?? '';
            if ($type === '') {
                continue;
            }
            if (!isset($agent_prices[$type])) {
                $agent_prices[$type] = (float) $row['custom_price'];
            }
        }
        $stmt->close();
    }
}

$bece_admin_price = (float) $settings['bece_price'];
$wassce_admin_price = (float) $settings['wassce_price'];
$bece_price = ($agent_id > 0 && isset($agent_prices['BECE']) && $agent_prices['BECE'] >= $bece_admin_price) ? $agent_prices['BECE'] : $bece_admin_price;
$wassce_price = ($agent_id > 0 && isset($agent_prices['WASSCE']) && $agent_prices['WASSCE'] >= $wassce_admin_price) ? $agent_prices['WASSCE'] : $wassce_admin_price;

$gateway = getActivePaymentGateway();
$gateway_label = $gateway === 'moolre' ? 'Moolre' : 'Paystack';

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result Checker Cards - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <script src="../immediate_icon_fix.js"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
    <style>
        :root {
            --rc-bg: #F1E9DA;
            --rc-card: #F1E9DA;
            --rc-text: #2E294E;
            --rc-muted: #541388;
            --rc-border: #F1E9DA;
            --rc-primary: #541388;
            --rc-primary-2: #541388;
            --rc-accent: #2E294E;
        }
        * { box-sizing: border-box; }
        .rc-shell {
            max-width: 980px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem;
            color: var(--rc-text);
        }
        .rc-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .rc-topbar h1 {
            margin: 0;
            font-size: 1.4rem;
        }
        .rc-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .rc-balance {
            background: var(--rc-accent);
            color: #F1E9DA;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .rc-hero {
            background: linear-gradient(135deg, #541388, #541388);
            color: #F1E9DA;
            border-radius: 18px;
            padding: 1.75rem 1.5rem;
            text-align: center;
            box-shadow: 0 18px 36px rgba(84, 19, 136, 0.24);
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
            box-shadow: 0 10px 24px rgba(46, 41, 78, 0.08);
        }
        .rc-icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            font-size: 1.2rem;
        }
        .rc-icon.blue { background: #F1E9DA; color: #541388; }
        .rc-icon.pink { background: #F1E9DA; color: #D90368; }
        .rc-price-meta h3 {
            margin: 0 0 0.3rem;
            font-size: 1rem;
        }
        .rc-price-meta span {
            color: #2E294E;
            font-weight: 700;
        }
        .rc-form {
            background: var(--rc-card);
            border: 1px solid var(--rc-border);
            border-radius: 18px;
            padding: 1.5rem;
            box-shadow: 0 12px 28px rgba(46, 41, 78, 0.08);
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
        .rc-select, .rc-methods, .rc-input {
            width: 100%;
            padding: 0.85rem 0.9rem;
            border-radius: 12px;
            border: 1px solid var(--rc-border);
            font-size: 0.95rem;
            background: #F1E9DA;
        }
        .rc-help {
            display: block;
            margin-top: 0.35rem;
            font-size: 0.8rem;
            color: var(--rc-muted);
        }
        .rc-balance-card {
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            border-radius: 12px;
            padding: 0.85rem 0.9rem;
            color: #541388;
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
            background: #F1E9DA;
            color: #F1E9DA;
            cursor: not-allowed;
        }
        .rc-submit.active {
            background: var(--rc-primary);
            color: #F1E9DA;
            cursor: pointer;
        }
        .rc-notes {
            margin-top: 1.25rem;
            display: grid;
            gap: 0.65rem;
        }
        .rc-note {
            background: #F1E9DA;
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
            border: 1px solid #F1E9DA;
            color: #541388;
            text-decoration: none;
            font-weight: 600;
            background: #F1E9DA;
        }
        .rc-result {
            margin-top: 1rem;
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            color: #2E294E;
            border-radius: 12px;
            padding: 1rem;
            display: none;
        }
        .rc-result h5 {
            margin: 0 0 0.5rem;
        }
        .rc-result code {
            display: inline-block;
            background: #F1E9DA;
            padding: 0.2rem 0.4rem;
            border-radius: 6px;
        }
        .rc-flash {
            margin-bottom: 1rem;
            padding: 0.8rem 1rem;
            border-radius: 12px;
            background: #F1E9DA;
            border: 1px solid var(--rc-border);
        }
        .rc-flash.success { border-color: #F1E9DA; color: #2E294E; }
        .rc-flash.error { border-color: #F1E9DA; color: #D90368; }
        [data-theme="dark"] .dashboard-content .alert {
            color: #2E294E;
        }
        @media (max-width: 640px) {
            .rc-topbar { flex-direction: column; align-items: flex-start; }
            .rc-actions { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <?php require_once '../includes/customer_sidebar.php'; ?>

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

<?php echo renderNotificationSlides('customers'); ?>


            <div class="dashboard-content">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <div class="page-title">
                    <h1>Result Checker Cards</h1>
                    <p class="page-subtitle">Purchase BECE and WASSCE result checker cards instantly.</p>
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
                                <span><?php echo CURRENCY . ' ' . number_format($bece_price, 2); ?></span>
                            </div>
                        </div>
                        <div class="rc-price-card">
                            <div class="rc-icon pink"><i class="fas fa-graduation-cap"></i></div>
                            <div class="rc-price-meta">
                                <h3>WASSCE Cards</h3>
                                <span><?php echo CURRENCY . ' ' . number_format($wassce_price, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="rc-form">
                        <h4>SELECT CARD TYPE</h4>
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
                                <option value="gateway">Pay with <?php echo htmlspecialchars($gateway_label); ?></option>
                            </select>
                        </div>
                        <div class="rc-balance-card">
                            <i class="fas fa-wallet"></i>
                            Available balance: <?php echo CURRENCY . ' ' . number_format($wallet_balance, 2); ?>
                        </div>
                        <button id="rcSubmit" class="rc-submit" type="button"><i class="fas fa-shopping-cart"></i> Continue</button>
                        <div class="rc-result" id="rcResult"></div>
                    </div>

                    <div class="rc-notes">
                        <div class="rc-note"><i class="fas fa-bolt"></i> Instant delivery after purchase</div>
                        <div class="rc-note"><i class="fas fa-shield-alt"></i> Valid for three-time use only</div>
                        <div class="rc-note"><i class="fas fa-history"></i> View history anytime</div>
                    </div>

                    <div class="rc-history">
                        <a href="result-checker-history.php<?php echo $store_slug ? ('?store=' . urlencode($store_slug)) : ''; ?>">
                            <i class="fas fa-clock"></i> View Purchase History
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const rcPrices = {
            BECE: <?php echo json_encode($bece_price); ?>,
            WASSCE: <?php echo json_encode($wassce_price); ?>
        };
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
            const enabled = type && rcEnabled[type] && phoneOk && emailOk;
            rcSubmit.classList.toggle('active', !!enabled);
            rcSubmit.disabled = !enabled;
        }
        updateButtonState();
        cardTypeSelect.addEventListener('change', updateButtonState);
        smsPhoneInput.addEventListener('input', updateButtonState);
        notifyEmailInput.addEventListener('input', updateButtonState);

        rcSubmit.addEventListener('click', async () => {
            const cardType = cardTypeSelect.value;
            const paymentMethod = methodSelect.value;
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
                        payment_method: paymentMethod,
                        sms_phone: smsPhone,
                        notification_email: notifyEmail,
                        store_slug: <?php echo json_encode($store_slug); ?>,
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
                resultBox.innerHTML =
                    '<h5>Card Details</h5>' +
                    '<div>Type: <strong>' + (details.card_type || cardType) + '</strong></div>' +
                    '<div>PIN: <code>' + (details.pin || '') + '</code></div>' +
                    '<div>Serial: <code>' + (details.serial_number || '') + '</code></div>' +
                    '<div>Reference: <strong>' + (details.reference || '') + '</strong></div>';
                resultBox.style.display = 'block';
            } catch (err) {
                alert(err.message || 'Purchase failed');
            } finally {
                rcSubmit.textContent = 'Continue';
                updateButtonState();
            }
        });
    </script>

    <script>
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
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }

        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');

            if (toggle && !toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
        });
    </script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
