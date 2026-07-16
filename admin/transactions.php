<?php
require_once '../config/config.php';
require_once '../includes/paystack_fees.php';

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

// Require admin role
requireRole('admin');
$current_admin = getCurrentUser();

$page_csrf_token = generateCSRF();
$report_csrf_token = $page_csrf_token;

$redirect_base = 'transactions.php';
$redirect_query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

// Fetch filters
$selected_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$selected_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$page_limit = 50;
$offset = ($page - 1) * $page_limit;
$fetch_limit = min(1000, $offset + $page_limit);
if ($fetch_limit < $page_limit) {
    $fetch_limit = $page_limit;
}

// Separate transaction categories
$transaction_category = isset($_GET['category']) ? sanitize($_GET['category']) : 'all';

function normalizeTransactionDateTimeInput($value, $isEnd = false) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = str_replace('T', ' ', $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value . ($isEnd ? ':59' : ':00');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '';
}

function findBulkCompletableOrderIds(mysqli $db, array $filters, $timeFrom = '', $timeTo = '') {
    if (function_exists('dbh_table_exists') && !dbh_table_exists('bundle_orders')) {
        return [];
    }

    $conditions = [
        "t.id IS NOT NULL",
        "LOWER(COALESCE(bo.status, '')) <> 'delivered'"
    ];
    $types = '';
    $params = [];

    $transactionCategory = strtolower((string) ($filters['category'] ?? 'all'));
    if ($transactionCategory === 'topup') {
        return [];
    }
    if ($transactionCategory === 'purchase') {
        $conditions[] = "t.transaction_type IN ('purchase', 'order_cost', 'debit')";
    }

    $selectedType = strtolower((string) ($filters['type'] ?? ''));
    if ($selectedType !== '') {
        if ($selectedType === 'credit' || $selectedType === 'topup' || $selectedType === 'commission') {
            return [];
        }
        if ($selectedType === 'debit') {
            $conditions[] = "t.transaction_type IN ('debit', 'order_cost')";
        } elseif ($selectedType === 'purchase') {
            $conditions[] = "t.transaction_type IN ('purchase', 'order_cost')";
        } else {
            $conditions[] = "t.transaction_type = ?";
            $types .= 's';
            $params[] = $selectedType;
        }
    }

    $selectedStatus = strtolower((string) ($filters['status'] ?? ''));
    if ($selectedStatus !== '') {
        $conditions[] = "(LOWER(t.status) = ? OR LOWER(COALESCE(bo.status, '')) = ?)";
        $types .= 'ss';
        $params[] = $selectedStatus;
        $params[] = $selectedStatus;
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $conditions[] = "DATE(t.created_at) >= ?";
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $conditions[] = "DATE(t.created_at) <= ?";
        $types .= 's';
        $params[] = $dateTo;
    }

    if ($timeFrom !== '') {
        $conditions[] = "t.created_at >= ?";
        $types .= 's';
        $params[] = $timeFrom;
    }

    if ($timeTo !== '') {
        $conditions[] = "t.created_at <= ?";
        $types .= 's';
        $params[] = $timeTo;
    }

    $searchTerm = trim((string) ($filters['search'] ?? ''));
    if ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        $searchParts = [
            't.description LIKE ?',
            't.reference LIKE ?',
            't.status LIKE ?',
            'bo.order_reference LIKE ?',
            'bo.beneficiary_number LIKE ?',
            'u.full_name LIKE ?',
            'u.email LIKE ?',
            'u.username LIKE ?'
        ];
        $types .= 'ssssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        if (ctype_digit($searchTerm)) {
            $searchParts[] = 't.id = ?';
            $types .= 'i';
            $params[] = (int) $searchTerm;
        }
        $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    $sql = "
        SELECT DISTINCT bo.id
        FROM bundle_orders bo
        INNER JOIN transactions t
            ON (
                bo.transaction_id = t.id
                OR (
                    bo.order_reference IS NOT NULL
                    AND bo.order_reference <> ''
                    AND bo.order_reference = t.reference
                )
            )
        LEFT JOIN users u ON u.id = t.user_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY t.created_at DESC
        LIMIT 5000
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $orderIds = [];
    while ($row = $result->fetch_assoc()) {
        $orderId = (int) ($row['id'] ?? 0);
        if ($orderId > 0) {
            $orderIds[] = $orderId;
        }
    }

    return array_values(array_unique($orderIds));
}

function findBulkFailingTopupTransactionIds(mysqli $db, array $filters) {
    $conditions = [
        "LOWER(COALESCE(t.transaction_type, '')) = 'topup'",
        "LOWER(COALESCE(t.status, '')) IN ('pending', 'processing')"
    ];
    $types = '';
    $params = [];

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $conditions[] = "DATE(t.created_at) >= ?";
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $conditions[] = "DATE(t.created_at) <= ?";
        $types .= 's';
        $params[] = $dateTo;
    }

    $searchTerm = trim((string) ($filters['search'] ?? ''));
    if ($searchTerm !== '') {
        $like = '%' . $searchTerm . '%';
        $searchParts = [
            't.description LIKE ?',
            't.reference LIKE ?',
            't.status LIKE ?',
            'u.full_name LIKE ?',
            'u.email LIKE ?',
            'u.username LIKE ?'
        ];
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
        if (ctype_digit($searchTerm)) {
            $searchParts[] = 't.id = ?';
            $types .= 'i';
            $params[] = (int) $searchTerm;
        }
        $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    $sql = "
        SELECT DISTINCT t.id
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY t.created_at DESC
        LIMIT 5000
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $transactionIds = [];
    while ($row = $result->fetch_assoc()) {
        $transactionId = (int) ($row['id'] ?? 0);
        if ($transactionId > 0) {
            $transactionIds[] = $transactionId;
        }
    }

    return array_values(array_unique($transactionIds));
}

