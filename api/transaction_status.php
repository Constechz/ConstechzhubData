<?php
require_once '../config/config.php';

requireLogin();

header('Content-Type: application/json');

$reference = sanitize($_GET['reference'] ?? '');
if ($reference === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing reference']);
    exit();
}

$current_user = getCurrentUser();
if (!$current_user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$stmt = $db->prepare("SELECT id, user_id, status, transaction_type, payment_method, metadata FROM transactions WHERE reference = ? LIMIT 1");
$stmt->bind_param("s", $reference);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();

if (!$txn) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
    exit();
}

if ((int) $txn['user_id'] !== (int) $current_user['id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit();
}

$metadata = [];
if (!empty($txn['metadata'])) {
    $decoded = json_decode($txn['metadata'], true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}

$role = $current_user['role'] ?? 'customer';
$role = strtolower(trim((string) $role));
$redirect = '';

$status = $txn['status'];

function moolre_status_is_success($status) {
    if (is_numeric($status)) {
        return (int) $status === 1;
    }
    $status = strtolower(trim((string) $status));
    if ($status === '') {
        return false;
    }
    $success_words = ['success', 'successful', 'completed', 'paid', 'approved'];
    foreach ($success_words as $word) {
        if (strpos($status, $word) !== false) {
            return true;
        }
    }
    return false;
}

// If still pending and Moolre, verify directly for faster redirect
if ($status !== 'success' && $txn['payment_method'] === 'moolre') {
    $config = getMoolreConfig();
    if (isMoolreConfigured($config)) {
        $payload = [
            'type' => 1,
            'idtype' => 1,
            'id' => $reference,
            'accountnumber' => $config['account_number']
        ];
        $error = null;
        $result = moolrePostJson('https://api.moolre.com/open/transact/status', $payload, $config, $error);
        if ($result && isset($result['data'])) {
            $gateway_data = is_array($result['data']) ? $result['data'] : [];
            $gateway_status = $gateway_data['status'] ?? $gateway_data['txstatus'] ?? ($result['txstatus'] ?? $result['status'] ?? null);
            if (moolre_status_is_success($gateway_status)) {
                $status = 'success';
                $redirect = '/api/moolre_callback.php?reference=' . urlencode($reference);
            }
        }
    }
}

if ($status === 'success') {
    $metadata_type = $metadata['type'] ?? '';
    $store_slug = $metadata['store_slug'] ?? '';
    if ($txn['transaction_type'] === 'topup' && $role === 'agent') {
        $redirect = '/agent/dashboard.php';
    } elseif ($txn['transaction_type'] === 'topup' && $metadata_type === 'customer_wallet_topup') {
        $redirect = '/customer/buy-data.php';
        if ($store_slug !== '') {
            $redirect .= '?store=' . urlencode($store_slug);
        }
    } elseif ($txn['transaction_type'] === 'purchase') {
        $redirect = $role === 'agent' ? '/agent/histories.php' : '/customer/order-history.php';
    } else {
        $redirect = $role === 'agent' ? '/agent/wallet.php' : '/customer/wallet.php';
    }
}

echo json_encode([
    'status' => 'ok',
    'transaction_status' => $status,
    'redirect_path' => $redirect
]);
