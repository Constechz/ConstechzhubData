<?php
require_once '../config/config.php';
require_once '../includes/commission.php';

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
        
        if ($action === 'update_payout_settings') {
            $payout_schedule = sanitize($_POST['payout_schedule']);
            $minimum_payout = floatval($_POST['minimum_payout']);
            $auto_payout = isset($_POST['auto_payout']) ? 1 : 0;
            $payout_day_month = intval($_POST['payout_day_month']);
            $payout_day_week = intval($_POST['payout_day_week']);
            
            // Validate inputs
            if (!in_array($payout_schedule, ['daily', 'weekly', 'monthly'])) {
                $error = 'Invalid payout schedule selected.';
            } elseif ($minimum_payout < 0 || $minimum_payout > 1000) {
                $error = 'Minimum payout amount must be between 0 and 1000.';
            } elseif ($payout_day_month < 1 || $payout_day_month > 28) {
                $error = 'Payout day of month must be between 1 and 28.';
            } elseif ($payout_day_week < 1 || $payout_day_week > 7) {
                $error = 'Payout day of week must be between 1 and 7.';
            } else {
                // Update settings
                $settings = [
                    'global_payout_schedule' => $payout_schedule,
                    'global_minimum_payout' => strval($minimum_payout),
                    'auto_payout_enabled' => $auto_payout ? 'true' : 'false',
                    'payout_day_of_month' => strval($payout_day_month),
                    'payout_day_of_week' => strval($payout_day_week)
                ];
                
                $db->getConnection()->begin_transaction();
                
                try {
                    foreach ($settings as $name => $value) {
                        $stmt = $db->prepare("
                            INSERT INTO commission_payout_settings (setting_name, setting_value) 
                            VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
                        ");
                        $stmt->bind_param("ss", $name, $value);
                        $stmt->execute();
                    }
                    
                    $db->getConnection()->commit();
                    $success = 'Commission payout settings updated successfully.';
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Failed to update settings: ' . $e->getMessage();
                }
            }
        }
    }
}

// Get current payout settings
function getPayoutSetting($name, $default = '') {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM commission_payout_settings WHERE setting_name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['setting_value'] : $default;
}

$current_schedule = getPayoutSetting('global_payout_schedule', 'monthly');
$current_minimum = getPayoutSetting('global_minimum_payout', '50.00');
$current_auto_payout = getPayoutSetting('auto_payout_enabled', 'false') === 'true';
$current_day_month = getPayoutSetting('payout_day_of_month', '1');
$current_day_week = getPayoutSetting('payout_day_of_week', '1');

