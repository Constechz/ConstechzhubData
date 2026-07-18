<?php
require_once '../config/config.php';
requireRole('agent');

$current_user = getCurrentUser();
$success = '';
$error = '';

// Handle request processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'process_request') {
            $requestId = intval($_POST['request_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            
            if (!in_array($status, ['approved', 'rejected'])) {
                $error = 'Invalid status selected.';
            } elseif ($requestId <= 0) {
                $error = 'Invalid request ID.';
            } else {
                $stmt = $db->prepare("SELECT * FROM topup_requests WHERE id = ? AND target_type = 'agent' AND target_agent_id = ?");
                $stmt->bind_param('ii', $requestId, $current_user['id']);
                $stmt->execute();
                $request = $stmt->get_result()->fetch_assoc();
                
                if (!$request || $request['status'] !== 'pending') {
                    $error = 'Request not found or already processed.';
                } else {
                    $stmt = $db->prepare("UPDATE topup_requests SET status = ?, admin_notes = ?, processed_by = ?, processed_at = NOW() WHERE id = ?");
                    $stmt->bind_param('ssii', $status, $notes, $current_user['id'], $requestId);
                    
                    if ($stmt->execute()) {
                        if ($status === 'approved') {
                            // Credit wallet and trigger notifications
                            $wallet_update_success = updateWalletBalanceWithSMS(
                                $request['requester_id'], 
                                $request['amount'], 
                                'credit', 
                                'AGENT_TOPUP_REQ_' . $request['request_id'], 
                                'Topup Request Approved by Agent - Request ID: ' . $request['request_id'],
                                'agent_topup_request'
                            );
                            
                            if ($wallet_update_success) {
                                logActivity($request['requester_id'], 'wallet_credit', 'Topup Request Approved by Agent - Amount: ' . CURRENCY . $request['amount'] . ' - Request ID: ' . $request['request_id']);
                            } else {
                                error_log("Wallet update failed for approved agent topup request: " . $request['request_id']);
                            }
                        }
                        
                        logActivity($current_user['id'], 'agent_topup_request_processed', json_encode([
                            'request_id' => $request['request_id'], 
                            'status' => $status,
                            'amount' => $request['amount']
                        ]));
                        
                        $success = "Request {$request['request_id']} has been {$status} successfully.";
                    } else {
                        $error = 'Failed to process request. Please try again.';
                    }
                }
            }
        }
    }
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get requests directed to this agent
$page = max(1, intval($_GET['page'] ?? 1));
$status_filter = $_GET['status'] ?? 'all';
$limit = 20;
$offset = ($page - 1) * $limit;

$whereClause = "target_type = 'agent' AND target_agent_id = ?";
$params = [$current_user['id']];
$types = 'i';

