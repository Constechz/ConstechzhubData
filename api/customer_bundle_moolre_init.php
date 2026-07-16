<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

requireRole('customer');
$current_user = getCurrentUser();
ensureDataPackageStockStatusColumn();
$customer_pricing_type = getCustomerPricingUserType($current_user);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$package_id = (int) ($input['package_id'] ?? 0);
$beneficiary_number = sanitize($input['beneficiary_number'] ?? '');
$allow_ported_mtn = !empty($input['allow_ported_mtn']) && (string) $input['allow_ported_mtn'] === '1';
$agent_id = (int) ($input['agent_id'] ?? 0);
$store_slug = sanitize($input['store_slug'] ?? '');
$csrf_token = $input['csrf_token'] ?? '';

if (!validateCSRF($csrf_token)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid session token. Please refresh and try again.']);
    exit();
}

if ($package_id <= 0 || $beneficiary_number === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

$requested_gateway = normalizePaymentGateway($input['gateway'] ?? '');
if ($requested_gateway !== '' && $requested_gateway !== 'moolre') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid gateway selection for this endpoint.']);
    exit();
}

if (!isPaymentGatewayEnabled('moolre')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Moolre is currently disabled by admin settings.']);
    exit();
}

$config = getMoolreConfig();
if (!isMoolreConfigured($config)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Moolre keys are not configured.']);
    exit();
}

if (($agent_id <= 0) && $store_slug !== '') {
    $store_stmt = $db->prepare("
        SELECT ast.agent_id
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ?
          AND ast.is_active = 1
          AND u.role = 'agent'
          AND u.status = 'active'
        LIMIT 1
    ");
    if ($store_stmt) {
        $store_stmt->bind_param('s', $store_slug);
        $store_stmt->execute();
        if ($store_row = $store_stmt->get_result()->fetch_assoc()) {
            $agent_id = (int) $store_row['agent_id'];
        }
        $store_stmt->close();
    }
}

if ($agent_id > 0) {
    $agent_stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' AND status = 'active'");
    if ($agent_stmt) {
        $agent_stmt->bind_param('i', $agent_id);
        $agent_stmt->execute();
        $agent_account = $agent_stmt->get_result()->fetch_assoc();
        $agent_stmt->close();
    } else {
        $agent_account = null;
    }

    if (empty($agent_account)) {
        $agent_id = 0;
    }
}

$stmt = $db->prepare('
    SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
           COALESCE(n.name, "Unknown") AS network_name,
           COALESCE(dp.stock_status, "in_stock") AS stock_status,
           COALESCE(pp_customer.price, pp_customer_fallback.price, dp.price, 0) AS customer_price,
           COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
           acp.custom_price AS agent_custom_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = ?
    LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = "customer"
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    WHERE dp.id = ? AND dp.status = "active" AND COALESCE(dp.stock_status, "in_stock") = "in_stock" AND (pp_customer.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0)
');
$stmt->bind_param('sii', $customer_pricing_type, $agent_id, $package_id);
$stmt->execute();
$package = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$package) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Selected package is currently out of stock or unavailable.']);
    exit();
}

$network_label = strtolower(trim((string) ($package['network_name'] ?? '')));
$network_display = $package['network_name'] ?? 'network';
$requires_validation = false;
$phone_valid = true;
if ($network_label !== '') {
    if ($network_label === 'mtn' || strpos($network_label, 'mtn') !== false) {
        $requires_validation = true;
        $network_display = 'MTN';
        $phone_valid = isMtnNumber($beneficiary_number);
        if (!$phone_valid && $allow_ported_mtn && validatePhone($beneficiary_number)) {
            $phone_valid = true;
            error_log('Customer Moolre bundle init: User confirmed ported MTN number for ' . $beneficiary_number);
        }
    } elseif ($network_label === 'at'
        || strpos($network_label, 'airtel') !== false
        || strpos($network_label, 'tigo') !== false
        || strpos($network_label, 'airteltigo') !== false) {
        $requires_validation = true;
        $network_display = 'AT';
        $phone_valid = isAtNumber($beneficiary_number);
    } elseif ($network_label === 'telecel'
        || strpos($network_label, 'vodafone') !== false
        || strpos($network_label, 'voda') !== false) {
        $requires_validation = true;
        $network_display = 'Telecel';
        $phone_valid = isTelecelNumber($beneficiary_number);
    }
}