function reconcilePendingPaystackTopups($db, $limit = 100) {
    if ($db instanceof Database) {
        $db = $db->getConnection();
    }

    if (!$db instanceof mysqli) {
        return [
            'checked' => 0,
            'credited' => 0,
            'failed' => 0,
            'still_pending' => 0,
            'skipped' => 0,
            'errors' => 1,
            'messages' => ['Database connection is not available.'],
        ];
    }

    $limit = max(1, min(200, (int) $limit));
    $summary = [
        'checked' => 0,
        'credited' => 0,
        'failed' => 0,
        'still_pending' => 0,
        'skipped' => 0,
        'errors' => 0,
        'messages' => [],
    ];

    $paystack_secret_key = trim((string) dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY));
    if ($paystack_secret_key === '') {
        $summary['messages'][] = 'Paystack secret key is not configured.';
        return $summary;
    }

    $stmt = $db->prepare("
        SELECT id, user_id, amount, reference, status
        FROM transactions
        WHERE transaction_type = 'topup'
          AND payment_method = 'paystack'
          AND status = 'pending'
          AND reference LIKE 'PAY_%'
          AND created_at <= (NOW() - INTERVAL 5 MINUTE)
        ORDER BY created_at ASC
        LIMIT ?
    ");
    if (!$stmt) {
        $summary['messages'][] = 'Could not load pending Paystack top-ups.';
        return $summary;
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($pending as $transaction) {
        $summary['checked']++;
        $reference = trim((string) ($transaction['reference'] ?? ''));
        $amount = round((float) ($transaction['amount'] ?? 0), 2);
        $user_id = (int) ($transaction['user_id'] ?? 0);

        if ($reference === '' || $amount <= 0 || $user_id <= 0) {
            $summary['skipped']++;
            continue;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $paystack_secret_key,
                'Cache-Control: no-cache',
            ],
        ]);

        $response = curl_exec($curl);
        $curl_error = curl_error($curl);
        $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curl_error !== '') {
            $summary['errors']++;
            $summary['messages'][] = "{$reference}: Paystack request failed.";
            continue;
        }

        $paystack_result = json_decode((string) $response, true);
        if (!$paystack_result || !($paystack_result['status'] ?? false) || empty($paystack_result['data'])) {
            $summary['errors']++;
            $summary['messages'][] = "{$reference}: Paystack verification failed" . ($http_code ? " (HTTP {$http_code})" : '') . '.';
            continue;
        }

        $gateway_data = $paystack_result['data'];
        $gateway_status = strtolower(trim((string) ($gateway_data['status'] ?? '')));
        $gateway_reference = trim((string) ($gateway_data['reference'] ?? ''));
        $gateway_amount = round(((float) ($gateway_data['amount'] ?? 0)) / 100, 2);

        if ($gateway_reference === '' || strcasecmp($gateway_reference, $reference) !== 0) {
            $summary['errors']++;
            $summary['messages'][] = "{$reference}: Paystack returned a mismatched reference.";
            continue;
        }

        if ($gateway_status === 'success') {
            $valid_amount = true;
            if (function_exists('validatePaystackAmount')) {
                $validation = validatePaystackAmount($gateway_amount, $amount);
                $valid_amount = !empty($validation['is_valid']);
            } elseif (abs($gateway_amount - $amount) > 0.01) {
                $valid_amount = false;
            }

            if (!$valid_amount) {
                $summary['errors']++;
                $summary['messages'][] = "{$reference}: amount mismatch. Expected " . CURRENCY . number_format($amount, 2) . ', Paystack returned ' . CURRENCY . number_format($gateway_amount, 2) . '.';
                continue;
            }

            if (function_exists('walletReferenceExists') && walletReferenceExists($user_id, $reference)) {
                $stmt = $db->prepare("UPDATE transactions SET status = 'success', updated_at = NOW() WHERE id = ? AND status = 'pending'");
                if ($stmt) {
                    $transaction_id = (int) $transaction['id'];
                    $stmt->bind_param('i', $transaction_id);
                    $stmt->execute();
                    $stmt->close();
                }
                $summary['credited']++;
                continue;
            }

            if (!updateWalletBalanceWithSMS($user_id, $amount, 'credit', $reference, 'Wallet top-up via Paystack reconciliation', 'paystack_reconciliation')) {
                $summary['errors']++;
                $summary['messages'][] = "{$reference}: wallet credit failed.";
                continue;
            }

            $paystack_reference = $gateway_reference;
            if (function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'paystack_reference')) {
                $stmt = $db->prepare("UPDATE transactions SET status = 'success', paystack_reference = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
                if ($stmt) {
                    $transaction_id = (int) $transaction['id'];
                    $stmt->bind_param('si', $paystack_reference, $transaction_id);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $db->prepare("UPDATE transactions SET status = 'success', updated_at = NOW() WHERE id = ? AND status = 'pending'");
                if ($stmt) {
                    $transaction_id = (int) $transaction['id'];
                    $stmt->bind_param('i', $transaction_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $summary['credited']++;
            continue;
        }

        if (in_array($gateway_status, ['failed', 'abandoned', 'reversed'], true)) {
            $failure_note = PHP_EOL . '[Paystack reconciliation marked failed: ' . $gateway_status . ' on ' . date('Y-m-d H:i:s') . ']';
            $stmt = $db->prepare("UPDATE transactions SET status = 'failed', description = CONCAT(COALESCE(description, ''), ?), updated_at = NOW() WHERE id = ? AND status = 'pending'");
            if ($stmt) {
                $transaction_id = (int) $transaction['id'];
                $stmt->bind_param('si', $failure_note, $transaction_id);
                $stmt->execute();
                $stmt->close();
            }
            $summary['failed']++;
            continue;
        }

        $summary['still_pending']++;
    }

    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['transaction_action'] ?? '') === 'approve_transaction') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf_token)) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    if ($transaction_id <= 0) {
        setFlashMessage('error', 'Invalid transaction selected.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    try {
        $stmt = $db->prepare("SELECT id, user_id, transaction_type, amount, status, reference, description FROM transactions WHERE id = ?");
        $stmt->bind_param('i', $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();

        if (!$transaction) {
            throw new Exception('Transaction not found.');
        }

        $status_value = strtolower($transaction['status'] ?? '');
        if (!in_array($status_value, ['pending', 'processing'])) {
            throw new Exception('Only pending transactions can be approved.');
        }

        $type_value = strtolower($transaction['transaction_type'] ?? '');
        if ($type_value !== 'topup') {
            throw new Exception('Only wallet top-up transactions can be approved from this page.');
        }

        $amount = (float)($transaction['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('Transaction amount is invalid.');
        }

        $credit_reference = !empty($transaction['reference']) ? $transaction['reference'] : ('MANUAL-' . $transaction_id);
        $admin_name = $current_admin['full_name'] ?? ($current_admin['username'] ?? 'Admin');
        $admin_id = (int)($current_admin['id'] ?? 0);
        $credit_context = 'Manual approval (Admin ID ' . ($admin_id ?: 'N/A') . ')';

        if (!updateWalletBalanceWithSMS($transaction['user_id'], $amount, 'credit', $credit_reference, $credit_context, 'manual_transaction_approval')) {
            throw new Exception('Failed to credit wallet. Please verify the wallet exists.');
        }

        $approval_note = PHP_EOL . '[Approved manually by ' . $admin_name;
        if ($admin_id) {
            $approval_note .= ' (ID ' . $admin_id . ')';
        }
        $approval_note .= ' on ' . date('Y-m-d H:i:s') . ']';

        $stmt = $db->prepare("UPDATE transactions SET status = 'success', description = CONCAT(COALESCE(description, ''), ?), updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $approval_note, $transaction_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to update transaction status.');
        }

        logActivity($admin_id ?: $transaction['user_id'], 'transaction_manual_approval', json_encode([
            'transaction_id' => $transaction_id,
            'user_id' => $transaction['user_id'],
            'amount' => $amount,
            'reference' => $credit_reference
        ]));

        setFlashMessage('success', 'Transaction #' . $transaction_id . ' approved. ' . CURRENCY . number_format($amount, 2) . ' credited to wallet.');
    } catch (Exception $e) {
        error_log('Manual transaction approval failed: ' . $e->getMessage());
        setFlashMessage('error', 'Failed to approve transaction: ' . $e->getMessage());
    }

    header('Location: ' . $redirect_base . $redirect_query);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['transaction_action'] ?? '') === 'reconcile_paystack_topups') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf_token)) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    try {
        $summary = reconcilePendingPaystackTopups($db, 100);
        $admin_id = (int) ($current_admin['id'] ?? 0);
        if ($admin_id) {
            logActivity($admin_id, 'paystack_pending_reconciliation', json_encode($summary));
        }

        $message = 'Paystack reconciliation checked ' . (int) $summary['checked'] . ' pending top-up(s). '
            . (int) $summary['credited'] . ' credited, '
            . (int) $summary['failed'] . ' marked failed, '
            . (int) $summary['still_pending'] . ' still pending, '
            . (int) $summary['errors'] . ' error(s).';

        if (!empty($summary['messages'])) {
            $message .= ' First issue: ' . $summary['messages'][0];
        }

        setFlashMessage($summary['errors'] > 0 ? 'warning' : 'success', $message);
    } catch (Throwable $e) {
        error_log('Paystack reconciliation failed: ' . $e->getMessage());
        setFlashMessage('error', 'Paystack reconciliation failed: ' . $e->getMessage());
    }

    header('Location: ' . $redirect_base . $redirect_query);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['transaction_action'] ?? ''), ['fail_transaction', 'bulk_fail_filtered_topups'], true)) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf_token)) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    $action = $_POST['transaction_action'] ?? '';
    $transaction_ids = [];

    if ($action === 'fail_transaction') {
        $single_transaction_id = isset($_POST['transaction_id']) ? (int) $_POST['transaction_id'] : 0;
        if ($single_transaction_id > 0) {
            $transaction_ids[] = $single_transaction_id;
        }
    } else {
        $transaction_ids = findBulkFailingTopupTransactionIds($db, [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'search' => $search,
        ]);
    }

    $transaction_ids = array_values(array_unique(array_filter(array_map('intval', $transaction_ids))));
    if (empty($transaction_ids)) {
        setFlashMessage('error', $action === 'bulk_fail_filtered_topups'
            ? 'No pending top-up transactions matched the current filters.'
            : 'Invalid transaction selected.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    $admin_name = $current_admin['full_name'] ?? ($current_admin['username'] ?? 'Admin');
    $admin_id = (int) ($current_admin['id'] ?? 0);
    $failure_note = PHP_EOL . '[Marked failed manually by ' . $admin_name;
    if ($admin_id) {
        $failure_note .= ' (ID ' . $admin_id . ')';
    }
    $failure_note .= ' on ' . date('Y-m-d H:i:s') . ']';

    $select_stmt = $db->prepare("SELECT id, transaction_type, status, reference, amount, user_id FROM transactions WHERE id = ? LIMIT 1");
    $update_stmt = $db->prepare("
        UPDATE transactions
        SET status = 'failed', description = CONCAT(COALESCE(description, ''), ?), updated_at = NOW()
        WHERE id = ?
          AND LOWER(COALESCE(transaction_type, '')) = 'topup'
          AND LOWER(COALESCE(status, '')) IN ('pending', 'processing')
    ");

    $updated = 0;
    $skipped = 0;
    $missing = 0;
    $updated_refs = [];

    foreach ($transaction_ids as $transaction_id) {
        $select_stmt->bind_param('i', $transaction_id);
        $select_stmt->execute();
        $transaction = $select_stmt->get_result()->fetch_assoc();

        if (!$transaction) {
            $missing++;
            continue;
        }

        $type_value = strtolower((string) ($transaction['transaction_type'] ?? ''));
        $status_value = strtolower((string) ($transaction['status'] ?? ''));
        if ($type_value !== 'topup' || !in_array($status_value, ['pending', 'processing'], true)) {
            $skipped++;
            continue;
        }

        $update_stmt->bind_param('si', $failure_note, $transaction_id);
        $update_stmt->execute();
        if ($update_stmt->affected_rows > 0) {
            $updated++;
            $updated_refs[] = $transaction['reference'] ?? ('TXN-' . $transaction_id);
        } else {
            $skipped++;
        }
    }

    if ($admin_id) {
        logActivity($admin_id, 'transaction_manual_fail', json_encode([
            'transaction_ids' => $transaction_ids,
            'references' => $updated_refs,
            'updated' => $updated,
            'skipped' => $skipped,
            'missing' => $missing,
            'filters' => [
                'date_from' => $date_from,
                'date_to' => $date_to,
                'search' => $search,
            ]
        ]));
    }

    $message_parts = [];
    if ($updated > 0) {
        $message_parts[] = $updated . ' pending top-up transaction(s) marked failed';
    }
    if ($skipped > 0) {
        $message_parts[] = $skipped . ' transaction(s) skipped';
    }
    if ($missing > 0) {
        $message_parts[] = $missing . ' transaction(s) not found';
    }
    if (empty($message_parts)) {
        $message_parts[] = 'No transactions updated.';
    }

    setFlashMessage('success', implode('. ', $message_parts) . '.');
    header('Location: ' . $redirect_base . $redirect_query);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['order_action'] ?? ''), ['complete_order', 'bulk_complete_orders', 'bulk_complete_orders_by_time'], true)) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf_token)) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    $action = $_POST['order_action'] ?? '';
    $order_ids = [];
    if ($action === 'complete_order') {
        $single_order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ($single_order_id > 0) {
            $order_ids[] = $single_order_id;
        }
    } elseif ($action === 'bulk_complete_orders_by_time') {
        $bulk_time_from = normalizeTransactionDateTimeInput($_POST['bulk_time_from'] ?? '', false);
        $bulk_time_to = normalizeTransactionDateTimeInput($_POST['bulk_time_to'] ?? '', true);

        if ($bulk_time_from === '' && $bulk_time_to === '') {
            setFlashMessage('error', 'Choose a start time, end time, or both for the bulk time action.');
            header('Location: ' . $redirect_base . $redirect_query);
            exit();
        }

        if ($bulk_time_from !== '' && $bulk_time_to !== '' && strtotime($bulk_time_from) > strtotime($bulk_time_to)) {
            setFlashMessage('error', 'The start time must be earlier than the end time.');
            header('Location: ' . $redirect_base . $redirect_query);
            exit();
        }

        $order_ids = findBulkCompletableOrderIds($db, [
            'category' => $transaction_category,
            'type' => $selected_type,
            'status' => $selected_status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'search' => $search,
        ], $bulk_time_from, $bulk_time_to);
    } else {
        $raw_ids = $_POST['order_ids'] ?? [];
        foreach ((array) $raw_ids as $raw_id) {
            $order_id = (int) $raw_id;
            if ($order_id > 0) {
                $order_ids[] = $order_id;
            }
        }
    }

    $order_ids = array_values(array_unique($order_ids));
    if (empty($order_ids)) {
        setFlashMessage('error', 'Please select at least one order to complete.');
        header('Location: ' . $redirect_base . $redirect_query);
        exit();
    }

    $admin_id = (int) ($current_admin['id'] ?? 0);
    $has_provider_status = function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'provider_status');
    $has_delivered_at = function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'delivered_at');
    $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('bundle_orders', 'updated_at');

    $update_fields = ["status = 'delivered'"];
    if ($has_provider_status) {
        $update_fields[] = "provider_status = 'manual'";
    }
    if ($has_delivered_at) {
        $update_fields[] = "delivered_at = NOW()";
    }
    if ($has_updated_at) {
        $update_fields[] = "updated_at = NOW()";
    }

    $update_sql = "UPDATE bundle_orders SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $check_stmt = $db->prepare("SELECT status FROM bundle_orders WHERE id = ? LIMIT 1");
    $update_stmt = $db->prepare($update_sql);

    $updated = 0;
    $skipped = 0;
    $missing = 0;
    $sms_sent = 0;
    $sms_failed = 0;
    $sms_skipped = 0;
    $completed_statuses = ['delivered'];

    foreach ($order_ids as $order_id) {
        $check_stmt->bind_param('i', $order_id);
        $check_stmt->execute();
        $order_row = $check_stmt->get_result()->fetch_assoc();

        if (!$order_row) {
            $missing++;
            continue;
        }

        $current_status = strtolower((string) ($order_row['status'] ?? ''));
        if (in_array($current_status, $completed_statuses, true)) {
            $skipped++;
            continue;
        }

        if (updateOrderStatus($order_id, 'delivered', 'manual')) {
            $updated++;
            
            // Increment UI stats count for manual complete logging
            $isEnabled = getSetting('agent_delivery_sms_enabled', '0');
            if ($isEnabled === '1') {
                $sms_sent++;
            } else {
                $sms_skipped++;
            }
        } else {
            $skipped++;
        }
    }

    if ($admin_id) {
        logActivity($admin_id, 'order_manual_complete', json_encode([
            'order_ids' => $order_ids,
            'updated' => $updated,
            'skipped' => $skipped,
            'missing' => $missing,
            'agent_delivery_sms_sent' => $sms_sent,
            'agent_delivery_sms_failed' => $sms_failed,
            'agent_delivery_sms_skipped' => $sms_skipped
        ]));
    }

    $message_parts = [];
    if ($updated > 0) {
        $message_parts[] = $updated . ' order(s) marked as delivered';
    }
    if ($skipped > 0) {
        $message_parts[] = $skipped . ' order(s) skipped';
    }
    if ($missing > 0) {
        $message_parts[] = $missing . ' order(s) not found';
    }
    if ($sms_sent > 0) {
        $message_parts[] = $sms_sent . ' agent delivery SMS sent';
    }
    if ($sms_failed > 0) {
        $message_parts[] = $sms_failed . ' agent delivery SMS failed';
    }

    if (empty($message_parts)) {
        $message_parts[] = 'No orders updated.';
    }

    setFlashMessage('success', implode('. ', $message_parts) . '.');
    header('Location: ' . $redirect_base . $redirect_query);
    exit();
}
$order_report_whatsapp_number = getSetting('order_report_whatsapp_number', '0249020304');
$whatsapp_digits = preg_replace('/\D+/', '', $order_report_whatsapp_number);
if (strpos($whatsapp_digits, '233') === 0) {
    $order_report_whatsapp_international = $whatsapp_digits;
} elseif (strpos($whatsapp_digits, '0') === 0) {
    $order_report_whatsapp_international = '233' . substr($whatsapp_digits, 1);
} elseif ($whatsapp_digits) {
    $order_report_whatsapp_international = '233' . ltrim($whatsapp_digits, '0');
} else {
    $order_report_whatsapp_international = '233249020304';
}

