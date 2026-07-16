<?php
require_once '../config/config.php';

if (function_exists('requireAnyRole')) {
    requireAnyRole(['admin', 'super_admin']);
} else {
    requireRole('admin');
}

$pageTitle = 'User Data Info';
$csrf_token = generateCSRF();
$flash = getFlashMessage();
$current_admin = getCurrentUser();
$redirect = 'user-data-info.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '');

function udi_trim_text($value, $max = 170, $suffix = '...') {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $max = (int)$max;
    if ($max <= 0) {
        return $text;
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, $suffix);
    }

    if (strlen($text) <= $max) {
        return $text;
    }

    return rtrim(substr($text, 0, max(1, $max - strlen($suffix)))) . $suffix;
}

function udi_reason_from_json($jsonText) {
    if (!is_string($jsonText) || trim($jsonText) === '') {
        return '';
    }
    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        return '';
    }
    foreach ([['error'], ['message'], ['response', 'message'], ['response', 'error'], ['data', 'message']] as $path) {
        $value = $decoded;
        foreach ($path as $p) {
            if (!is_array($value) || !array_key_exists($p, $value)) {
                $value = null;
                break;
            }
            $value = $value[$p];
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    return '';
}

function udi_delivery_reason(array $row) {
    $status = strtolower(trim((string)($row['delivery_status'] ?? '')));
    if ($status === '') {
        return 'No linked order.';
    }
    foreach ([
        trim((string)($row['api_error_message'] ?? '')),
        udi_reason_from_json($row['api_response'] ?? null),
        udi_reason_from_json($row['api_log_response'] ?? null)
    ] as $reason) {
        if ($reason !== '') {
            return udi_trim_text($reason, 170, '...');
        }
    }
    if (in_array($status, ['pending', 'processing'], true)) {
        $at = $row['order_updated_at'] ?? $row['order_created_at'] ?? null;
        if ($at) {
            $mins = (int) floor((time() - strtotime((string) $at)) / 60);
            if ($mins >= 20) {
                return 'Stuck in ' . $status . ' for about ' . $mins . ' minutes.';
            }
        }
        return ucfirst($status) . ' awaiting provider confirmation.';
    }
    if ($status === 'failed') {
        return 'Provider/API failed this order.';
    }
    return ucfirst($status) . '.';
}

function udi_quote_sql_list(mysqli $conn, array $items, $numeric = false) {
    $out = [];
    foreach ($items as $item) {
        if ($numeric) {
            $value = (int) $item;
            if ($value > 0) {
                $out[] = (string) $value;
            }
            continue;
        }

        $value = trim((string) $item);
        if ($value === '') {
            continue;
        }
        $out[] = "'" . $conn->real_escape_string($value) . "'";
    }
    return implode(',', array_values(array_unique($out)));
}

function udi_get_wallet_totals($db) {
    $result = [
        'total_balance' => 0.0,
        'wallet_count' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $sql = "SELECT COALESCE(SUM(balance), 0) AS total_balance, COUNT(*) AS wallet_count FROM wallets";
    $res = $db->query($sql);
    if ($res && ($row = $res->fetch_assoc())) {
        $result['total_balance'] = (float) ($row['total_balance'] ?? 0);
        $result['wallet_count'] = (int) ($row['wallet_count'] ?? 0);
        $result['updated_at'] = date('Y-m-d H:i:s');
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deduct_user_wallet') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
        header('Location: ' . $redirect);
        exit();
    }

    $target_user_id = (int) ($_POST['target_user_id'] ?? 0);
    $amount = round((float) ($_POST['deduction_amount'] ?? 0), 2);
    $note = sanitize(trim((string) ($_POST['deduction_note'] ?? '')));

    try {
        if ($target_user_id <= 0 || $amount <= 0) {
            throw new Exception('Provide valid user ID and amount.');
        }
        $stmt = $db->prepare("SELECT id, full_name, username FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $target_user_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        if (!$target) {
            throw new Exception('User not found.');
        }

        $before = getWalletBalance($target_user_id);
        if ($before < $amount) {
            throw new Exception('Insufficient balance. Available: ' . CURRENCY . number_format($before, 2));
        }

        $reference = generateReference('UDED');
        $desc = 'Admin deduction via User Data Info' . ($note !== '' ? (': ' . $note) : '');
        if (!updateWalletBalance($target_user_id, $amount, 'debit', $reference, $desc)) {
            throw new Exception('Deduction failed.');
        }

        $after = getWalletBalance($target_user_id);
        sendWalletDebitNotification($target_user_id, $amount, $after, $desc);
        if (!empty($current_admin['id'])) {
            logActivity((int)$current_admin['id'], 'admin_user_data_deduction', json_encode([
                'target_user_id' => $target_user_id,
                'amount' => $amount,
                'reference' => $reference,
                'before' => $before,
                'after' => $after
            ]));
        }

        $name = trim((string)($target['full_name'] ?? '')) ?: ('User #' . $target_user_id);
        setFlashMessage('success', 'Deducted ' . CURRENCY . number_format($amount, 2) . ' from ' . $name . '. New balance: ' . CURRENCY . number_format($after, 2));
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }

    header('Location: ' . $redirect);
    exit();
}

$search = trim((string)($_GET['search'] ?? ''));
$role = strtolower(trim((string)($_GET['role'] ?? 'all')));
$delivery = strtolower(trim((string)($_GET['delivery'] ?? 'all')));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 50);
if (!in_array($per_page, [25, 50, 100], true)) $per_page = 50;
if (!in_array($role, ['all', 'admin', 'agent', 'customer'], true)) $role = 'all';
if (!in_array($delivery, ['all', 'delivered', 'pending', 'failed', 'none'], true)) $delivery = 'all';
$offset = ($page - 1) * $per_page;
$wallet_totals = ['total_balance' => 0.0, 'wallet_count' => 0, 'updated_at' => date('Y-m-d H:i:s')];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'wallet_totals') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $totals = udi_get_wallet_totals($db);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_balance' => $totals['total_balance'],
                'formatted_total' => CURRENCY . number_format((float) $totals['total_balance'], 2),
                'wallet_count' => (int) $totals['wallet_count'],
                'updated_at' => $totals['updated_at'],
                'updated_at_human' => date('M j, Y g:i:s A', strtotime((string) $totals['updated_at']))
            ]
        ]);
    } catch (Throwable $e) {
        error_log('User Data Info wallet totals ajax error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to load wallet totals.'
        ]);
    }
    exit();
}

