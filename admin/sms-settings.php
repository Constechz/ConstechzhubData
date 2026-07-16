<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';

if (!function_exists('adminSmsMaskPhone')) {
    function adminSmsMaskPhone($phone) {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return 'Unknown recipient';
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return $phone;
        }

        if (strlen($digits) <= 4) {
            return $digits;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }
}

requireRole('admin');
$current_user = getCurrentUser();

$success_message = '';
$error_message = '';
$wallet_topup_log_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token';
    } else {
        if (isset($_POST['delete_wallet_topup_sms'])) {
            $notificationId = (int) ($_POST['sms_notification_id'] ?? 0);
            if ($notificationId <= 0) {
                $error_message = 'Invalid wallet top-up notification selected.';
            } else {
                $stmt = $db->prepare("DELETE FROM sms_notifications WHERE id = ? AND purpose = 'wallet_topup' LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $notificationId);
                    $stmt->execute();
                    if ($stmt->affected_rows > 0) {
                        $success_message = 'Wallet top-up SMS notification deleted successfully.';
                    } else {
                        $error_message = 'Wallet top-up SMS notification not found or already deleted.';
                    }
                    $stmt->close();
                } else {
                    $error_message = 'Failed to prepare wallet top-up notification deletion.';
                }
            }
        } elseif (isset($_POST['delete_all_wallet_topup_sms'])) {
            $stmt = $db->prepare("DELETE FROM sms_notifications WHERE purpose = 'wallet_topup'");
            if ($stmt) {
                $stmt->execute();
                $deletedCount = (int) $stmt->affected_rows;
                $stmt->close();
                $success_message = $deletedCount > 0
                    ? 'Deleted ' . number_format($deletedCount) . ' wallet top-up SMS notification(s).'
                    : 'No wallet top-up SMS notifications were found to delete.';
            } else {
                $error_message = 'Failed to prepare bulk wallet top-up notification deletion.';
            }
        } elseif (isset($_POST['test_sms'])) {
            $testPhone = trim((string) ($_POST['test_phone'] ?? ''));
            if ($testPhone === '') {
                $error_message = 'Please enter a test phone number.';
            } else {
                try {
                    $sms = new MnotifySmsService($_POST['mnotify_api_key'] ?? null, $_POST['mnotify_sender_id'] ?? null);
                    $result = $sms->sendSMS($testPhone, 'Test SMS from Constechzhub - ' . date('Y-m-d H:i:s'), 'general');

                    if ($result['success']) {
                        $details = [];
                        if (!empty($result['status'])) {
                            $details[] = 'Status: ' . strtoupper((string) $result['status']);
                        }
                        if (isset($result['cost'])) {
                            $details[] = 'Estimated cost: ' . ($result['cost'] ?? 'N/A');
                        }
                        if (!empty($result['provider_response']['raw_response'])) {
                            $details[] = 'Provider reply: ' . $result['provider_response']['raw_response'];
                        }
                        $success_message = 'Test SMS sent successfully. Message ID: ' . ($result['message_id'] ?? 'N/A');
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
                        $error_message = 'Test SMS failed: ' . ($result['error'] ?? 'Unknown error');
                        if (!empty($debug)) {
                            $error_message .= ' (' . implode(' | ', $debug) . ')';
                        }
                    }
                } catch (Exception $e) {
                    $error_message = 'SMS test error: ' . $e->getMessage();
                }
            }
        } else {
            try {
                $db->getConnection()->begin_transaction();

                $isEnabled = isset($_POST['mnotify_enabled']) ? '1' : '0';
                $apiKey = trim((string) ($_POST['mnotify_api_key'] ?? ''));
                $senderId = trim((string) ($_POST['mnotify_sender_id'] ?? ''));
                $notifyEnabled = isset($_POST['sms_notifications_enabled']) ? '1' : '0';
                $otpEnabled = isset($_POST['sms_otp_enabled']) ? '1' : '0';
                $agentDeliveryEnabled = isset($_POST['agent_delivery_sms_enabled']) ? '1' : '0';
                $agentDeliveryTemplate = trim((string) ($_POST['agent_delivery_sms_template'] ?? ''));
                $agentStoreDeliveryEnabled = isset($_POST['agent_store_delivery_sms_enabled']) ? '1' : '0';
                $agentStoreDeliveryTemplate = trim((string) ($_POST['agent_store_delivery_sms_template'] ?? ''));
                if ($isEnabled === '1') {
                    $notifyEnabled = '1';
                }

                if (smsSettingsUsesKeyValueSchema()) {
                    $settings = [
                        'mnotify_enabled' => $isEnabled,
                        'mnotify_api_key' => $apiKey,
                        'mnotify_sender_id' => $senderId,
                        'kivalo_enabled' => $isEnabled,
                        'kivalo_api_key' => $apiKey,
                        'kivalo_sender_id' => $senderId,
                        'sms_notifications_enabled' => $notifyEnabled,
                        'sms_otp_enabled' => $otpEnabled,
                    ];

                    foreach ($settings as $key => $value) {
                        $stmt = $db->prepare("INSERT INTO sms_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
                        $stmt->bind_param('ss', $key, $value);
                        $stmt->execute();
                    }
                } else {
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
                }

                saveSetting('agent_delivery_sms_enabled', $agentDeliveryEnabled, 'Send SMS to agents when admin marks data orders as delivered');
                saveSetting('agent_delivery_sms_template', $agentDeliveryTemplate, 'Agent delivered-order SMS template');
                saveSetting('agent_store_delivery_sms_enabled', $agentStoreDeliveryEnabled, 'Send SMS to agents when an order via their store link is completed');
                saveSetting('agent_store_delivery_sms_template', $agentStoreDeliveryTemplate, 'Agent store-link order completed SMS template');

                $db->getConnection()->commit();
                $success_message = 'SMS settings updated successfully.';
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error_message = 'Error updating SMS settings: ' . $e->getMessage();
            }
        }
    }
}

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
            $smsSettings['kivalo_enabled'] = $smsSettings['mnotify_enabled'];
            $smsSettings['kivalo_api_key'] = $smsSettings['mnotify_api_key'];
            $smsSettings['kivalo_sender_id'] = $smsSettings['mnotify_sender_id'];
            $smsSettings['sms_notifications_enabled'] = $smsSettings['mnotify_enabled'];
            $smsSettings['sms_otp_enabled'] = $smsSettings['mnotify_enabled'];
        }
    }
} catch (Exception $e) {
    // Settings table might not exist yet.
}

