<?php
require_once __DIR__ . '/config/config.php';

$requestPath = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($requestPath === 's') {
    header('Location: ' . rtrim((string) SITE_URL, '/') . '/store/index.php');
    exit();
}

if (preg_match('/^s\/([A-Za-z0-9_-]+)$/', $requestPath, $storeMatches)) {
    $_GET['store'] = $storeMatches[1];
    require __DIR__ . '/store/index.php';
    exit();
}

require_once __DIR__ . '/includes/seo.php';

preventBrowserCaching();

$isLoggedIn = isLoggedIn();
$role = $_SESSION['user_role'] ?? null;
$primaryCta = [
    'label' => $isLoggedIn ? 'Open Dashboard' : 'Create Account',
    'link'  => $isLoggedIn
        ? ($role === 'admin'
            ? SITE_URL . '/admin/dashboard.php'
            : ($role === 'agent'
                ? SITE_URL . '/agent/dashboard.php'
                : SITE_URL . '/customer/dashboard.php'))
        : SITE_URL . '/register.php'
];
$secondaryCta = [
    'label' => $isLoggedIn ? 'Support Center' : 'Sign In',
    'link'  => $isLoggedIn ? SITE_URL . '/support.php' : SITE_URL . '/login.php'
];
$siteName = htmlspecialchars(getSiteName(), ENT_QUOTES, 'UTF-8');
$year = date('Y');
$whatsappSetting = trim((string) getSetting('site_whatsapp_number', '0249020304'));
$whatsappDisplay = $whatsappSetting !== '' ? $whatsappSetting : '0249020304';
$whatsappDigits = preg_replace('/\D+/', '', $whatsappDisplay);
if (strpos($whatsappDigits, '233') === 0) {
    $whatsappInternational = $whatsappDigits;
} elseif (strpos($whatsappDigits, '0') === 0 && strlen($whatsappDigits) >= 9) {
    $whatsappInternational = '233' . substr($whatsappDigits, 1);
} elseif ($whatsappDigits !== '') {
    $whatsappInternational = '233' . ltrim($whatsappDigits, '0');
} else {
    $whatsappInternational = '233249020304';
}
$whatsappLink = 'https://wa.me/' . $whatsappInternational . '?text=' . urlencode('Hi ' . strip_tags($siteName) . ' team, I need assistance.');
$whatsappChannelRaw = trim((string) getSetting('whatsapp_channel_url', ''));
$whatsappChannelLink = filter_var($whatsappChannelRaw, FILTER_VALIDATE_URL) ? $whatsappChannelRaw : '';
$communityLink = $whatsappChannelLink !== '' ? $whatsappChannelLink : $whatsappLink;
$communityLabel = $whatsappChannelLink !== '' ? 'Join Community' : 'Contact Admin';
$heroPrimary = $secondaryCta;
$heroSecondary = $primaryCta;

$featureList = [
    ['icon' => 'fa-bolt', 'title' => 'Lightning Fast Delivery', 'body' => 'Automated fulfilment pushes data orders through immediately so customers are not left waiting.'],
    ['icon' => 'fa-upload', 'title' => 'Bulk Order Processing', 'body' => 'Upload spreadsheets or paste structured text to process large order batches in one flow.'],
    ['icon' => 'fa-wallet', 'title' => 'Smart Wallet System', 'body' => 'Fund wallets, track balances, and move from payment to delivery without manual reconciliation.'],
    ['icon' => 'fa-shield-halved', 'title' => 'Secure and Reliable', 'body' => 'Role-based access and controlled workflows keep customer transactions protected end to end.'],
    ['icon' => 'fa-headset', 'title' => 'Always-On Support', 'body' => 'WhatsApp and in-app support keep your team connected when customers need a quick resolution.'],
    ['icon' => 'fa-chart-line', 'title' => 'Analytics and Reports', 'body' => 'Monitor transactions, wallet movement, and platform health from a single operational view.'],
];

