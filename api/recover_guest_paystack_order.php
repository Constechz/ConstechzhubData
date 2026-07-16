<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/paystack_fees.php';

if (!function_exists('buildPaymentFinalizationDebugMessage')) {
    /**
     * Builds a structured debug message for payment finalization failures.
     */
    function buildPaymentFinalizationDebugMessage($reference, $stage, $error) {
        $msg = "Reference: " . (string) $reference;
        if (!empty($stage)) {
            $msg .= " (Stage: " . (string) $stage . ")";
        }
        $msg .= ". Error: " . (string) $error;
        return $msg;
    }
}

ensurePaymentGatewaySchema();
ensureGuestCheckoutSchema();

header('Content-Type: application/json');

$recovery_stage = 'recovery_bootstrap';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!validateCSRF($csrf)) {
    http_response_code(419);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit();
}

$store_slug = sanitize($payload['store_slug'] ?? '');
$reference = sanitize($payload['reference'] ?? '');
$email = strtolower(trim((string) ($payload['email'] ?? '')));
$phone_input = trim((string) ($payload['phone'] ?? ''));
$phone_digits = preg_replace('/\D+/', '', $phone_input);
$current_recovery_user = function_exists('getCurrentUser') ? getCurrentUser() : null;
$current_recovery_role = strtolower(trim((string) ($current_recovery_user['role'] ?? ($_SESSION['user_role'] ?? ''))));
$is_admin_recovery = is_array($current_recovery_user) && in_array($current_recovery_role, ['admin', 'super_admin'], true);

$lookupGuestPaystackReference = static function ($storeSlug, $emailAddress, $phoneDigits) use ($db) {
    $storeSlug = trim((string) $storeSlug);
    $emailAddress = strtolower(trim((string) $emailAddress));
    $formattedPhone = formatPhone((string) $phoneDigits);

    if ($storeSlug === '' || $emailAddress === '' || $formattedPhone === '') {
        return ['status' => 'error', 'message' => 'Store, email, and recipient number are required.'];
    }

    $cutoff = date('Y-m-d H:i:s', time() - (7 * 24 * 60 * 60));
    $stmt = $db->prepare("
        SELECT t.id, t.reference, t.status, t.metadata, t.order_id, t.created_at, u.email AS account_email
        FROM transactions t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.transaction_type = 'purchase'
          AND t.payment_method = 'paystack'
          AND t.status IN ('pending', 'failed', 'success')
          AND t.created_at >= ?
        ORDER BY t.id DESC
        LIMIT 100
    ");
    if (!$stmt) {
        return ['status' => 'error', 'message' => 'Unable to search payments right now.'];
    }

    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = [];

    while ($row = $result->fetch_assoc()) {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        if (!is_array($metadata) || ($metadata['type'] ?? '') !== 'guest_bundle_purchase') {
            continue;
        }

        $rowStore = trim((string) ($metadata['store_slug'] ?? ''));
        $rowEmail = strtolower(trim((string) ($metadata['buyer_email'] ?? ($metadata['email'] ?? ($row['account_email'] ?? '')))));
        $rowPhone = formatPhone((string) ($metadata['beneficiary_number'] ?? ''));

        if ($rowStore === $storeSlug && $rowEmail === $emailAddress && $rowPhone === $formattedPhone) {
            $matches[] = $row;
        }
    }
    $stmt->close();

    if (count($matches) === 0) {
        return ['status' => 'error', 'message' => 'No recent Paystack payment was found for that email and recipient number.'];
    }

    if (count($matches) > 1) {
        return ['status' => 'ambiguous', 'message' => 'More than one recent payment matches those details. Enter the Paystack reference from your receipt to verify the exact order.'];
    }

    return ['status' => 'success', 'reference' => (string) ($matches[0]['reference'] ?? '')];
};

if ($reference === '') {
    if ($email === '' || $store_slug === '' || $phone_digits === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Enter the email address and recipient number used for payment.']);
        exit();
    }

    if (!validateEmail($email)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
        exit();
    }

    if (!validatePhone($phone_digits)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid beneficiary number.']);
        exit();
    }

    $lookup = $lookupGuestPaystackReference($store_slug, $email, $phone_digits);
    if (($lookup['status'] ?? '') !== 'success' || empty($lookup['reference'])) {
        http_response_code(($lookup['status'] ?? '') === 'ambiguous' ? 409 : 404);
        echo json_encode(['status' => 'error', 'message' => $lookup['message'] ?? 'Unable to find this payment.']);
        exit();
    }

    $reference = sanitize($lookup['reference']);
}

if (!preg_match('/^PAY_/i', $reference)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only Paystack references generated by this system can be verified here.']);
    exit();
}

$stmt = $db->prepare("
    SELECT t.id, t.user_id, t.status, t.transaction_type, t.payment_method, t.amount, t.metadata, t.order_id, u.email AS account_email
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.reference = ?
    LIMIT 1
");
$stmt->bind_param('s', $reference);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transaction) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Transaction not found.']);
    exit();
}

