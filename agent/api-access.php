<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an agent
if (!isLoggedIn() || $_SESSION['user_role'] !== 'agent') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$current_user = getCurrentUser();
$agent_id = $current_user['id'];

// Handle API application submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_api_access'])) {
    $business_name = trim($_POST['business_name']);
    $business_description = trim($_POST['business_description']);
    $website_url = trim($_POST['website_url']);
    $expected_volume = $_POST['expected_volume'];
    $use_case = trim($_POST['use_case']);
    
    if (empty($business_name) || empty($use_case)) {
        setFlashMessage('error', 'Business name and use case are required.');
    } else {
        // Check if agent already has a pending or approved application
        $check_stmt = $db->prepare("SELECT id, status FROM agent_api_applications WHERE agent_id = ? AND status IN ('pending', 'approved') ORDER BY applied_at DESC LIMIT 1");
        $check_stmt->bind_param('i', $agent_id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            if ($existing['status'] === 'pending') {
                setFlashMessage('warning', 'You already have a pending API access application.');
            } else {
                setFlashMessage('info', 'You already have approved API access. Check your API keys below.');
            }
        } else {
            // Insert new application
            $stmt = $db->prepare("INSERT INTO agent_api_applications (agent_id, business_name, business_description, website_url, expected_volume, use_case) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $agent_id, $business_name, $business_description, $website_url, $expected_volume, $use_case);
            
            if ($stmt->execute()) {
                setFlashMessage('success', 'API access application submitted successfully. Admin will review your request.');
            } else {
                setFlashMessage('error', 'Failed to submit application. Please try again.');
            }
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Get agent's API applications
$applications_stmt = $db->prepare("
    SELECT aa.*, u.full_name as reviewed_by_name 
    FROM agent_api_applications aa 
    LEFT JOIN users u ON aa.reviewed_by = u.id 
    WHERE aa.agent_id = ? 
    ORDER BY aa.applied_at DESC
");
$applications_stmt->bind_param('i', $agent_id);
$applications_stmt->execute();
$applications = $applications_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get agent's API keys (only if approved)
$api_keys = [];
$approved_app = null;
foreach ($applications as $app) {
    if ($app['status'] === 'approved') {
        $approved_app = $app;
        break;
    }
}

if ($approved_app) {
    $keys_stmt = $db->prepare("
        SELECT ak.*, 
               COUNT(aul.id) as total_requests,
               MAX(aul.created_at) as last_request_at
        FROM agent_api_keys ak 
        LEFT JOIN agent_api_usage_logs aul ON ak.id = aul.api_key_id 
        WHERE ak.agent_id = ? AND ak.application_id = ?
        GROUP BY ak.id 
        ORDER BY ak.created_at DESC
    ");
    $keys_stmt->bind_param('ii', $agent_id, $approved_app['id']);
    $keys_stmt->execute();
    $api_keys = $keys_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Access - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/mobile-enhancements.js')); ?>""></script>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-brand">
                <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
            </div>
            
            <ul class="sidebar-nav">
                <li class="nav-section">
                    <div class="nav-section-title">Dashboard</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-home"></i>
                            Dashboard
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Services</div>
                    <div class="nav-item">
                        <a href="at-business.php" class="nav-link">
                            <i class="fas fa-mobile-alt"></i>
                            AT Business
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="mtn-business.php" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        MTN Business
                    </a>
                </div>
                <div class="nav-item">
                    <a href="afa-registration.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        AFA Registration
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php" class="nav-link">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="telecel-business.php" class="nav-link">
                        <i class="fas fa-signal"></i>
                        Telecel Business
                    </a>
                </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Transaction</div>
                    <div class="nav-item">
                        <a href="transactions.php" class="nav-link">
                            <i class="fas fa-money-bill-wave"></i>
                            Transactions
                        </a>
                    </div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link">
                        <i class="fas fa-history"></i>
                        Data Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Operations</div>
                    <div class="nav-item">
                        <a href="customer_topup.php" class="nav-link">
                            <i class="fas fa-user-plus"></i>
                            Customer Top-up
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="support.php" class="nav-link">
                            <i class="fas fa-life-ring"></i>
                            Support
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Business</div>
                    <div class="nav-item">
                        <a href="pricing.php" class="nav-link">
                            <i class="fas fa-tags"></i>
                            Custom Pricing
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Users</div>
                    <div class="nav-item">
                        <a href="customers.php" class="nav-link">
                            <i class="fas fa-user-friends"></i>
                            Customers
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Commission</div>
                    <div class="nav-item">
                        <a href="commission.php" class="nav-link">
                            <i class="fas fa-percentage"></i>
                            Commission
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog"></i>
                            Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="api-access.php" class="nav-link active">
                            <i class="fas fa-key"></i>
                            API Access
                        </a>
                    </div>
                </li>
            </ul>
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
                        <div class="breadcrumb-item active">API Access</div>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>
                        
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="wallet.php" class="dropdown-item">
                                <i class="fas fa-wallet"></i> Wallet
                            </a>
                            <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                            <a href="../logout.php" class="dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </header>

<?php echo renderNotificationSlides('agents'); ?>

            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="page-title">
                    <h1>API Access</h1>
                    <p class="page-subtitle">Apply for API access to integrate our data bundle services</p>
                </div>

                <?php if (hasFlashMessage()): ?>
                    <?php $flash = getFlashMessage(); ?>
                    <?php if ($flash && isset($flash['type']) && isset($flash['message'])): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- API Applications Section -->
                <div class="card">
                    <div class="card-header">
                        <h3>Your API Applications</h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <p>You haven't applied for API access yet.</p>
                                <button type="button" class="btn btn-primary" id="applyButton">Apply for API Access</button>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table mobile-cards-enabled">
                                    <thead>
                                        <tr>
                                            <th>Business Name</th>
                                            <th>Status</th>
                                            <th>Applied Date</th>
                                            <th>Reviewed Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($app['business_name']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $app['status']; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($app['applied_at'])); ?></td>
                                                <td>
                                                    <?php if ($app['reviewed_at']): ?>
                                                        <?php echo date('M j, Y', strtotime($app['reviewed_at'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline" onclick="viewApplication(<?php echo $app['id']; ?>)">View Details</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <?php if (!$approved_app): ?>
                                <div style="margin-top: 1rem;">
                                    <button type="button" class="btn btn-primary" id="applyAgainButton">Apply Again</button>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- API Keys Section (only show if approved) -->
                <?php if ($approved_app): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3>Your API Keys</h3>
                            <button type="button" class="btn btn-primary" onclick="showCreateKeyForm()">Generate New Key</button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($api_keys)): ?>
                                <div class="empty-state">
                                    <p>No API keys generated yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table mobile-cards-enabled">
                                        <thead>
                                            <tr>
                                                <th>Key Name</th>
                                                <th>API Key</th>
                                                <th>Status</th>
                                                <th>Rate Limits</th>
                                                <th>Last Used</th>
                                                <th>Total Requests</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($api_keys as $key): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($key['key_name']); ?></td>
                                                    <td>
                                                        <code class="api-key-display"><?php echo substr($key['api_key'], 0, 8) . '...'; ?></code>
                                                        <button type="button" class="btn btn-sm btn-link" onclick="toggleKeyVisibility('<?php echo $key['id']; ?>', '<?php echo $key['api_key']; ?>')">Show</button>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo $key['is_active'] ? 'approved' : 'suspended'; ?>">
                                                            <?php echo $key['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?php echo number_format($key['rate_limit_per_minute']); ?>/min<br>
                                                            <?php echo number_format($key['rate_limit_per_hour']); ?>/hour<br>
                                                            <?php echo number_format($key['rate_limit_per_day']); ?>/day
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php if ($key['last_used_at']): ?>
                                                            <?php echo date('M j, Y H:i', strtotime($key['last_used_at'])); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo number_format($key['total_requests']); ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline" onclick="viewKeyStats(<?php echo $key['id']; ?>)">Stats</button>
                                                        <?php if ($key['is_active']): ?>
                                                            <button type="button" class="btn btn-sm btn-danger" onclick="deactivateKey(<?php echo $key['id']; ?>)">Deactivate</button>
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

                    <!-- API Documentation Link -->
                    <div class="card">
                        <div class="card-header">
                            <h3>API Documentation</h3>
                        </div>
                        <div class="card-body">
                            <p>Access comprehensive API documentation to integrate our data bundle services into your application.</p>
                            <a href="<?php echo SITE_URL; ?>/api/docs/" class="btn btn-primary" target="_blank">View API Documentation</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Application Form Modal -->
    <div id="applicationModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(46, 41, 78, 0.5); z-index: 9999;">
        <div class="modal-content-wrapper" style="position: relative; background: var(--card-bg, #F1E9DA); color: var(--text-color, #2E294E); margin: 5% auto; padding: 0; width: 90%; max-width: 600px; border-radius: 8px; box-shadow: 0 4px 6px rgba(46, 41, 78, 0.1); max-height: 80vh; overflow-y: auto; border: 1px solid var(--border-color, #F1E9DA);">
            <div class="modal-header">
                <h3>Apply for API Access</h3>
                <span class="close" onclick="hideApplicationForm()">&times;</span>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="business_name">Business Name *</label>
                        <input type="text" id="business_name" name="business_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="business_description">Business Description</label>
                        <textarea id="business_description" name="business_description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="website_url">Website URL</label>
                        <input type="url" id="website_url" name="website_url" class="form-control" placeholder="https://example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="expected_volume">Expected Volume</label>
                        <select id="expected_volume" name="expected_volume" class="form-control">
                            <option value="low">Low (< 1,000 requests/day)</option>
                            <option value="medium" selected>Medium (1,000 - 10,000 requests/day)</option>
                            <option value="high">High (> 10,000 requests/day)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="use_case">Use Case *</label>
                        <textarea id="use_case" name="use_case" class="form-control" rows="4" placeholder="Describe how you plan to use our API..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideApplicationForm()">Cancel</button>
                    <button type="submit" name="apply_api_access" class="btn btn-primary">Submit Application</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* Dark mode styles for modal */
        [data-theme="dark"] .modal-content-wrapper {
            background: var(--card-bg) !important;
            color: var(--text-color) !important;
            border: 1px solid var(--border-color) !important;
        }
        
        [data-theme="dark"] .modal-header {
            background: var(--card-bg);
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        [data-theme="dark"] .modal-body {
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        [data-theme="dark"] .modal-footer {
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
        }
        
        [data-theme="dark"] .form-control {
            background: var(--input-bg, #2E294E);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        [data-theme="dark"] .form-control:focus {
            background: var(--input-bg, #2E294E);
            color: var(--text-color);
            border-color: var(--primary-color, #541388);
            box-shadow: 0 0 0 0.2rem rgba(84, 19, 136, 0.25);
        }
        
        [data-theme="dark"] .close {
            color: var(--text-color);
            opacity: 0.8;
        }
        
        [data-theme="dark"] .close:hover {
            color: var(--text-color);
            opacity: 1;
        }
        
        [data-theme="dark"] label {
            color: var(--text-color);
        }
        
        /* Light mode fallbacks */
        [data-theme="light"] .modal-content-wrapper,
        .modal-content-wrapper {
            background: #F1E9DA !important;
            color: #2E294E !important;
            border: 1px solid #F1E9DA !important;
        }
    </style>

    <script>
        function showApplicationForm() {
            console.log('showApplicationForm called');
            const modal = document.getElementById('applicationModal');
            console.log('Modal element:', modal);
            if (modal) {
                modal.style.display = 'block';
                modal.style.position = 'fixed';
                modal.style.top = '0';
                modal.style.left = '0';
                modal.style.width = '100%';
                modal.style.height = '100%';
                modal.style.backgroundColor = 'rgba(46, 41, 78, 0.5)';
                modal.style.zIndex = '9999';
                document.body.style.overflow = 'hidden';
                
                // Apply theme-aware styling to modal content
                const modalContent = modal.querySelector('.modal-content-wrapper');
                if (modalContent) {
                    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
                    if (currentTheme === 'dark') {
                        modalContent.style.background = 'var(--card-bg)';
                        modalContent.style.color = 'var(--text-color)';
                        modalContent.style.border = '1px solid var(--border-color)';
                    } else {
                        modalContent.style.background = '#F1E9DA';
                        modalContent.style.color = '#2E294E';
                        modalContent.style.border = '1px solid #F1E9DA';
                    }
                }
                
                console.log('Modal display set to:', modal.style.display);
            } else {
                console.error('Modal element not found');
                alert('Modal not found - please refresh the page');
            }
        }
        
        function hideApplicationForm() {
            const modal = document.getElementById('applicationModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
        }
        
        function toggleKeyVisibility(keyId, fullKey) {
            const display = document.querySelector(`tr:has([onclick*="${keyId}"]) .api-key-display`);
            const button = document.querySelector(`tr:has([onclick*="${keyId}"]) .btn-link`);
            
            if (button.textContent === 'Show') {
                display.textContent = fullKey;
                button.textContent = 'Hide';
            } else {
                display.textContent = fullKey.substr(0, 8) + '...';
                button.textContent = 'Show';
            }
        }
        
        function viewApplication(appId) {
            // Implementation for viewing application details
            alert('View application details - ID: ' + appId);
        }
        
        function showCreateKeyForm() {
            // Implementation for creating new API key
            alert('Create new API key form');
        }
        
        function viewKeyStats(keyId) {
            // Implementation for viewing key statistics
            alert('View key statistics - ID: ' + keyId);
        }
        
        function deactivateKey(keyId) {
            if (confirm('Are you sure you want to deactivate this API key?')) {
                // Implementation for deactivating key
                alert('Deactivate key - ID: ' + keyId);
            }
        }
        
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up event listeners'); // Debug log
            
            // Initialize mobile enhancements for tables
            if (typeof MobileEnhancements !== 'undefined') {
                new MobileEnhancements();
            }
            
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
            
            // Initialize theme
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            const themeIcon = document.getElementById('theme-icon');
            if (themeIcon) {
                themeIcon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            // Add event listeners to apply buttons
            const applyButton = document.getElementById('applyButton');
            const applyAgainButton = document.getElementById('applyAgainButton');
            
            if (applyButton) {
                console.log('Found apply button, adding event listener');
                applyButton.addEventListener('click', function(e) {
                    console.log('Apply button clicked');
                    e.preventDefault();
                    showApplicationForm();
                });
            }
            
            if (applyAgainButton) {
                console.log('Found apply again button, adding event listener');
                applyAgainButton.addEventListener('click', function(e) {
                    console.log('Apply again button clicked');
                    e.preventDefault();
                    showApplicationForm();
                });
            }
            
            // Also check for any remaining onclick buttons as fallback
            const onclickButtons = document.querySelectorAll('button[onclick*="showApplicationForm"]');
            console.log('Found onclick buttons:', onclickButtons.length);
            onclickButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    console.log('Onclick button clicked via event listener');
                    e.preventDefault();
                    showApplicationForm();
                });
            });
        });
        
        // Theme management
        function toggleTheme() {
            const currentTheme = localStorage.getItem('theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            localStorage.setItem('theme', newTheme);
            document.documentElement.setAttribute('data-theme', newTheme);
            
            const themeIcon = document.getElementById('theme-icon');
            themeIcon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
        }
        
        // User dropdown
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            }
        }
        
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('applicationModal');
            if (event.target === modal) {
                hideApplicationForm();
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
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