// Build unified transaction dataset including manual top-ups
$has_transactions = function_exists('dbh_table_exists') ? dbh_table_exists('transactions') : true;
$has_wallet_transactions = function_exists('dbh_table_exists') ? dbh_table_exists('wallet_transactions') : true;
$has_wallets = function_exists('dbh_table_exists') ? dbh_table_exists('wallets') : true;
$has_commissions = function_exists('dbh_table_exists') ? dbh_table_exists('commissions') : false;
$has_agent_commissions = function_exists('dbh_table_exists') ? dbh_table_exists('agent_commissions') : false;
$has_agent_profits = function_exists('dbh_table_exists') ? dbh_table_exists('agent_profits') : false;
$has_metadata = function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'metadata');
$has_payment_method = function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'payment_method');
$has_wallet_balance_before = function_exists('dbh_table_has_column') && dbh_table_has_column('wallet_transactions', 'balance_before');
$has_wallet_balance_after = function_exists('dbh_table_has_column') && dbh_table_has_column('wallet_transactions', 'balance_after');

$payment_method_expr = $has_payment_method ? 'tx.payment_method' : "'unknown'";

// Disable JSON extraction to avoid heavy parsing that can crash MySQL on large metadata payloads.
$disable_metadata_extract = true;
if ($has_metadata && !$disable_metadata_extract) {
    $json_prefix = "CASE WHEN JSON_VALID(t.metadata) AND CHAR_LENGTH(t.metadata) <= 8000 THEN ";
    $json_suffix = " ELSE NULL END";
    $metadata_beneficiary = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.beneficiary_number')){$json_suffix}";
    $metadata_msisdn = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.msisdn')){$json_suffix}";
    $metadata_phone = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.phone')){$json_suffix}";
    $metadata_phone_number = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.phone_number')){$json_suffix}";
    $metadata_data_size = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.data_size')){$json_suffix}";
    $metadata_package_name = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.package_name')){$json_suffix}";
    $metadata_network_name = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.network_name')){$json_suffix}";
    $metadata_network = "{$json_prefix}JSON_UNQUOTE(JSON_EXTRACT(t.metadata, '$.network')){$json_suffix}";
} else {
    $metadata_beneficiary = "NULL";
    $metadata_msisdn = "NULL";
    $metadata_phone = "NULL";
    $metadata_phone_number = "NULL";
    $metadata_data_size = "NULL";
    $metadata_package_name = "NULL";
    $metadata_network_name = "NULL";
    $metadata_network = "NULL";
}

// Build unified transaction dataset including manual top-ups
$include_transactions = $has_transactions;
$include_wallet_transactions = $has_wallet_transactions && $has_wallets;

$conditions_tx = ['1=1'];
$conditions_wt = ['1=1'];
$types_tx = '';
$params_tx = [];
$types_wt = '';
$params_wt = [];
$normalized_status = $selected_status !== '' ? strtolower($selected_status) : '';
$matching_order_transaction_ids = [];
$matching_order_references = [];
$matching_search_order_transaction_ids = [];
$matching_search_order_references = [];

if ($normalized_status !== '' && (!function_exists('dbh_table_exists') || dbh_table_exists('bundle_orders'))) {
    $status_order_stmt = $db->prepare("SELECT transaction_id, order_reference FROM bundle_orders WHERE LOWER(status) = ? LIMIT 5000");
    if ($status_order_stmt) {
        $status_order_stmt->bind_param('s', $normalized_status);
        $status_order_stmt->execute();
        $status_order_rs = $status_order_stmt->get_result();
        while ($status_order_row = $status_order_rs->fetch_assoc()) {
            $status_order_transaction_id = (int) ($status_order_row['transaction_id'] ?? 0);
            $status_order_reference = trim((string) ($status_order_row['order_reference'] ?? ''));
            if ($status_order_transaction_id > 0) {
                $matching_order_transaction_ids[] = $status_order_transaction_id;
            }
            if ($status_order_reference !== '') {
                $matching_order_references[] = $status_order_reference;
            }
        }
        $matching_order_transaction_ids = array_values(array_unique($matching_order_transaction_ids));
        $matching_order_references = array_values(array_unique($matching_order_references));
    }
}

// Transaction category filters
if ($transaction_category === 'topup') {
    $conditions_tx[] = "t.transaction_type = 'topup'";
} elseif ($transaction_category === 'purchase') {
    $conditions_tx[] = "t.transaction_type IN ('purchase', 'order_cost', 'debit')";
    $include_wallet_transactions = false;
}

// Type filter with special handling
if ($selected_type !== '') {
    if ($selected_type === 'credit') {
        $conditions_tx[] = "(t.transaction_type IN ('credit', 'topup'))";
    } elseif ($selected_type === 'debit') {
        $conditions_tx[] = "(t.transaction_type IN ('debit', 'order_cost'))";
        $include_wallet_transactions = false;
    } elseif ($selected_type === 'purchase') {
        $conditions_tx[] = "t.transaction_type IN ('purchase', 'order_cost')";
        $include_wallet_transactions = false;
    } elseif ($selected_type === 'topup') {
        $conditions_tx[] = "t.transaction_type = 'topup'";
    } else {
        $conditions_tx[] = "t.transaction_type = ?";
        $types_tx .= 's';
        $params_tx[] = $selected_type;
        $include_wallet_transactions = false;
    }
}

if ($selected_status !== '') {
    $status_conditions = ["LOWER(t.status) = ?"];
    $types_tx .= 's';
    $params_tx[] = $normalized_status;

    if (!empty($matching_order_transaction_ids)) {
        $placeholders = implode(',', array_fill(0, count($matching_order_transaction_ids), '?'));
        $status_conditions[] = "t.id IN ($placeholders)";
        $types_tx .= str_repeat('i', count($matching_order_transaction_ids));
        $params_tx = array_merge($params_tx, $matching_order_transaction_ids);
    }

    if (!empty($matching_order_references)) {
        $placeholders = implode(',', array_fill(0, count($matching_order_references), '?'));
        $status_conditions[] = "t.reference IN ($placeholders)";
        $types_tx .= str_repeat('s', count($matching_order_references));
        $params_tx = array_merge($params_tx, $matching_order_references);
    }

    $conditions_tx[] = '(' . implode(' OR ', $status_conditions) . ')';

    if (strtolower($selected_status) !== 'completed') {
        $include_wallet_transactions = false;
    }
}

if ($date_from !== '') {
    $conditions_tx[] = "DATE(t.created_at) = ?";
    $types_tx .= 's';
    $params_tx[] = $date_from;
    $conditions_wt[] = "DATE(wt.created_at) = ?";
    $types_wt .= 's';
    $params_wt[] = $date_from;
}

