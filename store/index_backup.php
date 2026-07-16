<?php
require_once __DIR__ . '/../config/config.php';

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
    renderStoreDirectory([], 'Service Unavailable', 'The agent store service is temporarily offline.');
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
$stmt->bind_param("s", $store_slug);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stores = fetchActiveStores($db);
    renderStoreDirectory($stores, 'Store Not Found', 'That storefront is unavailable. Choose another store to continue.');
    exit();
}

$store = $result->fetch_assoc();
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
$stmt->bind_param("isss", $store['id'], $visitor_ip, $user_agent, $referrer);
$stmt->execute();

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
    ORDER BY n.name, CAST(REGEXP_REPLACE(dp.data_size, '[^0-9.]', '') AS DECIMAL(10,2))
");
$stmt->bind_param('iiis', $use_agent_custom_pricing, $use_agent_custom_pricing, $agent_id, $store_pricing_type);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['display_price'] = (float) $row['display_price'];
    $packages[] = $row;
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
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('theme');
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-theme', savedTheme || (prefersDark ? 'dark' : 'light'));
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Data Bundle Store</title>
    <base href="<?php echo htmlspecialchars($store_root_url); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <meta name="description" content="<?php echo htmlspecialchars($store['store_description'] ?? 'Trusted storefront for smart data purchases.'); ?>">
    <?php if (!empty($store['primary_color']) || !empty($store['banner_image'])): ?>
        <style>
            <?php if (!empty($store['primary_color'])): ?>
            :root {
                --store-accent: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --store-accent-strong: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --brand-primary: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --primary-color: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
            }
            <?php endif; ?>
            
            <?php if (!empty($store['banner_image'])): ?>
            body.store-page .service-selector-card {
                background: linear-gradient(rgba(0, 0, 0, 0.45), rgba(0, 0, 0, 0.7)), url('../uploads/agent_banners/<?php echo htmlspecialchars($store['banner_image']); ?>') no-repeat center center !important;
                background-size: cover !important;
                color: #ffffff !important;
                border: none !important;
                --store-border: rgba(255, 255, 255, 0.25) !important;
                --store-ink: #ffffff !important;
            }
            body.store-page .service-selector-copy h1, 
            body.store-page .service-selector-copy p,
            body.store-page .service-selector-copy .service-selector-kicker,
            body.store-page .store-custom-welcome {
                color: #ffffff !important;
            }
            <?php endif; ?>
        </style>
    <?php endif; ?>
