<?php
require_once '../config/config.php';
require_once '../includes/paystack_fees.php';

ensurePaymentGatewaySchema();
ensureProductOrderTables();

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
$product_id = (int) ($input['product_id'] ?? 0);
$full_name = trim((string) ($input['full_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$delivery_address = trim((string) ($input['delivery_address'] ?? ''));
$city = trim((string) ($input['city'] ?? ''));
$region_name = trim((string) ($input['region_name'] ?? ''));
$landmark = trim((string) ($input['landmark'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($store_slug === '' || $product_id <= 0 || $full_name === '' || $email === '' || $phone === '' || $delivery_address === '' || $city === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please complete the required checkout fields.']);
    exit();
}

if (!validateEmail($email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'A valid email address is required for payment.']);
    exit();
}

if (!validatePhone($phone)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Enter a valid phone number.']);
    exit();
}

if (!isPaymentGatewayEnabled('paystack')) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paystack is currently disabled by admin settings.']);
    exit();
}

$store = getStoreBySlug($store_slug);
if (!$store) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Store not found.']);
    exit();
}

$product = getDashboardProductById($product_id, true);
if (!$product) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Product not found or unavailable.']);
    exit();
}

$amount = round((float) ($product['current_price'] ?? 0), 2);
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'This product does not have a valid selling price.']);
    exit();
}

$formatted_phone = formatPhone($phone);
$reference = generateReference('PROD');
$return_to = '/store/product-reference.php?store=' . urlencode($store_slug) . '&lookup=' . urlencode($reference);

$current_user = function_exists('getCurrentUser') ? getCurrentUser() : null;
$user_id = (int) ($current_user['id'] ?? 0);
$buyer_role = strtolower(trim((string) ($current_user['role'] ?? 'guest')));
if ($buyer_role === '') {
    $buyer_role = 'guest';
}

$description = 'Product purchase: ' . trim((string) ($product['name'] ?? 'Product')) . ' for ' . $full_name;
$metadata = [
    'type' => 'product_purchase',
    'store_slug' => $store_slug,
    'agent_id' => (int) ($store['agent_id'] ?? 0),
    'product_id' => $product_id,
    'buyer_name' => $full_name,
    'buyer_email' => $email,
    'buyer_role' => $buyer_role,
    'customer_phone' => $formatted_phone,
    'return_to' => $return_to,
];

$paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY', PAYSTACK_SECRET_KEY);
$checkout = initializePaystackCheckout($paystack_secret_key, [
    'email' => $email,
    'amount' => (int) round($amount * 100),
    'currency' => CURRENCY_CODE,
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL,
    'metadata' => [
        'type' => 'product_purchase',
        'store_slug' => $store_slug,
        'product_id' => $product_id,
        'user_id' => $user_id,
    ],
]);

if (empty($checkout['ok'])) {
    http_response_code((int) ($checkout['status_code'] ?? 500));
    echo json_encode([
        'status' => 'error',
        'message' => $checkout['message'] ?? 'Paystack could not start the payment right now.',
    ]);
    exit();
}

$conn = $db->getConnection();

try {
    if ($conn instanceof mysqli) {
        $conn->begin_transaction();
    }

    $orderStmt = $db->prepare("
        INSERT INTO product_orders
        (order_reference, product_id, agent_id, user_id, store_slug, quantity, unit_price, total_amount, customer_name, customer_email, customer_phone, delivery_address, city, region_name, landmark, notes, payment_status, order_status, payment_gateway)
        VALUES (?, ?, ?, NULLIF(?, 0), ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending_payment', 'paystack')
    ");
    if (!$orderStmt) {
        throw new RuntimeException('Unable to create the product order.');
    }

    $agent_id = (int) ($store['agent_id'] ?? 0);
    $orderStmt->bind_param(
        'siiisddssssssss',
        $reference,
        $product_id,
        $agent_id,
        $user_id,
        $store_slug,
        $amount,
        $amount,
        $full_name,
        $email,
        $formatted_phone,
        $delivery_address,
        $city,
        $region_name,
        $landmark,
        $notes
    );
    if (!$orderStmt->execute()) {
        throw new RuntimeException('Unable to save the product order.');
    }
    $order_id = method_exists($db, 'lastInsertId') ? (int) $db->lastInsertId() : (int) $conn->insert_id;
    $orderStmt->close();

    $metadata['order_id'] = $order_id;
    $metadata['delivery_address'] = $delivery_address;
    $metadata['city'] = $city;
    $metadata['region_name'] = $region_name;
    $metadata['landmark'] = $landmark;
    $metadata['notes'] = $notes;
    $metadata_json = json_encode($metadata);
    if ($metadata_json === false) {
        throw new RuntimeException('Unable to encode checkout metadata.');
    }

    $transactionStmt = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata, order_id)
        VALUES (NULLIF(?, 0), 'purchase', ?, 'pending', ?, 'paystack', ?, ?, ?)
    ");
    if (!$transactionStmt) {
        throw new RuntimeException('Unable to create the payment transaction.');
    }
    $transactionStmt->bind_param('idsssi', $user_id, $amount, $reference, $description, $metadata_json, $order_id);
    if (!$transactionStmt->execute()) {
        throw new RuntimeException('Unable to store the payment transaction.');
    }
    $transaction_id = method_exists($db, 'lastInsertId') ? (int) $db->lastInsertId() : (int) $conn->insert_id;
    $transactionStmt->close();

    $linkStmt = $db->prepare("UPDATE product_orders SET transaction_id = ? WHERE id = ?");
    if ($linkStmt) {
        $linkStmt->bind_param('ii', $transaction_id, $order_id);
        $linkStmt->execute();
        $linkStmt->close();
    }

    if ($conn instanceof mysqli) {
        $conn->commit();
    }
} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
    exit();
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'authorization_url' => $checkout['authorization_url'] ?? '',
        'access_code' => $checkout['access_code'] ?? '',
        'reference' => $reference,
        'order_id' => $order_id,
    ],
]);
?>
