<?php
require_once '../config/config.php';
require_once '../includes/order_status.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);

// Store context for nav links
$store_slug = $_GET['store'] ?? $_POST['store'] ?? null;
$agent_store = null;
if ($store_slug) {
    $stmt = $db->prepare("
        SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE
        LIMIT 1
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $agent_store = $stmt->get_result()->fetch_assoc();
}

$lookup = trim($_POST['lookup'] ?? $_GET['lookup'] ?? '');
$results = [];
$lookup_performed = false;

if ($lookup !== '') {
    $lookup_performed = true;
    $digits = preg_replace('/\D+/', '', $lookup);
    $reference = strtoupper($lookup);

    $filters = [];
    $phone_lookup = null;

    if (strlen($digits) >= 9) {
        $filters[] = "bo.beneficiary_number = ?";
        $phone_lookup = formatPhone($digits);
    }

    $filters[] = "bo.order_reference = ?";

    $where = "bo.user_id = ? AND (" . implode(" OR ", $filters) . ")";

    $query = "
        SELECT bo.order_reference, bo.beneficiary_number,
               COALESCE(t.amount, bo.amount) AS display_amount,
               bo.status, bo.created_at,
               dp.name AS package_name, dp.data_size, n.name AS network_name
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        LEFT JOIN transactions t ON t.id = bo.transaction_id
        WHERE {$where}
        ORDER BY bo.created_at DESC
        LIMIT 30
    ";

    $stmt = $db->prepare($query);
    if ($phone_lookup !== null) {
        $stmt->bind_param("iss", $current_user['id'], $phone_lookup, $reference);
    } else {
        $stmt->bind_param("is", $current_user['id'], $reference);
    }
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $results[] = $row;
    }
}

function dbh_customer_reference_phone_display($phone) {
    $local = normalizeGhanaLocalPhone($phone);
    return $local ?: $phone;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reference Lookup - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .lookup-card {
            display: grid;
            gap: 1rem;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1rem;
        }
        .lookup-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
        }
        .lookup-meta {
            display: grid;
            gap: 0.4rem;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        .status-pill {
            padding: 0.2rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #2E294E;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php require_once '../includes/customer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-search"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Reference Lookup</div>
                </nav>
            </div>
            <div class="header-actions">
                <div class="wallet-balance">
                    <i class="fas fa-wallet"></i>
                    <span>Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?></span>
                </div>
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
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
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
                <h1>Reference Lookup</h1>
                <p class="page-subtitle">Enter the phone number or reference to check an order status.</p>
            </div>

            <div class="widget">
                <div class="widget-header">
                    <h3 class="widget-title">Search Orders</h3>
                </div>
                <div class="widget-body">
                    <form method="post">
                        <?php if ($store_slug): ?>
                            <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">Phone or Reference</label>
                            <input type="text" name="lookup" class="form-control" placeholder="0241234567 or ORD123456" value="<?php echo htmlspecialchars($lookup); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Check Status
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($lookup_performed): ?>
                <div class="widget" style="margin-top: 1.5rem;">
                    <div class="widget-header">
                        <h3 class="widget-title">Results</h3>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($results)): ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No matching orders found.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: grid; gap: 1rem;">
                                <?php foreach ($results as $order): ?>
                                    <?php $status = getOrderStatusDisplay(strtolower($order['status'] ?? 'pending')); ?>
                                    <div class="lookup-card">
                                        <div class="lookup-header">
                                            <div>
                                                <strong><?php echo htmlspecialchars($order['package_name']); ?></strong>
                                                <div style="color: var(--text-muted); font-size: 0.85rem;">
                                                    <?php echo htmlspecialchars($order['network_name']); ?> â€¢ <?php echo htmlspecialchars($order['data_size']); ?>
                                                </div>
                                            </div>
                                            <span class="status-pill" style="background: <?php echo htmlspecialchars($status['color']); ?>;">
                                                <i class="fas <?php echo htmlspecialchars($status['icon']); ?>"></i>
                                                <?php echo htmlspecialchars($status['label']); ?>
                                            </span>
                                        </div>
                                        <div class="lookup-meta">
                                            <div><strong>Reference:</strong> <?php echo htmlspecialchars($order['order_reference']); ?></div>
                                            <div><strong>Phone:</strong> <?php echo htmlspecialchars(dbh_customer_reference_phone_display($order['beneficiary_number'])); ?></div>
                                            <div><strong>Amount:</strong> <?php echo formatCurrency((float) $order['display_amount']); ?></div>
                                            <div><strong>Date:</strong> <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($order['created_at']))); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme', next);
        applyTheme(next);
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.getElementById('theme-icon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    document.addEventListener('DOMContentLoaded', function() {
        applyTheme(localStorage.getItem('theme') || 'light');

        const toggle = document.querySelector('.mobile-menu-toggle');
        if (toggle) {
            toggle.addEventListener('click', function() {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }

        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('userDropdown');
            const trigger = document.querySelector('.user-dropdown-toggle');
            if (dropdown && trigger && !trigger.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
    });
</script>
<script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

