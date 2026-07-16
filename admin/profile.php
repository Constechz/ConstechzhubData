<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

requireRole('admin');

$current = getCurrentUser();
$csrf = generateCSRF();
$phone_columns = [];

if (function_exists('dbh_table_has_column')) {
    if (dbh_table_has_column('users', 'phone')) {
        $phone_columns[] = 'phone';
    }
    if (dbh_table_has_column('users', 'mobile')) {
        $phone_columns[] = 'mobile';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token');
    } else {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $mobile = sanitize($_POST['mobile'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($full_name === '') {
            setFlashMessage('error', 'Full name is required');
        } else {
            try {
                $updates = [];
                $params = [];
                $types = '';

                $updates[] = 'full_name = ?';
                $params[] = $full_name;
                $types .= 's';

                if ($mobile !== '') {
                    if (!validatePhone($mobile)) {
                        setFlashMessage('error', 'Please enter a valid phone number');
                        header('Location: profile.php');
                        exit;
                    }

                    $phone_value = formatPhone($mobile);
                    foreach ($phone_columns as $column) {
                        $updates[] = "{$column} = ?";
                        $params[] = $phone_value;
                        $types .= 's';
                    }
                }

                if ($new_password !== '') {
                    if ($current_password === '') {
                        setFlashMessage('error', 'Current password is required to change password');
                        header('Location: profile.php');
                        exit;
                    }

                    if (!password_verify($current_password, $current['password'])) {
                        setFlashMessage('error', 'Current password is incorrect');
                        header('Location: profile.php');
                        exit;
                    }

                    if ($new_password !== $confirm_password) {
                        setFlashMessage('error', 'New passwords do not match');
                        header('Location: profile.php');
                        exit;
                    }

                    if (strlen($new_password) < 6) {
                        setFlashMessage('error', 'Password must be at least 6 characters');
                        header('Location: profile.php');
                        exit;
                    }

                    $updates[] = 'password = ?';
                    $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                    $types .= 's';
                }

                $params[] = $current['id'];
                $types .= 'i';

                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);

                if ($stmt->execute()) {
                    setFlashMessage('success', 'Profile updated successfully');
                    logActivity($current['id'], 'profile_update', 'Admin profile updated');
                } else {
                    setFlashMessage('error', 'Failed to update profile');
                }
            } catch (Exception $e) {
                error_log('Profile update error: ' . $e->getMessage());
                setFlashMessage('error', 'An error occurred while updating profile');
            }
        }
    }

    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_email_change'])) {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token');
    } else {
        $new_email = sanitize($_POST['new_email'] ?? '');
        $result = createEmailChangeRequest((int) $current['id'], $new_email);
        setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
    }

    header('Location: profile.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $current['id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_phone = '';
foreach ($phone_columns as $column) {
    if (!empty($user[$column])) {
        $user_phone = (string) $user[$column];
        break;
    }
}

$pending_email_request = null;
ensureEmailChangeRequestsTable();
$pending_stmt = $db->prepare("SELECT requested_email, created_at FROM email_change_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
if ($pending_stmt) {
    $pending_stmt->bind_param('i', $current['id']);
    $pending_stmt->execute();
    $pending_email_request = $pending_stmt->get_result()->fetch_assoc();
    $pending_stmt->close();
}

$pageTitle = 'Profile';

require_once '../includes/admin_header.php';
?>
<style>
    .profile-shell {
        max-width: 1120px;
        margin: 0 auto;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.85fr);
        gap: 1.5rem;
        align-items: start;
    }

    .profile-stack {
        display: grid;
        gap: 1.25rem;
    }

    .profile-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 1.1rem;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .profile-card .card-header {
        padding: 1.15rem 1.35rem 1rem;
        border-bottom: 1px solid var(--border-color);
        background: linear-gradient(180deg, rgba(99, 102, 241, 0.08), transparent);
    }

    .profile-card .card-header h3,
    .profile-card .card-header h5 {
        margin: 0;
        color: var(--text-primary);
    }

    .profile-card .card-body {
        padding: 1.35rem;
    }

    .profile-summary {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 1rem;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .profile-summary-avatar {
        width: 64px;
        height: 64px;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.45rem;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        box-shadow: 0 16px 28px rgba(99, 102, 241, 0.24);
    }

    .profile-summary h2 {
        margin: 0;
        font-size: 1.45rem;
        color: var(--text-primary);
    }

    .profile-summary p {
        margin: 0.22rem 0 0;
        color: var(--text-secondary);
    }

    .profile-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.7rem;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        background: rgba(99, 102, 241, 0.12);
        color: var(--brand-primary);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .profile-meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .profile-meta-item {
        border: 1px solid var(--border-color);
        border-radius: 0.95rem;
        background: var(--bg-secondary);
        padding: 0.95rem 1rem;
    }

    .profile-meta-item .label {
        display: block;
        margin-bottom: 0.35rem;
        color: var(--text-muted);
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .profile-meta-item .value {
        color: var(--text-primary);
        font-size: 0.96rem;
        font-weight: 600;
        word-break: break-word;
    }

    .profile-section-title {
        margin: 1.5rem 0 0.35rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .profile-section-copy {
        margin: 0 0 1rem;
        color: var(--text-secondary);
        font-size: 0.92rem;
        line-height: 1.55;
    }

    .profile-form {
        display: grid;
        gap: 1rem;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .profile-form .form-group label {
        display: block;
        margin-bottom: 0.45rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .profile-form .form-control {
        width: 100%;
        min-height: 46px;
        border-radius: 0.85rem;
        border: 1px solid var(--border-color);
        background: var(--bg-primary);
        color: var(--text-primary);
        padding: 0.75rem 0.95rem;
    }

    .profile-form .form-control:disabled {
        background: var(--bg-secondary);
        color: var(--text-secondary);
    }

    .profile-form .form-text,
    .profile-form .text-muted {
        color: var(--text-secondary) !important;
        font-size: 0.84rem;
    }

    .password-input-wrapper {
        position: relative;
    }

    .password-input-wrapper .form-control {
        padding-right: 3rem;
    }

    .password-toggle {
        position: absolute;
        top: 50%;
        right: 0.7rem;
        transform: translateY(-50%);
        width: 36px;
        height: 36px;
        border: 1px solid var(--border-color);
        border-radius: 999px;
        background: var(--bg-secondary);
        color: var(--text-secondary);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: color 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    }

    .password-toggle:hover,
    .password-toggle:focus-visible {
        color: var(--brand-primary);
        border-color: var(--brand-primary);
        background: var(--bg-tertiary);
        outline: none;
    }

    .form-actions,
    .danger-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 0.25rem;
    }

    .form-actions .btn,
    .danger-actions .btn,
    .modal-footer .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .profile-request-card {
        background: linear-gradient(180deg, rgba(99, 102, 241, 0.08), rgba(99, 102, 241, 0.02));
    }

    .danger-card {
        border-color: rgba(239, 68, 68, 0.26);
    }

    .danger-card .card-header {
        background: linear-gradient(180deg, rgba(239, 68, 68, 0.13), transparent);
        border-bottom-color: rgba(239, 68, 68, 0.18);
    }

    .danger-card-title {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        color: #dc2626;
    }

    .danger-card h6 {
        margin-bottom: 0.5rem;
        color: #dc2626;
    }

    .modal-content {
        background: var(--bg-primary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: 1rem;
        box-shadow: 0 28px 60px rgba(15, 23, 42, 0.28);
    }

    .modal-header,
    .modal-footer {
        border-color: var(--border-color) !important;
    }

    .modal-header .modal-title {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        color: #dc2626 !important;
    }

    .modal-body ul {
        padding-left: 1.1rem;
        color: var(--text-secondary);
    }

    .modal-body label {
        color: var(--text-primary);
        font-weight: 600;
    }

    @media (max-width: 980px) {
        .profile-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .profile-meta-grid,
        .form-row {
            grid-template-columns: 1fr;
        }

        .profile-card .card-header,
        .profile-card .card-body {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .profile-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-title">
    <h1>Profile Settings</h1>
    <p class="page-subtitle">Manage your account information, security settings, and account recovery details.</p>
</div>

<div class="profile-shell">
    <?php if (hasFlashMessage()): ?>
        <?php $flash = getFlashMessage(); ?>
        <?php if ($flash && isset($flash['type']) && isset($flash['message'])): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="profile-grid">
        <section class="profile-card">
            <div class="card-header">
                <div class="profile-summary">
                    <div class="profile-summary-avatar"><?php echo strtoupper(substr((string) ($user['full_name'] ?? 'A'), 0, 1)); ?></div>
                    <div>
                        <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                        <p>Keep your admin account details current and your password secure.</p>
                        <span class="profile-badge">
                            <i class="fas fa-shield-alt"></i>
                            Administrator
                        </span>
                    </div>
                </div>

                <div class="profile-meta-grid">
                    <div class="profile-meta-item">
                        <span class="label">Email Address</span>
                        <span class="value"><?php echo htmlspecialchars((string) ($user['email'] ?? 'N/A')); ?></span>
                    </div>
                    <div class="profile-meta-item">
                        <span class="label">Phone Number</span>
                        <span class="value"><?php echo htmlspecialchars($user_phone !== '' ? $user_phone : 'Not set'); ?></span>
                    </div>
                    <div class="profile-meta-item">
                        <span class="label">Role</span>
                        <span class="value"><?php echo htmlspecialchars(ucfirst((string) ($user['role'] ?? 'admin'))); ?></span>
                    </div>
                    <div class="profile-meta-item">
                        <span class="label">Pending Email Request</span>
                        <span class="value"><?php echo htmlspecialchars($pending_email_request['requested_email'] ?? 'None'); ?></span>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <form method="POST" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">

                    <div class="profile-section-title">Personal Information</div>
                    <p class="profile-section-copy">Update the core details used across your admin account.</p>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                            <small class="form-text">Email changes require admin approval.</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="mobile">Phone Number</label>
                            <input type="tel" id="mobile" name="mobile" class="form-control" value="<?php echo htmlspecialchars($user_phone); ?>" placeholder="e.g., +233245152060">
                        </div>
                        <div class="form-group">
                            <label for="role">Role</label>
                            <input type="text" id="role" class="form-control" value="<?php echo htmlspecialchars(ucfirst((string) $user['role'])); ?>" disabled>
                        </div>
                    </div>

                    <div class="profile-section-title">Change Password</div>
                    <p class="profile-section-copy">Leave these fields empty if you do not want to change your password.</p>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="current_password" name="current_password" class="form-control">
                                <button type="button" class="password-toggle" data-target="current_password" aria-label="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="new_password" name="new_password" class="form-control" minlength="6">
                                <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="6">
                                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <span>Update Profile</span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <aside class="profile-stack">
            <section class="profile-card profile-request-card">
                <div class="card-header">
                    <h3>Change Email</h3>
                </div>
                <div class="card-body">
                    <p class="profile-section-copy">Submit a new email address. Your current email remains active until an admin approves the request.</p>

                    <?php if ($pending_email_request): ?>
                        <div class="alert alert-warning">
                            Pending request: <?php echo htmlspecialchars($pending_email_request['requested_email']); ?>
                            <?php if (!empty($pending_email_request['created_at'])): ?>
                                <span class="text-muted"> (<?php echo date('M j, Y H:i', strtotime($pending_email_request['created_at'])); ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                        <div class="form-group">
                            <label for="new_email">New Email Address</label>
                            <input type="email" id="new_email" name="new_email" class="form-control" placeholder="e.g., newemail@example.com" required>
                            <small class="form-text">An approval request will be sent for review.</small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="request_email_change" class="btn btn-secondary">
                                <i class="fas fa-envelope"></i>
                                <span>Request Email Change</span>
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="profile-card danger-card">
                <div class="card-header">
                    <h5 class="danger-card-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Danger Zone</span>
                    </h5>
                </div>
                <div class="card-body">
                    <h6>Delete Account</h6>
                    <p class="profile-section-copy">Once you delete your account, there is no going back. This action cannot be undone.</p>
                    <div class="danger-actions">
                        <button type="button" class="btn btn-danger" onclick="showDeleteAccountModal()">
                            <i class="fas fa-trash"></i>
                            <span>Delete Account</span>
                        </button>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<div class="modal fade" id="deleteAccountModal" tabindex="-1" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Delete Account</span>
                </h5>
                <button type="button" class="btn-close" onclick="hideDeleteAccountModal()"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <strong>Warning:</strong> This action is permanent and cannot be undone.
                </div>
                <p>Deleting your account will:</p>
                <ul>
                    <li>Permanently delete your profile and settings.</li>
                    <li>Remove all your data from the system.</li>
                    <li>Cancel any pending transactions.</li>
                    <li>Transfer remaining wallet balance to system.</li>
                </ul>
                <form id="deleteAccountForm">
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="deletePassword">Enter your password to confirm</label>
                        <div class="password-input-wrapper">
                            <input type="password" class="form-control" id="deletePassword" required>
                            <button type="button" class="password-toggle" data-target="deletePassword" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="deleteConfirmation">Type <strong>DELETE</strong> to confirm</label>
                        <input type="text" class="form-control" id="deleteConfirmation" placeholder="DELETE" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteAccountModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="deleteAccount()" id="deleteAccountBtn">
                    <i class="fas fa-trash"></i>
                    <span>Delete My Account</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const confirmPassword = document.getElementById('confirm_password');
        if (confirmPassword) {
            confirmPassword.addEventListener('input', function () {
                const newPassword = document.getElementById('new_password').value;
                const confirmValue = this.value;

                if (newPassword && confirmValue && newPassword !== confirmValue) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
        }
    });

    function showDeleteAccountModal() {
        const modal = document.getElementById('deleteAccountModal');
        if (!modal) {
            return;
        }

        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function hideDeleteAccountModal() {
        const modal = document.getElementById('deleteAccountModal');
        const form = document.getElementById('deleteAccountForm');
        if (!modal) {
            return;
        }

        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        if (form) {
            form.reset();
        }
    }

    async function deleteAccount() {
        const password = document.getElementById('deletePassword').value;
        const confirmation = document.getElementById('deleteConfirmation').value;
        const deleteBtn = document.getElementById('deleteAccountBtn');

        if (!password) {
            alert('Please enter your password');
            return;
        }

        if (confirmation !== 'DELETE') {
            alert('Please type DELETE to confirm');
            return;
        }

        if (!confirm('Are you absolutely sure? This action cannot be undone.')) {
            return;
        }

        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Deleting...</span>';

        try {
            const response = await fetch('../api/delete_account.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
                },
                body: JSON.stringify({
                    password: password,
                    confirmation: confirmation
                })
            });

            const data = await response.json();

            if (data.status === 'success') {
                alert('Account deleted successfully. You will be redirected to the login page.');
                window.location.href = data.redirect || '<?php echo SITE_URL; ?>/login.php';
                return;
            }

            alert(data.message || 'Failed to delete account');
        } catch (error) {
            alert('An error occurred. Please try again.');
        }

        deleteBtn.disabled = false;
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i><span>Delete My Account</span>';
    }
</script>
<?php require_once '../includes/admin_footer.php'; ?>
