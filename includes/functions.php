<?php
/**
 * Global Utility Functions
 */

if (!function_exists('getWalletBalance')) {
    /**
     * Retrieves the current wallet balance for a user.
     */
    function getWalletBalance($user_id) {
        global $db;
        try {
            if (function_exists('ensureWalletTable')) {
                ensureWalletTable();
            }
            $stmt = $db->prepare("SELECT SUM(balance) as balance FROM wallets WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                return (float) ($res['balance'] ?? 0.00);
            }
        } catch (Exception $e) {
            error_log('getWalletBalance failed: ' . $e->getMessage());
        }
        return 0.00;
    }
}

if (!function_exists('getLinkedAgentId')) {
    /**
     * Get the agent ID linked to a user.
     */
    function getLinkedAgentId($user_id) {
        global $db;
        $user_id = (int) $user_id;
        if ($user_id <= 0) return 0;
        
        $stmt = $db->prepare("SELECT agent_id FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            return (int) ($res['agent_id'] ?? 0);
        }
        return 0;
    }
}

if (!function_exists('resolveActiveAgentId')) {
    /**
     * Resolves an agent ID to ensure the agent is active and valid.
     */
    function resolveActiveAgentId($agent_id) {
        global $db;
        $agent_id = (int) $agent_id;
        if ($agent_id <= 0) return 0;
        
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role IN ('agent', 'vip') AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $agent_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            return (int) ($res['id'] ?? 0);
        }
        return 0;
    }
}

if (!function_exists('dbh_ensure_auto_increment')) {
    /**
     * Ensures auto_increment is enabled for a table.
     */
    function dbh_ensure_auto_increment($table) {
        return true; 
    }
}

if (!function_exists('dbh_generate_next_id')) {
    /**
     * Generates a manual next ID for a table.
     */
    function dbh_generate_next_id($table) {
        global $db;
        $res = $db->query("SELECT MAX(id) as max_id FROM `" . $table . "`");
        if ($res && $row = $res->fetch_assoc()) {
            return ((int) $row['max_id']) + 1;
        }
        return 1;
    }
}

if (!function_exists('updateWalletBalanceWithSMS')) {
    /**
     * Updates wallet balance and sends an SMS notification.
     */
    function updateWalletBalanceWithSMS($user_id, $amount, $type = 'credit', $reference = null, $description = '', $source = '') {
        $payment_method = $source ?: 'wallet';
        $success = updateWalletBalance($user_id, $amount, $type, $reference, $description, $payment_method);
        return $success;
    }
}

if (!function_exists('setSessionUserRole')) {
    /**
     * Sets the user role in the session.
     */
    function setSessionUserRole($role) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
        $_SESSION['user_role'] = $role;
    }
}

if (!function_exists('hasRole')) {
    /**
     * Checks if the current user has a specific role.
     */
    function hasRole($role) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
        $current = normalizeUserRole($_SESSION['user_role'] ?? '');
        if ($current === 'admin') return true;
        return $current === normalizeUserRole($role);
    }
}

if (!function_exists('jsonResponse')) {
    /**
     * Sends a JSON response and exits.
     * Supports both (status, message, data, code) and ([status=>..., message=>...], code)
     */
    function jsonResponse($status, $message = null, $data = null, $code = 200) {
        header('Content-Type: application/json');
        
        if (is_array($status)) {
            // Support for legacy single-array calls: jsonResponse(['status'=>'error', 'message'=>'...'], 401)
            $response = $status;
            // Use the second argument as HTTP code if it's an integer, otherwise use default $code
            $http_code = is_int($message) ? $message : $code;
            http_response_code($http_code);
        } else {
            http_response_code($code);
            $response = [
                'status' => $status,
                'message' => $message,
                'data' => $data
            ];
        }
        
        echo json_encode($response);
        exit();
    }
}

if (!function_exists('getUserAgentId')) {
    /**
     * Alias for getLinkedAgentId
     */
    function getUserAgentId($user_id) {
        return getLinkedAgentId($user_id);
    }
}

if (!function_exists('getPaymentGatewayMode')) {
    /**
     * Gets the current payment gateway mode (live or test).
     */
    function getPaymentGatewayMode() {
        return (defined('APP_ENV') && APP_ENV === 'production') ? 'live' : 'test';
    }
}

if (!function_exists('safe_session_start')) {
    /**
     * Starts a session safely if one isn't already active.
     */
    function safe_session_start() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }
}

if (!function_exists('redirectTopLevel')) {
    /**
     * Redirects the entire page to a URL, even if inside an iframe.
     */
    function redirectTopLevel($url) {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>Redirecting...</title></head><body style="font-family: Arial, sans-serif; padding: 24px;">';
        echo '<p>Redirecting...</p>';
        echo '<script>';
        echo 'var target = ' . json_encode($url) . ';';
        echo 'if (window.top && window.top !== window.self) { window.top.location = target; } else { window.location = target; }';
        echo '</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"><p>Please <a href="' . $safe . '">click here</a> if not redirected.</p></noscript>';
        echo '</body></html>';
        exit();
    }
}

if (!function_exists('findRecentGuestBundleTransaction')) {
    /**
     * Finds a recent transaction for a guest customer to prevent duplicates.
     */
    function findRecentGuestBundleTransaction($user_id, $package_id, $phone, $amount, $seconds = 180, $store_slug = '') {
        global $db;
        $user_id = (int) $user_id;
        $query = "
            SELECT id, reference, status, metadata 
            FROM transactions 
            WHERE transaction_type = 'purchase'
              AND amount = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ";
        if ($user_id > 0) {
            $query .= " AND user_id = ?";
        } else {
            $query .= " AND (user_id IS NULL OR user_id = 0)";
        }
        $query .= " ORDER BY id DESC LIMIT 10";
        $stmt = $db->prepare($query);
        if (!$stmt) return null;
        
        if ($user_id > 0) {
            $stmt->bind_param('dii', $amount, $seconds, $user_id);
        } else {
            $stmt->bind_param('di', $amount, $seconds);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['metadata'])) {
                $meta = json_decode($row['metadata'], true);
                if (is_array($meta) 
                    && (isset($meta['package_id']) && (int)$meta['package_id'] === (int)$package_id)
                    && (isset($meta['beneficiary_number']) && $meta['beneficiary_number'] === $phone)
                ) {
                    if ($store_slug !== '' && isset($meta['store_slug']) && $meta['store_slug'] !== $store_slug) {
                        continue;
                    }
                    $stmt->close();
                    return $row;
                }
            }
        }
        $stmt->close();
        return null;
    }
}

// 1. Session Management
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// 2. Authentication Helper
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

// 3. Role/Access Control
if (!function_exists('requireLogin')) {
    /**
     * Ensures the user is logged in, otherwise redirects to login page.
     */
    function requireLogin() {
        if (!isLoggedIn()) {
            header("Location: ../login.php");
            exit();
        }
    }
}

if (!function_exists('isConfiguredPaystackSecretKey')) {
    /**
     * Validates if a Paystack secret key is properly configured.
     */
    function isConfiguredPaystackSecretKey($key) {
        $key = trim((string) $key);
        if ($key === '' || stripos($key, 'your_secret_key_here') !== false) {
            return false;
        }
        return (bool) preg_match('/^sk_(test|live)_/i', $key);
    }
}

if (!function_exists('isInvalidPaystackKey')) {
    /**
     * Inverse of isConfiguredPaystackSecretKey for easier logic in some files.
     */
    function isInvalidPaystackKey($key) {
        return !isConfiguredPaystackSecretKey($key);
    }
}

if (!function_exists('normalizeUserRole')) {
    function normalizeUserRole($role) {
        $role = strtolower(trim((string)$role));
        if (in_array($role, array('admin', 'super_admin', 'super-admin'))) return 'admin';
        return $role;
    }
}

if (!function_exists('isCustomerAccountRole')) {
    function isCustomerAccountRole($role) {
        return strtolower((string)$role) === 'customer';
    }
}

if (!function_exists('isAgentAccountRole')) {
    function isAgentAccountRole($role) {
        $r = strtolower((string)$role);
        return ($r === 'agent' || $r === 'vip');
    }
}

if (!function_exists('getCustomerPricingUserType')) {
    function getCustomerPricingUserType($user) {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        return $role === 'vip' ? 'vip' : 'customer';
    }
}

if (!function_exists('requireRole')) {
    function requireRole($role) {
        if (!isLoggedIn()) {
            header("Location: ../login.php");
            exit();
        }
        $current_role = normalizeUserRole($_SESSION['user_role'] ?? '');
        $target_role = normalizeUserRole($role);
        if ($current_role !== $target_role) {
            $is_admin = ($current_role === 'admin');
            if ($target_role === 'agent' && $is_admin) return;
            if ($target_role === 'customer' && ($is_admin || $current_role === 'agent')) return;
            header("Location: ../unauthorized.php");
            exit();
        }
    }
}

if (!function_exists('requireAnyRole')) {
    /**
     * Requires the current user to have any of the specified roles.
     */
    function requireAnyRole($roles) {
        if (!isLoggedIn()) {
            header("Location: ../login.php");
            exit();
        }
        $current_role = normalizeUserRole($_SESSION['user_role'] ?? '');
        foreach ($roles as $role) {
            $target_role = normalizeUserRole($role);
            if ($current_role === $target_role) return;
            
            // Admin bypass
            if ($current_role === 'admin') return;
        }
        header("Location: ../unauthorized.php");
        exit();
    }
}

if (!function_exists('setSessionUserRole')) {
    /**
     * Sets the user role in the session.
     */
    function setSessionUserRole($role) {
        $_SESSION['user_role'] = $role;
    }
}

if (!function_exists('isEmailVerificationEnabled')) {
    function isEmailVerificationEnabled() {
        return getSetting('email_verification_enabled', '0') === '1';
    }
}

if (!function_exists('isUserEmailVerified')) {
    function isUserEmailVerified($user_id) {
        global $db;
        $stmt = $db->prepare("SELECT email_verified FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        return ($res['email_verified'] ?? 0) == 1;
    }
}

if (!function_exists('getVerificationMethod')) {
    function getVerificationMethod() {
        return getSetting('verification_method', 'email');
    }
}

if (!function_exists('refreshSessionUserRole')) {
    function refreshSessionUserRole($user_id = null) {
        global $db;
        if (!$user_id && isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
        }
        if (!$user_id || !isset($db)) return null;
        
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $_SESSION['user_role'] = $row['role'];
                return $row['role'];
            }
        }
        return null;
    }
}

