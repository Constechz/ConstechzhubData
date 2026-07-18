<?php
if (!function_exists('dbh_emit_fatal_json')) {
    function dbh_emit_fatal_json($message, array $context = []) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        $payload = array_merge([
            'status' => 'error',
            'message' => $message,
        ], $context);
        echo json_encode($payload);
    }
}

if (!function_exists('dbh_register_fatal_json_handlers')) {
    function dbh_register_fatal_json_handlers() {
        register_shutdown_function(function () {
            $error = error_get_last();
            if (!$error) {
                return;
            }
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error['type'], $fatalTypes, true)) {
                return;
            }
            $errorId = 'RC_FATAL_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $message = $error['message'] ?? 'Fatal error';
            $file = $error['file'] ?? 'unknown';
            $line = $error['line'] ?? 0;
            error_log("Result checker purchase fatal ({$errorId}): {$message} in {$file}:{$line}");
            dbh_emit_fatal_json('Server error. Please try again.', ['error_id' => $errorId]);
        });

        set_exception_handler(function ($exception) {
            $errorId = 'RC_EX_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $message = $exception instanceof Throwable ? $exception->getMessage() : 'Unhandled exception';
            $file = $exception instanceof Throwable ? $exception->getFile() : 'unknown';
            $line = $exception instanceof Throwable ? $exception->getLine() : 0;
            $trace = $exception instanceof Throwable ? $exception->getTraceAsString() : '';
            error_log("Result checker purchase exception ({$errorId}): {$message} in {$file}:{$line}\n{$trace}");
            dbh_emit_fatal_json('Server error. Please try again.', ['error_id' => $errorId]);
            exit();
        });
    }
}

dbh_register_fatal_json_handlers();

require_once '../config/config.php';

// Require login
requireLogin();

// JSON response
header('Content-Type: application/json');

if (!function_exists('dbh_json_response')) {
    function dbh_json_response(array $payload, int $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($payload);
        exit();
    }
}

if (!function_exists('dbh_json_error')) {
    function dbh_json_error(string $message, int $statusCode = 400, array $extra = []) {
        $payload = array_merge(['status' => 'error', 'message' => $message], $extra);
        dbh_json_response($payload, $statusCode);
    }
}

if (!function_exists('dbh_stmt_fetch_assoc')) {
    /**
     * Fetch a single row from a prepared statement as an associative array,
     * with a fallback when mysqlnd (get_result) is unavailable.
     */
    function dbh_stmt_fetch_assoc($stmt) {
        if (!$stmt) {
            return null;
        }

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if ($result instanceof mysqli_result) {
                return $result->fetch_assoc() ?: null;
            }
            return null;
        }

        $meta = $stmt->result_metadata();
        if (!$meta) {
            return null;
        }

        $row = [];
        $bindParams = [];
        while ($field = $meta->fetch_field()) {
            $row[$field->name] = null;
            $bindParams[] = &$row[$field->name];
        }

        if (!empty($bindParams)) {
            call_user_func_array([$stmt, 'bind_result'], $bindParams);
        }

        if ($stmt->fetch()) {
            $rowData = [];
            foreach ($row as $key => $value) {
                $rowData[$key] = $value;
            }
            return $rowData;
        }

        return null;
    }
}

if (!function_exists('dbh_build_insert_query')) {
    /**
     * Build an INSERT query using only columns that exist in the target table.
     * Returns [sql, types, values, errorMessage].
     */
    function dbh_build_insert_query($table, array $fieldSpecs, array $requiredColumns = []) {
        if (!function_exists('dbh_table_has_column')) {
            $requiredColumns = [];
        }

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        foreach ($fieldSpecs as $spec) {
            $column = $spec['column'];
            $exists = true;
            if (function_exists('dbh_table_has_column')) {
                $exists = dbh_table_has_column($table, $column);
            }

            if (!$exists) {
                if (in_array($column, $requiredColumns, true)) {
                    return [null, null, null, "Database schema missing required column: {$column}"];
                }
                continue;
            }

            $columns[] = "`{$column}`";
            $placeholders[] = '?';
            $types .= $spec['type'];
            $values[] = $spec['value'];
        }

        if (empty($columns)) {
            return [null, null, null, 'No columns available for insert.'];
        }

        $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        return [$sql, $types, $values, null];
    }
}

