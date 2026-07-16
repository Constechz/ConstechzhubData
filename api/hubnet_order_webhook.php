<?php
/**
 * Hubnet Order Webhook Handler
 * 
 * Handles real-time status updates from Hubnet for all order types:
 * - Customer orders
 * - Agent orders
 * - Guest orders
 * - Store orders (Data bundles)
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/order_status.php';

ensureProviderWebhookTables();

header('Content-Type: application/json');

/**
 * Send JSON response and exit
 */
function hubnet_json_response($status_code, $payload) {
    http_response_code($status_code);
    echo json_encode($payload);
    exit();
}

/**
 * Read and decode the incoming payload
 */
function hubnet_read_payload() {
    $raw = file_get_contents('php://input');
    $decoded = null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [$raw, $decoded];
        }
    }

    if (!empty($_POST)) {
        return [$raw !== false ? $raw : '', $_POST];
    }

    return [$raw !== false ? $raw : '', []];
}

/**
 * Helper to get nested values from payload
 */
function hubnet_payload_get($payload, $paths) {
    foreach ($paths as $path) {
        $current = $payload;
        $found = true;
        foreach ($path as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $found = false;
                break;
            }
            $current = $current[$part];
        }
        if ($found && (is_scalar($current) || $current === null) && $current !== null && trim((string) $current) !== '') {
            return trim((string) $current);
        }
    }
    return '';
}

/**
 * Map Hubnet status/event to internal status
 */
function normalizeHubnetWebhookStatus($payload, &$source = null) {
    $source = null;
    
    // Check Event first (it's the most definitive)
    $candidates = [
        'event' => hubnet_payload_get($payload, [['event']]),
        'data.status' => hubnet_payload_get($payload, [['data', 'status']]),
        'code' => hubnet_payload_get($payload, [['code'], ['data', 'code']]),
        'status_text' => hubnet_payload_get($payload, [['status_text']]),
        'message' => hubnet_payload_get($payload, [['message']]),
    ];

    foreach ($candidates as $candidate_source => $value) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') continue;

        // Hubnet success codes
        if ($normalized === '0000' || $normalized === 'success') {
            $source = $candidate_source;
            return 'delivered';
        }

        if (
            strpos($normalized, 'deliver') !== false || 
            strpos($normalized, 'success') !== false || 
            strpos($normalized, 'complete') !== false || 
            strpos($normalized, 'fulfilled') !== false
        ) {
            $source = $candidate_source;
            return 'delivered';
        }

        if (
            strpos($normalized, 'fail') !== false || 
            strpos($normalized, 'error') !== false || 
            strpos($normalized, 'reject') !== false || 
            strpos($normalized, 'cancel') !== false || 
            strpos($normalized, 'reverse') !== false
        ) {
            $source = $candidate_source;
            return 'failed';
        }

        if (
            strpos($normalized, 'processing') !== false || 
            strpos($normalized, 'pending') !== false || 
            strpos($normalized, 'queue') !== false || 
            strpos($normalized, 'progress') !== false || 
            strpos($normalized, 'validat') !== false ||
            strpos($normalized, 'initiated') !== false ||
            strpos($normalized, 'order received') !== false ||
            strpos($normalized, 'order placed') !== false ||
            strpos($normalized, 'accepted') !== false
        ) {
            $source = $candidate_source;
            return 'processing';
        }
    }

    return '';
}

/**
 * Find order in bundle_orders
 */
