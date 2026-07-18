<?php
require_once '../config/config.php';
require_once '../includes/email.php';
require_once '../includes/mnotify_sms.php';

if (function_exists('ensureTopupSettingsTable')) {
    ensureTopupSettingsTable();
}
if (function_exists('ensureTopupRequestTables')) {
    ensureTopupRequestTables();
}

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$payload = $method === 'POST' ? (json_decode($raw, true) ?: []) : $_GET;
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if ($method === 'POST') {
    if (!$csrfHeader && isset($payload['csrf_token'])) { 
        $csrfHeader = $payload['csrf_token']; 
    }
    if (!validateCSRF($csrfHeader)) { 
        jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419); 
    }
}

$action = $payload['action'] ?? '';
$current = getCurrentUser();

function generateRequestId() {
    return 'TR' . date('Ymd') . mt_rand(10000, 99999);
}

function getPaymentDetails($targetType, $targetAgentId = null) {
    global $db;
    
    if ($targetType === 'admin') {
        // Get admin payment details from topup_settings table
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM topup_settings WHERE user_id IS NULL AND setting_key IN ('admin_topup_account_network', 'admin_topup_account_name', 'admin_topup_account_number', 'admin_topup_instructions')");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $details = [];
        while ($row = $result->fetch_assoc()) {
            $key = str_replace('admin_topup_account_', '', $row['setting_key']);
            $details[$key] = $row['setting_value'];
        }
        
        return [
            'network' => $details['network'] ?? 'MTN MOMO',
            'wallet_name' => $details['name'] ?? 'Constechzhub Admin',
            'wallet_number' => $details['number'] ?? '0245152060',
            'instructions' => $details['admin_topup_instructions'] ?? 'Please send payment to the account details above and submit the topup request form.'
        ];
    } else {
        // Get agent payment details from topup_settings or user table
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM topup_settings WHERE user_id = ? AND setting_key IN ('agent_topup_account_network', 'agent_topup_account_name', 'agent_topup_account_number', 'agent_topup_instructions')");
        $stmt->bind_param('i', $targetAgentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $agentSettings = [];
        while ($row = $result->fetch_assoc()) {
            $key = str_replace('agent_topup_account_', '', $row['setting_key']);
            $agentSettings[$key] = $row['setting_value'];
        }
        
        // If no custom settings, fall back to user profile info
        if (empty($agentSettings)) {
            $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ? AND role = 'agent'");
            $stmt->bind_param('i', $targetAgentId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($agent = $result->fetch_assoc()) {
                return [
                    'network' => 'MTN MOMO',
                    'wallet_name' => $agent['full_name'],
                    'wallet_number' => $agent['phone'],
                    'instructions' => 'Please send payment to the account details above and submit the topup request form.'
                ];
            }
        } else {
            return [
                'network' => $agentSettings['network'] ?? 'MTN MOMO',
                'wallet_name' => $agentSettings['name'] ?? 'Agent Account',
                'wallet_number' => $agentSettings['number'] ?? 'N/A',
                'instructions' => $agentSettings['agent_topup_instructions'] ?? 'Please send payment to the account details above and submit the topup request form.'
            ];
        }
        
        return null;
    }
}

function sendTopupRequestNotification($requestId, $recipientEmail, $recipientPhone, $requestData) {
    global $db;
    
    $emailSent = false;
    $smsSent = false;
    
    // Email notification
    $subject = "New Topup Request - " . $requestData['request_id'];
    $message = "
        <h3>New Topup Request Received</h3>
        <p><strong>Request ID:</strong> {$requestData['request_id']}</p>
        <p><strong>Amount:</strong> \${$requestData['amount']}</p>
        <p><strong>From:</strong> {$requestData['user_email']}</p>
        <p><strong>Payment Details:</strong></p>
        <ul>
            <li>Network: {$requestData['network']}</li>
            <li>Wallet Name: {$requestData['wallet_name']}</li>
            <li>Wallet Number: {$requestData['wallet_number']}</li>
        </ul>
        <p>Please review and process this request.</p>
    ";
    
    try {
        if (sendEmail($recipientEmail, $subject, $message)) {
            $emailSent = true;
        }
    } catch (Exception $e) {
        error_log("Email notification failed: " . $e->getMessage());
    }
    
    // SMS notification (if enabled)
    try {
        // Check if SMS is enabled for topup notifications (mNotify with legacy fallback)
        $smsProviderEnabled = getSMSSetting('mnotify_enabled', getSMSSetting('kivalo_enabled', '0')) === '1';
        $smsEnabled = $smsProviderEnabled &&
                      getSMSSetting('sms_notifications_enabled', '0') === '1' && 
                      getSMSSetting('sms_notification_topup_request', '1') === '1';
        
        if ($smsEnabled && !empty($recipientPhone)) {
            $smsMessage = "New topup request #{$requestData['request_id']} for \${$requestData['amount']} from {$requestData['user_email']}. Please review and process. - " . SITE_NAME;
            
            $smsResult = sendSMS($recipientPhone, $smsMessage, 'general');
            if ($smsResult['success']) {
                $smsSent = true;
            }
        }
    } catch (Exception $e) {
        error_log("SMS notification failed: " . $e->getMessage());
    }
    
    // Log notification attempts
    $stmt = $db->prepare("INSERT INTO topup_request_notifications (request_id, notification_type, recipient_email, recipient_phone, status, sms_sent, error_message) VALUES (?, 'email', ?, ?, ?, ?, ?)");
    $emailStatus = $emailSent ? 'sent' : 'failed';
    $errorMsg = $emailSent ? '' : 'Failed to send email';
    $smsSentInt = $smsSent ? 1 : 0; // Convert boolean to integer
    $stmt->bind_param('ssssis', $requestId, $recipientEmail, $recipientPhone, $emailStatus, $smsSentInt, $errorMsg);
    $stmt->execute();
    
    return ['email_sent' => $emailSent, 'sms_sent' => $smsSent];
}

try {
    if ($action === 'submit_request' && $method === 'POST') {
        $amount = floatval($payload['amount'] ?? 0);
        $userEmail = trim($payload['user_email'] ?? '');
        
        if ($amount <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid amount']);
        }
        
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid email address']);
        }
        
        // Determine target based on user role
        $targetType = 'admin';
        $targetAgentId = null;
        
        if ($current['role'] === 'customer') {
            $agentId = getUserAgentId($current['id']);
            if ($agentId) {
                $targetType = 'agent';
                $targetAgentId = $agentId;
            }
        }
        
        // Get payment details
        $paymentDetails = getPaymentDetails($targetType, $targetAgentId);
        if (!$paymentDetails) {
            jsonResponse(['status' => 'error', 'message' => 'Payment details not available']);
        }
        
        // Generate unique request ID
        $requestId = generateRequestId();
        
        // Insert topup request
        $stmt = $db->prepare("INSERT INTO topup_requests (request_id, requester_id, requester_type, target_type, target_agent_id, amount, user_email, network, wallet_name, wallet_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $requesterType = $current['role'];
        $stmt->bind_param('sissdsssss', 
            $requestId,
            $current['id'], 
            $requesterType,
            $targetType,
            $targetAgentId,
            $amount,
            $userEmail,
            $paymentDetails['network'],
            $paymentDetails['wallet_name'],
            $paymentDetails['wallet_number']
        );
        
        $stmt->execute();
        $insertedId = $db->getConnection()->insert_id;
        
        // Send notifications
        $recipientEmail = '';
        $recipientPhone = '';
        
        if ($targetType === 'admin') {
            $adminStmt = $db->prepare("SELECT email, phone FROM users WHERE role = 'admin' LIMIT 1");
            $adminStmt->execute();
            $admin = $adminStmt->get_result()->fetch_assoc();
            $recipientEmail = !empty($admin['email']) ? $admin['email'] : '';
            $recipientPhone = $admin['phone'] ?? '';

            // Fallback to configured admin email if no admin user email found
            if (empty($recipientEmail) && defined('ADMIN_EMAIL')) {
                $recipientEmail = ADMIN_EMAIL;
            }
        } else {
            $agentStmt = $db->prepare("SELECT email, phone FROM users WHERE id = ?");
            $agentStmt->bind_param('i', $targetAgentId);
            $agentStmt->execute();
            $agent = $agentStmt->get_result()->fetch_assoc();
            $recipientEmail = $agent['email'] ?? '';
            $recipientPhone = $agent['phone'] ?? '';
        }
        
        $requestData = [
            'request_id' => $requestId,
            'amount' => $amount,
            'user_email' => $userEmail,
            'network' => $paymentDetails['network'],
            'wallet_name' => $paymentDetails['wallet_name'],
            'wallet_number' => $paymentDetails['wallet_number']
        ];
        
        sendTopupRequestNotification($requestId, $recipientEmail, $recipientPhone, $requestData);
        
        // Log activity
        logActivity($current['id'], 'topup_request_submitted', json_encode(['request_id' => $requestId, 'amount' => $amount]));
        
        jsonResponse([
            'status' => 'success', 
            'message' => 'Topup request submitted successfully',
            'request_id' => $requestId,
            'payment_details' => $paymentDetails
        ]);
        
    } elseif ($action === 'get_payment_details') {
        $targetType = 'admin';
        $targetAgentId = null;
        
        if ($current['role'] === 'customer') {
            $agentId = getUserAgentId($current['id']);
            if ($agentId) {
                $targetType = 'agent';
                $targetAgentId = $agentId;
            }
        }
        
        $paymentDetails = getPaymentDetails($targetType, $targetAgentId);
        
        if ($paymentDetails) {
            jsonResponse(['status' => 'success', 'payment_details' => $paymentDetails]);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Payment details not available']);
        }
        
    } elseif ($action === 'get_requests') {
        $page = max(1, intval($payload['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        if ($current['role'] === 'admin') {
            // Admin sees all requests
            $stmt = $db->prepare("SELECT tr.*, u.full_name as requester_name FROM topup_requests tr JOIN users u ON tr.requester_id = u.id ORDER BY tr.created_at DESC LIMIT ? OFFSET ?");
            $stmt->bind_param('ii', $limit, $offset);
        } elseif ($current['role'] === 'agent') {
            // Agent sees requests assigned to them and their own requests
            $stmt = $db->prepare("SELECT tr.*, u.full_name as requester_name FROM topup_requests tr JOIN users u ON tr.requester_id = u.id WHERE tr.target_agent_id = ? OR tr.requester_id = ? ORDER BY tr.created_at DESC LIMIT ? OFFSET ?");
            $stmt->bind_param('iiii', $current['id'], $current['id'], $limit, $offset);
        } else {
            // Customer sees only their own requests
            $stmt = $db->prepare("SELECT tr.*, u.full_name as requester_name FROM topup_requests tr JOIN users u ON tr.requester_id = u.id WHERE tr.requester_id = ? ORDER BY tr.created_at DESC LIMIT ? OFFSET ?");
            $stmt->bind_param('iii', $current['id'], $limit, $offset);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $requests = [];
        
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        
        jsonResponse(['status' => 'success', 'requests' => $requests]);
        
    } elseif ($action === 'process_request' && $method === 'POST') {
        $requestId = intval($payload['request_id'] ?? 0);
        $status = $payload['status'] ?? '';
        $notes = trim($payload['notes'] ?? '');
        
        if (!in_array($status, ['approved', 'rejected'])) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid status']);
        }
        
        // Check if user can process this request
        $stmt = $db->prepare("SELECT * FROM topup_requests WHERE id = ?");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        
        if (!$request) {
            jsonResponse(['status' => 'error', 'message' => 'Request not found']);
        }
        
        $canProcess = false;
        if ($current['role'] === 'admin' && $request['target_type'] === 'admin') {
            $canProcess = true;
        } elseif ($current['role'] === 'agent' && $request['target_type'] === 'agent' && $request['target_agent_id'] == $current['id']) {
            $canProcess = true;
        }
        
        if (!$canProcess) {
            jsonResponse(['status' => 'error', 'message' => 'Unauthorized to process this request']);
        }
        
        // Update request
        $stmt = $db->prepare("UPDATE topup_requests SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssii', $status, $notes, $current['id'], $requestId);
        $stmt->execute();
        
        // If approved, add balance to requester
        if ($status === 'approved') {
            $wallet_update_success = updateWalletBalanceWithSMS(
                $request['requester_id'], 
                $request['amount'], 
                'credit', 
                'API_TOPUP_REQ_' . $request['request_id'], 
                'Topup Request Approved - Request ID: ' . $request['request_id'],
                'topup_request'
            );
            
            if ($wallet_update_success) {
                // Log transaction - Check if logTransaction function exists, use logActivity as fallback
                if (function_exists('logTransaction')) {
                    logTransaction($request['requester_id'], 'credit', $request['amount'], 'Topup Request Approved - ' . $request['request_id']);
                } else {
                    logActivity($request['requester_id'], 'wallet_credit', 'Topup Request Approved - Amount: ' . CURRENCY . $request['amount'] . ' - Request ID: ' . $request['request_id']);
                }
            } else {
                error_log("Wallet update failed for approved API topup request: " . $request['request_id']);
            }
        }
        
        // Log activity
        logActivity($current['id'], 'topup_request_processed', json_encode(['request_id' => $request['request_id'], 'status' => $status]));
        
        jsonResponse(['status' => 'success', 'message' => 'Request processed successfully']);
        
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action or method'], 400);
    }
    
} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
?>
