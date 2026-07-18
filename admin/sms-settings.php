<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';

// Require admin role
requireRole('admin');
$current_user = getCurrentUser();

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token';
    } else {
        if (isset($_POST['test_sms'])) {
            // Test SMS
            $testPhone = $_POST['test_phone'] ?? '';
            if (empty($testPhone)) {
                $error_message = 'Please enter a test phone number';
            } else {
                try {
                    $sms = new MnotifySmsService($_POST['mnotify_api_key'] ?? null, $_POST['mnotify_sender_id'] ?? null);
                    // Use Constechzhub as the test sender brand name per request
                    $result = $sms->sendSMS($testPhone, 'Test SMS from Constechzhub - ' . date('Y-m-d H:i:s'), 'general');
                    
                    if ($result['success']) {
                        $details = [];
                        if (!empty($result['status'])) {
                            $details[] = 'Status: ' . strtoupper($result['status']);
                        }
                        if (isset($result['cost'])) {
                            $details[] = 'Estimated cost: ' . ($result['cost'] ?? 'N/A');
                        }
                        if (!empty($result['provider_response']['raw_response'])) {
                            $details[] = 'Provider reply: ' . $result['provider_response']['raw_response'];
                        }
                        $success_message = 'Test SMS sent successfully! Message ID: ' . ($result['message_id'] ?? 'N/A');
                        if (!empty($details)) {
                            $success_message .= ' (' . implode(' | ', $details) . ')';
                        }
                    } else {
                        $debug = [];
                        if (!empty($result['http_code'])) {
                            $debug[] = 'HTTP ' . $result['http_code'];
                        }
                        if (!empty($result['raw_response'])) {
                            $debug[] = 'Provider reply: ' . $result['raw_response'];
                        }
                        $error_message = 'Test SMS failed: ' . $result['error'];
                        if (!empty($debug)) {
                            $error_message .= ' (' . implode(' | ', $debug) . ')';
                        }
                    }
                } catch (Exception $e) {
                    $error_message = 'SMS test error: ' . $e->getMessage();
                }
            }
        } else {
            // Update SMS settings
            try {
                $db->getConnection()->begin_transaction();

                $isEnabled = isset($_POST['mnotify_enabled']) ? '1' : '0';
                $apiKey = $_POST['mnotify_api_key'] ?? '';
                $senderId = $_POST['mnotify_sender_id'] ?? '';
                $notifyEnabled = isset($_POST['sms_notifications_enabled']) ? '1' : '0';
                $otpEnabled = isset($_POST['sms_otp_enabled']) ? '1' : '0';
                if ($isEnabled === '1') {
                    $notifyEnabled = '1';
                }

                if (smsSettingsUsesKeyValueSchema()) {
                    // Key/value schema
                    $settings = [
                        'mnotify_enabled' => $isEnabled,
                        'mnotify_api_key' => $apiKey,
                        'mnotify_sender_id' => $senderId,
                        // legacy keys kept in sync for backward compatibility
                        'kivalo_enabled' => $isEnabled,
                        'kivalo_api_key' => $apiKey,
                        'kivalo_sender_id' => $senderId,
                        'sms_notifications_enabled' => $notifyEnabled,
                        'sms_otp_enabled' => $otpEnabled
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $stmt = $db->prepare("INSERT INTO sms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
                        $stmt->bind_param('ss', $key, $value);
                        $stmt->execute();
                    }
                } else {
                    // Provider schema (provider/api_key/sender_id/is_active)
                    $conn = $db->getConnection();
                    $res = $conn->query("SELECT id FROM sms_settings LIMIT 1");
                    if ($res && $res->num_rows > 0) {
                        $row = $res->fetch_assoc();
                        $stmt = $conn->prepare("UPDATE sms_settings SET provider = ?, api_key = ?, sender_id = ?, is_active = ? WHERE id = ?");
                        $isActive = $isEnabled === '1' ? 1 : 0;
                        $provider = 'mnotify';
                        $stmt->bind_param('sssii', $provider, $apiKey, $senderId, $isActive, $row['id']);
                        $stmt->execute();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO sms_settings (provider, api_key, sender_id, is_active) VALUES (?, ?, ?, ?)");
                        $isActive = $isEnabled === '1' ? 1 : 0;
                        $provider = 'mnotify';
                        $stmt->bind_param('sssi', $provider, $apiKey, $senderId, $isActive);
                        $stmt->execute();
                    }
                    // No dedicated columns for notification/OTP flags in this schema; they will mirror is_active
                }
                
                $db->getConnection()->commit();
                $success_message = 'SMS settings updated successfully!';
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error_message = 'Error updating SMS settings: ' . $e->getMessage();
            }
        }
    }
}

