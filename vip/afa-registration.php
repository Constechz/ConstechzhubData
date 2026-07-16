<?php
require_once '../config/config.php';

preventBrowserCaching();
requireRole('vip');
ensureAfaRegistrationTables();

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
$csrf_token = generateCSRF();
$flash = getFlashMessage();

$settings = [
    'agent_price' => 0,
    'guest_price' => 0,
    'is_enabled' => 0,
    'allow_wallet_agent' => 1,
    'allow_gateway_agent' => 1,
    'allow_wallet_customer' => 1,
    'allow_gateway_customer' => 1,
];
$settings_rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && ($settings_row = $settings_rs->fetch_assoc())) {
    $settings = array_merge($settings, $settings_row);
}

$agent_base_price = round((float) ($settings['agent_price'] ?? 0), 2);
$service_enabled = ((int) ($settings['is_enabled'] ?? 0) === 1) || ($agent_base_price > 0);
$allow_wallet = ((int) ($settings['allow_wallet_agent'] ?? 1) === 1);
$allow_gateway = ((int) ($settings['allow_gateway_agent'] ?? 1) === 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_customer_pricing') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    $raw_custom = trim((string) ($_POST['custom_price'] ?? ''));
    if ($raw_custom === '') {
        $stmt = $db->prepare("INSERT INTO agent_afa_registration_pricing (agent_id, custom_price, is_active) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price), is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP");
        if ($stmt) {
            $stmt->bind_param('i', $current_user['id']);
            $ok = $stmt->execute();
            $stmt->close();
            setFlashMessage($ok ? 'success' : 'error', $ok ? 'Customer custom price disabled.' : 'Failed to update customer price.');
        } else {
            setFlashMessage('error', 'Failed to update customer price.');
        }
    } else {
        $custom_price = round(max(0, (float) $raw_custom), 2);
        if ($custom_price < $agent_base_price) {
            setFlashMessage('error', 'Customer custom price must be at least ' . formatCurrency($agent_base_price, CURRENCY));
        } else {
            $stmt = $db->prepare("INSERT INTO agent_afa_registration_pricing (agent_id, custom_price, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price), is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP");
            if ($stmt) {
                $stmt->bind_param('id', $current_user['id'], $custom_price);
                $ok = $stmt->execute();
                $stmt->close();
                setFlashMessage($ok ? 'success' : 'error', $ok ? 'Customer custom price updated.' : 'Failed to update customer price.');
            } else {
                setFlashMessage('error', 'Failed to update customer price.');
            }
        }
    }

    header('Location: afa-registration.php');
    exit();
}

$agent_custom_price = null;
$stmt = $db->prepare("SELECT custom_price FROM agent_afa_registration_pricing WHERE agent_id = ? AND is_active = 1 ORDER BY updated_at DESC LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $agent_custom_price = (float) ($row['custom_price'] ?? 0);
    }
    $stmt->close();
}

