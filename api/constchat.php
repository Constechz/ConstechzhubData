<?php
require_once '../config/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$payload = $method === 'POST' ? (json_decode($raw, true) ?: []) : $_GET;
$action = $payload['action'] ?? '';

$guest_allowed_actions = ['guest_ask_question', 'guest_list_knowledge'];
if (!isLoggedIn() && !in_array($action, $guest_allowed_actions, true)) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if ($method === 'POST') {
    if (!$csrfHeader && isset($payload['csrf_token'])) {
        $csrfHeader = $payload['csrf_token'];
    }
    if (!validateCSRF($csrfHeader)) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
    }
}

$current = isLoggedIn() ? getCurrentUser() : null;

// Role check for admin-only actions
$admin_actions = ['admin_list_submissions', 'admin_reply_submission', 'admin_list_knowledge', 'admin_save_knowledge', 'admin_delete_knowledge'];
if (in_array($action, $admin_actions)) {
    if (!$current || $current['role'] !== 'admin') {
        jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function findBestAnswer($db, $user_query) {
    // 1. Clean query
    $query = preg_replace('/[^a-zA-Z0-9\s]/', '', strtolower($user_query));
    $words = array_filter(explode(' ', $query), function($w) {
        return strlen($w) > 2; // only words longer than 2 chars
    });
    
    // 2. Check for agent store searches
    if (preg_match('/\b(store|stores|location|locations|place|places|office|offices|branch|branches|shop|shops|agent|agents)\b/', $query)) {
        $stmt = $db->prepare("
            SELECT ast.store_name, ast.store_slug, u.full_name AS agent_name 
            FROM agent_stores ast 
            JOIN users u ON ast.agent_id = u.id 
            WHERE ast.is_active = 1 AND u.status = 'active' 
            LIMIT 5
        ");
        $stmt->execute();
        $res = $stmt->get_result();
        $stores = [];
        while ($row = $res->fetch_assoc()) {
            $stores[] = "• <strong>" . htmlspecialchars($row['store_name']) . "</strong> (by " . htmlspecialchars($row['agent_name']) . ") - [Visit Store](" . SITE_URL . "/store/index.php?store=" . urlencode($row['store_slug']) . ")";
        }
        if (!empty($stores)) {
            return "Here are some of the active agent stores/places registered on " . SITE_NAME . ":\n\n" . implode("\n", $stores) . "\n\nYou can recommend a new place or submit feedback using the 'Add Place' option!";
        }
    }

    // 3. Search knowledge base
    $res = $db->query("SELECT * FROM constchat_knowledge");
    if (!$res) {
        return null;
    }
    
    $best_match = null;
    $max_score = 0;
    
    while ($row = $res->fetch_assoc()) {
        $score = 0;
        $db_question = strtolower($row['question']);
        $db_keywords = array_filter(explode(',', strtolower($row['keywords'])));
        
        // Exact match
        if ($db_question === $query) {
            $score += 100;
        }
        
        // Substring match
        if (strpos($query, $db_question) !== false || strpos($db_question, $query) !== false) {
            $score += 50;
        }
        
        // Keyword overlap
        foreach ($words as $word) {
            if (strpos($db_question, $word) !== false) {
                $score += 10;
            }
            foreach ($db_keywords as $keyword) {
                $keyword_trimmed = trim($keyword);
                if ($keyword_trimmed !== '' && ($keyword_trimmed === $word || strpos($word, $keyword_trimmed) !== false || strpos($keyword_trimmed, $word) !== false)) {
                    $score += 15;
                }
            }
        }
        
        if ($score > $max_score) {
            $max_score = $score;
            $best_match = $row;
        }
    }
    
    if ($max_score >= 10 && $best_match) {
        return $best_match['answer'];
    }
    
    return null;
}

if (!function_exists('isMtnLocalPhone')) {
    function isMtnLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) return false;
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['024', '025', '053', '054', '055', '059'], true);
    }
}
if (!function_exists('isTelecelLocalPhone')) {
    function isTelecelLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) return false;
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['020', '050', '028'], true);
    }
}
if (!function_exists('isAtLocalPhone')) {
    function isAtLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) return false;
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['026', '056', '027', '057'], true);
    }
}

function dbh_find_matching_package($db, $network_id, $pkg_query) {
    $packages = dbh_get_active_packages($db, $network_id);
    $pkg_query_clean = str_replace(' ', '', strtolower($pkg_query));
    foreach ($packages as $p) {
        $p_name = str_replace(' ', '', strtolower($p['name']));
        $p_size = str_replace(' ', '', strtolower($p['data_size']));
        if ($p_name === $pkg_query_clean || $p_size === $pkg_query_clean) {
            return $p;
        }
    }
    return null;
}

