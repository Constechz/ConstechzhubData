<?php
require_once '../config/config.php';

// Require agent role
requireRole('agent');
$current_user = getCurrentUser();
$agent_id = (int)$current_user['id'];

// Handle delete customer action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_customer') {
    $customer_id = intval($_POST['customer_id']);
    
    // Verify customer belongs to this agent
    $stmt = $db->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'customer' AND (agent_id = ? OR id IN (SELECT user_id FROM user_referrals WHERE agent_id = ?))");
    $stmt->bind_param('iii', $customer_id, $agent_id, $agent_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    
    if (!$customer) {
        setFlashMessage('error', 'Customer not found or you do not have permission to delete this customer.');
        header('Location: customers.php');
        exit();
    }
    
    // Begin transaction for clean deletion
    $db->getConnection()->begin_transaction();
    
    try {
        // Cancel pending orders
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending', 'processing')");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Handle wallet - keep balance record for audit
        $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $wallet_result = $stmt->get_result();
        
        if ($wallet_result->num_rows > 0) {
            $wallet = $wallet_result->fetch_assoc();
            if ($wallet['balance'] > 0) {
                // Log the deletion with balance info
                $stmt = $db->prepare("
                    INSERT INTO activity_logs (user_id, action, details, ip_address) 
                    VALUES (?, 'customer_deleted_by_agent', ?, ?)
                ");
                $details = "Customer {$customer['full_name']} deleted by agent {$current_user['full_name']} with remaining balance: " . CURRENCY . number_format($wallet['balance'], 2);
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $stmt->bind_param("iss", $agent_id, $details, $ip);
                $stmt->execute();
            }
        }
        
        // Delete wallet transactions
        $stmt = $db->prepare("DELETE FROM wallet_transactions WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Delete wallet
        $stmt = $db->prepare("DELETE FROM wallets WHERE user_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Anonymize transactions (keep for audit but remove user reference)
        $stmt = $db->prepare("UPDATE transactions SET user_id = NULL, description = CONCAT('DELETED_CUSTOMER_', ?, '_', description) WHERE user_id = ?");
        $stmt->bind_param("ii", $customer_id, $customer_id);
        $stmt->execute();
        
        // Anonymize bundle orders
        $stmt = $db->prepare("UPDATE bundle_orders SET user_id = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Delete support tickets and messages
        $stmt = $db->prepare("DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ?)");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        $stmt = $db->prepare("DELETE FROM support_tickets WHERE user_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Delete user referrals
        $stmt = $db->prepare("DELETE FROM user_referrals WHERE user_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        
        // Finally, delete the customer
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $customer_id);
        $stmt->execute();
        
        $db->getConnection()->commit();
        setFlashMessage('success', "Customer '{$customer['full_name']}' deleted successfully with all associated data cleaned up.");
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        setFlashMessage('error', 'Failed to delete customer: ' . $e->getMessage());
    }
    
    header('Location: customers.php');
    exit();
}

// Inputs
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$selected_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : null;

// Build customer list query (customers registered via this agent's store)
$customers = [];
$total_customers = 0;
// Detect schema capabilities
$conn = $db->getConnection();
$hasReferrals = false;
$hasAgentIdCol = false;
try {
    $referralsResult = $conn->query("SHOW TABLES LIKE 'user_referrals'");
    $hasReferrals = $referralsResult && $referralsResult->num_rows > 0;
    $agentIdColResult = $conn->query("SHOW COLUMNS FROM users LIKE 'agent_id'");
    $hasAgentIdCol = $agentIdColResult && $agentIdColResult->num_rows > 0;
} catch (Exception $e) { /* ignore */ }

try {
    // Count
    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($hasReferrals && $hasAgentIdCol) {
            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT u.id) AS cnt FROM users u
                 LEFT JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ?) AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"
            );
            $stmt->bind_param('iisss', $agent_id, $agent_id, $like, $like, $like);
        } elseif ($hasReferrals) {
            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT u.id) AS cnt FROM users u
                 JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND ur.agent_id = ? AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"
            );
            $stmt->bind_param('isss', $agent_id, $like, $like, $like);
        } else { // fallback to agent_id only
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS cnt FROM users u WHERE u.role = 'customer' AND u.agent_id = ? AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)"
            );
            $stmt->bind_param('isss', $agent_id, $like, $like, $like);
        }
    } else {
        if ($hasReferrals && $hasAgentIdCol) {
            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT u.id) AS cnt FROM users u
                 LEFT JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ?)"
            );
            $stmt->bind_param('ii', $agent_id, $agent_id);
        } elseif ($hasReferrals) {
            $stmt = $db->prepare(
                "SELECT COUNT(DISTINCT u.id) AS cnt FROM users u
                 JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND ur.agent_id = ?"
            );
            $stmt->bind_param('i', $agent_id);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM users u WHERE u.role = 'customer' AND u.agent_id = ?");
            $stmt->bind_param('i', $agent_id);
        }
    }
    $stmt->execute();
    $total_customers = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);

    // Page data
    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($hasReferrals && $hasAgentIdCol) {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 LEFT JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ?) 
                   AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
                 GROUP BY u.id
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iisssii', $agent_id, $agent_id, $like, $like, $like, $limit, $offset);
        } elseif ($hasReferrals) {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND ur.agent_id = ? 
                   AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
                 GROUP BY u.id
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('isssii', $agent_id, $like, $like, $like, $limit, $offset);
        } else {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 WHERE u.role = 'customer' AND u.agent_id = ? 
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iii', $agent_id, $limit, $offset);
        }
    } else {
        if ($hasReferrals && $hasAgentIdCol) {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 LEFT JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ?) 
                 GROUP BY u.id
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iiii', $agent_id, $agent_id, $limit, $offset);
        } elseif ($hasReferrals) {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 JOIN user_referrals ur ON ur.user_id = u.id
                 WHERE u.role = 'customer' AND ur.agent_id = ? 
                 GROUP BY u.id
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iii', $agent_id, $limit, $offset);
        } else {
            $stmt = $db->prepare(
                "SELECT u.id, u.full_name, u.email, u.phone, u.created_at,
                        (SELECT COUNT(*) FROM bundle_orders bo WHERE bo.user_id = u.id) AS orders_count,
                        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = u.id AND t.transaction_type = 'purchase' AND t.status = 'success') AS total_spent
                 FROM users u 
                 WHERE u.role = 'customer' AND u.agent_id = ? 
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('iii', $agent_id, $limit, $offset);
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $customers[] = $row; }
} catch (Exception $e) {
    // Fail silently but show empty state
}