if (!function_exists('dbh_execute_prepared')) {
    /**
     * Execute a prepared statement with dynamic bind params.
     */
    function dbh_execute_prepared($db, $sql, $types, array $values) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => $db->getConnection()->error ?? 'Prepare failed'];
        }

        $bindParams = [$types];
        foreach ($values as $index => $value) {
            $bindParams[] = &$values[$index];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        return ['ok' => (bool) $ok, 'error' => $error];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dbh_json_error('Method not allowed', 405);
}

ensureResultCheckerTables();
ensurePaymentGatewaySchema();

$current_user = getCurrentUser();
if (!$current_user) {
    dbh_json_error('Unauthorized', 401);
}

$payload = json_decode(file_get_contents('php://input'), true);
$card_type = strtoupper(trim($payload['card_type'] ?? ''));
$payment_method = strtolower(trim($payload['payment_method'] ?? 'wallet'));
$store_slug = sanitize($payload['store_slug'] ?? '');
$sms_phone = sanitize($payload['sms_phone'] ?? '');
$notification_email = sanitize($payload['notification_email'] ?? '');
$csrf_token = $payload['csrf_token'] ?? '';

if (!validateCSRF($csrf_token)) {
    dbh_json_error('Invalid session token', 403);
}

function ensureCurlAvailable() {
    if (!function_exists('curl_init')) {
        dbh_json_error('Payment gateway is unavailable because cURL is not enabled on this server.', 500);
    }
}

if (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
    dbh_json_error('Invalid card type', 400);
}

if (!in_array($payment_method, ['wallet', 'gateway'], true)) {
    dbh_json_error('Invalid payment method', 400);
}

if (empty($sms_phone) || !validatePhone($sms_phone)) {
    dbh_json_error('Please provide a valid SMS phone number.', 400);
}

if ($notification_email !== '' && !validateEmail($notification_email)) {
    dbh_json_error('Please provide a valid email address.', 400);
}

$current_role = normalizeUserRole($current_user['role'] ?? '');
$requires_email = in_array($current_role, [
    normalizeUserRole(defined('ROLE_AGENT') ? ROLE_AGENT : 'agent'),
    normalizeUserRole(defined('ROLE_CUSTOMER') ? ROLE_CUSTOMER : 'customer')
], true);
if ($requires_email && $notification_email === '') {
    dbh_json_error('Email address is required to complete this purchase.', 400);
}

$sms_phone = formatPhone($sms_phone);

// Load settings
$settings = [
    'bece_price' => 17.00,
    'wassce_price' => 17.00,
    'bece_enabled' => 0,
    'wassce_enabled' => 0,
    'bece_checker_link' => '',
    'wassce_checker_link' => ''
];
$settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
    $settings = array_merge($settings, $settings_row);
}

$bece_enabled = ((int) $settings['bece_enabled'] === 1) || ((float) $settings['bece_price'] > 0);
$wassce_enabled = ((int) $settings['wassce_enabled'] === 1) || ((float) $settings['wassce_price'] > 0);
$enabled_flag = $card_type === 'BECE' ? $bece_enabled : $wassce_enabled;
if (!$enabled_flag) {
    dbh_json_error('Selected card type is currently unavailable', 400);
}

$admin_price = $card_type === 'BECE' ? (float) $settings['bece_price'] : (float) $settings['wassce_price'];

