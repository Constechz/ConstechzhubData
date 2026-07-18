<?php
require_once __DIR__ . '/../config/config.php';

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
                --shell-bg: #F1E9DA;
                --shell-card: #F1E9DA;
                --shell-text: #2E294E;
                --shell-muted: #541388;
                --shell-accent: #FFD400;
                --shell-border: rgba(46, 41, 78, 0.08);
            }
            [data-theme="dark"] {
                --shell-bg: #2E294E;
                --shell-card: #2E294E;
                --shell-text: #F1E9DA;
                --shell-muted: #F1E9DA;
                --shell-accent: #FFD400;
                --shell-border: rgba(241, 233, 218, 0.08);
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
                background: radial-gradient(circle at top, rgba(255, 212, 0, 0.18), transparent 60%);
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
                color: #F1E9DA;
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
                box-shadow: 0 14px 40px rgba(46, 41, 78, 0.08);
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
        <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
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
                            <a href="?store=<?php echo urlencode($store['store_slug']); ?>">Browse store <i class="fas fa-arrow-right"></i></a>
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
        WHERE ast.is_active = 1 AND u.status = 'active'
        ORDER BY ast.store_name ASC
        LIMIT 30
    ";
    if ($result = $db->query($query)) {
        $stores = $result->fetch_all(MYSQLI_ASSOC);
    }
    return $stores;
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
           u.agent_logo
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
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

// Log store visit
$visitor_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? '';

$stmt = $db->prepare("INSERT INTO store_visits (store_id, visitor_ip, user_agent, referrer) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $store['id'], $visitor_ip, $user_agent, $referrer);
$stmt->execute();

// Determine session helper flags
$is_customer = isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'customer';

// Fetch packages with pricing hierarchy (agent custom > customer price > legacy price)
$packages = [];
$stmt = $db->prepare("
    SELECT
        dp.id,
        dp.name,
        dp.package_type,
        dp.data_size,
        dp.validity_days,
        dp.description,
        n.name AS network,
        COALESCE(acp.custom_price, pp.price, dp.price) AS display_price,
        CASE WHEN acp.custom_price IS NOT NULL THEN 1 ELSE 0 END AS has_custom_price
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    WHERE dp.status = 'active'
    ORDER BY n.name, CAST(REGEXP_REPLACE(dp.data_size, '[^0-9.]', '') AS DECIMAL(10,2))
");
$stmt->bind_param('i', $agent_id);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Data Bundle Store</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <meta name="description" content="<?php echo htmlspecialchars($store['store_description']); ?>">
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
                        <i class="fas fa-store" style="color: #F1E9DA; font-size: 1.2rem;"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="nav-actions">
                <a href="<?php echo SITE_URL; ?>" class="btn btn-outline">
                    <i class="fas fa-home"></i>
                    Home
                </a>
                <a href="reference.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                    <i class="fas fa-search"></i>
                    Check Status
                </a>
                <?php if (isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer'): ?>
                    <a href="<?php echo SITE_URL; ?>/customer/dashboard.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-primary">
                        <i class="fas fa-th-large"></i>
                        Go to Dashboard
                    </a>
                <?php else: ?>
                    <a href="guest-checkout.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                        <i class="fas fa-bolt"></i>
                        Buy as Guest
                    </a>
                    <a href="login.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug); ?>" class="btn btn-outline">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </a>
                    <a href="register.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug); ?>" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i>
                        Register
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <div class="hero-section">
                <h1>Welcome to <?php echo htmlspecialchars($store['store_name']); ?></h1>
                <p class="hero-description">
                    <?php echo $store['store_description']
                        ? htmlspecialchars($store['store_description'])
                        : 'Get access to affordable data bundles for all major networks in Ghana. Enjoy competitive rates, instant delivery, and secure transactions.'; ?>
                </p>
                <p class="hero-subtitle">Login, register, or checkout as a guest to start purchasing data packages.</p>
                
                <div class="hero-buttons">
                    <?php if (isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer'): ?>
                        <a href="<?php echo SITE_URL; ?>/customer/dashboard.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-primary">
                            <i class="fas fa-th-large"></i>
                            Go to Dashboard
                        </a>
                    <?php else: ?>
                        <a href="guest-checkout.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                            <i class="fas fa-bolt"></i>
                            Buy as Guest
                        </a>
                        <a href="login.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug); ?>" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i>
                            Login to Your Account
                        </a>
                        <a href="register.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode(SITE_URL . '/customer/dashboard.php?store=' . $store_slug); ?>" class="btn btn-outline">
                            <i class="fas fa-user-plus"></i>
                            Create New Account
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="store-notifications">
                <?php echo renderNotificationSlides('all'); ?>
            </div>
            
            <section class="features-section">
                <h2>Why Choose <?php echo htmlspecialchars($store['store_name']); ?>?</h2>
                
                <div class="features-list">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Instant Delivery</h3>
                            <p>Data delivered instantly to your phone</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Secure & Reliable</h3>
                            <p>Safe transactions and guaranteed delivery</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-tags"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Best Prices</h3>
                            <p>Competitive rates for all networks</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <?php if (!empty($grouped_packages)): ?>
        <section class="packages-section" id="packages">
            <div class="container">
                <?php foreach ($grouped_packages as $network => $networkPackages): ?>
                    <div class="network-group">
                        <h3 class="network-title"><?php echo htmlspecialchars($network); ?> Bundles</h3>
                        <div class="packages-grid">
                            <?php foreach ($networkPackages as $package): ?>
                            <?php
                                $packageAnchor = 'package-' . (int) $package['id'];
                                if ($is_customer) {
                                    $cta_link = SITE_URL . '/customer/buy-data.php?store=' . urlencode($store_slug) . '#package-card-' . (int) $package['id'];
                                } else {
                                    $loginRedirect = SITE_URL . '/customer/buy-data.php?store=' . urlencode($store_slug);
                                    $cta_link = 'login.php?store=' . urlencode($store_slug) . '&redirect=' . urlencode($loginRedirect);
                                    $guest_link = 'guest-checkout.php?store=' . urlencode($store_slug) . '&package_id=' . (int) $package['id'];
                                }
                            ?>
                            <div class="package-card" id="<?php echo htmlspecialchars($packageAnchor); ?>">
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
                                        <?php echo formatCurrency((float) $package['display_price']); ?>
                                        <?php if (!empty($package['has_custom_price'])): ?>
                                            <span class="custom-price-badge">Store Offer</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($package['description'])): ?>
                                    <p class="package-description"><?php echo htmlspecialchars($package['description']); ?></p>
                                <?php endif; ?>
                                <?php if ($is_customer): ?>
                                    <a class="btn btn-primary purchase-btn" href="<?php echo htmlspecialchars($cta_link); ?>">
                                        Buy Now
                                    </a>
                                <?php else: ?>
                                    <div class="package-actions">
                                        <a class="btn btn-primary purchase-btn" href="<?php echo htmlspecialchars($guest_link ?? '#'); ?>">
                                            Buy as Guest
                                        </a>
                                        <a class="btn btn-outline purchase-btn" href="<?php echo htmlspecialchars($cta_link); ?>">
                                            Login
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php else: ?>
            <div class="container">
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No packages available right now. Please check back soon.</p>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Store Footer -->
    <footer class="store-footer">
        <div class="container">
            <div class="footer-content">
                <div class="store-contact">
                    <h4><?php echo htmlspecialchars($store['store_name']); ?></h4>
                    <p>Contact: <?php echo htmlspecialchars($store['agent_email']); ?></p>
                </div>
                
                <div class="powered-by">
                    <p>
                        Powered by <strong><?php echo htmlspecialchars(getSiteName()); ?></strong>
                    </p>
                </div>
            </div>
            
            <div class="footer-divider">
                <p>
                    Ãƒâ€šÃ‚Â© <?php echo date('Y'); ?> <?php echo htmlspecialchars($store['store_name']); ?>. All rights reserved.
                </p>
            </div>
        </div>
    </footer>

    <script>
        // Initialize theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
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
            color: #F1E9DA;
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
            color: #F1E9DA;
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
            background: #F1E9DA;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(46, 41, 78, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .package-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 15px rgba(46, 41, 78, 0.15);
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
            color: #F1E9DA;
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
            color: #F1E9DA;
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
            background-color: rgba(46, 41, 78, 0.5);
        }
        
        .modal-content {
            background-color: #F1E9DA;
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
            color: #F1E9DA;
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
            --store-accent: var(--brand-primary);
            --store-accent-strong: var(--brand-primary);
            --store-accent-cool: var(--brand-secondary);
            --store-card: var(--bg-primary);
            --store-border: var(--border-color);
            --store-shadow: var(--shadow-lg);
            --store-shadow-soft: var(--shadow);
            --store-glow: rgba(84, 19, 136, 0.35);
            --primary-color: var(--brand-primary);
            --bg-dark: var(--dark-bg);
            --bg-light: var(--bg-secondary);
            --success-color: var(--brand-secondary);
            font-family: "Work Sans", "Trebuchet MS", "Segoe UI", sans-serif;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            color: var(--store-ink);
        }

        [data-theme="dark"] body.store-page {
            --store-bg: var(--bg-primary);
            --store-ink: var(--text-primary);
            --store-muted: var(--text-secondary);
            --store-card: var(--bg-primary);
            --store-border: var(--border-color);
            --store-shadow: var(--shadow-lg);
            --store-shadow-soft: var(--shadow);
            --store-glow: rgba(84, 19, 136, 0.35);
            --primary-color: var(--brand-primary);
            --bg-dark: var(--dark-bg);
            --bg-light: var(--bg-secondary);
            --success-color: var(--brand-secondary);
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
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
            background: rgba(241, 233, 218, 0.85);
            border-bottom: 1px solid var(--store-border);
            backdrop-filter: blur(14px);
        }

        [data-theme="dark"] body.store-page .navbar {
            background: rgba(46, 41, 78, 0.9);
        }

        body.store-page .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            flex-wrap: wrap;
        }

        body.store-page .nav-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        body.store-page .nav-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            flex: 1 1 420px;
            min-width: 0;
            justify-content: flex-end;
        }

        body.store-page .main-content {
            padding-top: var(--store-nav-offset);
        }

        body.store-page .nav-actions .btn {
            white-space: nowrap;
            flex-shrink: 1;
            max-width: 100%;
        }

        body.store-page .btn {
            border-radius: 999px;
            padding: 0.8rem 1.6rem;
            font-weight: 600;
            border: 1px solid transparent;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        body.store-page .btn-primary {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            color: #F1E9DA;
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
            background: rgba(84, 19, 136, 0.28);
        }

        body.store-page .hero-section::after {
            width: 200px;
            height: 200px;
            right: 20%;
            bottom: -120px;
            background: rgba(241, 233, 218, 0.28);
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

        body.store-page .store-notifications {
            margin: 1.5rem auto 3rem;
        }

        body.store-page .store-notifications .notification-slider {
            max-width: 980px;
            margin: 0 auto;
        }

        body.store-page .store-notifications .notification-slide .alert {
            background: var(--store-card);
            border: 1px solid var(--store-border);
            color: var(--store-ink);
            box-shadow: var(--store-shadow-soft);
        }

        body.store-page .store-notifications .notification-cta {
            border-color: var(--store-accent);
            color: var(--store-accent);
        }

        body.store-page .store-notifications .notification-media {
            border-color: var(--store-border);
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
            background: rgba(84, 19, 136, 0.18);
            color: var(--store-accent);
            font-size: 1.2rem;
        }

        body.store-page .feature-content p {
            margin: 0;
            color: var(--store-muted);
        }

        body.store-page .network-title {
            color: var(--store-ink);
            margin-bottom: 1.75rem;
            padding-bottom: 0.6rem;
            border-bottom: 2px solid rgba(84, 19, 136, 0.35);
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

        body.store-page .package-header h4 {
            margin: 0;
            color: var(--store-accent-cool);
            font-size: 1.15rem;
        }

        body.store-page .package-network {
            background: rgba(84, 19, 136, 0.18);
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
            text-align: left;
            font-family: "Space Grotesk", "Work Sans", sans-serif;
        }

        body.store-page .custom-price-badge {
            background: var(--store-accent-cool);
            color: #F1E9DA;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            font-size: 0.7rem;
            margin-left: 0.35rem;
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
            padding: 3rem 0 2rem;
            background: var(--dark-bg);
            color: #F1E9DA;
        }

        [data-theme="dark"] body.store-page .store-footer {
            background: var(--dark-bg);
        }

        body.store-page .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        body.store-page .store-contact p {
            margin: 0;
            color: rgba(241, 233, 218, 0.8);
        }

        body.store-page .powered-by p {
            margin: 0;
            font-size: 0.9rem;
            color: rgba(241, 233, 218, 0.7);
        }

        body.store-page .powered-by strong {
            color: var(--store-accent);
        }

        body.store-page .footer-divider {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(241, 233, 218, 0.12);
            text-align: center;
        }

        body.store-page .footer-divider p {
            margin: 0;
            color: rgba(241, 233, 218, 0.6);
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
            body.store-page .navbar .container {
                flex-direction: row;
                align-items: center;
                flex-wrap: wrap;
            }

            body.store-page .hero-section {
                text-align: center;
            }

            body.store-page .hero-buttons {
                justify-content: center;
            }
        }

        @media (max-width: 720px) {
            body.store-page {
                --store-nav-offset: 140px;
            }

            body.store-page .nav-actions {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            body.store-page .nav-actions .btn {
                flex: 1 1 140px;
                padding: 0.55rem 0.85rem;
                font-size: 0.78rem;
            }

            body.store-page .hero-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            body.store-page .package-details {
                grid-template-columns: 1fr;
            }

            body.store-page .footer-content {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