</head>
<body class="store-page">
    <!-- Store Header -->
    <header class="navbar">
        <div class="container">
            <div class="nav-brand">
                <?php if (!empty($store['agent_logo'])): ?>
                    <img src="../uploads/agent_logos/<?php echo htmlspecialchars($store['agent_logo']); ?>" alt="<?php echo htmlspecialchars($store['store_name']); ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: contain;">
                <?php elseif ($store['store_logo']): ?>
                    <img src="../uploads/<?php echo htmlspecialchars($store['store_logo']); ?>" alt="<?php echo htmlspecialchars($store['store_name']); ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: contain;">
                <?php else: ?>
                    <div style="width: 40px; height: 40px; background: var(--primary-color); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-store" style="color: white; font-size: 1.2rem;"></i>
                    </div>
                <?php endif; ?>
                <div class="brand-copy">
                    <strong><?php echo htmlspecialchars($store['store_name']); ?></strong>
                    <span>Trusted data storefront</span>
                </div>
            </div>
            
            <button class="nav-menu-toggle" type="button" aria-expanded="false" aria-controls="storeNavActions" aria-label="Toggle navigation">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <div class="nav-actions" id="storeNavActions">
                <button type="button" class="btn btn-outline store-quick-link store-theme-toggle" id="storeThemeToggle" aria-label="Toggle dark mode">
                    <i class="fas fa-moon" id="storeThemeIcon"></i>
                    <span id="storeThemeText">Dark</span>
                </button>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-outline store-quick-link menu-link-home">
                    <i class="fas fa-home"></i>
                    Home
                </a>
                <a href="<?php echo htmlspecialchars($store_reference_url . '?store=' . urlencode($store_slug)); ?>" class="btn btn-outline store-quick-link menu-link-status">
                    <i class="fas fa-search"></i>
                    Check Status
                </a>
                <a href="<?php echo htmlspecialchars($store_verify_payment_url . '?store=' . urlencode($store_slug)); ?>" class="btn btn-outline store-quick-link menu-link-verify">
                    <i class="fas fa-sync-alt"></i>
                    Verify Payment
                </a>
                <a href="<?php echo htmlspecialchars($store_products_url . '?store=' . urlencode($store_slug)); ?>" class="btn btn-outline store-quick-link">
                    <i class="fas fa-shopping-bag"></i>
                    Products
                </a>
                <?php if ($is_customer): ?>
                    <a href="<?php echo SITE_URL; ?>/customer/dashboard.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-primary store-quick-link menu-link-primary">
                        <i class="fas fa-th-large"></i>
                        Go to Dashboard
                    </a>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($store_register_url . '?store=' . urlencode($store_slug) . '&redirect=' . urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug)); ?>" class="btn btn-outline store-quick-link menu-link-register">
                        <i class="fas fa-user-plus"></i>
                        Register
                    </a>
                    <a href="<?php echo htmlspecialchars($store_login_url . '?store=' . urlencode($store_slug) . '&redirect=' . urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug)); ?>" class="btn btn-primary store-quick-link menu-link-primary">
                        <i class="fas fa-sign-in-alt"></i>
                        Sign In
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

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

    <!-- Store Footer -->
    <footer class="store-footer">
        <div class="container">
            <div class="footer-content">
                <div class="store-contact">
                    <span class="footer-brand-mark">
                        <i class="fas fa-store"></i>
                    </span>
                    <div class="footer-brand-copy">
                        <h4><?php echo htmlspecialchars($store['store_name']); ?></h4>
                        <div class="footer-contact-links">
                            <?php if ($agent_support_phone !== ''): ?>
                                <a href="tel:<?php echo htmlspecialchars($agent_support_tel); ?>">
                                    <i class="fas fa-phone"></i>
                                    Support: <?php echo htmlspecialchars($agent_support_phone); ?>
                                </a>
                            <?php endif; ?>
                            <a href="mailto:<?php echo htmlspecialchars($store['agent_email']); ?>">
                                <i class="fas fa-envelope"></i>
                                <?php echo htmlspecialchars($store['agent_email']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="powered-by">
                    <span>Powered by</span>
                    <strong><?php echo htmlspecialchars(getSiteName()); ?></strong>
                </div>
            </div>
            
            <div class="footer-divider">
                <p>
                    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($store['store_name']); ?>. All rights reserved.
                </p>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            const themeToggle = document.getElementById('storeThemeToggle');
            const themeIcon = document.getElementById('storeThemeIcon');
            const themeText = document.getElementById('storeThemeText');

            function getPreferredTheme() {
                try {
                    const saved = localStorage.getItem('theme');
                    if (saved === 'dark' || saved === 'light') {
                        return saved;
                    }
                } catch (e) {}

                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light';
            }

            function applyStoreTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                if (themeIcon) {
                    themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                }
                if (themeText) {
                    themeText.textContent = theme === 'dark' ? 'Light' : 'Dark';
                }
                if (themeToggle) {
                    themeToggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
                }
            }

            applyStoreTheme(getPreferredTheme());

            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    const next = current === 'dark' ? 'light' : 'dark';
                    try {
                        localStorage.setItem('theme', next);
                    } catch (e) {}
                    applyStoreTheme(next);
                });
            }
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggle = document.querySelector('.nav-menu-toggle');
            const navActions = document.getElementById('storeNavActions');
            if (!toggle || !navActions) return;

            const syncMobileMenuState = function () {
                const isMobile = window.innerWidth <= 720;
                if (!isMobile && navActions.classList.contains('is-open')) {
                    navActions.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            };

            const closeMenu = function () {
                navActions.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            };

            toggle.addEventListener('click', function () {
                if (window.innerWidth > 720) {
                    return;
                }

                const nextOpen = !navActions.classList.contains('is-open');
                navActions.classList.toggle('is-open', nextOpen);
                toggle.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
            });

            navActions.querySelectorAll('.store-quick-link').forEach(function (link) {
                link.addEventListener('click', closeMenu);
            });

            window.addEventListener('resize', syncMobileMenuState);
            syncMobileMenuState();
        });
    </script>
    
    <style>
        .main-content {
            padding: 3rem 0;
            min-height: 70vh;
        }
        
        .hero-section {
            text-align: center;
            margin-bottom: 4rem;
            padding: 2rem 1rem;
        }
        
        .hero-section h1 {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            font-weight: 600;
        }
        
        .hero-description {
            font-size: 1.125rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            line-height: 1.6;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .hero-subtitle {
            color: var(--text-muted);
            margin-bottom: 2.5rem;
            font-size: 1rem;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 3rem;
        }
        
        .hero-buttons .btn {
            padding: 0.875rem 1.75rem;
            font-size: 1rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .features-section {
            margin-top: 3rem;
            padding: 0 1rem;
        }
        
        .features-section h2 {
            text-align: center;
            font-size: 1.875rem;
            margin-bottom: 3rem;
            color: var(--text-primary);
            font-weight: 600;
        }
        
        .features-list {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }
        
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            text-align: left;
        }
        
        .feature-icon {
            flex-shrink: 0;
            width: 60px;
            height: 60px;
            background: var(--brand-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .feature-icon i {
            font-size: 1.5rem;
            color: white;
        }
        
        .feature-content h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-primary);
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .feature-content p {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .hero-section h1 {
                font-size: 2rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .hero-buttons .btn {
                width: 100%;
                max-width: 280px;
                justify-content: center;
            }
            
            .feature-item {
                gap: 1rem;
            }
            
            .feature-icon {
                width: 50px;
                height: 50px;
            }
            
            .feature-icon i {
                font-size: 1.25rem;
            }
        }
        
        .store-header {
            background: var(--bg-dark);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .store-brand {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .store-logo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .store-logo-placeholder {
            width: 80px;
            height: 80px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        
        .store-info h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
        }
        
        .store-info p {
            margin: 0 0 0.5rem 0;
            opacity: 0.8;
        }
        
        .store-agent {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .packages-section {
            margin-top: 4rem;
            padding: 0 1rem 2rem;
        }

        .network-group {
            margin-bottom: 3rem;
        }
        
        .network-title {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .package-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .package-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
        }
        
        .package-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .package-header h4 {
            margin: 0;
            color: var(--text-dark);
        }
        
        .package-network {
            background: var(--primary-color);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .package-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .package-size,
        .package-validity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .package-price {
            grid-column: 1 / -1;
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
            text-align: center;
            margin: 1rem 0;
        }
        
        .custom-price-badge {
            background: var(--success-color);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }
        
        .package-description {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        
        .purchase-btn {
            width: 100%;
        }
        
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header h3 {
            margin: 0;
        }
        
        .close {
            font-size: 2rem;
            cursor: pointer;
            color: var(--text-muted);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .purchase-summary {
            background: var(--bg-light);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .store-footer {
            background: var(--bg-dark);
            color: white;
            padding: 2rem 0;
            margin-top: 4rem;
        }
        
        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                gap: 1rem;
            }
            
            .store-brand {
                flex-direction: column;
                text-align: center;
            }
            
            .packages-grid {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }

        body.store-page {
            --store-bg: var(--bg-primary);
            --store-ink: var(--text-primary);
            --store-muted: var(--text-secondary);
            --store-accent: #173fae;
            --store-accent-strong: #173fae;
            --store-accent-cool: #ef1c24;
            --store-accent-warm: #facc15;
            --store-card: var(--bg-primary);
            --store-border: var(--border-color);
            --store-shadow: var(--shadow-lg);
            --store-shadow-soft: var(--shadow);
            --store-glow: rgba(23, 63, 174, 0.18);
            --store-nav-surface: #ffffff;
            --store-nav-border: rgba(23, 63, 174, 0.12);
            --store-chip-bg: #ffffff;
            --store-chip-text: #1f2937;
            --store-chip-icon-bg: rgba(23, 63, 174, 0.1);
            --store-page-bg: #ffffff;
            --primary-color: var(--brand-primary);
            --bg-dark: var(--dark-bg);
            --bg-light: var(--bg-secondary);
            --success-color: var(--brand-secondary);
            font-family: "Work Sans", "Trebuchet MS", "Segoe UI", sans-serif;
            background: var(--store-page-bg);
            color: var(--store-ink);
        }

        [data-theme="dark"] body.store-page {
            --store-bg: #07111f;
            --store-ink: #f8fafc;
            --store-muted: #a8b3c7;
            --store-accent: #7aa2ff;
            --store-accent-strong: #9bb8ff;
            --store-accent-cool: #ff5a5f;
            --store-accent-warm: #facc15;
            --store-card: #0f1b2d;
            --store-border: rgba(148, 163, 184, 0.18);
            --store-shadow: 0 24px 54px rgba(0, 0, 0, 0.42);
            --store-shadow-soft: 0 14px 34px rgba(0, 0, 0, 0.28);
            --store-glow: rgba(122, 162, 255, 0.26);
            --store-nav-surface: rgba(8, 17, 31, 0.96);
            --store-nav-border: rgba(148, 163, 184, 0.18);
            --store-chip-bg: #132239;
            --store-chip-text: #e5edff;
            --store-chip-icon-bg: rgba(122, 162, 255, 0.16);
            --store-page-bg: #07111f;
            --primary-color: var(--brand-primary);
            --bg-dark: var(--dark-bg);
            --bg-light: #0b1626;
            --success-color: var(--brand-secondary);
            color-scheme: dark;
            background: var(--store-page-bg);
        }

        body.store-page h1,
        body.store-page h2,
        body.store-page h3,
        body.store-page h4 {
            font-family: "Space Grotesk", "Trebuchet MS", "Segoe UI", sans-serif;
        }

        body.store-page .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: block;
        }

        body.store-page {
            --store-nav-offset: 96px;
        }

        body.store-page .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 20;
            background: var(--store-nav-surface);
            border-bottom: 1px solid var(--store-nav-border);
            backdrop-filter: none;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }

        [data-theme="dark"] body.store-page .navbar {
            background: var(--store-nav-surface);
        }

        body.store-page .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.9rem 1.1rem;
            padding: 0.9rem 1.2rem 1rem;
            flex-wrap: wrap;
        }

        body.store-page .nav-brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            min-width: 0;
        }

        body.store-page .nav-brand img,
        body.store-page .nav-brand > div[style*="width: 40px"] {
            border-radius: 12px !important;
            box-shadow: 0 8px 18px rgba(124, 58, 237, 0.22);
        }

        body.store-page .brand-copy {
            display: grid;
            min-width: 0;
        }

        body.store-page .brand-copy strong {
            color: var(--store-ink);
            font-size: 0.95rem;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        body.store-page .brand-copy span {
            color: var(--store-muted);
            font-size: 0.74rem;
            letter-spacing: 0.02em;
        }

        body.store-page .nav-actions {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            flex: 1 1 560px;
            min-width: 0;
        }

        body.store-page .nav-menu-toggle {
            display: none;
            width: 46px;
            height: 46px;
            border: 1px solid var(--store-nav-border);
            border-radius: 14px;
            background: var(--store-chip-bg);
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            align-items: center;
            justify-content: center;
            gap: 0.26rem;
            padding: 0;
            cursor: pointer;
        }

        body.store-page .nav-menu-toggle span {
            display: block;
            width: 20px;
            height: 2px;
            border-radius: 999px;
            background: var(--store-chip-text);
        }

        body.store-page .main-content {
            padding-top: var(--store-nav-offset);
        }

        body.store-page .nav-actions .store-quick-link {
            white-space: nowrap;
            min-height: 46px;
            padding: 0.65rem 0.78rem;
            border-radius: 14px;
            border: 1px solid var(--store-nav-border);
            background: var(--store-chip-bg);
            color: var(--store-chip-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.36rem;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            text-decoration: none;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        body.store-page .nav-actions .store-quick-link i {
            width: 1.4rem;
            height: 1.4rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--store-chip-icon-bg);
            color: var(--store-accent);
            font-size: 0.72rem;
        }

        body.store-page .nav-actions .store-quick-link.btn-primary {
            background: linear-gradient(135deg, var(--store-accent) 0%, var(--store-accent-cool) 100%);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 12px 24px rgba(23, 63, 174, 0.2);
        }

        body.store-page .nav-actions .store-quick-link.btn-primary i {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        body.store-page .store-theme-toggle {
            cursor: pointer;
        }

        body.store-page .store-theme-toggle span {
            pointer-events: none;
        }

        [data-theme="dark"] body.store-page .nav-actions .store-quick-link:not(.btn-primary) {
            background: var(--store-chip-bg);
            border-color: var(--store-nav-border);
            color: var(--store-chip-text);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.22);
        }

        body.store-page .btn {
            border-radius: 999px;
            padding: 0.8rem 1.6rem;
            font-weight: 600;
            border: 1px solid transparent;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        body.store-page .btn-primary {
            background: linear-gradient(135deg, var(--store-accent) 0%, var(--store-accent-cool) 100%);
            color: #fff;
            box-shadow: 0 16px 32px var(--store-glow);
        }

        body.store-page .btn-outline {
            background: transparent;
            border-color: var(--store-border);
            color: var(--store-ink);
        }

        body.store-page .btn:hover {
            transform: translateY(-2px);
        }

        body.store-page .hero-section {
            position: relative;
            margin: 0 auto 4rem;
            padding: clamp(2rem, 4vw, 3.5rem);
            border-radius: 28px;
            background: var(--store-card);
            box-shadow: var(--store-shadow);
            overflow: hidden;
            text-align: left;
        }

        body.store-page .hero-section::before,
        body.store-page .hero-section::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            opacity: 0.7;
        }

        body.store-page .hero-section::before {
            width: 240px;
            height: 240px;
            right: -80px;
            top: -120px;
            background: rgba(23, 63, 174, 0.12);
        }

        body.store-page .hero-section::after {
            width: 200px;
            height: 200px;
            right: 20%;
            bottom: -120px;
            background: rgba(250, 204, 21, 0.16);
        }

        body.store-page .hero-section h1 {
            font-size: clamp(2.3rem, 4vw, 3.4rem);
            margin-bottom: 1rem;
        }

        body.store-page .hero-description {
            font-size: 1.05rem;
            color: var(--store-muted);
            max-width: 620px;
            line-height: 1.7;
        }

        body.store-page .hero-subtitle {
            color: var(--store-muted);
            margin-top: 1rem;
        }

        body.store-page .hero-buttons {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        body.store-page .features-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 1.5rem;
        }

        body.store-page .feature-item {
            background: var(--store-card);
            border-radius: 20px;
            padding: 1.4rem;
            border: 1px solid var(--store-border);
            box-shadow: var(--store-shadow-soft);
            display: grid;
            gap: 0.75rem;
        }

        body.store-page .feature-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(23, 63, 174, 0.08);
            color: var(--store-accent);
            font-size: 1.2rem;
        }

        body.store-page .feature-content p {
            margin: 0;
            color: var(--store-muted);
        }

        body.store-page .service-selector-section {
            margin-bottom: 2.5rem;
        }

        body.store-page .store-notifications-section {
            margin-bottom: 1.5rem;
        }

        body.store-page .notification-slider {
            margin-bottom: 0 !important;
        }

        body.store-page .notification-slide {
            display: none;
        }

        body.store-page .notification-slide.active {
            display: block;
        }

        body.store-page .notification-slide .alert {
            border: 1px solid var(--store-border);
            border-radius: 18px;
            padding: 1rem;
            box-shadow: var(--store-shadow-soft);
            display: flex;
            gap: 0.9rem;
            align-items: flex-start;
            color: var(--store-ink);
            background: #ffffff;
        }

        body.store-page .notification-slide .alert-info {
            border-color: rgba(23, 63, 174, 0.2);
            background: #eff6ff !important;
            color: #0f172a !important;
        }

        body.store-page .notification-slide .alert-success {
            border-color: rgba(22, 163, 74, 0.2);
            background: #ecfdf5 !important;
            color: #0f172a !important;
        }

        body.store-page .notification-slide .alert-warning {
            border-color: rgba(217, 119, 6, 0.24);
            background: #fffbeb !important;
            color: #0f172a !important;
        }

        body.store-page .notification-slide .alert-danger {
            border-color: rgba(220, 38, 38, 0.2);
            background: #fef2f2 !important;
            color: #0f172a !important;
        }

        body.store-page .notification-content {
            min-width: 0;
            display: grid;
            gap: 0.35rem;
        }

        body.store-page .notification-content .alert-heading {
            margin: 0;
            color: #0f172a !important;
            font-size: 1rem;
            font-weight: 800;
        }

        body.store-page .notification-text {
            margin: 0;
            color: #334155 !important;
            line-height: 1.55;
            font-weight: 500;
        }

        body.store-page .notification-list {
            margin: 0.25rem 0 0;
            padding-left: 1.2rem;
            color: #334155 !important;
        }

        [data-theme="dark"] body.store-page .notification-slide .alert {
            border-color: rgba(148, 163, 184, 0.2);
            background: #101d31 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body.store-page .notification-slide .alert-info,
        [data-theme="dark"] body.store-page .notification-slide .alert-success,
        [data-theme="dark"] body.store-page .notification-slide .alert-warning,
        [data-theme="dark"] body.store-page .notification-slide .alert-danger {
            background: #101d31 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body.store-page .notification-content .alert-heading {
            color: #ffffff !important;
        }

        [data-theme="dark"] body.store-page .notification-text,
        [data-theme="dark"] body.store-page .notification-list {
            color: #cbd5e1 !important;
        }

        body.store-page .notification-media {
            width: 76px;
            height: 76px;
            flex: 0 0 76px;
            border-radius: 14px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.65);
        }

        body.store-page .notification-media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        body.store-page .notification-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.3rem;
        }

        body.store-page .notification-cta {
            border-radius: 999px;
            padding: 0.45rem 0.8rem;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            background: var(--store-accent);
            color: #fff;
        }

        body.store-page .notification-cta-secondary {
            background: transparent;
            border: 1px solid var(--store-border);
            color: var(--store-ink);
        }

        body.store-page .notification-indicators {
            display: flex;
            justify-content: center;
            gap: 0.4rem;
            margin-top: 0.75rem;
        }

        body.store-page .notification-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.24);
            cursor: pointer;
        }

        body.store-page .notification-dot.active {
            background: var(--store-accent);
        }

        body.store-page .service-selector-card {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            margin-bottom: 1.5rem;
            padding: 1.6rem;
            border-radius: 24px;
            background: var(--store-card);
            border: 1px solid var(--store-border);
            box-shadow: var(--store-shadow-soft);
        }

        body.store-page .service-selector-copy {
            display: grid;
            gap: 0.5rem;
        }

        body.store-page .service-selector-copy h1 {
            margin: 0;
            font-size: clamp(1.7rem, 3vw, 2.4rem);
            line-height: 1.1;
            color: var(--store-ink);
        }

        body.store-page .service-selector-copy p {
            margin: 0;
            color: var(--store-muted);
            max-width: 680px;
            line-height: 1.6;
        }

        body.store-page .service-selector-kicker {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            min-height: 32px;
            padding: 0.2rem 0.82rem;
            border-radius: 999px;
            background: rgba(250, 204, 21, 0.16);
            color: var(--store-accent);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        body.store-page .service-selector-reset {
            min-height: 48px;
            white-space: nowrap;
        }

        body.store-page .service-selector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        body.store-page .service-card {
            position: relative;
            display: grid;
            justify-items: center;
            text-align: center;
            gap: 0.8rem;
            min-height: 220px;
            padding: 1.45rem;
            border-radius: 24px;
            border: 1px solid var(--store-border);
            background: var(--store-card);
            box-shadow: var(--store-shadow-soft);
            text-decoration: none;
            color: inherit;
            overflow: hidden;
            transition: transform 0.24s ease, box-shadow 0.24s ease, border-color 0.24s ease;
        }

        body.store-page .service-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--store-shadow);
        }

        body.store-page .service-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--service-card-accent, var(--store-accent-cool)), var(--service-card-strong, var(--store-accent)));
        }

        body.store-page .service-card-icon {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--service-card-soft, rgba(99, 102, 241, 0.12));
            color: var(--service-card-strong, var(--store-accent));
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24);
        }

        body.store-page .service-card-icon img {
            width: 54px;
            height: 54px;
            object-fit: contain;
            display: block;
        }

        body.store-page .service-card-afa .service-card-icon {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            background: #f7c600;
        }

        body.store-page .service-card-afa .service-card-icon img {
            width: 54px;
            height: 54px;
            border-radius: 14px;
        }

        body.store-page .service-card-icon i {
            font-size: 1.45rem;
        }

        body.store-page .service-card-title {
            color: var(--store-ink);
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        body.store-page .service-card-description {
            color: var(--store-muted);
            line-height: 1.55;
            font-size: 0.93rem;
            max-width: 24ch;
        }

        body.store-page .service-card-action {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            min-height: 38px;
            padding: 0 0.85rem;
            border-radius: 999px;
            background: var(--service-card-soft, rgba(99, 102, 241, 0.12));
            color: var(--service-card-strong, var(--store-accent));
            font-size: 0.84rem;
            font-weight: 700;
        }

        body.store-page .service-card-mtn {
            --service-card-accent: #facc15;
            --service-card-strong: #b7791f;
            --service-card-soft: rgba(250, 204, 21, 0.14);
        }

        body.store-page .service-card-at {
            --service-card-accent: #2563eb;
            --service-card-strong: #173fae;
            --service-card-soft: rgba(37, 99, 235, 0.14);
        }

        body.store-page .service-card-telecel {
            --service-card-accent: #ef4444;
            --service-card-strong: #be123c;
            --service-card-soft: rgba(239, 68, 68, 0.14);
        }

        body.store-page .service-card-afa {
            --service-card-accent: #8b5cf6;
            --service-card-strong: #6d28d9;
            --service-card-soft: rgba(139, 92, 246, 0.14);
        }

        body.store-page .service-card-checker {
            --service-card-accent: #0f766e;
            --service-card-strong: #0f766e;
            --service-card-soft: rgba(15, 118, 110, 0.14);
        }

        body.store-page .service-card-products {
            --service-card-accent: #f97316;
            --service-card-strong: #c2410c;
            --service-card-soft: rgba(249, 115, 22, 0.14);
        }

        body.store-page .service-page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1.6rem;
            border-radius: 24px;
            border: 1px solid var(--store-border);
            background: var(--store-card);
            box-shadow: var(--store-shadow-soft);
        }

        body.store-page .service-page-copy {
            display: grid;
            gap: 0.45rem;
        }

        body.store-page .service-page-copy h2 {
            margin: 0;
            color: var(--store-ink);
            font-size: clamp(1.5rem, 3vw, 2rem);
            line-height: 1.1;
        }

        body.store-page .service-page-copy p {
            margin: 0;
            color: var(--store-muted);
            line-height: 1.55;
        }

        body.store-page .network-title {
            color: var(--store-ink);
            margin-bottom: 1.75rem;
            padding-bottom: 0.6rem;
            border-bottom: 2px solid rgba(139, 92, 246, 0.35);
            letter-spacing: 0.04rem;
            text-transform: uppercase;
            font-size: 0.95rem;
        }

        body.store-page .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.6rem;
        }

        body.store-page .package-card {
            background: var(--store-card);
            border-radius: 22px;
            padding: 1.6rem;
            border: 1px solid var(--store-border);
            box-shadow: var(--store-shadow-soft);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
            display: grid;
            gap: 0.9rem;
        }

        body.store-page .package-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--store-accent-cool), var(--store-accent));
        }

        body.store-page .package-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--store-shadow);
        }

        body.store-page .network-group-mtn .packages-grid,
        body.store-page .network-group-at .packages-grid,
        body.store-page .network-group-telecel .packages-grid {
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
        }

        body.store-page .package-card-mtn {
            background: linear-gradient(180deg, #ffcd16 0%, #ffc400 100%);
            border: 1px solid rgba(214, 163, 0, 0.65);
            box-shadow: 0 14px 28px rgba(196, 140, 0, 0.22);
            color: #111827;
            gap: 1.25rem;
        }

        body.store-page .package-card-mtn::before,
        body.store-page .package-card-at::before,
        body.store-page .package-card-telecel::before {
            display: none;
        }

        body.store-page .package-card-mtn:hover {
            box-shadow: 0 18px 34px rgba(196, 140, 0, 0.28);
        }

        body.store-page .package-card-mtn-head,
        body.store-page .package-card-at-head,
        body.store-page .package-card-telecel-head {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.85rem;
            min-height: 88px;
            text-align: center;
        }

        body.store-page .package-card-mtn-logo,
        body.store-page .package-card-at-logo,
        body.store-page .package-card-telecel-logo {
            flex: 0 0 52px;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        body.store-page .package-card-mtn-logo {
            background: rgba(255, 235, 140, 0.45);
            box-shadow: inset 0 0 0 1px rgba(17, 24, 39, 0.12);
        }

        body.store-page .package-card-mtn-logo img,
        body.store-page .package-card-at-logo img,
        body.store-page .package-card-telecel-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        body.store-page .package-card-mtn-copy,
        body.store-page .package-card-at-copy,
        body.store-page .package-card-telecel-copy {
            display: grid;
            gap: 0.3rem;
            align-content: start;
            justify-items: center;
            text-align: center;
        }

        body.store-page .package-card-mtn-copy h4,
        body.store-page .package-card-at-copy h4,
        body.store-page .package-card-telecel-copy h4 {
            margin: 0;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.04em;
            font-family: "Space Grotesk", "Work Sans", sans-serif;
        }

        body.store-page .package-card-mtn-copy h4,
        body.store-page .package-price-mtn,
        body.store-page .package-card-mtn-copy p {
            color: #111827;
        }

        body.store-page .package-card-mtn-copy p,
        body.store-page .package-card-at-copy p,
        body.store-page .package-card-telecel-copy p {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.35;
        }

        body.store-page .package-price-mtn,
        body.store-page .package-price-at,
        body.store-page .package-price-telecel {
            margin-top: auto;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            font-family: "Space Grotesk", "Work Sans", sans-serif;
            text-align: center;
            width: 100%;
            justify-self: center;
        }

        body.store-page .package-card-mtn .custom-price-badge {
            background: rgba(17, 24, 39, 0.82);
            color: #fff7c2;
            margin-left: 0;
        }

        body.store-page .package-card-mtn-btn,
        body.store-page .package-card-at-btn,
        body.store-page .package-card-telecel-btn {
            min-height: 50px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            font-weight: 800;
        }

        body.store-page .package-card-mtn-btn {
            border: 1px solid rgba(186, 140, 0, 0.58);
            background: linear-gradient(180deg, #f8c800 0%, #f0be00 100%);
            color: #111827;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35);
        }

        body.store-page .package-card-mtn-btn:hover {
            transform: translateY(-1px);
            background: linear-gradient(180deg, #ffd321 0%, #f4c400 100%);
            color: #111827;
        }

        body.store-page .package-card-at {
            background: linear-gradient(180deg, #173fae 0%, #153da8 100%);
            border: 1px solid rgba(125, 160, 255, 0.22);
            box-shadow: 0 16px 30px rgba(12, 37, 106, 0.24);
            color: #f8fbff;
            gap: 1.25rem;
        }

        body.store-page .package-card-at:hover {
            box-shadow: 0 20px 38px rgba(12, 37, 106, 0.34);
        }

        body.store-page .package-card-at-logo {
            background: rgba(255, 255, 255, 0.96);
            box-shadow: inset 0 0 0 1px rgba(12, 37, 106, 0.08);
        }

        body.store-page .package-card-at-copy h4,
        body.store-page .package-card-at-copy p,
        body.store-page .package-card-at .package-price,
        body.store-page .package-price-at {
            color: #ffffff;
        }

        body.store-page .package-card-at-copy p span {
            display: block;
        }

        body.store-page .package-card-at .custom-price-badge,
        body.store-page .package-card-telecel .custom-price-badge {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            margin-left: 0;
        }

        body.store-page .package-card-at-btn {
            border: 1px solid rgba(118, 154, 255, 0.45);
            background: linear-gradient(180deg, rgba(71, 112, 220, 0.92) 0%, rgba(56, 98, 205, 0.92) 100%);
            color: #ffffff;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        body.store-page .package-card-at-btn:hover {
            transform: translateY(-1px);
            background: linear-gradient(180deg, rgba(82, 123, 231, 0.96) 0%, rgba(63, 105, 214, 0.96) 100%);
            color: #ffffff;
        }

        body.store-page .package-card-telecel {
            background: linear-gradient(180deg, #f20505 0%, #eb0000 100%);
            border: 1px solid rgba(255, 170, 170, 0.18);
            box-shadow: 0 16px 30px rgba(163, 5, 5, 0.26);
            color: #ffffff;
            gap: 1.25rem;
        }

        body.store-page .package-card-telecel:hover {
            box-shadow: 0 20px 38px rgba(163, 5, 5, 0.36);
        }

        body.store-page .package-card-telecel-logo {
            background: rgba(255, 42, 42, 0.92);
            box-shadow: inset 0 0 0 1px rgba(255, 205, 205, 0.35);
        }

        body.store-page .package-card-telecel-copy h4,
        body.store-page .package-card-telecel-copy p,
        body.store-page .package-card-telecel .package-price,
        body.store-page .package-price-telecel {
            color: #ffffff !important;
        }

        body.store-page .package-card-telecel-copy p {
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: 0.02em;
        }

        body.store-page .package-card-telecel-btn {
            border: 1px solid rgba(255, 167, 167, 0.32);
            background: linear-gradient(180deg, #ff2323 0%, #f91c1c 100%);
            color: #ffffff;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        body.store-page .package-card-telecel-btn:hover {
            transform: translateY(-1px);
            background: linear-gradient(180deg, #ff3838 0%, #ff2323 100%);
            color: #ffffff;
        }

        body.store-page .package-header h4 {
            margin: 0;
            color: var(--store-accent-cool);
            font-size: 1.15rem;
        }

        body.store-page .package-network {
            background: rgba(139, 92, 246, 0.18);
            color: var(--store-accent);
            padding: 0.3rem 0.8rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08rem;
        }

        body.store-page .package-size,
        body.store-page .package-validity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--store-muted);
            font-size: 0.92rem;
        }

        body.store-page .package-size i,
        body.store-page .package-validity i {
            color: var(--store-accent);
        }

        body.store-page .package-price {
            grid-column: 1 / -1;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--store-accent-strong);
            text-align: center;
            font-family: "Space Grotesk", "Work Sans", sans-serif;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
        }

        body.store-page .custom-price-badge {
            background: var(--store-accent-cool);
            color: #fff;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            font-size: 0.7rem;
            margin-left: 0;
            text-transform: uppercase;
            letter-spacing: 0.05rem;
        }

        body.store-page .package-description {
            color: var(--store-muted);
            font-size: 0.9rem;
            margin: 0;
        }

        body.store-page .package-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        body.store-page .package-actions .btn {
            flex: 1 1 140px;
            justify-content: center;
        }

        body.store-page .store-footer {
            margin-top: 4rem;
            padding: 3.25rem 0 1.6rem;
            background:
                radial-gradient(circle at 12% 0%, rgba(250, 204, 21, 0.16), transparent 30%),
                linear-gradient(135deg, #080014 0%, #12051e 48%, #05020a 100%);
            color: #f8fafc;
            border-top: 1px solid rgba(250, 204, 21, 0.22);
        }

        [data-theme="dark"] body.store-page .store-footer {
            background:
                radial-gradient(circle at 12% 0%, rgba(250, 204, 21, 0.16), transparent 30%),
                linear-gradient(135deg, #080014 0%, #12051e 48%, #05020a 100%);
        }

        body.store-page .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.25rem;
            flex-wrap: wrap;
        }

        body.store-page .store-contact {
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            min-width: 0;
        }

        body.store-page .footer-brand-mark {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 54px;
            background: rgba(250, 204, 21, 0.13);
            border: 1px solid rgba(250, 204, 21, 0.26);
            color: #facc15;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.22);
        }

        body.store-page .footer-brand-copy {
            min-width: 0;
        }

        body.store-page .store-contact h4 {
            margin: 0 0 0.45rem;
            color: #ffffff;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0;
        }

        body.store-page .footer-contact-links {
            display: flex;
            flex-direction: column;
            gap: 0.42rem;
        }

        body.store-page .store-contact a {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            max-width: 100%;
            color: rgba(248, 250, 252, 0.82);
            text-decoration: none;
            font-size: 0.95rem;
            overflow-wrap: anywhere;
        }

        body.store-page .store-contact a:hover {
            color: #facc15;
        }

        body.store-page .store-contact p {
            margin: 0;
            color: rgba(248, 250, 252, 0.8);
        }

        body.store-page .powered-by {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            min-height: 42px;
            padding: 0 0.9rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            font-size: 0.9rem;
            color: rgba(248, 250, 252, 0.7);
        }

        body.store-page .powered-by strong {
            color: #3b82f6;
            font-weight: 800;
        }

        body.store-page .footer-divider {
            margin-top: 2.25rem;
            padding-top: 1.05rem;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            text-align: center;
        }

        body.store-page .footer-divider p {
            margin: 0;
            color: rgba(248, 250, 252, 0.6);
            font-size: 0.85rem;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        body.store-page .hero-section,
        body.store-page .feature-item,
        body.store-page .package-card {
            animation: fadeUp 0.6s ease both;
        }

        body.store-page .feature-item:nth-child(2) { animation-delay: 0.08s; }
        body.store-page .feature-item:nth-child(3) { animation-delay: 0.16s; }
        body.store-page .package-card:nth-child(2) { animation-delay: 0.06s; }
        body.store-page .package-card:nth-child(3) { animation-delay: 0.12s; }
        body.store-page .package-card:nth-child(4) { animation-delay: 0.18s; }

        html,
        body.store-page {
            width: 100%;
            overflow-x: hidden;
        }

        body.store-page .navbar,
        body.store-page .main-content {
            overflow-x: hidden;
        }

        body.store-page,
        body.store-page * {
            box-sizing: border-box;
        }

        body.store-page .hero-section {
            max-width: 100%;
        }

        body.store-page .hero-buttons,
        body.store-page .hero-buttons .btn {
            max-width: 100%;
        }

        body.store-page img {
            max-width: 100%;
            height: auto;
        }

        body.store-page .package-header h4,
        body.store-page .hero-section h1,
        body.store-page .hero-description,
        body.store-page .store-contact p {
            word-break: break-word;
        }

        @media (prefers-reduced-motion: reduce) {
            body.store-page .hero-section,
            body.store-page .feature-item,
            body.store-page .package-card {
                animation: none;
            }
        }

        @media (max-width: 960px) {
            body.store-page {
                --store-nav-offset: 190px;
            }

            body.store-page .navbar .container {
                flex-direction: row;
                align-items: center;
                flex-wrap: wrap;
            }

            body.store-page .nav-actions {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            body.store-page .hero-section {
                text-align: center;
            }

            body.store-page .hero-buttons {
                justify-content: center;
            }

            body.store-page .service-selector-card,
            body.store-page .service-page-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 720px) {
            body.store-page {
                --store-nav-offset: 96px;
            }

            body.store-page .navbar .container {
                padding: 0.85rem 0.85rem 1rem;
                position: relative;
            }

            body.store-page .brand-copy strong {
                font-size: 0.9rem;
            }

            body.store-page .nav-menu-toggle {
                display: inline-flex;
                flex-direction: column;
                justify-self: end;
                margin-left: auto;
            }

            body.store-page .nav-actions {
                display: none;
                flex: 0 0 100%;
                width: 100%;
                margin-top: 0.9rem;
                padding-top: 0.7rem;
                border-top: 1px solid var(--store-nav-border);
                gap: 0.15rem;
                flex-direction: column;
                justify-content: flex-start;
            }

            body.store-page .nav-actions.is-open {
                display: flex;
            }

            body.store-page .nav-actions .store-quick-link {
                min-height: 42px;
                padding: 0.65rem 0;
                font-size: 0.96rem;
                border-radius: 0;
                justify-content: flex-start;
                width: 100%;
                white-space: normal;
                border: 0;
                background: transparent;
                box-shadow: none;
                color: var(--store-ink);
            }

            body.store-page .nav-actions .store-quick-link i {
                display: none;
            }

            body.store-page .nav-actions .store-theme-toggle i {
                display: inline-flex;
                width: 1.35rem;
                height: 1.35rem;
                align-items: center;
                justify-content: center;
                background: var(--store-chip-icon-bg);
                color: var(--store-accent);
                font-size: 0.95rem;
                margin-right: 0.15rem;
            }

            body.store-page .nav-actions .store-quick-link:hover {
                transform: none;
            }

            body.store-page .nav-actions .menu-link-primary {
                min-height: 54px;
                margin-top: 0.85rem;
                justify-content: center;
                border-radius: 999px;
                background: linear-gradient(135deg, var(--store-accent) 0%, var(--store-accent-cool) 100%);
                color: #ffffff;
                box-shadow: 0 16px 30px rgba(23, 63, 174, 0.2);
                font-weight: 700;
            }

            body.store-page .nav-actions .menu-link-primary i {
                display: inline-flex;
                width: 1.35rem;
                height: 1.35rem;
                align-items: center;
                justify-content: center;
                background: transparent;
                color: #ffffff;
                font-size: 0.95rem;
                margin-right: 0.15rem;
            }

            body.store-page .nav-actions .menu-link-home { order: 1; }
            body.store-page .nav-actions .menu-link-register { order: 2; }
            body.store-page .nav-actions .menu-link-status { order: 3; }
            body.store-page .nav-actions .menu-link-verify { order: 4; }
            body.store-page .nav-actions .store-theme-toggle { order: 5; }
            body.store-page .nav-actions .menu-link-primary { order: 20; }

            body.store-page .service-selector-grid {
                grid-template-columns: 1fr;
            }

            body.store-page .service-card {
                min-height: 190px;
            }

            body.store-page .hero-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            body.store-page .package-details {
                grid-template-columns: 1fr;
            }

            body.store-page .package-card-mtn,
            body.store-page .package-card-at,
            body.store-page .package-card-telecel {
                padding: 1.25rem;
                border-radius: 18px;
            }

            body.store-page .package-card-mtn-head,
            body.store-page .package-card-at-head,
            body.store-page .package-card-telecel-head {
                gap: 0.75rem;
                min-height: 72px;
            }

            body.store-page .package-card-mtn-logo,
            body.store-page .package-card-at-logo,
            body.store-page .package-card-telecel-logo {
                width: 48px;
                height: 48px;
                border-radius: 12px;
            }

            body.store-page .package-card-mtn-copy h4,
            body.store-page .package-price-mtn,
            body.store-page .package-card-at-copy h4,
            body.store-page .package-price-at,
            body.store-page .package-card-telecel-copy h4,
            body.store-page .package-price-telecel {
                font-size: 1.7rem;
            }

            body.store-page .footer-content {
                flex-direction: column;
                text-align: center;
            }

            body.store-page .store-contact {
                flex-direction: column;
                justify-content: center;
            }

            body.store-page .store-contact a {
                justify-content: center;
            }

            body.store-page .footer-contact-links {
                align-items: center;
            }
        }
    </style>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
