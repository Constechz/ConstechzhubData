<?php
require_once '../config/config.php';

preventBrowserCaching();
requireRole('agent');
ensureResultCheckerTables();

$current_user = getCurrentUser();
$agent_id = $current_user ? (int) $current_user['id'] : 0;

if (!function_exists('dbh_stmt_fetch_all_assoc')) {
    /**
     * Fetch all rows from a prepared statement as associative arrays,
     * with a fallback when mysqlnd (get_result) is unavailable.
     */
    function dbh_stmt_fetch_all_assoc($stmt) {
        if (!$stmt) {
            return null;
        }

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                return $result->fetch_all(MYSQLI_ASSOC);
            }
            return null;
        }

        $meta = $stmt->result_metadata();
        if (!$meta) {
            return null;
        }

        $row = [];
        $bindParams = [];
        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bindParams[] = &$row[$field->name];
        }

        if (!empty($bindParams)) {
            call_user_func_array([$stmt, 'bind_result'], $bindParams);
        }

        $rows = [];
        while ($stmt->fetch()) {
            $rowData = [];
            foreach ($row as $key => $value) {
                $rowData[$key] = $value;
            }
            $rows[] = $rowData;
        }

        return $rows;
    }
}

$my_purchases = [];
if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')
    && function_exists('dbh_table_has_column') && dbh_table_has_column('result_checker_purchases', 'user_id')) {
    $purchaseColumns = ['card_type', 'amount', 'status', 'pin', 'serial_number', 'reference', 'payment_gateway', 'created_at'];
    $selectParts = [];
    foreach ($purchaseColumns as $column) {
        if (dbh_table_has_column('result_checker_purchases', $column)) {
            $selectParts[] = "`{$column}`";
        } else {
            $selectParts[] = "NULL AS `{$column}`";
        }
    }

    $orderBy = '';
    if (dbh_table_has_column('result_checker_purchases', 'created_at')) {
        $orderBy = 'created_at';
    } elseif (dbh_table_has_column('result_checker_purchases', 'id')) {
        $orderBy = 'id';
    }

    $sql = "SELECT " . implode(', ', $selectParts) . " FROM result_checker_purchases WHERE user_id = ?";
    if ($orderBy !== '') {
        $sql .= " ORDER BY `{$orderBy}` DESC";
    }

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        if ($stmt->execute()) {
            $rows = dbh_stmt_fetch_all_assoc($stmt);
            if (is_array($rows)) {
                $my_purchases = $rows;
            } else {
                error_log('Result checker history: purchases fetch failed (' . $stmt->error . ')');
            }
        } else {
            error_log('Result checker history: purchases execute failed (' . $stmt->error . ')');
        }
        $stmt->close();
    }
}

