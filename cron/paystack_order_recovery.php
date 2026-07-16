<?php
/**
 * Automated Paystack Order Recovery Cron Job
 * 
 * This script identifies Paystack payments that were successful but where 
 * the corresponding order was not created or completed. It automatically 
 * verifies the status with Paystack and fulfills the order.
 * 
 * Recommended frequency: Every 2-5 minutes
 * Usage: php cron/paystack_order_recovery.php
 */

// Basic setup
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paystack_fees.php';
require_once __DIR__ . '/../includes/api_providers.php';
require_once __DIR__ . '/../includes/volume_converter.php';
require_once __DIR__ . '/../includes/order_status.php';

// Prevent web access
if (PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// Log to a specific file
$log_file = __DIR__ . '/../tmp_sessions/recovery_log_' . date('Y-m') . '.log';
function recovery_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    echo $log_entry;
    error_log($log_entry, 3, $log_file);
}

function runRecoverySideEffect($label, callable $callback) {
    try {
        $callback();
    } catch (Throwable $e) {
        recovery_log("Side effect failed [$label]: " . $e->getMessage());
    }
}

recovery_log("Starting Paystack recovery process...");

// 1. Fetch eligible transactions
// - Method: paystack
// - Case 1: Status is pending/failed (but might be successful on Paystack)
// - Case 2: Status is success but order_id is missing (for purchase)
// - Time window: 3 minutes to 12 hours ago
$query = "
    SELECT * FROM transactions 
    WHERE payment_method = 'paystack' 
    AND (
        (status IN ('pending', 'failed') AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) AND created_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE))
        OR 
        (transaction_type = 'purchase' AND status = 'success' AND (order_id IS NULL OR order_id = 0) AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) AND created_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE))
    )
    ORDER BY created_at ASC
    LIMIT 30
";

$result = $db->query($query);
if (!$result) {
    recovery_log("Error fetching transactions: " . $db->getConnection()->error);
    exit(1);
}

$processed = 0;
$recovered = 0;
$skipped = 0;
$failed = 0;

$paystack_secret_key = trim((string) dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY));
if (empty($paystack_secret_key)) {
    recovery_log("CRITICAL: Paystack Secret Key is not configured.");
    exit(1);
}

