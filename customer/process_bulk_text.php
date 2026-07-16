<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/email.php';
require_once '../includes/api_providers.php';
require_once '../includes/volume_converter.php';

requireRole('customer');

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

$csrf_token = $payload['csrf_token'] ?? '';
if (!validateCSRF($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid session token. Please refresh and try again.']);
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
$customer_pricing_type = getCustomerPricingUserType($current_user);

$agent_id = (int)($payload['agent_id'] ?? 0);
$store_slug = sanitize($payload['store_slug'] ?? '');

// Resolve agent by store slug if provided
if ($agent_id <= 0 && !empty($store_slug)) {
    $store_stmt = $db->prepare("
        SELECT ast.agent_id
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ?
          AND ast.is_active = 1
          AND u.role = 'agent'
          AND u.status = 'active'
        LIMIT 1
    ");
    if ($store_stmt) {
        $store_stmt->bind_param('s', $store_slug);
        $store_stmt->execute();
        if ($store_row = $store_stmt->get_result()->fetch_assoc()) {
            $agent_id = (int)$store_row['agent_id'];
        }
    }
}

// Validate referenced agent
if ($agent_id > 0) {
    $agent_stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' AND status = 'active'");
    if ($agent_stmt) {
        $agent_stmt->bind_param('i', $agent_id);
        $agent_stmt->execute();
        $agent_account = $agent_stmt->get_result()->fetch_assoc();
    } else {
        $agent_account = null;
    }
    if (empty($agent_account)) {
        $agent_id = 0;
    }
}

function normalize_customer_bulk_volume_key($value) {
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

function normalize_customer_numeric_key($value) {
    $parsed = (float) $value;
    if (!is_finite($parsed) || $parsed <= 0) {
        return '';
    }
    return rtrim(rtrim(number_format($parsed, 2, '.', ''), '0'), '.');
}

try {
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.network_id,
               COALESCE(n.name, 'Unknown') AS network_name,
               COALESCE(pp_customer.price, pp_customer_fallback.price, dp.price, 0) AS customer_price,
               COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
               COALESCE(dp.stock_status, 'in_stock') AS stock_status,
               acp.custom_price AS agent_custom_price
        FROM data_packages dp
        JOIN networks n ON n.id = dp.network_id AND n.name = 'MTN' AND n.is_active = 1
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = ?
        LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = 'customer'
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
        WHERE dp.status = 'active'
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
          AND (pp_customer.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0)
        ORDER BY dp.data_size
    ");
    $stmt->bind_param('si', $customer_pricing_type, $agent_id);
    $stmt->execute();
    $packages_result = $stmt->get_result();

    $packages = [];
    $packages_by_gb = [];
    while ($pkg = $packages_result->fetch_assoc()) {
        $key = normalize_customer_bulk_volume_key($pkg['data_size']);
        if (!isset($packages[$key])) {
            $packages[$key] = $pkg;
        } else {
            $current_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $packages[$key]['agent_custom_price'] !== null)
                ? (float) $packages[$key]['agent_custom_price']
                : (float) $packages[$key]['customer_price'];
            $candidate_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $pkg['agent_custom_price'] !== null)
                ? (float) $pkg['agent_custom_price']
                : (float) $pkg['customer_price'];
            if ($candidate_price > 0 && ($current_price <= 0 || $candidate_price < $current_price)) {
                $packages[$key] = $pkg;
            }
        }
        $size_gb = extractVolumeGB($pkg['data_size']);
        if ($size_gb > 0) {
            $gb_key = normalize_customer_numeric_key($size_gb);
            if ($gb_key !== '') {
                if (!isset($packages_by_gb[$gb_key])) {
                    $packages_by_gb[$gb_key] = $pkg;
                } else {
                    $current_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $packages_by_gb[$gb_key]['agent_custom_price'] !== null)
                        ? (float) $packages_by_gb[$gb_key]['agent_custom_price']
                        : (float) $packages_by_gb[$gb_key]['customer_price'];
                    $candidate_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $pkg['agent_custom_price'] !== null)
                        ? (float) $pkg['agent_custom_price']
                        : (float) $pkg['customer_price'];
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
    $total_customer_cost = 0.0;
    $total_agent_cost = 0.0;

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

        $volume_key = normalize_customer_bulk_volume_key($raw_volume);
        $package = null;
        $is_numeric_input = preg_match('/^\d+(\.\d+)?$/', trim($raw_volume)) === 1;
        if (isset($packages[$volume_key])) {
            $package = $packages[$volume_key];
        } elseif ($is_numeric_input) {
            $numeric_key = normalize_customer_numeric_key($raw_volume);
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

        $customer_price = (float)$package['customer_price'];
        $agent_wholesale_price = (float)$package['agent_wholesale_price'];
        $agent_custom_price = $package['agent_custom_price'];

        $price_to_charge_customer = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $agent_custom_price !== null)
            ? (float)$agent_custom_price
            : $customer_price;
        $price_to_deduct_from_agent = $agent_id > 0 ? $agent_wholesale_price : 0.0;

        $total_customer_cost += $price_to_charge_customer;
        $total_agent_cost += $price_to_deduct_from_agent;

        $valid_orders[] = [
            'phone' => formatPhone($raw_phone),
            'package' => $package,
            'charge_customer' => $price_to_charge_customer,
            'deduct_agent' => $price_to_deduct_from_agent
        ];
    }

    if (empty($valid_orders)) {
        echo json_encode(['success' => false, 'message' => 'No valid orders found', 'errors' => $errors]);
        exit;
    }

    // Ensure network provider availability before any wallet or transaction changes
    foreach ($valid_orders as $order) {
        $package = $order['package'];
        $endpoint_type = detectEndpointTypeForPackage($package['name'] ?? '', $package['data_size'] ?? '');
        $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
        if (!$availability['available']) {
            echo json_encode(['success' => false, 'message' => $availability['message']]);
            exit;
        }
    }

    $customer_balance = getWalletBalance($current_user['id']);
    if ($customer_balance < $total_customer_cost) {
        echo json_encode(['success' => false, 'message' => 'Insufficient wallet balance for total orders.']);
        exit;
    }

    $bundle_orders_auto_increment = true;
    $transactions_auto_increment = true;
    $commissions_auto_increment = true;
    if (function_exists('dbh_ensure_auto_increment')) {
        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
        $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
        $commissions_auto_increment = dbh_ensure_auto_increment('commissions');
    }

    $processed = 0;
    $processed_total = 0.0;
    $order_errors = [];

    foreach ($valid_orders as $idx => $order) {
        $package = $order['package'];
        $formatted_phone = $order['phone'];
        $price_to_charge_customer = $order['charge_customer'];
        $price_to_deduct_from_agent = $order['deduct_agent'];

        $db->getConnection()->begin_transaction();
        try {
            $endpoint_type = detectEndpointTypeForPackage($package['name'] ?? '', $package['data_size'] ?? '');
            $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
            if (!$availability['available']) {
                throw new Exception($availability['message']);
            }

            $order_ref = generateReference('ORD');
            $order_agent_id = $agent_id > 0 ? $agent_id : null;
            $order_agent_cost = $agent_id > 0 ? $price_to_deduct_from_agent : null;
            $order_id = null;

            if ($bundle_orders_auto_increment) {
                $stmt = $db->prepare('
                    INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status, agent_id, agent_cost)
                    VALUES (?, ?, ?, ?, ?, "processing", ?, ?)
                ');
                $stmt->bind_param(
                    'iisdsid',
                    $current_user['id'],
                    $package['id'],
                    $formatted_phone,
                    $price_to_charge_customer,
                    $order_ref,
                    $order_agent_id,
                    $order_agent_cost
                );
                $stmt->execute();
                $order_id = $db->lastInsertId();
            } else {
                $manual_order_id = dbh_generate_next_id('bundle_orders');
                $stmt = $db->prepare('
                    INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, agent_id, agent_cost)
                    VALUES (?, ?, ?, ?, ?, ?, "processing", ?, ?)
                ');
                $stmt->bind_param(
                    'iiisdsid',
                    $manual_order_id,
                    $current_user['id'],
                    $package['id'],
                    $formatted_phone,
                    $price_to_charge_customer,
                    $order_ref,
                    $order_agent_id,
                    $order_agent_cost
                );
                $stmt->execute();
                $order_id = $manual_order_id;
            }

            $transaction_id = null;
            $txn_ref = $order_ref;
            $description = $package['network_name'] . ' ' . $package['data_size'] . ' bundle purchase for ' . $formatted_phone;

            if ($transactions_auto_increment) {
                $stmt = $db->prepare('
                    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, created_at)
                    VALUES (?, "purchase", ?, "success", ?, "wallet", ?, NOW())
                ');
                $stmt->bind_param('idss', $current_user['id'], $price_to_charge_customer, $txn_ref, $description);
            } else {
                $manual_transaction_id = dbh_generate_next_id('transactions');
                $stmt = $db->prepare('
                    INSERT INTO transactions (id, user_id, transaction_type, amount, status, reference, payment_method, description, created_at)
                    VALUES (?, ?, "purchase", ?, "success", ?, "wallet", ?, NOW())
                ');
                $stmt->bind_param('iidss', $manual_transaction_id, $current_user['id'], $price_to_charge_customer, $txn_ref, $description);
            }
            $stmt->execute();
            $transaction_id = $transactions_auto_increment ? $db->lastInsertId() : $manual_transaction_id;

            if (!empty($transaction_id)) {
                $stmt = $db->prepare('UPDATE bundle_orders SET transaction_id = ? WHERE id = ?');
                $stmt->bind_param('ii', $transaction_id, $order_id);
                $stmt->execute();
            }

            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();

            if (!updateWalletBalance($current_user['id'], $price_to_charge_customer, 'debit', $txn_ref, $description)) {
                throw new Exception('Failed to deduct customer wallet');
            }

            $volume_gb = extractVolumeGB($package['data_size']);
            try {
                $api_result = processBundlePurchase($order_id, $package['network_id'], $formatted_phone, $volume_gb, $endpoint_type);
            } catch (Exception $e) {
                $api_result = ['success' => false, 'error' => $e->getMessage()];
            }

            if (!$api_result['success']) {
                $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                $api_response_json = json_encode($api_result);
                $stmt->bind_param("si", $api_response_json, $order_id);
                $stmt->execute();

                updateWalletBalance($current_user['id'], $price_to_charge_customer, 'credit', $txn_ref . '_REFUND', 'Refund: ' . ($api_result['error'] ?? 'Provider error'));

                throw new Exception('Provider error');
            }

            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
            $api_response_json = json_encode($api_result);
            $provider_ref = $api_result['reference'] ?? '';
            $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
            $stmt->execute();

            if (function_exists('applyMtnStatusPolicy')) {
                applyMtnStatusPolicy($order_id, 'processing');
            }

            $agent_order_profit = $agent_id > 0
                ? max(0, round($price_to_charge_customer - $price_to_deduct_from_agent, 2))
                : 0.0;

            if ($agent_id > 0 && $agent_order_profit > 0 && (!function_exists('walletReferenceExists') || !walletReferenceExists($agent_id, $order_ref))) {
                updateWalletBalance($agent_id, $agent_order_profit, 'credit', $order_ref, 'Store profit: bulk data bundle order');
            }

            if (function_exists('recordOrderProfit')) {
                recordOrderProfit([
                    'agent_id' => $agent_id,
                    'order_id' => $order_id,
                    'customer_id' => $current_user['id'],
                    'package_id' => $package['id'],
                    'customer_paid' => $price_to_charge_customer,
                    'agent_cost' => $price_to_deduct_from_agent,
                    'profit_amount' => $agent_order_profit,
                    'reference' => $order_ref,
                    'status' => 'earned'
                ]);
            }

            $db->getConnection()->commit();
            $processed++;
            $processed_total += (float) $price_to_charge_customer;
        } catch (Exception $e) {
            $db->getConnection()->rollback();
            $order_errors[] = 'Row ' . ($idx + 1) . ': ' . $e->getMessage();
        }
    }

    if ($processed > 0) {
        sendAdminDataOrderNotification([
            'order_reference' => generateReference('BULK'),
            'order_id' => 0,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => 'Multiple numbers',
            'network_name' => 'MTN',
            'package_name' => "Bulk MTN ({$processed} successful orders)",
            'amount' => $processed_total,
            'payment_method' => 'wallet',
            'status' => 'processed',
            'agent_id' => $agent_id,
            'source' => 'customer_bulk_text'
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

    $updated_wallet_balance = getWalletBalance($current_user['id']);

    echo json_encode([
        'success' => $processed > 0,
        'message' => $message,
        'processed' => $processed,
        'errors' => array_merge($errors, $order_errors),
        'wallet_balance' => (float) $updated_wallet_balance
    ]);
} catch (Exception $e) {
    error_log("Customer bulk text error: " . $e->getMessage());
    $message = 'An error occurred while processing the orders.';
    if (stripos($e->getMessage(), 'Network is busy') !== false) {
        $message = $e->getMessage();
    }
    $fallback_wallet_balance = 0;
    if (!empty($current_user['id'])) {
        $fallback_wallet_balance = (float) getWalletBalance((int) $current_user['id']);
    }
    echo json_encode(['success' => false, 'message' => $message, 'wallet_balance' => $fallback_wallet_balance]);
}

?>
