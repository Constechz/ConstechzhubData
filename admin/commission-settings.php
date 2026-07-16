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
        
        if ($action === 'save_agent_commissions') {
            $program_enabled = isset($_POST['program_enabled']) ? '1' : '0';
            $start_at_input = trim((string) ($_POST['start_at'] ?? ''));
            $start_at = '';
            if ($start_at_input !== '') {
                $timestamp = strtotime($start_at_input);
                if ($timestamp === false) {
                    $error = 'Please provide a valid commission start date and time.';
                } else {
                    $start_at = date('Y-m-d H:i:s', $timestamp);
                }
            }

            $data_rate = round(max(0, (float) ($_POST['data_rate_per_gb'] ?? 0)), 2);
            $checker_rate = round(max(0, (float) ($_POST['checker_rate_per_card'] ?? 0)), 2);
            $afa_rate = round(max(0, (float) ($_POST['afa_rate_per_order'] ?? 0)), 2);

            if ($error === '') {
                $save_ok = saveSetting('agent_commission_program_enabled', $program_enabled, 'Enable the agent commission program')
                    && saveSetting('agent_commission_start_at', $start_at, 'Commission start date and time')
                    && saveSetting('agent_commission_data_enabled', isset($_POST['data_enabled']) ? '1' : '0', 'Enable fixed data bundle commission for agents')
                    && saveSetting('agent_commission_data_rate_per_gb', (string) $data_rate, 'Agent commission amount per 1GB data')
                    && saveSetting('agent_commission_checker_enabled', isset($_POST['checker_enabled']) ? '1' : '0', 'Enable fixed result checker commission for agents')
                    && saveSetting('agent_commission_checker_rate_per_card', (string) $checker_rate, 'Agent commission amount per result checker card')
                    && saveSetting('agent_commission_afa_enabled', isset($_POST['afa_enabled']) ? '1' : '0', 'Enable fixed AFA registration commission for agents')
                    && saveSetting('agent_commission_afa_rate_per_order', (string) $afa_rate, 'Agent commission amount per AFA registration order');

                if ($save_ok) {
                    $success = 'Agent commission settings updated successfully.';
                } else {
                    $error = 'Failed to update commission settings.';
                }
            }
        }
    }
}

$agent_commission_settings = function_exists('getAgentCommissionSettings')
    ? getAgentCommissionSettings()
    : [
        'program_enabled' => false,
        'start_at' => '',
        'active_now' => false,
        'data_enabled' => false,
        'data_rate_per_gb' => 0.0,
        'checker_enabled' => false,
        'checker_rate_per_card' => 0.0,
        'afa_enabled' => false,
        'afa_rate_per_order' => 0.0,
    ];

$program_start_value = '';
if (!empty($agent_commission_settings['start_at'])) {
    $timestamp = strtotime((string) $agent_commission_settings['start_at']);
    if ($timestamp !== false) {
        $program_start_value = date('Y-m-d\TH:i', $timestamp);
    }
}

$commission_services = [
    [
        'label' => 'Data Bundles',
        'rate_label' => 'Amount per 1GB',
        'rate_name' => 'data_rate_per_gb',
        'enabled_name' => 'data_enabled',
        'enabled' => $agent_commission_settings['data_enabled'],
        'rate' => $agent_commission_settings['data_rate_per_gb'],
        'example' => '5GB order earns 5 × rate.',
        'icon' => 'fa-signal',
        'color' => '#2563eb',
    ],
    [
        'label' => 'Result Checkers',
        'rate_label' => 'Amount per card',
        'rate_name' => 'checker_rate_per_card',
        'enabled_name' => 'checker_enabled',
        'enabled' => $agent_commission_settings['checker_enabled'],
        'rate' => $agent_commission_settings['checker_rate_per_card'],
        'example' => '3 cards earn 3 × rate.',
        'icon' => 'fa-id-card',
        'color' => '#7c3aed',
    ],
    [
        'label' => 'AFA Registration',
        'rate_label' => 'Amount per order',
        'rate_name' => 'afa_rate_per_order',
        'enabled_name' => 'afa_enabled',
        'enabled' => $agent_commission_settings['afa_enabled'],
        'rate' => $agent_commission_settings['afa_rate_per_order'],
        'example' => '1 registration earns 1 × rate.',
        'icon' => 'fa-user-check',
        'color' => '#059669',
    ],
];

$commission_stats = [
    'total_agents' => 0,
    'data_commission' => 0,
    'checker_commission' => 0,
    'afa_commission' => 0,
];

$stmt = $db->prepare("SELECT COUNT(*) AS total_agents FROM users WHERE role = 'agent'");
if ($stmt && $stmt->execute()) {
    $row = $stmt->get_result()->fetch_assoc();
    $commission_stats['total_agents'] = (int) ($row['total_agents'] ?? 0);
    $stmt->close();
}

if (function_exists('ensureAgentCommissionTables')) {
    ensureAgentCommissionTables();
}

if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
    $result = $db->query("
        SELECT source_type, COALESCE(SUM(amount), 0) AS total
        FROM agent_commissions
        WHERE status = 'earned'
        GROUP BY source_type
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));
            $total = (float) ($row['total'] ?? 0);
            if ($sourceType === 'data') {
                $commission_stats['data_commission'] = $total;
            } elseif ($sourceType === 'checker') {
                $commission_stats['checker_commission'] = $total;
            } elseif ($sourceType === 'afa') {
                $commission_stats['afa_commission'] = $total;
            }
        }
    }
}

