<?php
require_once '../config/config.php';
require_once '../includes/email.php';

// Require agent role
requireRole('agent');

ensureResultCheckerTables();
ensureProfitWithdrawalTables();
if (function_exists('ensureTopupSettingsTable')) {
    ensureTopupSettingsTable();
}

$current_user = getCurrentUser();
$agent_id = (int) ($current_user['id'] ?? 0);
$wallet_balance = round((float) getWalletBalance($agent_id), 2);
$fee_schedule = getProfitWithdrawalFeeSchedule();
$fee_schedule_label = formatProfitWithdrawalFeeScheduleLabel($fee_schedule);

$flash = getFlashMessage();
$error = '';
$success = '';

// Load agent payout defaults
$agentSettings = [];
$stmt = $db->prepare("
    SELECT setting_key, setting_value
    FROM topup_settings
    WHERE user_id = ?
      AND setting_key IN (
        'agent_topup_account_network',
        'agent_topup_account_name',
        'agent_topup_account_number',
        'agent_topup_instructions'
      )
");
if ($stmt) {
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $agentSettings[$row['setting_key']] = $row['setting_value'];
    }
}

$default_network = $agentSettings['agent_topup_account_network'] ?? 'MTN MOMO';
$default_name = $agentSettings['agent_topup_account_name'] ?? ($current_user['full_name'] ?? '');
$default_number = $agentSettings['agent_topup_account_number'] ?? ($current_user['phone'] ?? '');

// Profit summaries
$data_profit = 0.0;
$direct_data_profit = 0.0;
$store_data_profit = 0.0;
if (function_exists('dbh_table_exists') && dbh_table_exists('agent_profits')) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(profit_amount), 0) AS total
        FROM agent_profits ap
        LEFT JOIN bundle_orders bo ON bo.id = ap.order_id
        WHERE ap.agent_id = ?
          AND ap.status = 'earned'
          AND (bo.id IS NULL OR bo.user_id IS NULL OR bo.user_id <> ?)
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $agent_id, $agent_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $data_profit = (float) ($row['total'] ?? 0);
        }
    }

    if (function_exists('dbh_table_has_column') && dbh_table_has_column('agent_profits', 'customer_id')) {
        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN customer_id = ? THEN profit_amount ELSE 0 END), 0) AS direct_total,
                COALESCE(SUM(CASE WHEN customer_id IS NULL OR customer_id <> ? THEN profit_amount ELSE 0 END), 0) AS store_total
            FROM agent_profits
            WHERE agent_id = ?
              AND status = 'earned'
        ");
        if ($stmt) {
            $stmt->bind_param('iii', $agent_id, $agent_id, $agent_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $direct_data_profit = (float) ($row['direct_total'] ?? 0);
                $store_data_profit = (float) ($row['store_total'] ?? 0);
            }
            $stmt->close();
        }
    }
}

$pending_withdrawals = 0.0;
$paid_out_withdrawals = 0.0;
$withdrawalSumColumn = 'amount';
if (function_exists('dbh_table_has_column') && dbh_table_has_column('profit_withdrawals', 'total_debit')) {
    $withdrawalSumColumn = 'CASE WHEN total_debit IS NULL OR total_debit <= 0 THEN amount WHEN total_debit > amount THEN amount ELSE total_debit END';
}
$stmt = $db->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS pending_total,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS paid_total
    FROM profit_withdrawals
    WHERE agent_id = ?
");
if ($stmt) {
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $pending_withdrawals = (float) ($row['pending_total'] ?? 0);
        $paid_out_withdrawals = (float) ($row['paid_total'] ?? 0);
    }
}

$total_profit = $data_profit;
$total_profit = round((float) $total_profit, 2);
$available_profit = round(max(0, $total_profit - $pending_withdrawals - $paid_out_withdrawals), 2);
$withdrawable_limit = $available_profit;
$withdrawable_profit_wallet = $withdrawable_limit;
$withdrawable_profit_momo = $withdrawable_limit;
$withdrawable_profit = $withdrawable_profit_wallet;
$processing_fee_label = $fee_schedule_label;
$wallet_is_limiting_withdrawal = false;
$wallet_shortfall = max(0, $available_profit - $withdrawable_limit);

