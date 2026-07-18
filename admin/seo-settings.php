<?php
require_once '../config/config.php';
require_once '../includes/seo.php';

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
        // Validate and sanitize form data
        $settings_to_update = [
            'site_name' => trim($_POST['site_name'] ?? ''),
            'site_description' => trim($_POST['site_description'] ?? ''),
            'site_keywords' => trim($_POST['site_keywords'] ?? ''),
            'seo_image' => trim($_POST['seo_image'] ?? ''),
            'site_url' => trim($_POST['site_url'] ?? ''),
            'facebook_app_id' => trim($_POST['facebook_app_id'] ?? ''),
            'twitter_handle' => trim($_POST['twitter_handle'] ?? ''),
            'google_analytics_id' => trim($_POST['google_analytics_id'] ?? ''),
            'google_site_verification' => trim($_POST['google_site_verification'] ?? ''),
            'favicon_url' => trim($_POST['favicon_url'] ?? '')
        ];
        
        // Basic validation
        $validation_errors = [];
        if (empty($settings_to_update['site_name'])) {
            $validation_errors[] = 'Site name is required';
        }
        if (empty($settings_to_update['site_description'])) {
            $validation_errors[] = 'Site description is required';
        }
        if (!empty($settings_to_update['site_url']) && !filter_var($settings_to_update['site_url'], FILTER_VALIDATE_URL)) {
            $validation_errors[] = 'Site URL must be a valid URL';
        }
        
        if (!empty($validation_errors)) {
            $error_message = 'Validation errors: ' . implode(', ', $validation_errors);
        } else {
            $updated_count = 0;
            $failed_updates = [];
            $update_details = [];
            
            foreach ($settings_to_update as $setting_name => $setting_value) {
                $update_result = updateSeoSetting($setting_name, $setting_value);
                
                if ($update_result) {
                    $updated_count++;
                    $update_details[] = "??? {$setting_name}";
                } else {
                    $failed_updates[] = $setting_name;
                    $update_details[] = "??? {$setting_name}";
                }
            }
            
            if ($updated_count > 0) {
                $success_message = "SEO settings updated successfully! ({$updated_count} of " . count($settings_to_update) . " settings updated)";
                if (!empty($failed_updates)) {
                    $success_message .= "\n\nFailed to update: " . implode(', ', $failed_updates);
                    // Log details for debugging
                    error_log("SEO Settings Update Details: " . implode('; ', $update_details));
                }
            } else {
                $error_message = 'Failed to update any SEO settings. Please check the error logs or contact support.';
                if (!empty($failed_updates)) {
                    $error_message .= ' Failed fields: ' . implode(', ', $failed_updates);
                }
                // Log details for debugging
                error_log("SEO Settings Update Failed: " . implode('; ', $update_details));
            }
        }
    }
}