$commission_stats['total_commission_earned'] = $commission_stats['data_commission']
    + $commission_stats['checker_commission']
    + $commission_stats['afa_commission'];

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
                    <?php renderAdminSidebar(); ?>
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
                <p class="page-subtitle">Turn the commission program on or off, choose when it starts, and set fixed earning amounts for data, checkers, and AFA orders.</p>
            </div>

            <div class="widget" style="margin-bottom: 1rem;">
                <div class="widget-header">
                    <h3 class="widget-title">How Commission Works</h3>
                </div>
                <div class="widget-body" style="color: var(--text-muted);">
                    <ol style="margin: 0 0 0.75rem 1.25rem;">
                        <li>Commission is only recorded when the master program switch is on.</li>
                        <li>No order before the configured start date is counted.</li>
                        <li>Data bundles use a fixed amount per 1GB and multiply by the bundle size.</li>
                        <li>Result checkers use a fixed amount per checker card.</li>
                        <li>AFA registrations use a fixed amount per submitted order.</li>
                        <li>If a service is inactive, no commission is recorded for that service.</li>
                    </ol>
                    <div>Examples: <strong>5GB = 5 × rate</strong>, <strong>3 checkers = 3 × rate</strong>, <strong>1 AFA order = 1 × rate</strong>.</div>
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
                        <div class="stat-value"><?php echo CURRENCY . number_format($commission_stats['total_commission_earned'] ?? 0, 2); ?></div>
                        <div class="stat-label">Total Commission Earned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($commission_stats['data_commission'] ?? 0, 2); ?></div>
                        <div class="stat-label">Data Commission</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-toggle-on text-primary"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo !empty($agent_commission_settings['program_enabled']) ? 'On' : 'Off'; ?></div>
                        <div class="stat-label">Program Status</div>
                    </div>
                </div>
            </div>

            <!-- Commission Settings by Network -->
            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Agent Service Commission</h3>
                    <p class="widget-subtitle">Set the master start date and one fixed commission rule per service.</p>
                </div>
                <div class="widget-content">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="save_agent_commissions">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                        <div style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-secondary);">
                            <label class="checkbox-label" style="margin: 0 0 0.5rem 0;">
                                <input type="checkbox" name="program_enabled" <?php echo !empty($agent_commission_settings['program_enabled']) ? 'checked' : ''; ?>>
                                <span class="checkmark"></span>
                                Enable Commission Program
                            </label>
                            <div style="font-size: 0.875rem; color: var(--text-muted);">
                                When off, no new commissions are recorded even if service rates are set.
                            </div>
                        </div>
                        <div style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-secondary);">
                            <label for="start_at" class="form-label" style="margin-bottom: 0.5rem;">Commission Start Date/Time</label>
                            <input type="datetime-local" id="start_at" name="start_at" class="form-control" value="<?php echo htmlspecialchars($program_start_value); ?>">
                            <div style="font-size: 0.875rem; color: var(--text-muted); margin-top: 0.5rem;">
                                Leave blank to start immediately when the program is enabled.
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table commission-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Rule</th>
                                    <th>Commission Amount (<?php echo htmlspecialchars(CURRENCY); ?>)</th>
                                    <th>Example</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commission_services as $service): ?>
                                <tr>
                                    <td data-label="Service">
                                        <div class="commission-network">
                                            <span class="network-dot" style="background: <?php echo htmlspecialchars($service['color']); ?>;"></span>
                                            <span><i class="fas <?php echo htmlspecialchars($service['icon']); ?>"></i> <?php echo htmlspecialchars($service['label']); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Rule">
                                        <?php echo htmlspecialchars($service['rate_label']); ?>
                                    </td>
                                    <td data-label="Commission Amount">
                                        <input type="number" name="<?php echo htmlspecialchars($service['rate_name']); ?>"
                                               value="<?php echo number_format((float) $service['rate'], 2, '.', ''); ?>"
                                               step="0.01" min="0" class="form-control commission-input">
                                    </td>
                                    <td data-label="Example"><?php echo htmlspecialchars($service['example']); ?></td>
                                    <td data-label="Status">
                                        <label class="checkbox-label" style="margin: 0;">
                                            <input type="checkbox" name="<?php echo htmlspecialchars($service['enabled_name']); ?>" <?php echo !empty($service['enabled']) ? 'checked' : ''; ?>>
                                            <span class="checkmark"></span>
                                            Active
                                        </label>
                                    </td>
                                    <td data-label="Actions" class="commission-actions">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="fas fa-save"></i> Save All
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </form>
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
                                <i class="fas fa-signal" style="color: var(--primary-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">Data Bundle Commission</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Set one amount per 1GB. A 10GB order earns 10 times that amount.
                            </p>
                        </div>
                        
                        <div class="info-item" style="padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <i class="fas fa-id-card" style="color: var(--success-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">Checker Commission</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Set one amount per checker card. Quantity purchased multiplies the commission.
                            </p>
                        </div>
                        
                        <div class="info-item" style="padding: 1rem; background: var(--bg-secondary); border-radius: 8px; border: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <i class="fas fa-user-check" style="color: var(--warning-color); margin-right: 0.5rem;"></i>
                                <h4 style="margin: 0;">AFA Registration Commission</h4>
                            </div>
                            <p style="margin: 0; color: var(--text-secondary); font-size: 0.875rem;">
                                Set one amount per registration order. Toggle off to stop recording AFA commission.
                            </p>
                        </div>
                    </div>
                    
                    <div style="margin-top: 2rem; padding: 1rem; background: var(--info-bg); border-radius: 8px; border-left: 4px solid var(--info-color);">
                        <h4 style="margin-bottom: 0.5rem; color: var(--info-color);">
                            <i class="fas fa-info-circle"></i> Fixed Commission Formula
                        </h4>
                        <p style="margin: 0; color: var(--text-secondary);">
                            Data = bundle GB × data rate, Checkers = card quantity × checker rate, AFA = order count × AFA rate.
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