if ($date_to !== '') {
    $conditions_tx[] = "DATE(t.created_at) <= ?";
    $types_tx .= 's';
    $params_tx[] = $date_to;
    $conditions_wt[] = "DATE(wt.created_at) <= ?";
    $types_wt .= 's';
    $params_wt[] = $date_to;
}

if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $search_user_ids = [];
    if (!function_exists('dbh_table_exists') || dbh_table_exists('bundle_orders')) {
        $order_search_sql = "
            SELECT transaction_id, order_reference
            FROM bundle_orders
            WHERE beneficiary_number LIKE ? OR order_reference LIKE ?
            LIMIT 5000
        ";
        $order_search_stmt = $db->prepare($order_search_sql);
        if ($order_search_stmt) {
            $order_search_stmt->bind_param('ss', $searchTerm, $searchTerm);
            $order_search_stmt->execute();
            $order_search_rs = $order_search_stmt->get_result();
            while ($order_search_row = $order_search_rs->fetch_assoc()) {
                $order_search_transaction_id = (int) ($order_search_row['transaction_id'] ?? 0);
                $order_search_reference = trim((string) ($order_search_row['order_reference'] ?? ''));
                if ($order_search_transaction_id > 0) {
                    $matching_search_order_transaction_ids[] = $order_search_transaction_id;
                }
                if ($order_search_reference !== '') {
                    $matching_search_order_references[] = $order_search_reference;
                }
            }
            $matching_search_order_transaction_ids = array_values(array_unique($matching_search_order_transaction_ids));
            $matching_search_order_references = array_values(array_unique($matching_search_order_references));
        }
    }

    $user_search_sql = "SELECT id FROM users WHERE full_name LIKE ? OR email LIKE ? OR username LIKE ? LIMIT 200";
    $user_stmt = $db->prepare($user_search_sql);
    if ($user_stmt) {
        $user_stmt->bind_param('sss', $searchTerm, $searchTerm, $searchTerm);
        $user_stmt->execute();
        $user_rs = $user_stmt->get_result();
        while ($user_row = $user_rs->fetch_assoc()) {
            $search_user_ids[] = (int) $user_row['id'];
        }
    }

    $tx_search_parts = ["t.description LIKE ?", "t.reference LIKE ?", "t.status LIKE ?"];
    $types_tx .= 'sss';
    array_push($params_tx, $searchTerm, $searchTerm, $searchTerm);
    if (ctype_digit($search)) {
        $tx_search_parts[] = "t.id = ?";
        $types_tx .= 'i';
        $params_tx[] = (int) $search;
    }
    if (!empty($matching_search_order_transaction_ids)) {
        $placeholders = implode(',', array_fill(0, count($matching_search_order_transaction_ids), '?'));
        $tx_search_parts[] = "t.id IN ($placeholders)";
        $types_tx .= str_repeat('i', count($matching_search_order_transaction_ids));
        $params_tx = array_merge($params_tx, $matching_search_order_transaction_ids);
    }
    if (!empty($matching_search_order_references)) {
        $placeholders = implode(',', array_fill(0, count($matching_search_order_references), '?'));
        $tx_search_parts[] = "t.reference IN ($placeholders)";
        $types_tx .= str_repeat('s', count($matching_search_order_references));
        $params_tx = array_merge($params_tx, $matching_search_order_references);
    }
    if (!empty($search_user_ids)) {
        $placeholders = implode(',', array_fill(0, count($search_user_ids), '?'));
        $tx_search_parts[] = "t.user_id IN ($placeholders)";
        $types_tx .= str_repeat('i', count($search_user_ids));
        $params_tx = array_merge($params_tx, $search_user_ids);
    }
    $conditions_tx[] = '(' . implode(' OR ', $tx_search_parts) . ')';

    $wt_search_parts = ["wt.description LIKE ?", "wt.reference LIKE ?", "'completed' LIKE ?"];
    $types_wt .= 'sss';
    array_push($params_wt, $searchTerm, $searchTerm, $searchTerm);
    if (ctype_digit($search)) {
        $wt_search_parts[] = "wt.id = ?";
        $types_wt .= 'i';
        $params_wt[] = (int) $search;
    }
    if (!empty($search_user_ids)) {
        $placeholders = implode(',', array_fill(0, count($search_user_ids), '?'));
        $wt_search_parts[] = "w.user_id IN ($placeholders)";
        $types_wt .= str_repeat('i', count($search_user_ids));
        $params_wt = array_merge($params_wt, $search_user_ids);
    }
    $conditions_wt[] = '(' . implode(' OR ', $wt_search_parts) . ')';
}

$transactions = [];