$is_agent_self_recovery = is_array($current_recovery_user)
    && in_array($current_recovery_role, ['agent', 'vip'], true)
    && (int) ($transaction['user_id'] ?? 0) === (int) ($current_recovery_user['id'] ?? 0);

if (($transaction['payment_method'] ?? '') !== 'paystack' || ($transaction['transaction_type'] ?? '') !== 'purchase') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only Paystack data orders can be recovered here.']);
    exit();
}

$metadata = [];
if (!empty($transaction['metadata'])) {
    $decoded = json_decode($transaction['metadata'], true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}

$bundle_purchase_type = (string) ($metadata['type'] ?? '');
if (!in_array($bundle_purchase_type, ['guest_bundle_purchase', 'customer_bundle_purchase'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This reference is not linked to a Paystack data bundle purchase.']);
    exit();
}

$is_agent_authorized_recovery = false;
if (is_array($current_recovery_user) && $current_recovery_role === 'agent') {
    $agent_id = (int) ($current_recovery_user['id'] ?? 0);
    $meta_agent_id = (int) ($metadata['agent_id'] ?? 0);
    if ($meta_agent_id > 0 && $meta_agent_id === $agent_id) {
        $is_agent_authorized_recovery = true;
    } else {
        $meta_store_slug = trim((string) ($metadata['store_slug'] ?? ''));
        if ($meta_store_slug !== '') {
            $stmt_store = $db->prepare("SELECT agent_id FROM agent_stores WHERE store_slug = ? AND is_active = TRUE LIMIT 1");
            if ($stmt_store) {
                $stmt_store->bind_param('s', $meta_store_slug);
                if ($stmt_store->execute()) {
                    $store_res = $stmt_store->get_result()->fetch_assoc();
                    $store_agent_id = (int) ($store_res['agent_id'] ?? 0);
                    if ($store_agent_id > 0 && $store_agent_id === $agent_id) {
                        $is_agent_authorized_recovery = true;
                    }
                }
                $stmt_store->close();
            }
        }
    }
}

$is_authorized_recovery = $is_admin_recovery || $is_agent_self_recovery || $is_agent_authorized_recovery;

if ($is_authorized_recovery) {
    if ($store_slug === '') {
        $store_slug = sanitize($metadata['store_slug'] ?? '');
    }
    if ($email === '') {
        $email = strtolower(trim((string) ($metadata['email'] ?? ($metadata['buyer_email'] ?? ($transaction['account_email'] ?? '')))));
    }
    if ($phone_digits === '') {
        $phone_input = trim((string) ($metadata['beneficiary_number'] ?? ''));
        $phone_digits = preg_replace('/\D+/', '', $phone_input);
    }
}

if (!$is_authorized_recovery && $email === '') {
    $email = strtolower(trim((string) ($metadata['email'] ?? ($metadata['buyer_email'] ?? ($transaction['account_email'] ?? '')))));
}

if (!$is_authorized_recovery && $store_slug === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Store, phone, and Paystack reference are required.']);
    exit();
}

if ($phone_digits === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Phone and Paystack reference are required.']);
    exit();
}

if ($email !== '' && !validateEmail($email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address.']);
    exit();
}

if (!validatePhone($phone_digits)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid beneficiary number.']);
    exit();
}

$formatted_phone = formatPhone($phone_digits);

$metadata_store_slug = trim((string) ($metadata['store_slug'] ?? ''));
if (!$is_authorized_recovery && $metadata_store_slug !== $store_slug) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'This payment reference does not belong to this store.']);
    exit();
}

if ($store_slug !== '') {
    $stmt = $db->prepare("
        SELECT ast.agent_id
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('s', $store_slug);
    $stmt->execute();
    $store = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$store) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Store not found.']);
        exit();
    }
}

