<?php
require_once '../config/config.php';

requireLogin();
header('Content-Type: application/json');

if (!function_exists('afa_json_response')) {
    function afa_json_response(array $payload, int $status = 200) {
        http_response_code($status);
        echo json_encode($payload);
        exit();
    }
}

if (!function_exists('afa_json_error')) {
    function afa_json_error(string $message, int $status = 400, array $extra = []) {
        afa_json_response(array_merge(['status' => 'error', 'message' => $message], $extra), $status);
    }
}

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

if (!function_exists('afa_store_card_image')) {
    function afa_store_card_image(string $fileKey, string $prefix = 'card') {
        if (!isset($_FILES[$fileKey])) {
            afa_json_error('Please upload both front and back Ghana card images.');
        }

        $file = $_FILES[$fileKey];
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            afa_json_error('Failed to upload Ghana card image. Please try again.');
        }

        $maxBytes = 5 * 1024 * 1024;
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            afa_json_error('Each Ghana card image must be between 1 byte and 5MB.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            afa_json_error('Invalid uploaded file.');
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            afa_json_error('Only JPG, PNG, or WEBP Ghana card images are allowed.');
        }

        $uploadDir = __DIR__ . '/../uploads/afa_cards';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            afa_json_error('Unable to create upload directory.', 500);
        }

        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $destPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            afa_json_error('Could not save uploaded Ghana card image.', 500);
        }

        return 'uploads/afa_cards/' . $filename;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    afa_json_error('Method not allowed', 405);
}

ensureAfaRegistrationTables();
ensurePaymentGatewaySchema();

$current_user = getCurrentUser();
if (!$current_user) {
    afa_json_error('Unauthorized', 401);
}

$payload = [];
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} else {
    $payload = $_POST;
}

$csrf_token = $payload['csrf_token'] ?? '';
if (!validateCSRF($csrf_token)) {
    afa_json_error('Invalid session token', 403);
}

$store_slug = sanitize($payload['store_slug'] ?? '');
$requested_gateway = sanitize($payload['gateway'] ?? '');

$beneficiary_name = trim((string) ($payload['beneficiary_name'] ?? ''));
$email = $current_user['email'] ?? '';
$phone_input = trim((string) ($payload['phone'] ?? ''));
$ghana_card_number_raw = trim((string) ($payload['ghana_card_number'] ?? ''));
$location = trim((string) ($payload['location'] ?? ''));
$occupation = trim((string) ($payload['occupation'] ?? ''));
$region = null;
$date_of_birth = trim((string) ($payload['date_of_birth'] ?? ''));
$payment_method = strtolower(trim((string) ($payload['payment_method'] ?? 'wallet')));

if ($beneficiary_name === '' || $email === '' || $phone_input === '' || $ghana_card_number_raw === '' || $location === '' || $occupation === '' || $date_of_birth === '') {
    afa_json_error('All fields are compulsory.');
}
if (!validateEmail($email)) {
    afa_json_error('Invalid email address.');
}
if (!in_array($payment_method, ['wallet', 'gateway'], true)) {
    afa_json_error('Invalid payment method.');
}

$phone_digits = preg_replace('/\D+/', '', $phone_input);
if ($phone_digits !== $phone_input || strlen($phone_digits) < 10 || strlen($phone_digits) > 15) {
    afa_json_error('Number for registration must contain digits only (10-15 digits).');
}
$phone = formatPhone($phone_digits);

$ghana_card_number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ghana_card_number_raw));
if (!preg_match('/^[A-Z0-9]{13}$/', $ghana_card_number)) {
    afa_json_error('Ghana Card number must be exactly 13 alphanumeric characters.');
}

$front_image = null;
$back_image = null;

$settings = [
    'agent_price' => 0,
    'guest_price' => 0,
    'is_enabled' => 0,
    'allow_wallet_agent' => 1,
    'allow_gateway_agent' => 1,
    'allow_wallet_customer' => 1,
    'allow_gateway_customer' => 1,
    'allow_guest_paystack' => 1,
    'allow_guest_moolre' => 1,
];
$settings_rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && ($settings_row = $settings_rs->fetch_assoc())) {
    $settings = array_merge($settings, $settings_row);
}

