<?php
require_once '../config/config.php';

requireRole('admin');

function userAccessFetchAll($sql, $types = '', array $params = []) {
    global $db;
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function userAccessFetchRow($sql, $types = '', array $params = [], array $defaults = []) {
    $rows = userAccessFetchAll($sql, $types, $params);
    if (empty($rows)) {
        return $defaults;
    }
    return array_merge($defaults, $rows[0]);
}

function userAccessBuildQuery(array $params) {
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $clean[$k] = $v;
    }
    return http_build_query($clean);
}

$has_logs = function_exists('dbh_table_exists') ? dbh_table_exists('activity_logs') : true;

$search = trim((string) ($_GET['search'] ?? ''));
$role = strtolower(trim((string) ($_GET['role'] ?? 'all')));
$date_from = sanitize($_GET['date_from'] ?? '');
$date_to = sanitize($_GET['date_to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$allowed_roles = ['all', 'admin', 'agent', 'customer', 'super_admin'];
if (!in_array($role, $allowed_roles, true)) {
    $role = 'all';
}

$stats = [
    'today_logins' => 0,
    'today_unique_users' => 0,
    'week_logins' => 0,
    'active_24h_users' => 0,
];
$entries = [];
$top_ips = [];
$role_breakdown = [];
$total_rows = 0;

if ($has_logs) {
    $stats = userAccessFetchRow("
        SELECT
            SUM(CASE WHEN action = 'login' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_logins,
            COUNT(DISTINCT CASE WHEN action = 'login' AND DATE(created_at) = CURDATE() THEN user_id END) AS today_unique_users,
            SUM(CASE WHEN action = 'login' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week_logins,
            COUNT(DISTINCT CASE WHEN action = 'login' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN user_id END) AS active_24h_users
        FROM activity_logs
    ", '', [], $stats);

    $top_ips = userAccessFetchAll("
        SELECT COALESCE(NULLIF(ip_address, ''), 'unknown') AS ip_address, COUNT(*) AS total
        FROM activity_logs
        WHERE action = 'login'
        GROUP BY COALESCE(NULLIF(ip_address, ''), 'unknown')
        ORDER BY total DESC
        LIMIT 8
    ");

    $role_breakdown = userAccessFetchAll("
        SELECT COALESCE(NULLIF(LOWER(u.role), ''), 'unknown') AS role_name, COUNT(*) AS total
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.action = 'login'
        GROUP BY COALESCE(NULLIF(LOWER(u.role), ''), 'unknown')
        ORDER BY total DESC
    ");

    $where = ["al.action = 'login'"];
    $types = '';
    $params = [];

    if ($role !== 'all') {
        $where[] = 'LOWER(u.role) = ?';
        $types .= 's';
        $params[] = $role;
    }
    if ($date_from !== '') {
        $where[] = 'DATE(al.created_at) >= ?';
        $types .= 's';
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where[] = 'DATE(al.created_at) <= ?';
        $types .= 's';
        $params[] = $date_to;
    }
    if ($search !== '') {
        $where[] = "(
            u.full_name LIKE ? OR
            u.username LIKE ? OR
            u.email LIKE ? OR
            u.phone LIKE ? OR
            u.mobile LIKE ? OR
            al.ip_address LIKE ? OR
            al.user_agent LIKE ?
        )";
        $like = '%' . $search . '%';
        $types .= 'sssssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $where_sql = implode(' AND ', $where);

    $count_row = userAccessFetchRow("
        SELECT COUNT(*) AS total_rows
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE {$where_sql}
    ", $types, $params, ['total_rows' => 0]);
    $total_rows = (int) ($count_row['total_rows'] ?? 0);

    $list_sql = "
        SELECT
            al.id,
            al.user_id,
            al.details,
            al.ip_address,
            al.user_agent,
            al.created_at,
            u.full_name,
            u.username,
            u.email,
            u.role,
            u.status,
            COALESCE(NULLIF(u.mobile, ''), NULLIF(u.phone, ''), '-') AS phone
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE {$where_sql}
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $entries_params = $params;
    $entries_params[] = $limit;
    $entries_params[] = $offset;
    $entries_types = $types . 'ii';
    $entries = userAccessFetchAll($list_sql, $entries_types, $entries_params);
}

$total_pages = max(1, (int) ceil(($total_rows > 0 ? $total_rows : 1) / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
}

$query_base = [
    'search' => $search,
    'role' => $role,
    'date_from' => $date_from,
    'date_to' => $date_to
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Access - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        body,
        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .widget,
        .widget-body {
            min-width: 0;
        }
        .user-access-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1rem; }
        .filters-grid { display: grid; grid-template-columns: repeat(5, minmax(140px,1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        .table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table-wrap table { width: 100%; min-width: 980px; }
        .table-wrap.small table { min-width: 430px; }
        .table-wrap.logs table { min-width: 980px; }
        .table-wrap thead th { position: sticky; top: 0; z-index: 2; background: var(--bg-primary, #F1E9DA); }
        .table-wrap th,
        .table-wrap td {
            white-space: nowrap;
            vertical-align: top;
        }
        .user-cell,
        .user-cell small,
        .device-cell {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .device-cell {
            max-width: 340px;
            line-height: 1.3;
        }
        .header-actions { gap: 0.5rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.82rem; }
        @media (max-width: 1200px) {
            .filters-grid { grid-template-columns: repeat(3, minmax(120px,1fr)); }
        }
        @media (max-width: 1024px) {
            .filters-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .user-access-grid { grid-template-columns: 1fr; }
            .header-actions { flex-wrap: wrap; justify-content: flex-end; }
        }
        @media (max-width: 768px) {
            .dashboard-content { padding: 0.75rem; }
            .filters-grid { grid-template-columns: 1fr; }
            .header-actions { width: 100%; justify-content: flex-end; }
            .header-actions .btn { padding: 0.45rem 0.6rem; font-size: 0.82rem; }
            .table-wrap.logs table { min-width: 860px; }
            .table-wrap.small table { min-width: 380px; }
            .table-wrap th,
            .table-wrap td { font-size: 0.88rem; padding: 0.55rem 0.45rem; }
            .device-cell { max-width: 260px; }
        }
        @media (max-width: 480px) {
            .header-actions .btn .action-text { display: none; }
            .header-actions .btn { min-width: 42px; min-height: 42px; padding: 0.45rem; }
            .table-wrap.logs table { min-width: 820px; }
            .table-wrap.small table { min-width: 340px; }
            .table-wrap th,
            .table-wrap td { font-size: 0.84rem; padding: 0.5rem 0.4rem; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <nav class="sidebar">
        <div class="sidebar-brand"><h3><?php echo htmlspecialchars(getSiteName()); ?></h3></div>
        <ul class="sidebar-nav">
            <li class="nav-section"><div class="nav-section-title">Dashboard</div><div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div></li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="data-histories.php" class="nav-link"><i class="fas fa-database"></i> Data Histories</a></div>
                <div class="nav-item"><a href="user-access.php" class="nav-link active"><i class="fas fa-user-shield"></i> User Access</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
            </li>
            <li class="nav-section"><div class="nav-section-title">Management</div><div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div></li>
            <li class="nav-section"><div class="nav-section-title">Quick Links</div><div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div><div class="nav-item"><a href="support.php" class="nav-link"><i class="fas fa-life-ring"></i> Support</a></div></li>
        </ul>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-user-shield"></i></div>
                    <div class="breadcrumb-item active">User Access</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
                <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> <span class="action-text">Dashboard</span></a>
                <a href="../logout.php" class="btn btn-danger btn-sm"><i class="fas fa-sign-out-alt"></i> <span class="action-text">Logout</span></a>
            </div>
        </header>

        <div class="dashboard-content">
            <?php if (!$has_logs): ?>
                <div class="alert alert-warning">`activity_logs` table is missing. Login monitoring is unavailable until this table exists.</div>
            <?php endif; ?>

            <div class="widget">
                <div class="widget-header">
                    <h1 class="widget-title">User Access Monitor</h1>
                    <p class="widget-subtitle">Track users that logged into the system with time, role, IP and device.</p>
                </div>
                <div class="widget-body">
                    <div class="stats-grid" style="margin-bottom:1rem;">
                        <div class="stat-card"><div class="stat-value"><?php echo number_format((int) $stats['today_logins']); ?></div><div class="stat-label">Today Logins</div></div>
                        <div class="stat-card"><div class="stat-value"><?php echo number_format((int) $stats['today_unique_users']); ?></div><div class="stat-label">Today Unique Users</div></div>
                        <div class="stat-card"><div class="stat-value"><?php echo number_format((int) $stats['week_logins']); ?></div><div class="stat-label">Last 7 Days Logins</div></div>
                        <div class="stat-card"><div class="stat-value"><?php echo number_format((int) $stats['active_24h_users']); ?></div><div class="stat-label">Active Users (24h)</div></div>
                    </div>

                    <form method="get" class="filters-grid">
                        <div class="form-group"><label class="form-label">Search</label><input class="form-control" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, phone, IP"></div>
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <select class="form-control" name="role">
                                <option value="all" <?php echo $role === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                <option value="super_admin" <?php echo $role === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                <option value="agent" <?php echo $role === 'agent' ? 'selected' : ''; ?>>Agent</option>
                                <option value="customer" <?php echo $role === 'customer' ? 'selected' : ''; ?>>Customer</option>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Date From</label><input class="form-control" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
                        <div class="form-group"><label class="form-label">Date To</label><input class="form-control" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
                        <div class="form-group" style="display:flex;align-items:flex-end;gap:0.5rem;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                            <a href="user-access.php" class="btn btn-outline">Reset</a>
                        </div>
                    </form>

                    <div class="user-access-grid">
                        <div class="widget">
                            <div class="widget-header"><h3 class="widget-title">Top Login IPs</h3></div>
                            <div class="widget-body">
                                <div class="table-wrap small">
                                    <table class="table">
                                        <thead><tr><th>IP Address</th><th>Logins</th></tr></thead>
                                        <tbody>
                                        <?php if (empty($top_ips)): ?>
                                            <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
                                        <?php else: foreach ($top_ips as $ip): ?>
                                            <tr><td class="mono"><?php echo htmlspecialchars($ip['ip_address'] ?? 'unknown'); ?></td><td><?php echo number_format((int) ($ip['total'] ?? 0)); ?></td></tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="widget">
                            <div class="widget-header"><h3 class="widget-title">Role Breakdown</h3></div>
                            <div class="widget-body">
                                <div class="table-wrap small">
                                    <table class="table">
                                        <thead><tr><th>Role</th><th>Logins</th></tr></thead>
                                        <tbody>
                                        <?php if (empty($role_breakdown)): ?>
                                            <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
                                        <?php else: foreach ($role_breakdown as $rb): ?>
                                            <tr><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($rb['role_name'] ?? 'unknown')))); ?></td><td><?php echo number_format((int) ($rb['total'] ?? 0)); ?></td></tr>
                                        <?php endforeach; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="widget" style="margin-top:1rem;">
                        <div class="widget-header"><h3 class="widget-title">Recent Login Records</h3><p class="widget-subtitle">Showing <?php echo number_format(count($entries)); ?> of <?php echo number_format($total_rows); ?> records</p></div>
                        <div class="widget-body">
                            <div class="table-wrap logs">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>User</th>
                                            <th>Role</th>
                                            <th>Phone</th>
                                            <th>IP Address</th>
                                            <th>Device</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($entries)): ?>
                                        <tr><td colspan="7" class="text-center text-muted">No login activity found.</td></tr>
                                    <?php else: foreach ($entries as $entry): ?>
                                        <?php
                                        $name = trim((string) ($entry['full_name'] ?? ''));
                                        if ($name === '') {
                                            $name = trim((string) ($entry['username'] ?? ''));
                                        }
                                        if ($name === '') {
                                            $name = 'User #' . (int) ($entry['user_id'] ?? 0);
                                        }
                                        $ua = (string) ($entry['user_agent'] ?? '');
                                        $uaShort = strlen($ua) > 90 ? (substr($ua, 0, 90) . '...') : $ua;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime((string) ($entry['created_at'] ?? 'now')))); ?></td>
                                            <td class="user-cell">
                                                <div><?php echo htmlspecialchars($name); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars((string) ($entry['email'] ?? 'N/A')); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($entry['role'] ?? 'unknown')))); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($entry['phone'] ?? '-')); ?></td>
                                            <td class="mono"><?php echo htmlspecialchars((string) ($entry['ip_address'] ?? 'unknown')); ?></td>
                                            <td class="device-cell" title="<?php echo htmlspecialchars($ua); ?>"><?php echo htmlspecialchars($uaShort !== '' ? $uaShort : 'Unknown device'); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($entry['status'] ?? 'unknown')); ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_pages > 1): ?>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.75rem;">
                                    <span class="text-muted">Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?></span>
                                    <div style="display:flex;gap:0.5rem;">
                                        <?php if ($page > 1): $prev = $query_base; $prev['page'] = $page - 1; ?>
                                            <a class="btn btn-outline btn-sm" href="user-access.php?<?php echo htmlspecialchars(userAccessBuildQuery($prev)); ?>">Previous</a>
                                        <?php endif; ?>
                                        <?php if ($page < $total_pages): $next = $query_base; $next['page'] = $page + 1; ?>
                                            <a class="btn btn-outline btn-sm" href="user-access.php?<?php echo htmlspecialchars(userAccessBuildQuery($next)); ?>">Next</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
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
        icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    const menuButton = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const closeSidebar = function() {
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
    };
    if (menuButton && sidebar) {
        menuButton.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show');
        });
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });
});
</script>
</body>
</html>
