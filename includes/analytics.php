<?php
/**
 * Dynamic analytics functions to replace hardcoded data
 */

/**
 * Get cached analytics data or generate new data
 */
function analyticsCacheTableReady() {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    try {
        if (function_exists('dbh_table_exists')) {
            $ready = dbh_table_exists('analytics_cache');
        } else {
            global $db;
            $result = $db->query("SHOW TABLES LIKE 'analytics_cache'");
            $ready = $result && $result->num_rows > 0;
        }
    } catch (Throwable $e) {
        $ready = false;
        error_log('Analytics cache table probe failed: ' . $e->getMessage());
    }

    return $ready;
}

function getCachedAnalytics($cache_key, $user_id = null, $cache_duration = 3600) {
    global $db;

    if (!analyticsCacheTableReady()) {
        return null;
    }

    try {
        $stmt = $db->prepare("
            SELECT cache_data FROM analytics_cache
            WHERE cache_key = ? AND user_id = ? AND expires_at > NOW()
        ");
        if (!$stmt) {
            return null;
        }

        $safe_user_id = is_numeric($user_id) ? (int)$user_id : 0;
        $stmt->bind_param("si", $cache_key, $safe_user_id);
        if (!$stmt->execute()) {
            return null;
        }

        $result = $stmt->get_result();
        if ($result && ($cached = $result->fetch_assoc())) {
            $decoded = json_decode($cached['cache_data'], true);
            return is_array($decoded) ? $decoded : null;
        }
    } catch (Throwable $e) {
        error_log('Analytics cache read error: ' . $e->getMessage());
    }

    return null;
}

/**
 * Cache analytics data
 */
function cacheAnalytics($cache_key, $data, $user_id = null, $cache_duration = 3600) {
    global $db;

    if (!analyticsCacheTableReady()) {
        return;
    }

    try {
        $expires_at = date('Y-m-d H:i:s', time() + $cache_duration);
        $cache_data = json_encode($data);

        $stmt = $db->prepare("
            INSERT INTO analytics_cache (cache_key, cache_data, user_id, expires_at) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            cache_data = VALUES(cache_data), 
            expires_at = VALUES(expires_at)
        ");
        if (!$stmt) {
            return;
        }
        $safe_user_id = is_numeric($user_id) ? (int)$user_id : 0;
        $stmt->bind_param("ssis", $cache_key, $cache_data, $safe_user_id, $expires_at);
        $stmt->execute();
    } catch (Exception $e) {
        // Silently fail caching - analytics will still work without cache
        error_log("Analytics cache error: " . $e->getMessage());
    }
}

if (!function_exists('analyticsBuildTrackedSalesFilters')) {
    function analyticsBuildTrackedSalesFilters($user_id = null, $user_role = 'admin', array $aliases = []) {
        $user_id = (int) $user_id;
        $defaultAliases = [
            'bundle' => 'bo',
            'afa' => 'ar',
            'checker' => 'rcp',
        ];
        $aliases = array_merge($defaultAliases, $aliases);

        $filters = [
            'bundle' => ['clause' => '', 'types' => '', 'params' => []],
            'afa' => ['clause' => '', 'types' => '', 'params' => []],
            'checker' => ['clause' => '', 'types' => '', 'params' => []],
        ];

        if ($user_id <= 0) {
            return $filters;
        }

        if ($user_role === 'agent' || $user_role === 'vip') {
            $filters['bundle'] = [
                'clause' => " AND ({$aliases['bundle']}.agent_id = ? OR {$aliases['bundle']}.user_id = ?)",
                'types' => 'ii',
                'params' => [$user_id, $user_id],
            ];
            $filters['afa'] = [
                'clause' => " AND ({$aliases['afa']}.agent_id = ? OR {$aliases['afa']}.user_id = ?)",
                'types' => 'ii',
                'params' => [$user_id, $user_id],
            ];
            $filters['checker'] = [
                'clause' => " AND ({$aliases['checker']}.agent_id = ? OR {$aliases['checker']}.user_id = ?)",
                'types' => 'ii',
                'params' => [$user_id, $user_id],
            ];
        } elseif ($user_role === 'customer') {
            $filters['bundle'] = [
                'clause' => " AND {$aliases['bundle']}.user_id = ?",
                'types' => 'i',
                'params' => [$user_id],
            ];
            $filters['afa'] = [
                'clause' => " AND {$aliases['afa']}.user_id = ?",
                'types' => 'i',
                'params' => [$user_id],
            ];
            $filters['checker'] = [
                'clause' => " AND {$aliases['checker']}.user_id = ?",
                'types' => 'i',
                'params' => [$user_id],
            ];
        }

        return $filters;
    }
}

if (!function_exists('analyticsGetTrackedSalesSummary')) {
    function analyticsGetTrackedSalesSummary($start_date, $end_date, $user_id = null, $user_role = 'admin') {
        global $db;

        $summary = [
            'total_sales' => 0.0,
            'total_orders' => 0,
        ];

        $filters = analyticsBuildTrackedSalesFilters($user_id, $user_role);

        $salesExpr = "bo.amount";
        if ($user_role === 'admin') {
            $salesExpr = "CASE WHEN bo.agent_id > 0 THEN COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) ELSE bo.amount END";
        } elseif ($user_role === 'agent' || $user_role === 'vip') {
            $salesExpr = "CASE WHEN bo.agent_id > 0 AND (bo.user_id IS NULL OR bo.user_id != bo.agent_id) THEN bo.amount ELSE COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) END";
        }

        if (!function_exists('dbh_table_exists') || dbh_table_exists('bundle_orders')) {
            try {
                $bundleSql = "
                    SELECT COALESCE(SUM({$salesExpr}), 0) AS total_sales, COUNT(*) AS total_orders
                    FROM bundle_orders bo
                    WHERE LOWER(bo.status) IN ('processing', 'delivered', 'success', 'completed')
                      AND DATE(COALESCE(bo.processed_at, bo.created_at)) BETWEEN ? AND ?
                      {$filters['bundle']['clause']}
                ";
                $bundleStmt = $db->prepare($bundleSql);
                if ($bundleStmt) {
                    $bundleTypes = 'ss' . $filters['bundle']['types'];
                    $bundleParams = array_merge([$start_date, $end_date], $filters['bundle']['params']);
                    $bundleStmt->bind_param($bundleTypes, ...$bundleParams);
                    $bundleStmt->execute();
                    $bundleRow = $bundleStmt->get_result()->fetch_assoc() ?: [];
                    $summary['total_sales'] += (float) ($bundleRow['total_sales'] ?? 0);
                    $summary['total_orders'] += (int) ($bundleRow['total_orders'] ?? 0);
                }
            } catch (Throwable $e) {
                error_log('Tracked bundle sales summary failed: ' . $e->getMessage());
            }
        }

        if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
            try {
                $afaSql = "
                    SELECT COALESCE(SUM(ar.amount), 0) AS total_sales, COUNT(*) AS total_orders
                    FROM afa_registrations ar
                    WHERE ar.status IN ('processing', 'success')
                      AND DATE(COALESCE(ar.processing_at, ar.created_at)) BETWEEN ? AND ?
                      {$filters['afa']['clause']}
                ";
                $afaStmt = $db->prepare($afaSql);
                if ($afaStmt) {
                    $afaTypes = 'ss' . $filters['afa']['types'];
                    $afaParams = array_merge([$start_date, $end_date], $filters['afa']['params']);
                    $afaStmt->bind_param($afaTypes, ...$afaParams);
                    $afaStmt->execute();
                    $afaRow = $afaStmt->get_result()->fetch_assoc() ?: [];
                    $summary['total_sales'] += (float) ($afaRow['total_sales'] ?? 0);
                    $summary['total_orders'] += (int) ($afaRow['total_orders'] ?? 0);
                }
            } catch (Throwable $e) {
                error_log('Tracked AFA sales summary failed: ' . $e->getMessage());
            }
        }

        if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
            try {
                $checkerSql = "
                    SELECT COALESCE(SUM(rcp.amount), 0) AS total_sales, COUNT(*) AS total_orders
                    FROM result_checker_purchases rcp
                    WHERE rcp.status = 'success'
                      AND DATE(rcp.created_at) BETWEEN ? AND ?
                      {$filters['checker']['clause']}
                ";
                $checkerStmt = $db->prepare($checkerSql);
                if ($checkerStmt) {
                    $checkerTypes = 'ss' . $filters['checker']['types'];
                    $checkerParams = array_merge([$start_date, $end_date], $filters['checker']['params']);
                    $checkerStmt->bind_param($checkerTypes, ...$checkerParams);
                    $checkerStmt->execute();
                    $checkerRow = $checkerStmt->get_result()->fetch_assoc() ?: [];
                    $summary['total_sales'] += (float) ($checkerRow['total_sales'] ?? 0);
                    $summary['total_orders'] += (int) ($checkerRow['total_orders'] ?? 0);
                }
            } catch (Throwable $e) {
                error_log('Tracked checker sales summary failed: ' . $e->getMessage());
            }
        }

        return $summary;
    }
}

