<?php
require_once '../config/config.php';
require_once '../includes/email.php';
require_once '../includes/mnotify_sms.php';

requireAnyRole(['admin', 'super_admin']);
ensureGuestCheckoutSchema();
ensureResultCheckerTables();
ensureProductOrderTables();
ensureSmsSupportTables();

$pageTitle = 'Guest Notifications';
$current_user = getCurrentUser();
$success_message = '';
$error_message = '';

function admin_guest_notice_clean_email($email) {
    $email = strtolower(trim((string) $email));
    return $email !== '' && validateEmail($email) ? $email : '';
}

function admin_guest_notice_clean_phone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }
    $formatted = formatPhone($phone);
    return $formatted !== '' && validatePhone($formatted) ? $formatted : '';
}

function admin_guest_notice_apply_placeholders($message, array $recipient) {
    $name = trim((string) ($recipient['name'] ?? ''));
    if ($name === '') {
        $name = 'Guest Customer';
    }

    return strtr((string) $message, [
        '{{name}}' => $name,
        '{{email}}' => (string) ($recipient['email'] ?? ''),
        '{{phone}}' => (string) ($recipient['phone'] ?? ''),
        '{{store}}' => (string) ($recipient['store_name'] ?? ($recipient['store_slug'] ?? '')),
        '{{site}}' => SITE_NAME,
    ]);
}

function admin_guest_notice_is_local_media_path($path) {
    $path = trim((string) $path);
    return $path !== '' && stripos($path, 'uploads/notifications/') === 0;
}

