<?php
/**
 * Hubnet Webhook Handler for Status Changes
 */

require_once '../config/config.php';
require_once '../includes/order_status.php';

// Set response header to JSON
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$raw_input = file_get_contents('php://input');
$payload = json_decode($raw_input, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit();
}

// Log webhook headers and request body for debugging/audit
$headers = getallheaders();
error_log("Hubnet Webhook Headers: " . json_encode($headers));
error_log("Hubnet Webhook Body: " . $raw_input);

// Extract parameters
$event = $payload['event'] ?? '';
$data = $payload['data'] ?? [];
$reference = $data['reference'] ?? $payload['reference'] ?? '';

if (empty($reference)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing transaction reference']);
    exit();
}

global $db;

// Look up order in the database by provider_reference or order_reference
$stmt = $db->prepare("SELECT * FROM bundle_orders WHERE provider_reference = ? OR order_reference = ? LIMIT 1");
$stmt->bind_param("ss", $reference, $reference);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    error_log("Hubnet Webhook: Order not found for reference " . $reference);
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    exit();
}

$order_id = (int)$order['id'];
$current_status = $order['status'];

// Check if order is already in a final state
if (in_array($current_status, ['delivered', 'failed'])) {
    echo json_encode(['status' => 'success', 'message' => 'Order already in final state: ' . $current_status]);
    exit();
}

// Map Hubnet status/event to internal status
$internal_status = null;
$status_message = $payload['message'] ?? 'Hubnet Webhook Update';

$hubnet_status = strtolower(trim($data['status'] ?? ''));
$event_name = strtolower(trim($event));

if ($event_name === 'transfer.delivered' || $hubnet_status === 'delivered' || $hubnet_status === 'success' || $hubnet_status === 'completed') {
    $internal_status = 'delivered';
} elseif ($event_name === 'transfer.failed' || $hubnet_status === 'failed' || $hubnet_status === 'error' || $hubnet_status === 'cancelled') {
    $internal_status = 'failed';
} elseif ($event_name === 'transfer.processing' || $hubnet_status === 'processing' || $hubnet_status === 'pending') {
    $internal_status = 'processing';
}

if (!$internal_status) {
    // Fallback search in strings
    if (strpos($event_name, 'processing') !== false) {
        $internal_status = 'processing';
    } elseif (strpos($event_name, 'delivered') !== false) {
        $internal_status = 'delivered';
    } elseif (strpos($event_name, 'failed') !== false) {
        $internal_status = 'failed';
    }
}

if (!$internal_status) {
    echo json_encode(['status' => 'success', 'message' => 'Event ignored/unrecognized: ' . $event]);
    exit();
}

// If the mapped status matches current status, return success with no action
if ($internal_status === $current_status) {
    echo json_encode(['status' => 'success', 'message' => 'Order status is already ' . $current_status]);
    exit();
}

// Start database transaction
$db->getConnection()->begin_transaction();

try {
    // If transitioning from pending to delivered/failed, go to processing first to satisfy validation
    if ($current_status === 'pending' && ($internal_status === 'delivered' || $internal_status === 'failed')) {
        updateOrderStatus($order_id, 'processing', 'processing', $reference);
    }

    // Update order status using standard flow
    $success = updateOrderStatus($order_id, $internal_status, $hubnet_status ?: $event, $reference);
    
    if (!$success) {
        throw new Exception("Failed to transition order status to " . $internal_status);
    }

    // Process status change logic
    if ($internal_status === 'delivered') {
        // Record profit for agent if applicable
        if ($order['agent_id'] > 0) {
            if (function_exists('recordOrderProfit')) {
                recordOrderProfit([
                    'agent_id' => $order['agent_id'],
                    'order_id' => $order_id,
                    'customer_id' => $order['user_id'],
                    'package_id' => $order['package_id'],
                    'customer_paid' => (float)$order['amount'],
                    'agent_cost' => (float)$order['agent_cost'],
                    'reference' => $order['order_reference'],
                    'status' => 'earned'
                ]);
            }
        }

        // Apply MTN status policy if configured
        if (function_exists('applyMtnStatusPolicy')) {
            applyMtnStatusPolicy($order_id, 'delivered');
        }

        // Retrieve user and package details to send confirmation email
        $user_stmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $order['user_id']);
        $user_stmt->execute();
        $user = $user_stmt->get_result()->fetch_assoc();

        if ($user) {
            $pkg_stmt = $db->prepare("
                SELECT dp.name, dp.data_size, dp.validity_days, n.name as network_name 
                FROM data_packages dp 
                JOIN networks n ON dp.network_id = n.id 
                WHERE dp.id = ?
            ");
            $pkg_stmt->bind_param("i", $order['package_id']);
            $pkg_stmt->execute();
            $package = $pkg_stmt->get_result()->fetch_assoc();

            if ($package && function_exists('sendOrderConfirmationEmail')) {
                $order_data = [
                    'order_id' => $order['order_reference'],
                    'network_name' => $package['network_name'],
                    'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                    'phone_number' => $order['beneficiary_number'],
                    'amount' => $order['amount'],
                    'status' => 'Completed'
                ];
                sendOrderConfirmationEmail($user['email'], $user['full_name'], $order_data);
            }
        }
        
    } elseif ($internal_status === 'failed') {
        // Refund logic
        $agent_id = (int)$order['agent_id'];
        $amount = (float)$order['amount'];
        $agent_cost = (float)$order['agent_cost'];
        $user_id = (int)$order['user_id'];
        $order_ref = $order['order_reference'];

        $refund_description = 'Refund: Order ' . $order_ref . ' failed (' . $status_message . ')';

        if ($agent_id > 0) {
            // Agent store purchase - reverse the transactions in order:
            // 1. Refund agent wholesale cost
            updateWalletBalance($agent_id, $agent_cost, 'credit', $order_ref . '_REFUND', $refund_description);
            // 2. Transfer back from agent to customer
            transferWalletBalance($agent_id, $user_id, $amount, $order_ref . '_REFUND', $refund_description);
        } else {
            // Regular customer purchase - refund customer wallet
            updateWalletBalance($user_id, $amount, 'credit', $order_ref . '_REFUND', $refund_description);
        }
        
        error_log("Hubnet Webhook: Refunded order " . $order_id . " due to failure");
    }

    $db->getConnection()->commit();
    echo json_encode(['status' => 'success', 'message' => 'Order updated to ' . $internal_status]);
    
} catch (Exception $e) {
    $db->getConnection()->rollback();
    error_log("Hubnet Webhook Error processing transaction: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}
