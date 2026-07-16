<?php
require_once '../config/config.php';

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
$card_type = strtoupper(trim((string) ($input['card_type'] ?? '')));
$quantity = isset($input['quantity']) ? (int) $input['quantity'] : 1;

if ($store_slug === '' || $email === '' || $phone === '' || !in_array($card_type, ['BECE', 'WASSCE'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid required fields']);
    exit();
}

if ($quantity < 1) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Quantity must be at least 1']);
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

ensureResultCheckerTables();
ensurePaymentGatewaySchema();

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

// Load settings and resolve base admin price
$settings = [
    'bece_price' => 17.00,
    'wassce_price' => 17.00,
    'bece_enabled' => 0,
    'wassce_enabled' => 0
];
$settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
    $settings = array_merge($settings, $settings_row);
}

$bece_enabled = ((int) $settings['bece_enabled'] === 1) || ((float) $settings['bece_price'] > 0);
$wassce_enabled = ((int) $settings['wassce_enabled'] === 1) || ((float) $settings['wassce_price'] > 0);
$enabled = $card_type === 'BECE' ? $bece_enabled : $wassce_enabled;
if (!$enabled) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Selected card type is currently unavailable']);
    exit();
}

$unit_admin_price = $card_type === 'BECE' ? (float) $settings['bece_price'] : (float) $settings['wassce_price'];
if ($unit_admin_price <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid card price']);
    exit();
}

// Resolve agent custom price if available
$unit_price_to_charge = $unit_admin_price;
if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing')) {
    $has_is_active = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'is_active');
    $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'updated_at');
    $has_created_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'created_at');

    $where = "WHERE agent_id = ? AND card_type = ?";
    if ($has_is_active) {
        $where .= " AND is_active = 1";
    }
    $orderBy = '';
    if ($has_updated_at) {
        $orderBy = ' ORDER BY updated_at DESC';
    } elseif ($has_created_at) {
        $orderBy = ' ORDER BY created_at DESC';
    }

    $stmt = $db->prepare("SELECT custom_price FROM agent_result_checker_pricing {$where}{$orderBy} LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('is', $agent_id, $card_type);
        $stmt->execute();
        $price_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($price_row) {
            $candidate = (float) ($price_row['custom_price'] ?? 0);
            if ($candidate >= $unit_admin_price) {
                $unit_price_to_charge = $candidate;
            }
        }
    }
}

$price_to_charge_customer = round($unit_price_to_charge * $quantity, 2);
$admin_price_total = round($unit_admin_price * $quantity, 2);
$profit_amount_total = $agent_id > 0 ? round(max(0, $price_to_charge_customer - $admin_price_total), 2) : 0.0;

// Ensure enough stock before payment init
$available_count = 0;
$stmt = $db->prepare("
    SELECT COUNT(*) AS total_count
    FROM result_checker_cards
    WHERE card_type = ? AND status = 'available'
");
$stmt->bind_param('s', $card_type);
$stmt->execute();
if ($row = $stmt->get_result()->fetch_assoc()) {
    $available_count = (int) ($row['total_count'] ?? 0);
}
$stmt->close();
if ($available_count < $quantity) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Only ' . $available_count . ' cards available for the selected type.']);
    exit();
}

$formatted_phone = formatPhone($phone);

// Find or create customer account
$stmt = $db->prepare('SELECT id, role, status, full_name FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_id = null;
$buyer_name = '';
$buyer_role = 'customer';
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
    $buyer_name = (string) ($user['full_name'] ?? '');
    $buyer_role = 'guest';
} else {
    $name_seed = explode('@', $email)[0];
    $name_seed = preg_replace('/[^a-zA-Z0-9]+/', ' ', $name_seed);
    $name_seed = trim($name_seed);
    if ($name_seed === '') {
        $name_seed = preg_replace('/[^0-9]/', '', $phone);
    }
    $full_name = $name_seed !== '' ? 'Guest ' . ucwords(strtolower($name_seed)) : 'Guest Customer';
    $buyer_name = $full_name;
    $buyer_role = 'guest';

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

    if ($agent_id > 0 && dbh_table_exists('user_referrals')) {
        try {
            $source = 'store_guest_result_checker';
            $stmt = $db->prepare('INSERT INTO user_referrals (user_id, agent_id, source, created_at) VALUES (?, ?, ?, NOW())');
            $stmt->bind_param('iis', $user_id, $agent_id, $source);
            $stmt->execute();
        } catch (Exception $e) {
            // Ignore optional referral failures.
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

// Create pending transaction + pending purchase record
$reference = generateReference('RCG');
$description = 'Guest result checker purchase: ' . $card_type . ($quantity > 1 ? (' x' . $quantity) : '');
$metadata = [
    'type' => 'result_checker_purchase',
    'card_type' => $card_type,
    'quantity' => $quantity,
    'agent_id' => $agent_id,
    'admin_price' => $admin_price_total,
    'sms_phone' => $formatted_phone,
    'notification_email' => $email,
    'store_slug' => $store_slug,
    'buyer_name' => $buyer_name,
    'buyer_email' => $email,
    'buyer_role' => $buyer_role,
    'user_id' => $user_id,
    'return_to' => '/customer/result-checker-history.php'
];
$metadata_json = json_encode($metadata);

$stmt = $db->prepare("
    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
    VALUES (?, 'purchase', ?, 'pending', ?, 'paystack', ?, ?)
");
$stmt->bind_param('idsss', $user_id, $price_to_charge_customer, $reference, $description, $metadata_json);
$stmt->execute();

$stmt = $db->prepare("
    INSERT INTO result_checker_purchases
        (user_id, agent_id, card_type, amount, admin_price, profit_amount, payment_gateway, reference, status, sms_phone, notification_email)
    VALUES (?, ?, ?, ?, ?, ?, 'paystack', ?, 'pending', ?, ?)
");
$stmt->bind_param(
    'iisdddsss',
    $user_id,
    $agent_id,
    $card_type,
    $price_to_charge_customer,
    $admin_price_total,
    $profit_amount_total,
    $reference,
    $formatted_phone,
    $email
);
$stmt->execute();

// Use admin Paystack keys from .env
$paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
if (isInvalidPaystackKey($paystack_secret_key)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack keys are not configured.']);
    exit();
}

$checkout = initializePaystackCheckout($paystack_secret_key, [
    'email' => $email,
    'amount' => (int) round($price_to_charge_customer * 100),
    'currency' => CURRENCY_CODE,
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL,
    'metadata' => $metadata
]);

if (empty($checkout['ok'])) {
    http_response_code((int) ($checkout['status_code'] ?? 500));
    echo json_encode(['status' => 'error', 'message' => $checkout['message'] ?? 'Failed to initialize payment']);
    exit();
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $checkout['authorization_url'] ?? '',
        'access_code' => $checkout['access_code'] ?? '',
        'reference' => $reference
    ]
]);