if ($include_transactions) {
    $payment_select = $has_payment_method ? 't.payment_method' : 'NULL AS payment_method';
    $transactions_subquery = "
        SELECT
            t.id,
            t.user_id,
            t.transaction_type,
            t.status,
            t.amount,
            t.description,
            t.created_at,
            t.reference,
            {$payment_select},
            t.balance_before,
            t.balance_after,
            t.metadata
        FROM transactions t
        WHERE " . implode(' AND ', $conditions_tx) . "
        ORDER BY t.created_at DESC
        LIMIT {$fetch_limit}
    ";

    $transactions_sql = "
        SELECT 
            tx.id,
            tx.user_id,
            tx.transaction_type,
            tx.status,
            tx.amount,
            LEFT(tx.description, 500) AS description,
            tx.created_at,
            tx.reference,
            {$payment_method_expr} AS payment_method,
            NULL AS order_id,
            NULL AS order_status,
            NULL AS package_name,
            NULL AS network_name,
            NULL AS beneficiary_number,
            NULL AS data_size,
            {$metadata_beneficiary} AS metadata_beneficiary,
            {$metadata_msisdn} AS metadata_msisdn,
            {$metadata_phone} AS metadata_phone,
            {$metadata_phone_number} AS metadata_phone_number,
            {$metadata_data_size} AS metadata_data_size,
            {$metadata_package_name} AS metadata_package_name,
            {$metadata_network_name} AS metadata_network_name,
            {$metadata_network} AS metadata_network,
            tx.balance_before,
            tx.balance_after,
            'system' AS origin,
            NULL AS full_name,
            NULL AS email,
            NULL AS username,
            NULL AS role,
            tx.metadata
        FROM (
            {$transactions_subquery}
        ) AS tx
        ORDER BY tx.created_at DESC
    ";
    if (!empty($params_tx)) {
        $stmt = $db->prepare($transactions_sql);
        if ($stmt) {
            $stmt->bind_param($types_tx, ...$params_tx);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($row = $rs->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
    } else {
        $rs = $db->query($transactions_sql);
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
    }
}

if ($include_wallet_transactions) {
    $wallet_balance_before_expr = $has_wallet_balance_before ? 'wt.balance_before' : 'NULL';
    $wallet_balance_after_expr = $has_wallet_balance_after ? 'wt.balance_after' : 'NULL';
    $wallet_sql = "
        SELECT
            wt.id,
            w.user_id,
            'topup' AS transaction_type,
            'completed' AS status,
            wt.amount,
            LEFT(wt.description, 500) AS description,
            wt.created_at,
            wt.reference,
            'manual' AS payment_method,
            NULL AS order_id,
            NULL AS order_status,
            NULL AS package_name,
            NULL AS network_name,
            NULL AS beneficiary_number,
            NULL AS data_size,
            NULL AS metadata_beneficiary,
            NULL AS metadata_msisdn,
            NULL AS metadata_phone,
            NULL AS metadata_phone_number,
            NULL AS metadata_data_size,
            NULL AS metadata_package_name,
            NULL AS metadata_network_name,
            NULL AS metadata_network,
            {$wallet_balance_before_expr} AS balance_before,
            {$wallet_balance_after_expr} AS balance_after,
            'manual' AS origin,
            u.full_name,
            u.email,
            u.username,
            u.role
        FROM wallet_transactions wt
        JOIN wallets w ON wt.wallet_id = w.id
        LEFT JOIN users u ON w.user_id = u.id
        WHERE wt.transaction_type = 'credit'
            AND wt.reference LIKE 'TOPUP_%'
            AND " . implode(' AND ', $conditions_wt) . "
        ORDER BY wt.created_at DESC
        LIMIT {$fetch_limit}
    ";
    if (!empty($params_wt)) {
        $stmt = $db->prepare($wallet_sql);
        if ($stmt) {
            $stmt->bind_param($types_wt, ...$params_wt);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($row = $rs->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
    } else {
        $rs = $db->query($wallet_sql);
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
    }
}

if (count($transactions) > 1) {
    usort($transactions, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
}
$has_prev = $page > 1;
$has_next = count($transactions) > ($offset + $page_limit);
$transactions = array_slice($transactions, $offset, $page_limit);

$base_query_params = $_GET;
unset($base_query_params['page']);
$base_query = http_build_query($base_query_params);
$page_link = function ($target_page) use ($base_query) {
    $query = $base_query;
    if ($query !== '') {
        $query .= '&';
    }
    return '?' . $query . 'page=' . (int) $target_page;
};

// Hydrate user info for current page
$user_ids = [];
foreach ($transactions as $txn) {
    if (!empty($txn['user_id'])) {
        $user_ids[] = (int) $txn['user_id'];
    }
}
$user_ids = array_values(array_unique($user_ids));
$user_map = [];
if (!empty($user_ids)) {
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $user_sql = "SELECT id, full_name, email, username, role FROM users WHERE id IN ($placeholders)";
    $user_stmt = $db->prepare($user_sql);
    if ($user_stmt) {
        $user_stmt->bind_param(str_repeat('i', count($user_ids)), ...$user_ids);
        $user_stmt->execute();
        $user_rs = $user_stmt->get_result();
        while ($user_row = $user_rs->fetch_assoc()) {
            $user_map[(int) $user_row['id']] = $user_row;
        }
    }
}
foreach ($transactions as &$txn) {
    $uid = (int) ($txn['user_id'] ?? 0);
    if ($uid && isset($user_map[$uid])) {
        $txn['full_name'] = $user_map[$uid]['full_name'];
        $txn['email'] = $user_map[$uid]['email'];
        $txn['username'] = $user_map[$uid]['username'];
        $txn['role'] = $user_map[$uid]['role'];
    } elseif ($uid === 0 && !empty($txn['metadata'])) {
        $meta = json_decode($txn['metadata'], true);
        if (is_array($meta)) {
            $guest_email = $meta['email'] ?? ($meta['buyer_email'] ?? '');
            $guest_name = $meta['buyer_name'] ?? 'Guest Customer';
            
            if ($guest_email !== '') {
                $txn['email'] = $guest_email;
                $txn['full_name'] = $guest_name;
                $txn['username'] = 'guest';
                $txn['role'] = 'guest';
            }
        }
    }
}
unset($txn);

// Hydrate order info for system transactions only (current page)
$tx_ids = [];
$tx_refs = [];
foreach ($transactions as $txn) {
    if (($txn['origin'] ?? '') !== 'system') {
        continue;
    }
    if (!empty($txn['id'])) {
        $tx_ids[] = (int) $txn['id'];
    }
    if (!empty($txn['reference'])) {
        $tx_refs[] = (string) $txn['reference'];
    }
}
$tx_ids = array_values(array_unique($tx_ids));
$tx_refs = array_values(array_unique($tx_refs));
$order_by_tx_id = [];
$order_by_ref = [];
if (!empty($tx_ids) || !empty($tx_refs)) {
    $clauses = [];
    $params = [];
    $types = '';
    if (!empty($tx_ids)) {
        $placeholders = implode(',', array_fill(0, count($tx_ids), '?'));
        $clauses[] = "bo.transaction_id IN ($placeholders)";
        $types .= str_repeat('i', count($tx_ids));
        $params = array_merge($params, $tx_ids);
    }
    if (!empty($tx_refs)) {
        $placeholders = implode(',', array_fill(0, count($tx_refs), '?'));
        $clauses[] = "bo.order_reference IN ($placeholders)";
        $types .= str_repeat('s', count($tx_refs));
        $params = array_merge($params, $tx_refs);
    }
    $order_sql = "
        SELECT
            bo.transaction_id,
            bo.order_reference,
            bo.id,
            bo.status,
            bo.provider_status,
            bo.agent_cost,
            bo.beneficiary_number,
            dp.name AS package_name,
            dp.data_size,
            COALESCE(n.name, 'Unknown') AS network_name
        FROM bundle_orders bo
        LEFT JOIN data_packages dp ON dp.id = bo.package_id
        LEFT JOIN networks n ON n.id = dp.network_id
        WHERE " . implode(' OR ', $clauses);
    $order_stmt = $db->prepare($order_sql);
    if ($order_stmt) {
        $order_stmt->bind_param($types, ...$params);
        $order_stmt->execute();
        $order_rs = $order_stmt->get_result();
        while ($order_row = $order_rs->fetch_assoc()) {
            if (!empty($order_row['transaction_id'])) {
                $order_by_tx_id[(int) $order_row['transaction_id']] = $order_row;
            }
            if (!empty($order_row['order_reference'])) {
                $order_by_ref[(string) $order_row['order_reference']] = $order_row;
            }
        }
    }
}
foreach ($transactions as &$txn) {
    if (($txn['origin'] ?? '') !== 'system') {
        continue;
    }
    $order_row = null;
    $tx_id = (int) ($txn['id'] ?? 0);
    if ($tx_id && isset($order_by_tx_id[$tx_id])) {
        $order_row = $order_by_tx_id[$tx_id];
    } elseif (!empty($txn['reference']) && isset($order_by_ref[$txn['reference']])) {
        $order_row = $order_by_ref[$txn['reference']];
    }

    if ($order_row) {
        $txn['order_id'] = $order_row['id'];
        $txn['order_status'] = $order_row['status'];
        $txn['provider_status'] = $order_row['provider_status'];
        $txn['package_name'] = $order_row['package_name'];
        $txn['network_name'] = $order_row['network_name'];
        $txn['beneficiary_number'] = $order_row['beneficiary_number'];
        $txn['data_size'] = $order_row['data_size'];
        $txn['agent_cost'] = $order_row['agent_cost'];
    }
}
unset($txn);

foreach ($transactions as &$txn) {
    $msisdn = '';
    $msisdnSources = [
        $txn['beneficiary_number'] ?? null,
        $txn['metadata_beneficiary'] ?? null,
        $txn['metadata_msisdn'] ?? null,
        $txn['metadata_phone'] ?? null,
        $txn['metadata_phone_number'] ?? null,
    ];
    foreach ($msisdnSources as $candidate) {
        if ($candidate === null) {
            continue;
        }
        $candidate = trim((string)$candidate);
        if ($candidate === '' || strtoupper($candidate) === 'N/A') {
            continue;
        }
        $msisdn = $candidate;
        break;
    }
    if ($msisdn === '' && !empty($txn['description'])) {
        if (preg_match('/(233\\d{9}|0\\d{9})/', $txn['description'], $match)) {
            $msisdn = $match[0];
        }
    }
    if ($msisdn !== '' && strlen($msisdn) === 12 && strpos($msisdn, '233') === 0) {
        $msisdn = '0' . substr($msisdn, 3);
    }
    $txn['derived_msisdn'] = $msisdn !== '' ? $msisdn : null;

    $volume = '';
    $volumeSources = [
        $txn['data_size'] ?? null,
        $txn['metadata_data_size'] ?? null,
        $txn['metadata_package_name'] ?? null,
        $txn['package_name'] ?? null,
    ];
    foreach ($volumeSources as $candidate) {
        if ($candidate === null) {
            continue;
        }
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        $volume = $candidate;
        break;
    }
    if ($volume === '' && !empty($txn['description'])) {
        if (preg_match('/(\\d+(?:\\.\\d+)?)\\s*(GB|MB|TB)/i', $txn['description'], $match)) {
            $volume = trim($match[1] . ' ' . strtoupper($match[2]));
        }
    }
    $txn['derived_volume'] = $volume !== '' ? $volume : null;

    $network = '';
    $networkSources = [
        $txn['network_name'] ?? null,
        $txn['metadata_network_name'] ?? null,
        $txn['metadata_network'] ?? null,
    ];
    foreach ($networkSources as $candidate) {
        if ($candidate === null) {
            continue;
        }
        $candidate = trim((string)$candidate);
        if ($candidate === '' || strcasecmp($candidate, 'unknown') === 0 || strtoupper($candidate) === 'N/A') {
            continue;
        }
        $network = $candidate;
        break;
    }
    if ($network === '') {
        $packageNames = [
            $txn['package_name'] ?? '',
            $txn['metadata_package_name'] ?? '',
        ];
        $knownNetworks = ['MTN', 'AT', 'AIRTELTIGO', 'AIRTEL TIGO', 'VODAFONE', 'GLO', 'TELECEL'];
        foreach ($packageNames as $pkgName) {
            if (!$pkgName) {
                continue;
            }
            foreach ($knownNetworks as $guess) {
                if (stripos($pkgName, $guess) !== false) {
                    $network = $guess === 'AIRTELTIGO' ? 'AirtelTigo' : ucwords(strtolower($guess));
                    break 2;
                }
            }
        }
    }
    if ($network === '' && !empty($txn['description'])) {
        $networkGuesses = ['MTN', 'AT', 'TELECEL', 'VODAFONE', 'GLO', 'AIRTEL TIGO', 'AIRTELTIGO'];
        foreach ($networkGuesses as $guess) {
            if (stripos($txn['description'], $guess) !== false) {
                $network = $guess === 'AIRTEL TIGO' || $guess === 'AIRTELTIGO' ? 'AirtelTigo' : ucfirst(strtolower($guess));
                break;
            }
        }
    }
    $txn['derived_network'] = $network !== '' ? $network : null;
}
unset($txn);

// Today's stats including manual top-ups
$stats_union = "
    SELECT t.transaction_type, t.status, t.amount, t.created_at
    FROM transactions t
";
if ($has_wallet_transactions && $has_wallets) {
    $stats_union .= "
    UNION ALL
    SELECT 'topup' AS transaction_type, 'completed' AS status, wt.amount, wt.created_at
    FROM wallet_transactions wt
    JOIN wallets w ON wt.wallet_id = w.id
    WHERE wt.transaction_type = 'credit' AND wt.reference LIKE 'TOPUP_%'
    ";
}

$stats_query = "
    SELECT 
        COUNT(*) AS total_transactions,
        SUM(CASE WHEN transaction_type IN ('credit', 'topup') THEN amount ELSE 0 END) AS total_credits,
        SUM(CASE WHEN transaction_type IN ('debit', 'purchase', 'order_cost') THEN amount ELSE 0 END) AS total_debits,
        SUM(CASE WHEN transaction_type = 'commission' THEN amount ELSE 0 END) AS transaction_commissions,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
    FROM (
        $stats_union
    ) AS stats_base
    WHERE DATE(stats_base.created_at) = CURDATE()
";

$stats_rs = $db->query($stats_query);
$stats = $stats_rs ? $stats_rs->fetch_assoc() : [
    'total_transactions' => 0,
    'total_credits' => 0,
    'total_debits' => 0,
    'total_commissions' => 0,
    'pending_count' => 0
];

$commission_today_sources = [];
if ($has_transactions) {
    $has_transaction_commission_earned = function_exists('dbh_table_has_column') && dbh_table_has_column('transactions', 'commission_earned');
    if ($has_transaction_commission_earned) {
        $commission_today_sources[] = "
            SELECT COALESCE(SUM(commission_earned), 0) AS amount
            FROM transactions
            WHERE DATE(created_at) = CURDATE()
              AND commission_earned > 0
        ";
    }
    $commission_today_sources[] = "
        SELECT COALESCE(SUM(amount), 0) AS amount
        FROM transactions
        WHERE DATE(created_at) = CURDATE()
          AND transaction_type = 'commission'
          AND status IN ('success', 'pending')
    ";
}
if ($has_commissions) {
    $commission_today_sources[] = "
        SELECT COALESCE(SUM(amount), 0) AS amount
        FROM commissions
        WHERE DATE(created_at) = CURDATE()
          AND status <> 'cancelled'
    ";
}
if ($has_agent_commissions) {
    $agent_commission_date_column = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_commissions', 'earned_at')
        ? 'earned_at'
        : 'created_at';
    $commission_today_sources[] = "
        SELECT COALESCE(SUM(amount), 0) AS amount
        FROM agent_commissions
        WHERE DATE({$agent_commission_date_column}) = CURDATE()
          AND status <> 'cancelled'
    ";
}
if ($has_agent_profits) {
    $has_agent_profit_status = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_profits', 'status');
    $agent_profit_status_filter = $has_agent_profit_status ? "AND status <> 'cancelled'" : '';
    $commission_today_sources[] = "
        SELECT COALESCE(SUM(profit_amount), 0) AS amount
        FROM agent_profits
        WHERE DATE(created_at) = CURDATE()
          AND profit_amount > 0
          {$agent_profit_status_filter}
    ";
}
if (!empty($commission_today_sources)) {
    $commission_today_query = "
        SELECT COALESCE(SUM(amount), 0) AS total_commissions
        FROM (
            " . implode("\nUNION ALL\n", $commission_today_sources) . "
        ) AS commission_today_base
    ";
    $commission_today_rs = $db->query($commission_today_query);
    if ($commission_today_rs && ($commission_today_row = $commission_today_rs->fetch_assoc())) {
        $stats['total_commissions'] = (float) ($commission_today_row['total_commissions'] ?? 0);
    }
}



$can_bulk_fail_filtered_topups = $transaction_category === 'topup'
    && $selected_type === 'topup'
    && in_array($selected_status, ['pending', 'processing'], true);

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Transactions - <?php echo SITE_NAME; ?></title>
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
                    <div class="breadcrumb-item"><i class="fas fa-history"></i></div>
                    <div class="breadcrumb-item">Transaction</div>
                    <div class="breadcrumb-item active">All Transactions</div>
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
                <h1>All Transactions</h1>
                <p class="page-subtitle">Monitor all system transactions and financial activities.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Today's Stats -->
            <div class="stats-grid" style="margin-bottom: 2rem;">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                        <div class="stat-label">Today's Transactions</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-arrow-up text-success"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($stats['total_credits'] ?? 0, 2); ?></div>
                        <div class="stat-label">Credits Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-arrow-down text-danger"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($stats['total_debits'] ?? 0, 2); ?></div>
                        <div class="stat-label">Debits Today</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-percentage text-warning"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo CURRENCY . number_format($stats['total_commissions'] ?? 0, 2); ?></div>
                        <div class="stat-label">Commissions Today</div>
                    </div>
                </div>
            </div>



            <!-- Transactions List -->
            <div class="widget">
                <div class="widget-header stacked-header">
                    <div class="widget-header-main">
                        <h3 class="widget-title">Transactions (<?php echo count($transactions); ?>)</h3>
                    </div>
                    <form method="get" class="transaction-filter-form">
                        <select name="category" class="form-control" onchange="this.form.submit()">
                            <option value="all" <?php echo $transaction_category==='all'?'selected':''; ?>>All Transactions</option>
                            <option value="topup" <?php echo $transaction_category==='topup'?'selected':''; ?>>Wallet Top-ups</option>
                            <option value="purchase" <?php echo $transaction_category==='purchase'?'selected':''; ?>>Data Bundle Purchases</option>
                        </select>
                        <select name="type" class="form-control" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <option value="topup" <?php echo $selected_type==='topup'?'selected':''; ?>>Top-up</option>
                            <option value="credit" <?php echo $selected_type==='credit'?'selected':''; ?>>Credit</option>
                            <option value="purchase" <?php echo $selected_type==='purchase'?'selected':''; ?>>Purchase</option>
                            <option value="order_cost" <?php echo $selected_type==='order_cost'?'selected':''; ?>>Order Cost</option>
                            <option value="commission" <?php echo $selected_type==='commission'?'selected':''; ?>>Commission</option>
                            <option value="debit" <?php echo $selected_type==='debit'?'selected':''; ?>>Debit</option>
                        </select>
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="completed" <?php echo $selected_status==='completed'?'selected':''; ?>>Completed</option>
                            <option value="success" <?php echo $selected_status==='success'?'selected':''; ?>>Success</option>
                            <option value="pending" <?php echo $selected_status==='pending'?'selected':''; ?>>Pending</option>
                            <option value="processing" <?php echo $selected_status==='processing'?'selected':''; ?>>Processing</option>
                            <option value="failed" <?php echo $selected_status==='failed'?'selected':''; ?>>Failed</option>
                        </select>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search user, reference, phone number, transaction ID, note, or status..." class="form-control search-input">
                        <button type="submit" class="btn btn-primary">Filter</button>
                    </form>
                </div>
                <div class="widget-body">
                    <div class="bulk-order-actions">
                        <?php if ($transaction_category !== 'topup'): ?>
                            <form id="bulk-order-form" class="bulk-order-form" method="post" onsubmit="return confirmBulkOrderSubmit(event);">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                <button type="submit" name="order_action" value="bulk_complete_orders" class="btn btn-sm btn-success">
                                    <i class="fas fa-check-circle"></i> Mark Selected Delivered
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearOrderSelection()">
                                    Clear Selection
                                </button>
                                <span class="form-text text-muted bulk-order-summary" id="bulkOrderSummary">Select orders below to complete them in bulk.</span>
                            </form>
                        <?php endif; ?>
                        <?php if ($can_bulk_fail_filtered_topups): ?>
                            <form method="post" class="bulk-order-form" onsubmit="return confirm('Mark all pending top-up transactions matching the current filters as failed?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                <input type="hidden" name="transaction_action" value="bulk_fail_filtered_topups">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-times-circle"></i> Mark Filtered Pending Top-Ups Failed
                                </button>
                                <span class="form-text text-muted bulk-order-summary">Uses the current date and search filters to mark matching pending top-ups as failed.</span>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive transactions-table-wrapper transactions-table-main-wrapper">
                        <table class="table transactions-table transactions-table-main">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="selectAllOrders" onclick="toggleOrderSelection(this)">
                                    </th>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>MSISDN</th>
                                    <th>Volume</th>
                                    <th>Network</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Wallet Balance</th>
                                    <th>Status</th>
                                    <th>Order Status</th>
                                    <th>Date/Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="13" class="text-center text-muted">No transactions found</td></tr>
                            <?php else: ?>
                                        <?php foreach ($transactions as $txn): ?>
                                    <?php
                                        $display_name = !empty($txn['full_name']) ? $txn['full_name'] : (!empty($txn['username']) ? $txn['username'] : 'Unknown User');
                                        $transaction_label = $txn['transaction_type'] ? ucwords(str_replace('_', ' ', $txn['transaction_type'])) : 'N/A';
                                        $status_value = strtolower($txn['status'] ?? '');
                                        $type_value = strtolower($txn['transaction_type'] ?? '');
                                        $order_status_value = strtolower($txn['order_status'] ?? '');
                                        $is_credit = in_array($type_value, ['credit', 'topup', 'commission', 'refund']);
                                        $display_amount = (isset($txn['agent_cost']) && (float)$txn['agent_cost'] > 0) ? (float)$txn['agent_cost'] : (float)($txn['amount'] ?? 0);
                                        $amount_formatted = CURRENCY . number_format($display_amount, 2);
                                        $amount_display = ($is_credit ? '+' : '-') . $amount_formatted;
                                        $amount_class = $is_credit ? 'credit' : 'debit';
                                        if (in_array($status_value, ['completed', 'success'])) {
                                            $status_class = 'success';
                                        } elseif (in_array($status_value, ['pending', 'processing'])) {
                                            $status_class = 'warning';
                                        } else {
                                            $status_class = 'danger';
                                        }
                                        if (in_array($type_value, ['topup', 'credit', 'commission', 'refund'])) {
                                            $type_class = 'success';
                                        } elseif (in_array($type_value, ['purchase', 'order_cost', 'debit'])) {
                                            $type_class = 'danger';
                                        } else {
                                            $type_class = 'warning';
                                        }
                                        if ($order_status_value === '') {
                                            $order_status_class = 'info';
                                        } elseif (in_array($order_status_value, ['delivered', 'success', 'completed'])) {
                                            $order_status_class = 'success';
                                        } elseif (in_array($order_status_value, ['pending', 'processing'])) {
                                            $order_status_class = 'warning';
                                        } else {
                                            $order_status_class = 'danger';
                                        }
                                        $status_label = $status_value ? ucwords(str_replace('_', ' ', $status_value)) : 'N/A';
                                        $order_status_label = $order_status_value ? ucwords(str_replace('_', ' ', $order_status_value)) : 'N/A';
                                        $type_label = $transaction_label;
                                        if ($type_value === 'topup' && stripos($txn['description'] ?? '', 'profit withdrawal') !== false) {
                                            $type_label = 'Profit to Wallet';
                                            $type_class = 'warning text-dark';
                                        }
                                        $created_at_label = !empty($txn['created_at']) ? date('M j, Y g:i A', strtotime($txn['created_at'])) : 'N/A';
                                        $origin_badge = ($txn['origin'] ?? '') === 'manual' ? '<span class="transaction-origin badge badge-info">Manual</span>' : '';
                                        $reference_label = !empty($txn['reference']) ? htmlspecialchars($txn['reference']) : 'N/A';
                                        $canApprove = in_array($status_value, ['pending', 'processing']) && $type_value === 'topup' && (($txn['origin'] ?? '') !== 'manual');
                                        $canFailTopup = in_array($status_value, ['pending', 'processing']) && $type_value === 'topup';
                                        $canCompleteOrder = !empty($txn['order_id']) && $order_status_value !== '' && $order_status_value !== 'delivered';
                                        $reportPayload = [
                                            'transaction_id' => (int) ($txn['id'] ?? 0),
                                            'order_id' => (int) ($txn['order_id'] ?? 0),
                                            'reference' => $txn['reference'] ?? '',
                                            'msisdn' => $txn['derived_msisdn'] ?? '',
                                            'volume' => $txn['derived_volume'] ?? '',
                                            'network' => $txn['derived_network'] ?? '',
                                            'amount' => (float) ($txn['amount'] ?? 0),
                                            'amount_formatted' => $amount_formatted,
                                            'status' => $status_label,
                                            'user' => $display_name,
                                            'email' => $txn['email'] ?? '',
                                            'package' => $txn['package_name'] ?? '',
                                            'created_at' => $txn['created_at'] ?? '',
                                        ];
                                        $reportPayloadJson = htmlspecialchars(json_encode($reportPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td data-label="Select">
                                            <?php if ($canCompleteOrder): ?>
                                                <input type="checkbox" class="order-select" form="bulk-order-form" name="order_ids[]" value="<?php echo (int) $txn['order_id']; ?>" data-created-at="<?php echo htmlspecialchars((string) ($txn['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php else: ?>
                                                &mdash;
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="ID"><?php echo $txn['id']; ?></td>
                                        <td data-label="User">
                                            <div class="transaction-summary">
                                                <span class="transaction-name"><?php echo htmlspecialchars($display_name); ?></span>
                                                <?php if (!empty($txn['email'])): ?>
                                                    <span class="transaction-email"><?php echo htmlspecialchars($txn['email']); ?></span>
                                                <?php endif; ?>
                                                <span class="transaction-reference">Ref: <?php echo $reference_label; ?></span>
                                                <?php echo $origin_badge; ?>
                                            </div>
                                        </td>
                                        <td data-label="MSISDN"><?php echo !empty($txn['derived_msisdn']) ? htmlspecialchars($txn['derived_msisdn']) : 'N/A'; ?></td>
                                        <td data-label="Volume"><?php echo !empty($txn['derived_volume']) ? htmlspecialchars($txn['derived_volume']) : 'N/A'; ?></td>
                                        <td data-label="Network"><?php echo !empty($txn['derived_network']) ? htmlspecialchars($txn['derived_network']) : 'N/A'; ?></td>
                                        <td data-label="Type">
                                            <span class="badge badge-<?php echo $type_class; ?>">
                                                <?php echo $type_label; ?>
                                            </span>
                                        </td>
                                        <td data-label="Amount">
                                            <span class="transaction-amount <?php echo $amount_class; ?>">
                                                <?php echo $amount_display; ?>
                                            </span>
                                        </td>
                                        <td data-label="Wallet Balance">
                                            <?php if (isset($txn['balance_after']) && $txn['balance_after'] !== null): ?>
                                                <span class="text-muted" style="font-weight: 500;">
                                                    <?php echo CURRENCY . number_format((float)$txn['balance_after'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <?php echo $status_label; ?>
                                            </span>
                                        </td>
                                        <td data-label="Order Status">
                                            <span class="badge badge-<?php echo $order_status_class; ?>">
                                                <?php echo $order_status_label; ?>
                                            </span>
                                        </td>
                                        <td data-label="Date/Time">
                                            <?php echo $created_at_label; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions">
                                                <button type="button" class="btn btn-info btn-sm" title="View Details" onclick="openTransactionDetails(<?php echo $txn['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($canCompleteOrder): ?>
                                                    <form method="post" class="inline-form" onsubmit="return confirm('Mark this order as delivered?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                                        <input type="hidden" name="order_action" value="complete_order">
                                                        <input type="hidden" name="order_id" value="<?php echo (int) $txn['order_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success" title="Mark Order Delivered">
                                                            <i class="fas fa-check-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canApprove): ?>
                                                    <form method="post" class="inline-form" onsubmit="return confirm('Approve this top-up and credit the user\\'s wallet?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                                        <input type="hidden" name="transaction_action" value="approve_transaction">
                                                        <input type="hidden" name="transaction_id" value="<?php echo (int)$txn['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success" title="Approve &amp; Credit Wallet">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canFailTopup): ?>
                                                    <form method="post" class="inline-form" onsubmit="return confirm('Mark this pending top-up as failed?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                                                        <input type="hidden" name="transaction_action" value="fail_transaction">
                                                        <input type="hidden" name="transaction_id" value="<?php echo (int)$txn['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" title="Mark Top-up Failed">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-success"
                                                    data-report-info="<?php echo $reportPayloadJson; ?>"
                                                    onclick="AdminTransactionReport.send(this)"
                                                    title="Report via WhatsApp & Email">
                                                    <i class="fab fa-whatsapp"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($has_prev || $has_next): ?>
                        <div class="pagination-bar">
                            <?php if ($has_prev): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($page_link($page - 1)); ?>">Previous</a>
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline-secondary disabled">Previous</span>
                            <?php endif; ?>
                            <span class="pagination-info">Page <?php echo (int) $page; ?> (showing <?php echo count($transactions); ?>)</span>
                            <?php if ($has_next): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($page_link($page + 1)); ?>">Next</a>
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline-secondary disabled">Next</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php foreach ($transactions as $txn): ?>
    <?php
        $display_name = !empty($txn['full_name']) ? $txn['full_name'] : (!empty($txn['username']) ? $txn['username'] : 'Unknown User');
        $type_value = strtolower($txn['transaction_type'] ?? '');
        $status_value = strtolower($txn['status'] ?? '');
        $order_status_value = strtolower($txn['order_status'] ?? '');
        $type_label = $txn['transaction_type'] ? ucwords(str_replace('_', ' ', $txn['transaction_type'])) : 'N/A';
        $status_label = $status_value ? ucwords(str_replace('_', ' ', $status_value)) : 'N/A';
        $order_status_label = $order_status_value ? ucwords(str_replace('_', ' ', $order_status_value)) : 'N/A';
        $is_credit = in_array($type_value, ['credit', 'topup', 'commission', 'refund']);
        $amount_formatted = CURRENCY . number_format((float)($txn['amount'] ?? 0), 2);
        $amount_display = ($is_credit ? '+' : '-') . $amount_formatted;
        $amount_class = $is_credit ? 'credit' : 'debit';
        $payment_method_label = !empty($txn['payment_method']) ? ucwords(str_replace('_', ' ', $txn['payment_method'])) : (($txn['origin'] ?? '') === 'manual' ? 'Manual' : 'Unknown');
        $reference_label = !empty($txn['reference']) ? htmlspecialchars($txn['reference']) : 'N/A';
        $created_at_label = !empty($txn['created_at']) ? date('M j, Y H:i', strtotime($txn['created_at'])) : 'N/A';
    ?>
    <div id="transactionModal_<?php echo $txn['id']; ?>" class="modal" style="display: none;">
        <div class="modal-content modal-wide">
            <span class="close" onclick="closeTransactionDetails(<?php echo $txn['id']; ?>)">&times;</span>
            <h2>Transaction Details</h2>
            
            <div class="detail-grid">
                <div class="detail-card">
                    <h3>Summary</h3>
                    <dl class="detail-list">
                        <div><dt>Transaction ID</dt><dd><?php echo $txn['id']; ?></dd></div>
                        <div><dt>Type</dt><dd><?php echo $type_label; ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo $status_label; ?></dd></div>
                        <div><dt>Amount</dt><dd class="transaction-amount <?php echo $amount_class; ?>"><?php echo $amount_display; ?></dd></div>
                        <div><dt>Payment Method</dt><dd><?php echo htmlspecialchars($payment_method_label); ?></dd></div>
                        <div><dt>Reference</dt><dd><?php echo $reference_label; ?></dd></div>
                        <div><dt>Created</dt><dd><?php echo $created_at_label; ?></dd></div>
                    </dl>
                </div>
                
                <div class="detail-card">
                    <h3>User</h3>
                    <dl class="detail-list">
                        <div><dt>Name</dt><dd><?php echo htmlspecialchars($display_name); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo !empty($txn['email']) ? htmlspecialchars($txn['email']) : 'N/A'; ?></dd></div>
                        <div><dt>Role</dt><dd><?php echo !empty($txn['role']) ? ucfirst($txn['role']) : 'N/A'; ?></dd></div>
                    </dl>
                </div>
                
                <?php
                    $hasOrderDetails = !empty($txn['order_id']) || !empty($txn['package_name']) || !empty($txn['derived_volume']) || !empty($txn['derived_network']) || !empty($txn['derived_msisdn']);
                    $packageLabel = !empty($txn['package_name'])
                        ? $txn['package_name']
                        : (!empty($txn['derived_volume']) ? $txn['derived_volume'] : '');
                ?>
                <?php if ($hasOrderDetails): ?>
                <div class="detail-card">
                    <h3>Order</h3>
                    <dl class="detail-list">
                        <div><dt>Order ID</dt><dd><?php echo $txn['order_id'] ? $txn['order_id'] : 'N/A'; ?></dd></div>
                        <div><dt>Order Status</dt><dd><?php echo $order_status_label; ?></dd></div>
                        <?php if (!empty($txn['provider_status'])): ?>
                            <div><dt>Provider Status</dt><dd><?php echo htmlspecialchars($txn['provider_status']); ?></dd></div>
                        <?php endif; ?>
                        <div><dt>Beneficiary</dt><dd><?php echo !empty($txn['derived_msisdn']) ? htmlspecialchars($txn['derived_msisdn']) : 'N/A'; ?></dd></div>
                        <div><dt>Package</dt><dd><?php echo $packageLabel ? htmlspecialchars($packageLabel) : 'N/A'; ?></dd></div>
                        <div><dt>Volume</dt><dd><?php echo !empty($txn['derived_volume']) ? htmlspecialchars($txn['derived_volume']) : 'N/A'; ?></dd></div>
                        <div><dt>Network</dt><dd><?php echo !empty($txn['derived_network']) ? htmlspecialchars($txn['derived_network']) : 'N/A'; ?></dd></div>
                    </dl>
                </div>
                <?php endif; ?>
                
                <?php if (isset($txn['balance_before'], $txn['balance_after']) && $txn['balance_before'] !== null && $txn['balance_after'] !== null): ?>
                <div class="detail-card">
                    <h3>Wallet Impact</h3>
                    <dl class="detail-list">
                        <div><dt>Balance Before</dt><dd><?php echo CURRENCY . number_format((float)$txn['balance_before'], 2); ?></dd></div>
                        <div><dt>Balance After</dt><dd><?php echo CURRENCY . number_format((float)$txn['balance_after'], 2); ?></dd></div>
                    </dl>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($txn['description'])): ?>
                <div class="detail-card">
                    <h3>Description</h3>
                    <p class="detail-note"><?php echo nl2br(htmlspecialchars($txn['description'])); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

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

.bulk-order-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.bulk-order-actions .form-text {
    margin: 0;
}

.bulk-order-form {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    width: 100%;
}

.bulk-order-time {
    min-width: 190px;
}

.bulk-order-summary {
    flex: 1 1 240px;
}

.transaction-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}

.transaction-filter-form .form-control {
    min-width: 150px;
}

.transaction-filter-form .search-input {
    min-width: 220px;
    flex: 1 1 220px;
}

.transaction-summary {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.transaction-name {
    font-weight: 600;
    color: var(--text-color, #1f2937);
}

[data-theme="dark"] .transaction-name {
    color: #e2e8f0;
}

.transaction-email,
.transaction-reference {
    font-size: 0.8rem;
    color: var(--text-muted, #64748b);
}

[data-theme="dark"] .transaction-email,
[data-theme="dark"] .transaction-reference {
    color: #cbd5f5;
}

.transaction-origin {
    margin-top: 0.25rem;
    align-self: flex-start;
}

.transaction-amount {
    font-weight: 600;
}

.transaction-amount.credit {
    color: var(--success, #16a34a);
}

.transaction-amount.debit {
    color: var(--danger, #dc2626);
}

.table-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.order-select {
    width: 16px;
    height: 16px;
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

.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.pagination-info {
    font-size: 0.9rem;
    color: var(--text-muted, #64748b);
}

.paystack-monitor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.paystack-monitor-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.6rem;
    flex-wrap: wrap;
}

.paystack-monitor-actions form {
    margin: 0;
}

.paystack-monitor-value {
    margin: 0;
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text-color, #1f2937);
}

[data-theme="dark"] .paystack-monitor-value {
    color: #e2e8f0;
}

.paystack-monitor-date {
    font-size: 1.05rem;
    line-height: 1.4;
}

.btn.disabled,
.btn:disabled {
    pointer-events: none;
    opacity: 0.6;
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

.detail-note {
    margin: 0;
    color: var(--text-muted, #64748b);
    font-size: 0.9rem;
}

[data-theme="dark"] .detail-note {
    color: #cbd5f5;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

@media (min-width: 769px) {
    .transactions-table-wrapper {
        overflow-x: hidden;
    }

    .transactions-table {
        min-width: 0;
        table-layout: fixed;
    }

    .transactions-table-main-wrapper {
        overflow-x: hidden;
    }

    .transactions-table-main {
        width: 100%;
        font-size: 0.82rem;
    }

    .transactions-table-main th,
    .transactions-table-main td {
        padding: 0.75rem 0.45rem;
        vertical-align: middle;
        white-space: normal;
        overflow-wrap: normal;
        word-break: normal;
    }

    .transactions-table-main th {
        font-size: 0.76rem;
        line-height: 1.2;
        letter-spacing: 0.02em;
        white-space: nowrap;
        overflow-wrap: normal;
        word-break: keep-all;
        text-align: center;
    }

    .transactions-table-main td:nth-child(1),
    .transactions-table-main th:nth-child(1) {
        width: 34px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(2),
    .transactions-table-main th:nth-child(2) {
        width: 50px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(3),
    .transactions-table-main th:nth-child(3) {
        width: 25%;
    }

    .transactions-table-main td:nth-child(4),
    .transactions-table-main th:nth-child(4) {
        width: 86px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(5),
    .transactions-table-main th:nth-child(5) {
        width: 54px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(6),
    .transactions-table-main th:nth-child(6) {
        width: 62px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(7),
    .transactions-table-main th:nth-child(7) {
        width: 82px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(8),
    .transactions-table-main th:nth-child(8) {
        width: 92px;
        text-align: right;
    }

    .transactions-table-main td:nth-child(9),
    .transactions-table-main th:nth-child(9) {
        width: 78px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(10),
    .transactions-table-main th:nth-child(10) {
        width: 92px;
        text-align: center;
    }

    .transactions-table-main td:nth-child(11),
    .transactions-table-main th:nth-child(11) {
        width: 120px;
    }

    .transactions-table-main td:nth-child(12),
    .transactions-table-main th:nth-child(12) {
        width: 108px;
        text-align: left;
    }

    .transactions-table-main .transaction-summary {
        min-width: 0;
        gap: 0.2rem;
    }

    .transactions-table-main .transaction-name {
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .transactions-table-main .transaction-email,
    .transactions-table-main .transaction-reference {
        display: block;
        max-width: 100%;
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .transactions-table-main .transaction-origin {
        margin-top: 0.1rem;
    }

    .transactions-table-main .transaction-amount {
        line-height: 1.35;
        white-space: nowrap;
    }

    .transactions-table-main .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        white-space: nowrap;
        text-align: center;
        line-height: 1.25;
        padding: 0.35rem 0.45rem;
    }

    .transactions-table-main .table-actions {
        min-width: 0;
        flex-wrap: nowrap;
        justify-content: flex-start;
        gap: 0.3rem;
    }

    .transactions-table-main .table-actions .btn {
        min-width: 30px;
        width: 30px;
        height: 30px;
        padding: 0;
    }

    .transactions-table-main td:nth-child(4),
    .transactions-table-main td:nth-child(5),
    .transactions-table-main td:nth-child(6),
    .transactions-table-main td:nth-child(7),
    .transactions-table-main td:nth-child(9),
    .transactions-table-main td:nth-child(10),
    .transactions-table-main td:nth-child(11) {
        white-space: nowrap;
    }
}

@media (max-width: 992px) {
    .transaction-filter-form .form-control {
        min-width: 140px;
    }
}

@media (max-width: 768px) {
    body,
    .dashboard-wrapper,
    .main-content {
        overflow-x: hidden;
    }

    .transaction-filter-form {
        flex-direction: column;
        align-items: stretch;
    }

    .transaction-filter-form .form-control,
    .transaction-filter-form .btn {
        width: 100%;
        min-width: 0;
    }

    .bulk-order-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .bulk-order-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .bulk-order-time {
        width: 100%;
        min-width: 0;
    }

    .bulk-order-actions .form-text {
        width: 100%;
    }

    .stacked-header .widget-header-main {
        align-items: stretch;
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
        gap: 1rem;
        word-break: break-word;
    }

    .table-responsive tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-muted, #64748b);
    }

    .table-responsive tbody td[data-label="Actions"] {
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .table-responsive {
        overflow-x: hidden;
    }

    .transaction-summary,
    .transaction-reference,
    .transaction-email {
        min-width: 0;
        word-break: break-word;
    }

    .table-actions {
        width: 100%;
    }
}
</style>

<script>
window.ADMIN_REPORT_SETTINGS = {
    apiUrl: '../api/report_transaction_issue.php',
    whatsappNumber: '<?php echo $order_report_whatsapp_international; ?>',
    csrfToken: '<?php echo $report_csrf_token; ?>'
};
</script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/admin-transaction-report.js')); ?>""></script>

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

    function openTransactionDetails(transactionId) {
        const modal = document.getElementById(`transactionModal_${transactionId}`);
        if (modal) {
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    function closeTransactionDetails(transactionId) {
        const modal = document.getElementById(`transactionModal_${transactionId}`);
        if (modal) {
            modal.style.display = 'none';
            refreshModalOpenState();
        }
    }

    function toggleOrderSelection(source) {
        const checkboxes = getOrderCheckboxes();
        checkboxes.forEach((checkbox) => {
            checkbox.checked = source.checked;
        });
        syncOrderSelectionState();
    }

    function getOrderCheckboxes() {
        return Array.from(document.querySelectorAll('.order-select'));
    }

    function parseTransactionDateTime(value) {
        if (!value) {
            return Number.NaN;
        }

        const normalized = value.includes('T') ? value : value.replace(' ', 'T');
        return new Date(normalized).getTime();
    }

    function updateBulkOrderSummary(message = '') {
        const summary = document.getElementById('bulkOrderSummary');
        if (!summary) {
            return;
        }

        const checkboxes = getOrderCheckboxes();
        const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        const totalCount = checkboxes.length;

        if (totalCount === 0) {
            summary.textContent = 'No deliverable orders are available on this page.';
            return;
        }

        summary.textContent = message || `${selectedCount} of ${totalCount} deliverable orders selected.`;
    }

    function syncOrderSelectionState(message = '') {
        const checkboxes = getOrderCheckboxes();
        const selectAll = document.getElementById('selectAllOrders');
        const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
        const totalCount = checkboxes.length;

        if (selectAll) {
            selectAll.checked = totalCount > 0 && selectedCount === totalCount;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < totalCount;
        }

        updateBulkOrderSummary(message);
    }

    function applyTimeRangeSelection() {
        const fromInput = document.getElementById('bulkTimeFrom');
        const toInput = document.getElementById('bulkTimeTo');
        const fromTime = parseTransactionDateTime(fromInput ? fromInput.value : '');
        const toTime = parseTransactionDateTime(toInput ? toInput.value : '');

        if (Number.isNaN(fromTime) && Number.isNaN(toTime)) {
            updateBulkOrderSummary('Choose a start time, end time, or both.');
            return;
        }

        if (!Number.isNaN(fromTime) && !Number.isNaN(toTime) && fromTime > toTime) {
            alert('The start time must be earlier than the end time.');
            return;
        }

        const checkboxes = getOrderCheckboxes();
        let matchedCount = 0;

        checkboxes.forEach((checkbox) => {
            const checkboxTime = parseTransactionDateTime(checkbox.dataset.createdAt || '');
            const withinLowerBound = Number.isNaN(fromTime) || (!Number.isNaN(checkboxTime) && checkboxTime >= fromTime);
            const withinUpperBound = Number.isNaN(toTime) || (!Number.isNaN(checkboxTime) && checkboxTime <= toTime);
            const shouldSelect = !Number.isNaN(checkboxTime) && withinLowerBound && withinUpperBound;

            checkbox.checked = shouldSelect;
            if (shouldSelect) {
                matchedCount += 1;
            }
        });

        syncOrderSelectionState(`${matchedCount} order${matchedCount === 1 ? '' : 's'} selected in the chosen time range.`);
    }

    function validateBulkTimeRangeSubmission() {
        const fromInput = document.getElementById('bulkTimeFrom');
        const toInput = document.getElementById('bulkTimeTo');
        const fromTime = parseTransactionDateTime(fromInput ? fromInput.value : '');
        const toTime = parseTransactionDateTime(toInput ? toInput.value : '');

        if (Number.isNaN(fromTime) && Number.isNaN(toTime)) {
            updateBulkOrderSummary('Choose a start time, end time, or both before running the time-range action.');
            return false;
        }

        if (!Number.isNaN(fromTime) && !Number.isNaN(toTime) && fromTime > toTime) {
            alert('The start time must be earlier than the end time.');
            return false;
        }

        return true;
    }

    function confirmBulkOrderSubmit(event) {
        const submitter = event && event.submitter ? event.submitter : null;
        const action = submitter ? submitter.value : '';

        if (action === 'bulk_complete_orders_by_time') {
            return confirm('Mark all filtered orders in this time range as delivered?');
        }

        return confirm('Mark selected orders as delivered?');
    }

    function clearOrderSelection() {
        getOrderCheckboxes().forEach((checkbox) => {
            checkbox.checked = false;
        });
        syncOrderSelectionState('Selection cleared.');
    }

    function initOrderSelectionControls() {
        getOrderCheckboxes().forEach((checkbox) => {
            checkbox.addEventListener('change', () => syncOrderSelectionState());
        });
        syncOrderSelectionState();
    }

    initOrderSelectionControls();
    
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
                event.target.style.display = 'none';
                refreshModalOpenState();
            }
        });
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