if (!function_exists('analyticsGetTrackedCustomerCount')) {
    function analyticsGetTrackedCustomerCount($user_id = null, $user_role = 'admin') {
        global $db;

        $user_id = (int) $user_id;
        if (!in_array($user_role, ['agent', 'vip'], true) || $user_id <= 0) {
            return 0;
        }

        $unionParts = [];
        $types = '';
        $params = [];

        if (!function_exists('dbh_table_exists') || dbh_table_exists('bundle_orders')) {
            $unionParts[] = "
                SELECT DISTINCT
                    CASE
                        WHEN COALESCE(bo.user_id, 0) > 0 THEN CONCAT('u:', bo.user_id)
                        WHEN NULLIF(TRIM(bo.beneficiary_number), '') IS NOT NULL THEN CONCAT('p:', TRIM(bo.beneficiary_number))
                        WHEN NULLIF(TRIM(bo.order_reference), '') IS NOT NULL THEN CONCAT('r:', TRIM(bo.order_reference))
                        ELSE NULL
                    END AS buyer_key
                FROM bundle_orders bo
                WHERE bo.agent_id = ?
                  AND LOWER(bo.status) IN ('processing', 'delivered', 'success', 'completed')
            ";
            $types .= 'i';
            $params[] = $user_id;
        }

        if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
            $unionParts[] = "
                SELECT DISTINCT
                    CASE
                        WHEN COALESCE(ar.user_id, 0) > 0 THEN CONCAT('u:', ar.user_id)
                        WHEN NULLIF(TRIM(ar.phone), '') IS NOT NULL THEN CONCAT('p:', TRIM(ar.phone))
                        WHEN NULLIF(TRIM(ar.email), '') IS NOT NULL THEN CONCAT('e:', LOWER(TRIM(ar.email)))
                        WHEN NULLIF(TRIM(ar.reference), '') IS NOT NULL THEN CONCAT('r:', TRIM(ar.reference))
                        ELSE NULL
                    END AS buyer_key
                FROM afa_registrations ar
                WHERE ar.agent_id = ?
                  AND ar.status IN ('processing', 'success')
            ";
            $types .= 'i';
            $params[] = $user_id;
        }

        if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
            $unionParts[] = "
                SELECT DISTINCT
                    CASE
                        WHEN COALESCE(rcp.user_id, 0) > 0 THEN CONCAT('u:', rcp.user_id)
                        WHEN NULLIF(TRIM(rcp.sms_phone), '') IS NOT NULL THEN CONCAT('p:', TRIM(rcp.sms_phone))
                        WHEN NULLIF(TRIM(rcp.notification_email), '') IS NOT NULL THEN CONCAT('e:', LOWER(TRIM(rcp.notification_email)))
                        WHEN NULLIF(TRIM(rcp.reference), '') IS NOT NULL THEN CONCAT('r:', TRIM(rcp.reference))
                        ELSE NULL
                    END AS buyer_key
                FROM result_checker_purchases rcp
                WHERE rcp.agent_id = ?
                  AND rcp.status = 'success'
            ";
            $types .= 'i';
            $params[] = $user_id;
        }

        if (empty($unionParts)) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) AS total
            FROM (
                " . implode("\nUNION\n", $unionParts) . "
            ) tracked_buyers
            WHERE buyer_key IS NOT NULL AND buyer_key <> ''
        ";

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('Tracked customer count failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Get weekly sales data for charts
 */
function getWeeklySalesData($user_id = null, $user_role = 'admin', $weekOffset = 0) {
    global $db;

    $weekOffset = max(0, (int) $weekOffset);
    $cache_key = "weekly_sales_v3_" . $user_role . "_" . $weekOffset;
    $cached = getCachedAnalytics($cache_key, $user_id, 1800); // 30 minutes cache
    
    if ($cached) {
        if (!isset($cached['total_orders']) || $cached['total_orders'] <= 0) {
            // Ignore stale cache so we can regenerate dynamic totals
        } else {
            return $cached;
        }
    }
    
    $weekly_sales = [];
    
    $today = new DateTimeImmutable('today');
    $startOfWeek = $today
        ->modify('-' . (int) $today->format('w') . ' days')
        ->modify('-' . ($weekOffset * 7) . ' days');

    for ($i = 0; $i < 7; $i++) {
        $dayDate = $startOfWeek->modify('+' . $i . ' days');
        $date = $dayDate->format('Y-m-d');
        $daily_data = analyticsGetTrackedSalesSummary($date, $date, $user_id, $user_role);
        
        $weekly_sales[] = [
            'day' => $dayDate->format('l'),
            'short_day' => $dayDate->format('D'),
            'date' => $date,
            'sales' => (float) ($daily_data['total_sales'] ?? 0)
        ];
    }
    
    cacheAnalytics($cache_key, $weekly_sales, $user_id, 1800);
    return $weekly_sales;
}

/**
 * Get weekly traffic data (store visits) for charts
 */
function getWeeklyTrafficData($user_id = null, $user_role = 'admin') {
    global $db;

    $cache_key = "weekly_traffic_" . $user_role;
    $cached = getCachedAnalytics($cache_key, $user_id, 1800); // 30 minutes cache

    if ($cached) {
        return $cached;
    }

    $weekly_traffic = [];
    $table_ready = true;

    if (function_exists('dbh_table_exists') && !dbh_table_exists('store_visits')) {
        $table_ready = false;
    }
    if (function_exists('dbh_table_has_column') && !dbh_table_has_column('store_visits', 'visited_at')) {
        $table_ready = false;
    }

    $filter_agent = (in_array($user_role, ['agent', 'vip'], true) && $user_id);

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $daily_visits = 0;

        if ($table_ready) {
            try {
                if ($filter_agent) {
                    $sql = "
                        SELECT COUNT(*) as daily_visits
                        FROM store_visits sv
                        JOIN agent_stores ast ON ast.id = sv.store_id
                        WHERE DATE(sv.visited_at) = ?
                          AND ast.agent_id = ?
                    ";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("si", $date, $user_id);
                } else {
                    $sql = "
                        SELECT COUNT(*) as daily_visits
                        FROM store_visits sv
                        WHERE DATE(sv.visited_at) = ?
                    ";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("s", $date);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $daily_data = $result->fetch_assoc();
                $daily_visits = (int) ($daily_data['daily_visits'] ?? 0);
            } catch (Exception $e) {
                error_log('Weekly traffic query failed: ' . $e->getMessage());
            }
        }

        $weekly_traffic[] = [
            'day' => date('l', strtotime($date)),
            'short_day' => date('D', strtotime($date)),
            'date' => $date,
            'visits' => $daily_visits
        ];
    }

    cacheAnalytics($cache_key, $weekly_traffic, $user_id, 1800);
    return $weekly_traffic;
}

/**
 * Get monthly sales data
 */
function getMonthlySalesData($user_id = null, $user_role = 'admin') {
    $cache_key = "monthly_sales_v3_" . $user_role;
    $cached = getCachedAnalytics($cache_key, $user_id, 3600); // 1 hour cache
    
    if ($cached) {
        return $cached;
    }
    
    $monthly_sales = [];
    
    for ($i = 11; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $monthly_data = analyticsGetTrackedSalesSummary($monthStart, $monthEnd, $user_id, $user_role);
        
        $monthly_sales[] = [
            'month' => date('F Y', strtotime($monthStart)),
            'short_month' => date('M Y', strtotime($monthStart)),
            'date' => date('Y-m', strtotime($monthStart)),
            'sales' => (float) ($monthly_data['total_sales'] ?? 0)
        ];
    }
    
    cacheAnalytics($cache_key, $monthly_sales, $user_id, 3600);
    return $monthly_sales;
}

/**
 * Get sales by network data
 */
function getSalesByNetworkData($user_id = null, $user_role = 'admin', $days = 30) {
    global $db;
    
    $cache_key = "sales_by_network_v4_{$user_role}_{$days}d";
    $cached = getCachedAnalytics($cache_key, $user_id, 1800);
    
    if ($cached) {
        $cached = array_values(array_filter((array) $cached, function ($row) {
            $network_name = strtolower(trim((string) ($row['network_name'] ?? '')));
            return $network_name !== 'vodafone';
        }));

        $has_data = false;
        foreach ($cached as $row) {
            if ((int)($row['total_orders'] ?? 0) > 0 || (float)($row['total_sales'] ?? 0) > 0) {
                $has_data = true;
                break;
            }
        }
        if ($has_data) {
            return $cached;
        }
    }
    
    $params = [$days];
    $param_types = "i";
    
    $agent_filter = "";
    $commission_join = "LEFT JOIN transactions t ON bo.transaction_id = t.id 
                                 AND t.status = 'success' 
                                 AND t.transaction_type = 'purchase'";
    $commission_total = "COALESCE(SUM(COALESCE(NULLIF(t.commission_earned, 0), bo.commission, 0)), 0)";
    if (in_array($user_role, ['agent', 'vip'], true) && $user_id) {
        $agent_filter = "AND (bo.agent_id = ? OR bo.user_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $param_types .= "ii";

        if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
            $commission_join = "
                LEFT JOIN agent_commissions ac ON ac.agent_id = ?
                                              AND ac.source_type = 'data'
                                              AND ac.status <> 'cancelled'
                                              AND (
                                                  (ac.source_id IS NOT NULL AND ac.source_id = bo.id)
                                                  OR (ac.source_reference <> '' AND ac.source_reference = bo.order_reference COLLATE utf8mb4_general_ci)
                                              )
            ";
            $commission_total = "COALESCE(SUM(ac.amount), 0)";
            $params[] = $user_id;
            $param_types .= "i";
        }
    }
    
    $salesExpr = "bo.amount";
    if ($user_role === 'admin') {
        $salesExpr = "CASE WHEN bo.agent_id > 0 THEN COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) ELSE bo.amount END";
    } elseif ($user_role === 'agent' || $user_role === 'vip') {
        $salesExpr = "CASE WHEN bo.agent_id > 0 AND (bo.user_id IS NULL OR bo.user_id != bo.agent_id) THEN bo.amount ELSE COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) END";
    }

    $sql = "
        SELECT n.name as network_name, n.color, 
               COALESCE(SUM({$salesExpr}), 0) as total_sales,
               COUNT(DISTINCT bo.id) as total_orders,
               {$commission_total} as commission_earned
        FROM networks n
        LEFT JOIN data_packages dp ON n.id = dp.network_id
        LEFT JOIN bundle_orders bo ON dp.id = bo.package_id 
                                 AND bo.status IN ('processing', 'success', 'delivered', 'completed')
                                 AND bo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                 $agent_filter
        {$commission_join}
        WHERE LOWER(TRIM(n.name)) <> 'vodafone'
        GROUP BY n.id, n.name, n.color
        ORDER BY total_sales DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sales_by_network = array_values(array_filter($result->fetch_all(MYSQLI_ASSOC), function ($row) {
        $network_name = strtolower(trim((string) ($row['network_name'] ?? '')));
        return $network_name !== 'vodafone';
    }));
    
    cacheAnalytics($cache_key, $sales_by_network, $user_id, 1800);
    return $sales_by_network;
}

