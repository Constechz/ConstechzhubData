<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';

preventBrowserCaching();

$isLoggedIn = isLoggedIn();
$role = $_SESSION['user_role'] ?? null;
$primaryCta = [
    'label' => $isLoggedIn ? 'Open Dashboard' : 'Create account for free',
    'link'  => $isLoggedIn
        ? ($role === 'admin'
            ? SITE_URL . '/admin/dashboard.php'
            : ($role === 'agent'
                ? SITE_URL . '/agent/dashboard.php'
                : SITE_URL . '/customer/dashboard.php'))
        : SITE_URL . '/register.php'
];
$secondaryCta = [
    'label' => $isLoggedIn ? 'Support' : 'Login',
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

$plans = [
    'MTN' => [['1GB', '4.15'], ['2GB', '8.30'], ['3GB', '12.49'], ['4GB', '16.60'], ['5GB', '20.29'], ['6GB', '24.49']],
    'Telecel' => [['5GB', '19.49'], ['10GB', '37.99'], ['15GB', '55.99'], ['20GB', '72.99'], ['25GB', '89.99'], ['30GB', '106']],
    'AirtelTigo' => [['20GB', '56'], ['25GB', '71'], ['30GB', '70'], ['40GB', '87'], ['50GB', '98'], ['60GB', '120']]
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <?php echo generateSeoMeta($siteName, 'Buy data bundles, airtime, and utility services in Ghana with fast delivery.'); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <style>
        :root {
            --bg: #f7f2e8;
            --surface: #fffaf0;
            --surface-2: #efe2ca;
            --ink: #19142f;
            --muted: #675d7c;
            --primary: #541388;
            --accent: #d90368;
            --yellow: #ffd400;
            --border: rgba(25, 20, 47, 0.14);
            --shadow: 0 24px 70px rgba(25, 20, 47, 0.16);
        }
        [data-theme="dark"] {
            --bg: #19142f;
            --surface: #241d43;
            --surface-2: #302653;
            --ink: #fff7e8;
            --muted: rgba(255, 247, 232, 0.76);
            --border: rgba(255, 247, 232, 0.16);
            --shadow: 0 24px 70px rgba(0, 0, 0, 0.35);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; font-size: 15px; }
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--ink);
            font-size: 1rem;
        }
        a { color: inherit; text-decoration: none; }
        .promo {
            background: var(--ink);
            color: var(--surface);
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.65rem 1rem;
            text-align: center;
            font-size: 0.85rem;
        }
        .promo b { color: var(--yellow); }
        .promo a { text-decoration: underline; text-underline-offset: 3px; }
        .nav {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            min-height: 86px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }
        .brand {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary);
        }
        .nav-links, .nav-actions { display: flex; align-items: center; gap: 1rem; }
        .nav-links a {
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 44px;
            padding: 0 1.2rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: rgba(255, 250, 240, 0.58);
            color: var(--ink);
            font-weight: 700;
            white-space: nowrap;
            font-size: 0.95rem;
        }
        [data-theme="dark"] .btn { background: rgba(255, 247, 232, 0.06); }
        .btn-primary {
            border-color: transparent;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fffaf0;
            box-shadow: 0 16px 34px rgba(84, 19, 136, 0.25);
        }
        .theme-toggle {
            width: 44px;
            padding: 0;
            border-radius: 50%;
            cursor: pointer;
        }
        .hero {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 3.2rem 0 5rem;
            display: grid;
            grid-template-columns: minmax(0, 1.02fr) minmax(330px, 0.78fr);
            gap: 3rem;
            align-items: center;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--primary);
            font-weight: 800;
            margin-bottom: 1.2rem;
            font-size: 0.95rem;
        }
        .hero h1 {
            margin: 0;
            font-size: clamp(2.5rem, 6vw, 4.8rem);
            line-height: 1.05;
            letter-spacing: -0.01em;
        }
        .hero h1 span { color: var(--accent); display: block; }
        .hero p {
            max-width: 620px;
            color: var(--muted);
            font-size: 1.05rem;
            line-height: 1.7;
            margin: 1.35rem 0 0;
        }
        .hero-actions { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-top: 1.8rem; }
        .phone-stage {
            position: relative;
            min-height: 530px;
            display: grid;
            place-items: center;
        }
        .shape {
            position: absolute;
            width: min(420px, 92%);
            aspect-ratio: 1;
            border-radius: 46% 54% 48% 52%;
            background: linear-gradient(145deg, var(--yellow), #ffec86 48%, #f6c96f);
            transform: rotate(-10deg);
            box-shadow: var(--shadow);
        }
        .phone {
            position: relative;
            width: min(305px, 82vw);
            min-height: 510px;
            border: 10px solid #18122d;
            border-radius: 38px;
            background: var(--surface);
            box-shadow: 0 28px 60px rgba(25, 20, 47, 0.25);
            padding: 1rem;
            overflow: hidden;
        }
        .phone-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 800;
            margin-bottom: 1rem;
        }
        .balance {
            border-radius: 22px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fffaf0;
            padding: 1rem;
        }
        .balance small { opacity: 0.78; font-size: 0.85rem; }
        .balance strong { display: block; font-size: 1.8rem; margin-top: 0.25rem; }
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin: 1rem 0;
        }
        .quick-tile {
            min-height: 86px;
            border: 1px solid var(--border);
            border-radius: 18px;
            display: grid;
            align-content: center;
            justify-items: center;
            gap: 0.35rem;
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 800;
            background: rgba(255, 255, 255, 0.36);
        }
        .quick-tile i { color: var(--primary); font-size: 1.15rem; }
        .mini-plan {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 16px;
            padding: 0.8rem;
            background: var(--surface-2);
            margin-top: 0.65rem;
            font-weight: 800;
            font-size: 0.9rem;
        }
        .float-card {
            position: absolute;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 0.9rem 1rem;
            font-weight: 800;
            font-size: 0.85rem;
        }
        .float-card.one { top: 70px; left: 0; }
        .float-card.two { right: 0; bottom: 76px; }
        section {
            padding: 5rem max(16px, calc((100% - 1180px) / 2));
        }
        .section-head {
            max-width: 760px;
            margin-bottom: 2rem;
        }
        .section-head small {
            color: var(--primary);
            font-weight: 800;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .section-head h2 {
            margin: 0.45rem 0 0;
            font-size: clamp(1.8rem, 3.5vw, 3.2rem);
            line-height: 1.05;
        }
        .why {
            background: var(--surface);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }
        .compare {
            display: grid;
            grid-template-columns: 0.95fr repeat(2, minmax(140px, 1fr));
            border: 1px solid var(--border);
            border-radius: 24px;
            overflow: hidden;
            background: var(--bg);
            font-size: 0.95rem;
        }
        .compare > div {
            padding: 1.1rem;
            border-bottom: 1px solid var(--border);
            font-weight: 800;
        }
        .compare > div:nth-last-child(-n+3) { border-bottom: 0; }
        .compare .head {
            background: var(--primary);
            color: #fffaf0;
        }
        .compare i.fa-check { color: #139b58; }
        .compare i.fa-minus { color: var(--muted); }
        .feature-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-top: 1.4rem;
        }
        .feature {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.25rem;
            min-height: 132px;
        }
        .feature i { color: var(--accent); font-size: 1.35rem; margin-bottom: 0.75rem; }
        .feature h3 { margin: 0; font-size: 1.05rem; }
        .feature p { font-size: 0.95rem; line-height: 1.6; }
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .plan-card {
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: 22px;
            padding: 1.3rem;
            box-shadow: 0 18px 42px rgba(25, 20, 47, 0.08);
        }
        .plan-card h3 { margin: 0 0 1rem; font-size: 1.35rem; }
        .plan-row {
            display: flex;
            justify-content: space-between;
            padding: 0.72rem 0;
            border-top: 1px solid var(--border);
            font-weight: 800;
            font-size: 0.95rem;
        }
        .plan-card .btn { width: 100%; margin-top: 1rem; }
        .process {
            background: var(--ink);
            color: var(--surface);
        }
        .process .section-head small, .process .section-head h2 { color: var(--surface); }
        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }
        .step {
            border: 1px solid rgba(255, 250, 240, 0.18);
            border-radius: 20px;
            padding: 1.2rem;
            min-height: 165px;
            background: rgba(255, 250, 240, 0.06);
        }
        .step span {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--yellow);
            color: #19142f;
            font-weight: 800;
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        .step h3 { font-size: 1.15rem; margin-bottom: 0.5rem; }
        .step p { font-size: 0.95rem; opacity: 0.9; }
        .cta {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: center;
            border-radius: 28px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fffaf0;
            padding: clamp(1.5rem, 4vw, 3rem);
        }
        .cta h2 { margin: 0; font-size: clamp(1.8rem, 3.5vw, 3.2rem); line-height: 1.1; }
        .cta p { margin: 0.8rem 0 0; opacity: 0.86; font-size: 1rem; }
        footer {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--muted);
            border-top: 1px solid var(--border);
            font-size: 0.9rem;
        }
        footer a { color: var(--primary); font-weight: 800; }
        @media (max-width: 920px) {
            .nav { flex-wrap: wrap; padding: 1rem 0; }
            .nav-links { order: 3; width: 100%; justify-content: center; flex-wrap: wrap; }
            .hero { grid-template-columns: 1fr; padding-top: 2rem; }
            .phone-stage { min-height: 460px; }
            .feature-strip, .plans-grid, .steps { grid-template-columns: repeat(2, 1fr); }
            .cta { grid-template-columns: 1fr; }
        }
        @media (max-width: 620px) {
            .promo { align-items: flex-start; flex-direction: column; gap: 0.25rem; text-align: left; }
            .nav, .nav-actions { align-items: stretch; }
            .nav { width: min(100% - 24px, 1180px); }
            .nav-actions, .hero-actions { width: 100%; flex-direction: column; }
            .nav-actions .btn, .hero-actions .btn { width: 100%; }
            .nav-actions .btn.theme-toggle { width: 44px; height: 44px; padding: 0; border-radius: 50%; aspect-ratio: 1; flex-shrink: 0; }
            .theme-toggle { align-self: center; }
            .hero { width: min(100% - 24px, 1180px); padding-bottom: 3rem; }
            .hero h1 { font-size: clamp(2.2rem, 14vw, 3.8rem); }
            .phone-stage { min-height: 390px; }
            .phone { min-height: 430px; border-radius: 30px; }
            .float-card { display: none; }
            .compare { grid-template-columns: 1fr; }
            .compare > div { border-bottom: 1px solid var(--border) !important; }
            .feature-strip, .plans-grid, .steps { grid-template-columns: 1fr; }
            section { padding-top: 3.4rem; padding-bottom: 3.4rem; }
        }
    </style>
