<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';

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
                        'payment_method' => 'moolre',
                        'status' => 'processing',
                    ]);
                }
            }

            if (function_exists('notifyAfaRegistrationSubmitted')) {
                notifyAfaRegistrationSubmitted($purchase_reference);
            }
        }

        if ($transaction['transaction_type'] === 'purchase' && ($metadata['type'] ?? '') === 'guest_bundle_purchase') {
            $user_id = (int) $transaction['user_id'];
            $package_id = (int) ($metadata['package_id'] ?? 0);
            $agent_id = (int) ($metadata['agent_id'] ?? 0);
            $beneficiary_number = $metadata['beneficiary_number'] ?? '';
            $beneficiary_number = $beneficiary_number ? formatPhone($beneficiary_number) : '';

            if ($user_id && $package_id && $beneficiary_number) {
                $order_id = (int) ($transaction['order_id'] ?? 0);
                $order_created = false;

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
                    $order_created = true;
                }

                if ($package && $order_id > 0) {
                    $api_result = [];
                    $api_error = null;
                    $endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false ||
                                      strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';
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
                        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'delivered', api_response = ?, provider_reference = ?, delivered_at = NOW() WHERE id = ?");
                        $api_response_json = json_encode($api_result);
                        $provider_ref = $api_result['reference'] ?? '';
                        $stmt->bind_param('ssi', $api_response_json, $provider_ref, $order_id);
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
                            'payment_method' => 'moolre',
                            'status' => 'delivered',
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
