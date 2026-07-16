<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/api_providers.php';
require_once '../includes/volume_converter.php';

requireLogin();
requireRole('agent');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$network = strtolower(trim($payload['network'] ?? 'mtn'));
if ($network !== 'mtn') {
    echo json_encode(['success' => false, 'message' => 'Only MTN bulk text orders are supported.']);
    exit;
}

$orders = $payload['orders'] ?? [];
if (!is_array($orders) || empty($orders)) {
    echo json_encode(['success' => false, 'message' => 'No orders provided']);
    exit;
}

$current_user = getCurrentUser();
if (!$current_user) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}
ensureDataPackageStockStatusColumn();

function normalize_volume_key($value) {
    $value = strtolower(trim($value));
    if ($value !== '' && preg_match('/^\d+(\.\d+)?$/', $value)) {
        $parsed = (float) $value;
        $normalized = rtrim(rtrim(number_format($parsed, 2, '.', ''), '0'), '.');
        return $normalized . 'g';
    }
    $value = preg_replace('/\s+/', '', $value);
    $value = str_replace(['gb', 'mb'], ['g', 'm'], $value);
    return $value;
}

function normalize_numeric_key($value) {
    $parsed = (float) $value;
    if (!is_finite($parsed) || $parsed <= 0) {
        return '';
    }
    return rtrim(rtrim(number_format($parsed, 2, '.', ''), '0'), '.');
}

