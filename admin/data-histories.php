<?php
require_once '../config/config.php';

requireRole('admin');
ensureResultCheckerTables();

$has_table_exists = function_exists('dbh_table_exists');
$has_column_exists = function_exists('dbh_table_has_column');

$has_bundle_orders = $has_table_exists ? dbh_table_exists('bundle_orders') : true;
$has_result_checker = $has_table_exists ? dbh_table_exists('result_checker_purchases') : true;
$has_api_logs = $has_table_exists ? dbh_table_exists('api_transaction_logs') : false;
$has_api_providers = $has_table_exists ? dbh_table_exists('api_providers') : false;
$has_rc_gateway = $has_result_checker && $has_column_exists ? dbh_table_has_column('result_checker_purchases', 'payment_gateway') : true;

$data_stats = ['total_orders' => 0, 'successful_orders' => 0, 'pending_orders' => 0, 'failed_orders' => 0, 'total_amount' => 0.0];
$checker_stats = ['total_orders' => 0, 'successful_orders' => 0, 'pending_orders' => 0, 'failed_orders' => 0, 'total_amount' => 0.0];
$data_providers = [];
$checker_providers = [];
$recent_rows = [];
$provider_name_by_id = [];
$provider_name_by_slug = [];

if ($has_api_providers) {
    $provider_lookup_sql = "SELECT id, name, slug FROM api_providers";
    $provider_lookup_result = $db->query($provider_lookup_sql);
    if ($provider_lookup_result) {
        while ($provider_row = $provider_lookup_result->fetch_assoc()) {
            $provider_id = (int)($provider_row['id'] ?? 0);
            $provider_name = trim((string)($provider_row['name'] ?? ''));
            $provider_slug = strtolower(trim((string)($provider_row['slug'] ?? '')));
            if ($provider_id > 0 && $provider_name !== '') {
                $provider_name_by_id[$provider_id] = $provider_name;
            }
            if ($provider_slug !== '' && $provider_name !== '') {
                $provider_name_by_slug[$provider_slug] = $provider_name;
            }
        }
    }
}

$resolve_provider_from_api_response = function ($api_response) use ($provider_name_by_id, $provider_name_by_slug) {
    if (!is_string($api_response) || trim($api_response) === '') {
        return '';
    }

    $decoded = json_decode($api_response, true);
    if (!is_array($decoded)) {
        return '';
    }

    $provider_name = '';
    $provider_slug = '';
    $provider_id = 0;

    if (isset($decoded['provider'])) {
        if (is_array($decoded['provider'])) {
            $provider_name = trim((string)($decoded['provider']['provider_name'] ?? ''));
            $provider_slug = strtolower(trim((string)($decoded['provider']['provider_slug'] ?? '')));
            $provider_id = (int)($decoded['provider']['provider_id'] ?? 0);
        } elseif (is_string($decoded['provider'])) {
            $provider_name = trim($decoded['provider']);
        }
    }

    if ($provider_name === '') {
        $provider_name = trim((string)($decoded['provider_name'] ?? ''));
    }
    if ($provider_slug === '') {
        $provider_slug = strtolower(trim((string)($decoded['provider_slug'] ?? '')));
    }
    if ($provider_id <= 0) {
        $provider_id = (int)($decoded['provider_id'] ?? 0);
    }

    if ($provider_name !== '') {
        return $provider_name;
    }
    if ($provider_id > 0 && isset($provider_name_by_id[$provider_id])) {
        return $provider_name_by_id[$provider_id];
    }
    if ($provider_slug !== '' && isset($provider_name_by_slug[$provider_slug])) {
        return $provider_name_by_slug[$provider_slug];
    }

    return '';
};

