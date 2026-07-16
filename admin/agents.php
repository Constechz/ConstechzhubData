<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

// Handle agent operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ? AND role = 'agent'");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            setFlashMessage('success', 'Agent status updated successfully');
        } else {
            setFlashMessage('error', 'Failed to update agent status');
        }
        $redirectParams = [];
        if (!empty($_GET['status'])) {
            $redirectParams['status'] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $redirectParams['search'] = $_GET['search'];
        }
        if (!empty($_GET['page'])) {
            $redirectParams['page'] = (int)$_GET['page'];
        }
        $redirectUrl = 'agents.php' . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');
        header('Location: ' . $redirectUrl);
        exit();
    }
    
    if ($action === 'toggle_store_status') {
        $id = intval($_POST['id']);
        $checkStmt = $db->prepare("SELECT id FROM agent_stores WHERE agent_id = ? LIMIT 1");
        $checkStmt->bind_param('i', $id);
        $checkStmt->execute();
        $storeResult = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($storeResult) {
            $stmt = $db->prepare("UPDATE agent_stores SET admin_active = NOT COALESCE(admin_active, 1) WHERE agent_id = ?");
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                setFlashMessage('success', 'Agent store link status updated successfully');
            } else {
                setFlashMessage('error', 'Failed to update agent store link status');
            }
            $stmt->close();
        } else {
            setFlashMessage('error', 'This agent does not have a sub-store configured yet.');
        }
        
        $redirectParams = [];
        if (!empty($_GET['status'])) {
            $redirectParams['status'] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $redirectParams['search'] = $_GET['search'];
        }
        if (!empty($_GET['page'])) {
            $redirectParams['page'] = (int)$_GET['page'];
        }
        $redirectUrl = 'agents.php' . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');
        header('Location: ' . $redirectUrl);
        exit();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'agent'");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            setFlashMessage('success', 'Agent deleted successfully');
        } else {
            setFlashMessage('error', 'Failed to delete agent');
        }
        $redirectParams = [];
        if (!empty($_GET['status'])) {
            $redirectParams['status'] = $_GET['status'];
        }
        if (!empty($_GET['search'])) {
            $redirectParams['search'] = $_GET['search'];
        }
        if (!empty($_GET['page'])) {
            $redirectParams['page'] = (int)$_GET['page'];
        }
        $redirectUrl = 'agents.php' . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');
        header('Location: ' . $redirectUrl);
        exit();
    }
}

// Fetch filters
$selected_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;

// Total count for pagination
$count_query = "SELECT COUNT(*) AS total FROM users u WHERE u.role = 'agent'";
$count_params = [];
$count_types = '';

if ($selected_status !== '') {
    $is_active = $selected_status === 'active' ? 1 : 0;
    $count_query .= " AND u.is_active = ?";
    $count_params[] = $is_active;
    $count_types .= 'i';
}

if ($search !== '') {
    $count_query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_params[] = $searchTerm;
    $count_types .= 'sss';
}

if (!empty($count_params)) {
    $count_stmt = $db->prepare($count_query);
    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $count_rs = $count_stmt->get_result();
} else {
    $count_rs = $db->query($count_query);
}

$total_agents = 0;
if ($count_row = $count_rs->fetch_assoc()) {
    $total_agents = (int)$count_row['total'];
}