// Determine agent context for customers
$agent_id = 0;
if (($current_user['role'] ?? '') === 'customer') {
    if ($store_slug !== '') {
        $stmt = $db->prepare("
            SELECT ast.agent_id
            FROM agent_stores ast
            JOIN users u ON ast.agent_id = u.id
            WHERE ast.store_slug = ? AND ast.is_active = 1 AND u.status = 'active'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $store_slug);
        if ($stmt->execute()) {
            $row = dbh_stmt_fetch_assoc($stmt);
            if ($row) {
                $agent_id = (int) $row['agent_id'];
            }
        }
        $stmt->close();
    } else {
        error_log('Result checker purchase: store lookup prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
    }
}
    if ($agent_id <= 0) {
        $agent_id = getLinkedAgentId($current_user['id']);
    }
}

// Resolve agent custom price if applicable
$agent_price = $admin_price;
if ($agent_id > 0) {
    if (function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing')) {
        $has_is_active = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'is_active');
        $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'updated_at');
        $has_created_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'created_at');

        $where = "WHERE agent_id = ? AND card_type = ?";
        if ($has_is_active) {
            $where .= " AND is_active = 1";
        }
        $orderBy = '';
        if ($has_updated_at) {
            $orderBy = ' ORDER BY updated_at DESC';
        } elseif ($has_created_at) {
            $orderBy = ' ORDER BY created_at DESC';
        }

        $stmt = $db->prepare("SELECT custom_price FROM agent_result_checker_pricing {$where}{$orderBy} LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('is', $agent_id, $card_type);
            if ($stmt->execute()) {
                $row = dbh_stmt_fetch_assoc($stmt);
                if ($row) {
                    $candidate = (float) $row['custom_price'];
                    if ($candidate >= $admin_price) {
                        $agent_price = $candidate;
                    }
                }
            }
            $stmt->close();
        } else {
            error_log('Result checker purchase: agent pricing prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        }
    }
}

$price_to_charge = $agent_id > 0 ? $agent_price : $admin_price;
$price_to_charge = round($price_to_charge, 2);
$admin_price = round($admin_price, 2);
$profit_amount = $agent_id > 0 ? max(0, $price_to_charge - $admin_price) : 0.0;
$profit_amount = round($profit_amount, 2);

// Quick availability check
$stmt = $db->prepare("SELECT COUNT(*) AS total_count FROM result_checker_cards WHERE card_type = ? AND status = 'available'");
if (!$stmt) {
    dbh_json_error('Database error. Please try again.', 500);
}
$stmt->bind_param('s', $card_type);
if (!$stmt->execute()) {
    $stmt->close();
    dbh_json_error('Unable to check card availability. Please try again.', 500);
}
$available_row = dbh_stmt_fetch_assoc($stmt);
$stmt->close();
if (!$available_row || (int) $available_row['total_count'] <= 0) {
    dbh_json_error('No cards available for the selected type.', 400);
}

