<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_pwa') {
            $app_name = sanitize($_POST['app_name'] ?? '');
            $app_short_name = sanitize($_POST['app_short_name'] ?? '');
            $app_description = sanitize($_POST['app_description'] ?? '');
            $theme_color = sanitize($_POST['theme_color'] ?? '#541388');
            $background_color = sanitize($_POST['background_color'] ?? '#F1E9DA');
            $display_mode = sanitize($_POST['display_mode'] ?? 'standalone');
            $orientation = sanitize($_POST['orientation'] ?? 'portrait');
            
            if (empty($app_name) || empty($app_short_name)) {
                $error = 'App name and short name are required.';
            } else {
                // Update PWA settings
                $stmt = $db->prepare("
                    UPDATE pwa_settings SET 
                    app_name = ?, app_short_name = ?, app_description = ?, 
                    theme_color = ?, background_color = ?, display_mode = ?, orientation = ?
                    WHERE id = 1
                ");
                $stmt->bind_param("sssssss", $app_name, $app_short_name, $app_description, 
                                $theme_color, $background_color, $display_mode, $orientation);
                
                if ($stmt->execute()) {
                    $success = 'PWA settings updated successfully!';
                } else {
                    $error = 'Failed to update PWA settings.';
                }
            }
        } elseif ($action === 'upload_icon') {
            $icon_type = $_POST['icon_type'] ?? '';
            
            if (!in_array($icon_type, ['192', '512'])) {
                $error = 'Invalid icon type.';
            } elseif (!isset($_FILES['icon']) || $_FILES['icon']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Please select an icon file to upload.';
            } else {
                $file = $_FILES['icon'];
                $allowed_types = ['image/png', 'image/jpeg', 'image/webp'];
                $max_size = 1 * 1024 * 1024; // 1MB
                
                if (!in_array($file['type'], $allowed_types)) {
                    $error = 'Invalid file type. Please upload PNG, JPG, or WebP images only.';
                } elseif ($file['size'] > $max_size) {
                    $error = 'File too large. Maximum size is 1MB.';
                } else {
                    // Create uploads directory if it doesn't exist
                    $upload_dir = '../uploads/pwa/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generate filename
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'icon-' . $icon_type . 'x' . $icon_type . '.' . $extension;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $filepath)) {
                        // Update database
                        $column = 'icon_' . $icon_type;
                        $stmt = $db->prepare("UPDATE pwa_settings SET {$column} = ? WHERE id = 1");
                        $stmt->bind_param("s", $filename);
                        
                        if ($stmt->execute()) {
                            $success = "Icon {$icon_type}x{$icon_type} uploaded successfully!";
                        } else {
                            $error = 'Failed to save icon to database.';
                            unlink($filepath);
                        }
                    } else {
                        $error = 'Failed to upload icon. Please try again.';
                    }
                }
            }
        } elseif ($action === 'remove_icon') {
            $icon_type = $_POST['icon_type'] ?? '';
            
            if (in_array($icon_type, ['192', '512'])) {
                // Get current icon filename
                $column = 'icon_' . $icon_type;
                $stmt = $db->prepare("SELECT {$column} FROM pwa_settings WHERE id = 1");
                $stmt->execute();
                $result = $stmt->get_result();
                $settings = $result->fetch_assoc();
                
                if (!empty($settings[$column])) {
                    $icon_path = '../uploads/pwa/' . $settings[$column];
                    if (file_exists($icon_path)) {
                        unlink($icon_path);
                    }
                    
                    // Remove from database
                    $stmt = $db->prepare("UPDATE pwa_settings SET {$column} = NULL WHERE id = 1");
                    if ($stmt->execute()) {
                        $success = "Icon {$icon_type}x{$icon_type} removed successfully!";
                    } else {
                        $error = 'Failed to remove icon from database.';
                    }
                }
            }
        }
    }
}

