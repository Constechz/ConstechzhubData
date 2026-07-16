<?php
require_once '../config/config.php';
require_once '../includes/email.php';
require_once '../includes/email_broadcast.php';

requireAnyRole(['admin', 'super_admin']);
ensureEmailBroadcastTables();

$current_user = getCurrentUser();
$current_user_id = (int) ($current_user['id'] ?? 0);
$current_user_role = normalizeUserRole($current_user['role'] ?? 'admin');

$pageTitle = 'Email Broadcast Center';
$success_message = '';
$error_message = '';

$smtpEnabled = getSmtpSetting('smtp_enabled', 'false') === 'true';
$smtpHost = trim((string) getSmtpSetting('smtp_host', ''));
$smtpReady = $smtpEnabled && $smtpHost !== '';

/**
 * Parse a list of emails from free-form input.
 */
function parseEmailList($raw) {
    $emails = [];
    foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $email) {
        $email = trim($email);
        if ($email === '') {
            continue;
        }
        if (validateEmail($email)) {
            $emails[strtolower($email)] = $email;
        }
    }
    return array_values($emails);
}

/**
 * Retrieve recipients for the selected audience.
 */
function getEmailRecipients($audience) {
    global $db;

    if ($audience === 'custom') {
        return [];
    }

    $clauses = [
        'agents' => "role = 'agent'",
        'customers' => "role = 'customer'",
        'admins' => "role = 'admin'",
        'all' => "role IN ('admin','agent','customer')",
    ];

    $where = $clauses[$audience] ?? $clauses['all'];

    if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'is_active')) {
        $where .= " AND is_active = 1";
    }
    if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'status')) {
        $where .= " AND status = 'active'";
    }

    $recipients = [];

    try {
        $result = $db->query("SELECT id, full_name, role, email FROM users WHERE {$where}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $email = trim((string) ($row['email'] ?? ''));
                if ($email === '' || !validateEmail($email)) {
                    continue;
                }
                $key = strtolower($email);
                $recipients[$key] = [
                    'user_id' => (int) $row['id'],
                    'email' => $email,
                    'name' => $row['full_name'] ?? '',
                    'role' => $row['role'] ?? '',
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Email audience recipient query failed: ' . $e->getMessage());
    }

    return array_values($recipients);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'Invalid security token. Please refresh and try again.';
    } elseif (isset($_POST['delete_broadcast_id'])) {
        $delete_id = (int) $_POST['delete_broadcast_id'];
        if ($delete_id <= 0) {
            $error_message = 'Invalid broadcast selected.';
        } else {
            try {
                if ($current_user_role === 'super_admin') {
                    $stmt = $db->prepare("DELETE FROM email_broadcasts WHERE id = ?");
                    $stmt->bind_param('i', $delete_id);
                } else {
                    $stmt = $db->prepare("DELETE FROM email_broadcasts WHERE id = ? AND owner_id = ? AND owner_role = ?");
                    $stmt->bind_param('iis', $delete_id, $current_user_id, $current_user_role);
                }
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success_message = 'Email broadcast deleted successfully.';
                } else {
                    $error_message = 'Unable to delete broadcast (not found or not allowed).';
                }
            } catch (Exception $e) {
                error_log('Email broadcast delete failed: ' . $e->getMessage());
                $error_message = 'Delete failed. Please try again.';
            }
        }
    } elseif (isset($_POST['process_queue'])) {
        $result = processEmailBroadcastQueue(50);
        if (!empty($result['error'])) {
            $error_message = $result['error'];
        } else {
            $success_message = "Queue processed. {$result['sent']} sent, {$result['failed']} failed.";
        }
    } elseif (isset($_POST['test_broadcast'])) {
        $test_recipient = trim((string) ($_POST['test_recipient'] ?? ($current_user['email'] ?? '')));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $allowHtml = isset($_POST['allow_html']);

        if (!validateEmail($test_recipient)) {
            $error_message = 'Invalid test recipient email address.';
        } elseif ($subject === '') {
            $error_message = 'Subject is required for test email.';
        } elseif ($message === '') {
            $error_message = 'Message is required for test email.';
        } elseif (!$smtpReady) {
            $error_message = 'SMTP is not configured. Cannot send test email.';
        } else {
            // Process basic placeholders for the test
            $processedMessage = str_replace(
                ['{{name}}', '{{role}}', '{{site}}', '{{site_name}}'],
                ['Test User', 'admin', getSiteName(), getSiteName()],
                $message
            );
            
            $result = sendEmail($test_recipient, '[TEST] ' . $subject, $processedMessage, strip_tags($processedMessage), 'broadcast_test');
            if ($result) {
                $success_message = "Test email sent successfully to {$test_recipient}.";
            } else {
                $error_message = 'Failed to send test email. Check your SMTP settings.';
            }
        }
    } elseif (!$smtpReady) {
        $error_message = 'SMTP is not enabled or configured. Please update SMTP settings first.';
    } else {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $audience = $_POST['audience'] ?? 'all';
        $message = trim((string) ($_POST['message'] ?? ''));
        $customEmails = trim((string) ($_POST['custom_emails'] ?? ''));
        $allowHtml = isset($_POST['allow_html']);
        $sendMode = $_POST['send_mode'] ?? 'now';
        $scheduleAtRaw = trim((string) ($_POST['schedule_at'] ?? ''));
        $scheduledAt = null;

        $allowedAudiences = ['all', 'agents', 'customers', 'admins', 'custom'];
        if (!in_array($audience, $allowedAudiences, true)) {
            $audience = 'all';
        }

        if ($subject === '') {
            $error_message = 'Email subject is required.';
        } elseif ($message === '') {
            $error_message = 'Email message is required.';
        } elseif ($audience === 'custom' && $customEmails === '') {
            $error_message = 'Custom broadcasts require at least one email address.';
        } else {
        if ($sendMode === 'schedule' && $scheduleAtRaw !== '') {
            $candidate = strtotime($scheduleAtRaw);
            if ($candidate !== false) {
                $scheduledAt = date('Y-m-d H:i:s', $candidate);
            }
        }
        if ($scheduledAt && strtotime($scheduledAt) <= time()) {
            $scheduledAt = null;
            $sendMode = 'now';
        }

            $recipients = getEmailRecipients($audience);

            $manualRecipients = [];
            if ($customEmails !== '') {
                foreach (parseEmailList($customEmails) as $email) {
                    $manualRecipients[strtolower($email)] = [
                        'user_id' => null,
                        'email' => $email,
                        'name' => '',
                        'role' => 'manual',
                    ];
                }
            }

            foreach ($recipients as $entry) {
                $manualRecipients[strtolower($entry['email'])] = $entry;
            }

            $recipients = array_values($manualRecipients);

            if (empty($recipients)) {
                $error_message = 'No valid recipients were found for this broadcast.';
            } else {
                $totalRecipients = count($recipients);
                $status = $scheduledAt ? 'scheduled' : 'pending';
                $meta = [
                    'audience' => $audience,
                    'allow_html' => $allowHtml ? 1 : 0,
                    'manual_input' => $customEmails,
                ];

                try {
                    $ownerRole = $current_user_role !== '' ? $current_user_role : 'admin';
                    $stmt = $db->prepare("INSERT INTO email_broadcasts (owner_id, owner_role, subject, message, allow_html, target_audience, total_recipients, successful_recipients, failed_recipients, status, scheduled_at, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $metaJson = json_encode($meta);
                    $zero = 0;
                    $ownerId = $current_user_id;
                    $allowHtmlFlag = $allowHtml ? 1 : 0;
                    $scheduledAtValue = $scheduledAt;
                    $stmt->bind_param(
                        'isssisiiisss',
                        $ownerId,
                        $ownerRole,
                        $subject,
                        $message,
                        $allowHtmlFlag,
                        $audience,
                        $totalRecipients,
                        $zero,
                        $zero,
                        $status,
                        $scheduledAtValue,
                        $metaJson
                    );
                    $stmt->execute();
                    $broadcastId = $db->lastInsertId();
                } catch (Exception $e) {
                    error_log('Email broadcast history insert failed: ' . $e->getMessage());
                }

                if (!empty($broadcastId)) {
                    $job_stmt = $db->prepare("INSERT INTO email_broadcast_jobs (broadcast_id, user_id, recipient_email, recipient_name, recipient_role) VALUES (?, ?, ?, ?, ?)");
                    if ($job_stmt) {
                        foreach ($recipients as $recipient) {
                            $userId = $recipient['user_id'] ?? null;
                            $userId = $userId ? (int) $userId : null;
                            $email = $recipient['email'];
                            $name = $recipient['name'] ?? '';
                            $role = $recipient['role'] ?? '';
                            $job_stmt->bind_param('iisss', $broadcastId, $userId, $email, $name, $role);
                            $job_stmt->execute();
                        }
                    }
                }

                if ($scheduledAt) {
                    $success_message = "Broadcast scheduled for {$scheduledAt}. {$totalRecipients} recipient(s) queued.";
                } else {
                    $success_message = "Broadcast queued. {$totalRecipients} recipient(s) will be sent shortly.";
                }
            }
        }
    }
}

