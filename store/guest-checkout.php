<?php
require_once __DIR__ . '/../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();
ensureDataPackageStockStatusColumn();

if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$selected_package_id = (int) ($_GET['package_id'] ?? $_POST['package_id'] ?? 0);

if (isLoggedIn() && isset($_SESSION['user_role']) && isCustomerAccountRole($_SESSION['user_role'])) {
    $customer_checkout_url = SITE_URL . '/customer/store-checkout.php?store=' . urlencode($store_slug);
    if ($selected_package_id > 0) {
        $customer_checkout_url .= '&package_id=' . $selected_package_id;
    }
    header('Location: ' . $customer_checkout_url);
    exit();
}

// Fetch store + agent info for branding (cache in session for faster reloads)
$store = null;
if (isset($_SESSION['store_cache'][$store_slug])) {
    $cached = $_SESSION['store_cache'][$store_slug];
    if (is_array($cached) && !empty($cached['data']) && !empty($cached['ts']) && (time() - (int) $cached['ts']) < 300) {
        $store = $cached['data'];
    }
}

if (!$store) {
    $stmt = $db->prepare("
        SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        header('HTTP/1.0 404 Not Found');
        include '../404.php';
        exit();
    }
    $store = $res->fetch_assoc();
    if (!isset($_SESSION['store_cache'])) {
        $_SESSION['store_cache'] = [];
    }
    $_SESSION['store_cache'][$store_slug] = [
        'data' => $store,
        'ts' => time()
    ];
}
$agent_id = (int) $store['agent_id'];
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
$guest_init_endpoints = [
    'paystack' => '../api/guest_paystack_init.php',
    'moolre' => '../api/guest_moolre_init.php'
];
$has_gateway_choice = count($enabled_gateways) > 1;
$gateway_label = $gateway_labels[$active_gateway] ?? ucfirst($active_gateway);

// Load packages for guest checkout
$packages = [];
$stmt = $db->prepare("
    SELECT dp.id, dp.name, dp.data_size,
           COALESCE(n.name, 'Unknown') AS network_name,
           COALESCE(dp.stock_status, 'in_stock') AS stock_status,
           COALESCE(acp.custom_price, pp.price, dp.price, 0) AS display_price
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    WHERE dp.status = 'active' AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    ORDER BY n.name
");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $packages[] = $row;
}

// Sort packages using PHP comparison function to avoid database engine differences
if (function_exists('dbh_compare_packages')) {
    usort($packages, 'dbh_compare_packages');
}

$selected_package = null;
$selected_network_name = '';
$available_networks = [];
$guest_package_catalog = [];

foreach ($packages as $package) {
    $network_name = (string) ($package['network_name'] ?? 'Unknown');
    if (!in_array($network_name, $available_networks, true)) {
        $available_networks[] = $network_name;
    }

    $package_id = (int) ($package['id'] ?? 0);
    if ($selected_package_id === $package_id) {
        $selected_network_name = $network_name;
        $selected_package = $package;
    }

    $guest_package_catalog[] = [
        'id' => $package_id,
        'network_name' => $network_name,
        'name' => (string) ($package['name'] ?? ''),
        'data_size' => (string) ($package['data_size'] ?? ''),
        'display_price' => (float) ($package['display_price'] ?? 0),
        'label' => $network_name . ' - ' . (string) ($package['data_size'] ?? '') . ' (' . formatCurrency((float) ($package['display_price'] ?? 0)) . ')'
    ];
}

sort($available_networks, SORT_NATURAL | SORT_FLAG_CASE);

