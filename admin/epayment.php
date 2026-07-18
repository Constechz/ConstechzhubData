<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

$manual_limit = 10;
$paystack_limit = 10;
$gateway_label = getActivePaymentGateway() === 'moolre' ? 'Moolre' : 'Paystack';

$transaction_type_column = 'transaction_type';
if (function_exists('dbh_table_has_column')) {
    if (dbh_table_has_column('transactions', 'transaction_type')) {
        $transaction_type_column = 'transaction_type';
    } elseif (dbh_table_has_column('transactions', 'type')) {
        $transaction_type_column = 'type';
    }
}
$transaction_type_column = in_array($transaction_type_column, ['transaction_type', 'type'], true)
    ? $transaction_type_column
    : 'transaction_type';

$has_wallet_transactions = function_exists('dbh_table_exists') && dbh_table_exists('wallet_transactions');
$wallet_has_user_id = $has_wallet_transactions && function_exists('dbh_table_has_column')
    && dbh_table_has_column('wallet_transactions', 'user_id');
$wallet_has_wallet_id = $has_wallet_transactions && function_exists('dbh_table_has_column')
    && dbh_table_has_column('wallet_transactions', 'wallet_id');

function fetchManualTopups($role, $limit, $wallet_has_user_id, $wallet_has_wallet_id) {
    global $db;

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('wallet_transactions')) {
        return [];
    }

    if ($wallet_has_user_id) {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.email,
                   COUNT(wt.id) AS topup_count,
                   SUM(wt.amount) AS total_amount,
                   MAX(wt.created_at) AS last_topup
            FROM wallet_transactions wt
            JOIN users u ON u.id = wt.user_id
            WHERE wt.transaction_type = 'credit'
              AND wt.reference LIKE 'TOPUP_%'
              AND u.role = ?
            GROUP BY u.id, u.username, u.full_name, u.email
            ORDER BY total_amount DESC
            LIMIT ?
        ";
    } elseif ($wallet_has_wallet_id) {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.email,
                   COUNT(wt.id) AS topup_count,
                   SUM(wt.amount) AS total_amount,
                   MAX(wt.created_at) AS last_topup
            FROM wallet_transactions wt
            JOIN wallets w ON w.id = wt.wallet_id
            JOIN users u ON u.id = w.user_id
            WHERE wt.transaction_type = 'credit'
              AND wt.reference LIKE 'TOPUP_%'
              AND u.role = ?
            GROUP BY u.id, u.username, u.full_name, u.email
            ORDER BY total_amount DESC
            LIMIT ?
        ";
    } else {
        return [];
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('si', $role, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function fetchPaystackTopups($role, $limit, $transaction_type_column) {
    global $db;

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('transactions')) {
        return [];
    }

    $sql = "
        SELECT u.id, u.username, u.full_name, u.email,
               COUNT(t.id) AS topup_count,
               SUM(t.amount) AS total_amount,
               MAX(t.created_at) AS last_topup
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        WHERE t.{$transaction_type_column} = 'topup'
          AND t.status IN ('success', 'completed')
          AND t.payment_method IN ('paystack', 'agent_paystack', 'moolre')
          AND u.role = ?
        GROUP BY u.id, u.username, u.full_name, u.email
        ORDER BY total_amount DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('si', $role, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$manual_agents = fetchManualTopups('agent', $manual_limit, $wallet_has_user_id, $wallet_has_wallet_id);
$manual_customers = fetchManualTopups('customer', $manual_limit, $wallet_has_user_id, $wallet_has_wallet_id);
$paystack_agents = fetchPaystackTopups('agent', $paystack_limit, $transaction_type_column);
$paystack_customers = fetchPaystackTopups('customer', $paystack_limit, $transaction_type_column);

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ePayment - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .epayment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .epayment-table {
            min-width: 0;
        }

        @media (max-width: 992px) {
            .epayment-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body,
            .dashboard-wrapper,
            .main-content {
                overflow-x: hidden;
            }

            .table-responsive {
                overflow-x: hidden;
            }

            .epayment-table,
            .epayment-table thead,
            .epayment-table tbody,
            .epayment-table tr,
            .epayment-table td {
                display: block;
                width: 100%;
            }

            .epayment-table thead {
                display: none;
            }

            .epayment-table tbody tr {
                border: 1px solid var(--border-color, #F1E9DA);
                border-radius: 8px;
                padding: 0.75rem 1rem;
                margin-bottom: 1rem;
                background: var(--card-bg, #F1E9DA);
            }

            .epayment-table tbody td {
                border: none;
                padding: 0.45rem 0;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
                word-break: break-word;
            }

            .epayment-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-muted, #541388);
            }

            [data-theme="dark"] .epayment-table tbody tr {
                background: #2E294E;
                border-color: #2E294E;
            }

            [data-theme="dark"] .epayment-table tbody td {
                color: #F1E9DA;
            }

            [data-theme="dark"] .epayment-table tbody td::before {
                color: #F1E9DA;
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
                <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link active"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-wallet"></i></div>
                    <div class="breadcrumb-item">Analytics</div>
                    <div class="breadcrumb-item active">ePayment</div>
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
                <h1>ePayment Insights</h1>
                <p class="page-subtitle">Top manual and <?php echo htmlspecialchars($gateway_label); ?> wallet top-ups by agents and customers.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="epayment-grid">
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Manual Top-ups - Agents</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table epayment-table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Email</th>
                                        <th>Top-ups</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($manual_agents)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No manual top-ups for agents yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($manual_agents as $row): ?>
                                        <?php $name = $row['full_name'] ?: $row['username']; ?>
                                        <tr>
                                            <td data-label="Agent"><?php echo htmlspecialchars($name ?: 'Unknown'); ?></td>
                                            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                            <td data-label="Top-ups"><?php echo number_format((int) ($row['topup_count'] ?? 0)); ?></td>
                                            <td data-label="Total"><?php echo CURRENCY . number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                            <td data-label="Last"><?php echo !empty($row['last_topup']) ? date('M j, Y', strtotime($row['last_topup'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Manual Top-ups - Customers</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table epayment-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Email</th>
                                        <th>Top-ups</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($manual_customers)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No manual top-ups for customers yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($manual_customers as $row): ?>
                                        <?php $name = $row['full_name'] ?: $row['username']; ?>
                                        <tr>
                                            <td data-label="Customer"><?php echo htmlspecialchars($name ?: 'Unknown'); ?></td>
                                            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                            <td data-label="Top-ups"><?php echo number_format((int) ($row['topup_count'] ?? 0)); ?></td>
                                            <td data-label="Total"><?php echo CURRENCY . number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                            <td data-label="Last"><?php echo !empty($row['last_topup']) ? date('M j, Y', strtotime($row['last_topup'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="epayment-grid">
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title"><?php echo htmlspecialchars($gateway_label); ?> Top-ups - Agents</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table epayment-table">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Email</th>
                                        <th>Top-ups</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($paystack_agents)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No <?php echo htmlspecialchars($gateway_label); ?> top-ups for agents yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($paystack_agents as $row): ?>
                                        <?php $name = $row['full_name'] ?: $row['username']; ?>
                                        <tr>
                                            <td data-label="Agent"><?php echo htmlspecialchars($name ?: 'Unknown'); ?></td>
                                            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                            <td data-label="Top-ups"><?php echo number_format((int) ($row['topup_count'] ?? 0)); ?></td>
                                            <td data-label="Total"><?php echo CURRENCY . number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                            <td data-label="Last"><?php echo !empty($row['last_topup']) ? date('M j, Y', strtotime($row['last_topup'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title"><?php echo htmlspecialchars($gateway_label); ?> Top-ups - Customers</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table epayment-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Email</th>
                                        <th>Top-ups</th>
                                        <th>Total</th>
                                        <th>Last</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($paystack_customers)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No <?php echo htmlspecialchars($gateway_label); ?> top-ups for customers yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($paystack_customers as $row): ?>
                                        <?php $name = $row['full_name'] ?: $row['username']; ?>
                                        <tr>
                                            <td data-label="Customer"><?php echo htmlspecialchars($name ?: 'Unknown'); ?></td>
                                            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                            <td data-label="Top-ups"><?php echo number_format((int) ($row['topup_count'] ?? 0)); ?></td>
                                            <td data-label="Total"><?php echo CURRENCY . number_format((float) ($row['total_amount'] ?? 0), 2); ?></td>
                                            <td data-label="Last"><?php echo !empty($row['last_topup']) ? date('M j, Y', strtotime($row['last_topup'])) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            sidebar.classList.toggle('show');
            sidebar.classList.toggle('active');
        }
    });

    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');

        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initTheme === 'function') {
            initTheme();
        }
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


