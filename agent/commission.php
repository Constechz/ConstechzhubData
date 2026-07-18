<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

// Require agent role
requireRole('agent');

$current_user = getCurrentUser();
$agent_id = $current_user['id'];

$error = '';
$success = '';

// Handle liquidation request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'request_liquidation') {
            $amount = floatval($_POST['amount']);
            $method = sanitize($_POST['method'] ?? 'wallet_credit');
            $notes = sanitize($_POST['notes'] ?? '');
            
            $result = createCommissionLiquidation($agent_id, $amount, $method, $notes);
            
            if ($result['success']) {
                $success = $result['message'] . ' (Reference: ' . $result['reference'] . ')';
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Get commission data
$pending_commission = getAgentPendingCommission($agent_id);
$liquidated_commission = getAgentLiquidatedCommission($agent_id);
$total_commission = $pending_commission + $liquidated_commission;

// Get commission breakdown by network
$commission_by_network = getAgentCommissionByNetwork($agent_id, 'pending');

// Get liquidation history
$liquidation_history = getAgentLiquidationHistory($agent_id, 10);

// Get recent commission transactions
$stmt = $db->prepare("
    SELECT t.*, bo.id as order_id, dp.name as package_name, n.name as network_name, n.color as network_color
    FROM transactions t
    LEFT JOIN bundle_orders bo ON t.reference = CONCAT('ORDER_', bo.id)
    LEFT JOIN data_packages dp ON bo.package_id = dp.id
    LEFT JOIN networks n ON dp.network_id = n.id
    WHERE t.user_id = ? AND t.commission_earned > 0
    ORDER BY t.created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$recent_commissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
                <div class="nav-section-title">Business</div>
                <div class="nav-item"><a href="at-business.php" class="nav-link"><i class="fas fa-shopping-cart"></i> AT Business</a></div>
                <div class="nav-item"><a href="mtn-business.php" class="nav-link"><i class="fas fa-mobile-alt"></i> MTN Business</a></div>
                <div class="nav-item">
                    <a href="afa-registration.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        AFA Registration
                    </a>
                </div>
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
                <div class="nav-item"><a href="customers.php" class="nav-link"><i class="fas fa-users"></i> My Customers</a></div>
                <div class="nav-item"><a href="customer_topup.php" class="nav-link"><i class="fas fa-plus-circle"></i> Customer Top-up</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Earnings</div>
                <div class="nav-item"><a href="commission.php" class="nav-link active"><i class="fas fa-coins"></i> Commission</a></div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a></div>
                <div class="nav-item"><a href="support.php" class="nav-link"><i class="fas fa-life-ring"></i> Support</a></div>
            </li>
        </ul>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
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

<?php echo renderNotificationSlides('agents'); ?>


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

            <!-- Commission Overview -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-coins text-warning"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">₵<?php echo number_format($total_commission, 2); ?></div>
                        <div class="stat-label">Total Commission Earned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock text-primary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">₵<?php echo number_format($pending_commission, 2); ?></div>
                        <div class="stat-label">Pending Commission</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">₵<?php echo number_format($liquidated_commission, 2); ?></div>
                        <div class="stat-label">Liquidated Commission</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-percentage text-info"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $total_commission > 0 ? number_format(($liquidated_commission / $total_commission) * 100, 1) : 0; ?>%</div>
                        <div class="stat-label">Liquidation Rate</div>
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
                                    <label for="amount" class="form-label">Liquidation Amount (₵)</label>
                                    <input type="number" id="amount" name="amount" class="form-control" 
                                           min="1" max="<?php echo $pending_commission; ?>" step="0.01" 
                                           value="<?php echo $pending_commission; ?>" required>
                                    <div class="form-help">Maximum available: ₵<?php echo number_format($pending_commission, 2); ?></div>
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

                <!-- Commission by Network -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Commission by Network</h3>
                        <p class="widget-subtitle">Breakdown of your pending commission earnings.</p>
                    </div>
                    <div class="widget-content">
                        <?php if (!empty($commission_by_network)): ?>
                            <div class="network-commission-list">
                                <?php foreach ($commission_by_network as $network): ?>
                                    <div class="network-commission-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color);">
                                        <div style="display: flex; align-items: center;">
                                            <div style="width: 12px; height: 12px; border-radius: 50%; background: <?php echo htmlspecialchars($network['network_color']); ?>; margin-right: 8px;"></div>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($network['network_name']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo $network['transaction_count']; ?> transactions</div>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-weight: 500; color: var(--success-color);">₵<?php echo number_format($network['total_commission'], 2); ?></div>
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
                                        <td><code><?php echo htmlspecialchars($liquidation['reference_number']); ?></code></td>
                                        <td>₵<?php echo number_format($liquidation['liquidated_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-secondary">
                                                <?php echo ucfirst(str_replace('_', ' ', $liquidation['liquidation_method'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $liquidation['status'] === 'completed' ? 'success' : 
                                                    ($liquidation['status'] === 'failed' ? 'danger' : 
                                                    ($liquidation['status'] === 'processing' ? 'warning' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst($liquidation['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($liquidation['created_at'])); ?></td>
                                        <td>
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

            <!-- Recent Commission Transactions -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Recent Commission Transactions</h3>
                    <p class="widget-subtitle">Your latest commission-earning transactions.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($recent_commissions)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Package</th>
                                        <th>Network</th>
                                        <th>Amount</th>
                                        <th>Commission</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_commissions as $transaction): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['package_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($transaction['network_name']): ?>
                                                <div style="display: flex; align-items: center;">
                                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo htmlspecialchars($transaction['network_color']); ?>; margin-right: 6px;"></div>
                                                    <?php echo htmlspecialchars($transaction['network_name']); ?>
                                                </div>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>₵<?php echo number_format($transaction['amount'], 2); ?></td>
                                        <td class="text-success">₵<?php echo number_format($transaction['commission_earned'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $transaction['commission_status'] === 'liquidated' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($transaction['commission_status']); ?>
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
                            <h4>No Commission Transactions</h4>
                            <p>Start selling data bundles to earn commission!</p>
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

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