if ($has_bundle_orders) {
    $stats_sql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN LOWER(status) IN ('success','completed','delivered') THEN 1 ELSE 0 END) AS successful_orders,
            SUM(CASE WHEN LOWER(status) IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN LOWER(status) IN ('failed','cancelled','refunded') THEN 1 ELSE 0 END) AS failed_orders,
            SUM(CASE WHEN LOWER(status) IN ('success','completed','delivered') THEN amount ELSE 0 END) AS total_amount
        FROM bundle_orders
    ";
    $result = $db->query($stats_sql);
    if ($result && $row = $result->fetch_assoc()) {
        $data_stats = array_merge($data_stats, $row);
    }

    $provider_aggregate_join = '';
    $provider_aggregate_select = "'' AS api_provider_name, '' AS api_provider_slug, NULL AS api_provider_id";
    if ($has_api_logs && $has_api_providers) {
        $provider_aggregate_join = "
            LEFT JOIN (
                SELECT l1.bundle_order_id, l1.provider_id
                FROM api_transaction_logs l1
                INNER JOIN (
                    SELECT bundle_order_id, MAX(id) AS max_id
                    FROM api_transaction_logs
                    GROUP BY bundle_order_id
                ) latest ON latest.max_id = l1.id
            ) atl ON atl.bundle_order_id = bo.id
            LEFT JOIN api_providers ap ON ap.id = atl.provider_id
        ";
        $provider_aggregate_select = "
            COALESCE(NULLIF(ap.name, ''), '') AS api_provider_name,
            COALESCE(NULLIF(ap.slug, ''), '') AS api_provider_slug,
            atl.provider_id AS api_provider_id
        ";
    }

    $provider_aggregate_sql = "
        SELECT
            bo.amount,
            bo.provider_status,
            bo.api_response,
            {$provider_aggregate_select}
        FROM bundle_orders bo
        {$provider_aggregate_join}
    ";
    $provider_aggregate_result = $db->query($provider_aggregate_sql);
    if ($provider_aggregate_result) {
        $provider_totals = [];
        $status_tokens = [
            'pending', 'processing', 'delivered', 'success', 'completed',
            'failed', 'cancelled', 'refunded', 'manual', 'queued'
        ];

        while ($provider_row = $provider_aggregate_result->fetch_assoc()) {
            $provider_name = trim((string)($provider_row['api_provider_name'] ?? ''));

            if ($provider_name === '') {
                $provider_name = $resolve_provider_from_api_response((string)($provider_row['api_response'] ?? ''));
            }

            if ($provider_name === '') {
                $status_candidate = strtolower(trim((string)($provider_row['provider_status'] ?? '')));
                if ($status_candidate !== '' && !in_array($status_candidate, $status_tokens, true)) {
                    $provider_name = (string)($provider_row['provider_status'] ?? '');
                }
            }

            if ($provider_name === '') {
                $provider_name = 'Unknown';
            }

            if (!isset($provider_totals[$provider_name])) {
                $provider_totals[$provider_name] = [
                    'provider_name' => $provider_name,
                    'total_orders' => 0,
                    'total_amount' => 0.0
                ];
            }

            $provider_totals[$provider_name]['total_orders']++;
            $provider_totals[$provider_name]['total_amount'] += (float)($provider_row['amount'] ?? 0);
        }

        $data_providers = array_values($provider_totals);
        usort($data_providers, function ($a, $b) {
            $orders_cmp = ((int)$b['total_orders']) <=> ((int)$a['total_orders']);
            if ($orders_cmp !== 0) {
                return $orders_cmp;
            }
            return strcasecmp((string)$a['provider_name'], (string)$b['provider_name']);
        });
        $data_providers = array_slice($data_providers, 0, 8);
    }

    $recent_data_join = '';
    $recent_data_provider_select = "'' AS api_provider_name, '' AS api_provider_slug, NULL AS api_provider_id";
    if ($has_api_logs && $has_api_providers) {
        $recent_data_join = "
            LEFT JOIN (
                SELECT l1.bundle_order_id, l1.provider_id
                FROM api_transaction_logs l1
                INNER JOIN (
                    SELECT bundle_order_id, MAX(id) AS max_id
                    FROM api_transaction_logs
                    GROUP BY bundle_order_id
                ) latest ON latest.max_id = l1.id
            ) atl_recent ON atl_recent.bundle_order_id = bo.id
            LEFT JOIN api_providers ap_recent ON ap_recent.id = atl_recent.provider_id
        ";
        $recent_data_provider_select = "
            COALESCE(NULLIF(ap_recent.name, ''), '') AS api_provider_name,
            COALESCE(NULLIF(ap_recent.slug, ''), '') AS api_provider_slug,
            atl_recent.provider_id AS api_provider_id
        ";
    }

    $recent_data_sql = "
        SELECT
            'data' AS order_type,
            bo.id AS record_id,
            COALESCE(NULLIF(bo.order_reference, ''), CONCAT('ORDER_', bo.id)) AS reference_code,
            COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'N/A') AS customer_name,
            COALESCE(NULLIF(u.email, ''), 'N/A') AS customer_email,
            COALESCE(NULLIF(dp.name, ''), 'Data Bundle') AS service_name,
            COALESCE(NULLIF(bo.beneficiary_number, ''), '-') AS target,
            bo.amount,
            COALESCE(NULLIF(bo.status, ''), 'pending') AS status,
            COALESCE(NULLIF(bo.provider_status, ''), '') AS provider_status,
            bo.api_response,
            {$recent_data_provider_select},
            bo.created_at
        FROM bundle_orders bo
        LEFT JOIN users u ON u.id = bo.user_id
        LEFT JOIN data_packages dp ON dp.id = bo.package_id
        {$recent_data_join}
        ORDER BY bo.created_at DESC
        LIMIT 80
    ";
    $result = $db->query($recent_data_sql);
    if ($result) {
        $recent_rows = array_merge($recent_rows, $result->fetch_all(MYSQLI_ASSOC));
    }
}