$transaction_email = strtolower(trim((string) ($metadata['email'] ?? ($transaction['account_email'] ?? ''))));
$transaction_phone = trim((string) ($metadata['beneficiary_number'] ?? ''));

if ($email !== '' && ($transaction_email === '' || strcasecmp($transaction_email, $email) !== 0)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The email address does not match this payment reference.']);
    exit();
}

if ($transaction_phone === '' || strcmp(formatPhone($transaction_phone), $formatted_phone) !== 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'The beneficiary number does not match this payment reference.']);
    exit();
}

$public_lookup_path = $store_slug !== ''
    ? '/store/reference.php?store=' . urlencode($store_slug) . '&lookup=' . urlencode($reference)
    : ($is_agent_self_recovery ? '/agent/transactions.php?search=' . urlencode($reference) : '/admin/transactions.php?search=' . urlencode($reference));
$transaction_status = strtolower(trim((string) ($transaction['status'] ?? '')));
$transaction_order_id = (int) ($transaction['order_id'] ?? 0);

if ($transaction_status === 'success' && $transaction_order_id > 0) {
    echo json_encode([
        'status' => 'success',
        'transaction_status' => 'success',
        'message' => 'This payment was already processed. Redirecting to order status.',
        'redirect_path' => $public_lookup_path,
    ]);
    exit();
}

if (!in_array($transaction_status, ['pending', 'failed', 'success'], true)) {
    echo json_encode([
        'status' => 'success',
        'transaction_status' => $transaction_status !== '' ? $transaction_status : 'unknown',
        'message' => 'This transaction is not in a recoverable state.',
    ]);
    exit();
}

$paystack_secret_key = trim((string) dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY));
if ($paystack_secret_key === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Paystack is not configured.']);
    exit();
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
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Unable to reach Paystack right now.']);
    exit();
}

$paystack_result = json_decode($response, true);
if (!$paystack_result || !($paystack_result['status'] ?? false) || empty($paystack_result['data'])) {
    http_response_code($http_code >= 400 ? $http_code : 502);
    echo json_encode(['status' => 'error', 'message' => 'Paystack could not verify this reference right now.']);
    exit();
}

$gateway_data = $paystack_result['data'];
$gateway_status = strtolower(trim((string) ($gateway_data['status'] ?? '')));
$gateway_reference = trim((string) ($gateway_data['reference'] ?? ''));
$gateway_amount = round(((float) ($gateway_data['amount'] ?? 0)) / 100, 2);
$expected_amount = round((float) ($transaction['amount'] ?? 0), 2);

if ($gateway_reference === '' || strcasecmp($gateway_reference, $reference) !== 0) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Paystack returned a mismatched reference.']);
    exit();
}

if (function_exists('validatePaystackAmount')) {
    $validation = validatePaystackAmount($gateway_amount, $expected_amount);
    if (empty($validation['is_valid'])) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'Amount mismatch detected for this Paystack payment.',
        ]);
        exit();
    }
} elseif (abs($gateway_amount - $expected_amount) > 0.01) {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Amount mismatch detected for this Paystack payment.']);
    exit();
}

