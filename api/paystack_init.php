<?php
require_once '../config/config.php';

// Require login
requireLogin();

$current_user = getCurrentUser();

// Set content type to JSON
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$amount = floatval($input['amount'] ?? 0);
$type = sanitize($input['type'] ?? '');
$store_slug = sanitize($input['store_slug'] ?? '');

// Compute effective limits
$limits = getEffectiveTopupLimits($current_user['id'], $current_user['role'] ?? 'customer');
$min_allowed = (float)$limits['min'];
$max_allowed = (float)$limits['max'];

// Validate input
if ($amount < $min_allowed) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Minimum amount is ' . CURRENCY . ' ' . number_format($min_allowed, 2)]);
    exit();
}

if ($amount > $max_allowed) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Maximum amount is ' . CURRENCY . ' ' . number_format($max_allowed, 2)]);
    exit();
}

if (!in_array($type, ['wallet_topup', 'agent_wallet_topup', 'customer_wallet_topup'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction type']);
    exit();
}

$active_gateway = getActivePaymentGateway();
if ($active_gateway !== 'paystack') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack is not the active gateway.']);
    exit();
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
$admin_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
if ($isInvalidPaystackKey($admin_secret_key)) {
    $admin_secret_key = PAYSTACK_SECRET_KEY;
}
$admin_public_key = dbh_env('PAYSTACK_PUBLIC_KEY');
if ($isInvalidPublicKey($admin_public_key)) {
    $admin_public_key = PAYSTACK_PUBLIC_KEY;
}

$paystack_secret_key = $admin_secret_key;
$paystack_public_key = $admin_public_key;
$payment_recipient = 'admin';
// By default, prefer admin Paystack keys from .env; only use agent keys if explicitly enabled.
$allow_agent_paystack = strtolower(trim((string) dbh_env('ALLOW_AGENT_PAYSTACK_KEYS', '0')));
$allow_agent_paystack = in_array($allow_agent_paystack, ['1', 'true', 'yes', 'on'], true);

if ($type === 'customer_wallet_topup' && !empty($store_slug) && $allow_agent_paystack) {
    // Customer topping up via agent store - use agent's Paystack
    $stmt = $db->prepare("
        SELECT aps.secret_key, aps.public_key, u.full_name as agent_name
        FROM agent_stores ast
        JOIN agent_paystack_settings aps ON ast.agent_id = aps.agent_id
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND aps.is_active = 1
    ");
    $stmt->bind_param('s', $store_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $candidate_secret = $row['secret_key'];
        if (!$isInvalidPaystackKey($candidate_secret)) {
            $paystack_secret_key = $candidate_secret;
            $payment_recipient = $row['agent_name'];
        }
    }
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
        'message' => 'Paystack keys are not configured. Resolved secret key: ' . $maskKey($paystack_secret_key) . ' from ' . $resolved_source . '. Please contact support to enable Paystack top-ups.'
    ]);
    exit();
}

try {
    // Generate reference
    $reference = generateReference('PAY');
    
    // Create pending transaction with appropriate description
    $description_map = [
        'wallet_topup' => 'Wallet top-up via Paystack',
        'agent_wallet_topup' => 'Agent wallet top-up (payment to admin)',
        'customer_wallet_topup' => $payment_recipient === 'admin' ? 'Customer wallet top-up via admin Paystack' : "Customer wallet top-up via {$payment_recipient}'s Paystack"
    ];
    $description = $description_map[$type] ?? 'Payment via Paystack';
    $transaction_type = 'topup'; // Only wallet top-ups allowed via Paystack
    
    $stmt = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) 
        VALUES (?, ?, ?, 'pending', ?, 'paystack', ?)
    ");
    $stmt->bind_param("isdss", $current_user['id'], $transaction_type, $amount, $reference, $description);
    $stmt->execute();
    
    // Initialize Paystack transaction
    $curl = curl_init();
    
    $postfields = json_encode([
        'email' => $current_user['email'],
        'amount' => $amount * 100, // Convert to kobo
        'currency' => CURRENCY_CODE,
        'reference' => $reference,
        'callback_url' => PAYSTACK_CALLBACK_URL,
        'metadata' => [
            'user_id' => $current_user['id'],
            'type' => $type,
            'store_slug' => $store_slug,
            'payment_recipient' => $payment_recipient,
            'custom_fields' => [
                [
                    'display_name' => 'User',
                    'variable_name' => 'user',
                    'value' => $current_user['full_name']
                ],
                [
                    'display_name' => 'Payment To',
                    'variable_name' => 'payment_recipient',
                    'value' => $payment_recipient
                ]
            ]
        ]
    ]);
    
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
        throw new Exception("cURL Error: " . $err);
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !$result['status']) {
        $message = $result['message'] ?? 'Unknown error';
        throw new Exception("Failed to initialize Paystack transaction: " . $message);
    }
    
    // Log activity
    logActivity($current_user['id'], 'payment_init', "Paystack payment initialized: {$reference}");
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'authorization_url' => $result['data']['authorization_url'],
            'access_code' => $result['data']['access_code'],
            'reference' => $reference
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Paystack initialization error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to initialize payment. ' . $e->getMessage()
    ]);
}
?>
