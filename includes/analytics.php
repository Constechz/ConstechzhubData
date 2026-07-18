<?php
/**
 * Dynamic analytics functions to replace hardcoded data
 */

/**
 * Get cached analytics data or generate new data
 */
function getCachedAnalytics($cache_key, $user_id = null, $cache_duration = 3600) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT cache_data FROM analytics_cache 
        WHERE cache_key = ? AND user_id = ? AND expires_at > NOW()
    ");
    $stmt->bind_param("si", $cache_key, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($cached = $result->fetch_assoc()) {
        return json_decode($cached['cache_data'], true);
    }
    
    return null;
}

/**
 * Cache analytics data
 */
function cacheAnalytics($cache_key, $data, $user_id = null, $cache_duration = 3600) {
    global $db;
    
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
        $stmt->bind_param("ssis", $cache_key, $cache_data, $user_id, $expires_at);
        $stmt->execute();
    } catch (Exception $e) {
        // Silently fail caching - analytics will still work without cache
        error_log("Analytics cache error: " . $e->getMessage());
    }
}

/**
 * Get weekly sales data for charts
 */
function getWeeklySalesData($user_id = null, $user_role = 'admin') {
    global $db;
    
    $cache_key = "weekly_sales_" . $user_role;
    $cached = getCachedAnalytics($cache_key, $user_id, 1800); // 30 minutes cache
    
    if ($cached) {
        if (!isset($cached['total_orders']) || $cached['total_orders'] <= 0) {
            // Ignore stale cache so we can regenerate dynamic totals
        } else {
            return $cached;
        }
    }
    
    $weekly_sales = [];
    $where_clause = "";
    $params = [];
    $param_types = "";
    
    // Filter by user if agent
    if ($user_role === 'agent' && $user_id) {
        $where_clause = "AND t.user_id = ?";
        $params[] = $user_id;
        $param_types .= "i";
    }
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sql = "
            SELECT COALESCE(SUM(t.amount), 0) as daily_sales 
            FROM transactions t 
            WHERE DATE(t.created_at) = ? 
            AND t.status = 'success' 
            AND t.transaction_type = 'purchase'
            $where_clause
        ";
        
        $stmt = $db->prepare($sql);
        $all_params = array_merge([$date], $params);
        $all_param_types = "s" . $param_types;
        
        if (!empty($all_params)) {
            $stmt->bind_param($all_param_types, ...$all_params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $daily_data = $result->fetch_assoc();
        
        $weekly_sales[] = [
            'day' => date('l', strtotime($date)),
            'short_day' => date('D', strtotime($date)),
            'date' => $date,
            'sales' => floatval($daily_data['daily_sales'])
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

    $filter_agent = ($user_role === 'agent' && $user_id);

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
    global $db;
    
    $cache_key = "monthly_sales_" . $user_role;
    $cached = getCachedAnalytics($cache_key, $user_id, 3600); // 1 hour cache
    
    if ($cached) {
        return $cached;
    }
    
    $monthly_sales = [];
    $where_clause = "";
    $params = [];
    $param_types = "";
    
    if ($user_role === 'agent' && $user_id) {
        $where_clause = "AND t.user_id = ?";
        $params[] = $user_id;
        $param_types .= "i";
    }
    
    for ($i = 11; $i >= 0; $i--) {
        $date = date('Y-m', strtotime("-$i months"));
        $sql = "
            SELECT COALESCE(SUM(t.amount), 0) as monthly_sales 
            FROM transactions t 
            WHERE DATE_FORMAT(t.created_at, '%Y-%m') = ? 
            AND t.status = 'success' 
            AND t.transaction_type = 'purchase'
            $where_clause
        ";
        
        $stmt = $db->prepare($sql);
        $all_params = array_merge([$date], $params);
        $all_param_types = "s" . $param_types;
        
        if (!empty($all_params)) {
            $stmt->bind_param($all_param_types, ...$all_params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $monthly_data = $result->fetch_assoc();
        
        $monthly_sales[] = [
            'month' => date('F Y', strtotime($date . '-01')),
            'short_month' => date('M Y', strtotime($date . '-01')),
            'date' => $date,
            'sales' => floatval($monthly_data['monthly_sales'])
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
    
    $cache_key = "sales_by_network_{$user_role}_{$days}d";
    $cached = getCachedAnalytics($cache_key, $user_id, 1800);
    
    if ($cached) {
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
    if ($user_role === 'agent' && $user_id) {
        $agent_filter = "AND (bo.agent_id = ? OR bo.user_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $param_types .= "ii";
    }
    
    $sql = "
        SELECT n.name as network_name, n.color, 
               COALESCE(SUM(bo.amount), 0) as total_sales,
               COUNT(DISTINCT bo.id) as total_orders,
               COALESCE(SUM(COALESCE(NULLIF(t.commission_earned, 0), bo.commission, 0)), 0) as commission_earned
        FROM networks n
        LEFT JOIN data_packages dp ON n.id = dp.network_id
        LEFT JOIN bundle_orders bo ON dp.id = bo.package_id 
                                 AND bo.status IN ('success', 'delivered', 'completed')
                                 AND bo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                 $agent_filter
        LEFT JOIN transactions t ON bo.transaction_id = t.id 
                                 AND t.status = 'success' 
                                 AND t.transaction_type = 'purchase'
        GROUP BY n.id, n.name, n.color
        ORDER BY total_sales DESC
    ";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($param_types, ...$params);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $code = (int) $e->getCode();
        $message = strtolower($e->getMessage() ?? '');
        $isGoneAway = in_array($code, [2006, 2013], true) || strpos($message, 'server has gone away') !== false;

        if ($isGoneAway) {
            // Reconnect and retry once
            $db->getConnection();
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
        } else {
            throw $e;
        }
    }
    $result = $stmt->get_result();
    
    $sales_by_network = $result->fetch_all(MYSQLI_ASSOC);
    
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
               COALESCE(SUM(bo.amount), 0) as total_sales,
               COUNT(DISTINCT bo.id) as total_orders
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        JOIN users u ON u.id = IFNULL(bo.agent_id, bo.user_id)
        WHERE bo.status IN ('success', 'delivered', 'completed')
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
        WHERE bo.status IN ('success', 'delivered', 'completed')
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
 * Get sales + order totals for a period (in days).
 */
function getSalesOrdersSummary($user_id = null, $user_role = 'admin', $days = 1) {
    global $db;

    $days = (int) $days;
    if ($days <= 0) {
        $days = 1;
    }

    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cache_key = "sales_orders_summary_{$user_role}_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, $user_id, 900);
    if ($cached) {
        return $cached;
    }

    $total_sales = 0.0;
    $total_orders = 0;

    // 1. Query bundle_orders
    if (function_exists('dbh_table_exists') && dbh_table_exists('bundle_orders')) {
        $params = [$start_date, $today];
        $param_types = "ss";
        $agent_filter = "";

        if ($user_role === 'agent' && $user_id) {
            $agent_filter = "AND (agent_id = ? OR user_id = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
            $param_types .= "ii";
        } elseif ($user_role === 'customer' && $user_id) {
            $agent_filter = "AND user_id = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }

        $sql = "
            SELECT COALESCE(SUM(amount), 0) as total_sales,
                   COUNT(*) as total_orders
            FROM bundle_orders
            WHERE status IN ('success', 'delivered', 'completed')
              AND DATE(created_at) BETWEEN ? AND ?
              {$agent_filter}
        ";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                $total_sales += (float) ($row['total_sales'] ?? 0);
                $total_orders += (int) ($row['total_orders'] ?? 0);
            }
        }
    }

    // 2. Query afa_registrations
    if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
        $params = [$start_date, $today];
        $param_types = "ss";
        $agent_filter = "";

        if ($user_role === 'agent' && $user_id) {
            $agent_filter = "AND (agent_id = ? OR user_id = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
            $param_types .= "ii";
        } elseif ($user_role === 'customer' && $user_id) {
            $agent_filter = "AND user_id = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }

        $sql = "
            SELECT COALESCE(SUM(amount), 0) as total_sales,
                   COUNT(*) as total_orders
            FROM afa_registrations
            WHERE status IN ('success', 'delivered', 'completed', 'processing')
              AND DATE(created_at) BETWEEN ? AND ?
              {$agent_filter}
        ";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                $total_sales += (float) ($row['total_sales'] ?? 0);
                $total_orders += (int) ($row['total_orders'] ?? 0);
            }
        }
    }

    // 3. Query result_checker_purchases
    if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
        $params = [$start_date, $today];
        $param_types = "ss";
        $agent_filter = "";

        if ($user_role === 'agent' && $user_id) {
            $agent_filter = "AND (agent_id = ? OR user_id = ?)";
            $params[] = $user_id;
            $params[] = $user_id;
            $param_types .= "ii";
        } elseif ($user_role === 'customer' && $user_id) {
            $agent_filter = "AND user_id = ?";
            $params[] = $user_id;
            $param_types .= "i";
        }

        $sql = "
            SELECT COALESCE(SUM(amount), 0) as total_sales,
                   COUNT(*) as total_orders
            FROM result_checker_purchases
            WHERE status = 'success'
              AND DATE(created_at) BETWEEN ? AND ?
              {$agent_filter}
        ";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                $total_sales += (float) ($row['total_sales'] ?? 0);
                $total_orders += (int) ($row['total_orders'] ?? 0);
            }
        }
    }

    $summary = [
        'total_sales' => $total_sales,
        'total_orders' => $total_orders
    ];

    cacheAnalytics($cache_key, $summary, $user_id, 900);
    return $summary;
}


/**
 * Summarize profits over a span of days.
 */
function getProfitSummary($days = 1) {
    global $db;

    $days = max(1, (int) $days);
    $default = [
        'total_profit' => 0,
        'total_revenue' => 0,
        'total_cost' => 0,
        'total_orders' => 0
    ];

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_profits')) {
        return $default;
    }

    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cache_key = "profit_summary_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 900);
    if ($cached) {
        return $cached;
    }

    $revenue_column = null;
    if (function_exists('dbh_table_has_column')) {
        foreach (['customer_paid', 'customer_payment'] as $candidate) {
            if (dbh_table_has_column('agent_profits', $candidate)) {
                $revenue_column = $candidate;
                break;
            }
        }
    }
    $cost_column = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_profits', 'agent_cost')
        ? 'agent_cost'
        : null;

    $select_clauses = [
        'COALESCE(SUM(ap.profit_amount), 0) as total_profit',
        $revenue_column ? "COALESCE(SUM(ap.{$revenue_column}), 0) as total_revenue" : '0 as total_revenue',
        $cost_column ? "COALESCE(SUM(ap.{$cost_column}), 0) as total_cost" : '0 as total_cost',
        'COALESCE(SUM(CASE WHEN bo.agent_id IS NOT NULL THEN ap.profit_amount ELSE 0 END), 0) as agent_profit',
        'COALESCE(SUM(CASE WHEN bo.agent_id IS NULL THEN ap.profit_amount ELSE 0 END), 0) as customer_profit',
        'COUNT(*) as total_orders'
    ];

    $sql = "
        SELECT
            " . implode(",\n            ", $select_clauses) . "
        FROM agent_profits ap
        LEFT JOIN bundle_orders bo ON bo.id = ap.order_id
        WHERE DATE(ap.created_at) BETWEEN ? AND ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('ss', $start_date, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    $summary = [
        'total_profit' => (float) ($data['total_profit'] ?? 0),
        'total_revenue' => (float) ($data['total_revenue'] ?? 0),
        'total_cost' => (float) ($data['total_cost'] ?? 0),
        'total_orders' => (int) ($data['total_orders'] ?? 0)
    ];

    cacheAnalytics($cache_key, $summary, null, 900);
    return $summary;
}


/**
 * Retrieve per-day profit breakdown for the requested window.
 */
function getProfitTrends($days = 7) {
    global $db;

    $days = max(1, (int) $days);

    if (!function_exists('dbh_table_exists') || !dbh_table_exists('agent_profits')) {
        return [];
    }

    $today = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cache_key = "profit_trends_{$days}d_{$today}";
    $cached = getCachedAnalytics($cache_key, null, 900);
    if ($cached) {
        return $cached;
    }

    $limit = (int) $days;
    $revenue_column = null;
    if (function_exists('dbh_table_has_column')) {
        foreach (['customer_paid', 'customer_payment'] as $candidate) {
            if (dbh_table_has_column('agent_profits', $candidate)) {
                $revenue_column = $candidate;
                break;
            }
        }
    }
    $cost_column = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_profits', 'agent_cost')
        ? 'agent_cost'
        : null;

    $select_clauses = [
        'DATE(ap.created_at) as profit_date',
        'COALESCE(SUM(ap.profit_amount), 0) as total_profit',
        $revenue_column ? "COALESCE(SUM(ap.{$revenue_column}), 0) as total_revenue" : '0 as total_revenue',
        $cost_column ? "COALESCE(SUM(ap.{$cost_column}), 0) as total_cost" : '0 as total_cost',
        'COALESCE(SUM(CASE WHEN bo.agent_id IS NOT NULL THEN ap.profit_amount ELSE 0 END), 0) as agent_profit',
        'COALESCE(SUM(CASE WHEN bo.agent_id IS NULL THEN ap.profit_amount ELSE 0 END), 0) as customer_profit',
        'COUNT(*) as total_orders'
    ];

    $sql = "
        SELECT
            " . implode(",\n            ", $select_clauses) . "
        FROM agent_profits ap
        LEFT JOIN bundle_orders bo ON bo.id = ap.order_id
        WHERE DATE(ap.created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) DESC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ss', $start_date, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    cacheAnalytics($cache_key, $rows, null, 900);
    return $rows;
}


/**
 * Sum up successful/completed orders from bundle_orders, afa_registrations, and result_checker_purchases.
 */
function getDashboardTotalOrdersCount($user_id, $user_role = 'admin') {
    global $db;
    $total_orders = 0;
    
    // 1. bundle_orders count
    $order_statuses = "'success','delivered','completed'";
    if (function_exists('dbh_table_exists') && dbh_table_exists('bundle_orders')) {
        if ($user_role === 'admin') {
            $sql = "SELECT COUNT(*) as total FROM bundle_orders WHERE status IN ({$order_statuses})";
            $res = $db->query($sql);
            if ($res) {
                $row = $res->fetch_assoc();
                $total_orders += (int)($row['total'] ?? 0);
            }
        } else {
            $sql = "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ? AND status IN ({$order_statuses})";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res) {
                    $row = $res->fetch_assoc();
                    $total_orders += (int)($row['total'] ?? 0);
                }
            }
        }
    }

    // 2. afa_registrations count
    $afa_statuses = "'success','completed','delivered','processing'";
    if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
        if ($user_role === 'admin') {
            $sql = "SELECT COUNT(*) as total FROM afa_registrations WHERE status IN ({$afa_statuses})";
            $res = $db->query($sql);
            if ($res) {
                $row = $res->fetch_assoc();
                $total_orders += (int)($row['total'] ?? 0);
            }
        } else {
            $sql = "SELECT COUNT(*) as total FROM afa_registrations WHERE user_id = ? AND status IN ({$afa_statuses})";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res) {
                    $row = $res->fetch_assoc();
                    $total_orders += (int)($row['total'] ?? 0);
                }
            }
        }
    }

    // 3. result_checker_purchases count
    $rc_statuses = "'success'";
    if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
        if ($user_role === 'admin') {
            $sql = "SELECT COUNT(*) as total FROM result_checker_purchases WHERE status IN ({$rc_statuses})";
            $res = $db->query($sql);
            if ($res) {
                $row = $res->fetch_assoc();
                $total_orders += (int)($row['total'] ?? 0);
            }
        } else {
            $sql = "SELECT COUNT(*) as total FROM result_checker_purchases WHERE user_id = ? AND status IN ({$rc_statuses})";
            $stmt = $db->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res) {
                    $row = $res->fetch_assoc();
                    $total_orders += (int)($row['total'] ?? 0);
                }
            }
        }
    }

    return $total_orders;
}


