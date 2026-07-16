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
ensureResultCheckerTables();
ensureAfaRegistrationTables();
ensureProductOrderTables();

// Get the payment reference from the request
$reference = $_GET['reference'] ?? $_POST['reference'] ?? '';
$metadata = [];
$redirect_store_slug = null;
$return_to = null;
$bundle_checkout_type = '';
$resolved_order_id = 0;
$callback_stage = 'bootstrap';

$buildGuestCheckoutPath = static function ($storeSlug, $packageId = 0) {
    $storeSlug = trim((string) $storeSlug);
    if ($storeSlug === '') {
        return '/';
    }

    $path = '/store/guest-checkout.php?store=' . urlencode($storeSlug);
    if ((int) $packageId > 0) {
        $path .= '&package_id=' . (int) $packageId;
    }

    return $path;
};

$buildProductCheckoutPath = static function ($storeSlug, $productId = 0) {
    $storeSlug = trim((string) $storeSlug);
    if ($storeSlug === '') {
        return '/';
    }

    $path = '/store/product-checkout.php?store=' . urlencode($storeSlug);
    if ((int) $productId > 0) {
        $path .= '&product_id=' . (int) $productId;
    }

    return $path;
};

$buildProductReferencePath = static function ($storeSlug, $reference = '') {
    $storeSlug = trim((string) $storeSlug);
    if ($storeSlug === '') {
        return '/';
    }

    $path = '/store/product-reference.php?store=' . urlencode($storeSlug);
    $reference = trim((string) $reference);
    if ($reference !== '') {
        $path .= '&lookup=' . urlencode($reference);
    }

    return $path;
};

if (empty($reference)) {
    http_response_code(400);
    // Fallback redirect to dashboard if no reference
    header('Location: ' . SITE_URL . '/index.php');
    exit();
}

// Fast path: if transaction already processed, skip gateway verification.
try {
    $stmt = $db->prepare("SELECT user_id, status, transaction_type, metadata, order_id FROM transactions WHERE reference = ? LIMIT 1");
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
                if ($metadata_type === 'guest_bundle_purchase' && $status === 'failed') {
                    $redirectPath = $buildGuestCheckoutPath(
                        (string) ($metadata['store_slug'] ?? ''),
                        (int) ($metadata['package_id'] ?? 0)
                    );
                } elseif ($user_id > 0) {
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
                if ($metadata_type === 'guest_bundle_purchase' && $status === 'failed') {
                    safe_session_start();
                    setFlashMessage('warning', 'The previous payment was not completed. Please try again.');
                } elseif ($metadata_type === 'product_purchase' && $status === 'failed') {
                    safe_session_start();
                    setFlashMessage('warning', 'The previous product payment was not completed. Please try again.');
                    $redirectPath = $buildProductCheckoutPath(
                        (string) ($metadata['store_slug'] ?? ''),
                        (int) ($metadata['product_id'] ?? 0)
                    );
                } elseif (!empty($metadata['return_to']) && is_string($metadata['return_to']) && strpos($metadata['return_to'], '/') === 0) {
                    $redirectPath = $metadata['return_to'];
                } elseif (($row['transaction_type'] ?? '') === 'purchase') {
                    $redirectPath = '/customer/order-history.php';
                } else {
                    $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
                }
                if (!in_array($metadata_type, ['guest_bundle_purchase', 'product_purchase'], true) || $status !== 'failed') {
                    safe_session_start();
                    setFlashMessage('info', 'Transaction already processed.');
                }
                header('Location: ' . SITE_URL . $redirectPath);
                exit();
            }
        }
    }
} catch (Throwable $e) {
    // Fall through to full verification
}