$formatWithdrawalLimitMessage = static function ($requestedAmount, $limitAmount, $availableProfitAmount, $walletBalanceAmount, $walletLimited) {
    $requestedText = CURRENCY . number_format((float) $requestedAmount, 2);
    $limitText = CURRENCY . number_format((float) $limitAmount, 2);
    $profitText = CURRENCY . number_format((float) $availableProfitAmount, 2);
    $walletText = CURRENCY . number_format((float) $walletBalanceAmount, 2);

    if ($walletLimited) {
        return 'Requested amount ' . $requestedText
            . ' exceeds your current withdrawable limit of ' . $limitText
            . '. Earned profit available: ' . $profitText
            . ', but your wallet balance funding withdrawals is currently ' . $walletText . '.';
    }

    return 'Requested amount ' . $requestedText
        . ' exceeds your available profit balance of ' . $limitText . '.';
};

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $payout_method = strtolower(trim((string) ($_POST['payout_method'] ?? 'momo')));
        $payout_method = $payout_method === 'wallet' ? 'wallet' : 'momo';
        $payout_network = sanitize($_POST['payout_network'] ?? '');
        $payout_name = sanitize($_POST['payout_name'] ?? '');
        $payout_number = sanitize($_POST['payout_number'] ?? '');
        if ($payout_method === 'momo') {
            $payout_number = formatPhone($payout_number);
        }
        $notes = sanitize($_POST['notes'] ?? '');
        $fee_amount = 0.0;
        $total_debit = $amount;
        $net_payout = $amount;
        $comparison_epsilon = 0.00001;

        if ($payout_method === 'momo') {
            $fee_amount = calculateProfitWithdrawalFee($amount, $fee_schedule);
            $total_debit = $amount;
            $net_payout = round($amount - $fee_amount, 2);
        }

        if ($amount <= 0) {
            $error = 'Withdrawal amount must be greater than zero.';
        } elseif ($payout_method === 'wallet' && ($amount - $withdrawable_profit_wallet) > $comparison_epsilon) {
            $error = $formatWithdrawalLimitMessage($amount, $withdrawable_profit_wallet, $available_profit, $wallet_balance, $wallet_is_limiting_withdrawal);
        } elseif ($payout_method === 'momo' && ($amount - $withdrawable_limit) > $comparison_epsilon) {
            $error = $formatWithdrawalLimitMessage($amount, $withdrawable_limit, $available_profit, $wallet_balance, $wallet_is_limiting_withdrawal);
        } elseif ($payout_method === 'momo' && $net_payout <= 0) {
            $error = 'Withdrawal amount must be greater than the processing fee.';
        } elseif ($payout_method === 'momo' && $payout_number === '') {
            $error = 'Please enter a valid mobile money number.';
        } else {
            $reference = generateReference('PWF');
            if ($payout_method === 'wallet') {
                $stmt = $db->prepare("
                    INSERT INTO profit_withdrawals
                        (agent_id, amount, fee_amount, total_debit, payout_method, status, reference, notes, processed_by, processed_at)
                    VALUES (?, ?, ?, ?, 'wallet', 'paid', ?, ?, ?, NOW())
                ");
                if ($stmt) {
                    $stmt->bind_param('iddsssi', $agent_id, $amount, $fee_amount, $total_debit, $reference, $notes, $agent_id);
                    if ($stmt->execute()) {
                        // Capture fresh wallet balance before top-up
                        $prev_balance = round((float) getWalletBalance($agent_id), 2);

                        // Call helper function to credit agent wallet balance and log a transaction
                        updateWalletBalance($agent_id, $amount, 'credit', $reference, 'Store Profit Withdrawal to Wallet', 'wallet');

                        $new_balance = round($prev_balance + $amount, 2);

                        // Send email to Admin
                        $admin_email = '';
                        $admin_stmt = $db->prepare("SELECT email FROM users WHERE role IN ('admin','super_admin') ORDER BY id ASC LIMIT 1");
                        if ($admin_stmt && $admin_stmt->execute()) {
                            $admin = $admin_stmt->get_result()->fetch_assoc();
                            $admin_email = $admin['email'] ?? '';
                        }
                        if (!$admin_email && defined('ADMIN_EMAIL')) {
                            $admin_email = ADMIN_EMAIL;
                        }

                        if ($admin_email) {
                            $amount_str = CURRENCY . number_format($amount, 2);
                            $agent_name = $current_user['full_name'] ?? $current_user['username'] ?? 'Agent';
                            $agent_email = $current_user['email'] ?? '';
                            $agent_phone = $current_user['phone'] ?? ($current_user['mobile'] ?? '');
                            $subject = "Profit Wallet Withdrawal - {$reference}";
                            $body_html = "
                                <h3>Profit Wallet Withdrawal Completed</h3>
                                <p><strong>Reference:</strong> " . htmlspecialchars($reference) . "</p>
                                <p><strong>Agent:</strong> " . htmlspecialchars($agent_name) . "</p>
                                <p><strong>Email:</strong> " . htmlspecialchars($agent_email) . "</p>
                                <p><strong>Phone:</strong> " . htmlspecialchars($agent_phone) . "</p>
                                <p><strong>Withdrawn Amount:</strong> {$amount_str}</p>
                                <p><strong>Payout Method:</strong> Wallet (Instant)</p>
                                <p><strong>Status:</strong> Paid (Automatically Credited)</p>
                                <p><strong>Notes:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>
                            ";
                            $body_text = "Profit wallet withdrawal completed\n"
                                . "Reference: {$reference}\n"
                                . "Agent: {$agent_name}\n"
                                . "Email: {$agent_email}\n"
                                . "Phone: {$agent_phone}\n"
                                . "Withdrawn Amount: {$amount_str}\n"
                                . "Payout Method: Wallet (Instant)\n"
                                . "Status: Paid (Automatically Credited)\n"
                                . "Notes: {$notes}\n";

                            try {
                                sendEmail($admin_email, $subject, $body_html, $body_text, 'profit_withdrawal_wallet_admin');
                            } catch (Exception $e) {
                                error_log('Profit withdrawal admin email failed: ' . $e->getMessage());
                            }
                        }

                        // Send email to User
                        $user_email = $current_user['email'] ?? '';
                        if ($user_email) {
                            $amount_str = CURRENCY . number_format($amount, 2);
                            $prev_str = CURRENCY . number_format($prev_balance, 2);
                            $new_str = CURRENCY . number_format($new_balance, 2);
                            $user_name = $current_user['full_name'] ?? $current_user['username'] ?? 'Agent';
                            $subject = "Profit Withdrawal Credited to Wallet - {$reference}";
                            $body_html = "
                                <h3>Profit Withdrawal Credited to Wallet</h3>
                                <p>Hi {$user_name},</p>
                                <p>Your store profit withdrawal request was successfully processed and credited to your wallet.</p>
                                <p><strong>Reference:</strong> " . htmlspecialchars($reference) . "</p>
                                <p><strong>Previous Wallet Balance:</strong> {$prev_str}</p>
                                <p><strong>Added Amount (Store Profit):</strong> {$amount_str}</p>
                                <p><strong>New Wallet Balance:</strong> {$new_str}</p>
                                <p><strong>Status:</strong> Credited Automatically</p>
                                <p>Thank you for using " . SITE_NAME . "!</p>
                            ";
                            $body_text = "Hi {$user_name},\n\n"
                                . "Your store profit withdrawal request was successfully processed and credited to your wallet.\n\n"
                                . "Reference: {$reference}\n"
                                . "Previous Wallet Balance: {$prev_str}\n"
                                . "Added Amount (Store Profit): {$amount_str}\n"
                                . "New Wallet Balance: {$new_str}\n"
                                . "Status: Credited Automatically\n";

                            try {
                                sendEmail($user_email, $subject, $body_html, $body_text, 'profit_withdrawal_wallet_user');
                            } catch (Exception $e) {
                                error_log('Profit withdrawal user email failed: ' . $e->getMessage());
                            }
                        }

                        setFlashMessage('success', 'Profit reflected in wallet automatically. Reference: ' . $reference);
                        header('Location: withdraw-profit.php');
                        exit();
                    }
                }
                $error = 'Failed to apply wallet profit. Please try again.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO profit_withdrawals
                        (agent_id, amount, fee_amount, total_debit, payout_method, payout_network, payout_name, payout_number, status, reference, notes)
                    VALUES (?, ?, ?, ?, 'momo', ?, ?, ?, 'pending', ?, ?)
                ");
                if ($stmt) {
                    $stmt->bind_param(
                        'idddsssss',
                        $agent_id,
                        $amount,
                        $fee_amount,
                        $total_debit,
                        $payout_network,
                        $payout_name,
                        $payout_number,
                        $reference,
                        $notes
                    );
                    if ($stmt->execute()) {
                        $admin_email = '';
                        $admin_stmt = $db->prepare("SELECT email FROM users WHERE role IN ('admin','super_admin') ORDER BY id ASC LIMIT 1");
                        if ($admin_stmt && $admin_stmt->execute()) {
                            $admin = $admin_stmt->get_result()->fetch_assoc();
                            $admin_email = $admin['email'] ?? '';
                        }
                        if (!$admin_email && defined('ADMIN_EMAIL')) {
                            $admin_email = ADMIN_EMAIL;
                        }

                        if ($admin_email) {
                            $amount_str = CURRENCY . number_format($amount, 2);
                            $fee_str = CURRENCY . number_format($fee_amount, 2);
                            $net_str = CURRENCY . number_format($net_payout, 2);
                            $agent_name = $current_user['full_name'] ?? $current_user['username'] ?? 'Agent';
                            $agent_email = $current_user['email'] ?? '';
                            $agent_phone = $current_user['phone'] ?? ($current_user['mobile'] ?? '');
                            $subject = "Profit Withdrawal Request - {$reference}";
                            $body_html = "
                                <h3>New Profit Withdrawal Request</h3>
                                <p><strong>Reference:</strong> " . htmlspecialchars($reference) . "</p>
                                <p><strong>Agent:</strong> " . htmlspecialchars($agent_name) . "</p>
                                <p><strong>Email:</strong> " . htmlspecialchars($agent_email) . "</p>
                                <p><strong>Phone:</strong> " . htmlspecialchars($agent_phone) . "</p>
                                <p><strong>Requested Amount:</strong> {$amount_str}</p>
                                <p><strong>Processing Fee:</strong> {$fee_str}</p>
                                <p><strong>Net Payout:</strong> {$net_str}</p>
                                <p><strong>Network:</strong> " . htmlspecialchars($payout_network) . "</p>
                                <p><strong>Account Name:</strong> " . htmlspecialchars($payout_name) . "</p>
                                <p><strong>MoMo Number:</strong> " . htmlspecialchars($payout_number) . "</p>
                                <p><strong>Notes:</strong> " . nl2br(htmlspecialchars($notes)) . "</p>
                                <p>Please review and process this request in the admin dashboard.</p>
                            ";
                            $body_text = "New profit withdrawal request\n"
                                . "Reference: {$reference}\n"
                                . "Agent: {$agent_name}\n"
                                . "Email: {$agent_email}\n"
                                . "Phone: {$agent_phone}\n"
                                . "Requested Amount: {$amount_str}\n"
                                . "Processing Fee: {$fee_str}\n"
                                . "Net Payout: {$net_str}\n"
                                . "Network: {$payout_network}\n"
                                . "Account Name: {$payout_name}\n"
                                . "MoMo Number: {$payout_number}\n"
                                . "Notes: {$notes}\n";

                            try {
                                sendEmail($admin_email, $subject, $body_html, $body_text, 'profit_withdrawal_request');
                            } catch (Exception $e) {
                                error_log('Profit withdrawal admin email failed: ' . $e->getMessage());
                            }
                        }
                        setFlashMessage('success', 'MoMo withdrawal request submitted. Reference: ' . $reference);
                        header('Location: withdraw-profit.php');
                        exit();
                    }
                }
                $error = 'Failed to submit withdrawal request. Please try again.';
            }
        }
    }
}

