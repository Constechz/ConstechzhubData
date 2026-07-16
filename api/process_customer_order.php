<?php
require_once '../config/config.php';
require_once '../includes/email.php';
require_once __DIR__ . '/../includes/api_providers.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit();
}

requireRole('customer');
$current_user = getCurrentUser();
ensureDataPackageStockStatusColumn();
$customer_pricing_type = getCustomerPricingUserType($current_user);

$package_id = intval($_POST['package_id'] ?? 0);
$beneficiary_number = sanitize($_POST['beneficiary_number'] ?? '');
$allow_ported_mtn = isset($_POST['allow_ported_mtn']) && $_POST['allow_ported_mtn'] === '1';
$agent_id = intval($_POST['agent_id'] ?? 0);
$store_slug = sanitize($_POST['store_slug'] ?? '');
// CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
$order_submit_token = $_POST['order_submit_token'] ?? '';

// Resolve agent by store slug if hidden input is missing or tampered with
if (($agent_id <= 0) && !empty($store_slug)) {
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
            $agent_id = (int) $store_row['agent_id'];
        }
    }
}

// Validate referenced agent (if any) to prevent FK constraint issues
if ($agent_id > 0) {
    $agent_stmt = $db->prepare("SELECT id, role FROM users WHERE id = ? AND (role = 'agent' OR role = 'vip') AND status = 'active'");
    if ($agent_stmt) {
        $agent_stmt->bind_param('i', $agent_id);
        $agent_stmt->execute();
        $agent_account = $agent_stmt->get_result()->fetch_assoc();
    } else {
        $agent_account = null;
    }
    if (empty($agent_account)) {
        error_log("Customer purchase: Provided agent_id {$agent_id} is invalid or inactive. Falling back to direct purchase.");
        $agent_id = 0;
    }
}

// Validate CSRF
if (!validateCSRF($csrf_token)) {
    setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
    
    // Ensure session is written before redirect
    session_write_close();
    
    $redirect_url = SITE_URL . '/customer/buy-data.php';
    if (!empty($store_slug)) {
        $redirect_url .= '?store=' . urlencode($store_slug);
    }
    header('Location: ' . $redirect_url);
    exit();
}

if (!$package_id || empty($beneficiary_number)) {
    setFlashMessage('error', 'Invalid request.');
    
    // Ensure session is written before redirect
    session_write_close();
    
    $redirect_url = SITE_URL . '/customer/buy-data.php';
    if (!empty($store_slug)) {
        $redirect_url .= '?store=' . urlencode($store_slug);
    }
    header('Location: ' . $redirect_url);
    exit();
}

if (empty($order_submit_token) || empty($_SESSION['order_submit_token']) || !hash_equals($_SESSION['order_submit_token'], $order_submit_token)) {
    setFlashMessage('error', 'This order was already processed or the session expired. Please start again.');
    
    // Prevent token reuse
    unset($_SESSION['order_submit_token']);
    session_write_close();
    
    $redirect_url = SITE_URL . '/customer/buy-data.php';
    if (!empty($store_slug)) {
        $redirect_url .= '?store=' . urlencode($store_slug);
    }
    header('Location: ' . $redirect_url);
    exit();
}

// Prevent duplicate submissions with the same token
unset($_SESSION['order_submit_token']);

$bundle_orders_auto_increment = true;
$transactions_auto_increment = true;
$commissions_auto_increment = true;
if (function_exists('dbh_ensure_auto_increment')) {
    $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
    $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
    $commissions_auto_increment = dbh_ensure_auto_increment('commissions');
}

// Normalize phone number
$formatted_phone = formatPhone($beneficiary_number);

