<?php
// Data Bundle Hub - Production Configuration File
// Copy this to config.php on your live server and update the database credentials

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration - UPDATE THESE FOR YOUR LIVE SERVER
define('DB_HOST', 'localhost'); // Your hosting provider's database host
define('DB_USER', 'your_db_username'); // Your database username from hosting provider
define('DB_PASS', 'your_db_password'); // Your database password from hosting provider
define('DB_NAME', 'your_database_name'); // Your database name (e.g., ereddaco_bigdata)

// Site Configuration - UPDATE FOR YOUR LIVE DOMAIN
define('SITE_NAME', 'Constechzhub');
define('SITE_URL', 'https://yourdomain.com'); // Your live domain URL
define('ADMIN_EMAIL', 'admin@yourdomain.com'); // Your admin email

// Paystack Configuration (defaults; may be overridden from DB settings below)
$__DEFAULT_PAYSTACK_PUBLIC = 'pk_live_your_live_public_key_here'; // Use live keys for production
$__DEFAULT_PAYSTACK_SECRET = 'sk_live_your_live_secret_key_here'; // Use live keys for production

// Moolre Configuration (defaults; may be overridden from DB settings below)
$__DEFAULT_MOOLRE_API_USER = '';
$__DEFAULT_MOOLRE_API_KEY = '';
$__DEFAULT_MOOLRE_API_PUBKEY = '';
$__DEFAULT_MOOLRE_API_VASKEY = '';
$__DEFAULT_MOOLRE_ACCOUNT_NUMBER = '';
$__DEFAULT_MOOLRE_WEBHOOK_SECRET = '';
$__DEFAULT_PAYMENT_GATEWAY = 'paystack';

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

// Error Reporting (DISABLED for production)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Include database connection
require_once __DIR__ . '/database.php';

// Include utility functions
require_once __DIR__ . '/../includes/functions.php';

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

// Helper function to get current user
function getCurrentUser() {
    global $db;
    if (!isset($_SESSION['user_id'])) {
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

    return $user ?: null;
}

// Helper function to get dynamic site name from SEO settings
function getSiteName($default = 'Constechzhub') {
    global $db;
    
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
?>
