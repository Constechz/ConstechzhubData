<?php
/**
 * Order Status Updater API
 * Handles automatic status updates and provider integration
 */

require_once '../config/config.php';
require_once '../includes/order_status.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'update_order_status':
        updateSingleOrderStatus($input);
        break;
        
    case 'batch_update':
        batchUpdateStatuses();
        break;
        
    case 'provider_webhook':
        handleProviderWebhook($input);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

/**
 * Update single order status
 */
function updateSingleOrderStatus($input) {
    $order_id = intval($input['order_id'] ?? 0);
    $new_status = $input['status'] ?? '';
    $provider_status = $input['provider_status'] ?? null;
    $provider_reference = $input['provider_reference'] ?? null;
    
    if (!$order_id || !$new_status) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
        return;
    }
    
    $success = updateOrderStatus($order_id, $new_status, $provider_status, $provider_reference);
    
    if ($success) {
        echo json_encode(['status' => 'success', 'message' => 'Order status updated']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update order status']);
    }
}

/**
 * Batch update order statuses
 */
function batchUpdateStatuses() {
    $updated_count = batchUpdateOrderStatuses();
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Updated $updated_count orders",
        'updated_count' => $updated_count
    ]);
}

/**
 * Handle provider webhook notifications
 */
function handleProviderWebhook($input) {
    $provider_reference = $input['provider_reference'] ?? '';
    $status = $input['status'] ?? '';
    $provider_name = $input['provider'] ?? '';
    
    if (!$provider_reference || !$status) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing webhook parameters']);
        return;
    }
    
    global $db;
    
    // Find order by provider reference
    $stmt = $db->prepare("SELECT id FROM bundle_orders WHERE provider_reference = ?");
    $stmt->bind_param("s", $provider_reference);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        return;
    }
    
    $order = $result->fetch_assoc();
    $order_id = $order['id'];
    
    // Map provider status to internal status
    $internal_status = mapProviderStatus($status);
    
    if ($internal_status) {
        $success = updateOrderStatus($order_id, $internal_status, $status, $provider_reference);
        
        if ($success) {
            echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update order']);
        }
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Status ignored']);
    }
}

/**
 * Map provider status to internal status
 */
function mapProviderStatus($provider_status) {
    $status_map = [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'delivered',
        'delivered' => 'delivered',
        'success' => 'delivered',
        'failed' => 'failed',
        'error' => 'failed',
        'cancelled' => 'failed'
    ];
    
    return $status_map[strtolower($provider_status)] ?? null;
}
?>