try {
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.data_size,
               COALESCE(dp.stock_status, 'in_stock') AS stock_status,
               COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
        FROM data_packages dp
        JOIN networks n ON dp.network_id = n.id
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
        WHERE n.name = 'MTN' AND dp.status = 'active'
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ORDER BY dp.data_size
    ");
    $stmt->execute();
    $packages_result = $stmt->get_result();

    $packages = [];
    $packages_by_gb = [];
    while ($pkg = $packages_result->fetch_assoc()) {
        $key = normalize_volume_key($pkg['data_size']);
        if (!isset($packages[$key])) {
            $packages[$key] = $pkg;
        } else {
            $current_price = (float) ($packages[$key]['effective_price'] ?? 0);
            $candidate_price = (float) ($pkg['effective_price'] ?? 0);
            if ($candidate_price > 0 && ($current_price <= 0 || $candidate_price < $current_price)) {
                $packages[$key] = $pkg;
            }
        }
        $size_gb = extractVolumeGB($pkg['data_size']);
        if ($size_gb > 0) {
            $gb_key = normalize_numeric_key($size_gb);
            if ($gb_key !== '') {
                if (!isset($packages_by_gb[$gb_key])) {
                    $packages_by_gb[$gb_key] = $pkg;
                } else {
                    $current_price = (float) ($packages_by_gb[$gb_key]['effective_price'] ?? 0);
                    $candidate_price = (float) ($pkg['effective_price'] ?? 0);
                    if ($candidate_price > 0 && ($current_price <= 0 || $candidate_price < $current_price)) {
                        $packages_by_gb[$gb_key] = $pkg;
                    }
                }
            }
        }
    }

    if (empty($packages)) {
        echo json_encode(['success' => false, 'message' => 'No MTN packages available']);
        exit;
    }

    $valid_orders = [];
    $errors = [];
    $expected_total = 0.0;

    foreach ($orders as $index => $order) {
        $raw_phone = trim((string)($order['phone'] ?? ''));
        $raw_volume = trim((string)($order['volume'] ?? ''));
        $row_num = $index + 1;

        if ($raw_phone === '' || $raw_volume === '') {
            $errors[] = "Row {$row_num}: Missing phone or volume";
            continue;
        }

        if (!validatePhone($raw_phone) || !isMtnNumber($raw_phone)) {
            $errors[] = "Row {$row_num}: Invalid MTN phone number";
            continue;
        }

        $volume_key = normalize_volume_key($raw_volume);
        $package = null;
        $is_numeric_input = preg_match('/^\d+(\.\d+)?$/', trim($raw_volume)) === 1;
        if (isset($packages[$volume_key])) {
            $package = $packages[$volume_key];
        } elseif ($is_numeric_input) {
            $numeric_key = normalize_numeric_key($raw_volume);
            if ($numeric_key !== '' && isset($packages_by_gb[$numeric_key])) {
                $package = $packages_by_gb[$numeric_key];
            }
        } else {
            foreach ($packages as $key => $pkg) {
                if (strpos($volume_key, $key) !== false || strpos($key, $volume_key) !== false) {
                    $package = $pkg;
                    break;
                }
            }
        }

        if (!$package) {
            $errors[] = "Row {$row_num}: Package not found for volume '{$raw_volume}'";
            continue;
        }

        $price = (float)($package['effective_price'] ?? 0);
        $expected_total += $price;

        $valid_orders[] = [
            'phone' => formatPhone($raw_phone),
            'package_id' => (int)$package['id'],
            'package_name' => $package['name'] ?? '',
            'data_size' => $package['data_size'],
            'price' => $price
        ];
    }

    if (empty($valid_orders)) {
        echo json_encode(['success' => false, 'message' => 'No valid orders found', 'errors' => $errors]);
        exit;
    }

    $wallet_balance = (float) getWalletBalance($current_user['id']);
    $expected_total = round($expected_total, 2);
    $balance_buffer = 0.01;
    if (($wallet_balance + $balance_buffer) < $expected_total) {
        $message = 'Insufficient wallet balance. Required: ' . formatCurrency($expected_total) .
            ', Available: ' . formatCurrency($wallet_balance);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'required' => $expected_total,
            'available' => $wallet_balance
        ]);
        exit;
    }

    $bundle_orders_auto_increment = true;
    if (function_exists('dbh_ensure_auto_increment')) {
        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
    }

    $db->getConnection()->begin_transaction();

    $processed = 0;
    $charged_total = 0.0;
    $order_errors = [];

    foreach ($valid_orders as $idx => $order) {
        $order_reference = generateReference('MTN');
        $order_id = null;

        if ($bundle_orders_auto_increment) {
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'processing', NOW())
            ");
            $stmt->bind_param(
                "iisds",
                $current_user['id'],
                $order['package_id'],
                $order['phone'],
                $order['price'],
                $order_reference
            );
            $stmt->execute();
            $order_id = $db->lastInsertId();
        } else {
            $manual_order_id = dbh_generate_next_id('bundle_orders');
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'processing', NOW())
            ");
            $stmt->bind_param(
                "iiisds",
                $manual_order_id,
                $current_user['id'],
                $order['package_id'],
                $order['phone'],
                $order['price'],
                $order_reference
            );
            $stmt->execute();
            $order_id = $manual_order_id;
        }

        if (!$order_id) {
            $order_errors[] = "Row " . ($idx + 1) . ": Failed to create order";
            continue;
        }

        $volume_gb = extractVolumeGB($order['data_size']);
        $endpoint_type = detectEndpointTypeForPackage($order['package_name'] ?? '', $order['data_size'] ?? '');
        $availability = checkNetworkProviderAvailability(1, $endpoint_type);
        if (!$availability['available']) {
            $stmt_update = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
            $api_response_json = json_encode(['success' => false, 'error' => $availability['message']]);
            $stmt_update->bind_param("si", $api_response_json, $order_id);
            $stmt_update->execute();
            $order_errors[] = "Row " . ($idx + 1) . ": " . $availability['message'];
            continue;
        }

        try {
            $api_result = processBundlePurchase($order_id, 1, $order['phone'], $volume_gb, $endpoint_type);
        } catch (Exception $e) {
            $api_result = ['success' => false, 'error' => $e->getMessage()];
        }

        if ($api_result['success']) {
            $stmt_update = $db->prepare("
                UPDATE bundle_orders
                SET status = 'processing', api_response = ?, provider_reference = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $api_response_json = json_encode($api_result);
            $provider_ref = $api_result['reference'] ?? '';
            $stmt_update->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
            $stmt_update->execute();

            if (function_exists('applyMtnStatusPolicy')) {
                applyMtnStatusPolicy($order_id, 'processing');
            }

            if (function_exists('recordAgentCommission')) {
                $commission_amount = function_exists('calculateAgentDataCommissionAmount')
                    ? calculateAgentDataCommissionAmount($order['data_size'] ?? '', 1)
                    : 0.0;

                if ($commission_amount > 0) {
                    recordAgentCommission([
                        'agent_id' => (int) $current_user['id'],
                        'source_type' => 'data',
                        'source_id' => (int) $order_id,
                        'source_reference' => (string) $order_reference,
                        'amount' => $commission_amount,
                        'quantity' => 1,
                        'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['data_rate_per_gb'] ?? 0) : null,
                        'notes' => ($order['network_name'] ?? 'Data') . ' ' . ($order['data_size'] ?? 'bundle') . ' for ' . $formatted_phone,
                    ]);
                }
            }

            $processed++;
            $charged_total += $order['price'];
        } else {
            $stmt_update = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
            $api_response_json = json_encode($api_result);
            $stmt_update->bind_param("si", $api_response_json, $order_id);
            $stmt_update->execute();

            $order_errors[] = "Row " . ($idx + 1) . ": API delivery failed";
        }
    }

    if ($processed > 0 && $charged_total > 0) {
        $deduction_reference = 'BULK_TEXT_' . time();
        $description = "Bulk text MTN orders - {$processed} orders";
        if (!updateWalletBalance($current_user['id'], $charged_total, 'debit', $deduction_reference, $description)) {
            throw new Exception('Failed to update wallet balance');
        }
    }

    $db->getConnection()->commit();

    if ($processed > 0) {
        sendAdminDataOrderNotification([
            'order_reference' => generateReference('AGBULKTXT'),
            'order_id' => 0,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => 'Multiple numbers',
            'network_name' => 'MTN',
            'package_name' => "Agent Bulk MTN ({$processed} successful orders)",
            'amount' => (float) $charged_total,
            'payment_method' => 'wallet',
            'status' => 'processed',
            'agent_id' => (int) $current_user['id'],
            'source' => 'agent_bulk_text'
        ]);

        sendUserOrderNotification([
            'order_type' => 'data',
            'order_reference' => generateReference('AGBULKTXT'),
            'order_id' => 0,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => 'Multiple numbers',
            'network_name' => 'MTN',
            'package_name' => "Agent Bulk MTN ({$processed} successful orders)",
            'amount' => (float) $charged_total,
            'payment_method' => 'wallet',
            'status' => 'processed',
            'source' => 'agent_bulk_text'
        ]);
    }

    $message = "Processed {$processed} orders successfully.";
    if (!empty($errors) || !empty($order_errors)) {
        $all_errors = array_merge($errors, $order_errors);
        $message .= " Errors: " . implode(', ', array_slice($all_errors, 0, 3));
        if (count($all_errors) > 3) {
            $message .= " and " . (count($all_errors) - 3) . " more...";
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'processed' => $processed,
        'total_charged' => $charged_total,
        'errors' => array_merge($errors, $order_errors)
    ]);
} catch (Exception $e) {
    $db->getConnection()->rollback();
    error_log("Bulk text error: " . $e->getMessage());
    $message = 'An error occurred while processing the orders.';
    if (stripos($e->getMessage(), 'Network is busy') !== false) {
        $message = $e->getMessage();
    }
    echo json_encode(['success' => false, 'message' => $message]);
}

?>
