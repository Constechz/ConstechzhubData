<?php
require_once '../config/config.php';

header('Content-Type: application/json');

if (!function_exists('guest_afa_json')) {
    function guest_afa_json(array $payload, int $status = 200) {
        http_response_code($status);
        echo json_encode($payload);
        exit();
    }
}

if (!function_exists('guest_afa_error')) {
    function guest_afa_error(string $message, int $status = 400) {
        guest_afa_json(['status' => 'error', 'message' => $message], $status);
    }
}

if (!function_exists('guest_afa_store_card_image')) {
    function guest_afa_store_card_image(string $fileKey, string $prefix = 'card') {
        if (!isset($_FILES[$fileKey])) {
            guest_afa_error('Please upload both front and back Ghana card images.');
        }

        $file = $_FILES[$fileKey];
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            guest_afa_error('Failed to upload Ghana card image. Please try again.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > (5 * 1024 * 1024)) {
            guest_afa_error('Each Ghana card image must be between 1 byte and 5MB.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            guest_afa_error('Invalid uploaded file.');
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
            guest_afa_error('Only JPG, PNG, or WEBP Ghana card images are allowed.');
        }

        $uploadDir = __DIR__ . '/../uploads/afa_cards';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            guest_afa_error('Unable to create upload directory.', 500);
        }

        $filename = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $destPath = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            guest_afa_error('Could not save uploaded Ghana card image.', 500);
        }

        return 'uploads/afa_cards/' . $filename;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    guest_afa_error('Method not allowed', 405);
}

$input = [];
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
} else {
    $input = $_POST;
}

$store_slug = sanitize($input['store_slug'] ?? '');
$beneficiary_name = trim((string) ($input['beneficiary_name'] ?? ''));
$email = sanitize($input['email'] ?? '');
$phone_input = trim((string) ($input['phone'] ?? ''));
$ghana_card_number_raw = trim((string) ($input['ghana_card_number'] ?? ''));
$location = trim((string) ($input['location'] ?? ''));
$occupation = trim((string) ($input['occupation'] ?? ''));
$region = trim((string) ($input['region'] ?? ''));
$date_of_birth = trim((string) ($input['date_of_birth'] ?? ''));

if ($store_slug === '' || $beneficiary_name === '' || $email === '' || $phone_input === '' || $ghana_card_number_raw === '' || $location === '' || $occupation === '' || $region === '' || $date_of_birth === '') {
    guest_afa_error('All fields are compulsory.');
}
if (!validateEmail($email)) {
    guest_afa_error('Invalid email address');
}

$phone_digits = preg_replace('/\D+/', '', $phone_input);
if ($phone_digits !== $phone_input || strlen($phone_digits) < 10 || strlen($phone_digits) > 15) {
    guest_afa_error('Number for registration must contain digits only (10-15 digits).');
}
$formatted_phone = formatPhone($phone_digits);

$ghana_card_number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ghana_card_number_raw));
if (!preg_match('/^[A-Z0-9]{13}$/', $ghana_card_number)) {
    guest_afa_error('Ghana Card number must be exactly 13 alphanumeric characters.');
}

ensureAfaRegistrationTables();
ensurePaymentGatewaySchema();

$requested_gateway = normalizePaymentGateway($input['gateway'] ?? '');
if ($requested_gateway !== '' && $requested_gateway !== 'paystack') {
    guest_afa_error('Invalid gateway selection for this endpoint.');
}

if (!isPaymentGatewayEnabled('paystack')) {
    guest_afa_error('Paystack is currently disabled by admin settings.');
}

$settings = [
    'guest_price' => 0,
    'is_enabled' => 0,
    'allow_guest_paystack' => 1,
];
$settings_rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && ($settings_row = $settings_rs->fetch_assoc())) {
    $settings = array_merge($settings, $settings_row);
}

$service_enabled = ((int) ($settings['is_enabled'] ?? 0) === 1) || ((float) ($settings['guest_price'] ?? 0) > 0);
if (!$service_enabled) {
    guest_afa_error('AFA registration is currently unavailable.');
}
if ((int) ($settings['allow_guest_paystack'] ?? 1) !== 1) {
    guest_afa_error('Guest Paystack checkout is disabled.');
}

$guest_price = round((float) ($settings['guest_price'] ?? 0), 2);
if ($guest_price <= 0) {
    guest_afa_error('Guest AFA price is not configured.');
}

$stmt = $db->prepare("SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name FROM agent_stores ast JOIN users u ON ast.agent_id = u.id WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active' LIMIT 1");
$stmt->bind_param('s', $store_slug);
$stmt->execute();
$store = $stmt->get_result()->fetch_assoc();
if (!$store) {
    guest_afa_error('Store not found', 404);
}
$agent_id = (int) ($store['agent_id'] ?? 0);
$admin_price = round((float) ($settings['agent_price'] ?? 0), 2);
if ($admin_price <= 0) {
    $admin_price = $guest_price;
}
$profit_amount = $agent_id > 0 ? round(max(0, $guest_price - $admin_price), 2) : 0.0;