if ($payment_method === 'gateway') {
    $gateway = getActivePaymentGateway();
    if (!in_array($gateway, ['paystack', 'moolre'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No active payment gateway available']);
        exit();
    }

    $reference = generateReference('RC');
    $description = "{$card_type} result checker card purchase";
    $metadata = [
        'type' => 'result_checker_purchase',
        'card_type' => $card_type,
        'agent_id' => $agent_id,
        'admin_price' => $admin_price,
        'sms_phone' => $sms_phone,
        'notification_email' => $notification_email,
        'store_slug' => $store_slug,
        'buyer_name' => $current_user['full_name'] ?? '',
        'buyer_role' => $current_user['role'] ?? 'customer',
        'return_to' => ($current_user['role'] ?? '') === 'agent' ? '/agent/result-checker.php' : '/customer/result-checker.php'
    ];

    $stmt = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
        VALUES (?, 'purchase', ?, 'pending', ?, ?, ?, ?)
    ");
    $metadata_json = json_encode($metadata);
    if (!$stmt) {
        dbh_json_error('Failed to create transaction. Please try again.', 500);
    }
    $stmt->bind_param("idssss", $current_user['id'], $price_to_charge, $reference, $gateway, $description, $metadata_json);
    if (!$stmt->execute()) {
        $stmt->close();
        dbh_json_error('Failed to create transaction. Please try again.', 500);
    }
    $stmt->close();

    $pendingFields = [
        ['column' => 'user_id', 'type' => 'i', 'value' => $current_user['id']],
        ['column' => 'agent_id', 'type' => 'i', 'value' => $agent_id],
        ['column' => 'card_type', 'type' => 's', 'value' => $card_type],
        ['column' => 'amount', 'type' => 'd', 'value' => $price_to_charge],
        ['column' => 'admin_price', 'type' => 'd', 'value' => $admin_price],
        ['column' => 'profit_amount', 'type' => 'd', 'value' => $profit_amount],
        ['column' => 'payment_gateway', 'type' => 's', 'value' => $gateway],
        ['column' => 'reference', 'type' => 's', 'value' => $reference],
        ['column' => 'status', 'type' => 's', 'value' => 'pending'],
        ['column' => 'sms_phone', 'type' => 's', 'value' => $sms_phone],
        ['column' => 'notification_email', 'type' => 's', 'value' => $notification_email],
    ];

    [$sql, $types, $values, $error] = dbh_build_insert_query(
        'result_checker_purchases',
        $pendingFields,
        ['user_id', 'card_type', 'amount', 'reference']
    );
    if ($error) {
        dbh_json_error($error, 500);
    }
    $insert = dbh_execute_prepared($db, $sql, $types, $values);
    if (!$insert['ok']) {
        dbh_json_error('Failed to record purchase. Please try again.', 500, ['detail' => $insert['error']]);
    }

    if ($gateway === 'paystack') {
        ensureCurlAvailable();
        $paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
        $isInvalidPaystackKey = function ($key) {
            $key = trim((string) $key);
            return $key === '' || stripos($key, 'your_secret_key_here') !== false;
        };
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            $paystack_secret_key = PAYSTACK_SECRET_KEY;
        }
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            dbh_json_error('Paystack keys are not configured.', 400);
        }

        $postfields = json_encode([
            'email' => $current_user['email'],
            'amount' => $price_to_charge * 100,
            'currency' => CURRENCY_CODE,
            'reference' => $reference,
            'callback_url' => PAYSTACK_CALLBACK_URL,
            'metadata' => $metadata
        ]);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $paystack_secret_key,
                "Content-Type: application/json",
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            dbh_json_error('Failed to initialize Paystack payment.', 500);
        }

        $result = json_decode($response, true);
        if (!$result || empty($result['status'])) {
            $message = $result['message'] ?? 'Unknown error';
            dbh_json_error($message, 500);
        }

        dbh_json_response([
            'status' => 'success',
            'data' => [
                'authorization_url' => $result['data']['authorization_url'],
                'reference' => $reference
            ]
        ]);
    }

    if ($gateway === 'moolre') {
        ensureCurlAvailable();
        $config = getMoolreConfig();
        if (!isMoolreConfigured($config)) {
            dbh_json_error('Moolre keys are not configured.', 400);
        }

        $redirectUrl = SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference);
        $payload = [
            'type' => 1,
            'amount' => round($price_to_charge, 2),
            'email' => $current_user['email'],
            'externalref' => $reference,
            'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
            'redirect' => $redirectUrl,
            'redirecturl' => $redirectUrl,
            'redirect_url' => $redirectUrl,
            'reusable' => '0',
            'currency' => CURRENCY_CODE,
            'accountnumber' => $config['account_number'],
            'metadata' => $metadata
        ];

        $error = null;
        $result = moolrePostJson('https://api.moolre.com/embed/link', $payload, $config, $error);
        if (!$result) {
            dbh_json_error($error ?: 'Failed to initialize Moolre payment.', 500);
        }

        $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
        if (!$status_ok) {
            $message = $result['message'] ?? 'Moolre initialization failed.';
            dbh_json_error($message, 500);
        }

        $auth_url = $result['data']['authorization_url'] ?? '';
        if ($auth_url === '') {
            dbh_json_error('Missing authorization URL from Moolre.', 500);
        }

        dbh_json_response([
            'status' => 'success',
            'data' => [
                'authorization_url' => $auth_url,
                'reference' => $reference
            ]
        ]);
    }
}

