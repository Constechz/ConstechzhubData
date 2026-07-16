<?php
// Data Bundle Hub - Main Configuration File

// Start session if not already started and headers can still be sent
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Avoid PCRE JIT allocation warnings on hosts that disallow executable memory.
if (function_exists('ini_set')) {
    @ini_set('pcre.jit', '0');
}

if (!function_exists('dbh_load_env_file')) {
    /**
     * Very small .env loader so we can keep credentials/config out of version control.
     * Only keys that are not already defined in the environment are injected.
     */
    function dbh_load_env_file($path) {
        static $parsed = [];
        if (isset($parsed[$path]) || !is_readable($path)) {
            return;
        }

        $parsed[$path] = true;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            if (stripos($name, 'export ') === 0) {
                $name = trim(substr($name, 6));
            }

            $value = ltrim($value);
            $quoteChar = substr($value, 0, 1);
            if (($quoteChar === '"' || $quoteChar === "'") && substr($value, -1) === $quoteChar) {
                $value = substr($value, 1, -1);
            } else {
                $value = rtrim($value);
            }

            if ($value === null) {
                $value = '';
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }

            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }

            if (!array_key_exists($name, $_SERVER)) {
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('dbh_bootstrap_env')) {
    /**
     * Load environment variables from .env (project root) once per request.
     */
    function dbh_bootstrap_env() {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $initialized = true;
        $projectRoot = realpath(__DIR__ . '/..');
        $candidates = [];

        if ($projectRoot) {
            $candidates[] = $projectRoot . '/.env';
        }

        $candidates[] = __DIR__ . '/.env';

        foreach ($candidates as $envFile) {
            dbh_load_env_file($envFile);
        }
    }
}

dbh_bootstrap_env();

if (!function_exists('dbh_env')) {
    /**
     * Resolve environment variables with a fallback.
     */
    function dbh_env($key, $default = null) {
        dbh_bootstrap_env();
        global $_ENV;
        if (is_array($_ENV) && array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

/**
 * Build the current site URL from the active request.
 * Falls back to null if we cannot safely detect it.
 */
function dbh_detect_site_url() {
    // Detect scheme (honor proxy headers such as ngrok's X-Forwarded-Proto)
    $protoHeader = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $proto = $protoHeader ? strtolower(trim(explode(',', $protoHeader)[0])) : '';
    $isHttps = $proto === 'https'
        || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $isHttps ? 'https' : 'http';

    // Detect host (prefer forwarded host for tunnels, sanitize to avoid header injection)
    $hostCandidates = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $forwardedHosts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
        $hostCandidates[] = trim($forwardedHosts[0]);
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $hostCandidates[] = $_SERVER['HTTP_HOST'];
    }
    if (!empty($_SERVER['SERVER_NAME'])) {
        $hostCandidates[] = $_SERVER['SERVER_NAME'];
    }
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $hostCandidates[] = $_SERVER['SERVER_ADDR'];
    }

    $host = null;
    foreach ($hostCandidates as $candidate) {
        $clean = preg_replace('/[^A-Za-z0-9\\.\\-:]/', '', $candidate);
        if (!empty($clean)) {
            $host = $clean;
            break;
        }
    }

    if (empty($host)) {
        return null;
    }

    // Detect path relative to document root to support subdirectory deployments (e.g., /data-bundle-hub)
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';
    $relativePath = '';

    if (!empty($projectRoot) && !empty($documentRoot) && strpos($projectRoot, $documentRoot) === 0) {
        $relativePath = trim(substr($projectRoot, strlen($documentRoot)), '/');
    }

    $basePath = $relativePath ? '/' . $relativePath : '';
    return rtrim($scheme . '://' . $host . $basePath, '/');
}

// Determine current environment
$app_env = dbh_env('APP_ENV');
if (is_string($app_env)) {
    $app_env = strtolower($app_env);
}

if (empty($app_env)) {
    $detectedHost = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $localAddresses = ['localhost', '127.0.0.1', '::1'];
    $isLocalHost = in_array($detectedHost, $localAddresses, true)
        || in_array($_SERVER['SERVER_ADDR'] ?? '', $localAddresses, true)
        || PHP_SAPI === 'cli';

    $app_env = $isLocalHost ? 'development' : 'production';
}

$is_production = $app_env === 'production';

if (!defined('APP_ENV')) {
    define('APP_ENV', $app_env);
}

// Database Configuration (auto-detected environment)
if ($is_production) {
    // Production database credentials - UPDATE THESE WITH YOUR LIVE SERVER DETAILS
    define('DB_HOST', dbh_env('DB_HOST', 'localhost'));              // Usually 'localhost' for shared hosting
    define('DB_USER', dbh_env('DB_USER', 'ereddaco_bigdata'));       // Your cPanel database username
    define('DB_PASS', dbh_env('DB_PASS', '_eKW6G^.crkG%*_')); // Your database password
    define('DB_NAME', dbh_env('DB_NAME', 'ereddaco_bigdata'));       // Your database name
    define('DB_PORT', dbh_env('DB_PORT', null));
} else {
    // Development database credentials (localhost)
    define('DB_HOST', dbh_env('DB_HOST', '127.0.0.1'));
    define('DB_USER', dbh_env('DB_USER', 'root'));
    define('DB_PASS', dbh_env('DB_PASS', ''));
    define('DB_NAME', dbh_env('DB_NAME', 'data_bundle_hub'));
    define('DB_PORT', dbh_env('DB_PORT', null));
}

// Site Configuration
define('SITE_NAME', 'Constechzhub');

$detectedSiteUrl = dbh_detect_site_url();
$siteUrlFromEnv = dbh_env('SITE_URL');

if ($is_production) {
    define('SITE_URL', $siteUrlFromEnv ?: ($detectedSiteUrl ?: 'https://constechzhub.com'));  // Your live server URL
} else {
    define('SITE_URL', $siteUrlFromEnv ?: ($detectedSiteUrl ?: 'http://localhost/mosesData'));
}

define('ADMIN_EMAIL', dbh_env('ADMIN_EMAIL', 'admin@bigdatagh.com'));

// Paystack Configuration (defaults; may be overridden from DB settings below)
$__DEFAULT_PAYSTACK_PUBLIC = 'pk_test_your_public_key_here';
$__DEFAULT_PAYSTACK_SECRET = 'sk_test_your_secret_key_here';

// Moolre Configuration (defaults; may be overridden from DB settings below)
$__DEFAULT_MOOLRE_API_USER = '';
$__DEFAULT_MOOLRE_API_KEY = '';
$__DEFAULT_MOOLRE_API_PUBKEY = '';
$__DEFAULT_MOOLRE_API_VASKEY = '';
$__DEFAULT_MOOLRE_ACCOUNT_NUMBER = '';
$__DEFAULT_MOOLRE_WEBHOOK_SECRET = '';
$__DEFAULT_PAYMENT_GATEWAY = 'paystack';

// Google OAuth
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', dbh_env('GOOGLE_CLIENT_ID', ''));
}

// Theme Colors
define('DARK_BG', '#1C1C1C');
define('PRIMARY_COLOR', '#E63B2C');
define('SECONDARY_COLOR', '#EA796E');
define('SUCCESS_COLOR', '#73ED3F');
define('BRAND_COLOR', '#8B5CF6');

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_AGENT', 'agent');
define('ROLE_CUSTOMER', 'customer');
define('ROLE_VIP', 'vip');

// Default Currency
define('CURRENCY', 'GH₵');
define('CURRENCY_CODE', 'GHS');

// File Upload Settings
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// Timezone
date_default_timezone_set('Africa/Accra');

// Error Reporting (disable in production)
if ($is_production) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Include database connection
require_once __DIR__ . '/database.php';

// For shared hosting deployments, use:
// Production schema: database/fixed_production_schema.sql
// Or compatibility fix: database/shared_hosting_compatible.sql

// Include utility functions
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/constchat_widget.php';
require_once __DIR__ . '/../includes/order_status.php';

if (!function_exists('dbh_should_enable_daily_notice')) {
    /**
     * Show the daily important notice on agent/customer/store pages and public guest pages.
     */
    function dbh_should_enable_daily_notice() {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtolower((string) parse_url($requestUri, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        // Never inject into backend/API paths.
        if (preg_match('#/(api|admin|super-admin|cron)/#', $path)) {
            return false;
        }

        if (preg_match('#/(agent|customer|store)/#', $path)) {
            return true;
        }

        $basename = basename($path);
        $publicPages = [
            'index.php',
            'login.php',
            'register.php',
            'forgot-password.php',
            'reset-password.php',
            'verify-email.php',
            'support.php'
        ];

        if (in_array($basename, $publicPages, true)) {
            return true;
        }

        // Support pretty URLs that resolve to the guest landing page.
        return substr($path, -1) === '/';
    }
}

if (!function_exists('dbh_render_daily_notice_markup')) {
    /**
     * Render the important notice modal with theme-aligned colors.
     */
    function dbh_render_daily_notice_markup() {
        $primary = defined('PRIMARY_COLOR') ? PRIMARY_COLOR : '#E63B2C';
        $secondary = defined('SECONDARY_COLOR') ? SECONDARY_COLOR : '#EA796E';
        $dark = defined('DARK_BG') ? DARK_BG : '#1C1C1C';

        return <<<HTML
<div id="dbhDailyNoticeOverlay" class="dbh-dn-overlay" style="display:none;" aria-hidden="true">
    <div class="dbh-dn-card" role="dialog" aria-modal="true" aria-labelledby="dbhDailyNoticeTitle">
        <div class="dbh-dn-icon-wrap">
            <span class="dbh-dn-icon-triangle"><span class="dbh-dn-icon-mark">!</span></span>
        </div>
        <h2 id="dbhDailyNoticeTitle" class="dbh-dn-title">Important Notice</h2>
        <p class="dbh-dn-copy">
            Our system cannot deliver data to the following SIM types/Phone Numbers. Orders for these will fail and there will not be any <strong>REFUND OF MONEY</strong>.
        </p>
        <ul class="dbh-dn-list">
            <li>Turbonet SIM</li>
            <li>Merchant SIM</li>
            <li>EVD SIM</li>
            <li>Broadband SIM</li>
            <li>Blacklisted SIM</li>
            <li>Roaming SIM</li>
            <li>Wrong Number</li>
            <li>Inactive Number</li>
        </ul>
        <button id="dbhDailyNoticeBtn" type="button" class="dbh-dn-btn">I Understand</button>
    </div>
</div>
<style>
    .dbh-dn-overlay {
        --dbh-dn-primary: {$primary};
        --dbh-dn-secondary: {$secondary};
        --dbh-dn-dark: {$dark};
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(12, 16, 25, 0.62);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 18px;
        -webkit-backdrop-filter: blur(2px);
        backdrop-filter: blur(2px);
    }
    .dbh-dn-card {
        width: min(100%, 460px);
        max-height: min(90vh, 760px);
        overflow-y: auto;
        background: #ffffff;
        border-radius: 18px;
        padding: 22px 20px 20px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.22);
        border: 1px solid rgba(230, 59, 44, 0.10);
    }
    .dbh-dn-icon-wrap {
        text-align: center;
        margin-bottom: 8px;
    }
    .dbh-dn-icon-triangle {
        width: 0;
        height: 0;
        border-left: 20px solid transparent;
        border-right: 20px solid transparent;
        border-bottom: 36px solid var(--dbh-dn-primary);
        display: inline-block;
        position: relative;
    }
    .dbh-dn-icon-mark {
        position: absolute;
        top: 12px;
        left: -4px;
        color: #fff;
        font-size: 18px;
        font-weight: 700;
        line-height: 1;
    }
    .dbh-dn-title {
        margin: 6px 0 12px;
        text-align: center;
        font-size: clamp(1.25rem, 2.5vw, 1.8rem);
        color: var(--dbh-dn-dark);
        font-weight: 700;
    }
    .dbh-dn-copy {
        margin: 0 0 14px;
        color: #394150;
        line-height: 1.5;
        font-size: 0.98rem;
    }
    .dbh-dn-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 9px;
    }
    .dbh-dn-list li {
        background: linear-gradient(180deg, rgba(230, 59, 44, 0.12), rgba(230, 59, 44, 0.08));
        border: 1px solid rgba(230, 59, 44, 0.16);
        border-radius: 10px;
        padding: 10px 12px;
        color: #8c2c22;
        font-weight: 600;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dbh-dn-list li::before {
        content: "x";
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: var(--dbh-dn-primary);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 18px;
        text-transform: uppercase;
    }
    .dbh-dn-btn {
        width: 100%;
        border: none;
        border-radius: 999px;
        margin-top: 18px;
        padding: 13px 14px;
        background: linear-gradient(120deg, var(--dbh-dn-dark), #2d3442);
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.12s ease, box-shadow 0.12s ease;
    }
    .dbh-dn-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(28, 28, 28, 0.22);
    }
    .dbh-dn-btn:active {
        transform: translateY(0);
    }
    body.dbh-dn-open {
        overflow: hidden;
    }
    .dbh-dn-overlay.is-hiding {
        opacity: 0;
        transition: opacity 0.18s ease;
    }
    @media (max-width: 480px) {
        .dbh-dn-card {
            padding: 20px 16px 16px;
        }
        .dbh-dn-list li {
            font-size: 0.92rem;
            padding: 9px 10px;
        }
        .dbh-dn-btn {
            margin-top: 14px;
            font-size: 0.96rem;
        }
    }
</style>
<script>
(function () {
    var overlay = document.getElementById('dbhDailyNoticeOverlay');
    var btn = document.getElementById('dbhDailyNoticeBtn');
    if (!overlay || !btn) {
        return;
    }

    function getTodayLocalDate() {
        var now = new Date();
        var year = now.getFullYear();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    var key = 'dbh_daily_notice_seen_date_v1';
    var today = getTodayLocalDate();
    var seenDate = '';
    var canUseLocalStorage = true;

    try {
        if (window.localStorage) {
            seenDate = localStorage.getItem(key) || '';
        }
    } catch (err) {
        canUseLocalStorage = false;
    }

    if (!seenDate) {
        var cookieMatch = document.cookie.match(/(?:^|; )dbh_daily_notice_seen=([^;]+)/);
        seenDate = cookieMatch ? decodeURIComponent(cookieMatch[1]) : '';
    }

    if (seenDate === today) {
        overlay.remove();
        return;
    }

    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('dbh-dn-open');

    var closeNotice = function () {
        if (canUseLocalStorage && window.localStorage) {
            try {
                localStorage.setItem(key, today);
            } catch (err) {}
        }
        document.cookie = 'dbh_daily_notice_seen=' + encodeURIComponent(today) + '; path=/; max-age=172800; SameSite=Lax';
        document.body.classList.remove('dbh-dn-open');
        overlay.classList.add('is-hiding');
        setTimeout(function () {
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }, 180);
    };

    btn.addEventListener('click', closeNotice);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            closeNotice();
        }
    });
})();
</script>
HTML;
    }
}

