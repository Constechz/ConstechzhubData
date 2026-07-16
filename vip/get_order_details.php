<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

requireRole('vip');
$current_user = getCurrentUser();
$agent_id = $current_user['id'];

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order reference.']);
    exit();
}

$stmt = $db->prepare("
    SELECT 
        bo.id,
        bo.order_reference,
        bo.status,
        bo.beneficiary_number,
        bo.amount,
        bo.agent_cost,
        COALESCE(NULLIF(bo.agent_cost, 0), NULLIF(pp_agent.price, 0), bo.amount, dp.price, 0) AS display_amount,
        bo.created_at,
        bo.updated_at,
        bo.delivered_at,
        bo.api_response,
        dp.name AS package_name,
        dp.data_size,
        dp.validity_days,
        n.name AS network_name,
        n.color AS network_color,
        t.reference AS transaction_reference,
        t.payment_method,
        t.status AS transaction_status,
        t.amount AS transaction_amount,
        u.full_name AS customer_name,
        u.email AS customer_email,
        COALESCE(
            ap.profit_amount,
            CASE
                WHEN bo.agent_id = ?
                     AND bo.agent_cost IS NOT NULL
                THEN GREATEST(COALESCE(bo.amount, 0) - COALESCE(bo.agent_cost, 0), 0)
                ELSE 0
            END,
            0
        ) AS order_profit
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    LEFT JOIN networks n ON n.id = dp.network_id
    LEFT JOIN transactions t ON t.id = bo.transaction_id
    LEFT JOIN users u ON u.id = bo.user_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    LEFT JOIN agent_profits ap ON ap.order_id = bo.id AND ap.agent_id = ? AND ap.status <> 'cancelled'
    WHERE bo.id = ?
      AND (bo.agent_id = ? OR bo.user_id = ?)
    LIMIT 1
");
$stmt->bind_param('iiiiii', $agent_id, $agent_id, $agent_id, $order_id, $agent_id, $agent_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || !$result->num_rows) {
    echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
    exit();
}

$order = $result->fetch_assoc();
$displayNetworkName = detectGhanaNetworkLabel($order['network_name'] ?? '', $order['beneficiary_number'] ?? '');

$responseData = [
    'id' => $order['id'],
    'order_reference' => $order['order_reference'],
    'status' => $order['status'],
    'beneficiary_number' => $order['beneficiary_number'],
    'amount' => (float) ($order['display_amount'] ?? 0),
    'recorded_amount' => (float) ($order['amount'] ?? 0),
    'agent_cost' => $order['agent_cost'],
    'created_at' => $order['created_at'],
    'updated_at' => $order['updated_at'],
    'delivered_at' => $order['delivered_at'],
    'api_response' => $order['api_response'],
    'package_name' => $order['package_name'],
    'data_size' => $order['data_size'],
    'validity_days' => $order['validity_days'],
    'network_name' => $displayNetworkName,
    'network_color' => $order['network_color'],
    'transaction_reference' => $order['transaction_reference'],
    'payment_method' => $order['payment_method'],
    'transaction_status' => $order['transaction_status'],
    'transaction_amount' => $order['transaction_amount'],
    'customer_name' => $order['customer_name'],
    'customer_email' => $order['customer_email'],
    'order_profit' => (float) ($order['order_profit'] ?? 0)
];

echo json_encode(['success' => true, 'data' => $responseData]);
exit();