while ($transaction = $result->fetch_assoc()) {
    $processed++;
    $reference = $transaction['reference'];
    
    // Verify with Paystack API
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $paystack_secret_key,
            'Cache-Control: no-cache',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        recovery_log("Ref: $reference - cURL error: $err");
        continue;
    }

    $paystack_result = json_decode($response, true);
    if (!$paystack_result || !($paystack_result['status'] ?? false)) {
        $skipped++;
        continue;
    }

    $gateway_data = $paystack_result['data'];
    $gateway_status = strtolower(trim((string) ($gateway_data['status'] ?? '')));

    if ($gateway_status !== 'success') {
        if (in_array($gateway_status, ['failed', 'reversed'], true) && $transaction['status'] === 'pending') {
            $db->query("UPDATE transactions SET status = 'failed' WHERE id = " . (int)$transaction['id']);
            recovery_log("Ref: $reference - Marked as failed based on Paystack status.");
        }
        $skipped++;
        continue;
    }

    // Amount validation
    $gateway_amount = round(((float) ($gateway_data['amount'] ?? 0)) / 100, 2);
    $expected_amount = round((float) ($transaction['amount'] ?? 0), 2);
    $validation = validatePaystackAmount($gateway_amount, $expected_amount);
    
    if (empty($validation['is_valid'])) {
        recovery_log("Ref: $reference - Amount mismatch (Paid: $gateway_amount, Expected: $expected_amount). Skipping.");
        $failed++;
        continue;
    }

    // Proceed with recovery
    $db->getConnection()->begin_transaction();
    try {
        $stmt = $db->prepare("SELECT id, status, order_id, user_id, amount, transaction_type, metadata FROM transactions WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $transaction['id']);
        $stmt->execute();
        $locked_txn = $stmt->get_result()->fetch_assoc();

        if (!$locked_txn || ($locked_txn['status'] === 'success' && $locked_txn['transaction_type'] === 'purchase' && (int)$locked_txn['order_id'] > 0)) {
            $db->getConnection()->rollback();
            $skipped++;
            continue;
        }

        $metadata = json_decode((string)($locked_txn['metadata'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];

        if ($locked_txn['transaction_type'] === 'topup') {
            $success = updateWalletBalanceWithSMS(
                $locked_txn['user_id'], 
                $locked_txn['amount'], 
                'credit', 
                $reference, 
                'Wallet top-up recovery via Paystack',
                'paystack'
            );
            if ($success) {
                $paystack_ref = $db->getConnection()->real_escape_string($gateway_data['reference']);
                $db->query("UPDATE transactions SET status = 'success', paystack_reference = '$paystack_ref', updated_at = NOW() WHERE id = " . (int)$locked_txn['id']);
                $recovered++;
                recovery_log("Ref: $reference - Topup recovered.");
            } else {
                throw new Exception("Wallet update failed.");
            }
        } elseif ($locked_txn['transaction_type'] === 'purchase') {
            $type = $metadata['type'] ?? '';
            
            if ($type === 'guest_bundle_purchase' || $type === 'customer_bundle_purchase') {
                $package_id = (int) ($metadata['package_id'] ?? 0);
                $beneficiary_number = trim((string) ($metadata['beneficiary_number'] ?? ''));
                $user_id = (int) ($locked_txn['user_id'] ?? 0);
                $agent_id = (int) ($metadata['agent_id'] ?? 0);
                
                $pkgStmt = $db->prepare("SELECT p.*, n.name as network_name FROM data_packages p JOIN networks n ON p.network_id = n.id WHERE p.id = ?");
                $pkgStmt->bind_param('i', $package_id);
                $pkgStmt->execute();
                $package = $pkgStmt->get_result()->fetch_assoc();
                
                if (!$package) throw new Exception("Package #$package_id not found.");

                $orderCheck = $db->prepare("SELECT id, status FROM bundle_orders WHERE transaction_id = ? OR order_reference = ?");
                $orderCheck->bind_param('is', $locked_txn['id'], $reference);
                $orderCheck->execute();
                $existingOrder = $orderCheck->get_result()->fetch_assoc();
                
                $order_id = $existingOrder ? (int)$existingOrder['id'] : 0;
                if (!$order_id) {
                    $amount = (float) $locked_txn['amount'];
                    $insOrder = $db->prepare("INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, status, transaction_id, order_reference, created_at) VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())");
                    $insOrder->bind_param('iisdis', $user_id, $package_id, $beneficiary_number, $amount, $locked_txn['id'], $reference);
                    $insOrder->execute();
                    $order_id = $db->lastInsertId();
                }

                if ($existingOrder && in_array($existingOrder['status'], ['delivered', 'success', 'completed'], true)) {
                    $paystack_ref = $db->getConnection()->real_escape_string($gateway_data['reference']);
                    $db->query("UPDATE transactions SET status = 'success', order_id = $order_id, paystack_reference = '$paystack_ref' WHERE id = " . (int)$locked_txn['id']);
                    $db->getConnection()->commit();
                    $recovered++;
                    continue;
                }

                $volume_gb = extractVolumeGB($package['data_size']);
                $endpoint_type = detectEndpointTypeForPackage($package['name'], $package['data_size'], $package['package_type']);
                
                $api_result = processBundlePurchase($order_id, $package['network_id'], $beneficiary_number, $volume_gb, $endpoint_type);
                $api_response_esc = $db->getConnection()->real_escape_string(json_encode($api_result));
                $paystack_ref = $db->getConnection()->real_escape_string($gateway_data['reference']);
                
                $final_status = $api_result['success'] ? 'delivered' : 'failed';
                $db->query("UPDATE bundle_orders SET status = '$final_status', api_response = '$api_response_esc', processed_at = NOW() " . ($final_status === 'delivered' ? ", delivered_at = NOW()" : "") . " WHERE id = $order_id");
                $db->query("UPDATE transactions SET status = 'success', order_id = $order_id, paystack_reference = '$paystack_ref' WHERE id = " . (int)$locked_txn['id']);
                
                // --- Notifications & Profit ---
                if ($final_status === 'delivered') {
                    if ($agent_id > 0 && function_exists('recordOrderProfit')) {
                        runRecoverySideEffect('record_profit', function() use ($agent_id, $order_id, $user_id, $package_id, $locked_txn, $package, $reference) {
                            $agent_cost = (float) ($package['agent_price'] ?? 0);
                            recordOrderProfit([
                                'agent_id' => $agent_id,
                                'order_id' => $order_id,
                                'customer_id' => $user_id > 0 ? $user_id : null,
                                'package_id' => $package_id,
                                'customer_paid' => (float) $locked_txn['amount'],
                                'agent_cost' => $agent_cost,
                                'profit_amount' => round((float)$locked_txn['amount'] - $agent_cost, 2),
                                'reference' => $reference,
                                'status' => 'earned'
                            ]);
                        });
                    }
                    
                    runRecoverySideEffect('notifications', function() use ($reference, $order_id, $user_id, $metadata, $beneficiary_number, $package, $locked_txn, $type) {
                        $source = ($type === 'guest_bundle_purchase' ? 'guest_paystack_recovery_cron' : 'customer_paystack_recovery_cron');
                        sendUserOrderNotification([
                            'order_type' => 'data', 'order_reference' => $reference, 'order_id' => $order_id, 'user_id' => $user_id,
                            'customer_name' => $metadata['buyer_name'] ?? '', 'customer_email' => $metadata['buyer_email'] ?? '',
                            'beneficiary_number' => $beneficiary_number, 'network_name' => $package['network_name'] ?? '',
                            'package_name' => $package['data_size'], 'amount' => (float)$locked_txn['amount'], 'payment_method' => 'paystack',
                            'status' => 'delivered', 'source' => $source
                        ]);
                        sendAdminDataOrderNotification([
                            'order_reference' => $reference, 'order_id' => $order_id, 'user_id' => $user_id, 'beneficiary_number' => $beneficiary_number,
                            'network_name' => $package['network_name'] ?? '', 'package_name' => $package['data_size'], 'amount' => (float)$locked_txn['amount'],
                            'payment_method' => 'paystack', 'status' => 'delivered', 'source' => $source
                        ]);
                    });
                }
                
                $recovered++;
                recovery_log("Ref: $reference - Bundle recovered ($final_status).");
            } elseif ($type === 'result_checker_purchase') {
                // Simplified result checker recovery: verify and mark as success if payment is good.
                // Complete recovery would require card allocation, but for now we mark as confirmed.
                $paystack_ref = $db->getConnection()->real_escape_string($gateway_data['reference']);
                $db->query("UPDATE transactions SET status = 'success', paystack_reference = '$paystack_ref' WHERE id = " . (int)$locked_txn['id']);
                $recovered++;
                recovery_log("Ref: $reference - Result checker payment confirmed. Manual card allocation may be needed.");
            } else {
                recovery_log("Ref: $reference - Unsupported type: $type.");
                $db->getConnection()->rollback();
                continue;
            }
        }

        $db->getConnection()->commit();
    } catch (Throwable $e) {
        $db->getConnection()->rollback();
        recovery_log("Ref: $reference - Recovery error: " . $e->getMessage());
        $failed++;
    }
}

recovery_log("Recovery process finished. Summary: Processed=$processed, Recovered=$recovered, Skipped=$skipped, Failed=$failed");
?>
