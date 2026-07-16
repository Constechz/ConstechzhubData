<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';

// Require agent role
requireRole('vip');

ensureSmsSupportTables();
ensureAgentSmsSettingsTable();
ensureAgentPaymentSettingsTable();
if (function_exists('ensureAgentStoreOrderEmailSettingColumn')) {
    ensureAgentStoreOrderEmailSettingColumn();
}

$current_user = getCurrentUser();
$error = '';
$success = '';
$agentStoreSlug = '';

try {
    $storeStmt = $db->prepare("SELECT store_slug FROM agent_stores WHERE agent_id = ? AND is_active = TRUE ORDER BY id DESC LIMIT 1");
    if ($storeStmt) {
        $storeStmt->bind_param('i', $current_user['id']);
        $storeStmt->execute();
        $storeRow = $storeStmt->get_result()->fetch_assoc();
        $agentStoreSlug = (string) ($storeRow['store_slug'] ?? '');
        $storeStmt->close();
    }
} catch (Exception $e) {
    error_log('Agent settings store slug load failed: ' . $e->getMessage());
}

$smsEnabled = isSMSFeatureEnabled();

$defaultSignature = 'Thanks, ' . ($current_user['full_name'] ?? SITE_NAME . ' Agent');
$agentSmsSettings = [
    'sender_label' => '',
    'default_signature' => $defaultSignature,
    'default_message' => 'Hello {{name}}, we have fresh data bundle deals on {{site}}. Reply if you need support.',
    'include_customer_name' => 1,
    'mnotify_api_key' => '',
    'mnotify_sender_id' => '',
    'mnotify_is_active' => 0,
];

try {
    $stmt = $db->prepare("SELECT sender_label, default_signature, default_message, include_customer_name, mnotify_api_key, mnotify_sender_id, mnotify_is_active FROM agent_sms_settings WHERE agent_id = ? LIMIT 1");
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $settingsResult = $stmt->get_result()->fetch_assoc();
    if ($settingsResult) {
        $agentSmsSettings = array_merge($agentSmsSettings, $settingsResult);
    }
} catch (Exception $e) {
    error_log('Agent SMS settings load failed: ' . $e->getMessage());
}

$agentSmsConfigured = !empty($agentSmsSettings['mnotify_api_key']) && !empty($agentSmsSettings['mnotify_sender_id']);
$agentSmsActive = $agentSmsConfigured && !empty($agentSmsSettings['mnotify_is_active']);
$agentSmsStatusLabel = 'Pending Setup';
$agentSmsStatusColor = 'var(--text-muted)';

if (!$agentSmsConfigured) {
    $agentSmsStatusLabel = 'Pending Agent Setup';
    $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
} elseif (!$agentSmsActive) {
    $agentSmsStatusLabel = 'Paused';
    $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
} elseif (!$smsEnabled) {
    $agentSmsStatusLabel = 'Active (Admin SMS Off)';
    $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
} else {
    $agentSmsStatusLabel = 'Active';
    $agentSmsStatusColor = 'var(--success-color, #1ab394)';
}

$agentCustomers = [];
$agentCustomerIndex = [];

try {
    $stmt = $db->prepare("SELECT id, full_name, phone FROM users WHERE role = 'customer' AND agent_id = ? ORDER BY full_name ASC");
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (empty($row['phone'])) {
            continue;
        }
        $normalized = formatPhone($row['phone']);
        if (!$normalized || !preg_match('/^233[0-9]{9}$/', $normalized)) {
            continue;
        }
        $row['phone_normalized'] = $normalized;
        $agentCustomers[] = $row;
        $agentCustomerIndex[$row['id']] = $row;
    }
} catch (Exception $e) {
    error_log('Agent customers load failed: ' . $e->getMessage());
}

$agentCustomerCount = count($agentCustomers);

function buildAgentSmsBody($template, $customerName, $agentName, $signature = '', $appendSignature = false) {
    $replacements = [
        '{{name}}' => $customerName ?: 'Customer',
        '{{agent}}' => $agentName,
        '{{site}}' => SITE_NAME,
    ];

    $message = strtr($template, $replacements);

    if ($appendSignature && !empty($signature)) {
        $message = rtrim($message) . "\n" . $signature;
    }

    return trim($message);
}

$smsFormData = [
    'title' => '',
    'target' => 'all_customers',
    'customer_id' => '',
    'custom_numbers' => '',
    'message' => $agentSmsSettings['default_message'] ?? '',
    'append_signature' => true,
    'signature_text' => $agentSmsSettings['default_signature'] ?? '',
    'personalize_names' => !empty($agentSmsSettings['include_customer_name']),
];

