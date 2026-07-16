<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/email.php';

requireRole('admin');

ensureEmailChangeRequestsTable();

$flash = getFlashMessage();
$csrf = generateCSRF();
$current_admin_id = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token');
        header('Location: email-change-requests.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $request_id = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;

    if ($request_id <= 0) {
        setFlashMessage('error', 'Invalid request selected.');
        header('Location: email-change-requests.php');
        exit;
    }

    $stmt = $db->prepare("
        SELECT ecr.*, u.email AS user_email, u.full_name, u.username
        FROM email_change_requests ecr
        JOIN users u ON u.id = ecr.user_id
        WHERE ecr.id = ? LIMIT 1
    ");
    if (!$stmt) {
        setFlashMessage('error', 'Unable to process request.');
        header('Location: email-change-requests.php');
        exit;
    }
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();

    if (!$request) {
        setFlashMessage('error', 'Request not found.');
        header('Location: email-change-requests.php');
        exit;
    }

    if ($request['status'] !== 'pending') {
        setFlashMessage('warning', 'This request has already been processed.');
        header('Location: email-change-requests.php');
        exit;
    }

    if ($action === 'approve') {
        $requested_email = $request['requested_email'] ?? '';
        $user_id = (int) $request['user_id'];

        $check = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1");
        if ($check) {
            $check->bind_param('si', $requested_email, $user_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                setFlashMessage('error', 'This email address is already in use.');
                header('Location: email-change-requests.php');
                exit;
            }
        }

        $update = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
        if (!$update) {
            setFlashMessage('error', 'Failed to update user email.');
            header('Location: email-change-requests.php');
            exit;
        }

        $update->bind_param('si', $requested_email, $user_id);
        if (!$update->execute()) {
            setFlashMessage('error', 'Failed to update user email.');
            header('Location: email-change-requests.php');
            exit;
        }

        $approve = $db->prepare("UPDATE email_change_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        if ($approve) {
            $approve->bind_param('ii', $current_admin_id, $request_id);
            $approve->execute();
        }

        if (function_exists('logActivity')) {
            $details = sprintf(
                'Approved email change for %s (%s) to %s.',
                $request['full_name'] ?: $request['username'],
                $request['user_email'],
                $requested_email
            );
            logActivity($current_admin_id, 'email_change_approved', $details);
        }

        if (!empty($requested_email) && function_exists('sendEmail') && validateEmail($requested_email)) {
            $site_name = function_exists('getSiteName') ? getSiteName() : SITE_NAME;
            $subject = $site_name . ' Email Change Approved';
            $body_html = '
                <p>Hello ' . htmlspecialchars($request['full_name'] ?: $request['username']) . ',</p>
                <p>Your email change request has been approved. Your account email is now:</p>
                <p><strong>' . htmlspecialchars($requested_email) . '</strong></p>
                <p>If you did not request this change, please contact support immediately.</p>
            ';
            $body_text = "Hello " . ($request['full_name'] ?: $request['username']) . ",\n\nYour email change request has been approved. Your account email is now: {$requested_email}\n\nIf you did not request this change, please contact support immediately.";
            sendEmail($requested_email, $subject, $body_html, $body_text, 'email_change_approved');
        }

        setFlashMessage('success', 'Email change approved and updated.');
        header('Location: email-change-requests.php');
        exit;
    }

    if ($action === 'reject') {
        $reject = $db->prepare("UPDATE email_change_requests SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        if ($reject) {
            $reject->bind_param('ii', $current_admin_id, $request_id);
            $reject->execute();
        }

        if (function_exists('logActivity')) {
            $details = sprintf(
                'Rejected email change request for %s (%s).',
                $request['full_name'] ?: $request['username'],
                $request['user_email']
            );
            logActivity($current_admin_id, 'email_change_rejected', $details);
        }

        $notify_email = $request['current_email'] ?? $request['user_email'] ?? '';
        if (!empty($notify_email) && function_exists('sendEmail') && validateEmail($notify_email)) {
            $site_name = function_exists('getSiteName') ? getSiteName() : SITE_NAME;
            $subject = $site_name . ' Email Change Rejected';
            $body_html = '
                <p>Hello ' . htmlspecialchars($request['full_name'] ?: $request['username']) . ',</p>
                <p>Your email change request to <strong>' . htmlspecialchars($request['requested_email']) . '</strong> was rejected.</p>
                <p>If you believe this is a mistake, please contact support.</p>
            ';
            $body_text = "Hello " . ($request['full_name'] ?: $request['username']) . ",\n\nYour email change request to {$request['requested_email']} was rejected.\n\nIf you believe this is a mistake, please contact support.";
            sendEmail($notify_email, $subject, $body_html, $body_text, 'email_change_rejected');
        }

        setFlashMessage('success', 'Email change request rejected.');
        header('Location: email-change-requests.php');
        exit;
    }

    setFlashMessage('error', 'Invalid action.');
    header('Location: email-change-requests.php');
    exit;
}

$pending_requests = [];
$stmt = $db->prepare("
    SELECT ecr.*, u.full_name, u.username, u.role
    FROM email_change_requests ecr
    JOIN users u ON u.id = ecr.user_id
    WHERE ecr.status = 'pending'
    ORDER BY ecr.created_at DESC
");
if ($stmt && $stmt->execute()) {
    $pending_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$history_requests = [];
$stmt = $db->prepare("
    SELECT ecr.*, u.full_name, u.username, u.role
    FROM email_change_requests ecr
    JOIN users u ON u.id = ecr.user_id
    WHERE ecr.status IN ('approved', 'rejected')
    ORDER BY ecr.reviewed_at DESC
    LIMIT 25
");
if ($stmt && $stmt->execute()) {
    $history_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = 'Email Change Requests';
include '../includes/admin_header.php';
?>

<div class="dashboard-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'] ?? 'info'); ?>">
            <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1>Email Change Requests</h1>
        <p class="page-subtitle">Approve or reject user email change requests.</p>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Pending Requests</h3>
        </div>
        <div class="card-body">
            <?php if (empty($pending_requests)): ?>
                <p class="text-muted">No pending requests.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Current Email</th>
                                <th>Requested Email</th>
                                <th>Requested At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $req): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($req['full_name'] ?: $req['username']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($req['role'])); ?></td>
                                    <td><?php echo htmlspecialchars($req['current_email']); ?></td>
                                    <td><?php echo htmlspecialchars($req['requested_email']); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($req['created_at']))); ?></td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Approve this email change?');">Approve</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Reject this email change?');">Reject</button>
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

    <div class="card" style="margin-top: 1.5rem;">
        <div class="card-header">
            <h3>Recent Decisions</h3>
        </div>
        <div class="card-body">
            <?php if (empty($history_requests)): ?>
                <p class="text-muted">No approved or rejected requests yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Requested Email</th>
                                <th>Status</th>
                                <th>Reviewed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history_requests as $req): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($req['full_name'] ?: $req['username']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($req['role'])); ?></td>
                                    <td><?php echo htmlspecialchars($req['requested_email']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($req['status'])); ?></td>
                                    <td>
                                        <?php echo !empty($req['reviewed_at']) ? htmlspecialchars(date('M j, Y H:i', strtotime($req['reviewed_at']))) : '-'; ?>
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

<?php include '../includes/admin_footer.php'; ?>