// Wallet purchase flow
$reference = generateReference('RC');
$description = "{$card_type} result checker card purchase";
$agent_reference = generateReference('RCAG');

if (($current_user['role'] ?? '') === 'agent') {
    $agent_id = 0;
    $price_to_charge = $admin_price;
    $profit_amount = 0.0;
}

if ($agent_id > 0) {
    $customer_balance = getWalletBalance($current_user['id']);
    if ($customer_balance < $price_to_charge) {
        dbh_json_error('Insufficient wallet balance. Please top up.', 400);
    }

    $agent_balance = getWalletBalance($agent_id);
    if (($agent_balance + $price_to_charge) < $admin_price) {
        dbh_json_error('Agent has insufficient balance to fulfill this order.', 400);
    }

    if (!transferWalletBalance($current_user['id'], $agent_id, $price_to_charge, $reference, $description)) {
        dbh_json_error('Failed to process payment', 500);
    }

    if (!updateWalletBalance($agent_id, $admin_price, 'debit', $agent_reference, 'Result checker wholesale cost')) {
        // Refund transfer if wholesale debit fails
        transferWalletBalance($agent_id, $current_user['id'], $price_to_charge, $reference . '_REFUND', 'Refund: wholesale cost failed');
        dbh_json_error('Failed to process agent wholesale cost', 500);
    }
} else {
    $balance = getWalletBalance($current_user['id']);
    if ($balance < $price_to_charge) {
        dbh_json_error('Insufficient wallet balance. Please top up.', 400);
    }
    if (!updateWalletBalance($current_user['id'], $price_to_charge, 'debit', $reference, $description)) {
        dbh_json_error('Failed to deduct wallet balance', 500);
    }
}