$total_pages = max(1, (int)ceil($total_agents / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// Fetch agents for current page (basic profile info)
$query = "
    SELECT u.id, u.username, u.full_name, u.email, u.phone, u.is_active, u.created_at,
           COALESCE((SELECT SUM(balance) FROM wallets WHERE user_id = u.id), 0) as wallet_balance,
           aps.public_key, aps.is_active as paystack_active,
           ast.store_name, ast.store_slug, ast.admin_active, ast.is_active as store_active
    FROM users u
    LEFT JOIN agent_paystack_settings aps ON aps.agent_id = u.id
    LEFT JOIN agent_stores ast ON ast.agent_id = u.id
    WHERE u.role = 'agent'
";

$params = [];
$types = '';

if ($selected_status !== '') {
    $is_active = $selected_status === 'active' ? 1 : 0;
    $query .= " AND u.is_active = ?";
    $params[] = $is_active;
    $types .= 'i';
}

if ($search !== '') {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$query .= " ORDER BY u.created_at DESC LIMIT $per_page OFFSET $offset";

if (!empty($params)) {
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $agents_rs = $stmt->get_result();
} else {
    $agents_rs = $db->query($query);
}

$agents = [];
$agent_ids = [];
while ($row = $agents_rs->fetch_assoc()) {
    $row['total_orders'] = 0;
    $row['total_transactions'] = 0;
    $row['total_commissions'] = 0;
    $agents[] = $row;
    $agent_ids[] = (int)$row['id'];
}

// Fetch performance metrics for current page only
if (!empty($agent_ids)) {
    $placeholders = implode(',', array_fill(0, count($agent_ids), '?'));

    $orders_query = "SELECT user_id, COUNT(*) AS total_orders FROM bundle_orders WHERE user_id IN ($placeholders) GROUP BY user_id";
    $orders_stmt = $db->prepare($orders_query);
    $orders_types = str_repeat('i', count($agent_ids));
    $orders_stmt->bind_param($orders_types, ...$agent_ids);
    $orders_stmt->execute();
    $orders_rs = $orders_stmt->get_result();
    $orders_map = [];
    while ($order_row = $orders_rs->fetch_assoc()) {
        $orders_map[(int)$order_row['user_id']] = (int)$order_row['total_orders'];
    }

    $transactions_query = "SELECT user_id, COUNT(*) AS total_transactions FROM transactions WHERE user_id IN ($placeholders) GROUP BY user_id";
    $transactions_stmt = $db->prepare($transactions_query);
    $transactions_types = str_repeat('i', count($agent_ids));
    $transactions_stmt->bind_param($transactions_types, ...$agent_ids);
    $transactions_stmt->execute();
    $transactions_rs = $transactions_stmt->get_result();
    $transactions_map = [];
    while ($tx_row = $transactions_rs->fetch_assoc()) {
        $transactions_map[(int)$tx_row['user_id']] = (int)$tx_row['total_transactions'];
    }

    $commission_query = "SELECT user_id, SUM(amount) AS total_commissions FROM transactions WHERE type = 'commission' AND user_id IN ($placeholders) GROUP BY user_id";
    $commission_stmt = $db->prepare($commission_query);
    $commission_types = str_repeat('i', count($agent_ids));
    $commission_stmt->bind_param($commission_types, ...$agent_ids);
    $commission_stmt->execute();
    $commission_rs = $commission_stmt->get_result();
    $commission_map = [];
    while ($commission_row = $commission_rs->fetch_assoc()) {
        $commission_map[(int)$commission_row['user_id']] = (float)$commission_row['total_commissions'];
    }

    foreach ($agents as &$agent) {
        $agent_id = (int)$agent['id'];
        $agent['total_orders'] = $orders_map[$agent_id] ?? 0;
        $agent['total_transactions'] = $transactions_map[$agent_id] ?? 0;
        $agent['total_commissions'] = $commission_map[$agent_id] ?? 0;
    }
    unset($agent);
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Management - <?php echo SITE_NAME; ?></title>
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
                    <?php renderAdminSidebar(); ?>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-user-tie"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Agent Management</div>
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
                <h1>Agent Management</h1>
                <p class="page-subtitle">Manage all agents, their performance, and payment settings.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Agents List -->
            <div class="widget">
                <div class="widget-header stacked-header">
                    <div class="widget-header-main">
                        <h3 class="widget-title">All Agents (<?php echo $total_agents; ?>)</h3>
                    </div>
                    <form method="get" class="form-inline agent-filter-form">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search agents..." class="form-control">
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $selected_status==='active'?'selected':''; ?>>Active</option>
                            <option value="inactive" <?php echo $selected_status==='inactive'?'selected':''; ?>>Inactive</option>
                        </select>
                        <input type="hidden" name="page" value="1">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                </div>
                <div class="widget-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Agent</th>
                                    <th>Status</th>
                                    <th>Wallet</th>
                                    <th>Paystack</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($agents)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No agents found</td></tr>
                            <?php else: ?>
                                <?php foreach ($agents as $agent): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $agent['id']; ?></td>
                                        <td data-label="Agent">
                                            <div class="agent-summary">
                                                <span class="agent-name"><?php echo htmlspecialchars($agent['full_name'] ?? $agent['username']); ?></span>
                                                <span class="agent-email"><?php echo htmlspecialchars($agent['email']); ?></span>
                                                <?php if (!empty($agent['phone'])): ?>
                                                    <span class="agent-phone"><?php echo htmlspecialchars($agent['phone']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($agent['store_name'])): ?>
                                                    <?php
                                                        $admin_active = isset($agent['admin_active']) ? (int)$agent['admin_active'] : 1;
                                                        $store_active = isset($agent['store_active']) ? (int)$agent['store_active'] : 1;
                                                        $store_status_badge = '';
                                                        if ($admin_active === 0) {
                                                            $store_status_badge = ' <span class="badge badge-danger" style="font-size:0.65rem; padding:1px 4px; vertical-align:middle;">Store Blocked</span>';
                                                        } elseif ($store_active === 0) {
                                                            $store_status_badge = ' <span class="badge badge-warning" style="font-size:0.65rem; padding:1px 4px; vertical-align:middle;">Store Paused</span>';
                                                        } else {
                                                            $store_status_badge = ' <span class="badge badge-success" style="font-size:0.65rem; padding:1px 4px; vertical-align:middle;">Store Active</span>';
                                                        }
                                                    ?>
                                                    <span class="agent-store">Store: <?php echo htmlspecialchars($agent['store_name']) . $store_status_badge; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php echo $agent['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $agent['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td data-label="Wallet"><?php echo CURRENCY . number_format($agent['wallet_balance'], 2); ?></td>
                                        <td data-label="Paystack">
                                            <?php if ($agent['public_key']): ?>
                                                <span class="badge badge-<?php echo $agent['paystack_active'] ? 'success' : 'warning'; ?>">
                                                    <?php echo $agent['paystack_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Not Set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions">
                                                <button type="button" class="btn btn-info btn-sm" title="View Details" onclick="openAgentDetails(<?php echo $agent['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                                                    <button type="submit" class="btn btn-<?php echo $agent['is_active'] ? 'warning' : 'success'; ?> btn-sm" title="<?php echo $agent['is_active'] ? 'Deactivate' : 'Activate'; ?> Agent">
                                                        <i class="fas fa-<?php echo $agent['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this agent?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete Agent">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                        <div class="table-pagination">
                            <div class="pagination-meta">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_agents; ?> agents)
                            </div>
                            <div class="pagination-links">
                                <?php
                                    $baseParams = [];
                                    if ($selected_status !== '') {
                                        $baseParams['status'] = $selected_status;
                                    }
                                    if ($search !== '') {
                                        $baseParams['search'] = $search;
                                    }
                                    $prevPage = max(1, $page - 1);
                                    $nextPage = min($total_pages, $page + 1);
                                ?>
                                <a class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('agents.php?' . http_build_query(array_merge($baseParams, ['page' => $prevPage]))); ?>">Previous</a>
                                <a class="pagination-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : ('agents.php?' . http_build_query(array_merge($baseParams, ['page' => $nextPage]))); ?>">Next</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
</main>
</div>

<?php foreach ($agents as $agent): ?>
    <div id="agentDetailsModal_<?php echo $agent['id']; ?>" class="modal" style="display: none;">
        <div class="modal-content modal-wide">
            <span class="close" onclick="closeAgentDetails(<?php echo $agent['id']; ?>)">&times;</span>
            <h2>Agent Details</h2>
            
            <div class="detail-grid">
                <div class="detail-card">
                    <h3>Profile</h3>
                    <dl class="detail-list">
                        <div><dt>Agent ID</dt><dd><?php echo $agent['id']; ?></dd></div>
                        <div><dt>Full Name</dt><dd><?php echo htmlspecialchars($agent['full_name'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Username</dt><dd><?php echo htmlspecialchars($agent['username']); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo htmlspecialchars($agent['email']); ?></dd></div>
                        <div><dt>Phone</dt><dd><?php echo htmlspecialchars($agent['phone'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo $agent['is_active'] ? 'Active' : 'Inactive'; ?></dd></div>
                        <div><dt>Joined</dt><dd><?php echo date('M j, Y H:i', strtotime($agent['created_at'])); ?></dd></div>
                        <div><dt>Store Name</dt><dd><?php echo htmlspecialchars($agent['store_name'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Store Slug</dt><dd><?php echo htmlspecialchars($agent['store_slug'] ?? 'N/A'); ?></dd></div>
                    </dl>
                </div>
                
                <div class="detail-card">
                    <h3>Performance</h3>
                    <dl class="detail-list">
                        <div><dt>Wallet Balance</dt><dd><?php echo CURRENCY . number_format($agent['wallet_balance'], 2); ?></dd></div>
                        <div><dt>Total Orders</dt><dd><?php echo intval($agent['total_orders']); ?></dd></div>
                        <div><dt>Total Transactions</dt><dd><?php echo intval($agent['total_transactions']); ?></dd></div>
                        <div><dt>Total Commissions</dt><dd><?php echo CURRENCY . number_format($agent['total_commissions'] ?? 0, 2); ?></dd></div>
                    </dl>
                </div>
                
                <div class="detail-card">
                    <h3>Paystack</h3>
                    <dl class="detail-list">
                        <div><dt>Status</dt><dd>
                            <?php
                                if ($agent['public_key']) {
                                    echo $agent['paystack_active'] ? 'Active' : 'Inactive';
                                } else {
                                    echo 'Not Configured';
                                }
                            ?>
                        </dd></div>
                        <div><dt>Public Key</dt><dd>
                            <?php
                                if ($agent['public_key']) {
                                    echo htmlspecialchars(substr($agent['public_key'], 0, 20)) . (strlen($agent['public_key']) > 20 ? '???' : '');
                                } else {
                                    echo '???';
                                }
                            ?>
                        </dd></div>
                    </dl>
                </div>
            </div>

            <div class="detail-actions">
                <div class="detail-form-card">
                    <h4>Agent Status</h4>
                    <form method="post" class="detail-form">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                        <p class="detail-note">Currently <?php echo $agent['is_active'] ? 'Active' : 'Inactive'; ?></p>
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-<?php echo $agent['is_active'] ? 'warning' : 'success'; ?>">
                                <?php echo $agent['is_active'] ? 'Deactivate Agent' : 'Activate Agent'; ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="detail-form-card">
                    <h4>Store Link Status</h4>
                    <?php if (empty($agent['store_name'])): ?>
                        <p class="detail-note text-muted">No store configured for this agent</p>
                    <?php else: ?>
                        <?php
                            $admin_active = isset($agent['admin_active']) ? (int)$agent['admin_active'] : 1;
                            $store_active = isset($agent['store_active']) ? (int)$agent['store_active'] : 1;
                            
                            $status_text = 'Active';
                            $status_class = 'text-success';
                            if ($admin_active === 0) {
                                $status_text = 'Blocked by Admin';
                                $status_class = 'text-danger';
                            } elseif ($store_active === 0) {
                                $status_text = 'Paused by Agent';
                                $status_class = 'text-warning';
                            }
                        ?>
                        <form method="post" class="detail-form">
                            <input type="hidden" name="action" value="toggle_store_status">
                            <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                            <p class="detail-note">Currently: <strong class="<?php echo $status_class; ?>"><?php echo $status_text; ?></strong></p>
                            <div class="form-actions compact">
                                <button type="submit" class="btn btn-<?php echo $admin_active ? 'warning' : 'success'; ?>">
                                    <?php echo $admin_active ? 'Disable Store Link' : 'Enable Store Link'; ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="detail-form-card">
                    <h4>Delete Agent</h4>
                    <form method="post" class="detail-form" onsubmit="return confirm('Are you sure you want to delete this agent?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $agent['id']; ?>">
                        <p class="detail-note">This action cannot be undone.</p>
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-danger">Delete Agent</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    
    function refreshModalOpenState() {
        const anyOpen = Array.from(document.querySelectorAll('.modal')).some(modal => modal.style.display === 'block');
        if (anyOpen) {
            document.body.classList.add('modal-open');
        } else {
            document.body.classList.remove('modal-open');
        }
    }

    function openAgentDetails(agentId) {
        const modal = document.getElementById(`agentDetailsModal_${agentId}`);
        if (modal) {
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    function closeAgentDetails(agentId) {
        const modal = document.getElementById(`agentDetailsModal_${agentId}`);
        if (modal) {
            modal.style.display = 'none';
            refreshModalOpenState();
        }
    }
    
    // Theme management - consistent across all pages
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

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
        
        window.addEventListener('click', function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                if (event.target.id && event.target.id.startsWith('agentDetailsModal_')) {
                    event.target.style.display = 'none';
                    refreshModalOpenState();
                }
            }
        });
    });
</script>

<style>
.stacked-header {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.stacked-header .widget-header-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.agent-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}

.agent-filter-form .form-control {
    min-width: 160px;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.45);
    z-index: 10000;
    display: none;
    padding: 2rem 1rem;
    overflow-y: auto;
}

.modal-open {
    overflow: hidden;
}

.modal-content {
    background: var(--card-bg, #fff);
    border-radius: 10px;
    margin: 0 auto;
    padding: 24px;
    max-width: 760px;
    box-shadow: 0 10px 40px rgba(15, 23, 42, 0.2);
    position: relative;
}

[data-theme="dark"] .modal-content {
    background: #1f2937;
    color: #e2e8f0;
}

.modal-content .close {
    position: absolute;
    top: 16px;
    right: 20px;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted, #64748b);
}

[data-theme="dark"] .modal-content .close {
    color: #cbd5f5;
}

.agent-summary {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.agent-name {
    font-weight: 600;
    color: var(--text-color, #1f2937);
}

[data-theme="dark"] .agent-name {
    color: #e2e8f0;
}

.agent-email,
.agent-phone,
.agent-store {
    font-size: 0.8rem;
    color: var(--text-muted, #64748b);
}

[data-theme="dark"] .agent-email,
[data-theme="dark"] .agent-phone,
[data-theme="dark"] .agent-store {
    color: #cbd5f5;
}

.table-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.table-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
}

.inline-form {
    display: inline;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}

.detail-card {
    background: var(--card-muted-bg, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

[data-theme="dark"] .detail-card {
    background: #1f2937;
    border-color: #374151;
}

.detail-card h3 {
    margin-top: 0;
    margin-bottom: 0.75rem;
}

.detail-list {
    margin: 0;
}

.detail-list div {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.detail-list dt {
    font-weight: 600;
    color: var(--text-muted, #64748b);
}

.detail-list dd {
    margin: 0;
    text-align: right;
    color: var(--text-color, #1f2937);
}

[data-theme="dark"] .detail-list dd {
    color: #e2e8f0;
}

.detail-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.detail-form-card {
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    padding: 1rem;
    background: var(--card-bg, #fff);
}

[data-theme="dark"] .detail-form-card {
    border-color: #374151;
    background: #111827;
}

.detail-form-card h4 {
    margin: 0 0 0.75rem 0;
}

.detail-note {
    margin: 0 0 0.75rem 0;
    color: var(--text-muted, #64748b);
    font-size: 0.85rem;
}

[data-theme="dark"] .detail-note {
    color: #cbd5f5;
}

.detail-form .form-actions.compact {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-color, #e2e8f0);
}

[data-theme="dark"] .detail-form .form-actions.compact {
    border-top-color: #4a5568;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

@media (max-width: 992px) {
    .agent-filter-form .form-control {
        min-width: 140px;
    }
}

@media (max-width: 768px) {
    html, body {
        overflow-x: hidden;
    }

    .dashboard-wrapper,
    .main-content,
    .dashboard-content {
        overflow-x: hidden;
    }

    .stacked-header .widget-header-main {
        align-items: stretch;
    }

    .agent-filter-form {
        width: 100%;
    }

    .agent-filter-form .form-control,
    .agent-filter-form .btn,
    .agent-filter-form .btn-outline-secondary {
        width: 100%;
        min-width: 0;
    }

    .table-responsive {
        overflow-x: hidden;
    }
    
    .table-responsive table,
    .table-responsive thead,
    .table-responsive tbody,
    .table-responsive th,
    .table-responsive td,
    .table-responsive tr {
        display: block;
    }

    .table-responsive thead {
        display: none;
    }

    .table-responsive tbody tr {
        margin-bottom: 1rem;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        background: var(--card-bg, #fff);
    }

    [data-theme="dark"] .table-responsive tbody tr {
        background: #1f2937;
        border-color: #374151;
    }

    .table-responsive tbody td {
        border: none;
        padding: 0.5rem 0;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 0.25rem;
        font-size: 0.85rem;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .table-responsive tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-muted, #64748b);
        font-size: 0.8rem;
    }

    .table-responsive tbody td[data-label="Actions"] {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
}
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



