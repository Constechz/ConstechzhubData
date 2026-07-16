<?php
require_once '../config/config.php';
ensureResultCheckerTables();
ensureAfaRegistrationTables();

$reference = $_GET['reference'] ?? $_POST['reference'] ?? '';

if (empty($reference)) {
    http_response_code(400);
    redirectTopLevel(SITE_URL . '/index.php');
}

function moolre_is_success_status($status) {
    if (is_numeric($status)) {
        return (int) $status === 1;
    }
    $status = strtolower(trim((string) $status));
    if ($status === '') {
        return false;
    }
    $success_words = ['success', 'successful', 'completed', 'paid', 'approved'];
    foreach ($success_words as $word) {
        if (strpos($status, $word) !== false) {
            return true;
        }
    }
    return false;
}

// Fast path: if transaction already processed, skip gateway verification unless
// a guest/customer bundle still needs order finalization.
try {
    $stmt = $db->prepare("SELECT user_id, status, metadata, order_id, transaction_type FROM transactions WHERE reference = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $order_id = (int) ($row['order_id'] ?? 0);
            $metadata = [];
            if (!empty($row['metadata'])) {
                $decoded = json_decode((string) $row['metadata'], true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            $metadata_type = (string) ($metadata['type'] ?? '');
            $requires_order_finalization = in_array($metadata_type, ['guest_bundle_purchase', 'customer_bundle_purchase'], true)
                && $order_id <= 0;
            if ($status !== '' && $status !== 'pending' && !$requires_order_finalization) {
                $user_role = 'customer';
                $user_id = (int) ($row['user_id'] ?? 0);
                $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
                if ($user_id > 0) {
                    $roleStmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                    if ($roleStmt) {
                        $roleStmt->bind_param('i', $user_id);
                        $roleStmt->execute();
                        if ($roleRow = $roleStmt->get_result()->fetch_assoc()) {
                            $user_role = $roleRow['role'] ?? $user_role;
                        }
                    }
                }
                $user_role = strtolower(trim((string) $user_role));
                if (!empty($metadata['return_to']) && is_string($metadata['return_to']) && strpos($metadata['return_to'], '/') === 0) {
                    $redirectPath = $metadata['return_to'];
                } elseif (($row['transaction_type'] ?? '') === 'purchase') {
                    $redirectPath = '/customer/order-history.php';
                } else {
                    $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
                }
                safe_session_start();
                setFlashMessage('info', 'Transaction already processed.');
                redirectTopLevel(SITE_URL . $redirectPath);
            }
        }
    }
} catch (Exception $e) {
    // If the fast-path fails, fall back to full verification.
}

try {
    $config = getMoolreConfig();
    if (!isMoolreConfigured($config)) {
        throw new Exception('Moolre keys are not configured.');
    }

    $payload = [
        'type' => 1,
        'idtype' => 1,
        'id' => $reference,
        'accountnumber' => $config['account_number']
    ];

    $error = null;
    $result = moolrePostJson('https://api.moolre.com/open/transact/status', $payload, $config, $error);
    if (!$result) {
        throw new Exception($error ?: 'Failed to verify transaction with Moolre');
    }

    $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
    if (!$status_ok && empty($result['data'])) {
        throw new Exception($result['message'] ?? 'Failed to verify transaction with Moolre');
    }

    $gateway_data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $gateway_status = $gateway_data['status'] ?? $gateway_data['txstatus'] ?? ($result['txstatus'] ?? $result['status'] ?? null);

    if (!moolre_is_success_status($gateway_status)) {
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE reference = ?");
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $stmt = $db->prepare("UPDATE afa_registrations SET status = 'failed', updated_at = NOW() WHERE reference = ?");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $stmt->close();
        }

        $user_role = 'customer';
        $user_id = null;
        $stmt = $db->prepare("SELECT user_id FROM transactions WHERE reference = ? LIMIT 1");
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $user_id = (int) $row['user_id'];
        }
        if ($user_id) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $resRole = $stmt->get_result()->fetch_assoc();
        if ($resRole && !empty($resRole['role'])) { $user_role = $resRole['role']; }
        $user_role = strtolower(trim((string) $user_role));
        }
        safe_session_start();
        setFlashMessage('error', 'Payment was not successful');
        $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
        redirectTopLevel(SITE_URL . $redirectPath);
    }

    $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND status = 'pending'");
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $resultPending = $stmt->get_result();
    $transaction = $resultPending->fetch_assoc();

    if (!$transaction) {
        safe_session_start();
        setFlashMessage('info', 'Transaction already processed.');
        redirectTopLevel(SITE_URL . '/customer/wallet.php');
    }

    $paid_amount = (float) ($gateway_data['amount'] ?? $gateway_data['amount_paid'] ?? $gateway_data['paid_amount'] ?? 0);
    $currency = $gateway_data['currency'] ?? '';

    if ($currency && strcasecmp($currency, CURRENCY_CODE) !== 0) {
        throw new Exception('Currency mismatch during verification.');
    }

    if ($paid_amount > 0 && abs($paid_amount - (float) $transaction['amount']) > 0.01) {
        throw new Exception('Amount mismatch during verification.');
    }

    $gateway_reference = $gateway_data['transactid'] ?? $gateway_data['transaction_id'] ?? $gateway_data['reference'] ?? $reference;

    $db->getConnection()->begin_transaction();

    try {
        $metadata = [];
        if (!empty($transaction['metadata'])) {
            $decoded = json_decode($transaction['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $redirect_store_slug = $metadata['store_slug'] ?? null;
        $metadata_type = $metadata['type'] ?? '';
        $return_to = $metadata['return_to'] ?? null;
        $success_message = 'Payment successful!';

        if ($transaction['transaction_type'] === 'purchase' && $metadata_type === 'result_checker_purchase') {
            $user_id = (int) $transaction['user_id'];
            $card_type = strtoupper(trim((string) ($metadata['card_type'] ?? '')));
            $quantity = max(1, (int) ($metadata['quantity'] ?? 1));
            $agent_id = resolveActiveAgentId((int) ($metadata['agent_id'] ?? 0));
            if ($agent_id <= 0 && $user_id > 0 && function_exists('getLinkedAgentId')) {
                $agent_id = resolveActiveAgentId((int) getLinkedAgentId($user_id));
            }
            $admin_price = (float) ($metadata['admin_price'] ?? 0);
            $purchase_amount = (float) $transaction['amount'];
            $purchase_reference = $transaction['reference'];
            $sms_phone = $metadata['sms_phone'] ?? null;
            $notification_email = $metadata['notification_email'] ?? null;
            $buyer_previous_balance = $user_id > 0 ? getWalletBalance($user_id) : null;
            $buyer_current_balance = $buyer_previous_balance;

            if (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
                throw new Exception('Invalid result checker card type.');
            }

            $available_count = 0;
            $stmt = $db->prepare("
                SELECT COUNT(*) AS total_count
                FROM result_checker_cards
                WHERE card_type = ? AND status = 'available'
            ");
            if ($stmt) {
                $stmt->bind_param('s', $card_type);
                $stmt->execute();
                if ($row = $stmt->get_result()->fetch_assoc()) {
                    $available_count = (int) ($row['total_count'] ?? 0);
                }
                $stmt->close();
            }

            if ($available_count < $quantity) {
                updateWalletBalance($user_id, $purchase_amount, 'credit', $purchase_reference . '_REFUND', 'Refund: insufficient result checker stock');
                $ref_like = $purchase_reference . '-%';
                $stmt = $db->prepare("UPDATE result_checker_purchases SET status = 'failed' WHERE reference = ? OR reference LIKE ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $purchase_reference, $ref_like);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_message = 'Payment received but stock is insufficient. Wallet credited.';
            } else {
                if (!$sms_phone || !$notification_email) {
                    $stmt = $db->prepare("SELECT sms_phone, notification_email FROM result_checker_purchases WHERE reference = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('s', $purchase_reference);
                        $stmt->execute();
                        if ($row = $stmt->get_result()->fetch_assoc()) {
                            if (!$sms_phone) {
                                $sms_phone = $row['sms_phone'] ?? null;
                            }
                            if (!$notification_email) {
                                $notification_email = $row['notification_email'] ?? null;
                            }
                        }
                        $stmt->close();
                    }
                }

                $checker_link = '';
                $settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
                if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
                    $checker_link = $card_type === 'BECE'
                        ? ($settings_row['bece_checker_link'] ?? '')
                        : ($settings_row['wassce_checker_link'] ?? '');
                    if ($admin_price <= 0) {
                        $unit_admin_price = $card_type === 'BECE'
                            ? (float) ($settings_row['bece_price'] ?? 0)
                            : (float) ($settings_row['wassce_price'] ?? 0);
                        $admin_price = $unit_admin_price * $quantity;
                    }
                }

                $cards = [];
                for ($i = 0; $i < $quantity; $i++) {
                    $stmt = $db->prepare("
                        SELECT id, pin, serial_number
                        FROM result_checker_cards
                        WHERE card_type = ? AND status = 'available'
                        ORDER BY id ASC
                        LIMIT 1
                        FOR UPDATE
                    ");
                    if (!$stmt) {
                        throw new Exception('Card allocation failed.');
                    }
                    $stmt->bind_param('s', $card_type);
                    $stmt->execute();
                    $card = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$card) {
                        throw new Exception('Card allocation failed due to low stock.');
                    }

                    $stmt = $db->prepare("
                        UPDATE result_checker_cards
                        SET status = 'purchased', purchased_by = ?, purchased_at = NOW()
                        WHERE id = ? AND status = 'available'
                    ");
                    if (!$stmt) {
                        throw new Exception('Card allocation failed.');
                    }
                    $stmt->bind_param('ii', $user_id, $card['id']);
                    $stmt->execute();
                    $affected = (int) $stmt->affected_rows;
                    $stmt->close();

                    if ($affected <= 0) {
                        throw new Exception('Card allocation failed.');
                    }

                    $cards[] = $card;
                }

                $profit_amount = $agent_id > 0 ? max(0, $purchase_amount - $admin_price) : 0.0;
                $unit_amount = round($purchase_amount / $quantity, 2);
                $unit_admin = round($admin_price / $quantity, 2);
                $unit_profit = round($profit_amount / $quantity, 2);

                if ($quantity === 1) {
                    $card = $cards[0];
                    $stmt = $db->prepare("
                        UPDATE result_checker_purchases
                        SET agent_id = COALESCE(NULLIF(agent_id, 0), NULLIF(?, 0)),
                            status = 'success', card_id = ?, pin = ?, serial_number = ?, sms_phone = COALESCE(sms_phone, ?),
                            notification_email = COALESCE(notification_email, ?),
                            amount = ?, admin_price = ?, profit_amount = ?
                        WHERE reference = ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param(
                            'iissssddds',
                            $agent_id,
                            $card['id'],
                            $card['pin'],
                            $card['serial_number'],
                            $sms_phone,
                            $notification_email,
                            $purchase_amount,
                            $admin_price,
                            $profit_amount,
                            $purchase_reference
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    $stmt = $db->prepare("DELETE FROM result_checker_purchases WHERE reference = ? AND status = 'pending'");
                    if ($stmt) {
                        $stmt->bind_param('s', $purchase_reference);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $insertStmt = $db->prepare("
                        INSERT INTO result_checker_purchases
                            (user_id, agent_id, card_id, card_type, amount, admin_price, profit_amount, payment_gateway, reference, status, pin, serial_number, sms_phone, notification_email)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', ?, ?, ?, ?)
                    ");
                    if (!$insertStmt) {
                        throw new Exception('Failed to save purchase records.');
                    }

                    foreach ($cards as $idx => $card) {
                        $card_reference = $purchase_reference . '-' . ($idx + 1);
                        $row_amount = $idx === 0 ? round($purchase_amount - ($unit_amount * ($quantity - 1)), 2) : $unit_amount;
                        $row_admin = $idx === 0 ? round($admin_price - ($unit_admin * ($quantity - 1)), 2) : $unit_admin;
                        $row_profit = $idx === 0 ? round($profit_amount - ($unit_profit * ($quantity - 1)), 2) : $unit_profit;
                        $gateway_name = 'moolre';
                        $insertStmt->bind_param(
                            'iiisdddssssss',
                            $user_id,
                            $agent_id,
                            $card['id'],
                            $card_type,
                            $row_amount,
                            $row_admin,
                            $row_profit,
                            $gateway_name,
                            $card_reference,
                            $card['pin'],
                            $card['serial_number'],
                            $sms_phone,
                            $notification_email
                        );
                        if (!$insertStmt->execute()) {
                            $insertStmt->close();
                            throw new Exception('Failed to save purchase records.');
                        }
                    }
                    $insertStmt->close();
                }

                if ($sms_phone) {
                    foreach ($cards as $card) {
                        sendResultCheckerSms($sms_phone, $card_type, $card['pin'], $card['serial_number'], $checker_link, $user_id);
                    }
                }
                if ($notification_email) {
                    foreach ($cards as $card) {
                        sendResultCheckerEmail($notification_email, $card_type, $card['pin'], $card['serial_number'], $checker_link, $metadata['buyer_name'] ?? '');
                    }
                }

                $is_agent_self_order = strtolower(trim((string) ($metadata['buyer_role'] ?? ''))) === 'agent'
                    && $agent_id > 0
                    && $agent_id === $user_id;
                if ($is_agent_self_order && $profit_amount > 0 && function_exists('recordAgentCommission')) {
                    recordAgentCommission([
                        'agent_id' => $agent_id,
                        'source_type' => 'checker',
                        'source_reference' => (string) $purchase_reference,
                        'amount' => $profit_amount,
                        'quantity' => $quantity,
                        'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['checker_rate_per_card'] ?? 0) : null,
                        'notes' => $card_type . ' checker card' . ($quantity > 1 ? ' x' . $quantity : ''),
                    ]);
                } elseif ($agent_id > 0 && $profit_amount > 0) {
                    updateWalletBalance($agent_id, $profit_amount, 'credit', $purchase_reference, 'Result checker profit');
                    if (function_exists('sendAgentProfitNotification')) {
                        sendAgentProfitNotification([
                            'agent_id' => $agent_id,
                            'service' => 'Result Checker Purchase',
                            'reference' => $purchase_reference,
                            'customer_name' => $metadata['buyer_name'] ?? '',
                            'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                            'beneficiary_number' => $sms_phone,
                            'item' => $card_type . ($quantity > 1 ? ' x' . $quantity : ''),
                            'amount' => $purchase_amount,
                            'profit_amount' => $profit_amount,
                            'payment_method' => 'moolre',
                            'status' => 'success',
                        ]);
                    }
                }

                if ($agent_id > 0 && !$is_agent_self_order) {
                    if (!function_exists('sendAgentOrderNotification')) {
                        require_once __DIR__ . '/../includes/functions.php';
                    }
                    if (function_exists('sendAgentOrderNotification')) {
                        sendAgentOrderNotification([
                            'agent_id' => $agent_id,
                            'service' => 'Result Checker Purchase',
                            'reference' => $purchase_reference,
                            'customer_name' => $metadata['buyer_name'] ?? '',
                            'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                            'beneficiary_number' => $sms_phone,
                            'item' => $card_type . ($quantity > 1 ? ' x' . $quantity : ''),
                            'amount' => $purchase_amount,
                            'payment_method' => 'moolre',
                            'status' => 'success',
                        ]);
                    }
                }
                $buyer_current_balance = $user_id > 0 ? getWalletBalance($user_id) : $buyer_previous_balance;

                sendUserOrderNotification([
                    'order_type' => 'result_checker',
                    'reference' => $purchase_reference,
                    'user_id' => $user_id,
                    'buyer_name' => $metadata['buyer_name'] ?? '',
                    'buyer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                    'customer_name' => $metadata['buyer_name'] ?? '',
                    'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                    'buyer_role' => $metadata['buyer_role'] ?? '',
                    'card_type' => $card_type,
                    'quantity' => $quantity,
                    'amount' => $purchase_amount,
                    'payment_method' => 'moolre',
                    'status' => 'success',
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'source' => 'moolre_callback'
                ]);
                sendAdminResultCheckerOrderNotification([
                    'reference' => $purchase_reference,
                    'user_id' => $user_id,
                    'buyer_name' => $metadata['buyer_name'] ?? '',
                    'buyer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                    'card_type' => $card_type,
                    'quantity' => $quantity,
                    'amount' => $purchase_amount,
                    'admin_price' => $admin_price,
                    'profit_amount' => $profit_amount,
                    'payment_method' => 'moolre',
                    'status' => 'success',
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'agent_id' => $agent_id,
                    'source' => 'moolre_callback'
                ]);
                $success_message = $quantity > 1
                    ? "Payment successful! Your {$quantity} result checker cards are ready."
                    : 'Payment successful! Your result checker card is ready.';
            }
        }

        if ($transaction['transaction_type'] === 'purchase' && $metadata_type === 'afa_registration_purchase') {
            $user_id = (int) $transaction['user_id'];
            $purchase_reference = (string) ($transaction['reference'] ?? $reference);
            $agent_id = resolveActiveAgentId((int) ($metadata['agent_id'] ?? 0));
            if ($agent_id <= 0 && $user_id > 0 && function_exists('getLinkedAgentId')) {
                $agent_id = resolveActiveAgentId((int) getLinkedAgentId($user_id));
            }
            $purchase_amount = round((float) ($transaction['amount'] ?? 0), 2);
            $admin_price = round((float) ($metadata['admin_price'] ?? $purchase_amount), 2);
            $profit_amount = round((float) ($metadata['profit_amount'] ?? max(0, $purchase_amount - $admin_price)), 2);

            $beneficiary_name = trim((string) ($metadata['beneficiary_name'] ?? ''));
            $email = trim((string) ($metadata['email'] ?? ($metadata['buyer_email'] ?? '')));
            $phone = trim((string) ($metadata['phone'] ?? ''));
            $ghana_card_number = trim((string) ($metadata['ghana_card_number'] ?? ''));
            $ghana_card_front_image = trim((string) ($metadata['ghana_card_front_image'] ?? ''));
            $ghana_card_back_image = trim((string) ($metadata['ghana_card_back_image'] ?? ''));
            $location = trim((string) ($metadata['location'] ?? ''));
            $occupation = trim((string) ($metadata['occupation'] ?? ''));
            $region = trim((string) ($metadata['region'] ?? ''));
            $date_of_birth = trim((string) ($metadata['date_of_birth'] ?? ''));
            if ($phone !== '') {
                $phone = formatPhone($phone);
            }
            if ($date_of_birth === '') {
                $date_of_birth = null;
            }

            $agent_sql = $agent_id > 0 ? (string) $agent_id : 'NULL';
            $registration_exists = false;
            $exists_stmt = $db->prepare("SELECT id FROM afa_registrations WHERE reference = ? LIMIT 1");
            if ($exists_stmt) {
                $exists_stmt->bind_param('s', $purchase_reference);
                $exists_stmt->execute();
                $registration_exists = (bool) $exists_stmt->get_result()->fetch_assoc();
                $exists_stmt->close();
            }

            $update_sql = "
                UPDATE afa_registrations
                SET agent_id = COALESCE(NULLIF(agent_id, 0), {$agent_sql}),
                    status = 'processing',
                    processing_at = COALESCE(processing_at, NOW()),
                    payment_gateway = 'moolre',
                    amount = ?,
                    admin_price = ?,
                    profit_amount = ?,
                    beneficiary_name = COALESCE(NULLIF(beneficiary_name, ''), ?),
                    email = COALESCE(NULLIF(email, ''), ?),
                    phone = COALESCE(NULLIF(phone, ''), ?),
                    ghana_card_number = COALESCE(NULLIF(ghana_card_number, ''), ?),
                    ghana_card_front_image = COALESCE(NULLIF(ghana_card_front_image, ''), ?),
                    ghana_card_back_image = COALESCE(NULLIF(ghana_card_back_image, ''), ?),
                    location = COALESCE(NULLIF(location, ''), ?),
                    occupation = COALESCE(NULLIF(occupation, ''), ?),
                    region = COALESCE(NULLIF(region, ''), ?),
                    date_of_birth = COALESCE(date_of_birth, ?)
                WHERE reference = ?
            ";
            $stmt = $db->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param(
                    'dddsssssssssss',
                    $purchase_amount,
                    $admin_price,
                    $profit_amount,
                    $beneficiary_name,
                    $email,
                    $phone,
                    $ghana_card_number,
                    $ghana_card_front_image,
                    $ghana_card_back_image,
                    $location,
                    $occupation,
                    $region,
                    $date_of_birth,
                    $purchase_reference
                );
                $stmt->execute();
                $stmt->close();

                if (!$registration_exists) {
                    $insert_sql = "
                        INSERT INTO afa_registrations
                            (user_id, agent_id, beneficiary_name, email, phone, ghana_card_number, ghana_card_front_image, ghana_card_back_image, location, occupation, region, date_of_birth, amount, admin_price, profit_amount, payment_gateway, reference, status, processing_at)
                        VALUES (?, {$agent_sql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'moolre', ?, 'processing', NOW())
                    ";
                    $insert = $db->prepare($insert_sql);
                    if ($insert) {
                        $insert->bind_param(
                            'issssssssssddds',
                            $user_id,
                            $beneficiary_name,
                            $email,
                            $phone,
                            $ghana_card_number,
                            $ghana_card_front_image,
                            $ghana_card_back_image,
                            $location,
                            $occupation,
                            $region,
                            $date_of_birth,
                            $purchase_amount,
                            $admin_price,
                            $profit_amount,
                            $purchase_reference
                        );
                        $insert->execute();
                        $insert->close();
                    }
                }
            }

            $is_agent_self_order = strtolower(trim((string) ($metadata['buyer_role'] ?? ''))) === 'agent'
                && $agent_id > 0
                && $agent_id === $user_id;
            if ($is_agent_self_order && $profit_amount > 0 && function_exists('recordAgentCommission')) {
                recordAgentCommission([
                    'agent_id' => $agent_id,
                    'source_type' => 'afa',
                    'source_reference' => (string) $purchase_reference,
                    'amount' => $profit_amount,
                    'quantity' => 1,
                    'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['afa_rate_per_order'] ?? 0) : null,
                    'notes' => $beneficiary_name !== '' ? ('AFA registration for ' . $beneficiary_name) : 'AFA registration',
                ]);
            } elseif ($agent_id > 0 && $profit_amount > 0) {
                updateWalletBalance($agent_id, $profit_amount, 'credit', $purchase_reference, 'AFA registration profit');
                if (function_exists('sendAgentProfitNotification')) {
                    sendAgentProfitNotification([
                        'agent_id' => $agent_id,
                        'service' => 'AFA Registration',
                        'reference' => $purchase_reference,
                        'customer_name' => $metadata['buyer_name'] ?? '',
                        'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                        'beneficiary_number' => $phone,
                        'item' => $beneficiary_name !== '' ? $beneficiary_name : 'AFA registration',
                        'amount' => $purchase_amount,
                        'profit_amount' => $profit_amount,
                        'payment_method' => 'moolre',
                        'status' => 'processing',
                    ]);
                }
            }

            if ($agent_id > 0 && !$is_agent_self_order) {
                if (!function_exists('sendAgentOrderNotification')) {
                    require_once __DIR__ . '/../includes/functions.php';
                }
                if (function_exists('sendAgentOrderNotification')) {
                    sendAgentOrderNotification([
                        'agent_id' => $agent_id,
                        'service' => 'AFA Registration',
                        'reference' => $purchase_reference,
                        'customer_name' => $metadata['buyer_name'] ?? '',
                        'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                        'beneficiary_number' => $phone,
                        'item' => $beneficiary_name !== '' ? $beneficiary_name : 'AFA registration',
                        'amount' => $purchase_amount,
                        'payment_method' => 'moolre',
                        'status' => 'processing',
                    ]);
                }
            }

            if (function_exists('notifyAfaRegistrationSubmitted')) {
                notifyAfaRegistrationSubmitted($purchase_reference);
            }

            $success_message = 'Payment successful! AFA registration is now processing.';
        }

        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'success', paystack_reference = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $gateway_reference, $transaction['id']);
        $stmt->execute();

        if ($transaction['transaction_type'] === 'purchase' && in_array(($metadata['type'] ?? ''), ['guest_bundle_purchase', 'customer_bundle_purchase'], true)) {
            $user_id = (int) $transaction['user_id'];
            $package_id = (int) ($metadata['package_id'] ?? 0);
            $agent_id = (int) ($metadata['agent_id'] ?? 0);
            $beneficiary_number = $metadata['beneficiary_number'] ?? '';
            $beneficiary_number = $beneficiary_number ? formatPhone($beneficiary_number) : '';
            $buyer_previous_balance = $user_id > 0 ? getWalletBalance($user_id) : null;
            $buyer_current_balance = $buyer_previous_balance;

            if ($package_id && $beneficiary_number) {
                $order_id = (int) ($transaction['order_id'] ?? 0);
                $order_created = false;
                $order_reference = $transaction['reference'] ?? $reference;

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
                $stmt->bind_param('ii', $agent_id, $package_id);
                $stmt->execute();
                $package = $stmt->get_result()->fetch_assoc();
                $metadata_agent_cost = isset($metadata['agent_cost']) ? (float) $metadata['agent_cost'] : 0.0;
                $order_agent_cost = $package
                    ? ($metadata_agent_cost > 0 ? $metadata_agent_cost : (float) $package['agent_wholesale_price'])
                    : 0.0;

                    if ($package && $order_id <= 0) {
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
                            $stmt->bind_param(
                                'iisdsiid',
                                $user_id,
                                $package_id,
                                $beneficiary_number,
                                $transaction['amount'],
                                $order_reference,
                                $transaction['id'],
                                $agent_id,
                                $order_agent_cost
                            );
                            $stmt->execute();
                            $order_id = $db->lastInsertId();
                        } else {
                            $manual_order_id = dbh_generate_next_id('bundle_orders');
                            $stmt = $db->prepare('
                                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, transaction_id, agent_id, agent_cost)
                                VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, "processing", ?, NULLIF(?, 0), ?)
                            ');
                            $stmt->bind_param(
                                'iiisdsiid',
                                $manual_order_id,
                                $user_id,
                                $package_id,
                                $beneficiary_number,
                                $transaction['amount'],
                                $order_reference,
                                $transaction['id'],
                                $agent_id,
                                $order_agent_cost
                            );
                            $stmt->execute();
                            $order_id = $manual_order_id;
                        }
                        $stmt = $db->prepare('UPDATE transactions SET order_id = ? WHERE id = ?');
                        $stmt->bind_param('ii', $order_id, $transaction['id']);
                        $stmt->execute();
                        $order_created = true;
                    }
                }

                if ($package && $order_id > 0 && ($order_created ?? false)) {
                    $api_result = [];
                    $api_error = null;
                    require_once __DIR__ . '/../includes/api_providers.php';
                    $endpoint_type = detectEndpointTypeForPackage(
                        $package['name'] ?? '',
                        $package['data_size'] ?? '',
                        $package['package_type'] ?? ''
                    );
                    $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
                    if (!$availability['available']) {
                        $api_error = $availability['message'];
                        $api_result = ['success' => false, 'error' => $api_error];
                    } elseif (function_exists('sendDataBundle')) {
                        $api_result = sendDataBundle($user_id, $package_id, $beneficiary_number, $order_id, $agent_id, $api_error);
                    } else {
                        $api_error = 'Data delivery integration is not available.';
                    }

                    if (!empty($api_result['success'])) {
                        $api_response_json = json_encode($api_result);
                        $provider_ref = $api_result['reference'] ?? '';
                        $provider_data = $api_result['provider'] ?? [];
                        $provider_name = strtolower(trim((string) ($provider_data['provider_name'] ?? '')));
                        $provider_slug = strtolower(trim((string) ($provider_data['provider_slug'] ?? '')));
                        $normalized_response = strtolower((string) $api_response_json);
                        $is_hubnet_order = $provider_name === 'hubnet console'
                            || strpos($provider_slug, 'hubnet') !== false
                            || strpos($normalized_response, '"provider_slug":"hubnet"') !== false
                            || strpos($normalized_response, '"provider_name":"hubnet console"') !== false;
                        $is_datawax_order = $provider_name === 'datawax'
                            || strpos($provider_slug, 'datawax') !== false
                            || strpos($normalized_response, '"provider_slug":"datawax"') !== false
                            || strpos($normalized_response, '"provider_name":"datawax"') !== false;
                        $order_status_for_notifications = 'processing';

                        if ($is_hubnet_order || $is_datawax_order) {
                            $provider_status = strtolower(trim((string) (
                                $api_result['response']['delivery_state']
                                ?? $api_result['response']['wc_status']
                                ?? $api_result['response']['status_label']
                                ?? $api_result['response']['status']
                                ?? 'processing'
                            )));
                            if ($provider_status === '' || $provider_status === '1') {
                                $provider_status = 'processing';
                            }
                            $internal_status = in_array($provider_status, ['completed', 'delivered'], true) ? 'delivered' : 'processing';

                            $stmt = $db->prepare("UPDATE bundle_orders SET status = ?, processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()" . ($internal_status === 'delivered' ? ", delivered_at = NOW()" : "") . " WHERE id = ?");
                            $stmt->bind_param("ssssi", $internal_status, $api_response_json, $provider_status, $provider_ref, $order_id);
                            $stmt->execute();
                            $order_status_for_notifications = $internal_status;
                        } else {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                            $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                            $stmt->execute();

                            if (function_exists('applyMtnStatusPolicy')) {
                                applyMtnStatusPolicy($order_id, 'processing');
                            }
                            $order_status_for_notifications = 'processing';
                        }

                        $is_agent_bundle_self_order = strtolower(trim((string) ($metadata['buyer_role'] ?? ''))) === 'agent'
                            && $user_id > 0
                            && ($agent_id <= 0 || $agent_id === $user_id);
                        if ($is_agent_bundle_self_order && function_exists('recordAgentCommission')) {
                            $commission_amount = function_exists('calculateAgentDataCommissionAmount')
                                ? calculateAgentDataCommissionAmount($package['data_size'] ?? '', 1)
                                : 0.0;
                            if ($commission_amount > 0) {
                                recordAgentCommission([
                                    'agent_id' => $user_id,
                                    'source_type' => 'data',
                                    'source_id' => $order_id,
                                    'source_reference' => (string) $order_reference,
                                    'amount' => $commission_amount,
                                    'quantity' => 1,
                                    'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['data_rate_per_gb'] ?? 0) : null,
                                    'notes' => trim(($package['network_name'] ?? 'Data') . ' ' . ($package['data_size'] ?? 'bundle')),
                                ]);
                            }
                        } elseif ($agent_id > 0) {
                            $agent_profit = round(max(0, (float) $transaction['amount'] - (float) $order_agent_cost), 2);
                            if (function_exists('recordOrderProfit')) {
                                recordOrderProfit([
                                    'agent_id' => $agent_id,
                                    'order_id' => $order_id,
                                    'customer_id' => $user_id > 0 ? $user_id : null,
                                    'package_id' => $package_id,
                                    'customer_paid' => (float) $transaction['amount'],
                                    'agent_cost' => (float) $order_agent_cost,
                                    'profit_amount' => $agent_profit,
                                    'reference' => $order_reference,
                                    'status' => 'earned'
                                ]);
                            }
                            // sendAgentProfitNotification is now handled automatically within recordOrderProfit() in includes/analytics.php
                            /*
                            if (function_exists('sendAgentProfitNotification') && $agent_profit > 0) {
                                sendAgentProfitNotification([
                                    'agent_id' => $agent_id,
                                    'service' => 'Data Bundle Purchase',
                                    'reference' => $order_reference,
                                    'customer_name' => $metadata['buyer_name'] ?? '',
                                    'customer_email' => $metadata['buyer_email'] ?? ($metadata['email'] ?? ''),
                                    'beneficiary_number' => $beneficiary_number,
                                    'item' => trim(($package['network_name'] ?? 'Data') . ' ' . ($package['data_size'] ?? ($package['name'] ?? 'bundle'))),
                                    'amount' => (float) $transaction['amount'],
                                    'profit_amount' => $agent_profit,
                                    'payment_method' => 'moolre',
                                    'status' => $order_status_for_notifications,
                                ]);
                            }
                            */
                        }
                        $buyer_current_balance = $user_id > 0 ? getWalletBalance($user_id) : $buyer_previous_balance;

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
                            'amount' => (float) $transaction['amount'],
                            'payment_method' => 'moolre',
                            'status' => $order_status_for_notifications,
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'source' => 'guest_moolre_callback'
                        ]);

                        sendAdminDataOrderNotification([
                            'order_reference' => $order_reference,
                            'order_id' => $order_id,
                            'user_id' => $user_id,
                            'beneficiary_number' => $beneficiary_number,
                            'network_name' => $package['network_name'] ?? '',
                            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                            'amount' => (float) $transaction['amount'],
                            'payment_method' => 'moolre',
                            'status' => $order_status_for_notifications,
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'agent_id' => $agent_id,
                            'source' => 'guest_moolre_callback'
                        ]);

                        $display_phone = (strlen($beneficiary_number) == 12 && substr($beneficiary_number, 0, 3) == '233')
                            ? '0' . substr($beneficiary_number, 3)
                            : $beneficiary_number;
                        $success_message = buildBundleSuccessMessage($package['data_size'] ?? 'Bundle', $display_phone);
                    } else {
                        if ($order_created) {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result ?: ['error' => $api_error]);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();
                        }

                        if ($user_id > 0) {
                            updateWalletBalanceWithSMS($user_id, $transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'moolre');
                            $success_message = 'Payment received but delivery failed. Amount credited to your wallet.';
                        } else {
                            $success_message = 'Payment received but delivery failed. Please contact support with your reference for assistance.';
                        }
                        $busy_error = $api_error ?: ($api_result['error'] ?? '');
                        if ($busy_error !== '' && stripos($busy_error, 'Network is busy') !== false) {
                            $success_message = 'Network is busy, validation is ongoing';
                        }
                    }
                }
            }
        }

        if ($transaction['transaction_type'] === 'topup') {
            $success = updateWalletBalanceWithSMS(
                $transaction['user_id'],
                $transaction['amount'],
                'credit',
                $reference,
                'Wallet top-up via Moolre',
                'moolre'
            );

            if (!$success) {
                throw new Exception('Failed to update wallet balance');
            }
        }

        logActivity($transaction['user_id'], 'payment_success', "Moolre payment successful: {$reference}");

        $db->getConnection()->commit();

        $user_role = 'customer';
        if (!empty($transaction['user_id'])) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->bind_param("i", $transaction['user_id']);
            $stmt->execute();
            $resRole = $stmt->get_result()->fetch_assoc();
        if ($resRole && !empty($resRole['role'])) { $user_role = $resRole['role']; }
        $user_role = strtolower(trim((string) $user_role));
        }

        safe_session_start();
        if (!empty($transaction['user_id'])) {
            $stmt = $db->prepare("SELECT id, username, email, full_name, role FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $transaction['user_id']);
            $stmt->execute();
            if ($user = $stmt->get_result()->fetch_assoc()) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                setSessionUserRole($user['role']);
            }
        }

        if ($transaction['transaction_type'] === 'topup') {
            $success_message = 'Payment successful! Your wallet has been credited with ' . formatCurrency($transaction['amount']);
        }
        setFlashMessage('success', $success_message);

        $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
        if (!empty($return_to) && is_string($return_to) && strpos($return_to, '/') === 0) {
            $redirectPath = $return_to;
        } elseif ($transaction['transaction_type'] === 'purchase') {
            $redirectPath = '/customer/order-history.php';
        }
        if ($transaction['transaction_type'] === 'topup' && $user_role === 'agent') {
            $redirectPath = '/agent/dashboard.php';
        } elseif ($transaction['transaction_type'] === 'topup' && $metadata_type === 'customer_wallet_topup') {
            $redirectPath = '/customer/buy-data.php';
        }
        if (!empty($redirect_store_slug) && stripos($redirectPath, 'store=') === false) {
            $joiner = strpos($redirectPath, '?') !== false ? '&' : '?';
            $redirectPath .= $joiner . 'store=' . urlencode($redirect_store_slug);
        }
        redirectTopLevel(SITE_URL . $redirectPath);

    } catch (Exception $e) {
        $db->getConnection()->rollback();

        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->bind_param("i", $transaction['id']);
        $stmt->execute();

        throw $e;
    }

} catch (Exception $e) {
    error_log('Moolre callback error: ' . $e->getMessage());

    safe_session_start();
    setFlashMessage('error', 'An error occurred while processing the payment');
    $fallback = SITE_URL . '/customer/wallet.php';
    redirectTopLevel($fallback);
}
?>
