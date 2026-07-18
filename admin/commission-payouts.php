<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

// Require admin role
requireRole('admin');

$error = '';
$success = '';

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
                    $error = 'Payout amount cannot exceed pending commission of ???' . number_format($pending_commission, 2);
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
                        
                        // Update commission status to liquidated
                        $stmt = $db->prepare("
                            UPDATE transactions 
                            SET commission_status = 'liquidated' 
                            WHERE user_id = ? AND commission_status = 'pending' 
                            ORDER BY created_at ASC 
                            LIMIT ?
                        ");
                        
                        // Calculate how many transactions to mark as liquidated
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
                        
                        $db->getConnection()->commit();
                        
                        // Get agent name for success message
                        $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                        $stmt->bind_param("i", $agent_id);
                        $stmt->execute();
                        $agent_name = $stmt->get_result()->fetch_assoc()['full_name'];
                        
                        $success = "Successfully paid out ???" . number_format($payout_amount, 2) . " to " . htmlspecialchars($agent_name);
                        
                    } catch (Exception $e) {
                        $db->getConnection()->rollback();
                        $error = 'Failed to process payout: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Get agents with pending commission
$stmt = $db->query("
    SELECT u.id, u.full_name, u.email,
           SUM(CASE WHEN t.commission_status = 'pending' THEN t.commission_earned ELSE 0 END) as pending_commission,
           COUNT(CASE WHEN t.commission_status = 'pending' THEN 1 END) as pending_transactions
    FROM users u
    LEFT JOIN transactions t ON u.id = t.user_id AND t.commission_earned > 0
    WHERE u.role = 'agent'
    GROUP BY u.id, u.full_name, u.email
    HAVING pending_commission > 0
    ORDER BY pending_commission DESC
");
$agents_with_commission = $stmt->fetch_all(MYSQLI_ASSOC);

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
    <title>Manual Commission Payouts - <?php echo SITE_NAME; ?></title>
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
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link active"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
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

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-wallet"></i></div>
                    <div class="breadcrumb-item">Commission</div>
                    <div class="breadcrumb-item active">Manual Payouts</div>
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
                <h1>Manual Commission Payouts</h1>
                <p class="page-subtitle">Manually credit agent commissions to their wallets.</p>
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

            <!-- Manual Payout Form -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Process Manual Payout</h3>
                    <p class="widget-subtitle">Credit commission earnings directly to an agent's wallet.</p>
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
                                            ???<?php echo number_format($agent['pending_commission'], 2); ?> pending
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text">Only agents with pending commission are shown.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="payout_amount" class="form-label">Payout Amount (???)</label>
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
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Pending Commission</th>
                                        <th>Transactions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agents_with_commission as $agent): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($agent['full_name']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($agent['email']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 500; color: var(--brand-primary);">
                                                ???<?php echo number_format($agent['pending_commission'], 2); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($agent['pending_transactions']); ?> transactions</td>
                                        <td>
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
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Agent</th>
                                        <th>Amount</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_payouts as $payout): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($payout['reference_number']); ?></code></td>
                                        <td><?php echo htmlspecialchars($payout['agent_name']); ?></td>
                                        <td>???<?php echo number_format($payout['commission_amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payout['processed_by_name'] ?? 'System'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($payout['created_at'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $payout['status'] === 'completed' ? 'success' : 
                                                    ($payout['status'] === 'failed' ? 'danger' : 'warning'); 
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
        infoText.textContent = `Available: ???${commission.toFixed(2)} from ${transactions} transactions`;
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



