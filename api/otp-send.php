<?php
require_once '../config/config.php';
require_once '../includes/arkesel.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF protection
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$phone = sanitize($_POST['phone'] ?? '');
$purpose = sanitize($_POST['purpose'] ?? 'signup');
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required']);
    exit;
}

// Validate phone number format
if (!validatePhone($phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
    exit;
}

// Rate limiting - check if OTP was sent recently
$stmt = $db->prepare("
    SELECT created_at FROM otp_verifications 
    WHERE phone_number = ? AND purpose = ? 
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("ss", $phone, $purpose);
$stmt->execute();
$result = $stmt->get_result();

if ($recent = $result->fetch_assoc()) {
    $time_diff = time() - strtotime($recent['created_at']);
    if ($time_diff < 60) { // 1 minute cooldown
        echo json_encode([
            'success' => false, 
            'message' => 'Please wait ' . (60 - $time_diff) . ' seconds before requesting another OTP'
        ]);
        exit;
    }
}

try {
    $sms = new ArkeselSMS();
    $response = $sms->sendOTP($phone, $purpose, $user_id);
    
    if ($response['success']) {
        echo json_encode([
            'success' => true, 
            'message' => 'OTP sent successfully. Please check your phone.'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to send OTP: ' . $response['message']
        ]);
    }
} catch (Exception $e) {
    error_log("OTP sending error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'SMS service unavailable. Please try again later.'
    ]);
}
?>