$recent = [];
$stmt = $db->prepare("SELECT reference, beneficiary_name, phone, ghana_card_number, occupation, date_of_birth, region, location, amount, status, payment_gateway, created_at FROM afa_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 10");
if ($stmt) {
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }
    $stmt->close();
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFA Registration - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .afa-shell {
            width: 100%;
            max-width: 100%;
        }

        .afa-shell {
            max-width: 1100px;
            margin: 0 auto;
        }

        .afa-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .pill {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.4rem;
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
        }

        .logout-icon {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid #fca5a5;
            background: #ffffff;
            color: #b91c1c;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .logout-icon:hover,
        .logout-icon:focus-visible {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }

        .logout-icon:focus-visible {
            outline: none;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.8rem;
            margin-bottom: 0.8rem;
        }

        .metric {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.8rem;
            background: #fafafa;
        }

        .metric strong {
            font-size: 1.05rem;
            display: block;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.8rem;
        }

        .note {
            color: #6b7280;
            font-size: 0.9rem;
        }

        .afa-table-wrap {
            overflow: auto;
        }

        .afa-table {
            width: 100%;
            border-collapse: collapse;
        }

        .afa-table th,
        .afa-table td {
            text-align: left;
            padding: 0.55rem;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .afa-table .afa-details-row {
            background: #fafafa;
        }
        .afa-table .afa-details-cell {
            white-space: normal !important;
            font-size: 0.85rem;
            color: #4b5563;
            border-bottom: 2px solid #edf2f7;
        }
        [data-theme="dark"] .afa-table .afa-details-row {
            background: #1f2937;
        }
        [data-theme="dark"] .afa-table .afa-details-cell {
            color: #d1d5db;
            border-bottom-color: #2f3746;
        }

        [data-theme="dark"] .afa-card {
            background: #111827;
            border-color: #374151;
        }

        [data-theme="dark"] .metric {
            background: #1f2937;
            border-color: #374151;
        }

        [data-theme="dark"] .note,
        [data-theme="dark"] .afa-table th,
        [data-theme="dark"] .afa-table td {
            color: #e5e7eb;
            border-bottom-color: #2f3746;
        }

        [data-theme="dark"] .logout-icon {
            background: #111827;
            border-color: #7f1d1d;
            color: #fca5a5;
        }

        [data-theme="dark"] .logout-icon:hover,
        [data-theme="dark"] .logout-icon:focus-visible {
            background: #3f1d1d;
            border-color: #ef4444;
            color: #fecaca;
        }

        @media (max-width: 992px) {
            .two-col {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 0.75rem;
            }

            .dashboard-header {
                padding: 0.45rem 0.55rem;
            }

            .header-left h2 {
                margin: 0;
                font-size: 1.1rem;
                line-height: 1.2;
            }

            .header-left {
                gap: 0.35rem;
            }

            .header-actions {
                margin-right: 0;
                gap: 0.3rem;
                flex-wrap: wrap;
                justify-content: flex-end;
            }

            .theme-toggle {
                width: 34px;
                height: 34px;
            }

            .logout-icon {
                width: 34px;
                height: 34px;
            }

            .header-actions .btn {
                max-width: 100%;
                min-height: 34px;
                min-width: 34px;
                padding: 0.35rem 0.55rem;
                font-size: 0.72rem;
                border-radius: 8px;
            }

            .header-actions .btn i {
                font-size: 0.72rem;
            }

            .pricing-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .afa-table-wrap {
                overflow: visible;
            }

            .afa-table,
            .afa-table thead,
            .afa-table tbody,
            .afa-table tr,
            .afa-table th,
            .afa-table td {
                display: block;
                width: 100%;
            }

            .afa-table thead {
                display: none;
            }

            .afa-table tr {
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 0.35rem;
                margin-bottom: 0.7rem;
            }

            .afa-table td {
                border: none;
                padding: 0.5rem 0.55rem;
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.6rem;
                white-space: normal;
                overflow-wrap: anywhere;
                word-break: break-word;
                text-align: right;
            }

            .afa-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #6b7280;
                text-align: left;
                flex: 0 0 44%;
                max-width: 44%;
            }

            .afa-table .empty-row td {
                display: block;
                text-align: left;
                padding: 0.6rem;
            }

            .afa-table .empty-row td::before {
                content: none;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header {
                padding: 0.4rem 0.45rem;
            }

            .header-left h2 {
                font-size: 0.95rem;
            }

            .theme-toggle {
                width: 30px;
                height: 30px;
            }

            .logout-icon {
                width: 30px;
                height: 30px;
            }

            .header-actions .btn {
                min-height: 30px;
                min-width: 30px;
                padding: 0.3rem 0.45rem;
                font-size: 0.68rem;
            }
        }

        @media (max-width: 360px) {
            .header-left h2 {
                font-size: 0.85rem;
            }

            .header-actions {
                gap: 0.2rem;
            }

            .theme-toggle,
            .logout-icon {
                width: 28px;
                height: 28px;
            }

            .header-actions .btn {
                min-height: 28px;
                min-width: 28px;
                padding: 0.25rem 0.4rem;
                font-size: 0.64rem;
            }
        }

        [data-theme="dark"] .afa-table td::before {
            color: #d1d5db;
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
                <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
                <h2>AFA Registration</h2>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" type="button" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
                <a class="logout-icon" href="../logout.php" aria-label="Logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
                <a class="btn btn-outline" href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="afa-shell">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
                <?php endif; ?>

                <?php if (!$service_enabled): ?>
                    <div class="alert alert-warning">AFA registration is disabled by admin.</div>
                <?php endif; ?>

                <div class="afa-card">
                    <div class="pricing-grid">
                        <div class="metric"><span>Agent Price</span><strong><?php echo htmlspecialchars(formatCurrency($agent_base_price, CURRENCY)); ?></strong></div>
                        <div class="metric"><span>Your Wallet</span><strong><?php echo htmlspecialchars(formatCurrency($wallet_balance, CURRENCY)); ?></strong></div>
                        <div class="metric"><span>Active Gateway</span><strong><?php echo htmlspecialchars($gateway_label); ?></strong></div>
                        <div class="metric"><span>Customer Price</span><strong><?php echo htmlspecialchars(formatCurrency($agent_custom_price !== null ? $agent_custom_price : $agent_base_price, CURRENCY)); ?></strong></div>
                    </div>
                    <span class="pill"><i class="fas fa-info-circle"></i> You can set custom customer price for users linked to your store.</span>
                </div>

                <div class="two-col">
                    <div class="afa-card">
                        <h3 style="margin-top:0;">Set Customer Price</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="save_customer_pricing">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="form-group">
                                <label for="custom_price">Custom Customer Price (GHS)</label>
                                <input id="custom_price" type="number" class="form-control" name="custom_price" min="0" step="0.01" placeholder="Leave blank to disable" value="<?php echo $agent_custom_price !== null ? htmlspecialchars(number_format($agent_custom_price, 2, '.', '')) : ''; ?>">
                                <small class="note">Must be at least <?php echo htmlspecialchars(formatCurrency($agent_base_price, CURRENCY)); ?>.</small>
                            </div>
                            <button class="btn btn-primary" type="submit" style="margin-top:.8rem;">Save Customer Price</button>
                        </form>
                    </div>

                    <div class="afa-card">
                        <h3 style="margin-top:0;">AFA Registration Form</h3>
                        <form id="afaForm" enctype="multipart/form-data">
                            <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <div class="form-grid">
                                <div><label>Beneficiary Fullname*</label><input class="form-control" name="beneficiary_name" required></div>
                                <div><label>Number For Registration*</label><input class="form-control" name="phone" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" required></div>
                                <div><label>Ghana Card Number*</label><input class="form-control" name="ghana_card_number" maxlength="13" minlength="13" pattern="[A-Za-z0-9]{13}" style="text-transform:uppercase;" required></div>
                                <div><label>Location*</label><input class="form-control" name="location" required></div>
                                <div><label>Occupation*</label><input class="form-control" name="occupation" required></div>
                                <div><label>Date of Birth*</label><input class="form-control" type="date" name="date_of_birth" required></div>
                                <div>
                                    <label>Payment Method</label>
                                    <select class="form-control" name="payment_method" id="payment_method">
                                        <?php if ($allow_wallet): ?><option value="wallet">Wallet</option><?php endif; ?>
                                        <?php if ($allow_gateway): ?><option value="gateway">Gateway (<?php echo htmlspecialchars($gateway_mode_label); ?>)</option><?php endif; ?>
                                    </select>
                                </div>
                                <?php if ($allow_gateway): ?>
                                    <div id="gatewayChoiceWrap" style="display:none;">
                                        <label>Gateway</label>
                                        <?php if ($has_gateway_choice): ?>
                                            <select class="form-control" name="gateway" id="gateway_choice">
                                                <?php foreach ($enabled_gateways as $gateway_name): ?>
                                                    <option value="<?php echo htmlspecialchars($gateway_name); ?>" <?php echo $gateway_name === $gateway ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($gateway_labels[$gateway_name] ?? ucfirst($gateway_name)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input class="form-control" type="text" value="<?php echo htmlspecialchars($gateway_label); ?>" readonly>
                                            <input type="hidden" name="gateway" value="<?php echo htmlspecialchars($gateway); ?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top:.9rem; width:100%;" <?php echo (!$service_enabled || (!$allow_wallet && !$allow_gateway)) ? 'disabled' : ''; ?>>Submit AFA Registration</button>
                            <small class="note" id="afaMsg" style="display:block; margin-top:.6rem;"></small>
                        </form>
                    </div>
                </div>

                <div class="afa-card">
                    <h3 style="margin-top:0;">Recent AFA Registrations</h3>
                    <div class="afa-table-wrap">
                        <table class="afa-table">
                            <thead><tr><th>Reference</th><th>Beneficiary</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                            <?php if (empty($recent)): ?>
                                <tr class="empty-row"><td colspan="6">No AFA registrations yet.</td></tr>
                            <?php else: foreach ($recent as $row): ?>
                                <tr>
                                    <td data-label="Reference"><?php echo htmlspecialchars($row['reference']); ?></td>
                                    <td data-label="Beneficiary"><?php echo htmlspecialchars($row['beneficiary_name']); ?></td>
                                    <td data-label="Amount"><?php echo htmlspecialchars(formatCurrency((float) $row['amount'], CURRENCY)); ?></td>
                                    <td data-label="Gateway"><?php echo htmlspecialchars(strtoupper((string) ($row['payment_gateway'] ?? '-'))); ?></td>
                                    <td data-label="Status"><?php echo htmlspecialchars(ucfirst((string) $row['status'])); ?></td>
                                    <td data-label="Date"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $row['created_at']))); ?></td>
                                </tr>
                                <tr class="afa-details-row">
                                    <td colspan="6" class="afa-details-cell">
                                        <strong>Phone:</strong> <?php echo htmlspecialchars((string) ($row['phone'] ?? 'N/A')); ?> |
                                        <strong>Card No:</strong> <?php echo htmlspecialchars((string) ($row['ghana_card_number'] ?? 'N/A')); ?> |
                                        <strong>Occupation:</strong> <?php echo htmlspecialchars((string) ($row['occupation'] ?? 'N/A')); ?> |
                                        <strong>DOB:</strong> <?php echo htmlspecialchars((string) ($row['date_of_birth'] ?? 'N/A')); ?> |
                                        <strong>Location:</strong> <?php echo htmlspecialchars((string) ($row['location'] ?? 'N/A')); ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

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
    if (icon) {
        icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

const form = document.getElementById('afaForm');
const msg = document.getElementById('afaMsg');
if (form) {
    const phoneInput = form.querySelector('input[name="phone"]');
    const cardInput = form.querySelector('input[name="ghana_card_number"]');
    const paymentMethodInput = form.querySelector('#payment_method');
    const gatewayChoiceWrap = document.getElementById('gatewayChoiceWrap');
    const gatewayChoiceInput = form.querySelector('#gateway_choice');
    const defaultGateway = <?php echo json_encode($gateway); ?>;

    const normalizePhone = () => {
        if (!phoneInput) {
            return;
        }
        phoneInput.value = phoneInput.value.replace(/\D+/g, '').slice(0, 15);
    };

    const normalizeCard = () => {
        if (!cardInput) {
            return;
        }
        cardInput.value = cardInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 13);
    };

    if (phoneInput) {
        phoneInput.addEventListener('input', normalizePhone);
        normalizePhone();
    }

    if (cardInput) {
        cardInput.addEventListener('input', normalizeCard);
        normalizeCard();
    }

    const toggleGatewayChoice = () => {
        if (!gatewayChoiceWrap || !paymentMethodInput) {
            return;
        }
        const useGateway = paymentMethodInput.value === 'gateway';
        gatewayChoiceWrap.style.display = useGateway ? 'block' : 'none';
        if (gatewayChoiceInput) {
            gatewayChoiceInput.disabled = !useGateway;
        }
    };
    if (paymentMethodInput) {
        paymentMethodInput.addEventListener('change', toggleGatewayChoice);
        toggleGatewayChoice();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.textContent = 'Processing...';
        msg.style.color = '#374151';

        normalizePhone();
        normalizeCard();
        if (!/^[0-9]{10,15}$/.test(phoneInput ? phoneInput.value : '')) {
            msg.textContent = 'Number for registration must contain digits only (10-15 digits).';
            msg.style.color = '#b91c1c';
            return;
        }
        if (!/^[A-Z0-9]{13}$/.test(cardInput ? cardInput.value : '')) {
            msg.textContent = 'Ghana Card number must be exactly 13 alphanumeric characters.';
            msg.style.color = '#b91c1c';
            return;
        }

        const formData = new FormData(form);
        formData.set('csrf_token', document.getElementById('csrf_token').value);
        const selectedMethod = paymentMethodInput ? String(paymentMethodInput.value || 'wallet') : 'wallet';
        if (selectedMethod === 'gateway') {
            const selectedGateway = gatewayChoiceInput ? String(gatewayChoiceInput.value || '').trim().toLowerCase() : defaultGateway;
            if (!selectedGateway) {
                msg.textContent = 'Please select a payment gateway.';
                msg.style.color = '#b91c1c';
                return;
            }
            formData.set('gateway', selectedGateway);
        } else {
            formData.delete('gateway');
        }

        try {
            const res = await fetch('../api/afa_registration_purchase.php', {
                method: 'POST',
                body: formData
            });
            const payload = await res.json();
            if (!res.ok || payload.status !== 'success') {
                throw new Error(payload.message || 'Request failed');
            }
            if (payload.data && payload.data.authorization_url) {
                window.location.href = payload.data.authorization_url;
                return;
            }
            msg.textContent = payload.message || 'AFA registration successful.';
            msg.style.color = '#065f46';
            form.reset();
            toggleGatewayChoice();
            setTimeout(() => window.location.reload(), 1200);
        } catch (err) {
            msg.textContent = err.message || 'Failed to submit registration.';
            msg.style.color = '#b91c1c';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initTheme();

    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
</script>
<script src="../immediate_icon_fix.js"></script>
</body>
</html>