if (!function_exists('dbh_inject_daily_notice_markup')) {
    /**
     * Inject the notice markup before </body> for HTML responses only.
     */
    function dbh_inject_daily_notice_markup($buffer) {
        try {
            if ($buffer === '' || stripos($buffer, '<html') === false) {
                return $buffer;
            }

            if (stripos($buffer, 'id="dbhDailyNoticeOverlay"') !== false) {
                return $buffer;
            }

            $extra = dbh_render_daily_notice_markup();
            if (function_exists('isLoggedIn') && isLoggedIn()) {
                $extra .= dbh_render_constchat_markup();
            } else {
                $store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
                if ($store_slug !== '' && function_exists('dbh_render_guest_constchat_markup')) {
                    $extra .= dbh_render_guest_constchat_markup($store_slug);
                }
            }

            $pos = stripos($buffer, '</body>');
            if ($pos !== false) {
                return substr_replace($buffer, $extra . '</body>', $pos, 7);
            }

            return $buffer . $extra;
        } catch (Throwable $e) {
            error_log("dbh_inject_daily_notice_markup error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            return $buffer;
        }
    }
}


if (!function_exists('dbh_should_enable_output_buffer')) {
    function dbh_should_enable_output_buffer() {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtolower((string) parse_url($requestUri, PHP_URL_PATH));
        if ($path === '') {
            return false;
        }

        // Never inject into API responses, webhook endpoints, or cron tasks
        if (preg_match('#/(api|cron|webhooks)/#', $path)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('dbh_should_run_bootstrap_schema_maintenance')) {
    /**
     * Expensive schema repair/migration checks should not run on every public
     * request. Keep them available for CLI/admin maintenance paths and allow an
     * env override when a deployment needs to force them.
     */
    function dbh_should_run_bootstrap_schema_maintenance() {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        $envFlag = function_exists('dbh_env') ? strtolower((string) dbh_env('DBH_AUTO_MIGRATE', '')) : '';
        if (in_array($envFlag, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = strtolower((string) parse_url($requestUri, PHP_URL_PATH));

        return preg_match('#/(admin|super-admin|cron)/#', $path) === 1;
    }
}

if (!defined('DBH_DAILY_NOTICE_BUFFER_STARTED') && dbh_should_enable_output_buffer()) {
    define('DBH_DAILY_NOTICE_BUFFER_STARTED', true);
    ob_start('dbh_inject_daily_notice_markup');
}

// Enforce maintenance mode before most of the application loads
if (function_exists('isMaintenanceModeEnabled') && isMaintenanceModeEnabled()) {
    try {
        if (!shouldBypassMaintenanceMode()) {
            renderMaintenanceNotice();
        }
    } catch (Exception $e) {
        error_log('Maintenance mode enforcement failed: ' . $e->getMessage());
    }
}

if (dbh_should_run_bootstrap_schema_maintenance()) {
    if (function_exists('dbh_fix_auto_increment_tables')) {
        dbh_fix_auto_increment_tables();
    }

    if (function_exists('ensureTopupSettingsTable')) {
        ensureTopupSettingsTable();
    }
    if (function_exists('ensureTopupRequestTables')) {
        ensureTopupRequestTables();
    }
    if (function_exists('ensureOrderIssueTables')) {
        ensureOrderIssueTables();
    }
    if (function_exists('ensurePaymentGatewaySchema')) {
        ensurePaymentGatewaySchema();
    }
    if (function_exists('ensurePricingProfilesSchema')) {
        ensurePricingProfilesSchema();
    }
    if (function_exists('ensureAccountTypeSchema')) {
        ensureAccountTypeSchema();
    }
    if (function_exists('ensureEmailVerificationTable')) {
        ensureEmailVerificationTable();
    }

    if (function_exists('maybeAutoCompletePendingMtnOrders')) {
        maybeAutoCompletePendingMtnOrders();
    }
}

/**
 * Global cache prevention function
 * Call this at the top of any page that should not be cached by browsers
 */
function preventBrowserCaching() {
    // Prevent caching to ensure real-time data updates
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    
    // Additional headers for mobile browsers
    header('Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0');
    header('Vary: *');
}

// Helper function to get current user (with static caching & shutdown safety)
function getCurrentUser() {
    global $db;
    static $cachedUser = null;
    static $hasRun = false;
    
    if ($hasRun) {
        return $cachedUser;
    }
    
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $cachedUser = null;
        $hasRun = true;
        return null;
    }
    
    try {
        // Shutdown safety: check if $db is active and connection is open
        if (!$db || !method_exists($db, 'getConnection') || !$db->getConnection() || !empty($db->getConnection()->connect_error)) {
            return null;
        }
        
        // Additional check for closed MySQLi object
        if (!($db->getConnection() instanceof mysqli) || @$db->getConnection()->ping() === false) {
            return null;
        }
        
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        if (!$stmt) {
            error_log("getCurrentUser prepare failed: " . ($db->getConnection()->error ?? 'unknown database error'));
            return null;
        }
        $stmt->bind_param("i", $_SESSION['user_id']);
        if (!$stmt->execute()) {
            error_log("getCurrentUser execute failed: " . ($db->getConnection()->error ?? 'unknown database error'));
            return null;
        }
        $result = $stmt->get_result();
        
        $user = $result->fetch_assoc();
        if ($user && function_exists('dbhNormalizeUserDisplayName')) {
            $user = dbhNormalizeUserDisplayName($user);
        }
        $cachedUser = $user ?: null;
        $hasRun = true;
        return $cachedUser;
    } catch (Exception $e) {
        error_log("getCurrentUser error: " . $e->getMessage());
        return null;
    }
}

// Pre-warm the cache during early page execution when database is active
if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['user_id'])) {
    getCurrentUser();
}

// Helper function to get dynamic site name from SEO settings
function getSiteName($default = 'Constechzhub') {
    global $db;

    if (function_exists('getSeoSetting')) {
        return getSeoSetting('site_name', $default) ?: $default;
    }
    
    try {
        $stmt = $db->prepare("SELECT setting_value FROM seo_settings WHERE setting_name = 'site_name'");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row['setting_value'] ?: $default;
        }
        
        return $default;
    } catch (Exception $e) {
        error_log("Site Name Error: " . $e->getMessage());
        return $default;
    }
}

// Load dynamic Paystack keys from settings table if available
try {
    $pk = $__DEFAULT_PAYSTACK_PUBLIC;
    $sk = $__DEFAULT_PAYSTACK_SECRET;
    $moolre_user = $__DEFAULT_MOOLRE_API_USER;
    $moolre_key = $__DEFAULT_MOOLRE_API_KEY;
    $moolre_pub = $__DEFAULT_MOOLRE_API_PUBKEY;
    $moolre_vas = $__DEFAULT_MOOLRE_API_VASKEY;
    $moolre_account = $__DEFAULT_MOOLRE_ACCOUNT_NUMBER;
    $moolre_webhook_secret = $__DEFAULT_MOOLRE_WEBHOOK_SECRET;
    $active_gateway = $__DEFAULT_PAYMENT_GATEWAY;

    if (isset($db)) {
        $res = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('paystack_public_key','paystack_secret_key','moolre_api_user','moolre_api_key','moolre_api_pubkey','moolre_api_vaskey','moolre_account_number','moolre_webhook_secret','payment_gateway_active')");
        if ($res && $res->execute()) {
            $r = $res->get_result();
            while ($row = $r->fetch_assoc()) {
                if ($row['setting_key'] === 'paystack_public_key' && !empty($row['setting_value'])) {
                    $pk = $row['setting_value'];
                }
                if ($row['setting_key'] === 'paystack_secret_key' && !empty($row['setting_value'])) {
                    $sk = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_api_user' && $row['setting_value'] !== '') {
                    $moolre_user = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_api_key' && $row['setting_value'] !== '') {
                    $moolre_key = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_api_pubkey' && $row['setting_value'] !== '') {
                    $moolre_pub = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_api_vaskey' && $row['setting_value'] !== '') {
                    $moolre_vas = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_account_number' && $row['setting_value'] !== '') {
                    $moolre_account = $row['setting_value'];
                }
                if ($row['setting_key'] === 'moolre_webhook_secret' && $row['setting_value'] !== '') {
                    $moolre_webhook_secret = $row['setting_value'];
                }
                if ($row['setting_key'] === 'payment_gateway_active' && $row['setting_value'] !== '') {
                    $active_gateway = strtolower(trim((string) $row['setting_value']));
                }
            }
        }
    }

    if (!defined('PAYSTACK_PUBLIC_KEY')) {
        define('PAYSTACK_PUBLIC_KEY', $pk);
    }
    if (!defined('PAYSTACK_SECRET_KEY')) {
        define('PAYSTACK_SECRET_KEY', $sk);
    }
    if (!defined('PAYSTACK_CALLBACK_URL')) {
        define('PAYSTACK_CALLBACK_URL', SITE_URL . '/api/paystack_callback.php');
    }
    if (!defined('MOOLRE_API_USER')) {
        define('MOOLRE_API_USER', $moolre_user);
    }
    if (!defined('MOOLRE_API_KEY')) {
        define('MOOLRE_API_KEY', $moolre_key);
    }
    if (!defined('MOOLRE_API_PUBKEY')) {
        define('MOOLRE_API_PUBKEY', $moolre_pub);
    }
    if (!defined('MOOLRE_API_VASKEY')) {
        define('MOOLRE_API_VASKEY', $moolre_vas);
    }
    if (!defined('MOOLRE_ACCOUNT_NUMBER')) {
        define('MOOLRE_ACCOUNT_NUMBER', $moolre_account);
    }
    if (!defined('MOOLRE_WEBHOOK_SECRET')) {
        define('MOOLRE_WEBHOOK_SECRET', $moolre_webhook_secret);
    }
    if (!defined('MOOLRE_CALLBACK_URL')) {
        define('MOOLRE_CALLBACK_URL', SITE_URL . '/api/moolre_webhook.php');
    }
    if (!defined('PAYMENT_GATEWAY_ACTIVE')) {
        define('PAYMENT_GATEWAY_ACTIVE', $active_gateway);
    }
} catch (Exception $e) {
    // Fallback: ensure constants exist even if settings lookup fails
    if (!defined('PAYSTACK_PUBLIC_KEY')) define('PAYSTACK_PUBLIC_KEY', $__DEFAULT_PAYSTACK_PUBLIC);
    if (!defined('PAYSTACK_SECRET_KEY')) define('PAYSTACK_SECRET_KEY', $__DEFAULT_PAYSTACK_SECRET);
    if (!defined('PAYSTACK_CALLBACK_URL')) define('PAYSTACK_CALLBACK_URL', SITE_URL . '/api/paystack_callback.php');
    if (!defined('MOOLRE_API_USER')) define('MOOLRE_API_USER', $__DEFAULT_MOOLRE_API_USER);
    if (!defined('MOOLRE_API_KEY')) define('MOOLRE_API_KEY', $__DEFAULT_MOOLRE_API_KEY);
    if (!defined('MOOLRE_API_PUBKEY')) define('MOOLRE_API_PUBKEY', $__DEFAULT_MOOLRE_API_PUBKEY);
    if (!defined('MOOLRE_API_VASKEY')) define('MOOLRE_API_VASKEY', $__DEFAULT_MOOLRE_API_VASKEY);
    if (!defined('MOOLRE_ACCOUNT_NUMBER')) define('MOOLRE_ACCOUNT_NUMBER', $__DEFAULT_MOOLRE_ACCOUNT_NUMBER);
    if (!defined('MOOLRE_WEBHOOK_SECRET')) define('MOOLRE_WEBHOOK_SECRET', $__DEFAULT_MOOLRE_WEBHOOK_SECRET);
    if (!defined('MOOLRE_CALLBACK_URL')) define('MOOLRE_CALLBACK_URL', SITE_URL . '/api/moolre_webhook.php');
    if (!defined('PAYMENT_GATEWAY_ACTIVE')) define('PAYMENT_GATEWAY_ACTIVE', $__DEFAULT_PAYMENT_GATEWAY);
}