function dbh_get_active_packages($db, $network_id) {
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.data_size, dp.package_type,
               COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
        FROM data_packages dp 
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
        WHERE dp.network_id = ? AND dp.status = 'active' 
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ORDER BY effective_price ASC
    ");
    $stmt->bind_param("i", $network_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $pkgs = [];
    while ($row = $res->fetch_assoc()) {
        $pkgs[] = $row;
    }
    return $pkgs;
}

function dbh_execute_chatbot_order($db, $current, $order) {
    $user_id = $current['id'];
    $package_id = $order['package_id'];
    $formatted_phone = $order['phone'];
    $network_id = $order['network_id'];
    $network_name = $order['network'];
    
    // Fetch package details
    $stmt = $db->prepare("
        SELECT dp.*, 
               COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
        FROM data_packages dp
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
        WHERE dp.id = ? AND dp.status = 'active'
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    ");
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package = $stmt->get_result()->fetch_assoc();
    
    if (!$package) {
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        return "The selected package is no longer available or is out of stock. Order failed.";
    }
    
    $effective_price = $package['effective_price'];
    $wallet_balance = getWalletBalance($user_id);
    if ($wallet_balance < $effective_price) {
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        return "Insufficient wallet balance. This package costs <strong>GH¢ " . number_format($effective_price, 2) . "</strong>, but your wallet balance is <strong>GH¢ " . number_format($wallet_balance, 2) . "</strong>. Please top up your wallet.";
    }
    
    // Check provider availability
    require_once '../includes/api_providers.php';
    $endpoint_type = detectEndpointTypeForPackage(
        $package['name'] ?? '',
        $package['data_size'] ?? '',
        $package['package_type'] ?? ''
    );
    $availability = checkNetworkProviderAvailability($network_id, $endpoint_type);
    if (!$availability['available']) {
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        return "Network Provider Error: " . $availability['message'];
    }
    
    // Check duplicate
    $duplicate_order = findRecentDuplicateBundleOrder(
        (int) $user_id,
        (int) $package_id,
        $formatted_phone,
        (float) $effective_price,
        180
    );
    if ($duplicate_order) {
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        $dup_ref = $duplicate_order['order_reference'] ?? ('#' . (int) ($duplicate_order['id'] ?? 0));
        return "A similar order was already received recently (Ref: <strong>{$dup_ref}</strong>). Please wait before retrying.";
    }
    
    // Prepare auto increments
    $bundle_orders_auto_increment = true;
    $transactions_auto_increment = true;
    $commissions_auto_increment = true;
    if (function_exists('dbh_ensure_auto_increment')) {
        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
        $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
        $commissions_auto_increment = dbh_ensure_auto_increment('commissions');
    }
    
    $order_reference = generateReference($network_name);
    
    $db->getConnection()->begin_transaction();
    try {
        // Create bundle order
        if ($bundle_orders_auto_increment) {
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status) 
                VALUES (?, ?, ?, ?, ?, 'processing')
            ");
            $stmt->bind_param("iisds", $user_id, $package_id, $formatted_phone, $effective_price, $order_reference);
            $stmt->execute();
            $order_id = $db->lastInsertId();
        } else {
            $manual_order_id = dbh_generate_next_id('bundle_orders');
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'processing')
            ");
            $stmt->bind_param("iiisds", $manual_order_id, $user_id, $package_id, $formatted_phone, $effective_price, $order_reference);
            $stmt->execute();
            $order_id = $manual_order_id;
        }

        $commission_amount = function_exists('calculateAgentDataCommissionAmount')
            ? calculateAgentDataCommissionAmount($package['data_size'] ?? '', 1)
            : 0.0;
        
        // Create transaction
        $transaction_ref = generateReference('TXN');
        $description = "{$network_name} " . $package['data_size'] . " bundle purchase for " . $formatted_phone . " via Constchat";
        if ($transactions_auto_increment) {
            $stmt = $db->prepare("
                INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) 
                VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?)
            ");
            $stmt->bind_param("idss", $user_id, $effective_price, $transaction_ref, $description);
            $stmt->execute();
            $transaction_id = $db->lastInsertId();
        } else {
            $manual_transaction_id = dbh_generate_next_id('transactions');
            $stmt = $db->prepare("
                INSERT INTO transactions (id, user_id, transaction_type, amount, status, reference, payment_method, description) 
                VALUES (?, ?, 'purchase', ?, 'success', ?, 'wallet', ?)
            ");
            $stmt->bind_param("iidss", $manual_transaction_id, $user_id, $effective_price, $transaction_ref, $description);
            $stmt->execute();
            $transaction_id = $manual_transaction_id;
        }
        
        // Update order with transaction ID
        $stmt = $db->prepare("UPDATE bundle_orders SET transaction_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $transaction_id, $order_id);
        $stmt->execute();
        
        // Deduct from wallet
        if (!updateWalletBalance($user_id, $effective_price, 'debit', $transaction_ref, $description)) {
            throw new Exception('Insufficient wallet balance');
        }
        
        // Convert data size to GB for API call
        require_once '../includes/volume_converter.php';
        $volume_gb = extractVolumeGB($package['data_size']);
        
        $api_result = processBundlePurchase($order_id, $network_id, $formatted_phone, $volume_gb, $endpoint_type);
        
        $order_status_for_notifications = 'delivered';
        if ($api_result['success']) {
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

            if ($is_hubnet_order) {
                $hubnet_provider_status = strtolower(trim((string) (($api_result['response']['delivery_state'] ?? $api_result['response']['status'] ?? 'processing'))));
                if ($hubnet_provider_status === '') {
                    $hubnet_provider_status = 'processing';
                }

                $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssi", $api_response_json, $hubnet_provider_status, $provider_ref, $order_id);
                $stmt->execute();
                $order_status_for_notifications = 'processing';
            } else {
                $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                $stmt->execute();

                if (function_exists('applyMtnStatusPolicy') && $network_id == 1) {
                    applyMtnStatusPolicy($order_id, 'processing');
                }
                $order_status_for_notifications = 'processing';
            }
        } else {
            // Update order status to failed
            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
            $api_response_json = json_encode($api_result);
            $stmt->bind_param("si", $api_response_json, $order_id);
            $stmt->execute();
            
            // Refund wallet
            updateWalletBalance($user_id, $effective_price, 'credit', $transaction_ref . '_REFUND', 'Refund: ' . ($api_result['error'] ?? 'API delivery failed'));
            throw new Exception('API delivery failed: ' . ($api_result['error'] ?? 'Unknown network provider error'));
        }

        // Record agent commission
        if ($commission_amount > 0 && function_exists('recordAgentCommission')) {
            recordAgentCommission([
                'agent_id' => (int) $user_id,
                'source_type' => 'data',
                'source_id' => (int) $order_id,
                'source_reference' => (string) $order_reference,
                'amount' => $commission_amount,
                'quantity' => 1,
                'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['data_rate_per_gb'] ?? 0) : null,
                'notes' => "{$network_name} " . ($package['data_size'] ?? 'bundle') . ' for ' . $formatted_phone,
            ]);
        }
        
        if ($commission_amount > 0) {
            if ($commissions_auto_increment) {
                $stmt = $db->prepare("
                    INSERT INTO commissions (agent_id, order_id, amount, status) 
                    VALUES (?, ?, ?, 'pending')
                ");
                $stmt->bind_param("iid", $user_id, $order_id, $commission_amount);
                $stmt->execute();
            } else {
                $manual_commission_id = dbh_generate_next_id('commissions');
                $stmt = $db->prepare("
                    INSERT INTO commissions (id, agent_id, order_id, amount, status) 
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $stmt->bind_param("iiid", $manual_commission_id, $user_id, $order_id, $commission_amount);
                $stmt->execute();
            }
        }
        
        $db->getConnection()->commit();

        if (function_exists('sendAdminDataOrderNotification')) {
            sendAdminDataOrderNotification([
                'order_reference' => $order_reference,
                'order_id' => $order_id,
                'user_id' => (int) $user_id,
                'beneficiary_number' => $formatted_phone,
                'amount' => $effective_price,
                'package_name' => $package['name'],
                'network_name' => $network_name,
                'status' => $order_status_for_notifications
            ]);
        }
        
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        return "🎉 <strong>Order Placed Successfully!</strong>\n" .
               "• <strong>Reference</strong>: <code>{$order_reference}</code>\n" .
               "• <strong>Package</strong>: {$package['name']}\n" .
               "• <strong>Price</strong>: GH¢ " . number_format($effective_price, 2) . "\n" .
               "• <strong>Beneficiary</strong>: {$formatted_phone}\n" .
               "• <strong>Status</strong>: Processing\n\n" .
               "The bundle is being placed. You can check its status by typing <code>status {$order_reference}</code>.";

    } catch (Exception $e) {
        $db->getConnection()->rollback();
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_confirming']);
        return "❌ <strong>Order Failed</strong>: " . $e->getMessage();
    }
}

function dbh_get_order_status_msg($db, $user_id, $ref) {
    $ref = ltrim(trim($ref), '#');
    $stmt = $db->prepare("
        SELECT bo.*, dp.name AS package_name, n.name AS network_name 
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        WHERE bo.user_id = ? AND (bo.order_reference = ? OR bo.id = ?)
    ");
    $ref_int = (int) $ref;
    $stmt->bind_param("isi", $user_id, $ref, $ref_int);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $status = ucfirst($row['status']);
        $amount = number_format($row['amount'], 2);
        $date = date('d M Y, h:i A', strtotime($row['created_at']));
        $beneficiary = htmlspecialchars($row['beneficiary_number']);
        $package = htmlspecialchars($row['package_name']);
        $network = htmlspecialchars($row['network_name']);
        
        $msg = "Here are the details for order <strong>{$ref}</strong>:\n" .
               "• <strong>Network</strong>: {$network}\n" .
               "• <strong>Package</strong>: {$package}\n" .
               "• <strong>Beneficiary</strong>: {$beneficiary}\n" .
               "• <strong>Price</strong>: GH¢ {$amount}\n" .
               "• <strong>Date</strong>: {$date}\n" .
               "• <strong>Status</strong>: <strong>{$status}</strong>";
        
        return $msg;
    }
    return "I couldn't find any bundle order with reference <strong>{$ref}</strong> on your account.";
}

function dbh_get_last_order_status_msg($db, $user_id) {
    $stmt = $db->prepare("
        SELECT bo.*, dp.name AS package_name, n.name AS network_name 
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        WHERE bo.user_id = ?
        ORDER BY bo.created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $ref = htmlspecialchars($row['order_reference']);
        $status = ucfirst($row['status']);
        $amount = number_format($row['amount'], 2);
        $date = date('d M Y, h:i A', strtotime($row['created_at']));
        $beneficiary = htmlspecialchars($row['beneficiary_number']);
        $package = htmlspecialchars($row['package_name']);
        $network = htmlspecialchars($row['network_name']);
        
        $msg = "Your most recent order is <strong>{$ref}</strong>:\n" .
               "• <strong>Network</strong>: {$network}\n" .
               "• <strong>Package</strong>: {$package}\n" .
               "• <strong>Beneficiary</strong>: {$beneficiary}\n" .
               "• <strong>Price</strong>: GH¢ {$amount}\n" .
               "• <strong>Date</strong>: {$date}\n" .
               "• <strong>Status</strong>: <strong>{$status}</strong>";
        
        return $msg;
    }
    return "You haven't placed any bundle orders yet.";
}

function dbh_handle_order_flow($db, $current, $question) {
    $user_id = $current['id'];
    $role = $current['role'];
    
    if (!in_array($role, ['agent', 'vip'], true)) {
        return null;
    }

    $wallet_balance = getWalletBalance($user_id);
    
    $question_lower = strtolower($question);
    $is_order_intent = preg_match('/\b(buy|order|purchase|get)\b/i', $question_lower);
    
    // Reset session if user expresses a new order intent
    if ($is_order_intent) {
        unset($_SESSION['constchat_order']);
        unset($_SESSION['constchat_order_packages']);
        unset($_SESSION['constchat_order_confirming']);
    }
    
    // Check if session is active
    if (!isset($_SESSION['constchat_order'])) {
        if (!$is_order_intent) {
            return null;
        }
        
        // Initialize state
        $_SESSION['constchat_order'] = [
            'state' => 'awaiting_network',
            'network' => null,
            'network_id' => null,
            'package_id' => null,
            'package_name' => null,
            'package_price' => null,
            'phone' => null,
        ];
        
        // Try parsing any details provided in the initial question:
        if (preg_match('/\bmtn\b/i', $question_lower)) {
            $_SESSION['constchat_order']['network'] = 'MTN';
            $_SESSION['constchat_order']['network_id'] = 1;
        } elseif (preg_match('/\b(telecel|vodafone)\b/i', $question_lower)) {
            $_SESSION['constchat_order']['network'] = 'Telecel';
            $_SESSION['constchat_order']['network_id'] = 4;
        } elseif (preg_match('/\b(at|airteltigo|airtel|tigo)\b/i', $question_lower)) {
            $_SESSION['constchat_order']['network'] = 'AT';
            $_SESSION['constchat_order']['network_id'] = 2;
        }
        
        if (preg_match('/\b(0\d{9}|233\d{9})\b/', $question, $phone_matches)) {
            $_SESSION['constchat_order']['phone'] = formatPhone($phone_matches[0]);
        }
        
        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:gb|mb))\b/i', $question, $pkg_matches)) {
            $pkg_str = str_replace(' ', '', strtolower($pkg_matches[1]));
            $_SESSION['constchat_order']['temp_package_query'] = $pkg_str;
        }
        
        if ($_SESSION['constchat_order']['network_id'] && isset($_SESSION['constchat_order']['temp_package_query'])) {
            $pkg = dbh_find_matching_package($db, $_SESSION['constchat_order']['network_id'], $_SESSION['constchat_order']['temp_package_query']);
            if ($pkg) {
                $_SESSION['constchat_order']['package_id'] = $pkg['id'];
                $_SESSION['constchat_order']['package_name'] = $pkg['name'];
                $_SESSION['constchat_order']['package_price'] = $pkg['effective_price'];
                unset($_SESSION['constchat_order']['temp_package_query']);
            }
        }
        
        // After initial parsing, let's see which state we are starting in:
        if (!$_SESSION['constchat_order']['network_id']) {
            $_SESSION['constchat_order']['state'] = 'awaiting_network';
            return "Sure! Let's place a data bundle order. Which network would you like to buy?\n" .
                   "1. <strong>MTN</strong>\n" .
                   "2. <strong>AT</strong> (AirtelTigo)\n" .
                   "3. <strong>Telecel</strong>\n\n" .
                   "Please reply with the network name or number (or type <strong>cancel</strong> to abort).";
        }
        
        if (!$_SESSION['constchat_order']['package_id']) {
            $_SESSION['constchat_order']['state'] = 'awaiting_package';
            return dbh_prompt_package($db, $_SESSION['constchat_order']);
        }
        
        if (!$_SESSION['constchat_order']['phone']) {
            $_SESSION['constchat_order']['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        
        $_SESSION['constchat_order']['state'] = 'awaiting_confirmation';
        return dbh_prompt_confirmation($wallet_balance, $_SESSION['constchat_order']);
    }
    
    // We already have an active order session. Process input for the current state:
    $order = &$_SESSION['constchat_order'];
    $input = trim($question);
    
    if ($order['state'] === 'awaiting_network') {
        $input_lower = strtolower($input);
        if ($input_lower === '1' || strpos($input_lower, 'mtn') !== false) {
            $order['network'] = 'MTN';
            $order['network_id'] = 1;
        } elseif ($input_lower === '2' || strpos($input_lower, 'at') !== false || strpos($input_lower, 'airtel') !== false || strpos($input_lower, 'tigo') !== false) {
            $order['network'] = 'AT';
            $order['network_id'] = 2;
        } elseif ($input_lower === '3' || strpos($input_lower, 'telecel') !== false || strpos($input_lower, 'vodafone') !== false) {
            $order['network'] = 'Telecel';
            $order['network_id'] = 4;
        } else {
            return "Invalid network choice. Please reply with <strong>MTN</strong>, <strong>AT</strong>, or <strong>Telecel</strong> (or type <strong>cancel</strong>).";
        }
        
        // Transition to next missing field
        if (!$order['package_id']) {
            $order['state'] = 'awaiting_package';
            return dbh_prompt_package($db, $order);
        }
        if (!$order['phone']) {
            $order['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_confirmation($wallet_balance, $order);
    }
    
    if ($order['state'] === 'awaiting_package') {
        $packages = $_SESSION['constchat_order_packages'] ?? [];
        $selected_pkg = null;
        
        if (ctype_digit($input)) {
            $idx = intval($input) - 1;
            if (isset($packages[$idx])) {
                $selected_pkg = $packages[$idx];
            }
        } else {
            $input_lower = str_replace(' ', '', strtolower($input));
            foreach ($packages as $p) {
                $p_name = str_replace(' ', '', strtolower($p['name']));
                $p_size = str_replace(' ', '', strtolower($p['data_size']));
                if ($p_name === $input_lower || $p_size === $input_lower || strpos($p_name, $input_lower) !== false) {
                    $selected_pkg = $p;
                    break;
                }
            }
        }
        
        if (!$selected_pkg) {
            return "Invalid package selection. Please type a number from the list or package name.";
        }
        
        $order['package_id'] = $selected_pkg['id'];
        $order['package_name'] = $selected_pkg['name'];
        $order['package_price'] = $selected_pkg['effective_price'];
        unset($_SESSION['constchat_order_packages']);
        
        // Transition to next missing field
        if (!$order['phone']) {
            $order['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_confirmation($wallet_balance, $order);
    }
    
    if ($order['state'] === 'awaiting_phone') {
        if (!validatePhone($input)) {
            return "Invalid phone number. Please enter a valid 10-digit Ghanaian phone number starting with 0:";
        }
        
        $formatted = formatPhone($input);
        if ($order['network_id'] === 1 && !isMtnLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical MTN prefixes (024, 025, 053, 054, 055, 059). Please enter a valid MTN number (or type <strong>cancel</strong>).";
        }
        if ($order['network_id'] === 2 && !isAtLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical AT prefixes (026, 056, 027, 057). Please enter a valid AT number (or type <strong>cancel</strong>).";
        }
        if ($order['network_id'] === 4 && !isTelecelLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical Telecel prefixes (020, 050, 028). Please enter a valid Telecel number (or type <strong>cancel</strong>).";
        }
        
        $order['phone'] = $formatted;
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_confirmation($wallet_balance, $order);
    }
    
    if ($order['state'] === 'awaiting_confirmation') {
        $effective_price = $order['package_price'];
        if ($wallet_balance < $effective_price) {
            unset($_SESSION['constchat_order']);
            unset($_SESSION['constchat_order_confirming']);
            return "Insufficient wallet balance. This package costs <strong>GH¢ " . number_format($effective_price, 2) . "</strong>, but your wallet balance is <strong>GH¢ " . number_format($wallet_balance, 2) . "</strong>. Please top up your wallet.";
        }
        
        $input_lower = strtolower($input);
        if (in_array($input_lower, ['confirm', 'yes', 'y', 'ok', 'sure'])) {
            return dbh_execute_chatbot_order($db, $current, $order);
        } elseif (in_array($input_lower, ['cancel', 'no', 'n', 'abort'])) {
            unset($_SESSION['constchat_order']);
            unset($_SESSION['constchat_order_confirming']);
            return "Order cancelled.";
        } else {
            return "Please type <strong>confirm</strong> to place the order, or <strong>cancel</strong> to abort.";
        }
    }
    
    return null;
}

function dbh_prompt_package($db, $order) {
    $packages = dbh_get_active_packages($db, $order['network_id']);
    if (empty($packages)) {
        unset($_SESSION['constchat_order']);
        return "I couldn't find any active packages for {$order['network']} at the moment. Order process aborted.";
    }
    
    $pkg_list = [];
    $idx = 1;
    foreach ($packages as $p) {
        $pkg_list[] = "{$idx}. <strong>{$p['name']}</strong> - GH¢ " . number_format($p['effective_price'], 2);
        $idx++;
    }
    
    $_SESSION['constchat_order_packages'] = $packages;
    
    return "Select a package for <strong>{$order['network']}</strong>:\n\n" . 
           implode("\n", $pkg_list) . "\n\n" .
           "Please reply with the package number or name (e.g. <code>1</code> or <code>5GB</code>).";
}

function dbh_prompt_confirmation($wallet_balance, $order) {
    return "Please confirm your order:\n" .
           "• <strong>Network</strong>: {$order['network']}\n" .
           "• <strong>Package</strong>: {$order['package_name']}\n" .
           "• <strong>Price</strong>: <strong>GH¢ " . number_format($order['package_price'], 2) . "</strong>\n" .
           "• <strong>Beneficiary</strong>: {$order['phone']}\n" .
           "• <strong>Your Balance</strong>: GH¢ " . number_format($wallet_balance, 2) . "\n\n" .
           "Reply with <strong>confirm</strong> to place the order, or <strong>cancel</strong> to abort.";
}

function dbh_get_admin_topup_payment_details($db) {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM topup_settings WHERE user_id IS NULL AND setting_key IN ('admin_topup_account_network', 'admin_topup_account_name', 'admin_topup_account_number', 'admin_topup_instructions')");
    $stmt->execute();
    $result = $stmt->get_result();

    $settings = [
        'network' => 'MTN MOMO',
        'name' => 'Constechzhub Admin',
        'number' => '0245152060',
        'instructions' => 'Send payment to this account, then submit your request with your sender details and transaction reference.'
    ];

    while ($row = $result->fetch_assoc()) {
        $key = $row['setting_key'];
        $value = trim((string) ($row['setting_value'] ?? ''));
        if ($key === 'admin_topup_account_network' && $value !== '') {
            $settings['network'] = $value;
        } elseif ($key === 'admin_topup_account_name' && $value !== '') {
            $settings['name'] = $value;
        } elseif ($key === 'admin_topup_account_number' && $value !== '') {
            $settings['number'] = $value;
        } elseif ($key === 'admin_topup_instructions' && $value !== '') {
            $settings['instructions'] = $value;
        }
    }

    return $settings;
}

function dbh_execute_paystack_topup($db, $current, $amount) {
    if (!isPaymentGatewayEnabled('paystack')) {
        unset($_SESSION['constchat_topup']);
        return "Paystack online payment is currently disabled. Please use manual top-up instead.";
    }

    $admin_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
    
    $isInvalidKey = function ($key) {
        $key = trim((string) $key);
        if ($key === '') return true;
        if (stripos($key, 'your_secret_key_here') !== false) return true;
        return !preg_match('/^sk_(test|live)_/i', $key);
    };

    if ($isInvalidKey($admin_secret_key)) {
        unset($_SESSION['constchat_topup']);
        return "Paystack is not configured properly on the server. Please notify support or use manual top-up.";
    }

    try {
        $reference = generateReference('PAY');
        $description = 'Wallet top-up via Paystack (Constchat)';
        $transaction_type = 'topup';

        $stmt = $db->prepare("
            INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) 
            VALUES (?, ?, ?, 'pending', ?, 'paystack', ?)
        ");
        $stmt->bind_param("isdss", $current['id'], $transaction_type, $amount, $reference, $description);
        $stmt->execute();
        $stmt->close();

        $checkout = initializePaystackCheckout($admin_secret_key, [
            'email' => $current['email'],
            'amount' => (int) round($amount * 100), 
            'currency' => CURRENCY_CODE,
            'reference' => $reference,
            'callback_url' => PAYSTACK_CALLBACK_URL,
            'metadata' => [
                'user_id' => $current['id'],
                'type' => ($current['role'] === 'agent' ? 'agent_wallet_topup' : ($current['role'] === 'vip' ? 'vip_wallet_topup' : 'wallet_topup')),
                'buyer_role' => $current['role'] ?? 'agent',
                'store_slug' => '',
                'payment_recipient' => 'admin'
            ]
        ]);

        if (empty($checkout['ok'])) {
            throw new Exception($checkout['message'] ?? 'Failed to initialize checkout.');
        }

        logActivity($current['id'], 'payment_init', "Paystack payment initialized via chatbot: {$reference}");
        unset($_SESSION['constchat_topup']);

        return "🎉 <strong>Deposit Link Generated Successfully!</strong>\n\n" .
               "Please click the link below to complete your payment of <strong>GH¢ " . number_format($amount, 2) . "</strong> via Paystack:\n" .
               "👉 <a href='{$checkout['authorization_url']}' target='_blank'><strong>Click Here to Pay</strong></a>\n\n" .
               "Reference: <code>{$reference}</code>\n" .
               "Your wallet will be credited automatically once payment is successful.";

    } catch (Exception $e) {
        unset($_SESSION['constchat_topup']);
        return "❌ <strong>Payment Error</strong>: " . $e->getMessage();
    }
}

function dbh_execute_manual_topup($db, $current, $topup) {
    $generate_req_id = function($db) {
        do {
            $requestId = 'TR' . date('Ymd') . mt_rand(10000, 99999);
            $stmt = $db->prepare("SELECT id FROM topup_requests WHERE request_id = ? LIMIT 1");
            $stmt->bind_param('s', $requestId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
        } while ($exists);
        return $requestId;
    };

    try {
        $requestId = $generate_req_id($db);
        $requesterType = $current['role'] === 'agent' ? 'agent' : 'customer';
        $targetType = 'admin';
        
        $stmt = $db->prepare("INSERT INTO topup_requests (request_id, requester_id, requester_type, target_type, target_agent_id, amount, user_email, network, wallet_name, wallet_number, payment_reference) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            'sissdsssss',
            $requestId,
            $current['id'],
            $requesterType,
            $targetType,
            $topup['amount'],
            $current['email'],
            $topup['network'],
            $topup['sender_name'],
            $topup['sender_number'],
            $topup['payment_reference']
        );

        if (!$stmt->execute()) {
            throw new Exception('Database error: failed to insert topup request.');
        }

        $notified = false;
        if (function_exists('notifyAdminForAgentTopupRequest')) {
            $notificationResult = notifyAdminForAgentTopupRequest($db, $current, [
                'request_id' => $requestId,
                'amount' => $topup['amount'],
                'user_email' => $current['email'],
                'sender_network' => $topup['network'],
                'sender_name' => $topup['sender_name'],
                'sender_number' => $topup['sender_number'],
                'payment_reference' => $topup['payment_reference']
            ]);
            $notified = $notificationResult['email_sent'] ?? false;
        } else {
            $adminStmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            if ($adminStmt && $adminStmt->execute()) {
                $admin = $adminStmt->get_result()->fetch_assoc();
                $recipientEmail = trim((string) ($admin['email'] ?? ''));
                if ($recipientEmail === '' && defined('ADMIN_EMAIL')) {
                    $recipientEmail = trim((string) ADMIN_EMAIL);
                }
                if ($recipientEmail !== '') {
                    $subject = "New Topup Request via Chatbot - " . $requestId;
                    $bodyHtml = "<h3>New Topup Request Received</h3>" .
                                "<p><strong>Request ID:</strong> {$requestId}</p>" .
                                "<p><strong>Amount:</strong> GH¢ " . number_format($topup['amount'], 2) . "</p>" .
                                "<p><strong>User:</strong> " . htmlspecialchars($current['full_name']) . "</p>" .
                                "<p><strong>Reference:</strong> " . htmlspecialchars($topup['payment_reference']) . "</p>";
                    sendEmail($recipientEmail, $subject, $bodyHtml);
                    $notified = true;
                }
            }
        }

        logActivity($current['id'], 'agent_topup_request_submitted_to_admin', json_encode([
            'request_id' => $requestId,
            'amount' => $topup['amount'],
            'payment_reference' => $topup['payment_reference'],
            'admin_email_notified' => $notified ? 1 : 0
        ]));

        unset($_SESSION['constchat_topup']);

        return "🎉 <strong>Manual Deposit Request Submitted!</strong>\n\n" .
               "Your top-up request has been received by administrators:\n" .
               "• <strong>Request ID</strong>: <code>{$requestId}</code>\n" .
               "• <strong>Amount</strong>: GH¢ " . number_format($topup['amount'], 2) . "\n" .
               "• <strong>Reference</strong>: <code>{$topup['payment_reference']}</code>\n\n" .
               "Administrators will verify the transaction reference and credit your wallet shortly. You will receive an alert once approved.";

    } catch (Exception $e) {
        unset($_SESSION['constchat_topup']);
        return "❌ <strong>Manual Request Failed</strong>: " . $e->getMessage();
    }
}

function dbh_handle_topup_flow($db, $current, $question) {
    $user_id = $current['id'];
    $role = $current['role'];
    
    if (!in_array($role, ['agent', 'vip'], true)) {
        return null;
    }

    $question_lower = strtolower(trim($question));
    
    if (in_array($question_lower, ['cancel', 'exit', 'abort', 'quit', 'stop'])) {
        if (isset($_SESSION['constchat_topup'])) {
            unset($_SESSION['constchat_topup']);
            return "Top-up process cancelled.";
        }
    }

    $is_topup_intent = preg_match('/\b(fund|top\s*up|deposit|add\s*money)\b/i', $question_lower);

    if ($is_topup_intent) {
        unset($_SESSION['constchat_topup']);
        unset($_SESSION['constchat_order']); 
        unset($_SESSION['constchat_order_packages']);
        unset($_SESSION['constchat_order_confirming']);
    }

    if (!isset($_SESSION['constchat_topup'])) {
        if (!$is_topup_intent) {
            return null;
        }

        $_SESSION['constchat_topup'] = [
            'state' => 'awaiting_method',
            'method' => null,
            'amount' => null,
            'network' => null,
            'sender_name' => null,
            'sender_number' => null,
            'payment_reference' => null,
        ];

        return "💵 <strong>Fund Wallet</strong>\n\n" .
               "How would you like to top up your wallet?\n" .
               "1. <strong>Paystack</strong> (Instant Online Deposit)\n" .
               "2. <strong>Manual Top Up</strong> (Momo/Bank transfer with receipt submission)\n\n" .
               "Please reply with <strong>1</strong> or <strong>2</strong> (or type <strong>cancel</strong> to abort).";
    }

    $topup = &$_SESSION['constchat_topup'];
    $input = trim($question);
    $input_lower = strtolower($input);

    $limits = getEffectiveTopupLimits($user_id, $role);
    $min_allowed = (float)($limits['min'] ?? 5.00);
    $max_allowed = (float)($limits['max'] ?? 10000.00);

    if ($topup['state'] === 'awaiting_method') {
        if ($input_lower === '1' || strpos($input_lower, 'paystack') !== false) {
            $topup['method'] = 'paystack';
            $topup['state'] = 'awaiting_amount';
            return "Please enter the amount you want to deposit (between GH¢ " . number_format($min_allowed, 2) . " and GH¢ " . number_format($max_allowed, 2) . "):";
        } elseif ($input_lower === '2' || strpos($input_lower, 'manual') !== false) {
            $topup['method'] = 'manual';
            $topup['state'] = 'awaiting_amount';
            return "Please enter the amount you want to deposit manually (between GH¢ " . number_format($min_allowed, 2) . " and GH¢ " . number_format($max_allowed, 2) . "):";
        } else {
            return "Invalid choice. Please reply with <strong>1</strong> for Paystack or <strong>2</strong> for Manual Top Up (or type <strong>cancel</strong>).";
        }
    }

    if ($topup['state'] === 'awaiting_amount') {
        if (!is_numeric($input) || floatval($input) <= 0) {
            return "Invalid amount. Please enter a valid number (e.g. 50 or 100):";
        }
        $amount = floatval($input);
        if ($amount < $min_allowed || $amount > $max_allowed) {
            return "Invalid amount. The deposit amount must be between <strong>GH¢ " . number_format($min_allowed, 2) . "</strong> and <strong>GH¢ " . number_format($max_allowed, 2) . "</strong>. Please try again:";
        }
        $topup['amount'] = $amount;

        if ($topup['method'] === 'paystack') {
            $topup['state'] = 'awaiting_paystack_confirm';
            return "Confirm payment initiation:\n" .
                   "• <strong>Method</strong>: Paystack (Online)\n" .
                   "• <strong>Amount</strong>: <strong>GH¢ " . number_format($amount, 2) . "</strong>\n\n" .
                   "Reply with <strong>confirm</strong> to generate your deposit link, or <strong>cancel</strong> to abort.";
        } else {
            $payment_details = dbh_get_admin_topup_payment_details($db);
            $topup['state'] = 'awaiting_manual_network';
            return "Please send the payment of <strong>GH¢ " . number_format($amount, 2) . "</strong> to the following account:\n" .
                   "• <strong>Network</strong>: {$payment_details['network']}\n" .
                   "• <strong>Account Name</strong>: {$payment_details['name']}\n" .
                   "• <strong>Account Number</strong>: <code>{$payment_details['number']}</code>\n" .
                   "• <strong>Instructions</strong>: {$payment_details['instructions']}\n\n" .
                   "Once sent, please select the network you paid from:\n" .
                   "1. <strong>MTN</strong>\n" .
                   "2. <strong>AT</strong> (AirtelTigo)\n" .
                   "3. <strong>Telecel</strong>\n\n" .
                   "Please reply with the network name or number.";
        }
    }

    if ($topup['state'] === 'awaiting_paystack_confirm') {
        if (in_array($input_lower, ['confirm', 'yes', 'y', 'ok'])) {
            return dbh_execute_paystack_topup($db, $current, $topup['amount']);
        } else {
            return "Please type <strong>confirm</strong> to proceed or <strong>cancel</strong> to abort.";
        }
    }

    if ($topup['state'] === 'awaiting_manual_network') {
        if ($input_lower === '1' || strpos($input_lower, 'mtn') !== false) {
            $topup['network'] = 'MTN';
        } elseif ($input_lower === '2' || strpos($input_lower, 'at') !== false || strpos($input_lower, 'airtel') !== false || strpos($input_lower, 'tigo') !== false) {
            $topup['network'] = 'AT';
        } elseif ($input_lower === '3' || strpos($input_lower, 'telecel') !== false || strpos($input_lower, 'vodafone') !== false) {
            $topup['network'] = 'Telecel';
        } else {
            return "Invalid network. Please reply with <strong>MTN</strong>, <strong>AT</strong>, or <strong>Telecel</strong>:";
        }
        $topup['state'] = 'awaiting_manual_sender_name';
        return "Please enter the sender's account/wallet name (the name on the wallet/account you sent the money from):";
    }

    if ($topup['state'] === 'awaiting_manual_sender_name') {
        if ($input === '') {
            return "Sender's name cannot be empty. Please enter a valid name:";
        }
        $topup['sender_name'] = $input;
        $topup['state'] = 'awaiting_manual_sender_number';
        return "Please enter the sender's mobile wallet or account number:";
    }

    if ($topup['state'] === 'awaiting_manual_sender_number') {
        if ($input === '') {
            return "Sender's number cannot be empty. Please enter a valid number:";
        }
        $topup['sender_number'] = $input;
        $topup['state'] = 'awaiting_manual_reference';
        return "Please enter the payment transaction reference number (e.g., Transaction ID / Ref):";
    }

    if ($topup['state'] === 'awaiting_manual_reference') {
        if ($input === '') {
            return "Reference cannot be empty. Please enter a valid transaction reference:";
        }
        $topup['payment_reference'] = $input;
        $topup['state'] = 'awaiting_manual_confirm';
        return "Please confirm your manual deposit request details:\n" .
               "• <strong>Method</strong>: Manual Top Up\n" .
               "• <strong>Amount</strong>: <strong>GH¢ " . number_format($topup['amount'], 2) . "</strong>\n" .
               "• <strong>Sender Network</strong>: {$topup['network']}\n" .
               "• <strong>Sender Name</strong>: {$topup['sender_name']}\n" .
               "• <strong>Sender Number</strong>: {$topup['sender_number']}\n" .
               "• <strong>Reference</strong>: <code>{$topup['payment_reference']}</code>\n\n" .
               "Reply with <strong>confirm</strong> to submit this request to administrators, or <strong>cancel</strong> to abort.";
    }

    if ($topup['state'] === 'awaiting_manual_confirm') {
        if (in_array($input_lower, ['confirm', 'yes', 'y', 'ok'])) {
            return dbh_execute_manual_topup($db, $current, $topup);
        } else {
            return "Please type <strong>confirm</strong> to submit the request or <strong>cancel</strong> to abort.";
        }
    }

    return null;
}

try {
    if ($action === 'ask_question' && $method === 'POST') {
        $question = trim($payload['question'] ?? '');
        if ($question === '') {
            jsonResponse(['status' => 'error', 'message' => 'Question cannot be empty']);
        }
        
        // 1. Intercept cancel/exit commands
        $question_lower = strtolower($question);
        if (in_array($question_lower, ['cancel', 'exit', 'abort', 'quit', 'cancel order', 'cancel topup', 'stop'])) {
            $cancelled = false;
            if (isset($_SESSION['constchat_order'])) {
                unset($_SESSION['constchat_order']);
                unset($_SESSION['constchat_order_confirming']);
                unset($_SESSION['constchat_order_packages']);
                $cancelled = true;
            }
            if (isset($_SESSION['constchat_topup'])) {
                unset($_SESSION['constchat_topup']);
                $cancelled = true;
            }
            if ($cancelled) {
                jsonResponse([
                    'status' => 'success',
                    'answer' => 'Process cancelled. Let me know if you need anything else!'
                ]);
            }
        }

        // 1b. Intercept greetings (e.g. Hi, Hello, Hey)
        $clean_question = preg_replace('/[^a-zA-Z\s]/', '', $question_lower);
        $clean_question = trim(preg_replace('/\s+/', ' ', $clean_question));
        if (in_array($clean_question, ['hi', 'hello', 'hey', 'greetings', 'yo', 'hi there', 'hello there', 'hey there', 'howdy'])) {
            $name = htmlspecialchars($current['full_name'] ?? 'User');
            $role = $current['role'] ?? '';
            
            if ($role === 'agent' || $role === 'vip') {
                $answer = "Hi <strong>{$name}</strong>! As an Agent/VIP, you can place data bundle orders by typing <strong>buy data</strong>, fund your wallet by typing <strong>fund wallet</strong>, check your order status by typing <strong>status [order reference]</strong>, or ask questions about MTN, AT, Telecel Business, and result checkers. What would you like to do?";
            } else {
                $answer = "Hi <strong>{$name}</strong>! I am your automated assistant. Ask me about buying data, wallet deposits, result checkers, or how to locate agent stores. You can also recommend new store places or submit suggestions. What would you like to know?";
            }
            
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // 2. Intercept status checks
        // Check if user typed status / check status alone
        if (preg_match('/^(?:check\s+)?(?:order\s+)?status$/i', $question_lower)) {
            $answer = dbh_get_last_order_status_msg($db, $current['id']);
            $answer .= "\n\nTo check a specific order, please reply with <strong>status [order_reference]</strong> (e.g. <code>status MTN_20260606002505_CEFB828D</code>).";
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // Check if user typed status with reference
        if (preg_match('/^(?:check\s+)?(?:order\s+)?status\s+([A-Za-z0-9\-_#]+)$/i', $question, $matches)) {
            $ref = trim($matches[1]);
            $answer = dbh_get_order_status_msg($db, $current['id'], $ref);
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // Check if user typed a reference directly (e.g. MTN_20260606002505_CEFB828D or #123)
        if (preg_match('/^(?:[A-Za-z0-9]{2,15}_\d{8,15}_[A-Fa-f0-9]{4,12}|#\d+)$/i', $question)) {
            $answer = dbh_get_order_status_msg($db, $current['id'], $question);
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        if (preg_match('/\b(?:my\s+)?last\s+order\b/i', $question)) {
            $answer = dbh_get_last_order_status_msg($db, $current['id']);
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // 2b. Handle active or new top-up flow
        $topup_flow_response = dbh_handle_topup_flow($db, $current, $question);
        if ($topup_flow_response !== null) {
            jsonResponse([
                'status' => 'success',
                'answer' => $topup_flow_response
            ]);
        }
        
        // 3. Handle active or new order flow
        $order_flow_response = dbh_handle_order_flow($db, $current, $question);
        if ($order_flow_response !== null) {
            jsonResponse([
                'status' => 'success',
                'answer' => $order_flow_response
            ]);
        }
        
        // 4. Default Q&A fallback
        $answer = findBestAnswer($db, $question);
        $saved = false;
        
        if ($answer === null) {
            // Auto-submit unanswered questions to constchat_submissions
            $title = substr($question, 0, 100);
            if (strlen($question) > 100) $title .= '...';
            
            $stmt = $db->prepare("INSERT INTO constchat_submissions (user_id, type, title, content, status) VALUES (?, 'question', ?, ?, 'pending')");
            $stmt->bind_param('iss', $current['id'], $title, $question);
            $stmt->execute();
            $saved = true;
            
            $answer = "I couldn't find a direct answer in my knowledge base. However, I have automatically saved your question under 'My Submissions'. A support agent will review and reply to it shortly! Alternatively, you can open a formal Support Ticket.";
        }
        
        jsonResponse([
            'status' => 'success',
            'answer' => $answer,
            'auto_saved' => $saved
        ]);
        
    } elseif ($action === 'submit_item' && $method === 'POST') {
        $type = trim($payload['type'] ?? 'question'); // 'question', 'place', 'suggestion'
        $title = trim($payload['title'] ?? '');
        $content = trim($payload['content'] ?? '');
        
        if (!in_array($type, ['question', 'place', 'suggestion'])) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid submission type']);
        }
        if ($title === '' || $content === '') {
            jsonResponse(['status' => 'error', 'message' => 'Title and details are required']);
        }
        
        $stmt = $db->prepare("INSERT INTO constchat_submissions (user_id, type, title, content, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bind_param('isss', $current['id'], $type, $title, $content);
        
        if ($stmt->execute()) {
            logActivity($current['id'], 'constchat_submit_' . $type, json_encode(['title' => $title]));
            jsonResponse(['status' => 'success', 'message' => 'Your submission has been received successfully!']);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Failed to save submission']);
        }
        
    } elseif ($action === 'list_my_submissions') {
        $stmt = $db->prepare("SELECT * FROM constchat_submissions WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param('i', $current['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $submissions = [];
        while ($row = $res->fetch_assoc()) {
            $submissions[] = $row;
        }
        
        jsonResponse(['status' => 'success', 'submissions' => $submissions]);
        
    } elseif ($action === 'list_knowledge') {
        $res = $db->query("SELECT id, question FROM constchat_knowledge ORDER BY RAND() LIMIT 5");
        $kb = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $kb[] = $row;
            }
        }
        jsonResponse(['status' => 'success', 'questions' => $kb]);
        
    } // ADMIN ONLY ACTIONS
    elseif ($action === 'admin_list_submissions') {
        $res = $db->query("
            SELECT cs.*, u.full_name, u.email 
            FROM constchat_submissions cs 
            LEFT JOIN users u ON cs.user_id = u.id 
            ORDER BY cs.created_at DESC
        ");
        
        $submissions = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $submissions[] = $row;
            }
        }
        jsonResponse(['status' => 'success', 'submissions' => $submissions]);
        
    } elseif ($action === 'admin_reply_submission' && $method === 'POST') {
        $id = (int)($payload['id'] ?? 0);
        $response = trim($payload['response'] ?? '');
        $add_to_kb = (bool)($payload['add_to_kb'] ?? false);
        
        if ($id <= 0 || $response === '') {
            jsonResponse(['status' => 'error', 'message' => 'ID and response are required']);
        }
        
        $stmt = $db->prepare("UPDATE constchat_submissions SET response = ?, status = 'replied' WHERE id = ?");
        $stmt->bind_param('si', $response, $id);
        
        if ($stmt->execute()) {
            if ($add_to_kb) {
                $question = trim($payload['question'] ?? '');
                $category = trim($payload['category'] ?? 'general');
                $keywords = trim($payload['keywords'] ?? '');
                
                if ($question !== '') {
                    $stmt_kb = $db->prepare("INSERT INTO constchat_knowledge (category, question, answer, keywords) VALUES (?, ?, ?, ?)");
                    $stmt_kb->bind_param('ssss', $category, $question, $response, $keywords);
                    $stmt_kb->execute();
                }
            }
            jsonResponse(['status' => 'success', 'message' => 'Response saved successfully!']);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Failed to save response']);
        }
        
    } elseif ($action === 'admin_list_knowledge') {
        $res = $db->query("SELECT * FROM constchat_knowledge ORDER BY created_at DESC");
        $kb = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $kb[] = $row;
            }
        }
        jsonResponse(['status' => 'success', 'knowledge' => $kb]);
        
    } elseif ($action === 'admin_save_knowledge' && $method === 'POST') {
        $id = (int)($payload['id'] ?? 0);
        $category = trim($payload['category'] ?? 'general');
        $question = trim($payload['question'] ?? '');
        $answer = trim($payload['answer'] ?? '');
        $keywords = trim($payload['keywords'] ?? '');
        
        if ($question === '' || $answer === '') {
            jsonResponse(['status' => 'error', 'message' => 'Question and answer are required']);
        }
        
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE constchat_knowledge SET category = ?, question = ?, answer = ?, keywords = ? WHERE id = ?");
            $stmt->bind_param('ssssi', $category, $question, $answer, $keywords, $id);
        } else {
            $stmt = $db->prepare("INSERT INTO constchat_knowledge (category, question, answer, keywords) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $category, $question, $answer, $keywords);
        }
        
        if ($stmt->execute()) {
            jsonResponse(['status' => 'success', 'message' => 'Q&A saved successfully!']);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Failed to save Q&A']);
        }
        
    } elseif ($action === 'admin_delete_knowledge' && $method === 'POST') {
        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid ID']);
        }
        
        $stmt = $db->prepare("DELETE FROM constchat_knowledge WHERE id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            jsonResponse(['status' => 'success', 'message' => 'Q&A deleted successfully!']);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Failed to delete Q&A']);
        }
        
    } elseif ($action === 'guest_list_knowledge') {
        $res = $db->query("SELECT id, question FROM constchat_knowledge ORDER BY RAND() LIMIT 5");
        $kb = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $kb[] = $row;
            }
        }
        jsonResponse(['status' => 'success', 'questions' => $kb]);
        
    } elseif ($action === 'guest_ask_question' && $method === 'POST') {
        $question = trim($payload['question'] ?? '');
        $store_slug = sanitize($payload['store_slug'] ?? '');
        
        if ($question === '') {
            jsonResponse(['status' => 'error', 'message' => 'Question cannot be empty']);
        }
        if ($store_slug === '') {
            jsonResponse(['status' => 'error', 'message' => 'Store slug is required']);
        }
        
        // Fetch store + agent info to make sure it's active
        $stmt = $db->prepare("
            SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name, u.email AS agent_email
            FROM agent_stores ast
            JOIN users u ON ast.agent_id = u.id
            WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("s", $store_slug);
        $stmt->execute();
        $store = $stmt->get_result()->fetch_assoc();
        if (!$store) {
            jsonResponse(['status' => 'error', 'message' => 'Store not found or inactive']);
        }
        
        $question_lower = strtolower($question);
        
        // 1. Intercept cancel/exit commands
        if (in_array($question_lower, ['cancel', 'exit', 'abort', 'quit', 'cancel order', 'stop'])) {
            $cancelled = false;
            if (isset($_SESSION['guest_constchat_order'])) {
                unset($_SESSION['guest_constchat_order']);
                unset($_SESSION['guest_constchat_order_packages']);
                $cancelled = true;
            }
            if ($cancelled) {
                jsonResponse([
                    'status' => 'success',
                    'answer' => 'Process cancelled. Let me know if you need anything else!'
                ]);
            }
        }
        
        // 1b. Intercept greetings
        $clean_question = preg_replace('/[^a-zA-Z\s]/', '', $question_lower);
        $clean_question = trim(preg_replace('/\s+/', ' ', $clean_question));
        if (in_array($clean_question, ['hi', 'hello', 'hey', 'greetings', 'yo', 'hi there', 'hello there', 'hey there', 'howdy'])) {
            $storeName = htmlspecialchars($store['store_name']);
            $answer = "Hi! Welcome to **{$storeName}** Constchat. I am your automated store assistant.\n\nYou can place a data bundle order by typing **buy data**, check order status by typing **status [order reference]**, or ask questions. What can I help you with today?";
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // 2. Intercept status checks
        if (preg_match('/^(?:check\s+)?(?:order\s+)?status\s+([A-Za-z0-9\-_#]+)$/i', $question, $matches)) {
            $ref = trim($matches[1]);
            $answer = dbh_get_guest_order_status_msg($db, $ref);
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        if (preg_match('/^(?:[A-Za-z0-9]{2,15}_\d{8,15}_[A-Fa-f0-9]{4,12}|#\d+)$/i', $question)) {
            $answer = dbh_get_guest_order_status_msg($db, $question);
            jsonResponse([
                'status' => 'success',
                'answer' => $answer
            ]);
        }
        
        // 3. Handle guest order flow
        $order_flow_response = dbh_handle_guest_order_flow($db, $store, $question);
        if ($order_flow_response !== null) {
            jsonResponse([
                'status' => 'success',
                'answer' => $order_flow_response
            ]);
        }
        
        // 4. Default Q&A fallback
        $answer = findBestAnswer($db, $question);
        $saved = false;
        
        if ($answer === null) {
            // Auto-submit unanswered questions to constchat_submissions (user_id = NULL)
            $title = substr($question, 0, 100);
            if (strlen($question) > 100) $title .= '...';
            
            $stmt = $db->prepare("INSERT INTO constchat_submissions (user_id, type, title, content, status) VALUES (NULL, 'question', ?, ?, 'pending')");
            $stmt->bind_param('ss', $title, $question);
            $stmt->execute();
            $saved = true;
            
            $answer = "I couldn't find a direct answer in my knowledge base. However, I have automatically saved your question for review. A support representative will look into it shortly!";
        }
        
        jsonResponse([
            'status' => 'success',
            'answer' => $answer,
            'auto_saved' => $saved
        ]);
        
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action or method'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}

// ==========================================
// GUEST CHAT HELPER FUNCTIONS
// ==========================================

function dbh_get_guest_active_packages($db, $agent_id, $network_id) {
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.data_size, dp.package_type,
               COALESCE(acp.custom_price, pp.price, dp.price, 0) AS effective_price
        FROM data_packages dp
        LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
        WHERE dp.network_id = ? AND dp.status = 'active'
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ORDER BY effective_price ASC
    ");
    $stmt->bind_param("ii", $agent_id, $network_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $pkgs = [];
    while ($row = $res->fetch_assoc()) {
        $pkgs[] = $row;
    }
    return $pkgs;
}

function dbh_find_guest_matching_package($db, $agent_id, $network_id, $pkg_query) {
    $packages = dbh_get_guest_active_packages($db, $agent_id, $network_id);
    $pkg_query_clean = str_replace(' ', '', strtolower($pkg_query));
    foreach ($packages as $p) {
        $p_name = str_replace(' ', '', strtolower($p['name']));
        $p_size = str_replace(' ', '', strtolower($p['data_size']));
        if ($p_name === $pkg_query_clean || $p_size === $pkg_query_clean) {
            return $p;
        }
    }
    return null;
}

function dbh_prompt_guest_package($db, $order) {
    $packages = dbh_get_guest_active_packages($db, $order['agent_id'], $order['network_id']);
    if (empty($packages)) {
        unset($_SESSION['guest_constchat_order']);
        return "I couldn't find any active packages for {$order['network']} at the moment. Order process aborted.";
    }
    
    $pkg_list = [];
    $idx = 1;
    foreach ($packages as $p) {
        $pkg_list[] = "{$idx}. <strong>{$p['name']}</strong> - GH¢ " . number_format($p['effective_price'], 2);
        $idx++;
    }
    
    $_SESSION['guest_constchat_order_packages'] = $packages;
    
    return "Select a package for <strong>{$order['network']}</strong>:\n\n" . 
           implode("\n", $pkg_list) . "\n\n" .
           "Please reply with the package number or name (e.g. <code>1</code> or <code>5GB</code>).";
}

function dbh_prompt_guest_confirmation($order) {
    return "Please confirm your order details:\n" .
           "• <strong>Network</strong>: {$order['network']}\n" .
           "• <strong>Package</strong>: {$order['package_name']}\n" .
           "• <strong>Price</strong>: <strong>GH¢ " . number_format($order['package_price'], 2) . "</strong>\n" .
           "• <strong>Beneficiary Number</strong>: {$order['phone']}\n" .
           "• <strong>Your Email</strong>: {$order['email']}\n" .
           "• <strong>Billing Phone</strong>: {$order['billing_phone']}\n\n" .
           "Reply with <strong>confirm</strong> to generate your Paystack payment link, or <strong>cancel</strong> to abort.";
}

function dbh_execute_guest_chatbot_order($db, $order) {
    if (!isPaymentGatewayEnabled('paystack')) {
        unset($_SESSION['guest_constchat_order']);
        return "Paystack online payment is currently disabled by settings. Order failed.";
    }

    $paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
    
    $isInvalidPaystackKey = function ($key) {
        $key = trim((string) $key);
        if ($key === '') return true;
        if (stripos($key, 'your_secret_key_here') !== false) return true;
        return !preg_match('/^sk_(test|live)_/i', $key);
    };

    if ($isInvalidPaystackKey($paystack_secret_key)) {
        unset($_SESSION['guest_constchat_order']);
        return "Paystack payment credentials are not properly configured on the server. Order failed.";
    }

    // Fetch package wholesale price for agent commission tracking
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
               COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price
        FROM data_packages dp
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        WHERE dp.id = ?
    ");
    $stmt->bind_param("i", $order['package_id']);
    $stmt->execute();
    $pkg_info = $stmt->get_result()->fetch_assoc();
    $agent_wholesale_price = $pkg_info ? (float)$pkg_info['agent_wholesale_price'] : $order['package_price'];

    $reference = generateReference('PAY');
    $description = "Guest bundle purchase via chat: " . $order['network'] . " " . $order['package_name'] . " for " . $order['phone'];
    
    $metadata = [
        'type' => 'guest_bundle_purchase',
        'store_slug' => $order['store_slug'],
        'agent_id' => (int)$order['agent_id'],
        'package_id' => (int)$order['package_id'],
        'beneficiary_number' => $order['phone'],
        'allow_ported_mtn' => 0,
        'customer_price' => (float)$order['package_price'],
        'agent_cost' => $agent_wholesale_price,
        'user_id' => 0,
        'email' => $order['email'],
        'buyer_name' => 'Guest Customer',
        'buyer_email' => $order['email'],
        'buyer_role' => 'guest',
        'return_to' => '/store/reference.php?store=' . urlencode($order['store_slug']) . '&lookup=' . urlencode($reference)
    ];
    $metadata_json = json_encode($metadata);

    try {
        $checkout = initializePaystackCheckout($paystack_secret_key, [
            'email' => $order['email'],
            'amount' => (int) round($order['package_price'] * 100),
            'currency' => CURRENCY_CODE,
            'reference' => $reference,
            'callback_url' => PAYSTACK_CALLBACK_URL,
            'metadata' => [
                'type' => 'guest_bundle_purchase',
                'store_slug' => $order['store_slug'],
                'package_id' => (int)$order['package_id'],
                'beneficiary_number' => $order['phone'],
                'user_id' => 0
            ]
        ]);

        if (empty($checkout['ok'])) {
            throw new Exception($checkout['message'] ?? 'Failed to initialize Paystack checkout.');
        }

        // Insert pending transaction
        $stmt = $db->prepare("
            INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
            VALUES (NULL, 'purchase', ?, 'pending', ?, 'paystack', ?, ?)
        ");
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . ($db->getConnection()->error));
        }
        $price = (float)$order['package_price'];
        $stmt->bind_param('dsss', $price, $reference, $description, $metadata_json);
        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }

        unset($_SESSION['guest_constchat_order']);
        unset($_SESSION['guest_constchat_order_packages']);

        return "🎉 <strong>Payment Link Generated!</strong>\n\n" .
               "Please click the link below to pay <strong>GH¢ " . number_format($price, 2) . "</strong> via Paystack to place your order:\n" .
               "👉 <a href='{$checkout['authorization_url']}' target='_blank'><strong>Click Here to Pay via Paystack</strong></a>\n\n" .
               "Reference: <code>{$reference}</code>\n" .
               "Once payment is completed, your bundle order will be placed automatically! You can track it using: <code>status {$reference}</code>";

    } catch (Exception $e) {
        unset($_SESSION['guest_constchat_order']);
        return "❌ <strong>Checkout Error</strong>: " . $e->getMessage();
    }
}

function dbh_handle_guest_order_flow($db, $store, $question) {
    $agent_id = (int)$store['agent_id'];
    $store_slug = $store['store_slug'];
    $question_lower = strtolower($question);
    
    $is_order_intent = preg_match('/\b(buy|order|purchase|get)\b/i', $question_lower);
    
    if ($is_order_intent) {
        unset($_SESSION['guest_constchat_order']);
        unset($_SESSION['guest_constchat_order_packages']);
    }
    
    if (!isset($_SESSION['guest_constchat_order'])) {
        if (!$is_order_intent) {
            return null;
        }
        
        $_SESSION['guest_constchat_order'] = [
            'state' => 'awaiting_network',
            'network' => null,
            'network_id' => null,
            'package_id' => null,
            'package_name' => null,
            'package_price' => null,
            'phone' => null,
            'email' => null,
            'billing_phone' => null,
            'store_slug' => $store_slug,
            'agent_id' => $agent_id
        ];
        
        // Parse initial fields if present
        if (preg_match('/\bmtn\b/i', $question_lower)) {
            $_SESSION['guest_constchat_order']['network'] = 'MTN';
            $_SESSION['guest_constchat_order']['network_id'] = 1;
        } elseif (preg_match('/\b(telecel|vodafone)\b/i', $question_lower)) {
            $_SESSION['guest_constchat_order']['network'] = 'Telecel';
            $_SESSION['guest_constchat_order']['network_id'] = 4;
        } elseif (preg_match('/\b(at|airteltigo|airtel|tigo)\b/i', $question_lower)) {
            $_SESSION['guest_constchat_order']['network'] = 'AT';
            $_SESSION['guest_constchat_order']['network_id'] = 2;
        }
        
        if (preg_match('/\b(0\d{9}|233\d{9})\b/', $question, $phone_matches)) {
            $_SESSION['guest_constchat_order']['phone'] = formatPhone($phone_matches[0]);
        }
        
        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:gb|mb))\b/i', $question, $pkg_matches)) {
            $pkg_str = str_replace(' ', '', strtolower($pkg_matches[1]));
            $_SESSION['guest_constchat_order']['temp_package_query'] = $pkg_str;
        }
        
        if ($_SESSION['guest_constchat_order']['network_id'] && isset($_SESSION['guest_constchat_order']['temp_package_query'])) {
            $pkg = dbh_find_guest_matching_package($db, $agent_id, $_SESSION['guest_constchat_order']['network_id'], $_SESSION['guest_constchat_order']['temp_package_query']);
            if ($pkg) {
                $_SESSION['guest_constchat_order']['package_id'] = $pkg['id'];
                $_SESSION['guest_constchat_order']['package_name'] = $pkg['name'];
                $_SESSION['guest_constchat_order']['package_price'] = $pkg['effective_price'];
                unset($_SESSION['guest_constchat_order']['temp_package_query']);
            }
        }
        
        // Prompt for the first missing field
        if (!$_SESSION['guest_constchat_order']['network_id']) {
            $_SESSION['guest_constchat_order']['state'] = 'awaiting_network';
            return "Sure! Let's place a data bundle order. Which network would you like to buy?\n" .
                   "1. <strong>MTN</strong>\n" .
                   "2. <strong>AT</strong> (AirtelTigo)\n" .
                   "3. <strong>Telecel</strong>\n\n" .
                   "Please reply with the network name or number (or type <strong>cancel</strong> to abort).";
        }
        
        if (!$_SESSION['guest_constchat_order']['package_id']) {
            $_SESSION['guest_constchat_order']['state'] = 'awaiting_package';
            return dbh_prompt_guest_package($db, $_SESSION['guest_constchat_order']);
        }
        
        if (!$_SESSION['guest_constchat_order']['phone']) {
            $_SESSION['guest_constchat_order']['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        
        $_SESSION['guest_constchat_order']['state'] = 'awaiting_email';
        return "Please enter your email address (for payment confirmation):";
    }
    
    $order = &$_SESSION['guest_constchat_order'];
    $input = trim($question);
    $input_lower = strtolower($input);
    
    if ($order['state'] === 'awaiting_network') {
        if ($input === '1' || strpos($input_lower, 'mtn') !== false) {
            $order['network'] = 'MTN';
            $order['network_id'] = 1;
        } elseif ($input === '2' || strpos($input_lower, 'at') !== false || strpos($input_lower, 'airtel') !== false || strpos($input_lower, 'tigo') !== false) {
            $order['network'] = 'AT';
            $order['network_id'] = 2;
        } elseif ($input === '3' || strpos($input_lower, 'telecel') !== false || strpos($input_lower, 'vodafone') !== false) {
            $order['network'] = 'Telecel';
            $order['network_id'] = 4;
        } else {
            return "Invalid network choice. Please reply with <strong>MTN</strong>, <strong>AT</strong>, or <strong>Telecel</strong> (or type <strong>cancel</strong>).";
        }
        
        if (!$order['package_id']) {
            $order['state'] = 'awaiting_package';
            return dbh_prompt_guest_package($db, $order);
        }
        if (!$order['phone']) {
            $order['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        if (!$order['email']) {
            $order['state'] = 'awaiting_email';
            return "Please enter your email address (for payment confirmation):";
        }
        if (!$order['billing_phone']) {
            $order['state'] = 'awaiting_billing_phone';
            return "Please enter your mobile billing phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_guest_confirmation($order);
    }
    
    if ($order['state'] === 'awaiting_package') {
        $packages = $_SESSION['guest_constchat_order_packages'] ?? [];
        $selected_pkg = null;
        
        if (ctype_digit($input)) {
            $idx = intval($input) - 1;
            if (isset($packages[$idx])) {
                $selected_pkg = $packages[$idx];
            }
        } else {
            $input_lower_clean = str_replace(' ', '', $input_lower);
            foreach ($packages as $p) {
                $p_name = str_replace(' ', '', strtolower($p['name']));
                $p_size = str_replace(' ', '', strtolower($p['data_size']));
                if ($p_name === $input_lower_clean || $p_size === $input_lower_clean || strpos($p_name, $input_lower_clean) !== false) {
                    $selected_pkg = $p;
                    break;
                }
            }
        }
        
        if (!$selected_pkg) {
            return "Invalid package selection. Please type a number from the list or package name.";
        }
        
        $order['package_id'] = $selected_pkg['id'];
        $order['package_name'] = $selected_pkg['name'];
        $order['package_price'] = $selected_pkg['effective_price'];
        unset($_SESSION['guest_constchat_order_packages']);
        
        if (!$order['phone']) {
            $order['state'] = 'awaiting_phone';
            return "Please enter the beneficiary phone number:";
        }
        if (!$order['email']) {
            $order['state'] = 'awaiting_email';
            return "Please enter your email address (for payment confirmation):";
        }
        if (!$order['billing_phone']) {
            $order['state'] = 'awaiting_billing_phone';
            return "Please enter your mobile billing phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_guest_confirmation($order);
    }
    
    if ($order['state'] === 'awaiting_phone') {
        if (!validatePhone($input)) {
            return "Invalid phone number. Please enter a valid 10-digit Ghanaian phone number starting with 0:";
        }
        
        $formatted = formatPhone($input);
        if ($order['network_id'] === 1 && !isMtnLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical MTN prefixes (024, 025, 053, 054, 055, 059). Please enter a valid MTN number (or type <strong>cancel</strong>).";
        }
        if ($order['network_id'] === 2 && !isAtLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical AT prefixes (026, 056, 027, 057). Please enter a valid AT number (or type <strong>cancel</strong>).";
        }
        if ($order['network_id'] === 4 && !isTelecelLocalPhone($formatted)) {
            return "The number {$formatted} does not match typical Telecel prefixes (020, 050, 028). Please enter a valid Telecel number (or type <strong>cancel</strong>).";
        }
        
        $order['phone'] = $formatted;
        
        if (!$order['email']) {
            $order['state'] = 'awaiting_email';
            return "Please enter your email address (for payment confirmation):";
        }
        if (!$order['billing_phone']) {
            $order['state'] = 'awaiting_billing_phone';
            return "Please enter your mobile billing phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_guest_confirmation($order);
    }
    
    if ($order['state'] === 'awaiting_email') {
        if (!validateEmail($input)) {
            return "Invalid email address. Please enter a valid email address:";
        }
        $order['email'] = strtolower($input);
        
        if (!$order['billing_phone']) {
            $order['state'] = 'awaiting_billing_phone';
            return "Please enter your mobile billing phone number:";
        }
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_guest_confirmation($order);
    }
    
    if ($order['state'] === 'awaiting_billing_phone') {
        if (!validatePhone($input)) {
            return "Invalid phone number. Please enter a valid 10-digit mobile billing phone number:";
        }
        $order['billing_phone'] = formatPhone($input);
        $order['state'] = 'awaiting_confirmation';
        return dbh_prompt_guest_confirmation($order);
    }
    
    if ($order['state'] === 'awaiting_confirmation') {
        if (in_array($input_lower, ['confirm', 'yes', 'y', 'ok', 'sure'])) {
            return dbh_execute_guest_chatbot_order($db, $order);
        } elseif (in_array($input_lower, ['cancel', 'no', 'n', 'abort'])) {
            unset($_SESSION['guest_constchat_order']);
            unset($_SESSION['guest_constchat_order_packages']);
            return "Order cancelled.";
        } else {
            return "Please type <strong>confirm</strong> to place the order, or <strong>cancel</strong> to abort.";
        }
    }
    
    return null;
}

function dbh_get_guest_order_status_msg($db, $ref) {
    $ref = ltrim(trim($ref), '#');
    $stmt = $db->prepare("
        SELECT bo.*, dp.name AS package_name, n.name AS network_name 
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        WHERE bo.order_reference = ? OR bo.id = ?
    ");
    $ref_int = (int) $ref;
    $stmt->bind_param("si", $ref, $ref_int);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $status = ucfirst($row['status']);
        $amount = number_format($row['amount'], 2);
        $date = date('d M Y, h:i A', strtotime($row['created_at']));
        $beneficiary = htmlspecialchars($row['beneficiary_number']);
        $package = htmlspecialchars($row['package_name']);
        $network = htmlspecialchars($row['network_name']);
        
        $msg = "Here are the details for guest order <strong>{$ref}</strong>:\n" .
               "• <strong>Network</strong>: {$network}\n" .
               "• <strong>Package</strong>: {$package}\n" .
               "• <strong>Beneficiary</strong>: {$beneficiary}\n" .
               "• <strong>Price</strong>: GH¢ {$amount}\n" .
               "• <strong>Date</strong>: {$date}\n" .
               "• <strong>Status</strong>: <strong>{$status}</strong>";
        
        return $msg;
    }
    return "I couldn't find any bundle order with reference <strong>{$ref}</strong>.";
}
