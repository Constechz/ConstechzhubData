<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';
ensureAfaRegistrationTables();

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit();
}

function moolre_webhook_success($status) {
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

try {
    $config = getMoolreConfig();
    if (!isMoolreConfigured($config)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Moolre keys not configured']);
        exit();
    }

    $secret = trim((string) ($config['webhook_secret'] ?? ''));
    if ($secret !== '') {
        $sig = $_SERVER['HTTP_X_MOOLRE_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_X_MOOLRE_SECRET'] ?? '';
        if ($sig !== '' && !hash_equals($secret, $sig)) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
            exit();
        }
    }

    $reference = $payload['externalref'] ?? $payload['reference'] ?? $payload['id'] ?? '';
    if ($reference === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing reference']);
        exit();
    }

    $status_raw = $payload['txstatus'] ?? $payload['status'] ?? '';
    if (!moolre_webhook_success($status_raw)) {
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE reference = ? AND status = 'pending'");
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $stmt = $db->prepare("UPDATE afa_registrations SET status = 'failed', updated_at = NOW() WHERE reference = ?");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['status' => 'ok', 'message' => 'Payment not successful']);
        exit();
    }

    $stmt = $db->prepare("SELECT * FROM transactions WHERE reference = ? AND status = 'pending'");
    $stmt->bind_param('s', $reference);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();

    if (!$transaction) {
        echo json_encode(['status' => 'ok', 'message' => 'Transaction already processed']);
        exit();
    }

    $paid_amount = (float) ($payload['amount'] ?? $payload['amount_paid'] ?? $payload['paid_amount'] ?? 0);
    $currency = $payload['currency'] ?? '';
    if ($currency && strcasecmp($currency, CURRENCY_CODE) !== 0) {
        throw new Exception('Currency mismatch');
    }
    if ($paid_amount > 0 && abs($paid_amount - (float) $transaction['amount']) > 0.01) {
        throw new Exception('Amount mismatch');
    }

    $gateway_reference = $payload['transactid'] ?? $payload['transaction_id'] ?? $payload['reference'] ?? $reference;

    $db->getConnection()->begin_transaction();

    try {
        $metadata = [];
        if (!empty($transaction['metadata'])) {
            $decoded = json_decode($transaction['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $stmt = $db->prepare("
            UPDATE transactions
            SET status = 'success', paystack_reference = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('si', $gateway_reference, $transaction['id']);
        $stmt->execute();

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'afa_registration_purchase') {
            $user_id = (int) $transaction['user_id'];
            $purchase_reference = (string) ($transaction['reference'] ?? $reference);
            $agent_id = max(0, (int) ($metadata['agent_id'] ?? 0));
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
                SET status = 'processing',
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

            if (function_exists('notifyAfaRegistrationSubmitted')) {
                notifyAfaRegistrationSubmitted($purchase_reference);
            }
        }

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
                        $order_created = true;
                    }
                }

                if ($package && $order_id > 0 && ($order_created ?? false)) {
                    $api_result = [];
                    $api_error = null;
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
                        $is_datawax_order = strpos($provider_slug, 'datawax') !== false || $provider_name === 'datawax';
                        $order_status_for_notifications = 'delivered';

                        if ($is_hubnet_order || $is_datawax_order) {
                            $provider_status = strtolower(trim((string) (($api_result['response']['delivery_state'] ?? $api_result['response']['status'] ?? 'processing'))));
                            if ($provider_status === '' || $provider_status === '1') {
                                $provider_status = 'processing';
                            }
                            
                            $internal_status = in_array($provider_status, ['completed', 'delivered'], true) ? 'delivered' : 'processing';

                            $stmt = $db->prepare("UPDATE bundle_orders SET status = ?, processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()" . ($internal_status === 'delivered' ? ", delivered_at = NOW()" : "") . " WHERE id = ?");
                            $stmt->bind_param('ssssi', $internal_status, $api_response_json, $provider_status, $provider_ref, $order_id);
                            $stmt->execute();
                            $order_status_for_notifications = $internal_status;
                        } else {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = COALESCE(processed_at, NOW()), api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                            $stmt->bind_param('ssi', $api_response_json, $provider_ref, $order_id);
                            $stmt->execute();

                            if (function_exists('applyMtnStatusPolicy')) {
                                applyMtnStatusPolicy($order_id, 'processing');
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
                            $agent_profit = (float) $transaction['amount'] - (float) $order_agent_cost;
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
                            'source' => 'guest_moolre_webhook'
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
                            'source' => 'guest_moolre_webhook'
                        ]);
                    } else {
                        if ($order_created) {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result ?: ['error' => $api_error]);
                            $stmt->bind_param('si', $api_response_json, $order_id);
                            $stmt->execute();
                        }

                        if ($user_id) {
                            updateWalletBalanceWithSMS($user_id, $transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'moolre');
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

        logActivity($transaction['user_id'], 'payment_success', "Moolre webhook processed: {$reference}");

        $db->getConnection()->commit();
        echo json_encode(['status' => 'ok']);
        exit();

    } catch (Exception $e) {
        $db->getConnection()->rollback();
        $stmt = $db->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
        $stmt->bind_param('i', $transaction['id']);
        $stmt->execute();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Moolre webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit();
}
?>
