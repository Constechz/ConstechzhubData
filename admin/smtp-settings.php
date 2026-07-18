<?php
require_once '../config/config.php';
require_once '../includes/email.php';

// Require admin role and get user
requireRole('admin');
$current_user = getCurrentUser();

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'Invalid CSRF token';
    } else {
        if (isset($_POST['test_smtp'])) {
            // Test SMTP connection
            $test_result = testSmtpConnection($_POST['test_email'] ?? null);
            if ($test_result['success']) {
                $success_message = $test_result['message'];
            } else {
                $error_message = $test_result['message'];
            }
        } else {
            // Update SMTP settings
            $settings_to_update = [
                'smtp_host' => ['value' => $_POST['smtp_host'] ?? '', 'encrypted' => false],
                'smtp_port' => ['value' => $_POST['smtp_port'] ?? '587', 'encrypted' => false],
                'smtp_encryption' => ['value' => $_POST['smtp_encryption'] ?? 'tls', 'encrypted' => false],
                'smtp_username' => ['value' => $_POST['smtp_username'] ?? '', 'encrypted' => false],
                'smtp_password' => ['value' => $_POST['smtp_password'] ?? '', 'encrypted' => true],
                'from_email' => ['value' => $_POST['from_email'] ?? '', 'encrypted' => false],
                'from_name' => ['value' => $_POST['from_name'] ?? '', 'encrypted' => false],
                'reply_to_email' => ['value' => $_POST['reply_to_email'] ?? '', 'encrypted' => false],
                'smtp_enabled' => ['value' => isset($_POST['smtp_enabled']) ? 'true' : 'false', 'encrypted' => false],
                'test_email' => ['value' => $_POST['test_email'] ?? '', 'encrypted' => false]
            ];
            
            $updated_count = 0;
            foreach ($settings_to_update as $setting_name => $setting_data) {
                // Skip password update if it's the masked value or empty
                if ($setting_name === 'smtp_password' && ($setting_data['value'] === '????????????????????????' || empty($setting_data['value']))) {
                    continue;
                }
                
                if (updateSmtpSetting($setting_name, $setting_data['value'], $setting_data['encrypted'])) {
                    $updated_count++;
                }
            }
            
            if ($updated_count > 0) {
                $success_message = "SMTP settings updated successfully! ($updated_count settings updated)";
            } else {
                $error_message = 'Failed to update SMTP settings';
            }
        }
    }
}