$smsSettings['mnotify_enabled'] = $smsSettings['mnotify_enabled'] ?? ($smsSettings['kivalo_enabled'] ?? '0');
$smsSettings['mnotify_api_key'] = $smsSettings['mnotify_api_key'] ?? ($smsSettings['kivalo_api_key'] ?? '');
$smsSettings['mnotify_sender_id'] = $smsSettings['mnotify_sender_id'] ?? ($smsSettings['kivalo_sender_id'] ?? 'DataBundle');
$smsSettings['sms_notifications_enabled'] = $smsSettings['sms_notifications_enabled'] ?? '0';
$smsSettings['sms_otp_enabled'] = $smsSettings['sms_otp_enabled'] ?? '0';
$defaultAgentDeliveryTemplate = 'Hi {agent_name}, order {reference} for {beneficiary_number} ({data_size} {network}) has been delivered successfully. {site_name}';
$smsSettings['agent_delivery_sms_enabled'] = getSetting('agent_delivery_sms_enabled', '0');
$smsSettings['agent_delivery_sms_template'] = getSetting('agent_delivery_sms_template', $defaultAgentDeliveryTemplate);
if (trim((string) $smsSettings['agent_delivery_sms_template']) === '') {
    $smsSettings['agent_delivery_sms_template'] = $defaultAgentDeliveryTemplate;
}

$defaultAgentStoreDeliveryTemplate = 'Hi {agent_name}, an order ({reference}) of {data_size} {network} placed via your store link has been successfully completed. {site_name}';
$smsSettings['agent_store_delivery_sms_enabled'] = getSetting('agent_store_delivery_sms_enabled', '0');
$smsSettings['agent_store_delivery_sms_template'] = getSetting('agent_store_delivery_sms_template', $defaultAgentStoreDeliveryTemplate);
if (trim((string) $smsSettings['agent_store_delivery_sms_template']) === '') {
    $smsSettings['agent_store_delivery_sms_template'] = $defaultAgentStoreDeliveryTemplate;
}

