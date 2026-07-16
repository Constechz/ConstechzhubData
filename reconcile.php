<?php
/**
 * Wallet Balance Reconciliation Utility (Today's Transactions Only)
 * Place this in the root directory of the application.
 */

// Enable error reporting to diagnose live server 500 errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

// Auth Check if run through a web browser
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !function_exists('hasRole') || !hasRole('admin')) {
        header('HTTP/1.1 403 Forbidden');
        echo "<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:sans-serif;background:#f7fafc;padding:50px;text-align:center;}div{background:#fff;padding:40px;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);max-width:500px;margin:0 auto;}h1{color:#e53e3e;}p{color:#4a5568;}</style></head><body><div><h1>Access Denied</h1><p>You must be logged in as an administrator to access this tool.</p><p><a href='login.php'>Go to Login</a></p></div></body></html>";
        exit();
    }
}

global $db;
if (!isset($db)) {
    die("Database connection object \$db is not initialized. Please check config/config.php.");
}
$conn = $db->getConnection();
if (!$conn) {
    die("Database connection failed. Please check config/config.php.");
}

// Fetch all users
$users = [];
try {
    $res = $conn->query("SELECT id, username, full_name, email, role FROM users ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }
    } else {
        die("Failed to fetch users from database: " . $conn->error);
    }
} catch (Throwable $e) {
    die("Failed to fetch users: " . $e->getMessage());
}

$reconciliation_data = [];
$total_exposed_overcharged = 0;
$total_exposed_overcredited = 0;

$today_date = date('Y-m-d');
$cutoff_time = $today_date . ' 00:00:00';

foreach ($users as $u) {
    $user_id = (int)$u['id'];

    // 1. Calculate current balance sum from all wallet rows (the state right now)
    $current_balance = 0.00;
    $wallet_rows_count = 0;
    try {
        $wallet_res = $conn->query("SELECT SUM(balance) as net_balance, COUNT(*) as row_count FROM wallets WHERE user_id = $user_id");
        if ($wallet_res) {
            $wallet_row = $wallet_res->fetch_assoc();
            $current_balance = $wallet_row ? (float)$wallet_row['net_balance'] : 0.00;
            $wallet_rows_count = $wallet_row ? (int)$wallet_row['row_count'] : 0;
        }
    } catch (Throwable $e) {
        $current_balance = 0.00;
        $wallet_rows_count = 0;
    }

    // 2. Calculate balance before today (base starting balance)
    $yesterday_balance = 0.00;
    try {
        $yesterday_res = $conn->query("SELECT SUM(balance) as total FROM wallets WHERE user_id = $user_id AND created_at < '$cutoff_time'");
        if ($yesterday_res) {
            $yesterday_row = $yesterday_res->fetch_assoc();
            $yesterday_balance = $yesterday_row ? (float)$yesterday_row['total'] : 0.00;
        }
    } catch (Throwable $e) {
        $yesterday_balance = 0.00;
    }

    // 3. Sum up today's topups (credits)
    $today_topups = 0.00;
    try {
        $topups_res = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE user_id = $user_id AND transaction_type = 'topup' AND status = 'success' AND created_at >= '$cutoff_time'");
        if ($topups_res) {
            $topups_row = $topups_res->fetch_assoc();
            $today_topups = $topups_row ? (float)$topups_row['total'] : 0.00;
        }
    } catch (Throwable $e) {
        $today_topups = 0.00;
    }

    // 4. Sum up today's commissions (credits)
    $today_commissions = 0.00;
    try {
        $commissions_res = $conn->query("SELECT SUM(amount) as total FROM commissions WHERE agent_id = $user_id AND status = 'paid' AND created_at >= '$cutoff_time'");
        if ($commissions_res) {
            $commissions_row = $commissions_res->fetch_assoc();
            $today_commissions = $commissions_row ? (float)$commissions_row['total'] : 0.00;
        }
    } catch (Throwable $e) {
        $today_commissions = 0.00;
    }

    // 5. Sum up today's purchases (debits)
    $today_purchases = 0.00;
    try {
        $purchases_res = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE user_id = $user_id AND transaction_type = 'purchase' AND status = 'success' AND created_at >= '$cutoff_time'");
        if ($purchases_res) {
            $purchases_row = $purchases_res->fetch_assoc();
            $today_purchases = $purchases_row ? (float)$purchases_row['total'] : 0.00;
        }
    } catch (Throwable $e) {
        $today_purchases = 0.00;
    }

    // 6. Sum up today's withdrawals (debits)
    $today_withdrawals = 0.00;
    try {
        $withdrawals_res = $conn->query("SELECT SUM(amount) as total FROM transactions WHERE user_id = $user_id AND transaction_type = 'withdrawal' AND status = 'success' AND created_at >= '$cutoff_time'");
        if ($withdrawals_res) {
            $withdrawals_row = $withdrawals_res->fetch_assoc();
            $today_withdrawals = $withdrawals_row ? (float)$withdrawals_row['total'] : 0.00;
        }
    } catch (Throwable $e) {
        $today_withdrawals = 0.00;
    }

    // Calculate reconstructed true balance: Yesterday's Balance + Today's Credits - Today's Debits
    $true_balance = $yesterday_balance + ($today_topups + $today_commissions) - ($today_purchases + $today_withdrawals);
    
    // Safety check: Wallet balance should never theoretically go below 0
    if ($true_balance < 0) {
        $true_balance = 0.00;
    }

    $discrepancy = $current_balance - $true_balance;

    // Discrepancy exists if balance doesn't match or duplicate rows were created today
    if (abs($discrepancy) > 0.01 || $wallet_rows_count > 1) {
        if ($discrepancy > 0) {
            $total_exposed_overcredited += $discrepancy;
        } else {
            $total_exposed_overcharged += abs($discrepancy);
        }

        $reconciliation_data[] = [
            'user_id' => $user_id,
            'username' => $u['username'],
            'full_name' => $u['full_name'],
            'email' => $u['email'],
            'role' => $u['role'],
            'current_balance' => $current_balance,
            'true_balance' => $true_balance,
            'discrepancy' => $discrepancy,
            'wallet_rows_count' => $wallet_rows_count
        ];
    }
}