// Get current SMS settings
$smsSettings = [];
try {
    if (smsSettingsUsesKeyValueSchema()) {
        $result = $db->query("SELECT setting_key, setting_value FROM sms_settings");
        while ($row = $result->fetch_assoc()) {
            $smsSettings[$row['setting_key']] = $row['setting_value'];
        }
    } else {
        $result = $db->query("SELECT provider, api_key, sender_id, is_active FROM sms_settings ORDER BY id DESC LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $smsSettings['mnotify_enabled'] = ($row['is_active'] ?? 0) ? '1' : '0';
            $smsSettings['mnotify_api_key'] = $row['api_key'] ?? '';
            $smsSettings['mnotify_sender_id'] = $row['sender_id'] ?? 'DataBundle';
            // mirror to legacy keys
            $smsSettings['kivalo_enabled'] = $smsSettings['mnotify_enabled'];
            $smsSettings['kivalo_api_key'] = $smsSettings['mnotify_api_key'];
            $smsSettings['kivalo_sender_id'] = $smsSettings['mnotify_sender_id'];
            $smsSettings['sms_notifications_enabled'] = $smsSettings['mnotify_enabled'];
            $smsSettings['sms_otp_enabled'] = $smsSettings['mnotify_enabled'];
        }
    }
} catch (Exception $e) {
    // Settings table might not exist yet
}

// Normalize keys to mNotify defaults with legacy fallbacks
$smsSettings['mnotify_enabled'] = $smsSettings['mnotify_enabled'] ?? ($smsSettings['kivalo_enabled'] ?? '0');
$smsSettings['mnotify_api_key'] = $smsSettings['mnotify_api_key'] ?? ($smsSettings['kivalo_api_key'] ?? '');
$smsSettings['mnotify_sender_id'] = $smsSettings['mnotify_sender_id'] ?? ($smsSettings['kivalo_sender_id'] ?? 'DataBundle');

// Get SMS balance if enabled
$balanceInfo = null;
if (($smsSettings['mnotify_enabled'] ?? '0') === '1' && !empty($smsSettings['mnotify_api_key'])) {
    try {
            $sms = new MnotifySmsService();
        $balanceResult = $sms->getBalance();
        if ($balanceResult['success']) {
            $balanceInfo = $balanceResult;
        }
    } catch (Exception $e) {
        // Ignore balance errors
    }
}

