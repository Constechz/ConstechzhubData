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

$current = getCurrentUser();

if ($action === 'admin_search_users') {
    if (!hasRole('admin')) {
        jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }

    $query = trim((string)($payload['query'] ?? ''));
    $role = strtolower(trim((string)($payload['role'] ?? 'agent')));
    if (!in_array($role, ['agent', 'customer'], true)) {
        $role = 'agent';
    }

    if ($query === '' || strlen($query) < 2) {
        jsonResponse(['status' => 'success', 'users' => []]);
    }

    $phoneColumn = dbh_get_users_phone_column();
    $phoneSelect = $phoneColumn !== '' ? "u.`{$phoneColumn}` AS phone" : "'' AS phone";
    $phoneLikeClause = $phoneColumn !== '' ? " OR u.`{$phoneColumn}` LIKE ?" : "";

    $statusClause = '';
    if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'status')) {
        $statusClause = " AND u.status = 'active'";
    }

    $sql = "
        SELECT u.id, u.full_name, u.email, u.username, {$phoneSelect}, u.role
        FROM users u
        WHERE u.role = ?
          {$statusClause}
          AND (
              u.full_name LIKE ?
              OR u.email LIKE ?
              OR u.username LIKE ?
              {$phoneLikeClause}
          )
        ORDER BY u.full_name ASC, u.id DESC
        LIMIT 20
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        jsonResponse(['status' => 'error', 'message' => 'Search is temporarily unavailable.'], 500);
    }

    $like = '%' . $query . '%';
    if ($phoneColumn !== '') {
        $stmt->bind_param('sssss', $role, $like, $like, $like, $like);
    } else {
        $stmt->bind_param('ssss', $role, $like, $like, $like);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    jsonResponse(['status' => 'success', 'users' => $rows]);
}

$amountRequiredActions = ['admin_to_agent', 'admin_to_customer', 'agent_to_customer'];
if (in_array($action, $amountRequiredActions, true) && $amount === 0.0) {
    jsonResponse(['status' => 'error', 'message' => 'Amount must be a non-zero value']);
}

