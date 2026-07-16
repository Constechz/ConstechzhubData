<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

if (!function_exists('admin_notifications_parse_datetime')) {
    function admin_notifications_parse_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return false;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('admin_notifications_normalize_url')) {
    function admin_notifications_normalize_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(https?:\\/\\/|mailto:|tel:)/i', $value)) {
            $validated = filter_var($value, FILTER_VALIDATE_URL);
            if ($validated === false && stripos($value, 'mailto:') !== 0 && stripos($value, 'tel:') !== 0) {
                return false;
            }
            return $value;
        }

        if (preg_match('/^(\\/|\\.\\/|\\.\\.\\/|#|\\?)/', $value)) {
            return $value;
        }

        return false;
    }
}

if (!function_exists('admin_notifications_normalize_media_source')) {
    function admin_notifications_normalize_media_source($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^https?:\\/\\//i', $value)) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : false;
        }

        if (preg_match('/^(\\/|\\.\\/|\\.\\.\\/)/', $value)) {
            return $value;
        }

        return false;
    }
}

if (!function_exists('admin_notifications_is_local_media_path')) {
    function admin_notifications_is_local_media_path($path) {
        $path = trim((string) $path);
        return $path !== '' && stripos($path, 'uploads/notifications/') === 0;
    }
}

if (!function_exists('admin_notifications_delete_media_file')) {
    function admin_notifications_delete_media_file($path) {
        if (!admin_notifications_is_local_media_path($path)) {
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
}

if (!function_exists('admin_notifications_upload_media')) {
    function admin_notifications_upload_media($file, &$error = null) {
        $error = null;

        if (!is_array($file) || !isset($file['error'])) {
            return null;
        }

        $fileError = (int) $file['error'];
        if ($fileError === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($fileError !== UPLOAD_ERR_OK) {
            $error = 'Failed to upload media file. Please try again.';
            return false;
        }

        $tmpName = $file['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $error = 'Uploaded media file is invalid.';
            return false;
        }

        $maxFileSize = 5 * 1024 * 1024; // 5MB
        if ((int) ($file['size'] ?? 0) > $maxFileSize) {
            $error = 'Media file is too large. Maximum allowed size is 5MB.';
            return false;
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mimeType = $finfo ? finfo_file($finfo, $tmpName) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowedMimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];

        if (!isset($allowedMimeMap[$mimeType])) {
            $error = 'Invalid media format. Allowed formats: JPG, PNG, WebP, GIF.';
            return false;
        }

        $uploadDir = __DIR__ . '/../uploads/notifications/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $error = 'Could not create notifications upload directory.';
            return false;
        }

        $extension = $allowedMimeMap[$mimeType];
        try {
            $token = bin2hex(random_bytes(4));
        } catch (Exception $e) {
            $token = substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        }
        $filename = 'notif_' . date('Ymd_His') . '_' . $token . '.' . $extension;
        $target = $uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $target)) {
            $error = 'Failed to save uploaded media file.';
            return false;
        }

        return 'uploads/notifications/' . $filename;
    }
}

