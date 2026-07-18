<?php
require_once '../config/config.php';
require_once __DIR__ . '/../includes/api_providers.php';

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
$email = sanitize($input['email'] ?? '');
$phone = sanitize($input['phone'] ?? '');
$package_id = (int) ($input['package_id'] ?? 0);

if ($store_slug === '' || $email === '' || $phone === '' || $package_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit();
}

if (!validateEmail($email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
    exit();
}

if (!validatePhone($phone)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid phone number']);
    exit();
}

$active_gateway = getActivePaymentGateway();
if ($active_gateway !== 'paystack') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack is not the active gateway.']);
    exit();
}

// Fetch store + agent
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

// Fetch package + pricing
$stmt = $db->prepare('
    SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.network_id,
           COALESCE(n.name, "Unknown") AS network_name,
           COALESCE(pp_customer.price, dp.price, 0) AS customer_price,
           COALESCE(pp_agent.price, dp.price, 0) AS agent_wholesale_price,
           acp.custom_price AS agent_custom_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = "customer"
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = "agent"
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    WHERE dp.id = ? AND dp.status = "active"
');
$stmt->bind_param('ii', $agent_id, $package_id);
$stmt->execute();
$package = $stmt->get_result()->fetch_assoc();
if (!$package) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Selected package not available']);
    exit();
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

$endpoint_type = (strpos(strtolower($package['name']), 'bigtime') !== false ||
                  strpos(strtolower($package['name']), 'big time') !== false) ? 'bigtime' : 'regular';
$availability = checkNetworkProviderAvailability($package['network_id'], $endpoint_type);
if (!$availability['available']) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $availability['message']]);
    exit();
}

$formatted_phone = formatPhone($phone);

// Find or create customer account
$stmt = $db->prepare('SELECT id, role, status FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_id = null;
if ($user) {
    if (($user['role'] ?? '') !== 'customer') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email already belongs to a non-customer account']);
        exit();
    }
    if (($user['status'] ?? '') !== 'active') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This account is not active']);
        exit();
    }
    $user_id = (int) $user['id'];
} else {
    $name_seed = explode('@', $email)[0];
    $name_seed = preg_replace('/[^a-zA-Z0-9]+/', ' ', $name_seed);
    $name_seed = trim($name_seed);
    if ($name_seed === '') {
        $name_seed = preg_replace('/[^0-9]/', '', $phone);
    }
    $full_name = $name_seed !== '' ? 'Guest ' . ucwords(strtolower($name_seed)) : 'Guest Customer';

    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name_seed));
    if ($base_username === '') {
        $base_username = 'guest';
    }

    $username = '';
    $tries = 0;
    while ($tries < 6) {
        $candidate = $base_username . rand(100, 999);
        $check = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->bind_param('s', $candidate);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $username = $candidate;
            break;
        }
        $tries++;
    }
    if ($username === '') {
        $username = $base_username . uniqid();
    }

    $plain_password = bin2hex(random_bytes(4));
    $password_hash = hashPassword($plain_password);
    $status = 'active';
    $role = 'customer';
    $activation_status = 'active';
    $phone_norm = formatPhone($phone);

    $has_agent_id = dbh_table_has_column('users', 'agent_id');
    $has_otp_required = dbh_table_has_column('users', 'otp_required');

    if ($has_agent_id && $has_otp_required) {
        $stmt = $db->prepare('
            INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, agent_id, otp_required)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $otp_required = 0;
        $stmt->bind_param('ssssssssii', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status, $agent_id, $otp_required);
    } elseif ($has_agent_id) {
        $stmt = $db->prepare('
            INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, agent_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('ssssssssi', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status, $agent_id);
    } elseif ($has_otp_required) {
        $stmt = $db->prepare('
            INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, otp_required)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $otp_required = 0;
        $stmt->bind_param('ssssssssi', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status, $otp_required);
    } else {
        $stmt = $db->prepare('
            INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('ssssssss', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status);
    }
    $stmt->execute();
    $user_id = $db->getConnection()->insert_id;

    if (dbh_table_exists('wallets')) {
        $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }

    if (dbh_table_exists('user_referrals')) {
        try {
            $source = 'store_guest_checkout';
            $stmt = $db->prepare('INSERT INTO user_referrals (user_id, agent_id, source, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->bind_param('iis', $user_id, $agent_id, $source);
            $stmt->execute();
        } catch (Exception $e) {
            // ignore missing table or constraints
        }
    }

    sendRegistrationCredentialsNotification([
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone_norm,
        'username' => $username,
        'plain_password' => $plain_password,
        'brand' => $store['store_name'] ?? SITE_NAME
    ], $user_id);
}

// Ensure wallet exists for existing users
$stmt = $db->prepare('SELECT id FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
if (!$wallet) {
    $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}
$isInvalidPaystackKey = function ($key) {
    $key = trim((string) $key);
    if ($key === '') {
        return true;
    }
    if (stripos($key, 'your_secret_key_here') !== false) {
        return true;
    }
    return !preg_match('/^sk_(test|live)_/i', $key);
};

$isInvalidPublicKey = function ($key) {
    $key = trim((string) $key);
    return $key === '' || stripos($key, 'your_public_key_here') !== false;
};

// Use admin Paystack keys from .env, fallback to database constant settings if invalid/placeholder
$paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
if ($isInvalidPaystackKey($paystack_secret_key)) {
    $paystack_secret_key = PAYSTACK_SECRET_KEY;
}
$paystack_public_key = dbh_env('PAYSTACK_PUBLIC_KEY');
if ($isInvalidPublicKey($paystack_public_key)) {
    $paystack_public_key = PAYSTACK_PUBLIC_KEY;
}

if ($isInvalidPaystackKey($paystack_secret_key)) {
    $maskKey = function($key) {
        $key = trim((string)$key);
        if ($key === '') return '[empty]';
        if (strlen($key) <= 10) return $key;
        return substr($key, 0, 5) . '...' . substr($key, -5);
    };
    $resolved_source = getenv('PAYSTACK_SECRET_KEY') !== false ? '.env (getenv)' : (isset($_ENV['PAYSTACK_SECRET_KEY']) ? '.env ($_ENV)' : 'database settings/fallback');
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Paystack keys are not configured. Resolved secret key: ' . $maskKey($paystack_secret_key) . ' from ' . $resolved_source . '.'
    ]);
    exit();
}

$recent_order = findRecentDuplicateBundleOrder($user_id, $package_id, $formatted_phone, $price_to_charge_customer, 180);
if ($recent_order) {
    http_response_code(409);
    $dup_ref = $recent_order['order_reference'] ?? ('#' . (int) ($recent_order['id'] ?? 0));
    echo json_encode([
        'status' => 'error',
        'message' => 'A similar order was already placed recently (Ref: ' . $dup_ref . '). Please wait before trying again.'
    ]);
    exit();
}

$recent_txn = findRecentGuestBundleTransaction($user_id, $package_id, $formatted_phone, $price_to_charge_customer, 180);
if ($recent_txn) {
    $tx_status = strtolower(trim((string) ($recent_txn['status'] ?? '')));
    $tx_ref = $recent_txn['reference'] ?? '';
    if ($tx_status === 'pending' || $tx_status === 'processing') {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'A similar payment is already in progress. Please complete it before starting another one.'
        ]);
        exit();
    }
    if ($tx_status === 'success') {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'A similar payment was already completed recently (Ref: ' . $tx_ref . '). Please wait for delivery confirmation.'
        ]);
        exit();
    }
}

