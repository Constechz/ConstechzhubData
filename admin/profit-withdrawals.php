<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';

// Require admin role
requireRole('admin');

ensureProfitWithdrawalTables();

$current_user = getCurrentUser();
$error = '';
$success = '';
$moolre_payout_url = trim((string) dbh_env('MOOLRE_PAYOUT_URL', defined('MOOLRE_PAYOUT_URL') ? MOOLRE_PAYOUT_URL : ''));
$moolre_config = getMoolreConfig();
$moolre_payout_auto = $moolre_payout_url !== '' && isMoolreConfigured($moolre_config);
$paystack_transfer_configured = function_exists('getPaystackTransferSecretKey') && getPaystackTransferSecretKey() !== '';
$paystack_transfer_otp_disabled = function_exists('isPaystackTransferOtpDisabled') && isPaystackTransferOtpDisabled();
$paystack_payout_auto = function_exists('isPaystackTransferAutomationAvailable') && isPaystackTransferAutomationAvailable();
$available_payout_routes = [
    'manual' => 'Manual payout',
];
if ($paystack_transfer_configured) {
    $available_payout_routes['paystack_manual'] = 'Manual Paystack';
}
if ($paystack_payout_auto) {
    $available_payout_routes['paystack_auto'] = 'Automatic Paystack';
}
if ($moolre_payout_auto) {
    $available_payout_routes['moolre_auto'] = 'Automatic Moolre';
}