$formSettings = $smsSettings;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_sms'])) {
    $formSettings['mnotify_enabled'] = isset($_POST['mnotify_enabled']) ? '1' : '0';
    $formSettings['mnotify_api_key'] = trim((string) ($_POST['mnotify_api_key'] ?? ''));
    $formSettings['mnotify_sender_id'] = trim((string) ($_POST['mnotify_sender_id'] ?? ''));
    $formSettings['sms_notifications_enabled'] = isset($_POST['sms_notifications_enabled']) ? '1' : '0';
    $formSettings['sms_otp_enabled'] = isset($_POST['sms_otp_enabled']) ? '1' : '0';
    $formSettings['agent_delivery_sms_enabled'] = isset($_POST['agent_delivery_sms_enabled']) ? '1' : '0';
    $formSettings['agent_delivery_sms_template'] = trim((string) ($_POST['agent_delivery_sms_template'] ?? $defaultAgentDeliveryTemplate));
    $formSettings['agent_store_delivery_sms_enabled'] = isset($_POST['agent_store_delivery_sms_enabled']) ? '1' : '0';
    $formSettings['agent_store_delivery_sms_template'] = trim((string) ($_POST['agent_store_delivery_sms_template'] ?? $defaultAgentStoreDeliveryTemplate));
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formSettings['agent_delivery_sms_enabled'] = isset($_POST['agent_delivery_sms_enabled']) ? '1' : '0';
    $formSettings['agent_delivery_sms_template'] = trim((string) ($_POST['agent_delivery_sms_template'] ?? $defaultAgentDeliveryTemplate));
    $formSettings['agent_store_delivery_sms_enabled'] = isset($_POST['agent_store_delivery_sms_enabled']) ? '1' : '0';
    $formSettings['agent_store_delivery_sms_template'] = trim((string) ($_POST['agent_store_delivery_sms_template'] ?? $defaultAgentStoreDeliveryTemplate));
}

$balanceInfo = null;
if (($smsSettings['mnotify_enabled'] ?? '0') === '1' && !empty($smsSettings['mnotify_api_key'])) {
    try {
        $sms = new MnotifySmsService();
        $balanceResult = $sms->getBalance();
        if ($balanceResult['success']) {
            $balanceInfo = $balanceResult;
        }
    } catch (Exception $e) {
        // Ignore balance errors.
    }
}

$stats = ['total_sent' => 0, 'successful' => 0, 'failed' => 0, 'total_cost' => 0];
try {
    $result = $db->query("SELECT
        COUNT(*) AS total_sent,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
        SUM(cost) AS total_cost
        FROM sms_notifications
        WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($result) {
        $stats = $result->fetch_assoc() ?: $stats;
    }
} catch (Exception $e) {
    $stats = ['total_sent' => 0, 'successful' => 0, 'failed' => 0, 'total_cost' => 0];
}

$recentMessages = [];
try {
    $result = $db->query("SELECT id, phone_number, purpose, status, message, cost, provider_response, sent_at FROM sms_notifications ORDER BY sent_at DESC LIMIT 8");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recentMessages[] = $row;
        }
    }
} catch (Exception $e) {
    $recentMessages = [];
}

try {
    $result = $db->query("SELECT COUNT(*) AS total FROM sms_notifications WHERE purpose = 'wallet_topup'");
    if ($result && ($row = $result->fetch_assoc())) {
        $wallet_topup_log_count = (int) ($row['total'] ?? 0);
    }
} catch (Exception $e) {
    $wallet_topup_log_count = 0;
}

$totalSent = (int) ($stats['total_sent'] ?? 0);
$successful = (int) ($stats['successful'] ?? 0);
$failed = (int) ($stats['failed'] ?? 0);
$totalCost = (float) ($stats['total_cost'] ?? 0);
$successRate = $totalSent > 0 ? (int) round(($successful / $totalSent) * 100) : 0;
$providerEnabled = ($smsSettings['mnotify_enabled'] ?? '0') === '1';
$notificationsEnabled = ($smsSettings['sms_notifications_enabled'] ?? '0') === '1';
$otpEnabled = ($smsSettings['sms_otp_enabled'] ?? '0') === '1';
$senderIdPreview = trim((string) ($smsSettings['mnotify_sender_id'] ?? '')) !== '' ? trim((string) $smsSettings['mnotify_sender_id']) : 'Not set';
$apiKeyPreview = trim((string) ($smsSettings['mnotify_api_key'] ?? ''));
if ($apiKeyPreview !== '' && strlen($apiKeyPreview) > 10) {
    $apiKeyPreview = substr($apiKeyPreview, 0, 6) . '...' . substr($apiKeyPreview, -4);
} elseif ($apiKeyPreview === '') {
    $apiKeyPreview = 'Not configured';
}