if (($_POST['action'] ?? '') === 'send_customer_sms') {
    $smsFormData['title'] = $_POST['sms_campaign_title'] ?? '';
    $smsFormData['target'] = $_POST['sms_target'] ?? 'all_customers';
    $smsFormData['customer_id'] = $_POST['sms_customer_id'] ?? '';
    $smsFormData['custom_numbers'] = $_POST['sms_custom_numbers'] ?? '';
    $smsFormData['message'] = $_POST['sms_message'] ?? $smsFormData['message'];
    $smsFormData['append_signature'] = isset($_POST['append_signature']);
    $smsFormData['signature_text'] = $_POST['signature_text'] ?? $smsFormData['signature_text'];
    $smsFormData['personalize_names'] = isset($_POST['personalize_names']) ? true : false;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'upload_logo') {
            // Handle logo upload
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['logo'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $max_size = 2 * 1024 * 1024; // 2MB
                
                if (!in_array($file['type'], $allowed_types)) {
                    $error = 'Invalid file type. Please upload JPG, PNG, GIF, or WebP images only.';
                } elseif ($file['size'] > $max_size) {
                    $error = 'File too large. Maximum size is 2MB.';
                } else {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../uploads/agent_logos/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate unique filename
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'agent_' . $current_user['id'] . '_' . time() . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        // Update agent logo in database
                        $stmt = $db->prepare("UPDATE users SET agent_logo = ? WHERE id = ?");
                        $stmt->bind_param("si", $filename, $current_user['id']);
                        
                        if ($stmt->execute()) {
                            $success = 'Logo uploaded successfully!';
                            // Update current user data
                            $current_user['agent_logo'] = $filename;
                        } else {
                            $error = 'Failed to save logo to database.';
                            unlink($filepath); // Remove uploaded file
                        }
                    } else {
                        $error = 'Failed to upload logo. Please try again.';
                    }
                }
            } else {
                $error = 'Please select a logo file to upload.';
            }
        } elseif ($action === 'remove_logo') {
            // Remove existing logo
            if (!empty($current_user['agent_logo'])) {
                $logo_path = '../uploads/agent_logos/' . $current_user['agent_logo'];
                if (file_exists($logo_path)) {
                    unlink($logo_path);
                }
                
                $stmt = $db->prepare("UPDATE users SET agent_logo = NULL WHERE id = ?");
                $stmt->bind_param("i", $current_user['id']);
                
                if ($stmt->execute()) {
                    $success = 'Logo removed successfully!';
                    $current_user['agent_logo'] = null;
                } else {
                    $error = 'Failed to remove logo from database.';
                }
            }
        } elseif ($action === 'update_payment_methods') {
            // Handle payment method settings update
            $allowPaystack = isset($_POST['allow_paystack']) ? 1 : 0;
            $allowTopupRequest = isset($_POST['allow_topup_request']) ? 1 : 0;
            
            // Ensure at least one payment method is enabled
            if (!$allowPaystack && !$allowTopupRequest) {
                $error = 'At least one payment method must be enabled for your customers.';
            } else {
                // Check if settings exist
                $stmt = $db->prepare("SELECT id FROM agent_payment_settings WHERE agent_id = ?");
                $stmt->bind_param('i', $current_user['id']);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                
                if ($exists) {
                    // Update existing settings
                    $stmt = $db->prepare("UPDATE agent_payment_settings SET allow_paystack = ?, allow_topup_request = ? WHERE agent_id = ?");
                    $stmt->bind_param('iii', $allowPaystack, $allowTopupRequest, $current_user['id']);
                } else {
                    // Create new settings
                    $stmt = $db->prepare("INSERT INTO agent_payment_settings (agent_id, allow_paystack, allow_topup_request) VALUES (?, ?, ?)");
                    $stmt->bind_param('iii', $current_user['id'], $allowPaystack, $allowTopupRequest);
                }
                
                if ($stmt->execute()) {
                    $success = 'Payment method preferences updated successfully!';
                    // Log activity
                    logActivity($current_user['id'], 'agent_payment_settings_updated', json_encode([
                        'allow_paystack' => (bool)$allowPaystack,
                        'allow_topup_request' => (bool)$allowTopupRequest
                    ]));
                } else {
                    $error = 'Failed to update payment method preferences.';
                }
            }
        } elseif ($action === 'update_sms_preferences') {
            $senderLabel = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['sender_label'] ?? ''));
            $senderLabel = substr($senderLabel, 0, 11);
            $defaultSignature = trim($_POST['default_signature'] ?? $agentSmsSettings['default_signature']);
            $defaultMessage = trim($_POST['default_message'] ?? $agentSmsSettings['default_message']);
            $includeNames = isset($_POST['include_customer_name']) ? 1 : 0;
            $mnotifyApiKey = trim($_POST['mnotify_api_key'] ?? $agentSmsSettings['mnotify_api_key']);
            $mnotifySenderId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['mnotify_sender_id'] ?? $agentSmsSettings['mnotify_sender_id']));
            $mnotifySenderId = substr($mnotifySenderId, 0, 11);
            $mnotifyActive = isset($_POST['mnotify_is_active']) ? 1 : 0;

            if ($defaultMessage === '') {
                $defaultMessage = $agentSmsSettings['default_message'];
            }

            try {
                $stmt = $db->prepare("INSERT INTO agent_sms_settings (agent_id, sender_label, default_signature, default_message, include_customer_name, mnotify_api_key, mnotify_sender_id, mnotify_is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE sender_label = VALUES(sender_label), default_signature = VALUES(default_signature), default_message = VALUES(default_message), include_customer_name = VALUES(include_customer_name), mnotify_api_key = VALUES(mnotify_api_key), mnotify_sender_id = VALUES(mnotify_sender_id), mnotify_is_active = VALUES(mnotify_is_active)");
                $stmt->bind_param('isssissi', $current_user['id'], $senderLabel, $defaultSignature, $defaultMessage, $includeNames, $mnotifyApiKey, $mnotifySenderId, $mnotifyActive);
                if ($stmt->execute()) {
                    $success = 'SMS preferences updated successfully!';
                    $agentSmsSettings['sender_label'] = $senderLabel;
                    $agentSmsSettings['default_signature'] = $defaultSignature;
                    $agentSmsSettings['default_message'] = $defaultMessage;
                    $agentSmsSettings['include_customer_name'] = $includeNames;
                    $agentSmsSettings['mnotify_api_key'] = $mnotifyApiKey;
                    $agentSmsSettings['mnotify_sender_id'] = $mnotifySenderId;
                    $agentSmsSettings['mnotify_is_active'] = $mnotifyActive;
                    $smsFormData['message'] = $defaultMessage;
                    $smsFormData['signature_text'] = $defaultSignature;
                    $smsFormData['personalize_names'] = (bool)$includeNames;
                    $agentSmsConfigured = !empty($agentSmsSettings['mnotify_api_key']) && !empty($agentSmsSettings['mnotify_sender_id']);
                    $agentSmsActive = $agentSmsConfigured && !empty($agentSmsSettings['mnotify_is_active']);
                    if (!$agentSmsConfigured) {
                        $agentSmsStatusLabel = 'Pending Agent Setup';
                        $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
                    } elseif (!$agentSmsActive) {
                        $agentSmsStatusLabel = 'Paused';
                        $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
                    } elseif (!$smsEnabled) {
                        $agentSmsStatusLabel = 'Active (Admin SMS Off)';
                        $agentSmsStatusColor = 'var(--warning-color, #f5a524)';
                    } else {
                        $agentSmsStatusLabel = 'Active';
                        $agentSmsStatusColor = 'var(--success-color, #1ab394)';
                    }
                } else {
                    $error = 'Failed to update SMS preferences.';
                }
            } catch (Exception $e) {
                $error = 'Database error while saving SMS preferences: ' . $e->getMessage();
            }
        } elseif ($action === 'update_email_preferences') {
            $receiveStoreOrderEmails = isset($_POST['receive_store_order_emails']) ? 1 : 0;
            
            if (function_exists('ensureAgentStoreOrderEmailSettingColumn')) {
                ensureAgentStoreOrderEmailSettingColumn();
            }

            $stmt = $db->prepare("UPDATE users SET receive_store_order_emails = ? WHERE id = ?");
            $stmt->bind_param('ii', $receiveStoreOrderEmails, $current_user['id']);
            
            if ($stmt->execute()) {
                $success = 'Email preferences updated successfully!';
                $current_user['receive_store_order_emails'] = $receiveStoreOrderEmails;
                logActivity($current_user['id'], 'agent_email_preferences_updated', json_encode([
                    'receive_store_order_emails' => (bool)$receiveStoreOrderEmails
                ]));
            } else {
                $error = 'Failed to update email preferences.';
            }
        } elseif ($action === 'send_customer_sms') {
            if (!$agentSmsConfigured) {
                $error = 'Please enter your mNotify API key and Sender ID in the SMS Preferences section before sending messages.';
            } elseif (empty($agentSmsSettings['mnotify_is_active'])) {
                $error = 'Enable agent SMS in the SMS Preferences section once your credentials are ready.';
            } else {
                $smsMessage = trim($_POST['sms_message'] ?? '');
                $campaignTitle = trim($_POST['sms_campaign_title'] ?? '');
                $target = $_POST['sms_target'] ?? 'all_customers';
                $selectedCustomerId = isset($_POST['sms_customer_id']) ? (int) $_POST['sms_customer_id'] : 0;
                $customNumbersRaw = trim($_POST['sms_custom_numbers'] ?? '');
                $appendSignature = isset($_POST['append_signature']);
                $signatureText = trim($_POST['signature_text'] ?? $agentSmsSettings['default_signature']);
                $personalizeNames = isset($_POST['personalize_names']);

                $allowedTargets = ['all_customers', 'single_customer', 'custom_numbers'];
                if (!in_array($target, $allowedTargets, true)) {
                    $target = 'all_customers';
                }
                $smsFormData['title'] = $campaignTitle;
                $smsFormData['target'] = $target;
                $smsFormData['customer_id'] = $selectedCustomerId;
                $smsFormData['custom_numbers'] = $customNumbersRaw;
                $smsFormData['message'] = $smsMessage;
                $smsFormData['append_signature'] = $appendSignature;
                $smsFormData['signature_text'] = $signatureText;
                $smsFormData['personalize_names'] = $personalizeNames;

                if ($smsMessage === '') {
                    $error = 'Please enter a message to send to your customers.';
                } else {
                    $recipients = [];
                    if ($target === 'all_customers') {
                        $recipients = array_map(function($customer) {
                            return [
                                'user_id' => $customer['id'],
                                'name' => $customer['full_name'],
                                'phone' => $customer['phone_normalized']
                            ];
                        }, $agentCustomers);
                        if (empty($recipients)) {
                            $error = 'You do not have any linked customers yet.';
                        }
                    } elseif ($target === 'single_customer') {
                        if (!$selectedCustomerId || !isset($agentCustomerIndex[$selectedCustomerId])) {
                            $error = 'Selected customer could not be found.';
                        } else {
                            $customer = $agentCustomerIndex[$selectedCustomerId];
                            $recipients[] = [
                                'user_id' => $customer['id'],
                                'name' => $customer['full_name'],
                                'phone' => $customer['phone_normalized']
                            ];
                        }
                    } else {
                        $manualNumbers = parseSmsPhoneList($customNumbersRaw);
                        if (empty($manualNumbers)) {
                            $error = 'Enter at least one valid phone number in the custom list.';
                        } else {
                            foreach ($manualNumbers as $phone) {
                                $recipients[] = [
                                    'user_id' => null,
                                    'name' => '',
                                    'phone' => $phone
                                ];
                            }
                        }
                    }

                    if (empty($error) && !empty($recipients)) {
                        $agentSenderId = !empty($agentSmsSettings['mnotify_sender_id']) ? $agentSmsSettings['mnotify_sender_id'] : ($agentSmsSettings['sender_label'] ?: null);
                        $smsService = new MnotifySmsService($agentSmsSettings['mnotify_api_key'], $agentSenderId ?: null);
                        $successCount = 0;
                        $failedNumbers = [];

                        if ($signatureText === '' && !empty($agentSmsSettings['default_signature'])) {
                            $signatureText = $agentSmsSettings['default_signature'];
                        }

                        foreach ($recipients as $recipient) {
                            $customerName = $personalizeNames ? $recipient['name'] : '';
                            $body = buildAgentSmsBody($smsMessage, $customerName, $current_user['full_name'], $signatureText, $appendSignature);
                            try {
                                $response = $smsService->sendSMS($recipient['phone'], $body, 'agent_customer_broadcast', $recipient['user_id']);
                                if (!empty($response['success'])) {
                                    $successCount++;
                                } else {
                                    $failedNumbers[] = $recipient['phone'];
                                }
                            } catch (Exception $ex) {
                                $failedNumbers[] = $recipient['phone'];
                            }
                        }

                        $totalRecipients = count($recipients);
                        $failedCount = count($failedNumbers);
                        $status = 'completed';
                        if ($failedCount === $totalRecipients) {
                            $status = 'failed';
                        } elseif ($failedCount > 0) {
                            $status = 'partial';
                        }

                        $meta = [
                            'target' => $target,
                            'failed_numbers' => $failedNumbers,
                            'append_signature' => $appendSignature,
                            'personalized' => $personalizeNames,
                        ];

                        try {
                            $stmt = $db->prepare("INSERT INTO sms_broadcasts (owner_id, owner_role, title, message, target_audience, total_recipients, successful_recipients, failed_recipients, status, meta_json) VALUES (?, 'agent', ?, ?, ?, ?, ?, ?, ?, ?)");
                            $titleValue = $campaignTitle !== '' ? $campaignTitle : 'Customer Broadcast';
                            $metaJson = json_encode($meta);
                            $stmt->bind_param(
                                'isssiiiss',
                                $current_user['id'],
                                $titleValue,
                                $smsMessage,
                                $target,
                                $totalRecipients,
                                $successCount,
                                $failedCount,
                                $status,
                                $metaJson
                            );
                            $stmt->execute();
                        } catch (Exception $e) {
                            error_log('Agent broadcast logging failed: ' . $e->getMessage());
                        }

                        if ($failedCount > 0 && $successCount > 0) {
                            $success = "SMS sent to {$successCount} customers. {$failedCount} failed.";
                        } elseif ($successCount === 0) {
                            $error = 'Unable to send SMS. Please verify your message and numbers.';
                        } else {
                            $success = "SMS sent to {$successCount} customer(s).";
                        }
                    }
                }
            }
        } elseif ($action === 'test_agent_sms') {
            $testPhone = trim($_POST['test_phone'] ?? '');
            if (!$agentSmsConfigured) {
                $error = 'Save your mNotify API key and Sender ID before running a test SMS.';
            } elseif ($testPhone === '') {
                $error = 'Enter a valid phone number to send the test message to.';
            } else {
                try {
                    $agentSenderId = !empty($agentSmsSettings['mnotify_sender_id']) ? $agentSmsSettings['mnotify_sender_id'] : ($agentSmsSettings['sender_label'] ?: null);
                    $smsService = new MnotifySmsService($agentSmsSettings['mnotify_api_key'], $agentSenderId ?: null);
                    $result = $smsService->sendSMS($testPhone, 'Test SMS from ' . ($current_user['full_name'] ?? 'Agent') . ' via ' . SITE_NAME, 'agent_test', $current_user['id']);
                    if (!empty($result['success'])) {
                        $success = 'Test SMS sent successfully to ' . htmlspecialchars($testPhone) . '. Message ID: ' . ($result['message_id'] ?? 'N/A');
                    } else {
                        $error = 'Test SMS failed: ' . ($result['error'] ?? 'Unknown error');
                    }
                } catch (Exception $e) {
                    $error = 'Test SMS error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current payment method settings
$paymentSettings = ['allow_paystack' => true, 'allow_topup_request' => true]; // defaults
$stmt = $db->prepare("SELECT allow_paystack, allow_topup_request FROM agent_payment_settings WHERE agent_id = ?");
$stmt->bind_param('i', $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
if ($settings = $result->fetch_assoc()) {
    $paymentSettings['allow_paystack'] = (bool)$settings['allow_paystack'];
    $paymentSettings['allow_topup_request'] = (bool)$settings['allow_topup_request'];
}

$agentBroadcastHistory = [];
$lastBroadcastAt = null;
try {
    $stmt = $db->prepare("SELECT title, target_audience, total_recipients, successful_recipients, failed_recipients, status, created_at FROM sms_broadcasts WHERE owner_id = ? AND owner_role = 'agent' ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $agentBroadcastHistory[] = $row;
    }
    if (!empty($agentBroadcastHistory)) {
        $lastBroadcastAt = $agentBroadcastHistory[0]['created_at'];
    }
} catch (Exception $e) {
    error_log('Agent SMS broadcast history error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - <?php echo SITE_NAME; ?></title>
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
        <?php renderAgentSidebar(); ?>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-cog"></i></div>
                    <div class="breadcrumb-item active">Settings</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Agent Settings</h1>
                <p class="page-subtitle">Manage your agent profile and sub-store branding.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Logo Management -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Sub-Store Logo</h3>
                    <p class="widget-subtitle">Upload a logo that will appear on your agent sub-store landing page.</p>
                </div>
                <div class="widget-content">
                    <div style="display: flex; gap: 2rem; align-items: flex-start;">
                        <!-- Current Logo Preview -->
                        <div style="flex-shrink: 0;">
                            <div style="margin-bottom: 1rem;">
                                <strong>Current Logo:</strong>
                            </div>
                            <div style="width: 150px; height: 150px; border: 2px dashed var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary);">
                                <?php if (!empty($current_user['agent_logo'])): ?>
                                    <img src="../uploads/agent_logos/<?php echo htmlspecialchars($current_user['agent_logo']); ?>" 
                                         alt="Agent Logo" 
                                         style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px;">
                                <?php else: ?>
                                    <div style="text-align: center; color: var(--text-muted);">
                                        <i class="fas fa-image" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                        <div>No logo uploaded</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Upload Form -->
                        <div style="flex: 1;">
                            <form method="post" enctype="multipart/form-data" style="margin-bottom: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="upload_logo">
                                
                                <div class="form-group">
                                    <label for="logo" class="form-label">Choose Logo File</label>
                                    <input type="file" id="logo" name="logo" class="form-control" accept="image/*" required>
                                    <div class="form-help">
                                        Supported formats: JPG, PNG, GIF, WebP. Maximum size: 2MB.
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-upload"></i> Upload Logo
                                </button>
                            </form>

                            <?php if (!empty($current_user['agent_logo'])): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="remove_logo">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove your logo?')">
                                        <i class="fas fa-trash"></i> Remove Logo
                                    </button>
                                </form>
                            <?php endif; ?>

                            <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-secondary); border-radius: 8px;">
                                <h4 style="margin-bottom: 0.5rem; color: var(--text-primary);">
                                    <i class="fas fa-info-circle"></i> Logo Guidelines
                                </h4>
                                <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary);">
                                    <li>Use a square or rectangular logo for best results</li>
                                    <li>Recommended minimum size: 200x200 pixels</li>
                                    <li>Logo will be displayed on your sub-store landing page</li>
                                    <li>Ensure your logo is clear and professional</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Method Settings -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Customer Payment Methods</h3>
                    <p class="widget-subtitle">Control which payment methods are available to your customers when topping up their wallets.</p>
                </div>
                <div class="widget-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_payment_methods">
                        
                        <div class="payment-methods-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                            <!-- Paystack Payment -->
                            <div class="payment-method-option">
                                <div class="payment-method-header">
                                    <div class="payment-method-info">
                                        <h4 style="margin: 0; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-credit-card" style="color: #00C851;"></i>
                                            Paystack Payment
                                        </h4>
                                        <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.875rem;">Allow customers to pay with cards, bank transfers, and mobile money through Paystack.</p>
                                    </div>
                                    <div class="toggle-switch">
                                        <label class="switch">
                                            <input type="checkbox" name="allow_paystack" <?php echo $paymentSettings['allow_paystack'] ? 'checked' : ''; ?>>
                                            <span class="slider round"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="payment-method-features">
                                    <ul style="margin: 1rem 0 0 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        <li>Instant payment processing</li>
                                        <li>Multiple payment options</li>
                                        <li>Automatic wallet credit</li>
                                        <li>Secure transactions</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Topup Request -->
                            <div class="payment-method-option">
                                <div class="payment-method-header">
                                    <div class="payment-method-info">
                                        <h4 style="margin: 0; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fas fa-hand-holding-usd" style="color: #FF8800;"></i>
                                            Topup Request
                                        </h4>
                                        <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.875rem;">Allow customers to request manual topup with payment to your mobile money account.</p>
                                    </div>
                                    <div class="toggle-switch">
                                        <label class="switch">
                                            <input type="checkbox" name="allow_topup_request" <?php echo $paymentSettings['allow_topup_request'] ? 'checked' : ''; ?>>
                                            <span class="slider round"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="payment-method-features">
                                    <ul style="margin: 1rem 0 0 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                        <li>Manual approval process</li>
                                        <li>Direct mobile money payments</li>
                                        <li>Email notifications</li>
                                        <li>Custom approval workflow</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Payment Settings
                            </button>
                        </div>

                        <div class="payment-settings-notice" style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border-left: 4px solid var(--primary-color);">
                            <h4 style="margin: 0 0 0.5rem 0; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-info-circle"></i> Important Notes
                            </h4>
                            <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                                <li><strong>At least one payment method must be enabled</strong> for customers to top up their wallets</li>
                                <li>Paystack payments are processed instantly, while topup requests require manual approval</li>
                                <li>Your customers will only see the payment methods you have enabled</li>
                                <li>You can change these settings at any time</li>
                            </ul>
                        </div>
                    </form>
                </div>
            </div>

            <!-- SMS Preferences -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">SMS Preferences</h3>
                    <p class="widget-subtitle">Add your mNotify API credentials and message defaults before contacting customers.</p>
                </div>
                <div class="widget-content">
                    <?php if (!$smsEnabled): ?>
                        <div class="alert alert-info" style="margin-bottom: 1rem;">
                            <i class="fas fa-info-circle me-2"></i>Admin SMS is currently off globally, but your personal mNotify credentials will be used for broadcasts.
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_sms_preferences">

                        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                            <div class="form-group">
                                <label class="form-label" for="sender_label">Sender Label <span style="font-size: 0.85rem; color: var(--text-muted);">(optional)</span></label>
                                <input type="text" class="form-control" id="sender_label" name="sender_label" maxlength="11" value="<?php echo htmlspecialchars($agentSmsSettings['sender_label']); ?>" placeholder="<?php echo strtoupper(substr($current_user['full_name'], 0, 11)); ?>">
                                <small class="form-help">Shown inside SMS copy if you prefer a friendly alias.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="default_signature">Default Signature</label>
                                <input type="text" class="form-control" id="default_signature" name="default_signature" maxlength="80" value="<?php echo htmlspecialchars($agentSmsSettings['default_signature']); ?>">
                                <small class="form-help">Used when you choose to append signature.</small>
                            </div>
                        </div>

                        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-top: 1rem;">
                            <div class="form-group">
                                <label class="form-label" for="mnotify_api_key">mNotify API Key</label>
                                <div class="password-input-wrapper">
                                    <input type="password" class="form-control" id="mnotify_api_key" name="mnotify_api_key" value="<?php echo htmlspecialchars($agentSmsSettings['mnotify_api_key']); ?>" placeholder="Paste your API key" autocomplete="off">
                                    <button type="button" class="password-toggle" data-target="mnotify_api_key" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-help">Found under <strong>API &amp; SMS &gt; API Keys</strong> in your mNotify dashboard.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mnotify_sender_id">Approved Sender ID</label>
                                <input type="text" class="form-control" id="mnotify_sender_id" name="mnotify_sender_id" maxlength="11" value="<?php echo htmlspecialchars($agentSmsSettings['mnotify_sender_id']); ?>" placeholder="e.g. CONSTECH">
                                <small class="form-help">Use the exact sender name approved in mNotify (letters/numbers only).</small>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label" for="default_message">Default Message Template</label>
                            <textarea class="form-control" id="default_message" name="default_message" rows="3"><?php echo htmlspecialchars($agentSmsSettings['default_message']); ?></textarea>
                            <small class="form-help">Placeholders: <code>{{name}}</code>, <code>{{agent}}</code>, <code>{{site}}</code>.</small>
                        </div>

                        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-top: 1rem;">
                            <div class="form-check">
                                <input type="checkbox" id="include_customer_name" name="include_customer_name" <?php echo !empty($agentSmsSettings['include_customer_name']) ? 'checked' : ''; ?>>
                                <label for="include_customer_name">Automatically include customer names by default</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="mnotify_is_active" name="mnotify_is_active" <?php echo !empty($agentSmsSettings['mnotify_is_active']) ? 'checked' : ''; ?>>
                                <label for="mnotify_is_active">Enable agent SMS with my mNotify credentials</label>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save SMS Preferences
                            </button>
                        </div>
                    </form>
                        <div class="sms-test-block">
                            <h4>Send Test SMS</h4>
                            <p class="form-help">Verify your credentials by sending a quick message to your own line.</p>
                            <form method="post" class="sms-test-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="test_agent_sms">
                                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                                    <div class="form-group">
                                        <label class="form-label" for="test_phone">Phone Number</label>
                                        <input type="text" class="form-control" id="test_phone" name="test_phone" placeholder="233XXXXXXXXX or 05XXXXXXXX">
                                    </div>
                                    <div class="form-group" style="display:flex; align-items:flex-end;">
                                        <button type="submit" class="btn btn-outline-primary" <?php echo !$agentSmsConfigured ? 'disabled' : ''; ?>>
                                            <i class="fas fa-paper-plane"></i> Send Test SMS
                                        </button>
                                    </div>
                                </div>
                                <?php if (!$agentSmsConfigured): ?>
                                    <small class="form-help">Save your API key &amp; sender ID above to unlock testing.</small>
                                <?php else: ?>
                                    <small class="form-help">Testing works even if your broadcast toggle is paused.</small>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="sms-guidelines">
                            <h4>How to configure mNotify</h4>
                            <ol>
                                <li>Log in to <a href="https://bms.mnotify.com" target="_blank" rel="noopener">bms.mnotify.com</a> and open <strong>API &amp; SMS &gt; API Keys</strong> to copy your key.</li>
                                <li>Visit <strong>Messaging &gt; Sender IDs</strong> to create/request an 11-character sender ID, then copy the approved value.</li>
                                <li>Paste both details above and save. mNotify may take a few minutes to approve new sender IDs.</li>
                                <li>Toggle “Enable agent SMS” after saving to activate sending from your dashboard.</li>
                            </ol>
                            <p>Need help? Contact the admin team or email <a href="mailto:support@mnotify.com">support@mnotify.com</a> for sender ID approval status.</p>
                        </div>
                </div>
            </div>

            <!-- Email Preferences -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Email Notification Preferences</h3>
                    <p class="widget-subtitle">Choose which email alerts you want to receive regarding your store activity.</p>
                </div>
                <div class="widget-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_email_preferences">

                        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                            <div class="form-check">
                                <input type="checkbox" id="receive_store_order_emails" name="receive_store_order_emails" <?php echo (!isset($current_user['receive_store_order_emails']) || (int)$current_user['receive_store_order_emails'] !== 0) ? 'checked' : ''; ?>>
                                <label for="receive_store_order_emails">Receive email alerts when customers place orders on your store link</label>
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Email Preferences
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Customer SMS Center -->
            <div class="widget" id="sms-center">
                <div class="widget-header">
                    <h3 class="widget-title">Customer SMS Center</h3>
                    <p class="widget-subtitle">Send updates, promotions or reminders once your mNotify credentials are active.</p>
                </div>
                <div class="widget-content">
                    <div class="sms-stat-grid">
                        <div class="sms-stat-card">
                            <div class="sms-stat-label">Linked Customers</div>
                            <div class="sms-stat-value"><?php echo number_format($agentCustomerCount); ?></div>
                        </div>
                        <div class="sms-stat-card">
                            <div class="sms-stat-label">Last Broadcast</div>
                            <div class="sms-stat-value"><?php echo $lastBroadcastAt ? timeAgo($lastBroadcastAt) : 'Never'; ?></div>
                        </div>
                        <div class="sms-stat-card">
                            <div class="sms-stat-label">SMS Status</div>
                            <div class="sms-stat-value" style="color: <?php echo $agentSmsStatusColor; ?>;"><?php echo htmlspecialchars($agentSmsStatusLabel); ?></div>
                            <?php if (!$agentSmsActive): ?>
                                <div class="sms-stat-hint">Configure credentials below</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="post" style="margin-top: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="send_customer_sms">

                        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
                            <div class="form-group">
                                <label class="form-label" for="sms_campaign_title">Campaign Title</label>
                                <input type="text" id="sms_campaign_title" name="sms_campaign_title" class="form-control" value="<?php echo htmlspecialchars($smsFormData['title']); ?>" placeholder="Optional e.g. Weekend Promo">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="sms-target">Send To</label>
                                <select class="form-select" id="sms-target" name="sms_target">
                                    <option value="all_customers" <?php echo $smsFormData['target'] === 'all_customers' ? 'selected' : ''; ?>>All linked customers</option>
                                    <option value="single_customer" <?php echo $smsFormData['target'] === 'single_customer' ? 'selected' : ''; ?>>Specific customer</option>
                                    <option value="custom_numbers" <?php echo $smsFormData['target'] === 'custom_numbers' ? 'selected' : ''; ?>>Custom numbers</option>
                                </select>
                                <small class="form-help">Choose your target recipients.</small>
                            </div>
                        </div>

                        <div class="sms-target-extra" id="single-customer-field" style="<?php echo $smsFormData['target'] === 'single_customer' ? 'display:block;' : ''; ?>">
                            <label class="form-label" for="sms_customer_id">Select Customer</label>
                            <select class="form-select" id="sms_customer_id" name="sms_customer_id">
                                <option value="">-- Choose customer --</option>
                                <?php foreach ($agentCustomers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" <?php echo (string)$smsFormData['customer_id'] === (string)$customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['full_name']); ?> (<?php echo htmlspecialchars($customer['phone_normalized']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sms-target-extra" id="custom-numbers-field" style="<?php echo $smsFormData['target'] === 'custom_numbers' ? 'display:block;' : ''; ?>">
                            <label class="form-label" for="sms_custom_numbers">Custom Numbers</label>
                            <textarea class="form-control" id="sms_custom_numbers" name="sms_custom_numbers" rows="3" placeholder="Comma, space or line separated numbers"><?php echo htmlspecialchars($smsFormData['custom_numbers']); ?></textarea>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label" for="sms_message">Message</label>
                            <textarea class="form-control" id="sms_message" name="sms_message" rows="4"><?php echo htmlspecialchars($smsFormData['message']); ?></textarea>
                            <small class="form-help">Use placeholders: <code>{{name}}</code>, <code>{{agent}}</code>, <code>{{site}}</code>.</small>
                        </div>

                        <div class="sms-options-grid">
                            <div class="form-check">
                                <input type="checkbox" id="personalize_names" name="personalize_names" <?php echo !empty($smsFormData['personalize_names']) ? 'checked' : ''; ?>>
                                <label for="personalize_names">Personalise with customer names</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" id="append_signature" name="append_signature" <?php echo !empty($smsFormData['append_signature']) ? 'checked' : ''; ?>>
                                <label for="append_signature">Append signature</label>
                            </div>
                            <div class="form-group signature-input">
                                <label class="form-label" for="signature_text">Signature Text</label>
                                <input type="text" id="signature_text" name="signature_text" class="form-control" value="<?php echo htmlspecialchars($smsFormData['signature_text']); ?>">
                            </div>
                        </div>

                        <div class="form-actions" style="margin-top: 1.5rem;">
                            <button type="submit" class="btn btn-primary" <?php echo (!$agentSmsActive) ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane"></i> Send SMS
                            </button>
                            <?php if (!$agentSmsActive): ?>
                                <span class="text-muted" style="margin-left: 1rem;">Add your mNotify API key &amp; sender ID, then enable agent SMS above.</span>
                            <?php elseif (!$smsEnabled): ?>
                                <span class="text-muted" style="margin-left: 1rem;">Admin SMS is off, but your credentials are active.</span>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="sms-history-table" style="margin-top: 2rem;">
                        <h4>Recent SMS Activity</h4>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Audience</th>
                                        <th>Delivered</th>
                                        <th>Failed</th>
                                        <th>Status</th>
                                        <th>Sent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($agentBroadcastHistory)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">No SMS broadcasts yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($agentBroadcastHistory as $smsLog): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($smsLog['title']); ?></td>
                                                <td class="text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $smsLog['target_audience'])); ?></td>
                                                <td><?php echo (int)$smsLog['successful_recipients']; ?> / <?php echo (int)$smsLog['total_recipients']; ?></td>
                                                <td><?php echo (int)$smsLog['failed_recipients']; ?></td>
                                                <td>
                                                    <?php
                                                        $badge = 'secondary';
                                                        if ($smsLog['status'] === 'completed') $badge = 'success';
                                                        elseif ($smsLog['status'] === 'failed') $badge = 'danger';
                                                        elseif ($smsLog['status'] === 'partial') $badge = 'warning';
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($smsLog['status']); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($smsLog['created_at']))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sub-Store Information -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Sub-Store Information</h3>
                    <p class="widget-subtitle">Your personalized agent store details.</p>
                </div>
                <div class="widget-content">
                    <div class="info-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div class="info-item">
                            <div class="info-label">Store URL</div>
                            <div class="info-value">
                                <code><?php echo $agentStoreSlug !== '' ? htmlspecialchars(rtrim(SITE_URL, '/') . '/s/' . rawurlencode($agentStoreSlug)) : 'Store not created'; ?></code>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Agent ID</div>
                            <div class="info-value"><?php echo $current_user['id']; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Store Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($current_user['full_name']); ?>'s Store</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
<script>
// Initialize theme
initializeTheme();

// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }

    const smsTarget = document.getElementById('sms-target');
    const singleField = document.getElementById('single-customer-field');
    const customField = document.getElementById('custom-numbers-field');

    function updateSmsTargetFields() {
        if (!smsTarget) return;
        const value = smsTarget.value;
        if (singleField) {
            singleField.style.display = value === 'single_customer' ? 'block' : 'none';
        }
        if (customField) {
            customField.style.display = value === 'custom_numbers' ? 'block' : 'none';
        }
    }

    if (smsTarget) {
        smsTarget.addEventListener('change', updateSmsTargetFields);
        updateSmsTargetFields();
    }
});

// User dropdown toggle
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!toggle.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// Preview uploaded image
document.getElementById('logo').addEventListener('change', function(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            // You could add a preview here if needed
        };
        reader.readAsDataURL(file);
    }
});
</script>

