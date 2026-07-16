<?php
require_once '../config/config.php';
requireRole('admin');

$current_user = getCurrentUser();
$success = '';
$error = '';

if (!function_exists('formatAdminTopupRequestAmount')) {
    function formatAdminTopupRequestAmount($amount) {
        return formatCurrency((float) $amount, CURRENCY_CODE);
    }
}

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
                                logActivity($request['requester_id'], 'wallet_credit', 'Admin Topup Request Approved - Amount: ' . formatAdminTopupRequestAmount($request['amount']) . ' - Request ID: ' . $request['request_id']);
                                
                                // Send email notification to the agent
                                try {
                                    require_once '../includes/email.php';
                                    $user_stmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
                                    if ($user_stmt) {
                                        $user_stmt->bind_param('i', $request['requester_id']);
                                        $user_stmt->execute();
                                        $user_info = $user_stmt->get_result()->fetch_assoc();
                                        $user_stmt->close();
                                        
                                        if ($user_info && !empty($user_info['email'])) {
                                            $requester_email = $user_info['email'];
                                            $full_name = htmlspecialchars($user_info['full_name'] ?: 'Agent');
                                            $new_balance = getWalletBalance($request['requester_id']);
                                            
                                            $currency_sym = defined('CURRENCY') ? CURRENCY : 'GH₵';
                                            $amount_fmt = $currency_sym . ' ' . number_format($request['amount'], 2);
                                            $balance_fmt = $currency_sym . ' ' . number_format($new_balance, 2);
                                            
                                            $site_name = getSiteName();
                                            $current_year = date('Y');
                                            
                                            $subject = "Wallet Top-Up Approved - Request #" . $request['request_id'];
                                            $body_html = '
<div style="font-family: \'Outfit\', \'Inter\', sans-serif; background-color: #f4f6f8; padding: 30px; border-radius: 12px; max-width: 600px; margin: 0 auto; color: #1f2937; border: 1px solid #e5e7eb;">
    <div style="text-align: center; margin-bottom: 25px;">
        <h2 style="color: #0f172a; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">Wallet Top-Up Approved</h2>
        <p style="color: #6b7280; font-size: 14px; margin-top: 5px; margin-bottom: 0;">Your request has been successfully processed</p>
    </div>
    
    <div style="background-color: #ffffff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <p style="font-size: 16px; line-height: 1.5; margin-top: 0; color: #374151;">
            Hello <strong>' . $full_name . '</strong>,
        </p>
        <p style="font-size: 15px; line-height: 1.5; color: #4b5563;">
            Great news! Your wallet top-up request has been approved and credited to your account.
        </p>
        
        <div style="background-color: #f8fafc; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <tr>
                    <td style="color: #6b7280; padding: 6px 0;"><strong>Request ID:</strong></td>
                    <td style="color: #1f2937; padding: 6px 0; text-align: right;">' . htmlspecialchars($request['request_id']) . '</td>
                </tr>
                <tr>
                    <td style="color: #6b7280; padding: 6px 0;"><strong>Amount Credited:</strong></td>
                    <td style="color: #10b981; padding: 6px 0; text-align: right; font-weight: bold; font-size: 16px;">' . $amount_fmt . '</td>
                </tr>
                <tr style="border-top: 1px solid #e2e8f0;">
                    <td style="color: #6b7280; padding: 10px 0 6px 0;"><strong>New Wallet Balance:</strong></td>
                    <td style="color: #0f172a; padding: 10px 0 6px 0; text-align: right; font-weight: bold; font-size: 16px;">' . $balance_fmt . '</td>
                </tr>
            </table>
        </div>
        
        <p style="font-size: 14px; line-height: 1.5; color: #6b7280; margin-bottom: 0;">
            Thank you for choosing ' . htmlspecialchars($site_name) . '. If you have any questions or notice any issues, please contact our support team immediately.
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 25px; font-size: 12px; color: #9ca3af;">
        &copy; ' . $current_year . ' ' . htmlspecialchars($site_name) . '. All rights reserved.
    </div>
</div>';

                                            $body_text = "Hello " . $full_name . ",\n\nYour wallet top-up request (Request ID: " . $request['request_id'] . ") has been approved.\nAmount Credited: " . $amount_fmt . "\nNew Wallet Balance: " . $balance_fmt . "\n\nThank you for choosing " . $site_name . ".";
                                            
                                            if (function_exists('sendEmail')) {
                                                sendEmail($requester_email, $subject, $body_html, $body_text, 'wallet_topup_approved');
                                            }
                                        }
                                    }
                                } catch (Exception $mail_e) {
                                    error_log("Failed to send topup approval email: " . $mail_e->getMessage());
                                }
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
        } elseif ($action === 'delete_request') {
            $requestId = intval($_POST['request_id'] ?? 0);

            if ($requestId <= 0) {
                $error = 'Invalid request ID.';
            } else {
                $stmt = $db->prepare("SELECT id, request_id FROM topup_requests WHERE id = ? AND target_type = 'admin' LIMIT 1");
                $stmt->bind_param('i', $requestId);
                $stmt->execute();
                $request = $stmt->get_result()->fetch_assoc();

                if (!$request) {
                    $error = 'Request not found.';
                } else {
                    $stmt = $db->prepare("DELETE FROM topup_requests WHERE id = ? AND target_type = 'admin' LIMIT 1");
                    $stmt->bind_param('i', $requestId);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        logActivity($current_user['id'], 'topup_request_deleted', json_encode([
                            'request_id' => $request['request_id'],
                            'target_type' => 'admin'
                        ]));
                        $success = "Request {$request['request_id']} deleted successfully.";
                    } else {
                        $error = 'Failed to delete request. Please try again.';
                    }
                }
            }
        } elseif ($action === 'delete_all_requests') {
            $stmt = $db->prepare("DELETE FROM topup_requests WHERE target_type = 'admin'");
            if ($stmt->execute()) {
                $deletedCount = (int) $stmt->affected_rows;
                logActivity($current_user['id'], 'topup_requests_deleted_all', json_encode([
                    'target_type' => 'admin',
                    'deleted_count' => $deletedCount
                ]));
                $success = $deletedCount > 0
                    ? "Deleted {$deletedCount} topup request(s)."
                    : 'No topup requests found to delete.';
            } else {
                $error = 'Failed to delete requests. Please try again.';
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    
    <!-- Emergency Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>"></script>
</head>
<body>
<!-- Mobile overlay for sidebar -->
<div class="mobile-overlay"></div>

<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
            <button type="button" class="sidebar-close" aria-label="Close navigation">&times;</button>
        </div>
                    <?php renderAdminSidebar(); ?>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button type="button" class="mobile-menu-toggle" aria-label="Open navigation menu">
                    <span class="mobile-menu-symbol" aria-hidden="true">&#9776;</span>
                </button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="breadcrumb-item active">Topup Requests</div>
                </nav>
            </div>
            <div class="header-actions">
                <button type="button" class="theme-toggle" aria-label="Toggle theme">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button type="button" class="user-dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name" style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div class="user-role" style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow" style="margin-left: 0.5rem;"></i>
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

            <div class="filters-bar" style="margin-bottom: 1.5rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <form method="get">
                    <select name="status" class="form-control" style="width: auto; min-width: 150px;" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Requests</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </form>
                <form method="post" style="margin-left: auto;" onsubmit="return confirm('Delete ALL admin topup requests? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="delete_all_requests">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete All
                    </button>
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
                            <table class="table topup-request-table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Requester</th>
                                        <th>Amount</th>
                                        <th>Payment Details</th>
                                        <th>Payment Ref</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                        <tr>
                                            <td data-label="Request ID"><strong><?php echo htmlspecialchars($request['request_id']); ?></strong></td>
                                            <td data-label="Requester">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($request['requester_name']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($request['requester_email']); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Amount"><strong style="color: var(--primary-color);"><?php echo htmlspecialchars(formatAdminTopupRequestAmount($request['amount'])); ?></strong></td>
                                            <td data-label="Payment Details">
                                                <div style="font-size: 0.875rem;">
                                                    <div><?php echo htmlspecialchars($request['network']); ?></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_name']); ?></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_number']); ?></div>
                                                </div>
                                            </td>
                                            <td data-label="Payment Ref">
                                                <?php if (!empty($request['payment_reference'])): ?>
                                                    <code><?php echo htmlspecialchars($request['payment_reference']); ?></code>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Status">
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
                                            <td data-label="Date"><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></td>
                                            <td data-label="Actions" class="actions-cell">
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-primary process-request-btn"
                                                        data-id="<?php echo intval($request['id']); ?>"
                                                        data-request-id="<?php echo htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-status="<?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-requester-name="<?php echo htmlspecialchars($request['requester_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-requester-email="<?php echo htmlspecialchars($request['requester_email'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-amount="<?php echo htmlspecialchars((string)$request['amount'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-created-at="<?php echo htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-network="<?php echo htmlspecialchars($request['network'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-wallet-name="<?php echo htmlspecialchars($request['wallet_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-wallet-number="<?php echo htmlspecialchars($request['wallet_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-payment-reference="<?php echo htmlspecialchars((string)($request['payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                    >
                                                        <i class="fas fa-edit"></i> Process
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-secondary view-request-btn"
                                                        data-id="<?php echo intval($request['id']); ?>"
                                                        data-request-id="<?php echo htmlspecialchars($request['request_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-status="<?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-requester-name="<?php echo htmlspecialchars($request['requester_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-requester-email="<?php echo htmlspecialchars($request['requester_email'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-amount="<?php echo htmlspecialchars((string)$request['amount'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-created-at="<?php echo htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-network="<?php echo htmlspecialchars($request['network'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-wallet-name="<?php echo htmlspecialchars($request['wallet_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-wallet-number="<?php echo htmlspecialchars($request['wallet_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-payment-reference="<?php echo htmlspecialchars((string)($request['payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                    >
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                <?php endif; ?>
                                                <form method="post" class="inline-action-form" onsubmit="return confirm('Delete this topup request permanently?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_request">
                                                    <input type="hidden" name="request_id" value="<?php echo intval($request['id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
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
            <button type="button" class="modal-close" aria-label="Close modal">&times;</button>
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
                <button type="button" class="btn btn-secondary close-modal-btn">Cancel</button>
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
    background-color: #10b981;
    color: white;
}

.badge-danger {
    background-color: #ef4444;
    color: white;
}

.badge-warning {
    background-color: #f59e0b;
    color: white;
}

.badge-secondary {
    background-color: #6b7280;
    color: white;
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: #059669;
    color: #ecfdf5;
}

[data-theme="dark"] .badge-danger {
    background-color: #dc2626;
    color: #fef2f2;
}

[data-theme="dark"] .badge-warning {
    background-color: #d97706;
    color: #fffbeb;
}

[data-theme="dark"] .badge-secondary {
    background-color: #4b5563;
    color: #f9fafb;
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
    background: var(--bg-secondary, #f8f9fa);
    color: var(--text-color);
}

[data-theme="dark"] .table thead th {
    background: var(--bg-secondary, #2d3748);
    color: var(--text-color, #f7fafc);
}

.table tbody tr {
    background-color: transparent;
}

.table tbody tr:hover {
    background-color: var(--bg-secondary);
}

[data-theme="dark"] .table tbody tr:hover {
    background-color: var(--bg-secondary, #2d3748);
}

[data-theme="dark"] .table tbody tr {
    background-color: transparent;
}

[data-theme="dark"] .table th,
[data-theme="dark"] .table td {
    border-color: var(--border-color, #4a5568);
    background-color: transparent;
    color: var(--text-color, #f7fafc);
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
    background-color: rgba(0, 0, 0, 0.5);
}

.modal-content {
    background-color: var(--bg-primary);
    margin: 5% auto;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

[data-theme="dark"] .modal-content {
    background-color: var(--bg-primary, #1a202c);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
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
    background: var(--input-bg, #fff);
    border: 1px solid var(--border-color, #ddd);
    color: var(--text-color, #333);
}

[data-theme="dark"] .modal .form-control {
    background: var(--input-bg, #2d3748);
    border: 1px solid var(--border-color, #4a5568);
    color: var(--text-color, #f7fafc);
}

/* Text muted styling */
.text-muted {
    color: var(--text-muted, #6b7280) !important;
}

[data-theme="dark"] .text-muted {
    color: var(--text-muted, #9ca3af) !important;
}

/* Empty state styling */
.empty-state {
    color: var(--text-muted);
}

[data-theme="dark"] .empty-state {
    color: var(--text-muted, #9ca3af);
}

.empty-state h3 {
    color: var(--text-color);
    margin: 1rem 0 0.5rem 0;
}

[data-theme="dark"] .empty-state h3 {
    color: var(--text-color, #f7fafc);
}

/* Widget styling for dark mode */
[data-theme="dark"] .widget {
    background: var(--widget-bg, #1a202c);
    border: 1px solid var(--border-color, #2d3748);
}

[data-theme="dark"] .widget-header {
    border-bottom: 1px solid var(--border-color, #2d3748);
}

[data-theme="dark"] .widget-title {
    color: var(--text-color, #f7fafc);
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

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>"></script>
<script>
const requestCurrencyCode = <?php echo json_encode(CURRENCY_CODE, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function updateTopupThemeIcon(theme) {
    const themeIcon = document.getElementById('theme-icon');
    if (!themeIcon) {
        return;
    }

    themeIcon.className = (theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon') + ' theme-icon';
}

function initTheme() {
    if (window.themeManager && typeof window.themeManager.getCurrentTheme === 'function') {
        updateTopupThemeIcon(window.themeManager.getCurrentTheme());
        return;
    }

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    const theme = savedTheme || (prefersDark ? 'dark' : 'light');

    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    updateTopupThemeIcon(theme);
}

function toggleTheme() {
    if (window.themeManager && typeof window.themeManager.toggleTheme === 'function') {
        window.themeManager.toggleTheme();
        updateTopupThemeIcon(window.themeManager.getCurrentTheme());
        return;
    }

    const currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateTopupThemeIcon(newTheme);
}

// Enhanced user dropdown functionality
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!dropdown || !toggle) return;

    const willShow = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willShow);
    toggle.classList.toggle('open', willShow);
    toggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!dropdown || !toggle) return;
    if (dropdown.contains(event.target) || toggle.contains(event.target)) return;

    dropdown.classList.remove('show');
    toggle.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
});

// Mobile menu toggle
function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.mobile-overlay');

    if (!sidebar || !overlay) return;

    const isOpen = sidebar.classList.contains('mobile-open') || sidebar.classList.contains('show') || sidebar.classList.contains('active');
    const shouldOpen = !isOpen;

    sidebar.classList.toggle('mobile-open', shouldOpen);
    sidebar.classList.toggle('show', shouldOpen);
    sidebar.classList.toggle('active', shouldOpen);
    overlay.classList.toggle('active', shouldOpen);
}

function closeMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.mobile-overlay');
    if (!sidebar || !overlay) return;

    sidebar.classList.remove('mobile-open', 'show', 'active');
    overlay.classList.remove('active');
}

function buildRequestFromButton(button) {
    if (!button || !button.dataset) return null;

    const request = {
        id: parseInt(button.dataset.id || '0', 10),
        request_id: button.dataset.requestId || '',
        status: button.dataset.status || '',
        requester_name: button.dataset.requesterName || '',
        requester_email: button.dataset.requesterEmail || '',
        amount: button.dataset.amount || '0',
        created_at: button.dataset.createdAt || '',
        network: button.dataset.network || '',
        wallet_name: button.dataset.walletName || '',
        wallet_number: button.dataset.walletNumber || '',
        payment_reference: button.dataset.paymentReference || ''
    };

    if (!request.id || !request.request_id) return null;
    return request;
}

function formatRequestAmount(amount) {
    const numericAmount = Number.parseFloat(amount);
    const safeAmount = Number.isFinite(numericAmount) ? numericAmount : 0;
    return `${requestCurrencyCode} ${safeAmount.toFixed(2)}`;
}

function bindTopupPageControls() {
    const themeToggleButton = document.querySelector('.theme-toggle');
    if (themeToggleButton && !themeToggleButton.dataset.boundThemeToggle) {
        themeToggleButton.dataset.boundThemeToggle = 'true';
        themeToggleButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleTheme();
        });
    }

    const userDropdownToggle = document.querySelector('.user-dropdown-toggle');
    if (userDropdownToggle && !userDropdownToggle.dataset.boundUserDropdown) {
        userDropdownToggle.dataset.boundUserDropdown = 'true';
        userDropdownToggle.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleUserDropdown();
        });
    }
}

document.addEventListener('click', function(event) {
    const menuBtn = event.target.closest('.mobile-menu-toggle');
    if (menuBtn) {
        event.preventDefault();
        toggleMobileMenu();
        return;
    }

    const sidebarCloseBtn = event.target.closest('.sidebar-close');
    if (sidebarCloseBtn) {
        event.preventDefault();
        closeMobileMenu();
        return;
    }

    const overlayEl = event.target.closest('.mobile-overlay');
    if (overlayEl && overlayEl.classList.contains('active')) {
        event.preventDefault();
        closeMobileMenu();
        return;
    }

    const modalCloseBtn = event.target.closest('.modal-close, .close-modal-btn');
    if (modalCloseBtn) {
        event.preventDefault();
        closeModal();
        return;
    }

    const processBtn = event.target.closest('.process-request-btn');
    if (processBtn) {
        event.preventDefault();
        const request = buildRequestFromButton(processBtn);
        if (!request) {
            alert('Unable to load request details. Please refresh and try again.');
            return;
        }
        openProcessModal(request);
        return;
    }

    const viewBtn = event.target.closest('.view-request-btn');
    if (viewBtn) {
        event.preventDefault();
        const request = buildRequestFromButton(viewBtn);
        if (!request) {
            alert('Unable to load request details. Please refresh and try again.');
            return;
        }
        viewRequest(request);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    bindTopupPageControls();
});

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
                    <span class="detail-value amount">${formatRequestAmount(request.amount)}</span>
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
                <div class="payment-reference" style="margin-top:0.75rem;font-size:0.875rem;">
                    <strong>Payment Reference:</strong> ${request.payment_reference ? request.payment_reference : 'N/A'}
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
    
    const details = [
        `Request ID: ${request.request_id}`,
        `Requester: ${request.requester_name}`,
        `Email: ${request.requester_email}`,
        `Amount: ${formatRequestAmount(request.amount)}`,
        `Status: ${request.status.charAt(0).toUpperCase() + request.status.slice(1)}`,
        `Date: ${formattedDate}`,
        `Payment: ${request.network} - ${request.wallet_name} (${request.wallet_number})`,
        `Payment Reference: ${request.payment_reference ? request.payment_reference : 'N/A'}`
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
    background-color: #10b981;
    color: white;
}

.badge-danger {
    background-color: #ef4444;
    color: white;
}

.badge-warning {
    background-color: #f59e0b;
    color: white;
}

.badge-secondary {
    background-color: #6b7280;
    color: white;
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: #059669;
    color: #ecfdf5;
}

[data-theme="dark"] .badge-danger {
    background-color: #dc2626;
    color: #fef2f2;
}

[data-theme="dark"] .badge-warning {
    background-color: #d97706;
    color: #fffbeb;
}

[data-theme="dark"] .badge-secondary {
    background-color: #4b5563;
    color: #f9fafb;
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
    background: var(--bg-secondary, #f8f9fa);
    color: var(--text-color);
}

[data-theme="dark"] .table thead th {
    background: var(--bg-secondary, #2d3748);
    color: var(--text-color, #f7fafc);
}

.table tbody tr {
    background-color: transparent;
}

.table tbody tr:hover {
    background-color: var(--bg-secondary);
}

[data-theme="dark"] .table tbody tr:hover {
    background-color: var(--bg-secondary, #2d3748);
}

[data-theme="dark"] .table tbody tr {
    background-color: transparent;
}

[data-theme="dark"] .table th,
[data-theme="dark"] .table td {
    border-color: var(--border-color, #4a5568);
    background-color: transparent;
    color: var(--text-color, #f7fafc);
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
    background-color: rgba(0, 0, 0, 0.5);
}

.modal-content {
    background-color: var(--bg-primary);
    margin: 5% auto;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

[data-theme="dark"] .modal-content {
    background-color: var(--bg-primary, #1a202c);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
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
    background: var(--input-bg, #fff);
    border: 1px solid var(--border-color, #ddd);
    color: var(--text-color, #333);
}

[data-theme="dark"] .modal .form-control {
    background: var(--input-bg, #2d3748);
    border: 1px solid var(--border-color, #4a5568);
    color: var(--text-color, #f7fafc);
}

/* Text muted styling */
.text-muted {
    color: var(--text-muted, #6b7280) !important;
}

[data-theme="dark"] .text-muted {
    color: var(--text-muted, #9ca3af) !important;
}

/* Empty state styling */
.empty-state {
    color: var(--text-muted);
}

[data-theme="dark"] .empty-state {
    color: var(--text-muted, #9ca3af);
}

.empty-state h3 {
    color: var(--text-color);
    margin: 1rem 0 0.5rem 0;
}

[data-theme="dark"] .empty-state h3 {
    color: var(--text-color, #f7fafc);
}

/* Widget styling for dark mode */
[data-theme="dark"] .widget {
    background: var(--widget-bg, #1a202c);
    border: 1px solid var(--border-color, #2d3748);
}

[data-theme="dark"] .widget-header {
    border-bottom: 1px solid var(--border-color, #2d3748);
}

[data-theme="dark"] .widget-title {
    color: var(--text-color, #f7fafc);
}

/* Alert styling for dark mode */
[data-theme="dark"] .alert-success {
    background: #1e3a8a;
    color: #93c5fd;
    border: 1px solid #3b82f6;
}

[data-theme="dark"] .alert-danger {
    background: #7f1d1d;
    color: #fca5a5;
    border: 1px solid #ef4444;
}

/* Page title styling for dark mode */
[data-theme="dark"] .page-title h1 {
    color: var(--text-color, #f9fafb);
}

[data-theme="dark"] .page-subtitle {
    color: var(--text-muted, #9ca3af);
}

/* Form control styling for dark mode */
[data-theme="dark"] .form-control {
    background: var(--input-bg, #2d3748);
    border: 1px solid var(--border-color, #4a5568);
    color: var(--text-color, #f7fafc);
}

[data-theme="dark"] .form-control:focus {
    border-color: var(--primary-color, #6366f1);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
}

/* Button styling for dark mode */
[data-theme="dark"] .btn-secondary {
    background: var(--secondary-bg, #374151);
    color: var(--text-color, #f9fafb);
    border: 1px solid var(--border-color, #4b5563);
}

[data-theme="dark"] .btn-secondary:hover {
    background: var(--secondary-hover, #4b5563);
}

.topup-request-table td small {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-muted);
}

.actions-cell .btn {
    white-space: nowrap;
}

.actions-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}

.actions-cell .inline-action-form {
    margin: 0;
}

@media (min-width: 992px) {
    .table-responsive {
        overflow-x: hidden;
    }

    .topup-request-table {
        table-layout: fixed;
    }

    .topup-request-table th,
    .topup-request-table td {
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .topup-request-table td code,
    .topup-request-table td small,
    .topup-request-table td strong,
    .topup-request-table td > div,
    .topup-request-table td > span {
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .topup-request-table .actions-cell .btn {
        white-space: normal;
    }
}

/* Enhanced responsive design and mobile optimizations */
@media (max-width: 991px) {
    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-wrapper,
    .main-content,
    .dashboard-content,
    .widget,
    .widget-content {
        max-width: 100%;
        overflow-x: hidden;
    }

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

    .filters-bar form {
        width: 100%;
    }

    .filters-bar .form-control {
        width: 100% !important;
        min-width: 0 !important;
    }
    
    .table th,
    .table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.8125rem;
    }

    .table-responsive {
        overflow-x: hidden;
    }

    .topup-request-table thead {
        display: none;
    }

    .topup-request-table,
    .topup-request-table tbody,
    .topup-request-table tr,
    .topup-request-table td {
        display: block;
        width: 100%;
    }

    .topup-request-table tr {
        margin-bottom: 0.875rem;
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        padding: 0.75rem;
        background: var(--bg-primary);
        box-sizing: border-box;
    }

    .topup-request-table td {
        border-top: none;
        padding: 0.5rem 0;
        font-size: 0.875rem;
        display: block;
        line-height: 1.35;
        min-width: 0;
        overflow-wrap: anywhere;
        word-break: break-word;
        white-space: normal;
    }

    .topup-request-table td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.25rem;
        font-weight: 600;
        color: var(--text-muted);
        min-width: 0;
    }

    .topup-request-table td > div,
    .topup-request-table td span,
    .topup-request-table td small,
    .topup-request-table td strong {
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .topup-request-table td.actions-cell {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        align-items: center;
        padding-top: 0.7rem;
    }

    .topup-request-table td.actions-cell::before {
        display: block;
        width: 100%;
        margin-bottom: 0.35rem;
    }

    .topup-request-table td.actions-cell .btn {
        width: auto;
        min-width: 0;
        justify-content: center;
        font-size: 0.75rem;
        line-height: 1.2;
        padding: 0.3rem 0.55rem;
    }

    .topup-request-table td.actions-cell .inline-action-form {
        width: auto;
        margin: 0;
        display: inline-flex;
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

.mobile-menu-symbol {
    display: inline-block;
    line-height: 1;
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
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.mobile-overlay.active {
    display: block;
    opacity: 1;
    pointer-events: auto;
}

@media (max-width: 991px) {
    .mobile-menu-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
}
</style>

<!-- Include theme management -->
<script src="../immediate_icon_fix.js"></script>
</body>
</html>

