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
        
        if ($action === 'update_commission') {
            $network_id = intval($_POST['network_id']);
            $commission_rate = floatval($_POST['commission_rate']);
            $min_commission = floatval($_POST['min_commission'] ?? 0);
            $max_commission = !empty($_POST['max_commission']) ? floatval($_POST['max_commission']) : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if ($commission_rate < 0 || $commission_rate > 100) {
                $error = 'Commission rate must be between 0% and 100%.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO commission_settings (network_id, package_type, commission_rate, min_commission, max_commission, is_active) 
                    VALUES (?, 'data', ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    commission_rate = VALUES(commission_rate),
                    min_commission = VALUES(min_commission),
                    max_commission = VALUES(max_commission),
                    is_active = VALUES(is_active)
                ");
                $stmt->bind_param("idddi", $network_id, $commission_rate, $min_commission, $max_commission, $is_active);
                
                if ($stmt->execute()) {
                    $success = 'Commission settings updated successfully!';
                } else {
                    $error = 'Failed to update commission settings.';
                }
            }
        }
    }
}

// Get all networks with commission settings
$stmt = $db->prepare("
    SELECT n.id, n.name, n.color,
           COALESCE(cs.commission_rate, 5.00) as commission_rate,
           COALESCE(cs.min_commission, 0.00) as min_commission,
           cs.max_commission,
           COALESCE(cs.is_active, 1) as is_active
    FROM networks n
    LEFT JOIN commission_settings cs ON n.id = cs.network_id AND cs.package_type = 'data'
    ORDER BY n.name
");
$stmt->execute();
$networks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get commission statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT t.user_id) as total_agents,
        SUM(t.commission_earned) as total_commission_earned,
        SUM(CASE WHEN t.commission_status = 'liquidated' THEN t.commission_earned ELSE 0 END) as total_liquidated,
        SUM(CASE WHEN t.commission_status = 'pending' THEN t.commission_earned ELSE 0 END) as total_pending
    FROM transactions t 
    WHERE t.commission_earned > 0
