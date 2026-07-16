<?php
// Prevent direct access
if (!defined('SITE_URL')) {
    exit();
}

$siteName = htmlspecialchars(getSiteName(), ENT_QUOTES, 'UTF-8');

// Determine session helper flags with DB-backed role to avoid stale session role mismatches
$current_user = isLoggedIn() ? getCurrentUser() : null;
$current_role = normalizeUserRole($current_user['role'] ?? ($_SESSION['user_role'] ?? ''));
if ($current_role !== '' && isset($_SESSION['user_role']) && normalizeUserRole($_SESSION['user_role']) !== $current_role) {
    setSessionUserRole($current_role);
}
$is_customer = isLoggedIn() && isCustomerAccountRole($current_role);

$store_root_url = rtrim((string) SITE_URL, '/') . '/store/';
$store_reference_url = $store_root_url . 'reference.php';
$store_register_url = $store_root_url . 'register.php';
$store_verify_payment_url = $store_root_url . 'verify-payment.php';
$store_products_url = $store_root_url . 'products.php';
$store_login_url = $store_root_url . 'login.php';
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
    <title><?php echo htmlspecialchars($store['store_name']); ?> - <?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Data Bundle Store'; ?></title>
    <base href="<?php echo htmlspecialchars($store_root_url); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="nav-brand">
                <?php if (!empty($store['agent_logo'])): ?>
                    <img src="../uploads/agent_logos/<?php echo htmlspecialchars($store['agent_logo']); ?>" alt="<?php echo htmlspecialchars($store['store_name']); ?>" style="width: 40px; height: 40px; border-radius: 8px; object-fit: contain;">
                <?php elseif (!empty($store['store_logo'])): ?>
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
            </a>
            
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
                <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline store-quick-link menu-link-home">
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
                <a href="constchat.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline store-quick-link">
                    <i class="fas fa-comments"></i>
                    Chat
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