// Get current settings (show decrypted password for trusted admin view)
$smtp_settings = getAllSmtpSettings(false);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Settings - Admin Dashboard</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            margin-right: 0.5rem;
        }
        .btn-primary {
            background: var(--primary-color);
            color: #F1E9DA;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: #F1E9DA;
        }
        .btn-secondary:hover {
            background: #2E294E;
        }
        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
        }
        .alert-success {
            background: #F1E9DA;
            color: #2E294E;
            border: 1px solid #F1E9DA;
        }
        .alert-error {
            background: #F1E9DA;
            color: #2E294E;
            border: 1px solid #F1E9DA;
        }
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 768px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        .status-enabled {
            background: var(--success-color);
        }
        .status-disabled {
            background: var(--danger-color);
        }
        .test-callout {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: linear-gradient(135deg, rgba(217, 3, 104, 0.08), rgba(84, 19, 136, 0.05));
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin: 0.5rem 0 1.25rem;
        }
        .test-callout h4 {
            margin: 0;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .test-callout p {
            margin: 0.25rem 0 0;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .btn-test {
            padding: 0.85rem 1.35rem;
            font-weight: 600;
            box-shadow: 0 6px 16px rgba(46, 41, 78, 0.08);
            background: var(--primary-color);
            color: #F1E9DA;
            border: 1px solid rgba(46, 41, 78, 0.12);
        }
        .btn-test:hover {
            filter: brightness(0.94);
        }
        .password-input {
            position: relative;
        }
        .password-input input {
            padding-right: 3rem;
        }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 0.75rem;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
            line-height: 1;
        }
        .password-toggle:hover {
            color: var(--text-primary);
        }
        .password-toggle:focus-visible {
            outline: 2px solid var(--primary-color);
            border-radius: 50%;
        }
    </style>
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
                    <div class="nav-section-title">Management</div>
                    <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                    <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
                    <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                    <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                    <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
                
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
                <li class="nav-section">
                    <div class="nav-section-title">Operations</div>
                    <div class="nav-item"><a href="manual_topup.php" class="nav-link"><i class="fas fa-plus-circle"></i> Manual Top-up</a></div>
                    <div class="nav-item"><a href="support.php" class="nav-link"><i class="fas fa-life-ring"></i> Support</a></div>
                </li>
                <li class="nav-section">
                    <div class="nav-section-title">Analytics</div>
                    <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                    <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                    <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
                </li>
                <li class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                    <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
                    <div class="nav-item"><a href="commission-settings.php" class="nav-link"><i class="fas fa-percentage"></i> Commission Settings</a></div>
                    <div class="nav-item"><a href="pwa-settings.php" class="nav-link"><i class="fas fa-mobile-alt"></i> PWA Settings</a></div>
                    <div class="nav-item"><a href="sms-settings.php" class="nav-link"><i class="fas fa-sms"></i> SMS Settings</a></div>
                    <div class="nav-item"><a href="seo-settings.php" class="nav-link"><i class="fas fa-globe"></i> SEO Settings</a></div>
                    <div class="nav-item"><a href="smtp-settings.php" class="nav-link active"><i class="fas fa-envelope"></i> SMTP Email Settings</a></div>
                    <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                </li>
            </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                    <nav class="breadcrumb">
                        <div class="breadcrumb-item"><i class="fas fa-cog"></i></div>
                        <div class="breadcrumb-item">Settings</div>
                        <div class="breadcrumb-item active">SMTP Email Settings</div>
                    </nav>
                </div>
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar"><?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?></div>
                            <div>
                                <div style="font-weight: 500;">&nbsp;<?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                            <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                            <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                            <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content-header">
                <h1><i class="fas fa-envelope"></i> SMTP Email Settings</h1>
                <p>Configure SMTP server settings for sending emails including password resets, order confirmations, and notifications.</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">SMTP Configuration</h3>
                </div>
                <div class="widget-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <div class="test-callout">
                            <div>
                                <h4><i class="fas fa-paper-plane"></i> Quick SMTP Test</h4>
                                <p>Uses the ???Test Email Address??? below (currently: <?php echo htmlspecialchars($smtp_settings['test_email']['setting_value'] ?? 'not set'); ?>). Make sure SMTP is enabled.</p>
                            </div>
                            <button type="submit" name="test_smtp" class="btn btn-secondary btn-test">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="smtp_enabled" name="smtp_enabled" 
                                       <?php echo (($smtp_settings['smtp_enabled']['setting_value'] ?? 'false') === 'true') ? 'checked' : ''; ?>>
                                <label for="smtp_enabled">Enable SMTP Email Sending</label>
                            </div>
                            <small>Enable or disable email sending functionality</small>
                        </div>
                        
                        <div class="settings-grid">
                            <div>
                                <div class="form-group">
                                    <label for="smtp_host">SMTP Host</label>
                                    <input type="text" id="smtp_host" name="smtp_host" 
                                           value="<?php echo htmlspecialchars($smtp_settings['smtp_host']['setting_value'] ?? ''); ?>" 
                                           placeholder="smtp.gmail.com" required>
                                    <small>SMTP server hostname</small>
                                </div>

                                <div class="form-group">
                                    <label for="smtp_port">SMTP Port</label>
                                    <input type="number" id="smtp_port" name="smtp_port" 
                                           value="<?php echo htmlspecialchars($smtp_settings['smtp_port']['setting_value'] ?? '587'); ?>" 
                                           required>
                                    <small>587 for TLS, 465 for SSL, 25 for unencrypted</small>
                                </div>

                                <div class="form-group">
                                    <label for="smtp_encryption">Encryption</label>
                                    <select id="smtp_encryption" name="smtp_encryption" required>
                                        <option value="tls" <?php echo (($smtp_settings['smtp_encryption']['setting_value'] ?? 'tls') === 'tls') ? 'selected' : ''; ?>>TLS</option>
                                        <option value="ssl" <?php echo (($smtp_settings['smtp_encryption']['setting_value'] ?? '') === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                        <option value="none" <?php echo (($smtp_settings['smtp_encryption']['setting_value'] ?? '') === 'none') ? 'selected' : ''; ?>>None</option>
                                    </select>
                                    <small>Encryption method for secure connection</small>
                                </div>

                                <div class="form-group">
                                    <label for="smtp_username">SMTP Username</label>
                                    <input type="text" id="smtp_username" name="smtp_username" 
                                           value="<?php echo htmlspecialchars($smtp_settings['smtp_username']['setting_value'] ?? ''); ?>" 
                                           required>
                                    <small>Usually your email address</small>
                                </div>

                                    <div class="form-group">
                                    <label for="smtp_password">SMTP Password</label>
                                    <div class="password-input password-input-wrapper">
                                        <input type="password" id="smtp_password" name="smtp_password" 
                                               value="<?php echo htmlspecialchars($smtp_settings['smtp_password']['setting_value'] ?? ''); ?>" 
                                               placeholder="Enter password" autocomplete="new-password">
                                        <button type="button" class="password-toggle" data-target="smtp_password" aria-label="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small>Stored password stays hidden until you tap the eye icon &mdash; clear the field and leave it blank if you don&rsquo;t want to change it.</small>
                                </div>
                            </div>

                            <div>
                                <div class="form-group">
                                    <label for="from_email">From Email</label>
                                    <input type="email" id="from_email" name="from_email" 
                                           value="<?php echo htmlspecialchars($smtp_settings['from_email']['setting_value'] ?? ''); ?>" 
                                           required>
                                    <small>Default sender email address</small>
                                </div>

                                <div class="form-group">
                                    <label for="from_name">From Name</label>
                                    <input type="text" id="from_name" name="from_name" 
                                           value="<?php echo htmlspecialchars($smtp_settings['from_name']['setting_value'] ?? ''); ?>" 
                                           required>
                                    <small>Default sender name</small>
                                </div>

                                <div class="form-group">
                                    <label for="reply_to_email">Reply-To Email</label>
                                    <input type="email" id="reply_to_email" name="reply_to_email" 
                                           value="<?php echo htmlspecialchars($smtp_settings['reply_to_email']['setting_value'] ?? ''); ?>">
                                    <small>Email address for replies (optional)</small>
                                </div>

                                <div class="form-group">
                                    <label for="test_email">Test Email Address</label>
                                    <input type="email" id="test_email" name="test_email" 
                                           value="<?php echo htmlspecialchars($smtp_settings['test_email']['setting_value'] ?? ''); ?>">
                                    <small>Email address for testing SMTP configuration</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update SMTP Settings
                            </button>
                            <button type="submit" name="test_smtp" class="btn btn-secondary">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Email Templates Info -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Email Templates</h3>
                </div>
                <div class="widget-body">
                    <p>The following email templates are configured and ready to use:</p>
                    <div class="settings-grid">
                        <div>
                            <h4><i class="fas fa-key"></i> Password Reset</h4>
                            <p>Sent when users request password reset</p>
                            <small>Template: <code>password_reset</code></small>
                        </div>
                        <div>
                            <h4><i class="fas fa-shopping-cart"></i> Order Confirmation</h4>
                            <p>Sent when data bundle orders are completed</p>
                            <small>Template: <code>order_confirmation</code></small>
                        </div>
                        <div>
                            <h4><i class="fas fa-life-ring"></i> Support Ticket</h4>
                            <p>Sent when support tickets are updated</p>
                            <small>Template: <code>support_ticket</code></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMTP Configuration Help -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Common SMTP Providers</h3>
                </div>
                <div class="widget-body">
                    <div class="settings-grid">
                        <div>
                            <h4><i class="fab fa-google"></i> Gmail</h4>
                            <ul style="font-size: 0.875rem; color: var(--text-muted);">
                                <li><strong>Host:</strong> smtp.gmail.com</li>
                                <li><strong>Port:</strong> 587 (TLS) or 465 (SSL)</li>
                                <li><strong>Username:</strong> your-email@gmail.com</li>
                                <li><strong>Password:</strong> App-specific password</li>
                            </ul>
                        </div>
                        <div>
                            <h4><i class="fas fa-envelope"></i> Outlook/Hotmail</h4>
                            <ul style="font-size: 0.875rem; color: var(--text-muted);">
                                <li><strong>Host:</strong> smtp-mail.outlook.com</li>
                                <li><strong>Port:</strong> 587 (TLS)</li>
                                <li><strong>Username:</strong> your-email@outlook.com</li>
                                <li><strong>Password:</strong> Your account password</li>
                            </ul>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: 0.375rem;">
                        <p><strong>Note:</strong> For Gmail, you need to enable 2-factor authentication and generate an app-specific password. Regular passwords won't work.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
    <script>
        // Theme + header interactions (same as dashboard)
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
            updateThemeIcon(theme);
        }
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        }
        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-icon');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) dropdown.classList.toggle('show');
        }
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (!dropdown || !toggle) return;
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
            const toggleBtn = document.querySelector('.mobile-menu-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    document.querySelector('.sidebar').classList.toggle('show');
                });
            }
        });
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



