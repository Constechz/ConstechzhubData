<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';
ensureGuestCheckoutSchema();
ensureDataPackageStockStatusColumn();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$store_slug = sanitize($input['store_slug'] ?? '');
$provided_email = strtolower(trim((string) ($input['email'] ?? '')));
$phone = sanitize($input['phone'] ?? '');
$allow_ported_mtn = !empty($input['allow_ported_mtn']) && (string) $input['allow_ported_mtn'] === '1';
$package_id = (int) ($input['package_id'] ?? 0);

if ($store_slug === '' || $phone === '' || $provided_email === '' || $package_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

if (!validateEmail($provided_email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Enter a valid email address. It will be used to verify your payment if the order does not go through.']);
    exit();
}

if (!validatePhone($phone)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid phone number']);
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

$stmt = $db->prepare("
    SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
    LIMIT 1
");
$stmt->bind_param("s", $store_slug);
$stmt->execute();
$store = $stmt->get_result()->fetch_assoc();
if (!$store) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Store not found']);
    exit();
}
$agent_id = (int) $store['agent_id'];

$stmt = $db->prepare('
    SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
           COALESCE(n.name, "Unknown") AS network_name,
           COALESCE(dp.stock_status, "in_stock") AS stock_status,
           COALESCE(pp_customer.price, dp.price, 0) AS customer_price,
           COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
           acp.custom_price AS agent_custom_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    WHERE dp.id = ? AND dp.status = "active" AND COALESCE(dp.stock_status, "in_stock") = "in_stock"
');
$stmt->bind_param('ii', $agent_id, $package_id);
$stmt->execute();
$package = $stmt->get_result()->fetch_assoc();
if (!$package) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Selected package is currently out of stock or unavailable']);
    exit();
}

$network_label = strtolower(trim((string) ($package['network_name'] ?? '')));
if ($network_label === 'mtn' || strpos($network_label, 'mtn') !== false) {
    if (!isMtnNumber($phone) && !($allow_ported_mtn && validatePhone($phone))) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid MTN number, or confirm that this number has been ported to MTN.']);
        exit();
    }
} elseif ($network_label === 'at'
    || strpos($network_label, 'airtel') !== false
    || strpos($network_label, 'tigo') !== false
    || strpos($network_label, 'airteltigo') !== false) {
    if (!isAtNumber($phone)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid AT number for this package.']);
        exit();
    }
} elseif ($network_label === 'telecel'
    || strpos($network_label, 'vodafone') !== false
    || strpos($network_label, 'voda') !== false) {
    if (!isTelecelNumber($phone)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid Telecel number for this package.']);
        exit();
    }
}

$customer_price = (float) $package['customer_price'];
$agent_wholesale_price = (float) $package['agent_wholesale_price'];
$agent_custom_price = $package['agent_custom_price'];
$price_to_charge_customer = ($agent_custom_price !== null) ? (float) $agent_custom_price : $customer_price;

if ($price_to_charge_customer <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid package price']);
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

$formatted_phone = formatPhone($phone);
$email = $provided_email;
$user_id = 0;
$buyer_name = 'Guest Customer';
$buyer_email = $provided_email;
$buyer_role = 'guest';

$recent_duplicate = findRecentGuestBundleTransaction(
    (int) $user_id,
    (int) $package_id,
    (string) $formatted_phone,
    (float) $price_to_charge_customer,
    180,
    (string) $store_slug
);
if ($recent_duplicate) {
    $duplicate_status = strtolower(trim((string) ($recent_duplicate['status'] ?? '')));
    if (in_array($duplicate_status, ['processing', 'success'], true)) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => $duplicate_status === 'success'
                ? 'This order was already completed. Opening the order status page.'
                : 'A previous payment is already being processed. Opening the order status page.',
            'reference' => $recent_duplicate['reference'] ?? null,
            'next_url' => '/store/reference.php?store=' . urlencode($store_slug) . '&lookup=' . urlencode((string) ($recent_duplicate['reference'] ?? ''))
        ]);
        exit();
    }
}

$reference = generateReference('PAY');
$description = 'Guest bundle purchase: ' . $package['network_name'] . ' ' . $package['data_size'] . ' for ' . $formatted_phone;
$metadata = [
    'type' => 'guest_bundle_purchase',
    'store_slug' => $store_slug,
    'agent_id' => $agent_id,
    'package_id' => $package_id,
    'beneficiary_number' => $formatted_phone,
    'allow_ported_mtn' => $allow_ported_mtn ? 1 : 0,
    'customer_price' => $price_to_charge_customer,
    'agent_cost' => $agent_wholesale_price,
    'user_id' => $user_id,
    'email' => $email,
    'buyer_name' => $buyer_name,
    'buyer_email' => $buyer_email,
    'buyer_role' => $buyer_role,
    'payment_gateway' => 'moolre',
    'return_to' => '/store/reference.php?store=' . urlencode($store_slug) . '&lookup=' . urlencode($reference)
];
$metadata_json = json_encode($metadata);

$redirectUrl = SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference);
$payload = [
    'type' => 1,
    'amount' => round($price_to_charge_customer, 2),
    'email' => $email,
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
$guest_user_id = null;
$stmt->bind_param('idsss', $guest_user_id, $price_to_charge_customer, $reference, $description, $metadata_json);
$stmt->execute();

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $auth_url,
        'reference' => $reference
    ]
]);
?>
