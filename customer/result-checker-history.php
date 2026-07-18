<?php
require_once '../config/config.php';

preventBrowserCaching();
requireRole('customer');
ensureResultCheckerTables();

$current_user = getCurrentUser();
$store_slug = sanitize($_GET['store'] ?? '');

// Resolve store + linked agent (for branding)
$agent_id = 0;
$store_name = '';
$agent_store = null;
$agent_name = '';

if ($store_slug !== '') {
    $stmt = $db->prepare("
        SELECT ast.agent_id, ast.store_name, u.full_name AS agent_name
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = 1 AND u.status = 'active'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $store_slug);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $agent_store = $row;
            $agent_id = (int) $row['agent_id'];
            $store_name = (string) ($row['store_name'] ?? '');
            $agent_name = (string) ($row['agent_name'] ?? '');
        }
    }
}

if ($agent_id <= 0) {
    $agent_id = getLinkedAgentId($current_user['id']);
}

$purchases = [];
$stmt = $db->prepare("
    SELECT card_type, amount, status, pin, serial_number, reference, payment_gateway, created_at
    FROM result_checker_purchases
    WHERE user_id = ?
    ORDER BY created_at DESC
");
if ($stmt) {
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result Checker History - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <script src="../immediate_icon_fix.js"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
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
        <?php require_once '../includes/customer_sidebar.php'; ?>

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
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Customer</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>

                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="#" class="dropdown-item">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="#" class="dropdown-item">
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

<?php echo renderNotificationSlides('customers'); ?>


            <div class="dashboard-content">
                <div class="page-title">
                    <h1>Result Checker History</h1>
                    <p class="page-subtitle">Review your result checker purchases.</p>
                </div>

                <div class="widget">
                    <div class="widget-header" style="display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                        <h3 class="widget-title">Purchase History</h3>
                        <div class="history-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="downloadPdf" <?php echo empty($purchases) ? 'disabled' : ''; ?>>
                                <i class="fas fa-file-pdf"></i> Download PDF
                            </button>
                        </div>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($purchases)): ?>
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
                                <?php foreach ($purchases as $purchase): ?>
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
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.5.29/dist/jspdf.plugin.autotable.min.js"></script>
    <script>
        const purchasesData = <?php echo json_encode($purchases); ?>;

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
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }

        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');

            if (toggle && !toggle.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }

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

        const downloadBtn = document.getElementById('downloadPdf');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', function() {
                const jspdf = window.jspdf;
                if (!jspdf || !jspdf.jsPDF) {
                    alert('PDF library failed to load. Please try again.');
                    return;
                }
                const doc = new jspdf.jsPDF();
                doc.setFontSize(14);
                doc.text('Result Checker History', 14, 16);

                const rows = purchasesData.map(item => ([
                    item.card_type || '',
                    item.amount ? (<?php echo json_encode(CURRENCY); ?> + ' ' + Number(item.amount).toFixed(2)) : '',
                    item.status || '',
                    item.pin || '',
                    item.serial_number || '',
                    item.payment_gateway || 'wallet',
                    item.created_at || ''
                ]));

                doc.autoTable({
                    head: [['Card', 'Amount', 'Status', 'PIN', 'Serial', 'Gateway', 'Date']],
                    body: rows,
                    startY: 24,
                    styles: { fontSize: 8, cellPadding: 2 },
                    headStyles: { fillColor: [139, 92, 246] }
                });

                doc.save('result-checker-history.pdf');
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
        });
    </script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