</head>
<body>
<div class="promo">
    <span><b>New!</b> Fast data bundles, airtime, and utility payments for Ghana.</span>
    <a href="<?php echo htmlspecialchars($primaryCta['link']); ?>">Learn more</a>
</div>

<nav class="nav">
    <a class="brand" href="<?php echo SITE_URL; ?>/"><?php echo $siteName; ?></a>
    <div class="nav-links">
        <a href="#services">Utility Bills</a>
        <a href="#plans">Our Plans</a>
        <a href="<?php echo htmlspecialchars($secondaryCta['link']); ?>"><?php echo htmlspecialchars($secondaryCta['label']); ?></a>
    </div>
    <div class="nav-actions">
        <button class="btn theme-toggle" id="globalThemeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
        <a class="btn btn-primary" href="<?php echo htmlspecialchars($primaryCta['link']); ?>"><?php echo htmlspecialchars($primaryCta['label']); ?></a>
    </div>
</nav>

<main>
    <header class="hero">
        <div>
            <div class="eyebrow"><i class="fas fa-bolt"></i> Try <?php echo $siteName; ?> now</div>
            <h1>One-stop-shop <span>data bundles airtime pay utility bills</span></h1>
            <p>Mobile top-ups and bill payments made simple in Ghana. Buy for yourself, serve customers, or run a data business from one clean platform.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?php echo htmlspecialchars($primaryCta['link']); ?>"><?php echo htmlspecialchars($primaryCta['label']); ?></a>
                <a class="btn" href="<?php echo htmlspecialchars($secondaryCta['link']); ?>"><?php echo htmlspecialchars($secondaryCta['label']); ?> — Here!</a>
                <a class="btn" href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Chat <?php echo htmlspecialchars($whatsappDisplay); ?></a>
                <?php if ($whatsappChannelLink): ?>
                    <a class="btn" href="<?php echo htmlspecialchars($whatsappChannelLink); ?>" target="_blank" rel="noopener">Join WhatsApp Channel</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="phone-stage" aria-hidden="true">
            <div class="shape"></div>
            <div class="phone">
                <div class="phone-top"><span>9:41</span><span><?php echo $siteName; ?></span></div>
                <div class="balance">
                    <small>Wallet balance</small>
                    <strong>GHS 248.50</strong>
                </div>
                <div class="quick-grid">
                    <div class="quick-tile"><i class="fas fa-wifi"></i>Data</div>
                    <div class="quick-tile"><i class="fas fa-phone"></i>Airtime</div>
                    <div class="quick-tile"><i class="fas fa-lightbulb"></i>ECG</div>
                    <div class="quick-tile"><i class="fas fa-droplet"></i>Water</div>
                </div>
                <div class="mini-plan"><span>MTN 5GB</span><span>GHS 20.29</span></div>
                <div class="mini-plan"><span>Telecel 10GB</span><span>GHS 37.99</span></div>
                <div class="mini-plan"><span>AT 30GB</span><span>GHS 70</span></div>
            </div>
            <div class="float-card one"><i class="fas fa-check-circle"></i> Delivered in seconds</div>
            <div class="float-card two"><i class="fas fa-shield-halved"></i> Secure checkout</div>
        </div>
    </header>

    <section class="why" id="services">
        <div class="section-head">
            <small>Why Us?</small>
            <h2>Fast, simple, and built for everyday payments.</h2>
        </div>
        <div class="compare">
            <div class="head">Featuring</div><div class="head"><?php echo $siteName; ?></div><div class="head">Others</div>
            <div>First class support</div><div><i class="fas fa-check"></i></div><div><i class="fas fa-minus"></i></div>
            <div>Fast and reliable delivery</div><div><i class="fas fa-check"></i></div><div><i class="fas fa-minus"></i></div>
            <div>Easy payments</div><div><i class="fas fa-check"></i></div><div><i class="fas fa-minus"></i></div>
            <div>Private and secured</div><div><i class="fas fa-check"></i></div><div><i class="fas fa-minus"></i></div>
            <div>Detailed transaction history</div><div><i class="fas fa-check"></i></div><div><i class="fas fa-minus"></i></div>
        </div>
        <div class="feature-strip">
            <div class="feature"><i class="fas fa-gauge-high"></i><h3>Fast and reliable</h3><p>Most mobile top-ups through our online services complete in seconds.</p></div>
            <div class="feature"><i class="fas fa-wallet"></i><h3>Convenient payments</h3><p>Use straightforward payment options that make checkout easy.</p></div>
            <div class="feature"><i class="fas fa-lock"></i><h3>Private and secured</h3><p>Your personal and financial details stay protected.</p></div>
            <div class="feature"><i class="fas fa-clock-rotate-left"></i><h3>Transaction history</h3><p>Track every order clearly from your dashboard.</p></div>
        </div>
    </section>

    <section id="plans">
        <div class="section-head">
            <small>Our popular plans</small>
            <h2>Choose a bundle and get connected.</h2>
        </div>
        <div class="plans-grid">
            <?php foreach ($plans as $network => $items): ?>
                <article class="plan-card">
                    <h3><?php echo htmlspecialchars($network); ?></h3>
                    <?php foreach ($items as $item): ?>
                        <div class="plan-row"><span><?php echo htmlspecialchars($item[0]); ?></span><span>GHS <?php echo htmlspecialchars($item[1]); ?></span></div>
                    <?php endforeach; ?>
                    <a class="btn btn-primary" href="<?php echo SITE_URL; ?>/login.php">Buy <?php echo htmlspecialchars($network); ?> Pack</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="process">
        <div class="section-head">
            <small>The process</small>
            <h2>How things work.</h2>
        </div>
        <div class="steps">
            <div class="step"><span>1</span><h3>Get numbers ready</h3><p>Enter the recipient numbers for every bundle or bill.</p></div>
            <div class="step"><span>2</span><h3>Add to cart</h3><p>Select as many packages as you need before checkout.</p></div>
            <div class="step"><span>3</span><h3>Pay smoothly</h3><p>Complete payment through the available secure channels.</p></div>
            <div class="step"><span>4</span><h3>Receive in seconds</h3><p>Track delivery and transaction status from your account.</p></div>
        </div>
    </section>

    <section id="cta">
        <div class="cta">
            <div>
                <h2>Join us. Make gifting data easy for friends and family.</h2>
                <p>Start with a free account or chat with support on WhatsApp.</p>
            </div>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?php echo htmlspecialchars($primaryCta['link']); ?>">Sign up free</a>
                <a class="btn" href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank" rel="noopener">Support</a>
            </div>
        </div>
    </section>
</main>

<footer>
    COPYRIGHT &copy; <?php echo $year; ?> <?php echo $siteName; ?>. All Rights Reserved. Developed by
    <a href="https://moses.constechz.com" target="_blank" rel="noopener">Constechz Technologies</a>.
</footer>

<script>
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.querySelector('#globalThemeToggle i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
    (function initTheme() {
        const saved = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(saved || (prefersDark ? 'dark' : 'light'));
    })();
    document.getElementById('globalThemeToggle').addEventListener('click', function() {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme', next);
        applyTheme(next);
    });
</script>
</body>
</html>