<style>
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.info-item {
    padding: 1rem;
    background: var(--bg-secondary);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.info-label {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.info-value {
    font-size: 1rem;
    color: var(--text-primary);
    font-weight: 600;
}

.info-value code {
    background: var(--bg-primary);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.875rem;
    border: 1px solid var(--border-color);
}

.sms-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
}

.sms-stat-card {
    padding: 1rem;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    background: var(--bg-secondary);
}

.sms-stat-label {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
    text-transform: uppercase;
}

.sms-stat-value {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary);
}

.sms-stat-hint {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.sms-guidelines {
    margin-top: 1.5rem;
    padding: 1rem;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    background: var(--bg-secondary);
}

.sms-guidelines h4 {
    margin: 0 0 0.5rem 0;
    color: var(--text-primary);
}

.sms-guidelines ol {
    margin: 0.5rem 0 0.5rem 1.25rem;
    color: var(--text-secondary);
}

.sms-guidelines p {
    margin: 0;
    color: var(--text-muted);
}

.sms-guidelines a {
    color: var(--primary-color);
}

.sms-test-block {
    margin-top: 1.5rem;
    padding: 1rem;
    border: 1px dashed var(--border-color);
    border-radius: 10px;
    background: var(--bg-secondary);
}
.sms-test-form .form-group {
    margin-bottom: 0;
}

.sms-target-extra {
    margin-top: 1rem;
    display: none;
}

.sms-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    align-items: flex-end;
}

.sms-options-grid .form-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.sms-options-grid input[type="checkbox"] {
    width: auto;
}

.sms-history-table h4 {
    margin-bottom: 0.75rem;
}

.signature-input input {
    width: 100%;
}

/* Payment Method Settings Styles */
.payment-method-option {
    padding: 1.5rem;
    background: var(--bg-secondary);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
}

.payment-method-option:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.payment-method-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.payment-method-info {
    flex: 1;
}

/* Toggle Switch Styles */
.switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
}

.slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
}

input:checked + .slider {
    background-color: var(--primary-color);
}

input:focus + .slider {
    box-shadow: 0 0 1px var(--primary-color);
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.slider.round {
    border-radius: 34px;
}

.slider.round:before {
    border-radius: 50%;
}
</style>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>

