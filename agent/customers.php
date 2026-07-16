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
$customers_by_id = [];
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
                   AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
                 ORDER BY u.created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->bind_param('isssii', $agent_id, $like, $like, $like, $limit, $offset);
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
    while ($row = $res->fetch_assoc()) {
        $customers[] = $row;
        $customers_by_id[(int) $row['id']] = $row;
    }
} catch (Exception $e) {
    // Fail silently but show empty state
}

// If a customer is selected, validate ownership and fetch their orders
$selected_customer = null;
$customer_orders = [];
$selection_error = '';
if ($selected_customer_id) {
    if ($hasAgentIdCol && $hasReferrals) {
        $stmt = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.created_at
            FROM users u
            WHERE u.id = ? AND u.role = 'customer'
              AND (u.agent_id = ? OR EXISTS (
                    SELECT 1 FROM user_referrals ur
                    WHERE ur.user_id = u.id AND ur.agent_id = ?
              ))
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('iii', $selected_customer_id, $agent_id, $agent_id);
            $stmt->execute();
            $selected_customer = $stmt->get_result()->fetch_assoc();
        }
    } elseif ($hasAgentIdCol) {
        $stmt = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.created_at
            FROM users u
            WHERE u.id = ? AND u.role = 'customer' AND u.agent_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $selected_customer_id, $agent_id);
            $stmt->execute();
            $selected_customer = $stmt->get_result()->fetch_assoc();
        }
    } elseif ($hasReferrals) {
        $stmt = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.phone, u.created_at
            FROM users u
            WHERE u.id = ? AND u.role = 'customer'
              AND EXISTS (
                    SELECT 1 FROM user_referrals ur
                    WHERE ur.user_id = u.id AND ur.agent_id = ?
              )
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $selected_customer_id, $agent_id);
            $stmt->execute();
            $selected_customer = $stmt->get_result()->fetch_assoc();
        }
    }

    if ($selected_customer) {
        $stmt = $db->prepare(
            "SELECT bo.id, bo.order_reference, bo.beneficiary_number, bo.amount, bo.status, bo.created_at,
                    COALESCE(dp.name, CONCAT('Package #', bo.package_id)) AS package_name,
                    COALESCE(n.name, 'Unknown') AS network
             FROM bundle_orders bo
             LEFT JOIN data_packages dp ON dp.id = bo.package_id
             LEFT JOIN networks n ON n.id = dp.network_id
             WHERE bo.user_id = ?
             ORDER BY bo.created_at DESC
             LIMIT 50"
        );
        $stmt->bind_param('i', $selected_customer_id);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $customer_orders[] = $row; }
    } else {
        // Fallback: if the customer exists in current list, allow selecting from list data.
        if (isset($customers_by_id[$selected_customer_id])) {
            $selected_customer = $customers_by_id[$selected_customer_id];

            $stmt = $db->prepare(
                "SELECT bo.id, bo.order_reference, bo.beneficiary_number, bo.amount, bo.status, bo.created_at,
                        COALESCE(dp.name, CONCAT('Package #', bo.package_id)) AS package_name,
                        COALESCE(n.name, 'Unknown') AS network
                 FROM bundle_orders bo
                 LEFT JOIN data_packages dp ON dp.id = bo.package_id
                 LEFT JOIN networks n ON n.id = dp.network_id
                 WHERE bo.user_id = ?
                 ORDER BY bo.created_at DESC
                 LIMIT 50"
            );
            $stmt->bind_param('i', $selected_customer_id);
            $stmt->execute();
            $r = $stmt->get_result();
            while ($row = $r->fetch_assoc()) { $customer_orders[] = $row; }
        } else {
            $selection_error = 'Selected customer could not be loaded. They may not belong to your account.';
        }
    }
}

$total_pages = max(1, (int)ceil($total_customers / $limit));