// Get commission statistics
$stmt = $db->query("
    SELECT 
        COUNT(DISTINCT user_id) as total_agents,
        SUM(CASE WHEN status = 'pending' THEN commission_earned ELSE 0 END) as total_pending,
        SUM(CASE WHEN status = 'liquidated' THEN commission_earned ELSE 0 END) as total_liquidated
    FROM transactions 
    WHERE commission_earned > 0
");
$commission_stats = $stmt->fetch_assoc();

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
    <title>Commission Payout Settings - <?php echo SITE_NAME; ?></title>
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
                <div class="nav-section-title">Commission</div>
                <div class="nav-item"><a href="commission-settings.php" class="nav-link"><i class="fas fa-percentage"></i> Commission Settings</a></div>
                <div class="nav-item"><a href="commission-payout-settings.php" class="nav-link active"><i class="fas fa-calendar-alt"></i> Payout Settings</a></div>
                <div class="nav-item"><a href="commission-liquidations.php" class="nav-link"><i class="fas fa-money-check-alt"></i> Liquidations</a></div>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
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
                <div class="nav-item"><a href="pwa-settings.php" class="nav-link"><i class="fas fa-mobile-alt"></i> PWA Settings</a></div>
                <div class="nav-item"><a href="sms-settings.php" class="nav-link"><i class="fas fa-sms"></i> SMS Settings</a></div>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-calendar-alt"></i></div>
                    <div class="breadcrumb-item">Commission</div>
                    <div class="breadcrumb-item active">Payout Settings</div>
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
                <h1>Commission Payout Settings</h1>
                <p class="page-subtitle">Configure when and how agent commissions are paid out.</p>
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

            <!-- Commission Statistics -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo number_format($commission_stats['total_agents'] ?? 0); ?></h3>
                        <p>Active Agents</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-content">
                        <h3>???<?php echo number_format($commission_stats['total_pending'] ?? 0, 2); ?></h3>
                        <p>Pending Commission</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>???<?php echo number_format($commission_stats['total_liquidated'] ?? 0, 2); ?></h3>
                        <p>Total Paid Out</p>
                    </div>
                </div>
            </div>

            <!-- Payout Settings Form -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Payout Schedule Configuration</h3>
                    <p class="widget-subtitle">Set when and how agent commissions should be paid out.</p>
                </div>
                <div class="widget-content">
                    <form method="post" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_payout_settings">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="payout_schedule" class="form-label">Payout Schedule</label>
                                <select id="payout_schedule" name="payout_schedule" class="form-control" required>
                                    <option value="daily" <?php echo $current_schedule === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo $current_schedule === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo $current_schedule === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                                <small class="form-text">How often commissions should be paid out to agents.</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="minimum_payout" class="form-label">Minimum Payout Amount (???)</label>
                                <input type="number" id="minimum_payout" name="minimum_payout" class="form-control" 
                                       value="<?php echo htmlspecialchars($current_minimum); ?>" 
                                       min="0" max="1000" step="0.01" required>
                                <small class="form-text">Minimum commission amount required before payout is triggered.</small>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group" id="monthly-settings" style="<?php echo $current_schedule !== 'monthly' ? 'display: none;' : ''; ?>">
                                <label for="payout_day_month" class="form-label">Day of Month</label>
                                <select id="payout_day_month" name="payout_day_month" class="form-control">
                                    <?php for ($i = 1; $i <= 28; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $current_day_month == $i ? 'selected' : ''; ?>>
                                            <?php echo $i; ?><?php echo $i == 1 ? 'st' : ($i == 2 ? 'nd' : ($i == 3 ? 'rd' : 'th')); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <small class="form-text">Day of the month to process payouts (1-28).</small>
                            </div>
                            
                            <div class="form-group" id="weekly-settings" style="<?php echo $current_schedule !== 'weekly' ? 'display: none;' : ''; ?>">
                                <label for="payout_day_week" class="form-label">Day of Week</label>
                                <select id="payout_day_week" name="payout_day_week" class="form-control">
                                    <option value="1" <?php echo $current_day_week == 1 ? 'selected' : ''; ?>>Monday</option>
                                    <option value="2" <?php echo $current_day_week == 2 ? 'selected' : ''; ?>>Tuesday</option>
                                    <option value="3" <?php echo $current_day_week == 3 ? 'selected' : ''; ?>>Wednesday</option>
                                    <option value="4" <?php echo $current_day_week == 4 ? 'selected' : ''; ?>>Thursday</option>
                                    <option value="5" <?php echo $current_day_week == 5 ? 'selected' : ''; ?>>Friday</option>
                                    <option value="6" <?php echo $current_day_week == 6 ? 'selected' : ''; ?>>Saturday</option>
                                    <option value="7" <?php echo $current_day_week == 7 ? 'selected' : ''; ?>>Sunday</option>
                                </select>
                                <small class="form-text">Day of the week to process payouts.</small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="auto_payout" value="1" <?php echo $current_auto_payout ? 'checked' : ''; ?>>
                                    <span class="checkbox-custom"></span>
                                    Enable Automatic Payouts
                                </label>
                                <small class="form-text">When enabled, commissions will be automatically credited to agent wallets based on the schedule above.</small>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Payout Settings
                            </button>
                        </div>
                    </form>
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

// Show/hide payout day settings based on schedule
document.getElementById('payout_schedule').addEventListener('change', function() {
    const schedule = this.value;
    const monthlySettings = document.getElementById('monthly-settings');
    const weeklySettings = document.getElementById('weekly-settings');
    
    monthlySettings.style.display = schedule === 'monthly' ? 'block' : 'none';
    weeklySettings.style.display = schedule === 'weekly' ? 'block' : 'none';
});
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



