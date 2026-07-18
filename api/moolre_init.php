<?php
require_once '../config/config.php';

// Require login
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$amount = floatval($input['amount'] ?? 0);
$type = sanitize($input['type'] ?? '');
$store_slug = sanitize($input['store_slug'] ?? '');

$current_user = getCurrentUser();
if (!$current_user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Compute effective limits
$limits = getEffectiveTopupLimits($current_user['id'], $current_user['role'] ?? 'customer');
$min_allowed = (float) $limits['min'];
$max_allowed = (float) $limits['max'];

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

if (!in_array($type, ['wallet_topup', 'agent_wallet_topup', 'customer_wallet_topup'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction type']);
    exit();
}

$active_gateway = getActivePaymentGateway();
if ($active_gateway !== 'moolre') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Moolre is not the active gateway.']);
    exit();
}

$config = getMoolreConfig();
if (!isMoolreConfigured($config)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Moolre keys are not configured. Please contact support to enable Moolre payments.'
    ]);
    exit();
}

try {
    $reference = generateReference('PAY');

    $description_map = [
        'wallet_topup' => 'Wallet top-up via Moolre',
        'agent_wallet_topup' => 'Agent wallet top-up (payment to admin)',
        'customer_wallet_topup' => 'Customer wallet top-up via Moolre'
    ];
    $description = $description_map[$type] ?? 'Payment via Moolre';

    $metadata = [
        'type' => $type,
        'store_slug' => $store_slug,
        'user_id' => $current_user['id'],
        'payment_gateway' => 'moolre'
    ];

    $stmt = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
        VALUES (?, 'topup', ?, 'pending', ?, 'moolre', ?, ?)
    ");
    $metadata_json = json_encode($metadata);
    $stmt->bind_param("idsss", $current_user['id'], $amount, $reference, $description, $metadata_json);
    $stmt->execute();

    $payload = [
        'type' => 1,
        'amount' => round($amount, 2),
        'email' => $current_user['email'],
        'externalref' => $reference,
        'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
        'redirect' => SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference),
        'reusable' => '0',
        'currency' => CURRENCY_CODE,
        'accountnumber' => $config['account_number'],
        'metadata' => $metadata
    ];

    $error = null;
    $result = moolrePostJson('https://api.moolre.com/embed/link', $payload, $config, $error);
    if (!$result) {
        throw new Exception($error ?: 'Failed to initialize Moolre payment.');
    }

    $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
    if (!$status_ok) {
        $message = $result['message'] ?? 'Moolre initialization failed.';
        throw new Exception($message);
    }

    $auth_url = $result['data']['authorization_url'] ?? '';
    if ($auth_url === '') {
        throw new Exception('Missing authorization URL from Moolre.');
    }

    logActivity($current_user['id'], 'payment_init', "Moolre payment initialized: {$reference}");

    echo json_encode([
        'status' => 'success',
        'data' => [
            'authorization_url' => $auth_url,
            'reference' => $reference
        ]
    ]);
} catch (Exception $e) {
    error_log('Moolre initialization error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to initialize payment. ' . $e->getMessage()
    ]);
}
?>