// Fetch recent broadcasts
$broadcastHistory = [];
try {
    if ($current_user_role === 'super_admin') {
        $stmt = $db->prepare("SELECT id, subject, target_audience, total_recipients, successful_recipients, failed_recipients, status, scheduled_at, created_at FROM email_broadcasts ORDER BY created_at DESC LIMIT 30");
    } else {
        $stmt = $db->prepare("SELECT id, subject, target_audience, total_recipients, successful_recipients, failed_recipients, status, scheduled_at, created_at FROM email_broadcasts WHERE owner_id = ? AND owner_role = ? ORDER BY created_at DESC LIMIT 15");
        $stmt->bind_param('is', $current_user_id, $current_user_role);
    }
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $broadcastHistory[] = $row;
        }
    }
} catch (Exception $e) {
    error_log('Email broadcast history fetch failed: ' . $e->getMessage());
}

$selectedAudience = $_POST['audience'] ?? 'all';
$allowedAudiences = ['all', 'agents', 'customers', 'admins', 'custom'];
if (!in_array($selectedAudience, $allowedAudiences, true)) {
    $selectedAudience = 'all';
}

$subjectValue = $_POST['subject'] ?? '';
$messageValue = $_POST['message'] ?? '';
$customEmailsValue = $_POST['custom_emails'] ?? '';
$allowHtmlChecked = !empty($_POST['allow_html']);
$sendModeValue = $_POST['send_mode'] ?? 'now';
$scheduleAtValue = $_POST['schedule_at'] ?? '';