// ----------------------------------------------------
// CLI MODE RENDERING
// ----------------------------------------------------
if (php_sapi_name() === 'cli') {
    echo "================================================================================\n";
    echo "            WALLET RECONCILIATION REPORT (TODAY'S TRANSACTIONS ONLY)             \n";
    echo "================================================================================\n";
    printf("%-8s | %-12s | %-15s | %-15s | %-15s\n", "User ID", "Username", "Net Wallet Bal", "True Balance", "Discrepancy");
    echo "--------------------------------------------------------------------------------\n";
    
    foreach ($reconciliation_data as $data) {
        printf("%-8d | %-12s | GHS %-11.2f | GHS %-11.2f | GHS %-11.2f (%s)\n",
            $data['user_id'],
            substr($data['username'], 0, 12),
            $data['current_balance'],
            $data['true_balance'],
            abs($data['discrepancy']),
            $data['discrepancy'] > 0 ? "Overcredited" : "Overcharged"
        );
    }
    
    echo "--------------------------------------------------------------------------------\n";
    printf("Total Overcredited Amount: GHS %.2f\n", $total_exposed_overcredited);
    printf("Total Overcharged Amount:  GHS %.2f\n", $total_exposed_overcharged);
    echo "================================================================================\n";
    exit();
}

// ----------------------------------------------------
// WEB BROWSER RENDERING
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Reconciliation - Admin Panel</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .recon-container { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .recon-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .recon-card { background: var(--bg-primary, #fff); border: 1px solid var(--border-color, #eef2f7); border-radius: 8px; padding: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .recon-card h3 { margin: 0 0 0.5rem 0; font-size: 0.9rem; color: var(--text-muted, #718096); text-transform: uppercase; letter-spacing: 0.05em; }
        .recon-card .val { font-size: 1.8rem; font-weight: 700; color: var(--text-main, #2d3748); }
        .recon-card.danger .val { color: #e53e3e; }
        .recon-card.warning .val { color: #dd6b20; }
        .badge-rows { background: #edf2f7; color: #4a5568; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600; }
        .badge-rows.multi { background: #feebc8; color: #c05621; }
        .sql-box { background: #1a202c; color: #a0aec0; font-family: monospace; padding: 1rem; border-radius: 6px; overflow-x: auto; max-height: 250px; margin-top: 1rem; font-size: 0.85rem; }
        .text-danger { color: #e53e3e; font-weight: 600; }
        .text-warning { color: #dd6b20; font-weight: 600; }
    </style>
</head>
<body>
    <div class="recon-container">
        <div style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h1 style="margin: 0; font-size: 1.8rem;">Wallet Reconciliation Manager</h1>
                <p style="margin: 0.25rem 0 0 0; color: var(--text-muted);"><strong style="color:#e53e3e;">Isolating to Today's Transactions Only.</strong> Compare today's wallet drifts and duplicate rows since midnight against transaction logs.</p>
            </div>
            <a href="admin/dashboard.php" class="btn btn-secondary"><i class="fas fa-home"></i> Back to Dashboard</a>
        </div>

        <div class="recon-stats">
            <div class="recon-card danger">
                <h3>Today's Overcharged Exposure</h3>
                <div class="val">GHS <?php echo number_format($total_exposed_overcharged, 2); ?></div>
                <small style="color: var(--text-muted);">Money users paid today but didn't receive due to double-charges.</small>
            </div>
            <div class="recon-card warning">
                <h3>Today's Overcredited Exposure</h3>
                <div class="val">GHS <?php echo number_format($total_exposed_overcredited, 2); ?></div>
                <small style="color: var(--text-muted);">Money credited to users' duplicates without gateway deposits today.</small>
            </div>
            <div class="recon-card">
                <h3>Discrepant Users (Today)</h3>
                <div class="val"><?php echo count($reconciliation_data); ?></div>
                <small style="color: var(--text-muted);">Users with multiple wallet rows or balance drifts created today.</small>
            </div>
        </div>

        <div class="widget">
            <div class="widget-header">
                <h3 class="widget-title">Discrepancy Details</h3>
            </div>
            <div class="widget-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Username / Profile</th>
                                <th>Role</th>
                                <th>Wallet Rows</th>
                                <th>Net Wallet Bal</th>
                                <th>True Log Balance</th>
                                <th>Discrepancy Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reconciliation_data)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-success" style="padding: 2rem;">
                                        <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                        All user balances are perfectly matching transaction records!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reconciliation_data as $data): ?>
                                    <tr>
                                        <td><?php echo $data['user_id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($data['full_name'] ?: $data['username']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($data['email']); ?></small>
                                        </td>
                                        <td><span class="badge"><?php echo ucfirst($data['role']); ?></span></td>
                                        <td>
                                            <span class="badge-rows <?php echo $data['wallet_rows_count'] > 1 ? 'multi' : ''; ?>">
                                                <?php echo $data['wallet_rows_count']; ?> row(s)
                                            </span>
                                        </td>
                                        <td>GHS <?php echo number_format($data['current_balance'], 2); ?></td>
                                        <td>GHS <?php echo number_format($data['true_balance'], 2); ?></td>
                                        <td>
                                            <?php if ($data['discrepancy'] > 0): ?>
                                                <span class="text-warning"><i class="fas fa-arrow-up"></i> Overcredited by GHS <?php echo number_format(abs($data['discrepancy']), 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-danger"><i class="fas fa-arrow-down"></i> Overcharged by GHS <?php echo number_format(abs($data['discrepancy']), 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if (!empty($reconciliation_data)): ?>
            <div class="widget" style="margin-top: 2rem;">
                <div class="widget-header">
                    <h3 class="widget-title">Correction Queries</h3>
                </div>
                <div class="widget-body">
                    <p>To automatically align the wallet balances of the affected users to their true logged history (and clean up duplicate rows), you can copy and run the SQL queries below in your phpMyAdmin panel. <strong>Make sure you have completed the table unique-key migration first!</strong></p>
                    <div class="sql-box">
                        <pre>-- SQL adjustments to correct overcharged/overcredited users
<?php
foreach ($reconciliation_data as $data) {
    printf("UPDATE wallets SET balance = %01.2f WHERE user_id = %d;\n", $data['true_balance'], $data['user_id']);
}
?>
                        </pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