// Create pending transaction
$reference = generateReference('PAY');
$description = 'Guest bundle purchase: ' . $package['network_name'] . ' ' . $package['data_size'] . ' for ' . $formatted_phone;
$metadata = [
    'type' => 'guest_bundle_purchase',
    'store_slug' => $store_slug,
    'agent_id' => $agent_id,
    'package_id' => $package_id,
    'beneficiary_number' => $formatted_phone,
    'customer_price' => $price_to_charge_customer,
    'agent_cost' => $agent_wholesale_price,
    'user_id' => $user_id,
    'email' => $email
];
$metadata_json = json_encode($metadata);

$stmt = $db->prepare("
    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
    VALUES (?, 'purchase', ?, 'pending', ?, 'paystack', ?, ?)
");
$stmt->bind_param('idsss', $user_id, $price_to_charge_customer, $reference, $description, $metadata_json);
$stmt->execute();



$postfields = json_encode([
    'email' => $email,
    'amount' => $price_to_charge_customer * 100,
    'currency' => CURRENCY_CODE,
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL,
    'metadata' => [
        'type' => 'guest_bundle_purchase',
        'store_slug' => $store_slug,
        'package_id' => $package_id,
        'beneficiary_number' => $formatted_phone,
        'user_id' => $user_id
    ]
]);

$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => $postfields,
    CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer " . $paystack_secret_key,
        "Content-Type: application/json",
    ),
));

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Paystack initialization error: ' . $err]);
    exit();
}

$result = json_decode($response, true);
if (!$result || empty($result['status'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to initialize payment']);
    exit();
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $result['data']['authorization_url'] ?? '',
        'access_code' => $result['data']['access_code'] ?? '',
        'reference' => $reference
    ]
]);
