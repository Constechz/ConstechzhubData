<?php
// DEPRECATED: Direct Paystack payments for data bundles are no longer supported
// All data bundle purchases must use wallet payments only
header('Content-Type: application/json');
http_response_code(403);
echo json_encode([
    'success' => false, 
    'message' => 'Direct Paystack payments for data bundles are no longer supported. Please top up your wallet first, then purchase data bundles using your wallet balance.',
    'error_code' => 'PAYSTACK_BUNDLES_DISABLED'
]);
exit();

/*
* Original file preserved below for reference but disabled
* Removed to enforce wallet-only payments for data bundles
*/

/*
require_once '../config/config.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Validate required fields
$required_fields = ['store_slug', 'package_id', 'beneficiary_number', 'customer_email', 'payment_reference', 'amount'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$store_slug = sanitize($input['store_slug']);
$package_id = (int)$input['package_id'];
$beneficiary_number = sanitize($input['beneficiary_number']);
$customer_email = sanitize($input['customer_email']);
$payment_reference = sanitize($input['payment_reference']);
$amount = (float)$input['amount'];

try {
    $db->begin_transaction();
    
    // Get store and agent information (and agent Paystack secret if active)
    $stmt = $db->prepare("
        SELECT as.*,
               u.id AS agent_id,
               aps.secret_key AS agent_secret_key
        FROM agent_stores as
        JOIN users u ON as.agent_id = u.id
        LEFT JOIN agent_paystack_settings aps ON aps.agent_id = u.id AND aps.is_active = 1
        WHERE as.store_slug = ? AND as.is_active = TRUE
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $store_result = $stmt->get_result();
    
    if ($store_result->num_rows === 0) {
        throw new Exception('Store not found');
    }
    
    $store = $store_result->fetch_assoc();
    $agent_id = $store['agent_id'];
    
    // Get package information
    $stmt = $db->prepare("SELECT * FROM data_packages WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $package_id);
    $stmt->execute();
    $package_result = $stmt->get_result();
    
    if ($package_result->num_rows === 0) {
        throw new Exception('Package not found');
    }
    
    $package = $package_result->fetch_assoc();
    
    // Verify payment with Paystack using agent's secret if active, else global
    $paystack_secret = !empty($store['agent_secret_key']) ? $store['agent_secret_key'] : PAYSTACK_SECRET_KEY;
    $payment_verified = verifyPaystackPayment($payment_reference, $paystack_secret);
    
    if (!$payment_verified) {
        throw new Exception('Payment verification failed');
    }
    
    // Create bundle order
    $order_id = generateOrderId();
    $stmt = $db->prepare("
        INSERT INTO bundle_orders 
        (order_id, user_id, package_id, beneficiary_number, amount, payment_reference, status, agent_id, customer_email) 
        VALUES (?, NULL, ?, ?, ?, ?, 'pending', ?, ?)
    ");
    $stmt->bind_param("sisdsiss", $order_id, $package_id, $beneficiary_number, $amount, $payment_reference, $agent_id, $customer_email);
    $stmt->execute();
    
    $bundle_order_id = $db->insert_id;
    
    // Create transaction record
    $stmt = $db->prepare("
        INSERT INTO transactions 
        (user_id, type, amount, description, reference, status, agent_id) 
        VALUES (NULL, 'purchase', ?, ?, ?, 'completed', ?)
    ");
    $description = "Data bundle purchase: " . $package['name'];
    $stmt->bind_param("dssi", $amount, $description, $payment_reference, $agent_id);
    $stmt->execute();
    
    // Calculate agent commission (e.g., 10% of sale)
    $commission_rate = 0.10;
    $commission_amount = $amount * $commission_rate;
    
    // Add commission to agent's wallet
    $stmt = $db->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?");
    $stmt->bind_param("di", $commission_amount, $agent_id);
    $stmt->execute();
    
    // Record commission transaction
    $stmt = $db->prepare("
        INSERT INTO commissions 
        (agent_id, order_id, commission_amount, commission_rate, status) 
        VALUES (?, ?, ?, ?, 'paid')
    ");
    $stmt->bind_param("iids", $agent_id, $bundle_order_id, $commission_amount, $commission_rate);
    $stmt->execute();
    
    // Process the data bundle (integrate with your data provider API here)
    $bundle_result = processDataBundle($package, $beneficiary_number);
    
    if ($bundle_result['success']) {
        // Update order status to success
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'success', processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $bundle_order_id);
        $stmt->execute();
        
        $db->commit();
        
        // Send confirmation email (optional)
        sendOrderConfirmationEmail($customer_email, $order_id, $package, $beneficiary_number);
        
        echo json_encode([
            'success' => true,
            'message' => 'Order processed successfully',
            'order_id' => $order_id,
            'status' => 'success'
        ]);
        
    } else {
        // Update order status to failed
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $bundle_order_id);
        $stmt->execute();
        
        $db->commit();
        
        echo json_encode([
            'success' => false,
            'message' => 'Data bundle processing failed: ' . $bundle_result['message'],
            'order_id' => $order_id,
            'status' => 'failed'
        ]);
    }
    
} catch (Exception $e) {
    $db->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function verifyPaystackPayment($reference, $secret_key) {
    $url = "https://api.paystack.co/transaction/verify/" . $reference;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $secret_key,
        "Content-Type: application/json"
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        return $data['status'] === true && $data['data']['status'] === 'success';
    }
    
    return false;
}

function processDataBundle($package, $beneficiary_number) {
    // This is where you'd integrate with your data provider's API
    // For now, we'll simulate a successful response
    
    // Example integration with a data provider:
    // /*
    $api_url = "https://dataprovider.com/api/send";
    $api_data = [
        'network' => $package['network'],
        'phone' => $beneficiary_number,
        'data_size' => $package['data_size'],
        'validity' => $package['validity_days']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer YOUR_API_KEY'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $result = json_decode($response, true);
        return [
            'success' => $result['status'] === 'success',
            'message' => $result['message'] ?? 'Data bundle sent successfully'
        ];
    }
    //
    
    // Simulate successful processing for demo
    return [
        'success' => true,
        'message' => 'Data bundle sent successfully'
    ];
}

function generateOrderId() {
    return 'ORD' . date('Ymd') . rand(1000, 9999);
}

function sendOrderConfirmationEmail($email, $order_id, $package, $beneficiary_number) {
    // Implement email sending logic here
    // You can use PHPMailer or similar library
    
    $subject = "Order Confirmation - " . $order_id;
    $message = "Your data bundle order has been processed successfully.\n\n";
    $message .= "Order ID: " . $order_id . "\n";
    $message .= "Package: " . $package['name'] . "\n";
    $message .= "Phone Number: " . $beneficiary_number . "\n";
    $message .= "Data Size: " . $package['data_size'] . "\n";
    $message .= "Validity: " . $package['validity_days'] . " days\n\n";
    $message .= "Thank you for your purchase!";
    
    // Use PHP's mail() function or a proper email library
    // mail($email, $subject, $message);
}
*/
?>