$csrf_token = generateCSRF();
$pageTitle = 'SMS Settings';
require_once '../includes/admin_header.php';
?>
<style>
    .dashboard-header .header-actions {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        flex-wrap: nowrap;
    }

    .dashboard-header .theme-toggle {
        flex: 0 0 auto;
        box-shadow: 0 12px 22px rgba(15, 23, 42, 0.18);
    }

    .dashboard-header .user-dropdown {
        display: flex;
        align-items: center;
    }

    .dashboard-header .user-dropdown-toggle {
        min-width: 210px;
        gap: 0.7rem;
        padding: 0.48rem 0.85rem 0.48rem 0.55rem;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
    }

    .dashboard-header .user-dropdown-menu {
        min-width: 220px;
    }

    .dashboard-header .dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        white-space: nowrap;
    }

    .dashboard-header .dropdown-item i {
        width: 1rem;
        min-width: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
    }

    .sms-page-title {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .sms-eyebrow {
        margin: 0 0 0.35rem;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--brand-primary);
    }

    .sms-page-meta {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .sms-balance-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.7rem 1rem;
        border-radius: 999px;
        border: 1px solid rgba(139, 92, 246, 0.18);
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.12), rgba(99, 102, 241, 0.06));
        color: var(--text-primary);
        font-weight: 600;
        white-space: nowrap;
    }

    .sms-stat-card .stat-icon {
        border-radius: 1rem;
    }

    .sms-settings-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.9fr);
        gap: 1.5rem;
        align-items: start;
        margin-bottom: 1.5rem;
    }

    .sms-main-column,
    .sms-side-column {
        display: grid;
        gap: 1.5rem;
    }

    .sms-widget-header {
        gap: 1rem;
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .sms-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: rgba(139, 92, 246, 0.1);
        color: var(--brand-primary);
    }

    .sms-toggle-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .sms-toggle-card {
        position: relative;
        padding: 1rem 1rem 1rem 4rem;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        background: var(--bg-secondary);
        min-height: 120px;
    }

    .sms-toggle-card input[type="checkbox"] {
        position: absolute;
        top: 1rem;
        left: 1rem;
        width: 1.2rem;
        height: 1.2rem;
        margin: 0;
    }

    .sms-toggle-card h4 {
        margin: 0 0 0.35rem;
        font-size: 1rem;
    }

    .sms-toggle-card p {
        margin: 0;
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .sms-form-note {
        margin-top: 1rem;
        padding: 0.95rem 1rem;
        border-radius: var(--radius-md);
        border: 1px solid rgba(139, 92, 246, 0.14);
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.08), rgba(59, 130, 246, 0.05));
        color: var(--text-secondary);
    }

    .sms-form-note strong {
        color: var(--text-primary);
    }

    .sms-test-shell {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(220px, 0.85fr);
        gap: 1rem;
        align-items: start;
    }

    .sms-preview-card {
        padding: 1rem;
        border-radius: var(--radius-lg);
        border: 1px dashed var(--border-color);
        background: linear-gradient(180deg, var(--bg-secondary), rgba(139, 92, 246, 0.05));
    }

    .sms-preview-card h4 {
        margin: 0 0 0.75rem;
        font-size: 0.95rem;
    }

    .sms-preview-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 0.75rem;
    }

    .sms-preview-list strong {
        display: block;
        margin-bottom: 0.2rem;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
    }

    .sms-status-panel {
        padding: 1rem;
        border-radius: var(--radius-lg);
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
    }

    .sms-status-panel + .sms-status-panel {
        margin-top: 1rem;
    }

    .sms-status-panel h4 {
        margin: 0 0 0.85rem;
        font-size: 0.95rem;
    }

    .sms-status-list {
        display: grid;
        gap: 0.85rem;
    }

    .sms-status-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding-bottom: 0.85rem;
        border-bottom: 1px solid var(--border-color);
    }

    .sms-status-row:last-child {
        padding-bottom: 0;
        border-bottom: none;
    }

    .sms-status-label {
        display: block;
        margin-bottom: 0.2rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-muted);
    }

    .sms-status-value {
        font-weight: 600;
        color: var(--text-primary);
        text-align: right;
    }

    .sms-status-value.muted {
        color: var(--text-secondary);
        font-weight: 500;
    }

    .sms-balance-card {
        padding: 1rem;
        border-radius: var(--radius-lg);
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(59, 130, 246, 0.08));
        border: 1px solid rgba(16, 185, 129, 0.18);
    }

    .sms-balance-label {
        display: block;
        margin-bottom: 0.4rem;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #047857;
    }

    .sms-balance-value {
        margin: 0;
        font-size: 1.9rem;
        font-weight: 700;
        line-height: 1.1;
        color: var(--text-primary);
    }

    .sms-balance-meta {
        margin: 0.5rem 0 0;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .sms-checklist {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 0.9rem;
    }

    .sms-checklist li {
        display: flex;
        gap: 0.75rem;
        align-items: flex-start;
        color: var(--text-secondary);
    }

    .sms-checklist li i {
        margin-top: 0.2rem;
        color: var(--brand-primary);
        flex-shrink: 0;
    }

    .sms-activity-list {
        display: grid;
        gap: 0.9rem;
    }

    .sms-activity-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem 1.1rem;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        background: var(--bg-secondary);
    }

    .sms-activity-top {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 0.4rem;
    }

    .sms-activity-phone {
        font-weight: 600;
        color: var(--text-primary);
    }

    .sms-purpose-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.2rem 0.5rem;
        border-radius: 999px;
        background: rgba(139, 92, 246, 0.1);
        color: var(--brand-primary);
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .sms-activity-text {
        margin: 0;
        color: var(--text-secondary);
        line-height: 1.5;
    }

    .sms-activity-side {
        min-width: 120px;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.45rem;
    }

    .sms-activity-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .sms-inline-form {
        display: inline-flex;
        align-items: center;
    }

    .sms-delete-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        min-height: 38px;
        border-radius: 999px;
        border: 1px solid rgba(239, 68, 68, 0.28);
        background: rgba(239, 68, 68, 0.08);
        color: #dc2626;
        padding: 0.45rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .sms-delete-btn:hover,
    .sms-delete-btn:focus-visible {
        background: rgba(239, 68, 68, 0.14);
        border-color: rgba(239, 68, 68, 0.4);
        color: #b91c1c;
        outline: none;
    }

    .sms-cost,
    .sms-time {
        font-size: 0.82rem;
        color: var(--text-muted);
        text-align: right;
    }

    .sms-empty-state {
        padding: 1.6rem;
        border-radius: var(--radius-lg);
        border: 1px dashed var(--border-color);
        background: var(--bg-secondary);
        color: var(--text-secondary);
        text-align: center;
    }

    .alert.sms-alert {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .alert.sms-alert i {
        margin-top: 0.15rem;
    }

    @media (max-width: 1100px) {
        .sms-settings-grid {
            grid-template-columns: 1fr;
        }

        .sms-toggle-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .dashboard-header .header-actions {
            gap: 0.55rem;
        }

        .dashboard-header .theme-toggle {
            width: 42px;
            height: 42px;
        }

        .dashboard-header .user-dropdown-toggle {
            min-width: auto;
            padding: 0.35rem 0.45rem;
            gap: 0.4rem;
        }

        .dashboard-header .user-dropdown-menu {
            right: 0;
            left: auto;
        }

        .sms-toggle-grid,
        .sms-test-shell {
            grid-template-columns: 1fr;
        }

        .sms-page-meta {
            width: 100%;
        }

        .sms-balance-pill {
            width: 100%;
            justify-content: center;
        }

        .sms-activity-item {
            flex-direction: column;
        }

        .sms-activity-side {
            min-width: 0;
            width: 100%;
            align-items: flex-start;
        }

        .sms-cost,
        .sms-time,
        .sms-status-value {
            text-align: left;
        }

        .sms-activity-actions {
            align-items: flex-start;
        }
    }

    @media (max-width: 520px) {
        .sms-toggle-grid {
            grid-template-columns: 1fr;
        }

        .sms-toggle-card {
            min-height: 0;
        }
    }
</style>

<div class="page-title sms-page-title">
    <div>
        <p class="sms-eyebrow">Messaging Configuration</p>
        <h1>SMS Settings</h1>
        <p class="page-subtitle">Manage mNotify credentials, delivery toggles, and live verification tools with a layout that stays clean on desktop, tablet, and mobile.</p>
    </div>
    <div class="sms-page-meta">
        <span class="badge <?php echo $providerEnabled ? 'badge-success' : 'badge-danger'; ?>">
            <i class="fas <?php echo $providerEnabled ? 'fa-check-circle' : 'fa-circle-xmark'; ?>"></i>
            <?php echo $providerEnabled ? 'Provider Enabled' : 'Provider Disabled'; ?>
        </span>
        <?php if ($balanceInfo): ?>
            <span class="sms-balance-pill">
                <i class="fas fa-wallet"></i>
                Balance: <?php echo htmlspecialchars((string) ($balanceInfo['balance'] ?? $balanceInfo['units'] ?? 'Available')); ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success sms-alert">
        <i class="fas fa-check-circle"></i>
        <div><?php echo htmlspecialchars($success_message); ?></div>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger sms-alert">
        <i class="fas fa-exclamation-triangle"></i>
        <div><?php echo htmlspecialchars($error_message); ?></div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card sms-stat-card">
        <div class="stat-icon primary"><i class="fas fa-paper-plane"></i></div>
        <div class="stat-content">
            <h3><?php echo number_format($totalSent); ?></h3>
            <p>Messages sent in the last 30 days</p>
        </div>
    </div>
    <div class="stat-card sms-stat-card">
        <div class="stat-icon success"><i class="fas fa-circle-check"></i></div>
        <div class="stat-content">
            <h3><?php echo number_format($successful); ?></h3>
            <p>Successful deliveries with <?php echo $successRate; ?>% success rate</p>
        </div>
    </div>
    <div class="stat-card sms-stat-card">
        <div class="stat-icon danger"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-content">
            <h3><?php echo number_format($failed); ?></h3>
            <p>Failed attempts requiring review</p>
        </div>
    </div>
    <div class="stat-card sms-stat-card">
        <div class="stat-icon warning"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <h3>GHS <?php echo number_format($totalCost, 2); ?></h3>
            <p>Estimated delivery cost for the same period</p>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

    <div class="sms-settings-grid">
        <div class="sms-main-column">
            <section class="widget">
                <div class="widget-header sms-widget-header">
                    <div>
                        <h3 class="widget-title"><i class="fas fa-sms"></i> Provider Configuration</h3>
                        <p class="widget-subtitle">Store the mNotify credentials and choose which SMS features should be active platform-wide.</p>
                    </div>
                    <span class="sms-chip"><i class="fas fa-bolt"></i> mNotify</span>
                </div>
                <div class="widget-body">
                    <div class="sms-toggle-grid">
                        <label class="sms-toggle-card" for="mnotify_enabled">
                            <input type="checkbox" id="mnotify_enabled" name="mnotify_enabled" <?php echo (($formSettings['mnotify_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <h4>Enable provider</h4>
                            <p>Turns SMS delivery on for the platform using the credentials below.</p>
                        </label>

                        <label class="sms-toggle-card" for="sms_notifications_enabled">
                            <input type="checkbox" id="sms_notifications_enabled" name="sms_notifications_enabled" <?php echo (($formSettings['sms_notifications_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <h4>Transactional alerts</h4>
                            <p>Allows system notifications such as purchase confirmations and order alerts.</p>
                        </label>

                        <label class="sms-toggle-card" for="sms_otp_enabled">
                            <input type="checkbox" id="sms_otp_enabled" name="sms_otp_enabled" <?php echo (($formSettings['sms_otp_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <h4>OTP verification</h4>
                            <p>Allows phone verification and other one-time password flows to use SMS.</p>
                        </label>

                        <label class="sms-toggle-card" for="agent_delivery_sms_enabled">
                            <input type="checkbox" id="agent_delivery_sms_enabled" name="agent_delivery_sms_enabled" <?php echo (($formSettings['agent_delivery_sms_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <h4>Agent delivery SMS</h4>
                            <p>Sends agents a message when admin marks their data orders as delivered.</p>
                        </label>

                        <label class="sms-toggle-card" for="agent_store_delivery_sms_enabled">
                            <input type="checkbox" id="agent_store_delivery_sms_enabled" name="agent_store_delivery_sms_enabled" <?php echo (($formSettings['agent_store_delivery_sms_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <h4>Agent store SMS</h4>
                            <p>Sends agents a message when a purchase made via their store link is completed.</p>
                        </label>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="mnotify_api_key">mNotify API Key</label>
                            <input type="text" id="mnotify_api_key" name="mnotify_api_key" class="form-control" value="<?php echo htmlspecialchars((string) ($formSettings['mnotify_api_key'] ?? '')); ?>" placeholder="Enter your mNotify API key" autocomplete="off">
                            <small class="form-help">Keep this private. It authorizes every request sent from the platform.</small>
                        </div>

                        <div class="form-group">
                            <label for="mnotify_sender_id">Sender ID</label>
                            <input type="text" id="mnotify_sender_id" name="mnotify_sender_id" class="form-control" value="<?php echo htmlspecialchars((string) ($formSettings['mnotify_sender_id'] ?? '')); ?>" placeholder="DataBundle" maxlength="20">
                            <small class="form-help">Use a short brand-safe sender name approved by your SMS provider.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="agent_delivery_sms_template">Agent Delivery Message</label>
                        <textarea id="agent_delivery_sms_template" name="agent_delivery_sms_template" class="form-control" rows="4"><?php echo htmlspecialchars((string) ($formSettings['agent_delivery_sms_template'] ?? $defaultAgentDeliveryTemplate)); ?></textarea>
                        <small class="form-help">Placeholders: {agent_name}, {beneficiary_number}, {data_size}, {network}, {reference}, {order_id}, {site_name}</small>
                    </div>

                    <div class="form-group">
                        <label for="agent_store_delivery_sms_template">Agent Store Order Completed Message</label>
                        <textarea id="agent_store_delivery_sms_template" name="agent_store_delivery_sms_template" class="form-control" rows="4"><?php echo htmlspecialchars((string) ($formSettings['agent_store_delivery_sms_template'] ?? $defaultAgentStoreDeliveryTemplate)); ?></textarea>
                        <small class="form-help">Placeholders: {agent_name}, {reference}, {data_size}, {network}, {beneficiary_number}, {site_name}</small>
                    </div>

                    <div class="sms-form-note">
                        <strong>Operational note:</strong> enabling the main provider automatically allows transactional SMS on save, because the platform depends on delivery-level notifications once SMS is turned on.
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Save SMS Settings
                        </button>
                        <a href="sms-broadcast.php" class="btn btn-secondary">
                            <i class="fas fa-bullhorn"></i>
                            Open Broadcast Center
                        </a>
                    </div>
                </div>
            </section>
            <section class="widget">
                <div class="widget-header sms-widget-header">
                    <div>
                        <h3 class="widget-title"><i class="fas fa-vial-circle-check"></i> Test Delivery</h3>
                        <p class="widget-subtitle">Send a live test SMS using the values currently visible in this form, even before saving them.</p>
                    </div>
                </div>
                <div class="widget-body">
                    <div class="sms-test-shell">
                        <div class="form-group">
                            <label for="test_phone">Test Phone Number</label>
                            <input type="text" id="test_phone" name="test_phone" class="form-control" value="<?php echo htmlspecialchars((string) ($_POST['test_phone'] ?? '')); ?>" placeholder="233XXXXXXXXX">
                            <small class="form-help">Use a reachable number in international or local format. The message includes the current server timestamp.</small>
                        </div>

                        <div class="sms-preview-card">
                            <h4>Test Payload</h4>
                            <ul class="sms-preview-list">
                                <li>
                                    <strong>Sender ID</strong>
                                    <span><?php echo htmlspecialchars(trim((string) ($formSettings['mnotify_sender_id'] ?? '')) !== '' ? (string) $formSettings['mnotify_sender_id'] : 'Uses provider default'); ?></span>
                                </li>
                                <li>
                                    <strong>Message type</strong>
                                    <span>General delivery verification</span>
                                </li>
                                <li>
                                    <strong>API key state</strong>
                                    <span><?php echo htmlspecialchars(trim((string) ($formSettings['mnotify_api_key'] ?? '')) !== '' ? 'Present in current form' : 'Missing'); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="test_sms" value="1" class="btn btn-secondary">
                            <i class="fas fa-paper-plane"></i>
                            Send Test SMS
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <aside class="sms-side-column">
            <section class="widget">
                <div class="widget-header sms-widget-header">
                    <div>
                        <h3 class="widget-title"><i class="fas fa-signal"></i> Delivery Status</h3>
                        <p class="widget-subtitle">Current saved configuration and delivery health.</p>
                    </div>
                </div>
                <div class="widget-body">
                    <?php if ($balanceInfo): ?>
                        <div class="sms-balance-card">
                            <span class="sms-balance-label">Available SMS Balance</span>
                            <p class="sms-balance-value"><?php echo htmlspecialchars((string) ($balanceInfo['balance'] ?? $balanceInfo['units'] ?? '0')); ?></p>
                            <p class="sms-balance-meta">Units refresh only when the provider is enabled and the API call succeeds.</p>
                        </div>
                    <?php endif; ?>

                    <div class="sms-status-panel">
                        <h4>Configuration Snapshot</h4>
                        <div class="sms-status-list">
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">Provider</span>
                                    <div>mNotify service status</div>
                                </div>
                                <div class="sms-status-value"><?php echo $providerEnabled ? 'Active' : 'Disabled'; ?></div>
                            </div>
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">Sender ID</span>
                                    <div>Configured sender identity</div>
                                </div>
                                <div class="sms-status-value muted"><?php echo htmlspecialchars($senderIdPreview); ?></div>
                            </div>
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">Notifications</span>
                                    <div>Transactional alerts</div>
                                </div>
                                <div class="sms-status-value"><?php echo $notificationsEnabled ? 'Enabled' : 'Disabled'; ?></div>
                            </div>
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">OTP</span>
                                    <div>Verification SMS</div>
                                </div>
                                <div class="sms-status-value"><?php echo $otpEnabled ? 'Enabled' : 'Disabled'; ?></div>
                            </div>
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">Agent Store SMS</span>
                                    <div>Store link completed alerts</div>
                                </div>
                                <div class="sms-status-value"><?php echo ($smsSettings['agent_store_delivery_sms_enabled'] ?? '0') === '1' ? 'Enabled' : 'Disabled'; ?></div>
                            </div>
                            <div class="sms-status-row">
                                <div>
                                    <span class="sms-status-label">API Key</span>
                                    <div>Stored credential fingerprint</div>
                                </div>
                                <div class="sms-status-value muted"><?php echo htmlspecialchars($apiKeyPreview); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="widget">
                <div class="widget-header sms-widget-header">
                    <div>
                        <h3 class="widget-title"><i class="fas fa-list-check"></i> Setup Notes</h3>
                        <p class="widget-subtitle">Quick operational reminders for a clean rollout.</p>
                    </div>
                </div>
                <div class="widget-body">
                    <ul class="sms-checklist">
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Use a sender ID that matches your approved brand name to reduce carrier filtering.</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Keep OTP enabled only when phone verification or secure login flows actively rely on SMS.</span>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <span>Run a live test after changing the API key or sender ID so the latest credentials are verified immediately.</span>
                        </li>
                    </ul>
                </div>
            </section>
        </aside>
    </div>
</form>

<section class="widget">
    <div class="widget-header sms-widget-header">
        <div class="sms-activity-actions">
            <div>
            <h3 class="widget-title"><i class="fas fa-clock-rotate-left"></i> Recent Delivery Activity</h3>
            <p class="widget-subtitle">Latest SMS attempts recorded by the platform, with status, purpose, and estimated cost.</p>
            </div>
            <?php if ($wallet_topup_log_count > 0): ?>
                <form method="POST" class="sms-inline-form" onsubmit="return confirm('Delete all wallet top-up SMS notifications? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" name="delete_all_wallet_topup_sms" value="1" class="sms-delete-btn">
                        <i class="fas fa-trash-alt"></i>
                        Delete All Wallet Topups
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="widget-body">
        <?php if (empty($recentMessages)): ?>
            <div class="sms-empty-state">
                No SMS delivery activity has been logged yet. Send a test message or enable transactional SMS to start seeing records here.
            </div>
        <?php else: ?>
            <div class="sms-activity-list">
                <?php foreach ($recentMessages as $message): ?>
                    <?php
                    $messageStatus = strtolower((string) ($message['status'] ?? 'failed'));
                    $statusBadgeClass = $messageStatus === 'sent' ? 'badge-success' : ($messageStatus === 'failed' ? 'badge-danger' : 'badge-warning');
                    $sentAt = !empty($message['sent_at']) ? strtotime((string) $message['sent_at']) : false;
                    ?>
                    <article class="sms-activity-item">
                        <div class="sms-activity-main">
                            <div class="sms-activity-top">
                                <span class="sms-activity-phone"><?php echo htmlspecialchars(adminSmsMaskPhone($message['phone_number'] ?? '')); ?></span>
                                <span class="sms-purpose-badge"><?php echo htmlspecialchars((string) ($message['purpose'] ?? 'general')); ?></span>
                            </div>
                            <p class="sms-activity-text"><?php echo htmlspecialchars((string) ($message['message'] ?? 'No message preview available.')); ?></p>
                             <?php 
                             if ($messageStatus === 'failed' && !empty($message['provider_response'])) {
                                 $responseObj = json_decode($message['provider_response'], true);
                                 $errDetails = $responseObj['error'] ?? '';
                                 if ($errDetails) {
                                     echo '<div class="text-danger small mt-1" style="font-size: 0.78rem;"><i class="fas fa-circle-info me-1"></i>Error: ' . htmlspecialchars($errDetails) . '</div>';
                                 }
                             }
                             ?>
                        </div>
                        <div class="sms-activity-side">
                            <span class="badge <?php echo $statusBadgeClass; ?>"><?php echo htmlspecialchars(ucfirst($messageStatus)); ?></span>
                            <span class="sms-cost">Cost: GHS <?php echo number_format((float) ($message['cost'] ?? 0), 2); ?></span>
                            <span class="sms-time"><?php echo $sentAt ? htmlspecialchars(date('M j, Y g:i A', $sentAt)) : 'Unknown time'; ?></span>
                            <?php if (($message['purpose'] ?? '') === 'wallet_topup'): ?>
                                <form method="POST" class="sms-inline-form" onsubmit="return confirm('Delete this wallet top-up SMS notification?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="sms_notification_id" value="<?php echo (int) ($message['id'] ?? 0); ?>">
                                    <button type="submit" name="delete_wallet_topup_sms" value="1" class="sms-delete-btn">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const providerToggle = document.getElementById('mnotify_enabled');
    const notificationToggle = document.getElementById('sms_notifications_enabled');

    if (!providerToggle || !notificationToggle) {
        return;
    }

    function syncToggles() {
        if (providerToggle.checked) {
            notificationToggle.checked = true;
        }
    }

    providerToggle.addEventListener('change', syncToggles);
    syncToggles();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