try {
    // Fetch package with pricing (agent custom pricing if available, otherwise customer pricing)
    // Handle both new package_pricing table and legacy data_packages.price column
    $stmt = $db->prepare('
        SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.price as legacy_price, dp.network_id,
               COALESCE(n.name, "Unknown") AS network_name,
               COALESCE(dp.stock_status, "in_stock") AS stock_status,
               COALESCE(pp_customer.price, pp_customer_fallback.price, dp.price, 0) AS customer_price,
               COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
               COALESCE(pp_vip.price, dp.price, 0) AS vip_wholesale_price,
               acp.custom_price AS agent_custom_price
        FROM data_packages dp
        LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = ?
        LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = "customer"
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
        LEFT JOIN package_pricing pp_vip ON pp_vip.package_id = dp.id AND pp_vip.user_type = "vip"
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
        WHERE dp.id = ? AND dp.status = "active" AND COALESCE(dp.stock_status, "in_stock") = "in_stock" AND (pp_customer.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0)
    ');
    $stmt->bind_param('sii', $customer_pricing_type, $agent_id, $package_id);
    $stmt->execute();
    $pkgRes = $stmt->get_result();
    $package = $pkgRes->fetch_assoc();

    if (!$package) {
        setFlashMessage('error', 'Selected package is currently out of stock or unavailable.');
        
        // Ensure session is written before redirect
        session_write_close();
        
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    // Validate beneficiary number against selected network
    $network_label = strtolower(trim((string) ($package['network_name'] ?? '')));
    $network_display = $package['network_name'] ?? 'network';
    $requires_validation = false;
    $phone_valid = true;
    if ($network_label !== '') {
        if ($network_label === 'mtn' || strpos($network_label, 'mtn') !== false) {
            $requires_validation = true;
            $network_display = 'MTN';
            $phone_valid = isMtnNumber($beneficiary_number);
            if (!$phone_valid && $allow_ported_mtn && validatePhone($beneficiary_number)) {
                $phone_valid = true;
                error_log('Customer purchase: User confirmed ported MTN number for ' . $beneficiary_number);
            }
        } elseif ($network_label === 'at'
            || strpos($network_label, 'airtel') !== false
            || strpos($network_label, 'tigo') !== false
            || strpos($network_label, 'airteltigo') !== false) {
            $requires_validation = true;
            $network_display = 'AT';
            $phone_valid = isAtNumber($beneficiary_number);
        } elseif ($network_label === 'telecel'
            || strpos($network_label, 'vodafone') !== false
            || strpos($network_label, 'voda') !== false) {
            $requires_validation = true;
            $network_display = 'Telecel';
            $phone_valid = isTelecelNumber($beneficiary_number);
        }
    }

    if ($requires_validation && !$phone_valid) {
        setFlashMessage('error', 'Please enter a valid ' . $network_display . ' number for this package.');
        session_write_close();
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    // Use agent custom pricing if available, otherwise use customer pricing
    $customer_price = floatval($package['customer_price']);
    $agent_wholesale_price = floatval($package['agent_wholesale_price']);
    $vip_wholesale_price = floatval($package['vip_wholesale_price'] ?? 0);
    $agent_role = strtolower(trim((string) ($agent_account['role'] ?? 'agent')));
    
    $agent_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $package['agent_custom_price'] !== null) 
        ? floatval($package['agent_custom_price']) 
        : $customer_price;
    
    // Customer pays the agent price, agent is charged the wholesale price
    $price_to_charge_customer = $agent_price;
    $price_to_deduct_from_agent = ($agent_role === 'vip') ? $vip_wholesale_price : $agent_wholesale_price;
    $agent_order_profit = $agent_id > 0
        ? max(0, round($price_to_charge_customer - $price_to_deduct_from_agent, 2))
        : 0.0;

    // Prevent rapid duplicate orders with same payload from flaky refreshes/retries.
    $duplicate_order = findRecentDuplicateBundleOrder(
        (int) $current_user['id'],
        (int) $package_id,
        (string) $formatted_phone,
        (float) $price_to_charge_customer
    );
    if ($duplicate_order) {
        $dup_ref = $duplicate_order['order_reference'] ?? ('#' . (int) ($duplicate_order['id'] ?? 0));
        setFlashMessage('error', 'Duplicate order detected. Recent reference: ' . $dup_ref . '. Please wait before retrying.');
        session_write_close();
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    // Ensure network provider availability before any wallet or transaction changes
    $endpoint_type = detectEndpointTypeForPackage(
        $package['name'] ?? '',
        $package['data_size'] ?? '',
        $package['package_type'] ?? ''
    );
    $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
    if (!$availability['available']) {
        setFlashMessage('error', $availability['message']);
        session_write_close();
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    // Check the buyer wallet only. Store agents do not need a prefunded wallet;
    // their profit is credited after the provider accepts the order.
    if ($agent_id > 0) {
        $customer_balance = getWalletBalance($current_user['id']);
        if ($customer_balance < $price_to_charge_customer) {
            setFlashMessage('error', 'Insufficient wallet balance. Please top up your wallet.');
            
            // Ensure session is written before redirect
            session_write_close();
            
            $redirect_url = SITE_URL . '/customer/buy-data.php';
            if (!empty($store_slug)) {
                $redirect_url .= '?store=' . urlencode($store_slug);
            }
            header('Location: ' . $redirect_url);
            exit();
        }
    } else {
        // Regular customer purchase - check customer wallet balance
        $balance = getWalletBalance($current_user['id']);
        if ($balance < $price_to_charge_customer) {
            setFlashMessage('error', 'Insufficient wallet balance. Please top up your wallet.');
            
            // Ensure session is written before redirect
            session_write_close();
            
            $redirect_url = SITE_URL . '/customer/buy-data.php';
            if (!empty($store_slug)) {
                $redirect_url .= '?store=' . urlencode($store_slug);
            }
            header('Location: ' . $redirect_url);
            exit();
        }
    }

    $db->getConnection()->begin_transaction();

    try {
        // Create bundle order (processing)
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
                $package_id,
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
                $package_id,
                $formatted_phone,
                $price_to_charge_customer,
                $order_ref,
                $order_agent_id,
                $order_agent_cost
            );
            $stmt->execute();
            $order_id = $manual_order_id;
        }

        // Ensure network provider availability before any wallet/transaction changes
        $availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
        if (!$availability['available']) {
            throw new Exception($availability['message']);
        }

        // Create transaction based on payment method
        $txn_ref = $order_ref; // use order reference to keep linkage
        $description = $package['network_name'] . ' ' . $package['data_size'] . ' bundle purchase for ' . $formatted_phone;
        $transaction_id = null;
        if ($agent_id > 0) {
            // Agent store purchase - customer pays from wallet
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

        } else {
            // Regular customer purchase from wallet
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
        }

        if (!empty($transaction_id)) {
            $stmt = $db->prepare('UPDATE bundle_orders SET transaction_id = ? WHERE id = ?');
            $stmt->bind_param('ii', $transaction_id, $order_id);
            $stmt->execute();
        }

        // Update order status to processing first
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();

        $buyer_previous_balance = null;
        $buyer_current_balance = null;
        
        // Handle wallet deductions BEFORE API call
        // Store purchase model: customer pays retail, platform covers wholesale,
        // linked agent receives only the store profit after provider acceptance.
        if ($agent_id > 0) {
            error_log("Agent store purchase: Customer pays GHS {$price_to_charge_customer}, wholesale cost GHS {$price_to_deduct_from_agent}, agent store profit GHS {$agent_order_profit}");
            
            $customer_wallet_before = getWalletBalance($current_user['id']);
            $buyer_previous_balance = $customer_wallet_before;
            $agent_wallet_before = getWalletBalance($agent_id);
            error_log("Customer purchase: Customer wallet before: GHS {$customer_wallet_before}, Agent wallet before: GHS {$agent_wallet_before}");
            
            if (!updateWalletBalance($current_user['id'], $price_to_charge_customer, 'debit', $txn_ref, $description)) {
                error_log("Customer purchase: FAILED to debit customer wallet");
                throw new Exception('Failed to deduct customer wallet');
            }
            
            $buyer_current_balance = getWalletBalance($current_user['id']);
            $customer_wallet_after = $buyer_current_balance;
            $agent_wallet_after_payment = getWalletBalance($agent_id);
            error_log("Customer purchase: Customer wallet after debit: {$customer_wallet_after}, agent wallet unchanged before profit credit: {$agent_wallet_after_payment}");
        } else {
            // Regular customer purchase - deduct from customer wallet only
            $buyer_previous_balance = getWalletBalance($current_user['id']);
            if (!updateWalletBalance($current_user['id'], $price_to_charge_customer, 'debit', $txn_ref, $description)) {
                throw new Exception('Failed to deduct customer wallet');
            }
            $buyer_current_balance = getWalletBalance($current_user['id']);
        }
        
        // Call API provider to deliver the bundle
        require_once __DIR__ . '/../includes/volume_converter.php';
        
        // Convert data size to GB for API call
        $volume_gb = extractVolumeGB($package['data_size']);
        
        // Determine endpoint type (regular/bigtime/special) from package metadata
        $endpoint_type = $endpoint_type ?? 'regular';
        
        // Enhanced logging for debugging
        error_log("Customer purchase: Starting API call for order {$order_id}, package {$package['id']}, phone {$formatted_phone}, volume {$volume_gb}GB");
        
        try {
            $api_result = processBundlePurchase($order_id, $package['network_id'], $formatted_phone, $volume_gb, $endpoint_type);
        } catch (Exception $e) {
            $api_result = ['success' => false, 'error' => $e->getMessage()];
        }
        
        // Enhanced logging for API result
        error_log("Customer purchase API result: " . json_encode($api_result));
        
        if (!$api_result['success']) {
            // API call failed - update order status and refund wallet
            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
            $api_response_json = json_encode($api_result);
            $stmt->bind_param("si", $api_response_json, $order_id);
            $stmt->execute();
            
            // Only the buyer was debited before the API call; no agent wholesale
            // balance was touched in the store flow.
            updateWalletBalance($current_user['id'], $price_to_charge_customer, 'credit', $txn_ref . '_REFUND', 'Refund: ' . $api_result['error']);
            
            // Provide more user-friendly error messages
            $user_error = $api_result['error'] ?? 'Provider API error';
            
            // Check for insufficient balance patterns and provide helpful message
            if (stripos($user_error, 'insufficient balance in provider wallet') !== false) {
                $user_error = 'Service temporarily unavailable due to system maintenance. Your payment has been refunded. Please try again later or contact support.';
            } elseif (stripos($user_error, 'invalid json response') !== false) {
                $user_error = 'Service temporarily unavailable. Your payment has been refunded. Please try again later.';
            }
            
            throw new Exception($user_error);
        }
        
        // Enhanced logging for successful API call
        error_log("Customer purchase: API call successful for order {$order_id}");

        $provider_data = $api_result['provider'] ?? [];
        $provider_name = strtolower(trim((string) ($provider_data['provider_name'] ?? '')));
        $provider_slug = strtolower(trim((string) ($provider_data['provider_slug'] ?? '')));
        $is_hubnet_order = $provider_name === 'hubnet console' || strpos($provider_slug, 'hubnet') !== false;
        $is_datawax_order = strpos($provider_slug, 'datawax') !== false || $provider_name === 'datawax';
        $provider_ref = (string) ($api_result['reference'] ?? '');
        $provider_response_payload = $api_result['response'] ?? $api_result;
        $api_response_json = json_encode($provider_response_payload);
        $order_status_for_notifications = 'processing';

        if ($is_hubnet_order || $is_datawax_order) {
            $provider_status = strtolower(trim((string) (
                $provider_response_payload['delivery_state']
                ?? $provider_response_payload['wc_status']
                ?? $provider_response_payload['status_label']
                ?? $provider_response_payload['status']
                ?? 'processing'
            )));
            if ($provider_status === '' || $provider_status === '1') {
                $provider_status = 'processing';
            }

            $internal_status = in_array($provider_status, ['completed', 'delivered'], true) ? 'delivered' : 'processing';

            $stmt = $db->prepare("
                UPDATE bundle_orders
                SET status = ?, api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()
                    " . ($internal_status === 'delivered' ? ", delivered_at = NOW()" : "") . "
                WHERE id = ?
            ");
            $stmt->bind_param("ssssi", $internal_status, $api_response_json, $provider_status, $provider_ref, $order_id);
            $stmt->execute();
            $order_status_for_notifications = $internal_status;
            error_log("Customer purchase: {$provider_slug} order {$order_id} accepted with provider status {$provider_status}");
        } else {
            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
            $stmt->execute();

            if (function_exists('applyMtnStatusPolicy')) {
                applyMtnStatusPolicy($order_id, 'processing');
            }
            $order_status_for_notifications = 'processing';
            error_log("Customer purchase: Order {$order_id} status updated to processing");
        }

        if (function_exists('recordOrderProfit')) {
            recordOrderProfit([
                'agent_id' => $agent_id,
                'order_id' => $order_id,
                'customer_id' => $current_user['id'],
                'package_id' => $package_id,
                'customer_paid' => $price_to_charge_customer,
                'agent_cost' => $price_to_deduct_from_agent,
                'profit_amount' => $agent_order_profit,
                'reference' => $order_ref,
                'status' => 'earned'
            ]);
        }

        // API call and wallet deductions already handled above

        $db->getConnection()->commit();

        // sendAgentProfitNotification is now handled automatically within recordOrderProfit() in includes/analytics.php
        /*
        if ($agent_id > 0 && $agent_order_profit > 0 && function_exists('sendAgentProfitNotification')) {
            sendAgentProfitNotification([
                'agent_id' => $agent_id,
                'service' => 'Data Bundle Purchase',
                'reference' => $order_ref,
                'customer_name' => $current_user['full_name'] ?? '',
                'customer_email' => $current_user['email'] ?? '',
                'beneficiary_number' => $beneficiary_number,
                'item' => trim(($package['network_name'] ?? 'Data') . ' ' . ($package['data_size'] ?? ($package['name'] ?? 'bundle'))),
                'amount' => $price_to_charge_customer,
                'profit_amount' => $agent_order_profit,
                'payment_method' => 'wallet',
                'status' => $order_status_for_notifications ?? 'delivered',
            ]);
        }
        */
        
        // Enhanced logging for final success
        error_log("Customer purchase: Transaction committed successfully for order {$order_id}");
        error_log("Customer purchase: Final wallet balance for customer {$current_user['id']}: " . getWalletBalance($current_user['id']));
        if ($agent_id > 0) {
            $final_agent_balance = getWalletBalance($agent_id);
            $profit_earned = $agent_order_profit;
            error_log("Customer purchase: Final wallet balance for agent {$agent_id}: GHS {$final_agent_balance}, Profit earned: GHS {$profit_earned}");
        }
        if ($buyer_current_balance === null) {
            $buyer_current_balance = getWalletBalance($current_user['id']);
        }
        if ($buyer_previous_balance === null) {
            $buyer_previous_balance = $buyer_current_balance;
        }

        // Send user and admin notifications with actual order status
        sendUserOrderNotification([
            'order_type' => 'data',
            'order_reference' => $order_ref,
            'order_id' => $order_id,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'customer_role' => $current_user['role'] ?? 'customer',
            'beneficiary_number' => $formatted_phone,
            'network_name' => $package['network_name'] ?? '',
            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
            'amount' => $price_to_charge_customer,
            'payment_method' => 'wallet',
            'status' => $order_status_for_notifications,
            'previous_balance' => $buyer_previous_balance,
            'current_balance' => $buyer_current_balance,
            'source' => !empty($store_slug) ? 'customer_store_order' : 'customer_direct_order'
        ]);

        // Notify admins about new placed data order
        sendAdminDataOrderNotification([
            'order_reference' => $order_ref,
            'order_id' => $order_id,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => $formatted_phone,
            'network_name' => $package['network_name'] ?? '',
            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
            'amount' => $price_to_charge_customer,
            'payment_method' => 'wallet',
            'status' => $order_status_for_notifications,
            'previous_balance' => $buyer_previous_balance,
            'current_balance' => $buyer_current_balance,
            'agent_id' => $agent_id,
            'source' => !empty($store_slug) ? 'customer_store_order' : 'customer_direct_order'
        ]);

        // Log and redirect with enhanced logging
        logActivity($current_user['id'], 'bundle_purchase', 'Customer purchase: ' . $description);
        
        $display_phone = (strlen($formatted_phone) == 12 && substr($formatted_phone, 0, 3) == '233')
            ? '0' . substr($formatted_phone, 3)
            : $formatted_phone;
        $success_message = buildBundleSuccessMessage($package['data_size'], $display_phone);
        setFlashMessage('success', $success_message);
        
        // Ensure session is written before redirect to prevent flash message timing issues
        session_write_close();
        
        error_log("Customer purchase: Success message set - {$success_message}");
        error_log("Customer purchase: Redirecting to buy-data.php");
        
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        
        error_log("Customer purchase: Final redirect URL - {$redirect_url}");
        header('Location: ' . $redirect_url);
        exit();

    } catch (Exception $e) {
        $db->getConnection()->rollback();
        error_log('Customer order error: ' . $e->getMessage());
        $error_message = $e->getMessage();
        if (stripos($error_message, 'Network is busy') !== false) {
            setFlashMessage('error', $error_message);
        } else {
            setFlashMessage('error', 'Purchase failed: ' . $error_message);
        }
        
        // Ensure session is written before redirect to prevent flash message timing issues
        session_write_close();
        
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

} catch (Exception $e) {
    error_log('Customer order exception: ' . $e->getMessage());
    $error_message = $e->getMessage();
    if (stripos($error_message, 'Network is busy') !== false) {
        setFlashMessage('error', $error_message);
    } else {
        setFlashMessage('error', 'Unexpected error. Please try again.');
    }
    
    // Ensure session is written before redirect to prevent flash message timing issues
    session_write_close();
    
    $redirect_url = SITE_URL . '/customer/buy-data.php';
    if (!empty($store_slug)) {
        $redirect_url .= '?store=' . urlencode($store_slug);
    }
    header('Location: ' . $redirect_url);
    exit();
}
