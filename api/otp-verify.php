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
$otp = sanitize($_POST['otp'] ?? '');
$purpose = sanitize($_POST['purpose'] ?? 'signup');

if (empty($phone) || empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'Phone number and OTP are required']);
    exit;
}

try {
    $sms = new ArkeselSMS();
    $verified = $sms->verifyOTP($phone, $otp, $purpose);
    
    if ($verified) {
        echo json_encode([
            'success' => true, 
            'message' => 'OTP verified successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Invalid or expired OTP'
        ]);
    }
} catch (Exception $e) {
    error_log("OTP verification error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Verification failed. Please try again.'
    ]);
}
?>
