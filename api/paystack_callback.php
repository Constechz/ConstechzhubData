<?php
require_once '../config/config.php';
require_once '../includes/paystack_fees.php';
ensureResultCheckerTables();

function safe_session_start() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

// Get the payment reference from the request
$reference = $_GET['reference'] ?? $_POST['reference'] ?? '';

if (empty($reference)) {
    http_response_code(400);
    // Fallback redirect to dashboard if no reference
    header('Location: ' . SITE_URL . '/index.php');
    exit();
}

// Fast path: if transaction already processed, skip gateway verification.
try {
    $stmt = $db->prepare("SELECT user_id, status FROM transactions WHERE reference = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($status !== '' && $status !== 'pending') {
                $user_role = 'customer';
                $user_id = (int) ($row['user_id'] ?? 0);
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
                safe_session_start();
                setFlashMessage('info', 'Transaction already processed.');
                $redirectPath = $user_role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
                header('Location: ' . SITE_URL . $redirectPath);
                exit();
            }
        }
    }
} catch (Exception $e) {
    // Fall through to full verification
}

try {
    // Verify the transaction with Paystack
    $curl = curl_init();
    
    $isInvalidPaystackKey = function ($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return true;
        }
        if (stripos($key, 'your_secret_key_here') !== false) {
            return true;
        }
        return !preg_match('/^sk_(test|live)_/i', $key);
    };

    $admin_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
    if ($isInvalidPaystackKey($admin_secret_key)) {
        $admin_secret_key = PAYSTACK_SECRET_KEY;
    }

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

    // Check if transaction was successful
    if ($transaction_data['status'] !== 'success') {
        // Update transaction status to failed
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE reference = ?");
        $stmt->bind_param("s", $reference);
        $stmt->execute();
        
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
        setFlashMessage('error', 'Payment was not successful');
        $redirectPath = $user_role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
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

    // Claim this transaction exactly once before processing.
    $stmt = $db->prepare("
        UPDATE transactions
        SET status = 'processing', updated_at = NOW()
        WHERE reference = ? AND status = 'pending'
    ");
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    if ((int) $stmt->affected_rows < 1) {
        $user_role = 'customer';
        if (!empty($transaction['user_id'])) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $transaction['user_id']);
            $stmt->execute();
            $resRole = $stmt->get_result()->fetch_assoc();
            if ($resRole && !empty($resRole['role'])) {
                $user_role = $resRole['role'];
            }
        }
        safe_session_start();
        setFlashMessage('info', 'Transaction already processed.');
        $redirectPath = strtolower(trim((string) $user_role)) === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
        header('Location: ' . SITE_URL . $redirectPath);
        exit();
    }

    $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? LIMIT 1");
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    if (!$transaction) {
        throw new Exception('Transaction not found after lock.');
    }
    
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
        $return_to = $metadata['return_to'] ?? null;
        $success_message = 'Payment successful!';

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'result_checker_purchase') {
            $user_id = (int) $transaction['user_id'];
            $card_type = strtoupper(trim((string) ($metadata['card_type'] ?? '')));
            $agent_id = (int) ($metadata['agent_id'] ?? 0);
            $admin_price = (float) ($metadata['admin_price'] ?? 0);
            $purchase_amount = (float) $transaction['amount'];
            $purchase_reference = $transaction['reference'];
            $sms_phone = $metadata['sms_phone'] ?? null;
            $notification_email = $metadata['notification_email'] ?? null;

            if (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
                throw new Exception('Invalid result checker card type.');
            }

            $stmt = $db->prepare("
                SELECT id, pin, serial_number
                FROM result_checker_cards
                WHERE card_type = ? AND status = 'available'
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->bind_param('s', $card_type);
            $stmt->execute();
            $card = $stmt->get_result()->fetch_assoc();

            if (!$card) {
                // Refund to wallet if no card available
                updateWalletBalance($user_id, $purchase_amount, 'credit', $purchase_reference . '_REFUND', 'Refund: result checker card out of stock');
                $stmt = $db->prepare("UPDATE result_checker_purchases SET status = 'failed' WHERE reference = ?");
                $stmt->bind_param('s', $purchase_reference);
                $stmt->execute();
                $success_message = 'Payment received but card is out of stock. Wallet credited.';
            } else {
                $stmt = $db->prepare("
                    UPDATE result_checker_cards
                    SET status = 'purchased', purchased_by = ?, purchased_at = NOW()
                    WHERE id = ? AND status = 'available'
                ");
                $stmt->bind_param('ii', $user_id, $card['id']);
                $stmt->execute();

                if (!$sms_phone || !$notification_email) {
                    $stmt = $db->prepare("SELECT sms_phone, notification_email FROM result_checker_purchases WHERE reference = ? LIMIT 1");
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
                }

                $checker_link = '';
                $settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
                if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
                    $checker_link = $card_type === 'BECE'
                        ? ($settings_row['bece_checker_link'] ?? '')
                        : ($settings_row['wassce_checker_link'] ?? '');
                    if ($admin_price <= 0) {
                        $admin_price = $card_type === 'BECE'
                            ? (float) ($settings_row['bece_price'] ?? 0)
                            : (float) ($settings_row['wassce_price'] ?? 0);
                    }
                }

                $profit_amount = $agent_id > 0 ? max(0, $purchase_amount - $admin_price) : 0.0;

                $stmt = $db->prepare("
                    UPDATE result_checker_purchases
                    SET status = 'success', card_id = ?, pin = ?, serial_number = ?, sms_phone = COALESCE(sms_phone, ?),
                        notification_email = COALESCE(notification_email, ?),
                        admin_price = ?, profit_amount = ?
                    WHERE reference = ?
                ");
                $stmt->bind_param('issssdds', $card['id'], $card['pin'], $card['serial_number'], $sms_phone, $notification_email, $admin_price, $profit_amount, $purchase_reference);
                $stmt->execute();

                if ($sms_phone) {
                    sendResultCheckerSms($sms_phone, $card_type, $card['pin'], $card['serial_number'], $checker_link, $user_id);
                }
                if ($notification_email) {
                    sendResultCheckerEmail($notification_email, $card_type, $card['pin'], $card['serial_number'], $checker_link, $metadata['buyer_name'] ?? '');
                }

                if ($agent_id > 0) {
                    if ($profit_amount > 0) {
                        updateWalletBalance($agent_id, $profit_amount, 'credit', $purchase_reference, 'Result checker profit');
                    }
                }

                sendAdminResultCheckerOrderNotification([
                    'reference' => $purchase_reference,
                    'user_id' => $user_id,
                    'buyer_name' => $metadata['buyer_name'] ?? '',
                    'card_type' => $card_type,
                    'amount' => $purchase_amount,
                    'admin_price' => $admin_price,
                    'profit_amount' => $profit_amount,
                    'payment_method' => 'paystack',
                    'status' => 'success',
                    'agent_id' => $agent_id,
                    'source' => 'paystack_callback'
                ]);

                $success_message = 'Payment successful! Your result checker card is ready.';
            }
        } elseif ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'afa_registration_purchase') {
            $purchase_reference = $transaction['reference'];
            $purchase_amount = (float) $transaction['amount'];
            $agent_id = (int) ($metadata['agent_id'] ?? 0);
            $profit_amount = (float) ($metadata['profit_amount'] ?? 0);

            // Update the AFA registration status to processing, record processing_at
            $stmt = $db->prepare("
                UPDATE afa_registrations
                SET status = 'processing', processing_at = NOW()
                WHERE reference = ? AND status = 'pending'
            ");
            if ($stmt) {
                $stmt->bind_param('s', $purchase_reference);
                $stmt->execute();
                $stmt->close();
            }

            // Credit the agent's profit if agent_id is set and profit is greater than 0
            if ($agent_id > 0 && $profit_amount > 0) {
                updateWalletBalance($agent_id, $profit_amount, 'credit', $purchase_reference, 'AFA registration profit');
                
                if (function_exists('sendAgentProfitNotification')) {
                    sendAgentProfitNotification([
                        'agent_id' => $agent_id,
                        'service' => 'AFA Registration',
                        'reference' => $purchase_reference,
                        'customer_name' => $metadata['buyer_name'] ?? '',
                        'customer_email' => $metadata['buyer_email'] ?? '',
                        'beneficiary_number' => $metadata['phone'] ?? '',
                        'item' => ($metadata['beneficiary_name'] ?? '') !== '' ? $metadata['beneficiary_name'] : 'AFA registration',
                        'amount' => $purchase_amount,
                        'profit_amount' => $profit_amount,
                        'payment_method' => 'paystack',
                        'status' => 'processing',
                    ]);
                }
            }

            if (function_exists('notifyAfaRegistrationSubmitted')) {
                notifyAfaRegistrationSubmitted($purchase_reference);
            }

            $success_message = 'Payment successful! Your AFA registration is now processing.';
        }

        // Update transaction status
        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'success', paystack_reference = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $transaction_data['reference'], $transaction['id']);
        $stmt->execute();

        // Handle guest bundle purchase (direct Paystack checkout)
        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'guest_bundle_purchase') {
            $user_id = (int) $transaction['user_id'];
            $package_id = (int) ($metadata['package_id'] ?? 0);
            $agent_id = (int) ($metadata['agent_id'] ?? 0);
            $beneficiary_number = $metadata['beneficiary_number'] ?? '';
            $beneficiary_number = $beneficiary_number ? formatPhone($beneficiary_number) : '';

            if ($user_id && $package_id && $beneficiary_number) {
                $order_id = (int) ($transaction['order_id'] ?? 0);
                $order_created = false;

                // Fetch package + pricing
                $stmt = $db->prepare('
                    SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.network_id,
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

                if ($package && $order_id <= 0) {
                    $bundle_orders_auto_increment = true;
                    if (function_exists('dbh_ensure_auto_increment')) {
                        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
                    }

                    $order_reference = $transaction['reference'];
                    $order_agent_cost = (float) $package['agent_wholesale_price'];
                    $agent_id_val = $agent_id > 0 ? $agent_id : null;

                    if ($bundle_orders_auto_increment) {
                        $stmt = $db->prepare('
                            INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status, transaction_id, agent_id, agent_cost)
                            VALUES (?, ?, ?, ?, ?, "processing", ?, ?, ?)
                        ');
                        $stmt->bind_param(
                            'iisisidd',
                            $user_id,
                            $package_id,
                            $beneficiary_number,
                            $transaction['amount'],
                            $order_reference,
                            $transaction['id'],
                            $agent_id_val,
                            $order_agent_cost
                        );
                        $stmt->execute();
                        $order_id = $db->lastInsertId();
                    } else {
                        $manual_order_id = dbh_generate_next_id('bundle_orders');
                        $stmt = $db->prepare('
                            INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, transaction_id, agent_id, agent_cost)
                            VALUES (?, ?, ?, ?, ?, ?, "processing", ?, ?, ?)
                        ');
                        $stmt->bind_param(
                            'iiisisidd',
                            $manual_order_id,
                            $user_id,
                            $package_id,
                            $beneficiary_number,
                            $transaction['amount'],
                            $order_reference,
                            $transaction['id'],
                            $agent_id_val,
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

                if ($package && $order_id > 0 && $order_created) {
                    require_once __DIR__ . '/../includes/api_providers.php';
                    require_once __DIR__ . '/../includes/volume_converter.php';

                    $volume_gb = extractVolumeGB($package['data_size']);
                    $endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false ||
                                      strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';

                    $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
                    if (!$availability['available']) {
                        $api_result = [
                            'success' => false,
                            'error' => $availability['message']
                        ];
                    } else {
                        $api_result = processBundlePurchase($order_id, $package['network_id'], $beneficiary_number, $volume_gb, $endpoint_type);
                    }

                    if (!$api_result['success']) {
                        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                        $api_response_json = json_encode($api_result);
                        $stmt->bind_param("si", $api_response_json, $order_id);
                        $stmt->execute();

                        updateWalletBalanceWithSMS($user_id, $transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'paystack');
                        $success_message = 'Payment received but delivery failed. Amount credited to your wallet.';
                        if (!empty($api_result['error']) && stripos($api_result['error'], 'Network is busy') !== false) {
                            $success_message = 'Network is busy, validation is ongoing';
                        }
                    } else {
                        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'delivered', api_response = ?, provider_reference = ?, delivered_at = NOW() WHERE id = ?");
                        $api_response_json = json_encode($api_result);
                        $provider_ref = $api_result['reference'] ?? '';
                        $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                        $stmt->execute();

                        if (function_exists('applyMtnStatusPolicy')) {
                            applyMtnStatusPolicy($order_id, 'delivered');
                        }

                        if ($agent_id > 0) {
                            $agent_profit = (float) $transaction['amount'] - (float) $package['agent_wholesale_price'];
                            if ($agent_profit > 0) {
                                updateWalletBalance($agent_id, $agent_profit, 'credit', $reference, 'Guest order profit');
                            }
                            if (function_exists('recordOrderProfit')) {
                                recordOrderProfit([
                                    'agent_id' => $agent_id,
                                    'order_id' => $order_id,
                                    'customer_id' => $user_id,
                                    'package_id' => $package_id,
                                    'customer_paid' => (float) $transaction['amount'],
                                    'agent_cost' => (float) $package['agent_wholesale_price'],
                                    'reference' => $order_reference,
                                    'status' => 'earned'
                                ]);
                            }
                        }

                        sendAdminDataOrderNotification([
                            'order_reference' => $order_reference,
                            'order_id' => $order_id,
                            'user_id' => $user_id,
                            'beneficiary_number' => $beneficiary_number,
                            'network_name' => $package['network_name'] ?? '',
                            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                            'amount' => (float) $transaction['amount'],
                            'payment_method' => 'paystack',
                            'status' => 'delivered',
                            'agent_id' => $agent_id,
                            'source' => 'guest_paystack_checkout'
                        ]);

                        $success_message = 'Payment successful! Your order has been processed.';
                    }
                }
            }
        }

        // Credit user's wallet if this is a top-up
        if ($transaction['transaction_type'] === 'topup') {
            $success = updateWalletBalanceWithSMS(
                $transaction['user_id'], 
                $transaction['amount'], 
                'credit', 
                $reference, 
                'Wallet top-up via Paystack',
                'paystack'
            );
            
            if (!$success) {
                throw new Exception("Failed to update wallet balance");
            }
        }
        
        // Log activity
        logActivity($transaction['user_id'], 'payment_success', "Paystack payment successful: {$reference}");
        
        $db->getConnection()->commit();
        
        // Determine redirect based on user role
        $user_role = 'customer';
        if (!empty($transaction['user_id'])) {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->bind_param("i", $transaction['user_id']);
            $stmt->execute();
            $resRole = $stmt->get_result()->fetch_assoc();
            if ($resRole && !empty($resRole['role'])) { $user_role = $resRole['role']; }
        }
        // Set success message for redirect
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

        $redirectPath = $user_role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
        if (!empty($return_to) && is_string($return_to) && strpos($return_to, '/') === 0) {
            $redirectPath = $return_to;
        } elseif ($transaction['transaction_type'] === 'purchase') {
            $redirectPath = $user_role === 'agent' ? '/agent/histories.php' : '/customer/order-history.php';
        }
        if (!empty($redirect_store_slug) && stripos($redirectPath, 'store=') === false) {
            $joiner = strpos($redirectPath, '?') !== false ? '&' : '?';
            $redirectPath .= $joiner . 'store=' . urlencode($redirect_store_slug);
        }
        header('Location: ' . SITE_URL . $redirectPath);
        exit();
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        
        // Update transaction status to failed
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->bind_param("i", $transaction['id']);
        $stmt->execute();
        
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Paystack callback error: " . $e->getMessage());
    
    // On error, try to redirect user to their wallet with error message if possible
    safe_session_start();
    setFlashMessage('error', 'An error occurred while processing the payment: ' . $e->getMessage());
    $fallback = SITE_URL . '/customer/wallet.php';
    header('Location: ' . $fallback);
    exit();
}
?>
