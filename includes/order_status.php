<?php
/**
 * Order Status Management Functions
 * Handles dynamic order status tracking and provider integration
 */

/**
 * Update order status with proper flow validation
 * @param int $order_id Bundle order ID
 * @param string $new_status New status to set
 * @param string $provider_status Optional provider status
 * @param string $provider_reference Optional provider reference
 * @return bool Success status
 */
function updateOrderStatus($order_id, $new_status, $provider_status = null, $provider_reference = null) {
    global $db;
    
    // Valid status transitions
    $valid_transitions = [
        'pending' => ['processing', 'failed', 'delivered'],
        'processing' => ['delivered', 'failed'],
        'delivered' => [], // Final state
        'failed' => ['processing', 'delivered'], // Allow retry or manual override
        'success' => ['delivered'], // Legacy support
        'completed' => ['delivered'] // Allow normalization
    ];
    
    // Get current order status
    $stmt = $db->prepare("SELECT status FROM bundle_orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    
    $current_status = $result->fetch_assoc()['status'];
    
    // Check if transition is valid
    if (!in_array($new_status, $valid_transitions[$current_status] ?? [])) {
        error_log("Invalid status transition from $current_status to $new_status for order $order_id");
        return false;
    }
    
    // Build update query
    $update_fields = ["status = ?"];
    $params = [$new_status];
    $types = "s";
    
    if ($provider_status !== null) {
        $update_fields[] = "provider_status = ?";
        $params[] = $provider_status;
        $types .= "s";
    }
    
    if ($provider_reference !== null) {
        $update_fields[] = "provider_reference = ?";
        $params[] = $provider_reference;
        $types .= "s";
    }
    
    if ($new_status === 'delivered') {
        $update_fields[] = "delivered_at = NOW()";
    }
    
    $update_fields[] = "updated_at = NOW()";
    $params[] = $order_id;
    $types .= "i";
    
    $sql = "UPDATE bundle_orders SET " . implode(", ", $update_fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    $success = $stmt->execute();
    
    if ($success) {
        // Log status change
        logActivity($order_id, 'order_status_changed', "Status changed from $current_status to $new_status");
        
        if ($new_status === 'delivered') {
            // Trigger Agent Delivery SMS (if enabled)
            require_once __DIR__ . '/mnotify_sms.php';
            
            // Check if this is a store link order (has agent_id and agent_id != user_id)
            $isStoreLinkOrder = false;
            try {
                $stmt_check = $db->prepare("SELECT agent_id, user_id FROM bundle_orders WHERE id = ? LIMIT 1");
                if ($stmt_check) {
                    $stmt_check->bind_param('i', $order_id);
                    $stmt_check->execute();
                    $chk_row = $stmt_check->get_result()->fetch_assoc();
                    $stmt_check->close();
                    if ($chk_row && !empty($chk_row['agent_id']) && $chk_row['agent_id'] != $chk_row['user_id']) {
                        $isStoreLinkOrder = true;
                    }
                }
            } catch (Exception $e) {}

            if ($isStoreLinkOrder) {
                if (function_exists('sendAgentStoreOrderCompletedSms')) {
                    sendAgentStoreOrderCompletedSms($order_id);
                }
            } else {
                if (function_exists('sendAgentOrderDeliveredSms')) {
                    sendAgentOrderDeliveredSms($order_id);
                }
            }
            
            // Trigger Customer & Admin Delivery Emails
            try {
                $stmt_info = $db->prepare("
                    SELECT bo.*, dp.name as package_name, dp.data_size, dp.validity_days, n.name as network_name, u.full_name, u.email, u.role, t.metadata as txn_metadata
                    FROM bundle_orders bo
                    LEFT JOIN data_packages dp ON bo.package_id = dp.id
                    LEFT JOIN networks n ON dp.network_id = n.id
                    LEFT JOIN users u ON bo.user_id = u.id
                    LEFT JOIN transactions t ON bo.transaction_id = t.id
                    WHERE bo.id = ?
                    LIMIT 1
                ");
                if ($stmt_info) {
                    $stmt_info->bind_param('i', $order_id);
                    $stmt_info->execute();
                    $order_info = $stmt_info->get_result()->fetch_assoc();
                    $stmt_info->close();
                    
                    if ($order_info) {
                        $meta = json_decode((string)($order_info['txn_metadata'] ?? ''), true);
                        if (!is_array($meta)) $meta = [];
                        
                        $c_name = $order_info['full_name'] ?? $meta['buyer_name'] ?? '';
                        $c_email = $order_info['email'] ?? $meta['buyer_email'] ?? '';
                        
                        // We also get previous and current wallet balance for the notification if needed
                        $buyer_previous_balance = null;
                        $buyer_current_balance = null;
                        if ($order_info['user_id'] > 0 && function_exists('getWalletBalance')) {
                            $buyer_current_balance = getWalletBalance($order_info['user_id']);
                            $buyer_previous_balance = $buyer_current_balance; // Fallback representation
                        }
                        
                        $notificationData = [
                            'order_type' => 'data',
                            'order_reference' => $order_info['order_reference'],
                            'order_id' => $order_info['id'],
                            'user_id' => $order_info['user_id'],
                            'customer_name' => $c_name,
                            'customer_email' => $c_email,
                            'customer_role' => $order_info['role'] ?? 'customer',
                            'beneficiary_number' => $order_info['beneficiary_number'],
                            'network_name' => $order_info['network_name'],
                            'package_name' => $order_info['data_size'] . ' - ' . ($order_info['validity_days'] ? $order_info['validity_days'] . ' days' : 'N/A'),
                            'amount' => (float)$order_info['amount'],
                            'payment_method' => $order_info['payment_method'] ?? 'wallet',
                            'status' => 'delivered',
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'source' => 'status_update_delivered'
                        ];
                        
                        if (!empty($c_email)) {
                            if (!function_exists('sendUserOrderNotification')) {
                                require_once __DIR__ . '/functions.php';
                            }
                            if (function_exists('sendUserOrderNotification')) {
                                sendUserOrderNotification($notificationData);
                            }
                        }
                        
                        if (!function_exists('sendAdminDataOrderNotification')) {
                            require_once __DIR__ . '/functions.php';
                        }
                        if (function_exists('sendAdminDataOrderNotification')) {
                            sendAdminDataOrderNotification($notificationData);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log("updateOrderStatus Delivery Notification Error: " . $e->getMessage());
            }
        }
    }
    
    return $success;
}

/**
 * Get order status display information
 * @param string $status Order status
 * @return array Status display info (label, color, icon)
 */
function getOrderStatusDisplay($status) {
    $status_info = [
        'pending' => [
            'label' => 'Pending',
            'color' => '#ffc107',
            'icon' => 'fa-clock',
            'description' => 'Order is waiting to be processed'
        ],
        'processing' => [
            'label' => 'Processing',
            'color' => '#17a2b8',
            'icon' => 'fa-spinner',
            'description' => 'Order is being processed by provider'
        ],
        'delivered' => [
            'label' => 'Delivered',
            'color' => '#28a745',
            'icon' => 'fa-check-circle',
            'description' => 'Data bundle has been delivered successfully'
        ],
        'failed' => [
            'label' => 'Failed',
            'color' => '#dc3545',
            'icon' => 'fa-times-circle',
            'description' => 'Order processing failed'
        ],
        'success' => [ // Legacy support
            'label' => 'Completed',
            'color' => '#28a745',
            'icon' => 'fa-check-circle',
            'description' => 'Order completed successfully'
        ],
        'completed' => [
            'label' => 'Completed',
            'color' => '#28a745',
            'icon' => 'fa-check-circle',
            'description' => 'Order completed successfully'
        ]
    ];
    
    return $status_info[$status] ?? $status_info['pending'];
}

/**
 * Check provider status and update order accordingly
 * @param int $order_id Bundle order ID
 * @return bool Success status
 */
function checkProviderStatus($order_id) {
    global $db;
    
    // Get order details
    $stmt = $db->prepare("
        SELECT bo.*, dp.network_id, n.name as network_name 
        FROM bundle_orders bo 
        JOIN data_packages dp ON dp.id = bo.package_id 
        JOIN networks n ON n.id = dp.network_id 
        WHERE bo.id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        return false;
    }
    
    // Skip if already delivered or failed
    if (in_array($order['status'], ['delivered', 'failed'])) {
        return true;
    }

    $provider_status_result = checkDatawaxProviderStatus($order);
    if ($provider_status_result !== null) {
        return $provider_status_result;
    }
    
    // TODO: Implement actual provider API status checking
    // This is a placeholder for provider integration
    
    // For now, simulate status progression based on time
    $created_time = strtotime($order['created_at']);
    $current_time = time();
    $elapsed_minutes = ($current_time - $created_time) / 60;
    
    // Simulate processing after 2 minutes, delivered after 5 minutes
    if ($order['status'] === 'pending' && $elapsed_minutes >= 2) {
        return updateOrderStatus($order_id, 'processing', 'processing', null);
    } elseif ($order['status'] === 'processing' && $elapsed_minutes >= 5) {
        return updateOrderStatus($order_id, 'delivered', 'delivered', 'DELIVERED_' . time());
    }
    
    return true;
}

/**
 * Poll Datawax status endpoint for accepted orders.
 *
 * @param array $order Bundle order row from checkProviderStatus.
 * @return bool|null Null when the order is not a Datawax order.
 */
function checkDatawaxProviderStatus(array $order) {
    global $db;

    $order_id = (int) ($order['id'] ?? 0);
    if ($order_id <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT ap.base_url, ap.auth_type, ap.auth_token
        FROM api_transaction_logs atl
        JOIN api_providers ap ON ap.id = atl.provider_id
        WHERE atl.bundle_order_id = ? AND ap.slug = 'datawax'
        ORDER BY atl.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$provider) {
        return null;
    }

    $provider_reference = trim((string) ($order['provider_reference'] ?? ''));
    $order_reference = trim((string) ($order['order_reference'] ?? ''));
    $query = '';

    if ($provider_reference !== '' && ctype_digit($provider_reference)) {
        $query = 'order_id=' . rawurlencode($provider_reference);
    } elseif ($order_reference !== '') {
        $query = 'externalref=' . rawurlencode($order_reference);
    } elseif ($provider_reference !== '') {
        $query = 'externalref=' . rawurlencode($provider_reference);
    }

    if ($query === '') {
        return true;
    }

    require_once __DIR__ . '/api_providers.php';

    $url = rtrim((string) $provider['base_url'], '/') . '/status?' . $query;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => function_exists('buildHeaders') ? buildHeaders($provider) : ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Datawax status check failed for order ' . $order_id . ': ' . $curl_error);
        return false;
    }

    $response_data = function_exists('decodeProviderResponse') ? decodeProviderResponse($response) : json_decode($response, true);
    if (!is_array($response_data)) {
        error_log('Datawax status check returned invalid response for order ' . $order_id . ' HTTP ' . $http_code);
        return false;
    }

    $wc_status = strtolower(trim((string) ($response_data['wc_status'] ?? $response_data['status_label'] ?? '')));
    if ($wc_status === '') {
        return true;
    }

    $provider_reference = (string) ($response_data['order_id'] ?? $provider_reference);
    $payload_json = json_encode($response_data, JSON_UNESCAPED_SLASHES);

    if (in_array($wc_status, ['completed', 'delivered', 'success'], true)) {
        if ($order['status'] === 'pending') {
            updateOrderStatus($order_id, 'processing', $wc_status, $provider_reference);
        }
        return updateOrderStatus($order_id, 'delivered', $wc_status, $provider_reference);
    }

    if ($wc_status === 'failed') {
        $updated = updateOrderStatus($order_id, 'failed', $wc_status, $provider_reference);
        if ($updated && function_exists('refundBundleOrderByReference')) {
            refundBundleOrderByReference($order_id, 'Datawax status endpoint marked order as failed', $wc_status);
        }
        return $updated;
    }

    $stmt = $db->prepare("
        UPDATE bundle_orders
        SET api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()
        WHERE id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('sssi', $payload_json, $wc_status, $provider_reference, $order_id);
        $stmt->execute();
        $stmt->close();
    }

    if ($order['status'] === 'pending') {
        return updateOrderStatus($order_id, 'processing', $wc_status, $provider_reference);
    }

    return true;
}

/**
 * Get orders that need status updates
 * @return array Orders pending status updates
 */
function getOrdersNeedingStatusUpdate() {
    global $db;
    
    $stmt = $db->prepare("
        SELECT id, status, created_at 
        FROM bundle_orders 
        WHERE status IN ('pending', 'processing') 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ORDER BY created_at ASC
    ");
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Batch update order statuses
 * @return int Number of orders updated
 */
function batchUpdateOrderStatuses() {
    $orders = getOrdersNeedingStatusUpdate();
    $updated_count = 0;
    
    foreach ($orders as $order) {
        if (checkProviderStatus($order['id'])) {
            $updated_count++;
        }
    }
    
    return $updated_count;
}

/**
 * Get MTN order policy settings (initial status + auto-delivery window)
 *
 * @return array{initial_status:string,auto_minutes:int}
 */
function getMtnOrderStatusSettings() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $initial = strtolower(getSetting('mtn_order_initial_status', 'delivered'));
    if (!in_array($initial, ['pending', 'delivered'], true)) {
        $initial = 'delivered';
    }
    $minutes = max(0, (int) getSetting('mtn_auto_deliver_minutes', 0));

    $cache = [
        'initial_status' => $initial,
        'auto_minutes'   => $minutes,
    ];

    return $cache;
}

/**
 * Detect whether the order belongs to Hubnet based on stored provider payload.
 *
 * @param int $order_id
 * @return bool
 */
function isHubnetBundleOrder($order_id) {
    global $db;

    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        SELECT api_response
        FROM bundle_orders
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $apiResponse = (string) ($row['api_response'] ?? '');
    if ($apiResponse === '') {
        return false;
    }

    $normalized = strtolower($apiResponse);
    return strpos($normalized, '"provider_slug":"hubnet"') !== false
        || strpos($normalized, '"provider_name":"hubnet console"') !== false
        || strpos($normalized, '"provider_name":"hubnet"') !== false;
}

/**
 * Apply MTN policy after a successful delivery event.
 * Forces MTN orders back to pending when the policy requires manual confirmation.
 *
 * @param int $order_id
 * @param string|null $new_status Optional status value recently applied
 * @return bool Whether the policy changed the order
 */
function applyMtnStatusPolicy($order_id, $new_status = null) {
    global $db;
    $settings = getMtnOrderStatusSettings();
    if ($settings['initial_status'] !== 'pending') {
        return false;
    }

    if (isHubnetBundleOrder($order_id)) {
        return false;
    }

    $networkCodeField = 'n.name';
    if (function_exists('dbh_table_has_column')) {
        if (dbh_table_has_column('networks', 'code')) {
            $networkCodeField = 'n.code';
        } elseif (dbh_table_has_column('networks', 'slug')) {
            $networkCodeField = 'n.slug';
        }
    }

    $stmt = $db->prepare("
        SELECT bo.status, dp.network_id, n.name AS network_name, {$networkCodeField} AS network_code
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        WHERE bo.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) {
        return false;
    }

    $networkName = strtolower($order['network_name'] ?? '');
    $networkCode = strtolower($order['network_code'] ?? '');
    if ($networkName !== 'mtn' && $networkCode !== 'mtn') {
        return false;
    }

    $statusToEvaluate = strtolower($new_status ?? $order['status']);
    $successfulStates = ['delivered', 'success', 'completed'];
    if (!in_array($statusToEvaluate, $successfulStates, true)) {
        return false;
    }

    $update = $db->prepare("UPDATE bundle_orders SET status = 'pending', delivered_at = NULL, updated_at = NOW() WHERE id = ?");
    $update->bind_param('i', $order_id);
    $update->execute();
    return $update->affected_rows > 0;
}

/**
 * Automatically move eligible MTN orders from pending to delivered based on the configured window.
 */
if (!function_exists('maybeAutoCompletePendingMtnOrders')) {
    function maybeAutoCompletePendingMtnOrders() {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;

        global $db;
        $settings = getMtnOrderStatusSettings();
        $excludeHubnetSql = " AND (
                bo.api_response IS NULL
                OR (
                    LOWER(bo.api_response) NOT LIKE '%\"provider_slug\":\"hubnet\"%'
                    AND LOWER(bo.api_response) NOT LIKE '%\"provider_name\":\"hubnet console\"%'
                    AND LOWER(bo.api_response) NOT LIKE '%\"provider_name\":\"hubnet\"%'
                )
            )";

        // If policy is set to delivered, make sure any legacy pending orders are closed out.
        if ($settings['initial_status'] !== 'pending') {
            $stmt = $db->prepare("
                UPDATE bundle_orders bo
                JOIN data_packages dp ON dp.id = bo.package_id
                JOIN networks n ON n.id = dp.network_id
                SET bo.status = 'delivered', bo.delivered_at = IFNULL(bo.delivered_at, NOW()), bo.updated_at = NOW()
                WHERE bo.status = 'pending' AND LOWER(n.name) = 'mtn' {$excludeHubnetSql}
            ");
            $stmt->execute();
            return;
        }

        $autoMinutes = (int) $settings['auto_minutes'];
        if ($autoMinutes <= 0) {
            return;
        }

        $stmt = $db->prepare("
            UPDATE bundle_orders bo
            JOIN data_packages dp ON dp.id = bo.package_id
            JOIN networks n ON n.id = dp.network_id
            SET bo.status = 'delivered', bo.delivered_at = IFNULL(bo.delivered_at, NOW()), bo.updated_at = NOW()
            WHERE bo.status = 'pending'
              AND LOWER(n.name) = 'mtn'
              {$excludeHubnetSql}
              AND bo.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->bind_param('i', $autoMinutes);
        $stmt->execute();
    }
}
?>