/**
 * Get top agents per network for a time window (in days).
 */
function getTopAgentsByNetwork($days = 7, $network_names = []) {
    global $db;

    $network_names = array_values(array_filter((array) $network_names, 'strlen'));
    $cache_key = "top_agents_by_network_{$days}d";
    if (!empty($network_names)) {
        $cache_key .= '_' . md5(implode('|', $network_names));
    }

    $cached = getCachedAnalytics($cache_key, null, 1800);
    if ($cached) {
        return $cached;
    }

    $params = [$days];
    $param_types = "i";
    $network_clause = "";

    if (!empty($network_names)) {
        $placeholders = implode(',', array_fill(0, count($network_names), '?'));
        $network_clause = "AND n.name IN ({$placeholders})";
        $params = array_merge($params, $network_names);
        $param_types .= str_repeat('s', count($network_names));
    }

    $sql = "
        SELECT n.name as network_name, n.color,
               u.id as agent_id, u.full_name, u.email,
               COALESCE(SUM(CASE WHEN bo.agent_id > 0 THEN COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) ELSE bo.amount END), 0) as total_sales,
               COUNT(DISTINCT bo.id) as total_orders
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        JOIN users u ON u.id = IFNULL(bo.agent_id, bo.user_id)
        WHERE bo.status IN ('processing', 'success', 'delivered', 'completed')
          AND bo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND u.role = 'agent'
          {$network_clause}
        GROUP BY n.id, n.name, n.color, u.id, u.full_name, u.email
        ORDER BY n.name, total_sales DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    $top_by_network = [];
    if (!empty($network_names)) {
        foreach ($network_names as $name) {
            $top_by_network[$name] = null;
        }
    }

    foreach ($rows as $row) {
        $network = $row['network_name'] ?? 'Unknown';
        if (!isset($top_by_network[$network]) || $top_by_network[$network] === null) {
            $top_by_network[$network] = $row;
            continue;
        }
        if ((float) $row['total_sales'] > (float) ($top_by_network[$network]['total_sales'] ?? 0)) {
            $top_by_network[$network] = $row;
        }
    }

    cacheAnalytics($cache_key, $top_by_network, null, 1800);
    return $top_by_network;
}

