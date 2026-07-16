<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

// Require agent role
requireRole('vip');

$current_user = getCurrentUser();
$agent_id = $current_user['id'];

$error = '';
$success = '';

$commission_settings = function_exists('getAgentCommissionSettings')
    ? getAgentCommissionSettings()
    : ['program_enabled' => false, 'start_at' => '', 'active_now' => false];
$store_profit_total = 0.0;
$modern_profit_total = 0.0;
$data_commission = 0.0;
$checker_commission = 0.0;
$afa_commission = 0.0;
$pending_requests_total = 0.0;
$liquidated_commission = 0.0;
$commission_by_source = [];
$recent_commissions = [];
$liquidation_history = [];

if (function_exists('dbh_table_exists') && dbh_table_exists('agent_profits')) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(profit_amount), 0) AS total FROM agent_profits WHERE agent_id = ? AND status = 'earned'");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $store_profit_total = (float) ($row['total'] ?? 0);
            $modern_profit_total = $store_profit_total;
        }
        $stmt->close();
    }
}

if (function_exists('ensureAgentCommissionTables')) {
    ensureAgentCommissionTables();
}

if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
    $stmt = $db->prepare("
        SELECT source_type, COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount
        FROM agent_commissions
        WHERE agent_id = ? AND status <> 'cancelled'
        GROUP BY source_type
    ");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));
            $amount = (float) ($row['total_amount'] ?? 0);
            $count = (int) ($row['total_count'] ?? 0);

            if ($sourceType === 'data') {
                $data_commission = $amount;
                $label = 'Data Commission';
                $color = '#2563eb';
                $icon = 'fa-wifi';
            } elseif ($sourceType === 'checker') {
                $checker_commission = $amount;
                $label = 'Result Checker Commission';
                $color = '#f59e0b';
                $icon = 'fa-award';
            } elseif ($sourceType === 'afa') {
                $afa_commission = $amount;
                $label = 'AFA Commission';
                $color = '#059669';
                $icon = 'fa-id-card';
            } else {
                $label = ucfirst($sourceType) . ' Commission';
                $color = '#64748b';
                $icon = 'fa-coins';
            }

            $commission_by_source[] = [
                'label' => $label,
                'amount' => $amount,
                'count' => $count,
                'icon' => $icon,
                'color' => $color,
            ];
        }
        $stmt->close();
    }

    $stmt = $db->prepare("
        SELECT source_type, source_reference, amount, quantity, rate_snapshot, status, earned_at, notes
        FROM agent_commissions
        WHERE agent_id = ?
        ORDER BY earned_at DESC, id DESC
        LIMIT 20
    ");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));
            if ($sourceType === 'data') {
                $sourceLabel = 'Data Commission';
            } elseif ($sourceType === 'checker') {
                $sourceLabel = 'Result Checker';
            } elseif ($sourceType === 'afa') {
                $sourceLabel = 'AFA Registration';
            } else {
                $sourceLabel = ucfirst($sourceType);
            }

            $itemLabel = trim((string) ($row['notes'] ?? ''));
            if ($itemLabel === '') {
                $itemLabel = (string) ($row['source_reference'] ?? 'Commission record');
            }

            $recent_commissions[] = [
                'created_at' => $row['earned_at'] ?? null,
                'source_label' => $sourceLabel,
                'item_label' => $itemLabel,
                'amount' => (float) ($row['amount'] ?? 0),
                'commission_amount' => (float) ($row['amount'] ?? 0),
                'status_label' => ucfirst(strtolower((string) ($row['status'] ?? 'earned'))),
                'commission_earned' => (float) ($row['amount'] ?? 0),
                'commission_status' => strtolower((string) ($row['status'] ?? 'earned')),
            ];
        }
        $stmt->close();
    }
}

$commission_by_network = $commission_by_source;

$total_commission = round($data_commission + $checker_commission + $afa_commission, 2);

$hasCommissionLiquidations = function_exists('dbh_table_exists') && dbh_table_exists('commission_liquidations');
if ($hasCommissionLiquidations) {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN liquidated_amount ELSE 0 END), 0) AS pending_total,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN liquidated_amount ELSE 0 END), 0) AS completed_total
        FROM commission_liquidations
        WHERE agent_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $pending_requests_total = (float) ($row['pending_total'] ?? 0);
            $liquidated_commission = (float) ($row['completed_total'] ?? 0);
        }
        $stmt->close();
    }

    if (function_exists('getAgentLiquidationHistory')) {
        $liquidation_history = getAgentLiquidationHistory($agent_id, 10);
    }
}

$liquidated_commission = max(
    $liquidated_commission,
    function_exists('getAgentLiquidatedCommission') ? (float) getAgentLiquidatedCommission($agent_id) : 0.0
);
$pending_commission = function_exists('getAgentPendingCommission')
    ? (float) getAgentPendingCommission($agent_id)
    : max(0, round($total_commission - $pending_requests_total - $liquidated_commission, 2));

