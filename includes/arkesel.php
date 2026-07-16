<?php
/**
 * Arkesel SMS API Integration
 * Documentation: https://developers.arkesel.com/
 */

class ArkeselSMS {
    private $api_key;
    private $sender_id;
    private $base_url = 'https://sms.arkesel.com/api/v2/sms';
    
    public function __construct($api_key = null, $sender_id = null) {
        global $db;
        
        if ($api_key && $sender_id) {
            $this->api_key = $api_key;
            $this->sender_id = $sender_id;
        } else {
            // Get settings from database
            $stmt = $db->prepare("SELECT api_key, sender_id FROM sms_settings WHERE provider = 'arkesel' AND is_active = TRUE LIMIT 1");
            if (!$stmt) {
                throw new Exception('Arkesel SMS settings lookup failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($settings = $result->fetch_assoc()) {
                $this->api_key = $settings['api_key'];
                $this->sender_id = $settings['sender_id'];
            } else {
                throw new Exception('Arkesel SMS settings not configured');
            }
        }
    }
    
    /**
     * Send SMS message
     */
    public function sendSMS($phone, $message, $purpose = 'general') {
        global $db;
        
        // Format phone number (ensure it starts with country code)
        $phone = $this->formatPhoneNumber($phone);
        
        $data = [
            'sender' => $this->sender_id,
            'message' => $message,
            'recipients' => [$phone]
        ];
        
        $response = $this->makeRequest('send', $data);
        
        // Log SMS notification
        $user_id = $this->getUserIdByPhone($phone);
        $status = $response['success'] ? 'sent' : 'failed';
        $provider_response = json_encode($response);
        
        $stmt = $db->prepare("
            INSERT INTO sms_notifications (user_id, phone_number, message, purpose, status, provider_response, sent_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt) {
            $stmt->bind_param("isssss", $user_id, $phone, $message, $purpose, $status, $provider_response);
            $stmt->execute();
        } else {
            error_log('Arkesel SMS log failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        }
        
        return $response;
    }
    
    /**
     * Generate and send OTP
     */
    public function sendOTP($phone, $purpose = 'signup', $user_id = null) {
        global $db;
        
        // Generate 6-digit OTP
        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store OTP in database (expires in 10 minutes)
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        $stmt = $db->prepare("
            INSERT INTO otp_verifications (phone_number, otp_code, purpose, user_id, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("sssis", $phone, $otp, $purpose, $user_id, $expires_at);
            $stmt->execute();
        } else {
            error_log('Arkesel OTP storage failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        }
        
        // Send OTP via SMS
        $message = "Your " . getSiteName() . " verification code is: {$otp}. Valid for 10 minutes. Do not share this code.";
        
        return $this->sendSMS($phone, $message, 'otp');
    }
    
    /**
     * Verify OTP
     */
    public function verifyOTP($phone, $otp, $purpose = 'signup') {
        global $db;
        
        $stmt = $db->prepare("
            SELECT id, user_id FROM otp_verifications 
            WHERE phone_number = ? AND otp_code = ? AND purpose = ? 
            AND is_verified = FALSE AND expires_at > NOW() 
            ORDER BY created_at DESC LIMIT 1
        ");
        if (!$stmt) {
            error_log('Arkesel OTP verify lookup failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            return false;
        }
        $stmt->bind_param("sss", $phone, $otp, $purpose);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($verification = $result->fetch_assoc()) {
            // Mark as verified
            $stmt = $db->prepare("UPDATE otp_verifications SET is_verified = TRUE WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $verification['id']);
                $stmt->execute();
            } else {
                error_log('Arkesel OTP verify update failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
                return false;
            }
            
            // Update user phone verification status if applicable
            if ($verification['user_id'] && $purpose === 'signup') {
                $stmt = $db->prepare("UPDATE users SET phone_verified = TRUE WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $verification['user_id']);
                    $stmt->execute();
                } else {
                    error_log('Arkesel OTP user update failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
                    return false;
                }
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Send wallet top-up notification
     */
    public function sendTopupNotification($user_id, $amount, $new_balance) {
        global $db;
        
        // Get user phone
        $stmt = $db->prepare("SELECT phone, full_name FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $message = "Hi {$user['full_name']}, your wallet has been credited with GHS " . number_format($amount, 2) . 
                      ". New balance: GHS " . number_format($new_balance, 2) . ". Thank you!";
            
            return $this->sendSMS($user['phone'], $message, 'topup');
        }
        
        return false;
    }
    
    /**
     * Format phone number for Ghana (+233)
     */
    private function formatPhoneNumber($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Handle different formats
        if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            // 0XXXXXXXXX -> 233XXXXXXXXX
            return '233' . substr($phone, 1);
        } elseif (strlen($phone) == 9) {
            // XXXXXXXXX -> 233XXXXXXXXX
            return '233' . $phone;
        } elseif (strlen($phone) == 12 && substr($phone, 0, 3) == '233') {
            // Already in correct format
            return $phone;
        }
        
        return $phone; // Return as-is if format is unclear
    }
    
    /**
     * Get user ID by phone number
     */
    private function getUserIdByPhone($phone) {
        global $db;
        
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? OR phone = ? LIMIT 1");
        if (!$stmt) {
            error_log('Arkesel user lookup failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            return null;
        }
        $formatted_phone = $this->formatPhoneNumber($phone);
        $stmt->bind_param("ss", $phone, $formatted_phone);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            return $user['id'];
        }
        
        return null;
    }
    
    /**
     * Make HTTP request to Arkesel API
     */
    private function makeRequest($endpoint, $data) {
        $url = $this->base_url . '/' . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'api-key: ' . $this->api_key
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error,
                'data' => null
            ];
        }
        
        $decoded = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300) {
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'data' => $decoded
            ];
        } else {
            return [
                'success' => false,
                'message' => $decoded['message'] ?? 'SMS sending failed',
                'data' => $decoded
            ];
        }
    }
    
    /**
     * Check SMS balance
     */
    public function getBalance() {
        return $this->makeRequest('balance', []);
    }
}

/**
 * Helper function to get Arkesel instance
 */
function getArkeselSMS() {
    static $instance = null;
    
    if ($instance === null) {
        try {
            $instance = new ArkeselSMS();
        } catch (Exception $e) {
            error_log("Arkesel SMS initialization failed: " . $e->getMessage());
            return null;
        }
    }
    
    return $instance;
}