if ($status_filter !== 'all') {
    $whereClause .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$stmt = $db->prepare("SELECT tr.*, u.full_name as requester_name, u.email as requester_email, p.full_name as processed_by_name
    FROM topup_requests tr 
    JOIN users u ON tr.requester_id = u.id 
    LEFT JOIN users p ON tr.processed_by = p.id
    WHERE {$whereClause}
    ORDER BY tr.created_at DESC 
    LIMIT ? OFFSET ?");

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count pending requests for this agent
$pendingCount = 0;
$stmt = $db->prepare("SELECT COUNT(*) as count FROM topup_requests WHERE target_type = 'agent' AND target_agent_id = ? AND status = 'pending'");
$stmt->bind_param('i', $current_user['id']);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $pendingCount = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Topup Requests - <?php echo SITE_NAME; ?></title>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
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
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Services</div>
                <div class="nav-item">
                    <a href="at-business.php" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        AT Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="mtn-business.php" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        MTN Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php" class="nav-link">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="telecel-business.php" class="nav-link">
                        <i class="fas fa-signal"></i>
                        Telecel Business
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Transaction</div>
                <div class="nav-item">
                    <a href="transactions.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        Transactions
                    </a>
                </div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link">
                        <i class="fas fa-history"></i>
                        Data Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Operations</div>
                <div class="nav-item">
                    <a href="customer_topup.php" class="nav-link">
                        <i class="fas fa-user-plus"></i>
                        Customer Top-up
                    </a>
                </div>
                <div class="nav-item">
                    <a href="topup-requests.php" class="nav-link active">
                        <i class="fas fa-hand-holding-usd"></i>
                        Topup Requests
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge badge-warning"><?php echo $pendingCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="support.php" class="nav-link">
                        <i class="fas fa-life-ring"></i>
                        Support
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Business</div>
                <div class="nav-item">
                    <a href="pricing.php" class="nav-link">
                        <i class="fas fa-tags"></i>
                        Custom Pricing
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Users</div>
                <div class="nav-item">
                    <a href="customers.php" class="nav-link">
                        <i class="fas fa-user-friends"></i>
                        Customers
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Commission</div>
                <div class="nav-item">
                    <a href="commission.php" class="nav-link">
                        <i class="fas fa-percentage"></i>
                        Commission
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item">
                    <a href="settings.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        Settings
                    </a>
                </div>
                <div class="nav-item">
                    <a href="api-access.php" class="nav-link">
                        <i class="fas fa-key"></i>
                        API Access
                    </a>
                </div>
            </li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                    <i class="fas fa-bars"></i>
                </button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="breadcrumb-item active">Customer Topup Requests</div>
                </nav>
            </div>
            
            <div class="header-actions">
                <button class="theme-toggle" type="button">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" type="button">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="wallet.php" class="dropdown-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('agents'); ?>


        <div class="dashboard-content">
            <div class="page-title">
                <h1>Customer Topup Requests</h1>
                <p class="page-subtitle">Manage and process customer topup requests sent to you.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="filters-bar" style="margin-bottom: 1.5rem;">
                <form method="get">
                    <select name="status" class="form-control" style="width: auto; min-width: 150px;" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Requests</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </form>
            </div>

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">
                        <i class="fas fa-hand-holding-usd"></i>
                        Customer Topup Requests 
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge badge-warning"><?php echo $pendingCount; ?> Pending</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="widget-body">
                    <?php if (empty($requests)): ?>
                        <div class="empty-state" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                            <i class="fas fa-hand-holding-usd" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No customer topup requests</h3>
                            <p>When your customers submit topup requests, they will appear here for your review.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Payment Details</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($request['request_id']); ?></strong></td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($request['requester_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($request['requester_email']); ?></small>
                                                </div>
                                            </td>
                                            <td><strong style="color: var(--primary-color);"><?php echo CURRENCY; ?><?php echo number_format($request['amount'], 2); ?></strong></td>
                                            <td>
                                                <div style="font-size: 0.875rem;">
                                                    <div><strong><?php echo htmlspecialchars($request['network']); ?></strong></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_name']); ?></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_number']); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $statusClass = 'badge-secondary';
                                                if ($request['status'] === 'approved') $statusClass = 'badge-success';
                                                elseif ($request['status'] === 'rejected') $statusClass = 'badge-danger';
                                                elseif ($request['status'] === 'pending') $statusClass = 'badge-warning';
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo ucfirst($request['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="btn btn-sm btn-primary" onclick="openProcessModal(<?php echo htmlspecialchars(json_encode($request)); ?>)">
                                                        <i class="fas fa-edit"></i> Process
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-secondary" onclick="viewRequest(<?php echo htmlspecialchars(json_encode($request)); ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Process Modal -->
<div id="processModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Process Customer Topup Request</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="process_request">
            <input type="hidden" name="request_id" id="modalRequestId">
            
            <div class="modal-body">
                <div id="modalRequestDetails"></div>
                
                <div class="form-group">
                    <label class="form-label">Decision *</label>
                    <select name="status" class="form-control" required>
                        <option value="">Select decision...</option>
                        <option value="approved">Approve Request</option>
                        <option value="rejected">Reject Request</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes for the customer..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Process Request</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Enhanced Topup Requests Page Styling */

/* Badge system with consistent colors */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.badge-success {
    background-color: var(--success-bg, #F1E9DA);
    color: var(--success-text, #2E294E);
    border: 1px solid var(--success-border, #2E294E);
}

.badge-danger {
    background-color: var(--danger-bg, #F1E9DA);
    color: var(--danger-text, #D90368);
    border: 1px solid var(--danger-border, #D90368);
}

.badge-warning {
    background-color: var(--warning-bg, #F1E9DA);
    color: var(--warning-text, #2E294E);
    border: 1px solid var(--warning-border, #FFD400);
}

.badge-secondary {
    background-color: var(--secondary-bg, #F1E9DA);
    color: var(--secondary-text, #2E294E);
    border: 1px solid var(--secondary-border, #541388);
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: var(--success-bg, #2E294E);
    color: var(--success-text, #F1E9DA);
    border-color: var(--success-border, #2E294E);
}

[data-theme="dark"] .badge-danger {
    background-color: var(--danger-bg, #2E294E);
    color: var(--danger-text, #F1E9DA);
    border-color: var(--danger-border, #D90368);
}

[data-theme="dark"] .badge-warning {
    background-color: var(--warning-bg, #2E294E);
    color: var(--warning-text, #FFD400);
    border-color: var(--warning-border, #FFD400);
}

[data-theme="dark"] .badge-secondary {
    background-color: var(--secondary-bg, #2E294E);
    color: var(--secondary-text, #F1E9DA);
    border-color: var(--secondary-border, #2E294E);
}

/* Enhanced table styling */
.table {
    width: 100%;
    margin-bottom: 1rem;
    color: var(--text-primary);
    background: transparent;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
}

.table th {
    font-weight: 600;
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 10;
}

.table tbody tr {
    transition: background-color 0.15s ease;
}

.table tbody tr:hover {
    background-color: var(--bg-tertiary);
}

.table tbody tr:nth-child(even) {
    background-color: var(--bg-subtle, rgba(46, 41, 78, 0.02));
}

[data-theme="dark"] .table tbody tr:nth-child(even) {
    background-color: var(--bg-subtle, rgba(241, 233, 218, 0.02));
}

/* Enhanced table responsiveness */
.table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
}

.table-scroll-wrapper {
    position: relative;
}

.table-scroll-wrapper::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(90deg, transparent, var(--bg-primary));
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.table-scroll-wrapper.scrollable::after {
    opacity: 1;
}

/* Keep header actions visible across breakpoints */
.header-actions {
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}

.theme-toggle,
.user-dropdown-toggle {
    touch-action: manipulation;
    pointer-events: auto;
}

.header-actions,
.user-dropdown {
    pointer-events: auto;
}

/* Enhanced modal styling */
.modal {
    display: none;
    position: fixed;
    z-index: 1050;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(46, 41, 78, 0.6);
    backdrop-filter: blur(4px);
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background-color: var(--bg-primary);
    margin: 2rem auto;
    border-radius: 1rem;
    width: 90%;
    max-width: 700px;
    max-height: calc(100vh - 4rem);
    overflow-y: auto;
    box-shadow: var(--shadow-xl);
    transform: scale(0.9);
    opacity: 0;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.modal.show .modal-content {
    transform: scale(1);
    opacity: 1;
}

.modal-header {
    padding: 1.5rem 2rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
    color: var(--text-primary);
    font-size: 1.25rem;
    font-weight: 600;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0.5rem;
    width: 2.5rem;
    height: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s ease;
    pointer-events: auto;
    z-index: 2;
}

.modal-close:hover {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    transform: scale(1.1);
}

.modal-body {
    padding: 1rem 2rem 1.5rem;
}

.modal-footer {
    padding: 1rem 2rem 1.5rem;
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    border-top: 1px solid var(--border-color);
}

/* Enhanced request details card in modal */
.request-details-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.request-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.request-title {
    margin: 0;
    color: var(--text-primary);
    font-size: 1.125rem;
    font-weight: 600;
}

.request-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.detail-value {
    font-size: 0.875rem;
    color: var(--text-primary);
    font-weight: 500;
}

.detail-value.amount {
    color: var(--brand-primary);
    font-weight: 600;
    font-size: 1rem;
}

.payment-details {
    border-top: 1px solid var(--border-color);
    padding-top: 1rem;
}

.payment-title {
    margin: 0 0 0.75rem 0;
    color: var(--text-primary);
    font-size: 1rem;
    font-weight: 600;
}

.payment-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--bg-tertiary);
    border-radius: 0.5rem;
    border: 1px solid var(--border-color);
}

.payment-info i {
    color: var(--brand-primary);
    font-size: 1.125rem;
}

.wallet-number {
    color: var(--text-muted);
    font-family: var(--font-mono, 'Courier New', monospace);
    background: var(--bg-primary);
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    border: 1px solid var(--border-color);
    margin-left: auto;
}

/* Form styling improvements */
.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--text-primary);
    font-size: 0.875rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--input-bg, var(--bg-primary));
    color: var(--text-primary);
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--brand-primary);
    box-shadow: 0 0 0 3px var(--brand-primary-alpha, rgba(84, 19, 136, 0.1));
}

.form-control:disabled {
    background: var(--bg-muted);
    color: var(--text-muted);
    cursor: not-allowed;
}

/* Enhanced buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border: 1px solid transparent;
    border-radius: var(--border-radius);
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.btn-primary {
    background: var(--brand-primary);
    color: #F1E9DA;
    border-color: var(--brand-primary);
}

.btn-primary:hover {
    background: var(--brand-primary-dark);
    border-color: var(--brand-primary-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.btn-secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

.btn-secondary:hover {
    background: var(--bg-tertiary);
    border-color: var(--brand-primary);
    color: var(--brand-primary);
}

.btn-sm {
    padding: 0.5rem 0.75rem;
    font-size: 0.8125rem;
}

/* Enhanced empty state */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--text-muted);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.5;
    color: var(--brand-primary);
}

.empty-state h3 {
    color: var(--text-primary);
    margin: 0 0 1rem 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.empty-state p {
    margin: 0;
    font-size: 0.875rem;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
    line-height: 1.6;
}

/* Mobile menu toggle */
.mobile-menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--text-primary);
    font-size: 1.25rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: var(--border-radius);
    transition: all 0.2s ease;
}

.mobile-menu-toggle:hover {
    background: var(--bg-secondary);
    color: var(--brand-primary);
}

/* Responsive design */
@media (max-width: 768px) {
    .mobile-menu-toggle {
        display: block;
    }
    
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .modal-content {
        margin: 1rem;
        width: calc(100% - 2rem);
        max-height: calc(100vh - 2rem);
    }
    
    .modal-header,
    .modal-body,
    .modal-footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .request-grid {
        grid-template-columns: 1fr;
    }
    
    .request-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .table th,
    .table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.8125rem;
    }
    
    .payment-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .wallet-number {
        margin-left: 0;
        align-self: stretch;
        text-align: center;
    }

    .header-actions {
        display: flex !important;
        width: auto;
        margin-left: auto;
    }

    .header-actions .theme-toggle,
    .header-actions .user-dropdown-toggle {
        display: inline-flex !important;
        visibility: visible;
    }

    .dashboard-header {
        position: sticky;
        overflow: visible;
    }

    .header-actions {
        position: fixed;
        right: 0.75rem;
        top: 0.75rem;
        transform: none;
        z-index: 1200;
    }

    .header-left {
        width: 100%;
        padding-right: 0;
    }
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid var(--border-color);
    border-top: 2px solid var(--brand-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Accessibility improvements */
.btn:focus,
.form-control:focus,
.modal-close:focus {
    outline: 2px solid var(--brand-primary);
    outline-offset: 2px;
}

/* High contrast mode support */
@media (prefers-contrast: high) {
    .badge {
        border-width: 2px;
    }
    
    .btn {
        border-width: 2px;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>"></script>
<script>
// Enhanced user dropdown functionality
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
    if (toggle) {
        toggle.classList.toggle('open');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (dropdown && toggle && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
        toggle.classList.remove('open');
    }
});

// Mobile menu toggle
function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    
    if (sidebar) {
        sidebar.classList.toggle('show');
    }
}

// Modal functions with enhanced UX
function openProcessModal(request) {
    const modal = document.getElementById('processModal');
    const modalContent = modal.querySelector('.modal-content');
    
    document.getElementById('modalRequestId').value = request.id;
    
    // Enhanced modal content with better styling
    document.getElementById('modalRequestDetails').innerHTML = `
        <div class="request-details-card">
            <div class="request-header">
                <h4 class="request-title">Request: ${request.request_id}</h4>
                <span class="badge badge-${getStatusBadgeClass(request.status)}">
                    ${request.status.charAt(0).toUpperCase() + request.status.slice(1)}
                </span>
            </div>
            
            <div class="request-grid">
                <div class="detail-item">
                    <span class="detail-label">Customer:</span>
                    <span class="detail-value">${request.requester_name}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value amount"><?php echo CURRENCY; ?>${parseFloat(request.amount).toFixed(2)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">${request.requester_email || request.user_email || 'N/A'}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Date:</span>
                    <span class="detail-value">${formatDate(request.created_at)}</span>
                </div>
            </div>
            
            <div class="payment-details">
                <h5 class="payment-title">Payment Details</h5>
                <div class="payment-info">
                    <i class="fas fa-university"></i>
                    <span><strong>${request.network}</strong> - ${request.wallet_name}</span>
                    <span class="wallet-number">${request.wallet_number}</span>
                </div>
            </div>
        </div>
    `;
    
    // Show modal with animation
    modal.style.display = 'block';
    setTimeout(() => {
        modalContent.style.transform = 'scale(1)';
        modalContent.style.opacity = '1';
    }, 10);
    
    // Focus on select
    setTimeout(() => {
        const statusSelect = modal.querySelector('select[name="status"]');
        if (statusSelect) statusSelect.focus();
    }, 300);
}

function viewRequest(request) {
    const formattedDate = formatDate(request.created_at);
    const amount = parseFloat(request.amount).toFixed(2);
    
    const details = [
        `Request ID: ${request.request_id}`,
        `Customer: ${request.requester_name}`,
        `Email: ${request.requester_email || request.user_email || 'N/A'}`,
        `Amount: <?php echo CURRENCY; ?>${amount}`,
        `Status: ${request.status.charAt(0).toUpperCase() + request.status.slice(1)}`,
        `Date: ${formattedDate}`,
        `Payment: ${request.network} - ${request.wallet_name} (${request.wallet_number})`
    ];
    
    alert(details.join('\n'));
}

function closeModal() {
    const modal = document.getElementById('processModal');
    const modalContent = modal.querySelector('.modal-content');
    
    // Hide with animation
    modalContent.style.transform = 'scale(0.9)';
    modalContent.style.opacity = '0';
    
    setTimeout(() => {
        modal.style.display = 'none';
        modalContent.style.transform = 'scale(1)';
        modalContent.style.opacity = '1';
    }, 200);
}

// Helper functions
function getStatusBadgeClass(status) {
    const statusClasses = {
        'pending': 'warning',
        'approved': 'success',
        'rejected': 'danger'
    };
    return statusClasses[status] || 'secondary';
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Enhanced modal click outside handling
window.onclick = function(event) {
    const modal = document.getElementById('processModal');
    if (event.target === modal) {
        closeModal();
    }
}

// Keyboard navigation for modal
document.addEventListener('keydown', function(event) {
    const modal = document.getElementById('processModal');
    if (modal.style.display === 'block' && event.key === 'Escape') {
        closeModal();
    }
});

// Enhanced form handling
document.addEventListener('DOMContentLoaded', function() {
    // Auto-focus first input in forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        const firstInput = form.querySelector('input, select, textarea');
        if (firstInput && !firstInput.hasAttribute('readonly')) {
            // Don't auto-focus on mobile to prevent zoom
            if (window.innerWidth > 768) {
                firstInput.focus();
            }
        }
    });
    
    // Enhanced table responsiveness
    const tables = document.querySelectorAll('.table-responsive');
    tables.forEach(table => {
        // Add scroll indicators
        const wrapper = document.createElement('div');
        wrapper.className = 'table-scroll-wrapper';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });

    // Ensure modal close works reliably on mobile/touch
    const modalCloseButtons = document.querySelectorAll('.modal-close');
    modalCloseButtons.forEach(button => {
        ['click', 'touchend', 'pointerup'].forEach(evt => {
            button.addEventListener(evt, function(e) {
                e.preventDefault();
                closeModal();
            }, { passive: false });
        });
    });

    const bindTap = (element, handler) => {
        if (!element) return;
        let lastTouch = 0;
        const wrapped = (event) => {
            if (event.type === 'pointerup' && event.pointerType === 'touch') {
                lastTouch = Date.now();
            }
            if (event.type === 'click' && Date.now() - lastTouch < 600) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            handler();
        };
        element.addEventListener('pointerup', wrapped, { passive: false });
        element.addEventListener('click', wrapped, { passive: false });
    };

    const applyLocalTheme = (theme) => {
        const resolved = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', resolved);
        try {
            localStorage.setItem('theme', resolved);
        } catch (err) {
            // Ignore storage errors
        }
        const icon = document.getElementById('theme-icon');
        if (icon) {
            icon.className = resolved === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    };

    const getInitialTheme = () => {
        try {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || saved === 'light') {
                return saved;
            }
        } catch (err) {
            // Ignore storage errors
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    const safeToggleTheme = () => {
        if (typeof window.toggleTheme === 'function') {
            try {
                window.toggleTheme();
                return;
            } catch (err) {
                // Fallback to local toggle
            }
        }
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyLocalTheme(current === 'dark' ? 'light' : 'dark');
    };

    applyLocalTheme(getInitialTheme());

    bindTap(document.querySelector('.theme-toggle'), safeToggleTheme);
    bindTap(document.querySelector('.user-dropdown-toggle'), () => toggleUserDropdown());
});
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