try {
    // Verify the transaction with Paystack
    $curl = curl_init();
    
    $admin_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . $reference,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $admin_secret_key,
            "Cache-Control: no-cache",
        ),
    ));
    
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        throw new Exception("cURL Error: " . $err);
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !$result['status']) {
        throw new Exception("Failed to verify transaction with Paystack");
    }
    
    $transaction_data = $result['data'];

    // Get the transaction from our database (pending state)
    $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND status = 'pending'");
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $resultPending = $stmt->get_result();
    $transaction = $resultPending->fetch_assoc();

    if (!empty($transaction['metadata'])) {
        $decoded = json_decode((string) $transaction['metadata'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $redirect_store_slug = $metadata['store_slug'] ?? null;
    $return_to = $metadata['return_to'] ?? null;
    $bundle_checkout_type = (string) ($metadata['type'] ?? '');

    // Check if transaction was successful
    if ($transaction_data['status'] !== 'success') {
        // Update transaction status to failed
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE reference = ?");
        $stmt->bind_param("s", $reference);
        $stmt->execute();
        if (($metadata['type'] ?? '') === 'product_purchase') {
            $failedOrderId = (int) ($transaction['order_id'] ?? ($metadata['order_id'] ?? 0));
            if ($failedOrderId > 0) {
                $stmt = $db->prepare("
                    UPDATE product_orders
                    SET payment_status = 'failed',
                        order_status = 'payment_failed',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $failedOrderId);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        if (($metadata['type'] ?? '') === 'afa_registration_purchase') {
            $stmt = $db->prepare("UPDATE afa_registrations SET status = 'failed', updated_at = NOW() WHERE reference = ?");
            if ($stmt) {
                $stmt->bind_param("s", $reference);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Determine redirect based on user role
        $user_role = 'customer';
        if (!empty($transaction['user_id'])) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->bind_param("i", $transaction['user_id']);
            $stmt->execute();
            $resRole = $stmt->get_result()->fetch_assoc();
            if ($resRole && !empty($resRole['role'])) { $user_role = $resRole['role']; }
        }
        safe_session_start();
        setFlashMessage('warning', 'Payment was not completed. You can try again.');
        if ($bundle_checkout_type === 'guest_bundle_purchase') {
            $redirectPath = $buildGuestCheckoutPath(
                (string) ($redirect_store_slug ?? ''),
                (int) ($metadata['package_id'] ?? 0)
            );
        } elseif (($metadata['type'] ?? '') === 'product_purchase') {
            $redirectPath = $buildProductCheckoutPath(
                (string) ($metadata['store_slug'] ?? ''),
                (int) ($metadata['product_id'] ?? 0)
            );
        } else {
            $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
        }
        header('Location: ' . SITE_URL . $redirectPath);
        exit();
    }
    
    if (!$transaction) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Transaction not found or already processed'
        ]);
        exit();
    }
    
    // Dynamic Paystack fee validation using configuration system
    $paid_amount = $transaction_data['amount'] / 100; // Convert from kobo to naira/cedi
    $expected_amount = (float)$transaction['amount'];
    
    // Use the new dynamic validation system
    $validation = validatePaystackAmount($paid_amount, $expected_amount);
    
    // Log comprehensive analysis
    error_log("Paystack Dynamic Fee Analysis - Reference: {$reference}");
    error_log("Expected: {$expected_amount}, Paid: {$paid_amount}, Difference: {$validation['amount_difference']}");
    error_log("Fee Range - Min: {$validation['fee_range']['min_fee']}, Max: {$validation['fee_range']['max_fee']}, Estimated: {$validation['fee_range']['estimated_fee']}");
    error_log("Acceptable Range: {$validation['acceptable_range']['min']} - {$validation['acceptable_range']['max']}");
    error_log("Validation Result - Valid: " . ($validation['is_valid'] ? 'YES' : 'NO') . ", Exact: " . ($validation['is_exact_match'] ? 'YES' : 'NO') . ", With Fees: " . ($validation['is_with_fees'] ? 'YES' : 'NO'));
    
    // Reject payment if validation fails
    if (!$validation['is_valid']) {
        // Log detailed rejection information
        error_log("Payment REJECTED - Amount outside acceptable range");
        error_log("Rejection Details - Paid: {$paid_amount}, Expected: {$expected_amount}, Min: {$validation['acceptable_range']['min']}, Max: {$validation['acceptable_range']['max']}");
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Amount mismatch - payment amount outside acceptable range',
            'debug' => [
                'paid_amount' => $paid_amount,
                'expected_amount' => $expected_amount,
                'difference' => $validation['amount_difference'],
                'fee_analysis' => $validation['fee_range'],
                'acceptable_range' => $validation['acceptable_range'],
                'paystack_amount_kobo' => $transaction_data['amount'],
                'validation_result' => [
                    'is_valid' => $validation['is_valid'],
                    'exact_match' => $validation['is_exact_match'],
                    'within_fee_range' => $validation['is_with_fees']
                ]
            ]
        ]);
        exit();
    }
    
    // Log successful validation
    $validation_type = $validation['is_exact_match'] ? 'exact match' : 'with fees';
    error_log("Payment ACCEPTED ({$validation_type}) - Reference: {$reference}, Expected: {$expected_amount}, Paid: {$paid_amount}");
    
    $redirect_store_slug = $metadata['store_slug'] ?? null;
    $return_to = $metadata['return_to'] ?? null;
    $bundle_checkout_type = (string) ($metadata['type'] ?? '');
    $callback_stage = 'verified';

    $db->getConnection()->begin_transaction();
    
    try {
        $success_message = 'Payment successful!';
        $runSafeSideEffect = static function ($label, callable $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                error_log('Paystack callback side effect failed [' . $label . ']: ' . $e->getMessage());
            }
        };

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'result_checker_purchase') {
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
                        $gateway_name = 'paystack';
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
                            'payment_method' => 'paystack',
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
                            'payment_method' => 'paystack',
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
                    'payment_method' => 'paystack',
                    'status' => 'success',
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'source' => 'paystack_callback'
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
                    'payment_method' => 'paystack',
                    'status' => 'success',
                    'previous_balance' => $buyer_previous_balance,
                    'current_balance' => $buyer_current_balance,
                    'agent_id' => $agent_id,
                    'source' => 'paystack_callback'
                ]);

                $success_message = $quantity > 1
                    ? "Payment successful! Your {$quantity} result checker cards are ready."
                    : 'Payment successful! Your result checker card is ready.';
            }
        }

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'product_purchase') {
            $product_order_id = (int) ($transaction['order_id'] ?? ($metadata['order_id'] ?? 0));
            $resolved_order_id = $product_order_id;
            $paystack_reference = trim((string) ($transaction_data['reference'] ?? $reference));

            if ($product_order_id <= 0) {
                throw new Exception('Product order record is missing.');
            }

            $stmt = $db->prepare("
                UPDATE product_orders
                SET payment_status = 'paid',
                    order_status = CASE
                        WHEN order_status IN ('pending_payment', 'payment_failed') THEN 'processing'
                        ELSE order_status
                    END,
                    paystack_reference = ?,
                    payment_gateway = 'paystack',
                    updated_at = NOW()
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception('Unable to update product order payment status.');
            }
            $stmt->bind_param('si', $paystack_reference, $product_order_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Unable to save product order payment status.');
            }
            $stmt->close();

            $success_message = 'Your order has been received successfully';
        }

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'afa_registration_purchase') {
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
                    payment_gateway = 'paystack',
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
                        VALUES (?, {$agent_sql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paystack', ?, 'processing', NOW())
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
                        'payment_method' => 'paystack',
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
                        'payment_method' => 'paystack',
                        'status' => 'processing',
                    ]);
                }
            }

            if (function_exists('notifyAfaRegistrationSubmitted')) {
                notifyAfaRegistrationSubmitted($purchase_reference);
            }

            $success_message = 'Payment successful! AFA registration is now processing.';
        }

        $defer_transaction_success = $transaction['transaction_type'] === 'purchase'
            && in_array($bundle_checkout_type, ['guest_bundle_purchase', 'customer_bundle_purchase'], true);

        if (!$defer_transaction_success) {
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'success', paystack_reference = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception('Unable to persist verified Paystack transaction.');
            }
            $stmt->bind_param("si", $transaction_data['reference'], $transaction['id']);
            $stmt->execute();
        }

        // Handle direct Paystack bundle purchase for guests and logged-in customers
        if ($transaction['transaction_type'] === 'purchase' && in_array($bundle_checkout_type, ['guest_bundle_purchase', 'customer_bundle_purchase'], true)) {
            $callback_stage = 'guest_purchase_start';
            $user_id = (int) $transaction['user_id'];
            $package_id = (int) ($metadata['package_id'] ?? 0);
            $agent_id = resolveActiveAgentId((int) ($metadata['agent_id'] ?? 0));
            if ($agent_id <= 0 && strtolower(trim((string) ($metadata['buyer_role'] ?? ''))) === 'agent' && $user_id > 0) {
                $agent_id = resolveActiveAgentId($user_id);
            }
            if ($agent_id <= 0 && $user_id > 0 && function_exists('getLinkedAgentId')) {
                $agent_id = resolveActiveAgentId((int) getLinkedAgentId($user_id));
            }
            $beneficiary_number = $metadata['beneficiary_number'] ?? '';
            $beneficiary_number = $beneficiary_number ? formatPhone($beneficiary_number) : '';
            $buyer_previous_balance = $user_id > 0 ? getWalletBalance($user_id) : null;
            $buyer_current_balance = $buyer_previous_balance;
            $notification_source = $bundle_checkout_type === 'customer_bundle_purchase'
                ? 'customer_paystack_checkout'
                : 'guest_paystack_checkout';

            if ($package_id && $beneficiary_number) {
                $order_id = (int) ($transaction['order_id'] ?? 0);
                $order_created = false;
                $order_reference = $transaction['reference'] ?? $reference;
                $resolved_order_id = $order_id;

                // Fetch package + pricing
                $stmt = $db->prepare('
                    SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
                           COALESCE(n.name, "Unknown") AS network_name,
                           COALESCE(pp_customer.price, dp.price, 0) AS customer_price,
                           COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
                           COALESCE(pp_vip.price, dp.price, 0) AS vip_wholesale_price,
                           acp.custom_price AS agent_custom_price,
                           u_agent.role AS agent_role
                    FROM data_packages dp
                    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
                    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
                    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
                    LEFT JOIN package_pricing pp_vip ON pp_vip.package_id = dp.id AND pp_vip.user_type = "vip"
                    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
                    LEFT JOIN users u_agent ON u_agent.id = ?
                    WHERE dp.id = ? AND dp.status = "active"
                ');
                $stmt->bind_param('iii', $agent_id, $agent_id, $package_id);
                $stmt->execute();
                $package = $stmt->get_result()->fetch_assoc();
                $metadata_agent_cost = isset($metadata['agent_cost']) ? (float) $metadata['agent_cost'] : 0.0;
                
                $resolved_wholesale_price = 0.0;
                if ($package) {
                    if (($package['agent_role'] ?? '') === 'vip') {
                        $resolved_wholesale_price = (float) $package['vip_wholesale_price'];
                    } else {
                        $resolved_wholesale_price = (float) $package['agent_wholesale_price'];
                    }
                }
                
                $order_agent_cost = $package
                    ? ($metadata_agent_cost > 0 ? $metadata_agent_cost : $resolved_wholesale_price)
                    : 0.0;

                if ($package && $order_id <= 0) {
                    $order_reference = $transaction['reference'] ?? $reference;
                    
                    // Check if order already exists to prevent duplicate insertion
                    $check_stmt = $db->prepare('SELECT id FROM bundle_orders WHERE order_reference = ?');
                    $check_stmt->bind_param('s', $order_reference);
                    $check_stmt->execute();
                    $check_res = $check_stmt->get_result();
                    if ($check_res->num_rows > 0) {
                        $existing_order = $check_res->fetch_assoc();
                        $order_id = (int)$existing_order['id'];
                        $resolved_order_id = $order_id;
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
                        $resolved_order_id = $order_id;
                        $callback_stage = 'guest_order_created';
                    }
                }

                if ($package && $order_id > 0 && ($order_created ?? false)) {
                    $resolved_order_id = $order_id;
                    $callback_stage = 'guest_order_dispatching';
                    require_once __DIR__ . '/../includes/api_providers.php';
                    require_once __DIR__ . '/../includes/volume_converter.php';

                    $volume_gb = extractVolumeGB($package['data_size']);
                    $order_already_processing = false;
                    $endpoint_type = detectEndpointTypeForPackage(
                        $package['name'] ?? '',
                        $package['data_size'] ?? '',
                        $package['package_type'] ?? ''
                    );

                    $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
                    if (!$availability['available']) {
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
                        $error_message = trim((string)($api_result['error'] ?? ''));
                        $is_transitional = isValidationOngoingResponse($error_message, $api_result['response'] ?? null);

                        if ($is_transitional) {
                            // transitional failure (e.g. "Network is busy, validation is ongoing")
                            // keep as processing
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, updated_at = NOW() WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();
                            $success_message = 'Payment received. ' . ($error_message ?: 'Order is being processed.');
                            $callback_stage = 'guest_order_processing_transitional';
                        } else {
                            // definitive failure
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();

                            if ($user_id > 0) {
                                $runSafeSideEffect('guest_order_refund', static function () use ($user_id, $transaction, $reference) {
                                    updateWalletBalanceWithSMS($user_id, $transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'paystack');
                                });
                                $success_message = 'Payment received but delivery failed. Amount credited to your wallet.';
                                $callback_stage = 'guest_order_failed_refunded';
                            } else {
                                $success_message = 'Payment received but delivery failed. Please contact support with your reference for assistance.';
                                $callback_stage = 'guest_order_failed';
                            }
                        }
                    } else {
                        $api_response_json = json_encode($api_result);
                        $provider_ref = $api_result['reference'] ?? '';
                        $provider_data = $api_result['provider'] ?? [];
                        $provider_name = strtolower(trim((string) ($provider_data['provider_name'] ?? '')));
                        $provider_slug = strtolower(trim((string) ($provider_data['provider_slug'] ?? '')));
                        $normalized_response = strtolower((string) $api_response_json);
                        $is_hubnet_order = $provider_name === 'hubnet console'
                            || $provider_slug === 'hubnet'
                            || strpos($provider_slug, 'hubnet') !== false
                            || strpos($normalized_response, '"provider_slug":"hubnet"') !== false
                            || strpos($normalized_response, '"provider_name":"hubnet console"') !== false;
                        $is_datawax_order = $provider_name === 'datawax'
                            || $provider_slug === 'datawax'
                            || strpos($provider_slug, 'datawax') !== false
                            || strpos($normalized_response, '"provider_slug":"datawax"') !== false
                            || strpos($normalized_response, '"provider_name":"datawax"') !== false;
                        $order_status_for_notifications = 'processing';

                        if ($order_already_processing) {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = COALESCE(processed_at, NOW()), updated_at = NOW() WHERE id = ?");
                            $stmt->bind_param("i", $order_id);
                            $stmt->execute();
                            $order_status_for_notifications = 'processing';
                        } elseif ($is_hubnet_order || $is_datawax_order) {
                            $provider_status = strtolower(trim((string) (
                                $api_result['response']['delivery_state']
                                ?? $api_result['response']['wc_status']
                                ?? $api_result['response']['status_label']
                                ?? $api_result['response']['status']
                                ?? 'processing'
                            )));
                            
                            // Hubnet returns true/false for status. 
                            // '1' (true) means "Accepted", NOT "Delivered".
                            if ($provider_status === '' || $provider_status === '1' || $provider_status === 'true') {
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
                                $runSafeSideEffect('apply_mtn_status_policy', static function () use ($order_id) {
                                    applyMtnStatusPolicy($order_id, 'processing');
                                });
                            }
                            $order_status_for_notifications = 'processing';
                        }

                        $buyer_role = strtolower(trim((string) ($metadata['buyer_role'] ?? '')));
                        $is_agent_bundle_self_order = ($buyer_role === 'agent' || $buyer_role === 'vip')
                            && $user_id > 0
                            && ($agent_id <= 0 || $agent_id === $user_id);
                        if ($is_agent_bundle_self_order && function_exists('recordAgentCommission')) {
                            $commission_amount = function_exists('calculateAgentDataCommissionAmount')
                                ? calculateAgentDataCommissionAmount($package['data_size'] ?? '', 1)
                                : 0.0;
                            if ($commission_amount > 0) {
                                $runSafeSideEffect('record_agent_data_commission', static function () use ($user_id, $order_id, $order_reference, $commission_amount, $package) {
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
                                });
                            }
                        } elseif ($agent_id > 0) {
                            $agent_profit = round(max(0, (float) $transaction['amount'] - (float) $order_agent_cost), 2);
                            if (function_exists('recordOrderProfit')) {
                                $runSafeSideEffect('record_order_profit', static function () use ($agent_id, $order_id, $user_id, $package_id, $transaction, $order_agent_cost, $order_reference, $agent_profit) {
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
                                });
                            }
                            // sendAgentProfitNotification is now handled automatically within recordOrderProfit() in includes/analytics.php
                            /*
                            if (function_exists('sendAgentProfitNotification') && $agent_profit > 0) {
                                $runSafeSideEffect('agent_data_profit_notification', static function () use ($agent_id, $order_reference, $metadata, $beneficiary_number, $package, $transaction, $agent_profit, $order_status_for_notifications) {
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
                                        'payment_method' => 'paystack',
                                        'status' => $order_status_for_notifications,
                                    ]);
                                });
                            }
                            */
                        }
                        $buyer_current_balance = $user_id > 0 ? getWalletBalance($user_id) : $buyer_previous_balance;

                        $runSafeSideEffect('user_order_notification', static function () use ($order_reference, $order_id, $user_id, $metadata, $beneficiary_number, $package, $transaction, $order_status_for_notifications, $buyer_previous_balance, $buyer_current_balance, $notification_source) {
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
                                'payment_method' => 'paystack',
                                'status' => $order_status_for_notifications,
                                'previous_balance' => $buyer_previous_balance,
                                'current_balance' => $buyer_current_balance,
                                'source' => $notification_source
                            ]);
                        });

                        $runSafeSideEffect('admin_data_order_notification', static function () use ($order_reference, $order_id, $user_id, $beneficiary_number, $package, $transaction, $order_status_for_notifications, $buyer_previous_balance, $buyer_current_balance, $agent_id, $notification_source) {
                            sendAdminDataOrderNotification([
                                'order_reference' => $order_reference,
                                'order_id' => $order_id,
                                'user_id' => $user_id,
                                'beneficiary_number' => $beneficiary_number,
                                'network_name' => $package['network_name'] ?? '',
                                'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                                'amount' => (float) $transaction['amount'],
                                'payment_method' => 'paystack',
                                'status' => $order_status_for_notifications,
                                'previous_balance' => $buyer_previous_balance,
                                'current_balance' => $buyer_current_balance,
                                'agent_id' => $agent_id,
                                'source' => $notification_source
                            ]);
                        });

                        $display_phone = (strlen($beneficiary_number) == 12 && substr($beneficiary_number, 0, 3) == '233')
                            ? '0' . substr($beneficiary_number, 3)
                            : $beneficiary_number;
                        $success_message = buildBundleSuccessMessage($package['data_size'] ?? 'Bundle', $display_phone);
                        $callback_stage = 'guest_order_completed';
                    }
                }
            }

            if ($resolved_order_id > 0 || !empty($success_message)) {
                $stmt = $db->prepare("
                    UPDATE transactions 
                    SET status = 'success', paystack_reference = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                if (!$stmt) {
                    throw new Exception('Unable to persist finalized guest Paystack transaction.');
                }
                $stmt->bind_param("si", $transaction_data['reference'], $transaction['id']);
                $stmt->execute();
            }
        }

        // Credit user's wallet if this is a top-up
        if ($transaction['transaction_type'] === 'topup') {
            $conn = $db->getConnection();
            
            $topup_success = updateWalletBalanceWithSMS($transaction['user_id'], $transaction['amount'], 'credit', $reference, 'Wallet topup via Paystack', 'paystack');
            if (!$topup_success) {
                throw new Exception("Failed to update wallet balance");
            }
            
            $stmt = $db->prepare("
                UPDATE transactions 
                SET status = 'success', paystack_reference = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param("si", $transaction_data['reference'], $transaction['id']);
                $stmt->execute();
            }
        }
        
        // Log activity
        logActivity($transaction['user_id'], 'payment_success', "Paystack payment successful: {$reference}");
        $callback_stage = 'before_commit';
        
        $db->getConnection()->commit();
        $callback_stage = 'committed';
        
        // Determine redirect based on user role
        $user_role = 'customer';
        if (!empty($transaction['user_id'])) {
            $runSafeSideEffect('redirect_role_lookup', function () use ($db, $transaction, &$user_role) {
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                if (!$stmt) {
                    throw new Exception('Redirect role lookup prepare failed.');
                }
                $stmt->bind_param("i", $transaction['user_id']);
                $stmt->execute();
                $resRole = $stmt->get_result()->fetch_assoc();
                if ($resRole && !empty($resRole['role'])) {
                    $user_role = $resRole['role'];
                }
            });
        }
        // Set success message for redirect
        safe_session_start();
        if (!empty($transaction['user_id'])) {
            $runSafeSideEffect('session_user_hydration', function () use ($db, $transaction) {
                $stmt = $db->prepare("SELECT id, username, email, full_name, role FROM users WHERE id = ? LIMIT 1");
                if (!$stmt) {
                    throw new Exception('User session hydration prepare failed.');
                }
                $stmt->bind_param("i", $transaction['user_id']);
                $stmt->execute();
                if ($user = $stmt->get_result()->fetch_assoc()) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'];
                    setSessionUserRole($user['role']);
                }
            });
        }

        if ($transaction['transaction_type'] === 'topup') {
            $success_message = 'Payment successful! Your wallet has been credited with ' . formatCurrency($transaction['amount']);
        }
        setFlashMessage('success', $success_message);

        $redirectPath = ($user_role === 'agent' ? '/agent/wallet.php' : ($user_role === 'vip' ? '/vip/wallet.php' : '/customer/wallet.php'));
        if (!empty($return_to) && is_string($return_to) && strpos($return_to, '/') === 0) {
            $redirectPath = $return_to;
        } elseif ($transaction['transaction_type'] === 'purchase') {
            if ($bundle_checkout_type === 'guest_bundle_purchase' && !empty($redirect_store_slug)) {
                $redirectPath = '/store/reference.php?store=' . urlencode((string)$redirect_store_slug) . '&lookup=' . urlencode($reference);
            } else {
                $redirectPath = '/customer/order-history.php';
            }
        }
        if (!empty($redirect_store_slug) && stripos($redirectPath, 'store=') === false) {
            $joiner = strpos($redirectPath, '?') !== false ? '&' : '?';
            $redirectPath .= $joiner . 'store=' . urlencode($redirect_store_slug);
        }
        header('Location: ' . SITE_URL . $redirectPath);
        exit();
        
    } catch (Throwable $e) {
        try {
            $db->getConnection()->rollback();
        } catch (Throwable $rollbackException) {
            error_log('Paystack callback rollback failed: ' . $rollbackException->getMessage());
        }

        $callback_error = trim((string) $e->getMessage());
        if (!empty($transaction['id'])) {
            try {
                $retry_metadata = is_array($metadata) ? $metadata : [];
                if ($callback_error !== '') {
                    $retry_metadata['callback_error'] = $callback_error;
                }
                $retry_metadata['callback_stage'] = $callback_stage;
                $retry_metadata_json = json_encode($retry_metadata);
                if ($retry_metadata_json !== false) {
                    $stmt = $db->prepare("UPDATE transactions SET status = 'pending', metadata = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("si", $retry_metadata_json, $transaction['id']);
                } else {
                    $stmt = $db->prepare("UPDATE transactions SET status = 'pending', updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $transaction['id']);
                }
                $stmt->execute();
            } catch (Throwable $persistException) {
                error_log('Paystack callback retry-state persistence failed: ' . $persistException->getMessage());
            }
        }

        throw $e;
    }
    
} catch (Throwable $e) {
    error_log("Paystack callback error [{$reference}] stage={$callback_stage} order_id={$resolved_order_id}: " . $e->getMessage());
    
    // On error, keep the transaction recoverable and send the user back to the most relevant page.
    safe_session_start();
    $debug_message = buildPaymentFinalizationDebugMessage($reference, $callback_stage, $e->getMessage());
    $public_lookup_path = null;
    if (!empty($redirect_store_slug)) {
        if ($bundle_checkout_type === 'product_purchase') {
            $public_lookup_path = $buildProductReferencePath((string) $redirect_store_slug, $reference);
        } else {
            $public_lookup_path = '/store/reference.php?store=' . urlencode((string) $redirect_store_slug) . '&lookup=' . urlencode($reference);
        }
    }
    if ($bundle_checkout_type === 'guest_bundle_purchase' && $resolved_order_id > 0 && $public_lookup_path) {
        setFlashMessage('info', 'Payment was confirmed. Your order record was created; check the order status below.');
        header('Location: ' . SITE_URL . $public_lookup_path);
        exit();
    }
    if ($bundle_checkout_type === 'product_purchase' && $resolved_order_id > 0 && $public_lookup_path) {
        setFlashMessage('info', 'Payment was confirmed. Your product order is available below.');
        header('Location: ' . SITE_URL . $public_lookup_path);
        exit();
    }
    setFlashMessage('error', $debug_message . ' Use Verify Missing Payment if the order does not appear.');
    $fallback = SITE_URL . '/customer/wallet.php';
    if ($bundle_checkout_type === 'guest_bundle_purchase' && !empty($redirect_store_slug)) {
        $fallback = SITE_URL . '/store/guest-checkout.php?store=' . urlencode((string) $redirect_store_slug);
    } elseif ($bundle_checkout_type === 'product_purchase' && !empty($redirect_store_slug)) {
        $fallback = SITE_URL . $buildProductCheckoutPath((string) $redirect_store_slug, (int) ($metadata['product_id'] ?? 0));
    } elseif (!empty($return_to) && is_string($return_to) && strpos($return_to, '/') === 0) {
        $fallback = SITE_URL . $return_to;
    } elseif (!empty($redirect_store_slug)) {
        $fallback = SITE_URL . '/store/guest-checkout.php?store=' . urlencode((string) $redirect_store_slug);
    }
    header('Location: ' . $fallback);
    exit();
}
?>