// Get current settings
$seo_settings = getAllSeoSettings();

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
    <title>SEO Settings - Admin Dashboard</title>
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
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .btn-primary {
            background: var(--primary-color);
            color: #F1E9DA;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn {
            display: inline-block;
            text-decoration: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            border: none;
        }
        .btn-secondary {
            background: var(--border-color);
            color: var(--text-primary);
        }
        .btn-secondary:hover {
            background: var(--text-muted);
            color: #F1E9DA;
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
        .preview-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 1rem;
            margin-top: 1rem;
        }
        .preview-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .preview-description {
            color: var(--text-muted);
            font-size: 0.875rem;
            line-height: 1.4;
        }
        .preview-url {
            color: var(--success-color);
            font-size: 0.75rem;
            margin-top: 0.25rem;
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
                    <div class="nav-item"><a href="seo-settings.php" class="nav-link active"><i class="fas fa-globe"></i> SEO Settings</a></div>
                    <div class="nav-item"><a href="smtp-settings.php" class="nav-link"><i class="fas fa-envelope"></i> SMTP Email Settings</a></div>
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
                        <div class="breadcrumb-item active">SEO Settings</div>
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
                <h1><i class="fas fa-search"></i> SEO Settings</h1>
                <p>Configure website SEO meta tags, social media sharing, and search engine optimization settings.</p>
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
                    <h3 class="widget-title">SEO Configuration</h3>
                </div>
                <div class="widget-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="settings-grid">
                            <div>
                                <div class="form-group">
                                    <label for="site_name">Website Name</label>
                                    <input type="text" id="site_name" name="site_name" 
                                           value="<?php echo htmlspecialchars($seo_settings['site_name']['setting_value'] ?? ''); ?>" 
                                           required>
                                    <small>The name of your website, used in title tags and meta data</small>
                                </div>

                                <div class="form-group">
                                    <label for="site_description">Website Description</label>
                                    <textarea id="site_description" name="site_description" required><?php echo htmlspecialchars($seo_settings['site_description']['setting_value'] ?? ''); ?></textarea>
                                    <small>Default meta description for SEO (150-160 characters recommended)</small>
                                </div>

                                <div class="form-group">
                                    <label for="site_keywords">Keywords</label>
                                    <input type="text" id="site_keywords" name="site_keywords" 
                                           value="<?php echo htmlspecialchars($seo_settings['site_keywords']['setting_value'] ?? ''); ?>">
                                    <small>Comma-separated keywords for meta tags</small>
                                </div>

                                <div class="form-group">
                                    <label for="site_url">Website URL</label>
                                    <input type="url" id="site_url" name="site_url" 
                                           value="<?php echo htmlspecialchars($seo_settings['site_url']['setting_value'] ?? ''); ?>" 
                                           required>
                                    <small>Your website's base URL (e.g., https://yourdomain.com)</small>
                                </div>

                                <div class="form-group">
                                    <label for="seo_image">Default SEO Image</label>
                                    <input type="text" id="seo_image" name="seo_image" 
                                           value="<?php echo htmlspecialchars($seo_settings['seo_image']['setting_value'] ?? ''); ?>">
                                    <small>Default image for social media sharing (1200x630px recommended)</small>
                                </div>
                            </div>

                            <div>
                                <div class="form-group">
                                    <label for="facebook_app_id">Facebook App ID</label>
                                    <input type="text" id="facebook_app_id" name="facebook_app_id" 
                                           value="<?php echo htmlspecialchars($seo_settings['facebook_app_id']['setting_value'] ?? ''); ?>">
                                    <small>Facebook App ID for Open Graph integration</small>
                                </div>

                                <div class="form-group">
                                    <label for="twitter_handle">Twitter Handle</label>
                                    <input type="text" id="twitter_handle" name="twitter_handle" 
                                           value="<?php echo htmlspecialchars($seo_settings['twitter_handle']['setting_value'] ?? ''); ?>" 
                                           placeholder="@yourusername">
                                    <small>Your Twitter handle for Twitter Cards</small>
                                </div>

                                <div class="form-group">
                                    <label for="google_analytics_id">Google Analytics ID</label>
                                    <input type="text" id="google_analytics_id" name="google_analytics_id" 
                                           value="<?php echo htmlspecialchars($seo_settings['google_analytics_id']['setting_value'] ?? ''); ?>" 
                                           placeholder="G-XXXXXXXXXX">
                                    <small>Google Analytics tracking ID</small>
                                </div>

                                <div class="form-group">
                                    <label for="google_site_verification">Google Site Verification</label>
                                    <input type="text" id="google_site_verification" name="google_site_verification" 
                                           value="<?php echo htmlspecialchars($seo_settings['google_site_verification']['setting_value'] ?? ''); ?>">
                                    <small>Google Search Console verification code</small>
                                </div>

                                <div class="form-group">
                                    <label for="favicon_url">Favicon URL</label>
                                    <input type="text" id="favicon_url" name="favicon_url" 
                                           value="<?php echo htmlspecialchars($seo_settings['favicon_url']['setting_value'] ?? ''); ?>">
                                    <small>Path to your website favicon</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn-primary" id="save-btn">
                                <i class="fas fa-save"></i> Update SEO Settings
                            </button>
                            <a href="seo-fix.php" class="btn btn-secondary" style="margin-left: 10px;">
                                <i class="fas fa-tools"></i> Diagnostic Tools
                            </a>
                            <button type="button" class="btn btn-secondary" onclick="testSaveFunction()" style="margin-left: 10px;">
                                <i class="fas fa-vial"></i> Test Save Function
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- SEO Preview -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Search Result Preview</h3>
                </div>
                <div class="widget-body">
                    <div class="preview-box">
                        <div class="preview-title" id="preview-title">
                            <?php echo htmlspecialchars($seo_settings['site_name']['setting_value'] ?? 'Constechzhub'); ?>
                        </div>
                        <div class="preview-url" id="preview-url">
                            <?php echo htmlspecialchars($seo_settings['site_url']['setting_value'] ?? 'https://yourdomain.com'); ?>
                        </div>
                        <div class="preview-description" id="preview-description">
                            <?php echo htmlspecialchars($seo_settings['site_description']['setting_value'] ?? 'Your trusted partner for affordable data bundles'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Theme management (same as dashboard)
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
        // User dropdown
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            if (dropdown) dropdown.classList.toggle('show');
        }
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (!dropdown || !toggle) return;
            if (!toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        // Mobile menu toggle
        const toggleBtn = document.querySelector('.mobile-menu-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }
        document.addEventListener('DOMContentLoaded', initTheme);
        
        // Live preview updates
        document.getElementById('site_name').addEventListener('input', function() {
            document.getElementById('preview-title').textContent = this.value || 'Constechzhub';
        });

        document.getElementById('site_url').addEventListener('input', function() {
            document.getElementById('preview-url').textContent = this.value || 'https://yourdomain.com';
        });

        document.getElementById('site_description').addEventListener('input', function() {
            document.getElementById('preview-description').textContent = this.value || 'Your trusted partner for affordable data bundles';
        });
        
        // Form submission enhancement
        document.querySelector('form').addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('save-btn');
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveBtn.disabled = true;
        });
        
        // Test save function
        function testSaveFunction() {
            const testData = {
                test_setting: 'test_value_' + Date.now()
            };
            
            fetch('test-seo-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(testData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('??? Save function is working correctly!');
                } else {
                    alert('??? Save function test failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('??? Test failed: ' + error.message);
            });
        }
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