// If a customer is selected, validate ownership and fetch their orders
$selected_customer = null;
$customer_orders = [];
if ($selected_customer_id) {
    if ($hasReferrals && $hasAgentIdCol) {
        $stmt = $db->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.created_at FROM users u LEFT JOIN user_referrals ur ON ur.user_id = u.id WHERE u.id = ? AND u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ?) LIMIT 1");
        $stmt->bind_param('iii', $selected_customer_id, $agent_id, $agent_id);
    } elseif ($hasReferrals) {
        $stmt = $db->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.created_at FROM users u JOIN user_referrals ur ON ur.user_id = u.id WHERE u.id = ? AND u.role = 'customer' AND ur.agent_id = ? LIMIT 1");
        $stmt->bind_param('ii', $selected_customer_id, $agent_id);
    } else {
        $stmt = $db->prepare("SELECT id, full_name, email, phone, created_at FROM users WHERE id = ? AND role = 'customer' AND agent_id = ? LIMIT 1");
        $stmt->bind_param('ii', $selected_customer_id, $agent_id);
    }
    $stmt->execute();
    $selected_customer = $stmt->get_result()->fetch_assoc();

    if ($selected_customer) {
        $stmt = $db->prepare(
            "SELECT bo.id, bo.order_reference, bo.beneficiary_number, bo.amount, bo.status, bo.created_at,
                    dp.name AS package_name, n.name AS network
             FROM bundle_orders bo
             JOIN data_packages dp ON dp.id = bo.package_id
             JOIN networks n ON n.id = dp.network_id
             WHERE bo.user_id = ?
             ORDER BY bo.created_at DESC
             LIMIT 50"
        );
        $stmt->bind_param('i', $selected_customer_id);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $customer_orders[] = $row; }
    }
}