function resolveGuestNetworkBranding($network_name)
{
    $normalized = strtolower(trim((string) $network_name));
    if (strpos($normalized, 'mtn') !== false) {
        return [
            'label' => 'MTN',
            'icon' => 'fa-signal',
            'color' => '#f4c430',
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
            'icon' => 'fa-broadcast-tower',
            'color' => '#e11d48',
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
            'icon' => 'fa-sim-card',
            'color' => '#1d4ed8',
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
        'icon' => 'fa-globe',
        'color' => '#0f766e',
        'accent_strong' => '#115e59',
        'accent_soft' => '#ccfbf1',
        'ink' => '#ffffff',
        'logo' => '',
        'number_hint' => 'Enter the recipient number to continue.',
        'placeholder' => '0241234567',
    ];
}

function buildGuestNetworkBadgeMarkup($network_name)
{
    $brand = resolveGuestNetworkBranding($network_name);
    $color = htmlspecialchars((string) ($brand['color'] ?? '#6366f1'), ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars((string) ($brand['icon'] ?? 'fa-globe'), ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars((string) ($brand['label'] ?? $network_name ?: 'Network'), ENT_QUOTES, 'UTF-8');

    return '<span class="network-badge-inline" style="--network-color: ' . $color . ';">'
        . '<span class="network-dot"></span>'
        . '<i class="fas ' . $icon . '"></i>'
        . '<span>' . $label . '</span>'
        . '</span>';
}

$guest_network_branding_map = [];
foreach ($available_networks as $network_name) {
    $guest_network_branding_map[$network_name] = resolveGuestNetworkBranding($network_name);
}

$selected_brand = $selected_network_name !== ''
    ? ($guest_network_branding_map[$selected_network_name] ?? resolveGuestNetworkBranding($selected_network_name))
    : resolveGuestNetworkBranding('');
$selected_package_title = 'Selected package';
$selected_package_meta = 'Choose your network and bundle to continue with secure guest checkout.';
$selected_package_price = null;
if ($selected_package) {
    $selected_package_title = trim((string) ($selected_package['data_size'] ?? '')) !== ''
        ? trim((string) $selected_package['data_size'])
        : trim((string) ($selected_package['name'] ?? 'Selected package'));
    $selected_package_meta = trim((string) ($selected_package['name'] ?? ''));
    if ($selected_package_meta === '' || strcasecmp($selected_package_meta, $selected_package_title) === 0) {
        $selected_package_meta = 'Instant delivery after secure payment.';
    }
    $selected_package_price = (float) ($selected_package['display_price'] ?? 0);
}
$lock_package_selection = $selected_package !== null;
$guest_page_title = htmlspecialchars(($selected_brand['label'] ?? 'Guest') . ' Guest Checkout', ENT_QUOTES, 'UTF-8');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Please use the checkout button to complete payment.';
}

$flash = getFlashMessage();
if ($flash && isset($flash['message'])) {
    $flash_type = strtolower(trim((string) ($flash['type'] ?? 'info')));
    if (in_array($flash_type, ['danger', 'error'], true)) {
        $flash_type = 'danger';
    } elseif (!in_array($flash_type, ['success', 'warning', 'info'], true)) {
        $flash_type = 'info';
    }
} else {
    $flash = null;
    $flash_type = 'info';
}

$guest_checkout_csrf = generateCSRF();
?>
<?php
$page_title = $selected_brand['label'] . ' Guest Checkout';
require_once __DIR__ . '/includes/header.php';
?>
    <style>
        :root {
            --checkout-accent: <?php echo htmlspecialchars($selected_brand['color']); ?>;
            --checkout-accent-strong: <?php echo htmlspecialchars($selected_brand['accent_strong']); ?>;
            --checkout-accent-soft: <?php echo htmlspecialchars($selected_brand['accent_soft']); ?>;
            --checkout-button-ink: <?php echo htmlspecialchars($selected_brand['ink']); ?>;
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
        .checkout-shell,
        .guest-shell {
            max-width: 520px;
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
        .checkout-card,
        .guest-card {
            background: var(--checkout-panel);
            border: 1px solid var(--checkout-border);
            border-radius: 24px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.09);
            overflow: hidden;
        }
        .guest-layout { display: block; }
        .checkout-summary,
        .guest-overview {
            padding: 0.95rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.92);
        }
        .guest-form-wrap {
            padding: 0.95rem;
            display: grid;
            gap: 0.9rem;
        }
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
        .checkout-form,
        .guest-form {
            padding: 0.95rem;
            display: grid;
            gap: 0.9rem;
        }
        .guest-form[style*="padding:0"] {
            padding: 0 !important;
        }
        .checkout-form .form-label,
        .guest-form .form-label {
            display: inline-block;
            margin-bottom: 0.45rem;
            color: #0f172a;
            font-size: 0.9rem;
            font-weight: 700;
        }
        .checkout-form .form-control,
        .checkout-form select,
        .guest-form .form-control,
        .guest-form select {
            width: 100%;
            min-height: 50px;
            padding: 0.72rem 0.9rem;
            border-radius: 14px;
            border: 1px solid #d8e0eb;
            background: #f8fafc;
            color: #0f172a;
        }
        .checkout-form .form-control:focus,
        .checkout-form select:focus,
        .guest-form .form-control:focus,
        .guest-form select:focus {
            border-color: var(--checkout-accent);
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--checkout-accent) 18%, transparent);
            background: #ffffff;
            outline: none;
        }
        .field-help { display: block; margin-top: 0.45rem; color: var(--checkout-muted); font-size: 0.82rem; line-height: 1.45; }
        .ported-mtn-confirm {
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
        .network-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
            margin-top: 0.75rem;
        }
        .network-chip {
            border: 1px solid var(--checkout-border);
            background: rgba(148, 163, 184, 0.1);
            color: var(--checkout-text);
            border-radius: 999px;
            padding: 0.3rem 0.7rem;
            font-size: 0.83rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }
        .network-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.14);
        }
        .network-chip.active {
            border-color: var(--network-color, var(--checkout-accent));
            background: color-mix(in srgb, var(--network-color, var(--checkout-accent)) 16%, transparent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--network-color, var(--checkout-accent)) 24%, transparent);
        }
        .network-chip-icon {
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.74rem;
            color: #fff;
            background: var(--network-color, #475569);
        }
        .network-chip-label {
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .network-selected-badge {
            margin-top: 0.65rem;
            min-height: 1.6rem;
            display: flex;
            align-items: center;
        }
        .network-badge-inline {
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            border-radius: 999px;
            padding: 0.24rem 0.62rem;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid var(--checkout-border);
            background: rgba(148, 163, 184, 0.12);
            color: var(--checkout-text);
        }
        .network-badge-inline .network-dot {
            width: 0.58rem;
            height: 0.58rem;
            border-radius: 999px;
            background: var(--network-color, #475569);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.32);
        }
        .payment-instructions {
            display: none;
            padding: 0.9rem 0.95rem;
            border-radius: 16px;
            border: 1px solid color-mix(in srgb, var(--checkout-accent) 18%, #cbd5e1);
            background: linear-gradient(180deg, #ffffff 0%, color-mix(in srgb, var(--checkout-accent-soft) 38%, #ffffff) 100%);
        }
        .payment-instructions.is-visible { display: block; }
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
        .payment-instructions li + li { margin-top: 0.3rem; }
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
        .checkout-actions,
        .guest-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .checkout-actions .btn,
        .guest-actions .btn {
            flex: 1 1 180px;
            justify-content: center;
        }
        .checkout-actions .btn.btn-outline,
        .guest-actions .btn.btn-outline {
            color: var(--checkout-text);
            border-color: var(--checkout-border);
            background: rgba(148, 163, 184, 0.12);
        }
        .checkout-secondary {
            border-top: 1px solid rgba(226, 232, 240, 0.92);
            padding: 0.95rem;
            display: grid;
            gap: 0.9rem;
        }
        .guest-form-wrap hr {
            margin: 0.25rem 0;
            border: none;
            border-top: 1px solid rgba(226, 232, 240, 0.92);
        }
        .checkout-secondary h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }
        .checkout-secondary p {
            margin: 0;
            color: var(--checkout-muted);
            font-size: 0.86rem;
            line-height: 1.55;
        }
        .checkout-secondary .alert {
            margin: 0;
            border-radius: 14px;
        }
        @media (max-width: 640px) {
            .checkout-shell { padding: 1rem 0.85rem 2rem; }
            .checkout-card { border-radius: 22px; }
        }
    </style>
    <div class="checkout-shell">
        <a class="checkout-back-link" href="index.php?store=<?php echo urlencode($store_slug); ?>">
            <i class="fas fa-arrow-left"></i>
            <span>Back to store packages</span>
        </a>
        <section class="checkout-card">
            <div class="guest-layout">
                <div class="checkout-summary guest-overview">
                    <span class="checkout-kicker">Guest Checkout</span>
                    <div class="checkout-store"><?php echo htmlspecialchars($store['store_name']); ?><?php if (!empty($store['agent_name'])): ?> by <?php echo htmlspecialchars($store['agent_name']); ?><?php endif; ?></div>

                    <div class="checkout-hero">
                        <div class="checkout-logo" id="guestSummaryLogo">
                            <?php if (!empty($selected_brand['logo'])): ?>
                                <img src="<?php echo htmlspecialchars($selected_brand['logo']); ?>" alt="<?php echo htmlspecialchars($selected_brand['label']); ?> logo">
                            <?php else: ?>
                                <i class="fas <?php echo htmlspecialchars($selected_brand['icon']); ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="checkout-brand" id="guestSummaryBrand"><?php echo htmlspecialchars($selected_brand['label']); ?> Guest Checkout</div>
                        <h1 class="checkout-title" id="guestSummaryTitle"><?php echo htmlspecialchars($selected_package_title); ?></h1>
                        <p class="checkout-meta" id="guestSummaryMeta"><?php echo htmlspecialchars($selected_package_meta); ?></p>
                        <div class="checkout-price" id="guestSummaryPrice"><?php echo $selected_package_price !== null ? 'GHS ' . number_format($selected_package_price, 2) : 'Choose bundle'; ?></div>
                    </div>

                </div>

                <div class="guest-form-wrap">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flash_type); ?>">
                            <?php echo htmlspecialchars((string) $flash['message']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="checkout-error" id="guestPaystackError"></div>

                    <form method="post" class="checkout-form" id="guestPaystackForm">
                        <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                        <?php if ($lock_package_selection): ?>
                            <input type="hidden" name="guest_network" value="<?php echo htmlspecialchars($selected_network_name); ?>">
                            <input type="hidden" name="package_id" value="<?php echo (int) $selected_package_id; ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label" for="guestPhoneInput">Recipient Number</label>
                            <input type="tel" id="guestPhoneInput" name="phone" class="form-control" placeholder="<?php echo htmlspecialchars($selected_brand['placeholder']); ?>" required>
                            <small class="field-help" id="guestNumberHint"><?php echo htmlspecialchars($selected_brand['number_hint']); ?></small>
                            <label class="ported-mtn-confirm" id="guestPortedMtnConfirm" style="display:none;">
                                <input type="checkbox" name="allow_ported_mtn" value="1">
                                <span>This number has been ported to MTN</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="guestEmailInput">Email Address</label>
                            <input type="email" id="guestEmailInput" name="email" class="form-control" placeholder="name@example.com" required>
                            <small class="field-help">Use a valid email. It will help verify your payment if the order does not go through.</small>
                        </div>

                        <?php if (!$lock_package_selection): ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="guestNetworkSelect">Data Network</label>
                                    <select name="guest_network" id="guestNetworkSelect" class="form-control" required>
                                        <option value="">Choose network</option>
                                        <?php foreach ($available_networks as $network_name): ?>
                                            <option value="<?php echo htmlspecialchars($network_name); ?>" <?php echo $selected_network_name === $network_name ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($network_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="network-chip-list" id="guestNetworkChips">
                                        <?php foreach ($available_networks as $network_name): ?>
                                            <?php $network_branding = $guest_network_branding_map[$network_name] ?? resolveGuestNetworkBranding($network_name); ?>
                                            <button
                                                type="button"
                                                class="network-chip<?php echo $selected_network_name === $network_name ? ' active' : ''; ?>"
                                                data-network="<?php echo htmlspecialchars($network_name); ?>"
                                                style="--network-color: <?php echo htmlspecialchars($network_branding['color']); ?>;"
                                            >
                                                <span class="network-chip-icon" style="--network-color: <?php echo htmlspecialchars($network_branding['color']); ?>;">
                                                    <i class="fas <?php echo htmlspecialchars($network_branding['icon']); ?>"></i>
                                                </span>
                                                <span class="network-chip-label"><?php echo htmlspecialchars($network_branding['label']); ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="network-selected-badge" id="guestSelectedNetworkBadge"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="guestPackageSelect">Data Package</label>
                                    <select name="package_id" id="guestPackageSelect" class="form-control" required disabled>
                                        <option value="">Choose a network first</option>
                                    </select>
                                    <small class="field-help" id="guestPackageHelp">Select a network to view available bundles.</small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label" for="guestGatewaySelect">Payment Gateway</label>
                            <select name="payment_gateway" id="guestGatewaySelect" class="form-control" required>
                                <?php foreach ($enabled_gateways as $gateway): ?>
                                    <option value="<?php echo htmlspecialchars($gateway); ?>" <?php echo $gateway === $active_gateway ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($gateway_labels[$gateway] ?? ucfirst($gateway)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($has_gateway_choice): ?>
                                <small class="field-help" id="guestGatewayHelp">Choose your preferred payment provider.</small>
                            <?php else: ?>
                                <small class="field-help" id="guestGatewayHelp">Only one gateway is enabled by admin settings.</small>
                            <?php endif; ?>
                        </div>

                        <div class="payment-instructions" id="guestPaystackInstructions">
                            <h3>Payment Instructions</h3>
                            <ol>
                                <li>Approve the MoMo prompt on your phone when it appears or use My Approvals to approve the transaction when the prompt fails to appear.</li>
                                <li>After receiving the MoMo confirmation SMS, click "I have completed the payment".</li>
                            </ol>
                        </div>

                        <button type="submit" class="btn checkout-submit" id="guestPaystackBtn">
                            <i class="fas fa-credit-card"></i> Pay with <?php echo htmlspecialchars($gateway_label); ?>
                        </button>

                        <div class="checkout-note">
                            Enter the recipient number and continue to payment. You can track the order later using the phone number or order reference.
                        </div>
                    </form>

                </div>
            </div>
        </section>
    </div>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script>
        const guestForm = document.getElementById('guestPaystackForm');
        const guestBtn = document.getElementById('guestPaystackBtn');
        const guestError = document.getElementById('guestPaystackError');
        const guestEmailInput = document.getElementById('guestEmailInput');
        const guestNetworkSelect = document.getElementById('guestNetworkSelect');
        const guestPackageSelect = document.getElementById('guestPackageSelect');
        const guestPackageHelp = document.getElementById('guestPackageHelp');
        const guestNetworkChips = Array.from(document.querySelectorAll('.network-chip'));
        const guestSelectedNetworkBadge = document.getElementById('guestSelectedNetworkBadge');
        const guestGatewaySelect = document.getElementById('guestGatewaySelect');
        const guestPaystackInstructions = document.getElementById('guestPaystackInstructions');
        const guestGatewayHelp = document.getElementById('guestGatewayHelp');
        const guestPhoneInput = document.getElementById('guestPhoneInput');
        const guestPortedMtnConfirm = document.getElementById('guestPortedMtnConfirm');
        const guestSummaryLogo = document.getElementById('guestSummaryLogo');
        const guestSummaryBrand = document.getElementById('guestSummaryBrand');
        const guestSummaryTitle = document.getElementById('guestSummaryTitle');
        const guestSummaryMeta = document.getElementById('guestSummaryMeta');
        const guestSummaryPrice = document.getElementById('guestSummaryPrice');
        const guestCheckoutCsrf = <?php echo json_encode($guest_checkout_csrf); ?>;
        const guestPackageCatalog = <?php echo json_encode($guest_package_catalog); ?>;
        const guestNetworkBranding = <?php echo json_encode($guest_network_branding_map); ?>;
        const guestGatewayLabels = <?php echo json_encode($gateway_labels); ?>;
        const guestGatewayEndpoints = <?php echo json_encode($guest_init_endpoints); ?>;
        const fallbackGuestGateway = <?php echo json_encode($active_gateway); ?>;
        const preselectedGuestPackageId = <?php echo (int) $selected_package_id; ?>;
        const preselectedGuestNetwork = <?php echo json_encode($selected_network_name); ?>;
        const guestCheckoutStorageKey = 'guestPendingCheckout:' + <?php echo json_encode($store_slug); ?>;
        const guestSavedEmailKey = 'guestCheckoutEmail:' + <?php echo json_encode($store_slug); ?>;

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
                        background: #fff;
                        border: 1px solid rgba(148, 163, 184, 0.35);
                        border-radius: 14px;
                        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
                        color: #111827;
                        overflow: hidden;
                    }
                    .order-confirm-header {
                        padding: 1rem 1.2rem 0.5rem;
                        font-weight: 700;
                        font-size: 1.05rem;
                    }
                    .order-confirm-subtitle {
                        padding: 0 1.2rem;
                        color: #6b7280;
                        font-size: 0.9rem;
                    }
                    .order-confirm-details {
                        margin: 0.9rem 1.2rem 0;
                        border: 1px solid #e5e7eb;
                        border-radius: 10px;
                        overflow: hidden;
                    }
                    .order-confirm-row {
                        display: flex;
                        justify-content: space-between;
                        gap: 1rem;
                        padding: 0.7rem 0.85rem;
                        border-bottom: 1px solid #e5e7eb;
                        font-size: 0.92rem;
                    }
                    .order-confirm-row:last-child { border-bottom: none; }
                    .order-confirm-row span:first-child { color: #6b7280; }
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
                    <div class="order-confirm-subtitle" id="orderConfirmSubtitle">Review details before continuing.</div>
                    <div class="order-confirm-details" id="orderConfirmDetails"></div>
                    <div class="order-confirm-actions">
                        <button type="button" class="btn btn-outline" id="orderConfirmCancelBtn">Cancel</button>
                        <button type="button" class="btn btn-primary" id="orderConfirmOkBtn">Continue</button>
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
                state.title.textContent = config.title || 'Confirm Order';
                state.subtitle.textContent = config.subtitle || 'Review details before continuing.';
                state.okBtn.textContent = config.confirmText || 'Continue';
                state.cancelBtn.textContent = config.cancelText || 'Cancel';
                state.details.innerHTML = '';

                (config.details || []).forEach(function(item) {
                    const row = document.createElement('div');
                    row.className = 'order-confirm-row';
                    const label = document.createElement('span');
                    label.textContent = item.label || '';
                    const value = document.createElement('span');
                    if (item && item.isHtml) {
                        value.innerHTML = item.value || '';
                    } else {
                        value.textContent = item.value || '';
                    }
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

        function showGuestError(message) {
            if (!guestError) return;
            guestError.textContent = message;
            guestError.style.display = 'block';
        }

        function escapeGuestHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getGuestNetworkBrand(networkName) {
            const key = String(networkName || '');
            if (guestNetworkBranding && guestNetworkBranding[key]) {
                return guestNetworkBranding[key];
            }
            return { label: key || 'Network', icon: 'fa-globe', color: '#6366f1' };
        }

        function buildGuestNetworkBadgeHtml(networkName) {
            const brand = getGuestNetworkBrand(networkName);
            const label = escapeGuestHtml(brand.label || networkName || 'Network');
            const icon = escapeGuestHtml(brand.icon || 'fa-globe');
            const color = escapeGuestHtml(brand.color || '#6366f1');
            return '<span class="network-badge-inline" style="--network-color: ' + color + ';">'
                + '<span class="network-dot"></span>'
                + '<i class="fas ' + icon + '"></i>'
                + '<span>' + label + '</span>'
                + '</span>';
        }

        function formatGuestCurrency(value) {
            const amount = Number(value || 0);
            return 'GHS ' + amount.toFixed(2);
        }

        function getGuestPackageDetails(packageId) {
            const targetId = String(packageId || '').trim();
            if (!targetId) {
                return null;
            }
            return (Array.isArray(guestPackageCatalog) ? guestPackageCatalog : []).find(function(pkg) {
                return String(pkg.id || '') === targetId;
            }) || null;
        }

        function updateGuestCheckoutTheme(brand) {
            const root = document.documentElement;
            const accent = String((brand && brand.color) || '#0f766e');
            const strong = String((brand && brand.accent_strong) || '#115e59');
            const soft = String((brand && brand.accent_soft) || '#ccfbf1');
            const ink = String((brand && brand.ink) || '#ffffff');
            root.style.setProperty('--checkout-accent', accent);
            root.style.setProperty('--checkout-accent-strong', strong);
            root.style.setProperty('--checkout-accent-soft', soft);
            root.style.setProperty('--checkout-button-ink', ink);
        }

        function updateGuestSummary(networkName, packageId) {
            const brand = getGuestNetworkBrand(networkName);
            const pkg = getGuestPackageDetails(packageId);
            const title = pkg
                ? String(pkg.data_size || pkg.name || 'Selected package').trim()
                : 'Selected package';
            const packageMeta = pkg && pkg.name && pkg.name.toLowerCase() !== title.toLowerCase()
                ? pkg.name
                : (pkg ? 'Instant delivery after secure payment.' : 'Choose your network and bundle to continue with secure guest checkout.');

            updateGuestCheckoutTheme(brand);

            if (guestSummaryBrand) {
                guestSummaryBrand.textContent = String((brand && brand.label) || 'Network') + ' Guest Checkout';
            }
            if (guestSummaryTitle) {
                guestSummaryTitle.textContent = title;
            }
            if (guestSummaryMeta) {
                guestSummaryMeta.textContent = packageMeta;
            }
            if (guestSummaryPrice) {
                guestSummaryPrice.textContent = pkg ? formatGuestCurrency(pkg.display_price) : 'Choose bundle';
            }
            if (guestPhoneInput) {
                guestPhoneInput.placeholder = String((brand && brand.placeholder) || '0241234567');
            }
            if (document.getElementById('guestNumberHint')) {
                document.getElementById('guestNumberHint').textContent = String((brand && brand.number_hint) || 'Enter the recipient number to continue.');
            }
            const isMtnNetwork = String(networkName || '').toLowerCase().indexOf('mtn') !== -1;
            if (guestPortedMtnConfirm) {
                guestPortedMtnConfirm.style.display = isMtnNetwork ? 'flex' : 'none';
                if (!isMtnNetwork) {
                    const checkbox = guestPortedMtnConfirm.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = false;
                }
            }
            if (guestSummaryLogo) {
                if (brand && brand.logo) {
                    guestSummaryLogo.innerHTML = '<img src="' + escapeGuestHtml(brand.logo) + '" alt="' + escapeGuestHtml((brand.label || 'Network') + ' logo') + '">';
                } else {
                    guestSummaryLogo.innerHTML = '<i class="fas ' + escapeGuestHtml((brand && brand.icon) || 'fa-globe') + '"></i>';
                }
            }
        }

        function syncGuestNetworkVisuals(networkName) {
            const selected = String(networkName || '');
            guestNetworkChips.forEach(function(chip) {
                if (!chip || !chip.dataset) return;
                chip.classList.toggle('active', chip.dataset.network === selected);
            });
            if (guestSelectedNetworkBadge) {
                guestSelectedNetworkBadge.innerHTML = selected ? buildGuestNetworkBadgeHtml(selected) : '';
            }
        }

        function clearGuestError() {
            if (!guestError) return;
            guestError.textContent = '';
            guestError.style.display = 'none';
        }

        function readGuestCheckoutContext() {
            try {
                const raw = sessionStorage.getItem(guestCheckoutStorageKey);
                if (!raw) {
                    return null;
                }
                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : null;
            } catch (error) {
                return null;
            }
        }

        function writeGuestCheckoutContext(context) {
            try {
                sessionStorage.setItem(guestCheckoutStorageKey, JSON.stringify(context || {}));
            } catch (error) {
                // ignore storage failures
            }
        }

        function updateGuestCheckoutContext(patch) {
            const existing = readGuestCheckoutContext() || {};
            writeGuestCheckoutContext(Object.assign({}, existing, patch || {}));
        }

        function readSavedGuestEmail() {
            try {
                return String(localStorage.getItem(guestSavedEmailKey) || '').trim();
            } catch (error) {
                return '';
            }
        }

        function writeSavedGuestEmail(email) {
            const value = String(email || '').trim();
            if (!value) return;
            try {
                localStorage.setItem(guestSavedEmailKey, value);
            } catch (error) {
                // Ignore storage failures.
            }
        }

        function prefillGuestEmail() {
            if (!guestEmailInput || guestEmailInput.value) return;
            const context = readGuestCheckoutContext();
            const savedEmail = (context && context.email) ? String(context.email).trim() : readSavedGuestEmail();
            if (savedEmail) {
                guestEmailInput.value = savedEmail;
            }
        }

        function getSelectedGuestGateway() {
            if (guestGatewaySelect && guestGatewaySelect.value) {
                return String(guestGatewaySelect.value);
            }
            return String(fallbackGuestGateway || 'paystack');
        }

        function getGuestGatewayLabel(gateway) {
            const key = String(gateway || '');
            if (guestGatewayLabels && guestGatewayLabels[key]) {
                return String(guestGatewayLabels[key]);
            }
            return key ? key.charAt(0).toUpperCase() + key.slice(1) : 'Gateway';
        }

        function updateGuestGatewayButtonLabel() {
            if (!guestBtn) return;
            const gateway = getSelectedGuestGateway();
            const label = getGuestGatewayLabel(gateway);
            guestBtn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with ' + label;
            if (guestGatewayHelp) {
                guestGatewayHelp.textContent = gateway === 'paystack'
                    ? 'You will be redirected to secure Paystack checkout to complete payment.'
                    : 'Complete payment securely with ' + label + '.';
            }
            if (guestPaystackInstructions) {
                guestPaystackInstructions.classList.toggle('is-visible', gateway === 'paystack');
            }
        }

        function renderGuestPackages(networkName, preferredPackageId) {
            if (!guestPackageSelect) return;

            const selectedNetwork = String(networkName || '').trim();
            const catalog = Array.isArray(guestPackageCatalog) ? guestPackageCatalog : [];
            syncGuestNetworkVisuals(selectedNetwork);
            guestPackageSelect.innerHTML = '';

            const placeholderOption = document.createElement('option');
            placeholderOption.value = '';

            if (!selectedNetwork) {
                placeholderOption.textContent = 'Choose a network first';
                guestPackageSelect.appendChild(placeholderOption);
                guestPackageSelect.disabled = true;
                if (guestPackageHelp) {
                    guestPackageHelp.textContent = 'Select a network to view available bundles.';
                }
                updateGuestSummary('', '');
                return;
            }

            const filteredPackages = catalog.filter(function(pkg) {
                return String(pkg.network_name || '') === selectedNetwork;
            });

            if (filteredPackages.length === 0) {
                placeholderOption.textContent = 'No bundles available for selected network';
                guestPackageSelect.appendChild(placeholderOption);
                guestPackageSelect.disabled = true;
                if (guestPackageHelp) {
                    guestPackageHelp.textContent = 'No active package found for ' + selectedNetwork + '.';
                }
                updateGuestSummary(selectedNetwork, '');
                return;
            }

            placeholderOption.textContent = 'Choose a bundle';
            guestPackageSelect.appendChild(placeholderOption);
            filteredPackages.forEach(function(pkg) {
                const option = document.createElement('option');
                option.value = String(pkg.id || '');
                option.textContent = String(pkg.label || '');
                guestPackageSelect.appendChild(option);
            });
            guestPackageSelect.disabled = false;

            const preferredId = String(preferredPackageId || '').trim();
            if (preferredId && filteredPackages.some(function(pkg) { return String(pkg.id) === preferredId; })) {
                guestPackageSelect.value = preferredId;
            }

            if (guestPackageHelp) {
                guestPackageHelp.textContent = filteredPackages.length + ' bundle(s) available for ' + selectedNetwork + '.';
            }
            updateGuestSummary(selectedNetwork, guestPackageSelect.value || '');
        }

        function initializeGuestNetworkPicker() {
            if (!guestNetworkSelect || !guestPackageSelect) return;

            const initialNetwork = guestNetworkSelect.value || preselectedGuestNetwork || '';
            if (initialNetwork) {
                guestNetworkSelect.value = initialNetwork;
                renderGuestPackages(initialNetwork, preselectedGuestPackageId);
            } else {
                renderGuestPackages('', '');
            }

            guestNetworkSelect.addEventListener('change', function() {
                renderGuestPackages(guestNetworkSelect.value, '');
                clearGuestError();
            });

            guestPackageSelect.addEventListener('change', function() {
                updateGuestSummary(guestNetworkSelect.value, guestPackageSelect.value);
                clearGuestError();
            });
            guestNetworkChips.forEach(function(chip) {
                chip.addEventListener('click', function() {
                    if (!chip.dataset || !chip.dataset.network) return;
                    guestNetworkSelect.value = chip.dataset.network;
                    renderGuestPackages(guestNetworkSelect.value, '');
                    clearGuestError();
                });
            });
        }

        initializeGuestNetworkPicker();
        if (guestGatewaySelect) {
            guestGatewaySelect.addEventListener('change', function() {
                updateGuestGatewayButtonLabel();
                clearGuestError();
            });
        }
        updateGuestGatewayButtonLabel();
        prefillGuestEmail();

        if (guestForm) {
            guestForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                if (!guestBtn) return;

                const formData = new FormData(guestForm);
                const payload = {
                    store_slug: '<?php echo htmlspecialchars($store_slug); ?>',
                    phone: formData.get('phone') || '',
                    email: formData.get('email') || '',
                    package_id: formData.get('package_id') || '',
                    allow_ported_mtn: formData.get('allow_ported_mtn') === '1' ? '1' : '0'
                };
                const selectedNetwork = formData.get('guest_network') || '';
                const selectedGateway = getSelectedGuestGateway();
                payload.gateway = selectedGateway;

                if (!payload.phone || !payload.email || !selectedNetwork || !payload.package_id) {
                    showGuestError('Please fill in all required fields, including a valid email address.');
                    return;
                }
                writeSavedGuestEmail(payload.email);

                const selectedPackageDetails = getGuestPackageDetails(payload.package_id);
                const packageLabel = selectedPackageDetails
                    ? String(selectedPackageDetails.data_size || selectedPackageDetails.name || 'Selected package').trim()
                    : 'Selected package';
                const confirmed = await openOrderConfirmModal({
                    title: 'Confirm Guest Order',
                    subtitle: 'Review order details before payment.',
                    confirmText: 'Continue to Payment',
                    details: [
                        { label: 'Recipient', value: payload.phone },
                        { label: 'Email', value: payload.email },
                        { label: 'Network', value: buildGuestNetworkBadgeHtml(selectedNetwork), isHtml: true },
                        { label: 'Gateway', value: getGuestGatewayLabel(selectedGateway) },
                        { label: 'Package', value: packageLabel }
                    ]
                });

                if (!confirmed) {
                    return;
                }

                guestBtn.disabled = true;
                guestBtn.innerHTML = '<span class="spinner"></span> Redirecting...';
                clearGuestError();

                try {
                    const endpoint = guestGatewayEndpoints && guestGatewayEndpoints[selectedGateway]
                        ? guestGatewayEndpoints[selectedGateway]
                        : guestGatewayEndpoints[fallbackGuestGateway];
                    if (!endpoint) {
                        throw new Error('No payment endpoint configured for the selected gateway.');
                    }

                    const res = await fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const raw = await res.text();
                    let data = null;
                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch (parseError) {
                        throw new Error(raw && raw.trim() ? raw.trim() : 'Unexpected server response.');
                    }

                    if (data.status === 'success' && data.data && data.data.authorization_url) {
                        updateGuestCheckoutContext({
                            phone: String(payload.phone || '').trim(),
                            email: String(payload.email || '').trim(),
                            reference: String((data.data && data.data.reference) || '').trim(),
                            store_slug: '<?php echo htmlspecialchars($store_slug); ?>'
                        });
                        window.location.href = data.data.authorization_url;
                        return;
                    }

                    if (data && data.reference) {
                        updateGuestCheckoutContext({
                            phone: String(payload.phone || '').trim(),
                            email: String(payload.email || '').trim(),
                            reference: String(data.reference || '').trim(),
                            store_slug: '<?php echo htmlspecialchars($store_slug); ?>'
                        });
                    }

                    if (data && data.next_url) {
                        window.location.href = String(data.next_url);
                        return;
                    }

                    showGuestError((data && data.message) ? data.message : (res.ok ? 'Unable to start payment right now.' : 'Payment request failed.'));
                } catch (err) {
                    showGuestError(err && err.message ? err.message : 'Network error. Please try again.');
                } finally {
                    guestBtn.disabled = false;
                    updateGuestGatewayButtonLabel();
                }
            });
        }

    </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

