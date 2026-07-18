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

$package_id = intval($_POST['package_id'] ?? 0);
$beneficiary_number = sanitize($_POST['beneficiary_number'] ?? '');
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
    $agent_stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' AND status = 'active'");
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
        SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.price as legacy_price, dp.network_id,
               COALESCE(n.name, "Unknown") AS network_name,
               COALESCE(pp_customer.price, dp.price, 0) AS customer_price,
               COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
               acp.custom_price AS agent_custom_price
        FROM data_packages dp
        LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
        WHERE dp.id = ? AND dp.status = "active" AND (pp_customer.price IS NOT NULL OR dp.price > 0)
    ');
    $stmt->bind_param('ii', $agent_id, $package_id);
    $stmt->execute();
    $pkgRes = $stmt->get_result();
    $package = $pkgRes->fetch_assoc();

    if (!$package) {
        setFlashMessage('error', 'Selected package is not available.');
        
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
    $agent_price = ($agent_id > 0 && $package['agent_custom_price'] !== null) 
        ? floatval($package['agent_custom_price']) 
        : $customer_price;
    
    // Customer pays the agent price, agent is charged the wholesale price
    $price_to_charge_customer = $agent_price;
    $price_to_deduct_from_agent = $agent_wholesale_price;

    // Ensure network provider availability before any wallet or transaction changes
    $endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false ||
                     strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';
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

    $duplicate_order = findRecentDuplicateBundleOrder(
        (int) $current_user['id'],
        (int) $package_id,
        $formatted_phone,
        (float) $price_to_charge_customer,
        180
    );
    if ($duplicate_order) {
        $dup_ref = $duplicate_order['order_reference'] ?? ('#' . (int) ($duplicate_order['id'] ?? 0));
        setFlashMessage('info', 'Similar order already received recently (Ref: ' . $dup_ref . '). Please wait before trying again.');
        session_write_close();
        $redirect_url = SITE_URL . '/customer/buy-data.php';
        if (!empty($store_slug)) {
            $redirect_url .= '?store=' . urlencode($store_slug);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    $payment_method = strtolower(trim((string) ($_POST['payment_method'] ?? 'wallet')));
    if (!in_array($payment_method, ['wallet', 'paystack', 'moolre'], true)) {
        $payment_method = 'wallet';
    }

    if ($payment_method !== 'wallet') {
        if (!isPaymentGatewayEnabled($payment_method)) {
            setFlashMessage('error', 'Selected payment gateway is currently unavailable.');
            session_write_close();
            $redirect_url = SITE_URL . '/customer/buy-data.php';
            if (!empty($store_slug)) {
                $redirect_url .= '?store=' . urlencode($store_slug);
            }
            header('Location: ' . $redirect_url);
            exit();
        }

        if (function_exists('findRecentGuestBundleTransaction')) {
            $recent_txn = findRecentGuestBundleTransaction($current_user['id'], $package_id, $formatted_phone, $price_to_charge_customer, 180);
            if ($recent_txn) {
                $tx_status = strtolower(trim((string) ($recent_txn['status'] ?? '')));
                if ($tx_status === 'pending' || $tx_status === 'processing') {
                    setFlashMessage('info', 'A similar payment is already in progress. Please complete it before starting another one.');
                    session_write_close();
                    $redirect_url = SITE_URL . '/customer/buy-data.php';
                    if (!empty($store_slug)) {
                        $redirect_url .= '?store=' . urlencode($store_slug);
                    }
                    header('Location: ' . $redirect_url);
                    exit();
                }
            }
        }

        $init_error = '';
        $auth_url = initializeGatewayBundlePurchase(
            $current_user['id'],
            $current_user['email'],
            $package_id,
            $formatted_phone,
            $price_to_charge_customer,
            $price_to_deduct_from_agent,
            $agent_id,
            $store_slug,
            $payment_method,
            $init_error
        );

        if ($auth_url) {
            header('Location: ' . $auth_url);
            exit();
        } else {
            setFlashMessage('error', $init_error ?: 'Failed to initialize gateway payment.');
            session_write_close();
            $redirect_url = SITE_URL . '/customer/buy-data.php';
            if (!empty($store_slug)) {
                $redirect_url .= '?store=' . urlencode($store_slug);
            }
            header('Location: ' . $redirect_url);
            exit();
        }
    }

    // Check wallet balances for both customer and agent in agent store purchases
    if ($agent_id > 0) {
        // Agent store purchase - check both customer wallet (for payment) and agent wallet (for API cost)
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
        
        // Check if agent has enough balance to cover the API provider cost
        // Note: Agent will receive customer payment first, then pay wholesale cost
        $agent_balance = getWalletBalance($agent_id);
        $agent_balance_after_customer_payment = $agent_balance + $price_to_charge_customer;
        if ($agent_balance_after_customer_payment < $price_to_deduct_from_agent) {
            $required_balance = $price_to_deduct_from_agent - $price_to_charge_customer;
            setFlashMessage('error', "Agent has insufficient balance to fulfill this order. Agent needs at least ₵" . number_format($required_balance, 2) . " additional balance. Please contact the agent.");
            
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
                'iisisid',
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
                'iiisisid',
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
        
        // Initialize agent transaction variables for agent store purchases
        $agent_txn_ref = null;
        $agent_description = null;
        
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

            
            // Create agent debit transaction
            $agent_txn_ref = generateReference('TXN');
            $agent_description = 'Order fulfillment cost for ' . $description;
            // Create agent debit transaction with proper variable binding
            $order_cost_type = 'purchase';  // Use valid ENUM value instead of 'order_cost'
            $success_status = 'success';
            if ($transactions_auto_increment) {
                $stmt = $db->prepare('INSERT INTO transactions (user_id, amount, transaction_type, reference, status, description) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('idssss', $agent_id, $price_to_deduct_from_agent, $order_cost_type, $agent_txn_ref, $success_status, $agent_description);
            } else {
                $manual_agent_txn_id = dbh_generate_next_id('transactions');
                $stmt = $db->prepare('INSERT INTO transactions (id, user_id, amount, transaction_type, reference, status, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iidssss', $manual_agent_txn_id, $agent_id, $price_to_deduct_from_agent, $order_cost_type, $agent_txn_ref, $success_status, $agent_description);
            }
            $stmt->execute();
            $agent_transaction_id = $transactions_auto_increment ? $db->lastInsertId() : $manual_agent_txn_id;
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
        
        // Handle wallet deductions BEFORE API call
        // Correct business model: Customer pays Agent, Agent pays Admin (wholesale price), Agent keeps profit
        if ($agent_id > 0) {
            // Agent store purchase - proper profit flow implementation
            error_log("Agent store purchase: Customer pays ₵{$price_to_charge_customer}, Agent pays ₵{$price_to_deduct_from_agent} wholesale, Agent profit: ₵" . ($price_to_charge_customer - $price_to_deduct_from_agent));
            
            $customer_wallet_before = getWalletBalance($current_user['id']);
            $agent_wallet_before = getWalletBalance($agent_id);
            error_log("Customer purchase: Customer wallet before: ₵{$customer_wallet_before}, Agent wallet before: ₵{$agent_wallet_before}");
            
            // Step 1: Transfer from customer wallet to agent wallet (customer payment to agent)
            $customer_to_agent_desc = 'Payment to agent for ' . $description;
            $agent_receive_desc = 'Payment received from customer for ' . $description;
            
            if (!transferWalletBalance($current_user['id'], $agent_id, $price_to_charge_customer, $txn_ref, $customer_to_agent_desc)) {
                error_log("Customer purchase: FAILED to transfer from customer to agent");
                throw new Exception('Failed to process customer payment to agent');
            }
            
            $customer_wallet_after = getWalletBalance($current_user['id']);
            $agent_wallet_after_payment = getWalletBalance($agent_id);
            error_log("Customer purchase: After customer->agent transfer - Customer: ₵{$customer_wallet_after}, Agent: ₵{$agent_wallet_after_payment}");
            
            // Step 2: Deduct wholesale cost from agent wallet (agent payment to admin)
            if (!updateWalletBalance($agent_id, $price_to_deduct_from_agent, 'debit', $agent_txn_ref, $agent_description)) {
                error_log("Customer purchase: FAILED to deduct wholesale cost from agent wallet");
                // Refund customer by reversing the transfer
                transferWalletBalance($agent_id, $current_user['id'], $price_to_charge_customer, $txn_ref . '_REFUND', 'Refund: Agent wholesale deduction failed');
                throw new Exception('Failed to deduct agent wholesale cost');
            }
            
            $agent_wallet_final = getWalletBalance($agent_id);
            $agent_profit = $price_to_charge_customer - $price_to_deduct_from_agent;
            error_log("Customer purchase: Final agent wallet: ₵{$agent_wallet_final}, Agent profit earned: ₵{$agent_profit}");
        } else {
            // Regular customer purchase - deduct from customer wallet only
            if (!updateWalletBalance($current_user['id'], $price_to_charge_customer, 'debit', $txn_ref, $description)) {
                throw new Exception('Failed to deduct customer wallet');
            }
        }
        
        // Call API provider to deliver the bundle
        require_once __DIR__ . '/../includes/volume_converter.php';
        
        // Convert data size to GB for API call
        $volume_gb = extractVolumeGB($package['data_size']);
        
        // Determine endpoint type (regular or bigtime)
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
            
            // Refund wallets based on corrected logic
            if ($agent_id > 0) {
                // Agent store purchase - reverse the transactions in order
                // 1. Refund agent wholesale cost
                updateWalletBalance($agent_id, $price_to_deduct_from_agent, 'credit', $agent_txn_ref . '_REFUND', 'Refund: ' . $api_result['error']);
                // 2. Transfer back from agent to customer
                transferWalletBalance($agent_id, $current_user['id'], $price_to_charge_customer, $txn_ref . '_REFUND', 'Refund: ' . $api_result['error']);
            } else {
                // Regular customer purchase - refund customer wallet
                updateWalletBalance($current_user['id'], $price_to_charge_customer, 'credit', $txn_ref . '_REFUND', 'Refund: ' . $api_result['error']);
            }
            
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

        // API call successful - update order status
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'delivered', api_response = ?, provider_reference = ?, delivered_at = NOW() WHERE id = ?");
        $api_response_json = json_encode($api_result);
        $provider_ref = $api_result['reference'] ?? '';
        $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
        $stmt->execute();

        if (function_exists('applyMtnStatusPolicy')) {
            applyMtnStatusPolicy($order_id, 'delivered');
        }

        error_log("Customer purchase: Order {$order_id} status updated to delivered");

        if (function_exists('recordOrderProfit')) {
            recordOrderProfit([
                'agent_id' => $agent_id,
                'order_id' => $order_id,
                'customer_id' => $current_user['id'],
                'package_id' => $package_id,
                'customer_paid' => $price_to_charge_customer,
                'agent_cost' => $price_to_deduct_from_agent,
                'reference' => $order_ref,
                'status' => 'earned'
            ]);
        }

        // API call and wallet deductions already handled above

        // Update bundle order with API response
        $stmt = $db->prepare('UPDATE bundle_orders SET api_response = ?, updated_at = NOW() WHERE id = ?');
        $api_response_json = json_encode($api_result['response']);
        $stmt->bind_param('si', $api_response_json, $order_id);
        $stmt->execute();

        $db->getConnection()->commit();
        
        // Enhanced logging for final success
        error_log("Customer purchase: Transaction committed successfully for order {$order_id}");
        error_log("Customer purchase: Final wallet balance for customer {$current_user['id']}: " . getWalletBalance($current_user['id']));
        if ($agent_id > 0) {
            $final_agent_balance = getWalletBalance($agent_id);
            $profit_earned = $price_to_charge_customer - $price_to_deduct_from_agent;
            error_log("Customer purchase: Final wallet balance for agent {$agent_id}: ₵{$final_agent_balance}, Profit earned: ₵{$profit_earned}");
        }

        // Send order confirmation email
        $order_data = [
            'order_id' => $txn_ref,
            'network_name' => $package['network_name'],
            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
            'phone_number' => $formatted_phone,
            'amount' => $price_to_charge_customer,
            'status' => 'Completed'
        ];
        
        sendOrderConfirmationEmail($current_user['email'], $current_user['full_name'], $order_data);

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
            'status' => 'delivered',
            'agent_id' => $agent_id,
            'source' => !empty($store_slug) ? 'customer_store_order' : 'customer_direct_order'
        ]);

        // Log and redirect with enhanced logging
        logActivity($current_user['id'], 'bundle_purchase', 'Customer purchase: ' . $description);
        
        $success_message = 'Purchase successful! ' . $package['data_size'] . ' sent to ' . $formatted_phone . '.';
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
