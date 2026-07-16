<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

// Require admin role
requireRole('admin');

if (function_exists('ensureAgentCommissionTables')) {
    ensureAgentCommissionTables();
}
if (function_exists('ensureCommissionPayoutTables')) {
    ensureCommissionPayoutTables();
}

$error = '';
$success = '';
$current_user = getCurrentUser();
$current_minimum_payout = (float) getCommissionPayoutSetting('global_minimum_payout', '0');
$current_auto_payout_enabled = getCommissionPayoutSetting('auto_payout_enabled', 'false') === 'true';
$paystack_payout_auto = function_exists('isPaystackTransferAutomationAvailable') && isPaystackTransferAutomationAvailable();
$moolre_payout_url = trim((string) dbh_env('MOOLRE_PAYOUT_URL', defined('MOOLRE_PAYOUT_URL') ? MOOLRE_PAYOUT_URL : ''));
$moolre_config = function_exists('getMoolreConfig') ? getMoolreConfig() : [];
$moolre_payout_auto = $moolre_payout_url !== '' && function_exists('isMoolreConfigured') && isMoolreConfigured($moolre_config);
$available_auto_payout_routes = [];
if ($paystack_payout_auto) {
    $available_auto_payout_routes['paystack_auto'] = 'Automatic Paystack';
}
if ($moolre_payout_auto) {
    $available_auto_payout_routes['moolre_auto'] = 'Automatic Moolre';
}
$default_auto_payout_route = $paystack_payout_auto ? 'paystack_auto' : ($moolre_payout_auto ? 'moolre_auto' : '');

