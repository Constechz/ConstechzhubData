<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';
require_once '../includes/commission.php';

ensureProfitWithdrawalTables();
ensureCommissionPayoutTables();

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

if (!verifyPaystackWebhookSignature($payload, $signature)) {
    http_response_code(401);
    echo 'Invalid signature';
    exit();
}

$event = json_decode((string) $payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit();
}

$event_name = strtolower(trim((string) ($event['event'] ?? '')));
if (!in_array($event_name, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
    http_response_code(200);
    echo 'Ignored';
    exit();
}

$transfer = $event['data'] ?? [];
$provider_reference = trim((string) ($transfer['reference'] ?? ''));
$transfer_code = trim((string) ($transfer['transfer_code'] ?? ''));

if ($provider_reference === '' && $transfer_code === '') {
    http_response_code(200);
    echo 'Missing transfer reference';
    exit();
}

$conditions = [];
$types = '';
$params = [];
if ($provider_reference !== '') {
    $conditions[] = 'provider_reference = ?';
    $types .= 's';
    $params[] = $provider_reference;
}
if ($transfer_code !== '') {
    $conditions[] = 'provider_transfer_code = ?';
    $types .= 's';
    $params[] = $transfer_code;
}

$sql = "
    SELECT *
    FROM profit_withdrawals
    WHERE payout_provider = 'paystack'
      AND (" . implode(' OR ', $conditions) . ")
    ORDER BY id DESC
    LIMIT 1
";
$stmt = $db->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo 'Query failed';
    exit();
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$withdrawal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($withdrawal) {
    $withdrawal_id = (int) ($withdrawal['id'] ?? 0);
    $agent_id = (int) ($withdrawal['agent_id'] ?? 0);
    $reference = (string) ($withdrawal['reference'] ?? '');
    $total_debit = round((float) ($withdrawal['total_debit'] ?? $withdrawal['amount'] ?? 0), 2);
    $amount = round((float) ($withdrawal['amount'] ?? 0), 2);
    $fee_amount = round((float) ($withdrawal['fee_amount'] ?? 0), 2);
    $net_payout = round(max(0, $amount - $fee_amount), 2);
    $current_status = strtolower(trim((string) ($withdrawal['status'] ?? 'pending')));
    $mapped_status = $event_name === 'transfer.success' ? 'paid' : ($event_name === 'transfer.reversed' ? 'reversed' : 'failed');
    $provider_status = str_replace('transfer.', '', $event_name);
    $admin_notes = trim((string) ($withdrawal['admin_notes'] ?? ''));
    $event_note = '[Paystack ' . strtoupper($provider_status) . ' on ' . date('Y-m-d H:i:s') . ']';
    if ($transfer_code !== '') {
        $event_note .= ' Transfer code: ' . $transfer_code;
    }
    if ($provider_reference !== '') {
        $event_note .= ' Ref: ' . $provider_reference;
    }
    $updated_notes = trim($admin_notes . PHP_EOL . $event_note);
    $stmt = $db->prepare("
        UPDATE profit_withdrawals
        SET status = ?, provider_status = ?, provider_response = ?, admin_notes = ?, processed_at = IFNULL(processed_at, NOW())
        WHERE id = ?
    ");
    if (!$stmt) {
        http_response_code(500);
        echo 'Update failed';
        exit();
    }
    $provider_payload = $payload;
    $stmt->bind_param('ssssi', $mapped_status, $provider_status, $provider_payload, $updated_notes, $withdrawal_id);
    $stmt->execute();
    $stmt->close();

    if ($mapped_status === 'paid' && $current_status !== 'paid' && $agent_id > 0) {
        $phone_column = function_exists('dbh_get_users_phone_column') ? dbh_get_users_phone_column() : 'phone';
        $phone_select = $phone_column !== '' ? $phone_column : 'phone';
        $agent_stmt = $db->prepare("SELECT full_name, {$phone_select} AS phone FROM users WHERE id = ? LIMIT 1");
        if ($agent_stmt) {
            $agent_stmt->bind_param('i', $agent_id);
            if ($agent_stmt->execute()) {
                $agent = $agent_stmt->get_result()->fetch_assoc();
                $agent_phone = $agent['phone'] ?? '';
                $agent_name = $agent['full_name'] ?? 'Agent';
                if ($agent_phone && isSMSFeatureEnabled()) {
                    $amount_str = CURRENCY . number_format($amount, 2);
                    $fee_str = CURRENCY . number_format($fee_amount, 2);
                    $total_str = CURRENCY . number_format($total_debit, 2);
                    $net_str = CURRENCY . number_format($net_payout, 2);
                    $smsMessage = "Hi {$agent_name}, your profit withdrawal of {$amount_str} has been paid.";
                    if ($fee_amount > 0) {
                        $smsMessage .= " Processing fee {$fee_str} applied.";
                    }
                    $smsMessage .= " You received {$net_str}. Store profit withdrawn: {$total_str}. Ref: {$reference}. - " . SITE_NAME;
                    try {
                        sendSMS(formatPhone($agent_phone), $smsMessage, 'profit_withdrawal', $agent_id);
                    } catch (Exception $e) {
                        error_log('Paystack payout SMS failed: ' . $e->getMessage());
                    }
                }
            }
            $agent_stmt->close();
        }
    }

    http_response_code(200);
    echo 'OK';
    exit();
}

$payoutSql = "
    SELECT *
    FROM commission_payouts
    WHERE payout_provider = 'paystack'
      AND (" . implode(' OR ', $conditions) . ")
    ORDER BY id DESC
    LIMIT 1
";
$stmt = $db->prepare($payoutSql);
if (!$stmt) {
    http_response_code(500);
    echo 'Query failed';
    exit();
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$commissionPayout = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$commissionPayout) {
    http_response_code(200);
    echo 'No matching payout';
    exit();
}

$payout_id = (int) ($commissionPayout['id'] ?? 0);
$liquidation_id = (int) ($commissionPayout['liquidation_id'] ?? 0);
$processed_by = (int) ($commissionPayout['processed_by'] ?? 0);
$current_status = strtolower(trim((string) ($commissionPayout['status'] ?? 'processing')));
$mapped_status = $event_name === 'transfer.success' ? 'completed' : 'failed';
$provider_status = str_replace('transfer.', '', $event_name);
$notes = trim((string) ($commissionPayout['notes'] ?? ''));
$event_note = '[Paystack ' . strtoupper($provider_status) . ' on ' . date('Y-m-d H:i:s') . ']';
if ($transfer_code !== '') {
    $event_note .= ' Transfer code: ' . $transfer_code;
}
if ($provider_reference !== '') {
    $event_note .= ' Ref: ' . $provider_reference;
}
$updated_notes = trim($notes . PHP_EOL . $event_note);

$stmt = $db->prepare("
    UPDATE commission_payouts
    SET status = ?, provider_status = ?, provider_response = ?, notes = ?, processed_at = IFNULL(processed_at, NOW())
    WHERE id = ?
");
if (!$stmt) {
    http_response_code(500);
    echo 'Update failed';
    exit();
}
$provider_payload = $payload;
$stmt->bind_param('ssssi', $mapped_status, $provider_status, $provider_payload, $updated_notes, $payout_id);
$stmt->execute();
$stmt->close();

if ($liquidation_id > 0) {
    if ($mapped_status === 'completed' && $current_status !== 'completed') {
        processCommissionLiquidation($liquidation_id, $processed_by, 'completed', 'Paystack webhook confirmed commission payout.');
    } elseif ($mapped_status === 'failed') {
        updateCommissionLiquidationStatus($liquidation_id, 'failed', $processed_by > 0 ? $processed_by : null, 'Paystack webhook reported ' . $provider_status . '.');
    }
}

http_response_code(200);
echo 'OK';
