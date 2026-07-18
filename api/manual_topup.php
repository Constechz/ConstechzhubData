<?php
require_once '../config/config.php';

// Only POST JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Invalid method'], 405);
}

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$payloadRaw = file_get_contents('php://input');
$payload = json_decode($payloadRaw, true);
if (!is_array($payload)) {
    $payload = [];
}
if (!$payload && !empty($_POST)) {
    $payload = $_POST;
}
if (!$csrfHeader && isset($payload['csrf_token'])) { $csrfHeader = $payload['csrf_token']; }
if (!validateCSRF($csrfHeader)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
}

$action = $payload['action'] ?? '';
$amountInput = $payload['amount'] ?? null;
$amount = is_numeric($amountInput) ? (float)$amountInput : 0.0;
$note = trim($payload['note'] ?? '');
$reference = generateReference('TOPUP');

if ($amount === 0.0) {
    jsonResponse(['status' => 'error', 'message' => 'Amount must be a non-zero value']);
}

$current = getCurrentUser();

function sendAdminManualTopupEmail($admin, array $agent, $amount, $type, $reference, $note, $newBalance = null) {
    if (empty($admin) || empty($admin['email'])) {
        return;
    }

    require_once __DIR__ . '/../includes/email.php';

    $adminName = trim((string)($admin['full_name'] ?? 'Administrator'));
    $agentName = trim((string)($agent['full_name'] ?? 'Agent'));
    $agentEmail = trim((string)($agent['email'] ?? ''));
    $agentId = (int)($agent['id'] ?? 0);

    $amountValue = abs((float) $amount);
    $amountStr = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format($amountValue, 2);
    $balanceStr = $newBalance !== null
        ? (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format((float) $newBalance, 2)
        : 'N/A';
    $noteText = $note !== '' ? $note : 'N/A';
    $typeLabel = strtolower((string)$type) === 'debit' ? 'Debit' : 'Credit';
    $timestamp = date('M j, Y g:i A');

    $subject = 'Manual Top-up ' . $typeLabel . ' Completed - ' . $reference;
    $safeAdmin = htmlspecialchars($adminName);
    $safeAgent = htmlspecialchars($agentName);
    $safeAgentEmail = htmlspecialchars($agentEmail);
    $safeNote = htmlspecialchars($noteText);
    $safeRef = htmlspecialchars($reference);
    $safeTime = htmlspecialchars($timestamp);
    $safeAmount = htmlspecialchars($amountStr);
    $safeBalance = htmlspecialchars($balanceStr);

    $agentLine = $agentId > 0 ? "{$safeAgent} (ID #{$agentId})" : $safeAgent;
    if ($safeAgentEmail !== '') {
        $agentLine .= " &lt;{$safeAgentEmail}&gt;";
    }

    $body_html = "
        <p>Hello {$safeAdmin},</p>
        <p>Your manual wallet {$typeLabel} has been processed successfully.</p>
        <ul>
            <li><strong>Agent:</strong> {$agentLine}</li>
            <li><strong>Amount:</strong> {$safeAmount}</li>
            <li><strong>Reference:</strong> {$safeRef}</li>
            <li><strong>Note:</strong> {$safeNote}</li>
            <li><strong>Agent Balance:</strong> {$safeBalance}</li>
            <li><strong>Time:</strong> {$safeTime}</li>
        </ul>
        <p>Regards,<br>" . htmlspecialchars(getSiteName()) . "</p>
    ";

    $body_text = "Hello {$adminName},\n\n"
        . "Your manual wallet {$typeLabel} has been processed successfully.\n"
        . "Agent: {$agentName}" . ($agentId > 0 ? " (ID #{$agentId})" : '') . ($agentEmail ? " <{$agentEmail}>" : '') . "\n"
        . "Amount: {$amountStr}\n"
        . "Reference: {$reference}\n"
        . "Note: {$noteText}\n"
        . "Agent Balance: {$balanceStr}\n"
        . "Time: {$timestamp}\n\n"
        . "Regards,\n" . getSiteName();

    try {
        sendEmail($admin['email'], $subject, $body_html, $body_text, 'admin_manual_topup');
    } catch (Throwable $e) {
        error_log('Admin manual topup email failed: ' . $e->getMessage());
    }
}

try {
    if ($action === 'admin_to_agent') {
        // Admin can credit any user wallet directly
        if (!hasRole('admin')) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        $agent_id = 0;
        if (!empty($payload['agent_id'])) {
            $agent_id = (int)$payload['agent_id'];
        } elseif (!empty($payload['agent_identifier'])) {
            $agent_id = findUserIdByEmailOrPhone($payload['agent_identifier']) ?: 0;
        }
        if ($agent_id <= 0) jsonResponse(['status' => 'error', 'message' => 'Invalid user']);
        // Validate user exists
        $stmt = $db->prepare("SELECT role, full_name, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $dbError = $db->getConnection()->error ?? 'unknown database error';
            error_log('Manual topup user lookup failed: ' . $dbError);
            jsonResponse(['status' => 'error', 'message' => 'Database error while validating user. Please contact support.'], 500);
        }
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $agentRow = $stmt->get_result()->fetch_assoc();
        if (!$agentRow) {
            jsonResponse(['status' => 'error', 'message' => 'User not found']);
        }
        $agentProfile = [
            'id' => $agent_id,
            'full_name' => $agentRow['full_name'] ?? '',
            'email' => $agentRow['email'] ?? ''
        ];
        $targetRole = $agentRow['role'] ?? 'customer';
        // Limits
        $limits = getEffectiveTopupLimits($agent_id, $targetRole);
        $adjustmentType = $amount > 0 ? 'credit' : 'debit';
        $absoluteAmount = abs($amount);

        if ($absoluteAmount < (float)$limits['min'] || $absoluteAmount > (float)$limits['max']) {
            jsonResponse(['status' => 'error', 'message' => 'Amount outside allowed limits. Allowed range: ' . $limits['min'] . ' - ' . $limits['max']]);
        }

        $descBase = $adjustmentType === 'credit' ? 'Admin manual top-up' : 'Admin manual deduction';
        $desc = $descBase;
        if ($note) $desc .= ': ' . $note;

        if ($adjustmentType === 'credit') {
            $error_msg = null;
            if (updateWalletBalanceWithSMS($agent_id, $absoluteAmount, 'credit', $reference, $desc, 'manual_admin_topup', $error_msg)) {
                logActivity($current['id'], 'admin_manual_topup_user', json_encode(['user_id' => $agent_id, 'amount' => $absoluteAmount, 'type' => 'credit', 'ref' => $reference]));
                $newBalance = getWalletBalance($agent_id);
                sendAdminManualTopupEmail($current, $agentProfile, $absoluteAmount, 'credit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'User wallet credited successfully', 'reference' => $reference]);
            }
            throw new Exception($error_msg ?: 'Failed to credit user');
        } else {
            $currentBalance = getWalletBalance($agent_id);
            if ($currentBalance < $absoluteAmount) {
                jsonResponse(['status' => 'error', 'message' => 'Insufficient wallet balance. Available: ' . CURRENCY . ' ' . number_format($currentBalance, 2)]);
            }
            $error_msg = null;
            if (updateWalletBalance($agent_id, $absoluteAmount, 'debit', $reference, $desc, $error_msg)) {
                $newBalance = getWalletBalance($agent_id);
                sendWalletDebitNotification($agent_id, $absoluteAmount, $newBalance, $desc);
                logActivity($current['id'], 'admin_manual_topup_user', json_encode(['user_id' => $agent_id, 'amount' => -$absoluteAmount, 'type' => 'debit', 'ref' => $reference]));
                sendAdminManualTopupEmail($current, $agentProfile, $absoluteAmount, 'debit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'User wallet debited successfully', 'reference' => $reference]);
            }
            throw new Exception($error_msg ?: 'Failed to debit user');
        }
    } elseif ($action === 'agent_to_customer') {
        // Agent can transfer from own wallet to a linked customer
        if (!hasRole('agent')) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        $customer_id = 0;
        if (!empty($payload['customer_id'])) {
            $customer_id = (int)$payload['customer_id'];
        } elseif (!empty($payload['customer_identifier'])) {
            $customer_id = findUserIdByEmailOrPhone($payload['customer_identifier']) ?: 0;
        }
        if ($customer_id <= 0) jsonResponse(['status' => 'error', 'message' => 'Customer not found']);
        // Validate customer role
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $dbError = $db->getConnection()->error ?? 'unknown database error';
            error_log('Manual topup customer role lookup failed: ' . $dbError);
            jsonResponse(['status' => 'error', 'message' => 'Database error while validating customer. Please contact support.'], 500);
        }
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $roleRow = $stmt->get_result()->fetch_assoc();
        if (!$roleRow || $roleRow['role'] !== 'customer') {
            jsonResponse(['status' => 'error', 'message' => 'Target is not a customer']);
        }
        // Validate linkage
        if (!isCustomerLinkedToAgent($customer_id, $current['id'])) {
            jsonResponse(['status' => 'error', 'message' => 'Customer is not linked to you']);
        }
        // Limits based on customer effective min/max
        $limits = getEffectiveTopupLimits($customer_id, 'customer');
        $absoluteAmount = abs($amount);
        if ($absoluteAmount < (float)$limits['min'] || $absoluteAmount > (float)$limits['max']) {
            jsonResponse(['status' => 'error', 'message' => 'Amount outside allowed limits. Allowed range: ' . $limits['min'] . ' - ' . $limits['max']]);
        }

        $adjustmentType = $amount > 0 ? 'credit' : 'debit';

        if ($adjustmentType === 'credit') {
            // Ensure agent has enough balance before transfer
            $agentBalance = getWalletBalance($current['id']);
            if ($agentBalance < $absoluteAmount) {
                jsonResponse(['status' => 'error', 'message' => 'Insufficient wallet balance. Available: ' . CURRENCY . ' ' . number_format($agentBalance, 2)]);
            }

            $desc = 'Agent manual top-up to customer #' . $customer_id;
            if ($note) {
                $desc .= ': ' . $note;
            }

            $ok = transferWalletBalance($current['id'], $customer_id, $absoluteAmount, $reference, $desc);
            if (!$ok) {
                throw new Exception('Transfer failed');
            }

            // Notify customer via SMS/email
            $newBalance = getWalletBalance($customer_id);
            sendWalletCreditNotification($customer_id, $absoluteAmount, $newBalance, $desc, 'manual_agent_topup');

            logActivity($current['id'], 'agent_manual_topup_customer', json_encode(['customer_id' => $customer_id, 'amount' => $absoluteAmount, 'type' => 'credit', 'ref' => $reference]));
            jsonResponse(['status' => 'success', 'message' => 'Customer wallet credited', 'reference' => $reference]);
        } else {
            // Deduct from customer and return to agent
            $customerBalance = getWalletBalance($customer_id);
            if ($customerBalance < $absoluteAmount) {
                jsonResponse(['status' => 'error', 'message' => 'Customer has insufficient balance. Available: ' . CURRENCY . ' ' . number_format($customerBalance, 2)]);
            }

            $desc = 'Agent manual deduction from customer #' . $customer_id;
            if ($note) {
                $desc .= ': ' . $note;
            }

            $ok = transferWalletBalance($customer_id, $current['id'], $absoluteAmount, $reference, $desc);
            if (!$ok) {
                throw new Exception('Deduction failed');
            }

            // Notify customer of deduction
            $newBalance = getWalletBalance($customer_id);
            sendWalletDebitNotification($customer_id, $absoluteAmount, $newBalance, $desc);

            logActivity($current['id'], 'agent_manual_topup_customer', json_encode(['customer_id' => $customer_id, 'amount' => -$absoluteAmount, 'type' => 'debit', 'ref' => $reference]));
            jsonResponse(['status' => 'success', 'message' => 'Customer wallet debited', 'reference' => $reference]);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