/**
 * Get top customers for a specific agent within a time window (in days).
 */
function getTopCustomersForAgent($agent_id, $days = 7, $limit = 5) {
    global $db;

    $agent_id = (int) $agent_id;
    $days = (int) $days;
    $limit = (int) $limit;

    if ($agent_id <= 0 || $days <= 0 || $limit <= 0) {
        return [];
    }

    $cache_key = "top_customers_agent_{$agent_id}_{$days}d_{$limit}";
    $cached = getCachedAnalytics($cache_key, $agent_id, 1800);
    if ($cached) {
        return $cached;
    }

    $sql = "
        SELECT u.id as customer_id, u.full_name, u.email,
               COALESCE(SUM(bo.amount), 0) as total_sales,
               COUNT(DISTINCT bo.id) as total_orders
        FROM bundle_orders bo
        JOIN users u ON u.id = bo.user_id
        WHERE bo.status IN ('processing', 'success', 'delivered', 'completed')
          AND bo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND u.role = 'customer'
          AND (bo.agent_id = ? OR u.agent_id = ?)
        GROUP BY u.id, u.full_name, u.email
        ORDER BY total_sales DESC, total_orders DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("iiii", $days, $agent_id, $agent_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    cacheAnalytics($cache_key, $rows, $agent_id, 1800);
    return $rows;
}

/**
 * Get top-up totals per agent for a time window (in days).
 */
function getTopupAgentsByPeriod($days = 7, $limit = 5) {
    global $db;

    $days = (int) $days;
    $limit = (int) $limit;
    if ($days <= 0 || $limit <= 0) {
        return [];
    }

    $cache_key = "topup_agents_{$days}d_{$limit}";
    $cached = getCachedAnalytics($cache_key, null, 1800);
    if ($cached) {
        return $cached;
    }

    $sql = "
        SELECT u.id as agent_id, u.full_name, u.email,
               COALESCE(SUM(t.amount), 0) as total_topup,
               COALESCE(SUM(CASE WHEN t.payment_method IN ('paystack', 'agent_paystack', 'moolre') THEN t.amount ELSE 0 END), 0) as paystack_topup,
               COALESCE(SUM(CASE WHEN t.payment_method NOT IN ('paystack', 'agent_paystack', 'moolre') THEN t.amount ELSE 0 END), 0) as manual_topup
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        WHERE t.transaction_type = 'topup'
          AND t.status = 'success'
          AND u.role = 'agent'
          AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY u.id, u.full_name, u.email
        ORDER BY total_topup DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $days, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    cacheAnalytics($cache_key, $rows, null, 1800);
    return $rows;
}

/**
 * Get top sales agents for a tracked-sales period (bundle orders, AFA, result checker).
 */
function getTopSalesAgentsByPeriod($days = 1, $limit = 5) {
    global $db;

    $days = (int) $days;
    $limit = (int) $limit;
    if ($days <= 0 || $limit <= 0) {
        return [];
    }

    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cache_key = "top_sales_agents_{$days}d_{$limit}_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 1800);
    if ($cached) {
        return $cached;
    }

    $unionParts = [];
    $types = '';
    $params = [];

    if (!function_exists('dbh_table_exists') || dbh_table_exists('bundle_orders')) {
        $unionParts[] = "
            SELECT
                IFNULL(bo.agent_id, bo.user_id) AS agent_id,
                COALESCE(SUM(CASE WHEN bo.agent_id > 0 THEN COALESCE(NULLIF(bo.agent_cost, 0), bo.amount) ELSE bo.amount END), 0) AS total_sales,
                COUNT(*) AS total_orders
            FROM bundle_orders bo
            WHERE LOWER(bo.status) IN ('processing', 'delivered', 'success', 'completed')
              AND DATE(COALESCE(bo.processed_at, bo.created_at)) BETWEEN ? AND ?
            GROUP BY IFNULL(bo.agent_id, bo.user_id)
        ";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $today;
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
        $unionParts[] = "
            SELECT
                IFNULL(ar.agent_id, ar.user_id) AS agent_id,
                COALESCE(SUM(ar.amount), 0) AS total_sales,
                COUNT(*) AS total_orders
            FROM afa_registrations ar
            WHERE ar.status IN ('processing', 'success')
              AND DATE(COALESCE(ar.processing_at, ar.created_at)) BETWEEN ? AND ?
            GROUP BY IFNULL(ar.agent_id, ar.user_id)
        ";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $today;
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
        $unionParts[] = "
            SELECT
                IFNULL(rcp.agent_id, rcp.user_id) AS agent_id,
                COALESCE(SUM(rcp.amount), 0) AS total_sales,
                COUNT(*) AS total_orders
            FROM result_checker_purchases rcp
            WHERE rcp.status = 'success'
              AND DATE(rcp.created_at) BETWEEN ? AND ?
            GROUP BY IFNULL(rcp.agent_id, rcp.user_id)
        ";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $today;
    }

    if (empty($unionParts)) {
        return [];
    }

    $sql = "
        SELECT
            u.id AS agent_id,
            u.full_name,
            u.email,
            COALESCE(SUM(sales.total_sales), 0) AS total_sales,
            COALESCE(SUM(sales.total_orders), 0) AS total_orders
        FROM (
            " . implode("\nUNION ALL\n", $unionParts) . "
        ) sales
        JOIN users u ON u.id = sales.agent_id
        WHERE sales.agent_id IS NOT NULL
          AND sales.agent_id > 0
          AND u.role = 'agent'
        GROUP BY u.id, u.full_name, u.email
        ORDER BY total_sales DESC, total_orders DESC, u.full_name ASC
        LIMIT ?
    ";

    $types .= 'i';
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    cacheAnalytics($cache_key, $rows, null, 1800);
    return $rows;
}

/**
 * Get sales + order totals for a period (in days).
 */
function getSalesOrdersSummary($user_id = null, $user_role = 'admin', $days = 1) {
    $days = (int) $days;
    if ($days <= 0) {
        $days = 1;
    }

    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    return analyticsGetTrackedSalesSummary($start_date, $today, $user_id, $user_role);
}


/**
 * Record profit for an order in the agent_profits table.
 * This is used as a ledger for agent earnings.
 */
if (!function_exists('recordOrderProfit')) {
    function recordOrderProfit($data) {
        global $db;
        
        if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_profits')) {
            return false;
        }
        
        $agent_id = (int)($data['agent_id'] ?? 0);
        $order_id = (int)($data['order_id'] ?? 0);
        $customer_id = isset($data['customer_id']) ? (int)$data['customer_id'] : null;
        $package_id = (int)($data['package_id'] ?? 0);
        $customer_payment = (float)($data['customer_paid'] ?? $data['customer_payment'] ?? 0);
        $agent_cost = (float)($data['agent_cost'] ?? 0);
        $profit_amount = (float)($data['profit_amount'] ?? 0);
        $reference = (string)($data['reference'] ?? '');
        $status = (string)($data['status'] ?? 'earned');
        
        if ($order_id <= 0 || $agent_id <= 0) {
            return false;
        }

        try {
            // Check if already recorded to avoid duplicates
            $stmt = $db->prepare("SELECT id FROM agent_profits WHERE order_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    return false;
                }
            }
            
            $stmt = $db->prepare("
                INSERT INTO agent_profits 
                (agent_id, order_id, customer_id, package_id, customer_payment, agent_cost, profit_amount, reference, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param("iiiidddss", $agent_id, $order_id, $customer_id, $package_id, $customer_payment, $agent_cost, $profit_amount, $reference, $status);
            $inserted = $stmt->execute();

            if ($inserted) {
                // Fetch order details for notification
                $stmt_notif = $db->prepare("
                    SELECT 
                        bo.id AS order_id,
                        bo.order_reference,
                        bo.beneficiary_number,
                        bo.amount AS customer_payment,
                        bo.agent_cost,
                        bo.status AS order_status,
                        bo.user_id AS customer_user_id,
                        bo.agent_id,
                        dp.name AS package_name,
                        dp.data_size,
                        n.name AS network_name,
                        t.metadata AS txn_metadata,
                        u.full_name AS customer_name,
                        u.email AS customer_email
                    FROM bundle_orders bo
                    LEFT JOIN data_packages dp ON bo.package_id = dp.id
                    LEFT JOIN networks n ON dp.network_id = n.id
                    LEFT JOIN users u ON bo.user_id = u.id
                    LEFT JOIN transactions t ON bo.order_reference = t.reference
                    WHERE bo.id = ?
                    LIMIT 1
                ");
                if ($stmt_notif) {
                    $stmt_notif->bind_param("i", $order_id);
                    $stmt_notif->execute();
                    $order_info = $stmt_notif->get_result()->fetch_assoc();
                    $stmt_notif->close();
                    
                    if ($order_info) {
                        $c_name = 'Guest Customer';
                        $c_email = '';
                        if ($order_info['customer_user_id'] > 0) {
                            $c_name = $order_info['customer_name'] ?? 'Customer';
                            $c_email = $order_info['customer_email'] ?? '';
                        } else if (!empty($order_info['txn_metadata'])) {
                            $meta = json_decode($order_info['txn_metadata'], true);
                            if (is_array($meta)) {
                                $c_name = $meta['buyer_name'] ?? 'Guest Customer';
                                $c_email = $meta['buyer_email'] ?? $meta['email'] ?? '';
                            }
                        }
                        
                        $network_name = $order_info['network_name'] ?? 'Data';
                        $data_size = $order_info['data_size'] ?? $order_info['package_name'] ?? 'bundle';
                        $item_name = trim($network_name . ' ' . $data_size);
                        
                        if (!function_exists('sendAgentOrderNotification')) {
                            require_once __DIR__ . '/functions.php';
                        }
                        if (function_exists('sendAgentOrderNotification')) {
                            sendAgentOrderNotification([
                                'agent_id' => $agent_id,
                                'service' => 'Data Bundle Purchase',
                                'item' => $item_name,
                                'reference' => $order_info['order_reference'] ?? $reference,
                                'customer_name' => $c_name,
                                'customer_email' => $c_email,
                                'beneficiary_number' => $order_info['beneficiary_number'] ?? '',
                                'amount' => $customer_payment,
                                'payment_method' => 'online',
                                'status' => $order_info['order_status'] ?? $status
                            ]);
                        }

                        if ($profit_amount > 0) {
                            if (!function_exists('sendAgentProfitNotification')) {
                                require_once __DIR__ . '/functions.php';
                            }
                            if (function_exists('sendAgentProfitNotification')) {
                                sendAgentProfitNotification([
                                    'agent_id' => $agent_id,
                                    'service' => 'Data Bundle Purchase',
                                    'item' => $item_name,
                                    'reference' => $order_info['order_reference'] ?? $reference,
                                    'customer_name' => $c_name,
                                    'customer_email' => $c_email,
                                    'beneficiary_number' => $order_info['beneficiary_number'] ?? '',
                                    'amount' => $customer_payment,
                                    'profit_amount' => $profit_amount,
                                    'payment_method' => 'online',
                                    'status' => $order_info['order_status'] ?? $status
                                ]);
                            }
                        }
                    }
                }
            }

            return $inserted;
        } catch (Throwable $e) {
            error_log('recordOrderProfit failed: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Summarize profits over a span of days.
 * Strictly matches the calculation logic in admin/profit-monitor.php for "Store Profit".
 */
function getProfitSummary($days = 1) {
    global $db;

    $days = (int) $days;
    $is_lifetime = $days <= 0;
    if (!$is_lifetime) {
        $days = max(1, $days);
    }
    $default = [
        'total_profit' => 0,
        'total_revenue' => 0,
        'total_cost' => 0,
        'agent_profit' => 0,
        'customer_profit' => 0,
        'total_orders' => 0,
        'available_profit' => 0,
        'paid_withdrawals' => 0,
        'pending_withdrawals' => 0
    ];

    $today = date('Y-m-d');
    $start_date = $is_lifetime ? '2000-01-01' : date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    // New cache key version to force update
    $cache_key = $is_lifetime ? "profit_summary_v6_lifetime_{$today}" : "profit_summary_v6_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 900);
    if ($cached) {
        return $cached;
    }

    $summary = $default;

    // 1. Calculate Data Store Profit (Bundles) - Matches admin/profit-monitor.php
    if (function_exists('dbh_table_exists') && dbh_table_exists('bundle_orders')) {
        try {
            $bundleActivityExpression = (function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'delivered_at'))
                ? 'COALESCE(bo.delivered_at, bo.updated_at, bo.created_at)'
                : 'COALESCE(bo.updated_at, bo.created_at)';

            // This SQL strictly follows Profit Monitor's data_profit calculation
            $bundleSql = "
                SELECT
                    COALESCE(SUM(GREATEST(0, COALESCE(bo.amount, 0) - COALESCE(bo.agent_cost, 0))), 0) AS data_profit,
                    COALESCE(SUM(COALESCE(bo.amount, 0)), 0) AS data_revenue,
                    COALESCE(SUM(COALESCE(bo.agent_cost, 0)), 0) AS data_cost,
                    COUNT(*) AS data_orders
                FROM bundle_orders bo
                WHERE bo.agent_id IS NOT NULL
                  AND bo.agent_id > 0
                  AND (bo.user_id IS NULL OR bo.user_id <> bo.agent_id)
                  AND LOWER(COALESCE(bo.status, '')) IN ('success', 'delivered', 'completed')
                  AND COALESCE(bo.agent_cost, 0) > 0
                  AND COALESCE(bo.amount, 0) > COALESCE(bo.agent_cost, 0)
                  AND DATE({$bundleActivityExpression}) BETWEEN ? AND ?
            ";
            
            $stmt = $db->prepare($bundleSql);
            if ($stmt) {
                $stmt->bind_param('ss', $start_date, $today);
                $stmt->execute();
                $data = $stmt->get_result()->fetch_assoc();
                
                $summary['total_profit'] = (float) ($data['data_profit'] ?? 0);
                $summary['total_revenue'] = (float) ($data['data_revenue'] ?? 0);
                $summary['total_cost'] = (float) ($data['data_cost'] ?? 0);
                $summary['agent_profit'] = (float) ($data['data_profit'] ?? 0);
                $summary['total_orders'] = (int) ($data['data_orders'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('getProfitSummary Bundle Calculation failed: ' . $e->getMessage());
        }
    }

    // 2. Subtract withdrawals if lifetime to get available profit (matching Profit Monitor)
    if ($is_lifetime && function_exists('dbh_table_exists') && dbh_table_exists('profit_withdrawals')) {
        try {
            $withdrawalSumColumn = (function_exists('dbh_table_has_column') && dbh_table_has_column('profit_withdrawals', 'total_debit'))
                ? 'CASE WHEN pw.total_debit IS NULL OR pw.total_debit <= 0 THEN pw.amount WHEN pw.total_debit > pw.amount THEN pw.amount ELSE pw.total_debit END'
                : 'pw.amount';

            $withdrawalSql = "
                SELECT
                    COALESCE(SUM(CASE WHEN pw.status IN ('pending', 'approved', 'processing') THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS pending_withdrawals,
                    COALESCE(SUM(CASE WHEN pw.status = 'paid' THEN {$withdrawalSumColumn} ELSE 0 END), 0) AS paid_withdrawals
                FROM profit_withdrawals pw
            ";
            
            $result = $db->query($withdrawalSql);
            if ($result) {
                $wData = $result->fetch_assoc();
                $summary['pending_withdrawals'] = (float)($wData['pending_withdrawals'] ?? 0);
                $summary['paid_withdrawals'] = (float)($wData['paid_withdrawals'] ?? 0);
                $summary['available_profit'] = max(0, $summary['total_profit'] - $summary['pending_withdrawals'] - $summary['paid_withdrawals']);
            }
        } catch (Throwable $e) {
            error_log('getProfitSummary Withdrawal calculation failed: ' . $e->getMessage());
        }
    }

    cacheAnalytics($cache_key, $summary, null, 900);
    return $summary;
}

/**
 * Summarize agent commission program earnings over a span of days.
 */
function getCommissionSummary($days = 1) {
    global $db;

    $days = (int) $days;
    $is_lifetime = $days <= 0;
    if (!$is_lifetime) {
        $days = max(1, $days);
    }

    $default = [
        'total_commission' => 0,
        'pending_commission' => 0,
        'liquidated_commission' => 0,
        'total_entries' => 0,
    ];

    if (function_exists('ensureAgentCommissionTables')) {
        ensureAgentCommissionTables();
    }

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_commissions')) {
        return $default;
    }

    $today = date('Y-m-d');
    $start_date = $is_lifetime ? '2000-01-01' : date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cache_key = $is_lifetime ? "commission_summary_lifetime_{$today}" : "commission_summary_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 900);
    if ($cached) {
        return $cached;
    }

    $sql = "
        SELECT
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN amount ELSE 0 END), 0) AS total_commission,
            COALESCE(SUM(CASE WHEN status = 'earned' THEN amount ELSE 0 END), 0) AS pending_commission,
            COALESCE(SUM(CASE WHEN status = 'liquidated' THEN amount ELSE 0 END), 0) AS liquidated_commission,
            COUNT(CASE WHEN status <> 'cancelled' THEN 1 END) AS total_entries
        FROM agent_commissions
        WHERE DATE(earned_at) BETWEEN ? AND ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('ss', $start_date, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    $summary = [
        'total_commission' => (float) ($data['total_commission'] ?? 0),
        'pending_commission' => (float) ($data['pending_commission'] ?? 0),
        'liquidated_commission' => (float) ($data['liquidated_commission'] ?? 0),
        'total_entries' => (int) ($data['total_entries'] ?? 0),
    ];

    cacheAnalytics($cache_key, $summary, null, 900);
    return $summary;
}


/**
 * Retrieve per-day profit breakdown for the requested window.
 * Strictly matches the calculation logic in admin/profit-monitor.php for "Store Profit".
 */
function getProfitTrends($days = 7) {
    global $db;

    $days = max(1, (int) $days);
    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    // New cache key version to force update
    $cache_key = "profit_trends_v5_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 900);
    if ($cached) {
        return $cached;
    }

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('bundle_orders')) {
        return [];
    }

    try {
        $bundleActivityExpression = (function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'delivered_at'))
            ? 'COALESCE(bo.delivered_at, bo.updated_at, bo.created_at)'
            : 'COALESCE(bo.updated_at, bo.created_at)';

        $sql = "
            SELECT
                DATE({$bundleActivityExpression}) as profit_date,
                COALESCE(SUM(GREATEST(0, COALESCE(bo.amount, 0) - COALESCE(bo.agent_cost, 0))), 0) AS total_profit,
                COALESCE(SUM(COALESCE(bo.amount, 0)), 0) AS total_revenue,
                COALESCE(SUM(COALESCE(bo.agent_cost, 0)), 0) AS total_cost,
                COUNT(*) AS total_orders
            FROM bundle_orders bo
            WHERE bo.agent_id IS NOT NULL
              AND bo.agent_id > 0
              AND (bo.user_id IS NULL OR bo.user_id <> bo.agent_id)
              AND LOWER(COALESCE(bo.status, '')) IN ('success', 'delivered', 'completed')
              AND COALESCE(bo.agent_cost, 0) > 0
              AND COALESCE(bo.amount, 0) > COALESCE(bo.agent_cost, 0)
              AND DATE({$bundleActivityExpression}) BETWEEN ? AND ?
            GROUP BY DATE({$bundleActivityExpression})
            ORDER BY DATE({$bundleActivityExpression}) DESC
            LIMIT ?
        ";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ssi', $start_date, $today, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        cacheAnalytics($cache_key, $rows, null, 900);
        return $rows;
    } catch (Throwable $e) {
        error_log('getProfitTrends calculation failed: ' . $e->getMessage());
        return [];
    }
}


/**
 * Get dashboard statistics
 */
function getDashboardStats($user_id, $user_role = 'admin') {
    global $db;

    $stats = [];
    $completed_order_statuses = "'success','delivered'";

    // Define queries based on role
    $queries = [
        'admin' => [
            'total_users' => "SELECT COUNT(*) as total FROM users WHERE role != 'admin'",
            'total_agents' => "SELECT COUNT(*) as total FROM users WHERE role = 'agent'",
            'total_customers' => "SELECT COUNT(*) as total FROM users WHERE role = 'customer'",
            'total_sales' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'success' AND transaction_type = 'purchase'",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders",
            'total_balance' => "SELECT COALESCE(SUM(balance), 0) as total FROM wallets"
        ],
        'agent' => [
            'wallet_balance' => "SELECT COALESCE(balance, 0) as balance FROM wallets WHERE user_id = ?",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ?",
            'total_sales' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND status = 'success' AND transaction_type = 'purchase'",
            'total_customers' => "SELECT COUNT(DISTINCT bo.user_id) as total FROM bundle_orders bo WHERE bo.agent_id = ? AND bo.status = 'success'"
        ],
        'vip' => [
            'wallet_balance' => "SELECT COALESCE(balance, 0) as balance FROM wallets WHERE user_id = ?",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ?",
            'total_sales' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND status = 'success' AND transaction_type = 'purchase'",
            'total_customers' => "SELECT COUNT(DISTINCT bo.user_id) as total FROM bundle_orders bo WHERE bo.agent_id = ? AND bo.status = 'success'"
        ],
        'customer' => [
            'wallet_balance' => "SELECT COALESCE(balance, 0) as balance FROM wallets WHERE user_id = ?",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ?",
            'total_spent' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND status = 'success' AND transaction_type = 'purchase'"
        ]
    ];
    
    $role_queries = $queries[$user_role] ?? $queries['admin'];
    
    foreach ($role_queries as $key => $query) {
        if (strpos($query, '?') !== false) {
            // Query with parameter - must have user_id
            if (!$user_id) {
                $stats[$key] = 0; // Default value for queries requiring user_id when not provided
                continue;
            }
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
        } else {
            // Query without parameter
            $result = $db->query($query);
            if (!$result) {
                $stats[$key] = 0; // Default value on query failure
                continue;
            }
            $data = $result->fetch_assoc();
        }

        // Extract the value based on column name
        if ($data) {
            if (isset($data['total'])) {
                $stats[$key] = floatval($data['total']);
            } elseif (isset($data['balance'])) {
                $stats[$key] = floatval($data['balance']);
            } else {
                $stats[$key] = 0; // Default value when no recognizable column found
            }
        } else {
            $stats[$key] = 0; // Default value when query returns no data
        }
    }

    if (in_array($user_role, ['admin', 'agent', 'vip'], true)) {
        $lifetimeSummary = analyticsGetTrackedSalesSummary('2000-01-01', date('Y-m-d'), in_array($user_role, ['agent', 'vip'], true) ? $user_id : null, $user_role);
        $stats['total_sales'] = (float) ($lifetimeSummary['total_sales'] ?? 0);
        $stats['total_orders'] = (int) ($lifetimeSummary['total_orders'] ?? 0);
    }

    if (in_array($user_role, ['agent', 'vip'], true) && $user_id) {
        $stats['total_customers'] = analyticsGetTrackedCustomerCount($user_id, $user_role);
    }

    // Fallback: if total_orders still resolves to zero (legacy data without bundle_orders),
    // derive the count from transactions so the dashboard stays dynamic.
    if ((!isset($stats['total_orders']) || $stats['total_orders'] <= 0) && dbh_table_exists('transactions')) {
        if ($user_role === 'admin') {
            $fallbackResult = $db->query("SELECT COUNT(*) AS total FROM transactions WHERE transaction_type = 'purchase' AND status = 'success'");
            if ($fallbackResult) {
                $row = $fallbackResult->fetch_assoc();
                if ($row && isset($row['total'])) {
                    $stats['total_orders'] = (int)$row['total'];
                }
            }
        } elseif ($user_id) {
            $stmt = $db->prepare("SELECT COUNT(*) AS total FROM transactions WHERE transaction_type = 'purchase' AND status = 'success' AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row && isset($row['total'])) {
                    $stats['total_orders'] = (int)$row['total'];
                }
            }
        }
    }
    return $stats;
}

/**
 * Get top performing agents
 */
function getTopPerformingAgents($limit = 10, $user_id = null, $user_role = 'admin') {
    global $db;
    
    $cache_key = "top_agents";
    $cached = getCachedAnalytics($cache_key, null, 1800);
    
    if ($cached) {
        return $cached;
    }
    
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.email,
               COALESCE(SUM(t.amount), 0) as total_sales,
               COALESCE(SUM(t.commission_earned), 0) as total_commission,
               COUNT(DISTINCT customer_orders.user_id) as customer_count,
               COUNT(t.id) as transaction_count
        FROM users u
        LEFT JOIN transactions t ON u.id = t.user_id AND t.status = 'success' AND t.transaction_type = 'purchase'
        LEFT JOIN (
            SELECT bo.user_id, u2.agent_id 
            FROM bundle_orders bo 
            JOIN users u2 ON bo.user_id = u2.id 
            WHERE u2.agent_id IS NOT NULL
        ) customer_orders ON u.id = customer_orders.agent_id
        WHERE u.role = 'agent'
        GROUP BY u.id, u.full_name, u.email
        ORDER BY total_sales DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $top_agents = $result->fetch_all(MYSQLI_ASSOC);
    
    cacheAnalytics($cache_key, $top_agents, null, 1800);
    return $top_agents;
}

/**
 * Get recent transactions for dashboard
 */
function getRecentTransactions($user_id = null, $user_role = 'admin', $limit = 10) {
    global $db;
    
    $inner_where_clause = "";
    $params = [$limit];
    $param_types = "i";
    
    if ($user_id) {
        $inner_where_clause = "WHERE user_id = ?";
        $params = [$user_id, $limit];
        $param_types = "ii";
    }
    
    $sql = "
        SELECT 
            t.*,
            u.full_name as user_name, 
            bo.id as order_id, 
            bo.order_reference,
            bo.beneficiary_number,
            bo.amount as order_amount,
            bo.agent_cost,
            dp.name as package_name, 
            n.name as network_name, 
            n.color as network_color,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.admin_price')) AS metadata_admin_price,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.beneficiary_number')) AS metadata_beneficiary,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.msisdn')) AS metadata_msisdn,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.phone')) AS metadata_phone,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.package_name')) AS metadata_package,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.amount')) AS metadata_amount,
            JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.value')) AS metadata_value,
            COALESCE(t.reference, bo.order_reference, CONCAT('ORD', bo.id), t.description, CONCAT('TXN-', t.id)) AS reference_display,
            COALESCE(
                t.transaction_type,
                CASE WHEN bo.id IS NOT NULL THEN 'purchase' END,
                'wallet',
                'purchase'
            ) AS transaction_type_display,
            COALESCE(t.status, bo.status, 'pending') AS status_display
        FROM (
            SELECT *
            FROM transactions
            $inner_where_clause
            ORDER BY created_at DESC
            LIMIT ?
        ) t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN bundle_orders bo ON (
            bo.transaction_id = t.id
            OR (
                t.user_id = bo.user_id
                AND (
                    t.reference = bo.order_reference
                    OR t.reference = CONCAT('ORD', bo.id)
                    OR bo.order_reference = t.description
                )
            )
        )
        LEFT JOIN data_packages dp ON bo.package_id = dp.id
        LEFT JOIN networks n ON dp.network_id = n.id
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Clear analytics cache
 */