$csrf_token = generateCSRF();
require_once '../includes/admin_header.php';
?>

<style>
    .email-broadcast-page .header-stack {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: flex-start;
        justify-content: space-between;
    }

    .email-broadcast-page .status-badges {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        align-items: flex-end;
    }

    .email-broadcast-page .card-header {
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .email-broadcast-page .compose-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        justify-content: space-between;
        align-items: center;
    }

    .email-broadcast-page .broadcast-table td,
    .email-broadcast-page .broadcast-table th {
        vertical-align: middle;
    }

    .email-broadcast-page {
        overflow-x: hidden;
    }

    .email-broadcast-page .container-fluid {
        max-width: 100%;
        overflow-x: hidden;
    }

    .email-broadcast-page .card {
        border-radius: 0.9rem;
        border: 1px solid var(--border-color, #e5e7eb);
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
    }

    .email-broadcast-page .card-header {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(16, 185, 129, 0.08));
        border-bottom: 1px solid var(--border-color, #e5e7eb);
    }

    [data-theme="dark"] .email-broadcast-page .card-header {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.18), rgba(16, 185, 129, 0.12));
    }

    .email-broadcast-page h1,
    .email-broadcast-page h5 {
        color: var(--text-primary, #111827);
    }

    .email-broadcast-page .text-muted {
        color: var(--text-muted, #6b7280) !important;
    }

    [data-theme="dark"] .email-broadcast-page .text-muted {
        color: #a0aec0 !important;
    }

    .email-broadcast-page .table {
        border-radius: 0.8rem;
        overflow: hidden;
        background: var(--bg-secondary, #ffffff);
    }

    .email-broadcast-page .table thead th {
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        color: var(--text-muted, #6b7280);
        background: rgba(15, 23, 42, 0.04);
    }

    [data-theme="dark"] .email-broadcast-page .table thead th {
        background: rgba(148, 163, 184, 0.12);
        color: #e2e8f0;
    }

    .email-broadcast-page .table tbody tr {
        transition: background 0.2s ease;
    }

    .email-broadcast-page .table tbody tr:hover {
        background: rgba(59, 130, 246, 0.06);
    }

    [data-theme="dark"] .email-broadcast-page .table {
        background: #0f172a;
        color: #e2e8f0;
    }

    [data-theme="dark"] .email-broadcast-page .table tbody td,
    [data-theme="dark"] .email-broadcast-page .table tbody th {
        color: #e2e8f0;
    }

    [data-theme="dark"] .email-broadcast-page .table tbody tr {
        border-color: rgba(148, 163, 184, 0.2);
    }

    [data-theme="dark"] .email-broadcast-page .table tbody tr:hover {
        background: rgba(59, 130, 246, 0.18);
    }

    [data-theme="dark"] .email-broadcast-page .table .text-success {
        color: #22c55e !important;
    }

    [data-theme="dark"] .email-broadcast-page .table .text-danger {
        color: #f87171 !important;
    }

    .rich-editor {
        margin-top: 0.75rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        background: #ffffff;
    }

    .editor-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        padding: 0.5rem;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
    }

    .editor-surface {
        min-height: 200px;
        padding: 0.75rem;
        outline: none;
        font-size: 0.95rem;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .email-broadcast-page .action-text {
        display: none;
        margin-left: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    @media (max-width: 767.98px) {
        html, body {
            overflow-x: hidden;
        }

        .dashboard-wrapper,
        .main-content,
        .dashboard-content {
            overflow-x: hidden;
        }

        .email-broadcast-page .container-fluid,
        .email-broadcast-page .card,
        .email-broadcast-page .card-body,
        .email-broadcast-page .table-responsive {
            max-width: 100%;
            overflow-x: hidden;
        }

        .email-broadcast-page .header-stack {
            flex-direction: column;
            align-items: flex-start;
        }

        .email-broadcast-page .status-badges {
            width: 100%;
            align-items: flex-start;
        }

        .email-broadcast-page .broadcast-table thead {
            display: none;
        }

        .email-broadcast-page .broadcast-table,
        .email-broadcast-page .broadcast-table tbody,
        .email-broadcast-page .broadcast-table tr,
        .email-broadcast-page .broadcast-table td {
            display: block;
            width: 100%;
        }

        .email-broadcast-page .broadcast-table tr {
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96));
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 0.85rem 0.95rem;
            margin-bottom: 1rem;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        [data-theme="dark"] .email-broadcast-page .broadcast-table tr {
            background: linear-gradient(180deg, rgba(15,23,42,0.98), rgba(2,6,23,0.98));
            border-color: rgba(148, 163, 184, 0.25);
        }

        .email-broadcast-page .broadcast-table td {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            align-items: flex-start;
            padding: 0.6rem 0;
            border: 0;
            font-size: 0.85rem;
            word-break: break-word;
            overflow-wrap: anywhere;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            text-align: left;
            white-space: normal;
        }

        .email-broadcast-page .broadcast-table td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #6b7280;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            text-align: left;
        }

        [data-theme="dark"] .email-broadcast-page .broadcast-table td::before {
            color: #cbd5f5;
        }

        .email-broadcast-page .broadcast-table td:last-child {
            border-bottom: 0;
        }

        .email-broadcast-page .broadcast-table td[data-label="Subject"] {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary, #111827);
        }

        [data-theme="dark"] .email-broadcast-page .broadcast-table td[data-label="Subject"] {
            color: #f1f5f9;
        }

        .email-broadcast-page .broadcast-table td[data-label="Status"] .badge {
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            align-self: flex-start;
        }

        .email-broadcast-page .broadcast-table td[data-label="Total"],
        .email-broadcast-page .broadcast-table td[data-label="Sent"],
        .email-broadcast-page .broadcast-table td[data-label="Failed"] {
            display: inline-flex;
            flex-direction: row;
            align-items: flex-start;
            gap: 0.5rem;
            justify-content: flex-start;
            text-align: left;
        }

        .email-broadcast-page .broadcast-table td[data-label="Total"]::before,
        .email-broadcast-page .broadcast-table td[data-label="Sent"]::before,
        .email-broadcast-page .broadcast-table td[data-label="Failed"]::before {
            margin-bottom: 0;
        }

        .email-broadcast-page .broadcast-table td[data-label="Actions"] {
            align-items: flex-start;
        }

        .email-broadcast-page .broadcast-table td[data-label="Actions"] form {
            width: 100%;
            display: flex;
            justify-content: flex-start;
        }

        .email-broadcast-page .broadcast-table td[data-label="Actions"] .btn {
            width: auto;
            min-width: 160px;
            max-width: 90%;
            justify-content: center;
            gap: 0.4rem;
        }

        .email-broadcast-page .action-text {
            display: inline-block;
        }
    }
</style>

<div class="email-broadcast-page">
<div class="container-fluid">
    <div class="header-stack mb-4">
        <div>
            <h1 class="h3 mb-1">Email Broadcasts</h1>
            <p class="text-muted mb-0">Send email updates to customers, agents, admins or everyone.</p>
        </div>
        <div class="status-badges text-end">
            <span class="badge bg-<?php echo $smtpReady ? 'success' : 'danger'; ?>">
                <?php echo $smtpReady ? 'SMTP Ready' : 'SMTP Not Ready'; ?>
            </span>
            <?php if (!$smtpReady): ?>
                <div class="small text-muted">Configure SMTP in Settings → SMTP Email Settings.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="process_queue" value="1">
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-cogs me-1"></i> Process Queue Now
            </button>
        </form>
        <span class="text-muted small">Use this if you don't have a cron job set up.</span>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Compose Broadcast</h5>
                <small class="text-muted">Use placeholders like <code>{{name}}</code>, <code>{{role}}</code>, <code>{{site}}</code>.</small>
            </div>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="subject">Email Subject</label>
                        <input type="text" class="form-control" id="subject" name="subject" placeholder="Subject line" value="<?php echo htmlspecialchars($subjectValue); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="audience">Target Audience</label>
                        <select class="form-select" id="audience" name="audience">
                            <option value="all" <?php echo $selectedAudience === 'all' ? 'selected' : ''; ?>>All Users</option>
                            <option value="customers" <?php echo $selectedAudience === 'customers' ? 'selected' : ''; ?>>Customers</option>
                            <option value="agents" <?php echo $selectedAudience === 'agents' ? 'selected' : ''; ?>>Agents</option>
                            <option value="admins" <?php echo $selectedAudience === 'admins' ? 'selected' : ''; ?>>Admins</option>
                            <option value="custom" <?php echo $selectedAudience === 'custom' ? 'selected' : ''; ?>>Custom Emails</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="message">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="7" placeholder="Write your email message..." required><?php echo htmlspecialchars($messageValue); ?></textarea>
                    <div class="rich-editor d-none" id="richEditorWrapper">
                        <div class="editor-toolbar">
                            <button type="button" class="btn btn-sm btn-light" data-cmd="bold"><strong>B</strong></button>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="italic"><em>I</em></button>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="underline"><u>U</u></button>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="insertUnorderedList">&bull; List</button>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="insertOrderedList">1. List</button>
                            <button type="button" class="btn btn-sm btn-light" data-link="1">Link</button>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="removeFormat">Clear</button>
                        </div>
                        <div id="richEditor" class="editor-surface" contenteditable="true"></div>
                        <small class="text-muted">HTML is allowed. Make sure to include placeholders like {{name}} if needed.</small>
                    </div>
                </div>

                <div class="row g-3 align-items-center mt-2">
                    <div class="col-lg-8">
                        <label class="form-label" for="custom_emails">Custom Email List (optional)</label>
                        <textarea class="form-control" id="custom_emails" name="custom_emails" rows="2" placeholder="Enter emails separated by comma or space."><?php echo htmlspecialchars($customEmailsValue); ?></textarea>
                        <small class="text-muted">Custom emails are merged with the audience selection.</small>
                    </div>
                    <div class="col-lg-4">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="allow_html" name="allow_html" <?php echo $allowHtmlChecked ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="allow_html">Treat message as HTML</label>
                        </div>
                    </div>
                </div>

                <div class="row g-3 align-items-center mt-2">
                    <div class="col-lg-6">
                        <label class="form-label" for="send_mode">Delivery</label>
                        <select class="form-select" id="send_mode" name="send_mode">
                            <option value="now" <?php echo $sendModeValue === 'now' ? 'selected' : ''; ?>>Send Now (Queue)</option>
                            <option value="schedule" <?php echo $sendModeValue === 'schedule' ? 'selected' : ''; ?>>Schedule for Later</option>
                        </select>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="schedule_at">Schedule Date & Time</label>
                        <input type="datetime-local" class="form-control" id="schedule_at" name="schedule_at" value="<?php echo htmlspecialchars($scheduleAtValue); ?>">
                        <small class="text-muted">Leave empty to send immediately.</small>
                    </div>
                </div>

                <div class="compose-actions mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Send Broadcast
                        </button>
                    </div>
                    
                    <div class="test-email-box d-flex align-items-center gap-2 p-2 border rounded bg-light">
                        <div class="input-group input-group-sm" style="max-width: 300px;">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" name="test_recipient" class="form-control" placeholder="Test email address" value="<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>">
                        </div>
                        <button type="submit" name="test_broadcast" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-flask me-1"></i> Send Test
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Recent Broadcasts</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped broadcast-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject</th>
                            <th>Audience</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Sent</th>
                            <th class="text-center">Failed</th>
                            <th>Status</th>
                            <th>Scheduled</th>
                            <th>Sent At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($broadcastHistory)): ?>
                            <tr><td colspan="10" class="text-center text-muted">No broadcasts yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($broadcastHistory as $broadcast): ?>
                                <?php
                                    $statusClass = 'secondary';
                                    if ($broadcast['status'] === 'completed') {
                                        $statusClass = 'success';
                                    } elseif ($broadcast['status'] === 'failed') {
                                        $statusClass = 'danger';
                                    } elseif ($broadcast['status'] === 'partial') {
                                        $statusClass = 'warning';
                                    }
                                ?>
                                <tr>
                                    <td data-label="ID"><?php echo (int) $broadcast['id']; ?></td>
                                    <td data-label="Subject"><?php echo htmlspecialchars($broadcast['subject']); ?></td>
                                    <td data-label="Audience"><?php echo htmlspecialchars(ucfirst($broadcast['target_audience'])); ?></td>
                                    <td data-label="Total" class="text-center"><?php echo (int) $broadcast['total_recipients']; ?></td>
                                    <td data-label="Sent" class="text-center text-success"><?php echo (int) $broadcast['successful_recipients']; ?></td>
                                    <td data-label="Failed" class="text-center text-danger"><?php echo (int) $broadcast['failed_recipients']; ?></td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($broadcast['status']); ?></span>
                                    </td>
                                    <td data-label="Scheduled">
                                        <?php echo $broadcast['scheduled_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($broadcast['scheduled_at']))) : 'Immediate'; ?>
                                    </td>
                                    <td data-label="Sent At"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($broadcast['created_at']))); ?></td>
                                    <td data-label="Actions">
                                        <form method="post" onsubmit="return confirm('Delete this broadcast and its queued emails?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="delete_broadcast_id" value="<?php echo (int) $broadcast['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                                <span class="action-text">Delete</span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    (function () {
        const allowHtml = document.getElementById('allow_html');
        const textarea = document.getElementById('message');
        const richWrapper = document.getElementById('richEditorWrapper');
        const editor = document.getElementById('richEditor');
        const toolbar = document.querySelector('.editor-toolbar');
        const sendMode = document.getElementById('send_mode');
        const scheduleInput = document.getElementById('schedule_at');

        const syncToEditor = () => {
            editor.innerHTML = textarea.value;
        };

        const syncToTextarea = () => {
            textarea.value = editor.innerHTML;
        };

        const toggleEditor = () => {
            if (allowHtml.checked) {
                richWrapper.classList.remove('d-none');
                textarea.classList.add('d-none');
                syncToEditor();
            } else {
                richWrapper.classList.add('d-none');
                textarea.classList.remove('d-none');
                syncToTextarea();
            }
        };

        const toggleSchedule = () => {
            scheduleInput.disabled = sendMode.value !== 'schedule';
        };

        if (toolbar) {
            toolbar.addEventListener('click', function (event) {
                const btn = event.target.closest('button');
                if (!btn) {
                    return;
                }
                const cmd = btn.getAttribute('data-cmd');
                if (cmd) {
                    document.execCommand(cmd, false, null);
                    syncToTextarea();
                    return;
                }
                if (btn.getAttribute('data-link')) {
                    const url = prompt('Enter link URL:');
                    if (url) {
                        document.execCommand('createLink', false, url);
                        syncToTextarea();
                    }
                }
            });
        }

        if (editor) {
            editor.addEventListener('input', syncToTextarea);
        }

        if (allowHtml) {
            allowHtml.addEventListener('change', toggleEditor);
            toggleEditor();
        }

        if (sendMode) {
            sendMode.addEventListener('change', toggleSchedule);
            toggleSchedule();
        }
    })();
</script>

<?php require_once '../includes/admin_footer.php'; ?>
