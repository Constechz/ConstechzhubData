<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');
$current_user = getCurrentUser();
$flash = getFlashMessage();

// Load current values
$paystack_public = PAYSTACK_PUBLIC_KEY;
$paystack_secret = PAYSTACK_SECRET_KEY;
$moolre_api_user = getSetting('moolre_api_user', defined('MOOLRE_API_USER') ? MOOLRE_API_USER : '');
$moolre_api_key = getSetting('moolre_api_key', defined('MOOLRE_API_KEY') ? MOOLRE_API_KEY : '');
$moolre_api_pubkey = getSetting('moolre_api_pubkey', defined('MOOLRE_API_PUBKEY') ? MOOLRE_API_PUBKEY : '');
$moolre_api_vaskey = getSetting('moolre_api_vaskey', defined('MOOLRE_API_VASKEY') ? MOOLRE_API_VASKEY : '');
$moolre_account_number = getSetting('moolre_account_number', defined('MOOLRE_ACCOUNT_NUMBER') ? MOOLRE_ACCOUNT_NUMBER : '');
$moolre_webhook_secret = getSetting('moolre_webhook_secret', defined('MOOLRE_WEBHOOK_SECRET') ? MOOLRE_WEBHOOK_SECRET : '');
$active_gateway = getSetting('payment_gateway_active', defined('PAYMENT_GATEWAY_ACTIVE') ? PAYMENT_GATEWAY_ACTIVE : 'paystack');
$active_gateway = normalizePaymentGateway($active_gateway) ?: 'paystack';
$agent_fee = 50.00;

// Load current wallet top-up settings
$min_topup_admin_customer = (float) getSetting('min_topup_admin_customer', 5.00);
$min_topup_admin_agent = (float) getSetting('min_topup_admin_agent', 5.00);
$max_topup_global = (float) getSetting('max_topup_global', 1000.00);
$order_report_delay_minutes = (int) getSetting('order_report_delay_minutes', 20);
$order_report_whatsapp = getSetting('order_report_whatsapp_number', '0249020304');
$whatsapp_channel_url = trim((string) getSetting('whatsapp_channel_url', ''));
$site_whatsapp_number = trim((string) getSetting('site_whatsapp_number', '0249020304'));
if ($site_whatsapp_number === '') {
    $site_whatsapp_number = '0249020304';
}
$profit_fee_schedule = function_exists('getProfitWithdrawalFeeSchedule') ? getProfitWithdrawalFeeSchedule() : [];
$profit_fee_schedule_text = function_exists('formatProfitWithdrawalFeeScheduleText')
    ? formatProfitWithdrawalFeeScheduleText($profit_fee_schedule)
    : '';
$mtn_order_initial_status = strtolower(getSetting('mtn_order_initial_status', 'delivered'));
if (!in_array($mtn_order_initial_status, ['pending', 'delivered'], true)) {
    $mtn_order_initial_status = 'delivered';
}
$mtn_auto_deliver_minutes = max(0, (int) getSetting('mtn_auto_deliver_minutes', 0));

$default_maintenance_message = 'Our storefront is undergoing maintenance. Please check back soon.';
$maintenance_message = trim((string) getSetting('maintenance_message', $default_maintenance_message));
if ($maintenance_message === '') {
    $maintenance_message = $default_maintenance_message;
}
$maintenance_mode = getSetting('maintenance_mode', '0') === '1';
$email_verification_enabled = getSetting('email_verification_enabled', '0') === '1';
$verification_method = strtolower(trim((string) getSetting('verification_method', 'sms')));
if (!in_array($verification_method, ['sms', 'email'], true)) {
    $verification_method = 'sms';
}

// Fetch agent registration fee
$stmt = $db->prepare("SELECT fee_amount FROM registration_fees WHERE user_type = 'agent' AND is_active = TRUE LIMIT 1");
if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $agent_fee = (float)$row['fee_amount'];
    }
}