// Get current PWA settings
$stmt = $db->prepare("SELECT * FROM pwa_settings WHERE id = 1 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$pwa = $result->fetch_assoc();

if (!$pwa) {
    // Create default settings
    $stmt = $db->prepare("
        INSERT INTO pwa_settings (id, app_name, app_short_name, app_description) 
        VALUES (1, 'Constechzhub', 'CTZH', 'Affordable data bundles for all networks')
    ");
    $stmt->execute();
    
    $pwa = [
        'app_name' => 'Constechzhub',
        'app_short_name' => 'CTZH',
        'app_description' => 'Affordable data bundles for all networks',
        'theme_color' => '#541388',
        'background_color' => '#F1E9DA',
        'display_mode' => 'standalone',
        'orientation' => 'portrait',
        'icon_192' => null,
        'icon_512' => null
    ];
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PWA Settings - <?php echo SITE_NAME; ?></title>
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
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
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
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
                <div class="nav-item"><a href="pwa-settings.php" class="nav-link active"><i class="fas fa-mobile-alt"></i> PWA Settings</a></div>
                <div class="nav-item"><a href="sms-settings.php" class="nav-link"><i class="fas fa-sms"></i> SMS Settings</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-mobile-alt"></i></div>
                    <div class="breadcrumb-item">Settings</div>
                    <div class="breadcrumb-item active">PWA Settings</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
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

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Progressive Web App Settings</h1>
                <p class="page-subtitle">Configure your app's PWA settings and icons for mobile installation.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- PWA Basic Settings -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">App Information</h3>
                    <p class="widget-subtitle">Configure basic app details that appear when users install your PWA.</p>
                </div>
                <div class="widget-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_pwa">
                        
                        <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="app_name" class="form-label">App Name</label>
                                <input type="text" id="app_name" name="app_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($pwa['app_name']); ?>" required>
                                <div class="form-help">Full name displayed on app stores and installation prompts.</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="app_short_name" class="form-label">Short Name</label>
                                <input type="text" id="app_short_name" name="app_short_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($pwa['app_short_name']); ?>" maxlength="12" required>
                                <div class="form-help">Short name for home screen (max 12 characters).</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="app_description" class="form-label">Description</label>
                            <textarea id="app_description" name="app_description" class="form-control" rows="3"><?php echo htmlspecialchars($pwa['app_description']); ?></textarea>
                            <div class="form-help">Brief description of your app's purpose.</div>
                        </div>
                        
                        <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="theme_color" class="form-label">Theme Color</label>
                                <input type="color" id="theme_color" name="theme_color" class="form-control" 
                                       value="<?php echo htmlspecialchars($pwa['theme_color']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="background_color" class="form-label">Background Color</label>
                                <input type="color" id="background_color" name="background_color" class="form-control" 
                                       value="<?php echo htmlspecialchars($pwa['background_color']); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="display_mode" class="form-label">Display Mode</label>
                                <select id="display_mode" name="display_mode" class="form-control">
                                    <option value="standalone" <?php echo $pwa['display_mode'] === 'standalone' ? 'selected' : ''; ?>>Standalone</option>
                                    <option value="fullscreen" <?php echo $pwa['display_mode'] === 'fullscreen' ? 'selected' : ''; ?>>Fullscreen</option>
                                    <option value="minimal-ui" <?php echo $pwa['display_mode'] === 'minimal-ui' ? 'selected' : ''; ?>>Minimal UI</option>
                                    <option value="browser" <?php echo $pwa['display_mode'] === 'browser' ? 'selected' : ''; ?>>Browser</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="orientation" class="form-label">Orientation</label>
                                <select id="orientation" name="orientation" class="form-control">
                                    <option value="portrait" <?php echo $pwa['orientation'] === 'portrait' ? 'selected' : ''; ?>>Portrait</option>
                                    <option value="landscape" <?php echo $pwa['orientation'] === 'landscape' ? 'selected' : ''; ?>>Landscape</option>
                                    <option value="any" <?php echo $pwa['orientation'] === 'any' ? 'selected' : ''; ?>>Any</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update PWA Settings
                        </button>
                    </form>
                </div>
            </div>

            <!-- PWA Icons -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">App Icons</h3>
                    <p class="widget-subtitle">Upload custom icons for your PWA. Recommended: PNG format with transparent background.</p>
                </div>
                <div class="widget-content">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <!-- 192x192 Icon -->
                        <div class="icon-upload-section">
                            <h4>192x192 Icon</h4>
                            <div style="margin-bottom: 1rem;">
                                <div style="width: 120px; height: 120px; border: 2px dashed var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary); margin: 0 auto;">
                                    <?php if (!empty($pwa['icon_192'])): ?>
                                        <img src="../uploads/pwa/<?php echo htmlspecialchars($pwa['icon_192']); ?>" 
                                             alt="192x192 Icon" 
                                             style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px;">
                                    <?php else: ?>
                                        <div style="text-align: center; color: var(--text-muted);">
                                            <i class="fas fa-image" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                            <div>No icon</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <form method="post" enctype="multipart/form-data" style="margin-bottom: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="upload_icon">
                                <input type="hidden" name="icon_type" value="192">
                                
                                <div class="form-group">
                                    <input type="file" name="icon" class="form-control" accept="image/*" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-upload"></i> Upload 192x192
                                </button>
                            </form>

                            <?php if (!empty($pwa['icon_192'])): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="remove_icon">
                                    <input type="hidden" name="icon_type" value="192">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this icon?')">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- 512x512 Icon -->
                        <div class="icon-upload-section">
                            <h4>512x512 Icon</h4>
                            <div style="margin-bottom: 1rem;">
                                <div style="width: 120px; height: 120px; border: 2px dashed var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--bg-secondary); margin: 0 auto;">
                                    <?php if (!empty($pwa['icon_512'])): ?>
                                        <img src="../uploads/pwa/<?php echo htmlspecialchars($pwa['icon_512']); ?>" 
                                             alt="512x512 Icon" 
                                             style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px;">
                                    <?php else: ?>
                                        <div style="text-align: center; color: var(--text-muted);">
                                            <i class="fas fa-image" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                            <div>No icon</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <form method="post" enctype="multipart/form-data" style="margin-bottom: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="upload_icon">
                                <input type="hidden" name="icon_type" value="512">
                                
                                <div class="form-group">
                                    <input type="file" name="icon" class="form-control" accept="image/*" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-upload"></i> Upload 512x512
                                </button>
                            </form>

                            <?php if (!empty($pwa['icon_512'])): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="remove_icon">
                                    <input type="hidden" name="icon_type" value="512">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this icon?')">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; padding: 1rem; background: var(--bg-secondary); border-radius: 8px;">
                        <h4 style="margin-bottom: 0.5rem; color: var(--text-primary);">
                            <i class="fas fa-info-circle"></i> Icon Guidelines
                        </h4>
                        <ul style="margin: 0; padding-left: 1.5rem; color: var(--text-secondary);">
                            <li>Use PNG format with transparent background for best results</li>
                            <li>192x192 icon is used for home screen and app launcher</li>
                            <li>512x512 icon is used for splash screen and app stores</li>
                            <li>Icons should be square and clearly visible at small sizes</li>
                            <li>Maximum file size: 1MB per icon</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- PWA Preview -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">PWA Preview</h3>
                    <p class="widget-subtitle">Preview how your PWA will appear to users.</p>
                </div>
                <div class="widget-content">
                    <div style="display: flex; gap: 2rem; align-items: center;">
                        <div style="flex-shrink: 0;">
                            <div style="width: 80px; height: 80px; border-radius: 16px; background: <?php echo htmlspecialchars($pwa['background_color']); ?>; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(46, 41, 78, 0.15);">
                                <?php if (!empty($pwa['icon_192'])): ?>
                                    <img src="../uploads/pwa/<?php echo htmlspecialchars($pwa['icon_192']); ?>" 
                                         alt="App Icon" 
                                         style="width: 64px; height: 64px; object-fit: contain; border-radius: 12px;">
                                <?php else: ?>
                                    <i class="fas fa-mobile-alt" style="font-size: 2rem; color: <?php echo htmlspecialchars($pwa['theme_color']); ?>;"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <h3 style="margin: 0 0 0.25rem 0; color: var(--text-primary);">
                                <?php echo htmlspecialchars($pwa['app_name']); ?>
                            </h3>
                            <p style="margin: 0 0 0.5rem 0; color: var(--text-secondary); font-size: 0.875rem;">
                                <?php echo htmlspecialchars($pwa['app_description']); ?>
                            </p>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span style="background: <?php echo htmlspecialchars($pwa['theme_color']); ?>; color: #F1E9DA; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                    <?php echo strtoupper($pwa['display_mode']); ?>
                                </span>
                                <span style="color: var(--text-muted); font-size: 0.75rem;">
                                    <?php echo ucfirst($pwa['orientation']); ?> orientation
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
<script>
// Initialize theme
initializeTheme();

// Mobile menu toggle
document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
    document.querySelector('.sidebar').classList.toggle('active');
});

// User dropdown toggle
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!toggle.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>




