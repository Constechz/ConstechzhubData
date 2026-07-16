<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

requireAnyRole(['agent', 'vip']);
$current_user = getCurrentUser();
ensureDataPackageStockStatusColumn();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$package_id = (int) ($input['package_id'] ?? 0);
$beneficiary_number = sanitize($input['beneficiary_number'] ?? '');
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
if ($requested_gateway !== '' && $requested_gateway !== 'paystack') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid gateway selection for this endpoint.']);
    exit();
}

if (!isPaymentGatewayEnabled('paystack')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack is currently disabled by admin settings.']);
    exit();
}

if (!function_exists('normalizeTelecelLocalPhone')) {
    function normalizeTelecelLocalPhone($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strpos($digits, '233') === 0) {
            return '0' . substr($digits, 3);
        }
        return $digits;
    }
}

if (!function_exists('isTelecelLocalPhone')) {
    function isTelecelLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) {
            return false;
        }
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['020', '050'], true);
    }
}

if (!validatePhone($beneficiary_number)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number']);
    exit();
}

$local_phone = normalizeTelecelLocalPhone($beneficiary_number);
if (!isTelecelLocalPhone($local_phone)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Use Telecel numbers only (020/050) and 10 digits.']);
    exit();
}

$stmt = $db->prepare('
    SELECT dp.id, dp.name, dp.package_type, dp.data_size, dp.validity_days, dp.network_id,
           COALESCE(n.name, "Unknown") AS network_name,
           COALESCE(dp.stock_status, "in_stock") AS stock_status,
           COALESCE(pp_agent.price, pp_customer.price, dp.price, 0) AS agent_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
    WHERE dp.id = ? AND dp.status = "active" AND COALESCE(dp.stock_status, "in_stock") = "in_stock" AND n.name = "Telecel"
');
$stmt->bind_param('i', $package_id);
$stmt->execute();
$package = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$package) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Selected package is currently out of stock or unavailable.']);
    exit();
}

$price_to_charge = (float) ($package['agent_price'] ?? 0);
if ($price_to_charge <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This package is not available for online payment right now.']);
    exit();
}

$formatted_phone = formatPhone($beneficiary_number);

$duplicate_order = findRecentDuplicateBundleOrder(
    (int) $current_user['id'],
    (int) $package_id,
    (string) $formatted_phone,
    (float) $price_to_charge,
    180
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

$recent_duplicate = findRecentGuestBundleTransaction(
    (int) $current_user['id'],
    (int) $package_id,
    (string) $formatted_phone,
    (float) $price_to_charge,
    180
);

if ($recent_duplicate) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'message' => 'A similar order is already pending or was just created. Please wait before trying again.',
        'reference' => $recent_duplicate['reference'] ?? null
    ]);
    exit();
}

$endpoint_type = detectEndpointTypeForPackage(
    $package['name'] ?? '',
    $package['data_size'] ?? '',
    $package['package_type'] ?? ''
);
$availability = checkNetworkProviderAvailability((int) $package['network_id'], $endpoint_type);
if (!$availability['available']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $availability['message']]);
    exit();
}

$paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
if (!isConfiguredPaystackSecretKey($paystack_secret_key)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack keys are not configured.']);
    exit();
}

$reference = generateReference('PAY');
$description = 'Agent bundle purchase: Telecel ' . ($package['data_size'] ?? $package['name'] ?? 'bundle') . ' for ' . $formatted_phone;
$customer_email = trim((string) ($current_user['email'] ?? ''));
if ($customer_email === '' || !validateEmail($customer_email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email address is required on your account before you can pay with Paystack.']);
    exit();
}

$metadata = [
    'type' => 'customer_bundle_purchase',
    'store_slug' => '',
    'agent_id' => 0,
    'package_id' => $package_id,
    'beneficiary_number' => $formatted_phone,
    'customer_price' => $price_to_charge,
    'agent_cost' => $price_to_charge,
    'user_id' => (int) $current_user['id'],
    'email' => (string) ($current_user['email'] ?? ''),
    'buyer_name' => (string) ($current_user['full_name'] ?? $current_user['username'] ?? ''),
    'buyer_email' => (string) ($current_user['email'] ?? ''),
    'buyer_role' => (string) ($current_user['role'] ?? 'agent'),
    'return_to' => '/agent/telecel-business.php'
];
$metadata_json = json_encode($metadata);
$user_id = (int) $current_user['id'];
$checkout = initializePaystackCheckout($paystack_secret_key, [
    'email' => $customer_email,
    'amount' => (int) round($price_to_charge * 100),
    'currency' => CURRENCY_CODE,
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL,
    'metadata' => [
        'type' => 'customer_bundle_purchase',
        'package_id' => $package_id,
        'beneficiary_number' => $formatted_phone,
        'user_id' => $user_id
    ]
]);
if (empty($checkout['ok'])) {
    http_response_code((int) ($checkout['status_code'] ?? 500));
    echo json_encode(['status' => 'error', 'message' => $checkout['message'] ?? 'Failed to initialize payment']);
    exit();
}

$stmt = $db->prepare("
    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
    VALUES (?, 'purchase', ?, 'pending', ?, 'paystack', ?, ?)
");
$stmt->bind_param('idsss', $user_id, $price_to_charge, $reference, $description, $metadata_json);
$stmt->execute();
$stmt->close();

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $checkout['authorization_url'] ?? '',
        'access_code' => $checkout['access_code'] ?? '',
        'reference' => $reference
    ]
]);
