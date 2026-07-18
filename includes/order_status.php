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
        'pending' => ['processing', 'failed'],
        'processing' => ['delivered', 'failed'],
        'delivered' => [], // Final state
        'failed' => ['processing'], // Allow retry
        'success' => ['delivered'] // Legacy support
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
            'color' => '#FFD400',
            'icon' => 'fa-clock',
            'description' => 'Order is waiting to be processed'
        ],
        'processing' => [
            'label' => 'Processing',
            'color' => '#2E294E',
            'icon' => 'fa-spinner',
            'description' => 'Order is being processed by provider'
        ],
        'delivered' => [
            'label' => 'Delivered',
            'color' => '#2E294E',
            'icon' => 'fa-check-circle',
            'description' => 'Data bundle has been delivered successfully'
        ],
        'failed' => [
            'label' => 'Failed',
            'color' => '#D90368',
            'icon' => 'fa-times-circle',
            'description' => 'Order processing failed'
        ],
        'success' => [ // Legacy support
            'label' => 'Completed',
            'color' => '#2E294E',
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
function maybeAutoCompletePendingMtnOrders() {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    global $db;
    $settings = getMtnOrderStatusSettings();

    // If policy is set to delivered, make sure any legacy pending orders are closed out.
    if ($settings['initial_status'] !== 'pending') {
        $stmt = $db->prepare("
            UPDATE bundle_orders bo
            JOIN data_packages dp ON dp.id = bo.package_id
            JOIN networks n ON n.id = dp.network_id
            SET bo.status = 'delivered', bo.delivered_at = IFNULL(bo.delivered_at, NOW()), bo.updated_at = NOW()
            WHERE bo.status = 'pending' AND LOWER(n.name) = 'mtn'
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
          AND bo.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->bind_param('i', $autoMinutes);
    $stmt->execute();
}
?>