// Allocate a card
$stmt = $db->prepare("
    SELECT id, pin, serial_number
    FROM result_checker_cards
    WHERE card_type = ? AND status = 'available'
    ORDER BY id ASC
    LIMIT 1
");
if (!$stmt) {
    dbh_json_error('Unable to allocate card. Please try again.', 500);
}
$stmt->bind_param('s', $card_type);
if (!$stmt->execute()) {
    $stmt->close();
    dbh_json_error('Unable to allocate card. Please try again.', 500);
}
$card = dbh_stmt_fetch_assoc($stmt);
$stmt->close();

if (!$card) {
    // Refund wallet operations
    if ($agent_id > 0) {
        updateWalletBalance($agent_id, $admin_price, 'credit', $agent_reference . '_REFUND', 'Refund: card not available');
        transferWalletBalance($agent_id, $current_user['id'], $price_to_charge, $reference . '_REFUND', 'Refund: card not available');
    } else {
        updateWalletBalance($current_user['id'], $price_to_charge, 'credit', $reference . '_REFUND', 'Refund: card not available');
    }
    dbh_json_error('No cards available. Payment reversed.', 400);
}

$stmt = $db->prepare("
    UPDATE result_checker_cards
    SET status = 'purchased', purchased_by = ?, purchased_at = NOW()
    WHERE id = ? AND status = 'available'
");
if (!$stmt) {
    dbh_json_error('Card allocation failed. Payment reversed.', 500);
}
$stmt->bind_param('ii', $current_user['id'], $card['id']);
if (!$stmt->execute()) {
    $stmt->close();
    dbh_json_error('Card allocation failed. Payment reversed.', 500);
}
$stmt->close();

if ($stmt->affected_rows <= 0) {
    if ($agent_id > 0) {
        updateWalletBalance($agent_id, $admin_price, 'credit', $agent_reference . '_REFUND', 'Refund: card allocation failed');
        transferWalletBalance($agent_id, $current_user['id'], $price_to_charge, $reference . '_REFUND', 'Refund: card allocation failed');
    } else {
        updateWalletBalance($current_user['id'], $price_to_charge, 'credit', $reference . '_REFUND', 'Refund: card allocation failed');
    }
    dbh_json_error('Card allocation failed. Payment reversed.', 400);
}

// Record purchase
$successFields = [
    ['column' => 'user_id', 'type' => 'i', 'value' => $current_user['id']],
    ['column' => 'agent_id', 'type' => 'i', 'value' => $agent_id],
    ['column' => 'card_id', 'type' => 'i', 'value' => $card['id']],
    ['column' => 'card_type', 'type' => 's', 'value' => $card_type],
    ['column' => 'amount', 'type' => 'd', 'value' => $price_to_charge],
    ['column' => 'admin_price', 'type' => 'd', 'value' => $admin_price],
    ['column' => 'profit_amount', 'type' => 'd', 'value' => $profit_amount],
    ['column' => 'payment_gateway', 'type' => 's', 'value' => 'wallet'],
    ['column' => 'reference', 'type' => 's', 'value' => $reference],
    ['column' => 'status', 'type' => 's', 'value' => 'success'],
    ['column' => 'pin', 'type' => 's', 'value' => $card['pin']],
    ['column' => 'serial_number', 'type' => 's', 'value' => $card['serial_number']],
    ['column' => 'sms_phone', 'type' => 's', 'value' => $sms_phone],
    ['column' => 'notification_email', 'type' => 's', 'value' => $notification_email],
];

[$sql, $types, $values, $error] = dbh_build_insert_query(
    'result_checker_purchases',
    $successFields,
    ['user_id', 'card_id', 'card_type', 'amount', 'reference', 'pin', 'serial_number']
);
if ($error) {
    dbh_json_error($error, 500);
}
$insert = dbh_execute_prepared($db, $sql, $types, $values);
if (!$insert['ok']) {
    dbh_json_error('Failed to record purchase. Please try again.', 500, ['detail' => $insert['error']]);
}

// Record transaction rows
$stmt = $db->prepare("
    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description)
    VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?)
");
if ($stmt) {
    $stmt->bind_param('idss', $current_user['id'], $price_to_charge, $reference, $description);
    $stmt->execute();
    $stmt->close();
}

if ($agent_id > 0) {
    $agent_desc = 'Result checker wholesale cost for ' . $card_type . ' card';
    $stmt = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description)
        VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?)
    ");
    if ($stmt) {
        $stmt->bind_param('idss', $agent_id, $admin_price, $agent_reference, $agent_desc);
        $stmt->execute();
        $stmt->close();
    }
}

// Send SMS with card details
$checker_link = $card_type === 'BECE' ? ($settings['bece_checker_link'] ?? '') : ($settings['wassce_checker_link'] ?? '');
if (function_exists('curl_init')) {
    sendResultCheckerSms($sms_phone, $card_type, $card['pin'], $card['serial_number'], $checker_link, $current_user['id']);
} else {
    error_log('Result checker SMS skipped: cURL extension not available.');
}
if ($notification_email) {
    sendResultCheckerEmail($notification_email, $card_type, $card['pin'], $card['serial_number'], $checker_link, $current_user['full_name'] ?? '');
}

sendAdminResultCheckerOrderNotification([
    'reference' => $reference,
    'user_id' => (int) $current_user['id'],
    'buyer_name' => $current_user['full_name'] ?? '',
    'buyer_email' => $current_user['email'] ?? '',
    'card_type' => $card_type,
    'amount' => $price_to_charge,
    'admin_price' => $admin_price,
    'profit_amount' => $profit_amount,
    'payment_method' => 'wallet',
    'status' => 'success',
    'agent_id' => $agent_id,
    'source' => 'result_checker_wallet_purchase'
]);

dbh_json_response([
    'status' => 'success',
    'message' => 'Purchase successful',
    'data' => [
        'card_type' => $card_type,
        'pin' => $card['pin'],
        'serial_number' => $card['serial_number'],
        'reference' => $reference,
        'amount' => $price_to_charge
    ]
]);