function admin_guest_notice_delete_media_file($path) {
    if (!admin_guest_notice_is_local_media_path($path)) {
        return;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    if (!$projectRoot) {
        return;
    }

    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'notifications');
    if (!$uploadsRoot) {
        return;
    }

    $relative = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
    $candidate = $projectRoot . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
    $absolute = realpath($candidate);
    if (!$absolute || strpos($absolute, $uploadsRoot) !== 0) {
        return;
    }

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function admin_guest_notice_add_recipient(array &$recipients, array $entry) {
    $email = admin_guest_notice_clean_email($entry['email'] ?? '');
    $phone = admin_guest_notice_clean_phone($entry['phone'] ?? '');
    if ($email === '' && $phone === '') {
        return;
    }

    $key = $email !== '' ? ('email:' . $email) : ('phone:' . $phone);
    if (!isset($recipients[$key])) {
        $recipients[$key] = [
            'name' => trim((string) ($entry['name'] ?? 'Guest Customer')),
            'email' => $email,
            'phone' => $phone,
            'store_slug' => trim((string) ($entry['store_slug'] ?? '')),
            'store_name' => trim((string) ($entry['store_name'] ?? '')),
            'source' => trim((string) ($entry['source'] ?? 'guest')),
        ];
        return;
    }

    if ($recipients[$key]['phone'] === '' && $phone !== '') {
        $recipients[$key]['phone'] = $phone;
    }
    if ($recipients[$key]['email'] === '' && $email !== '') {
        $recipients[$key]['email'] = $email;
    }
}

function admin_guest_notice_fetch_recipients($audience, $store_slug = '') {
    global $db;

    $audience = trim((string) $audience);
    $store_slug = trim((string) $store_slug);
    $recipients = [];

    $storeNames = [];
    try {
        $storeResult = $db->query("SELECT store_slug, store_name FROM agent_stores");
        if ($storeResult) {
            while ($store = $storeResult->fetch_assoc()) {
                $storeNames[(string) ($store['store_slug'] ?? '')] = (string) ($store['store_name'] ?? '');
            }
        }
    } catch (Throwable $e) {
        error_log('Guest notification store lookup failed: ' . $e->getMessage());
    }

    if (in_array($audience, ['all_guests', 'guest_data'], true) && dbh_table_exists('transactions') && dbh_table_has_column('transactions', 'metadata')) {
        try {
            $stmt = $db->prepare("
                SELECT metadata
                FROM transactions
                WHERE transaction_type = 'purchase'
                  AND metadata IS NOT NULL
                ORDER BY id DESC
                LIMIT 2000
            ");
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
                    if (!is_array($metadata) || ($metadata['type'] ?? '') !== 'guest_bundle_purchase') {
                        continue;
                    }
                    $metaStore = trim((string) ($metadata['store_slug'] ?? ''));
                    if ($store_slug !== '' && $metaStore !== $store_slug) {
                        continue;
                    }
                    admin_guest_notice_add_recipient($recipients, [
                        'name' => $metadata['buyer_name'] ?? 'Guest Customer',
                        'email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                        'phone' => $metadata['beneficiary_number'] ?? '',
                        'store_slug' => $metaStore,
                        'store_name' => $storeNames[$metaStore] ?? $metaStore,
                        'source' => 'guest_data',
                    ]);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Guest notification data recipients failed: ' . $e->getMessage());
        }
    }

    if (in_array($audience, ['all_guests', 'guest_products'], true) && dbh_table_exists('product_orders')) {
        try {
            $sql = "
                SELECT customer_name, customer_email, customer_phone, store_slug
                FROM product_orders
                WHERE (user_id IS NULL OR user_id = 0)
            ";
            if ($store_slug !== '') {
                $sql .= " AND store_slug = ?";
                $stmt = $db->prepare($sql . " ORDER BY id DESC LIMIT 2000");
                $stmt->bind_param('s', $store_slug);
            } else {
                $stmt = $db->prepare($sql . " ORDER BY id DESC LIMIT 2000");
            }
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $rowStore = trim((string) ($row['store_slug'] ?? ''));
                    admin_guest_notice_add_recipient($recipients, [
                        'name' => $row['customer_name'] ?? 'Guest Customer',
                        'email' => $row['customer_email'] ?? '',
                        'phone' => $row['customer_phone'] ?? '',
                        'store_slug' => $rowStore,
                        'store_name' => $storeNames[$rowStore] ?? $rowStore,
                        'source' => 'guest_products',
                    ]);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Guest notification product recipients failed: ' . $e->getMessage());
        }
    }

    if (in_array($audience, ['all_guests', 'guest_checkers'], true) && dbh_table_exists('result_checker_purchases')) {
        try {
            $stmt = $db->prepare("
                SELECT rcp.sms_phone, rcp.notification_email, rcp.reference, t.metadata, u.full_name, u.email, u.phone
                FROM result_checker_purchases rcp
                LEFT JOIN users u ON u.id = rcp.user_id
                LEFT JOIN transactions t ON t.reference = rcp.reference
                WHERE rcp.notification_email IS NOT NULL OR rcp.sms_phone IS NOT NULL
                ORDER BY rcp.id DESC
                LIMIT 2000
            ");
            if ($stmt && $stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }
                    $rowStore = trim((string) ($metadata['store_slug'] ?? ''));
                    if ($store_slug !== '' && $rowStore !== $store_slug) {
                        continue;
                    }
                    admin_guest_notice_add_recipient($recipients, [
                        'name' => $metadata['buyer_name'] ?? ($row['full_name'] ?? 'Guest Customer'),
                        'email' => $row['notification_email'] ?? ($row['email'] ?? ''),
                        'phone' => $row['sms_phone'] ?? ($row['phone'] ?? ''),
                        'store_slug' => $rowStore,
                        'store_name' => $storeNames[$rowStore] ?? $rowStore,
                        'source' => 'guest_checkers',
                    ]);
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Guest notification checker recipients failed: ' . $e->getMessage());
        }
    }

    return array_values($recipients);
}

function admin_guest_notice_count_display_messages() {
    global $db;

    if (!dbh_table_exists('notifications')) {
        return 0;
    }

    try {
        $result = $db->query("SELECT COUNT(*) AS total FROM notifications WHERE target_audience = 'guests'");
        if ($result) {
            $row = $result->fetch_assoc();
            return (int) ($row['total'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('Guest notification display count failed: ' . $e->getMessage());
    }

    return 0;
}

function admin_guest_notice_delete_display_messages() {
    global $db;

    if (!dbh_table_exists('notifications')) {
        return 0;
    }

    $deleted = 0;
    $mediaPaths = [];

    try {
        $hasImagePath = function_exists('dbh_table_has_column') && dbh_table_has_column('notifications', 'image_path');
        if ($hasImagePath) {
            $mediaResult = $db->query("SELECT image_path FROM notifications WHERE target_audience = 'guests' AND image_path IS NOT NULL AND image_path <> ''");
            if ($mediaResult) {
                while ($row = $mediaResult->fetch_assoc()) {
                    $mediaPaths[] = (string) ($row['image_path'] ?? '');
                }
            }
        }

        $stmt = $db->prepare("DELETE FROM notifications WHERE target_audience = 'guests'");
        if ($stmt && $stmt->execute()) {
            $deleted = (int) $stmt->affected_rows;
            foreach ($mediaPaths as $mediaPath) {
                admin_guest_notice_delete_media_file($mediaPath);
            }
        }
        if ($stmt) {
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Guest notification display delete failed: ' . $e->getMessage());
        throw $e;
    }

    return $deleted;
}

$stores = [];
try {
    $storeResult = $db->query("
        SELECT store_slug, store_name
        FROM agent_stores
        WHERE is_active = 1
        ORDER BY store_name ASC
    ");
    if ($storeResult) {
        while ($store = $storeResult->fetch_assoc()) {
            $stores[] = $store;
        }
    }
} catch (Throwable $e) {
    error_log('Guest notification active stores failed: ' . $e->getMessage());
}

$postAction = $_POST['action'] ?? 'send';
$selectedAudience = $_POST['audience'] ?? 'all_guests';
$selectedStore = sanitize($_POST['store_slug'] ?? '');
$selectedChannel = $_POST['channel'] ?? 'email';
$subjectValue = trim((string) ($_POST['subject'] ?? ''));
$messageValue = trim((string) ($_POST['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRF($_POST['csrf_token'])) {
        $error_message = 'Invalid security token. Please refresh and try again.';
    } elseif ($postAction === 'delete_guest_display_notifications') {
        try {
            $deletedCount = admin_guest_notice_delete_display_messages();
            $success_message = $deletedCount > 0
                ? 'Deleted ' . $deletedCount . ' guest display notification message(s).'
                : 'No guest display notification messages were found to delete.';
            $subjectValue = '';
            $messageValue = '';
        } catch (Throwable $e) {
            $error_message = 'Could not delete guest display notifications. Please try again.';
        }
    } else {
        $allowedAudiences = ['all_guests', 'guest_data', 'guest_products', 'guest_checkers'];
        if (!in_array($selectedAudience, $allowedAudiences, true)) {
            $selectedAudience = 'all_guests';
        }
        if (!in_array($selectedChannel, ['email', 'sms', 'both'], true)) {
            $selectedChannel = 'email';
        }

        if ($messageValue === '') {
            $error_message = 'Message is required.';
        } elseif (in_array($selectedChannel, ['email', 'both'], true) && $subjectValue === '') {
            $error_message = 'Email subject is required for email notifications.';
        } else {
            $recipients = admin_guest_notice_fetch_recipients($selectedAudience, $selectedStore);
            if (empty($recipients)) {
                $error_message = 'No guest recipients were found for this selection.';
            } else {
                $emailSent = 0;
                $smsSent = 0;
                $emailFailed = 0;
                $smsFailed = 0;
                $smsService = null;
                if (in_array($selectedChannel, ['sms', 'both'], true)) {
                    $smsService = new MnotifySmsService();
                }

                foreach ($recipients as $recipient) {
                    $bodyText = admin_guest_notice_apply_placeholders($messageValue, $recipient);
                    if (in_array($selectedChannel, ['email', 'both'], true) && $recipient['email'] !== '') {
                        $bodyHtml = '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>';
                        try {
                            if (sendEmail($recipient['email'], $subjectValue, $bodyHtml, $bodyText, 'guest_admin_notice')) {
                                $emailSent++;
                            } else {
                                $emailFailed++;
                            }
                        } catch (Throwable $e) {
                            $emailFailed++;
                        }
                    }

                    if ($smsService && $recipient['phone'] !== '') {
                        try {
                            $smsResult = $smsService->sendSMS($recipient['phone'], $bodyText, 'guest_admin_notice', null);
                            if (!empty($smsResult['success'])) {
                                $smsSent++;
                            } else {
                                $smsFailed++;
                            }
                        } catch (Throwable $e) {
                            $smsFailed++;
                        }
                    }
                }

                $success_message = 'Guest notification completed. Emails sent: ' . $emailSent . ', email failed/skipped: ' . $emailFailed . '. SMS sent: ' . $smsSent . ', SMS failed/skipped: ' . $smsFailed . '.';
            }
        }
    }
}

$previewRecipients = admin_guest_notice_fetch_recipients($selectedAudience, $selectedStore);
$guestDisplayNotificationCount = admin_guest_notice_count_display_messages();
$csrf_token = generateCSRF();

require_once '../includes/admin_header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
        <div>
            <h1 class="h3 mb-1">Guest Notifications</h1>
            <p class="text-muted mb-0">Send admin-managed email or SMS notices to guest store customers.</p>
        </div>
        <span class="badge bg-info text-dark"><?php echo count($previewRecipients); ?> matching guest contact(s)</span>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <div class="card mb-4 border-warning">
        <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <h5 class="mb-0">Guest Display Notifications</h5>
                <small class="text-muted">Manage notices shown on guest storefront pages.</small>
            </div>
            <span class="badge bg-warning text-dark"><?php echo $guestDisplayNotificationCount; ?> guest-only message(s)</span>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <div class="text-muted">
                    Delete all display notifications targeted only to guest users. Shared <code>All</code> audience messages are kept.
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="delete_guest_display_notifications">
                    <button
                        type="submit"
                        class="btn btn-outline-danger"
                        <?php echo $guestDisplayNotificationCount <= 0 ? 'disabled' : ''; ?>
                        onclick="return confirm('Delete all guest-only display notification messages? This will remove them from every guest storefront.');"
                    >
                        <i class="fas fa-trash-alt me-1"></i> Delete Guest Messages
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Compose Guest Notice</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="send">

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="audience">Guest Audience</label>
                        <select class="form-select" id="audience" name="audience">
                            <option value="all_guests" <?php echo $selectedAudience === 'all_guests' ? 'selected' : ''; ?>>All Guest Contacts</option>
                            <option value="guest_data" <?php echo $selectedAudience === 'guest_data' ? 'selected' : ''; ?>>Guest Data Buyers</option>
                            <option value="guest_products" <?php echo $selectedAudience === 'guest_products' ? 'selected' : ''; ?>>Guest Product Buyers</option>
                            <option value="guest_checkers" <?php echo $selectedAudience === 'guest_checkers' ? 'selected' : ''; ?>>Guest Checker Buyers</option>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="store_slug">Store</label>
                        <select class="form-select" id="store_slug" name="store_slug">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store['store_slug']); ?>" <?php echo $selectedStore === $store['store_slug'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="channel">Channel</label>
                        <select class="form-select" id="channel" name="channel">
                            <option value="email" <?php echo $selectedChannel === 'email' ? 'selected' : ''; ?>>Email Only</option>
                            <option value="sms" <?php echo $selectedChannel === 'sms' ? 'selected' : ''; ?>>SMS Only</option>
                            <option value="both" <?php echo $selectedChannel === 'both' ? 'selected' : ''; ?>>Email + SMS</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="subject">Email Subject</label>
                    <input type="text" class="form-control" id="subject" name="subject" value="<?php echo htmlspecialchars($subjectValue); ?>" placeholder="Required for email">
                </div>

                <div class="mt-3">
                    <label class="form-label" for="message">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="6" required placeholder="Hi {{name}}, ..."><?php echo htmlspecialchars($messageValue); ?></textarea>
                    <small class="text-muted">Placeholders: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{phone}}</code>, <code>{{store}}</code>, <code>{{site}}</code>.</small>
                </div>

                <div class="mt-4 d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <div class="text-muted small">Messages are sent immediately by the admin account.</div>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Send this notification to matching guest contacts?');">
                        <i class="fas fa-paper-plane me-1"></i> Send Guest Notification
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Matching Guest Contacts</h5>
            <small class="text-muted">Preview shows up to 50 contacts after deduplication.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Store</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($previewRecipients)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No guest contacts found.</td></tr>
                    <?php else: ?>
                        <?php foreach (array_slice($previewRecipients, 0, 50) as $recipient): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($recipient['name'] ?: 'Guest Customer'); ?></td>
                                <td><?php echo htmlspecialchars($recipient['email'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($recipient['phone'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($recipient['store_name'] ?: ($recipient['store_slug'] ?: '-')); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('_', ' ', $recipient['source'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