/**
 * Get dashboard statistics
 */
function getDashboardStats($user_id, $user_role = 'admin') {
    global $db;

    $cache_key = "dashboard_stats_" . $user_role . "_" . $user_id;
    $cached = getCachedAnalytics($cache_key, $user_id, 900);
    $use_cached_only = false;
    
    if ($cached) {
        if ($user_role !== 'agent') {
            return $cached;
        }
        $stats = $cached;
        $use_cached_only = true;
    } else {
        $stats = [];
    }

    // Normalized order statuses we consider "completed"
    $order_statuses = "'success','delivered'";

    // Define queries based on role
    $queries = [
        'admin' => [
            'total_users' => "SELECT COUNT(*) as total FROM users WHERE role != 'admin'",
            'total_agents' => "SELECT COUNT(*) as total FROM users WHERE role = 'agent'",
            'total_customers' => "SELECT COUNT(*) as total FROM users WHERE role = 'customer'",
            'total_sales' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE status = 'success' AND transaction_type = 'purchase'",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE status IN ({$order_statuses})",
            'total_balance' => "SELECT COALESCE(SUM(balance), 0) as total FROM wallets"
        ],
        'agent' => [
            'wallet_balance' => "SELECT COALESCE(balance, 0) as balance FROM wallets WHERE user_id = ?",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ? AND status IN ({$order_statuses})",
            'total_sales' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND status = 'success' AND transaction_type = 'purchase'",
            'total_customers' => "SELECT COUNT(DISTINCT bo.user_id) as total FROM bundle_orders bo WHERE bo.agent_id = ? AND bo.status = 'success'"
        ],
        'customer' => [
            'wallet_balance' => "SELECT COALESCE(balance, 0) as balance FROM wallets WHERE user_id = ?",
            'total_orders' => "SELECT COUNT(*) as total FROM bundle_orders WHERE user_id = ? AND status IN ({$order_statuses})",
            'total_spent' => "SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND status = 'success' AND transaction_type = 'purchase'"
        ]
    ];
    
    $role_queries = $queries[$user_role] ?? $queries['admin'];
    
    if (!$use_cached_only) {
        foreach ($role_queries as $key => $query) {
            if ($key === 'total_orders') {
                $stats[$key] = getDashboardTotalOrdersCount($user_id, $user_role);
                continue;
            }
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
    }

    if ($user_role === 'agent' && $user_id) {
        $hasAgentIdCol = dbh_table_has_column('users', 'agent_id');
        $hasReferrals = dbh_table_exists('user_referrals');
        $hasBundleOrders = dbh_table_exists('bundle_orders');

        try {
            if ($hasAgentIdCol && $hasReferrals && $hasBundleOrders) {
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT u.id) AS total
                    FROM users u
                    LEFT JOIN user_referrals ur ON ur.user_id = u.id
                    LEFT JOIN bundle_orders bo ON bo.user_id = u.id AND bo.agent_id = ? AND bo.status IN ({$order_statuses})
                    WHERE u.role = 'customer' AND (u.agent_id = ? OR ur.agent_id = ? OR bo.agent_id = ?)
                ");
                $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
            } elseif ($hasAgentIdCol) {
                $stmt = $db->prepare("SELECT COUNT(*) AS total FROM users WHERE role = 'customer' AND agent_id = ?");
                $stmt->bind_param("i", $user_id);
            } elseif ($hasReferrals) {
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT u.id) AS total
                    FROM users u
                    JOIN user_referrals ur ON ur.user_id = u.id
                    WHERE u.role = 'customer' AND ur.agent_id = ?
                ");
                $stmt->bind_param("i", $user_id);
            } elseif ($hasBundleOrders) {
                $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) AS total FROM bundle_orders WHERE agent_id = ? AND status IN ({$order_statuses})");
                $stmt->bind_param("i", $user_id);
            } else {
                $stmt = null;
            }

            if (!empty($stmt)) {
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row && isset($row['total'])) {
                    $stats['total_customers'] = (int) $row['total'];
                }
            }
        } catch (Exception $e) {
            error_log('Agent client count query failed: ' . $e->getMessage());
        }
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

    cacheAnalytics($cache_key, $stats, $user_id, 900);
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
    
    $where_clause = "";
    $params = [$limit];
    $param_types = "i";
    
    if ($user_id) {
        $where_clause = "WHERE t.user_id = ?";
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
            dp.name as package_name, 
            n.name as network_name, 
            n.color as network_color,
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
        FROM transactions t
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
        $where_clause
        ORDER BY t.created_at DESC
        LIMIT ?
    ";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($param_types, ...$params);

    $attempts = 0;
    while (true) {
        try {
            $stmt->execute();
            break;
        } catch (mysqli_sql_exception $e) {
            $attempts++;
            $code = (int) $e->getCode();
            $message = strtolower($e->getMessage() ?? '');
            $isGoneAway = in_array($code, [2006, 2013], true) || strpos($message, 'server has gone away') !== false;

            if (!$isGoneAway || $attempts >= 2) {
                error_log('getRecentTransactions failed: ' . $e->getMessage());
                return [];
            }

            // Reconnect and retry once
            $db->getConnection();
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($param_types, ...$params);
        }
    }

    $result = $stmt->get_result();
    if (!$result) {
        return [];
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Clear analytics cache
 */
function clearAnalyticsCache($cache_key = null, $user_id = null) {
    global $db;
    
    if ($cache_key) {
        $stmt = $db->prepare("DELETE FROM analytics_cache WHERE cache_key = ? AND user_id = ?");
        $stmt->bind_param("si", $cache_key, $user_id);
    } else {
        $stmt = $db->prepare("DELETE FROM analytics_cache WHERE expires_at < NOW()");
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
    
    if ($user_role === 'agent' && $user_id) {
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