$front_image = null;
$back_image = null;

$stmt = $db->prepare('SELECT id, role, status, full_name FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$user_id = 0;
$buyer_name = '';
$buyer_role = 'guest';
if ($user) {
    if (($user['role'] ?? '') !== 'customer') {
        guest_afa_error('Email already belongs to a non-customer account');
    }
    if (($user['status'] ?? '') !== 'active') {
        guest_afa_error('This account is not active', 403);
    }
    $user_id = (int) $user['id'];
    $buyer_name = (string) ($user['full_name'] ?? '');
} else {
    $name_seed = explode('@', $email)[0];
    $name_seed = preg_replace('/[^a-zA-Z0-9]+/', ' ', $name_seed);
    $name_seed = trim($name_seed);
    if ($name_seed === '') {
        $name_seed = preg_replace('/[^0-9]/', '', $phone_digits);
    }

    $full_name = $name_seed !== '' ? 'Guest ' . ucwords(strtolower($name_seed)) : 'Guest Customer';
    $buyer_name = $full_name;

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

    $has_agent_id = function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'agent_id');
    $has_otp_required = function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'otp_required');

    if ($has_agent_id && $has_otp_required) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, agent_id, otp_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $otp_required = 0;
        $stmt->bind_param('ssssssssii', $username, $email, $password_hash, $full_name, $formatted_phone, $role, $status, $activation_status, $agent_id, $otp_required);
    } elseif ($has_agent_id) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssssi', $username, $email, $password_hash, $full_name, $formatted_phone, $role, $status, $activation_status, $agent_id);
    } elseif ($has_otp_required) {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, otp_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $otp_required = 0;
        $stmt->bind_param('ssssssssi', $username, $email, $password_hash, $full_name, $formatted_phone, $role, $status, $activation_status, $otp_required);
    } else {
        $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssss', $username, $email, $password_hash, $full_name, $formatted_phone, $role, $status, $activation_status);
    }
    $stmt->execute();
    $user_id = (int) $db->getConnection()->insert_id;

    if (function_exists('dbh_table_exists') && dbh_table_exists('wallets')) {
        $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }

    if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('user_referrals')) {
        try {
            $source = 'store_guest_afa_registration';
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
        'phone' => $formatted_phone,
        'username' => $username,
        'plain_password' => $plain_password,
        'brand' => $store['store_name'] ?? SITE_NAME
    ], $user_id);
}

$stmt = $db->prepare('SELECT id FROM wallets WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
if (!$wallet) {
    $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

$reference = generateReference('AFG');
$description = 'Guest AFA registration payment';
$metadata = [
    'type' => 'afa_registration_purchase',
    'beneficiary_name' => $beneficiary_name,
    'email' => $email,
    'phone' => $formatted_phone,
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
    'store_slug' => $store_slug,
    'buyer_name' => $buyer_name,
    'buyer_email' => $email,
    'buyer_role' => $buyer_role,
    'user_id' => $user_id,
    'return_to' => '/customer/afa-registration.php'
];
$metadata_json = json_encode($metadata);

$stmt = $db->prepare("INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata) VALUES (?, 'purchase', ?, 'pending', ?, 'paystack', ?, ?)");
$stmt->bind_param('idsss', $user_id, $guest_price, $reference, $description, $metadata_json);
$stmt->execute();

$agent_sql = $agent_id > 0 ? (string) $agent_id : 'NULL';
$stmt = $db->prepare("INSERT INTO afa_registrations (user_id, agent_id, beneficiary_name, email, phone, ghana_card_number, ghana_card_front_image, ghana_card_back_image, location, occupation, region, date_of_birth, amount, admin_price, profit_amount, payment_gateway, reference, status) VALUES (?, {$agent_sql}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paystack', ?, 'pending')");
$stmt->bind_param('issssssssssddds', $user_id, $beneficiary_name, $email, $formatted_phone, $ghana_card_number, $front_image, $back_image, $location, $occupation, $region, $date_of_birth, $guest_price, $admin_price, $profit_amount, $reference);
$stmt->execute();
$stmt->close();

if (function_exists('notifyAfaRegistrationSubmitted')) {
    notifyAfaRegistrationSubmitted($reference);
}

$paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
if (isInvalidPaystackKey($paystack_secret_key)) {
    guest_afa_error('Paystack keys are not configured.');
}

$checkout = initializePaystackCheckout($paystack_secret_key, [
    'email' => $email,
    'amount' => (int) round($guest_price * 100),
    'currency' => CURRENCY_CODE,
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL,
    'metadata' => $metadata
]);

if (empty($checkout['ok'])) {
    guest_afa_error($checkout['message'] ?? 'Failed to initialize payment', 500);
}

guest_afa_json([
    'status' => 'success',
    'data' => [
        'authorization_url' => $checkout['authorization_url'] ?? '',
        'access_code' => $checkout['access_code'] ?? '',
        'reference' => $reference
    ]
]);