// Recent withdrawal history
$withdrawal_history = [];
$stmt = $db->prepare("
    SELECT pw.*, u.full_name, u.email
    FROM profit_withdrawals pw
    LEFT JOIN users u ON u.id = pw.agent_id
    WHERE pw.agent_id = ?
    ORDER BY pw.created_at DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $withdrawal_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Generate CSRF token
$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Profit - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .withdraw-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            border-radius: 0.85rem;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            margin-top: 1rem;
        }

        .withdraw-summary div {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .withdraw-summary span {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .withdraw-summary strong {
            font-size: 1rem;
            color: var(--text-primary);
        }

        .locked-amount-input {
            background: var(--bg-secondary);
            cursor: not-allowed;
            caret-color: transparent;
        }

        .confirm-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .confirm-modal.show {
            display: flex;
        }

        .confirm-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
        }

        .confirm-dialog {
            position: relative;
            background: var(--bg-primary);
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
            width: min(520px, 92vw);
            box-shadow: var(--shadow-lg);
            z-index: 1;
        }

        .confirm-header {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }

        .confirm-list {
            display: grid;
            gap: 0.5rem;
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }

        .confirm-list div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

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

        .table-responsive {
            width: 100%;
            overflow-x: hidden;
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
        <?php renderAgentSidebar(); ?>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-wallet"></i></div>
                    <div class="breadcrumb-item active">Store Profit</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>

                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>

                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <a href="support.php" class="dropdown-item">
                            <i class="fas fa-life-ring"></i> Support
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
                <h1>Store Profit</h1>
                <p class="page-subtitle">This is the single place to view and withdraw your order earnings from your own bundle purchases, customer store-link orders, result checker, and AFA registration sales.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

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


            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-sack-dollar text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($total_profit, 2); ?></div>
                        <div class="stat-label"><strong>Total Profit Earned</strong></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-wifi text-primary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($data_profit, 2); ?></div>
                        <div class="stat-label"><strong>Bundle Profit</strong></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-store text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($data_profit, 2); ?></div>
                        <div class="stat-label"><strong>Store Link Orders</strong></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-hourglass-half text-info"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($pending_withdrawals, 2); ?></div>
                        <div class="stat-label"><strong>Pending Requests</strong></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-money-check-dollar text-warning"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($paid_out_withdrawals, 2); ?></div>
                        <div class="stat-label"><strong>Paid Out</strong></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-wallet text-secondary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($withdrawable_profit_wallet, 2); ?></div>
                        <div class="stat-label"><strong>Available to Withdraw</strong></div>
                    </div>
                </div>
            </div>

            <div class="grid-2">
                <div class="widget">
                    <div class="widget-content">
                        <?php if ($withdrawable_profit > 0): ?>
                            <form method="post" id="withdrawForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                                <div class="form-group">
                                    <label class="form-label" for="amount">Withdrawable Profit Amount (<?php echo CURRENCY; ?>)</label>
                                    <input type="number" id="amount" name="amount"
                                           min="1"
                                           max="<?php echo htmlspecialchars($withdrawable_profit_wallet); ?>"
                                           step="0.01"
                                           value="<?php echo htmlspecialchars($withdrawable_profit_wallet); ?>"
                                           data-max-wallet="<?php echo htmlspecialchars(number_format($withdrawable_profit_wallet, 2, '.', '')); ?>"
                                           data-max-momo="<?php echo htmlspecialchars(number_format($withdrawable_profit_momo, 2, '.', '')); ?>"
                                           readonly
                                           aria-readonly="true"
                                           inputmode="none"
                                           onkeydown="return false;"
                                           onwheel="this.blur();"
                                           class="form-control locked-amount-input"
                                           required>
                                <div class="form-help">
                                    Earned profit available: <?php echo CURRENCY . number_format($available_profit, 2); ?>
                                </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="payout_method">Withdrawal Method</label>
                                    <select id="payout_method" name="payout_method" class="form-control" required>
                                        <option value="wallet">Wallet (Instant)</option>
                                        <option value="momo">Mobile Money (Admin Approval via Paystack)</option>
                                    </select>
                                </div>

                                <div id="feeSummary" class="form-help" style="margin-top: -0.5rem;">
                                    Processing fee: <strong id="processingFeeValue"><?php echo CURRENCY . number_format(0, 2); ?></strong>
                                    | You will receive: <strong id="netPayoutValue"><?php echo CURRENCY . number_format($withdrawable_profit_wallet, 2); ?></strong>
                                </div>

                                <div class="withdraw-summary" id="withdrawSummary">
                                    <div>
                                        <span>Method</span>
                                        <strong id="summaryMethod">Wallet</strong>
                                    </div>
                                    <div>
                                        <span>Amount</span>
                                        <strong id="summaryAmount"><?php echo CURRENCY . number_format($withdrawable_profit_wallet, 2); ?></strong>
                                    </div>
                                    <div>
                                        <span>Fee</span>
                                        <strong id="summaryFee"><?php echo CURRENCY . number_format(0, 2); ?></strong>
                                    </div>
                                    <div>
                                        <span>You Receive</span>
                                        <strong id="summaryNet"><?php echo CURRENCY . number_format($withdrawable_profit_wallet, 2); ?></strong>
                                    </div>
                                    <div id="summaryNetworkWrap" style="display:none;">
                                        <span>Network</span>
                                        <strong id="summaryNetwork">MTN MOMO</strong>
                                    </div>
                                    <div id="summaryNumberWrap" style="display:none;">
                                        <span>MoMo Number</span>
                                        <strong id="summaryNumber">-</strong>
                                    </div>
                                </div>

                                <div id="payoutDetails" class="form-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                    <div class="form-group">
                                        <label class="form-label" for="payout_network">Network</label>
                                        <select id="payout_network" name="payout_network" class="form-control" required>
                                            <option value="MTN MOMO" <?php echo $default_network === 'MTN MOMO' ? 'selected' : ''; ?>>MTN Mobile Money</option>
                                            <option value="VODAFONE CASH" <?php echo $default_network === 'VODAFONE CASH' ? 'selected' : ''; ?>>Vodafone Cash</option>
                                            <option value="AIRTELTIGO MONEY" <?php echo $default_network === 'AIRTELTIGO MONEY' ? 'selected' : ''; ?>>AirtelTigo Money</option>
                                            <option value="OTHER" <?php echo $default_network === 'OTHER' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="payout_name">Account Name</label>
                                        <input type="text" id="payout_name" name="payout_name" class="form-control"
                                               value="<?php echo htmlspecialchars($default_name); ?>" placeholder="Account name">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="payout_number">MoMo Number</label>
                                        <input type="text" id="payout_number" name="payout_number" class="form-control"
                                               value="<?php echo htmlspecialchars($default_number); ?>" placeholder="e.g. 0240000000" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="notes">Notes (Optional)</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Any additional information"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit Request
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-wallet" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                <h4>No Available Profit</h4>
                                <p>Earn profit from sales to submit a withdrawal request.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="widget">
                    <div class="widget-content">
                        <div style="font-weight: bold; margin-bottom: 1rem; text-align: center;">
                            Wallet Balance: <?php echo CURRENCY . number_format($wallet_balance, 2); ?>
                        </div>
                        <?php if ($wallet_is_limiting_withdrawal): ?>
                            <div class="alert alert-warning" style="margin-top: 1rem;">
                                You have <?php echo CURRENCY . number_format($available_profit, 2); ?> in earned profit, but only
                                <?php echo CURRENCY . number_format($withdrawable_limit, 2); ?> is withdrawable right now because your
                                wallet balance is <?php echo CURRENCY . number_format($wallet_balance, 2); ?>.
                                The uncovered difference is <?php echo CURRENCY . number_format($wallet_shortfall, 2); ?>.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Withdrawal History</h3>
                </div>
                <div class="widget-content">
                    <?php if (!empty($withdrawal_history)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Fee</th>
                                        <th>Net Payout</th>
                                        <th>Method</th>
                                        <th>Network</th>
                                        <th>MoMo Number</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($withdrawal_history as $row): ?>
                                        <tr>
                                            <td data-label="User">
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($row['full_name'] ?? ''); ?></div>
                                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['email'] ?? ''); ?></div>
                                            </td>
                                            <td data-label="Reference"><code><?php echo htmlspecialchars($row['reference'] ?? ''); ?></code></td>
                                            <td data-label="Amount"><?php echo CURRENCY . number_format((float) $row['amount'], 2); ?></td>
                                            <?php
                                            $row_fee = (float) ($row['fee_amount'] ?? 0);
                                            $row_amount = (float) ($row['amount'] ?? 0);
                                            $row_total = round($row_amount - $row_fee, 2);
                                            if ($row_total < 0) {
                                                $row_total = 0;
                                            }
                                            ?>
                                            <td data-label="Fee"><?php echo CURRENCY . number_format($row_fee, 2); ?></td>
                                            <td data-label="Net Payout"><?php echo CURRENCY . number_format($row_total, 2); ?></td>
                                            <td data-label="Method"><?php echo ucfirst($row['payout_method'] ?? 'momo'); ?></td>
                                            <td data-label="Network"><?php echo htmlspecialchars($row['payout_network'] ?? ''); ?></td>
                                            <td data-label="MoMo Number"><?php echo htmlspecialchars($row['payout_number'] ?? ''); ?></td>
                                            <td data-label="Status">
                                                <?php
                                                $status = $row['status'] ?? 'pending';
                                                $badge = $status === 'paid'
                                                    ? 'success'
                                                    : (in_array($status, ['failed', 'rejected', 'reversed'], true) ? 'danger' : 'warning');
                                                ?>
                                                <span class="badge badge-<?php echo $badge; ?>"><?php echo ucfirst($status); ?></span>
                                            </td>
                                            <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($row['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Withdrawal Requests</h4>
                            <p>You haven't submitted any withdrawal requests yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="confirm-modal" id="withdrawConfirmModal" aria-hidden="true">
    <div class="confirm-backdrop" data-close="1"></div>
    <div class="confirm-dialog" role="dialog" aria-modal="true">
        <div class="confirm-header">Confirm Withdrawal Request</div>
        <div class="confirm-list">
            <div><span>Method</span><strong id="confirmMethod">Wallet</strong></div>
            <div><span>Amount</span><strong id="confirmAmount">-</strong></div>
            <div><span>Fee</span><strong id="confirmFee">-</strong></div>
            <div><span>You Receive</span><strong id="confirmNet">-</strong></div>
            <div id="confirmNetworkRow" style="display:none;"><span>Network</span><strong id="confirmNetwork">-</strong></div>
            <div id="confirmNumberRow" style="display:none;"><span>MoMo Number</span><strong id="confirmNumber">-</strong></div>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn btn-outline" id="confirmCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmSubmit">Confirm Request</button>
        </div>
    </div>
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

    const payoutMethod = document.getElementById('payout_method');
    const payoutDetails = document.getElementById('payoutDetails');
    const payoutNetwork = document.getElementById('payout_network');
    const payoutNumber = document.getElementById('payout_number');
    const amountInput = document.getElementById('amount');
    const feeValue = document.getElementById('processingFeeValue');
    const netValue = document.getElementById('netPayoutValue');
    const summaryMethod = document.getElementById('summaryMethod');
    const summaryAmount = document.getElementById('summaryAmount');
    const summaryFee = document.getElementById('summaryFee');
    const summaryNet = document.getElementById('summaryNet');
    const summaryNetworkWrap = document.getElementById('summaryNetworkWrap');
    const summaryNumberWrap = document.getElementById('summaryNumberWrap');
    const summaryNetwork = document.getElementById('summaryNetwork');
    const summaryNumber = document.getElementById('summaryNumber');
    const withdrawForm = document.getElementById('withdrawForm');
    const confirmModal = document.getElementById('withdrawConfirmModal');
    const confirmCancel = document.getElementById('confirmCancel');
    const confirmSubmit = document.getElementById('confirmSubmit');
    const confirmMethod = document.getElementById('confirmMethod');
    const confirmAmount = document.getElementById('confirmAmount');
    const confirmFee = document.getElementById('confirmFee');
    const confirmNet = document.getElementById('confirmNet');
    const confirmNetworkRow = document.getElementById('confirmNetworkRow');
    const confirmNumberRow = document.getElementById('confirmNumberRow');
    const confirmNetwork = document.getElementById('confirmNetwork');
    const confirmNumber = document.getElementById('confirmNumber');
    const currencySymbol = <?php echo json_encode(CURRENCY); ?>;
    const feeSchedule = <?php echo json_encode($fee_schedule); ?>;

    function togglePayoutFields() {
        const isMomo = payoutMethod && payoutMethod.value === 'momo';
        if (payoutDetails) {
            payoutDetails.style.display = isMomo ? 'grid' : 'none';
        }
        if (payoutNetwork) payoutNetwork.required = isMomo;
        if (payoutNumber) payoutNumber.required = isMomo;
        updateFeeSummary();
    }

    function round2(value) {
        return Math.round(value * 100) / 100;
    }

    function updateFeeSummary() {
        if (!amountInput) {
            return;
        }
        const method = payoutMethod ? payoutMethod.value : 'wallet';
        const maxWallet = parseFloat(amountInput.dataset.maxWallet || '0');
        const maxMomo = parseFloat(amountInput.dataset.maxMomo || '0');
        const max = method === 'momo' ? maxMomo : maxWallet;

        if (max > 0) {
            amountInput.max = max.toFixed(2);
        }

        let amount = parseFloat(amountInput.value || '0');
        if (max > 0 && amount > max) {
            amount = max;
            amountInput.value = max.toFixed(2);
        }

        let fee = 0;
        if (method === 'momo') {
            for (let i = 0; i < feeSchedule.length; i += 1) {
                const band = feeSchedule[i];
                const min = parseFloat(band.min || 0);
                const max = band.max === null ? null : parseFloat(band.max);
                if (amount >= min && (max === null || amount <= max)) {
                    fee = parseFloat(band.fee || 0);
                    break;
                }
            }
            fee = round2(fee);
        }
        const net = round2(amount - fee);

        if (feeValue) {
            feeValue.textContent = currencySymbol + fee.toFixed(2);
        }
        if (netValue) {
            const netSafe = net < 0 ? 0 : net;
            netValue.textContent = currencySymbol + netSafe.toFixed(2);
        }

        const methodLabel = method === 'momo' ? 'Mobile Money' : 'Wallet';
        if (summaryMethod) summaryMethod.textContent = methodLabel;
        if (summaryAmount) summaryAmount.textContent = currencySymbol + amount.toFixed(2);
        if (summaryFee) summaryFee.textContent = currencySymbol + fee.toFixed(2);
        if (summaryNet) summaryNet.textContent = currencySymbol + (net < 0 ? 0 : net).toFixed(2);

        if (summaryNetworkWrap && summaryNumberWrap && summaryNetwork && summaryNumber) {
            const showMomo = method === 'momo';
            summaryNetworkWrap.style.display = showMomo ? 'block' : 'none';
            summaryNumberWrap.style.display = showMomo ? 'block' : 'none';
            summaryNetwork.textContent = payoutNetwork ? payoutNetwork.value || '-' : '-';
            summaryNumber.textContent = payoutNumber ? payoutNumber.value || '-' : '-';
        }
    }

    if (payoutMethod) {
        payoutMethod.addEventListener('change', togglePayoutFields);
        togglePayoutFields();
    }

    if (amountInput) {
        amountInput.addEventListener('input', updateFeeSummary);
        updateFeeSummary();
    }

    if (payoutNetwork) {
        payoutNetwork.addEventListener('change', updateFeeSummary);
    }

    if (payoutNumber) {
        payoutNumber.addEventListener('input', updateFeeSummary);
    }

    if (withdrawForm && confirmModal) {
        withdrawForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (!withdrawForm.checkValidity()) {
                withdrawForm.reportValidity();
                return;
            }

            const method = payoutMethod ? payoutMethod.value : 'wallet';
            const methodLabel = method === 'momo' ? 'Mobile Money' : 'Wallet';
            const amount = parseFloat(amountInput ? amountInput.value || '0' : '0');
            let fee = 0;
            if (method === 'momo') {
                for (let i = 0; i < feeSchedule.length; i += 1) {
                    const band = feeSchedule[i];
                    const min = parseFloat(band.min || 0);
                    const max = band.max === null ? null : parseFloat(band.max);
                    if (amount >= min && (max === null || amount <= max)) {
                        fee = parseFloat(band.fee || 0);
                        break;
                    }
                }
            }
            const net = round2(amount - fee);

            if (confirmMethod) confirmMethod.textContent = methodLabel;
            if (confirmAmount) confirmAmount.textContent = currencySymbol + amount.toFixed(2);
            if (confirmFee) confirmFee.textContent = currencySymbol + fee.toFixed(2);
            if (confirmNet) confirmNet.textContent = currencySymbol + (net < 0 ? 0 : net).toFixed(2);

            if (confirmNetworkRow && confirmNumberRow && confirmNetwork && confirmNumber) {
                const showMomo = method === 'momo';
                confirmNetworkRow.style.display = showMomo ? 'flex' : 'none';
                confirmNumberRow.style.display = showMomo ? 'flex' : 'none';
                confirmNetwork.textContent = payoutNetwork ? payoutNetwork.value || '-' : '-';
                confirmNumber.textContent = payoutNumber ? payoutNumber.value || '-' : '-';
            }

            confirmModal.classList.add('show');
            confirmModal.setAttribute('aria-hidden', 'false');
        });

        confirmModal.addEventListener('click', function(event) {
            if (event.target && event.target.getAttribute('data-close') === '1') {
                confirmModal.classList.remove('show');
                confirmModal.setAttribute('aria-hidden', 'true');
            }
        });

        if (confirmCancel) {
            confirmCancel.addEventListener('click', function() {
                confirmModal.classList.remove('show');
                confirmModal.setAttribute('aria-hidden', 'true');
            });
        }

        if (confirmSubmit) {
            confirmSubmit.addEventListener('click', function() {
                confirmModal.classList.remove('show');
                confirmModal.setAttribute('aria-hidden', 'true');
                withdrawForm.submit();
            });
        }
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

