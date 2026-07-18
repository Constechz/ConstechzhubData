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

        if ($withdrawal_id <= 0) {
            $error = 'Invalid withdrawal request.';
        } elseif (!in_array($action, ['approve', 'reject'], true)) {
            $error = 'Invalid action.';
        } else {
            // Fetch withdrawal details
            $stmt = $db->prepare("SELECT * FROM profit_withdrawals WHERE id = ? LIMIT 1");
            if (!$stmt) {
                $error = 'Failed to load withdrawal request.';
            } else {
                $stmt->bind_param('i', $withdrawal_id);
                $stmt->execute();
                $withdrawal = $stmt->get_result()->fetch_assoc();

                if (!$withdrawal || ($withdrawal['status'] ?? '') !== 'pending') {
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
                        $balance = getWalletBalance($agent_id);
                        if ($balance < $total_debit) {
                            $error = 'Agent wallet balance is insufficient to reserve this withdrawal.';
                        } else {
                            $payout_error = '';
                            $payout_result = null;
                            if ($moolre_payout_auto) {
                                $payout_result = requestMoolreMomoPayout(
                                    $net_payout,
                                    (string) ($withdrawal['payout_network'] ?? ''),
                                    (string) ($withdrawal['payout_number'] ?? ''),
                                    (string) ($withdrawal['payout_name'] ?? ''),
                                    $reference,
                                    $payout_error
                                );
                            }

                            if ($moolre_payout_auto && !$payout_result) {
                                $error = 'Momo payout failed: ' . ($payout_error ?: 'Unknown error.');
                            } else {
                                $debit_note = 'Profit withdrawal payout';
                                if ($fee_amount > 0) {
                                    $debit_note .= ' (fee ' . CURRENCY . number_format($fee_amount, 2) . ')';
                                }
                                $debit_ok = updateWalletBalance($agent_id, $total_debit, 'debit', $reference, $debit_note);
                                if (!$debit_ok) {
                                    $error = 'Failed to debit agent wallet for this payout.';
                                } else {
                                    $gateway_ref = '';
                                    if ($payout_result && is_array($payout_result['data'] ?? null)) {
                                        $gateway_ref = $payout_result['data']['transactid'] ?? $payout_result['data']['transaction_id'] ?? '';
                                    }
                                    $note_suffix = '';
                                    if ($moolre_payout_auto) {
                                        $note_suffix = $gateway_ref ? (' | Moolre ref: ' . $gateway_ref) : '';
                                    } else {
                                        $note_suffix = ' | Manual MoMo payout (API not configured)';
                                    }
                                    $fee_note = $fee_amount > 0 ? (' | Fee: ' . CURRENCY . number_format($fee_amount, 2)) : '';
                                    $payout_note = ' | Net payout: ' . CURRENCY . number_format($net_payout, 2);
                                    $final_notes = trim($admin_notes . $fee_note . $payout_note . $note_suffix);

                                    $stmt = $db->prepare("
                                        UPDATE profit_withdrawals
                                        SET status = 'paid', admin_notes = ?, processed_by = ?, processed_at = NOW()
                                        WHERE id = ? AND status = 'pending'
                                    ");
                                    if ($stmt) {
                                        $stmt->bind_param('sii', $final_notes, $current_user['id'], $withdrawal_id);
                                        $stmt->execute();
                                        if ($stmt->affected_rows > 0) {
                                            $success = $moolre_payout_auto
                                                ? 'Withdrawal paid via MoMo and agent wallet debited.'
                                                : 'Withdrawal marked paid (manual MoMo payout) and agent wallet debited.';
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
                                                        $smsMessage .= " You received {$net_str}. Wallet debited: {$total_str}. Ref: {$reference}. - " . SITE_NAME;
                                                        try {
                                                            sendSMS(formatPhone($agent_phone), $smsMessage, 'profit_withdrawal', $agent_id);
                                                        } catch (Exception $e) {
                                                            error_log('Profit withdrawal SMS failed: ' . $e->getMessage());
                                                        }
                                                    }
                                                }
                                            }
                                        } else {
                                            updateWalletBalance($agent_id, $total_debit, 'credit', $reference . '_REFUND', 'Refund: withdrawal update failed');
                                            $error = 'Failed to update withdrawal status.';
                                        }
                                    } else {
                                        updateWalletBalance($agent_id, $total_debit, 'credit', $reference . '_REFUND', 'Refund: withdrawal update failed');
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
                border: 1px solid var(--border-color, #F1E9DA);
                border-radius: 12px;
                padding: 0.5rem;
                background: #F1E9DA;
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
                color: var(--text-muted, #541388);
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
            background: #2E294E;
            border-color: #2E294E;
        }

        [data-theme="dark"] .table td {
            color: #F1E9DA;
        }

        [data-theme="dark"] .table td::before {
            color: #F1E9DA;
        }

        [data-theme="dark"] .table td code {
            color: #F1E9DA;
            background: #2E294E;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
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
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Commission</div>
                <div class="nav-item"><a href="commission-settings.php" class="nav-link"><i class="fas fa-percentage"></i> Commission Settings</a></div>
                <div class="nav-item"><a href="commission-payout-settings.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Payout Settings</a></div>
                <div class="nav-item"><a href="commission-liquidations.php" class="nav-link"><i class="fas fa-money-check-alt"></i> Liquidations</a></div>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link active"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
                <div class="nav-item"><a href="pwa-settings.php" class="nav-link"><i class="fas fa-mobile-alt"></i> PWA Settings</a></div>
                <div class="nav-item"><a href="sms-settings.php" class="nav-link"><i class="fas fa-sms"></i> SMS Settings</a></div>
            </li>
        </ul>
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
            <?php if (!$moolre_payout_auto): ?>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    Automatic MoMo payout is not configured. Approvals will be recorded as paid after you send MoMo manually.
                </div>
            <?php endif; ?>
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Pending Requests</h3>
                    <p class="widget-subtitle">Requests waiting for approval.</p>
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
                                                <input type="text" name="admin_notes" class="form-control" placeholder="Notes (optional)" style="min-width:180px;">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check"></i> Pay MoMo
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
                                            $badge = $status === 'approved' || $status === 'paid' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning');
                                            ?>
                                            <span class="badge badge-<?php echo $badge; ?>"><?php echo ucfirst($status); ?></span>
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