// Ensure notifications table/schema exists with advanced fields
try {
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        target_audience ENUM('all', 'agents', 'customers', 'guests') NOT NULL DEFAULT 'all',
        notification_type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
        is_active BOOLEAN DEFAULT TRUE,
        starts_at TIMESTAMP NULL,
        expires_at TIMESTAMP NULL,
        display_order INT DEFAULT 0,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        image_path VARCHAR(255) DEFAULT NULL,
        link_url VARCHAR(255) DEFAULT NULL,
        cta_text VARCHAR(120) DEFAULT NULL,
        cta_secondary_url VARCHAR(255) DEFAULT NULL,
        cta_secondary_text VARCHAR(120) DEFAULT NULL,
        cta_new_tab TINYINT(1) NOT NULL DEFAULT 1,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_target_audience (target_audience),
        INDEX idx_is_active (is_active),
        INDEX idx_expires_at (expires_at)
    )";
    
    $db->query($sql);

    try {
        $db->query("ALTER TABLE notifications MODIFY target_audience ENUM('all', 'agents', 'customers', 'guests') NOT NULL DEFAULT 'all'");
    } catch (Exception $e) {
        error_log('Notification audience enum update failed: ' . $e->getMessage());
    }
    
    // Ensure older installations have required columns
    $columnDefinitions = [
        'image_path' => "VARCHAR(255) DEFAULT NULL",
        'link_url' => "VARCHAR(255) DEFAULT NULL",
        'cta_text' => "VARCHAR(120) DEFAULT NULL",
        'cta_secondary_url' => "VARCHAR(255) DEFAULT NULL",
        'cta_secondary_text' => "VARCHAR(120) DEFAULT NULL",
        'cta_new_tab' => "TINYINT(1) NOT NULL DEFAULT 1"
    ];

    foreach ($columnDefinitions as $column => $definition) {
        if (function_exists('dbh_table_has_column') && !dbh_table_has_column('notifications', $column)) {
            $db->query("ALTER TABLE notifications ADD COLUMN `$column` $definition");
        }
    }

    // Insert sample notifications if table is empty
    $count_check = $db->query("SELECT COUNT(*) as count FROM notifications")->fetch_assoc();
    if ($count_check['count'] == 0) {
        $admin_id = $_SESSION['user_id'];
        $sample_sql = "INSERT INTO notifications (title, message, target_audience, notification_type, created_by) VALUES 
            ('Welcome to Constechzhub', 'Welcome to our platform! Enjoy fast and reliable data bundle services.', 'all', 'info', $admin_id),
            ('Agent Commission Update', 'New commission rates are now in effect. Check your earnings dashboard for details.', 'agents', 'success', $admin_id),
            ('Customer Rewards Program', 'Join our new loyalty program and earn points on every purchase!', 'customers', 'info', $admin_id)";
        $db->query($sample_sql);
    }
} catch (Exception $e) {
    // Table creation failed, but continue with the page
    error_log("Notification table creation failed: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $target_audience = trim((string) ($_POST['target_audience'] ?? 'all'));
        $notification_type = trim((string) ($_POST['notification_type'] ?? 'info'));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $display_order = intval($_POST['display_order'] ?? 0);
        $cta_new_tab = isset($_POST['cta_new_tab']) ? 1 : 0;
        $remove_media = isset($_POST['remove_media']);

        $allowedAudiences = ['all', 'agents', 'customers', 'guests'];
        $allowedTypes = ['info', 'success', 'warning', 'danger'];
        if (!in_array($target_audience, $allowedAudiences, true)) {
            $target_audience = 'all';
        }
        if (!in_array($notification_type, $allowedTypes, true)) {
            $notification_type = 'info';
        }

        $starts_at = admin_notifications_parse_datetime($_POST['starts_at'] ?? '');
        $expires_at = admin_notifications_parse_datetime($_POST['expires_at'] ?? '');

        if ($starts_at === false || $expires_at === false) {
            setFlashMessage('error', 'Invalid start or end date format.');
            header('Location: notifications.php');
            exit();
        }

        if ($starts_at && $expires_at && strtotime($starts_at) > strtotime($expires_at)) {
            setFlashMessage('error', 'End date/time must be later than start date/time.');
            header('Location: notifications.php');
            exit();
        }

        $link_url = admin_notifications_normalize_url($_POST['link_url'] ?? '');
        $cta_text = trim((string) ($_POST['cta_text'] ?? ''));
        if ($link_url === false) {
            setFlashMessage('error', 'Primary button URL is invalid. Use http(s), mailto, tel, or a site-relative path.');
            header('Location: notifications.php');
            exit();
        }
        if ($link_url === null && $cta_text !== '') {
            setFlashMessage('error', 'Primary button text requires a URL.');
            header('Location: notifications.php');
            exit();
        }
        if ($link_url !== null && $cta_text === '') {
            $cta_text = 'Learn more';
        }
        if ($link_url === null) {
            $cta_text = null;
        }

        $cta_secondary_url = admin_notifications_normalize_url($_POST['cta_secondary_url'] ?? '');
        $cta_secondary_text = trim((string) ($_POST['cta_secondary_text'] ?? ''));
        if ($cta_secondary_url === false) {
            setFlashMessage('error', 'Secondary button URL is invalid. Use http(s), mailto, tel, or a site-relative path.');
            header('Location: notifications.php');
            exit();
        }
        if ($cta_secondary_url === null && $cta_secondary_text !== '') {
            setFlashMessage('error', 'Secondary button text requires a URL.');
            header('Location: notifications.php');
            exit();
        }
        if ($cta_secondary_url !== null && $cta_secondary_text === '') {
            $cta_secondary_text = 'Open';
        }
        if ($cta_secondary_url === null) {
            $cta_secondary_text = null;
        }

        if ($title === '' || $message === '') {
            setFlashMessage('error', 'Title and message are required.');
            header('Location: notifications.php');
            exit();
        }

        $currentImagePath = null;
        if ($action === 'edit') {
            if ($id <= 0) {
                setFlashMessage('error', 'Invalid notification selected for editing.');
                header('Location: notifications.php');
                exit();
            }

            $checkStmt = $db->prepare("SELECT image_path FROM notifications WHERE id = ? LIMIT 1");
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $current = $checkStmt->get_result()->fetch_assoc();
            if (!$current) {
                setFlashMessage('error', 'Notification not found.');
                header('Location: notifications.php');
                exit();
            }
            $currentImagePath = $current['image_path'] ?? null;
        }

        $image_path = $action === 'edit' ? $currentImagePath : null;

        $media_url = admin_notifications_normalize_media_source($_POST['media_url'] ?? '');
        if ($media_url === false) {
            setFlashMessage('error', 'Media URL is invalid. Use http(s) or a site-relative path.');
            header('Location: notifications.php');
            exit();
        }

        if ($remove_media) {
            $image_path = null;
        }
        if ($media_url !== null) {
            $image_path = $media_url;
        }

        $uploadError = null;
        $uploadedImagePath = admin_notifications_upload_media($_FILES['media_file'] ?? null, $uploadError);
        if ($uploadedImagePath === false) {
            setFlashMessage('error', $uploadError ?: 'Failed to process uploaded media file.');
            header('Location: notifications.php');
            exit();
        }
        if ($uploadedImagePath !== null) {
            $image_path = $uploadedImagePath;
        }

        try {
            if ($action === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO notifications
                    (title, message, target_audience, notification_type, is_active, starts_at, expires_at, display_order, created_by, image_path, link_url, cta_text, cta_secondary_url, cta_secondary_text, cta_new_tab)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "ssssissiisssssi",
                    $title,
                    $message,
                    $target_audience,
                    $notification_type,
                    $is_active,
                    $starts_at,
                    $expires_at,
                    $display_order,
                    $_SESSION['user_id'],
                    $image_path,
                    $link_url,
                    $cta_text,
                    $cta_secondary_url,
                    $cta_secondary_text,
                    $cta_new_tab
                );
            } else {
                $stmt = $db->prepare("
                    UPDATE notifications
                    SET title = ?, message = ?, target_audience = ?, notification_type = ?, is_active = ?, starts_at = ?, expires_at = ?, display_order = ?, image_path = ?, link_url = ?, cta_text = ?, cta_secondary_url = ?, cta_secondary_text = ?, cta_new_tab = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    "ssssississsssii",
                    $title,
                    $message,
                    $target_audience,
                    $notification_type,
                    $is_active,
                    $starts_at,
                    $expires_at,
                    $display_order,
                    $image_path,
                    $link_url,
                    $cta_text,
                    $cta_secondary_url,
                    $cta_secondary_text,
                    $cta_new_tab,
                    $id
                );
            }

            if ($stmt && $stmt->execute()) {
                if ($action === 'edit' && $currentImagePath && $currentImagePath !== $image_path) {
                    admin_notifications_delete_media_file($currentImagePath);
                }
                setFlashMessage('success', $action === 'create' ? 'Notification created successfully.' : 'Notification updated successfully.');
            } else {
                if ($uploadedImagePath) {
                    admin_notifications_delete_media_file($uploadedImagePath);
                }
                setFlashMessage('error', $action === 'create' ? 'Failed to create notification.' : 'Failed to update notification.');
            }
        } catch (Exception $e) {
            if ($uploadedImagePath) {
                admin_notifications_delete_media_file($uploadedImagePath);
            }
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }

        header('Location: notifications.php');
        exit();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        
        try {
            $currentMediaPath = null;
            $mediaStmt = $db->prepare("SELECT image_path FROM notifications WHERE id = ? LIMIT 1");
            if ($mediaStmt) {
                $mediaStmt->bind_param("i", $id);
                $mediaStmt->execute();
                $mediaRow = $mediaStmt->get_result()->fetch_assoc();
                $currentMediaPath = $mediaRow['image_path'] ?? null;
            }

            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                if ($currentMediaPath) {
                    admin_notifications_delete_media_file($currentMediaPath);
                }
                setFlashMessage('success', 'Notification deleted successfully');
            } else {
                setFlashMessage('error', 'Failed to delete notification');
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }
        
        header('Location: notifications.php');
        exit();
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        
        try {
            $stmt = $db->prepare("UPDATE notifications SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                setFlashMessage('success', 'Notification status updated successfully');
            } else {
                setFlashMessage('error', 'Failed to update notification status');
            }
        } catch (Exception $e) {
            setFlashMessage('error', 'Database error: ' . $e->getMessage());
        }
        
        header('Location: notifications.php');
        exit();
    }
}