// Handle liquidation request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_liquidation') {
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $method = sanitize($_POST['method'] ?? 'wallet_credit');
            $notes = sanitize($_POST['notes'] ?? '');
            $allowed_methods = ['wallet_credit', 'mobile_money', 'bank_transfer'];
            if (!in_array($method, $allowed_methods, true)) {
                $method = 'wallet_credit';
            }

            if ($amount > $pending_commission) {
                $error = 'Insufficient pending commission.';
            } elseif ($amount < 1.00) {
                $error = 'Minimum liquidation amount is ' . CURRENCY . '1.00';
            } else {
                $reference = function_exists('generateReference')
                    ? generateReference('LIQ')
                    : ('LIQ_' . time() . '_' . $agent_id);
                $remaining = max(0, round($pending_commission - $amount, 2));
                $stmt = $db->prepare("
                    INSERT INTO commission_liquidations
                    (agent_id, total_commission, liquidated_amount, remaining_commission, liquidation_method, reference_number, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt) {
                    $stmt->bind_param('idddsss', $agent_id, $pending_commission, $amount, $remaining, $method, $reference, $notes);
                    if ($stmt->execute()) {
                        $success = 'Liquidation request created successfully (Reference: ' . $reference . ')';
                        $pending_requests_total = round($pending_requests_total + $amount, 2);
                        $pending_commission = max(0, round($pending_commission - $amount, 2));
                    } else {
                        $error = 'Failed to create liquidation request.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Failed to prepare liquidation request.';
                }
            }
        }
    }
}

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
    <title>Commission - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        html,
        body {
            overflow-x: hidden;
        }

        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .grid-2,
        .widget,
        .stats-grid,
        .stat-card,
        .header-actions,
        .user-dropdown {
            min-width: 0;
            max-width: 100%;
        }

        @media (max-width: 768px) {
            .header-actions {
                margin-right: 0;
            }

            .alert {
                overflow-wrap: anywhere;
            }

            .network-commission-item {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.65rem;
            }

            .network-commission-item > div:last-child {
                text-align: left !important;
            }

            .table-responsive {
                overflow: visible;
            }

            .table {
                width: 100%;
                min-width: 0 !important;
            }

            .table thead {
                display: none;
            }

            .table tbody,
            .table tr,
            .table td {
                display: block;
                width: 100%;
            }

            .table tr {
                padding: 0.95rem 0;
                border-bottom: 1px solid var(--border-color);
            }

            .table td {
                padding: 0.35rem 0;
                border: none;
                text-align: left;
                overflow-wrap: anywhere;
            }

            .table td::before {
                content: attr(data-label);
                display: block;
                margin-bottom: 0.15rem;
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--text-muted);
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
        }
    </style>
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
                    <div class="breadcrumb-item"><i class="fas fa-coins"></i></div>
                    <div class="breadcrumb-item active">Commission</div>
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
                <h1>Commission Management</h1>
                <p class="page-subtitle">Track and liquidate your commission earnings.</p>
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

            <div class="alert alert-info" style="margin-bottom: 1rem;">
                This page shows your admin-configured commissions from direct data purchases, result checker purchases, and AFA registrations.
                Store-link resale profit for guest and customer orders appears on
                <a href="withdraw-profit.php" style="font-weight:600; text-decoration:underline;">Store Profit</a>.
                <?php if ($modern_profit_total > 0): ?>
                    Your current store-link profit there is <strong><?php echo CURRENCY . number_format($modern_profit_total, 2); ?></strong>.
                <?php endif; ?>
                <?php if (empty($commission_settings['program_enabled'])): ?>
                    Commission recording is currently turned off by admin.
                <?php elseif (!empty($commission_settings['start_at']) && empty($commission_settings['active_now'])): ?>
                    Commission recording starts on <strong><?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $commission_settings['start_at']))); ?></strong>.
                <?php endif; ?>
                <span style="display:block; margin-top: 0.35rem;">
                    <strong>Total Commission Earned</strong> is lifetime gross commission and does not reduce after liquidation.
                    <strong>Available for Liquidation</strong> is what remains after paid out amounts and pending requests.
                </span>
            </div>

            <!-- Commission Overview -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-coins text-warning"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($total_commission, 2); ?></div>
                        <div class="stat-label">Total Commission Earned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock text-primary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($pending_commission, 2); ?></div>
                        <div class="stat-label">Available for Liquidation</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($liquidated_commission, 2); ?></div>
                        <div class="stat-label">Paid Out</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-hourglass-half text-info"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($pending_requests_total, 2); ?></div>
                        <div class="stat-label">Pending Requests</div>
                    </div>
                </div>
            </div>

            <div class="grid-2">
                <!-- Commission Liquidation -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Request Commission Liquidation</h3>
                        <p class="widget-subtitle">Convert your pending commission to wallet credit or request withdrawal.</p>
                    </div>
                    <div class="widget-content">
                        <?php if ($pending_commission > 0): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="request_liquidation">
                                
                                <div class="form-group">
                                    <label for="amount" class="form-label">Liquidation Amount (<?php echo htmlspecialchars(CURRENCY); ?>)</label>
                                    <input type="number" id="amount" name="amount" class="form-control"
                                           min="1" max="<?php echo $pending_commission; ?>" step="0.01"
                                           value="<?php echo $pending_commission; ?>" readonly required>
                                    <div class="form-help">Maximum available: <?php echo CURRENCY . number_format($pending_commission, 2); ?></div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="method" class="form-label">Liquidation Method</label>
                                    <select id="method" name="method" class="form-control" required>
                                        <option value="wallet_credit">Wallet Credit (Instant)</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="notes" class="form-label">Notes (Optional)</label>
                                    <textarea id="notes" name="notes" class="form-control" rows="3" 
                                              placeholder="Additional information for withdrawal methods..."></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Request Liquidation
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-coins" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                                <h4>No Pending Commission</h4>
                                <p>You don't have any pending commission to liquidate. Start selling to earn commission!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Commission by Source -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Commission by Source</h3>
                        
                    </div>
                    <div class="widget-content">
                        <?php if (!empty($commission_by_network)): ?>
                            <div class="network-commission-list">
                                <?php foreach ($commission_by_network as $network): ?>
                                    <div class="network-commission-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div style="display: flex; align-items: center;">
                                            <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($network['color'] ?? '#64748b'); ?>; margin-right: 8px;"></div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($network['label'] ?? ''); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo (int)($network['count'] ?? 0); ?> transactions</div>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 500; color: var(--success-color);"><?php echo CURRENCY . number_format((float)($network['amount'] ?? 0), 2); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-chart-pie" style="font-size: 2rem; color: var(--text-muted); margin-bottom: 0.5rem;"></i>
                                <p>No commission data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Liquidation History -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Liquidation History</h3>
                    <p class="widget-subtitle">Track your commission liquidation requests and status.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($liquidation_history)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Processed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($liquidation_history as $liquidation): ?>
                                    <tr>
                                        <td data-label="Reference"><code><?php echo htmlspecialchars($liquidation['reference_number']); ?></code></td>
                                        <td data-label="Amount"><?php echo CURRENCY . number_format($liquidation['liquidated_amount'], 2); ?></td>
                                        <td data-label="Method">
                                            <span class="badge badge-secondary">
                                                <?php echo ucfirst(str_replace('_', ' ', $liquidation['liquidation_method'])); ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php 
                                                echo $liquidation['status'] === 'completed' ? 'success' : 
                                                    ($liquidation['status'] === 'failed' ? 'danger' : 
                                                    ($liquidation['status'] === 'processing' ? 'warning' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst($liquidation['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Date"><?php echo date('M j, Y', strtotime($liquidation['created_at'])); ?></td>
                                        <td data-label="Processed">
                                            <?php if ($liquidation['processed_at']): ?>
                                                <?php echo date('M j, Y', strtotime($liquidation['processed_at'])); ?>
                                                <?php if ($liquidation['processed_by_name']): ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($liquidation['processed_by_name']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Liquidation History</h4>
                            <p>You haven't made any liquidation requests yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Commission Activity -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Recent Commission Activity</h3>
                    
                </div>
                <div class="widget-content">
                    <?php if (!empty($recent_commissions)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Source</th>
                                        <th>Item</th>
                                        <th>Amount</th>
                                        <th>Commission</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_commissions as $transaction): ?>
                                    <tr>
                                        <td data-label="Date"><?php echo date('M j, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                        <td data-label="Source"><?php echo htmlspecialchars($transaction['source_label'] ?? ($transaction['network_name'] ?? 'Commission')); ?></td>
                                        <td data-label="Item"><?php echo htmlspecialchars($transaction['item_label'] ?? ($transaction['package_name'] ?? 'N/A')); ?></td>
                                        <td data-label="Amount"><?php echo CURRENCY . number_format($transaction['amount'], 2); ?></td>
                                        <td data-label="Commission" class="text-success"><?php echo CURRENCY . number_format($transaction['commission_earned'], 2); ?></td>
                                        <td data-label="Status">
                                            <?php $status_label = strtolower((string) ($transaction['status_label'] ?? $transaction['commission_status'] ?? 'earned')); ?>
                                            <span class="badge badge-<?php echo in_array($status_label, ['success', 'completed', 'earned'], true) ? 'success' : ($status_label === 'pending' ? 'warning' : 'secondary'); ?>">
                                                <?php echo htmlspecialchars($transaction['status_label'] ?? ucfirst((string) ($transaction['commission_status'] ?? 'earned'))); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-receipt" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Commission Activity</h4>
                            <p>Your admin-configured commission earnings will appear here once you make eligible sales.</p>
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
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
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
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>