$stats = [
    ['value' => '10K+', 'label' => 'Happy Customers'],
    ['value' => '50K+', 'label' => 'Orders Processed'],
    ['value' => '99.9%', 'label' => 'Platform Uptime'],
    ['value' => '24/7', 'label' => 'Support Access'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php echo generateSeoMeta($siteName, 'Affordable data services for everyone with fast fulfilment, flexible ordering and dependable support.'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <style>
        :root {
            --primary-color: #2f63d9;
            --secondary-color: #1f4fb8;
            --accent-color: #f59e0b;
            --dark-color: #1f2937;
            --dark-surface: #111827;
            --light-color: #f8fafc;
            --surface: #ffffff;
            --text-color: #374151;
            --heading-color: #111827;
            --border-color: #e5e7eb;
            --shadow-soft: 0 18px 50px rgba(15, 23, 42, 0.08);
            --shadow-strong: 0 24px 60px rgba(37, 99, 235, 0.18);
            --container: min(1140px, calc(100% - 2rem));
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text-color);
            background: var(--light-color);
            line-height: 1.6;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            max-width: 100%;
            display: block;
        }

        .container {
            width: var(--container);
            margin: 0 auto;
        }

        .site-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid rgba(229, 231, 235, 0.9);
            backdrop-filter: blur(12px);
            transition: box-shadow 0.25s ease, background 0.25s ease;
        }

        .site-header.is-scrolled {
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08);
        }

        .site-header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            min-height: 84px;
        }

        .brand {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--primary-color);
            letter-spacing: -0.03em;
            white-space: nowrap;
        }

        .nav-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: #fff;
            color: var(--heading-color);
            cursor: pointer;
        }

        .nav-shell {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex: 1;
            justify-content: flex-end;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 1.4rem;
        }

        .nav-links a {
            font-size: 0.98rem;
            font-weight: 500;
            position: relative;
            color: var(--text-color);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -0.45rem;
            width: 0;
            height: 2px;
            border-radius: 999px;
            background: var(--primary-color);
            transform: translateX(-50%);
            transition: width 0.25s ease;
        }

        .nav-links a:hover::after,
        .nav-links a:focus-visible::after {
            width: 100%;
        }

        .nav-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            padding: 0.85rem 1.5rem;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: #fff;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.28);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .nav-cta:hover,
        .btn-primary:hover,
        .btn-secondary:hover,
        .btn-contact:hover,
        .scroll-top:hover {
            transform: translateY(-2px);
        }

        .nav-cta:hover,
        .btn-primary:hover,
        .scroll-top:hover {
            box-shadow: 0 16px 34px rgba(37, 99, 235, 0.28);
        }

        .hero {
            position: relative;
            min-height: 100vh;
            padding: 8.5rem 0 5.5rem;
            display: flex;
            align-items: center;
            overflow: hidden;
            background: linear-gradient(135deg, #4f7cf0 0%, #2f63d9 52%, #1f4fb8 100%);
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.22), transparent 28%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, transparent 58%),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 800'%3E%3Cpolygon fill='rgba(255,255,255,0.08)' points='0,800 800,0 800,800'/%3E%3C/svg%3E");
            background-size: auto, auto, cover;
            pointer-events: none;
        }

        .hero .container {
            position: relative;
            z-index: 1;
        }

        .hero-panel {
            max-width: 860px;
            margin: 0 auto;
            text-align: center;
            color: #fff;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 0.92rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .hero h1 {
            margin: 0 0 1.25rem;
            font-size: clamp(2.7rem, 6vw, 4.3rem);
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -0.045em;
        }

        .hero h1 .accent {
            color: var(--accent-color);
            text-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
        }

        .hero p {
            margin: 0 auto;
            max-width: 720px;
            font-size: clamp(1.05rem, 2vw, 1.22rem);
            color: rgba(255, 255, 255, 0.92);
        }

        .hero-actions {
            margin-top: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .btn-primary,
        .btn-secondary,
        .btn-contact {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            min-height: 56px;
            padding: 0.95rem 1.9rem;
            border-radius: 999px;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
        }

        .btn-primary {
            background: var(--accent-color);
            color: #fff;
            box-shadow: 0 14px 28px rgba(245, 158, 11, 0.28);
        }

        .btn-primary:hover {
            background: #d97706;
        }

        .btn-secondary {
            background: transparent;
            color: #fff;
            border: 2px solid rgba(255, 255, 255, 0.78);
        }

        .btn-secondary:hover {
            background: #fff;
            color: var(--primary-color);
        }

        .hero-highlights {
            margin-top: 2.75rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .hero-highlight {
            padding: 1.15rem 1.2rem;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            text-align: left;
        }

        .hero-highlight strong {
            display: block;
            margin-bottom: 0.2rem;
            font-size: 1.05rem;
            color: #fff;
        }

        .hero-highlight span {
            display: block;
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .section {
            padding: 6rem 0;
        }

        .section--white {
            background: #fff;
        }

        .section-title {
            max-width: 680px;
            margin: 0 auto 3.75rem;
            text-align: center;
        }

        .section-title .label {
            display: inline-block;
            margin-bottom: 0.8rem;
            color: var(--primary-color);
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .section-title h2 {
            margin: 0 0 1rem;
            color: var(--heading-color);
            font-size: clamp(2rem, 4vw, 2.9rem);
            line-height: 1.12;
            letter-spacing: -0.035em;
        }

        .section-title p {
            margin: 0;
            font-size: 1.05rem;
            color: #6b7280;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1.5rem;
        }

        .feature-card {
            height: 100%;
            padding: 2.2rem 1.8rem;
            border-radius: 24px;
            background: #fff;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-soft);
            text-align: center;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 26px 54px rgba(15, 23, 42, 0.12);
        }

        .feature-icon {
            width: 82px;
            height: 82px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #fff;
            font-size: 1.9rem;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: var(--shadow-strong);
        }

        .feature-card h3 {
            margin: 0 0 0.8rem;
            color: var(--heading-color);
            font-size: 1.35rem;
        }

        .feature-card p {
            margin: 0;
            color: #6b7280;
        }

        .stats {
            padding: 5.5rem 0;
            color: #fff;
            background: linear-gradient(135deg, var(--dark-color), #374151);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .stat-card {
            padding: 1.4rem 1rem;
            text-align: center;
        }

        .stat-card strong {
            display: block;
            margin-bottom: 0.4rem;
            font-size: clamp(2.2rem, 4vw, 3rem);
            line-height: 1;
            color: var(--accent-color);
            letter-spacing: -0.05em;
        }

        .stat-card span {
            display: block;
            font-size: 1.05rem;
            color: rgba(255, 255, 255, 0.86);
            font-weight: 500;
        }

        .contact {
            padding: 6rem 0;
            background: #fff;
        }

        .contact-card {
            padding: 3.5rem;
            border-radius: 30px;
            text-align: center;
            color: #fff;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 24px 60px rgba(37, 99, 235, 0.28);
        }

        .contact-card h2 {
            margin: 0 0 1rem;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.1;
            letter-spacing: -0.04em;
        }

        .contact-card p {
            max-width: 700px;
            margin: 0 auto;
            font-size: 1.08rem;
            color: rgba(255, 255, 255, 0.92);
        }

        .contact-actions {
            margin-top: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .btn-contact {
            padding-inline: 1.7rem;
        }

        .btn-contact.primary {
            background: var(--accent-color);
            color: #fff;
        }

        .btn-contact.primary:hover {
            background: #d97706;
        }

        .btn-contact.secondary {
            color: #fff;
            border: 2px solid rgba(255, 255, 255, 0.82);
        }

        .btn-contact.secondary:hover {
            background: #fff;
            color: var(--primary-color);
        }

        .footer {
            padding: 2rem 0 2.4rem;
            text-align: center;
            background: var(--dark-color);
            color: rgba(255, 255, 255, 0.84);
            font-size: 0.98rem;
        }

        .scroll-top {
            position: fixed;
            right: 1.4rem;
            bottom: 1.4rem;
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: #fff;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.28);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease, transform 0.2s ease;
            z-index: 950;
        }

        .scroll-top.is-visible {
            opacity: 1;
            visibility: visible;
        }

        @media (max-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .hero-highlights {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 820px) {
            .site-header .container {
                min-height: 76px;
            }

            .nav-toggle {
                display: inline-flex;
            }

            .nav-shell {
                position: absolute;
                top: calc(100% + 0.85rem);
                left: 1rem;
                right: 1rem;
                display: none;
                flex-direction: column;
                align-items: stretch;
                padding: 1rem;
                background: rgba(255, 255, 255, 0.98);
                border: 1px solid var(--border-color);
                border-radius: 24px;
                box-shadow: 0 24px 50px rgba(15, 23, 42, 0.14);
            }

            .nav-shell.is-open {
                display: flex;
            }

            .nav-links {
                flex-direction: column;
                align-items: stretch;
                gap: 0.35rem;
            }

            .nav-links a {
                padding: 0.8rem 0.9rem;
                border-radius: 14px;
            }

            .nav-links a:hover {
                background: #eff6ff;
            }

            .nav-links a::after {
                display: none;
            }

            .nav-cta {
                width: 100%;
                margin-top: 0.35rem;
            }
        }

        @media (max-width: 680px) {
            .hero {
                padding: 7.5rem 0 4.5rem;
            }

            .hero h1 {
                font-size: clamp(2rem, 10vw, 2.45rem);
                line-height: 1.12;
            }

            .hero-actions,
            .contact-actions {
                flex-direction: column;
            }

            .btn-primary,
            .btn-secondary,
            .btn-contact {
                width: 100%;
            }

            .features-grid,
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section,
            .contact {
                padding: 4.5rem 0;
            }

            .contact-card {
                padding: 2.5rem 1.4rem;
            }
        }
    </style>
</head>
<body>
<header id="siteHeader" class="site-header">
    <div class="container">
        <a class="brand" href="<?php echo SITE_URL; ?>/"><?php echo $siteName; ?></a>

        <button id="navToggle" class="nav-toggle" type="button" aria-expanded="false" aria-controls="navShell" aria-label="Open navigation">
            <i class="fas fa-bars"></i>
        </button>

        <div id="navShell" class="nav-shell">
            <nav class="nav-links" aria-label="Primary navigation">
                <a href="#hero">Home</a>
                <a href="<?php echo SITE_URL; ?>/register.php"><?php echo $isLoggedIn ? 'Dashboard' : 'Register'; ?></a>
                <a href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> Contact Admin
                </a>
                <a href="<?php echo htmlspecialchars($communityLink); ?>" target="_blank" rel="noopener">
                    <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($communityLabel); ?>
                </a>
            </nav>

            <a class="nav-cta" href="<?php echo htmlspecialchars($secondaryCta['link']); ?>">
                <i class="fas fa-right-to-bracket"></i>
                <?php echo htmlspecialchars($secondaryCta['label']); ?>
            </a>
        </div>
    </div>
</header>

<main>
    <section id="hero" class="hero">
        <div class="container">
            <div class="hero-panel">
                <h1>Simple and Affordable Data <span class="accent">For Everyone</span></h1>
                <p>
                    <?php echo $siteName; ?> gives customers, agents and resellers fast data fulfilment, wallet-powered checkout,
                    bulk order support and reliable assistance from one clean platform.
                </p>

                <div class="hero-actions">
                    <a class="btn-primary" href="<?php echo htmlspecialchars($heroPrimary['link']); ?>">
                        <i class="fas fa-right-to-bracket"></i>
                        <?php echo htmlspecialchars($heroPrimary['label']); ?>
                    </a>
                    <a class="btn-secondary" href="<?php echo htmlspecialchars($heroSecondary['link']); ?>">
                        <i class="fas fa-user-plus"></i>
                        <?php echo htmlspecialchars($heroSecondary['label']); ?>
                    </a>
                </div>

                <div class="hero-highlights">
                    <div class="hero-highlight">
                        <strong>Automated Delivery</strong>
                        <span>Orders are routed quickly so customers receive value without unnecessary delay.</span>
                    </div>
                    <div class="hero-highlight">
                        <strong>Reseller Ready</strong>
                        <span>Agent workflows, wallet funding and bulk processing are built in from the start.</span>
                    </div>
                    <div class="hero-highlight">
                        <strong>Direct Support</strong>
                        <span>WhatsApp contact and community access stay visible wherever users land.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="section section--white">
        <div class="container">
            <div class="section-title">
                <span class="label">Why Choose Us</span>
                <h2>Comprehensive data solutions with speed, control and reliability.</h2>
                <p>
                    The homepage now follows the reference style, but the content still reflects the real value of your platform:
                    faster fulfilment, wallet-based payments, bulk operations and responsive support.
                </p>
            </div>

            <div class="features-grid">
                <?php foreach ($featureList as $feature): ?>
                    <article class="feature-card">
                        <div class="feature-icon">
                            <i class="fas <?php echo htmlspecialchars($feature['icon']); ?>"></i>
                        </div>
                        <h3><?php echo htmlspecialchars($feature['title']); ?></h3>
                        <p><?php echo htmlspecialchars($feature['body']); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="stats">
        <div class="container">
            <div class="stats-grid">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card">
                        <strong><?php echo htmlspecialchars($stat['value']); ?></strong>
                        <span><?php echo htmlspecialchars($stat['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="contact">
        <div class="container">
            <div class="contact-card">
                <h2>Get In Touch</h2>
                <p>
                    Ready to onboard customers or grow your reseller operation? Reach the admin directly or move into the
                    community channel for updates, announcements and support.
                </p>

                <div class="contact-actions">
                    <a class="btn-contact primary" href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank" rel="noopener">
                        <i class="fab fa-whatsapp"></i>
                        Contact Admin
                    </a>
                    <a class="btn-contact secondary" href="<?php echo htmlspecialchars($communityLink); ?>" target="_blank" rel="noopener">
                        <i class="fab fa-whatsapp"></i>
                        <?php echo htmlspecialchars($communityLabel); ?>
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container">
        <p>&copy; <?php echo htmlspecialchars($year); ?> <strong><?php echo $siteName; ?></strong> | All Rights Reserved</p>
    </div>
</footer>

<a id="scrollTop" class="scroll-top" href="#hero" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</a>

<script>
    (function () {
        const header = document.getElementById('siteHeader');
        const navToggle = document.getElementById('navToggle');
        const navShell = document.getElementById('navShell');
        const scrollTop = document.getElementById('scrollTop');

        function syncHeader() {
            if (window.scrollY > 40) {
                header.classList.add('is-scrolled');
            } else {
                header.classList.remove('is-scrolled');
            }

            if (window.scrollY > 320) {
                scrollTop.classList.add('is-visible');
            } else {
                scrollTop.classList.remove('is-visible');
            }
        }

        function closeMobileNav() {
            navShell.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.innerHTML = '<i class="fas fa-bars"></i>';
        }

        navToggle.addEventListener('click', function () {
            const isOpen = navShell.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            navToggle.innerHTML = isOpen ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-bars"></i>';
        });

        navShell.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 820) {
                    closeMobileNav();
                }
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 820) {
                closeMobileNav();
            }
        });

        window.addEventListener('scroll', syncHeader, { passive: true });
        syncHeader();
    })();
</script>
</body>
</html>
