<?php
require_once '../config/config.php';
require_once '../includes/api_providers.php';
require_once '../includes/volume_converter.php';
require_once '../includes/order_status.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$current_user = getCurrentUser();
$agent_id = (int) ($current_user['id'] ?? 0);
if ($agent_id <= 0 || (($current_user['role'] ?? '') !== 'agent')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Agent access required']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf_token = trim((string) ($payload['csrf_token'] ?? ''));
if (!validateCSRF($csrf_token)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

$order_id = (int) ($payload['order_id'] ?? 0);
if ($order_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit();
}

try {
    $stmt = $db->prepare("
        SELECT
            bo.id,
            bo.status,
            bo.package_id,
            bo.beneficiary_number,
            bo.order_reference,
            bo.agent_id,
            bo.user_id,
            bo.provider_reference,
            dp.network_id,
            dp.name AS package_name,
            dp.data_size,
            dp.package_type
        FROM bundle_orders bo
        LEFT JOIN data_packages dp ON dp.id = bo.package_id
        WHERE bo.id = ?
          AND (bo.agent_id = ? OR bo.user_id = ?)
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare order lookup');
    }
    $stmt->bind_param('iii', $order_id, $agent_id, $agent_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found or access denied']);
        exit();
    }

    $status = strtolower(trim((string) ($order['status'] ?? '')));
    if (in_array($status, ['delivered', 'success', 'completed'], true)) {
        echo json_encode(['success' => true, 'message' => 'Order is already delivered']);
        exit();
    }

    if ($status !== 'failed') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only failed orders can be retried']);
        exit();
    }

    // Strict anti-duplicate mode:
    // If there is any sign the provider may have received the first request,
    // do not auto-retry to avoid accidental double fulfillment.
    $provider_reference = trim((string) ($order['provider_reference'] ?? ''));
    if ($provider_reference !== '') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Auto-retry blocked for safety. This order already has a provider reference. Please report issue for manual verification.'
        ]);
        exit();
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('api_transaction_logs')) {
        $log_stmt = $db->prepare("
            SELECT id, is_successful, http_status_code, response_data, error_message
            FROM api_transaction_logs
            WHERE bundle_order_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        if ($log_stmt) {
            $log_stmt->bind_param('i', $order_id);
            $log_stmt->execute();
            $log_row = $log_stmt->get_result()->fetch_assoc();
            if ($log_row) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Auto-retry blocked for safety because provider transaction logs exist. Please report this order for manual verification.'
                ]);
                exit();
            }
        }
    }

    $network_id = (int) ($order['network_id'] ?? 0);
    $data_size = (string) ($order['data_size'] ?? '');
    $phone = trim((string) ($order['beneficiary_number'] ?? ''));
    if ($network_id <= 0 || $data_size === '' || $phone === '') {
        throw new Exception('Order is missing package/network details and cannot be retried');
    }

    $endpoint_type = detectEndpointTypeForPackage(
        (string) ($order['package_name'] ?? ''),
        $data_size,
        (string) ($order['package_type'] ?? '')
    );

    $availability = checkNetworkProviderAvailability($network_id, $endpoint_type);
    if (empty($availability['available'])) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => (string) ($availability['message'] ?? 'Network is currently unavailable')
        ]);
        exit();
    }

    $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare order status update');
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();

    $volume_gb = extractVolumeGB($data_size);
    try {
        $api_result = processBundlePurchase($order_id, $network_id, $phone, $volume_gb, $endpoint_type, true);
    } catch (Exception $e) {
        $api_result = ['success' => false, 'error' => $e->getMessage()];
    }

    if (!empty($api_result['success'])) {
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

        if ($is_hubnet_order) {
            $hubnet_provider_status = strtolower(trim((string) (($api_result['response']['delivery_state'] ?? $api_result['response']['status'] ?? 'processing'))));
            if ($hubnet_provider_status === '' || $hubnet_provider_status === '1') {
                $hubnet_provider_status = 'processing';
            }

            $internal_status = in_array($hubnet_provider_status, ['completed', 'delivered'], true) ? 'delivered' : 'processing';

            $stmt = $db->prepare("
                UPDATE bundle_orders
                SET status = ?,
                    api_response = ?,
                    provider_status = ?,
                    provider_reference = ?,
                    updated_at = NOW()
                    " . ($internal_status === 'delivered' ? ", delivered_at = NOW()" : "") . "
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare processing status update');
            }
            $stmt->bind_param('ssssi', $internal_status, $api_response_json, $hubnet_provider_status, $provider_ref, $order_id);
            $stmt->execute();
        } else {
            $stmt = $db->prepare("
                UPDATE bundle_orders
                SET status = 'processing',
                    api_response = ?,
                    provider_reference = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare processing status update');
            }
            $stmt->bind_param('ssi', $api_response_json, $provider_ref, $order_id);
            $stmt->execute();

            if (function_exists('applyMtnStatusPolicy')) {
                applyMtnStatusPolicy($order_id, 'processing');
            }
        }

        $final_status = 'processing';
        $status_stmt = $db->prepare("SELECT status FROM bundle_orders WHERE id = ? LIMIT 1");
        if ($status_stmt) {
            $status_stmt->bind_param('i', $order_id);
            $status_stmt->execute();
            $status_row = $status_stmt->get_result()->fetch_assoc();
            if (!empty($status_row['status'])) {
                $final_status = strtolower((string) $status_row['status']);
            }
        }

        logActivity($agent_id, 'order_retry_success', 'Retried order #' . $order_id . ' without additional wallet deduction');

        $message = $final_status === 'processing'
            ? 'Retry submitted. Order accepted and is now processing pending Hubnet confirmation.'
            : ($final_status === 'pending'
            ? 'Retry submitted. Order accepted and is now pending confirmation.'
            : 'Retry successful. Order marked as delivered.');

        echo json_encode([
            'success' => true,
            'message' => $message,
            'order_id' => $order_id,
            'status' => $final_status
        ]);
        exit();
    }

    $api_response_json = json_encode($api_result);
    $stmt = $db->prepare("
        UPDATE bundle_orders
        SET status = 'failed',
            api_response = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare failed status update');
    }
    $stmt->bind_param('si', $api_response_json, $order_id);
    $stmt->execute();

    $error_message = trim((string) ($api_result['error'] ?? 'Provider rejected retry request'));
    logActivity($agent_id, 'order_retry_failed', 'Retry failed for order #' . $order_id . ': ' . $error_message);

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => $error_message,
        'order_id' => $order_id,
        'status' => 'failed'
    ]);
} catch (Exception $e) {
    error_log('Retry order error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Retry failed: ' . $e->getMessage()]);
}