$service_enabled = ((int) ($settings['is_enabled'] ?? 0) === 1) || ((float) ($settings['agent_price'] ?? 0) > 0);
if (!$service_enabled) {
    afa_json_error('AFA registration is currently unavailable.');
}

$agent_base_price = round((float) ($settings['agent_price'] ?? 0), 2);
if ($agent_base_price <= 0) {
    afa_json_error('AFA registration pricing is not configured yet.');
}

$current_role = normalizeUserRole($current_user['role'] ?? '');
$is_agent = ($current_role === 'agent' || $current_role === 'vip');
$is_customer = ($current_role === 'customer');
if (!$is_agent && !$is_customer) {
    afa_json_error('Only agents, VIPs, and customers can perform this operation.', 403);
}

$agent_id = 0;
$unit_price_to_charge = $agent_base_price;
$unit_admin_price = $agent_base_price;
$unit_profit_amount = 0.0;

if ($current_role === 'vip') {
    $return_to = '/vip/afa-registration.php';
} elseif ($is_agent) {
    $return_to = '/agent/afa-registration.php';
} else {
    $return_to = '/customer/afa-registration.php';
}

if ($is_customer) {
    if ($store_slug !== '') {
        $stmt = $db->prepare("SELECT ast.agent_id FROM agent_stores ast JOIN users u ON ast.agent_id = u.id WHERE ast.store_slug = ? AND ast.is_active = 1 AND u.status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $store_slug);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $agent_id = (int) ($row['agent_id'] ?? 0);
            }
            $stmt->close();
        }
    }
    if ($agent_id <= 0) {
        $agent_id = (int) getLinkedAgentId($current_user['id']);
    }

    if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('agent_afa_registration_pricing')) {
        $where = "WHERE agent_id = ?";
        if (function_exists('dbh_table_has_column') && dbh_table_has_column('agent_afa_registration_pricing', 'is_active')) {
            $where .= " AND is_active = 1";
        }
        $stmt = $db->prepare("SELECT custom_price FROM agent_afa_registration_pricing {$where} ORDER BY updated_at DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $agent_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $candidate = (float) ($row['custom_price'] ?? 0);
                if ($candidate >= $agent_base_price) {
                    $unit_price_to_charge = round($candidate, 2);
                }
            }
            $stmt->close();
        }
    }
} elseif ($is_agent) {
    $agent_id = (int) ($current_user['id'] ?? 0);
}

$unit_profit_amount = ($agent_id > 0 && function_exists('calculateAgentAfaCommissionAmount'))
    ? calculateAgentAfaCommissionAmount(1)
    : 0.0;
$unit_profit_amount = round($unit_profit_amount, 2);

$allow_wallet = $is_agent ? ((int) ($settings['allow_wallet_agent'] ?? 1) === 1) : ((int) ($settings['allow_wallet_customer'] ?? 1) === 1);
$allow_gateway = $is_agent ? ((int) ($settings['allow_gateway_agent'] ?? 1) === 1) : ((int) ($settings['allow_gateway_customer'] ?? 1) === 1);

if ($payment_method === 'wallet' && !$allow_wallet) {
    afa_json_error('Wallet payment is disabled for your account type.');
}
if ($payment_method === 'gateway' && !$allow_gateway) {
    afa_json_error('Gateway payment is disabled for your account type.');
}

$amount = $unit_price_to_charge;
$admin_price = $unit_admin_price;
$profit_amount = $unit_profit_amount;

