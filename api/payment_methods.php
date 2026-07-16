<?php
require_once '../config/config.php';

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$payload = $method === 'POST' ? (json_decode($raw, true) ?: []) : $_GET;
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if ($method === 'POST') {
    if (!$csrfHeader && isset($payload['csrf_token'])) { 
        $csrfHeader = $payload['csrf_token']; 
    }
    if (!validateCSRF($csrfHeader)) { 
        jsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], 419); 
    }
}

$action = $payload['action'] ?? '';
$current = getCurrentUser();

try {
    if ($action === 'get_payment_methods') {
        $agentId = null;
        
        // Determine which agent's settings to check
        if ($current['role'] === 'customer') {
            $agentId = getUserAgentId($current['id']);
            
            // If customer accessed via store context, also check store agent
            $store_slug = $payload['store_slug'] ?? $_GET['store'] ?? null;
            if ($store_slug && !$agentId) {
                // Get agent from store if customer isn't directly linked to an agent
                $stmt = $db->prepare("
                    SELECT ast.agent_id
                    FROM agent_stores ast
                    JOIN users u ON ast.agent_id = u.id
                    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
                ");
                $stmt->bind_param("s", $store_slug);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $agentId = $row['agent_id'];
                }
            } elseif ($store_slug && $agentId) {
                // If customer has agent but accessing via store, use store agent if they match
                $stmt = $db->prepare("
                    SELECT ast.agent_id
                    FROM agent_stores ast
                    JOIN users u ON ast.agent_id = u.id
                    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
                ");
                $stmt->bind_param("s", $store_slug);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    // Use store agent's settings regardless of customer's default agent
                    $agentId = $row['agent_id'];
                }
            }
        } elseif ($current['role'] === 'agent') {
            $agentId = $current['id'];
        }
        
        $active_gateway = getActivePaymentGateway();
        $gateway_mode = getPaymentGatewayMode();
        $enabled_gateways = getEnabledPaymentGateways();
        $paymentMethods = [
            'paystack' => in_array('paystack', $enabled_gateways, true),
            'moolre' => in_array('moolre', $enabled_gateways, true),
            'gateway' => !empty($enabled_gateways),
            'topup_request' => true
        ];

        $has_agent_paystack = false;
        if ($agentId && dbh_table_exists('agent_paystack_settings')) {
            $stmt = $db->prepare("SELECT 1 FROM agent_paystack_settings WHERE agent_id = ? AND is_active = 1 LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $agentId);
                $stmt->execute();
                $has_agent_paystack = (bool) $stmt->get_result()->fetch_row();
            }
        }
        
        jsonResponse([
            'status' => 'success', 
            'payment_methods' => $paymentMethods,
            'active_gateway' => $active_gateway,
            'debug' => [
                'user_id' => $current['id'],
                'user_role' => $current['role'],
                'agent_id' => $agentId,
                'store_slug' => $store_slug ?? null,
                'agent_paystack_active' => $has_agent_paystack ?? null,
                'gateway_mode' => $gateway_mode,
                'enabled_gateways' => $enabled_gateways
            ]
        ]);
        
    } elseif ($action === 'get_agent_settings') {
        if ($current['role'] !== 'agent') {
            jsonResponse(['status' => 'error', 'message' => 'Only agents can access this endpoint'], 403);
        }
        
        // Agents can only use top-up request, no Paystack control needed
        jsonResponse([
            'status' => 'success',
            'settings' => [
                'allow_paystack' => false,
                'allow_topup_request' => true
            ]
        ]);
        
    } elseif ($action === 'update_agent_settings' && $method === 'POST') {
        // Agents cannot change payment settings - only top-up request is allowed
        jsonResponse(['status' => 'error', 'message' => 'Payment method settings are now controlled by admin only'], 403);
        
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action or method'], 400);
    }
    
} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
?>
