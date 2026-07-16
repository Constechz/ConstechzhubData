<?php
require_once __DIR__ . '/../config/config.php';

if (isset($_GET['debug_error']) || isset($_POST['debug_error'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

ensureDataPackageStockStatusColumn();

// Prevent browser caching for real-time updates
preventBrowserCaching();

function renderStoreDirectory($stores, $title = 'Choose a Store', $message = 'Pick an agent storefront to continue.') {
    $siteName = htmlspecialchars(getSiteName(), ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $siteName; ?> - Store Directory</title>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
        <style>
            :root {
                --shell-bg: #f4f6fb;
                --shell-card: #ffffff;
                --shell-text: #0f172a;
                --shell-muted: #6b7280;
                --shell-accent: #f97316;
                --shell-border: rgba(15, 23, 42, 0.08);
            }
            [data-theme="dark"] {
                --shell-bg: #050a14;
                --shell-card: #0f172a;
                --shell-text: #f3f5ff;
                --shell-muted: #a5adcf;
                --shell-accent: #fb923c;
                --shell-border: rgba(255, 255, 255, 0.08);
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: var(--shell-bg);
                color: var(--shell-text);
            }
            .directory-shell {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }
            .directory-hero {
                padding: 4rem 1rem 2rem;
                text-align: center;
                background: radial-gradient(circle at top, rgba(249,115,22,.18), transparent 60%);
            }
            .directory-hero h1 {
                margin: 0 0 1rem;
                font-size: clamp(2rem, 5vw, 3.25rem);
            }
            .directory-hero p {
                margin: 0 auto;
                max-width: 620px;
                color: var(--shell-muted);
            }
            .directory-actions {
                margin-top: 2rem;
                display: flex;
                justify-content: center;
                gap: 0.75rem;
                flex-wrap: wrap;
            }
            .ghost-btn, .primary-btn {
                border-radius: 999px;
                padding: 0.65rem 1.5rem;
                border: 1px solid var(--shell-border);
                background: transparent;
                color: var(--shell-text);
                font-weight: 600;
                cursor: pointer;
            }
            .primary-btn {
                background: var(--shell-accent);
                border-color: var(--shell-accent);
                color: #fff;
            }
            .store-grid {
                width: min(1200px, 94%);
                margin: 2rem auto 3rem;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 1.75rem;
            }
            .store-card {
                border-radius: 20px;
                padding: 1.8rem;
                background: var(--shell-card);
                border: 1px solid var(--shell-border);
                box-shadow: 0 14px 40px rgba(15,23,42,.08);
                display: flex;
                flex-direction: column;
                gap: 0.65rem;
            }
            .store-card h3 {
                margin: 0;
                font-size: 1.2rem;
            }
            .store-card p {
                margin: 0;
                color: var(--shell-muted);
                line-height: 1.5;
            }
            .store-card .meta {
                font-size: 0.85rem;
                color: var(--shell-muted);
            }
            .store-card a {
                margin-top: auto;
                text-decoration: none;
                font-weight: 600;
                color: var(--shell-accent);
                display: inline-flex;
                gap: 0.4rem;
                align-items: center;
            }
            .directory-empty {
                text-align: center;
                color: var(--shell-muted);
                margin: 4rem 0;
                font-size: 1rem;
            }
            footer {
                margin-top: auto;
                padding: 2rem 1rem;
                text-align: center;
                color: var(--shell-muted);
            }
        </style>
    </head>
    <body>
        <div class="directory-shell">
            <section class="directory-hero">
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <div class="directory-actions">
                    <button class="ghost-btn" id="directoryThemeToggle"><i class="fas fa-moon"></i></button>
                    <button class="ghost-btn" onclick="window.location.href='<?php echo SITE_URL; ?>'">Go to Homepage</button>
                    <button class="primary-btn" onclick="window.location.href='<?php echo SITE_URL; ?>/register.php'">Become a Vendor</button>
                </div>
            </section>
            <?php if (!empty($stores)): ?>
                <div class="store-grid">
                    <?php foreach ($stores as $store): ?>
                        <div class="store-card">
                            <h3><?php echo htmlspecialchars($store['store_name']); ?></h3>
                            <p><?php echo htmlspecialchars($store['store_description'] ?? 'Trusted storefront for smart data purchases.'); ?></p>
                            <span class="meta">Agent: <?php echo htmlspecialchars($store['agent_name']); ?></span>
                            <a href="<?php echo htmlspecialchars(rtrim(SITE_URL, '/') . '/s/' . rawurlencode($store['store_slug'])); ?>">Browse store <i class="fas fa-arrow-right"></i></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="directory-empty">
                    <i class="fas fa-store-slash" style="font-size:2rem; margin-bottom:0.5rem;"></i>
                    <p>No active stores yet. Check back soon.</p>
                </div>
            <?php endif; ?>
            <footer>Powered by <?php echo $siteName; ?></footer>
        </div>
        <script>
            function applyDirectoryTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                const icon = document.querySelector('#directoryThemeToggle i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                }
            }
            (function initTheme(){
                const saved = localStorage.getItem('theme') || 'light';
                applyDirectoryTheme(saved);
            })();
            document.getElementById('directoryThemeToggle').addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                localStorage.setItem('theme', next);
                applyDirectoryTheme(next);
            });
        </script>
    </body>
    </html>
    <?php
}

function fetchActiveStores($db) {
    $stores = [];
    $query = "
        SELECT ast.store_name, ast.store_slug, ast.store_description, u.full_name AS agent_name
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.is_active = 1 AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
        ORDER BY ast.store_name ASC
        LIMIT 30
    ";
    if ($result = $db->query($query)) {
        $stores = $result->fetch_all(MYSQLI_ASSOC);
    }
    return $stores;
}

// Check if agent store feature is globally active
if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

// Get store slug from URL
$store_slug = $_GET['store'] ?? '';

if (empty($store_slug)) {
    $stores = fetchActiveStores($db);
    renderStoreDirectory($stores);
    exit();
}

// Get agent store information (and agent Paystack settings if active)
$stmt = $db->prepare("
    SELECT ast.*,
           u.full_name AS agent_name,
           u.email AS agent_email,
           u.phone AS agent_phone,
           u.agent_logo
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
");
$store = null;
if ($stmt) {
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $store = $result->fetch_assoc();
        if (!isset($_SESSION['store_cache'])) {
            $_SESSION['store_cache'] = [];
        }
        $_SESSION['store_cache'][$store_slug] = [
            'data' => $store,
            'ts' => time()
        ];
    }
    $stmt->close();
}

if (!$store) {
    $stores = fetchActiveStores($db);
    renderStoreDirectory($stores, 'Store Not Found', 'That storefront is unavailable. Choose another store to continue.');
    exit();
}

$agent_id = $store['agent_id'];
$agent_support_phone = trim((string) ($store['agent_phone'] ?? ''));
$agent_support_tel = preg_replace('/\D+/', '', $agent_support_phone);
if ($agent_support_tel !== '' && strpos($agent_support_tel, '0') === 0) {
    $agent_support_tel = '233' . substr($agent_support_tel, 1);
}

// Log store visit
$visitor_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? '';

$stmt = $db->prepare("INSERT INTO store_visits (store_id, visitor_ip, user_agent, referrer) VALUES (?, ?, ?, ?)");
if ($stmt) {
    $stmt->bind_param("isss", $store['id'], $visitor_ip, $user_agent, $referrer);
    $stmt->execute();
    $stmt->close();
}

// Determine session helper flags with DB-backed role to avoid stale session role mismatches
$current_user = isLoggedIn() ? getCurrentUser() : null;
$current_role = normalizeUserRole($current_user['role'] ?? ($_SESSION['user_role'] ?? ''));
if ($current_role !== '' && isset($_SESSION['user_role']) && normalizeUserRole($_SESSION['user_role']) !== $current_role) {
    setSessionUserRole($current_role);
}
$is_customer = isLoggedIn() && isCustomerAccountRole($current_role);
$store_pricing_type = $is_customer ? getCustomerPricingUserType($current_user) : 'customer';
$use_agent_custom_pricing = $store_pricing_type === 'vip' ? 0 : 1;

// Fetch packages with pricing hierarchy (VIP price > customer fallback for VIP; store custom > customer price for regular customers)
$packages = [];
$stmt = $db->prepare("
    SELECT
        dp.id,
        dp.name,
        dp.package_type,
        dp.data_size,
        dp.validity_days,
        dp.description,
        COALESCE(dp.stock_status, 'in_stock') AS stock_status,
        n.name AS network,
        COALESCE(CASE WHEN ? = 1 THEN acp.custom_price ELSE NULL END, pp.price, pp_customer_fallback.price, dp.price) AS display_price,
        CASE WHEN ? = 1 AND acp.custom_price IS NOT NULL THEN 1 ELSE 0 END AS has_custom_price
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = ?
    LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = 'customer'
    WHERE dp.status = 'active'
    ORDER BY n.name
");
if ($stmt) {
    $stmt->bind_param('iiis', $use_agent_custom_pricing, $use_agent_custom_pricing, $agent_id, $store_pricing_type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['display_price'] = (float) $row['display_price'];
            $packages[] = $row;
        }
    }
    $stmt->close();
}

// Sort packages using PHP comparison function to avoid database engine differences
if (function_exists('dbh_compare_packages')) {
    usort($packages, 'dbh_compare_packages');
}

// Group packages by network
$grouped_packages = [];
foreach ($packages as $package) {
    $grouped_packages[$package['network']][] = $package;
}

function isMtnStoreNetwork($networkName) {
    return stripos((string) $networkName, 'mtn') !== false;
}

function getMtnStoreSubtitle($networkName, array $package) {
    $name = trim((string) ($package['name'] ?? ''));
    $dataSize = trim((string) ($package['data_size'] ?? ''));
    if ($name !== '' && strcasecmp($name, $dataSize) !== 0) {
        return $name;
    }

    return trim((string) $networkName) . ' Master Internet';
}

function isAtStoreNetwork($networkName) {
    $normalized = strtolower(trim((string) $networkName));
    return $normalized === 'at' || strpos($normalized, 'airtel') !== false || strpos($normalized, 'tigo') !== false;
}

function getAtStoreSubtitleLines(array $package) {
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

function isTelecelStoreNetwork($networkName) {
    $normalized = strtolower(trim((string) $networkName));
    return strpos($normalized, 'telecel') !== false || strpos($normalized, 'vodafone') !== false;
}

function getTelecelStoreSubtitle($networkName) {
    if (stripos((string) $networkName, 'telecel') !== false || stripos((string) $networkName, 'vodafone') !== false) {
        return 'TELECEL';
    }

    return strtoupper(trim((string) $networkName));
}

function getStoreNetworkViewKey($networkName) {
    if (isMtnStoreNetwork($networkName)) {
        return 'mtn';
    }

    if (isAtStoreNetwork($networkName)) {
        return 'at';
    }

    if (isTelecelStoreNetwork($networkName)) {
        return 'telecel';
    }

    return '';
}

$store_view = strtolower(trim((string) ($_GET['view'] ?? '')));
$allowed_store_views = ['mtn', 'at', 'telecel'];
if (!in_array($store_view, $allowed_store_views, true)) {
    $store_view = '';
}

$filtered_grouped_packages = [];
foreach ($grouped_packages as $network => $networkPackages) {
    $network_view_key = getStoreNetworkViewKey($network);
    if ($store_view !== '' && $network_view_key !== $store_view) {
        continue;
    }

    $filtered_grouped_packages[$network] = $networkPackages;
}

$store_root_url = rtrim((string) SITE_URL, '/') . '/store/';
$store_index_url = $store_root_url . 'index.php';
$store_reference_url = $store_root_url . 'reference.php';
$store_register_url = $store_root_url . 'register.php';
$store_guest_checkout_url = $store_root_url . 'guest-checkout.php';
$store_verify_payment_url = $store_root_url . 'verify-payment.php';
$store_guest_checker_url = $store_root_url . 'guest-checker.php';
$store_guest_afa_url = $store_root_url . 'guest-afa-registration.php';
$store_products_url = $store_root_url . 'products.php';
$store_login_url = $store_root_url . 'login.php';
$store_home_url = $store_index_url . '?store=' . urlencode($store_slug);

$service_cards = [
    [
        'key' => 'mtn',
        'title' => 'MTN DATA',
        'description' => 'Open MTN bundles and order instantly.',
        'icon' => 'assets/images/mtn-logo.svg',
        'href' => $store_index_url . '?store=' . urlencode($store_slug) . '&view=mtn',
        'is_external' => false,
    ],
    [
        'key' => 'at',
        'title' => 'AIRTEL TIGO DATA',
        'description' => 'Browse AT bundles for quick purchase.',
        'icon' => 'assets/images/at-logo.svg',
        'href' => $store_index_url . '?store=' . urlencode($store_slug) . '&view=at',
        'is_external' => false,
    ],
    [
        'key' => 'telecel',
        'title' => 'TELECEL DATA',
        'description' => 'View Telecel data offers for this store.',
        'icon' => 'assets/images/telecel-logo.svg',
        'href' => $store_index_url . '?store=' . urlencode($store_slug) . '&view=telecel',
        'is_external' => false,
    ],
    [
        'key' => 'afa',
        'title' => 'AFA REGISTRATION',
        'description' => 'Start AFA registration for this store.',
        'icon' => 'assets/images/mtn-afa-registration.svg',
        'href' => $store_guest_afa_url . '?store=' . urlencode($store_slug),
        'is_external' => true,
    ],
    [
        'key' => 'checker',
        'title' => 'CHECKERS',
        'description' => 'Open checker access and track orders.',
        'icon_class' => 'fas fa-award',
        'href' => $store_guest_checker_url . '?store=' . urlencode($store_slug),
        'is_external' => true,
    ],
    [
        'key' => 'products',
        'title' => 'PRODUCTS',
        'description' => 'Shop routers and devices with delivery checkout.',
        'icon_class' => 'fas fa-shopping-bag',
        'href' => $store_products_url . '?store=' . urlencode($store_slug),
        'is_external' => true,
    ],
    [
        'key' => 'recover',
        'title' => 'VERIFY PAYMENT',
        'description' => 'Recover a paid Paystack data order that did not submit.',
        'icon_class' => 'fas fa-rotate',
        'href' => $store_verify_payment_url . '?store=' . urlencode($store_slug),
        'is_external' => true,
    ],
];

$store_guest_notifications_html = function_exists('renderNotificationSlides')
    ? renderNotificationSlides('guests')
    : '';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php if (trim((string) $store_guest_notifications_html) !== ''): ?>
            <section class="store-notifications-section">
                <div class="container">
                    <?php echo $store_guest_notifications_html; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($store_view === ''): ?>
        <section class="service-selector-section">
            <div class="container">
                <div class="service-selector-card">
                    <div class="service-selector-copy">
                        <span class="service-selector-kicker">Store Services</span>
                        <h1>Choose what you want to do</h1>
                        <p>Open a dedicated page for MTN Data, Airtel Tigo Data, Telecel Data, AFA Registration, or Checkers.</p>
                        <?php if (!empty($store['welcome_text'])): ?>
                            <div class="store-custom-welcome" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed var(--store-border); font-size: 1.1rem; line-height: 1.5; color: var(--store-ink); opacity: 0.95; white-space: pre-line;">
                                <?php echo htmlspecialchars($store['welcome_text']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="service-selector-grid">
                    <?php foreach ($service_cards as $service_card): ?>
                        <a href="<?php echo htmlspecialchars($service_card['href']); ?>" class="service-card service-card-<?php echo htmlspecialchars($service_card['key']); ?>">
                            <span class="service-card-icon">
                                <?php if (!empty($service_card['icon'])): ?>
                                    <img src="<?php echo htmlspecialchars(dbh_asset($service_card['icon'])); ?>" alt="<?php echo htmlspecialchars($service_card['title']); ?>">
                                <?php else: ?>
                                    <i class="<?php echo htmlspecialchars($service_card['icon_class'] ?? 'fas fa-circle'); ?>"></i>
                                <?php endif; ?>
                            </span>
                            <span class="service-card-title"><?php echo htmlspecialchars($service_card['title']); ?></span>
                            <span class="service-card-description"><?php echo htmlspecialchars($service_card['description']); ?></span>
                            <span class="service-card-action">
                                <?php echo $service_card['is_external'] ? 'Open' : 'Choose'; ?>
                                <i class="fas fa-arrow-right"></i>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($store_view !== '' && !empty($filtered_grouped_packages)): ?>
        <section class="packages-section" id="packages">
            <div class="container">
                <div class="service-page-header service-card-<?php echo htmlspecialchars($store_view); ?>">
                    <div class="service-page-copy">
                        <span class="service-selector-kicker">Service Page</span>
                        <h2><?php echo htmlspecialchars(strtoupper($store_view === 'at' ? 'Airtel Tigo Data' : ($store_view === 'mtn' ? 'MTN Data' : 'Telecel Data'))); ?></h2>
                        <p>Browse and order only this service from <?php echo htmlspecialchars($store['store_name']); ?>.</p>
                    </div>
                    <a href="<?php echo htmlspecialchars($store_home_url); ?>" class="btn btn-outline service-selector-reset">
                        <i class="fas fa-arrow-left"></i>
                        Back to Services
                    </a>
                </div>
                <?php foreach ($filtered_grouped_packages as $network => $networkPackages): ?>
                    <div class="network-group<?php
                        echo isMtnStoreNetwork($network)
                            ? ' network-group-mtn'
                            : (isAtStoreNetwork($network)
                                ? ' network-group-at'
                                : (isTelecelStoreNetwork($network) ? ' network-group-telecel' : ''));
                    ?>">
                        <h3 class="network-title"><?php echo htmlspecialchars($network); ?> Bundles</h3>
                        <div class="packages-grid">
                            <?php foreach ($networkPackages as $package): ?>
                            <?php
                                $packageAnchor = 'package-' . (int) $package['id'];
                                $is_mtn_network = isMtnStoreNetwork($network);
                                $is_at_network = isAtStoreNetwork($network);
                                $is_telecel_network = isTelecelStoreNetwork($network);
                                if ($is_customer) {
                                    $cta_link = SITE_URL . '/customer/store-checkout.php?store=' . urlencode($store_slug) . '&package_id=' . (int) $package['id'];
                                } else {
                                    $loginRedirect = SITE_URL . '/customer/store-checkout.php?store=' . urlencode($store_slug) . '&package_id=' . (int) $package['id'];
                                    $cta_link = $store_login_url . '?store=' . urlencode($store_slug) . '&redirect=' . urlencode($loginRedirect);
                                    $guest_link = $store_guest_checkout_url . '?store=' . urlencode($store_slug) . '&package_id=' . (int) $package['id'];
                                }
                                $primary_purchase_link = $is_customer ? $cta_link : ($guest_link ?? $cta_link);
                                $mtn_subtitle = getMtnStoreSubtitle($network, $package);
                                $at_subtitle_lines = getAtStoreSubtitleLines($package);
                                $telecel_subtitle = getTelecelStoreSubtitle($network);
                                $is_out_of_stock = (($package['stock_status'] ?? 'in_stock') === 'out_of_stock');
                                if ($is_out_of_stock) {
                                    $cta_link = '#';
                                    $guest_link = '#';
                                    $primary_purchase_link = '#';
                                }
                            ?>
                            <div class="package-card<?php
                                echo $is_mtn_network
                                    ? ' package-card-mtn'
                                    : ($is_at_network
                                        ? ' package-card-at'
                                        : ($is_telecel_network ? ' package-card-telecel' : ''));
                                echo $is_out_of_stock ? ' package-card-out-of-stock' : '';
                            ?>" id="<?php echo htmlspecialchars($packageAnchor); ?>">
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
                                        GHS <?php echo number_format($package['display_price'], 2); ?>
                                        <?php if (!empty($package['has_custom_price'])): ?>
                                            <span class="custom-price-badge">Store Offer</span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <a class="btn btn-primary purchase-btn package-card-mtn-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($primary_purchase_link); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                        <i class="fas <?php echo $is_out_of_stock ? 'fa-ban' : 'fa-bolt'; ?>"></i>
                                        <span><?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy Now'; ?></span>
                                    </a>
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
                                        GHS <?php echo number_format($package['display_price'], 2); ?>
                                        <?php if (!empty($package['has_custom_price'])): ?>
                                            <span class="custom-price-badge">Store Offer</span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <a class="btn btn-primary purchase-btn package-card-at-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($primary_purchase_link); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                        <i class="fas <?php echo $is_out_of_stock ? 'fa-ban' : 'fa-bolt'; ?>"></i>
                                        <span><?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy Now'; ?></span>
                                    </a>
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
                                        GHS <?php echo number_format($package['display_price'], 2); ?>
                                        <?php if (!empty($package['has_custom_price'])): ?>
                                            <span class="custom-price-badge">Store Offer</span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                    <a class="btn btn-primary purchase-btn package-card-telecel-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($primary_purchase_link); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                        <i class="fas <?php echo $is_out_of_stock ? 'fa-ban' : 'fa-bolt'; ?>"></i>
                                        <span><?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy Now'; ?></span>
                                    </a>
                                <?php else: ?>
                                <div class="package-header">
                                    <h4><?php echo htmlspecialchars($package['name']); ?></h4>
                                    <span class="package-network"><?php echo htmlspecialchars($network); ?></span>
                                </div>
                                <div class="package-details">
                                    <div class="package-size">
                                        <i class="fas fa-database"></i>
                                        <span><?php echo htmlspecialchars($package['data_size']); ?></span>
                                    </div>
                                    <div class="package-validity">
                                        <i class="fas fa-clock"></i>
                                        <span><?php echo (int) $package['validity_days']; ?> days</span>
                                    </div>
                                    <div class="package-price">
                                        GHS <?php echo number_format($package['display_price'], 2); ?>
                                        <?php if (!empty($package['has_custom_price'])): ?>
                                            <span class="custom-price-badge">Store Offer</span>
                                        <?php endif; ?>
                                        <?php if ($is_out_of_stock): ?>
                                            <span class="stock-status-badge">Out of Stock</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($package['description'])): ?>
                                    <p class="package-description"><?php echo htmlspecialchars($package['description']); ?></p>
                                <?php endif; ?>
                                <?php if ($is_customer): ?>
                                    <a class="btn btn-primary purchase-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($cta_link); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                        <?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy Now'; ?>
                                    </a>
                                <?php else: ?>
                                    <div class="package-actions">
                                        <a class="btn btn-primary purchase-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($guest_link ?? '#'); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                            <?php echo $is_out_of_stock ? 'Out of Stock' : 'Buy as Guest'; ?>
                                        </a>
                                        <a class="btn btn-outline purchase-btn<?php echo $is_out_of_stock ? ' disabled' : ''; ?>" href="<?php echo htmlspecialchars($cta_link); ?>" <?php echo $is_out_of_stock ? 'onclick="return false;" aria-disabled="true"' : ''; ?>>
                                            Login
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php elseif ($store_view !== ''): ?>
            <div class="container">
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No packages are available for this service right now. Choose another service to continue.</p>
                </div>
            </div>
        <?php endif; ?>
    </main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