if ($payment_method === 'gateway') {
    if ($requested_gateway !== '' && !isPaymentGatewayEnabled($requested_gateway)) {
        afa_json_error('Selected payment gateway is currently unavailable.');
    }

    $gateway = $requested_gateway !== '' ? $requested_gateway : getActivePaymentGateway();
    if (!in_array($gateway, ['paystack', 'moolre'], true) || !isPaymentGatewayEnabled($gateway)) {
        afa_json_error('No active payment gateway available.');
    }

    $reference = generateReference('AFR');
    $description = 'AFA registration fee';
    $metadata = [
        'type' => 'afa_registration_purchase',
        'beneficiary_name' => $beneficiary_name,
        'email' => $email,
        'phone' => $phone,
        'ghana_card_number' => $ghana_card_number,
        'ghana_card_front_image' => $front_image,
        'ghana_card_back_image' => $back_image,
        'location' => $location,
        'occupation' => $occupation,
        'region' => $region,
        'date_of_birth' => $date_of_birth,
        'agent_id' => $agent_id,
        'admin_price' => $admin_price,
        'profit_amount' => $profit_amount,
        'buyer_name' => $current_user['full_name'] ?? '',
        'buyer_email' => $current_user['email'] ?? $email,
        'buyer_role' => $current_user['role'] ?? 'customer',
        'store_slug' => $store_slug,
        'return_to' => $return_to
    ];
    $metadata_json = json_encode($metadata);

    $stmt = $db->prepare("INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata) VALUES (?, 'purchase', ?, 'pending', ?, ?, ?, ?)");
    if (!$stmt) {
        afa_json_error('Failed to create transaction. Please try again.', 500);
    }
    $stmt->bind_param('idssss', $current_user['id'], $amount, $reference, $gateway, $description, $metadata_json);
    if (!$stmt->execute()) {
        $stmt->close();
        afa_json_error('Failed to create transaction. Please try again.', 500);
    }
    $stmt->close();

    $agent_sql = $agent_id > 0 ? (string) ((int) $agent_id) : 'NULL';
    $insert_sql = "INSERT INTO afa_registrations (user_id, agent_id, beneficiary_name, email, phone, ghana_card_number, ghana_card_front_image, ghana_card_back_image, location, occupation, region, date_of_birth, amount, admin_price, profit_amount, payment_gateway, reference, status) VALUES (?, {$agent_sql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $db->prepare($insert_sql);
    if (!$stmt) {
        afa_json_error('Failed to save registration details. Please try again.', 500);
    }
    $stmt->bind_param('issssssssssdddss', $current_user['id'], $beneficiary_name, $email, $phone, $ghana_card_number, $front_image, $back_image, $location, $occupation, $region, $date_of_birth, $amount, $admin_price, $profit_amount, $gateway, $reference);
    if (!$stmt->execute()) {
        $stmt->close();
        afa_json_error('Failed to save registration details. Please try again.', 500);
    }
    $stmt->close();

    if (function_exists('notifyAfaRegistrationSubmitted')) {
        notifyAfaRegistrationSubmitted($reference);
    }

    if ($gateway === 'paystack') {
        if (!function_exists('curl_init')) {
            afa_json_error('Payment gateway is unavailable because cURL is not enabled on this server.', 500);
        }

        $paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
        $isInvalidPaystackKey = function ($key) {
            $key = trim((string) $key);
            return $key === '' || stripos($key, 'your_secret_key_here') !== false;
        };
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            $paystack_secret_key = PAYSTACK_SECRET_KEY;
        }
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            afa_json_error('Paystack keys are not configured.', 400);
        }

        $postfields = json_encode([
            'email' => $current_user['email'] ?? $email,
            'amount' => $amount * 100,
            'currency' => CURRENCY_CODE,
            'reference' => $reference,
            'callback_url' => PAYSTACK_CALLBACK_URL,
            'metadata' => $metadata
        ]);

        $curl = curl_init();
        $options = [
            CURLOPT_URL => 'https://api.paystack.co/transaction/initialize',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $paystack_secret_key,
                'Content-Type: application/json',
            ],
        ];
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            afa_json_error('Failed to initialize Paystack payment.', 500);
        }

        $result = json_decode($response, true);
        if (!$result || empty($result['status'])) {
            $message = $result['message'] ?? 'Unknown error';
            afa_json_error($message, 500);
        }

        afa_json_response([
            'status' => 'success',
            'data' => [
                'authorization_url' => $result['data']['authorization_url'] ?? '',
                'reference' => $reference
            ]
        ]);
    }

    if (!function_exists('curl_init')) {
        afa_json_error('Payment gateway is unavailable because cURL is not enabled on this server.', 500);
    }

    $config = getMoolreConfig();
    if (!isMoolreConfigured($config)) {
        afa_json_error('Moolre keys are not configured.', 400);
    }

    $redirectUrl = SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference);
    $gateway_payload = [
        'type' => 1,
        'amount' => round($amount, 2),
        'email' => $current_user['email'] ?? $email,
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
    $result = moolrePostJson('https://api.moolre.com/embed/link', $gateway_payload, $config, $error);
    if (!$result) {
        afa_json_error($error ?: 'Failed to initialize Moolre payment.', 500);
    }

    $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
    if (!$status_ok) {
        $message = $result['message'] ?? 'Moolre initialization failed.';
        afa_json_error($message, 500);
    }

    $auth_url = $result['data']['authorization_url'] ?? '';
    if ($auth_url === '') {
        afa_json_error('Missing authorization URL from Moolre.', 500);
    }

    afa_json_response([
        'status' => 'success',
        'data' => [
            'authorization_url' => $auth_url,
            'reference' => $reference
        ]
    ]);
}

