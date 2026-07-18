<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

// First, let's try to create the notifications table if it doesn't exist
try {
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        link_url VARCHAR(255) DEFAULT NULL,
        cta_text VARCHAR(120) DEFAULT NULL,
        target_audience ENUM('all', 'agents', 'customers') NOT NULL DEFAULT 'all',
        notification_type ENUM('info', 'success', 'warning', 'danger') NOT NULL DEFAULT 'info',
        is_active BOOLEAN DEFAULT TRUE,
        starts_at TIMESTAMP NULL,
        expires_at TIMESTAMP NULL,
        display_order INT DEFAULT 0,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_target_audience (target_audience),
        INDEX idx_is_active (is_active),
        INDEX idx_expires_at (expires_at)
    )";
    
    $db->query($sql);

    // Ensure new columns exist for advertising media
    $columnMigrations = [
        "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS link_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS cta_text VARCHAR(120) DEFAULT NULL"
    ];
    foreach ($columnMigrations as $migration) {
        try {
            $db->query($migration);
        } catch (Exception $e) {
            // Ignore migration failures (column may already exist)
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

function handleNotificationImageUpload($file, $existing_path = null) {
    $allowed_types = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if (!in_array($file['type'], $allowed_types, true)) {
        return [null, 'Invalid file type. Please upload PNG, JPG, GIF, or WebP images only.'];
    }
    if ($file['size'] > $max_size) {
        return [null, 'File too large. Maximum size is 2MB.'];
    }

    $upload_dir = '../uploads/notifications/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'jpg';
    }
    $filename = 'notification-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $filepath = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return [null, 'Failed to upload image. Please try again.'];
    }

    if ($existing_path) {
        $existing_file = '../' . ltrim($existing_path, '/');
        if (is_file($existing_file)) {
            @unlink($existing_file);
        }
    }

    return ['uploads/notifications/' . $filename, null];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $title = sanitize($_POST['title']);
        $message = sanitize($_POST['message']);
        $target_audience = sanitize($_POST['target_audience']);
        $notification_type = sanitize($_POST['notification_type']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $starts_at = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $display_order = intval($_POST['display_order'] ?? 0);
        $link_url = trim($_POST['link_url'] ?? '');
        $cta_text = trim($_POST['cta_text'] ?? '');
        $image_path = null;

        if ($link_url !== '' && !preg_match('/^https?:\\/\\//i', $link_url) && strpos($link_url, '/') !== 0) {
            $link_url = 'https://' . $link_url;
        }
        if ($link_url !== '' && !filter_var($link_url, FILTER_VALIDATE_URL) && strpos($link_url, '/') !== 0) {
            setFlashMessage('error', 'Invalid link URL provided.');
            header('Location: notifications.php');
            exit();
        }

        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            [$image_path, $upload_error] = handleNotificationImageUpload($_FILES['image']);
            if ($upload_error) {
                setFlashMessage('error', $upload_error);
                header('Location: notifications.php');
                exit();
            }
        }
        
        if (empty($title) || empty($message)) {
            setFlashMessage('error', 'Title and message are required');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO notifications (title, message, image_path, link_url, cta_text, target_audience, notification_type, is_active, starts_at, expires_at, display_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "sssssssissii",
                    $title,
                    $message,
                    $image_path,
                    $link_url,
                    $cta_text,
                    $target_audience,
                    $notification_type,
                    $is_active,
                    $starts_at,
                    $expires_at,
                    $display_order,
                    $_SESSION['user_id']
                );
                
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Notification created successfully');
                } else {
                    setFlashMessage('error', 'Failed to create notification');
                }
            } catch (Exception $e) {
                setFlashMessage('error', 'Database error: ' . $e->getMessage());
            }
        }
        
        header('Location: notifications.php');
        exit();
    }
    
    if ($action === 'edit') {
        $id = intval($_POST['id']);
        $title = sanitize($_POST['title']);
        $message = sanitize($_POST['message']);
        $target_audience = sanitize($_POST['target_audience']);
        $notification_type = sanitize($_POST['notification_type']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $starts_at = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $display_order = intval($_POST['display_order'] ?? 0);
        $link_url = trim($_POST['link_url'] ?? '');
        $cta_text = trim($_POST['cta_text'] ?? '');
        $existing_image = trim($_POST['existing_image_path'] ?? '');
        $remove_image = isset($_POST['remove_image']);

        if ($link_url !== '' && !preg_match('/^https?:\\/\\//i', $link_url) && strpos($link_url, '/') !== 0) {
            $link_url = 'https://' . $link_url;
        }
        if ($link_url !== '' && !filter_var($link_url, FILTER_VALIDATE_URL) && strpos($link_url, '/') !== 0) {
            setFlashMessage('error', 'Invalid link URL provided.');
            header('Location: notifications.php');
            exit();
        }

        $image_path = $existing_image !== '' ? $existing_image : null;
        if ($remove_image && $existing_image !== '') {
            $existing_file = '../' . ltrim($existing_image, '/');
            if (is_file($existing_file)) {
                @unlink($existing_file);
            }
            $image_path = null;
        }

        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            [$uploaded_path, $upload_error] = handleNotificationImageUpload($_FILES['image'], $existing_image ?: null);
            if ($upload_error) {
                setFlashMessage('error', $upload_error);
                header('Location: notifications.php');
                exit();
            }
            $image_path = $uploaded_path;
        }
        
        if (empty($title) || empty($message)) {
            setFlashMessage('error', 'Title and message are required');
        } else {
            try {
                $stmt = $db->prepare("UPDATE notifications SET title = ?, message = ?, image_path = ?, link_url = ?, cta_text = ?, target_audience = ?, notification_type = ?, is_active = ?, starts_at = ?, expires_at = ?, display_order = ? WHERE id = ?");
                $stmt->bind_param(
                    "sssssssissii",
                    $title,
                    $message,
                    $image_path,
                    $link_url,
                    $cta_text,
                    $target_audience,
                    $notification_type,
                    $is_active,
                    $starts_at,
                    $expires_at,
                    $display_order,
                    $id
                );
                
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Notification updated successfully');
                } else {
                    setFlashMessage('error', 'Failed to update notification');
                }
            } catch (Exception $e) {
                setFlashMessage('error', 'Database error: ' . $e->getMessage());
            }
        }
        
        header('Location: notifications.php');
        exit();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        
        try {
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
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
            border: 1px solid var(--border-color, #F1E9DA);
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            background: var(--card-bg, #F1E9DA);
        }

        [data-theme="dark"] .table-responsive tbody tr {
            background: #2E294E;
            border-color: #2E294E;
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
            color: var(--text-muted, #541388);
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
</style>

<div class="container-fluid">
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
                                            'customers' => '<span class="badge bg-primary">Customers Only</span>'
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
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="notificationForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create" id="formAction">
                <input type="hidden" name="id" value="" id="notificationId">
                <input type="hidden" name="existing_image_path" value="" id="existing_image_path">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Create New Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
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
                                <label for="image" class="form-label">Advertisement Image (Optional)</label>
                                <input type="file" class="form-control" id="image" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
                                <small class="text-muted">PNG/JPG/GIF/WebP up to 2MB.</small>
                                <div id="imagePreviewWrapper" class="mt-2 d-none">
                                    <img id="imagePreview" src="" alt="Notification image" style="max-width: 100%; border-radius: 8px; border: 1px solid #F1E9DA;">
                                    <div class="form-check mt-2">
                                        <input type="checkbox" class="form-check-input" id="remove_image" name="remove_image">
                                        <label class="form-check-label" for="remove_image">Remove current image</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="link_url" class="form-label">CTA Link (Optional)</label>
                                <input type="url" class="form-control" id="link_url" name="link_url" placeholder="https://example.com/promo">
                            </div>
                            <div class="form-group mb-3">
                                <label for="cta_text" class="form-label">CTA Button Text (Optional)</label>
                                <input type="text" class="form-control" id="cta_text" name="cta_text" maxlength="120" placeholder="Learn more">
                                <small class="text-muted">Shown when a link is provided.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="target_audience" class="form-label">Target Audience</label>
                                <select class="form-control" id="target_audience" name="target_audience" required>
                                    <option value="all">All Users</option>
                                    <option value="agents">Agents Only</option>
                                    <option value="customers">Customers Only</option>
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
                                <label for="starts_at" class="form-label">Start Date/Time (Optional)</label>
                                <input type="datetime-local" class="form-control" id="starts_at" name="starts_at">
                                <small class="text-muted">Leave empty to start immediately</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="expires_at" class="form-label">End Date/Time (Optional)</label>
                                <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                                <small class="text-muted">Leave empty for no expiration</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Active (notification will be displayed)
                        </label>
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
function editNotification(notification) {
    // Switch to edit mode
    document.getElementById('formAction').value = 'edit';
    document.getElementById('notificationId').value = notification.id;
    document.getElementById('modalTitle').textContent = 'Edit Notification';
    document.getElementById('submitBtn').textContent = 'Update Notification';
    
    // Fill form fields
    document.getElementById('title').value = notification.title;
    document.getElementById('message').value = notification.message;
    document.getElementById('target_audience').value = notification.target_audience;
    document.getElementById('notification_type').value = notification.notification_type;
    document.getElementById('display_order').value = notification.display_order;
    document.getElementById('is_active').checked = notification.is_active == 1;
    document.getElementById('link_url').value = notification.link_url || '';
    document.getElementById('cta_text').value = notification.cta_text || '';
    document.getElementById('existing_image_path').value = notification.image_path || '';
    document.getElementById('remove_image').checked = false;
    document.getElementById('image').value = '';
    const previewWrapper = document.getElementById('imagePreviewWrapper');
    const previewImage = document.getElementById('imagePreview');
    if (notification.image_path) {
        let imageUrl = notification.image_path;
        if (!/^https?:\/\//i.test(imageUrl)) {
            imageUrl = '../' + imageUrl.replace(/^\/+/, '');
        }
        previewImage.src = imageUrl;
        previewWrapper.classList.remove('d-none');
    } else {
        previewImage.src = '';
        previewWrapper.classList.add('d-none');
    }
    
    // Handle datetime fields
    if (notification.starts_at) {
        const startDate = new Date(notification.starts_at);
        document.getElementById('starts_at').value = startDate.toISOString().slice(0, 16);
    } else {
        document.getElementById('starts_at').value = '';
    }
    
    if (notification.expires_at) {
        const endDate = new Date(notification.expires_at);
        document.getElementById('expires_at').value = endDate.toISOString().slice(0, 16);
    } else {
        document.getElementById('expires_at').value = '';
    }
    
    // Show modal
    new bootstrap.Modal(document.getElementById('addNotificationModal')).show();
}

// Reset form when modal is closed
document.getElementById('addNotificationModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('notificationForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('notificationId').value = '';
    document.getElementById('modalTitle').textContent = 'Create New Notification';
    document.getElementById('submitBtn').textContent = 'Create Notification';
    document.getElementById('is_active').checked = true;
    document.getElementById('existing_image_path').value = '';
    document.getElementById('remove_image').checked = false;
    document.getElementById('imagePreview').src = '';
    document.getElementById('imagePreviewWrapper').classList.add('d-none');
});

const imageInput = document.getElementById('image');
if (imageInput) {
    imageInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        const previewWrapper = document.getElementById('imagePreviewWrapper');
        const previewImage = document.getElementById('imagePreview');
        if (!file) {
            previewImage.src = '';
            previewWrapper.classList.add('d-none');
            return;
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            previewImage.src = e.target.result;
            previewWrapper.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    });
}
</script>

<!-- IMMEDIATE Icon Fix for square placeholder issues -->
<script src="../immediate_icon_fix.js"></script>

<?php require_once '../includes/admin_footer.php'; ?>