if ($has_result_checker) {
    $stats_sql = "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN LOWER(status) IN ('success','completed','delivered') THEN 1 ELSE 0 END) AS successful_orders,
            SUM(CASE WHEN LOWER(status) IN ('pending','processing') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN LOWER(status) IN ('failed','cancelled','refunded') THEN 1 ELSE 0 END) AS failed_orders,
            SUM(CASE WHEN LOWER(status) IN ('success','completed','delivered') THEN amount ELSE 0 END) AS total_amount
        FROM result_checker_purchases
    ";
    $result = $db->query($stats_sql);
    if ($result && $row = $result->fetch_assoc()) {
        $checker_stats = array_merge($checker_stats, $row);
    }

    $gateway_expr = $has_rc_gateway ? "COALESCE(NULLIF(LOWER(payment_gateway), ''), 'wallet')" : "'wallet'";
    $provider_sql = "
        SELECT {$gateway_expr} AS provider_name, COUNT(*) AS total_orders, SUM(amount) AS total_amount
        FROM result_checker_purchases
        GROUP BY {$gateway_expr}
        ORDER BY total_orders DESC, provider_name ASC
        LIMIT 8
    ";
    $result = $db->query($provider_sql);
    if ($result) {
        $checker_providers = $result->fetch_all(MYSQLI_ASSOC);
    }

    $recent_checker_sql = "
        SELECT
            'result_checker' AS order_type,
            p.id AS record_id,
            COALESCE(NULLIF(p.reference, ''), CONCAT('RC_', p.id)) AS reference_code,
            COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'N/A') AS customer_name,
            COALESCE(NULLIF(u.email, ''), 'N/A') AS customer_email,
            CONCAT(COALESCE(NULLIF(p.card_type, ''), 'RESULT'), ' Checker') AS service_name,
            " . ($has_column_exists && dbh_table_has_column('result_checker_purchases', 'sms_phone') ? "COALESCE(NULLIF(p.sms_phone, ''), '-')" : "'-'") . " AS target,
            p.amount,
            COALESCE(NULLIF(p.status, ''), 'pending') AS status,
            '' AS provider_status,
            NULL AS api_response,
            p.created_at
        FROM result_checker_purchases p
        LEFT JOIN users u ON u.id = p.user_id
        ORDER BY p.created_at DESC
        LIMIT 80
    ";
    $result = $db->query($recent_checker_sql);
    if ($result) {
        $recent_rows = array_merge($recent_rows, $result->fetch_all(MYSQLI_ASSOC));
    }
}

foreach (['total_orders', 'successful_orders', 'pending_orders', 'failed_orders'] as $key) {
    $data_stats[$key] = (int) ($data_stats[$key] ?? 0);
    $checker_stats[$key] = (int) ($checker_stats[$key] ?? 0);
}
$data_stats['total_amount'] = (float) ($data_stats['total_amount'] ?? 0);
$checker_stats['total_amount'] = (float) ($checker_stats['total_amount'] ?? 0);