// 4. Formatting Utilities
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $currency = null) {
        $symbol = $currency ? $currency : (defined('CURRENCY') ? CURRENCY : 'GH₵');
        return $symbol . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('dbh_asset')) {
    function dbh_asset($path) {
        $baseUrl = defined('SITE_URL') ? SITE_URL : '';
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}

// 5. Database & Settings Helpers
if (!function_exists('getSetting')) {
    function getSetting($key, $default = '') {
        global $db;
        if (!isset($db)) return $default;
        static $settings = null;
        if ($settings === null) {
            $settings = array();
            try {
                $result = $db->query("SELECT setting_key, setting_value FROM settings");
                if ($result) {
                    while ($row = $result->fetch_assoc()) { $settings[$row['setting_key']] = $row['setting_value']; }
                }
            } catch (Exception $e) {}
        }
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
}

if (!function_exists('saveSetting')) {
    function saveSetting($key, $value, $description = '') {
        global $db;
        if (!isset($db)) return false;
        try {
            $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $stmt = $db->prepare("UPDATE settings SET setting_value = ?, description = ? WHERE setting_key = ?");
                if (!$stmt) return false;
                $stmt->bind_param('sss', $value, $description, $key);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }

            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
            if (!$stmt) return false;
            $stmt->bind_param('sss', $key, $value, $description);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('smsSettingsUsesKeyValueSchema')) {
    function smsSettingsUsesKeyValueSchema() {
        global $db;
        if (!isset($db)) return false;
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $res = $db->getConnection()->query("SHOW COLUMNS FROM sms_settings LIKE 'setting_key'");
            $cached = $res && $res->num_rows > 0;
        } catch (Exception $e) {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('dbh_table_exists')) {
    function dbh_table_exists($table) {
        global $db;
        if (!isset($db)) return false;
        try {
            $result = $db->query("SHOW TABLES LIKE '$table'");
            return ($result && $result->num_rows > 0);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('dbh_table_has_column')) {
    function dbh_table_has_column($table, $column) {
        global $db;
        if (!isset($db)) return false;
        try {
            $result = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return ($result && $result->num_rows > 0);
        } catch (Exception $e) { return false; }
    }
}

if (!function_exists('dbhNormalizeUserDisplayName')) {
    function dbhNormalizeUserDisplayName($user) {
        if (empty($user['full_name'])) {
            $user['full_name'] = isset($user['username']) ? $user['username'] : 'User';
        }
        return $user;
    }
}

if (!function_exists('sanitize')) {
    function sanitize($data) {
        global $db;
        if (is_array($data)) {
            foreach ($data as $k => $v) { $data[$k] = sanitize($v); }
            return $data;
        }
        $data = htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
        if (isset($db) && method_exists($db, 'escape')) { $data = $db->escape($data); }
        return $data;
    }
}

if (!function_exists('validateEmail')) {
    /**
     * Validates an email address format.
     */
    function validateEmail($email) {
        if (empty($email)) return false;
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('hashPassword')) {
    /**
     * Hashes a plain text password using the default algorithm.
     */
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('verifyPassword')) {
    /**
     * Verifies a plain text password against a hash.
     */
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

if (!function_exists('generateCSRF')) {
    /**
     * Generates or retrieves a CSRF token for the current session.
     */
    function generateCSRF() {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCSRF')) {
    /**
     * Validates a provided token against the session CSRF token.
     */
    function validateCSRF($token) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('validatePhone')) {
    /**
     * Validates a Ghana phone number (accepts 10-digit local or 12-digit international).
     */
    function validatePhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', (string)$phone);
        if (strlen($phone) === 10) {
            return substr($phone, 0, 1) === '0';
        }
        if (strlen($phone) === 12) {
            return strpos($phone, '233') === 0;
        }
        return false;
    }
}

if (!function_exists('formatPhone')) {
    /**
     * Formats a phone number to a standard Ghana 10-digit local format (0xxxxxxxxx).
     */
    function formatPhone($phone) {
        // Strip all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', (string)$phone);
        
        // Handle international format (233...)
        if (strpos($phone, '233') === 0 && strlen($phone) >= 11) {
            $phone = '0' . substr($phone, 3);
        }
        
        // If it starts with 0 and is 10 digits, it's already perfect
        if (strlen($phone) == 10 && substr($phone, 0, 1) === '0') {
            return $phone;
        }
        
        // If it's 9 digits and doesn't start with 0, add it
        if (strlen($phone) == 9 && substr($phone, 0, 1) !== '0') {
            return '0' . $phone;
        }
        
        // Final fallback: just take the last 10 digits and ensure leading zero if needed
        if (strlen($phone) > 10) {
            $last10 = substr($phone, -10);
            if (substr($last10, 0, 1) !== '0') {
                // If last 10 don't start with 0, maybe it's the last 9 we want?
                $last9 = substr($phone, -9);
                return '0' . $last9;
            }
            return $last10;
        }
        
        return $phone;
    }
}

if (!function_exists('getPublicRegistrationAccountTypes')) {
    /**
     * Returns list of account types allowed for public registration.
     */
    function getPublicRegistrationAccountTypes() {
        return ['customer', 'agent', 'vip'];
    }
}

if (!function_exists('getActivePaymentGateway')) {
    /**
     * Retrieves the currently active payment gateway from constants or settings.
     */
    function getActivePaymentGateway() {
        return defined('PAYMENT_GATEWAY_ACTIVE') ? PAYMENT_GATEWAY_ACTIVE : 'paystack';
    }
}

if (!function_exists('normalizePaymentGateway')) {
    /**
     * Normalizes payment gateway name and optionally returns a default.
     */
    function normalizePaymentGateway($gateway, $return_default = false) {
        $gateway = strtolower(trim((string)$gateway));
        if ($gateway === 'moolre' || $gateway === 'paystack') {
            return $gateway;
        }
        return $return_default ? 'paystack' : '';
    }
}

if (!function_exists('isPaystackTransferOtpDisabled')) {
    /**
     * Checks if Paystack transfer OTP is disabled.
     */
    function isPaystackTransferOtpDisabled() {
        return getSetting('paystack_transfer_otp_disabled', '0') === '1';
    }
}

if (!function_exists('getProfitWithdrawalFeeSchedule')) {
    /**
     * Retrieves the profit withdrawal fee schedule from settings.
     */
    function getProfitWithdrawalFeeSchedule() {
        $val = getSetting('profit_withdrawal_fee_schedule', '[]');
        $decoded = json_decode($val, true);
        $schedule = [];
        if (is_array($decoded)) {
            $schedule = $decoded;
        } else if (function_exists('parseProfitWithdrawalFeeScheduleText')) {
            $error = null;
            $parsed = parseProfitWithdrawalFeeScheduleText($val, $error);
            if (is_array($parsed)) {
                $schedule = $parsed;
            }
        }
        
        // Normalize schedule to have both max_amount/fee and min/max/fee keys for frontend JS compatibility
        if (!empty($schedule) && is_array($schedule)) {
            // Sort by max_amount/max ascending
            usort($schedule, function($a, $b) {
                $a_max = (float) ($a['max_amount'] ?? $a['max'] ?? 0);
                $b_max = (float) ($b['max_amount'] ?? $b['max'] ?? 0);
                return $a_max <=> $b_max;
            });
            
            $prev_max = 0.0;
            foreach ($schedule as &$tier) {
                if (!isset($tier['max_amount'])) {
                    $tier['max_amount'] = isset($tier['max']) ? (float)$tier['max'] : 99999999.0;
                }
                if (!isset($tier['min'])) {
                    $tier['min'] = $prev_max;
                }
                if (!isset($tier['max'])) {
                    $tier['max'] = $tier['max_amount'] >= 99999999.0 ? null : $tier['max_amount'];
                }
                if (!isset($tier['fee'])) {
                    $tier['fee'] = 0.0;
                }
                $prev_max = (float) $tier['max_amount'];
            }
            unset($tier);
        }
        
        return $schedule;
    }
}

if (!function_exists('formatProfitWithdrawalFeeScheduleText')) {
    /**
     * Formats a fee schedule array into a readable string.
     */
    function formatProfitWithdrawalFeeScheduleText($schedule) {
        if (empty($schedule)) return '';
        $lines = [];
        foreach ($schedule as $tier) {
            $lines[] = "Up to " . formatCurrency($tier['max_amount'] ?? 0) . ": " . formatCurrency($tier['fee'] ?? 0);
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('calculateProfitWithdrawalFee')) {
    /**
     * Calculates the profit withdrawal fee based on the amount and fee schedule.
     */
    function calculateProfitWithdrawalFee($amount, $schedule) {
        $amount = (float) $amount;
        if (empty($schedule)) {
            return 0.0;
        }
        
        // Sort just in case it isn't sorted
        usort($schedule, function($a, $b) {
            return $a['max_amount'] <=> $b['max_amount'];
        });
        
        foreach ($schedule as $tier) {
            if ($amount <= (float)($tier['max_amount'] ?? 0)) {
                return (float)($tier['fee'] ?? 0);
            }
        }
        
        // If it exceeds all tiers, return the fee of the last tier
        $last_tier = end($schedule);
        return (float)($last_tier['fee'] ?? 0);
    }
}

if (!function_exists('parseProfitWithdrawalFeeScheduleText')) {
    /**
     * Parses a plain-text profit withdrawal fee schedule.
     */
    function parseProfitWithdrawalFeeScheduleText($text, &$error_msg = null) {
        $lines = explode("\n", $text);
        $schedule = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strcasecmp($line, 'No fee schedule configured') === 0) continue;
            
            // Normalize "Up to X: Y" to just parse X and Y
            if (stripos($line, 'Up to') === 0) {
                $line = str_ireplace('Up to', '', $line);
            }
            
            $parts = explode('=', $line);
            if (count($parts) !== 2) {
                $parts = explode(':', $line);
            }
            if (count($parts) !== 2) {
                $error_msg = "Invalid line format: '$line'. Must contain '=' or ':' to separate range/amount and fee.";
                return null;
            }
            
            $range_str = trim($parts[0]);
            $fee_str = trim($parts[1]);
            
            // Clean fee_str (extract float)
            $fee = (float) preg_replace('/[^\d.]/', '', $fee_str);
            
            // Parse range_str
            if (strpos($range_str, '<') === 0) {
                $max_amount = (float) preg_replace('/[^\d.]/', '', $range_str);
            } elseif (strpos($range_str, '+') !== false) {
                $max_amount = 99999999.0; // effectively infinity
            } elseif (strpos($range_str, '-') !== false) {
                $range_parts = explode('-', $range_str);
                $max_amount = (float) preg_replace('/[^\d.]/', '', $range_parts[1]);
            } else {
                $max_amount = (float) preg_replace('/[^\d.]/', '', $range_str);
            }
            
            if ($max_amount <= 0) {
                $error_msg = "Invalid maximum amount in line: '$line'.";
                return null;
            }
            
            $schedule[] = [
                'max_amount' => $max_amount,
                'fee' => $fee
            ];
        }
        
        // Sort schedule by max_amount ascending
        usort($schedule, function($a, $b) {
            return $a['max_amount'] <=> $b['max_amount'];
        });
        
        return $schedule;
    }
}

if (!function_exists('defaultProfitWithdrawalFeeSchedule')) {
    /**
     * Returns the default profit withdrawal fee schedule.
     */
    function defaultProfitWithdrawalFeeSchedule() {
        return [
            ['max_amount' => 49.99, 'fee' => 1.00],
            ['max_amount' => 99.99, 'fee' => 1.50],
            ['max_amount' => 199.99, 'fee' => 4.00],
            ['max_amount' => 299.99, 'fee' => 8.00],
            ['max_amount' => 399.99, 'fee' => 12.00],
            ['max_amount' => 99999999.0, 'fee' => 16.00],
        ];
    }
}

if (!function_exists('ensureProductCatalogTables')) {
    /**
     * Ensures that product and package tables exist in the database.
     */
    function ensureProductCatalogTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `products` (
                `id` int NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `network` varchar(50) DEFAULT NULL,
                `description` text,
                `image_path` varchar(255) DEFAULT NULL,
                `is_active` tinyint(1) DEFAULT '1',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $db->query("CREATE TABLE IF NOT EXISTS `packages` (
                `id` int NOT NULL AUTO_INCREMENT,
                `product_id` int NOT NULL,
                `name` varchar(100) NOT NULL,
                `amount` decimal(10,2) NOT NULL,
                `cost_price` decimal(10,2) DEFAULT '0.00',
                `agent_price` decimal(10,2) DEFAULT NULL,
                `vip_price` decimal(10,2) DEFAULT NULL,
                `data_value` varchar(50) DEFAULT NULL,
                `validity` varchar(50) DEFAULT NULL,
                `is_active` tinyint(1) DEFAULT '1',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $db->query("CREATE TABLE IF NOT EXISTS `dashboard_products` (
                `id` int NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text,
                `size_label` varchar(50) DEFAULT NULL,
                `current_price` decimal(10,2) NOT NULL DEFAULT '0.00',
                `old_price` decimal(10,2) DEFAULT NULL,
                `rating` int DEFAULT '5',
                `image_path` varchar(255) DEFAULT NULL,
                `sort_order` int DEFAULT '0',
                `is_active` tinyint(1) DEFAULT '1',
                `created_by` int DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('getDashboardProducts')) {
    /**
     * Retrieves products for the dashboard.
     */
    function getDashboardProducts($onlyActive = true, $limit = null) {
        global $db;
        $products = [];
        try {
            $sql = "SELECT * FROM dashboard_products";
            if ($onlyActive) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, name ASC";
            if ($limit !== null) {
                $sql .= " LIMIT " . (int)$limit;
            }
            
            $result = $db->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $products[] = $row;
                }
            }
        } catch (Exception $e) {
            error_log('getDashboardProducts failed: ' . $e->getMessage());
        }
        return $products;
    }
}

if (!function_exists('ensurePricingProfilesSchema')) {
    /**
     * Ensures that pricing profile tables exist in the database.
     */
    function ensurePricingProfilesSchema() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `pricing_profiles` (
                `id` int NOT NULL AUTO_INCREMENT,
                `profile_key` varchar(50) NOT NULL,
                `profile_name` varchar(100) NOT NULL,
                `is_active` tinyint(1) DEFAULT '0',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `profile_key` (`profile_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            
            $db->query("INSERT IGNORE INTO `pricing_profiles` (`profile_key`, `profile_name`, `is_active`) VALUES ('default', 'Default Pricing', 1);");
        } catch (Exception $e) {}
    }
}

if (!function_exists('getPricingProfileOptions')) {
    /**
     * Retrieves available pricing profile options.
     */
    function getPricingProfileOptions() {
        global $db;
        $options = [];
        try {
            $result = $db->query("SELECT profile_key, profile_name FROM pricing_profiles");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $options[$row['profile_key']] = $row['profile_name'];
                }
            }
        } catch (Exception $e) {}
        return $options ?: ['default' => 'Default Pricing'];
    }
}

if (!function_exists('getActivePricingProfile')) {
    /**
     * Retrieves the currently active pricing profile key.
     */
    function getActivePricingProfile() {
        global $db;
        try {
            $result = $db->query("SELECT profile_key FROM pricing_profiles WHERE is_active = 1 LIMIT 1");
            if ($result && $row = $result->fetch_assoc()) {
                return $row['profile_key'];
            }
        } catch (Exception $e) {}
        return 'default';
    }
}

if (!function_exists('normalizePricingProfile')) {
    /**
     * Normalizes a pricing profile key.
     */
    function normalizePricingProfile($profile) {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$profile))) ?: 'default';
    }
}

if (!function_exists('getPackagePricingUserTypeOptions')) {
    /**
     * Returns list of user types for package pricing.
     */
    function getPackagePricingUserTypeOptions() {
        return [
            'customer' => 'Customer',
            'agent' => 'Agent',
            'vip' => 'VIP'
        ];
    }
}

if (!function_exists('switchActivePricingProfile')) {
    /**
     * Switches the active pricing profile.
     */
    function switchActivePricingProfile($profile_key) {
        global $db;
        try {
            $db->query("START TRANSACTION");
            $db->query("UPDATE pricing_profiles SET is_active = 0");
            $stmt = $db->prepare("UPDATE pricing_profiles SET is_active = 1 WHERE profile_key = ?");
            $stmt->bind_param("s", $profile_key);
            $stmt->execute();
            $db->query("COMMIT");
            return true;
        } catch (Exception $e) {
            $db->query("ROLLBACK");
            return false;
        }
    }
}

if (!function_exists('ensurePricingProfileSeeded')) {
    /**
     * Stub for ensuring pricing profile data is seeded.
     */
    function ensurePricingProfileSeeded($profile_key, $active_profile) {
        return true; 
    }
}

if (!function_exists('upsertPricingProfilePrice')) {
    /**
     * Upserts a pricing profile price.
     */
    function upsertPricingProfilePrice($profile_key, $package_id, $user_type, $price) {
        global $db;
        try {
            $stmt = $db->prepare("INSERT INTO package_pricing_profiles (profile_key, package_id, user_type, price) 
                                 VALUES (?, ?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE price = VALUES(price)");
            $stmt->bind_param("sisd", $profile_key, $package_id, $user_type, $price);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to upsert pricing profile price: " . $e->getMessage());
            return false;
        }
    }
}


if (!function_exists('getMoolreConfig')) {
    /**
     * Retrieves Moolre configuration from constants.
     */
    function getMoolreConfig() {
        return [
            'api_user' => defined('MOOLRE_API_USER') ? MOOLRE_API_USER : '',
            'api_key' => defined('MOOLRE_API_KEY') ? MOOLRE_API_KEY : '',
            'api_pubkey' => defined('MOOLRE_API_PUBKEY') ? MOOLRE_API_PUBKEY : '',
            'api_vaskey' => defined('MOOLRE_API_VASKEY') ? MOOLRE_API_VASKEY : '',
            'account_number' => defined('MOOLRE_ACCOUNT_NUMBER') ? MOOLRE_ACCOUNT_NUMBER : '',
            'webhook_secret' => defined('MOOLRE_WEBHOOK_SECRET') ? MOOLRE_WEBHOOK_SECRET : '',
        ];
    }
}

if (!function_exists('isMoolreConfigured')) {
    /**
     * Checks if Moolre is properly configured.
     */
    function isMoolreConfigured($config) {
        return !empty($config['api_user']) && !empty($config['api_key']);
    }
}

if (!function_exists('moolrePostJson')) {
    function moolrePostJson($url, $payload, $config, &$error = null) {
        $api_key = $config['api_key'] ?? '';
        if ($api_key === '') {
            $error = 'Moolre API key not configured.';
            return false;
        }

        $json = json_encode($payload);
        if ($json === false) {
            $error = 'Failed to encode Moolre payload.';
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            $error = $curl_error ?: 'Moolre request failed (empty response).';
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $error = 'Moolre returned invalid JSON. HTTP ' . $http_code;
            return false;
        }

        return $decoded;
    }
}

if (!function_exists('getNewAfaRegistrationCount')) {
    /**
     * Retrieves the count of new AFA registrations.
     */
    function getNewAfaRegistrationCount() {
        global $db;
        try {
            if (!dbh_table_exists('afa_registrations')) return 0;
            $result = $db->query("SELECT COUNT(*) AS total FROM afa_registrations WHERE status = 'processing'");
            if ($result && $row = $result->fetch_assoc()) {
                return (int) $row['total'];
            }
        } catch (Exception $e) {
            error_log('getNewAfaRegistrationCount failed: ' . $e->getMessage());
        }
        return 0;
    }
}

if (!function_exists('getStoreBySlug')) {
    /**
     * Retrieves store information by slug.
     */
    function getStoreBySlug($slug) {
        global $db;
        try {
            if (!dbh_table_exists('agent_stores')) return null;
            $stmt = $db->prepare("SELECT * FROM agent_stores WHERE store_slug = ? AND is_active = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $slug);
                $stmt->execute();
                return $stmt->get_result()->fetch_assoc();
            }
        } catch (Exception $e) {
            error_log('getStoreBySlug failed: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message) {
        $_SESSION['flash_message'] = array('type' => $type, 'message' => $message);
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $flash = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('hasFlashMessage')) {
    /**
     * Checks if a flash message exists in the session.
     */
    function hasFlashMessage() {
        return isset($_SESSION['flash_message']);
    }
}

// 6. Logging & Activity
if (!function_exists('logActivity')) {
    function logActivity($user_id, $action, $details = '') {
        global $db;
        if (!isset($db)) return;
        try {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issss", $user_id, $action, $details, $ip, $ua);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {}
    }
}

// 7. Wallet & Order Logic
if (!function_exists('updateWalletBalance')) {
    function updateWalletBalance($user_id, $amount, $type = 'credit', $reference = '', $description = null, $payment_method = 'wallet', $initiated_by_id = null, $target_user_id = null) {
        global $db;
        if (!isset($db) || !$user_id || $amount == 0) return false;
        if ($amount < 0) return false;
        
        $conn = $db->getConnection();
        $auto_commit_was_on = true;
        
        $allowed_methods = ['wallet', 'paystack', 'bank_transfer', 'agent_paystack', 'mobile_money', 'card', 'cash'];
        $payment_method = in_array($payment_method, $allowed_methods, true) ? $payment_method : 'wallet';
        
        try {
            $res = $conn->query("SELECT @@autocommit");
            $row = $res->fetch_row();
            $auto_commit_was_on = ($row[0] == 1);
            
            if ($auto_commit_was_on) {
                $conn->begin_transaction();
            }
 
            $conn->query("INSERT IGNORE INTO wallets (user_id, balance) VALUES ($user_id, 0)");
 
            if ($type === 'credit') {
                $sql = "UPDATE wallets SET balance = balance + ? WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("di", $amount, $user_id);
                $stmt->execute();
            } else {
                $sql = "UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("did", $amount, $user_id, $amount);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    if ($auto_commit_was_on) { $conn->rollback(); }
                    return false;
                }
            }
            $stmt->close();
 
            // Fetch balance snapshots
            $bal_res = $conn->query("SELECT balance FROM wallets WHERE user_id = $user_id");
            $bal_row = $bal_res->fetch_assoc();
            $balance_after = $bal_row ? (float)$bal_row['balance'] : 0.00;
            $balance_before = ($type === 'credit') ? ($balance_after - $amount) : ($balance_after + $amount);
 
            $ref = $reference ?: 'REF_' . time() . '_' . rand(1000, 9999);
            $txn_type = ($type === 'credit') ? 'topup' : 'purchase';
            $desc = $description ?? '';
 
            $stmt2 = $conn->prepare("INSERT IGNORE INTO transactions (user_id, transaction_type, amount, status, reference, description, payment_method, balance_before, balance_after, initiated_by_id, target_user_id) VALUES (?, ?, ?, 'success', ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("isdsssddii", $user_id, $txn_type, $amount, $ref, $desc, $payment_method, $balance_before, $balance_after, $initiated_by_id, $target_user_id);
            $stmt2->execute();
            if ($stmt2->affected_rows === 0) {
                // Pre-inserted row exists; update the balance snapshots
                $stmt3 = $conn->prepare("UPDATE transactions SET balance_before = ?, balance_after = ? WHERE reference = ? AND user_id = ?");
                $stmt3->bind_param("ddsi", $balance_before, $balance_after, $ref, $user_id);
                $stmt3->execute();
                $stmt3->close();
            }
            $stmt2->close();
 
            if ($auto_commit_was_on) {
                $conn->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($auto_commit_was_on) {
                $conn->rollback();
            }
            error_log("updateWalletBalance error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendWalletDebitNotification')) {
    function sendWalletDebitNotification($user_id, $amount, $new_balance, $description = '') {
        try {
            $phone_column = function_exists('dbh_get_users_phone_column') ? dbh_get_users_phone_column() : 'phone';
            global $db;
            if (!$db) return;
            $conn = $db->getConnection();
            $safe_col = $conn->real_escape_string($phone_column);
            $stmt = $conn->prepare("SELECT $safe_col AS phone FROM users WHERE id = ? LIMIT 1");
            if (!$stmt) return;
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if (!$user || empty($user['phone'])) return;
            $phone = $user['phone'];
            $amount_str = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format(abs((float)$amount), 2);
            $desc = $description ?: 'Wallet debit';
            $message = "Your wallet has been debited with $amount_str. New balance: ";
            $message .= (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format((float)$new_balance, 2);
            $message .= ". Ref: $desc";
            if (function_exists('sendSMS')) {
                sendSMS($phone, $message, 'wallet_debit_notification', $user_id);
            }
        } catch (Throwable $e) {
            error_log("sendWalletDebitNotification error: " . $e->getMessage());
        }
    }
}

if (!function_exists('sendWalletCreditNotification')) {
    function sendWalletCreditNotification($user_id, $amount, $new_balance, $description = '', $source = '') {
        try {
            $phone_column = function_exists('dbh_get_users_phone_column') ? dbh_get_users_phone_column() : 'phone';
            global $db;
            if (!$db) return;
            $conn = $db->getConnection();
            $safe_col = $conn->real_escape_string($phone_column);
            $stmt = $conn->prepare("SELECT $safe_col AS phone FROM users WHERE id = ? LIMIT 1");
            if (!$stmt) return;
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if (!$user || empty($user['phone'])) return;
            $phone = $user['phone'];
            $amount_str = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format(abs((float)$amount), 2);
            $desc = $description ?: 'Wallet credit';
            $message = "Your wallet has been credited with $amount_str. New balance: ";
            $message .= (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format((float)$new_balance, 2);
            $message .= ". Ref: $desc";
            if (function_exists('sendSMS')) {
                sendSMS($phone, $message, 'wallet_credit_notification', $user_id);
            }
        } catch (Throwable $e) {
            error_log("sendWalletCreditNotification error: " . $e->getMessage());
        }
    }
}

if (!function_exists('transferWalletBalance')) {
    function transferWalletBalance($from_id, $to_id, $amount, $reference = '', $description = '', $initiated_by_id = null, $target_user_id = null) {
        global $db;
        if (!isset($db) || !$from_id || !$to_id || $amount <= 0) return false;
 
        $conn = $db->getConnection();
        $auto_commit_was_on = true;
 
        try {
            $res = $conn->query("SELECT @@autocommit");
            $row = $res->fetch_row();
            $auto_commit_was_on = ($row[0] == 1);
 
            if ($auto_commit_was_on) {
                $conn->begin_transaction();
            }
 
            $conn->query("INSERT IGNORE INTO wallets (user_id, balance) VALUES ($from_id, 0)");
            $conn->query("INSERT IGNORE INTO wallets (user_id, balance) VALUES ($to_id, 0)");
 
            $stmt = $conn->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?");
            $stmt->bind_param("did", $amount, $from_id, $amount);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                if ($auto_commit_was_on) { $conn->rollback(); }
                return false;
            }
            $stmt->close();
 
            $stmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?");
            $stmt->bind_param("di", $amount, $to_id);
            $stmt->execute();
            $stmt->close();
 
            // Fetch balance snapshots for sender (from)
            $bal_res_from = $conn->query("SELECT balance FROM wallets WHERE user_id = $from_id");
            $bal_row_from = $bal_res_from->fetch_assoc();
            $from_balance_after = $bal_row_from ? (float)$bal_row_from['balance'] : 0.00;
            $from_balance_before = $from_balance_after + $amount;
 
            // Fetch balance snapshots for receiver (to)
            $bal_res_to = $conn->query("SELECT balance FROM wallets WHERE user_id = $to_id");
            $bal_row_to = $bal_res_to->fetch_assoc();
            $to_balance_after = $bal_row_to ? (float)$bal_row_to['balance'] : 0.00;
            $to_balance_before = $to_balance_after - $amount;
 
            $ref = $reference ?: 'TRF_' . time() . '_' . rand(1000, 9999);
            $desc = $description ?: 'Wallet transfer';
 
            $stmt2 = $conn->prepare("INSERT IGNORE INTO transactions (user_id, transaction_type, amount, status, reference, description, payment_method, balance_before, balance_after, initiated_by_id, target_user_id) VALUES (?, 'purchase', ?, 'success', ?, ?, 'wallet', ?, ?, ?, ?)");
            $from_desc = $desc . ' (sent)';
            $stmt2->bind_param("idssddii", $from_id, $amount, $ref, $from_desc, $from_balance_before, $from_balance_after, $initiated_by_id, $target_user_id);
            $stmt2->execute();
            if ($stmt2->affected_rows === 0) {
                $stmt3 = $conn->prepare("UPDATE transactions SET balance_before = ?, balance_after = ? WHERE reference = ? AND user_id = ?");
                $stmt3->bind_param("ddsi", $from_balance_before, $from_balance_after, $ref, $from_id);
                $stmt3->execute();
                $stmt3->close();
            }
            $stmt2->close();
 
            $stmt2 = $conn->prepare("INSERT IGNORE INTO transactions (user_id, transaction_type, amount, status, reference, description, payment_method, balance_before, balance_after, initiated_by_id, target_user_id) VALUES (?, 'topup', ?, 'success', ?, ?, 'wallet', ?, ?, ?, ?)");
            $to_desc = $desc . ' (received)';
            $stmt2->bind_param("idssddii", $to_id, $amount, $ref, $to_desc, $to_balance_before, $to_balance_after, $initiated_by_id, $target_user_id);
            $stmt2->execute();
            if ($stmt2->affected_rows === 0) {
                $stmt3 = $conn->prepare("UPDATE transactions SET balance_before = ?, balance_after = ? WHERE reference = ? AND user_id = ?");
                $stmt3->bind_param("ddsi", $to_balance_before, $to_balance_after, $ref, $to_id);
                $stmt3->execute();
                $stmt3->close();
            }
            $stmt2->close();
 
            if ($auto_commit_was_on) {
                $conn->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($auto_commit_was_on) {
                $conn->rollback();
            }
            error_log("transferWalletBalance error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('isCustomerLinkedToAgent')) {
    function isCustomerLinkedToAgent($customer_id, $agent_id) {
        global $db;
        $customer_id = (int) $customer_id;
        $agent_id = (int) $agent_id;
        if ($customer_id <= 0 || $agent_id <= 0) return false;

        // Check via agent_id column or user_referrals table
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'customer' AND agent_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $customer_id, $agent_id);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                return true;
            }
        }

        // Also check via referrals table
        $hasReferrals = false;
        try {
            $r = $db->query("SHOW TABLES LIKE 'user_referrals'");
            $hasReferrals = $r && $r->num_rows > 0;
        } catch (Exception $e) {}

        if ($hasReferrals) {
            $stmt = $db->prepare("SELECT id FROM user_referrals WHERE user_id = ? AND agent_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('ii', $customer_id, $agent_id);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('findUserIdByEmailOrPhone')) {
    function findUserIdByEmailOrPhone($identifier) {
        if (empty($identifier)) return 0;
        global $db;
        if (!$db) return 0;
        $conn = $db->getConnection();
        $phone_column = function_exists('dbh_get_users_phone_column') ? dbh_get_users_phone_column() : 'phone';
        $safe_phone_col = $conn->real_escape_string($phone_column);
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR $safe_phone_col = ? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : 0;
    }
}

if (!function_exists('ensureWalletTable')) {
    /**
     * Ensures that the wallets table exists in the database.
     */
    function ensureWalletTable() {
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;

        global $db;
        try {
            // Only attempt CREATE TABLE if we really think it's missing to avoid implicit commits in MySQL
            $db->query("CREATE TABLE IF NOT EXISTS `wallets` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}
if (!function_exists('refundBundleOrderByReference')) {
    function refundBundleOrderByReference($order_id, $reason = '', $status = 'failed') {
        global $db;
        if (!isset($db)) return array('success' => false);
        try {
            $stmt = $db->prepare("SELECT * FROM bundle_orders WHERE id = ? OR order_reference = ?");
            $stmt->bind_param("is", $order_id, $order_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$order) return array('success' => false);
            
            $user_id = $order['user_id'];
            $amount = (float)$order['amount'];
            if ($amount > 0 && updateWalletBalance($user_id, $amount, 'credit', "Refund order #".$order['id'].": ".$reason)) {
                $stmt2 = $db->prepare("UPDATE bundle_orders SET status = 'failed', provider_status = 'refunded' WHERE id = ?");
                $stmt2->bind_param("i", $order['id']);
                $stmt2->execute();
                $stmt2->close();
                return array('success' => true);
            }
        } catch (Exception $e) {}
        return array('success' => false);
    }
}

// 8. Notifications & Sidebar
if (!defined('ANALYTICS_LOADED')) {
    require_once __DIR__ . '/analytics.php';
    define('ANALYTICS_LOADED', true);
}
if (!function_exists('sendUserOrderNotification')) {
    function sendUserOrderNotification($data) {
        if (!function_exists('sendOrderConfirmationEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (function_exists('sendOrderConfirmationEmail')) {
            return sendOrderConfirmationEmail($data['customer_email'], $data['customer_name'], array(
                'order_id' => $data['order_id'],
                'network_name' => $data['network_name'],
                'package_name' => $data['package_name'],
                'phone_number' => $data['beneficiary_number'],
                'amount' => $data['amount'],
                'status' => $data['status']
            ));
        }
        return false;
    }
}

if (!function_exists('sendAdminDataOrderNotification')) {
    /**
     * Sends an email notification to the administrator for a data bundle order.
     */
    function sendAdminDataOrderNotification($data) {
        if (!defined('ADMIN_EMAIL') || empty(ADMIN_EMAIL)) return false;
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (!function_exists('sendEmail')) return false;

        $subject = "New Data Order: " . ($data['order_reference'] ?? $data['reference'] ?? 'N/A');
        
        $customer_email = $data['customer_email'] ?? '';
        $balance_html = '';
        $user_id = function_exists('findUserIdByEmailOrPhone') ? findUserIdByEmailOrPhone($customer_email) : 0;
        if ($user_id > 0 && function_exists('getWalletBalance')) {
            $balance = getWalletBalance($user_id);
            $balance_html = "<p><strong>Customer Remaining Balance:</strong> " . formatCurrency($balance) . "</p>";
        }

        $body = "<h2>New Data Bundle Order</h2>";
        $body .= "<p><strong>Reference:</strong> " . htmlspecialchars($data['order_reference'] ?? $data['reference'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Customer:</strong> " . htmlspecialchars($data['customer_name'] ?? 'N/A') . " (" . htmlspecialchars($customer_email ?? 'N/A') . ")</p>";
        $body .= "<p><strong>Beneficiary:</strong> " . htmlspecialchars($data['beneficiary_number'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Network:</strong> " . htmlspecialchars($data['network_name'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Package:</strong> " . htmlspecialchars($data['package_name'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Amount:</strong> " . formatCurrency($data['amount'] ?? 0) . "</p>";
        $body .= "<p><strong>Status:</strong> " . htmlspecialchars($data['status'] ?? 'N/A') . "</p>";
        $body .= $balance_html;
        $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";

        return sendEmail(ADMIN_EMAIL, $subject, $body);
    }
}

if (!function_exists('sendAdminResultCheckerOrderNotification')) {
    /**
     * Sends an email notification to the administrator for a result checker purchase.
     */
    function sendAdminResultCheckerOrderNotification($data) {
        if (!defined('ADMIN_EMAIL') || empty(ADMIN_EMAIL)) return false;
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (!function_exists('sendEmail')) return false;

        $subject = "Result Checker Purchase: " . ($data['reference'] ?? 'N/A');
        
        $body = "<h2>Result Checker Purchase</h2>";
        $body .= "<p><strong>Reference:</strong> " . htmlspecialchars($data['reference'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Buyer:</strong> " . htmlspecialchars($data['buyer_name'] ?? 'N/A') . " (" . htmlspecialchars($data['buyer_email'] ?? 'N/A') . ")</p>";
        $body .= "<p><strong>Exam Type:</strong> " . htmlspecialchars($data['card_type'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Quantity:</strong> " . htmlspecialchars($data['quantity'] ?? '1') . "</p>";
        $body .= "<p><strong>Amount:</strong> " . formatCurrency($data['amount'] ?? 0) . "</p>";
        $body .= "<p><strong>Status:</strong> " . htmlspecialchars($data['status'] ?? 'N/A') . "</p>";
        $body .= "<p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>";

        return sendEmail(ADMIN_EMAIL, $subject, $body);
    }
}

if (!function_exists('sendAgentProfitNotification')) {
    function sendAgentProfitNotification($data) {
        global $db;
        $agent_id = (int) ($data['agent_id'] ?? 0);
        if ($agent_id <= 0) return false;

        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (!function_exists('sendEmail')) return false;

        $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? AND (role = 'agent' OR role = 'vip') LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $agent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$agent || empty($agent['email'])) return false;

        $agent_name = htmlspecialchars($agent['full_name'] ?? 'Agent');
        $service = htmlspecialchars($data['service'] ?? 'Purchase');
        $item = htmlspecialchars($data['item'] ?? '');
        $reference = htmlspecialchars($data['reference'] ?? '');
        $customer_name = htmlspecialchars($data['customer_name'] ?? 'A customer');
        $customer_email = htmlspecialchars($data['customer_email'] ?? '');
        $beneficiary = htmlspecialchars($data['beneficiary_number'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        $profit_amount = (float) ($data['profit_amount'] ?? 0);
        $payment_method = htmlspecialchars($data['payment_method'] ?? '');
        $order_status = htmlspecialchars($data['status'] ?? '');

        $subject = "Profit Earned - {$service} ({$reference})";

        $body = "<h2>Profit Notification</h2>";
        $body .= "<p>Dear <strong>{$agent_name}</strong>,</p>";
        $body .= "<p>You have earned a profit from a sale made through your store.</p>";
        $body .= "<table style='border-collapse:collapse;width:100%'>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Service</td><td style='padding:8px;border:1px solid #ddd'>{$service}</td></tr>";
        if ($item) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Item</td><td style='padding:8px;border:1px solid #ddd'>{$item}</td></tr>";
        }
        if ($reference) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Reference</td><td style='padding:8px;border:1px solid #ddd'>{$reference}</td></tr>";
        }
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Customer</td><td style='padding:8px;border:1px solid #ddd'>{$customer_name}" . ($customer_email ? " ({$customer_email})" : "") . "</td></tr>";
        if ($beneficiary) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Beneficiary</td><td style='padding:8px;border:1px solid #ddd'>{$beneficiary}</td></tr>";
        }
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Amount Paid</td><td style='padding:8px;border:1px solid #ddd'>" . formatCurrency($amount) . "</td></tr>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Your Profit</td><td style='padding:8px;border:1px solid #ddd'><strong style='color:#16a34a'>" . formatCurrency($profit_amount) . "</strong></td></tr>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Payment Method</td><td style='padding:8px;border:1px solid #ddd'>" . ucfirst($payment_method) . "</td></tr>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Status</td><td style='padding:8px;border:1px solid #ddd'>" . ucfirst($order_status) . "</td></tr>";
        $body .= "</table>";
        $body .= "<p style='margin-top:16px'>Thank you for using " . htmlspecialchars(function_exists('getSiteName') ? getSiteName() : 'Constechzhub') . ".</p>";

        return sendEmail($agent['email'], $subject, $body);
    }
}

if (!function_exists('ensureAgentStoreOrderEmailSettingColumn')) {
    function ensureAgentStoreOrderEmailSettingColumn() {
        global $db;
        try {
            if (function_exists('dbh_table_has_column') && !dbh_table_has_column('users', 'receive_store_order_emails')) {
                $db->query("ALTER TABLE `users` ADD COLUMN `receive_store_order_emails` TINYINT(1) NOT NULL DEFAULT '1' AFTER `is_active`;");
            }
        } catch (Exception $e) {
            error_log("Failed to alter users table for receive_store_order_emails: " . $e->getMessage());
        }
    }
}

if (!function_exists('sendAgentOrderNotification')) {
    function sendAgentOrderNotification($data) {
        global $db;
        $agent_id = (int) ($data['agent_id'] ?? 0);
        if ($agent_id <= 0) return false;

        if (function_exists('ensureAgentStoreOrderEmailSettingColumn')) {
            ensureAgentStoreOrderEmailSettingColumn();
        }

        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (!function_exists('sendEmail')) return false;

        $stmt = $db->prepare("SELECT full_name, email, receive_store_order_emails FROM users WHERE id = ? AND (role = 'agent' OR role = 'vip') LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $agent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$agent || empty($agent['email'])) return false;

        // Skip sending email if the agent has turned off store order emails
        if (isset($agent['receive_store_order_emails']) && (int) $agent['receive_store_order_emails'] === 0) {
            return false;
        }

        $agent_name = htmlspecialchars($agent['full_name'] ?? 'Agent');
        $service = htmlspecialchars($data['service'] ?? 'Purchase');
        $item = htmlspecialchars($data['item'] ?? '');
        $reference = htmlspecialchars($data['reference'] ?? '');
        $customer_name = htmlspecialchars($data['customer_name'] ?? 'A customer');
        $customer_email = htmlspecialchars($data['customer_email'] ?? '');
        $beneficiary = htmlspecialchars($data['beneficiary_number'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        $payment_method = htmlspecialchars($data['payment_method'] ?? '');
        $order_status = htmlspecialchars($data['status'] ?? '');

        $subject = "New Order Placed - {$service} ({$reference})";

        $body = "<h2>New Order Notification</h2>";
        $body .= "<p>Dear <strong>{$agent_name}</strong>,</p>";
        $body .= "<p>An order has been placed through your store link.</p>";
        $body .= "<table style='border-collapse:collapse;width:100%'>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Service</td><td style='padding:8px;border:1px solid #ddd'>{$service}</td></tr>";
        if ($item) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Item</td><td style='padding:8px;border:1px solid #ddd'>{$item}</td></tr>";
        }
        if ($reference) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Order Reference</td><td style='padding:8px;border:1px solid #ddd'>{$reference}</td></tr>";
        }
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Customer</td><td style='padding:8px;border:1px solid #ddd'>{$customer_name}" . ($customer_email ? " ({$customer_email})" : "") . "</td></tr>";
        if ($beneficiary) {
            $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Beneficiary</td><td style='padding:8px;border:1px solid #ddd'>{$beneficiary}</td></tr>";
        }
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Total Amount Paid</td><td style='padding:8px;border:1px solid #ddd'>" . formatCurrency($amount) . "</td></tr>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Payment Method</td><td style='padding:8px;border:1px solid #ddd'>" . ucfirst($payment_method) . "</td></tr>";
        $body .= "<tr><td style='padding:8px;border:1px solid #ddd;font-weight:bold'>Status</td><td style='padding:8px;border:1px solid #ddd'>" . ucfirst($order_status) . "</td></tr>";
        $body .= "</table>";
        $body .= "<p style='margin-top:16px'>Thank you for using " . htmlspecialchars(function_exists('getSiteName') ? getSiteName() : 'Constechzhub') . ".</p>";

        return sendEmail($agent['email'], $subject, $body);
    }
}


if (!function_exists('renderAdminSidebar')) {
    function renderAdminSidebar() {
        $cur = basename($_SERVER['PHP_SELF']);
        
        $menu_groups = array(
            'users' => array(
                'label' => 'User Control',
                'icon' => 'fa-users',
                'items' => array(
                    array('url' => 'users.php', 'icon' => 'fa-users', 'label' => 'Users'),
                    array('url' => 'agents.php', 'icon' => 'fa-user-tie', 'label' => 'Agents'),
                    array('url' => 'user-access.php', 'icon' => 'fa-user-shield', 'label' => 'User Access'),
                    array('url' => 'afa-registration.php', 'icon' => 'fa-user-plus', 'label' => 'AFA Registration')
                )
            ),
            'catalog' => array(
                'label' => 'Catalog & Pricing',
                'icon' => 'fa-tags',
                'items' => array(
                    array('url' => 'products.php', 'icon' => 'fa-box', 'label' => 'Products'),
                    array('url' => 'packages.php', 'icon' => 'fa-boxes', 'label' => 'Packages'),
                    array('url' => 'pricing.php', 'icon' => 'fa-tags', 'label' => 'Pricing'),
                    array('url' => 'result-checker.php', 'icon' => 'fa-graduation-cap', 'label' => 'Result Checker')
                )
            ),
            'financials' => array(
                'label' => 'Financials',
                'icon' => 'fa-wallet',
                'items' => array(
                    array('url' => 'reports.php', 'icon' => 'fa-file-alt', 'label' => 'Reports'),
                    array('url' => 'transactions.php', 'icon' => 'fa-exchange-alt', 'label' => 'Transactions'),
                    array('url' => 'topup-requests.php', 'icon' => 'fa-wallet', 'label' => 'Topup Requests'),
                    array('url' => 'manual_topup.php', 'icon' => 'fa-hand-holding-usd', 'label' => 'Manual Topup'),
                    array('url' => 'profit-stats.php', 'icon' => 'fa-chart-pie', 'label' => 'Profit Stats'),
                    array('url' => 'profit-monitor.php', 'icon' => 'fa-chart-line', 'label' => 'Profit Monitor'),
                    array('url' => 'profit-withdrawals.php', 'icon' => 'fa-money-bill-wave', 'label' => 'Profit Withdrawals'),
                    array('url' => 'commission-payouts.php', 'icon' => 'fa-coins', 'label' => 'Commission Payouts'),
                    array('url' => 'commission-settings.php', 'icon' => 'fa-percentage', 'label' => 'Commission Settings'),
                    array('url' => 'commission-payout-settings.php', 'icon' => 'fa-sliders-h', 'label' => 'Payout Settings')
                )
            ),
            'marketing' => array(
                'label' => 'Marketing & Support',
                'icon' => 'fa-bullhorn',
                'items' => array(
                    array('url' => 'constchat.php', 'icon' => 'fa-comments', 'label' => 'Constchat'),
                    array('url' => 'sms-broadcast.php', 'icon' => 'fa-sms', 'label' => 'SMS Broadcast'),
                    array('url' => 'email-broadcast.php', 'icon' => 'fa-envelope', 'label' => 'Email Broadcast'),
                    array('url' => 'notifications.php', 'icon' => 'fa-bell', 'label' => 'Notifications'),
                    array('url' => 'guest-notifications.php', 'icon' => 'fa-bullhorn', 'label' => 'Guest Notifications')
                )
            ),
            'configuration' => array(
                'label' => 'Configuration',
                'icon' => 'fa-sliders-h',
                'items' => array(
                    array('url' => 'settings.php', 'icon' => 'fa-cog', 'label' => 'Main Settings'),
                    array('url' => 'api-providers.php', 'icon' => 'fa-network-wired', 'label' => 'API Providers'),
                    array('url' => 'api-applications.php', 'icon' => 'fa-code-branch', 'label' => 'API Applications'),
                    array('url' => 'seo-settings.php', 'icon' => 'fa-search', 'label' => 'SEO Settings'),
                    array('url' => 'pwa-settings.php', 'icon' => 'fa-mobile', 'label' => 'PWA Settings'),
                    array('url' => 'sms-settings.php', 'icon' => 'fa-comment-dots', 'label' => 'SMS Settings'),
                    array('url' => 'smtp-settings.php', 'icon' => 'fa-at', 'label' => 'SMTP Settings'),
                    array('url' => 'paystack-fee-config.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'Paystack Fees'),
                    array('url' => 'paystack-order-recovery.php', 'icon' => 'fa-sync-alt', 'label' => 'Paystack Order Recovery')
                )
            )
        );
        
        echo '<ul class="nav flex-column sidebar-nav">';
        
        // Render Dashboard as a standalone root-level item
        $db_act = ($cur === 'dashboard.php') ? 'active' : '';
        echo '<li class="nav-item">';
        echo '<a class="nav-link ' . $db_act . '" href="dashboard.php">';
        echo '<i class="fas fa-tachometer-alt"></i>';
        echo '<span>Dashboard</span>';
        echo '</a>';
        echo '</li>';
        
        foreach ($menu_groups as $group_key => $group) {
            $is_active_group = false;
            foreach ($group['items'] as $item) {
                if ($cur === $item['url']) {
                    $is_active_group = true;
                    break;
                }
            }
            
            $grp_class = $is_active_group ? 'sidebar-dropdown active-group' : 'sidebar-dropdown';
            $expanded_attr = $is_active_group ? 'true' : 'false';
            
            echo '<li class="' . $grp_class . '">';
            echo '<a class="nav-link sidebar-dropdown-toggle" href="javascript:void(0);" aria-expanded="' . $expanded_attr . '">';
            echo '<i class="fas ' . $group['icon'] . '"></i>';
            echo '<span>' . $group['label'] . '</span>';
            echo '<i class="fas fa-chevron-right dropdown-arrow"></i>';
            echo '</a>';
            
            echo '<ul class="sidebar-submenu">';
            foreach ($group['items'] as $item) {
                $act = ($cur === $item['url']) ? 'active' : '';
                echo '<li class="submenu-item">';
                echo '<a class="nav-link submenu-link ' . $act . '" href="' . $item['url'] . '">';
                echo '<i class="fas ' . $item['icon'] . '"></i>';
                echo '<span>' . $item['label'] . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
            echo '</li>';
        }
        echo '</ul>';
        
        // Output inline script to handle collapsible dropdowns dynamically and store state
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var toggles = document.querySelectorAll(".sidebar-dropdown-toggle");
            toggles.forEach(function(toggle) {
                toggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    var parent = this.parentElement;
                    var isExpanded = parent.classList.contains("active-group");
                    
                    // Accordion behavior: close other groups if opening this one
                    if (!isExpanded) {
                        var openGroups = document.querySelectorAll(".sidebar-dropdown.active-group");
                        openGroups.forEach(function(og) {
                            og.classList.remove("active-group");
                            var ogToggle = og.querySelector(".sidebar-dropdown-toggle");
                            if (ogToggle) {
                                ogToggle.setAttribute("aria-expanded", "false");
                            }
                        });
                    }
                    
                    // Toggle current group
                    if (isExpanded) {
                        parent.classList.remove("active-group");
                        this.setAttribute("aria-expanded", "false");
                    } else {
                        parent.classList.add("active-group");
                        this.setAttribute("aria-expanded", "true");
                    }
                });
            });
        });
        </script>';
    }
}

if (!function_exists('renderAgentSidebar')) {
    function renderAgentSidebar() {
        $cur = basename($_SERVER['PHP_SELF']);
        
        $menu_groups = array(
            'services' => array(
                'label' => 'Operations & Services',
                'icon' => 'fa-layer-group',
                'items' => array(
                    array('url' => 'mtn-business.php', 'icon' => 'fa-mobile-alt', 'label' => 'MTN Business'),
                    array('url' => 'at-business.php', 'icon' => 'fa-mobile-alt', 'label' => 'AT Business'),
                    array('url' => 'telecel-business.php', 'icon' => 'fa-mobile-alt', 'label' => 'Telecel Business'),
                    array('url' => 'bulk-mtn.php', 'icon' => 'fa-layer-group', 'label' => 'Bulk MTN'),
                    array('url' => 'result-checker.php', 'icon' => 'fa-graduation-cap', 'label' => 'Result Checker'),
                    array('url' => 'afa-registration.php', 'icon' => 'fa-id-card', 'label' => 'AFA Registration')
                )
            ),
            'financials' => array(
                'label' => 'Financials & Reports',
                'icon' => 'fa-wallet',
                'items' => array(
                    array('url' => 'wallet.php', 'icon' => 'fa-wallet', 'label' => 'My Wallet'),
                    array('url' => 'histories.php', 'icon' => 'fa-history', 'label' => 'Data Histories'),
                    array('url' => 'transactions.php', 'icon' => 'fa-exchange-alt', 'label' => 'Transactions'),
                    array('url' => 'paystack-order-recovery.php', 'icon' => 'fa-sync-alt', 'label' => 'Paystack Order Recovery'),
                    array('url' => 'commission.php', 'icon' => 'fa-percentage', 'label' => 'Commissions'),
                    array('url' => 'withdraw-profit.php', 'icon' => 'fa-money-bill-wave', 'label' => 'Withdraw Profit'),
                    array('url' => 'payment-settings.php', 'icon' => 'fa-credit-card', 'label' => 'Payment Settings'),
                    array('url' => 'pricing.php', 'icon' => 'fa-tags', 'label' => 'My Pricing')
                )
            ),
            'management' => array(
                'label' => 'Customer & API',
                'icon' => 'fa-users',
                'items' => array(
                    array('url' => 'customers.php', 'icon' => 'fa-users', 'label' => 'My Customers'),
                    array('url' => 'api-access.php', 'icon' => 'fa-code', 'label' => 'API Access')
                )
            ),
            'support' => array(
                'label' => 'Support & Help',
                'icon' => 'fa-headset',
                'items' => array(
                    array('url' => 'constchat.php', 'icon' => 'fa-comments', 'label' => 'Constchat'),
                    array('url' => 'support.php', 'icon' => 'fa-headset', 'label' => 'Support'),
                    array('url' => 'settings.php', 'icon' => 'fa-cog', 'label' => 'Settings')
                )
            )
        );

        echo '<ul class="sidebar-nav" style="list-style: none; padding: 0; margin: 0;">';
        
        // Render Dashboard as a standalone root-level item
        $db_act = ($cur === 'dashboard.php') ? 'active' : '';
        echo '<li class="nav-item">';
        echo '<a class="nav-link ' . $db_act . '" href="dashboard.php">';
        echo '<i class="fas fa-tachometer-alt"></i> ';
        echo '<span>Dashboard</span>';
        echo '</a>';
        echo '</li>';
        
        foreach ($menu_groups as $group_key => $group) {
            $is_active_group = false;
            foreach ($group['items'] as $item) {
                if ($cur === $item['url']) {
                    $is_active_group = true;
                    break;
                }
            }
            
            $grp_class = $is_active_group ? 'sidebar-dropdown active-group' : 'sidebar-dropdown';
            $expanded_attr = $is_active_group ? 'true' : 'false';
            
            echo '<li class="' . $grp_class . '">';
            echo '<a class="nav-link sidebar-dropdown-toggle" href="javascript:void(0);" aria-expanded="' . $expanded_attr . '">';
            echo '<i class="fas ' . $group['icon'] . '"></i> ';
            echo '<span>' . $group['label'] . '</span>';
            echo '<i class="fas fa-chevron-right dropdown-arrow"></i>';
            echo '</a>';
            
            echo '<ul class="sidebar-submenu">';
            foreach ($group['items'] as $item) {
                $act = ($cur === $item['url']) ? 'active' : '';
                echo '<li class="submenu-item">';
                echo '<a class="nav-link submenu-link ' . $act . '" href="' . $item['url'] . '">';
                echo '<i class="fas ' . $item['icon'] . '"></i> ';
                echo '<span>' . $item['label'] . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
            echo '</li>';
        }
        echo '</ul>';
        
        // Output inline script to handle collapsible dropdowns dynamically and store state
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            var toggles = document.querySelectorAll(".sidebar-dropdown-toggle");
            toggles.forEach(function(toggle) {
                toggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    var parent = this.parentElement;
                    var isExpanded = parent.classList.contains("active-group");
                    
                    // Accordion behavior: close other groups if opening this one
                    if (!isExpanded) {
                        var openGroups = document.querySelectorAll(".sidebar-dropdown.active-group");
                        openGroups.forEach(function(og) {
                            og.classList.remove("active-group");
                            var ogToggle = og.querySelector(".sidebar-dropdown-toggle");
                            if (ogToggle) {
                                ogToggle.setAttribute("aria-expanded", "false");
                            }
                        });
                    }
                    
                    // Toggle current group
                    if (isExpanded) {
                        parent.classList.remove("active-group");
                        this.setAttribute("aria-expanded", "false");
                    } else {
                        parent.classList.add("active-group");
                        this.setAttribute("aria-expanded", "true");
                    }
                });
            });
        });
        </script>';
    }
}

if (!function_exists('isPaymentGatewayEnabled')) {
    /**
     * Checks if a specific payment gateway is enabled in settings.
     */
    function isPaymentGatewayEnabled($gateway) {
        $active = getActivePaymentGateway();
        if ($gateway === 'paystack') {
            return ($active === 'paystack' || getSetting('paystack_enabled', '1') === '1');
        }
        if ($gateway === 'moolre') {
            return ($active === 'moolre' || getSetting('moolre_enabled', '0') === '1');
        }
        return false;
    }
}

if (!function_exists('getEnabledPaymentGateways')) {
    /**
     * Returns an array of enabled payment gateway identifiers.
     */
    function getEnabledPaymentGateways() {
        $enabled = [];
        if (isPaymentGatewayEnabled('paystack')) {
            $enabled[] = 'paystack';
        }
        if (isPaymentGatewayEnabled('moolre')) {
            $enabled[] = 'moolre';
        }
        return $enabled;
    }
}

if (!function_exists('getEffectiveTopupLimits')) {
    /**
     * Retrieves the effective min/max top-up limits for a user role.
     */
    function getEffectiveTopupLimits($user_id, $role = 'customer') {
        $role = strtolower(trim((string)$role));
        $prefix = ($role === 'agent' || $role === 'vip') ? 'agent_topup_' : 'customer_topup_';
        
        return [
            'min' => (float) getSetting($prefix . 'min_amount', 1.00),
            'max' => (float) getSetting($prefix . 'max_amount', 5000.00)
        ];
    }
}

if (!function_exists('formatProfitWithdrawalFeeScheduleLabel')) {
    /**
     * Formats the profit withdrawal fee schedule for display.
     */
    function formatProfitWithdrawalFeeScheduleLabel($schedule) {
        if (empty($schedule)) return 'No fees';
        $labels = [];
        foreach ($schedule as $tier) {
            $labels[] = formatCurrency($tier['fee'] ?? 0) . ' fee for up to ' . formatCurrency($tier['max_amount'] ?? 0);
        }
        return implode(', ', $labels);
    }
}

if (!function_exists('formatBundleDisplaySize')) {
    /**
     * Formats a data bundle size for display (e.g. 1GB, 500MB).
     */
    function formatBundleDisplaySize($size) {
        if (empty($size)) return 'N/A';
        $sizeStr = strtoupper(trim((string)$size));
        
        // If it already has a unit, return it
        if (preg_match('/(GB|MB|TB|KB)$/', $sizeStr)) {
            return $sizeStr;
        }
        
        // If it's numeric, assume it's in GB if >= 1, else MB
        if (is_numeric($size)) {
            $val = (float)$size;
            if ($val >= 1000) return ($val/1000) . 'TB';
            if ($val >= 1) return $val . 'GB';
            return ($val * 1000) . 'MB';
        }
        
        return $sizeStr;
    }
}

if (!function_exists('ensureDataPackageStockStatusColumn')) {

    /**
     * Ensures that the stock_status column exists in the data_packages table.
     */
    function ensureDataPackageStockStatusColumn() {
        global $db;
        try {
            if (!dbh_table_has_column('data_packages', 'stock_status')) {
                $db->query("ALTER TABLE `data_packages` ADD COLUMN `stock_status` VARCHAR(20) DEFAULT 'in_stock' AFTER `package_type`;");
            }
        } catch (Exception $e) {}
    }
}

if (!function_exists('parseSmsPhoneList')) {
    /**
     * Parses a comma or newline separated list of phone numbers.
     */
    function parseSmsPhoneList($list) {
        if (empty($list)) return [];
        $raw = preg_split('/[,\n\r]+/', (string)$list);
        $phones = [];
        foreach ($raw as $val) {
            $val = trim($val);
            if ($val !== '') $phones[] = $val;
        }
        return array_unique($phones);
    }
}

// 9. Webhook Logging System
if (!function_exists('ensureProviderWebhookTables')) {
    function ensureProviderWebhookTables() {
        global $db;
        if (!isset($db)) return;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `provider_webhook_logs` (
                `id` int NOT NULL AUTO_INCREMENT,
                `provider` varchar(50) NOT NULL,
                `event` varchar(100) DEFAULT NULL,
                `reference` varchar(100) DEFAULT NULL,
                `status` varchar(50) DEFAULT NULL,
                `order_id` int DEFAULT '0',
                `is_processed` tinyint(1) DEFAULT '0',
                `error_message` text,
                `payload` longtext,
                `ip_address` varchar(45) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('recordProviderWebhookLog')) {
    function recordProviderWebhookLog($p, $e, $r, $s, $oid, $iproc, $err, $pay) {
        global $db;
        if (!isset($db)) return;
        try {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $pay_str = is_array($pay) ? json_encode($pay) : (string)$pay;
            $stmt = $db->prepare("INSERT INTO provider_webhook_logs (provider, event, reference, status, order_id, is_processed, error_message, payload, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssiisss', $p, $e, $r, $s, $oid, $iproc, $err, $pay_str, $ip);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $ex) {}
    }
}

if (!function_exists('generateReference')) {
    /**
     * Generates a unique reference string with an optional prefix.
     */
    function generateReference($prefix = 'REF') {
        return strtoupper($prefix) . '_' . date('YmdHis') . '_' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('sendRegistrationCredentialsNotification')) {
    /**
     * Sends registration credentials via Email and/or SMS.
     */
    function sendRegistrationCredentialsNotification($data, $user_id) {
        $full_name = $data['full_name'] ?? 'User';
        $email = $data['email'] ?? '';
        $phone = $data['phone'] ?? '';
        $username = $data['username'] ?? '';
        $password = $data['plain_password'] ?? '';
        $brand = $data['brand'] ?? getSiteName();
        
        $login_url = defined('SITE_URL') ? SITE_URL . '/login.php' : '';
        
        // Try Email
        if (!empty($email) && function_exists('sendEmail')) {
            $subject = "Your account credentials for $brand";
            $message = "Hello $full_name,<br><br>Your account has been created successfully.<br><br>Username: $username<br>Password: $password<br><br>Login here: $login_url";
            sendEmail($email, $subject, $message);
        }
        
        // Try SMS
        if (!empty($phone) && function_exists('sendSMS')) {
            $sms_msg = "Welcome to $brand! Your account: User: $username, Pass: $password. Login: $login_url";
            sendSMS($phone, $sms_msg, 'registration', $user_id);
        }
        
        return true;
    }
}

if (!function_exists('isMaintenanceModeEnabled')) {
    /**
     * Checks if maintenance mode is currently enabled in settings.
     */
    function isMaintenanceModeEnabled() {
        return getSetting('maintenance_mode', '0') === '1';
    }
}

if (!function_exists('shouldBypassMaintenanceMode')) {
    /**
     * Determines if the current request should bypass maintenance mode.
     */
    function shouldBypassMaintenanceMode() {
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
            return true;
        }
        // Could add IP whitelisting here
        return false;
    }
}

if (!function_exists('renderMaintenanceNotice')) {
    /**
     * Renders the maintenance mode page and terminates execution.
     */
    function renderMaintenanceNotice() {
        static $is_rendering = false;
        if ($is_rendering) {
            echo "<h1>Site under maintenance</h1><p>We'll be back soon.</p>";
            exit();
        }
        $is_rendering = true;

        $maintenance_file = __DIR__ . '/../maintenance.php';
        if (is_file($maintenance_file)) {
            include $maintenance_file;
        } else {
            echo "<h1>Site under maintenance</h1><p>We'll be back soon.</p>";
        }
        exit();
    }
}

if (!function_exists('ensureAfaRegistrationTables')) {
    function ensureAfaRegistrationTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `afa_registrations` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `full_name` varchar(100) DEFAULT NULL,
                `phone_number` varchar(20) DEFAULT NULL,
                `status` varchar(20) DEFAULT 'pending',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureAgentCommissionTables')) {
    function ensureAgentCommissionTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `agent_commissions` (
                `id` int NOT NULL AUTO_INCREMENT,
                `agent_id` int NOT NULL,
                `transaction_id` int NOT NULL,
                `amount` decimal(10,2) NOT NULL,
                `status` varchar(20) DEFAULT 'earned',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureEmailBroadcastTables')) {
    function ensureEmailBroadcastTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `email_broadcasts` (
                `id` int NOT NULL AUTO_INCREMENT,
                `subject` varchar(255) NOT NULL,
                `message` text NOT NULL,
                `audience` varchar(50) DEFAULT 'all',
                `status` varchar(20) DEFAULT 'pending',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureEmailChangeRequestsTable')) {
    function ensureEmailChangeRequestsTable() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `email_change_requests` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `current_email` varchar(190) NOT NULL,
                `requested_email` varchar(190) NOT NULL,
                `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reviewed_at` timestamp NULL DEFAULT NULL,
                `reviewed_by` int DEFAULT NULL,
                `admin_note` text,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureProductOrderTables')) {
    function ensureProductOrderTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `product_orders` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `product_id` int NOT NULL,
                `package_id` int NOT NULL,
                `amount` decimal(10,2) NOT NULL,
                `status` varchar(20) DEFAULT 'pending',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureProfitWithdrawalTables')) {
    function ensureProfitWithdrawalTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `profit_withdrawals` (
                `id` int NOT NULL AUTO_INCREMENT,
                `agent_id` int NOT NULL,
                `amount` decimal(10,2) NOT NULL,
                `fee_amount` decimal(10,2) DEFAULT '0.00',
                `payout_method` varchar(50) DEFAULT 'momo',
                `status` varchar(20) DEFAULT 'pending',
                `admin_notes` text,
                `processed_by` int DEFAULT NULL,
                `processed_at` timestamp NULL DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureAgentPaymentSettingsTable')) {
    /**
     * Ensures that the agent_payment_settings and agent_paystack_settings tables exist.
     */
    function ensureAgentPaymentSettingsTable() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `agent_payment_settings` (
                `id` int NOT NULL AUTO_INCREMENT,
                `agent_id` int NOT NULL,
                `allow_paystack` tinyint(1) NOT NULL DEFAULT '1',
                `allow_topup_request` tinyint(1) NOT NULL DEFAULT '1',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_agent_id` (`agent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $db->query("CREATE TABLE IF NOT EXISTS `agent_paystack_settings` (
                `id` int NOT NULL AUTO_INCREMENT,
                `agent_id` int NOT NULL,
                `public_key` varchar(255) NOT NULL,
                `secret_key` varchar(255) NOT NULL,
                `is_active` tinyint(1) DEFAULT '0',
                `min_topup_agent_customer` decimal(10,2) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_agent_id` (`agent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureAgentSmsSettingsTable')) {
    /**
     * Ensures that the agent_sms_settings table exists.
     */
    function ensureAgentSmsSettingsTable() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `agent_sms_settings` (
                `agent_id` int NOT NULL,
                `sender_label` varchar(11) DEFAULT NULL,
                `default_signature` varchar(80) DEFAULT NULL,
                `default_message` text,
                `include_customer_name` tinyint(1) NOT NULL DEFAULT '1',
                `mnotify_api_key` text,
                `mnotify_sender_id` varchar(20) DEFAULT NULL,
                `mnotify_is_active` tinyint(1) NOT NULL DEFAULT '0',
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`agent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureResultCheckerTables')) {
    function ensureResultCheckerTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `result_checkers` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `exam_type` varchar(50) DEFAULT NULL,
                `amount` decimal(10,2) NOT NULL,
                `status` varchar(20) DEFAULT 'pending',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureSmsSupportTables')) {
    function ensureSmsSupportTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `sms_settings` (
                `id` int NOT NULL AUTO_INCREMENT,
                `provider` varchar(50) NOT NULL,
                `api_key` varchar(255) DEFAULT NULL,
                `sender_id` varchar(20) DEFAULT NULL,
                `is_active` tinyint(1) DEFAULT '0',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('isSMSFeatureEnabled')) {
    function isSMSFeatureEnabled() {
        return getSetting('sms_enabled', '0') === '1';
    }
}

if (!function_exists('getPaystackTransferSecretKey')) {
    function getPaystackTransferSecretKey() {
        return getSetting('paystack_secret_key', defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '');
    }
}

if (!function_exists('isPaystackTransferAutomationAvailable')) {
    function isPaystackTransferAutomationAvailable() {
        return getSetting('paystack_transfer_otp_disabled', '0') === '1';
    }
}

if (!function_exists('getAgentStoreProfitWithdrawalBalance')) {
    function getAgentStoreProfitWithdrawalBalance($agent_id) {
        return 0.00; 
    }
}

if (!function_exists('dbh_get_users_phone_column')) {
    function dbh_get_users_phone_column() {
        return 'phone';
    }
}

if (!function_exists('createPaystackMobileMoneyRecipient')) {
    function createPaystackMobileMoneyRecipient($name, $number, $network, &$error = '') {
        $secret_key = getPaystackTransferSecretKey();
        if (empty($secret_key)) {
            $error = 'Paystack secret key is not configured.';
            return false;
        }

        $network_lower = strtolower(trim($network));
        $bank_code = '';
        if (strpos($network_lower, 'mtn') !== false) {
            $bank_code = 'MTN';
        } elseif (strpos($network_lower, 'telecel') !== false || strpos($network_lower, 'voda') !== false) {
            $bank_code = 'VOD';
        } elseif (strpos($network_lower, 'airtel') !== false || strpos($network_lower, 'tigo') !== false) {
            $bank_code = 'ATL';
        } else {
            $error = 'Invalid network provider: ' . $network;
            return false;
        }

        $url = "https://api.paystack.co/transferrecipient";
        $fields = [
            "type" => "mobile_money",
            "name" => $name,
            "account_number" => formatPhone($number),
            "bank_code" => $bank_code,
            "currency" => "GHS"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $secret_key,
            "Content-Type: application/json",
            "Cache-Control: no-cache"
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error = 'cURL Error: ' . $err;
            return false;
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            $error = $result['message'] ?? 'Failed to create recipient';
            return false;
        }

        return [
            'recipient_code' => $result['data']['recipient_code'] ?? '',
            'bank_code' => $bank_code
        ];
    }
}

if (!function_exists('initiatePaystackProfitTransfer')) {
    function initiatePaystackProfitTransfer($recipient_code, $amount, $reference, $reason, &$error = '') {
        $secret_key = getPaystackTransferSecretKey();
        if (empty($secret_key)) {
            $error = 'Paystack secret key is not configured.';
            return false;
        }

        $url = "https://api.paystack.co/transfer";
        $fields = [
            "source" => "balance",
            "amount" => (int)round($amount * 100), // Convert to GHS pesewas/kobo
            "recipient" => $recipient_code,
            "reference" => $reference,
            "reason" => $reason
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $secret_key,
            "Content-Type: application/json",
            "Cache-Control: no-cache"
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error = 'cURL Error: ' . $err;
            return false;
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            $error = $result['message'] ?? 'Failed to initiate transfer';
            return false;
        }

        return [
            'transfer_code' => $result['data']['transfer_code'] ?? '',
            'provider_reference' => $result['data']['reference'] ?? $reference,
            'status' => $result['data']['status'] ?? 'pending',
            'response' => $result
        ];
    }
}

if (!function_exists('verifyPaystackWebhookSignature')) {
    function verifyPaystackWebhookSignature($payload, $signature) {
        $secret_key = getPaystackTransferSecretKey();
        if (empty($secret_key) || empty($signature)) {
            return false;
        }
        $expected = hash_hmac('sha512', $payload, $secret_key);
        return hash_equals($expected, $signature);
    }
}

if (!function_exists('verifyPaystackProfitTransfer')) {
    function verifyPaystackProfitTransfer($reference, &$error = '') {
        $secret_key = getPaystackTransferSecretKey();
        if (empty($secret_key)) {
            $error = 'Paystack secret key is not configured.';
            return false;
        }

        $url = "https://api.paystack.co/transfer/verify/" . rawurlencode($reference);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $secret_key,
            "Content-Type: application/json",
            "Cache-Control: no-cache"
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $error = 'cURL Error: ' . $err;
            return false;
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            $error = $result['message'] ?? 'Failed to verify transfer';
            return false;
        }

        return [
            'transfer_code' => $result['data']['transfer_code'] ?? '',
            'provider_reference' => $result['data']['reference'] ?? $reference,
            'status' => strtolower(trim((string)($result['data']['status'] ?? 'pending'))),
            'response' => $result
        ];
    }
}

if (!function_exists('requestMoolreMomoPayout')) {
    function requestMoolreMomoPayout($data) {
        return ['success' => false, 'message' => 'Moolre payout stub'];
    }
}

if (!function_exists('ensureNotificationTables')) {
    /**
     * Ensures that the notifications table exists in the database.
     */
    function ensureNotificationTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                target_audience ENUM('all', 'agents', 'customers', 'guests') NOT NULL DEFAULT 'all',
                notification_type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
                is_active BOOLEAN DEFAULT TRUE,
                starts_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                display_order INT DEFAULT 0,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                image_path VARCHAR(255) DEFAULT NULL,
                link_url VARCHAR(255) DEFAULT NULL,
                cta_text VARCHAR(120) DEFAULT NULL,
                cta_secondary_url VARCHAR(255) DEFAULT NULL,
                cta_secondary_text VARCHAR(120) DEFAULT NULL,
                cta_new_tab TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_target_audience (target_audience),
                INDEX idx_is_active (is_active),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('renderNotificationSlides')) {
    /**
     * Renders a carousel of active notifications for a specific audience.
     */
    function renderNotificationSlides($audience = 'all') {
        global $db;
        ensureNotificationTables();
        
        $notifications = [];
        try {
            $stmt = $db->prepare("
                SELECT * FROM notifications 
                WHERE is_active = 1 
                  AND (target_audience = 'all' OR target_audience = ?)
                  AND (starts_at IS NULL OR starts_at <= NOW())
                  AND (expires_at IS NULL OR expires_at >= NOW())
                ORDER BY display_order ASC, created_at DESC
            ");
            if ($stmt) {
                $stmt->bind_param("s", $audience);
                $stmt->execute();
                $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        } catch (Exception $e) {
            error_log('renderNotificationSlides failed: ' . $e->getMessage());
        }

        if (empty($notifications)) {
            return '';
        }

        ob_start();
        ?>
        <div class="notification-carousel" style="margin-bottom: 1.5rem;">
            <div class="carousel-container">
                <?php foreach ($notifications as $n): ?>
                    <div class="carousel-item alert alert-<?php echo htmlspecialchars($n['notification_type']); ?>">
                        <div class="carousel-item-content">
                            <h4 class="alert-heading"><?php echo htmlspecialchars($n['title']); ?></h4>
                            <p><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>
                            <?php if ($n['link_url']): ?>
                                <hr>
                                <a href="<?php echo htmlspecialchars($n['link_url']); ?>" class="btn btn-sm btn-<?php echo htmlspecialchars($n['notification_type']); ?>" <?php echo $n['cta_new_tab'] ? 'target="_blank"' : ''; ?>>
                                    <?php echo htmlspecialchars($n['cta_text'] ?: 'Learn More'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .notification-carousel { overflow: hidden; position: relative; border-radius: 12px; }
            .carousel-container { display: flex; transition: transform 0.5s ease; }
            .carousel-item { min-width: 100%; margin: 0 !important; border-radius: 12px; }
            .alert-heading { margin-top: 0; font-weight: 700; }
        </style>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('ensureTopupSettingsTable')) {
    function ensureTopupSettingsTable() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `topup_settings` (
                `id` int NOT NULL AUTO_INCREMENT,
                `user_id` int NOT NULL,
                `setting_key` varchar(100) NOT NULL,
                `setting_value` text,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_user_key` (`user_id`, `setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureTopupRequestTables')) {
    function ensureTopupRequestTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `topup_requests` (
                `id` int NOT NULL AUTO_INCREMENT,
                `request_id` varchar(32) NOT NULL,
                `requester_id` int NOT NULL,
                `requester_type` enum('customer','agent','admin') NOT NULL,
                `target_type` enum('admin','agent') NOT NULL,
                `target_agent_id` int DEFAULT NULL,
                `amount` decimal(12,2) NOT NULL,
                `user_email` varchar(190) DEFAULT NULL,
                `network` varchar(50) DEFAULT NULL,
                `wallet_name` varchar(190) DEFAULT NULL,
                `wallet_number` varchar(100) DEFAULT NULL,
                `payment_reference` varchar(100) DEFAULT NULL,
                `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
                `admin_notes` text,
                `processed_by` int DEFAULT NULL,
                `processed_at` timestamp NULL DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `sms_notification_sent` tinyint(1) DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_request_id` (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $db->query("CREATE TABLE IF NOT EXISTS `topup_request_notifications` (
                `id` int NOT NULL AUTO_INCREMENT,
                `request_id` varchar(32) NOT NULL,
                `notification_type` enum('email','sms') NOT NULL,
                `recipient_email` varchar(190) DEFAULT NULL,
                `recipient_phone` varchar(30) DEFAULT NULL,
                `status` enum('pending','sent','failed') DEFAULT 'pending',
                `error_message` text,
                `sms_sent` tinyint(1) DEFAULT '0',
                `sms_message_id` varchar(100) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensureOrderIssueTables')) {
    function ensureOrderIssueTables() {
        global $db;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `order_issues` (
                `id` int NOT NULL AUTO_INCREMENT,
                `order_id` int NOT NULL,
                `user_id` int NOT NULL,
                `issue_description` text,
                `status` varchar(20) DEFAULT 'open',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}
    }
}

if (!function_exists('ensurePaymentGatewaySchema')) {
    function ensurePaymentGatewaySchema() { return true; }
}

if (!function_exists('ensureAccountTypeSchema')) {
    function ensureAccountTypeSchema() { return true; }
}

if (!function_exists('isMtnNumber')) {
    /**
     * Checks if a phone number is an MTN Ghana number.
     */
    function isMtnNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) $phone = substr($phone, -10);
        return preg_match('/^(024|025|053|054|055|059)/', $phone);
    }
}

if (!function_exists('isAtNumber')) {
    /**
     * Checks if a phone number is an AT (AirtelTigo) Ghana number.
     */
    function isAtNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) $phone = substr($phone, -10);
        return preg_match('/^(026|027|056|057)/', $phone);
    }
}

if (!function_exists('isTelecelNumber')) {
    /**
     * Checks if a phone number is a Telecel (Vodafone) Ghana number.
     */
    function isTelecelNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10) $phone = substr($phone, -10);
        return preg_match('/^(020|050)/', $phone);
    }
}

if (!function_exists('initializePaystackCheckout')) {
    function initializePaystackCheckout($secret_key, array $payload) {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status_code' => 500, 'message' => 'cURL is not enabled on this server.'];
        }

        // Ensure amount is an integer
        if (isset($payload['amount'])) {
            $payload['amount'] = (int) round($payload['amount']);
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . trim((string) $secret_key),
                "Content-Type: application/json",
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($err) {
            error_log('Paystack CURL Error: ' . $err);
            return ['ok' => false, 'status_code' => 500, 'message' => 'Failed to connect to Paystack: ' . $err];
        }

        error_log('Paystack Response Code: ' . $http_code);
        error_log('Paystack Response Body: ' . $response);

        $result = json_decode($response, true);
        if ($result && !empty($result['status'])) {
            return [
                'ok' => true,
                'status_code' => $http_code,
                'authorization_url' => $result['data']['authorization_url'] ?? '',
                'access_code' => $result['data']['access_code'] ?? '',
                'reference' => $result['data']['reference'] ?? ($payload['reference'] ?? '')
            ];
        }

        return [
            'ok' => false,
            'status_code' => $http_code > 0 ? $http_code : 500,
            'message' => $result['message'] ?? 'Paystack checkout failed. Please try again.'
        ];
    }
}


if (!function_exists('ensureGuestCheckoutSchema')) {
    /**
     * Ensures that the transactions and bundle_orders tables allow NULL for user_id to support guest checkouts.
     */
    function ensureGuestCheckoutSchema() {
        global $db;
        try {
            // Check transactions table
            $res = $db->query("SHOW COLUMNS FROM transactions LIKE 'user_id'");
            if ($res && $row = $res->fetch_assoc()) {
                if ($row['Null'] === 'NO') {
                    $db->query("ALTER TABLE transactions MODIFY COLUMN user_id INT(11) NULL");
                }
            }
            
            // Check bundle_orders table
            $res = $db->query("SHOW COLUMNS FROM bundle_orders LIKE 'user_id'");
            if ($res && $row = $res->fetch_assoc()) {
                if ($row['Null'] === 'NO') {
                    $db->query("ALTER TABLE bundle_orders MODIFY COLUMN user_id INT(11) NULL");
                }
            }
        } catch (Exception $e) {
            error_log('ensureGuestCheckoutSchema failed: ' . $e->getMessage());
        }
        return true;
    }
}


if (!function_exists('buildPaymentFinalizationDebugMessage')) {
    /**
     * Builds a structured debug message for payment finalization failures.
     */
    function buildPaymentFinalizationDebugMessage($reference, $stage, $error) {
        $msg = "Reference: " . (string) $reference;
        if (!empty($stage)) {
            $msg .= " (Stage: " . (string) $stage . ")";
        }
        $msg .= ". Error: " . (string) $error;
        return $msg;
    }
}

if (!function_exists('detectGhanaNetworkLabel')) {
    /**
     * Attempts to detect a Ghana network label from a name or list of phone number candidates.
     */
    function detectGhanaNetworkLabel($networkName = '', ...$phoneCandidates) {
        $networkName = trim((string) $networkName);
        if ($networkName !== '') {
            return $networkName;
        }

        foreach ($phoneCandidates as $phoneCandidate) {
            $phoneCandidate = trim((string) $phoneCandidate);
            if ($phoneCandidate === '') {
                continue;
            }

            if (function_exists('isMtnNumber') && isMtnNumber($phoneCandidate)) {
                return 'MTN';
            }

            if (function_exists('isAtNumber') && isAtNumber($phoneCandidate)) {
                return 'AT';
            }

            if (function_exists('isTelecelNumber') && isTelecelNumber($phoneCandidate)) {
                return 'Telecel';
            }
        }

        return 'N/A';
    }
}

if (!function_exists('findRecentDuplicateBundleOrder')) {
    function findRecentDuplicateBundleOrder($user_id, $package_id, $phone, $amount) {
        global $db;
        $stmt = $db->prepare("
            SELECT id, order_reference, status
            FROM bundle_orders
            WHERE user_id = ? 
              AND package_id = ? 
              AND beneficiary_number = ? 
              AND amount = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
            ORDER BY id DESC LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('iisd', $user_id, $package_id, $phone, $amount);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('buildBundleSuccessMessage')) {
    /**
     * Builds a success message for a bundle purchase.
     */
    function buildBundleSuccessMessage($data_size, $phone) {
        return "Your order has been received successfully";
    }
}

if (!function_exists('sendResultCheckerSms')) {
    function sendResultCheckerSms($phone, $card_type, $pin, $serial_number, $checker_link, $user_id = null) {
        $message = "Your {$card_type} Result Checker: Serial: {$serial_number}, PIN: {$pin}. Check results here: {$checker_link}";
        if (function_exists('sendSMS')) {
            return sendSMS($phone, $message, 'result_checker', $user_id);
        }
        return false;
    }
}

if (!function_exists('sendResultCheckerEmail')) {
    function sendResultCheckerEmail($to_email, $card_type, $pin, $serial_number, $checker_link, $buyer_name = '') {
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/email.php';
        }
        if (!function_exists('sendEmail')) {
            return false;
        }

        $variables = [
            'customer_name' => $buyer_name,
            'card_type' => $card_type,
            'serial_number' => $serial_number,
            'pin_code' => $pin,
            'checker_link' => $checker_link,
            'current_year' => date('Y'),
            'site_name' => defined('SITE_NAME') ? SITE_NAME : 'Constechzhub'
        ];

        // Try to send via template
        if (function_exists('getEmailTemplate') && getEmailTemplate('result_checker_delivery')) {
            return sendTemplatedEmail($to_email, 'result_checker_delivery', $variables);
        }

        // Fallback: send hardcoded email
        $subject = "Result Checker Card Purchase - " . ($variables['site_name']);
        $body_html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Result Checker Card Purchase</title><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333}.container{max-width:600px;margin:0 auto;padding:20px}.header{background:#17a2b8;color:white;padding:20px;text-align:center}.content{padding:20px;background:#f8f9fa}.card-details{background:white;padding:15px;border-radius:5px;margin:15px 0;border-left:5px solid #17a2b8}.footer{padding:20px;text-align:center;font-size:12px;color:#666}</style></head><body><div class='container'><div class='header'><h1>Result Checker Purchased!</h1></div><div class='content'><p>Hello " . htmlspecialchars($buyer_name) . ",</p><p>Thank you for your purchase. Here is your result checker card details:</p><div class='card-details'><h3>Card Information</h3><p><strong>Exam Type:</strong> " . htmlspecialchars($card_type) . "</p><p><strong>Serial Number:</strong> " . htmlspecialchars($serial_number) . "</p><p><strong>PIN Code:</strong> " . htmlspecialchars($pin) . "</p><p><strong>Link:</strong> <a href='" . htmlspecialchars($checker_link) . "'>" . htmlspecialchars($checker_link) . "</a></p></div><p>You can use the link above to check your results. Thank you for choosing " . htmlspecialchars($variables['site_name']) . "!</p></div><div class='footer'><p>&copy; " . date('Y') . " " . htmlspecialchars($variables['site_name']) . ". All rights reserved.</p></div></div></body></html>";

        return sendEmail($to_email, $subject, $body_html);
    }
}

if (!function_exists('ensureAgentStoresCustomizationColumns')) {
    /**
     * Ensures that customization columns exist in the agent_stores table.
     */
    function ensureAgentStoresCustomizationColumns() {
        global $db;
        try {
            if (!dbh_table_has_column('agent_stores', 'primary_color')) {
                $db->query("ALTER TABLE `agent_stores` ADD COLUMN `primary_color` VARCHAR(7) DEFAULT NULL AFTER `store_logo`;");
            }
            if (!dbh_table_has_column('agent_stores', 'welcome_text')) {
                $db->query("ALTER TABLE `agent_stores` ADD COLUMN `welcome_text` TEXT DEFAULT NULL AFTER `primary_color`;");
            }
            if (!dbh_table_has_column('agent_stores', 'banner_image')) {
                $db->query("ALTER TABLE `agent_stores` ADD COLUMN `banner_image` VARCHAR(255) DEFAULT NULL AFTER `welcome_text`;");
            }
        } catch (Exception $e) {
            error_log('ensureAgentStoresCustomizationColumns failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notifyAfaRegistrationSubmitted')) {
    /**
     * Sends an email notification to the customer when AFA registration is successfully submitted/paid.
     */
    function notifyAfaRegistrationSubmitted($reference) {
        global $db;
        try {
            $stmt = $db->prepare("SELECT * FROM afa_registrations WHERE reference = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param("s", $reference);
            $stmt->execute();
            $registration = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$registration || empty($registration['email'])) {
                return false;
            }
            
            $to_email = $registration['email'];
            $name = $registration['beneficiary_name'] ?? 'Customer';
            $phone = $registration['phone'] ?? '';
            $amount = $registration['amount'] ?? 0.00;
            
            $subject = "AFA Registration Submitted Successfully - " . $reference;
            
            $site_name = defined('SITE_NAME') ? SITE_NAME : 'Constechzhub';
            $body_html = "
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                    <h2 style='color: #4f46e5;'>AFA Registration Submitted</h2>
                    <p>Dear " . htmlspecialchars($name) . ",</p>
                    <p>Thank you for submitting your AFA registration on " . htmlspecialchars($site_name) . ".</p>
                    <p>Your payment of <strong>GH₵ " . number_format($amount, 2) . "</strong> has been verified successfully, and your registration is now under review.</p>
                    
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <h4 style='margin-top: 0;'>Registration Details:</h4>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr><td style='padding: 5px 0;'><strong>Reference:</strong></td><td>" . htmlspecialchars($reference) . "</td></tr>
                            <tr><td style='padding: 5px 0;'><strong>Beneficiary:</strong></td><td>" . htmlspecialchars($name) . "</td></tr>
                            <tr><td style='padding: 5px 0;'><strong>Phone:</strong></td><td>" . htmlspecialchars($phone) . "</td></tr>
                            <tr><td style='padding: 5px 0;'><strong>Status:</strong></td><td>Ongoing (Processing)</td></tr>
                        </table>
                    </div>
                    
                    <p>We will process your registration shortly and notify you once it is completed.</p>
                    <p>Best regards,<br>The " . htmlspecialchars($site_name) . " Team</p>
                </div>
            ";
            
            require_once __DIR__ . '/email.php';
            if (function_exists('sendEmail')) {
                return sendEmail($to_email, $subject, $body_html);
            }
        } catch (Exception $e) {
            error_log("notifyAfaRegistrationSubmitted error: " . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('notifyAfaRegistrationStatusChange')) {
    /**
     * Sends an email notification to the customer when their AFA registration status changes (e.g. Completed or Failed).
     */
    function notifyAfaRegistrationStatusChange($reference, $new_status, $admin_notes, $send_to_user = true) {
        if (!$send_to_user) return false;
        global $db;
        try {
            $stmt = $db->prepare("SELECT * FROM afa_registrations WHERE reference = ? LIMIT 1");
            if (!$stmt) return false;
            $stmt->bind_param("s", $reference);
            $stmt->execute();
            $registration = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$registration || empty($registration['email'])) {
                return false;
            }
            
            $to_email = $registration['email'];
            $name = $registration['beneficiary_name'] ?? 'Customer';
            $site_name = defined('SITE_NAME') ? SITE_NAME : 'Constechzhub';
            
            $subject = "AFA Registration Status Update - " . $reference;
            
            $status_labels = [
                'success' => 'Completed Successfully',
                'failed' => 'Failed',
                'refunded' => 'Refunded',
                'processing' => 'Ongoing (Processing)',
                'pending' => 'Pending'
            ];
            $display_status = $status_labels[strtolower($new_status)] ?? ucfirst($new_status);
            
            if (strtolower($new_status) === 'success') {
                $status_header = "<h2 style='color: #16a34a;'>AFA Registration Completed!</h2>";
                $status_msg = "<p>Great news! Your AFA registration has been processed and completed successfully.</p>";
            } elseif (strtolower($new_status) === 'failed') {
                $status_header = "<h2 style='color: #dc2626;'>AFA Registration Failed</h2>";
                $status_msg = "<p>We regret to inform you that your AFA registration request has failed during processing.</p>";
            } else {
                $status_header = "<h2 style='color: #4f46e5;'>AFA Registration Updated</h2>";
                $status_msg = "<p>Your AFA registration status has been updated to: <strong>" . htmlspecialchars($display_status) . "</strong>.</p>";
            }
            
            $notes_section = "";
            if (!empty($admin_notes)) {
                $notes_section = "
                    <div style='background: #fef3c7; border-left: 4px solid #d97706; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #b45309;'>Remarks / Explanations:</h4>
                        <p style='margin-bottom: 0;'>" . nl2br(htmlspecialchars($admin_notes)) . "</p>
                    </div>
                ";
            }
            
            $body_html = "
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                    " . $status_header . "
                    <p>Dear " . htmlspecialchars($name) . ",</p>
                    " . $status_msg . "
                    
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <h4 style='margin-top: 0;'>Registration Summary:</h4>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr><td style='padding: 5px 0;'><strong>Reference:</strong></td><td>" . htmlspecialchars($reference) . "</td></tr>
                            <tr><td style='padding: 5px 0;'><strong>Beneficiary:</strong></td><td>" . htmlspecialchars($name) . "</td></tr>
                            <tr><td style='padding: 5px 0;'><strong>Current Status:</strong></td><td><strong>" . htmlspecialchars($display_status) . "</strong></td></tr>
                        </table>
                    </div>
                    
                    " . $notes_section . "
                    
                    <p>If you have any questions or require further assistance, please reply to this email or contact our support team.</p>
                    <p>Best regards,<br>The " . htmlspecialchars($site_name) . " Team</p>
                </div>
            ";
            
            require_once __DIR__ . '/email.php';
            if (function_exists('sendEmail')) {
                return sendEmail($to_email, $subject, $body_html);
            }
        } catch (Exception $e) {
            error_log("notifyAfaRegistrationStatusChange error: " . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('dbh_parse_data_size_to_mb')) {
    /**
     * Parse package data size string (e.g. '1.5GB', '500MB') into a numeric float representation in MB.
     */
    function dbh_parse_data_size_to_mb($sizeStr) {
        $sizeStr = strtolower(str_replace(' ', '', (string)$sizeStr));
        preg_match('/([0-9.]+)\s*([a-z]*)/i', $sizeStr, $matches);
        if (empty($matches)) {
            return 0.0;
        }
        $val = (float)$matches[1];
        $unit = $matches[2] ?? '';
        if (strpos($unit, 't') !== false) {
            return $val * 1024.0 * 1024.0;
        }
        if (strpos($unit, 'g') !== false) {
            return $val * 1024.0;
        }
        if (strpos($unit, 'm') !== false) {
            return $val;
        }
        if (strpos($unit, 'k') !== false) {
            return $val / 1024.0;
        }
        return $val;
    }
}

if (!function_exists('dbh_compare_packages')) {
    /**
     * Compare function to sort package arrays first by network, then by package type (if exists), then by numeric data size.
     */
    function dbh_compare_packages($a, $b) {
        // 1. Compare networks
        $netA = $a['network'] ?? ($a['network_name'] ?? '');
        $netB = $b['network'] ?? ($b['network_name'] ?? '');
        $netCompare = strcasecmp((string)$netA, (string)$netB);
        if ($netCompare !== 0) {
            return $netCompare;
        }
        
        // 2. Compare package types
        $typeA = $a['package_type'] ?? '';
        $typeB = $b['package_type'] ?? '';
        $typeCompare = strcasecmp((string)$typeA, (string)$typeB);
        if ($typeCompare !== 0) {
            return $typeCompare;
        }
        
        // 3. Compare data sizes
        $sizeA = dbh_parse_data_size_to_mb($a['data_size'] ?? '');
        $sizeB = dbh_parse_data_size_to_mb($b['data_size'] ?? '');
        if ($sizeA == $sizeB) {
            return 0;
        }
        return ($sizeA < $sizeB) ? -1 : 1;
    }
}