// Generate CSRF token
$csrf_token = generateCSRF();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Settings - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
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
                    <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                    <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
                    <div class="nav-item"><a href="topup-settings.php" class="nav-link"><i class="fas fa-university"></i> Topup Settings</a></div>
                    <div class="nav-item"><a href="sms-settings.php" class="nav-link active"><i class="fas fa-mobile-alt"></i> SMS Settings</a></div>
                    <div class="nav-item"><a href="smtp-settings.php" class="nav-link"><i class="fas fa-envelope"></i> SMTP Settings</a></div>
                    <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                </li>
                <li class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item"><a href="profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></div>
                    <div class="nav-item"><a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
                </li>
            </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <a href="dashboard.php">Dashboard</a>
                        <span class="breadcrumb-separator">/</span>
                        <a href="settings.php">Settings</a>
                        <span class="breadcrumb-separator">/</span>
                        <span class="breadcrumb-item active">SMS Settings</span>
                    </nav>
                </div>
                <div class="header-actions">
                    <button class="theme-toggle" type="button" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    <div class="user-dropdown">
                        <button class="user-avatar"><?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?></button>
                        <div class="dropdown-menu">
                            <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                            <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="dashboard-content">
                <div class="page-title">
                    <h1>SMS Settings</h1>
                    <p class="page-subtitle">Configure mNotify (bms.mnotify.com) SMS for notifications and OTP</p>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- SMS Balance Widget -->
                <?php if ($balanceInfo): ?>
                <div class="stats-grid" style="margin-bottom: 2rem;">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo htmlspecialchars($balanceInfo['balance']); ?></h3>
                            <p>SMS Credits Available</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-mobile-alt"></i>
                            mNotify SMS Configuration
                        </h3>
                        <p class="widget-subtitle">Configure your mNotify BMS SMS integration settings</p>
                    </div>
                    <div class="widget-body">
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            
                            <div class="form-section">
                                <h4><i class="fas fa-toggle-on"></i> General Settings</h4>
                                
                                <div class="form-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="mnotify_enabled" <?php echo (($smsSettings['mnotify_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                        <span class="toggle-label">Enable mNotify SMS Service</span>
                                    </label>
                                    <small class="form-help">Turn on to enable SMS functionality throughout the system</small>
                                </div>
                                
                                <div class="form-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_notifications_enabled" <?php echo (($smsSettings['sms_notifications_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                        <span class="toggle-label">SMS Notifications</span>
                                    </label>
                                    <small class="form-help">Send SMS notifications for important events</small>
                                </div>
                                
                                <div class="form-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="sms_otp_enabled" <?php echo (($smsSettings['sms_otp_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                        <span class="toggle-label">SMS OTP Verification</span>
                                    </label>
                                    <small class="form-help">Enable SMS-based OTP for user verification</small>
                                </div>
                            </div>

                            <div class="form-section">
                                <h4><i class="fas fa-key"></i> API Configuration</h4>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="mnotify_api_key">
                                            <i class="fas fa-key"></i>
                                            mNotify API Key *
                                        </label>
                                        <div class="password-input-wrapper">
                                            <input type="password" id="mnotify_api_key" name="mnotify_api_key" class="form-control" 
                                                   value="<?php echo htmlspecialchars($smsSettings['mnotify_api_key'] ?? ''); ?>" 
                                                   placeholder="Enter your mNotify API key" required>
                                            <button type="button" class="password-toggle" data-target="mnotify_api_key" aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <small class="form-help">Get your API key from <a href="https://bms.mnotify.com" target="_blank">bms.mnotify.com</a></small>
                                    </div>

                                    <div class="form-group">
                                        <label for="mnotify_sender_id">
                                            <i class="fas fa-signature"></i>
                                            Sender ID
                                        </label>
                                        <input type="text" id="mnotify_sender_id" name="mnotify_sender_id" class="form-control" 
                                               value="<?php echo htmlspecialchars($smsSettings['mnotify_sender_id'] ?? 'DataBundle'); ?>" 
                                               placeholder="DataBundle" maxlength="11">
                                        <small class="form-help">Max 11 characters, letters and numbers only</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h4><i class="fas fa-vial"></i> Test SMS</h4>
                                <div class="form-group">
                                    <label for="test_phone">
                                        <i class="fas fa-phone"></i>
                                        Test Phone Number
                                    </label>
                                    <input type="tel" id="test_phone" name="test_phone" class="form-control" 
                                           placeholder="e.g., 0245152060" 
                                           pattern="[0-9+\-\s\(\)]+" title="Please enter a valid phone number">
                                    <small class="form-help">Enter phone number to test SMS sending</small>
                                </div>
                                <button type="submit" name="test_sms" class="btn btn-secondary">
                                    <i class="fas fa-paper-plane"></i>
                                    Send Test SMS
                                </button>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Update SMS Settings
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='settings.php'">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- SMS Statistics -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-chart-bar"></i>
                            SMS Statistics
                        </h3>
                    </div>
                    <div class="widget-body">
                        <?php
                        // Get SMS statistics
                        $stats = [];
                        try {
                            $result = $db->query("SELECT 
                                COUNT(*) as total_sent,
                                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as successful,
                                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                                SUM(cost) as total_cost
                                FROM sms_notifications 
                                WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
                            $stats = $result->fetch_assoc();
                        } catch (Exception $e) {
                            // SMS table might not exist
                            $stats = ['total_sent' => 0, 'successful' => 0, 'failed' => 0, 'total_cost' => 0];
                        }
                        ?>
                        
                        <div class="stats-grid">
                            <div class="stat-item">
                                <h4><?php echo number_format($stats['total_sent'] ?? 0); ?></h4>
                                <p>Total SMS (30 days)</p>
                            </div>
                            <div class="stat-item">
                                <h4><?php echo number_format($stats['successful'] ?? 0); ?></h4>
                                <p>Successful</p>
                            </div>
                            <div class="stat-item">
                                <h4><?php echo number_format($stats['failed'] ?? 0); ?></h4>
                                <p>Failed</p>
                            </div>
                            <div class="stat-item">
                                <h4><?php echo number_format($stats['total_cost'] ?? 0, 2); ?></h4>
                                <p>Total Cost</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Setup Instructions -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-info-circle"></i>
                            Setup Instructions
                        </h3>
                    </div>
                    <div class="widget-body">
                        <div class="instructions">
                            <div class="instruction-step">
                                <span class="step-number">1</span>
                                <div class="step-content">
                                    <h5>Create mNotify Account</h5>
                                    <p>Visit <a href="https://bms.mnotify.com" target="_blank">bms.mnotify.com</a> and create an account if you don't have one.</p>
                                </div>
                            </div>
                            <div class="instruction-step">
                                <span class="step-number">2</span>
                                <div class="step-content">
                                    <h5>Get API Key</h5>
                                    <p>Open the mNotify BMS dashboard and copy your API key from the API section.</p>
                                </div>
                            </div>
                            <div class="instruction-step">
                                <span class="step-number">3</span>
                                <div class="step-content">
                                    <h5>Configure Settings</h5>
                                    <p>Enter your API key and sender ID in the form above, then enable the SMS service.</p>
                                </div>
                            </div>
                            <div class="instruction-step">
                                <span class="step-number">4</span>
                                <div class="step-content">
                                    <h5>Test SMS</h5>
                                    <p>Use the test SMS feature to verify your configuration is working correctly.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <style>
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .form-section h4 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 1rem;
            cursor: pointer;
        }

        .toggle-slider {
            position: relative;
            width: 50px;
            height: 24px;
            background-color: #F1E9DA;
            border-radius: 24px;
            transition: 0.3s;
        }

        .toggle-slider:before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: #F1E9DA;
            top: 2px;
            left: 2px;
            transition: 0.3s;
        }

        input[type="checkbox"]:checked + .toggle-slider {
            background-color: var(--primary-color);
        }

        input[type="checkbox"]:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        input[type="checkbox"] {
            display: none;
        }

        .toggle-label {
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group label i {
            color: var(--primary-color);
            width: 16px;
        }

        .form-help {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
        }

        .form-actions {
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--widget-bg, #F1E9DA);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .stat-item h4 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-item p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .instructions {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .instruction-step {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .step-number {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            color: #F1E9DA;
            border-radius: 50%;
            font-weight: bold;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .step-content h5 {
            margin: 0 0 0.5rem 0;
            color: var(--text-color);
            font-weight: 600;
        }

        .step-content p {
            margin: 0;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .step-content a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .step-content a:hover {
            text-decoration: underline;
        }

        /* Comprehensive Dark mode support */
        [data-theme="dark"] .stat-item {
            background: var(--widget-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .toggle-slider {
            background-color: #2E294E;
        }

        [data-theme="dark"] .form-section {
            border-bottom-color: var(--border-color, #2E294E);
        }

        [data-theme="dark"] .form-section h4 {
            color: var(--primary-color, #F1E9DA);
        }

        [data-theme="dark"] .toggle-label {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .form-help {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .form-control {
            background-color: var(--input-bg, #2E294E);
            border-color: var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .form-control:focus {
            border-color: var(--primary-color, #F1E9DA);
            box-shadow: 0 0 0 0.2rem rgba(241, 233, 218, 0.25);
        }

        [data-theme="dark"] .form-control::placeholder {
            color: var(--text-muted, #541388);
        }

        [data-theme="dark"] .widget {
            background: var(--widget-bg, #2E294E);
            border-color: var(--border-color, #2E294E);
        }

        [data-theme="dark"] .widget-header h3 {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .widget-subtitle {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .step-content h5 {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .step-content p {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .step-content a {
            color: var(--primary-color, #F1E9DA);
        }

        [data-theme="dark"] .alert {
            background: var(--widget-bg, #2E294E);
            border-color: var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .alert-success {
            background: rgba(46, 41, 78, 0.2);
            border-color: #2E294E;
            color: #F1E9DA;
        }

        [data-theme="dark"] .alert-danger {
            background: rgba(217, 3, 104, 0.2);
            border-color: #D90368;
            color: #F1E9DA;
        }

        [data-theme="dark"] .btn-primary {
            background-color: var(--primary-color, #F1E9DA);
            border-color: var(--primary-color, #F1E9DA);
            color: #F1E9DA;
        }

        [data-theme="dark"] .btn-primary:hover {
            background-color: var(--primary-dark, #541388);
            border-color: var(--primary-dark, #541388);
        }

        [data-theme="dark"] .btn-secondary {
            background-color: var(--secondary-color, #541388);
            border-color: var(--secondary-color, #541388);
            color: #F1E9DA;
        }

        [data-theme="dark"] .btn-secondary:hover {
            background-color: #2E294E;
            border-color: #2E294E;
        }

        [data-theme="dark"] .page-title h1 {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .page-subtitle {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .dashboard-content {
            background: var(--bg-color, #2E294E);
        }

        [data-theme="dark"] .stat-card {
            background: var(--widget-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
        }

        [data-theme="dark"] .stat-icon {
            color: var(--primary-color, #F1E9DA);
        }

        [data-theme="dark"] .stat-content h3 {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .stat-content p {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .breadcrumb a {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .breadcrumb .breadcrumb-item.active {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .breadcrumb-separator {
            color: var(--text-muted, #541388);
        }

        [data-theme="dark"] .form-group label {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .form-actions {
            border-top-color: var(--border-color, #2E294E);
        }
    </style>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme-fallback.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Form validation
            const form = document.querySelector('.settings-form');
            const apiKeyField = document.getElementById('mnotify_api_key');
            const enabledCheckbox = document.querySelector('input[name=\"mnotify_enabled\"]');
            
            // Require API key when SMS is enabled
            if (form && apiKeyField && enabledCheckbox) {
                form.addEventListener('submit', function(e) {
                    if (enabledCheckbox.checked && !apiKeyField.value.trim()) {
                        e.preventDefault();
                        alert('Please enter your mNotify API key to enable SMS service.');
                        apiKeyField.focus();
                    }
                });
                
                // Toggle API key requirement
                enabledCheckbox.addEventListener('change', function() {
                    apiKeyField.required = this.checked;
                });
            }

            // Mobile menu toggle
            const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileMenuToggle && sidebar) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
        });
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



