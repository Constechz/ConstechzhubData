<?php
require_once '../config/config.php';
require_once '../includes/mnotify_sms.php';

requireRole('admin');

ensureSmsSupportTables();

$current_user = getCurrentUser();
$current_user_id = (int) ($current_user['id'] ?? 0);
$current_user_role = normalizeUserRole($current_user['role'] ?? 'admin');

$pageTitle = 'SMS Broadcast Center';
$success_message = '';
$error_message = '';

$smsEnabled = isSMSFeatureEnabled();
$balanceInfo = null;

if ($smsEnabled) {
    try {
        $balanceData = getSMSBalance();
        if (!empty($balanceData['success'])) {
            $balanceInfo = $balanceData;
        }
    } catch (Exception $e) {
        $balanceInfo = null;
    }
}

/**
 * Retrieve system users for the selected audience.
 */
function getAudienceRecipients($audience) {
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
    $recipients = [];

    try {
        $result = $db->query("SELECT id, full_name, role, phone FROM users WHERE {$where}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (empty($row['phone'])) {
                    continue;
                }

                $normalized = formatPhone($row['phone']);
                if (!$normalized || !preg_match('/^233[0-9]{9}$/', $normalized)) {
                    continue;
                }

                $recipients[$normalized] = [
                    'user_id' => (int) $row['id'],
                    'phone' => $normalized,
                    'name' => $row['full_name'],
                    'role' => $row['role'],
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Audience recipient query failed: ' . $e->getMessage());
    }

    return array_values($recipients);
}

/**
 * Apply dynamic placeholders to the SMS template.
 */
function buildBroadcastMessage($template, array $recipient) {
    $replacements = [
        '{{name}}' => !empty($recipient['name']) ? $recipient['name'] : 'Valued User',
        '{{role}}' => !empty($recipient['role']) ? ucfirst($recipient['role']) : 'User',
        '{{site}}' => SITE_NAME,
    ];

    return strtr($template, $replacements);
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
                    $stmt = $db->prepare("DELETE FROM sms_broadcasts WHERE id = ?");
                    $stmt->bind_param('i', $delete_id);
                } else {
                    $stmt = $db->prepare("DELETE FROM sms_broadcasts WHERE id = ? AND owner_id = ? AND owner_role = ?");
                    $stmt->bind_param('iis', $delete_id, $current_user_id, $current_user_role);
                }
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success_message = 'SMS broadcast deleted successfully.';
                } else {
                    $error_message = 'Unable to delete broadcast (not found or not allowed).';
                }
            } catch (Exception $e) {
                error_log('SMS broadcast delete failed: ' . $e->getMessage());
                $error_message = 'Delete failed. Please try again.';
            }
        }
    } elseif (!$smsEnabled) {
        $error_message = 'SMS is not enabled. Please configure your SMS provider first.';
    } else {
        $title = trim($_POST['broadcast_title'] ?? '');
        $audience = $_POST['audience'] ?? 'all';
        $message = trim($_POST['message'] ?? '');
        $customNumbers = trim($_POST['custom_numbers'] ?? '');

        $allowedAudiences = ['all', 'agents', 'customers', 'admins', 'custom'];
        if (!in_array($audience, $allowedAudiences, true)) {
            $audience = 'all';
        }

        if ($message === '') {
            $error_message = 'Message body is required.';
        } elseif ($audience === 'custom' && $customNumbers === '') {
            $error_message = 'Custom broadcasts require at least one phone number.';
        } else {
            $recipients = getAudienceRecipients($audience);

            $manualRecipients = [];
            if ($customNumbers !== '') {
                foreach (parseSmsPhoneList($customNumbers) as $phone) {
                    $manualRecipients[$phone] = [
                        'user_id' => null,
                        'phone' => $phone,
                        'name' => '',
                        'role' => 'manual',
                    ];
                }
            }

            foreach ($recipients as $entry) {
                $manualRecipients[$entry['phone']] = $entry;
            }

            $recipients = array_values($manualRecipients);

            if (empty($recipients)) {
                $error_message = 'No valid recipients were found for this broadcast.';
            } else {
                $sms = new MnotifySmsService();
                $successCount = 0;
                $failedNumbers = [];

                foreach ($recipients as $recipient) {
                    $personalized = buildBroadcastMessage($message, $recipient);
                    try {
                        $response = $sms->sendSMS(
                            $recipient['phone'],
                            $personalized,
                            'admin_broadcast',
                            $recipient['user_id']
                        );

                        if (!empty($response['success'])) {
                            $successCount++;
                        } else {
                            $failedNumbers[] = $recipient['phone'];
                        }
                    } catch (Exception $ex) {
                        $failedNumbers[] = $recipient['phone'];
                    }
                }

                $totalRecipients = count($recipients);
                $failedCount = count($failedNumbers);
                $status = 'completed';

                if ($failedCount === $totalRecipients) {
                    $status = 'failed';
                } elseif ($failedCount > 0) {
                    $status = 'partial';
                }

                $meta = [
                    'audience' => $audience,
                    'manual_input' => $customNumbers,
                    'failed_numbers' => $failedNumbers,
                ];

                try {
                    $stmt = $db->prepare("INSERT INTO sms_broadcasts (owner_id, owner_role, title, message, target_audience, total_recipients, successful_recipients, failed_recipients, status, meta_json) VALUES (?, 'admin', ?, ?, ?, ?, ?, ?, ?, ?)");
                    $titleValue = $title !== '' ? $title : 'Admin Broadcast';
                    $metaJson = json_encode($meta);
                    $stmt->bind_param(
                        'isssiiiss',
                        $_SESSION['user_id'],
                        $titleValue,
                        $message,
                        $audience,
                        $totalRecipients,
                        $successCount,
                        $failedCount,
                        $status,
                        $metaJson
                    );
                    $stmt->execute();
                } catch (Exception $e) {
                    error_log('Broadcast log failed: ' . $e->getMessage());
                }

                if ($failedCount > 0 && $successCount > 0) {
                    $success_message = "Broadcast completed with {$successCount} successes and {$failedCount} failures.";
                } elseif ($successCount === 0) {
                    $error_message = 'Unable to send SMS broadcast. Please check the SMS provider logs.';
                } else {
                    $success_message = "Broadcast sent to {$successCount} recipient(s).";
                }
            }
        }
    }
}