function sendAdminManualTopupEmail($admin, array $targetUser, $targetRole, $amount, $type, $reference, $note, $newBalance = null) {
    if (empty($admin) || empty($admin['email'])) {
        return;
    }

    require_once __DIR__ . '/../includes/email.php';

    $adminName = trim((string)($admin['full_name'] ?? 'Administrator'));
    $targetRole = strtolower(trim((string)$targetRole)) === 'customer' ? 'Customer' : 'Agent';
    $targetName = trim((string)($targetUser['full_name'] ?? $targetRole));
    $targetEmail = trim((string)($targetUser['email'] ?? ''));
    $targetId = (int)($targetUser['id'] ?? 0);

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
    $safeNote = htmlspecialchars($noteText);
    $safeRef = htmlspecialchars($reference);
    $safeTime = htmlspecialchars($timestamp);
    $safeAmount = htmlspecialchars($amountStr);
    $safeBalance = htmlspecialchars($balanceStr);

    $safeTarget = htmlspecialchars($targetName);
    $safeTargetEmail = htmlspecialchars($targetEmail);

    $targetLine = $targetId > 0 ? "{$safeTarget} (ID #{$targetId})" : $safeTarget;
    if ($safeTargetEmail !== '') {
        $targetLine .= " &lt;{$safeTargetEmail}&gt;";
    }

    $body_html = "
        <p>Hello {$safeAdmin},</p>
        <p>Your manual wallet {$typeLabel} has been processed successfully.</p>
        <ul>
            <li><strong>{$targetRole}:</strong> {$targetLine}</li>
            <li><strong>Amount:</strong> {$safeAmount}</li>
            <li><strong>Reference:</strong> {$safeRef}</li>
            <li><strong>Note:</strong> {$safeNote}</li>
            <li><strong>{$targetRole} Balance:</strong> {$safeBalance}</li>
            <li><strong>Time:</strong> {$safeTime}</li>
        </ul>
        <p>Regards,<br>" . htmlspecialchars(getSiteName()) . "</p>
    ";

    $body_text = "Hello {$adminName},\n\n"
        . "Your manual wallet {$typeLabel} has been processed successfully.\n"
        . "{$targetRole}: {$targetName}" . ($targetId > 0 ? " (ID #{$targetId})" : '') . ($targetEmail ? " <{$targetEmail}>" : '') . "\n"
        . "Amount: {$amountStr}\n"
        . "Reference: {$reference}\n"
        . "Note: {$noteText}\n"
        . "{$targetRole} Balance: {$balanceStr}\n"
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
        // Admin can credit agent wallet directly
        if (!hasRole('admin')) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        $agent_id = 0;
        if (!empty($payload['agent_id'])) {
            $agent_id = (int)$payload['agent_id'];
        } elseif (!empty($payload['agent_identifier'])) {
            $agent_id = findUserIdByEmailOrPhone($payload['agent_identifier']) ?: 0;
        }
        if ($agent_id <= 0) jsonResponse(['status' => 'error', 'message' => 'Invalid agent']);
        // Validate agent role
        $stmt = $db->prepare("SELECT role, full_name, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $dbError = $db->getConnection()->error ?? 'unknown database error';
            error_log('Manual topup role lookup failed: ' . $dbError);
            jsonResponse(['status' => 'error', 'message' => 'Database error while validating agent. Please contact support.'], 500);
        }
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $agentRow = $stmt->get_result()->fetch_assoc();
        if (!$agentRow || $agentRow['role'] !== 'agent') {
            jsonResponse(['status' => 'error', 'message' => 'User is not an agent']);
        }
        $agentProfile = [
            'id' => $agent_id,
            'full_name' => $agentRow['full_name'] ?? '',
            'email' => $agentRow['email'] ?? ''
        ];
        // Limits
        $limits = getEffectiveTopupLimits($agent_id, 'agent');
        $adjustmentType = $amount > 0 ? 'credit' : 'debit';
        $absoluteAmount = abs($amount);

        if ($absoluteAmount < (float)$limits['min'] || $absoluteAmount > (float)$limits['max']) {
            jsonResponse(['status' => 'error', 'message' => 'Amount outside allowed limits. Allowed range: ' . $limits['min'] . ' - ' . $limits['max']]);
        }

        $descBase = $adjustmentType === 'credit' ? 'Admin manual top-up' : 'Admin manual deduction';
        $desc = $descBase;
        if ($note) $desc .= ': ' . $note;

        if ($adjustmentType === 'credit') {
            if (updateWalletBalanceWithSMS($agent_id, $absoluteAmount, 'credit', $reference, $desc, 'manual_admin_topup')) {
                logActivity($current['id'], 'admin_manual_topup_agent', json_encode(['agent_id' => $agent_id, 'amount' => $absoluteAmount, 'type' => 'credit', 'ref' => $reference]));
                $newBalance = getWalletBalance($agent_id);
                sendAdminManualTopupEmail($current, $agentProfile, 'agent', $absoluteAmount, 'credit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'Agent wallet credited', 'reference' => $reference]);
            }
            throw new Exception('Failed to credit agent');
        } else {
            $currentBalance = getWalletBalance($agent_id);
            if ($currentBalance < $absoluteAmount) {
                jsonResponse(['status' => 'error', 'message' => 'Insufficient wallet balance. Available: ' . CURRENCY . ' ' . number_format($currentBalance, 2)]);
            }
            if (updateWalletBalance($agent_id, $absoluteAmount, 'debit', $reference, $desc)) {
                $newBalance = getWalletBalance($agent_id);
                sendWalletDebitNotification($agent_id, $absoluteAmount, $newBalance, $desc);
                logActivity($current['id'], 'admin_manual_topup_agent', json_encode(['agent_id' => $agent_id, 'amount' => -$absoluteAmount, 'type' => 'debit', 'ref' => $reference]));
                sendAdminManualTopupEmail($current, $agentProfile, 'agent', $absoluteAmount, 'debit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'Agent wallet debited', 'reference' => $reference]);
            }
            throw new Exception('Failed to debit agent');
        }
    } elseif ($action === 'admin_to_customer') {
        // Admin can credit/debit customer wallet directly
        if (!hasRole('admin')) {
            jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        $customer_id = 0;
        if (!empty($payload['customer_id'])) {
            $customer_id = (int)$payload['customer_id'];
        } elseif (!empty($payload['customer_identifier'])) {
            $customer_id = findUserIdByEmailOrPhone($payload['customer_identifier']) ?: 0;
        }
        if ($customer_id <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid customer']);
        }

        $stmt = $db->prepare("SELECT role, full_name, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $dbError = $db->getConnection()->error ?? 'unknown database error';
            error_log('Manual topup customer lookup failed: ' . $dbError);
            jsonResponse(['status' => 'error', 'message' => 'Database error while validating customer. Please contact support.'], 500);
        }
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        $customerRow = $stmt->get_result()->fetch_assoc();
        if (!$customerRow || $customerRow['role'] !== 'customer') {
            jsonResponse(['status' => 'error', 'message' => 'User is not a customer']);
        }
        $customerProfile = [
            'id' => $customer_id,
            'full_name' => $customerRow['full_name'] ?? '',
            'email' => $customerRow['email'] ?? ''
        ];

        $limits = getEffectiveTopupLimits($customer_id, 'customer');
        $adjustmentType = $amount > 0 ? 'credit' : 'debit';
        $absoluteAmount = abs($amount);

        if ($absoluteAmount < (float)$limits['min'] || $absoluteAmount > (float)$limits['max']) {
            jsonResponse(['status' => 'error', 'message' => 'Amount outside allowed limits. Allowed range: ' . $limits['min'] . ' - ' . $limits['max']]);
        }

        $descBase = $adjustmentType === 'credit' ? 'Admin manual top-up' : 'Admin manual deduction';
        $desc = $descBase;
        if ($note) {
            $desc .= ': ' . $note;
        }

        if ($adjustmentType === 'credit') {
            if (updateWalletBalanceWithSMS($customer_id, $absoluteAmount, 'credit', $reference, $desc, 'manual_admin_topup')) {
                logActivity($current['id'], 'admin_manual_topup_customer', json_encode(['customer_id' => $customer_id, 'amount' => $absoluteAmount, 'type' => 'credit', 'ref' => $reference]));
                $newBalance = getWalletBalance($customer_id);
                sendAdminManualTopupEmail($current, $customerProfile, 'customer', $absoluteAmount, 'credit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'Customer wallet credited', 'reference' => $reference]);
            }
            throw new Exception('Failed to credit customer');
        } else {
            $currentBalance = getWalletBalance($customer_id);
            if ($currentBalance < $absoluteAmount) {
                jsonResponse(['status' => 'error', 'message' => 'Insufficient wallet balance. Available: ' . CURRENCY . ' ' . number_format($currentBalance, 2)]);
            }
            if (updateWalletBalance($customer_id, $absoluteAmount, 'debit', $reference, $desc)) {
                $newBalance = getWalletBalance($customer_id);
                sendWalletDebitNotification($customer_id, $absoluteAmount, $newBalance, $desc);
                logActivity($current['id'], 'admin_manual_topup_customer', json_encode(['customer_id' => $customer_id, 'amount' => -$absoluteAmount, 'type' => 'debit', 'ref' => $reference]));
                sendAdminManualTopupEmail($current, $customerProfile, 'customer', $absoluteAmount, 'debit', $reference, $note, $newBalance);
                jsonResponse(['status' => 'success', 'message' => 'Customer wallet debited', 'reference' => $reference]);
            }
            throw new Exception('Failed to debit customer');
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

            $ok = transferWalletBalance($current['id'], $customer_id, $absoluteAmount, $reference, $desc, $current['id'], $customer_id);
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

            $ok = transferWalletBalance($customer_id, $current['id'], $absoluteAmount, $reference, $desc, $current['id'], $customer_id);
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