if ($gateway_status === 'success') {
    $metadata['return_to'] = $public_lookup_path;
    $metadata_json = json_encode($metadata);
    if ($metadata_json !== false) {
        $stmt = $db->prepare("UPDATE transactions SET status = 'pending', metadata = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $metadata_json, $transaction['id']);
        $stmt->execute();
        $stmt->close();
    } elseif ($transaction_status === 'failed') {
        $stmt = $db->prepare("UPDATE transactions SET status = 'pending', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $transaction['id']);
        $stmt->execute();
        $stmt->close();
    }
    
    require_once __DIR__ . '/../includes/api_providers.php';
    require_once __DIR__ . '/../includes/volume_converter.php';

    $runSafeSideEffect = static function ($label, callable $callback) {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Guest recovery side effect failed [' . $label . ']: ' . $e->getMessage());
        }
    };

    try {
        $db->getConnection()->begin_transaction();
        $recovery_stage = 'recovery_locked';

        $stmt = $db->prepare("
            SELECT id, user_id, amount, reference, metadata, order_id
            FROM transactions
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        if (!$stmt) {
            throw new Exception('Unable to lock the transaction for recovery.');
        }
        $stmt->bind_param('i', $transaction['id']);
        $stmt->execute();
        $locked_transaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$locked_transaction) {
            throw new Exception('The transaction could not be reloaded for recovery.');
        }

        $user_id = (int) ($locked_transaction['user_id'] ?? 0);
        $package_id = (int) ($metadata['package_id'] ?? 0);
        $agent_id = resolveActiveAgentId((int) ($metadata['agent_id'] ?? 0));
        if ($agent_id <= 0 && strtolower(trim((string) ($metadata['buyer_role'] ?? ''))) === 'agent' && $user_id > 0) {
            $agent_id = resolveActiveAgentId($user_id);
        }
        if ($agent_id <= 0 && $user_id > 0 && function_exists('getLinkedAgentId')) {
            $agent_id = resolveActiveAgentId((int) getLinkedAgentId($user_id));
        }
        $beneficiary_number = formatPhone((string) ($metadata['beneficiary_number'] ?? ''));
        $buyer_previous_balance = $user_id > 0 ? getWalletBalance($user_id) : null;
        $buyer_current_balance = $buyer_previous_balance;
        $order_id = (int) ($locked_transaction['order_id'] ?? 0);
        $order_reference = (string) ($locked_transaction['reference'] ?? $reference);
        $success_message = 'Payment verified successfully.';

        if ($package_id <= 0 || $beneficiary_number === '') {
            throw new Exception('Recovery metadata is incomplete for this payment.');
        }

        $stmt = $db->prepare('
            SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
                   COALESCE(n.name, "Unknown") AS network_name,
                   COALESCE(pp_customer.price, dp.price, 0) AS customer_price,
                   COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
                   acp.custom_price AS agent_custom_price
            FROM data_packages dp
            LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
            LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
            LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
            LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
            WHERE dp.id = ? AND dp.status = "active"
        ');
        if (!$stmt) {
            throw new Exception('Unable to load package details for recovery.');
        }
        $stmt->bind_param('ii', $agent_id, $package_id);
        $stmt->execute();
        $package = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$package) {
            throw new Exception('The selected package is no longer available for recovery.');
        }
        $recovery_stage = 'recovery_package_loaded';
        $metadata_agent_cost = isset($metadata['agent_cost']) ? (float) $metadata['agent_cost'] : 0.0;
        $order_agent_cost = $metadata_agent_cost > 0
            ? $metadata_agent_cost
            : (float) $package['agent_wholesale_price'];

        $order_created = false;
        if ($order_id <= 0) {
            // Check if order already exists to prevent duplicate insertion
            $check_stmt = $db->prepare('SELECT id FROM bundle_orders WHERE order_reference = ?');
            $check_stmt->bind_param('s', $order_reference);
            $check_stmt->execute();
            $check_res = $check_stmt->get_result();
            if ($check_res->num_rows > 0) {
                $existing_order = $check_res->fetch_assoc();
                $order_id = (int)$existing_order['id'];
                $order_created = false;
            } else {
                $bundle_orders_auto_increment = true;
                if (function_exists('dbh_ensure_auto_increment')) {
                    $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
                }

                if ($bundle_orders_auto_increment) {
                    $stmt = $db->prepare('
                        INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status, transaction_id, agent_id, agent_cost)
                        VALUES (NULLIF(?, 0), ?, ?, ?, ?, "processing", ?, NULLIF(?, 0), ?)
                    ');
                    if (!$stmt) {
                        throw new Exception('Unable to create the recovered order.');
                    }
                    $stmt->bind_param(
                        'iisdsiid',
                        $user_id,
                        $package_id,
                        $beneficiary_number,
                        $locked_transaction['amount'],
                        $order_reference,
                        $locked_transaction['id'],
                        $agent_id,
                        $order_agent_cost
                    );
                    $stmt->execute();
                    $order_id = $db->lastInsertId();
                    $stmt->close();
                } else {
                    $manual_order_id = dbh_generate_next_id('bundle_orders');
                    $stmt = $db->prepare('
                        INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, transaction_id, agent_id, agent_cost)
                        VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, "processing", ?, NULLIF(?, 0), ?)
                    ');
                    if (!$stmt) {
                        throw new Exception('Unable to create the recovered order.');
                    }
                    $stmt->bind_param(
                        'iiisdsiid',
                        $manual_order_id,
                        $user_id,
                        $package_id,
                        $beneficiary_number,
                        $locked_transaction['amount'],
                        $order_reference,
                        $locked_transaction['id'],
                        $agent_id,
                        $order_agent_cost
                    );
                    $stmt->execute();
                    $stmt->close();
                    $order_id = $manual_order_id;
                }

                $stmt = $db->prepare('UPDATE transactions SET order_id = ? WHERE id = ?');
                if (!$stmt) {
                    throw new Exception('Unable to link the recovered order to the transaction.');
                }
                $stmt->bind_param('ii', $order_id, $locked_transaction['id']);
                $stmt->execute();
                $stmt->close();
                $order_created = true;
            }
            $recovery_stage = 'recovery_order_created';
        }

        $volume_gb = extractVolumeGB($package['data_size']);
        $endpoint_type = detectEndpointTypeForPackage(
            $package['name'] ?? '',
            $package['data_size'] ?? '',
            $package['package_type'] ?? ''
        );
        $order_already_processing = false;
        $recovery_stage = 'recovery_order_dispatching';

        $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
        if (!$order_created && $order_id > 0) {
            // Already created and dispatched previously
            $api_result = [
                'success' => true,
                'error' => null,
                'response' => ['status' => 'success', 'message' => 'Already processed.']
            ];
        } elseif (!$availability['available']) {
            $api_result = [
                'success' => false,
                'error' => $availability['message']
            ];
        } else {
            try {
                $api_result = processBundlePurchase($order_id, $package['network_id'], $beneficiary_number, $volume_gb, $endpoint_type);
            } catch (Throwable $e) {
                $dispatch_error = trim((string) $e->getMessage());
                if ($dispatch_error !== '' && stripos($dispatch_error, 'already being processed') !== false) {
                    $order_already_processing = true;
                    $api_result = [
                        'success' => true,
                        'provider' => null,
                        'response' => [
                            'status' => 'processing',
                            'message' => $dispatch_error
                        ],
                        'error' => null,
                        'reference' => ''
                    ];
                } else {
                    $api_result = [
                        'success' => false,
                        'error' => $dispatch_error !== '' ? $dispatch_error : 'Data delivery failed.'
                    ];
                }
            }
        }

        if (!$api_result['success']) {
            $api_response_json = json_encode($api_result);
            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Unable to mark the recovered order as failed.');
            }
            $stmt->bind_param('si', $api_response_json, $order_id);
            $stmt->execute();
            $stmt->close();

            if ($user_id > 0) {
                $runSafeSideEffect('recovery_refund', static function () use ($user_id, $locked_transaction, $reference) {
                    updateWalletBalanceWithSMS($user_id, $locked_transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'paystack');
                });
                $success_message = 'Payment was confirmed, but delivery failed. Amount credited to your wallet.';
            } else {
                $success_message = 'Payment was confirmed, but delivery failed. Please contact support with your reference for assistance.';
            }
            if (!empty($api_result['error']) && stripos((string) $api_result['error'], 'Network is busy') !== false) {
                $success_message = 'Network is busy, validation is ongoing';
            }
            $recovery_stage = 'recovery_order_failed_refunded';
        } else {
            $api_response_json = json_encode($api_result);
            $provider_ref = (string) ($api_result['reference'] ?? '');
            $provider_data = $api_result['provider'] ?? [];
            $provider_name = strtolower(trim((string) ($provider_data['provider_name'] ?? '')));
            $provider_slug = strtolower(trim((string) ($provider_data['provider_slug'] ?? '')));
            $normalized_response = strtolower((string) $api_response_json);
            $is_hubnet_order = $provider_name === 'hubnet console'
                || strpos($provider_slug, 'hubnet') !== false
                || strpos($normalized_response, '"provider_slug":"hubnet"') !== false
                || strpos($normalized_response, '"provider_name":"hubnet console"') !== false;
            $order_status_for_notifications = 'delivered';

            if ($order_already_processing) {
                $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = COALESCE(processed_at, NOW()), updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('i', $order_id);
                $stmt->execute();
                $stmt->close();
                $order_status_for_notifications = 'processing';
            } elseif ($is_hubnet_order) {
                $hubnet_provider_status = strtolower(trim((string) (($api_result['response']['delivery_state'] ?? $api_result['response']['status'] ?? 'processing'))));
                if ($hubnet_provider_status === '' || $hubnet_provider_status === '1') {
                    $hubnet_provider_status = 'processing';
                }

                $internal_status = in_array($hubnet_provider_status, ['completed', 'delivered'], true) ? 'delivered' : 'processing';

                $stmt = $db->prepare("UPDATE bundle_orders SET status = ?, processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()" . ($internal_status === 'delivered' ? ", delivered_at = NOW()" : "") . " WHERE id = ?");
                $stmt->bind_param('ssssi', $internal_status, $api_response_json, $hubnet_provider_status, $provider_ref, $order_id);
                $stmt->execute();
                $stmt->close();
                $order_status_for_notifications = $internal_status;
            } else {
                $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssi', $api_response_json, $provider_ref, $order_id);
                $stmt->execute();
                $stmt->close();

                if (function_exists('applyMtnStatusPolicy')) {
                    $runSafeSideEffect('recovery_apply_mtn_status_policy', static function () use ($order_id) {
                        applyMtnStatusPolicy($order_id, 'processing');
                    });
                }
                $order_status_for_notifications = 'processing';
            }

            if ($agent_id > 0) {
                $agent_profit = (float) $locked_transaction['amount'] - (float) $order_agent_cost;
                if (function_exists('recordOrderProfit')) {
                    $runSafeSideEffect('recovery_record_order_profit', static function () use ($agent_id, $order_id, $user_id, $package_id, $locked_transaction, $order_agent_cost, $order_reference, $agent_profit) {
                        recordOrderProfit([
                            'agent_id' => $agent_id,
                            'order_id' => $order_id,
                            'customer_id' => $user_id > 0 ? $user_id : null,
                            'package_id' => $package_id,
                            'customer_paid' => (float) $locked_transaction['amount'],
                            'agent_cost' => (float) $order_agent_cost,
                            'profit_amount' => $agent_profit,
                            'reference' => $order_reference,
                            'status' => 'earned'
                        ]);
                    });
                }
            }

            $buyer_current_balance = $user_id > 0 ? getWalletBalance($user_id) : $buyer_previous_balance;

            $runSafeSideEffect('recovery_user_order_notification', static function () use ($order_reference, $order_id, $user_id, $metadata, $beneficiary_number, $package, $locked_transaction, $order_status_for_notifications, $buyer_previous_balance, $buyer_current_balance) {
                sendUserOrderNotification([
                    'order_type' => 'data',
                    'order_reference' => $order_reference,
                    'order_id' => $order_id,
                    'user_id' => $user_id,
                    'customer_name' => $metadata['buyer_name'] ?? '',
                    'customer_email' => $metadata['buyer_email'] ?? '',
                    'customer_role' => $metadata['buyer_role'] ?? '',
                    'beneficiary_number' => $beneficiary_number,
                    'network_name' => $package['network_name'] ?? '',
                    'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                    'amount' => (float) $locked_transaction['amount'],
                    'payment_method' => 'paystack',
                    'status' => $order_status_for_notifications,
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'source' => $bundle_purchase_type === 'customer_bundle_purchase' ? 'customer_paystack_recovery' : 'guest_paystack_recovery'
                ]);
            });

            $runSafeSideEffect('recovery_admin_data_order_notification', static function () use ($order_reference, $order_id, $user_id, $beneficiary_number, $package, $locked_transaction, $order_status_for_notifications, $buyer_previous_balance, $buyer_current_balance, $agent_id, $bundle_purchase_type) {
                sendAdminDataOrderNotification([
                    'order_reference' => $order_reference,
                    'order_id' => $order_id,
                    'user_id' => $user_id,
                    'beneficiary_number' => $beneficiary_number,
                    'network_name' => $package['network_name'] ?? '',
                    'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                    'amount' => (float) $locked_transaction['amount'],
                    'payment_method' => 'paystack',
                    'status' => $order_status_for_notifications,
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'agent_id' => $agent_id,
                    'source' => $bundle_purchase_type === 'customer_bundle_purchase' ? 'customer_paystack_recovery' : 'guest_paystack_recovery'
                ]);
            });

            $display_phone = (strlen($beneficiary_number) === 12 && substr($beneficiary_number, 0, 3) === '233')
                ? '0' . substr($beneficiary_number, 3)
                : $beneficiary_number;
            $success_message = $order_status_for_notifications === 'processing'
                ? 'Payment received. Your order is processing and will update automatically once it confirms delivery.'
                : buildBundleSuccessMessage($package['data_size'] ?? 'Bundle', $display_phone);
            $recovery_stage = 'recovery_order_completed';
        }

        $recovery_stage = 'recovery_transaction_saved';
        $stmt = $db->prepare("UPDATE transactions SET status = 'success', paystack_reference = ?, order_id = ?, metadata = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Unable to persist the recovered payment state.');
        }
        $paystack_reference = $gateway_reference !== '' ? $gateway_reference : $reference;
        $stmt->bind_param('sisi', $paystack_reference, $order_id, $metadata_json, $locked_transaction['id']);
        $stmt->execute();
        $stmt->close();

        $db->getConnection()->commit();

        echo json_encode([
            'status' => 'success',
            'transaction_status' => 'success',
            'message' => $success_message,
            'redirect_path' => $public_lookup_path,
        ]);
        exit();
    } catch (Throwable $e) {
        try {
            $db->getConnection()->rollback();
        } catch (Throwable $rollbackException) {
            error_log('Guest recovery rollback failed: ' . $rollbackException->getMessage());
        }

        error_log('Guest recovery finalization failed [' . $reference . '] stage=' . $recovery_stage . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => buildPaymentFinalizationDebugMessage(
                $reference,
                $recovery_stage,
                $e->getMessage(),
                'Payment was confirmed, but recovery stopped while'
            ),
        ]);
        exit();
    }
}

if (in_array($gateway_status, ['failed', 'abandoned', 'reversed'], true)) {
    $stmt = $db->prepare("UPDATE transactions SET status = 'failed', updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $transaction['id']);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'transaction_status' => 'failed',
        'message' => 'Paystack reported this transaction as unsuccessful.',
    ]);
    exit();
}

echo json_encode([
    'status' => 'success',
    'transaction_status' => 'pending',
    'message' => 'This payment is still pending on Paystack.',
]);
?>
