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
    define('DB_PORT', dbh_env('DB_PORT', '3306'));
} else {
    // Development database credentials (localhost)
    define('DB_HOST', dbh_env('DB_HOST', '127.0.0.1'));
    define('DB_USER', dbh_env('DB_USER', 'root'));
    define('DB_PASS', dbh_env('DB_PASS', ''));
    define('DB_NAME', dbh_env('DB_NAME', 'data_bundle_hub'));
    define('DB_PORT', dbh_env('DB_PORT', '3306'));
}

// Site Configuration
if (!function_exists('dbh_resolve_site_name')) {
    function dbh_resolve_site_name($fallback = 'Constechzhub') {
        $fallback = trim((string) $fallback);
        if ($fallback === '') {
            $fallback = 'Constechzhub';
        }

        if (!class_exists('mysqli')) {
            return $fallback;
        }

        try {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int) DB_PORT);
            if (!$conn || $conn->connect_errno) {
                return $fallback;
            }
        } catch (Throwable $e) {
            return $fallback;
        }

        $resolved = '';
        try {
            $queries = [
                "SELECT setting_value FROM seo_settings WHERE setting_name = 'site_name' LIMIT 1",
                "SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1",
            ];

            foreach ($queries as $sql) {
                $result = @$conn->query($sql);
                if ($result && $row = $result->fetch_assoc()) {
                    $value = trim((string) ($row['setting_value'] ?? ''));
                    if ($value !== '') {
                        $resolved = $value;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $resolved = '';
        }

        @$conn->close();
        return $resolved !== '' ? $resolved : $fallback;
    }
}

define('SITE_NAME', dbh_resolve_site_name('Constechzhub'));

$detectedSiteUrl = dbh_detect_site_url();
$siteUrlFromEnv = dbh_env('SITE_URL');

if ($is_production) {
    define('SITE_URL', $siteUrlFromEnv ?: ($detectedSiteUrl ?: 'https://bigdatagh.com'));  // Your live server URL
} else {
    define('SITE_URL', $siteUrlFromEnv ?: ($detectedSiteUrl ?: 'http://localhost/data-bundle-hub'));
}

define('ADMIN_EMAIL', 'admin@bigdatagh.com');

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
define('DARK_BG', '#2E294E');
define('PRIMARY_COLOR', '#D90368');
define('SECONDARY_COLOR', '#FFD400');
define('SUCCESS_COLOR', '#541388');
define('BRAND_COLOR', '#541388');

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_AGENT', 'agent');
define('ROLE_CUSTOMER', 'customer');

// Default Currency
define('CURRENCY', 'GH' . "\u{20B5}");
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
require_once __DIR__ . '/../includes/order_status.php';

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
if (function_exists('ensureNotificationsSchema')) {
    ensureNotificationsSchema();
}
if (function_exists('ensureEmailVerificationTable')) {
    ensureEmailVerificationTable();
}

if (function_exists('maybeAutoCompletePendingMtnOrders')) {
    maybeAutoCompletePendingMtnOrders();
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

// Helper function to get current user
function getCurrentUser() {
    global $db;
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return null;
    }
    
    try {
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
        return $user ?: null; // Return null if user not found
    } catch (Exception $e) {
        error_log("getCurrentUser error: " . $e->getMessage());
        return null;
    }
}

// Helper function to get dynamic site name from SEO settings
function getSiteName($default = SITE_NAME) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT setting_value FROM seo_settings WHERE setting_name = 'site_name' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $name = trim((string) ($row['setting_value'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1");
        if ($stmt && $stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $name = trim((string) ($row['setting_value'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $default ?: SITE_NAME;
    } catch (Exception $e) {
        error_log("Site Name Error: " . $e->getMessage());
        return $default ?: SITE_NAME;
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