function adminSaveSetting($key, $value, $description) {
    global $db;

    $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Failed to prepare settings lookup.');
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();

    if ($exists) {
        $stmt = $db->prepare("UPDATE settings SET setting_value = ?, description = ? WHERE setting_key = ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare settings update.');
        }
        $stmt->bind_param('sss', $value, $description, $key);
        $stmt->execute();
        return;
    }

    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Failed to prepare settings insert.');
    }
    $stmt->bind_param('sss', $key, $value, $description);
    $stmt->execute();
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf)) {
        setFlashMessage('error', 'Invalid CSRF token');
        header('Location: settings.php');
        exit();
    }

    // Determine which form was submitted
    $form_type = $_POST['form_type'] ?? '';
    
    if ($form_type === 'wallet_topup') {
        // Handle wallet topup settings
        $min_topup_admin_customer_in = isset($_POST['min_topup_admin_customer']) ? (float)$_POST['min_topup_admin_customer'] : $min_topup_admin_customer;
        $min_topup_admin_agent_in = isset($_POST['min_topup_admin_agent']) ? (float)$_POST['min_topup_admin_agent'] : $min_topup_admin_agent;
        $max_topup_global_in = isset($_POST['max_topup_global']) ? (float)$_POST['max_topup_global'] : $max_topup_global;
        
        // Validate wallet top-up constraints
        $errors = [];
        if ($min_topup_admin_customer_in <= 0) { $errors[] = 'Admin customer minimum must be greater than 0'; }
        if ($min_topup_admin_agent_in <= 0) { $errors[] = 'Admin agent minimum must be greater than 0'; }
        if ($max_topup_global_in <= 0) { $errors[] = 'Global maximum must be greater than 0'; }
        if ($max_topup_global_in < $min_topup_admin_customer_in || $max_topup_global_in < $min_topup_admin_agent_in) {
            $errors[] = 'Global maximum must be greater than or equal to both admin minimums';
        }
        
        if (!empty($errors)) {
            setFlashMessage('error', implode('\n', $errors));
            header('Location: settings.php');
            exit();
        }
        
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
        try {
            adminSaveSetting('min_topup_admin_customer', number_format($min_topup_admin_customer_in, 2, '.', ''), 'Minimum top-up amount for admin customers');
            adminSaveSetting('min_topup_admin_agent', number_format($min_topup_admin_agent_in, 2, '.', ''), 'Minimum top-up amount for admin agents');
            adminSaveSetting('max_topup_global', number_format($max_topup_global_in, 2, '.', ''), 'Global maximum top-up amount');
            
            $conn->commit();
            setFlashMessage('success', 'Wallet top-up settings saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Wallet topup settings save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save wallet top-up settings');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'profit_withdrawal_fee_schedule') {
        $schedule_input = trim($_POST['profit_withdrawal_fee_schedule'] ?? '');
        $parse_error = null;
        $schedule = function_exists('parseProfitWithdrawalFeeScheduleText')
            ? parseProfitWithdrawalFeeScheduleText($schedule_input, $parse_error)
            : null;

        if (!$schedule || $parse_error) {
            setFlashMessage('error', $parse_error ?: 'Invalid fee schedule.');
            header('Location: settings.php');
            exit();
        }

        $normalized = function_exists('formatProfitWithdrawalFeeScheduleText')
            ? formatProfitWithdrawalFeeScheduleText($schedule)
            : $schedule_input;

        try {
            adminSaveSetting('profit_withdrawal_fee_schedule', $normalized, 'Profit withdrawal processing fee schedule');
            setFlashMessage('success', 'Profit withdrawal fee schedule saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            error_log('Profit withdrawal fee schedule save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save profit withdrawal fee schedule');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'profit_withdrawal_fee_reset') {
        $default_schedule = function_exists('defaultProfitWithdrawalFeeSchedule')
            ? defaultProfitWithdrawalFeeSchedule()
            : [];
        $default_text = function_exists('formatProfitWithdrawalFeeScheduleText')
            ? formatProfitWithdrawalFeeScheduleText($default_schedule)
            : '';

        try {
            adminSaveSetting('profit_withdrawal_fee_schedule', $default_text, 'Profit withdrawal processing fee schedule');
            setFlashMessage('success', 'Profit withdrawal fee schedule reset to default');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            error_log('Profit withdrawal fee schedule reset error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to reset profit withdrawal fee schedule');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'order_escalation') {
        $delay_input = isset($_POST['order_report_delay_minutes']) ? (int) $_POST['order_report_delay_minutes'] : $order_report_delay_minutes;
        $whatsapp_input = trim($_POST['order_report_whatsapp_number'] ?? $order_report_whatsapp);

        $errors = [];
        if ($delay_input < 5) {
            $errors[] = 'Delay must be at least 5 minutes to give the network time to process.';
        }
        $digits = preg_replace('/\D+/', '', $whatsapp_input);
        if (strlen($digits) < 9) {
            $errors[] = 'Please enter a valid WhatsApp number.';
        }

        if (!empty($errors)) {
            setFlashMessage('error', implode('\n', $errors));
            header('Location: settings.php');
            exit();
        }

        $conn = $db->getConnection();
        $conn->begin_transaction();

        try {
            adminSaveSetting('order_report_delay_minutes', (string) $delay_input, 'Minutes to wait before allowing users to report undelivered orders');
            adminSaveSetting('order_report_whatsapp_number', $whatsapp_input, 'WhatsApp number to escalate order issues to');

            $conn->commit();
            setFlashMessage('success', 'Order escalation settings saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Order escalation settings save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save order escalation settings');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'email_verification') {
        $enabled_input = (string) ($_POST['email_verification_enabled'] ?? '0');
        $enabled_input = $enabled_input === '1' ? '1' : '0';
        $method_input = strtolower(trim((string) ($_POST['verification_method'] ?? $verification_method)));
        if (!in_array($method_input, ['sms', 'email'], true)) {
            $method_input = 'sms';
        }
        try {
            adminSaveSetting('email_verification_enabled', $enabled_input, 'Require account verification on login');
            adminSaveSetting('verification_method', $method_input, 'Verification method for account access');
            if ($enabled_input === '0') {
                // Clear pending verification tokens when verification is disabled.
                try {
                    if (dbh_table_exists('email_verifications') && dbh_table_has_column('email_verifications', 'used_at')) {
                        $db->query("UPDATE email_verifications SET used_at = NOW() WHERE used_at IS NULL");
                    }
                    if (dbh_table_exists('otp_verifications')) {
                        $setParts = [];
                        if (dbh_table_has_column('otp_verifications', 'is_used')) {
                            $setParts[] = "is_used = 1";
                        }
                        if (dbh_table_has_column('otp_verifications', 'is_verified')) {
                            $setParts[] = "is_verified = 1";
                        }
                        if (dbh_table_has_column('otp_verifications', 'verified_at')) {
                            $setParts[] = "verified_at = NOW()";
                        }
                        if (!empty($setParts)) {
                            $where = '1=1';
                            if (dbh_table_has_column('otp_verifications', 'purpose')) {
                                $where = "purpose = 'phone_verification' AND (is_used = 0 OR is_used IS NULL)";
                            } elseif (dbh_table_has_column('otp_verifications', 'is_used')) {
                                $where = "is_used = 0 OR is_used IS NULL";
                            }
                            $db->query("UPDATE otp_verifications SET " . implode(', ', $setParts) . " WHERE {$where}");
                        } else {
                            $where = dbh_table_has_column('otp_verifications', 'purpose')
                                ? "WHERE purpose = 'phone_verification'"
                                : '';
                            $db->query("DELETE FROM otp_verifications {$where}");
                        }
                    }
                } catch (Exception $cleanupEx) {
                    error_log('Verification cleanup error: ' . $cleanupEx->getMessage());
                }
            }
            setFlashMessage('success', 'Verification settings saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            error_log('Email verification settings save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save verification settings');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'whatsapp_channel') {
        $site_whatsapp_input = trim($_POST['site_whatsapp_number'] ?? $site_whatsapp_number);
        $channel_input = trim($_POST['whatsapp_channel_url'] ?? $whatsapp_channel_url);
        $errors = [];
        $site_whatsapp_digits = preg_replace('/\D+/', '', $site_whatsapp_input);
        if ($site_whatsapp_digits === '' || strlen($site_whatsapp_digits) < 9) {
            $errors[] = 'Please provide a valid public WhatsApp number.';
        }
        if ($channel_input !== '' && !filter_var($channel_input, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please provide a valid WhatsApp channel link (including https://).';
        }

        if (!empty($errors)) {
            setFlashMessage('error', implode('\n', $errors));
            header('Location: settings.php');
            exit();
        }

        try {
            adminSaveSetting('site_whatsapp_number', $site_whatsapp_input, 'Primary public WhatsApp contact number shown on website');
            adminSaveSetting('whatsapp_channel_url', $channel_input, 'Public WhatsApp channel invite link shown on homepage');

            setFlashMessage('success', 'WhatsApp settings updated successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            error_log('WhatsApp channel save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save WhatsApp settings');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'mtn_status_policy') {
        $initial_status_input = strtolower(trim($_POST['mtn_order_initial_status'] ?? $mtn_order_initial_status));
        $auto_minutes_input = isset($_POST['mtn_auto_deliver_minutes']) ? (int) $_POST['mtn_auto_deliver_minutes'] : $mtn_auto_deliver_minutes;

        $valid_statuses = ['pending', 'delivered'];
        $errors = [];
        if (!in_array($initial_status_input, $valid_statuses, true)) {
            $errors[] = 'Invalid initial status selected.';
        }
        if ($auto_minutes_input < 0) {
            $errors[] = 'Auto delivery minutes cannot be negative.';
        }
        if ($initial_status_input === 'pending' && $auto_minutes_input === 0) {
            $errors[] = 'Please set a timeframe greater than 0 minutes when using pending as the initial status.';
        }

        if (!empty($errors)) {
            setFlashMessage('error', implode('\n', $errors));
            header('Location: settings.php');
            exit();
        }

        $conn = $db->getConnection();
        $conn->begin_transaction();

        try {
            adminSaveSetting('mtn_order_initial_status', $initial_status_input, 'Default MTN order status after successful delivery');
            adminSaveSetting('mtn_auto_deliver_minutes', (string) $auto_minutes_input, 'Minutes before MTN orders auto-change from pending to delivered');

            $conn->commit();
            setFlashMessage('success', 'MTN order status policy updated successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('MTN status policy save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save MTN order policy');
            header('Location: settings.php');
            exit();
        }
    } elseif ($form_type === 'maintenance_mode') {
        $mode_input = isset($_POST['maintenance_mode']) && $_POST['maintenance_mode'] === '1' ? '1' : '0';
        $message_input = trim((string) ($_POST['maintenance_message'] ?? ''));
        if ($message_input === '') {
            $message_input = $default_maintenance_message;
        }

        $conn = $db->getConnection();
        $conn->begin_transaction();

        try {
            adminSaveSetting('maintenance_mode', $mode_input, 'Maintenance mode (0=off, 1=on)');
            adminSaveSetting('maintenance_message', $message_input, 'Maintenance landing page notice');

            $conn->commit();
            setFlashMessage('success', 'Maintenance settings saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Maintenance settings save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save maintenance settings');
            header('Location: settings.php');
            exit();
        }
    } else {
        // Handle payment gateway and registration fee settings (default form)
        $paystack_public_in = trim($_POST['paystack_public_key'] ?? '');
        $paystack_secret_in = trim($_POST['paystack_secret_key'] ?? '');
        $moolre_api_user_in = trim($_POST['moolre_api_user'] ?? '');
        $moolre_api_key_in = trim($_POST['moolre_api_key'] ?? '');
        $moolre_api_pubkey_in = trim($_POST['moolre_api_pubkey'] ?? '');
        $moolre_api_vaskey_in = trim($_POST['moolre_api_vaskey'] ?? '');
        $moolre_account_number_in = trim($_POST['moolre_account_number'] ?? '');
        $moolre_webhook_secret_in = trim($_POST['moolre_webhook_secret'] ?? '');
        $active_gateway_in = normalizePaymentGateway($_POST['payment_gateway_active'] ?? $active_gateway);
        $agent_fee_in = (float)($_POST['agent_registration_fee'] ?? $agent_fee);
        
        // Basic validation
        $errors = [];
        if ($paystack_public_in !== '' && strpos($paystack_public_in, 'pk_') !== 0) {
            $errors[] = 'Public key must start with pk_';
        }
        if ($paystack_secret_in !== '' && strpos($paystack_secret_in, 'sk_') !== 0) {
            $errors[] = 'Secret key must start with sk_';
        }
        if ($active_gateway_in === '') {
            $errors[] = 'Invalid payment gateway selection';
        }
        if ($agent_fee_in < 0) {
            $errors[] = 'Registration fee cannot be negative';
        }
        
        if (!empty($errors)) {
            setFlashMessage('error', implode('\n', $errors));
            header('Location: settings.php');
            exit();
        }
        
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
        try {
            adminSaveSetting('payment_gateway_active', $active_gateway_in, 'Active payment gateway');
            if ($paystack_public_in !== '') {
                adminSaveSetting('paystack_public_key', $paystack_public_in, 'Paystack public key');
            }
            if ($paystack_secret_in !== '') {
                adminSaveSetting('paystack_secret_key', $paystack_secret_in, 'Paystack secret key');
            }
            if ($moolre_api_user_in !== '') {
                adminSaveSetting('moolre_api_user', $moolre_api_user_in, 'Moolre API user');
            }
            if ($moolre_api_key_in !== '') {
                adminSaveSetting('moolre_api_key', $moolre_api_key_in, 'Moolre API key');
            }
            if ($moolre_api_pubkey_in !== '') {
                adminSaveSetting('moolre_api_pubkey', $moolre_api_pubkey_in, 'Moolre public API key');
            }
            if ($moolre_api_vaskey_in !== '') {
                adminSaveSetting('moolre_api_vaskey', $moolre_api_vaskey_in, 'Moolre VAS key');
            }
            if ($moolre_account_number_in !== '') {
                adminSaveSetting('moolre_account_number', $moolre_account_number_in, 'Moolre account number');
            }
            if ($moolre_webhook_secret_in !== '') {
                adminSaveSetting('moolre_webhook_secret', $moolre_webhook_secret_in, 'Moolre webhook secret');
            }
            
            // Upsert agent registration fee
            $stmtChk = $db->prepare("SELECT id FROM registration_fees WHERE user_type = 'agent' LIMIT 1");
            $stmtChk->execute();
            $exists = $stmtChk->get_result()->fetch_assoc();
            if ($exists) {
                $stmtFee = $db->prepare("UPDATE registration_fees SET fee_amount = ?, is_active = TRUE WHERE user_type = 'agent'");
                $stmtFee->bind_param('d', $agent_fee_in);
                $stmtFee->execute();
            } else {
                $stmtFee = $db->prepare("INSERT INTO registration_fees (user_type, fee_amount, is_active) VALUES ('agent', ?, TRUE)");
                $stmtFee->bind_param('d', $agent_fee_in);
                $stmtFee->execute();
            }
            
            $conn->commit();
            setFlashMessage('success', 'Payment gateway settings saved successfully');
            header('Location: settings.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Payment gateway settings save error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to save payment gateway settings');
            header('Location: settings.php');
            exit();
        }
    }

}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notification Settings</a></div>
                <div class="nav-item"><a href="settings.php" class="nav-link active"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
                <div class="nav-item"><a href="topup-settings.php" class="nav-link"><i class="fas fa-university"></i> Topup Settings</a></div>
                <div class="nav-item"><a href="topup-requests.php" class="nav-link"><i class="fas fa-file-invoice"></i> Topup Requests</a></div>
                <div class="nav-item"><a href="sms-settings.php" class="nav-link"><i class="fas fa-sms"></i> SMS Settings</a></div>
                <div class="nav-item"><a href="seo-settings.php" class="nav-link"><i class="fas fa-globe"></i> SEO Settings</a></div>
                <div class="nav-item"><a href="smtp-settings.php" class="nav-link"><i class="fas fa-envelope"></i> SMTP Email Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="api-providers.php" class="nav-link"><i class="fas fa-plug"></i> API Providers</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-cog"></i></div>
                    <div class="breadcrumb-item">Settings</div>
                    <div class="breadcrumb-item active">System Settings</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar"><?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?></div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>System Settings</h1>
                <p class="page-subtitle">Configure global payment and registration options.</p>
            </div>

            <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                <?php echo nl2br(htmlspecialchars($flash['message'])); ?>
            </div>
            <?php endif; ?>

            <div class="dashboard-grid">
                <!-- Payment Gateway -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Payment Gateway Settings</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <div class="form-group">
                                <label for="payment_gateway_active">Active Payment Gateway</label>
                                <select id="payment_gateway_active" name="payment_gateway_active" class="form-control">
                                    <option value="paystack" <?php echo $active_gateway === 'paystack' ? 'selected' : ''; ?>>Paystack</option>
                                    <option value="moolre" <?php echo $active_gateway === 'moolre' ? 'selected' : ''; ?>>Moolre</option>
                                </select>
                                <small class="form-text text-muted">Only one online gateway is active at a time.</small>
                            </div>
                            <div class="form-group">
                                <label for="paystack_public_key">Public Key</label>
                                <input type="text" id="paystack_public_key" name="paystack_public_key" class="form-control" value="<?php echo htmlspecialchars($paystack_public); ?>" placeholder="pk_live_xxx or pk_test_xxx">
                                <small class="form-text text-muted">Starts with pk_</small>
                            </div>
                            <div class="form-group">
                                <label for="paystack_secret_key">Secret Key</label>
                                <div class="password-input-wrapper">
                                    <input type="password" id="paystack_secret_key" name="paystack_secret_key" class="form-control" value="<?php echo htmlspecialchars($paystack_secret); ?>" placeholder="sk_live_xxx or sk_test_xxx">
                                    <button type="button" class="password-toggle" data-target="paystack_secret_key" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-text text-muted">Starts with sk_</small>
                            </div>
                            <hr style="margin: 1rem 0;">
                            <div class="form-group">
                                <label for="moolre_api_user">Moolre API User (X-API-USER)</label>
                                <input type="text" id="moolre_api_user" name="moolre_api_user" class="form-control" value="<?php echo htmlspecialchars($moolre_api_user); ?>" placeholder="your_moolre_username">
                            </div>
                            <div class="form-group">
                                <label for="moolre_api_pubkey">Moolre Public Key (X-API-PUBKEY)</label>
                                <input type="text" id="moolre_api_pubkey" name="moolre_api_pubkey" class="form-control" value="<?php echo htmlspecialchars($moolre_api_pubkey); ?>" placeholder="public_key_here">
                            </div>
                            <div class="form-group">
                                <label for="moolre_api_key">Moolre API Key (X-API-KEY)</label>
                                <div class="password-input-wrapper">
                                    <input type="password" id="moolre_api_key" name="moolre_api_key" class="form-control" value="<?php echo htmlspecialchars($moolre_api_key); ?>" placeholder="api_key_here">
                                    <button type="button" class="password-toggle" data-target="moolre_api_key" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="moolre_api_vaskey">Moolre VAS Key (X-API-VASKEY)</label>
                                <div class="password-input-wrapper">
                                    <input type="password" id="moolre_api_vaskey" name="moolre_api_vaskey" class="form-control" value="<?php echo htmlspecialchars($moolre_api_vaskey); ?>" placeholder="vas_key_here">
                                    <button type="button" class="password-toggle" data-target="moolre_api_vaskey" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="moolre_account_number">Moolre Account Number</label>
                                <input type="text" id="moolre_account_number" name="moolre_account_number" class="form-control" value="<?php echo htmlspecialchars($moolre_account_number); ?>" placeholder="merchant_account_number">
                            </div>
                            <div class="form-group">
                                <label for="moolre_webhook_secret">Moolre Webhook Secret (Optional)</label>
                                <div class="password-input-wrapper">
                                    <input type="password" id="moolre_webhook_secret" name="moolre_webhook_secret" class="form-control" value="<?php echo htmlspecialchars($moolre_webhook_secret); ?>" placeholder="webhook_secret">
                                    <button type="button" class="password-toggle" data-target="moolre_webhook_secret" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="agent_registration_fee">Agent Registration Fee</label>
                                <input type="number" step="0.01" min="0" id="agent_registration_fee" name="agent_registration_fee" class="form-control" value="<?php echo htmlspecialchars(number_format($agent_fee, 2, '.', '')); ?>">
                                <small class="form-text text-muted">Amount in <?php echo CURRENCY_CODE; ?> required for new agent signup.</small>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                        </form>
                    </div>
                </div>

                <!-- Wallet Top-up Settings -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Wallet Top-up Settings</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="wallet_topup" />
                            <div class="form-group">
                                <label for="min_topup_admin_customer">Admin Customer Minimum</label>
                                <input type="number" step="0.01" min="0.01" id="min_topup_admin_customer" name="min_topup_admin_customer" class="form-control" value="<?php echo htmlspecialchars(number_format($min_topup_admin_customer, 2, '.', '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="min_topup_admin_agent">Admin Agent Minimum</label>
                                <input type="number" step="0.01" min="0.01" id="min_topup_admin_agent" name="min_topup_admin_agent" class="form-control" value="<?php echo htmlspecialchars(number_format($min_topup_admin_agent, 2, '.', '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="max_topup_global">Global Maximum</label>
                                <input type="number" step="0.01" min="0.01" id="max_topup_global" name="max_topup_global" class="form-control" value="<?php echo htmlspecialchars(number_format($max_topup_global, 2, '.', '')); ?>">
                                <small class="form-text text-muted">Must be greater than or equal to both minimums.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                        </form>
                    </div>
                </div>

                <!-- Profit Withdrawal Fee Schedule -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Profit Withdrawal Fee Schedule</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="profit_withdrawal_fee_schedule" />
                            <div class="form-group">
                                <label for="profit_withdrawal_fee_schedule">Fee Rules (one per line)</label>
                                <textarea id="profit_withdrawal_fee_schedule" name="profit_withdrawal_fee_schedule" class="form-control" rows="6"><?php echo htmlspecialchars($profit_fee_schedule_text); ?></textarea>
                                <small class="form-text text-muted">
                                    Format examples: <code>&lt;50=1</code>, <code>50-99.99=1.50</code>, <code>100-199.99=4</code>, <code>200-299.99=8</code>, <code>300-399.99=12</code>, <code>400+=16</code>.
                                    Use <code>+</code> for open-ended ranges. MoMo payout = Amount - Fee.
                                </small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Fee Schedule</button>
                        </form>
                        <form method="post" style="margin-top: 0.5rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="profit_withdrawal_fee_reset" />
                            <button type="submit" class="btn btn-outline-secondary" onclick="return confirm('Reset the fee schedule to the default rules?');">
                                <i class="fas fa-undo"></i> Reset to Default Schedule
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Order Escalation Settings -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Order Escalation Settings</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="order_escalation" />
                            <div class="form-group">
                                <label for="order_report_delay_minutes">Report Delay (minutes)</label>
                                <input type="number" min="5" step="1" id="order_report_delay_minutes" name="order_report_delay_minutes" class="form-control" value="<?php echo htmlspecialchars((int)$order_report_delay_minutes); ?>">
                                <small class="form-text text-muted">Users must wait this long after placing an order before the "Not Delivered" button becomes active.</small>
                            </div>
                            <div class="form-group">
                                <label for="order_report_whatsapp_number">WhatsApp Escalation Number</label>
                                <input type="text" id="order_report_whatsapp_number" name="order_report_whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($order_report_whatsapp); ?>" placeholder="e.g. 0249020304">
                                <small class="form-text text-muted">Agents and customers can forward order details to this WhatsApp number directly from their history pages.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Escalation Settings</button>
                        </form>
                    </div>
                </div>

                <!-- Account Verification Settings -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Account Verification</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="email_verification" />
                            <div class="form-group">
                                <label for="email_verification_enabled">Require Verification</label>
                                <select id="email_verification_enabled" name="email_verification_enabled" class="form-control">
                                    <option value="0" <?php echo $email_verification_enabled ? '' : 'selected'; ?>>Disabled</option>
                                    <option value="1" <?php echo $email_verification_enabled ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                                <small class="form-text text-muted">When enabled, users must verify their account after login before accessing their dashboards.</small>
                            </div>
                            <div class="form-group">
                                <label for="verification_method">Verification Method</label>
                                <select id="verification_method" name="verification_method" class="form-control">
                                    <option value="sms" <?php echo $verification_method === 'sms' ? 'selected' : ''; ?>>SMS (OTP)</option>
                                    <option value="email" <?php echo $verification_method === 'email' ? 'selected' : ''; ?>>Email (Link)</option>
                                </select>
                                <small class="form-text text-muted">Choose the channel users will use to verify their account.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Verification Settings</button>
                        </form>
                    </div>
                </div>

                <!-- WhatsApp Channel Link -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">WhatsApp Contact & Channel</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="whatsapp_channel" />
                            <div class="form-group">
                                <label for="site_whatsapp_number">Public WhatsApp Number</label>
                                <input type="text" id="site_whatsapp_number" name="site_whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($site_whatsapp_number); ?>" placeholder="e.g. 0249020304">
                                <small class="form-text text-muted">This number appears on the public website WhatsApp buttons and contact callouts.</small>
                            </div>
                            <div class="form-group">
                                <label for="whatsapp_channel_url">Channel Invitation Link</label>
                                <input type="url" id="whatsapp_channel_url" name="whatsapp_channel_url" class="form-control" value="<?php echo htmlspecialchars($whatsapp_channel_url); ?>" placeholder="https://whatsapp.com/channel/XXXXXX">
                                <small class="form-text text-muted">Paste the full WhatsApp channel link you want highlighted on the homepage. Leave blank to hide the channel button.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save WhatsApp Settings</button>
                        </form>
                    </div>
                </div>

                <!-- MTN Order Status Policy -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">MTN Order Status Policy</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="mtn_status_policy" />
                            <div class="form-group">
                                <label for="mtn_order_initial_status">Initial Status</label>
                                <select id="mtn_order_initial_status" name="mtn_order_initial_status" class="form-control">
                                    <option value="pending" <?php echo $mtn_order_initial_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="delivered" <?php echo $mtn_order_initial_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                </select>
                                <small class="form-text text-muted">Choose how MTN orders should appear immediately after being processed.</small>
                            </div>
                            <div class="form-group">
                                <label for="mtn_auto_deliver_minutes">Auto-Deliver After (minutes)</label>
                                <input type="number" min="0" step="1" id="mtn_auto_deliver_minutes" name="mtn_auto_deliver_minutes" class="form-control" value="<?php echo htmlspecialchars((int)$mtn_auto_deliver_minutes); ?>">
                                <small class="form-text text-muted">
                                    When using Pending, orders will automatically move to Delivered after this many minutes.
                                    Set to 0 to disable automatic changes.
                                </small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save MTN Policy</button>
                        </form>
                    </div>
                </div>

                <!-- Maintenance Mode -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Maintenance Mode</h3></div>
                    <div class="widget-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" />
                            <input type="hidden" name="form_type" value="maintenance_mode" />
                            <div class="form-group">
                                <label for="maintenance_mode">Website Status</label>
                                <select id="maintenance_mode" name="maintenance_mode" class="form-control">
                                    <option value="0" <?php echo $maintenance_mode ? '' : 'selected'; ?>>Disabled</option>
                                    <option value="1" <?php echo $maintenance_mode ? 'selected' : ''; ?>>Enabled</option>
                                </select>
                                <small class="form-text text-muted">Non-admin visitors will see the maintenance notice when enabled.</small>
                            </div>
                            <div class="form-group">
                                <label for="maintenance_message">Visitor Notice</label>
                                <textarea id="maintenance_message" name="maintenance_message" class="form-control" rows="3"><?php echo htmlspecialchars($maintenance_message, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <small class="form-text text-muted">This text is shown on the maintenance landing page.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Maintenance Settings</button>
                            <p class="form-text text-muted" style="margin-top: 0.75rem;">
                                <a href="<?php echo htmlspecialchars(SITE_URL . '/maintenance.php'); ?>" target="_blank" rel="noopener">Preview maintenance page</a>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Help -->
                <div class="widget">
                    <div class="widget-header"><h3 class="widget-title">Notes</h3></div>
                    <div class="widget-body">
                        <div class="alert alert-info">
                            <p><strong>Registration Fee</strong> is used on the signup page and verified server-side in <code>register.php</code>.</p>
                            <p><strong>Paystack/Moolre Keys</strong> are loaded dynamically via <code>config/config.php</code> from the <code>settings</code> table.</p>
                            <p><strong>Order Escalation Controls</strong> configure the delay before the "Report Not Delivered" button becomes active and the WhatsApp number customers/agents can share orders with.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
<script>
// Mobile menu toggle
document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('show');
});

function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = savedTheme || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
    updateThemeIcon(theme);
}
function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}
function updateThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
function toggleUserDropdown() {
    document.getElementById('userDropdown').classList.toggle('show');
}

document.addEventListener('DOMContentLoaded', function(){ initTheme(); });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



