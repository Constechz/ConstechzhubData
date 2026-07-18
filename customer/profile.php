<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
requireLogin();

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

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!validateCSRF($_POST['csrf_token'])) {
        setFlashMessage('error', 'Invalid session token');
    } else {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $mobile = sanitize($_POST['mobile'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($full_name)) {
            setFlashMessage('error', 'Full name is required');
        } else {
            try {
                $updates = [];
                $params = [];
                $types = '';
                
                // Update name and mobile
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
                    if (!empty($phone_columns)) {
                        foreach ($phone_columns as $column) {
                            $updates[] = "{$column} = ?";
                            $params[] = $phone_value;
                            $types .= 's';
                        }
                    }
                }
                
                // Handle password change
                if (!empty($new_password)) {
                    if (empty($current_password)) {
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
                
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Profile updated successfully');
                    logActivity($current['id'], 'profile_update', 'Customer profile updated');
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

// Get updated user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
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
}

// Check if customer has an agent
$agent_info = null;
if ($user['agent_id']) {
    $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'agent'");
    $stmt->bind_param('i', $user['agent_id']);
    $stmt->execute();
    $agent_info = $stmt->get_result()->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Customer - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
<div class="dashboard-wrapper">
    <?php require_once '../includes/customer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="breadcrumb">
                    <span>Customer</span>
                    <i class="fas fa-chevron-right"></i>
                    <span>Profile</span>
                </div>
            </div>
            <div class="header-right">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-moon" id="theme-icon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <i class="fas fa-user-circle"></i>
                        <span><?php echo htmlspecialchars($current['full_name']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="wallet.php" class="dropdown-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <div class="dropdown-divider"></div>
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('customers'); ?>

        
        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <div class="page-title">
                <h1>Profile Settings</h1>
                <p class="page-subtitle">Manage your account information and security settings</p>
            </div>

            <div class="content-area">
                <?php if (hasFlashMessage()): ?>
                    <?php $flash = getFlashMessage(); ?>
                    <?php if ($flash && isset($flash['type']) && isset($flash['message'])): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3>Personal Information</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="profile-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="full_name">Full Name</label>
                                    <input type="text" id="full_name" name="full_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                    <small class="form-text">Email changes require admin approval</small>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="mobile">Phone Number</label>
                                    <input type="tel" id="mobile" name="mobile" class="form-control" 
                                           value="<?php echo htmlspecialchars($user_phone); ?>" 
                                           placeholder="e.g., +233245152060">
                                </div>
                                <div class="form-group">
                                    <label for="role">Account Type</label>
                                    <input type="text" id="role" class="form-control" 
                                           value="<?php echo ucfirst($user['role']); ?>" disabled>
                                </div>
                            </div>
                            
                            <?php if ($agent_info): ?>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="agent_name">Your Agent</label>
                                    <input type="text" id="agent_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($agent_info['full_name']); ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label for="agent_email">Agent Email</label>
                                    <input type="email" id="agent_email" class="form-control" 
                                           value="<?php echo htmlspecialchars($agent_info['email']); ?>" disabled>
                                    <small class="form-text">Contact your agent for support</small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <hr>
                            <h4>Change Password</h4>
                            <p class="text-muted">Leave password fields empty if you don't want to change your password</p>
                            
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
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <div class="password-input-wrapper">
                                        <input type="password" id="new_password" name="new_password" class="form-control" 
                                               minlength="6">
                                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <div class="password-input-wrapper">
                                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                               minlength="6">
                                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
                            </div>
                        </form>

                        <hr>
                        <h4>Change Email (Admin Approval Required)</h4>
                        <p class="text-muted">Submit a new email address. An admin must approve before the change takes effect.</p>

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
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_email">New Email Address</label>
                                    <input type="email" id="new_email" name="new_email" class="form-control" placeholder="e.g., newemail@example.com" required>
                                    <small class="form-text">Your current email remains active until an admin approves the request.</small>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="request_email_change" class="btn btn-secondary">
                                    <i class="fas fa-envelope"></i> Request Email Change
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Delete Account Section -->
            <div class="card" style="margin-top: 2rem; border-color: #D90368;">
                <div class="card-header" style="background-color: #F1E9DA; border-bottom-color: #D90368;">
                    <h5 style="color: #2E294E; margin: 0;"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h5>
                </div>
                <div class="card-body">
                    <h6 style="color: #D90368;">Delete Account</h6>
                    <p style="color: #541388; margin-bottom: 1rem;">Once you delete your account, there is no going back. This will remove all your data and order history.</p>
                    <button type="button" class="btn btn-danger" onclick="showDeleteAccountModal()">
                        <i class="fas fa-trash"></i> Delete Account
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom-color: #D90368;">
                <h5 class="modal-title" style="color: #D90368;"><i class="fas fa-exclamation-triangle"></i> Delete Account</h5>
                <button type="button" class="btn-close" onclick="hideDeleteAccountModal()"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <strong>Warning:</strong> This action is permanent and cannot be undone!
                </div>
                <p>Deleting your account will:</p>
                <ul>
                    <li>Permanently delete your profile and personal data</li>
                    <li>Cancel any pending orders</li>
                    <li>Transfer remaining wallet balance to system</li>
                    <li>Remove your order history</li>
                </ul>
                <form id="deleteAccountForm">
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="deletePassword">Enter your password to confirm:</label>
                        <div class="password-input-wrapper">
                            <input type="password" class="form-control" id="deletePassword" required>
                            <button type="button" class="password-toggle" data-target="deletePassword" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label for="deleteConfirmation">Type <strong>DELETE</strong> to confirm:</label>
                        <input type="text" class="form-control" id="deleteConfirmation" placeholder="DELETE" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideDeleteAccountModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="deleteAccount()" id="deleteAccountBtn">
                    <i class="fas fa-trash"></i> Delete My Account
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
<script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }
        
        // Initialize theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon) {
            themeIcon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
        }
    });
    
    // Theme toggle
    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon) {
            themeIcon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
    
    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        if (!dropdown || !toggle) {
            return;
        }
        dropdown.classList.toggle('show');
        toggle.classList.toggle('open');
    }
    
    // Close dropdown when clicking outside
    window.onclick = function(event) {
        if (!event.target.closest('.user-dropdown')) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (dropdown) {
                dropdown.classList.remove('show');
            }
            if (toggle) {
                toggle.classList.remove('open');
            }
        }
    }
    
    // Password confirmation validation
    document.getElementById('confirm_password').addEventListener('input', function() {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = this.value;
        
        if (newPassword && confirmPassword && newPassword !== confirmPassword) {
            this.setCustomValidity('Passwords do not match');
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Delete Account Functions
    function showDeleteAccountModal() {
        document.getElementById('deleteAccountModal').style.display = 'block';
        document.getElementById('deleteAccountModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function hideDeleteAccountModal() {
        document.getElementById('deleteAccountModal').style.display = 'none';
        document.getElementById('deleteAccountModal').classList.remove('show');
        document.body.style.overflow = 'auto';
        document.getElementById('deleteAccountForm').reset();
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
        
        if (!confirm('Are you absolutely sure? This action cannot be undone!')) {
            return;
        }
        
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        
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
            } else {
                alert(data.message || 'Failed to delete account');
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete My Account';
            }
        } catch (error) {
            alert('An error occurred. Please try again.');
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Delete My Account';
        }
    }
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