$reference = generateReference('AFR');
$description = 'AFA registration fee';
$agent_reference = generateReference('AFRA');
$is_agent_self_order = $is_agent && $agent_id === (int) ($current_user['id'] ?? 0);

if ($is_agent_self_order) {
    $balance = getWalletBalance($current_user['id']);
    if ($balance < $amount) {
        afa_json_error('Insufficient wallet balance. Please top up.');
    }
    if (!updateWalletBalance($current_user['id'], $amount, 'debit', $reference, $description)) {
        afa_json_error('Failed to deduct wallet balance.', 500);
    }
} elseif ($agent_id > 0) {
    $customer_balance = getWalletBalance($current_user['id']);
    if ($customer_balance < $amount) {
        afa_json_error('Insufficient wallet balance. Please top up.');
    }

    $agent_balance = getWalletBalance($agent_id);
    if (($agent_balance + $amount) < $admin_price) {
        afa_json_error('Agent has insufficient balance to fulfill this order.');
    }

    if (!transferWalletBalance($current_user['id'], $agent_id, $amount, $reference, $description)) {
        afa_json_error('Failed to process wallet payment.', 500);
    }

    if (!updateWalletBalance($agent_id, $admin_price, 'debit', $agent_reference, 'AFA registration wholesale cost')) {
        transferWalletBalance($agent_id, $current_user['id'], $amount, $reference . '_REFUND', 'Refund: AFA wholesale cost failed');
        afa_json_error('Failed to process agent wholesale cost.', 500);
    }
} else {
    $balance = getWalletBalance($current_user['id']);
    if ($balance < $amount) {
        afa_json_error('Insufficient wallet balance. Please top up.');
    }
    if (!updateWalletBalance($current_user['id'], $amount, 'debit', $reference, $description)) {
        afa_json_error('Failed to deduct wallet balance.', 500);
    }
}

$agent_sql = $agent_id > 0 ? (string) ((int) $agent_id) : 'NULL';
$insert_sql = "INSERT INTO afa_registrations (user_id, agent_id, beneficiary_name, email, phone, ghana_card_number, ghana_card_front_image, ghana_card_back_image, location, occupation, region, date_of_birth, amount, admin_price, profit_amount, payment_gateway, reference, status, processing_at) VALUES (?, {$agent_sql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'wallet', ?, 'processing', NOW())";
$stmt = $db->prepare($insert_sql);
if (!$stmt) {
    if ($is_agent_self_order) {
        updateWalletBalance($current_user['id'], $amount, 'credit', $reference . '_REFUND', 'Refund: registration save failed');
    } elseif ($agent_id > 0) {
        updateWalletBalance($agent_id, $admin_price, 'credit', $agent_reference . '_REFUND', 'Refund: registration save failed');
        transferWalletBalance($agent_id, $current_user['id'], $amount, $reference . '_REFUND', 'Refund: registration save failed');
    } else {
        updateWalletBalance($current_user['id'], $amount, 'credit', $reference . '_REFUND', 'Refund: registration save failed');
    }
    afa_json_error('Failed to save AFA registration. Amount refunded.', 500);
}
$stmt->bind_param('issssssssssddds', $current_user['id'], $beneficiary_name, $email, $phone, $ghana_card_number, $front_image, $back_image, $location, $occupation, $region, $date_of_birth, $amount, $admin_price, $profit_amount, $reference);
if (!$stmt->execute()) {
    $stmt->close();
    if ($is_agent_self_order) {
        updateWalletBalance($current_user['id'], $amount, 'credit', $reference . '_REFUND', 'Refund: registration save failed');
    } elseif ($agent_id > 0) {
        updateWalletBalance($agent_id, $admin_price, 'credit', $agent_reference . '_REFUND', 'Refund: registration save failed');
        transferWalletBalance($agent_id, $current_user['id'], $amount, $reference . '_REFUND', 'Refund: registration save failed');
    } else {
        updateWalletBalance($current_user['id'], $amount, 'credit', $reference . '_REFUND', 'Refund: registration save failed');
    }
    afa_json_error('Failed to save AFA registration. Amount refunded.', 500);
}
$stmt->close();