$totals = [
    'orders' => $data_stats['total_orders'] + $checker_stats['total_orders'],
    'successful' => $data_stats['successful_orders'] + $checker_stats['successful_orders'],
    'pending' => $data_stats['pending_orders'] + $checker_stats['pending_orders'],
    'failed' => $data_stats['failed_orders'] + $checker_stats['failed_orders'],
    'amount' => $data_stats['total_amount'] + $checker_stats['total_amount'],
];

foreach ($recent_rows as &$row) {
    $provider_name = '';
    if (($row['order_type'] ?? '') === 'result_checker') {
        $provider_name = 'wallet/gateway';
    } else {
        $status_tokens = [
            'pending', 'processing', 'delivered', 'success', 'completed',
            'failed', 'cancelled', 'refunded', 'manual', 'queued'
        ];

        $provider_name = trim((string)($row['api_provider_name'] ?? ''));
        if ($provider_name === '') {
            $provider_name = $resolve_provider_from_api_response((string)($row['api_response'] ?? ''));
        }

        if ($provider_name === '') {
            $status_candidate = strtolower(trim((string)($row['provider_status'] ?? '')));
            if ($status_candidate !== '' && !in_array($status_candidate, $status_tokens, true)) {
                $provider_name = (string)($row['provider_status'] ?? '');
            }
        }
    }
    if ($provider_name === '') {
        $provider_name = 'Unknown';
    }
    $row['provider_name'] = $provider_name;
}
unset($row);