if ($requires_validation && !$phone_valid) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid ' . $network_display . ' number for this package.']);
    exit();
}

$formatted_phone = formatPhone($beneficiary_number);
$customer_price = (float) $package['customer_price'];
$agent_wholesale_price = (float) $package['agent_wholesale_price'];
$agent_price = ($customer_pricing_type !== 'vip' && $agent_id > 0 && $package['agent_custom_price'] !== null)
    ? (float) $package['agent_custom_price']
    : $customer_price;
$price_to_charge_customer = $agent_price;

if ($price_to_charge_customer <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid package price.']);
    exit();
}

$duplicate_order = findRecentDuplicateBundleOrder(
    (int) $current_user['id'],
    (int) $package_id,
    (string) $formatted_phone,
    (float) $price_to_charge_customer
);
if ($duplicate_order) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'message' => 'A similar order was recently created. Please wait before trying again.',
        'reference' => $duplicate_order['order_reference'] ?? null
    ]);
    exit();
}

$endpoint_type = detectEndpointTypeForPackage(
    $package['name'] ?? '',
    $package['data_size'] ?? '',
    $package['package_type'] ?? ''
);
$availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
if (!$availability['available']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $availability['message']]);
    exit();
}

$reference = generateReference('PAY');
$description = 'Customer bundle purchase: ' . $package['network_name'] . ' ' . $package['data_size'] . ' for ' . $formatted_phone;
$customer_email = trim((string) ($current_user['email'] ?? ''));
if ($customer_email === '' || !validateEmail($customer_email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email address is required on your account before you can pay with Moolre.']);
    exit();
}

$metadata = [
    'type' => 'customer_bundle_purchase',
    'store_slug' => $store_slug,
    'agent_id' => $agent_id,
    'package_id' => $package_id,
    'beneficiary_number' => $formatted_phone,
    'allow_ported_mtn' => $allow_ported_mtn ? 1 : 0,
    'customer_price' => $price_to_charge_customer,
    'pricing_user_type' => $customer_pricing_type,
    'agent_cost' => $agent_wholesale_price,
    'user_id' => (int) $current_user['id'],
    'email' => (string) ($current_user['email'] ?? ''),
    'buyer_name' => (string) ($current_user['full_name'] ?? $current_user['username'] ?? ''),
    'buyer_email' => (string) ($current_user['email'] ?? ''),
    'buyer_role' => (string) ($current_user['role'] ?? 'customer'),
    'payment_gateway' => 'moolre',
    'return_to' => '/customer/order-history.php'
];
$metadata_json = json_encode($metadata);
$user_id = (int) $current_user['id'];

$redirectUrl = SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference);
$payload = [
    'type' => 1,
    'amount' => round($price_to_charge_customer, 2),
    'email' => $customer_email,
    'externalref' => $reference,
    'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
    'redirect' => $redirectUrl,
    'redirecturl' => $redirectUrl,
    'redirect_url' => $redirectUrl,
    'reusable' => '0',
    'currency' => CURRENCY_CODE,
    'accountnumber' => $config['account_number'],
    'metadata' => $metadata
];

$error = null;
$result = moolrePostJson('https://api.moolre.com/embed/link', $payload, $config, $error);
if (!$result) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error ?: 'Moolre initialization failed']);
    exit();
}

$status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
if (!$status_ok) {
    $gateway_message = trim((string) ($result['message'] ?? ''));
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $gateway_message !== '' ? $gateway_message : 'Moolre could not start the payment right now.']);
    exit();
}

$auth_url = $result['data']['authorization_url'] ?? '';
if ($auth_url === '') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Moolre did not return a checkout link.']);
    exit();
}

$stmt = $db->prepare("
    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
    VALUES (?, 'purchase', ?, 'pending', ?, 'moolre', ?, ?)
");
$stmt->bind_param('idsss', $user_id, $price_to_charge_customer, $reference, $description, $metadata_json);
$stmt->execute();
$stmt->close();

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $auth_url,
        'reference' => $reference
    ]
]);
