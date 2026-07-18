<?php
require_once '../config/config.php';

ensureTopupSettingsTable();

// Require agent role
requireRole('agent');
$current_user = getCurrentUser();

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token';
    } else {
        try {
            $db->getConnection()->begin_transaction();
            
            // Update agent topup settings
            $settings = [
                'agent_topup_account_network' => $_POST['account_network'] ?? '',
                'agent_topup_account_name' => $_POST['account_name'] ?? '',
                'agent_topup_account_number' => $_POST['account_number'] ?? '',
                'agent_topup_instructions' => $_POST['instructions'] ?? ''
            ];
            
            foreach ($settings as $key => $value) {
                if (!empty($value)) {
                    $stmt = $db->prepare("INSERT INTO topup_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
                    $stmt->bind_param('iss', $current_user['id'], $key, $value);
                    $stmt->execute();
                }
            }
            
            $db->getConnection()->commit();
            $success_message = 'Payment settings updated successfully!';
        } catch (Exception $e) {
            $db->getConnection()->rollback();
            $error_message = 'Error updating settings: ' . $e->getMessage();
        }
    }
}

// Get current settings
$currentSettings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM topup_settings WHERE user_id = ?");
$stmt->bind_param('i', $current_user['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

// Generate CSRF token
$csrf_token = generateCSRF();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Settings - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="../manifest.php">
    <meta name="theme-color" content="#541388">
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    
    <!-- Enhanced Font Awesome Loading with Multiple CDN Fallbacks -->
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
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
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                </li>
                <li class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog"></i>
                            General Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="payment-settings.php" class="nav-link active">
                            <i class="fas fa-university"></i>
                            Payment Settings
                        </a>
                    </div>
                </li>
            </ul>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <a href="dashboard.php">Dashboard</a>
                        <span class="breadcrumb-separator">/</span>
                        <span class="breadcrumb-item active">Payment Settings</span>
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

<?php echo renderNotificationSlides('agents'); ?>


            <div class="dashboard-content">
                <div class="page-title">
                    <h1>Payment Settings</h1>
                    <p class="page-subtitle">Configure your payment account for customer topup requests</p>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-university"></i>
                            Your Payment Account Details
                        </h3>
                        <p class="widget-subtitle">Set up where customers should send payments for topup requests</p>
                    </div>
                    <div class="widget-body">
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Important:</strong> If you don't configure these settings, the system will use your profile name and phone number as defaults.
                            </div>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="account_network">
                                        <i class="fas fa-network-wired"></i>
                                        Payment Network
                                    </label>
                                    <select id="account_network" name="account_network" class="form-control">
                                        <option value="">Use Default (MTN MOMO)</option>
                                        <option value="MTN MOMO" <?php echo (($currentSettings['agent_topup_account_network'] ?? '') === 'MTN MOMO') ? 'selected' : ''; ?>>MTN Mobile Money</option>
                                        <option value="VODAFONE CASH" <?php echo (($currentSettings['agent_topup_account_network'] ?? '') === 'VODAFONE CASH') ? 'selected' : ''; ?>>Vodafone Cash</option>
                                        <option value="AIRTELTIGO MONEY" <?php echo (($currentSettings['agent_topup_account_network'] ?? '') === 'AIRTELTIGO MONEY') ? 'selected' : ''; ?>>AirtelTigo Money</option>
                                        <option value="BANK TRANSFER" <?php echo (($currentSettings['agent_topup_account_network'] ?? '') === 'BANK TRANSFER') ? 'selected' : ''; ?>>Bank Transfer</option>
                                        <option value="OTHER" <?php echo (($currentSettings['agent_topup_account_network'] ?? '') === 'OTHER') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                    <small class="form-help">Payment method for customer payments</small>
                                </div>

                                <div class="form-group">
                                    <label for="account_name">
                                        <i class="fas fa-user"></i>
                                        Account Name
                                    </label>
                                    <input type="text" id="account_name" name="account_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($currentSettings['agent_topup_account_name'] ?? ''); ?>" 
                                           placeholder="Default: <?php echo htmlspecialchars($current_user['full_name']); ?>">
                                    <small class="form-help">Leave empty to use your profile name</small>
                                </div>

                                <div class="form-group">
                                    <label for="account_number">
                                        <i class="fas fa-phone"></i>
                                        Account Number/Phone
                                    </label>
                                    <input type="text" id="account_number" name="account_number" class="form-control" 
                                           value="<?php echo htmlspecialchars($currentSettings['agent_topup_account_number'] ?? ''); ?>" 
                                           placeholder="Default: <?php echo htmlspecialchars($current_user['phone'] ?? 'N/A'); ?>">
                                    <small class="form-help">Leave empty to use your profile phone number</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="instructions">
                                    <i class="fas fa-info-circle"></i>
                                    Custom Payment Instructions
                                </label>
                                <textarea id="instructions" name="instructions" class="form-control" rows="4" 
                                          placeholder="Enter custom instructions for your customers..."><?php echo htmlspecialchars($currentSettings['agent_topup_instructions'] ?? ''); ?></textarea>
                                <small class="form-help">Optional custom instructions displayed to customers during topup request</small>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    Save Settings
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='settings.php'">
                                    <i class="fas fa-arrow-left"></i>
                                    Back to Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Current Settings Display -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <i class="fas fa-eye"></i>
                            Current Payment Details
                        </h3>
                        <p class="widget-subtitle">This is what customers see when requesting topup from you</p>
                    </div>
                    <div class="widget-body">
                        <div class="payment-details-preview">
                            <h5 style="margin-bottom: 1rem;">Payment Details</h5>
                            <div class="payment-detail-item">
                                <span class="detail-label">Network:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($currentSettings['agent_topup_account_network'] ?? 'MTN MOMO'); ?>
                                </span>
                            </div>
                            <div class="payment-detail-item">
                                <span class="detail-label">Wallet Name:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($currentSettings['agent_topup_account_name'] ?? $current_user['full_name']); ?>
                                </span>
                            </div>
                            <div class="payment-detail-item">
                                <span class="detail-label">Wallet Number:</span>
                                <span class="detail-value">
                                    <?php echo htmlspecialchars($currentSettings['agent_topup_account_number'] ?? $current_user['phone'] ?? 'N/A'); ?>
                                </span>
                            </div>
                            <?php if (!empty($currentSettings['agent_topup_instructions'])): ?>
                            <div class="payment-instructions">
                                <p><strong>Instructions:</strong></p>
                                <p><?php echo nl2br(htmlspecialchars($currentSettings['agent_topup_instructions'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <style>
        .settings-form .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group label i {
            color: var(--primary-color);
            width: 16px;
        }

        .form-help {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
        }

        .form-actions {
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .payment-details-preview {
            background: var(--widget-bg, #F1E9DA);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.5rem;
        }

        .payment-detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .payment-detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-muted);
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-color);
        }

        .payment-instructions {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .payment-instructions strong {
            color: var(--text-color);
        }

        .payment-instructions p {
            color: var(--text-muted);
            margin: 0.5rem 0;
        }

        /* Dark mode enhancements */
        [data-theme="dark"] .payment-details-preview {
            background: var(--widget-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .detail-label {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .detail-value {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .payment-detail-item {
            border-bottom: 1px solid var(--border-color, #2E294E);
        }

        [data-theme="dark"] .payment-instructions {
            border-top: 1px solid var(--border-color, #2E294E);
        }

        [data-theme="dark"] .payment-instructions strong {
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .payment-instructions p {
            color: var(--text-muted, #F1E9DA);
        }

        [data-theme="dark"] .form-actions {
            border-top: 1px solid var(--border-color, #2E294E);
        }

        /* Form styling improvements */
        .form-control {
            background: var(--input-bg, #F1E9DA);
            border: 1px solid var(--border-color, #F1E9DA);
            color: var(--text-color, #2E294E);
            border-radius: 4px;
            padding: 0.75rem;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color, #541388);
            outline: none;
            box-shadow: 0 0 0 3px rgba(84, 19, 136, 0.1);
        }

        [data-theme="dark"] .form-control {
            background: var(--input-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
            color: var(--text-color, #F1E9DA);
        }

        [data-theme="dark"] .form-control:focus {
            border-color: var(--primary-color, #541388);
            box-shadow: 0 0 0 3px rgba(84, 19, 136, 0.2);
        }

        /* Widget styling */
        .widget {
            background: var(--widget-bg, #F1E9DA);
            border: 1px solid var(--border-color, #F1E9DA);
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        [data-theme="dark"] .widget {
            background: var(--widget-bg, #2E294E);
            border: 1px solid var(--border-color, #2E294E);
        }

        .widget-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color, #F1E9DA);
        }

        [data-theme="dark"] .widget-header {
            border-bottom: 1px solid var(--border-color, #2E294E);
        }

        .widget-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color, #2E294E);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        [data-theme="dark"] .widget-title {
            color: var(--text-color, #F1E9DA);
        }

        .widget-subtitle {
            margin: 0.5rem 0 0 0;
            color: var(--text-muted, #541388);
            font-size: 0.875rem;
        }

        [data-theme="dark"] .widget-subtitle {
            color: var(--text-muted, #F1E9DA);
        }

        .widget-body {
            padding: 1.5rem;
        }

        /* Button styling */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary-color, #541388);
            color: #F1E9DA;
        }

        .btn-primary:hover {
            background: var(--primary-hover, #541388);
        }

        .btn-secondary {
            background: var(--secondary-bg, #F1E9DA);
            color: var(--text-color, #2E294E);
            border: 1px solid var(--border-color, #F1E9DA);
        }

        .btn-secondary:hover {
            background: var(--secondary-hover, #F1E9DA);
        }

        [data-theme="dark"] .btn-secondary {
            background: var(--secondary-bg, #2E294E);
            color: var(--text-color, #F1E9DA);
            border: 1px solid var(--border-color, #2E294E);
        }

        [data-theme="dark"] .btn-secondary:hover {
            background: var(--secondary-hover, #2E294E);
        }

        /* Alert styling */
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #F1E9DA;
            color: #2E294E;
            border: 1px solid #F1E9DA;
        }

        .alert-danger {
            background: #F1E9DA;
            color: #D90368;
            border: 1px solid #F1E9DA;
        }

        .alert-info {
            background: #F1E9DA;
            color: #2E294E;
            border: 1px solid #F1E9DA;
        }

        [data-theme="dark"] .alert-success {
            background: #2E294E;
            color: #F1E9DA;
            border: 1px solid #541388;
        }

        [data-theme="dark"] .alert-danger {
            background: #2E294E;
            color: #F1E9DA;
            border: 1px solid #D90368;
        }

        [data-theme="dark"] .alert-info {
            background: #2E294E;
            color: #F1E9DA;
            border: 1px solid #541388;
        }

        /* Page title styling */
        .page-title h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color, #2E294E);
        }

        [data-theme="dark"] .page-title h1 {
            color: var(--text-color, #F1E9DA);
        }

        .page-subtitle {
            margin: 0;
            color: var(--text-muted, #541388);
            font-size: 1rem;
        }

        [data-theme="dark"] .page-subtitle {
            color: var(--text-muted, #F1E9DA);
        }

        /* Form styling */
        .settings-form .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group label i {
            color: var(--primary-color);
            width: 16px;
        }

        .form-help {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
            display: block;
        }

        .form-actions {
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <script>
        // Theme toggle functionality
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            // Update theme icon
            const themeIcon = document.getElementById('theme-icon');
            if (themeIcon) {
                themeIcon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
            }
        }

        // Load saved theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            const themeIcon = document.getElementById('theme-icon');
            if (themeIcon) {
                themeIcon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
            }
            
            // Real-time preview updates
            setupPreviewUpdates();
        });

        // User dropdown toggle
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) {
                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            
            if (dropdown && toggle && !toggle.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });

        // Real-time preview updates
        function setupPreviewUpdates() {
            const accountNetwork = document.getElementById('account_network');
            const accountName = document.getElementById('account_name');
            const accountNumber = document.getElementById('account_number');
            const instructions = document.getElementById('instructions');
            
            function updatePreview() {
                // Note: This would need corresponding preview elements in the HTML
                // For now, just implementing the JavaScript structure
                console.log('Preview updated');
            }
            
            // Add event listeners
            if (accountNetwork) accountNetwork.addEventListener('change', updatePreview);
            if (accountName) accountName.addEventListener('input', updatePreview);
            if (accountNumber) accountNumber.addEventListener('input', updatePreview);
            if (instructions) instructions.addEventListener('input', updatePreview);
            
            // Initial update
            updatePreview();
        }

        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('sidebar-open');
                });
            }
        });
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