$loadAgentsWithCommission = static function () use ($db) {
    $stmt = $db->query("
        SELECT u.id, u.full_name, u.email,
               GREATEST(
                   0,
                   COALESCE(SUM(CASE WHEN ac.status = 'earned' THEN ac.amount ELSE 0 END), 0)
                   - COALESCE(cl.reserved_commission, 0)
               ) as pending_commission,
               COUNT(CASE WHEN ac.status = 'earned' THEN 1 END) as pending_transactions
        FROM users u
        LEFT JOIN agent_commissions ac ON u.id = ac.agent_id AND ac.amount > 0
        LEFT JOIN (
            SELECT agent_id, COALESCE(SUM(liquidated_amount), 0) AS reserved_commission
            FROM commission_liquidations
            WHERE status IN ('pending', 'processing')
            GROUP BY agent_id
        ) cl ON cl.agent_id = u.id
        WHERE u.role = 'agent'
        GROUP BY u.id, u.full_name, u.email
        HAVING pending_commission > 0
        ORDER BY pending_commission DESC
    ");

    return $stmt ? $stmt->fetch_all(MYSQLI_ASSOC) : [];
};

// Handle manual payout processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'process_manual_payout') {
            $agent_id = intval($_POST['agent_id']);
            $payout_amount = floatval($_POST['payout_amount']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            if ($agent_id <= 0) {
                $error = 'Please select a valid agent.';
            } elseif ($payout_amount <= 0 || $payout_amount > 10000) {
                $error = 'Payout amount must be between 0.01 and 10,000.';
            } else {
                // Get agent's pending commission
                $pending_commission = getAgentPendingCommission($agent_id);
                
                if ($payout_amount > $pending_commission) {
                    $error = 'Payout amount cannot exceed pending commission of ' . CURRENCY . number_format($pending_commission, 2);
                } else {
                    $db->getConnection()->begin_transaction();
                    
                    try {
                        // Generate reference number
                        $reference = 'PAYOUT_' . strtoupper(uniqid());
                        
                        // Create payout record
                        $stmt = $db->prepare("
                            INSERT INTO commission_payouts (agent_id, commission_amount, payout_method, reference_number, status, processed_by, notes, processed_at)
                            VALUES (?, ?, 'wallet_credit', ?, 'completed', ?, ?, NOW())
                        ");
                        $stmt->bind_param("idsss", $agent_id, $payout_amount, $reference, $_SESSION['user_id'], $notes);
                        $stmt->execute();
                        
                        // Credit agent's wallet
                        require_once '../includes/functions.php';
                        updateWalletBalance($agent_id, $payout_amount, 'credit', $reference, 'Commission payout: ' . $notes);
                        
                        if (function_exists('liquidateAgentCommissionRows') && function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
                            liquidateAgentCommissionRows($agent_id, $payout_amount);
                        } else {
                            // Legacy commission rows stored on transactions.
                            $remaining_amount = $payout_amount;
                            $stmt = $db->prepare("
                                SELECT id, commission_earned
                                FROM transactions
                                WHERE user_id = ? AND commission_status = 'pending' AND commission_earned > 0
                                ORDER BY created_at ASC
                            ");
                            $stmt->bind_param("i", $agent_id);
                            $stmt->execute();
                            $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                            foreach ($transactions as $transaction) {
                                if ($remaining_amount <= 0) break;

                                $commission_to_liquidate = min($remaining_amount, $transaction['commission_earned']);

                                $stmt = $db->prepare("UPDATE transactions SET commission_status = 'liquidated' WHERE id = ?");
                                $stmt->bind_param("i", $transaction['id']);
                                $stmt->execute();

                                $remaining_amount -= $commission_to_liquidate;
                            }
                        }
                        
                        $db->getConnection()->commit();
                        
                        // Get agent name for success message
                        $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                        $stmt->bind_param("i", $agent_id);
                        $stmt->execute();
                        $agent_name = $stmt->get_result()->fetch_assoc()['full_name'];
                        
                        $success = "Successfully paid out " . CURRENCY . number_format($payout_amount, 2) . " to " . htmlspecialchars($agent_name);
                        
                    } catch (Exception $e) {
                        $db->getConnection()->rollback();
                        $error = 'Failed to process payout: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'process_bulk_auto_payout') {
            $payout_route = trim((string) ($_POST['payout_route'] ?? $default_auto_payout_route));
            $notes = sanitize($_POST['notes'] ?? '');

            if ($payout_route === '' || !array_key_exists($payout_route, $available_auto_payout_routes)) {
                $error = 'Select a valid automatic payout route.';
            } else {
                $bulkAgents = $loadAgentsWithCommission();
                $completedCount = 0;
                $processingCount = 0;
                $failedCount = 0;
                $skippedCount = 0;
                $completedAmount = 0.0;
                $processingAmount = 0.0;
                $issues = [];

                foreach ($bulkAgents as $agent) {
                    $amount = round((float) ($agent['pending_commission'] ?? 0), 2);
                    if ($amount <= 0) {
                        continue;
                    }

                    if ($current_minimum_payout > 0 && $amount < $current_minimum_payout) {
                        $skippedCount++;
                        $issues[] = $agent['full_name'] . ' skipped: below minimum auto payout of ' . CURRENCY . number_format($current_minimum_payout, 2) . '.';
                        continue;
                    }

                    $profile = getAgentCommissionPayoutProfile((int) $agent['id']);
                    if (empty($profile['ready'])) {
                        $skippedCount++;
                        $issues[] = $agent['full_name'] . ' skipped: ' . implode(', ', (array) ($profile['issues'] ?? [])) . '.';
                        continue;
                    }

                    $result = submitCommissionAutomaticPayout((int) $agent['id'], $amount, $payout_route, (int) ($current_user['id'] ?? 0), $notes);
                    $status = strtolower(trim((string) ($result['status'] ?? 'failed')));

                    if ($status === 'completed') {
                        $completedCount++;
                        $completedAmount += $amount;
                    } elseif ($status === 'processing') {
                        $processingCount++;
                        $processingAmount += $amount;
                    } else {
                        $failedCount++;
                        $issues[] = $agent['full_name'] . ': ' . (string) ($result['message'] ?? 'Automatic payout failed.');
                    }
                }

                if ($completedCount === 0 && $processingCount === 0 && $skippedCount === 0 && $failedCount === 0) {
                    $error = 'No agents are currently eligible for automatic payout.';
                } else {
                    $summary = [];
                    if ($completedCount > 0) {
                        $summary[] = $completedCount . ' completed (' . CURRENCY . number_format($completedAmount, 2) . ')';
                    }
                    if ($processingCount > 0) {
                        $summary[] = $processingCount . ' processing (' . CURRENCY . number_format($processingAmount, 2) . ')';
                    }
                    if ($skippedCount > 0) {
                        $summary[] = $skippedCount . ' skipped';
                    }
                    if ($failedCount > 0) {
                        $summary[] = $failedCount . ' failed';
                    }

                    $success = 'Bulk automatic payout run finished via ' . $available_auto_payout_routes[$payout_route] . ': ' . implode(', ', $summary) . '.';
                    if (!empty($issues)) {
                        $error = implode(' ', array_slice($issues, 0, 6));
                    }
                }
            }
        }
    }
}

$agents_with_commission = $loadAgentsWithCommission();

$total_pending_commission = 0.0;
$total_pending_transactions = 0;
$auto_ready_agents = 0;
$auto_ready_amount = 0.0;
$missing_payout_agents = 0;

foreach ($agents_with_commission as &$agent) {
    $agent['pending_commission'] = round((float) ($agent['pending_commission'] ?? 0), 2);
    $agent['pending_transactions'] = (int) ($agent['pending_transactions'] ?? 0);
    $agent['payout_profile'] = getAgentCommissionPayoutProfile((int) $agent['id']);
    $agent['meets_minimum'] = $current_minimum_payout <= 0 || $agent['pending_commission'] >= $current_minimum_payout;
    $agent['auto_payout_ready'] = !empty($agent['payout_profile']['ready']) && $agent['meets_minimum'];

    $total_pending_commission += $agent['pending_commission'];
    $total_pending_transactions += $agent['pending_transactions'];

    if ($agent['auto_payout_ready']) {
        $auto_ready_agents++;
        $auto_ready_amount += $agent['pending_commission'];
    } elseif (empty($agent['payout_profile']['ready'])) {
        $missing_payout_agents++;
    }
}
unset($agent);

$agents_awaiting_payout = count($agents_with_commission);

// Get recent payouts
$stmt = $db->query("
    SELECT cp.*, u.full_name as agent_name, admin.full_name as processed_by_name
    FROM commission_payouts cp
    JOIN users u ON cp.agent_id = u.id
    LEFT JOIN users admin ON cp.processed_by = admin.id
    ORDER BY cp.created_at DESC
    LIMIT 20
");
$recent_payouts = $stmt->fetch_all(MYSQLI_ASSOC);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Payouts - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .commission-payouts-page .widget-content {
            padding: var(--spacing-lg);
        }

        .commission-payouts-page .widget-header {
            align-items: flex-start;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
        }

        .commission-payouts-page .widget-subtitle {
            margin: 0;
            color: var(--text-secondary);
            max-width: 60ch;
        }

        .commission-payouts-page .alert {
            overflow-wrap: anywhere;
        }

        .commission-payouts-page .table td,
        .commission-payouts-page .table th {
            vertical-align: top;
        }

        .commission-payouts-page .table code {
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .commission-payouts-page .table .btn {
            width: 100%;
        }

        .commission-payouts-page .payout-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
        }

        .commission-payouts-page .payout-chip.ready {
            background: rgba(34, 197, 94, 0.12);
            color: #15803d;
        }

        .commission-payouts-page .payout-chip.missing {
            background: rgba(239, 68, 68, 0.12);
            color: #b91c1c;
        }

        .commission-payouts-page .payout-chip.waiting {
            background: rgba(245, 158, 11, 0.14);
            color: #b45309;
        }

        .commission-payouts-page .payout-meta {
            display: grid;
            gap: 0.25rem;
        }

        .commission-payouts-page .inline-list {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .commission-payouts-page .inline-list strong {
            color: var(--text-primary);
        }

        .commission-payouts-page .widget-header-actions {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            flex-wrap: wrap;
        }

        @media (min-width: 769px) {
            .commission-payouts-page .table-responsive {
                overflow-x: visible;
            }

            .commission-payouts-page .table {
                min-width: 0;
                table-layout: fixed;
            }

            .commission-payouts-page .table td,
            .commission-payouts-page .table th {
                white-space: normal;
                overflow-wrap: anywhere;
            }

            .commission-payouts-page .table .btn {
                width: auto;
                max-width: 100%;
                white-space: normal;
            }
        }

        @media (max-width: 768px) {
            .commission-payouts-page .widget-content {
                padding: var(--spacing-md);
            }

            .commission-payouts-page .page-title {
                margin-bottom: var(--spacing-lg);
            }

            .commission-payouts-page .page-subtitle,
            .commission-payouts-page .form-text,
            .commission-payouts-page .widget-subtitle {
                overflow-wrap: anywhere;
            }

            .commission-payouts-page .table-responsive {
                margin-left: 0;
                margin-right: 0;
                border-left: 1px solid var(--border-color);
                border-right: 1px solid var(--border-color);
            }

            .commission-payouts-page .responsive-table-stack tr {
                padding: var(--spacing-md);
            }

            .commission-payouts-page .responsive-table-stack td[data-label="Actions"] {
                padding-top: var(--spacing-sm);
            }

            .commission-payouts-page .widget-header-actions,
            .commission-payouts-page .widget-header-actions .payout-chip {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .commission-payouts-page .widget-content {
                padding: var(--spacing-sm);
            }

            .commission-payouts-page .responsive-table-stack tr {
                padding: var(--spacing-sm);
            }

            .commission-payouts-page .form-actions .btn,
            .commission-payouts-page .table .btn {
                min-height: 44px;
            }
        }
    </style>
</head>
<body class="commission-payouts-page">
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
                    <?php renderAdminSidebar(); ?>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-wallet"></i></div>
                    <div class="breadcrumb-item">Commission</div>
                    <div class="breadcrumb-item active">Payouts</div>
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
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                            <div class="user-role">Administrator</div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow" style="margin-left: 0.5rem;"></i>
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
                <h1>Commission Payouts</h1>
                <p class="page-subtitle">Run wallet payouts manually or send eligible agent commissions to mobile money in bulk.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo CURRENCY . number_format($total_pending_commission, 2); ?></h3>
                        <p>Total commission currently pending payout</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($agents_awaiting_payout); ?></h3>
                        <p>Agents awaiting payout</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($total_pending_transactions); ?></h3>
                        <p>Commission entries waiting to be paid</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($auto_ready_agents); ?></h3>
                        <p>Agents ready for automatic MoMo payout</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($missing_payout_agents); ?></h3>
                        <p>Agents missing payout details</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Bulk Automatic Payout -->
            <div class="widget">
                <div class="widget-header">
                    <div>
                        <h3 class="widget-title">Bulk Automatic MoMo Payout</h3>
                        <p class="widget-subtitle">Uses each agent's saved payment settings and pays the full pending commission for agents who are ready.</p>
                    </div>
                    <div class="widget-header-actions">
                        <?php if ($current_auto_payout_enabled): ?>
                            <span class="payout-chip ready"><i class="fas fa-bolt"></i> Auto payout enabled</span>
                        <?php else: ?>
                            <span class="payout-chip waiting"><i class="fas fa-sliders-h"></i> Auto payout setting is off</span>
                        <?php endif; ?>
                        <span class="payout-chip <?php echo !empty($available_auto_payout_routes) ? 'ready' : 'missing'; ?>">
                            <i class="fas fa-route"></i>
                            <?php echo !empty($available_auto_payout_routes) ? count($available_auto_payout_routes) . ' route(s) available' : 'No automatic route configured'; ?>
                        </span>
                    </div>
                </div>
                <div class="widget-content">
                    <p class="inline-list">
                        <strong>Eligible now:</strong> <?php echo number_format($auto_ready_agents); ?> agents,
                        <?php echo CURRENCY . number_format($auto_ready_amount, 2); ?> total
                        <?php if ($current_minimum_payout > 0): ?>
                            , minimum auto payout <?php echo CURRENCY . number_format($current_minimum_payout, 2); ?>
                        <?php endif; ?>
                    </p>
                    <p class="inline-list">
                        Agents should save their MoMo network, account name, and number in <a href="../agent/payment-settings.php">Payment Settings</a>.
                    </p>

                    <form method="post" class="form" style="margin-top: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="process_bulk_auto_payout">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="payout_route" class="form-label">Automatic Payout Route</label>
                                <select id="payout_route" name="payout_route" class="form-control" <?php echo empty($available_auto_payout_routes) ? 'disabled' : ''; ?> required>
                                    <?php if (empty($available_auto_payout_routes)): ?>
                                        <option value="">No route configured</option>
                                    <?php else: ?>
                                        <?php foreach ($available_auto_payout_routes as $routeKey => $routeLabel): ?>
                                            <option value="<?php echo htmlspecialchars($routeKey); ?>" <?php echo $routeKey === $default_auto_payout_route ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($routeLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <small class="form-text">Paystack stays in processing until its transfer webhook confirms the final result.</small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bulk Eligibility</label>
                                <div class="form-control" style="display:flex; align-items:center;">
                                    <?php echo number_format($auto_ready_agents); ?> agents / <?php echo CURRENCY . number_format($auto_ready_amount, 2); ?>
                                </div>
                                <small class="form-text">Only agents with payout details and commissions above the minimum threshold are included.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="bulk_notes" class="form-label">Notes</label>
                            <textarea id="bulk_notes" name="notes" class="form-control" rows="3" placeholder="Optional note to attach to each automatic payout record"></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" <?php echo empty($available_auto_payout_routes) || $auto_ready_agents === 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane"></i> Run Bulk Automatic Payout
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Manual Payout Form -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Process Manual Payout</h3>
                    <p class="widget-subtitle">Credit commission earnings directly to an agent's wallet when you do not want to use automatic MoMo payout.</p>
                </div>
                <div class="widget-content">
                    <form method="post" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="process_manual_payout">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="agent_id" class="form-label">Select Agent</label>
                                <select id="agent_id" name="agent_id" class="form-control" required onchange="updateCommissionInfo()">
                                    <option value="">Choose an agent...</option>
                                    <?php foreach ($agents_with_commission as $agent): ?>
                                        <option value="<?php echo $agent['id']; ?>" 
                                                data-commission="<?php echo $agent['pending_commission']; ?>"
                                                data-transactions="<?php echo $agent['pending_transactions']; ?>">
                                            <?php echo htmlspecialchars($agent['full_name']); ?> - 
                                            <?php echo CURRENCY . number_format($agent['pending_commission'], 2); ?> pending
                                            <?php if (!empty($agent['auto_payout_ready'])): ?> - auto ready<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text">Only agents with pending commission are shown. "Auto ready" means saved MoMo details are available too.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="payout_amount" class="form-label">Payout Amount (<?php echo CURRENCY; ?>)</label>
                                <input type="number" id="payout_amount" name="payout_amount" class="form-control" 
                                       min="0.01" max="10000" step="0.01" required>
                                <small class="form-text" id="commission-info">Select an agent to see available commission.</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3" 
                                      placeholder="Add notes about this payout..."></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-credit-card"></i> Process Payout
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Agents with Pending Commission -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Agents with Pending Commission</h3>
                    <p class="widget-subtitle">Agents who have earned commission waiting for payout.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($agents_with_commission)): ?>
                        <div class="table-responsive">
                            <table class="table responsive-table-stack">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Pending Commission</th>
                                        <th>Transactions</th>
                                        <th>Payout Details</th>
                                        <th>Auto Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agents_with_commission as $agent): ?>
                                    <tr>
                                        <td data-label="Agent">
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['full_name']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            </div>
                                        </td>
                                        <td data-label="Pending Commission">
                                            <span style="font-weight: 500; color: var(--brand-primary);">
                                                <?php echo CURRENCY . number_format($agent['pending_commission'], 2); ?>
                                            </span>
                                        </td>
                                        <td data-label="Transactions"><?php echo number_format($agent['pending_transactions']); ?> transactions</td>
                                        <td data-label="Payout Details">
                                            <div class="payout-meta">
                                                <div><?php echo htmlspecialchars($agent['payout_profile']['name'] ?: 'No account name'); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);">
                                                    <?php
                                                    $details = [];
                                                    if (!empty($agent['payout_profile']['network']) && strtoupper($agent['payout_profile']['network']) !== 'N/A') {
                                                        $details[] = $agent['payout_profile']['network'];
                                                    }
                                                    if (!empty($agent['payout_profile']['number'])) {
                                                        $details[] = $agent['payout_profile']['number'];
                                                    }
                                                    echo htmlspecialchars(!empty($details) ? implode(' | ', $details) : 'No payout details saved');
                                                    ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Auto Status">
                                            <?php if (!empty($agent['auto_payout_ready'])): ?>
                                                <span class="payout-chip ready"><i class="fas fa-check-circle"></i> Ready</span>
                                            <?php elseif (empty($agent['meets_minimum'])): ?>
                                                <span class="payout-chip waiting"><i class="fas fa-hourglass-half"></i> Below minimum</span>
                                            <?php else: ?>
                                                <span class="payout-chip missing"><i class="fas fa-triangle-exclamation"></i> Missing details</span>
                                                <?php if (!empty($agent['payout_profile']['issues'])): ?>
                                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">
                                                        <?php echo htmlspecialchars(implode(', ', $agent['payout_profile']['issues'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <button class="btn btn-sm btn-primary" onclick="selectAgent(<?php echo $agent['id']; ?>)">
                                                <i class="fas fa-credit-card"></i> Pay Out
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Pending Commissions</h4>
                            <p>All agent commissions have been paid out.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Payouts -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Recent Payouts</h3>
                    <p class="widget-subtitle">History of manual commission payouts.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($recent_payouts)): ?>
                        <div class="table-responsive">
                            <table class="table responsive-table-stack">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Agent</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Provider</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payouts as $payout): ?>
                                    <tr>
                                        <td data-label="Reference"><code><?php echo htmlspecialchars($payout['reference_number']); ?></code></td>
                                        <td data-label="Agent"><?php echo htmlspecialchars($payout['agent_name']); ?></td>
                                        <td data-label="Amount"><?php echo CURRENCY . number_format($payout['commission_amount'], 2); ?></td>
                                        <td data-label="Method"><?php echo htmlspecialchars((string) ($payout['payout_method'] ?? 'wallet_credit')); ?></td>
                                        <td data-label="Provider">
                                            <?php
                                            $providerBits = [];
                                            if (!empty($payout['payout_provider'])) {
                                                $providerBits[] = ucfirst((string) $payout['payout_provider']);
                                            }
                                            if (!empty($payout['provider_status'])) {
                                                $providerBits[] = strtolower((string) $payout['provider_status']);
                                            }
                                            echo htmlspecialchars(!empty($providerBits) ? implode(' | ', $providerBits) : 'Manual');
                                            ?>
                                        </td>
                                        <td data-label="Processed By"><?php echo htmlspecialchars($payout['processed_by_name'] ?? 'System'); ?></td>
                                        <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($payout['created_at'])); ?></td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php 
                                                echo $payout['status'] === 'completed' ? 'success' :
                                                    (($payout['status'] === 'failed' || $payout['status'] === 'reversed') ? 'danger' : 'warning');
                                            ?>">
                                                <?php echo ucfirst($payout['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Payouts Yet</h4>
                            <p>No manual commission payouts have been processed.</p>
                        </div>
                    <?php endif; ?>
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
document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('active');
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

// Update commission info when agent is selected
function updateCommissionInfo() {
    const select = document.getElementById('agent_id');
    const amountInput = document.getElementById('payout_amount');
    const infoText = document.getElementById('commission-info');
    
    if (select.value) {
        const option = select.selectedOptions[0];
        const commission = parseFloat(option.dataset.commission);
        const transactions = option.dataset.transactions;
        
        amountInput.max = commission;
        amountInput.value = commission;
        infoText.textContent = `Available: <?php echo CURRENCY; ?>${commission.toFixed(2)} from ${transactions} transactions`;
        infoText.style.color = 'var(--brand-primary)';
    } else {
        amountInput.max = 10000;
        amountInput.value = '';
        infoText.textContent = 'Select an agent to see available commission.';
        infoText.style.color = 'var(--text-muted)';
    }
}

// Select agent from table
function selectAgent(agentId) {
    const select = document.getElementById('agent_id');
    select.value = agentId;
    updateCommissionInfo();
    
    // Scroll to form
    document.querySelector('.widget').scrollIntoView({ behavior: 'smooth' });
}
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