$stmt = $db->prepare("INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata) VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?, ?) ON DUPLICATE KEY UPDATE metadata = VALUES(metadata)");
if ($stmt) {
    $meta = json_encode([
        'type' => 'afa_registration_purchase',
        'beneficiary_name' => $beneficiary_name,
        'email' => $email,
        'phone' => $phone,
        'ghana_card_number' => $ghana_card_number,
        'ghana_card_front_image' => $front_image,
        'ghana_card_back_image' => $back_image,
        'location' => $location,
        'occupation' => $occupation,
        'region' => $region,
        'date_of_birth' => $date_of_birth,
        'agent_id' => $agent_id,
        'admin_price' => $admin_price,
        'profit_amount' => $profit_amount,
        'return_to' => $return_to
    ]);
    $stmt->bind_param('idsss', $current_user['id'], $amount, $reference, $description, $meta);
    $stmt->execute();
    $stmt->close();
}

if ($agent_id > 0 && !$is_agent_self_order) {
    $stmt = $db->prepare("INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?) ON DUPLICATE KEY UPDATE description = VALUES(description)");
    if ($stmt) {
        $agent_desc = 'AFA registration wholesale cost';
        $stmt->bind_param('idss', $agent_id, $admin_price, $agent_reference, $agent_desc);
        $stmt->execute();
        $stmt->close();
    }
}

if ($is_agent_self_order && $profit_amount > 0 && function_exists('recordAgentCommission')) {
    recordAgentCommission([
        'agent_id' => (int) $current_user['id'],
        'source_type' => 'afa',
        'source_reference' => (string) $reference,
        'amount' => $profit_amount,
        'quantity' => 1,
        'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['afa_rate_per_order'] ?? 0) : null,
        'notes' => $beneficiary_name !== '' ? ('AFA registration for ' . $beneficiary_name) : 'AFA registration',
    ]);
}

if ($agent_id > 0 && !$is_agent_self_order && $profit_amount > 0 && function_exists('sendAgentProfitNotification')) {
    sendAgentProfitNotification([
        'agent_id' => $agent_id,
        'service' => 'AFA Registration',
        'reference' => $reference,
        'customer_name' => $current_user['full_name'] ?? '',
        'customer_email' => $current_user['email'] ?? '',
        'beneficiary_number' => $phone,
        'item' => $beneficiary_name !== '' ? $beneficiary_name : 'AFA registration',
        'amount' => $amount,
        'profit_amount' => $profit_amount,
        'payment_method' => 'wallet',
        'status' => 'processing',
    ]);
}

if (function_exists('notifyAfaRegistrationSubmitted')) {
    notifyAfaRegistrationSubmitted($reference);
}

afa_json_response([
    'status' => 'success',
    'message' => 'AFA registration submitted successfully.',
    'data' => [
        'reference' => $reference,
        'amount' => $amount,
        'beneficiary_name' => $beneficiary_name
    ]
]);

} catch (Throwable $e) {
    afa_json_response([
        'status' => 'error',
        'message' => 'System Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ' on line ' . $e->getLine()
    ], 200);
}