usort($recent_rows, function ($a, $b) {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});
$recent_rows = array_slice($recent_rows, 0, 120);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Histories - <?php echo SITE_NAME; ?></title>
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
        .data-histories-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
        }
        .table-wrap table {
            width: 100%;
            min-width: 0;
            table-layout: auto;
        }
        .summary-table table {
            min-width: 560px;
        }
        .provider-table-wrap table {
            min-width: 500px;
        }
        .recent-table-wrap table {
            min-width: 920px;
        }
        .table-wrap th,
        .table-wrap td {
            white-space: nowrap;
            vertical-align: top;
        }
        .table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--bg-primary, #F1E9DA);
            box-shadow: inset 0 -1px 0 var(--border-color, #F1E9DA);
        }
        .header-actions {
            gap: 0.5rem;
        }
        .summary-table {
            margin-bottom: 1rem;
        }
        .recent-orders-tools {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0.75rem;
        }
        .recent-orders-tools .form-control {
            width: 320px;
            max-width: 100%;
        }
        @media (max-width: 1024px) {
            .data-histories-grid {
                grid-template-columns: 1fr;
            }
            .header-actions {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
        }
        @media (max-width: 768px) {
            .dashboard-content {
                padding: var(--spacing-sm);
            }
            .widget-body {
                padding: 0.75rem;
            }
            .dashboard-header {
                gap: 0.5rem;
            }
            .header-actions {
                width: 100%;
                justify-content: flex-end;
            }
            .header-actions .btn {
                padding: 0.45rem 0.6rem;
                font-size: 0.8rem;
            }
            .table-wrap th,
            .table-wrap td {
                font-size: 0.9rem;
                padding: 0.55rem 0.5rem;
                line-height: 1.4;
            }
            .recent-orders-tools {
                justify-content: stretch;
            }
            .recent-orders-tools .form-control {
                width: 100%;
            }
            .summary-table table {
                min-width: 520px;
            }
            .provider-table-wrap table {
                min-width: 460px;
            }
            .recent-table-wrap table {
                min-width: 860px;
            }
        }
        @media (max-width: 480px) {
            .header-actions .btn .action-text {
                display: none;
            }
            .header-actions .btn {
                padding: 0.45rem;
                min-width: 42px;
                min-height: 42px;
            }
            .table-wrap th,
            .table-wrap td {
                font-size: 0.88rem;
                padding: 0.5rem 0.45rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
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
                <div class="nav-section-title">Management</div>
                <div class="nav-item">
                    <a href="afa-registration.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        AFA Registration
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item">
                    <a href="transactions.php" class="nav-link">
                        <i class="fas fa-history"></i>
                        Transactions
                    </a>
                </div>
                <div class="nav-item">
                    <a href="data-histories.php" class="nav-link active">
                        <i class="fas fa-database"></i>
                        Data Histories
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        Reports
                    </a>
                </div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Quick Links</div>
                <div class="nav-item">
                    <a href="result-checker.php" class="nav-link">
                        <i class="fas fa-award"></i>
                        Result Checker
                    </a>
                </div>
                <div class="nav-item">
                    <a href="support.php" class="nav-link">
                        <i class="fas fa-life-ring"></i>
                        Support
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
                    <div class="breadcrumb-item"><i class="fas fa-database"></i></div>
                    <div class="breadcrumb-item active">Data Histories</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                <a href="dashboard.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-arrow-left"></i> <span class="action-text">Dashboard</span>
                </a>
                <a href="../logout.php" class="btn btn-danger btn-sm">
                    <i class="fas fa-sign-out-alt"></i> <span class="action-text">Logout</span>
                </a>
            </div>
        </header>
        <div class="dashboard-content">
            <div class="widget">
                <div class="widget-header">
                    <h1 class="widget-title">All Data + Checker Histories</h1>
                    <p class="widget-subtitle">Summary of all placed orders with provider/channel visibility.</p>
                </div>
                <div class="widget-body">
                    <div class="stats-grid" style="margin-bottom:1rem;">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($totals['orders']); ?></div>
                            <div class="stat-label">All Orders</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($data_stats['total_orders']); ?></div>
                            <div class="stat-label">Data Orders</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($checker_stats['total_orders']); ?></div>
                            <div class="stat-label">Result Checker</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo CURRENCY . number_format($totals['amount'], 2); ?></div>
                            <div class="stat-label">Successful Revenue</div>
                        </div>
                    </div>
                    <div class="table-wrap summary-table">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Channel</th>
                                    <th>Total</th>
                                    <th>Success</th>
                                    <th>Pending</th>
                                    <th>Failed</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Data Orders</td>
                                    <td><?php echo number_format($data_stats['total_orders']); ?></td>
                                    <td><?php echo number_format($data_stats['successful_orders']); ?></td>
                                    <td><?php echo number_format($data_stats['pending_orders']); ?></td>
                                    <td><?php echo number_format($data_stats['failed_orders']); ?></td>
                                    <td><?php echo CURRENCY . number_format($data_stats['total_amount'], 2); ?></td>
                                </tr>
                                <tr>
                                    <td>Result Checker</td>
                                    <td><?php echo number_format($checker_stats['total_orders']); ?></td>
                                    <td><?php echo number_format($checker_stats['successful_orders']); ?></td>
                                    <td><?php echo number_format($checker_stats['pending_orders']); ?></td>
                                    <td><?php echo number_format($checker_stats['failed_orders']); ?></td>
                                    <td><?php echo CURRENCY . number_format($checker_stats['total_amount'], 2); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="data-histories-grid">
                        <div class="widget">
                            <div class="widget-header">
                                <h3 class="widget-title">Data API Providers Used</h3>
                            </div>
                            <div class="widget-body">
                                <div class="table-wrap provider-table-wrap">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Provider</th>
                                                <th>Orders</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($data_providers)): ?>
                                                <tr>
                                                    <td colspan="3" class="text-muted text-center">No provider data found.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($data_providers as $p): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($p['provider_name'] ?? 'Unknown'); ?></td>
                                                        <td><?php echo number_format((int) ($p['total_orders'] ?? 0)); ?></td>
                                                        <td><?php echo CURRENCY . number_format((float) ($p['total_amount'] ?? 0), 2); ?></td>
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
                                <h3 class="widget-title">Checker Payment Channels Used</h3>
                            </div>
                            <div class="widget-body">
                                <div class="table-wrap provider-table-wrap">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Channel</th>
                                                <th>Orders</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($checker_providers)): ?>
                                                <tr>
                                                    <td colspan="3" class="text-muted text-center">No checker channel data found.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($checker_providers as $p): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(strtoupper((string) ($p['provider_name'] ?? 'wallet'))); ?></td>
                                                        <td><?php echo number_format((int) ($p['total_orders'] ?? 0)); ?></td>
                                                        <td><?php echo CURRENCY . number_format((float) ($p['total_amount'] ?? 0), 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="widget" style="margin-top:1rem;">
                        <div class="widget-header">
                            <h3 class="widget-title">Recent Orders (Data + Checker)</h3>
                        </div>
                        <div class="widget-body">
                            <div class="recent-orders-tools">
                                <input
                                    type="text"
                                    id="recentOrdersSearch"
                                    class="form-control"
                                    placeholder="Search by number or reference"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="table-wrap recent-table-wrap">
                                <table class="table" id="recentOrdersTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Reference</th>
                                            <th>User</th>
                                            <th>Service</th>
                                            <th>Target</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Provider/Channel</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recent_rows)): ?>
                                            <tr id="recentOrdersEmpty">
                                                <td colspan="9" class="text-center text-muted">No recent orders found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recent_rows as $row): ?>
                                                <?php
                                                $search_text = strtolower(implode(' ', [
                                                    (string) ($row['reference_code'] ?? ''),
                                                    (string) ($row['customer_name'] ?? ''),
                                                    (string) ($row['customer_email'] ?? ''),
                                                    (string) ($row['service_name'] ?? ''),
                                                    (string) ($row['target'] ?? ''),
                                                    (string) ($row['status'] ?? ''),
                                                    (string) ($row['provider_name'] ?? '')
                                                ]));
                                                $target_digits = preg_replace('/\D+/', '', (string) ($row['target'] ?? ''));
                                                $reference_digits = preg_replace('/\D+/', '', (string) ($row['reference_code'] ?? ''));
                                                ?>
                                                <tr
                                                    data-search-row="1"
                                                    data-search="<?php echo htmlspecialchars($search_text, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-target-digits="<?php echo htmlspecialchars($target_digits, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-reference-digits="<?php echo htmlspecialchars($reference_digits, ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) ($row['created_at'] ?? 'now')))); ?></td>
                                                    <td><?php echo htmlspecialchars(($row['order_type'] ?? '') === 'result_checker' ? 'Result Checker' : 'Data'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['reference_code'] ?? ''); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($row['customer_email'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['service_name'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($row['target'] ?? '-'); ?></td>
                                                    <td><?php echo CURRENCY . number_format((float) ($row['amount'] ?? 0), 2); ?></td>
                                                    <td><?php echo htmlspecialchars($row['status'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($row['provider_name'] ?? 'Unknown'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr id="recentOrdersEmpty" style="display:none;">
                                                <td colspan="9" class="text-center text-muted">No matching orders found.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
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
    if (!icon) {
        return;
    }
    icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

document.addEventListener('DOMContentLoaded', function () {
    initTheme();

    const menuButton = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function closeSidebar() {
        if (!sidebar) {
            return;
        }
        sidebar.classList.remove('show');
        if (overlay) {
            overlay.classList.remove('show');
        }
    }

    if (menuButton && sidebar) {
        menuButton.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            if (overlay) {
                overlay.classList.toggle('show');
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    const recentOrdersSearch = document.getElementById('recentOrdersSearch');
    const recentOrdersTable = document.getElementById('recentOrdersTable');
    const recentOrdersEmpty = document.getElementById('recentOrdersEmpty');

    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    if (recentOrdersSearch && recentOrdersTable) {
        const rows = Array.from(recentOrdersTable.querySelectorAll('tbody tr[data-search-row="1"]'));

        const applyRecentOrdersFilter = function () {
            const query = (recentOrdersSearch.value || '').trim().toLowerCase();
            const queryDigits = digitsOnly(query);
            let visibleCount = 0;

            rows.forEach(function (row) {
                const haystack = (row.getAttribute('data-search') || '').toLowerCase();
                const targetDigits = row.getAttribute('data-target-digits') || '';
                const referenceDigits = row.getAttribute('data-reference-digits') || '';

                let isMatch;
                if (query === '') {
                    isMatch = true;
                } else if (queryDigits !== '') {
                    isMatch = targetDigits.includes(queryDigits)
                        || referenceDigits.includes(queryDigits)
                        || haystack.includes(query);
                } else {
                    isMatch = haystack.includes(query);
                }

                row.style.display = isMatch ? '' : 'none';
                if (isMatch) {
                    visibleCount++;
                }
            });

            if (recentOrdersEmpty) {
                recentOrdersEmpty.style.display = visibleCount === 0 ? '' : 'none';
            }
        };

        recentOrdersSearch.addEventListener('input', applyRecentOrdersFilter);
    }
});
</script>
</body>
</html>