$conn = $db->getConnection();
$baseWhere = ["1=1"];
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $like = "'%" . $safe . "%'";
    $baseWhere[] = "(u.full_name LIKE {$like} OR u.email LIKE {$like} OR u.username LIKE {$like} OR wt.reference LIKE {$like} OR wt.description LIKE {$like})";
}
if ($role !== 'all') {
    $safeRole = $conn->real_escape_string($role);
    $baseWhere[] = "u.role = '{$safeRole}'";
}
$baseWhereSql = implode(' AND ', $baseWhere);

$deliveryWhere = "1=1";
if ($delivery === 'delivered') $deliveryWhere = "COALESCE(bo.status,'') IN ('delivered','success','Delivered','Success','DELIVERED','SUCCESS')";
if ($delivery === 'pending') $deliveryWhere = "COALESCE(bo.status,'') IN ('pending','processing','Pending','Processing','PENDING','PROCESSING')";
if ($delivery === 'failed') $deliveryWhere = "COALESCE(bo.status,'') IN ('failed','Failed','FAILED')";
if ($delivery === 'none') $deliveryWhere = "bo.id IS NULL";
$hasDeliveryFilter = $delivery !== 'all';

$total_rows = 0;
$total_pages = 1;
$rows = [];
$overview = ['pending' => 0, 'failed' => 0, 'delivered' => 0, 'overdue' => 0];
$issueCounts = [];
$issueRows = [];

