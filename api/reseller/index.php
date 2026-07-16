<?php
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/api_providers.php';
require_once '../../includes/volume_converter.php';

$networksHasCode = function_exists('dbh_table_has_column') ? dbh_table_has_column('networks', 'code') : false;
$networksHasSlug = function_exists('dbh_table_has_column') ? dbh_table_has_column('networks', 'slug') : false;
$networkCodeSelect = $networksHasCode ? 'n.code' : ($networksHasSlug ? 'n.slug' : 'n.name');
$networkCodeColumn = $networksHasCode ? 'code' : ($networksHasSlug ? 'slug' : 'name');

// CORS headers for API access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// API Authentication
function authenticateApiRequest() {
    $headers = getallheaders();
    $api_key = null;
    
    // Check for API key in headers
    if (isset($headers['X-API-Key'])) {
        $api_key = $headers['X-API-Key'];
    } elseif (isset($headers['Authorization'])) {
        $auth_header = $headers['Authorization'];
        if (strpos($auth_header, 'Bearer ') === 0) {
            $api_key = substr($auth_header, 7);
        }
    }
    
    if (!$api_key) {
        return ['success' => false, 'error' => 'API key required', 'code' => 401];
    }
    
    global $db;
    
    // Validate API key and get agent info
    $stmt = $db->prepare("
        SELECT ak.*, aa.status as app_status, u.id as agent_id, u.full_name, u.wallet_balance
        FROM agent_api_keys ak 
        JOIN agent_api_applications aa ON ak.application_id = aa.id 
        JOIN users u ON ak.agent_id = u.id 
        WHERE ak.api_key = ? AND ak.is_active = 1 AND aa.status = 'approved'
    ");
    $stmt->bind_param('s', $api_key);
    $stmt->execute();
    $key_data = $stmt->get_result()->fetch_assoc();
    
    if (!$key_data) {
        return ['success' => false, 'error' => 'Invalid or inactive API key', 'code' => 401];
    }
    
    // Check rate limits
    $rate_limit_check = checkRateLimit($key_data['id'], $key_data);
    if (!$rate_limit_check['allowed']) {
        return ['success' => false, 'error' => 'Rate limit exceeded', 'code' => 429, 'retry_after' => $rate_limit_check['retry_after']];
    }
    
    // Update last used timestamp
    $update_stmt = $db->prepare("UPDATE agent_api_keys SET last_used_at = NOW() WHERE id = ?");
    $update_stmt->bind_param('i', $key_data['id']);
    $update_stmt->execute();
    
    return ['success' => true, 'agent' => $key_data];
}

// Rate limiting function
function checkRateLimit($api_key_id, $key_data) {
    global $db;
    
    $current_time = time();
    $windows = [
        'minute' => ['limit' => $key_data['rate_limit_per_minute'], 'seconds' => 60],
        'hour' => ['limit' => $key_data['rate_limit_per_hour'], 'seconds' => 3600],
        'day' => ['limit' => $key_data['rate_limit_per_day'], 'seconds' => 86400]
    ];
    
    foreach ($windows as $window_type => $config) {
        $window_start = floor($current_time / $config['seconds']) * $config['seconds'];
        $window_start_mysql = date('Y-m-d H:i:s', $window_start);
        
        // Get or create rate limit record
        $stmt = $db->prepare("
            INSERT INTO agent_api_rate_limits (api_key_id, time_window, window_start, request_count) 
            VALUES (?, ?, ?, 1) 
            ON DUPLICATE KEY UPDATE request_count = request_count + 1
        ");
        $stmt->bind_param('iss', $api_key_id, $window_type, $window_start_mysql);
        $stmt->execute();
        
        // Check if limit exceeded
        $check_stmt = $db->prepare("
            SELECT request_count FROM agent_api_rate_limits 
            WHERE api_key_id = ? AND time_window = ? AND window_start = ?
        ");
        $check_stmt->bind_param('iss', $api_key_id, $window_type, $window_start_mysql);
        $check_stmt->execute();
        $count_result = $check_stmt->get_result()->fetch_assoc();
        
        if ($count_result && $count_result['request_count'] > $config['limit']) {
            $retry_after = ($window_start + $config['seconds']) - $current_time;
            return ['allowed' => false, 'retry_after' => $retry_after];
        }
    }
    
    return ['allowed' => true];
}

// Log API usage
function logApiUsage($api_key_id, $agent_id, $endpoint, $method, $request_data, $response_code, $response_data, $processing_time_ms) {
    global $db;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $db->prepare("
        INSERT INTO agent_api_usage_logs 
        (api_key_id, agent_id, endpoint, method, request_data, response_code, response_data, ip_address, user_agent, processing_time_ms) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $request_json = json_encode($request_data);
    $response_json = json_encode($response_data);
    
    $stmt->bind_param('iisssisssi', $api_key_id, $agent_id, $endpoint, $method, $request_json, $response_code, $response_json, $ip_address, $user_agent, $processing_time_ms);
    $stmt->execute();
}

// Main API router
$start_time = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_SERVER['REQUEST_URI'];
$request_data = [];

if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $request_data = json_decode($input, true) ?: [];
} else {
    $request_data = $_GET;
}

// Authenticate request
$auth_result = authenticateApiRequest();
if (!$auth_result['success']) {
    $response = ['success' => false, 'error' => $auth_result['error']];
    http_response_code($auth_result['code']);
    
    if (isset($auth_result['retry_after'])) {
        header('Retry-After: ' . $auth_result['retry_after']);
    }
    
    echo json_encode($response);
    exit();
}

$agent = $auth_result['agent'];
$response = ['success' => false, 'error' => 'Endpoint not found'];
$response_code = 404;

// Route API endpoints
$path = parse_url($endpoint, PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));

// Remove 'api/reseller' from path
if (count($path_parts) >= 2 && $path_parts[0] === 'api' && $path_parts[1] === 'reseller') {
    $path_parts = array_slice($path_parts, 2);
}

try {
    switch ($path_parts[0] ?? '') {
        case 'balance':
            if ($method === 'GET') {
                $response = [
                    'success' => true,
                    'data' => [
                        'balance' => (float)$agent['wallet_balance'],
                        'currency' => 'GHS',
                        'agent_name' => $agent['full_name']
                    ]
                ];
                $response_code = 200;
            }
            break;
            
        case 'networks':
            if ($method === 'GET') {
                $networks_stmt = $db->prepare("SELECT id, name, {$networkCodeColumn} AS code, color FROM networks WHERE is_active = 1 ORDER BY name");
                $networks_stmt->execute();
                $networks = $networks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $response = [
                    'success' => true,
                    'data' => $networks
                ];
                $response_code = 200;
            }
            break;
            
        case 'packages':
            if ($method === 'GET') {
                $network_id = $request_data['network_id'] ?? null;
                
                $sql = "
                    SELECT dp.id, dp.name, dp.volume, dp.validity, dp.price, dp.agent_price, 
                           n.name as network_name, {$networkCodeSelect} as network_code
                    FROM data_packages dp 
                    JOIN networks n ON dp.network_id = n.id 
                    WHERE dp.is_active = 1 AND n.is_active = 1
                ";
                $params = [];
                $param_types = "";
                
                if ($network_id) {
                    $sql .= " AND dp.network_id = ?";
                    $params[] = $network_id;
                    $param_types .= "i";
                }
                
                $sql .= " ORDER BY n.name, dp.price";
                
                $stmt = $db->prepare($sql);
                if (!empty($params)) {
                    $stmt->bind_param($param_types, ...$params);
                }
                $stmt->execute();
                $packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $response = [
                    'success' => true,
                    'data' => $packages
                ];
                $response_code = 200;
            }
            break;
            
        case 'purchase':
            if ($method === 'POST') {
                $required_fields = ['package_id', 'phone_number'];
                $missing_fields = [];
                
                foreach ($required_fields as $field) {
                    if (empty($request_data[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                if (!empty($missing_fields)) {
                    $response = [
                        'success' => false,
                        'error' => 'Missing required fields: ' . implode(', ', $missing_fields)
                    ];
                    $response_code = 400;
                    break;
                }
                
                $package_id = (int)$request_data['package_id'];
                $phone_number = trim($request_data['phone_number']);
                $reference = $request_data['reference'] ?? null;
                
                // Get package details
                $package_stmt = $db->prepare("
                    SELECT dp.*, n.name as network_name, {$networkCodeSelect} as network_code 
                    FROM data_packages dp 
                    JOIN networks n ON dp.network_id = n.id 
                    WHERE dp.id = ? AND dp.is_active = 1
                ");
                $package_stmt->bind_param('i', $package_id);
                $package_stmt->execute();
                $package = $package_stmt->get_result()->fetch_assoc();
                
                if (!$package) {
                    $response = [
                        'success' => false,
                        'error' => 'Package not found or inactive'
                    ];
                    $response_code = 404;
                    break;
                }
                
                // Check agent balance
                if ($agent['wallet_balance'] < $package['agent_price']) {
                    $response = [
                        'success' => false,
                        'error' => 'Insufficient balance',
                        'required' => (float)$package['agent_price'],
                        'available' => (float)$agent['wallet_balance']
                    ];
                    $response_code = 400;
                    break;
                }
                
                // Generate transaction reference
                $txn_ref = $reference ?: 'API_' . time() . '_' . rand(1000, 9999);

                $bundle_orders_auto_increment = true;
                if (function_exists('dbh_ensure_auto_increment')) {
                    $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
                }
                
                // Start transaction
                $db->getConnection()->begin_transaction();
                
                try {
                    $buyer_previous_balance = getWalletBalance($agent['agent_id']);
                    $buyer_current_balance = $buyer_previous_balance;

                    // Deduct from agent wallet
                    $deduct_result = updateWalletBalance($agent['agent_id'], $package['agent_price'], 'debit', $txn_ref, 'API Bundle Purchase: ' . $package['name']);
                    
                    if (!$deduct_result) {
                        throw new Exception('Failed to deduct from wallet');
                    }
                    $buyer_current_balance = getWalletBalance($agent['agent_id']);
                    
                    // Create bundle order
                    if ($bundle_orders_auto_increment) {
                        $order_stmt = $db->prepare("
                            INSERT INTO bundle_orders (user_id, package_id, phone_number, amount, status, transaction_id, reference) 
                            VALUES (?, ?, ?, ?, 'pending', ?, ?)
                        ");
                        $order_stmt->bind_param('iisdss', $agent['agent_id'], $package_id, $phone_number, $package['agent_price'], $txn_ref, $txn_ref);
                        $order_stmt->execute();
                        $order_id = $db->lastInsertId();
                    } else {
                        $manual_order_id = dbh_generate_next_id('bundle_orders');
                        $order_stmt = $db->prepare("
                            INSERT INTO bundle_orders (id, user_id, package_id, phone_number, amount, status, transaction_id, reference) 
                            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
                        ");
                        $order_stmt->bind_param('iiisdss', $manual_order_id, $agent['agent_id'], $package_id, $phone_number, $package['agent_price'], $txn_ref, $txn_ref);
                        $order_stmt->execute();
                        $order_id = $manual_order_id;
                    }
                    
                    // Process bundle purchase via API provider
                    $formatted_phone = formatPhone($phone_number);
                    $volume_gb = extractVolumeGB($package['data_size']);
                    $endpoint_type = detectEndpointTypeForPackage(
                        $package['name'] ?? '',
                        $package['data_size'] ?? '',
                        $package['package_type'] ?? ''
                    );
                    $api_result = processBundlePurchase($order_id, $package['network_id'], $formatted_phone, $volume_gb, $endpoint_type);
                    
                    if ($api_result['success']) {
                        $api_response_json = json_encode($api_result);
                        $provider_ref = (string) ($api_result['reference'] ?? '');
                        $provider_data = $api_result['provider'] ?? [];
                        $provider_name = strtolower(trim((string) ($provider_data['provider_name'] ?? '')));
                        $provider_slug = strtolower(trim((string) ($provider_data['provider_slug'] ?? '')));
                        $normalized_response = strtolower((string) $api_response_json);
                        $is_hubnet_order = $provider_name === 'hubnet console'
                            || strpos($provider_slug, 'hubnet') !== false
                            || strpos($normalized_response, '"provider_slug":"hubnet"') !== false
                            || strpos($normalized_response, '"provider_name":"hubnet console"') !== false;
                        $notification_status = 'success';

                        if ($is_hubnet_order) {
                            $hubnet_provider_status = strtolower(trim((string) (($api_result['response']['delivery_state'] ?? $api_result['response']['status'] ?? 'processing'))));
                            if ($hubnet_provider_status === '') {
                                $hubnet_provider_status = 'processing';
                            }

                            $update_stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                            $update_stmt->bind_param('sssi', $api_response_json, $hubnet_provider_status, $provider_ref, $order_id);
                            $update_stmt->execute();
                            $notification_status = 'processing';
                        } else {
                            $update_stmt = $db->prepare("UPDATE bundle_orders SET status = 'success', api_response = ?, provider_reference = ?, updated_at = NOW() WHERE id = ?");
                            $update_stmt->bind_param('ssi', $api_response_json, $provider_ref, $order_id);
                            $update_stmt->execute();

                            if (function_exists('applyMtnStatusPolicy')) {
                                applyMtnStatusPolicy($order_id, 'success');
                            }
                        }
                        
                        $db->getConnection()->commit();

                        sendUserOrderNotification([
                            'order_type' => 'data',
                            'order_reference' => $txn_ref,
                            'order_id' => $order_id,
                            'user_id' => (int) $agent['agent_id'],
                            'customer_name' => $agent['full_name'] ?? '',
                            'customer_role' => 'agent',
                            'beneficiary_number' => $formatted_phone,
                            'network_name' => $package['network_name'] ?? '',
                            'package_name' => $package['name'] ?? ($package['data_size'] ?? ''),
                            'amount' => (float) $package['agent_price'],
                            'payment_method' => 'wallet',
                            'status' => $notification_status,
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'source' => 'reseller_api'
                        ]);

                        sendAdminDataOrderNotification([
                            'order_reference' => $txn_ref,
                            'order_id' => $order_id,
                            'user_id' => (int) $agent['agent_id'],
                            'customer_name' => $agent['full_name'] ?? '',
                            'beneficiary_number' => $formatted_phone,
                            'network_name' => $package['network_name'] ?? '',
                            'package_name' => $package['name'] ?? ($package['data_size'] ?? ''),
                            'amount' => (float) $package['agent_price'],
                            'payment_method' => 'wallet',
                            'status' => 'success',
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'agent_id' => (int) $agent['agent_id'],
                            'source' => 'reseller_api'
                        ]);
                        
                        $response = [
                            'success' => true,
                            'data' => [
                                'order_id' => $order_id,
                                'reference' => $txn_ref,
                                'package' => $package['name'],
                                'network' => $package['network_name'],
                                'phone_number' => $phone_number,
                                'amount' => (float)$package['agent_price'],
                                'status' => 'success',
                                'message' => 'Bundle purchase successful'
                            ]
                        ];
                        $response_code = 200;
                    } else {
                        // Rollback and refund
                        $db->getConnection()->rollback();
                        updateWalletBalance($agent['agent_id'], $package['agent_price'], 'credit', $txn_ref . '_REFUND', 'API Purchase Refund: ' . $api_result['error']);
                        
                        $response = [
                            'success' => false,
                            'error' => 'Bundle purchase failed: ' . $api_result['error']
                        ];
                        $response_code = 500;
                    }
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    
                    $response = [
                        'success' => false,
                        'error' => 'Transaction failed: ' . $e->getMessage()
                    ];
                    $response_code = 500;
                }
            }
            break;
            
        case 'transactions':
            if ($method === 'GET') {
                $limit = min((int)($request_data['limit'] ?? 50), 100);
                $offset = (int)($request_data['offset'] ?? 0);
                $status = $request_data['status'] ?? null;
                
                $sql = "
                    SELECT bo.id, bo.reference, bo.phone_number, bo.amount, bo.status, bo.created_at,
                           dp.name as package_name, n.name as network_name
                    FROM bundle_orders bo 
                    JOIN data_packages dp ON bo.package_id = dp.id 
                    JOIN networks n ON dp.network_id = n.id 
                    WHERE bo.user_id = ?
                ";
                $params = [$agent['agent_id']];
                $param_types = "i";
                
                if ($status) {
                    $sql .= " AND bo.status = ?";
                    $params[] = $status;
                    $param_types .= "s";
                }
                
                $sql .= " ORDER BY bo.created_at DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                $param_types .= "ii";
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param($param_types, ...$params);
                $stmt->execute();
                $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $response = [
                    'success' => true,
                    'data' => $transactions,
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset
                    ]
                ];
                $response_code = 200;
            }
            break;
            
        default:
            $response = [
                'success' => false,
                'error' => 'Endpoint not found',
                'available_endpoints' => [
                    'GET /balance' => 'Get agent wallet balance',
                    'GET /networks' => 'Get available networks',
                    'GET /packages' => 'Get data packages (optional: ?network_id=X)',
                    'POST /purchase' => 'Purchase data bundle',
                    'GET /transactions' => 'Get transaction history'
                ]
            ];
            $response_code = 404;
    }
} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => 'Internal server error'
    ];
    $response_code = 500;
    
    // Log the error
    error_log('API Error: ' . $e->getMessage());
}

// Calculate processing time
$processing_time_ms = round((microtime(true) - $start_time) * 1000);

// Log API usage
logApiUsage($agent['id'], $agent['agent_id'], $endpoint, $method, $request_data, $response_code, $response, $processing_time_ms);

// Send response
http_response_code($response_code);
echo json_encode($response);
?>
