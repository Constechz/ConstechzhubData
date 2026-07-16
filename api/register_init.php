<?php
require_once '../config/config.php';

header('Content-Type: application/json');

try {
    // Require registration session and agent type
    if (empty($_SESSION['registration_data']) || ($_SESSION['registration_data']['account_type'] ?? '') !== 'agent') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid registration session.']);
        exit();
    }

    $email = $_SESSION['registration_data']['email'] ?? '';
    if (!$email) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing email in registration data.']);
        exit();
    }

    // Get current agent registration fee
    $fee_amount = 0.00; // default fallback
    $stmt = $db->prepare("SELECT fee_amount FROM registration_fees WHERE user_type = 'agent' AND is_active = TRUE LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $fee_amount = (float)$row['fee_amount'];
        }
    }

    // If fee is 0, skip payment and complete registration directly
    if ($fee_amount <= 0) {
        // Complete registration without payment
        require_once '../register.php';
        $success = completeRegistration();
        if ($success) {
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'redirect_url' => SITE_URL . '/login.php?registered=1',
                    'message' => 'Registration completed successfully'
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to complete registration']);
        }
        exit();
    }

    // Generate reference and persist for fallback completion
    $reference = generateReference('REG');
    $_SESSION['registration_reference'] = $reference;

    $reg = $_SESSION['registration_data'];
    $metadata = [
        'purpose' => 'agent_registration',
        'account_type' => $reg['account_type'] ?? 'agent',
        'full_name' => $reg['full_name'] ?? '',
        'email' => $reg['email'] ?? $email,
        'phone' => $reg['phone'] ?? '',
        'password' => $reg['password'] ?? '',
        'store_name' => $reg['store_name'] ?? '',
        'store_slug' => $reg['store_slug'] ?? '',
        'store_description' => $reg['store_description'] ?? ''
    ];

    $gateway = getActivePaymentGateway();
    if ($gateway === 'moolre') {
        $config = getMoolreConfig();
        if (!isMoolreConfigured($config)) {
            throw new Exception('Moolre keys are not configured.');
        }

        $payload = [
            'type' => 1,
            'amount' => round($fee_amount, 2),
            'email' => $email,
            'externalref' => $reference,
            'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
            'redirect' => SITE_URL . '/register.php?step=complete&gateway=moolre&reference=' . urlencode($reference),
            'reusable' => '0',
            'currency' => CURRENCY_CODE,
            'accountnumber' => $config['account_number'],
            'metadata' => $metadata
        ];

        $error = null;
        $result = moolrePostJson('https://api.moolre.com/embed/link', $payload, $config, $error);
        if (!$result) {
            throw new Exception($error ?: 'Failed to initialize Moolre: Unknown error');
        }

        $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
        if (!$status_ok) {
            throw new Exception('Failed to initialize Moolre: ' . ($result['message'] ?? 'Unknown error'));
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'authorization_url' => $result['data']['authorization_url'] ?? '',
                'reference' => $reference,
            ],
        ]);
        exit();
    }

    $payload = json_encode([
        'email' => $email,
        'amount' => intval(round($fee_amount * 100)),
        'currency' => CURRENCY_CODE,
        'reference' => $reference,
        'callback_url' => SITE_URL . '/register.php?step=complete&gateway=paystack',
        'metadata' => $metadata,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.paystack.co/transaction/initialize',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception('cURL error: ' . $err);
    }

    $result = json_decode($response, true);
    if (!$result || !($result['status'] ?? false)) {
        throw new Exception('Failed to initialize Paystack: ' . ($result['message'] ?? 'Unknown error'));
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'authorization_url' => $result['data']['authorization_url'] ?? '',
            'reference' => $reference,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