$flash = getFlashMessage();
$agent_wallet_balance = (float) getWalletBalance($agent_id);
$csrf_token = $_SESSION['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrf_token;
}
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
        <?php renderAgentSidebar(); ?>
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
            <div class="header-actions" style="display:flex; align-items:center; gap:0.75rem;">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>

                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name'] ?? 'Agent'); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>

                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
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
                                        <?php
                                            $view_params = [];
                                            if ($q !== '') {
                                                $view_params['q'] = $q;
                                            }
                                            $view_params['page'] = $page;
                                            $view_params['customer_id'] = (int) $c['id'];
                                            $view_url = 'customers.php?' . http_build_query($view_params);
                                            $is_selected = $selected_customer_id === (int) $c['id'];
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($view_url); ?>" style="font-weight:600; color: var(--text-primary); text-decoration: none;">
                                                    <?php echo htmlspecialchars($c['full_name']); ?>
                                                </a>
                                                <?php if ($is_selected): ?>
                                                    <span class="badge badge-info" style="margin-left:.35rem;">Selected</span>
                                                <?php endif; ?>
                                            </td>
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
                                                    <button class="btn btn-success btn-sm topup-btn" data-customer-id="<?php echo (int)$c['id']; ?>" data-customer-name="<?php echo htmlspecialchars($c['full_name']); ?>" title="Top Up Customer">
                                                        <i class="fas fa-wallet"></i> Top Up
                                                    </button>
                                                    <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($view_url); ?>">
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
                        <form method="GET" action="" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom: 1rem;">
                            <?php if ($q !== ''): ?>
                                <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="page" value="<?php echo (int) $page; ?>">
                            <select name="customer_id" class="form-control" style="min-width: 260px;">
                                <option value="">Select customer...</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo ($selected_customer_id === (int) $c['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> Load Orders
                            </button>
                        </form>

                        <?php if ($selection_error !== ''): ?>
                            <div class="alert alert-warning" style="margin-bottom: 1rem;">
                                <?php echo htmlspecialchars($selection_error); ?>
                            </div>
                        <?php endif; ?>
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
function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
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
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
</script>
    <!-- Top Up Modal -->
    <div id="topupModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width:480px;">
            <div class="modal-header">
                <h3><i class="fas fa-wallet"></i> Top Up Customer Wallet</h3>
                <button type="button" class="modal-close" onclick="closeTopupModal()">&times;</button>
            </div>
            <form id="topupForm" onsubmit="return submitTopup(event)">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" id="topup_customer_id" name="customer_id" value="">
                <div class="modal-body">
                    <div id="topupInfo" style="margin-bottom:1rem;padding:0.75rem;background:var(--bg-secondary);border-radius:0.5rem;border:1px solid var(--border-color);">
                        <strong>Customer:</strong> <span id="topupCustomerName"></span>
                    </div>
                    <div style="margin-bottom:1rem;padding:0.75rem;background:var(--bg-secondary);border-radius:0.5rem;border:1px solid var(--border-color);">
                        <strong>Your Wallet Balance:</strong> <?php echo formatCurrency($agent_wallet_balance); ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="topup_amount">Amount (<?php echo CURRENCY; ?>) *</label>
                        <input type="number" id="topup_amount" class="form-control" step="0.01" min="0.01" max="<?php echo max(0, $agent_wallet_balance); ?>" placeholder="Enter amount" required>
                        <small class="text-muted">Enter the amount to credit the customer's wallet.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="topup_note">Note (optional)</label>
                        <input type="text" id="topup_note" class="form-control" placeholder="e.g. Manual top-up">
                    </div>
                    <div id="topupError" style="display:none;" class="alert alert-danger"></div>
                    <div id="topupSuccess" style="display:none;" class="alert alert-success"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeTopupModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="topupSubmitBtn">
                        <i class="fas fa-check"></i> Confirm Top Up
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .modal {
        position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
        background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
    }
    .modal-content {
        background-color: var(--bg-primary); margin: 10% auto; border-radius: 12px;
        width: 90%; max-width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    [data-theme="dark"] .modal-content {
        background-color: var(--bg-primary,#1a202c);
    }
    .modal-header {
        padding: 1.5rem 1.5rem 0; display: flex; justify-content: space-between;
        align-items: center; border-bottom: 1px solid var(--border-color); margin-bottom: 1rem;
    }
    .modal-header h3 { margin: 0; color: var(--text-color); }
    .modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--text-muted); }
    .modal-body { padding: 0 1.5rem 1rem; }
    .modal-footer { padding: 1rem 1.5rem 1.5rem; display:flex; justify-content:flex-end; gap:1rem; border-top:1px solid var(--border-color); }
    .form-group { margin-bottom: 1rem; }
    .form-label { display:block; margin-bottom:0.5rem; font-weight:500; color:var(--text-primary); font-size:0.875rem; }
    .btn-success { background-color:#10b981; color:white; border:1px solid #059669; }
    .btn-success:hover { background-color:#059669; }
    [data-theme="dark"] .btn-success { background-color:#059669; border-color:#047857; }
    [data-theme="dark"] .btn-success:hover { background-color:#047857; }
    </style>

    <script>
    function openTopupModal(customerId, customerName) {
        document.getElementById('topup_customer_id').value = customerId;
        document.getElementById('topupCustomerName').textContent = customerName;
        document.getElementById('topup_amount').value = '';
        document.getElementById('topup_note').value = '';
        document.getElementById('topupError').style.display = 'none';
        document.getElementById('topupSuccess').style.display = 'none';
        document.getElementById('topupModal').style.display = 'block';
        document.getElementById('topup_amount').focus();
    }

    function closeTopupModal() {
        document.getElementById('topupModal').style.display = 'none';
    }

    function submitTopup(event) {
        event.preventDefault();
        var btn = document.getElementById('topupSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Processing...';

        var errorDiv = document.getElementById('topupError');
        var successDiv = document.getElementById('topupSuccess');
        errorDiv.style.display = 'none';
        successDiv.style.display = 'none';

        var customerId = document.getElementById('topup_customer_id').value;
        var amount = document.getElementById('topup_amount').value;
        var note = document.getElementById('topup_note').value;
        var csrf = document.querySelector('input[name="csrf_token"]').value;

        if (!customerId || !amount || parseFloat(amount) <= 0) {
            errorDiv.textContent = 'Please enter a valid amount.';
            errorDiv.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Confirm Top Up';
            return;
        }

        var formData = new FormData();
        formData.append('action', 'agent_to_customer');
        formData.append('customer_id', customerId);
        formData.append('amount', amount);
        formData.append('note', note);
        formData.append('csrf_token', csrf);

        fetch('../api/manual_topup.php', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf
            },
            body: new URLSearchParams(formData)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                successDiv.innerHTML = '<i class="fas fa-check-circle"></i> ' + data.message + '<br><small>Ref: ' + data.reference + '</small>';
                successDiv.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Top Up Completed';
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                errorDiv.textContent = data.message || 'Top up failed. Please try again.';
                errorDiv.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Confirm Top Up';
            }
        })
        .catch(function(err) {
            errorDiv.textContent = 'Network error. Please try again.';
            errorDiv.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Confirm Top Up';
            console.error(err);
        });

        return false;
    }

    document.addEventListener('click', function(event) {
        var btn = event.target.closest('.topup-btn');
        if (btn) {
            event.preventDefault();
            openTopupModal(btn.dataset.customerId, btn.dataset.customerName);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeTopupModal();
        }
    });

    window.addEventListener('click', function(event) {
        var modal = document.getElementById('topupModal');
        if (event.target === modal) {
            closeTopupModal();
        }
    });
    </script>

    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>

