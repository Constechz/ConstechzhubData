<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

// Require admin role
requireRole('admin');

$error = '';
$success = '';

// Handle liquidation processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'process_liquidation') {
            $liquidation_id = intval($_POST['liquidation_id']);
            $status = sanitize($_POST['status']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            $result = processCommissionLiquidation($liquidation_id, $_SESSION['user_id'], $status, $notes);
            
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Get all pending liquidations
$pending_liquidations = getPendingLiquidations();

// Get processed liquidations (last 50)
$stmt = $db->prepare("
    SELECT cl.*, u.full_name as agent_name, u.email as agent_email,
           admin.full_name as processed_by_name
    FROM commission_liquidations cl
    JOIN users u ON cl.agent_id = u.id
    LEFT JOIN users admin ON cl.processed_by = admin.id
    WHERE cl.status != 'pending'
    ORDER BY cl.processed_at DESC
    LIMIT 50
");
$stmt->execute();
$processed_liquidations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
    <title>Commission Liquidations - <?php echo SITE_NAME; ?></title>
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
                <div class="nav-item"><a href="commission-liquidations.php" class="nav-link active"><i class="fas fa-money-check-alt"></i> Liquidations</a></div>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
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
                    <div class="breadcrumb-item"><i class="fas fa-money-check-alt"></i></div>
                    <div class="breadcrumb-item">Commission</div>
                    <div class="breadcrumb-item active">Liquidations</div>
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
                <h1>Commission Liquidations</h1>
                <p class="page-subtitle">Process agent commission liquidation requests.</p>
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

            <!-- Pending Liquidations -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Pending Liquidation Requests</h3>
                    <p class="widget-subtitle">Review and process agent commission liquidation requests.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($pending_liquidations)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Requested</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_liquidations as $liquidation): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($liquidation['agent_name']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($liquidation['agent_email']); ?></div>
                                            </div>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($liquidation['reference_number']); ?></code></td>
                                        <td>???<?php echo number_format($liquidation['liquidated_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-secondary">
                                                <?php echo ucfirst(str_replace('_', ' ', $liquidation['liquidation_method'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M j, Y H:i', strtotime($liquidation['created_at'])); ?></td>
                                        <td>
                                            <?php if ($liquidation['notes']): ?>
                                                <span title="<?php echo htmlspecialchars($liquidation['notes']); ?>">
                                                    <?php echo htmlspecialchars(substr($liquidation['notes'], 0, 50)) . (strlen($liquidation['notes']) > 50 ? '...' : ''); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">No notes</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-success" onclick="processLiquidation(<?php echo $liquidation['id']; ?>, 'completed')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="processLiquidation(<?php echo $liquidation['id']; ?>, 'failed')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Pending Liquidations</h4>
                            <p>All commission liquidation requests have been processed.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Processed Liquidations -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Recent Processed Liquidations</h3>
                    <p class="widget-subtitle">History of processed commission liquidations.</p>
                </div>
                <div class="widget-content">
                    <?php if (!empty($processed_liquidations)): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Reference</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($processed_liquidations as $liquidation): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($liquidation['agent_name']); ?></div>
                                                <div style="font-size: 0.875rem; color: var(--text-muted);"><?php echo htmlspecialchars($liquidation['agent_email']); ?></div>
                                            </div>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($liquidation['reference_number']); ?></code></td>
                                        <td>???<?php echo number_format($liquidation['liquidated_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-secondary">
                                                <?php echo ucfirst(str_replace('_', ' ', $liquidation['liquidation_method'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $liquidation['status'] === 'completed' ? 'success' : 
                                                    ($liquidation['status'] === 'failed' ? 'danger' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($liquidation['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($liquidation['processed_by_name'] ?? 'System'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($liquidation['processed_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                            <h4>No Processed Liquidations</h4>
                            <p>No commission liquidations have been processed yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Process Liquidation Modal -->
<div id="processModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Process Liquidation</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="post" id="processForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="process_liquidation">
            <input type="hidden" name="liquidation_id" id="liquidationId">
            <input type="hidden" name="status" id="liquidationStatus">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="notes" class="form-label">Processing Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="4" 
                              placeholder="Add notes about the processing decision..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="processBtn">Process</button>
            </div>
        </form>
    </div>
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

// Process liquidation modal
function processLiquidation(liquidationId, status) {
    document.getElementById('liquidationId').value = liquidationId;
    document.getElementById('liquidationStatus').value = status;
    
    const modal = document.getElementById('processModal');
    const title = document.getElementById('modalTitle');
    const btn = document.getElementById('processBtn');
    
    if (status === 'completed') {
        title.textContent = 'Approve Liquidation';
        btn.textContent = 'Approve';
        btn.className = 'btn btn-success';
    } else {
        title.textContent = 'Reject Liquidation';
        btn.textContent = 'Reject';
        btn.className = 'btn btn-danger';
    }
    
    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('processModal').style.display = 'none';
    document.getElementById('notes').value = '';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('processModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

<style>
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(46, 41, 78, 0.5);
}

.modal-content {
    background-color: var(--bg-primary);
    margin: 5% auto;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 20px rgba(46, 41, 78, 0.15);
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.btn-group {
    display: flex;
    gap: 0.25rem;
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