$my_sales = [];
if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')
    && function_exists('dbh_table_has_column') && dbh_table_has_column('result_checker_purchases', 'agent_id')) {
    $saleColumns = ['card_type', 'amount', 'status', 'pin', 'serial_number', 'reference', 'payment_gateway', 'created_at'];
    $selectParts = [];
    foreach ($saleColumns as $column) {
        if (dbh_table_has_column('result_checker_purchases', $column)) {
            $selectParts[] = "p.`{$column}`";
        } else {
            $selectParts[] = "NULL AS `{$column}`";
        }
    }

    $join = '';
    $customerNameExpr = 'NULL';
    $customerEmailExpr = 'NULL';
    if (dbh_table_exists('users') && dbh_table_has_column('users', 'id')) {
        $join = 'LEFT JOIN users u ON u.id = p.user_id';
        if (dbh_table_has_column('users', 'full_name')) {
            $customerNameExpr = 'u.full_name';
        }
        if (dbh_table_has_column('users', 'email')) {
            $customerEmailExpr = 'u.email';
        }
    }
    $selectParts[] = "{$customerNameExpr} AS customer_name";
    $selectParts[] = "{$customerEmailExpr} AS customer_email";

    $orderBy = '';
    if (dbh_table_has_column('result_checker_purchases', 'created_at')) {
        $orderBy = 'created_at';
    } elseif (dbh_table_has_column('result_checker_purchases', 'id')) {
        $orderBy = 'id';
    }

    $sql = "SELECT " . implode(', ', $selectParts)
        . " FROM result_checker_purchases p {$join} WHERE p.agent_id = ?";
    if ($orderBy !== '') {
        $sql .= " ORDER BY p.`{$orderBy}` DESC";
    }

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        if ($stmt->execute()) {
            $rows = dbh_stmt_fetch_all_assoc($stmt);
            if (is_array($rows)) {
                $my_sales = $rows;
            } else {
                error_log('Result checker history: sales fetch failed (' . $stmt->error . ')');
            }
        } else {
            error_log('Result checker history: sales execute failed (' . $stmt->error . ')');
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result Checker History - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .rc-table { width: 100%; border-collapse: collapse; }
        .rc-table th, .rc-table td { text-align:left; padding:0.75rem; border-bottom:1px solid #F1E9DA; font-size:0.95rem; }
        .rc-table th { color:#541388; font-weight:600; }
        .badge { padding:0.2rem 0.6rem; border-radius:999px; font-size:0.8rem; }
        .badge.success { background:#F1E9DA; color:#2E294E; }
        .badge.failed { background:#F1E9DA; color:#D90368; }
        .badge.pending { background:#F1E9DA; color:#2E294E; }
        .history-actions { display:flex; align-items:center; gap:0.5rem; }
        .copy-cell { display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; }
        .copy-btn {
            border: 1px solid #F1E9DA;
            background: #F1E9DA;
            color: #2E294E;
            border-radius: 999px;
            padding: 0.2rem 0.6rem;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .copy-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        [data-theme="dark"] .copy-btn {
            color: #2E294E !important;
            background: #F1E9DA;
            font-weight: 500;
        }
        [data-theme="dark"] .widget,
        [data-theme="dark"] .widget * {
            color: #F1E9DA;
        }
        [data-theme="dark"] .rc-table th {
            color: #F1E9DA;
        }
        [data-theme="dark"] .rc-table td::before {
            color: #F1E9DA;
        }
        [data-theme="dark"] .badge.success {
            color: #2E294E;
        }
        @media (max-width: 780px) {
            .rc-table, .rc-table thead, .rc-table tbody, .rc-table th, .rc-table td, .rc-table tr { display:block; }
            .rc-table thead { display:none; }
            .rc-table tr { margin-bottom:1rem; border:1px solid #F1E9DA; border-radius:12px; padding:0.5rem; }
            .rc-table td { border:none; display:flex; justify-content:space-between; }
            .rc-table td::before { content: attr(data-label); font-weight:600; color:#541388; }
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
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
                    <div class="nav-section-title">Services</div>
                    <div class="nav-item">
                        <a href="at-business.php" class="nav-link">
                            <i class="fas fa-mobile-alt"></i>
                            AT Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="mtn-business.php" class="nav-link">
                            <i class="fas fa-mobile-alt"></i>
                            MTN Business
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="bulk-mtn.php" class="nav-link">
                            <i class="fas fa-layer-group"></i>
                            Bulk MTN
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="result-checker.php" class="nav-link active">
                            <i class="fas fa-award"></i>
                            Result Checker
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="telecel-business.php" class="nav-link">
                            <i class="fas fa-signal"></i>
                            Telecel Business
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Transaction</div>
                    <div class="nav-item">
                        <a href="transactions.php" class="nav-link">
                            <i class="fas fa-money-bill-wave"></i>
                            Transactions
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="histories.php" class="nav-link">
                            <i class="fas fa-history"></i>
                            Data Histories
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="reference.php" class="nav-link">
                            <i class="fas fa-search"></i>
                            Reference
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Operations</div>
                    <div class="nav-item">
                        <a href="customer_topup.php" class="nav-link">
                            <i class="fas fa-user-plus"></i>
                            Customer Top-up
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="topup-requests.php" class="nav-link">
                            <i class="fas fa-hand-holding-usd"></i>
                            Topup Requests
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="support.php" class="nav-link">
                            <i class="fas fa-life-ring"></i>
                            Support
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Business</div>
                    <div class="nav-item">
                        <a href="pricing.php" class="nav-link">
                            <i class="fas fa-tags"></i>
                            Custom Pricing
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Users</div>
                    <div class="nav-item">
                        <a href="customers.php" class="nav-link">
                            <i class="fas fa-user-friends"></i>
                            Customers
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Commission</div>
                    <div class="nav-item">
                        <a href="commission.php" class="nav-link">
                            <i class="fas fa-percentage"></i>
                            Commission
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Settings</div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog"></i>
                            Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="payment-settings.php" class="nav-link">
                            <i class="fas fa-university"></i>
                            Payment Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="api-access.php" class="nav-link">
                            <i class="fas fa-key"></i>
                            API Access
                        </a>
                    </div>
                </li>
            </ul>
        </nav>
        
        <main class="main-content">
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <div class="breadcrumb-item">
                            <i class="fas fa-award"></i>
                        </div>
                        <div class="breadcrumb-item">Result Checker</div>
                        <div class="breadcrumb-item active">History</div>
                    </nav>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
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
                            <a href="wallet.php" class="dropdown-item">
                                <i class="fas fa-wallet"></i> Wallet
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
                    <h1>Result Checker History</h1>
                    <p class="page-subtitle">Track your purchases and customer sales.</p>
                </div>

                <div class="widget">
                    <div class="widget-header" style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                        <h3 class="widget-title">My Purchases</h3>
                        <div class="history-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="downloadPurchasesPdf" <?php echo empty($my_purchases) ? 'disabled' : ''; ?>>
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                        </div>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($my_purchases)): ?>
                            <p>No result checker purchases yet.</p>
                        <?php else: ?>
                            <table class="rc-table" id="purchaseTable">
                                <thead>
                                    <tr>
                                        <th>Card</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>PIN</th>
                                        <th>Serial</th>
                                        <th>Gateway</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($my_purchases as $purchase): ?>
                                    <?php
                                        $pin = trim((string) ($purchase['pin'] ?? ''));
                                        $serial = trim((string) ($purchase['serial_number'] ?? ''));
                                        $pin_display = $pin !== '' ? $pin : '-';
                                        $serial_display = $serial !== '' ? $serial : '-';
                                    ?>
                                    <tr>
                                        <td data-label="Card"><?php echo htmlspecialchars($purchase['card_type']); ?></td>
                                        <td data-label="Amount"><?php echo CURRENCY . ' ' . number_format((float) $purchase['amount'], 2); ?></td>
                                        <td data-label="Status">
                                            <?php $status = strtolower($purchase['status']); ?>
                                            <span class="badge <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                        </td>
                                        <td data-label="PIN">
                                            <div class="copy-cell">
                                                <span><?php echo htmlspecialchars($pin_display); ?></span>
                                                <button type="button" class="copy-btn" data-copy="<?php echo htmlspecialchars($pin); ?>" <?php echo $pin === '' ? 'disabled' : ''; ?>>
                                                    Copy
                                                </button>
                                            </div>
                                        </td>
                                        <td data-label="Serial">
                                            <div class="copy-cell">
                                                <span><?php echo htmlspecialchars($serial_display); ?></span>
                                                <button type="button" class="copy-btn" data-copy="<?php echo htmlspecialchars($serial); ?>" <?php echo $serial === '' ? 'disabled' : ''; ?>>
                                                    Copy
                                                </button>
                                            </div>
                                        </td>
                                        <td data-label="Gateway"><?php echo htmlspecialchars($purchase['payment_gateway'] ?? 'wallet'); ?></td>
                                        <td data-label="Date"><?php echo htmlspecialchars($purchase['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="widget">
                    <div class="widget-header" style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                        <h3 class="widget-title">Customer Sales</h3>
                        <div class="history-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="downloadSalesPdf" <?php echo empty($my_sales) ? 'disabled' : ''; ?>>
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                        </div>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($my_sales)): ?>
                            <p>No customer purchases yet.</p>
                        <?php else: ?>
                            <table class="rc-table" id="salesTable">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Card</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>PIN</th>
                                        <th>Serial</th>
                                        <th>Gateway</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($my_sales as $sale): ?>
                                    <?php
                                        $pin = trim((string) ($sale['pin'] ?? ''));
                                        $serial = trim((string) ($sale['serial_number'] ?? ''));
                                        $pin_display = $pin !== '' ? $pin : '-';
                                        $serial_display = $serial !== '' ? $serial : '-';
                                    ?>
                                    <tr>
                                        <td data-label="Customer"><?php echo htmlspecialchars($sale['customer_name'] ?: $sale['customer_email'] ?: 'Customer'); ?></td>
                                        <td data-label="Card"><?php echo htmlspecialchars($sale['card_type']); ?></td>
                                        <td data-label="Amount"><?php echo CURRENCY . ' ' . number_format((float) $sale['amount'], 2); ?></td>
                                        <td data-label="Status">
                                            <?php $status = strtolower($sale['status']); ?>
                                            <span class="badge <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                        </td>
                                        <td data-label="PIN">
                                            <div class="copy-cell">
                                                <span><?php echo htmlspecialchars($pin_display); ?></span>
                                                <button type="button" class="copy-btn" data-copy="<?php echo htmlspecialchars($pin); ?>" <?php echo $pin === '' ? 'disabled' : ''; ?>>
                                                    Copy
                                                </button>
                                            </div>
                                        </td>
                                        <td data-label="Serial">
                                            <div class="copy-cell">
                                                <span><?php echo htmlspecialchars($serial_display); ?></span>
                                                <button type="button" class="copy-btn" data-copy="<?php echo htmlspecialchars($serial); ?>" <?php echo $serial === '' ? 'disabled' : ''; ?>>
                                                    Copy
                                                </button>
                                            </div>
                                        </td>
                                        <td data-label="Gateway"><?php echo htmlspecialchars($sale['payment_gateway'] ?? 'wallet'); ?></td>
                                        <td data-label="Date"><?php echo htmlspecialchars($sale['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.5.29/dist/jspdf.plugin.autotable.min.js"></script>
    <script>
        const purchaseData = <?php echo json_encode($my_purchases); ?>;
        const salesData = <?php echo json_encode($my_sales); ?>;

        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            const sidebar = document.querySelector('.sidebar');
            if (mobileToggle && sidebar) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
        });
        
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
            if (icon) {
                icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

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
            initTheme();
        });

        document.addEventListener('click', function(event) {
            const btn = event.target.closest('.copy-btn');
            if (!btn || btn.disabled) return;
            const value = btn.getAttribute('data-copy') || '';
            if (!value) return;

            const setFeedback = () => {
                const original = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(() => {
                    btn.textContent = original;
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(setFeedback).catch(() => {
                    const textarea = document.createElement('textarea');
                    textarea.value = value;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    setFeedback();
                });
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = value;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                setFeedback();
            }
        });

        function downloadPdf(title, rows, head) {
            const jspdf = window.jspdf;
            if (!jspdf || !jspdf.jsPDF) {
                alert('PDF library failed to load. Please try again.');
                return;
            }
            const doc = new jspdf.jsPDF();
            doc.setFontSize(14);
            doc.text(title, 14, 16);
            doc.autoTable({
                head: [head],
                body: rows,
                startY: 24,
                styles: { fontSize: 8, cellPadding: 2 },
                headStyles: { fillColor: [139, 92, 246] }
            });
            doc.save(title.toLowerCase().replace(/\s+/g, '-') + '.pdf');
        }

        const downloadPurchases = document.getElementById('downloadPurchasesPdf');
        if (downloadPurchases) {
            downloadPurchases.addEventListener('click', function() {
                const rows = purchaseData.map(item => ([
                    item.card_type || '',
                    item.amount ? (<?php echo json_encode(CURRENCY); ?> + ' ' + Number(item.amount).toFixed(2)) : '',
                    item.status || '',
                    item.pin || '',
                    item.serial_number || '',
                    item.payment_gateway || 'wallet',
                    item.created_at || ''
                ]));
                downloadPdf('Result Checker Purchases', rows, ['Card', 'Amount', 'Status', 'PIN', 'Serial', 'Gateway', 'Date']);
            });
        }

        const downloadSales = document.getElementById('downloadSalesPdf');
        if (downloadSales) {
            downloadSales.addEventListener('click', function() {
                const rows = salesData.map(item => ([
                    item.customer_name || item.customer_email || 'Customer',
                    item.card_type || '',
                    item.amount ? (<?php echo json_encode(CURRENCY); ?> + ' ' + Number(item.amount).toFixed(2)) : '',
                    item.status || '',
                    item.pin || '',
                    item.serial_number || '',
                    item.payment_gateway || 'wallet',
                    item.created_at || ''
                ]));
                downloadPdf('Result Checker Sales', rows, ['Customer', 'Card', 'Amount', 'Status', 'PIN', 'Serial', 'Gateway', 'Date']);
            });
        }
    </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