// Fetch notifications
$notifications = [];
try {
    $result = $db->query("
        SELECT n.*, u.username as created_by_username 
        FROM notifications n 
        LEFT JOIN users u ON n.created_by = u.id 
        ORDER BY n.display_order ASC, n.created_at DESC
    ");
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

// Get notification for editing if edit_id is provided
$edit_notification = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    try {
        $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $edit_notification = $stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error fetching notification for edit: " . $e->getMessage());
    }
}

$pageTitle = "Notification Settings";
require_once '../includes/admin_header.php';
?>

<style>
    @media (min-width: 769px) {
        html,
        body {
            overflow-x: hidden;
        }

        .main-content {
            max-width: calc(100vw - 250px);
            overflow-x: hidden;
        }

        .notifications-admin-page {
            max-width: 100%;
            overflow-x: hidden;
        }

        .notifications-admin-page .card,
        .notifications-admin-page .card-body {
            max-width: 100%;
            overflow: hidden;
        }

        .notifications-admin-page .table-responsive {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .notifications-admin-page .table {
            width: 100%;
            table-layout: fixed;
        }

        .notifications-admin-page .table th,
        .notifications-admin-page .table td {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: normal;
            vertical-align: middle;
        }

        .notifications-admin-page .table th:nth-child(1),
        .notifications-admin-page .table td:nth-child(1) {
            width: 6%;
        }

        .notifications-admin-page .table th:nth-child(2),
        .notifications-admin-page .table td:nth-child(2) {
            width: 22%;
        }

        .notifications-admin-page .table th:nth-child(3),
        .notifications-admin-page .table td:nth-child(3),
        .notifications-admin-page .table th:nth-child(5),
        .notifications-admin-page .table td:nth-child(5) {
            width: 12%;
        }

        .notifications-admin-page .table th:nth-child(4),
        .notifications-admin-page .table td:nth-child(4),
        .notifications-admin-page .table th:nth-child(6),
        .notifications-admin-page .table td:nth-child(6) {
            width: 8%;
        }

        .notifications-admin-page .table th:nth-child(7),
        .notifications-admin-page .table td:nth-child(7),
        .notifications-admin-page .table th:nth-child(8),
        .notifications-admin-page .table td:nth-child(8) {
            width: 12%;
        }

        .notifications-admin-page .table th:nth-child(9),
        .notifications-admin-page .table td:nth-child(9) {
            width: 8%;
        }

        .notifications-admin-page .badge {
            white-space: normal;
            line-height: 1.25;
        }

        .notifications-admin-page .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }

        .notifications-admin-page .btn-group .btn {
            border-radius: 0.25rem !important;
            flex: 0 0 auto;
        }
    }

    @media (max-width: 768px) {
        html, body {
            overflow-x: hidden;
        }

        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .container-fluid {
            overflow-x: hidden;
        }

        .d-flex.justify-content-between.align-items-center.mb-4 {
            flex-direction: column;
            align-items: flex-start !important;
            gap: 0.75rem;
        }

        .d-flex.justify-content-between.align-items-center.mb-4 .btn {
            width: 100%;
        }

        .table-responsive {
            overflow-x: hidden;
        }

        .table-responsive table,
        .table-responsive thead,
        .table-responsive tbody,
        .table-responsive th,
        .table-responsive td,
        .table-responsive tr {
            display: block;
            width: 100%;
        }

        .table-responsive thead {
            display: none;
        }

        .table-responsive tbody tr {
            margin-bottom: 1rem;
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            background: var(--card-bg, #fff);
        }

        [data-theme="dark"] .table-responsive tbody tr {
            background: #1f2937;
            border-color: #374151;
        }

        .table-responsive tbody td {
            border: none;
            padding: 0.45rem 0;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.25rem;
            font-size: 0.85rem;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .table-responsive tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            color: var(--text-muted, #64748b);
            font-size: 0.8rem;
        }

        .table-responsive tbody td:last-child {
            padding-bottom: 0;
        }

        .table-responsive .text-center {
            text-align: left !important;
        }

        .table-responsive .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }

        .table-responsive .btn-group .btn {
            min-width: 36px;
        }
    }

    .notification-media-preview {
        max-width: 100%;
        max-height: 180px;
        border-radius: 10px;
        border: 1px solid var(--border-color, #e2e8f0);
    }

    .notification-mini-media {
        width: 56px;
        height: 56px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid var(--border-color, #e2e8f0);
    }

    .notification-preview-card {
        border: 1px dashed var(--border-color, #d0d7e2);
        border-radius: 10px;
        padding: 1rem;
        background: var(--card-bg, #fff);
    }

    .notification-preview-card .preview-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .notification-preview-card .preview-media {
        margin-bottom: 0.75rem;
    }
</style>

<div class="container-fluid notifications-admin-page">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Notification Settings</h1>
            <p class="text-muted">Manage notifications for agents and customers</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addNotificationModal">
            <i class="fas fa-plus me-2"></i>Create Notification
        </button>
    </div>

    <?php if (hasFlashMessage()): 
        $flash = getFlashMessage(); ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Notifications List -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Active Notifications</h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No notifications found</h5>
                    <p class="text-muted">Create your first notification to get started</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order</th>
                                <th>Title</th>
                                <th>Target Audience</th>
                                <th>Type</th>
                                <th>Media / Buttons</th>
                                <th>Status</th>
                                <th>Schedule</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td data-label="Order">
                                        <span class="badge bg-secondary"><?php echo $notification['display_order']; ?></span>
                                    </td>
                                    <td data-label="Title">
                                        <div>
                                            <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars(substr($notification['message'], 0, 60)); ?>
                                                <?php if (strlen($notification['message']) > 60): ?>...<?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Target Audience">
                                        <?php 
                                        $audience_labels = [
                                            'all' => '<span class="badge bg-info">All Users</span>',
                                            'agents' => '<span class="badge bg-success">Agents Only</span>',
                                            'customers' => '<span class="badge bg-primary">Customers Only</span>',
                                            'guests' => '<span class="badge bg-warning text-dark">Store Guests</span>'
                                        ];
                                        echo $audience_labels[$notification['target_audience']] ?? htmlspecialchars($notification['target_audience']);
                                        ?>
                                    </td>
                                    <td data-label="Type">
                                        <?php 
                                        $type_labels = [
                                            'info' => '<span class="badge bg-info">Info</span>',
                                            'success' => '<span class="badge bg-success">Success</span>',
                                            'warning' => '<span class="badge bg-warning text-dark">Warning</span>',
                                            'danger' => '<span class="badge bg-danger">Danger</span>'
                                        ];
                                        echo $type_labels[$notification['notification_type']] ?? htmlspecialchars($notification['notification_type']);
                                        ?>
                                    </td>
                                    <td data-label="Media / Buttons">
                                        <?php
                                        $mediaPath = trim((string) ($notification['image_path'] ?? ''));
                                        $primaryUrl = trim((string) ($notification['link_url'] ?? ''));
                                        $secondaryUrl = trim((string) ($notification['cta_secondary_url'] ?? ''));
                                        $mediaUrl = '';
                                        if ($mediaPath !== '') {
                                            $mediaUrl = preg_match('/^https?:\\/\\//i', $mediaPath) ? $mediaPath : dbh_asset($mediaPath);
                                        }
                                        ?>
                                        <?php if ($mediaUrl !== ''): ?>
                                            <div class="mb-2">
                                                <img src="<?php echo htmlspecialchars($mediaUrl); ?>" alt="Notification media" class="notification-mini-media">
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php if ($primaryUrl !== ''): ?>
                                                <span class="badge bg-primary">Primary CTA</span>
                                            <?php endif; ?>
                                            <?php if ($secondaryUrl !== ''): ?>
                                                <span class="badge bg-dark">Secondary CTA</span>
                                            <?php endif; ?>
                                            <?php if ($mediaUrl === '' && $primaryUrl === '' && $secondaryUrl === ''): ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?php echo $notification['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $notification['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="small" data-label="Schedule">
                                        <?php if ($notification['starts_at']): ?>
                                            <div>Start: <?php echo date('M j, Y H:i', strtotime($notification['starts_at'])); ?></div>
                                        <?php endif; ?>
                                        <?php if ($notification['expires_at']): ?>
                                            <div>End: <?php echo date('M j, Y H:i', strtotime($notification['expires_at'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (!$notification['starts_at'] && !$notification['expires_at']): ?>
                                            <span class="text-muted">Always active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small" data-label="Created By">
                                        <?php echo htmlspecialchars($notification['created_by_username'] ?? 'Unknown'); ?>
                                        <div class="text-muted">
                                            <?php echo date('M j, Y', strtotime($notification['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    onclick="editNotification(<?php echo htmlspecialchars(json_encode($notification)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" class="btn btn-outline-<?php echo $notification['is_active'] ? 'warning' : 'success'; ?>" 
                                                        title="<?php echo $notification['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas fa-<?php echo $notification['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this notification?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
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

<!-- Add/Edit Notification Modal -->
<div class="modal fade" id="addNotificationModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="notificationForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create" id="formAction">
                <input type="hidden" name="id" value="" id="notificationId">
                <input type="hidden" name="existing_image_path" value="" id="existingImagePath">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Create New Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="form-group mb-3">
                                        <label for="title" class="form-label">Title *</label>
                                        <input type="text" class="form-control" id="title" name="title" required maxlength="255">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group mb-3">
                                        <label for="display_order" class="form-label">Display Order</label>
                                        <input type="number" class="form-control" id="display_order" name="display_order" value="0" min="0">
                                        <small class="text-muted">Lower numbers display first</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group mb-3">
                                <label for="message" class="form-label">Message *</label>
                                <textarea class="form-control" id="message" name="message" rows="4" required></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="target_audience" class="form-label">Target Audience</label>
                                        <select class="form-control" id="target_audience" name="target_audience" required>
                                            <option value="all">All Users</option>
                                            <option value="agents">Agents Only</option>
                                            <option value="customers">Customers Only</option>
                                            <option value="guests">Store Guests</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="notification_type" class="form-label">Notification Type</label>
                                        <select class="form-control" id="notification_type" name="notification_type" required>
                                            <option value="info">Info (Blue)</option>
                                            <option value="success">Success (Green)</option>
                                            <option value="warning">Warning (Yellow)</option>
                                            <option value="danger">Danger (Red)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="starts_at" class="form-label">Start Date/Time</label>
                                        <input type="datetime-local" class="form-control" id="starts_at" name="starts_at">
                                        <small class="text-muted">Optional. Leave empty to start immediately.</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label for="expires_at" class="form-label">End Date/Time</label>
                                        <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                                        <small class="text-muted">Optional. Leave empty for no expiration.</small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h6 class="mb-2">Flyer / Media</h6>
                            <div class="form-group mb-3">
                                <label for="media_file" class="form-label">Upload Image/Flyer</label>
                                <input type="file" class="form-control" id="media_file" name="media_file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                                <small class="text-muted">Standard formats supported: JPG, PNG, WebP, GIF (max 5MB).</small>
                            </div>
                            <div class="form-group mb-3">
                                <label for="media_url" class="form-label">Or Use Media URL</label>
                                <input type="text" class="form-control" id="media_url" name="media_url" placeholder="https://example.com/flyer.jpg or /uploads/flyer.jpg">
                            </div>
                            <div id="existingMediaWrap" class="mb-3 d-none">
                                <div class="mb-2">
                                    <img id="existingMediaPreview" class="notification-media-preview" src="" alt="Current media">
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="remove_media" name="remove_media">
                                    <label class="form-check-label" for="remove_media">Remove current media</label>
                                </div>
                            </div>

                            <hr>

                            <h6 class="mb-2">Clickable Buttons</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-2">
                                        <label for="cta_text" class="form-label">Primary Button Text</label>
                                        <input type="text" class="form-control" id="cta_text" name="cta_text" maxlength="120" placeholder="e.g. Buy Now">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="link_url" class="form-label">Primary Button URL</label>
                                        <input type="text" class="form-control" id="link_url" name="link_url" maxlength="255" placeholder="/customer/buy-data.php or https://...">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-2">
                                        <label for="cta_secondary_text" class="form-label">Secondary Button Text</label>
                                        <input type="text" class="form-control" id="cta_secondary_text" name="cta_secondary_text" maxlength="120" placeholder="e.g. View Details">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="cta_secondary_url" class="form-label">Secondary Button URL</label>
                                        <input type="text" class="form-control" id="cta_secondary_url" name="cta_secondary_url" maxlength="255" placeholder="/support.php or https://...">
                                    </div>
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input" id="cta_new_tab" name="cta_new_tab" checked>
                                <label class="form-check-label" for="cta_new_tab">Open button links in a new tab</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                                <label class="form-check-label" for="is_active">
                                    Active (notification will be displayed)
                                </label>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <h6 class="mb-2">Live Preview</h6>
                            <div class="notification-preview-card">
                                <div id="previewMediaWrap" class="preview-media d-none">
                                    <img id="previewMediaImage" class="notification-media-preview" src="" alt="Preview media">
                                </div>
                                <h6 id="previewTitle" class="mb-1">Notification title</h6>
                                <p id="previewMessage" class="mb-0 text-muted">Your message appears here.</p>
                                <div id="previewActions" class="preview-actions d-none">
                                    <button type="button" id="previewPrimaryBtn" class="btn btn-sm btn-primary">Learn more</button>
                                    <button type="button" id="previewSecondaryBtn" class="btn btn-sm btn-outline-secondary">Open</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Create Notification</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const notificationForm = document.getElementById('notificationForm');
const mediaFileInput = document.getElementById('media_file');
const mediaUrlInput = document.getElementById('media_url');
const existingImagePathInput = document.getElementById('existingImagePath');
const removeMediaCheckbox = document.getElementById('remove_media');
const existingMediaWrap = document.getElementById('existingMediaWrap');
const existingMediaPreview = document.getElementById('existingMediaPreview');
let uploadedMediaPreview = '';

function toDateTimeLocalValue(value) {
    if (!value) return '';
    const normalized = String(value).trim().replace(' ', 'T');
    return normalized.length >= 16 ? normalized.slice(0, 16) : '';
}

function resolveMediaSource(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    if (path.startsWith('/')) return path;
    return '../' + path.replace(/^\.?\//, '');
}

function refreshNotificationPreview() {
    const title = document.getElementById('title').value.trim();
    const message = document.getElementById('message').value.trim();
    const primaryText = document.getElementById('cta_text').value.trim();
    const primaryUrl = document.getElementById('link_url').value.trim();
    const secondaryText = document.getElementById('cta_secondary_text').value.trim();
    const secondaryUrl = document.getElementById('cta_secondary_url').value.trim();

    document.getElementById('previewTitle').textContent = title || 'Notification title';
    document.getElementById('previewMessage').textContent = message || 'Your message appears here.';

    let mediaSrc = uploadedMediaPreview;
    if (!mediaSrc) {
        const mediaUrl = mediaUrlInput.value.trim();
        if (mediaUrl) {
            mediaSrc = mediaUrl;
        } else if (!removeMediaCheckbox.checked && existingImagePathInput.value) {
            mediaSrc = resolveMediaSource(existingImagePathInput.value);
        }
    }

    const previewMediaWrap = document.getElementById('previewMediaWrap');
    const previewMediaImage = document.getElementById('previewMediaImage');
    if (mediaSrc) {
        previewMediaImage.src = mediaSrc;
        previewMediaWrap.classList.remove('d-none');
    } else {
        previewMediaImage.src = '';
        previewMediaWrap.classList.add('d-none');
    }

    const hasPrimary = primaryUrl !== '';
    const hasSecondary = secondaryUrl !== '';
    const previewActions = document.getElementById('previewActions');
    const previewPrimaryBtn = document.getElementById('previewPrimaryBtn');
    const previewSecondaryBtn = document.getElementById('previewSecondaryBtn');

    previewPrimaryBtn.style.display = hasPrimary ? '' : 'none';
    previewPrimaryBtn.textContent = primaryText || 'Learn more';
    previewSecondaryBtn.style.display = hasSecondary ? '' : 'none';
    previewSecondaryBtn.textContent = secondaryText || 'Open';
    previewActions.classList.toggle('d-none', !hasPrimary && !hasSecondary);
}

function showExistingMedia(path) {
    const src = resolveMediaSource(path);
    if (!src) {
        existingMediaPreview.src = '';
        existingMediaWrap.classList.add('d-none');
        return;
    }
    existingMediaPreview.src = src;
    existingMediaWrap.classList.remove('d-none');
}

function editNotification(notification) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('notificationId').value = notification.id;
    document.getElementById('modalTitle').textContent = 'Edit Notification';
    document.getElementById('submitBtn').textContent = 'Update Notification';

    document.getElementById('title').value = notification.title || '';
    document.getElementById('message').value = notification.message || '';
    document.getElementById('target_audience').value = notification.target_audience || 'all';
    document.getElementById('notification_type').value = notification.notification_type || 'info';
    document.getElementById('display_order').value = notification.display_order || 0;
    document.getElementById('is_active').checked = notification.is_active == 1;
    document.getElementById('link_url').value = notification.link_url || '';
    document.getElementById('cta_text').value = notification.cta_text || '';
    document.getElementById('cta_secondary_url').value = notification.cta_secondary_url || '';
    document.getElementById('cta_secondary_text').value = notification.cta_secondary_text || '';
    document.getElementById('cta_new_tab').checked = notification.cta_new_tab == 1;
    document.getElementById('starts_at').value = toDateTimeLocalValue(notification.starts_at);
    document.getElementById('expires_at').value = toDateTimeLocalValue(notification.expires_at);

    existingImagePathInput.value = notification.image_path || '';
    mediaUrlInput.value = '';
    mediaFileInput.value = '';
    uploadedMediaPreview = '';
    removeMediaCheckbox.checked = false;
    showExistingMedia(existingImagePathInput.value);
    refreshNotificationPreview();

    new bootstrap.Modal(document.getElementById('addNotificationModal')).show();
}

mediaFileInput.addEventListener('change', function () {
    uploadedMediaPreview = '';
    const file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) {
        refreshNotificationPreview();
        return;
    }

    const reader = new FileReader();
    reader.onload = function (event) {
        uploadedMediaPreview = event.target && event.target.result ? String(event.target.result) : '';
        refreshNotificationPreview();
    };
    reader.readAsDataURL(file);
});

mediaUrlInput.addEventListener('input', refreshNotificationPreview);
removeMediaCheckbox.addEventListener('change', function () {
    if (this.checked) {
        mediaUrlInput.value = '';
        mediaFileInput.value = '';
        uploadedMediaPreview = '';
    }
    refreshNotificationPreview();
});

['title', 'message', 'cta_text', 'link_url', 'cta_secondary_text', 'cta_secondary_url'].forEach((id) => {
    const element = document.getElementById(id);
    if (element) {
        element.addEventListener('input', refreshNotificationPreview);
    }
});

document.getElementById('addNotificationModal').addEventListener('hidden.bs.modal', function () {
    notificationForm.reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('notificationId').value = '';
    document.getElementById('modalTitle').textContent = 'Create New Notification';
    document.getElementById('submitBtn').textContent = 'Create Notification';
    document.getElementById('is_active').checked = true;
    document.getElementById('cta_new_tab').checked = true;
    existingImagePathInput.value = '';
    mediaUrlInput.value = '';
    mediaFileInput.value = '';
    uploadedMediaPreview = '';
    removeMediaCheckbox.checked = false;
    existingMediaWrap.classList.add('d-none');
    existingMediaPreview.src = '';
    refreshNotificationPreview();
});

document.addEventListener('DOMContentLoaded', refreshNotificationPreview);
</script>

<!-- IMMEDIATE Icon Fix for square placeholder issues -->
<script src="../immediate_icon_fix.js"></script>

<?php require_once '../includes/admin_footer.php'; ?>
