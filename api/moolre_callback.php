<?php
require_once '../config/config.php';
ensureResultCheckerTables();

$reference = $_GET['reference'] ?? $_POST['reference'] ?? '';

function safe_session_start() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function redirectTopLevel($url) {
    $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Redirecting...</title></head><body style="font-family: Arial, sans-serif; padding: 24px;">';
    echo '<p>Redirecting...</p>';
    echo '<script>';
    echo 'var target = ' . json_encode($url) . ';';
    echo 'if (window.top && window.top !== window.self) { window.top.location = target; } else { window.location = target; }';
    echo '</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
    echo '<p><a href="' . $safe . '">Continue</a></p>';
    echo '</body></html>';
    exit();
}

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
        $redirectPath = $user_role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
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
        redirectTopLevel(SITE_URL . $redirectPath);
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
        $metadata_type = $metadata['type'] ?? '';
        $return_to = $metadata['return_to'] ?? null;
        $success_message = 'Payment successful!';

        if ($transaction['transaction_type'] === 'purchase' && $metadata_type === 'result_checker_purchase') {
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
                    'payment_method' => 'moolre',
                    'status' => 'success',
                    'agent_id' => $agent_id,
                    'source' => 'moolre_callback'
                ]);
                $success_message = 'Payment successful! Your result checker card is ready.';
            }
        } elseif ($transaction['transaction_type'] === 'purchase' && $metadata_type === 'afa_registration_purchase') {
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

            $success_message = 'Payment successful! Your AFA registration is now processing.';
        }

        $stmt = $db->prepare("
            UPDATE transactions 
            SET status = 'success', paystack_reference = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param("si", $gateway_reference, $transaction['id']);
        $stmt->execute();

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
                    require_once __DIR__ . '/../includes/api_providers.php';
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
                            'payment_method' => 'moolre',
                            'status' => 'delivered',
                            'agent_id' => $agent_id,
                            'source' => 'guest_moolre_callback'
                        ]);

                        $success_message = 'Payment successful! Your order has been processed.';
                    } else {
                        if ($order_created) {
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result ?: ['error' => $api_error]);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();
                        }

                        if ($user_id) {
                            updateWalletBalanceWithSMS($user_id, $transaction['amount'], 'credit', $reference, 'Refund: Order failed', 'moolre');
                        }
                        $success_message = 'Payment received but delivery failed. Amount credited to your wallet.';
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

        $redirectPath = $user_role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
        if (!empty($return_to) && is_string($return_to) && strpos($return_to, '/') === 0) {
            $redirectPath = $return_to;
        } elseif ($transaction['transaction_type'] === 'purchase') {
            $redirectPath = $user_role === 'agent' ? '/agent/histories.php' : '/customer/order-history.php';
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