try {
    $wallet_totals = udi_get_wallet_totals($db);

    $countSql = '';
    if ($hasDeliveryFilter) {
        $countSql = "
SELECT COUNT(*) AS total_rows
FROM wallet_transactions wt
JOIN users u ON u.id = wt.user_id
LEFT JOIN transactions t ON t.reference = wt.reference AND t.user_id = wt.user_id
LEFT JOIN bundle_orders bo ON bo.id = (
    SELECT bo2.id
    FROM bundle_orders bo2
    WHERE ((t.id IS NOT NULL AND bo2.transaction_id = t.id) OR bo2.order_reference = wt.reference)
    ORDER BY bo2.id DESC
    LIMIT 1
)
WHERE {$baseWhereSql} AND {$deliveryWhere}
";
    } else {
        $countSql = "
SELECT COUNT(*) AS total_rows
FROM wallet_transactions wt
JOIN users u ON u.id = wt.user_id
WHERE {$baseWhereSql}
";
    }
    $total_rows = 0;
    $countRes = $db->query($countSql);
    if ($countRes && ($r = $countRes->fetch_assoc())) {
        $total_rows = (int)($r['total_rows'] ?? 0);
    }
    $total_pages = max(1, (int)ceil(($total_rows > 0 ? $total_rows : 1) / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    $rows = [];
    if ($hasDeliveryFilter) {
        $dataSql = "
SELECT
    wt.id,
    wt.user_id,
    wt.transaction_type,
    wt.amount,
    wt.balance_before,
    wt.balance_after,
    wt.reference,
    wt.description,
    wt.created_at,
    COALESCE(NULLIF(u.full_name,''), NULLIF(u.username,''), CONCAT('User #', u.id)) AS user_name,
    COALESCE(NULLIF(u.email,''), 'N/A') AS email,
    COALESCE(NULLIF(u.role,''), 'unknown') AS role,
    COALESCE(w.balance, wt.balance_after) AS live_balance,
    COALESCE(NULLIF(bo.status,''), '') AS delivery_status,
    COALESCE(NULLIF(bo.provider_status,''), '') AS provider_status,
    bo.api_response,
    bo.created_at AS order_created_at,
    bo.updated_at AS order_updated_at,
    atl.error_message AS api_error_message,
    atl.response_data AS api_log_response
FROM wallet_transactions wt
JOIN users u ON u.id = wt.user_id
LEFT JOIN wallets w ON w.user_id = wt.user_id
LEFT JOIN transactions t ON t.reference = wt.reference AND t.user_id = wt.user_id
LEFT JOIN bundle_orders bo ON bo.id = (
    SELECT bo2.id
    FROM bundle_orders bo2
    WHERE ((t.id IS NOT NULL AND bo2.transaction_id = t.id) OR bo2.order_reference = wt.reference)
    ORDER BY bo2.id DESC
    LIMIT 1
)
LEFT JOIN (
    SELECT l.bundle_order_id, l.error_message, l.response_data
    FROM api_transaction_logs l
    JOIN (
        SELECT bundle_order_id, MAX(id) AS max_id
        FROM api_transaction_logs
        GROUP BY bundle_order_id
    ) lx ON lx.max_id = l.id
) atl ON atl.bundle_order_id = bo.id
WHERE {$baseWhereSql} AND {$deliveryWhere}
ORDER BY wt.created_at DESC
LIMIT {$per_page} OFFSET {$offset}
";
        $res = $db->query($dataSql);
        if ($res) {
            $rows = $res->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        // Fast path: fetch the current wallet page first, then enrich only those rows.
        $baseSql = "
SELECT
    wt.id,
    wt.user_id,
    wt.transaction_type,
    wt.amount,
    wt.balance_before,
    wt.balance_after,
    wt.reference,
    wt.description,
    wt.created_at,
    COALESCE(NULLIF(u.full_name,''), NULLIF(u.username,''), CONCAT('User #', u.id)) AS user_name,
    COALESCE(NULLIF(u.email,''), 'N/A') AS email,
    COALESCE(NULLIF(u.role,''), 'unknown') AS role,
    COALESCE(w.balance, wt.balance_after) AS live_balance,
    t.id AS linked_transaction_id
FROM wallet_transactions wt
JOIN users u ON u.id = wt.user_id
LEFT JOIN wallets w ON w.user_id = wt.user_id
LEFT JOIN transactions t ON t.reference = wt.reference AND t.user_id = wt.user_id
WHERE {$baseWhereSql}
ORDER BY wt.created_at DESC
LIMIT {$per_page} OFFSET {$offset}
";
        $baseRes = $db->query($baseSql);
        if ($baseRes) {
            $rows = $baseRes->fetch_all(MYSQLI_ASSOC);
        }

        if (!empty($rows)) {
            $references = [];
            $transactionIds = [];
            foreach ($rows as $row) {
                $ref = trim((string) ($row['reference'] ?? ''));
                $txId = (int) ($row['linked_transaction_id'] ?? 0);
                if ($ref !== '') {
                    $references[] = $ref;
                }
                if ($txId > 0) {
                    $transactionIds[] = $txId;
                }
            }

            $latestByTx = [];
            $latestByRef = [];
            $selectedOrders = [];

            $txList = udi_quote_sql_list($conn, $transactionIds, true);
            $refList = udi_quote_sql_list($conn, $references, false);
            $whereOrderParts = [];
            if ($txList !== '') {
                $whereOrderParts[] = "bo.transaction_id IN ({$txList})";
            }
            if ($refList !== '') {
                $whereOrderParts[] = "bo.order_reference IN ({$refList})";
            }

            if (!empty($whereOrderParts)) {
                $ordersSql = "
SELECT
    bo.id,
    bo.transaction_id,
    bo.order_reference,
    bo.status,
    bo.provider_status,
    bo.api_response,
    bo.created_at,
    bo.updated_at
FROM bundle_orders bo
WHERE " . implode(' OR ', $whereOrderParts) . "
ORDER BY bo.id DESC
";
                $ordersRes = $db->query($ordersSql);
                if ($ordersRes) {
                    while ($order = $ordersRes->fetch_assoc()) {
                        $txId = (int) ($order['transaction_id'] ?? 0);
                        $orderRef = trim((string) ($order['order_reference'] ?? ''));
                        if ($txId > 0 && !isset($latestByTx[$txId])) {
                            $latestByTx[$txId] = $order;
                        }
                        if ($orderRef !== '' && !isset($latestByRef[$orderRef])) {
                            $latestByRef[$orderRef] = $order;
                        }
                    }
                }
            }

            foreach ($rows as $index => $row) {
                $txId = (int) ($row['linked_transaction_id'] ?? 0);
                $orderRef = trim((string) ($row['reference'] ?? ''));
                $txOrder = $txId > 0 ? ($latestByTx[$txId] ?? null) : null;
                $refOrder = $orderRef !== '' ? ($latestByRef[$orderRef] ?? null) : null;

                $selected = null;
                if (is_array($txOrder) && is_array($refOrder)) {
                    $selected = ((int) ($txOrder['id'] ?? 0) >= (int) ($refOrder['id'] ?? 0)) ? $txOrder : $refOrder;
                } elseif (is_array($txOrder)) {
                    $selected = $txOrder;
                } elseif (is_array($refOrder)) {
                    $selected = $refOrder;
                }

                if ($selected) {
                    $orderId = (int) ($selected['id'] ?? 0);
                    $rows[$index]['delivery_status'] = (string) ($selected['status'] ?? '');
                    $rows[$index]['provider_status'] = (string) ($selected['provider_status'] ?? '');
                    $rows[$index]['api_response'] = (string) ($selected['api_response'] ?? '');
                    $rows[$index]['order_created_at'] = (string) ($selected['created_at'] ?? '');
                    $rows[$index]['order_updated_at'] = (string) ($selected['updated_at'] ?? '');
                    $rows[$index]['api_error_message'] = '';
                    $rows[$index]['api_log_response'] = '';
                    $rows[$index]['linked_order_id'] = $orderId;
                    if ($orderId > 0) {
                        $selectedOrders[] = $orderId;
                    }
                } else {
                    $rows[$index]['delivery_status'] = '';
                    $rows[$index]['provider_status'] = '';
                    $rows[$index]['api_response'] = '';
                    $rows[$index]['order_created_at'] = '';
                    $rows[$index]['order_updated_at'] = '';
                    $rows[$index]['api_error_message'] = '';
                    $rows[$index]['api_log_response'] = '';
                    $rows[$index]['linked_order_id'] = 0;
                }
            }

            $orderList = udi_quote_sql_list($conn, $selectedOrders, true);
            if ($orderList !== '') {
                $logsSql = "
SELECT l.bundle_order_id, l.error_message, l.response_data
FROM api_transaction_logs l
JOIN (
    SELECT bundle_order_id, MAX(id) AS max_id
    FROM api_transaction_logs
    WHERE bundle_order_id IN ({$orderList})
    GROUP BY bundle_order_id
) lx ON lx.max_id = l.id
";
                $logsRes = $db->query($logsSql);
                $latestLogs = [];
                if ($logsRes) {
                    while ($log = $logsRes->fetch_assoc()) {
                        $latestLogs[(int) ($log['bundle_order_id'] ?? 0)] = $log;
                    }
                }

                foreach ($rows as $index => $row) {
                    $orderId = (int) ($row['linked_order_id'] ?? 0);
                    if ($orderId > 0 && isset($latestLogs[$orderId])) {
                        $rows[$index]['api_error_message'] = (string) ($latestLogs[$orderId]['error_message'] ?? '');
                        $rows[$index]['api_log_response'] = (string) ($latestLogs[$orderId]['response_data'] ?? '');
                    }
                }
            }
        }
    }

    $metricsCacheKey = 'udi_metrics_cache_v1';
    $metricsTtl = 90;
    $metricsCached = false;
    if (isset($_SESSION[$metricsCacheKey]) && is_array($_SESSION[$metricsCacheKey])) {
        $cached = $_SESSION[$metricsCacheKey];
        $cachedAt = (int) ($cached['cached_at'] ?? 0);
        if ($cachedAt > 0 && (time() - $cachedAt) <= $metricsTtl) {
            $overview = is_array($cached['overview'] ?? null) ? $cached['overview'] : $overview;
            $issueCounts = is_array($cached['issue_counts'] ?? null) ? $cached['issue_counts'] : [];
            $metricsCached = true;
        }
    }

    if (!$metricsCached) {
        $overview = ['pending' => 0, 'failed' => 0, 'delivered' => 0, 'overdue' => 0];
        $overRes = $db->query("
    SELECT
        SUM(CASE WHEN status IN ('pending','processing','Pending','Processing','PENDING','PROCESSING') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status IN ('failed','Failed','FAILED') THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status IN ('delivered','success','Delivered','Success','DELIVERED','SUCCESS') THEN 1 ELSE 0 END) AS delivered,
        SUM(CASE WHEN status IN ('pending','processing','Pending','Processing','PENDING','PROCESSING') AND updated_at <= DATE_SUB(NOW(), INTERVAL 20 MINUTE) THEN 1 ELSE 0 END) AS overdue
    FROM bundle_orders
");
        if ($overRes && ($ov = $overRes->fetch_assoc())) {
            $overview['pending'] = (int)($ov['pending'] ?? 0);
            $overview['failed'] = (int)($ov['failed'] ?? 0);
            $overview['delivered'] = (int)($ov['delivered'] ?? 0);
            $overview['overdue'] = (int)($ov['overdue'] ?? 0);
        }

        $issueCounts = [];
        $issueRows = [];
        $issueRes = $db->query("
    SELECT bo.id, bo.order_reference, bo.status AS delivery_status, bo.provider_status, bo.api_response, bo.created_at AS order_created_at, bo.updated_at AS order_updated_at,
           COALESCE(NULLIF(u.full_name,''), NULLIF(u.username,''), CONCAT('User #', bo.user_id)) AS user_name,
           atl.error_message AS api_error_message, atl.response_data AS api_log_response
    FROM bundle_orders bo
    LEFT JOIN users u ON u.id = bo.user_id
    LEFT JOIN (
        SELECT l.bundle_order_id, l.error_message, l.response_data
        FROM api_transaction_logs l
        JOIN (
            SELECT bundle_order_id, MAX(id) AS max_id
            FROM api_transaction_logs
            WHERE bundle_order_id IS NOT NULL
            GROUP BY bundle_order_id
        ) lx ON lx.max_id = l.id
    ) atl ON atl.bundle_order_id = bo.id
    WHERE bo.status IN ('pending','processing','failed','Pending','Processing','Failed','PENDING','PROCESSING','FAILED')
    ORDER BY bo.updated_at DESC
    LIMIT 120
");
        if ($issueRes) {
            $issueRows = $issueRes->fetch_all(MYSQLI_ASSOC);
            foreach ($issueRows as $issue) {
                $reason = udi_delivery_reason($issue);
                $k = strtolower($reason);
                if (!isset($issueCounts[$k])) {
                    $issueCounts[$k] = ['reason' => $reason, 'count' => 0];
                }
                $issueCounts[$k]['count']++;
            }
            $issueCounts = array_values($issueCounts);
            usort($issueCounts, function ($a, $b) {
                return ((int)$b['count']) <=> ((int)$a['count']);
            });
            $issueCounts = array_slice($issueCounts, 0, 6);
        }

        $_SESSION[$metricsCacheKey] = [
            'cached_at' => time(),
            'overview' => $overview,
            'issue_counts' => $issueCounts
        ];
    }
} catch (Throwable $e) {
    error_log('User Data Info page load error: ' . $e->getMessage());
    if (empty($rows)) {
        $rows = [];
    }
    if (empty($overview)) {
        $overview = ['pending' => 0, 'failed' => 0, 'delivered' => 0, 'overdue' => 0];
    }
    if (empty($issueCounts)) {
        $issueCounts = [];
    }
    if (empty($issueRows)) {
        $issueRows = [];
    }
}

include '../includes/admin_header.php';
?>
<div class="dashboard-content">
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div><?php endif; ?>
<div class="page-title"><h1><i class="fas fa-database"></i> User Data Info</h1><p class="page-subtitle">User balances, transaction history, delivery status, deductions, and order-failure causes.</p></div>

<div class="widget">
<div class="widget-body">
<div class="udi-wallet-total-card">
<div class="udi-wallet-total-label">Total Money In User Wallets</div>
<div class="udi-wallet-total-value" id="walletTotalValue"><?php echo CURRENCY . number_format((float)($wallet_totals['total_balance'] ?? 0), 2); ?></div>
<div class="udi-wallet-total-meta">
<span id="walletTotalCount"><?php echo number_format((int)($wallet_totals['wallet_count'] ?? 0)); ?> wallet account(s)</span>
<span id="walletTotalUpdated">Updated <?php echo htmlspecialchars(date('M j, Y g:i:s A', strtotime((string)($wallet_totals['updated_at'] ?? date('Y-m-d H:i:s'))))); ?></span>
</div>
</div>
</div>
</div>

<div class="widget"><div class="widget-header"><h3 class="widget-title">Dynamic Deduction</h3></div><div class="widget-body">
<form method="post" id="deductForm" class="udi-form">
<input type="hidden" name="action" value="deduct_user_wallet"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
<input class="form-control" type="number" min="1" name="target_user_id" id="target_user_id" placeholder="User ID" required>
<input class="form-control" type="number" min="0.01" step="0.01" name="deduction_amount" placeholder="Amount to deduct (<?php echo CURRENCY; ?>)" required>
<input class="form-control" type="text" name="deduction_note" maxlength="180" placeholder="Reason / note (optional)">
<button type="submit" class="btn btn-danger"><i class="fas fa-minus-circle"></i> Deduct Wallet</button>
</form>
<small class="text-muted">Tip: click <strong>Deduct</strong> inside any transaction row to auto-fill user ID.</small>
</div></div>

<div class="widget"><div class="widget-header"><h3 class="widget-title">Wallet Transactions (<?php echo number_format($total_rows); ?>)</h3>
<form method="get" class="udi-filter">
<input class="form-control" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search user/ref...">
<select class="form-control" name="role" onchange="this.form.submit()"><option value="all" <?php echo $role==='all'?'selected':''; ?>>All Roles</option><option value="admin" <?php echo $role==='admin'?'selected':''; ?>>Admin</option><option value="agent" <?php echo $role==='agent'?'selected':''; ?>>Agent</option><option value="customer" <?php echo $role==='customer'?'selected':''; ?>>Customer</option></select>
<select class="form-control" name="delivery" onchange="this.form.submit()"><option value="all" <?php echo $delivery==='all'?'selected':''; ?>>All Delivery</option><option value="delivered" <?php echo $delivery==='delivered'?'selected':''; ?>>Delivered</option><option value="pending" <?php echo $delivery==='pending'?'selected':''; ?>>Pending/Processing</option><option value="failed" <?php echo $delivery==='failed'?'selected':''; ?>>Failed</option><option value="none" <?php echo $delivery==='none'?'selected':''; ?>>No Order</option></select>
<select class="form-control" name="per_page" onchange="this.form.submit()"><option value="25" <?php echo $per_page===25?'selected':''; ?>>25</option><option value="50" <?php echo $per_page===50?'selected':''; ?>>50</option><option value="100" <?php echo $per_page===100?'selected':''; ?>>100</option></select>
<button class="btn btn-primary" type="submit">Filter</button>
</form></div>
<div class="widget-body"><div class="table-responsive udi-table-wrap"><table class="table udi-table"><thead><tr><th>ID</th><th>User</th><th>Type</th><th>Amount</th><th>Previous</th><th>Current</th><th>Live</th><th>Delivery</th><th>Cause</th><th>Reference</th><th>Date</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted">No records found.</td></tr><?php else: foreach ($rows as $row):
    $t = strtolower((string)$row['transaction_type']);
    $ds = strtolower((string)$row['delivery_status']);
    $reason = udi_delivery_reason($row);
    $dclass = $ds===''?'secondary':(in_array($ds,['delivered','success'],true)?'success':(in_array($ds,['pending','processing'],true)?'warning':($ds==='failed'?'danger':'secondary')));
?>
<tr>
<td data-label="ID"><?php echo (int)$row['id']; ?></td>
<td data-label="User"><strong><?php echo htmlspecialchars((string)$row['user_name']); ?></strong><br><small class="text-muted">#<?php echo (int)$row['user_id']; ?> | <?php echo htmlspecialchars(ucfirst((string)$row['role'])); ?></small></td>
<td data-label="Type"><span class="badge badge-<?php echo $t==='credit'?'success':'danger'; ?>"><?php echo strtoupper(htmlspecialchars($t)); ?></span></td>
<td data-label="Amount"><span class="transaction-amount <?php echo $t==='credit'?'credit':'debit'; ?>"><?php echo ($t==='credit'?'+':'-') . CURRENCY . number_format((float)$row['amount'],2); ?></span></td>
<td data-label="Previous"><?php echo CURRENCY . number_format((float)$row['balance_before'],2); ?></td>
<td data-label="Current"><?php echo CURRENCY . number_format((float)$row['balance_after'],2); ?></td>
<td data-label="Live"><?php echo CURRENCY . number_format((float)$row['live_balance'],2); ?></td>
<td data-label="Delivery"><span class="badge badge-<?php echo $dclass; ?>"><?php echo $ds!==''?htmlspecialchars(ucwords($ds)):'N/A'; ?></span></td>
<td data-label="Cause"><?php echo htmlspecialchars($reason); ?></td>
<td data-label="Reference"><code><?php echo htmlspecialchars((string)$row['reference']); ?></code></td>
<td data-label="Date"><?php echo !empty($row['created_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['created_at']))) : 'N/A'; ?></td>
<td data-label="Action"><button type="button" class="btn btn-danger btn-sm js-deduct" data-user-id="<?php echo (int)$row['user_id']; ?>" <?php echo ((float)$row['live_balance']<=0)?'disabled':''; ?>><i class="fas fa-minus-circle"></i> Deduct</button></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<div class="table-actions"><div class="text-muted">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?></div><?php if ($total_rows>$per_page): $q=$_GET; $q['page']=max(1,$page-1); ?><div class="btn-group"><a class="btn btn-outline btn-sm" href="user-data-info.php?<?php echo htmlspecialchars(http_build_query($q)); ?>">Prev</a><span class="btn btn-sm btn-secondary" style="cursor:default"><?php echo (int)$page; ?>/<?php echo (int)$total_pages; ?></span><?php $q['page']=min($total_pages,$page+1); ?><a class="btn btn-outline btn-sm" href="user-data-info.php?<?php echo htmlspecialchars(http_build_query($q)); ?>">Next</a></div><?php endif; ?></div>
</div></div>

<div class="widget"><div class="widget-header"><h3 class="widget-title">Delivery Failure Checks</h3></div><div class="widget-body">
<div class="stats-grid" style="margin-bottom:1rem;"><div class="stat-card"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div class="stat-content"><div class="stat-value"><?php echo number_format($overview['pending']); ?></div><div class="stat-label">Pending/Processing</div></div></div><div class="stat-card"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-content"><div class="stat-value"><?php echo number_format($overview['overdue']); ?></div><div class="stat-label">Pending 20+ mins</div></div></div><div class="stat-card"><div class="stat-icon"><i class="fas fa-times-circle"></i></div><div class="stat-content"><div class="stat-value"><?php echo number_format($overview['failed']); ?></div><div class="stat-label">Failed</div></div></div><div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-content"><div class="stat-value"><?php echo number_format($overview['delivered']); ?></div><div class="stat-label">Delivered</div></div></div></div>
<?php if ($issueCounts): ?><ul class="udi-reasons"><?php foreach ($issueCounts as $item): ?><li><span class="reason-count"><?php echo (int)$item['count']; ?></span><span><?php echo htmlspecialchars((string)$item['reason']); ?></span></li><?php endforeach; ?></ul><?php else: ?><p class="text-muted">No unresolved order causes found.</p><?php endif; ?>
</div></div>
</div>

<style>
.dashboard-content {
    overflow-x: hidden;
}

.udi-wallet-total-card {
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 0.9rem 1rem;
    background: var(--card-bg, #ffffff);
}

.udi-wallet-total-label {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted, #64748b);
}

.udi-wallet-total-value {
    font-size: 1.6rem;
    line-height: 1.15;
    font-weight: 700;
    margin-top: 0.25rem;
    color: var(--text-primary, #0f172a);
}

.udi-wallet-total-meta {
    margin-top: 0.4rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.82rem;
    color: var(--text-muted, #64748b);
}

[data-theme="dark"] .udi-wallet-total-card {
    background: #1f2937;
    border-color: #374151;
}

[data-theme="dark"] .udi-wallet-total-label {
    color: #cbd5e1;
}

[data-theme="dark"] .udi-wallet-total-value {
    color: #f8fafc;
}

[data-theme="dark"] .udi-wallet-total-meta {
    color: #e2e8f0;
}

[data-theme="dark"] .udi-table td,
[data-theme="dark"] .udi-table td strong {
    color: #f3f4f6;
}

[data-theme="dark"] .udi-table td code {
    background: #0f172a;
    color: #e2e8f0;
    border: 1px solid #334155;
}

[data-theme="dark"] .udi-table td .text-muted,
[data-theme="dark"] .table-actions .text-muted {
    color: #cbd5e1 !important;
}

[data-theme="dark"] .udi-table .badge-danger {
    background: #7f1d1d;
    color: #fee2e2;
}

[data-theme="dark"] .udi-table .badge-success {
    background: #14532d;
    color: #dcfce7;
}

[data-theme="dark"] .udi-table .badge-warning {
    background: #78350f;
    color: #fef3c7;
}

[data-theme="dark"] .udi-table .badge-secondary {
    background: #334155;
    color: #e2e8f0;
}

.udi-form,
.udi-filter {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.udi-form .form-control,
.udi-filter .form-control {
    min-width: 140px;
}

.udi-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.udi-table td code {
    white-space: normal;
    overflow-wrap: anywhere;
}

.table-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.udi-reasons {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.5rem;
}

.udi-reasons li {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
}

.reason-count {
    min-width: 34px;
    text-align: center;
    border-radius: 999px;
    background: #fee2e2;
    color: #991b1b;
    font-weight: 600;
    font-size: 0.78rem;
    padding: 0.1rem 0.4rem;
}

@media (max-width: 1024px) {
    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-wrapper,
    .main-content,
    .dashboard-content {
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-content {
        padding: 0.9rem;
        line-height: 1.4;
    }

    .page-title {
        margin-bottom: 0.9rem;
    }

    .page-title h1 {
        margin: 0 0 0.25rem;
        font-size: 1.24rem;
        line-height: 1.25;
    }

    .page-subtitle {
        margin: 0;
        font-size: 0.9rem;
        line-height: 1.4;
    }

    .widget {
        margin-bottom: 0.85rem;
    }

    .widget-header {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
        padding: 0.75rem 0.85rem;
    }

    .widget-title {
        margin: 0;
        font-size: 0.98rem;
        line-height: 1.3;
    }

    .widget-body {
        padding: 0.85rem;
    }

    .udi-wallet-total-value {
        font-size: 1.3rem;
    }

    .udi-form,
    .udi-filter {
        flex-direction: column;
        width: 100%;
    }

    .udi-form .form-control,
    .udi-filter .form-control,
    .udi-form .btn,
    .udi-filter .btn {
        width: 100%;
        min-width: 0;
        font-size: 0.88rem;
        line-height: 1.3;
    }

    .udi-filter .form-control {
        flex: 1 1 auto;
        min-width: 0;
        padding: 0.52rem 0.68rem;
    }

    .udi-form .form-control,
    .udi-form .btn,
    .udi-filter .btn {
        min-height: 2.2rem;
        padding: 0.5rem 0.66rem;
    }

    .udi-form .btn {
        font-size: 0.82rem;
        padding: 0.38rem 0.62rem;
        line-height: 1.2;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
    }

    .udi-table-wrap {
        overflow-x: visible;
    }

    .udi-table-wrap table,
    .udi-table-wrap thead,
    .udi-table-wrap tbody,
    .udi-table-wrap th,
    .udi-table-wrap td,
    .udi-table-wrap tr {
        display: block;
        width: 100%;
    }

    .udi-table-wrap thead {
        display: none;
    }

    .udi-table-wrap tbody tr {
        margin-bottom: 0.85rem;
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 10px;
        padding: 0.8rem 0.85rem;
        background: var(--card-bg, #fff);
    }

    .udi-table-wrap tbody td {
        border: none;
        padding: 0.42rem 0;
        display: flex;
        flex-direction: column;
        gap: 0.24rem;
        word-break: break-word;
        overflow-wrap: anywhere;
        font-size: 0.88rem;
        line-height: 1.35;
    }

    .udi-table-wrap tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-muted, #64748b);
        font-size: 0.78rem;
        letter-spacing: 0.01em;
        margin-bottom: 0.06rem;
    }

    .udi-table-wrap tbody td .badge {
        align-self: flex-start;
    }

    .udi-table-wrap tbody td[data-label="Action"] .btn {
        width: auto;
        align-self: flex-start;
        display: inline-flex;
        font-size: 0.78rem;
        padding: 0.3rem 0.5rem;
        line-height: 1.15;
    }

    [data-theme="dark"] .udi-table-wrap tbody tr {
        background: #111827;
        border-color: #374151;
    }

    [data-theme="dark"] .udi-table-wrap tbody td {
        color: #f3f4f6;
    }

    [data-theme="dark"] .udi-table-wrap tbody td::before {
        color: #cbd5e1;
    }

    .table-actions {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.55rem;
    }

    .table-actions .btn-group {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 0.35rem;
    }

    .table-actions .btn-group .btn {
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .dashboard-content {
        padding: 0.75rem;
    }

    .widget-header {
        padding: 0.68rem 0.75rem;
    }

    .widget-body {
        padding: 0.75rem;
    }

    .page-title {
        margin-bottom: 0.75rem;
    }

    .page-title h1 {
        font-size: 1.14rem;
    }

    .page-subtitle {
        font-size: 0.86rem;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .page-title h1 {
        font-size: 1.1rem;
    }

    .page-subtitle {
        font-size: 0.85rem;
    }

    .widget-body {
        padding: 0.75rem;
    }

    .widget-header {
        padding: 0.62rem 0.7rem;
    }

    .udi-wallet-total-card {
        padding: 0.75rem 0.8rem;
    }

    .udi-wallet-total-value {
        font-size: 1.05rem;
    }

    .udi-wallet-total-meta {
        font-size: 0.74rem;
        gap: 0.4rem;
    }

    .udi-table-wrap tbody td {
        font-size: 0.82rem;
        line-height: 1.32;
        padding: 0.38rem 0;
    }

    .udi-table-wrap tbody td::before {
        font-size: 0.74rem;
        margin-bottom: 0.04rem;
    }

    .udi-table-wrap tbody td[data-label="Action"] .btn {
        font-size: 0.72rem;
        padding: 0.26rem 0.44rem;
        line-height: 1.1;
        width: auto;
        align-self: flex-start;
    }

    .udi-form .btn {
        font-size: 0.74rem;
        padding: 0.3rem 0.5rem;
        line-height: 1.1;
    }
}
</style>

<script>
function refreshWalletTotals() {
    var endpoint = 'user-data-info.php?ajax=wallet_totals';
    fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(payload) {
        if (!payload || payload.status !== 'success' || !payload.data) return;
        var totalEl = document.getElementById('walletTotalValue');
        var countEl = document.getElementById('walletTotalCount');
        var updatedEl = document.getElementById('walletTotalUpdated');
        if (totalEl) totalEl.textContent = payload.data.formatted_total || totalEl.textContent;
        if (countEl) countEl.textContent = (payload.data.wallet_count || 0).toLocaleString() + ' wallet account(s)';
        if (updatedEl) updatedEl.textContent = 'Updated ' + (payload.data.updated_at_human || '');
    })
    .catch(function() {
        // Silent fail: current displayed totals remain.
    });
}

setInterval(refreshWalletTotals, 30000);

document.addEventListener('click', function(e){
    var deductBtn = e.target.closest('.js-deduct');
    if (!deductBtn) return;

    var userId = deductBtn.getAttribute('data-user-id') || '';
    var input = document.getElementById('target_user_id');
    if (!input) return;

    input.value = userId;
    input.focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

var deductForm = document.getElementById('deductForm');
if (deductForm) {
    deductForm.addEventListener('submit', function(e){
        if (!confirm('Apply this wallet deduction now?')) e.preventDefault();
    });
}
</script>
<?php include '../includes/admin_footer.php'; ?>