// Fetch recent broadcasts
$broadcastHistory = [];
try {
    if ($current_user_role === 'super_admin') {
        $stmt = $db->prepare("SELECT id, title, target_audience, total_recipients, successful_recipients, failed_recipients, status, created_at FROM sms_broadcasts ORDER BY created_at DESC LIMIT 30");
    } else {
        $stmt = $db->prepare("SELECT id, title, target_audience, total_recipients, successful_recipients, failed_recipients, status, created_at FROM sms_broadcasts WHERE owner_id = ? AND owner_role = ? ORDER BY created_at DESC LIMIT 15");
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
    error_log('Broadcast history fetch failed: ' . $e->getMessage());
}

$selectedAudience = $_POST['audience'] ?? 'all';
$allowedAudiences = ['all', 'agents', 'customers', 'admins', 'custom'];
if (!in_array($selectedAudience, $allowedAudiences, true)) {
    $selectedAudience = 'all';
}

$titleValue = $_POST['broadcast_title'] ?? '';
$messageValue = $_POST['message'] ?? '';
$customNumbersValue = $_POST['custom_numbers'] ?? '';

$csrf_token = generateCSRF();
require_once '../includes/admin_header.php';
?>

<style>
    .sms-broadcast-page {
        overflow-x: hidden;
    }

    .sms-broadcast-page .container-fluid {
        max-width: 100%;
        overflow-x: hidden;
    }

    .sms-broadcast-page .card,
    .sms-broadcast-page .card-body {
        min-width: 0;
    }

    .sms-broadcast-page .form-control,
    .sms-broadcast-page .form-select,
    .sms-broadcast-page textarea {
        max-width: 100%;
    }

    .sms-broadcast-page code {
        white-space: normal;
        word-break: break-word;
    }

    .sms-broadcast-page .header-stack {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: flex-start;
        justify-content: space-between;
    }

    .sms-broadcast-page .status-badges {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        align-items: flex-end;
    }

    .sms-broadcast-page .card-header {
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .sms-broadcast-page .compose-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        justify-content: space-between;
        align-items: center;
    }

    .sms-broadcast-page .table-responsive {
        overflow-x: auto;
    }

    @media (max-width: 991.98px) {
        .sms-broadcast-page .status-badges {
            align-items: flex-start;
        }
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

        .sms-broadcast-page .row {
            margin-left: 0;
            margin-right: 0;
        }

        .sms-broadcast-page .table-responsive {
            overflow-x: hidden;
        }

        .sms-broadcast-page .header-stack {
            flex-direction: column;
            align-items: flex-start;
        }

        .sms-broadcast-page .status-badges {
            width: 100%;
            align-items: flex-start;
        }

        .sms-broadcast-page .card-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .sms-broadcast-page .compose-actions {
            flex-direction: column;
            align-items: flex-start;
        }

        .sms-broadcast-page .compose-actions .btn {
            width: 100%;
        }

        .sms-broadcast-page .table-responsive {
            overflow-x: visible;
        }

        .sms-broadcast-page .broadcast-table thead {
            display: none;
        }

        .sms-broadcast-page .broadcast-table,
        .sms-broadcast-page .broadcast-table tbody,
        .sms-broadcast-page .broadcast-table tr,
        .sms-broadcast-page .broadcast-table td {
            display: block;
            width: 100%;
        }

        .sms-broadcast-page .broadcast-table tr {
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            border-radius: 0.9rem;
            padding: 0.75rem 0.9rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 18px rgba(46, 41, 78, 0.06);
        }

        .sms-broadcast-page .broadcast-table td {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 0.25rem;
            padding: 0.4rem 0;
            border: 0;
            font-size: 0.85rem;
            word-break: break-word;
            overflow-wrap: anywhere;
            text-align: left;
        }

        .sms-broadcast-page .broadcast-table td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #541388;
            flex: 0 0 auto;
            font-size: 0.8rem;
        }

        .sms-broadcast-page .broadcast-table tr {
            padding: 0.6rem 0.75rem;
        }

        .sms-broadcast-page .broadcast-table td[data-label="Title"] {
            font-weight: 600;
        }

    .sms-broadcast-page .broadcast-table td[data-label="Sent At"] {
        font-size: 0.8rem;
        color: #541388;
    }

    .sms-broadcast-page .broadcast-table td:last-child {
        padding-bottom: 0;
    }

    .sms-broadcast-page .broadcast-table .text-center {
        text-align: left !important;
    }

    .sms-broadcast-page .action-text {
        display: none;
        margin-left: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .sms-broadcast-page .broadcast-table td[data-label="Actions"] form {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .sms-broadcast-page .broadcast-table td[data-label="Actions"] .btn {
        width: auto;
        min-width: 160px;
        max-width: 90%;
        justify-content: center;
        gap: 0.4rem;
    }

    .sms-broadcast-page .action-text {
        display: inline-block;
    }
}
</style>

<div class="sms-broadcast-page">
<div class="container-fluid">
    <div class="header-stack mb-4">
        <div>
            <h1 class="h3 mb-1">SMS Broadcasts</h1>
            <p class="text-muted mb-0">Send targeted SMS alerts to admins, agents, customers or everyone.</p>
        </div>
        <div class="status-badges text-end">
            <span class="badge bg-<?php echo $smsEnabled ? 'success' : 'danger'; ?> me-2">
                <?php echo $smsEnabled ? 'SMS Enabled' : 'SMS Disabled'; ?>
            </span>
            <?php if ($balanceInfo): ?>
                <div class="small text-muted">Balance: <?php echo htmlspecialchars($balanceInfo['balance'] ?? $balanceInfo['units'] ?? 'n/a'); ?> units</div>
            <?php endif; ?>
        </div>
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
                <small class="text-muted">Use placeholders like <code>{{name}}</code>, <code>{{role}}</code> or <code>{{site}}</code>.</small>
            </div>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="mb-3">
                    <label class="form-label" for="broadcast_title">Campaign Title</label>
                    <input type="text" class="form-control" id="broadcast_title" name="broadcast_title" placeholder="Optional title e.g. Weekend Promo" value="<?php echo htmlspecialchars($titleValue); ?>">
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="audience">Target Audience</label>
                        <select class="form-select" id="audience" name="audience">
                            <option value="all" <?php echo $selectedAudience === 'all' ? 'selected' : ''; ?>>All Users</option>
                            <option value="agents" <?php echo $selectedAudience === 'agents' ? 'selected' : ''; ?>>Agents Only</option>
                            <option value="customers" <?php echo $selectedAudience === 'customers' ? 'selected' : ''; ?>>Customers Only</option>
                            <option value="admins" <?php echo $selectedAudience === 'admins' ? 'selected' : ''; ?>>Admins Only</option>
                            <option value="custom" <?php echo $selectedAudience === 'custom' ? 'selected' : ''; ?>>Custom Numbers</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="custom_numbers">Additional Numbers (comma, space or line separated)</label>
                        <textarea class="form-control" id="custom_numbers" name="custom_numbers" rows="2" placeholder="233XXXXXXXXX, 054XXXXXXX"><?php echo htmlspecialchars($customNumbersValue); ?></textarea>
                        <small class="text-muted">These numbers are always appended. Required when using Custom audience.</small>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="message">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="4" placeholder="Hi {{name}}, ..."><?php echo htmlspecialchars($messageValue); ?></textarea>
                    <small class="text-muted">Personalise messages using {{name}}, {{role}} and {{site}} placeholders.</small>
                </div>

                <div class="mt-4 compose-actions">
                    <div class="text-muted small">Large broadcasts may take a few minutes to finish sending.</div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Send Broadcast
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Broadcasts</h5>
            <small class="text-muted">Showing latest 15 entries.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 broadcast-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Audience</th>
                        <th>Total</th>
                        <th>Delivered</th>
                        <th>Failed</th>
                        <th>Status</th>
                        <th>Sent At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($broadcastHistory)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No broadcasts yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($broadcastHistory as $broadcast): ?>
                            <tr>
                                <td data-label="Title"><?php echo htmlspecialchars($broadcast['title']); ?></td>
                                <td data-label="Audience" class="text-capitalize"><?php echo htmlspecialchars($broadcast['target_audience']); ?></td>
                                <td data-label="Total"><?php echo (int) $broadcast['total_recipients']; ?></td>
                                <td data-label="Delivered"><?php echo (int) $broadcast['successful_recipients']; ?></td>
                                <td data-label="Failed"><?php echo (int) $broadcast['failed_recipients']; ?></td>
                                <td data-label="Status">
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
                                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($broadcast['status']); ?></span>
                                </td>
                                <td data-label="Sent At"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($broadcast['created_at']))); ?></td>
                                <td data-label="Actions">
                                    <form method="post" onsubmit="return confirm('Delete this SMS broadcast?');">
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

<?php require_once '../includes/admin_footer.php'; ?>