$total_pages = max(1, (int)ceil($total_customers / $limit));

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - <?php echo SITE_NAME; ?></title>
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
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Users</div>
                <div class="nav-item">
                    <a href="customers.php" class="nav-link active">
                        <i class="fas fa-user-friends"></i>
                        Customers
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Transaction</div>
                <div class="nav-item">
                    <a href="histories.php" class="nav-link">
                        <i class="fas fa-history"></i>
                        Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
                <div class="nav-item">
                    <a href="result-checker.php" class="nav-link">
                        <i class="fas fa-award"></i>
                        Result Checker
                    </a>
                </div>
            </li>
        </ul>
                    <div class="nav-item">
                        <a href="withdraw-profit.php" class="nav-link">
                            <i class="fas fa-wallet"></i>
                            Withdraw Profit
                        </a>
                    </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-users"></i></div>
                    <div class="breadcrumb-item">Users</div>
                    <div class="breadcrumb-item active">Customers</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
            </div>
        </header>

<?php echo renderNotificationSlides('agents'); ?>


        <div class="dashboard-content">
            <div class="page-title">
                <h1>Customers</h1>
                <p class="page-subtitle">Customers registered via your store</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="widget">
                <div class="widget-body">
                    <form method="GET" action="" style="display:flex; gap:.5rem; flex-wrap: wrap; align-items:center;">
                        <input type="text" name="q" class="form-control" placeholder="Search by name, email or phone" value="<?php echo htmlspecialchars($q); ?>" style="flex:1; min-width: 240px;">
                        <?php if ($selected_customer_id): ?>
                            <input type="hidden" name="customer_id" value="<?php echo (int)$selected_customer_id; ?>">
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Search</button>
                        <a href="customers.php" class="btn btn-outline"><i class="fas fa-times"></i> Reset</a>
                    </form>
                </div>
            </div>

            <div class="dashboard-grid" style="grid-template-columns: 1fr 1.3fr;">
                <!-- Customers list -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Customers (<?php echo number_format($total_customers); ?>)</h3>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($customers)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <p>No customers found.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>Orders</th>
                                            <th>Total Spent</th>
                                            <th>Joined</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers as $c): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($c['full_name']); ?></td>
                                            <td>
                                                <div style="display:flex; flex-direction:column;">
                                                    <span><?php echo htmlspecialchars($c['email']); ?></span>
                                                    <span style="color:var(--text-muted); font-size:.85rem;"><?php echo htmlspecialchars($c['phone']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo (int)$c['orders_count']; ?></td>
                                            <td><?php echo formatCurrency((float)$c['total_spent']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                                            <td>
                                                <div style="display: flex; gap: 0.25rem;">
                                                    <a class="btn btn-outline btn-sm" href="customers.php?customer_id=<?php echo (int)$c['id']; ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this customer? This action cannot be undone and will remove all their data.')">
                                                        <input type="hidden" name="action" value="delete_customer">
                                                        <input type="hidden" name="customer_id" value="<?php echo (int)$c['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete Customer">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div style="display:flex; gap:.5rem; justify-content:flex-end; margin-top: .75rem;">
                                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                    <?php 
                                        $params = $_GET; 
                                        $params['page'] = $p; 
                                        $url = 'customers.php?' . http_build_query($params);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>" class="btn <?php echo $p === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $p; ?></a>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Orders detail -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">
                            <?php if ($selected_customer): ?>
                                Orders for <?php echo htmlspecialchars($selected_customer['full_name']); ?>
                            <?php else: ?>
                                Orders
                            <?php endif; ?>
                        </h3>
                    </div>
                    <div class="widget-body">
                        <?php if (!$selected_customer): ?>
                            <div class="empty-state">
                                <i class="fas fa-receipt"></i>
                                <p>Select a customer to view their recent orders</p>
                            </div>
                        <?php else: ?>
                            <?php if (empty($customer_orders)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-invoice"></i>
                                    <p>No orders yet for this customer.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Package</th>
                                                <th>MSISDN</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($customer_orders as $o): ?>
                                            <tr>
                                                <td><?php echo str_pad($o['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                                <td>
                                                    <div style="display:flex; flex-direction:column;">
                                                        <span><?php echo htmlspecialchars($o['package_name']); ?></span>
                                                        <span style="color:var(--text-muted); font-size:.85rem;"><?php echo htmlspecialchars($o['network']); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($o['beneficiary_number']); ?></td>
                                                <td><?php echo formatCurrency($o['amount']); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $o['status'] === 'success' ? 'success' : ($o['status'] === 'failed' ? 'danger' : 'warning'); ?>">
                                                        <?php echo ucfirst($o['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($o['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Theme management (reuse from dashboard)
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
    if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}

document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
