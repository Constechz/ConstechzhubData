<?php
require_once '../config/config.php';

preventBrowserCaching();
requireRole('customer');

$current_user = getCurrentUser();
ensureDataPackageStockStatusColumn();
$wallet_balance = getWalletBalance($current_user['id']);
$customer_pricing_type = getCustomerPricingUserType($current_user);
$store_slug = sanitize($_GET['store'] ?? '');
$package_id = (int) ($_GET['package_id'] ?? 0);

if ($store_slug === '' || $package_id <= 0) {
    header('Location: ' . SITE_URL . '/store/index.php');
    exit();
}

$stmt = $db->prepare("
    SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
    LIMIT 1
");
$stmt->bind_param('s', $store_slug);
$stmt->execute();
$store = $stmt->get_result()->fetch_assoc();

if (!$store) {
    header('HTTP/1.0 404 Not Found');
    include __DIR__ . '/../404.php';
    exit();
}

$agent_id = (int) $store['agent_id'];
$package_availability_sql = "(pp_customer.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0) AND dp.status = 'active' AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'";

$stmt = $db->prepare('
    SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
           COALESCE(n.name, "Unknown") AS network_name,
           COALESCE(dp.stock_status, "in_stock") AS stock_status,
           COALESCE(pp_customer.price, pp_customer_fallback.price, dp.price, 0) AS customer_price,
           COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
           acp.custom_price AS agent_custom_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = ?
    LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = "customer"
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    WHERE dp.id = ? AND ' . $package_availability_sql . '
    LIMIT 1
');
$stmt->bind_param('sii', $customer_pricing_type, $agent_id, $package_id);
$stmt->execute();
$package = $stmt->get_result()->fetch_assoc();

if (!$package) {
    setFlashMessage('error', 'Selected package is currently out of stock or unavailable.');
    header('Location: ' . SITE_URL . '/store/index.php?store=' . urlencode($store_slug));
    exit();
}

function resolveCustomerStoreCheckoutView($network_name) {
    $normalized = strtolower(trim((string) $network_name));
    if (strpos($normalized, 'mtn') !== false) {
        return 'mtn';
    }
    if (strpos($normalized, 'telecel') !== false || strpos($normalized, 'vodafone') !== false) {
        return 'telecel';
    }
    if (strpos($normalized, 'at') !== false || strpos($normalized, 'airtel') !== false || strpos($normalized, 'tigo') !== false) {
        return 'at';
    }
    return '';
}

function resolveCustomerStoreCheckoutBrand($network_name) {
    $normalized = strtolower(trim((string) $network_name));
    if (strpos($normalized, 'mtn') !== false) {
        return [
            'label' => 'MTN',
            'accent' => '#f4c430',
            'accent_strong' => '#b7791f',
            'accent_soft' => '#fff6cf',
            'ink' => '#2b2000',
            'logo' => dbh_asset('assets/images/mtn-logo.svg'),
            'number_hint' => 'Use an MTN number like 0241234567',
            'placeholder' => '0241234567',
        ];
    }
    if (strpos($normalized, 'telecel') !== false || strpos($normalized, 'vodafone') !== false) {
        return [
            'label' => 'Telecel',
            'accent' => '#e11d48',
            'accent_strong' => '#be123c',
            'accent_soft' => '#ffe2e8',
            'ink' => '#ffffff',
            'logo' => dbh_asset('assets/images/telecel-logo.svg'),
            'number_hint' => 'Use a Telecel number like 0201234567',
            'placeholder' => '0201234567',
        ];
    }
    if (strpos($normalized, 'at') !== false || strpos($normalized, 'airtel') !== false || strpos($normalized, 'tigo') !== false) {
        return [
            'label' => 'AT',
            'accent' => '#1d4ed8',
            'accent_strong' => '#173fae',
            'accent_soft' => '#dbeafe',
            'ink' => '#ffffff',
            'logo' => dbh_asset('assets/images/at-logo.svg'),
            'number_hint' => 'Use an AT number like 0261234567',
            'placeholder' => '0261234567',
        ];
    }
    return [
        'label' => trim((string) $network_name) ?: 'Network',
        'accent' => '#0f766e',
        'accent_strong' => '#115e59',
        'accent_soft' => '#ccfbf1',
        'ink' => '#ffffff',
        'logo' => '',
        'number_hint' => 'Enter the recipient number to continue.',
        'placeholder' => '0241234567',
    ];
}

$brand = resolveCustomerStoreCheckoutBrand($package['network_name'] ?? '');
$view_key = resolveCustomerStoreCheckoutView($package['network_name'] ?? '');
$is_mtn_checkout = $view_key === 'mtn';
$back_url = SITE_URL . '/store/index.php?store=' . urlencode($store_slug) . ($view_key !== '' ? '&view=' . urlencode($view_key) : '');

$customer_email = trim((string) ($current_user['email'] ?? ($_SESSION['email'] ?? '')));
$paystack_direct_enabled = isPaymentGatewayEnabled('paystack');
$gateway_checkout_available = $paystack_direct_enabled && $customer_email !== '' && validateEmail($customer_email);
$payment_options = ['wallet'];
if ($gateway_checkout_available) {
    $payment_options[] = 'paystack';
}
$gateway_labels = [
    'wallet' => 'Wallet',
    'paystack' => 'Paystack',
];

$package_price = ($customer_pricing_type !== 'vip' && $package['agent_custom_price'] !== null)
    ? (float) $package['agent_custom_price']
    : (float) $package['customer_price'];
$checkout_available = true;
$default_payment_option = ($wallet_balance < $package_price && $gateway_checkout_available) ? 'paystack' : 'wallet';
$csrf_token = generateCSRF();
$order_submit_token = bin2hex(random_bytes(32));
$_SESSION['order_submit_token'] = $order_submit_token;
$package_title = trim((string) ($package['data_size'] ?? '')) !== '' ? trim((string) $package['data_size']) : trim((string) ($package['name'] ?? 'Selected package'));
$package_meta = trim((string) ($package['name'] ?? ''));
if ($package_meta === '' || strcasecmp($package_meta, $package_title) === 0) {
    $package_meta = 'Instant delivery after payment';
}
$page_title = htmlspecialchars(($brand['label'] ?? 'Data') . ' Checkout', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        :root {
            --checkout-accent: <?php echo htmlspecialchars($brand['accent']); ?>;
            --checkout-accent-strong: <?php echo htmlspecialchars($brand['accent_strong']); ?>;
            --checkout-accent-soft: <?php echo htmlspecialchars($brand['accent_soft']); ?>;
            --checkout-button-ink: <?php echo htmlspecialchars($brand['ink']); ?>;
            --checkout-bg: #eff4fb;
            --checkout-panel: rgba(255, 255, 255, 0.96);
            --checkout-border: rgba(148, 163, 184, 0.25);
            --checkout-text: #0f172a;
            --checkout-muted: #64748b;
        }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--checkout-text);
            background:
                radial-gradient(circle at top left, color-mix(in srgb, var(--checkout-accent) 18%, transparent), transparent 30%),
                linear-gradient(180deg, var(--checkout-bg) 0%, #f8fafc 100%);
        }
        .checkout-shell {
            max-width: 480px;
            margin: 0 auto;
            padding: 0.95rem 0.85rem 1.75rem;
        }
        .checkout-back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin-bottom: 0.9rem;
            color: #475569;
            font-size: 0.92rem;
            font-weight: 600;
            text-decoration: none;
        }
        .checkout-back-link:hover { color: var(--checkout-accent-strong); }
        .checkout-card {
            background: var(--checkout-panel);
            border: 1px solid var(--checkout-border);
            border-radius: 24px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.09);
            overflow: hidden;
        }
        .checkout-summary { padding: 0.95rem; border-bottom: 1px solid rgba(226, 232, 240, 0.92); }
        .checkout-kicker {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0.24rem 0.78rem;
            border-radius: 999px;
            background: var(--checkout-accent-soft);
            color: var(--checkout-accent-strong);
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .checkout-store { margin-top: 0.9rem; color: var(--checkout-muted); font-size: 0.84rem; font-weight: 700; }
        .checkout-hero {
            margin-top: 0.75rem;
            border-radius: 20px;
            padding: 0.75rem 0.85rem;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border: 1px solid rgba(226, 232, 240, 0.96);
            text-align: center;
        }
        .checkout-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 0.65rem;
            border-radius: 20px;
            background: var(--checkout-accent-soft);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }
        .checkout-logo img { width: 56px; height: 56px; object-fit: contain; }
        .checkout-brand {
            color: var(--checkout-accent-strong);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .checkout-title { margin: 0.2rem 0 0.15rem; font-size: 1.35rem; line-height: 1.02; color: #0f172a; }
        .checkout-meta { margin: 0; color: var(--checkout-muted); font-size: 0.88rem; line-height: 1.45; }
        .checkout-price { margin-top: 0.75rem; font-size: 1.55rem; font-weight: 800; letter-spacing: -0.03em; }
        .checkout-grid { margin-top: 0.75rem; display: grid; gap: 0.15rem; }
        .checkout-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.62rem 0.1rem;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.88rem;
        }
        .checkout-row:last-child { border-bottom: 0; }
        .checkout-row span { color: var(--checkout-muted); }
        .checkout-row strong { text-align: right; color: #0f172a; }
        .checkout-form { padding: 0.95rem; display: grid; gap: 0.9rem; }
        .checkout-form .form-label {
            display: inline-block;
            margin-bottom: 0.45rem;
            color: #0f172a;
            font-size: 0.9rem;
            font-weight: 700;
        }
        .checkout-form .form-control,
        .checkout-form select {
            width: 100%;
            min-height: 50px;
            padding: 0.72rem 0.9rem;
            border-radius: 14px;
            border: 1px solid #d8e0eb;
            background: #f8fafc;
            color: #0f172a;
        }
        .checkout-form .form-control:focus,
        .checkout-form select:focus {
            border-color: var(--checkout-accent);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--checkout-accent) 18%, transparent);
            background: #ffffff;
            outline: none;
        }
        .field-help { display: block; margin-top: 0.45rem; color: var(--checkout-muted); font-size: 0.82rem; line-height: 1.45; }
        .ported-mtn-confirm {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.7rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--checkout-text);
        }
        .ported-mtn-confirm input {
            width: 1rem;
            height: 1rem;
            flex: 0 0 auto;
        }
        .checkout-error {
            display: none;
            padding: 0.82rem 0.95rem;
            border-radius: 16px;
            border: 1px solid rgba(239, 68, 68, 0.2);
            background: rgba(254, 226, 226, 0.95);
            color: #b91c1c;
            font-size: 0.88rem;
            line-height: 1.45;
        }
        .payment-instructions {
            display: none;
            padding: 0.9rem 0.95rem;
            border-radius: 16px;
            border: 1px solid color-mix(in srgb, var(--checkout-accent) 18%, #cbd5e1);
            background: linear-gradient(180deg, #ffffff 0%, color-mix(in srgb, var(--checkout-accent-soft) 38%, #ffffff) 100%);
        }
        .payment-instructions.is-visible {
            display: block;
        }
        .payment-instructions h3 {
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
            color: var(--checkout-accent-strong);
        }
        .payment-instructions ol {
            margin: 0;
            padding-left: 1.05rem;
            color: #334155;
            font-size: 0.86rem;
            line-height: 1.55;
        }
        .payment-instructions li + li {
            margin-top: 0.3rem;
        }
        .checkout-submit {
            width: 100%;
            min-height: 54px;
            border: 0;
            border-radius: 16px;
            background: linear-gradient(180deg, var(--checkout-accent) 0%, var(--checkout-accent-strong) 100%);
            color: var(--checkout-button-ink);
            font-weight: 800;
            box-shadow: 0 18px 30px color-mix(in srgb, var(--checkout-accent-strong) 22%, transparent);
        }
        .checkout-submit:hover,
        .checkout-submit:focus-visible {
            color: var(--checkout-button-ink);
            transform: translateY(-1px);
        }
        .checkout-note { color: var(--checkout-muted); font-size: 0.84rem; line-height: 1.55; }
        @media (max-width: 640px) {
            .checkout-shell { padding: 1rem 0.85rem 2rem; }
            .checkout-card { border-radius: 22px; }
        }
    </style>
</head>
<body>
    <div class="checkout-shell">
        <a class="checkout-back-link" href="<?php echo htmlspecialchars($back_url); ?>">
            <i class="fas fa-arrow-left"></i>
            <span>Back to <?php echo htmlspecialchars($brand['label']); ?> packages</span>
        </a>

        <section class="checkout-card">
            <div class="checkout-summary">
                <span class="checkout-kicker">Store Checkout</span>
                <div class="checkout-store"><?php echo htmlspecialchars($store['store_name']); ?><?php if (!empty($store['agent_name'])): ?> by <?php echo htmlspecialchars($store['agent_name']); ?><?php endif; ?></div>

                <div class="checkout-hero">
                    <div class="checkout-logo">
                        <?php if (!empty($brand['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($brand['logo']); ?>" alt="<?php echo htmlspecialchars($brand['label']); ?> logo">
                        <?php else: ?>
                            <i class="fas fa-signal"></i>
                        <?php endif; ?>
                    </div>
                    <div class="checkout-brand"><?php echo htmlspecialchars($brand['label']); ?> Checkout</div>
                    <h1 class="checkout-title"><?php echo htmlspecialchars($package_title); ?></h1>
                    <p class="checkout-meta"><?php echo htmlspecialchars($package_meta); ?></p>
                    <div class="checkout-price">GHS <?php echo number_format($package_price, 2); ?></div>
                </div>

                <div class="checkout-grid">
                    <div class="checkout-row">
                        <span>Network</span>
                        <strong><?php echo htmlspecialchars($brand['label']); ?></strong>
                    </div>
                    <div class="checkout-row">
                        <span>Package</span>
                        <strong><?php echo htmlspecialchars($package_title); ?></strong>
                    </div>
                    <div class="checkout-row">
                        <span>Wallet Balance</span>
                        <strong><?php echo CURRENCY . ' ' . number_format((float) $wallet_balance, 2); ?></strong>
                    </div>
                </div>
            </div>

            <form class="checkout-form" id="customerStoreCheckoutForm">
                <input type="hidden" name="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
                <input type="hidden" name="agent_id" value="<?php echo (int) $agent_id; ?>">
                <input type="hidden" name="package_id" value="<?php echo (int) $package_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="order_submit_token" value="<?php echo htmlspecialchars($order_submit_token); ?>">

                <div class="checkout-error" id="checkoutError"></div>

                <div>
                    <label class="form-label" for="beneficiaryNumber">Recipient Number</label>
                    <input
                        type="tel"
                        class="form-control"
                        id="beneficiaryNumber"
                        name="beneficiary_number"
                        placeholder="<?php echo htmlspecialchars($brand['placeholder']); ?>"
                        pattern="[0-9]{10}"
                        required
                    >
                    <small class="field-help"><?php echo htmlspecialchars($brand['number_hint']); ?></small>
                    <?php if ($is_mtn_checkout): ?>
                        <label class="ported-mtn-confirm">
                            <input type="checkbox" name="allow_ported_mtn" value="1">
                            <span>This number has been ported to MTN</span>
                        </label>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="gatewaySelect">Payment Method</label>
                    <select id="gatewaySelect" name="gateway" class="form-control">
                        <?php foreach ($payment_options as $payment_option): ?>
                            <?php
                            $option_label = $gateway_labels[$payment_option] ?? ucfirst($payment_option);
                            if ($payment_option === 'wallet' && $wallet_balance < $package_price) {
                                $option_label .= ' (Low Balance)';
                            }
                            ?>
                            <option value="<?php echo htmlspecialchars($payment_option); ?>" <?php echo $payment_option === $default_payment_option ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-help" id="paymentMethodHelp">
                        <?php if ($wallet_balance >= $package_price): ?>
                            Wallet payment is available, or you can continue with secure online checkout.
                        <?php elseif ($gateway_checkout_available): ?>
                            Your wallet balance is below this package price. Choose Paystack or top up your wallet.
                        <?php else: ?>
                            Your wallet balance is below this package price. Top up your wallet to continue.
                        <?php endif; ?>
                    </small>
                </div>

                <div class="payment-instructions" id="paystackInstructions">
                    <h3>Payment Instructions</h3>
                    <ol>
                        <li>Approve the MoMo prompt on your phone when it appears or use My Approvals to approve the transaction when the prompt fails to appear.</li>
                        <li>After receiving the MoMo confirmation SMS, click “I have completed the payment”.</li>
                    </ol>
                </div>

                <button type="submit" class="btn checkout-submit" id="checkoutSubmitBtn" <?php echo $checkout_available ? '' : 'disabled'; ?>>
                    <i class="fas fa-credit-card"></i> Continue
                </button>

                <div class="checkout-note">
                    Enter the recipient number, then continue to payment to complete this transaction.
                </div>
            </form>
        </section>
    </div>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script>
        const checkoutForm = document.getElementById('customerStoreCheckoutForm');
        const checkoutError = document.getElementById('checkoutError');
        const checkoutSubmitBtn = document.getElementById('checkoutSubmitBtn');
        const beneficiaryInput = document.getElementById('beneficiaryNumber');
        const gatewaySelect = document.getElementById('gatewaySelect');
        const paymentMethodHelp = document.getElementById('paymentMethodHelp');
        const paystackInstructions = document.getElementById('paystackInstructions');
        const paystackEndpoint = <?php echo json_encode('../api/customer_bundle_paystack_init.php'); ?>;
        const walletCheckoutAction = <?php echo json_encode('buy-data.php?store=' . urlencode($store_slug)); ?>;
        const networkLabel = <?php echo json_encode(strtolower((string) ($brand['label'] ?? ''))); ?>;
        const walletBalance = <?php echo json_encode((float) $wallet_balance); ?>;
        const packagePrice = <?php echo json_encode((float) $package_price); ?>;
        const gatewayCheckoutAvailable = <?php echo $gateway_checkout_available ? 'true' : 'false'; ?>;
        const gatewayLabels = <?php echo json_encode($gateway_labels); ?>;

        function normalizeLocalPhone(value) {
            const digits = String(value || '').replace(/\D/g, '');
            if (digits.startsWith('233')) {
                return '0' + digits.slice(3);
            }
            return digits;
        }

        function isMtnLocalPhone(localPhone) {
            if (!/^\d{10}$/.test(localPhone)) return false;
            return ['024', '025', '053', '054', '055', '059'].indexOf(localPhone.slice(0, 3)) !== -1;
        }

        function isAtLocalPhone(localPhone) {
            if (!/^\d{10}$/.test(localPhone)) return false;
            return ['026', '027', '056', '057'].indexOf(localPhone.slice(0, 3)) !== -1;
        }

        function isTelecelLocalPhone(localPhone) {
            if (!/^\d{10}$/.test(localPhone)) return false;
            return ['020', '050'].indexOf(localPhone.slice(0, 3)) !== -1;
        }

        function validateBeneficiaryNumber() {
            if (!beneficiaryInput) return false;
            const localPhone = normalizeLocalPhone(beneficiaryInput.value);
            const allowPortedMtn = checkoutForm && checkoutForm.querySelector('input[name="allow_ported_mtn"]:checked');
            let valid = /^\d{10}$/.test(localPhone);
            let message = 'Please enter a valid 10-digit phone number.';

            if (networkLabel === 'mtn') {
                valid = isMtnLocalPhone(localPhone);
                if (!valid && allowPortedMtn && /^\d{10}$/.test(localPhone)) {
                    valid = true;
                }
                message = 'Please enter a valid MTN number (024/025/053/054/055/059), or confirm that this number has been ported to MTN.';
            } else if (networkLabel === 'at') {
                valid = isAtLocalPhone(localPhone);
                message = 'Please enter a valid AT number (026/027/056/057).';
            } else if (networkLabel === 'telecel') {
                valid = isTelecelLocalPhone(localPhone);
                message = 'Please enter a valid Telecel number (020/050).';
            }

            beneficiaryInput.setCustomValidity(valid ? '' : message);
            return valid;
        }

        function showCheckoutError(message) {
            if (!checkoutError) return;
            checkoutError.textContent = message;
            checkoutError.style.display = 'block';
        }

        function clearCheckoutError() {
            if (!checkoutError) return;
            checkoutError.textContent = '';
            checkoutError.style.display = 'none';
        }

        function updateCheckoutButtonState() {
            if (!checkoutSubmitBtn) return;
            const method = gatewaySelect && gatewaySelect.value ? String(gatewaySelect.value) : 'wallet';
            if (paystackInstructions) {
                paystackInstructions.classList.toggle('is-visible', method === 'paystack');
            }

            if (method === 'wallet') {
                checkoutSubmitBtn.innerHTML = '<i class="fas fa-wallet"></i> Pay From Wallet';
                if (paymentMethodHelp) {
                    paymentMethodHelp.textContent = walletBalance >= packagePrice
                        ? 'Wallet payment will use your current balance immediately.'
                        : 'Your wallet balance is below this package price. Top up your wallet or choose Paystack.';
                }
                return;
            }

            checkoutSubmitBtn.innerHTML = '<i class="fas fa-credit-card"></i> Proceed to Paystack';
            if (paymentMethodHelp) {
                paymentMethodHelp.textContent = 'You will be redirected to secure Paystack checkout to complete payment.';
            }
        }

        if (beneficiaryInput) {
            beneficiaryInput.addEventListener('input', function() {
                beneficiaryInput.setCustomValidity('');
                clearCheckoutError();
            });
        }

        if (gatewaySelect) {
            gatewaySelect.addEventListener('change', function() {
                clearCheckoutError();
                updateCheckoutButtonState();
            });
        }

        updateCheckoutButtonState();

        if (checkoutForm) {
            checkoutForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                clearCheckoutError();

                if (!validateBeneficiaryNumber()) {
                    beneficiaryInput.reportValidity();
                    return;
                }

                const formData = new FormData(checkoutForm);
                const payload = {
                    store_slug: formData.get('store_slug') || '',
                    agent_id: parseInt(formData.get('agent_id') || '0', 10),
                    package_id: formData.get('package_id') || '',
                    beneficiary_number: normalizeLocalPhone(formData.get('beneficiary_number') || ''),
                    allow_ported_mtn: formData.get('allow_ported_mtn') === '1' ? '1' : '0',
                    gateway: formData.get('gateway') || '',
                    csrf_token: formData.get('csrf_token') || ''
                };

                if (!payload.store_slug || !payload.package_id || !payload.beneficiary_number || !payload.gateway) {
                    showCheckoutError('Please fill in all required fields before continuing.');
                    return;
                }

                if (payload.gateway === 'wallet') {
                    if (walletBalance < packagePrice) {
                        showCheckoutError('Insufficient wallet balance. Top up your wallet or choose Paystack.');
                        return;
                    }

                    checkoutSubmitBtn.disabled = true;
                    checkoutSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    checkoutForm.action = walletCheckoutAction;
                    checkoutForm.method = 'post';
                    HTMLFormElement.prototype.submit.call(checkoutForm);
                    return;
                }

                if (!gatewayCheckoutAvailable) {
                    showCheckoutError('Paystack checkout is not available right now.');
                    return;
                }

                checkoutSubmitBtn.disabled = true;
                checkoutSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Redirecting...';
                if (gatewaySelect) {
                    gatewaySelect.disabled = true;
                }

                try {
                    const response = await fetch(paystackEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': payload.csrf_token
                        },
                        body: JSON.stringify(payload)
                    });
                    const data = await response.json().catch(() => null);

                    if (!response.ok || !data || data.status !== 'success' || !data.data || !data.data.authorization_url) {
                        throw new Error((data && data.message) ? data.message : 'Unable to start checkout right now.');
                    }

                    window.location.href = data.data.authorization_url;
                } catch (error) {
                    showCheckoutError(error && error.message ? error.message : 'Unable to start checkout right now.');
                    checkoutSubmitBtn.disabled = false;
                    if (gatewaySelect) {
                        gatewaySelect.disabled = false;
                    }
                    updateCheckoutButtonState();
                }
            });
        }
    </script>
</body>
</html>
