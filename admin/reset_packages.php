<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

$current_user = getCurrentUser();
$reset_performed = false;
$reset_stats = null;
$error = '';

// Handle reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    if (!validateCSRF($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $confirmation = sanitize($_POST['confirmation'] ?? '');
        
        if ($confirmation !== 'RESET PACKAGES') {
            $error = 'Please type "RESET PACKAGES" exactly to confirm the reset operation.';
        } else {
            try {
                // Start transaction
                $db->getConnection()->begin_transaction();
                
                // Get statistics before reset
                $stats_query = "
                    SELECT 
                        COUNT(dp.id) as total_packages,
                        COUNT(DISTINCT dp.network_id) as networks_affected,
                        COUNT(pp.id) as pricing_records,
                        COUNT(acp.id) as custom_pricing_records,
                        COUNT(bo.id) as bundle_orders,
                        COUNT(c.id) as commissions
                    FROM data_packages dp
                    LEFT JOIN package_pricing pp ON pp.package_id = dp.id
                    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id
                    LEFT JOIN bundle_orders bo ON bo.package_id = dp.id
                    LEFT JOIN commissions c ON c.order_id = bo.id
                ";
                
                $stats_result = $db->query($stats_query);
                $reset_stats = $stats_result->fetch_assoc();
                
                // Step 1: Delete commissions related to bundle orders (if any)
                $db->query("
                    DELETE c FROM commissions c 
                    INNER JOIN bundle_orders bo ON c.order_id = bo.id
                ");
                
                // Step 2: Delete bundle orders
                $db->query("DELETE FROM bundle_orders");
                
                // Step 3: Delete agent custom pricing
                $db->query("DELETE FROM agent_custom_pricing");
                
                // Step 4: Delete package pricing
                $db->query("DELETE FROM package_pricing");
                
                // Step 5: Finally delete data packages
                $db->query("DELETE FROM data_packages");
                
                // Reset auto increment IDs to start fresh
                $db->query("ALTER TABLE data_packages AUTO_INCREMENT = 1");
                $db->query("ALTER TABLE package_pricing AUTO_INCREMENT = 1");
                $db->query("ALTER TABLE agent_custom_pricing AUTO_INCREMENT = 1");
                $db->query("ALTER TABLE bundle_orders AUTO_INCREMENT = 1");
                $db->query("ALTER TABLE commissions AUTO_INCREMENT = 1");
                
                // Log the reset activity
                logActivity($current_user['id'], 'packages_reset', 'Admin reset all data bundle packages and related data');
                
                // Commit transaction
                $db->getConnection()->commit();
                
                $reset_performed = true;
                setFlashMessage('success', 'All data bundle packages and related data have been successfully reset.');
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->getConnection()->rollback();
                $error = 'Reset failed: ' . $e->getMessage();
                error_log("Package reset error: " . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRF();

// Get current package statistics
$current_stats = null;
try {
    $stats_query = "
        SELECT 
            COUNT(dp.id) as total_packages,
            COUNT(DISTINCT dp.network_id) as networks_with_packages,
            COUNT(pp.id) as pricing_records,
            COUNT(acp.id) as custom_pricing_records,
            COUNT(bo.id) as bundle_orders,
            COUNT(c.id) as commissions
        FROM data_packages dp
        LEFT JOIN package_pricing pp ON pp.package_id = dp.id
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id
        LEFT JOIN bundle_orders bo ON bo.package_id = dp.id
        LEFT JOIN commissions c ON c.order_id = bo.id
    ";
    
    $stats_result = $db->query($stats_query);
    $current_stats = $stats_result->fetch_assoc();
} catch (Exception $e) {
    error_log("Stats query error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Data Packages - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .warning-box {
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            color: #2E294E;
        }
        .danger-box {
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            color: #2E294E;
        }
        .success-box {
            background: #F1E9DA;
            border: 1px solid #F1E9DA;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            color: #2E294E;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-card {
            background: var(--bg-secondary);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--brand-primary);
        }
        .confirmation-input {
            font-family: monospace;
            font-weight: bold;
            padding: 0.75rem;
            width: 100%;
            border: 2px solid #D90368;
            border-radius: 4px;
            margin: 0.5rem 0;
        }
        .reset-button {
            background: #D90368;
            color: #F1E9DA;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        .reset-button:hover {
            background: #D90368;
        }
        .reset-button:disabled {
            background: #541388;
            cursor: not-allowed;
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
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
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
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="breadcrumb-item">Management</div>
                    <div class="breadcrumb-item">Data Packages</div>
                    <div class="breadcrumb-item active">Reset</div>
                </nav>
            </div>
            <div class="header-actions">
                <a href="packages.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Packages
                </a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1><i class="fas fa-exclamation-triangle" style="color: #D90368;"></i> Reset Data Packages</h1>
                <p class="page-subtitle">Permanently remove all data bundle packages and related data from the system.</p>
            </div>

            <?php if ($error): ?>
                <div class="danger-box">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($reset_performed && $reset_stats): ?>
                <div class="success-box">
                    <h3><i class="fas fa-check-circle"></i> Reset Completed Successfully</h3>
                    <p>The following data has been permanently removed:</p>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($reset_stats['total_packages']); ?></div>
                            <div>Data Packages</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($reset_stats['pricing_records']); ?></div>
                            <div>Pricing Records</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($reset_stats['custom_pricing_records']); ?></div>
                            <div>Custom Pricing</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($reset_stats['bundle_orders']); ?></div>
                            <div>Bundle Orders</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($reset_stats['commissions']); ?></div>
                            <div>Commission Records</div>
                        </div>
                    </div>
                    <p><strong>All auto-increment IDs have been reset to start from 1.</strong></p>
                    <div style="margin-top: 1rem;">
                        <a href="packages.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add New Packages
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="widget">
                    <div class="widget-header">
                        <h3><i class="fas fa-database"></i> Current System Status</h3>
                    </div>
                    <div class="widget-content">
                        <?php if ($current_stats): ?>
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['total_packages']); ?></div>
                                    <div>Data Packages</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['networks_with_packages']); ?></div>
                                    <div>Networks with Packages</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['pricing_records']); ?></div>
                                    <div>Pricing Records</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['custom_pricing_records']); ?></div>
                                    <div>Custom Agent Pricing</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['bundle_orders']); ?></div>
                                    <div>Bundle Orders</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo number_format($current_stats['commissions']); ?></div>
                                    <div>Commission Records</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="danger-box">
                    <h3><i class="fas fa-exclamation-triangle"></i> Warning: Irreversible Action</h3>
                    <p><strong>This operation will permanently delete:</strong></p>
                    <ul>
                        <li>All data bundle packages</li>
                        <li>All package pricing configurations</li>
                        <li>All agent custom pricing settings</li>
                        <li>All bundle orders and transaction history</li>
                        <li>All commission records related to packages</li>
                    </ul>
                    <p><strong>This action cannot be undone. Make sure you have a database backup before proceeding.</strong></p>
                </div>

                <?php if ($current_stats && $current_stats['total_packages'] > 0): ?>
                    <div class="widget">
                        <div class="widget-header">
                            <h3><i class="fas fa-trash-alt"></i> Confirm Reset</h3>
                        </div>
                        <div class="widget-content">
                            <form method="POST" action="" onsubmit="return confirmReset()">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                
                                <div class="form-group">
                                    <label for="confirmation">
                                        <strong>Type "RESET PACKAGES" to confirm:</strong>
                                    </label>
                                    <input 
                                        type="text" 
                                        id="confirmation" 
                                        name="confirmation" 
                                        class="confirmation-input"
                                        placeholder="Type: RESET PACKAGES"
                                        required
                                        autocomplete="off"
                                    >
                                    <small>This confirmation is case-sensitive and must match exactly.</small>
                                </div>
                                
                                <div class="form-group" style="margin-top: 1.5rem;">
                                    <button type="submit" name="confirm_reset" class="reset-button" id="resetButton" disabled>
                                        <i class="fas fa-trash-alt"></i> Reset All Data Packages
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="warning-box">
                        <i class="fas fa-info-circle"></i>
                        <strong>No packages to reset:</strong> The system currently has no data packages. 
                        <a href="packages.php">Add some packages</a> to get started.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
<script>
// Enable reset button only when correct confirmation is typed
document.getElementById('confirmation').addEventListener('input', function() {
    const resetButton = document.getElementById('resetButton');
    const confirmationText = this.value.trim();
    
    if (confirmationText === 'RESET PACKAGES') {
        resetButton.disabled = false;
        resetButton.style.background = '#D90368';
    } else {
        resetButton.disabled = true;
        resetButton.style.background = '#541388';
    }
});

// Final confirmation dialog
function confirmReset() {
    return confirm(
        'Are you absolutely sure you want to reset ALL data packages?\n\n' +
        'This will permanently delete:\n' +
        '??? All data packages\n' +
        '??? All pricing configurations\n' +
        '??? All bundle orders\n' +
        '??? All related commission records\n\n' +
        'This action CANNOT be undone!'
    );
}

// User dropdown functionality
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!toggle.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