");
$stmt->execute();
$commission_stats = $stmt->get_result()->fetch_assoc();

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
    <title>Commission Settings - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .commission-table {
            min-width: 0;
        }

        .commission-table .commission-network {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .commission-table .network-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .commission-form {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .commission-input {
            max-width: 120px;
            min-width: 96px;
        }

        @media (max-width: 1024px) {
            .commission-table th,
            .commission-table td {
                white-space: normal;
            }

            .commission-input {
                max-width: 140px;
            }
        }

        @media (max-width: 768px) {
            .table-responsive {
                border: none;
            }

            .commission-table thead {
                display: none;
            }

            .commission-table,
            .commission-table tbody,
            .commission-table tr,
            .commission-table td {
                display: block;
                width: 100%;
            }

            .commission-table tr {
                border: 1px solid var(--border-color);
                border-radius: var(--radius-lg);
                padding: var(--spacing-md);
                margin-bottom: var(--spacing-md);
                background: var(--bg-primary);
            }

            .commission-table td {
                border: none;
                padding: var(--spacing-sm) 0;
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: var(--spacing-md);
                flex-direction: column;
            }

            .commission-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-muted);
                margin-bottom: 0.25rem;
            }

            .commission-table td[data-label="Network"] {
                align-items: flex-start;
            }

            .commission-table td[data-label="Network"]::before {
                margin-top: 2px;
            }

            .commission-table td[data-label="Actions"] {
                justify-content: flex-start;
                align-items: stretch;
            }

            .commission-table td[data-label="Actions"]::before {
                display: none;
            }

            .commission-form {
                width: 100%;
            }

            .commission-input {
                width: 100% !important;
                max-width: none;
            }

            .commission-actions .btn {
                width: 100%;
                justify-content: center;
            }
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
                <div class="nav-item"><a href="commission-settings.php" class="nav-link active"><i class="fas fa-percentage"></i> Commission Settings</a></div>
                <div class="nav-item"><a href="commission-payout-settings.php" class="nav-link"><i class="fas fa-calendar-alt"></i> Payout Settings</a></div>
                <div class="nav-item"><a href="commission-liquidations.php" class="nav-link"><i class="fas fa-money-check-alt"></i> Liquidations</a></div>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
                <div class="nav-item"><a href="commission-payouts.php" class="nav-link"><i class="fas fa-wallet"></i> Manual Payouts</a></div>
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
                    <div class="breadcrumb-item"><i class="fas fa-percentage"></i></div>
                    <div class="breadcrumb-item">Settings</div>
                    <div class="breadcrumb-item active">Commission Settings</div>
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
                <h1>Commission Settings</h1>
                <p class="page-subtitle">Configure commission rates, payout schedules, and minimum earnings thresholds.</p>
            </div>

            <div class="widget" style="margin-bottom: 1rem;">
                <div class="widget-header">
                    <h3 class="widget-title">How Commission Works</h3>
                </div>
                <div class="widget-body" style="color: var(--text-muted);">
                    <ol style="margin: 0 0 0.75rem 1.25rem;">
                        <li>Set a commission rate per network (percentage of the sale).</li>
                        <li>Min commission is the lowest amount an agent can earn per order.</li>
                        <li>Max commission caps the payout per order (leave blank for no cap).</li>
                        <li>Inactive networks earn no commission.</li>
                    </ol>
                    <div>Formula: <strong>commission = clamp(rate% ?? sale, min, max)</strong></div>
                </div>
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
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($commission_stats['total_agents'] ?? 0); ?></div>
                        <div class="stat-label">Active Agents</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-coins text-warning"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">???<?php echo number_format($commission_stats['total_commission_earned'] ?? 0, 2); ?></div>
                        <div class="stat-label">Total Commission Earned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">???<?php echo number_format($commission_stats['total_liquidated'] ?? 0, 2); ?></div>
                        <div class="stat-label">Total Liquidated</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-clock text-primary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">???<?php echo number_format($commission_stats['total_pending'] ?? 0, 2); ?></div>
                        <div class="stat-label">Pending Commission</div>
                    </div>
                </div>
            </div>

            <!-- Commission Settings by Network -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Network Commission Rates</h3>
                    <p class="widget-subtitle">Configure commission rates for each network.</p>
                </div>
                <div class="widget-content">
                    <div class="table-responsive">
                        <table class="table commission-table">
                            <thead>
                                <tr>
                                    <th>Network</th>
                                    <th>Commission Rate (%)</th>
                                    <th>Min Commission (???)</th>
                                    <th>Max Commission (???)</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($networks as $network): ?>
                                <tr>
                                    <td data-label="Network">
                                        <div class="commission-network">
                                            <span class="network-dot" style="background: <?php echo htmlspecialchars($network['color']); ?>;"></span>
                                            <?php echo htmlspecialchars($network['name']); ?>
                                        </div>
                                    </td>
                                    <td data-label="Commission Rate (%)">
                                        <form method="post" class="commission-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="update_commission">
                                            <input type="hidden" name="network_id" value="<?php echo $network['id']; ?>">
                                            <input type="number" name="commission_rate" value="<?php echo $network['commission_rate']; ?>"
                                                   step="0.01" min="0" max="100" class="form-control commission-input">
                                    </td>
                                    <td data-label="Min Commission">
                                        <input type="number" name="min_commission" value="<?php echo $network['min_commission']; ?>"
                                               step="0.01" min="0" class="form-control commission-input">
                                    </td>
                                    <td data-label="Max Commission">
                                        <input type="number" name="max_commission" value="<?php echo $network['max_commission']; ?>"
                                               step="0.01" min="0" class="form-control commission-input" placeholder="No limit">
                                    </td>
                                    <td data-label="Status">
                                        <label class="checkbox-label" style="margin: 0;">
                                            <input type="checkbox" name="is_active" <?php echo $network['is_active'] ? 'checked' : ''; ?>>
                                            <span class="checkmark"></span>
                                            Active
                                        </label>
                                    </td>
                                    <td data-label="Actions" class="commission-actions">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="fas fa-save"></i> Update
                                        </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Commission Information -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">How Commission Works</h3>
                </div>
                <div class="widget-content">
                    <div class="info-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                        <div class="info-item" style="padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <i class="fas fa-percentage" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">Commission Rate</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Percentage of transaction amount earned as commission by agents.
                            </p>
                        </div>
                        
                        <div class="info-item" style="padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <i class="fas fa-arrow-up" style="color: var(--success-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">Minimum Commission</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Minimum commission amount guaranteed per transaction.
                            </p>
                        </div>
                        
                        <div class="info-item" style="padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <i class="fas fa-arrow-down" style="color: var(--warning-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">Maximum Commission</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Maximum commission cap per transaction (optional).
                            </p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; padding: 1rem; background: var(--info-bg); border-radius: 8px; border-left: 4px solid var(--info-color);">
                        <h4 style="margin-bottom: 0.5rem; color: var(--info-color);">
                            <i class="fas fa-info-circle"></i> Commission Calculation
                        </h4>
                        <p style="margin: 0; color: var(--text-secondary);">
                            Commission = max(min_commission, min(transaction_amount ?? commission_rate / 100, max_commission))
                        </p>
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