function hubnet_find_order_by_reference($reference) {
    global $db;

    $reference = trim((string) $reference);
    if ($reference === '') return null;

    $stmt = $db->prepare("
        SELECT bo.*, dp.name as package_name, dp.data_size, n.name as network_name, u.full_name, u.email, u.role, t.metadata as txn_metadata
        FROM bundle_orders bo
        LEFT JOIN data_packages dp ON bo.package_id = dp.id
        LEFT JOIN networks n ON dp.network_id = n.id
        LEFT JOIN users u ON bo.user_id = u.id
        LEFT JOIN transactions t ON bo.transaction_id = t.id
        WHERE bo.order_reference = ? OR bo.provider_reference = ?
        ORDER BY bo.id DESC
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('ss', $reference, $reference);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $order ?: null;
}

/**
 * Persist raw payload to order record
 */
function hubnet_store_order_payload($order_id, $payload_json, $provider_status, $provider_reference) {
    global $db;

    $stmt = $db->prepare("
        UPDATE bundle_orders
        SET api_response = ?, provider_status = ?, provider_reference = ?, updated_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param('sssi', $payload_json, $provider_status, $provider_reference, $order_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Main execution starts here
[$raw_payload, $payload] = hubnet_read_payload();

// 1. Security Check
$configured_secret = trim((string) dbh_env('HUBNET_WEBHOOK_SECRET', ''));
if ($configured_secret !== '') {
    $provided_secret = trim((string) (
        $_SERVER['HTTP_X_HUBNET_WEBHOOK_SECRET'] ?? 
        $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? 
        $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? 
        ($_GET['token'] ?? $_POST['token'] ?? $_GET['secret'] ?? $_POST['secret'] ?? '')
    ));
    if ($provided_secret === '' || !hash_equals($configured_secret, $provided_secret)) {
        recordProviderWebhookLog('hubnet', '', '', '', 0, 0, 'Invalid Hubnet webhook secret.', $raw_payload !== '' ? $raw_payload : $payload);
        hubnet_json_response(401, ['status' => false, 'message' => 'Unauthorized']);
    }
}

if (!is_array($payload) || empty($payload)) {
    recordProviderWebhookLog('hubnet', '', '', '', 0, 0, 'Empty or invalid Hubnet payload.', $raw_payload);
    hubnet_json_response(400, ['status' => false, 'message' => 'Invalid payload']);
}

// 2. Extract Key Info
$event_name = hubnet_payload_get($payload, [['event'], ['type']]);
$reference = hubnet_payload_get($payload, [
    ['data', 'reference'], 
    ['reference'], 
    ['data', 'reference_id'], 
    ['reference_id'],
    ['data', 'external_id'],
    ['external_id'],
    ['data', 'order_id'],
    ['order_id'],
    ['data', 'batch_id'],
    ['batch_id']
]);
$provider_status_raw = hubnet_payload_get($payload, [['data', 'status'], ['status'], ['status_text'], ['message']]);
$status_source = '';
$normalized_status = normalizeHubnetWebhookStatus($payload, $status_source);

if ($reference === '') {
    recordProviderWebhookLog('hubnet', $event_name, '', $normalized_status, 0, 0, 'Missing Hubnet reference in webhook payload.', $raw_payload !== '' ? $raw_payload : $payload);
    hubnet_json_response(202, ['status' => true, 'message' => 'Reference missing; payload logged']);
}

// 3. Find Matching Order
$order = hubnet_find_order_by_reference($reference);
if (!$order) {
    recordProviderWebhookLog('hubnet', $event_name, $reference, $normalized_status, 0, 0, 'No local order matched the Hubnet reference.', $raw_payload !== '' ? $raw_payload : $payload);
    hubnet_json_response(202, ['status' => true, 'message' => 'No matching order found; payload logged']);
}

$order_id = (int) ($order['id'] ?? 0);
$current_status = strtolower(trim((string) ($order['status'] ?? 'pending')));
$payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES);
$provider_reference = $reference;

// 4. Update Status & Side Effects
$processed = false;
$error_message = null;

if ($normalized_status === 'processing') {
    if ($current_status === 'pending' || $current_status === 'failed') {
        $processed = updateOrderStatus($order_id, 'processing', $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
    } else {
        $processed = hubnet_store_order_payload($order_id, $payload_json, $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
    }
} elseif ($normalized_status === 'delivered') {
    // Force transition to 'delivered' if not already definitively delivered, or just update metadata
    if ($current_status !== 'delivered' && $current_status !== 'completed') {
        // Special case: if it was 'success' (legacy), allow moving to 'delivered'
        $db->query("UPDATE bundle_orders SET status = 'processing' WHERE id = $order_id AND status = 'success'");
        
        $processed = updateOrderStatus($order_id, 'delivered', $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
    } else {
        // Already delivered, just update payload/metadata
        $processed = hubnet_store_order_payload($order_id, $payload_json, $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
    }
} elseif ($normalized_status === 'failed') {
    if ($current_status === 'pending' || $current_status === 'processing') {
        $processed = updateOrderStatus($order_id, 'failed', $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
        if ($processed) {
            // Attempt auto-refund if applicable
            $refund_result = refundBundleOrderByReference(
                $order_id,
                'Hubnet webhook marked order as failed',
                $provider_status_raw !== '' ? $provider_status_raw : $normalized_status
            );
            if (!$refund_result['success']) {
                $error_message = $refund_result['message'] ?? 'Refund failed after Hubnet failure.';
            }
        }
    } else {
        $error_message = "Order #$order_id is in state: $current_status. Webhook ignored.";
    }
}

// Final Storage
hubnet_store_order_payload($order_id, $payload_json, $provider_status_raw !== '' ? $provider_status_raw : $normalized_status, $provider_reference);
recordProviderWebhookLog('hubnet', $event_name, $reference, $normalized_status, $order_id, $processed ? 1 : 0, $error_message, $raw_payload !== '' ? $raw_payload : $payload);

hubnet_json_response(200, [
    'status' => true,
    'message' => 'Hubnet webhook processed',
    'order_id' => $order_id,
    'normalized_status' => $normalized_status,
    'status_source' => $status_source,
    'processed' => $processed,
    'error' => $error_message
]);
