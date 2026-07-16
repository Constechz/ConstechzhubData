<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_role'] !== 'admin') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$current_user = getCurrentUser();

// Handle application approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['review_application'])) {
        $app_id = (int)$_POST['app_id'];
        $action = $_POST['action'];
        $admin_notes = trim($_POST['admin_notes']);
        
        if (in_array($action, ['approved', 'rejected'])) {
            $stmt = $db->prepare("UPDATE agent_api_applications SET status = ?, admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $stmt->bind_param('ssii', $action, $admin_notes, $current_user['id'], $app_id);
            
            if ($stmt->execute()) {
                // If approved, generate initial API key
                if ($action === 'approved') {
                    $app_stmt = $db->prepare("SELECT agent_id FROM agent_api_applications WHERE id = ?");
                    $app_stmt->bind_param('i', $app_id);
                    $app_stmt->execute();
                    $app_data = $app_stmt->get_result()->fetch_assoc();
                    
                    if ($app_data) {
                        $api_key = 'dbh_' . bin2hex(random_bytes(24));
                        $api_secret = bin2hex(random_bytes(32));
                        $key_name = 'Default API Key';
                        
                        $key_stmt = $db->prepare("INSERT INTO agent_api_keys (agent_id, application_id, api_key, api_secret, key_name) VALUES (?, ?, ?, ?, ?)");
                        $key_stmt->bind_param('iisss', $app_data['agent_id'], $app_id, $api_key, $api_secret, $key_name);
                        $key_stmt->execute();
                    }
                }
                
                setFlashMessage('success', 'Application ' . $action . ' successfully.');
            } else {
                setFlashMessage('error', 'Failed to update application status.');
            }
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get all API applications
$applications_stmt = $db->prepare("
    SELECT aa.*, u.full_name as agent_name, u.email as agent_email,
           reviewer.full_name as reviewed_by_name
    FROM agent_api_applications aa 
    JOIN users u ON aa.agent_id = u.id 
    LEFT JOIN users reviewer ON aa.reviewed_by = reviewer.id 
    ORDER BY 
        CASE aa.status 
            WHEN 'pending' THEN 1 
            WHEN 'approved' THEN 2 
            WHEN 'rejected' THEN 3 
            WHEN 'suspended' THEN 4 
        END,
        aa.applied_at DESC
");
$applications_stmt->execute();
$applications = $applications_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_applications,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) as approved_count,
        COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected_count
    FROM agent_api_applications
";
$stats = $db->query($stats_query)->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Applications - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-brand">
                <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
            </div>
            
                        <?php renderAdminSidebar(); ?>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <div class="breadcrumb-item">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="breadcrumb-item">Settings</div>
                        <div class="breadcrumb-item active">API Applications</div>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>
                        
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="settings.php" class="dropdown-item">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                            <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                            <a href="../logout.php" class="dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="page-title">
                    <h1>API Applications</h1>
                    <p class="page-subtitle">Manage agent API access requests and applications</p>
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

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['total_applications'] ?? 0); ?></h3>
                            <p>Total Applications</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['pending_review'] ?? 0); ?></h3>
                            <p>Pending Review</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['approved'] ?? 0); ?></h3>
                            <p>Approved</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-content">
                            <h3><?php echo number_format($stats['rejected'] ?? 0); ?></h3>
                            <p>Rejected</p>
                        </div>
                    </div>
                </div>

                <!-- Applications List -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Applications</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <p>No API applications found.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Agent</th>
                                            <th>Business Name</th>
                                            <th>Expected Volume</th>
                                            <th>Status</th>
                                            <th>Applied Date</th>
                                            <th>Reviewed Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($app['agent_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($app['agent_email']); ?></small>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($app['business_name']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $app['expected_volume']; ?>">
                                                        <?php echo ucfirst($app['expected_volume']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $app['status']; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($app['applied_at'])); ?></td>
                                                <td>
                                                    <?php if ($app['reviewed_at']): ?>
                                                        <?php echo date('M j, Y', strtotime($app['reviewed_at'])); ?><br>
                                                        <small class="text-muted">by <?php echo htmlspecialchars($app['reviewed_by_name']); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline" id="viewBtn_<?php echo $app['id']; ?>" data-app-id="<?php echo $app['id']; ?>">View</button>
                                                    <?php if ($app['status'] === 'pending'): ?>
                                                        <button type="button" class="btn btn-sm btn-success" id="approveBtn_<?php echo $app['id']; ?>" data-app-id="<?php echo $app['id']; ?>" data-action="approve">Approve</button>
                                                        <button type="button" class="btn btn-sm btn-danger" id="rejectBtn_<?php echo $app['id']; ?>" data-app-id="<?php echo $app['id']; ?>" data-action="reject">Reject</button>
                                                    <?php endif; ?>
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
        </div>
    </main>
</div>

<script>
    function viewApplication(appId) {
        // Load application details via AJAX
        fetch(`<?php echo SITE_URL; ?>/api/admin/get_application.php?id=${appId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('applicationDetails').innerHTML = data.html;
                    document.getElementById('applicationModal').style.display = 'block';
                } else {
                    alert('Failed to load application details');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to load application details');
            });
    }
    
    function reviewApplication(appId, action) {
        document.getElementById('review_app_id').value = appId;
        document.getElementById('review_action').value = action + 'd';
        document.getElementById('reviewTitle').textContent = action.charAt(0).toUpperCase() + action.slice(1) + ' Application';
        
        const submitBtn = document.getElementById('reviewSubmitBtn');
        if (action === 'approve') {
            submitBtn.className = 'btn btn-success';
            submitBtn.textContent = 'Approve Application';
        } else {
            submitBtn.className = 'btn btn-danger';
            submitBtn.textContent = 'Reject Application';
        }
        
        document.getElementById('reviewModal').style.display = 'block';
    }
    
    function hideModal() {
        document.getElementById('applicationModal').style.display = 'none';
    }
    
    function hideReviewModal() {
        document.getElementById('reviewModal').style.display = 'none';
    }
    
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, setting up event listeners');
        
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }
        
        // Add event listeners to all action buttons
        const viewButtons = document.querySelectorAll('button[id^="viewBtn_"]');
        const approveButtons = document.querySelectorAll('button[id^="approveBtn_"]');
        const rejectButtons = document.querySelectorAll('button[id^="rejectBtn_"]');
        
        console.log('Found buttons:', {
            view: viewButtons.length,
            approve: approveButtons.length,
            reject: rejectButtons.length
        });
        
        // View buttons
        viewButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const appId = this.getAttribute('data-app-id');
                console.log('View button clicked for app:', appId);
                viewApplication(appId);
            });
        });
        
        // Approve buttons
        approveButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const appId = this.getAttribute('data-app-id');
                console.log('Approve button clicked for app:', appId);
                reviewApplication(appId, 'approve');
            });
        });
        
        // Reject buttons
        rejectButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const appId = this.getAttribute('data-app-id');
                console.log('Reject button clicked for app:', appId);
                reviewApplication(appId, 'reject');
            });
        });
        
        // Initialize theme
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon) {
            themeIcon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
        }
    });
    
    // Theme management
    function toggleTheme() {
        const currentTheme = localStorage.getItem('theme') || 'light';
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        
        localStorage.setItem('theme', newTheme);
        document.documentElement.setAttribute('data-theme', newTheme);
        
        const themeIcon = document.getElementById('theme-icon');
        if (themeIcon) {
            themeIcon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
    
    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown) {
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
    }
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const appModal = document.getElementById('applicationModal');
        const reviewModal = document.getElementById('reviewModal');
        
        if (event.target === appModal) {
            hideModal();
        }
        if (event.target === reviewModal) {
            hideReviewModal();
        }
        
        // Close user dropdown when clicking outside
        if (!event.target.closest('.user-dropdown')) {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.style.display = 'none';
            }
        }
    }
</script>

    <!-- Application Details Modal -->
    <div id="applicationModal" class="modal" style="display: none;">
        <div class="modal-content large">
            <div class="modal-header">
                <h3>Application Details</h3>
                <span class="close" onclick="hideModal()">&times;</span>
            </div>
            <div class="modal-body" id="applicationDetails">
                <!-- Content loaded via JavaScript -->
            </div>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="reviewTitle">Review Application</h3>
                <span class="close" onclick="hideReviewModal()">&times;</span>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="review_app_id" name="app_id">
                    <input type="hidden" id="review_action" name="action">
                    
                    <div class="form-group">
                        <label for="admin_notes">Admin Notes</label>
                        <textarea id="admin_notes" name="admin_notes" class="form-control" rows="4" placeholder="Add notes about your decision..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideReviewModal()">Cancel</button>
                    <button type="submit" name="review_application" class="btn" id="reviewSubmitBtn">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function viewApplication(appId) {
            // Load application details via AJAX
            fetch(`<?php echo SITE_URL; ?>/api/admin/get_application.php?id=${appId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('applicationDetails').innerHTML = data.html;
                        const modal = document.getElementById('applicationModal');
                        modal.style.display = 'block';
                        modal.style.position = 'fixed';
                        modal.style.top = '0';
                        modal.style.left = '0';
                        modal.style.width = '100%';
                        modal.style.height = '100%';
                        modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                        modal.style.zIndex = '9999';
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('Failed to load application details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load application details');
                });
        }
        
        function reviewApplication(appId, action) {
            document.getElementById('review_app_id').value = appId;
            document.getElementById('review_action').value = action + 'd';
            document.getElementById('reviewTitle').textContent = action.charAt(0).toUpperCase() + action.slice(1) + ' Application';
            
            const submitBtn = document.getElementById('reviewSubmitBtn');
            if (action === 'approve') {
                submitBtn.className = 'btn btn-success';
                submitBtn.textContent = 'Approve Application';
            } else {
                submitBtn.className = 'btn btn-danger';
                submitBtn.textContent = 'Reject Application';
            }
            
            const modal = document.getElementById('reviewModal');
            modal.style.display = 'block';
            modal.style.position = 'fixed';
            modal.style.top = '0';
            modal.style.left = '0';
            modal.style.width = '100%';
            modal.style.height = '100%';
            modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
            modal.style.zIndex = '9999';
            document.body.style.overflow = 'hidden';
        }
        
        function hideModal() {
            const modal = document.getElementById('applicationModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function hideReviewModal() {
            const modal = document.getElementById('reviewModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const appModal = document.getElementById('applicationModal');
            const reviewModal = document.getElementById('reviewModal');
            
            if (event.target === appModal) {
                hideModal();
            }
            if (event.target === reviewModal) {
                hideReviewModal();
            }
        }
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