$default_payout_route = 'manual';
if ($paystack_payout_auto) {
    $default_payout_route = 'paystack_auto';
} elseif ($moolre_payout_auto) {
    $default_payout_route = 'moolre_auto';
} elseif ($paystack_transfer_configured) {
    $default_payout_route = 'paystack_manual';
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $withdrawal_id = (int) ($_POST['withdrawal_id'] ?? 0);
        $admin_notes = sanitize($_POST['admin_notes'] ?? '');
        $payout_route = trim((string) ($_POST['payout_route'] ?? $default_payout_route));

        if ($withdrawal_id <= 0) {
            $error = 'Invalid withdrawal request.';
        } elseif (!in_array($action, ['approve', 'reject', 'verify_status'], true)) {
            $error = 'Invalid action.';
        } elseif ($action === 'approve' && !array_key_exists($payout_route, $available_payout_routes)) {
            $error = 'Invalid payout route selected.';
        } else {
            // Fetch withdrawal details
            $stmt = $db->prepare("SELECT * FROM profit_withdrawals WHERE id = ? LIMIT 1");
            if (!$stmt) {
                $error = 'Failed to load withdrawal request.';
            } else {
                $stmt->bind_param('i', $withdrawal_id);
                $stmt->execute();
                $withdrawal = $stmt->get_result()->fetch_assoc();

                if (!$withdrawal) {
                    $error = 'Request not found.';
                } elseif ($action === 'verify_status') {
                    $ref = (string) ($withdrawal['reference'] ?? '');
                    $payout_provider = (string) ($withdrawal['payout_provider'] ?? '');
                    $current_status = (string) ($withdrawal['status'] ?? '');
                    
                    if ($current_status !== 'processing') {
                        $error = 'Only processing withdrawals can be verified.';
                    } elseif ($payout_provider !== 'paystack') {
                        $error = 'Verification is only supported for Paystack automated transfers.';
                    } elseif ($ref === '') {
                        $error = 'Missing withdrawal reference.';
                    } else {
                        $payout_error = '';
                        $verify_res = verifyPaystackProfitTransfer($ref, $payout_error);
                        if (!$verify_res) {
                            $error = 'Paystack verification query failed: ' . ($payout_error ?: 'Unknown error.');
                        } else {
                            $provider_status = $verify_res['status'];
                            $transfer_code = $verify_res['transfer_code'];
                            $provider_reference = $verify_res['provider_reference'];
                            $provider_response = $verify_res['response'];
                            $provider_response_json = json_encode($provider_response, JSON_UNESCAPED_SLASHES);
                            
                            $mapped_status = $provider_status === 'success' ? 'paid' : ($provider_status === 'reversed' ? 'reversed' : ($provider_status === 'failed' ? 'failed' : 'processing'));
                            
                            $admin_notes = trim((string) ($withdrawal['admin_notes'] ?? ''));
                            $event_note = '[Paystack check: ' . strtoupper($provider_status) . ' on ' . date('Y-m-d H:i:s') . ']';
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
                            if ($stmt) {
                                $stmt->bind_param('ssssi', $mapped_status, $provider_status, $provider_response_json, $updated_notes, $withdrawal_id);
                                $stmt->execute();
                                if ($stmt->affected_rows >= 0) {
                                    if ($mapped_status === 'paid') {
                                        $success = 'Withdrawal payment verified as successful and marked as Paid.';
                                        // Send SMS if not already marked paid
                                        if ($current_status !== 'paid') {
                                            $agent_id = (int) ($withdrawal['agent_id'] ?? 0);
                                            $amount = (float) ($withdrawal['amount'] ?? 0);
                                            $fee_amount = (float) ($withdrawal['fee_amount'] ?? 0);
                                            $total_debit = $amount;
                                            $net_payout = round($amount - $fee_amount, 2);
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
                                                        $smsMessage .= " You received {$net_str}. Store profit withdrawn: {$total_str}. Ref: {$ref}. - " . SITE_NAME;
                                                        try {
                                                            sendSMS(formatPhone($agent_phone), $smsMessage, 'profit_withdrawal', $agent_id);
                                                        } catch (Exception $e) {
                                                            error_log('Paystack manual verify SMS failed: ' . $e->getMessage());
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } elseif ($mapped_status === 'failed' || $mapped_status === 'reversed') {
                                        $success = 'Withdrawal checked. Paystack reported status as: ' . strtoupper($provider_status) . '. Local status updated to ' . $mapped_status . '.';
                                    } else {
                                        $success = 'Withdrawal checked. Paystack reported status is still: ' . strtoupper($provider_status) . '.';
                                    }
                                } else {
                                    $error = 'Failed to update withdrawal record.';
                                }
                            } else {
                                $error = 'Failed to prepare database update.';
                            }
                        }
                    }
                } elseif (($withdrawal['status'] ?? '') !== 'pending') {
                    $error = 'Request already processed or not found.';
                } else {
                    $agent_id = (int) ($withdrawal['agent_id'] ?? 0);
                    $amount = (float) ($withdrawal['amount'] ?? 0);
                    $reference = (string) ($withdrawal['reference'] ?? '');
                    $payout_method = (string) ($withdrawal['payout_method'] ?? 'momo');
                    $fee_amount = (float) ($withdrawal['fee_amount'] ?? 0);
                    $total_debit = $amount;
                    $net_payout = round($amount - $fee_amount, 2);
                    if ($net_payout < 0) {
                        $net_payout = 0;
                    }

                    if ($agent_id <= 0 || $amount <= 0) {
                        $error = 'Invalid withdrawal request details.';
                    } elseif ($payout_method !== 'momo') {
                        $error = 'Only mobile money withdrawals require approval.';
                    } elseif ($net_payout <= 0) {
                        $error = 'Net payout must be greater than zero after fee.';
                    } elseif ($action === 'approve') {
                        $store_profit_balance = function_exists('getAgentStoreProfitWithdrawalBalance')
                            ? getAgentStoreProfitWithdrawalBalance($agent_id)
                            : $amount;
                        // The current pending request is already reserved in the store-profit balance calculation.
                        $store_profit_balance = round($store_profit_balance + $total_debit, 2);
                        if (($total_debit - $store_profit_balance) > 0.00001) {
                            $error = 'Agent store profit balance is insufficient for this withdrawal.';
                        } else {
                            $payout_error = '';
                            $payout_result = null;
                            $payout_provider = 'manual';
                            $provider_bank_code = '';
                            $provider_recipient_code = (string) ($withdrawal['provider_recipient_code'] ?? '');
                            $provider_transfer_code = '';
                            $provider_reference = '';
                            $provider_status = '';
                            $provider_response = null;
                            $should_send_paid_notification = false;

                            if ($payout_route === 'paystack_auto') {
                                $payout_provider = 'paystack';
                                if ($provider_recipient_code === '') {
                                    $recipient_result = createPaystackMobileMoneyRecipient(
                                        (string) ($withdrawal['payout_name'] ?? ''),
                                        (string) ($withdrawal['payout_number'] ?? ''),
                                        (string) ($withdrawal['payout_network'] ?? ''),
                                        $payout_error
                                    );
                                    if ($recipient_result) {
                                        $provider_recipient_code = (string) ($recipient_result['recipient_code'] ?? '');
                                        $provider_bank_code = (string) ($recipient_result['bank_code'] ?? '');
                                    }
                                }

                                if ($provider_recipient_code === '') {
                                    $error = 'Paystack recipient creation failed: ' . ($payout_error ?: 'Unknown error.');
                                } else {
                                    $payout_result = initiatePaystackProfitTransfer(
                                        $provider_recipient_code,
                                        $net_payout,
                                        $reference,
                                        'Agent profit withdrawal',
                                        $payout_error
                                    );
                                    if (!$payout_result) {
                                        $error = 'Paystack payout failed: ' . ($payout_error ?: 'Unknown error.');
                                    } else {
                                        $provider_transfer_code = (string) ($payout_result['transfer_code'] ?? '');
                                        $provider_reference = (string) ($payout_result['provider_reference'] ?? '');
                                        $provider_status = strtolower(trim((string) ($payout_result['status'] ?? 'pending')));
                                        $provider_response = $payout_result['response'] ?? null;
                                    }
                                }
                            } elseif ($payout_route === 'moolre_auto') {
                                $payout_provider = 'moolre';
                                $payout_result = requestMoolreMomoPayout(
                                    $net_payout,
                                    (string) ($withdrawal['payout_network'] ?? ''),
                                    (string) ($withdrawal['payout_number'] ?? ''),
                                    (string) ($withdrawal['payout_name'] ?? ''),
                                    $reference,
                                    $payout_error
                                );
                                $provider_status = $payout_result ? 'success' : 'failed';
                                $provider_response = $payout_result;
                            } elseif ($payout_route === 'paystack_manual') {
                                $payout_provider = 'paystack';
                                $provider_status = 'manual';
                            } else {
                                $provider_status = 'manual';
                            }

                            if ($payout_route === 'paystack_auto' && !$paystack_payout_auto) {
                                $error = 'Automatic Paystack payout is not available right now.';
                            } elseif ($payout_route === 'moolre_auto' && !$moolre_payout_auto) {
                                $error = 'Automatic Moolre payout is not available right now.';
                            } elseif ($payout_route === 'paystack_manual' && !$paystack_transfer_configured) {
                                $error = 'Manual Paystack payout is not configured right now.';
                            } elseif ($payout_route === 'paystack_auto' && !$payout_result) {
                                $error = 'Paystack payout failed: ' . ($payout_error ?: 'Unknown error.');
                            } elseif ($payout_route === 'moolre_auto' && !$payout_result) {
                                $error = 'Momo payout failed: ' . ($payout_error ?: 'Unknown error.');
                            } else {
                                $profit_reserved = true;
                                if (!$profit_reserved) {
                                    $error = 'Failed to reserve agent store profit for this payout.';
                                } else {
                                    $gateway_ref = '';
                                    if ($moolre_payout_auto && $payout_result && is_array($payout_result['data'] ?? null)) {
                                        $gateway_ref = $payout_result['data']['transactid'] ?? $payout_result['data']['transaction_id'] ?? '';
                                    }
                                    $note_suffix = '';
                                    $final_status = 'paid';
                                    if ($payout_route === 'paystack_auto') {
                                        $final_status = $provider_status === 'success' ? 'paid' : 'processing';
                                        $note_suffix = ' | Paystack status: ' . strtoupper($provider_status !== '' ? $provider_status : 'pending');
                                        if ($provider_transfer_code !== '') {
                                            $note_suffix .= ' | Transfer code: ' . $provider_transfer_code;
                                        }
                                        if ($provider_reference !== '') {
                                            $note_suffix .= ' | Paystack ref: ' . $provider_reference;
                                        }
                                        $should_send_paid_notification = $final_status === 'paid';
                                    } elseif ($payout_route === 'moolre_auto') {
                                        $note_suffix = $gateway_ref ? (' | Moolre ref: ' . $gateway_ref) : '';
                                        $should_send_paid_notification = true;
                                    } elseif ($payout_route === 'paystack_manual') {
                                        $note_suffix = ' | Manual Paystack payout';
                                        $should_send_paid_notification = true;
                                    } else {
                                        $note_suffix = ' | Manual payout';
                                        $provider_status = 'manual';
                                        $should_send_paid_notification = true;
                                    }
                                    $fee_note = $fee_amount > 0 ? (' | Fee: ' . CURRENCY . number_format($fee_amount, 2)) : '';
                                    $payout_note = ' | Net payout: ' . CURRENCY . number_format($net_payout, 2);
                                    $final_notes = trim($admin_notes . $fee_note . $payout_note . $note_suffix);
                                    $provider_response_json = $provider_response !== null ? json_encode($provider_response, JSON_UNESCAPED_SLASHES) : null;

                                    $stmt = $db->prepare("
                                        UPDATE profit_withdrawals
                                        SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW(),
                                            payout_provider = ?, provider_bank_code = ?, provider_recipient_code = ?,
                                            provider_transfer_code = ?, provider_reference = ?, provider_status = ?, provider_response = ?
                                        WHERE id = ? AND status = 'pending'
                                    ");
                                    if ($stmt) {
                                        $stmt->bind_param(
                                            'ssisssssssi',
                                            $final_status,
                                            $final_notes,
                                            $current_user['id'],
                                            $payout_provider,
                                            $provider_bank_code,
                                            $provider_recipient_code,
                                            $provider_transfer_code,
                                            $provider_reference,
                                            $provider_status,
                                            $provider_response_json,
                                            $withdrawal_id
                                        );
                                        $stmt->execute();
                                        if ($stmt->affected_rows > 0) {
                                            if ($payout_route === 'paystack_auto') {
                                                $success = $final_status === 'paid'
                                                    ? 'Withdrawal paid via Paystack and store profit recorded.'
                                                    : 'Withdrawal approved and submitted to Paystack. Final confirmation is pending.';
                                            } elseif ($payout_route === 'moolre_auto') {
                                                $success = 'Withdrawal paid via MoMo and store profit recorded.';
                                            } elseif ($payout_route === 'paystack_manual') {
                                                $success = 'Withdrawal marked paid as a manual Paystack payout and store profit recorded.';
                                            } else {
                                                $success = 'Withdrawal marked paid manually and store profit recorded.';
                                            }
                                            if ($should_send_paid_notification) {
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
                                                                error_log('Profit withdrawal SMS failed: ' . $e->getMessage());
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            $error = 'Failed to update withdrawal status.';
                                        }
                                    } else {
                                        $error = 'Failed to update withdrawal status.';
                                    }
                                }
                            }
                        }
                    } else {
                        $stmt = $db->prepare("
                            UPDATE profit_withdrawals
                            SET status = 'rejected', admin_notes = ?, processed_by = ?, processed_at = NOW()
                            WHERE id = ? AND status = 'pending'
                        ");
                        if ($stmt) {
                            $stmt->bind_param('sii', $admin_notes, $current_user['id'], $withdrawal_id);
                            $stmt->execute();
                            if ($stmt->affected_rows > 0) {
                                $success = 'Withdrawal rejected.';
                            } else {
                                $error = 'Failed to update withdrawal status.';
                            }
                        } else {
                            $error = 'Failed to update withdrawal status.';
                        }
                    }
                }
            }
        }
    }
}

// Pending requests
$pending_requests = [];
$stmt = $db->query("
    SELECT pw.*, u.full_name, u.email
    FROM profit_withdrawals pw
    JOIN users u ON pw.agent_id = u.id
    WHERE pw.status = 'pending' AND pw.payout_method = 'momo'
    ORDER BY pw.created_at ASC
");
if ($stmt) {
    $pending_requests = $stmt->fetch_all(MYSQLI_ASSOC);
}

// Recent history
$history = [];
$stmt = $db->query("
    SELECT pw.*, u.full_name, admin.full_name AS processed_by_name
    FROM profit_withdrawals pw
    JOIN users u ON pw.agent_id = u.id
    LEFT JOIN users admin ON pw.processed_by = admin.id
    ORDER BY pw.created_at DESC
    LIMIT 50
");
if ($stmt) {
    $history = $stmt->fetch_all(MYSQLI_ASSOC);
}

$withdrawal_summary = [
    'pending_count' => 0,
    'pending_amount' => 0,
    'processing_count' => 0,
    'processing_amount' => 0,
    'paid_today_count' => 0,
    'paid_today_amount' => 0,
    'problem_count' => 0,
    'problem_amount' => 0,
];
$stmt = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
        COALESCE(SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END), 0) AS processing_count,
        COALESCE(SUM(CASE WHEN status = 'processing' THEN amount ELSE 0 END), 0) AS processing_amount,
        COALESCE(SUM(CASE WHEN status = 'paid' AND DATE(processed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS paid_today_count,
        COALESCE(SUM(CASE WHEN status = 'paid' AND DATE(processed_at) = CURDATE() THEN amount ELSE 0 END), 0) AS paid_today_amount,
        COALESCE(SUM(CASE WHEN status IN ('failed','reversed') THEN 1 ELSE 0 END), 0) AS problem_count,
        COALESCE(SUM(CASE WHEN status IN ('failed','reversed') THEN amount ELSE 0 END), 0) AS problem_amount
    FROM profit_withdrawals
");
if ($stmt) {
    $withdrawal_summary = array_merge($withdrawal_summary, $stmt->fetch_assoc() ?: []);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit Withdrawals - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .widget,
        .widget-content,
        .table-responsive {
            max-width: 100%;
            overflow-x: hidden;
            min-width: 0;
        }
        .table {
            width: 100%;
            max-width: 100%;
        }
        .table th,
        .table td {
            word-break: break-word;
            white-space: normal;
        }
        .table td code {
            white-space: normal;
        }
        .table-responsive form {
            flex-wrap: wrap;
        }
        .table-responsive input.form-control {
            min-width: 0 !important;
            width: 100% !important;
        }

        @media (max-width: 900px) {
            body {
                overflow-x: hidden;
            }
            .dashboard-content {
                padding: 1rem;
            }
            .page-title h1 {
                font-size: 1.25rem;
            }
            .page-subtitle {
                font-size: 0.9rem;
            }
            .widget-title {
                font-size: 1rem;
            }
            .widget-subtitle {
                font-size: 0.85rem;
            }
            .table,
            .table thead,
            .table tbody,
            .table th,
            .table td,
            .table tr {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .table thead {
                display: none !important;
            }
            .table tr {
                margin-bottom: 1rem;
                border: 1px solid var(--border-color, #e5e7eb);
                border-radius: 12px;
                padding: 0.5rem;
                background: #ffffff;
            }
            .table td {
                border: none;
                display: block !important;
                font-size: 0.82rem;
                padding: 0.5rem;
            }
            .table td::before {
                content: attr(data-label);
                display: block;
                font-weight: 600;
                color: var(--text-muted, #6b7280);
                margin-bottom: 0.25rem;
            }
            .table td code {
                font-size: 0.78rem;
                word-break: break-word;
            }
            .table td > * {
                max-width: 100%;
            }
            .table-responsive form {
                flex-direction: column;
                align-items: stretch;
                gap: 0.4rem;
                width: 100%;
            }
            .table-responsive .btn {
                width: 100%;
            }
        }

        [data-theme="dark"] .table tr {
            background: #0f172a;
            border-color: #1f2937;
        }

        [data-theme="dark"] .table td {
            color: #e5e7eb;
        }

        [data-theme="dark"] .table td::before {
            color: #9ca3af;
        }

        [data-theme="dark"] .table td code {
            color: #e5e7eb;
            background: #111827;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
                    <?php renderAdminSidebar(); ?>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="breadcrumb-item">Commission</div>
                    <div class="breadcrumb-item active">Profit Withdrawals</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
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
                <h1>Profit Withdrawals</h1>
                <p class="page-subtitle">Approve or reject agent profit withdrawal requests.</p>
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
            <?php if ((int) ($withdrawal_summary['processing_count'] ?? 0) > 0): ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    <?php echo (int) $withdrawal_summary['processing_count']; ?> payout(s) are still awaiting final confirmation from Paystack.
                </div>
            <?php endif; ?>
            <?php if (!empty($pending_requests)): ?>
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    <?php echo (int) count($pending_requests); ?> profit withdrawal request(s) are waiting for admin action.
                </div>
            <?php endif; ?>
            <?php if ($paystack_transfer_configured && !$paystack_transfer_otp_disabled): ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    Paystack payout automation is paused because transfer OTP is still enabled. Complete the Paystack Transfer OTP setup in <a href="settings.php">System Settings</a> to restore automatic payouts.
                </div>
            <?php endif; ?>
            <?php if ($paystack_payout_auto): ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
                    Automatic mobile money payouts are configured through Paystack. You can choose Automatic Paystack or Manual Paystack when approving each withdrawal.
                </div>
            <?php elseif ($paystack_transfer_configured): ?>
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    Paystack is configured for manual payouts. Automatic Paystack will appear after transfer OTP is disabled in <a href="settings.php">System Settings</a>.
                </div>
            <?php elseif ($moolre_payout_auto): ?>
                <div class="alert alert-info" style="margin-bottom: 1rem;">
                    Automatic MoMo payout is currently configured through Moolre.
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    Automatic payout is not configured. Approvals will be recorded as paid after you send mobile money manually.
                </div>
            <?php endif; ?>
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="fas fa-hourglass-half"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ($withdrawal_summary['pending_count'] ?? 0); ?></div>
                        <div class="stat-label">Awaiting Approval</div>
                        <div class="stat-subtitle"><?php echo CURRENCY . number_format((float) ($withdrawal_summary['pending_amount'] ?? 0), 2); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="fas fa-paper-plane"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ($withdrawal_summary['processing_count'] ?? 0); ?></div>
                        <div class="stat-label">Paystack Processing</div>
                        <div class="stat-subtitle"><?php echo CURRENCY . number_format((float) ($withdrawal_summary['processing_amount'] ?? 0), 2); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ($withdrawal_summary['paid_today_count'] ?? 0); ?></div>
                        <div class="stat-label">Paid Today</div>
                        <div class="stat-subtitle"><?php echo CURRENCY . number_format((float) ($withdrawal_summary['paid_today_amount'] ?? 0), 2); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo (int) ($withdrawal_summary['problem_count'] ?? 0); ?></div>
                        <div class="stat-label">Failed / Reversed</div>
                        <div class="stat-subtitle"><?php echo CURRENCY . number_format((float) ($withdrawal_summary['problem_amount'] ?? 0), 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Pending Requests</h3>
                    <p class="widget-subtitle">Requests waiting for admin approval and payout.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($pending_requests)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Amount</th>
                                        <th>Fee</th>
                                        <th>Net Payout</th>
                                        <th>Payout Details</th>
                                        <th>Reference</th>
                                        <th>Requested</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_requests as $request): ?>
                                    <tr>
                                        <td data-label="Agent">
                                            <div style="font-weight: 500;"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                            <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($request['email']); ?></div>
                                        </td>
                                        <td data-label="Amount"><?php echo CURRENCY . number_format((float) $request['amount'], 2); ?></td>
                                        <?php
                                        $request_fee = (float) ($request['fee_amount'] ?? 0);
                                        $request_amount = (float) ($request['amount'] ?? 0);
                                        $request_total = round($request_amount - $request_fee, 2);
                                        if ($request_total < 0) {
                                            $request_total = 0;
                                        }
                                        ?>
                                        <td data-label="Fee"><?php echo CURRENCY . number_format($request_fee, 2); ?></td>
                                        <td data-label="Net Payout"><?php echo CURRENCY . number_format($request_total, 2); ?></td>
                                        <td data-label="Payout Details">
                                            <div><?php echo htmlspecialchars($request['payout_network'] ?? ''); ?></div>
                                            <div><?php echo htmlspecialchars($request['payout_name'] ?? ''); ?></div>
                                            <div><?php echo htmlspecialchars($request['payout_number'] ?? ''); ?></div>
                                        </td>
                                        <td data-label="Reference"><code><?php echo htmlspecialchars($request['reference'] ?? ''); ?></code></td>
                                        <td data-label="Requested"><?php echo date('M j, Y H:i', strtotime($request['created_at'])); ?></td>
                                        <td data-label="Actions">
                                            <form method="post" style="display:flex; gap:0.5rem; flex-wrap: wrap;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="withdrawal_id" value="<?php echo (int) $request['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <select name="payout_route" class="form-control" style="min-width:180px;">
                                                    <?php foreach ($available_payout_routes as $route_value => $route_label): ?>
                                                        <option value="<?php echo htmlspecialchars($route_value); ?>" <?php echo $route_value === $default_payout_route ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($route_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="admin_notes" class="form-control" placeholder="Notes (optional)" style="min-width:180px;">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i> Approve & Process
                                                </button>
                                            </form>
                                            <form method="post" style="margin-top:0.5rem;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="withdrawal_id" value="<?php echo (int) $request['id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="text" name="admin_notes" class="form-control" placeholder="Reason for rejection" style="min-width:180px;">
                                                <button type="submit" class="btn btn-danger btn-sm" style="margin-top:0.25rem;">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Pending Requests</h4>
                            <p>All profit withdrawal requests have been handled.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Recent Withdrawal History</h3>
                    <p class="widget-subtitle">Latest profit withdrawal activity.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($history)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Agent</th>
                                        <th>Amount</th>
                                        <th>Fee</th>
                                        <th>Net Payout</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $row): ?>
                                    <tr>
                                        <td data-label="Reference"><code><?php echo htmlspecialchars($row['reference'] ?? ''); ?></code></td>
                                        <td data-label="Agent"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td data-label="Amount"><?php echo CURRENCY . number_format((float) $row['amount'], 2); ?></td>
                                        <?php
                                        $history_fee = (float) ($row['fee_amount'] ?? 0);
                                        $history_amount = (float) ($row['amount'] ?? 0);
                                        $history_total = round($history_amount - $history_fee, 2);
                                        if ($history_total < 0) {
                                            $history_total = 0;
                                        }
                                        ?>
                                        <td data-label="Fee"><?php echo CURRENCY . number_format($history_fee, 2); ?></td>
                                        <td data-label="Net Payout"><?php echo CURRENCY . number_format($history_total, 2); ?></td>
                                        <td data-label="Method"><?php echo ucfirst($row['payout_method'] ?? 'momo'); ?></td>
                                        <td data-label="Status">
                                            <?php
                                            $status = $row['status'] ?? 'pending';
                                            $badge = $status === 'paid'
                                                ? 'success'
                                                : (in_array($status, ['failed', 'rejected', 'reversed'], true) ? 'danger' : 'warning');
                                            ?>
                                            <span class="badge badge-<?php echo $badge; ?>"><?php echo ucfirst($status); ?></span>
                                            <?php if ($status === 'processing' && ($row['payout_provider'] ?? '') === 'paystack'): ?>
                                                <form method="post" style="display:inline-block; margin-top:0.25rem; width: 100%;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="withdrawal_id" value="<?php echo (int) $row['id']; ?>">
                                                    <input type="hidden" name="action" value="verify_status">
                                                    <button type="submit" class="btn btn-sm btn-outline-info" style="padding: 2px 6px; font-size: 0.75rem; width: 100%; text-align: center;" title="Check Paystack transfer status">
                                                        <i class="fas fa-sync-alt"></i> Verify Status
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Processed By"><?php echo htmlspecialchars($row['processed_by_name'] ?? ''); ?></td>
                                        <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Withdrawal Records</h4>
                            <p>No profit withdrawal history yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>"></script>
<script>
initializeTheme();

document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});

function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    if (toggle && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});
</script>
<!-- IMMEDIATE Icon Fix for square placeholder issues -->
<script src="../immediate_icon_fix.js"></script>
</body>
</html>