function clearAnalyticsCache($cache_key = null, $user_id = null) {
    global $db;

    if (!analyticsCacheTableReady()) {
        return;
    }

    if ($cache_key) {
        $stmt = $db->prepare("DELETE FROM analytics_cache WHERE cache_key = ? AND user_id = ?");
        if (!$stmt) {
            return;
        }
        $safe_user_id = is_numeric($user_id) ? (int)$user_id : 0;
        $stmt->bind_param("si", $cache_key, $safe_user_id);
    } else {
        $stmt = $db->prepare("DELETE FROM analytics_cache WHERE expires_at < NOW()");
        if (!$stmt) {
            return;
        }
    }

    $stmt->execute();
}

/**
 * Get hourly sales data for today
 */
function getHourlySalesData($user_id = null, $user_role = 'admin') {
    global $db;
    
    $cache_key = "hourly_sales_" . $user_role;
    $cached = getCachedAnalytics($cache_key, $user_id, 900); // 15 minutes cache
    
    if ($cached) {
        return $cached;
    }
    
    $hourly_sales = [];
    $where_clause = "";
    $params = [];
    $param_types = "";
    
    if (in_array($user_role, ['agent', 'vip'], true) && $user_id) {
        $where_clause = "AND t.user_id = ?";
        $params[] = $user_id;
        $param_types .= "i";
    }
    
    for ($hour = 0; $hour < 24; $hour++) {
        $sql = "
            SELECT COALESCE(SUM(t.amount), 0) as hourly_sales 
            FROM transactions t 
            WHERE DATE(t.created_at) = CURDATE() 
            AND HOUR(t.created_at) = ?
            AND t.status = 'success' 
            AND t.transaction_type = 'purchase'
            $where_clause
        ";
        
        $stmt = $db->prepare($sql);
        $all_params = array_merge([$hour], $params);
        $all_param_types = "i" . $param_types;
        
        $stmt->bind_param($all_param_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result();
        $hourly_data = $result->fetch_assoc();
        
        $hourly_sales[] = [
            'hour' => $hour,
            'time' => sprintf('%02d:00', $hour),
            'sales' => floatval($hourly_data['hourly_sales'])
        ];
    }
    
    cacheAnalytics($cache_key, $hourly_sales, $user_id, 900);
    return $hourly_sales;
}
?>
