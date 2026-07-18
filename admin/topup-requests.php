<?php
require_once '../config/config.php';
requireRole('admin');

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
                $stmt = $db->prepare("SELECT * FROM topup_requests WHERE id = ? AND target_type = 'admin'");
                $stmt->bind_param('i', $requestId);
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
                                'TOPUP_REQ_' . $request['request_id'], 
                                'Admin Topup Request Approved - Request ID: ' . $request['request_id'],
                                'admin_topup_request'
                            );
                            
                            if ($wallet_update_success) {
                                logActivity($request['requester_id'], 'wallet_credit', 'Admin Topup Request Approved - Amount: ' . CURRENCY . $request['amount'] . ' - Request ID: ' . $request['request_id']);
                            } else {
                                error_log("Wallet update failed for approved topup request: " . $request['request_id']);
                            }
                        }
                        
                        logActivity($current_user['id'], 'topup_request_processed', json_encode([
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

// Get requests
$page = max(1, intval($_GET['page'] ?? 1));
$status_filter = $_GET['status'] ?? 'all';
$limit = 20;
$offset = ($page - 1) * $limit;

$whereClause = "target_type = 'admin'";
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $whereClause .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$stmt = $db->prepare("
    SELECT tr.*, u.full_name as requester_name, u.email as requester_email, p.full_name as processed_by_name
    FROM topup_requests tr 
    JOIN users u ON tr.requester_id = u.id 
    LEFT JOIN users p ON tr.processed_by = p.id
    WHERE {$whereClause}
    ORDER BY tr.created_at DESC 
    LIMIT ? OFFSET ?
");

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pendingCount = 0;
$stmt = $db->prepare("SELECT COUNT(*) as count FROM topup_requests WHERE target_type = 'admin' AND status = 'pending'");
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
    <title>Topup Requests - <?php echo SITE_NAME; ?></title>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
</head>
<body>
<!-- Mobile overlay for sidebar -->
<div class="mobile-overlay" onclick="toggleMobileMenu()"></div>

<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">User Management</div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Financial</div>
                <div class="nav-item"><a href="topup-requests.php" class="nav-link active"><i class="fas fa-hand-holding-usd"></i> Topup Requests 
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge badge-warning"><?php echo $pendingCount; ?></span>
                    <?php endif; ?>
                </a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
                    <i class="fas fa-bars"></i>
                </button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="breadcrumb-item active">Topup Requests</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Topup Requests</h1>
                <p class="page-subtitle">Manage and process customer topup requests.</p>
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
                        Topup Requests 
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge badge-warning"><?php echo $pendingCount; ?> Pending</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="widget-content">
                    <?php if (empty($requests)): ?>
                        <div class="empty-state" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                            <i class="fas fa-hand-holding-usd" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <h3>No topup requests found</h3>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Requester</th>
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
                                                    <small><?php echo htmlspecialchars($request['requester_email']); ?></small>
                                                </div>
                                            </td>
                                            <td><strong style="color: var(--primary-color);">$<?php echo number_format($request['amount'], 2); ?></strong></td>
                                            <td>
                                                <div style="font-size: 0.875rem;">
                                                    <div><?php echo htmlspecialchars($request['network']); ?></div>
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
            <h3>Process Topup Request</h3>
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
                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
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
/* Dark mode support for badges */
.badge {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.375rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.badge-success {
    background-color: #2E294E;
    color: #F1E9DA;
}

.badge-danger {
    background-color: #D90368;
    color: #F1E9DA;
}

.badge-warning {
    background-color: #FFD400;
    color: #F1E9DA;
}

.badge-secondary {
    background-color: #541388;
    color: #F1E9DA;
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: #2E294E;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-danger {
    background-color: #D90368;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-warning {
    background-color: #FFD400;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-secondary {
    background-color: #2E294E;
    color: #F1E9DA;
}

/* Table styling for dark mode */
.table {
    width: 100%;
    margin-bottom: 1rem;
    color: var(--text-color);
    background-color: transparent;
}

.table th,
.table td {
    padding: 0.75rem;
    vertical-align: top;
    border-top: 1px solid var(--border-color);
    background-color: transparent;
}

.table thead th {
    vertical-align: bottom;
    border-bottom: 2px solid var(--border-color);
    font-weight: 600;
    background: var(--bg-secondary, #F1E9DA);
    color: var(--text-color);
}

[data-theme="dark"] .table thead th {
    background: var(--bg-secondary, #2E294E);
    color: var(--text-color, #F1E9DA);
}

.table tbody tr {
    background-color: transparent;
}

.table tbody tr:hover {
    background-color: var(--bg-secondary);
}

[data-theme="dark"] .table tbody tr:hover {
    background-color: var(--bg-secondary, #2E294E);
}

[data-theme="dark"] .table tbody tr {
    background-color: transparent;
}

[data-theme="dark"] .table th,
[data-theme="dark"] .table td {
    border-color: var(--border-color, #2E294E);
    background-color: transparent;
    color: var(--text-color, #F1E9DA);
}

.table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
}

/* Modal styling for dark mode */
.modal {
    display: none;
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
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 25px rgba(46, 41, 78, 0.2);
}

[data-theme="dark"] .modal-content {
    background-color: var(--bg-primary, #2E294E);
    box-shadow: 0 10px 25px rgba(46, 41, 78, 0.5);
}

.modal-header {
    padding: 1.5rem 1.5rem 0 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 1rem;
}

.modal-header h3 {
    margin: 0;
    color: var(--text-color);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s ease;
}

.modal-close:hover {
    background-color: var(--bg-secondary);
}

.modal-body {
    padding: 0 1.5rem 1rem 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem 1.5rem 1.5rem;
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    border-top: 1px solid var(--border-color);
}

/* Form controls in modal */
.modal .form-control {
    background: var(--input-bg, #F1E9DA);
    border: 1px solid var(--border-color, #F1E9DA);
    color: var(--text-color, #2E294E);
}

[data-theme="dark"] .modal .form-control {
    background: var(--input-bg, #2E294E);
    border: 1px solid var(--border-color, #2E294E);
    color: var(--text-color, #F1E9DA);
}

/* Text muted styling */
.text-muted {
    color: var(--text-muted, #541388) !important;
}

[data-theme="dark"] .text-muted {
    color: var(--text-muted, #F1E9DA) !important;
}

/* Empty state styling */
.empty-state {
    color: var(--text-muted);
}

[data-theme="dark"] .empty-state {
    color: var(--text-muted, #F1E9DA);
}

.empty-state h3 {
    color: var(--text-color);
    margin: 1rem 0 0.5rem 0;
}

[data-theme="dark"] .empty-state h3 {
    color: var(--text-color, #F1E9DA);
}

/* Widget styling for dark mode */
[data-theme="dark"] .widget {
    background: var(--widget-bg, #2E294E);
    border: 1px solid var(--border-color, #2E294E);
}

[data-theme="dark"] .widget-header {
    border-bottom: 1px solid var(--border-color, #2E294E);
}

[data-theme="dark"] .widget-title {
    color: var(--text-color, #F1E9DA);
}

/* Responsive design */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .modal-content {
        margin: 10% auto;
        width: 95%;
    }
}
</style>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
<script>
// Enhanced user dropdown functionality
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (dropdown) {
        const isVisible = dropdown.style.display === 'block';
        dropdown.style.display = isVisible ? 'none' : 'block';
        
        if (toggle) {
            toggle.classList.toggle('active', !isVisible);
        }
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (dropdown && toggle && !toggle.contains(event.target)) {
        dropdown.style.display = 'none';
        toggle.classList.remove('active');
    }
});

// Mobile menu toggle
function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.mobile-overlay');
    
    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
    }
    
    if (overlay) {
        overlay.classList.toggle('active');
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
                    <span class="detail-label">Requester:</span>
                    <span class="detail-value">${request.requester_name}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value amount"><?php echo CURRENCY; ?>${parseFloat(request.amount).toFixed(2)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">${request.requester_email}</span>
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
        `Requester: ${request.requester_name}`,
        `Email: ${request.requester_email}`,
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
</script>

<style>
/* Dark mode support for badges */
.badge {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.375rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.badge-success {
    background-color: #2E294E;
    color: #F1E9DA;
}

.badge-danger {
    background-color: #D90368;
    color: #F1E9DA;
}

.badge-warning {
    background-color: #FFD400;
    color: #F1E9DA;
}

.badge-secondary {
    background-color: #541388;
    color: #F1E9DA;
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: #2E294E;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-danger {
    background-color: #D90368;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-warning {
    background-color: #FFD400;
    color: #F1E9DA;
}

[data-theme="dark"] .badge-secondary {
    background-color: #2E294E;
    color: #F1E9DA;
}

/* Table styling for dark mode */
.table {
    width: 100%;
    margin-bottom: 1rem;
    color: var(--text-color);
    background-color: transparent;
}

.table th,
.table td {
    padding: 0.75rem;
    vertical-align: top;
    border-top: 1px solid var(--border-color);
    background-color: transparent;
}

.table thead th {
    vertical-align: bottom;
    border-bottom: 2px solid var(--border-color);
    font-weight: 600;
    background: var(--bg-secondary, #F1E9DA);
    color: var(--text-color);
}

[data-theme="dark"] .table thead th {
    background: var(--bg-secondary, #2E294E);
    color: var(--text-color, #F1E9DA);
}

.table tbody tr {
    background-color: transparent;
}

.table tbody tr:hover {
    background-color: var(--bg-secondary);
}

[data-theme="dark"] .table tbody tr:hover {
    background-color: var(--bg-secondary, #2E294E);
}

[data-theme="dark"] .table tbody tr {
    background-color: transparent;
}

[data-theme="dark"] .table th,
[data-theme="dark"] .table td {
    border-color: var(--border-color, #2E294E);
    background-color: transparent;
    color: var(--text-color, #F1E9DA);
}

.table-responsive {
    display: block;
    width: 100%;
    overflow-x: auto;
}

/* Modal styling for dark mode */
.modal {
    display: none;
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
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 25px rgba(46, 41, 78, 0.2);
}

[data-theme="dark"] .modal-content {
    background-color: var(--bg-primary, #2E294E);
    box-shadow: 0 10px 25px rgba(46, 41, 78, 0.5);
}

.modal-header {
    padding: 1.5rem 1.5rem 0 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 1rem;
}

.modal-header h3 {
    margin: 0;
    color: var(--text-color);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background-color 0.2s ease;
}

.modal-close:hover {
    background-color: var(--bg-secondary);
}

.modal-body {
    padding: 0 1.5rem 1rem 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem 1.5rem 1.5rem;
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    border-top: 1px solid var(--border-color);
}

/* Form controls in modal */
.modal .form-control {
    background: var(--input-bg, #F1E9DA);
    border: 1px solid var(--border-color, #F1E9DA);
    color: var(--text-color, #2E294E);
}

[data-theme="dark"] .modal .form-control {
    background: var(--input-bg, #2E294E);
    border: 1px solid var(--border-color, #2E294E);
    color: var(--text-color, #F1E9DA);
}

/* Text muted styling */
.text-muted {
    color: var(--text-muted, #541388) !important;
}

[data-theme="dark"] .text-muted {
    color: var(--text-muted, #F1E9DA) !important;
}

/* Empty state styling */
.empty-state {
    color: var(--text-muted);
}

[data-theme="dark"] .empty-state {
    color: var(--text-muted, #F1E9DA);
}

.empty-state h3 {
    color: var(--text-color);
    margin: 1rem 0 0.5rem 0;
}

[data-theme="dark"] .empty-state h3 {
    color: var(--text-color, #F1E9DA);
}

/* Widget styling for dark mode */
[data-theme="dark"] .widget {
    background: var(--widget-bg, #2E294E);
    border: 1px solid var(--border-color, #2E294E);
}

[data-theme="dark"] .widget-header {
    border-bottom: 1px solid var(--border-color, #2E294E);
}

[data-theme="dark"] .widget-title {
    color: var(--text-color, #F1E9DA);
}

/* Alert styling for dark mode */
[data-theme="dark"] .alert-success {
    background: #2E294E;
    color: #F1E9DA;
    border: 1px solid #541388;
}

[data-theme="dark"] .alert-danger {
    background: #2E294E;
    color: #F1E9DA;
    border: 1px solid #D90368;
}

/* Page title styling for dark mode */
[data-theme="dark"] .page-title h1 {
    color: var(--text-color, #F1E9DA);
}

[data-theme="dark"] .page-subtitle {
    color: var(--text-muted, #F1E9DA);
}

/* Form control styling for dark mode */
[data-theme="dark"] .form-control {
    background: var(--input-bg, #2E294E);
    border: 1px solid var(--border-color, #2E294E);
    color: var(--text-color, #F1E9DA);
}

[data-theme="dark"] .form-control:focus {
    border-color: var(--primary-color, #541388);
    box-shadow: 0 0 0 3px rgba(84, 19, 136, 0.2);
}

/* Button styling for dark mode */
[data-theme="dark"] .btn-secondary {
    background: var(--secondary-bg, #2E294E);
    color: var(--text-color, #F1E9DA);
    border: 1px solid var(--border-color, #2E294E);
}

[data-theme="dark"] .btn-secondary:hover {
    background: var(--secondary-hover, #2E294E);
}

/* Enhanced responsive design and mobile optimizations */
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

/* Mobile overlay */
.mobile-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(46, 41, 78, 0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mobile-overlay.active {
    display: block;
    opacity: 1;
}

@media (max-width: 768px) {
    .mobile-overlay {
        display: block;
    }
}
</style>

<!-- Include theme management -->
<script src="../immediate_icon_fix.js"></script>
</body>
</html>


