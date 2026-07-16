<?php
require_once '../config/config.php';
require_once '../includes/email.php';
requireRole('agent');

if (function_exists('ensureTopupSettingsTable')) {
    ensureTopupSettingsTable();
}
if (function_exists('ensureTopupRequestTables')) {
    ensureTopupRequestTables();
}

$current_user = getCurrentUser();
$success = '';
$error = '';
$limits = getEffectiveTopupLimits($current_user['id'], 'agent');
$min_allowed = (float) ($limits['min'] ?? 5.00);
$max_allowed = (float) ($limits['max'] ?? 1000.00);

function generateAgentTopupRequestId($db) {
    do {
        $requestId = 'TR' . date('Ymd') . mt_rand(10000, 99999);
        $stmt = $db->prepare("SELECT id FROM topup_requests WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('s', $requestId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
    } while ($exists);

    return $requestId;
}

function getAdminTopupPaymentDetails($db) {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM topup_settings WHERE user_id IS NULL AND setting_key IN ('admin_topup_account_network', 'admin_topup_account_name', 'admin_topup_account_number', 'admin_topup_instructions')");
    $stmt->execute();
    $result = $stmt->get_result();

    $settings = [
        'network' => 'MTN MOMO',
        'name' => 'Constechzhub Admin',
        'number' => '0245152060',
        'instructions' => 'Send payment to this account, then submit your request with your sender details and transaction reference.'
    ];

    while ($row = $result->fetch_assoc()) {
        $key = $row['setting_key'];
        $value = trim((string) ($row['setting_value'] ?? ''));
        if ($key === 'admin_topup_account_network' && $value !== '') {
            $settings['network'] = $value;
        } elseif ($key === 'admin_topup_account_name' && $value !== '') {
            $settings['name'] = $value;
        } elseif ($key === 'admin_topup_account_number' && $value !== '') {
            $settings['number'] = $value;
        } elseif ($key === 'admin_topup_instructions' && $value !== '') {
            $settings['instructions'] = $value;
        }
    }

    return $settings;
}

function notifyAdminForAgentTopupRequest($db, array $currentUser, array $requestData) {
    $adminId = 0;
    $recipientEmail = '';
    $recipientPhone = '';

    $adminStmt = $db->prepare("SELECT id, full_name, email, phone FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    if ($adminStmt && $adminStmt->execute()) {
        $admin = $adminStmt->get_result()->fetch_assoc();
        if ($admin) {
            $adminId = (int) ($admin['id'] ?? 0);
            $recipientEmail = trim((string) ($admin['email'] ?? ''));
            $recipientPhone = trim((string) ($admin['phone'] ?? ''));
        }
    }

    if ($recipientEmail === '' && defined('ADMIN_EMAIL')) {
        $recipientEmail = trim((string) ADMIN_EMAIL);
    }

    $emailSent = false;
    $errorMessage = '';

    $safeRequestId = htmlspecialchars((string) ($requestData['request_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars(number_format((float) ($requestData['amount'] ?? 0), 2), ENT_QUOTES, 'UTF-8');
    $safeAgentName = htmlspecialchars((string) ($currentUser['full_name'] ?? 'Agent'), ENT_QUOTES, 'UTF-8');
    $safeAgentEmail = htmlspecialchars((string) ($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeUserEmail = htmlspecialchars((string) ($requestData['user_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSenderNetwork = htmlspecialchars((string) ($requestData['sender_network'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSenderName = htmlspecialchars((string) ($requestData['sender_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSenderNumber = htmlspecialchars((string) ($requestData['sender_number'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safePaymentReference = htmlspecialchars((string) ($requestData['payment_reference'] ?? ''), ENT_QUOTES, 'UTF-8');

    $subject = "New Agent Topup Request - " . ($requestData['request_id'] ?? '');
    $bodyHtml = "
        <h3>New Agent Topup Request Received</h3>
        <p><strong>Request ID:</strong> {$safeRequestId}</p>
        <p><strong>Amount:</strong> " . CURRENCY . "{$safeAmount}</p>
        <p><strong>Agent:</strong> {$safeAgentName}</p>
        <p><strong>Agent Email:</strong> {$safeAgentEmail}</p>
        <p><strong>Submitted Email:</strong> {$safeUserEmail}</p>
        <p><strong>Sender Network:</strong> {$safeSenderNetwork}</p>
        <p><strong>Sender Name:</strong> {$safeSenderName}</p>
        <p><strong>Sender Number:</strong> {$safeSenderNumber}</p>
        <p><strong>Payment Reference:</strong> {$safePaymentReference}</p>
        <p>Please review and process this request in the admin portal.</p>
    ";
    $bodyText = "New agent topup request received.\n"
        . "Request ID: " . ($requestData['request_id'] ?? '') . "\n"
        . "Amount: " . CURRENCY . number_format((float) ($requestData['amount'] ?? 0), 2) . "\n"
        . "Agent: " . ($currentUser['full_name'] ?? 'Agent') . "\n"
        . "Agent Email: " . ($currentUser['email'] ?? '') . "\n"
        . "Submitted Email: " . ($requestData['user_email'] ?? '') . "\n"
        . "Sender Network: " . ($requestData['sender_network'] ?? '') . "\n"
        . "Sender Name: " . ($requestData['sender_name'] ?? '') . "\n"
        . "Sender Number: " . ($requestData['sender_number'] ?? '') . "\n"
        . "Payment Reference: " . ($requestData['payment_reference'] ?? '') . "\n";

    try {
        if ($recipientEmail !== '') {
            $emailSent = sendEmail($recipientEmail, $subject, $bodyHtml, $bodyText, 'agent_topup_request_admin');
        } else {
            $errorMessage = 'Admin email not configured';
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('Agent topup request email notification failed: ' . $e->getMessage());
    }

    $notificationStatus = $emailSent ? 'sent' : 'failed';
    $logError = $emailSent ? '' : ($errorMessage !== '' ? $errorMessage : 'Failed to send email');
    $notifStmt = $db->prepare("INSERT INTO topup_request_notifications (request_id, notification_type, recipient_email, recipient_phone, status, sms_sent, error_message) VALUES (?, 'email', ?, ?, ?, 0, ?)");
    if ($notifStmt) {
        $requestId = (string) ($requestData['request_id'] ?? '');
        $notifStmt->bind_param('sssss', $requestId, $recipientEmail, $recipientPhone, $notificationStatus, $logError);
        $notifStmt->execute();
    }

    if ($adminId > 0) {
        logActivity($adminId, 'admin_topup_request_received', json_encode([
            'request_id' => $requestData['request_id'] ?? '',
            'amount' => (float) ($requestData['amount'] ?? 0),
            'from_agent_id' => (int) ($currentUser['id'] ?? 0),
            'from_agent_name' => $currentUser['full_name'] ?? '',
            'email_sent' => $emailSent ? 1 : 0
        ]));
    }

    return [
        'email_sent' => $emailSent,
        'error' => $logError
    ];
}

$adminPaymentDetails = getAdminTopupPaymentDetails($db);

// Handle request processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'submit_admin_request') {
            $amount = (float) ($_POST['amount'] ?? 0);
            $userEmail = trim((string) ($_POST['user_email'] ?? ''));
            $senderNetwork = trim((string) ($_POST['sender_network'] ?? ''));
            $senderName = trim((string) ($_POST['sender_name'] ?? ''));
            $senderNumber = trim((string) ($_POST['sender_number'] ?? ''));
            $paymentReference = trim((string) ($_POST['payment_reference'] ?? ''));
            $paymentConfirmed = isset($_POST['payment_confirmed']) && $_POST['payment_confirmed'] === '1';

            if ($amount < $min_allowed || $amount > $max_allowed) {
                $error = 'Invalid amount. Please use the allowed topup range.';
            } elseif (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please provide a valid email address.';
            } elseif ($senderNetwork === '' || $senderName === '' || $senderNumber === '') {
                $error = 'Sender payment details are required.';
            } elseif ($paymentReference === '') {
                $error = 'Payment reference is required.';
            } elseif (!$paymentConfirmed) {
                $error = 'You must confirm payment before submitting a topup request.';
            } else {
                $requestId = generateAgentTopupRequestId($db);
                $requesterType = 'agent';
                $targetType = 'admin';

                $stmt = $db->prepare("INSERT INTO topup_requests (request_id, requester_id, requester_type, target_type, target_agent_id, amount, user_email, network, wallet_name, wallet_number, payment_reference) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    'sissdsssss',
                    $requestId,
                    $current_user['id'],
                    $requesterType,
                    $targetType,
                    $amount,
                    $userEmail,
                    $senderNetwork,
                    $senderName,
                    $senderNumber,
                    $paymentReference
                );

                if ($stmt->execute()) {
                    $notificationResult = notifyAdminForAgentTopupRequest($db, $current_user, [
                        'request_id' => $requestId,
                        'amount' => $amount,
                        'user_email' => $userEmail,
                        'sender_network' => $senderNetwork,
                        'sender_name' => $senderName,
                        'sender_number' => $senderNumber,
                        'payment_reference' => $paymentReference
                    ]);

                    logActivity($current_user['id'], 'agent_topup_request_submitted_to_admin', json_encode([
                        'request_id' => $requestId,
                        'amount' => $amount,
                        'payment_reference' => $paymentReference,
                        'admin_email_notified' => $notificationResult['email_sent'] ? 1 : 0
                    ]));
                    $success = "Topup request submitted successfully. Request ID: {$requestId}";
                    if (!$notificationResult['email_sent']) {
                        $success .= ' Request was saved, but admin email notification failed.';
                    }
                } else {
                    $error = 'Failed to submit topup request. Please try again.';
                }
            }
        } elseif ($action === 'process_request') {
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
        } elseif ($action === 'delete_request') {
            $requestId = intval($_POST['request_id'] ?? 0);

            if ($requestId <= 0) {
                $error = 'Invalid request ID.';
            } else {
                $stmt = $db->prepare("SELECT id, request_id FROM topup_requests WHERE id = ? AND target_type = 'agent' AND target_agent_id = ? LIMIT 1");
                $stmt->bind_param('ii', $requestId, $current_user['id']);
                $stmt->execute();
                $request = $stmt->get_result()->fetch_assoc();

                if (!$request) {
                    $error = 'Request not found.';
                } else {
                    $stmt = $db->prepare("DELETE FROM topup_requests WHERE id = ? AND target_type = 'agent' AND target_agent_id = ? LIMIT 1");
                    $stmt->bind_param('ii', $requestId, $current_user['id']);

                    if ($stmt->execute() && $stmt->affected_rows > 0) {
                        logActivity($current_user['id'], 'agent_topup_request_deleted', json_encode([
                            'request_id' => $request['request_id'],
                            'target_type' => 'agent'
                        ]));
                        $success = "Request {$request['request_id']} deleted successfully.";
                    } else {
                        $error = 'Failed to delete request. Please try again.';
                    }
                }
            }
        } elseif ($action === 'delete_all_requests') {
            $stmt = $db->prepare("DELETE FROM topup_requests WHERE target_type = 'agent' AND target_agent_id = ?");
            $stmt->bind_param('i', $current_user['id']);

            if ($stmt->execute()) {
                $deletedCount = (int) $stmt->affected_rows;
                logActivity($current_user['id'], 'agent_topup_requests_deleted_all', json_encode([
                    'target_type' => 'agent',
                    'target_agent_id' => $current_user['id'],
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

// Recent requests submitted by this agent to admin
$myAdminRequests = [];
$myPendingToAdminCount = 0;

$stmt = $db->prepare("
    SELECT tr.*, p.full_name AS processed_by_name
    FROM topup_requests tr
    LEFT JOIN users p ON tr.processed_by = p.id
    WHERE tr.requester_id = ? AND tr.target_type = 'admin'
    ORDER BY tr.created_at DESC
    LIMIT 15
");
$stmt->bind_param('i', $current_user['id']);
$stmt->execute();
$myAdminRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $db->prepare("SELECT COUNT(*) AS count FROM topup_requests WHERE requester_id = ? AND target_type = 'admin' AND status = 'pending'");
$stmt->bind_param('i', $current_user['id']);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $myPendingToAdminCount = (int) $row['count'];
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
<div class="mobile-overlay" id="mobileOverlay" aria-hidden="true"></div>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        
        <?php renderAgentSidebar(); ?>
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

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">
                        <i class="fas fa-paper-plane"></i>
                        Send Topup Request To Admin
                        <?php if ($myPendingToAdminCount > 0): ?>
                            <span class="badge badge-warning"><?php echo (int) $myPendingToAdminCount; ?> Pending</span>
                        <?php endif; ?>
                    </h3>
                </div>
                <div class="widget-body">
                    <div class="alert alert-info">
                        <strong>Payment is compulsory:</strong> Pay to the admin account below first, then submit your request with your sender details and payment reference.
                    </div>

                    <div class="payment-details-card">
                        <div class="payment-detail-row">
                            <span class="label">Admin Account Network:</span>
                            <span class="value"><?php echo htmlspecialchars($adminPaymentDetails['network']); ?></span>
                        </div>
                        <div class="payment-detail-row">
                            <span class="label">Admin Account Name:</span>
                            <span class="value"><?php echo htmlspecialchars($adminPaymentDetails['name']); ?></span>
                        </div>
                        <div class="payment-detail-row">
                            <span class="label">Admin Account Number:</span>
                            <span class="value"><?php echo htmlspecialchars($adminPaymentDetails['number']); ?></span>
                        </div>
                        <div class="payment-instructions">
                            <?php echo nl2br(htmlspecialchars($adminPaymentDetails['instructions'])); ?>
                        </div>
                    </div>

                    <form method="post" class="request-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="submit_admin_request">

                        <div class="form-group">
                            <label class="form-label" for="amount">Amount (<?php echo CURRENCY; ?>) *</label>
                            <input
                                type="number"
                                id="amount"
                                name="amount"
                                class="form-control"
                                min="<?php echo htmlspecialchars(number_format($min_allowed, 2, '.', '')); ?>"
                                max="<?php echo htmlspecialchars(number_format($max_allowed, 2, '.', '')); ?>"
                                step="0.01"
                                required
                            >
                            <small class="text-muted">Min: <?php echo CURRENCY; ?><?php echo htmlspecialchars(number_format($min_allowed, 2)); ?>, Max: <?php echo CURRENCY; ?><?php echo htmlspecialchars(number_format($max_allowed, 2)); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="user_email">Your Email *</label>
                            <input
                                type="email"
                                id="user_email"
                                name="user_email"
                                class="form-control"
                                value="<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sender_network">Payment Network Used *</label>
                            <select id="sender_network" name="sender_network" class="form-control" required>
                                <option value="">Select network</option>
                                <option value="MTN MOMO">MTN Mobile Money</option>
                                <option value="VODAFONE CASH">Vodafone Cash</option>
                                <option value="AIRTELTIGO MONEY">AirtelTigo Money</option>
                                <option value="BANK TRANSFER">Bank Transfer</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sender_name">Sender Name *</label>
                            <input
                                type="text"
                                id="sender_name"
                                name="sender_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($current_user['full_name'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sender_number">Sender Phone/Account Number *</label>
                            <input
                                type="text"
                                id="sender_number"
                                name="sender_number"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="payment_reference">Payment Reference / Transaction ID *</label>
                            <input
                                type="text"
                                id="payment_reference"
                                name="payment_reference"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="form-group full-width">
                            <label class="payment-confirm-check">
                                <input type="checkbox" name="payment_confirmed" value="1" required>
                                <span>I confirm I have already made this payment to the admin account above.</span>
                            </label>
                        </div>

                        <div class="form-group full-width">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Request To Admin
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">
                        <i class="fas fa-clock"></i>
                        My Topup Requests To Admin
                    </h3>
                </div>
                <div class="widget-body">
                    <?php if (empty($myAdminRequests)): ?>
                        <div class="empty-state" style="text-align: center; padding: 2rem 1rem; color: var(--text-muted);">
                            <h3>No requests submitted yet</h3>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table topup-request-table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Amount</th>
                                        <th>Payment Reference</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>Processed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($myAdminRequests as $request): ?>
                                        <?php
                                        $statusClass = 'badge-secondary';
                                        if ($request['status'] === 'approved') $statusClass = 'badge-success';
                                        elseif ($request['status'] === 'rejected') $statusClass = 'badge-danger';
                                        elseif ($request['status'] === 'pending') $statusClass = 'badge-warning';
                                        ?>
                                        <tr>
                                            <td data-label="Request ID"><strong><?php echo htmlspecialchars($request['request_id']); ?></strong></td>
                                            <td data-label="Amount"><strong style="color: var(--primary-color);"><?php echo CURRENCY; ?><?php echo number_format((float) $request['amount'], 2); ?></strong></td>
                                            <td data-label="Payment Reference"><?php echo htmlspecialchars($request['payment_reference'] ?? 'N/A'); ?></td>
                                            <td data-label="Status"><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($request['status']); ?></span></td>
                                            <td data-label="Submitted"><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></td>
                                            <td data-label="Processed By"><?php echo htmlspecialchars($request['processed_by_name'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="filters-bar" style="margin-bottom: 1.5rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <form method="get">
                    <select name="status" class="form-control" style="width: auto; min-width: 150px;" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Requests</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </form>
                <form method="post" style="margin-left: auto;" onsubmit="return confirm('Delete ALL topup requests sent to you? This cannot be undone.');">
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
                            <table class="table topup-request-table">
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
                                            <td data-label="Request ID"><strong><?php echo htmlspecialchars($request['request_id']); ?></strong></td>
                                            <td data-label="Customer">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($request['requester_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($request['requester_email']); ?></small>
                                                </div>
                                            </td>
                                            <td data-label="Amount"><strong style="color: var(--primary-color);"><?php echo CURRENCY; ?><?php echo number_format($request['amount'], 2); ?></strong></td>
                                            <td data-label="Payment Details">
                                                <div style="font-size: 0.875rem;">
                                                    <div><strong><?php echo htmlspecialchars($request['network']); ?></strong></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_name']); ?></div>
                                                    <div><?php echo htmlspecialchars($request['wallet_number']); ?></div>
                                                </div>
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
                                                    <button class="btn btn-sm btn-primary" onclick="openProcessModal(<?php echo htmlspecialchars(json_encode($request)); ?>)">
                                                        <i class="fas fa-edit"></i> Process
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-secondary" onclick="viewRequest(<?php echo htmlspecialchars(json_encode($request)); ?>)">
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
    background-color: var(--success-bg, #d1fae5);
    color: var(--success-text, #065f46);
    border: 1px solid var(--success-border, #10b981);
}

.badge-danger {
    background-color: var(--danger-bg, #fecaca);
    color: var(--danger-text, #991b1b);
    border: 1px solid var(--danger-border, #ef4444);
}

.badge-warning {
    background-color: var(--warning-bg, #fef3c7);
    color: var(--warning-text, #92400e);
    border: 1px solid var(--warning-border, #f59e0b);
}

.badge-secondary {
    background-color: var(--secondary-bg, #f3f4f6);
    color: var(--secondary-text, #374151);
    border: 1px solid var(--secondary-border, #6b7280);
}

/* Dark mode badge adjustments */
[data-theme="dark"] .badge-success {
    background-color: var(--success-bg, #064e3b);
    color: var(--success-text, #a7f3d0);
    border-color: var(--success-border, #059669);
}

[data-theme="dark"] .badge-danger {
    background-color: var(--danger-bg, #7f1d1d);
    color: var(--danger-text, #fca5a5);
    border-color: var(--danger-border, #dc2626);
}

[data-theme="dark"] .badge-warning {
    background-color: var(--warning-bg, #78350f);
    color: var(--warning-text, #fbbf24);
    border-color: var(--warning-border, #d97706);
}

[data-theme="dark"] .badge-secondary {
    background-color: var(--secondary-bg, #374151);
    color: var(--secondary-text, #d1d5db);
    border-color: var(--secondary-border, #4b5563);
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
    background-color: var(--bg-subtle, rgba(0, 0, 0, 0.02));
}

[data-theme="dark"] .table tbody tr:nth-child(even) {
    background-color: var(--bg-subtle, rgba(255, 255, 255, 0.02));
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

.actions-cell .btn {
    white-space: nowrap;
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
    background-color: rgba(0, 0, 0, 0.6);
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
    box-shadow: 0 0 0 3px var(--brand-primary-alpha, rgba(99, 102, 241, 0.1));
}

.form-control:disabled {
    background: var(--bg-muted);
    color: var(--text-muted);
    cursor: not-allowed;
}

.payment-details-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 0.75rem;
    padding: 1rem;
    margin-bottom: 1rem;
}

.payment-detail-row {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--border-color);
}

.payment-detail-row:last-of-type {
    border-bottom: none;
}

.payment-detail-row .label {
    color: var(--text-muted);
    font-weight: 500;
}

.payment-detail-row .value {
    color: var(--text-primary);
    font-weight: 600;
    text-align: right;
}

.payment-instructions {
    margin-top: 0.85rem;
    font-size: 0.875rem;
    color: var(--text-muted);
}

.request-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.request-form-grid .full-width {
    grid-column: 1 / -1;
}

.payment-confirm-check {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    font-size: 0.9rem;
    color: var(--text-primary);
}

.payment-confirm-check input {
    margin-top: 0.25rem;
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
    color: white;
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

/* Mobile overlay and stacked-card table behavior */
.mobile-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 1190;
    opacity: 0;
    transition: opacity 0.2s ease;
    pointer-events: none;
}

.mobile-overlay.active {
    opacity: 1;
    pointer-events: auto;
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

@media (max-width: 991px) {
    .mobile-menu-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .mobile-overlay {
        display: block;
    }

    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: min(86vw, 320px);
        z-index: 1200;
        transform: translateX(-105%);
        transition: transform 0.25s ease;
    }

    .sidebar.show,
    .sidebar.mobile-open {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        width: 100%;
    }

    .dashboard-content,
    .widget,
    .widget-body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-header {
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
    }

    .header-left {
        min-width: 0;
        flex: 1 1 auto;
    }

    .breadcrumb {
        min-width: 0;
    }

    .breadcrumb .breadcrumb-item:last-child {
        max-width: 56vw;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .header-actions {
        position: static;
        margin-left: auto;
        z-index: auto;
    }

    .filters-bar {
        align-items: stretch !important;
    }

    .filters-bar form {
        width: 100%;
        margin-left: 0 !important;
    }

    .filters-bar .form-control {
        width: 100% !important;
        min-width: 0 !important;
    }

    .filters-bar .btn {
        width: 100%;
        justify-content: center;
    }

    .request-form-grid {
        grid-template-columns: 1fr;
    }

    .payment-detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.35rem;
    }

    .payment-detail-row .value {
        text-align: left;
    }

    .table-scroll-wrapper {
        overflow: visible;
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
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        background: var(--bg-primary);
        margin-bottom: 0.85rem;
        padding: 0.75rem;
    }

    .topup-request-table td {
        border: none;
        padding: 0.45rem 0;
        font-size: 0.875rem;
        overflow-wrap: anywhere;
        word-break: break-word;
        white-space: normal;
    }

    .topup-request-table td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.2rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .actions-cell {
        display: block;
        padding-top: 0.65rem !important;
    }

    .actions-cell .inline-action-form {
        width: 100%;
        margin-top: 0.5rem;
    }

    .actions-cell .btn {
        width: 100%;
        justify-content: center;
    }

    .modal-content {
        margin: 1rem auto;
        width: calc(100% - 1rem);
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

@media (max-width: 480px) {
    .page-title h1 {
        font-size: 1.15rem;
    }

    .page-subtitle {
        font-size: 0.85rem;
    }

    .topup-request-table tr {
        padding: 0.65rem;
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
    const overlay = document.getElementById('mobileOverlay');
    if (!sidebar || !overlay) return;

    const shouldOpen = !sidebar.classList.contains('show') && !sidebar.classList.contains('mobile-open');
    sidebar.classList.toggle('show', shouldOpen);
    sidebar.classList.toggle('mobile-open', shouldOpen);
    overlay.classList.toggle('active', shouldOpen);
    document.body.style.overflow = shouldOpen ? 'hidden' : '';
}

function closeMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('mobileOverlay');
    if (!sidebar || !overlay) return;

    sidebar.classList.remove('show', 'mobile-open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
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
        return;
    }

    if (event.key === 'Escape') {
        closeMobileMenu();
    }
});

// Enhanced form handling
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('mobileOverlay');
    if (overlay) {
        overlay.addEventListener('click', function() {
            closeMobileMenu();
        });
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 991) {
                closeMobileMenu();
            }
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 991) {
            closeMobileMenu();
        }
    });

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
            if (event.type === 'pointerup' && event.pointerType === 'mouse') {
                // Mouse will trigger click immediately after pointerup; handle it only once via click.
                return;
            }
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
</body>
</html>


