<?php
/**
 * mNotify BMS SMS Integration Service
 * https://bms.mnotify.com/
 *
 * Notes:
 * - Uses Bearer token authentication (API key).
 * - Sending endpoint: POST /api/sms/quick
 * - Balance endpoint: GET /api/account/balance (falls back to /api/sms/balance)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/arkesel.php';

class MnotifySmsService {
    private $apiKey;
    private $senderId;
    private $primaryBase = 'https://api.mnotify.com/api';
    private $secondaryBase = 'https://bms.mnotify.com/api'; // fallback host
    private $smsEndpoint = '/sms/quick'; // API v2.0 quick SMS
    private $balanceEndpoint = '/balance/sms'; // API v2.0 balance endpoint
    private $requestTimeout = 12;
    private $connectTimeout = 6;

    public function __construct($apiKey = null, $senderId = null) {
        // Prefer new mNotify keys; fall back to legacy Kivalo keys if present
        $this->apiKey = $apiKey
            ?: $this->getSmsSetting('mnotify_api_key', $this->getSmsSetting('kivalo_api_key'));
        $this->senderId = $senderId
            ?: $this->getSmsSetting('mnotify_sender_id', $this->getSmsSetting('kivalo_sender_id', 'DataBundle'));
    }

    /**
     * Send a single SMS message.
     */
    public function sendSMS($phone, $message, $purpose = 'general', $userId = null, $scheduledTime = null) {
        if (!$this->apiKey) {
            throw new Exception('mNotify API key not configured');
        }

        $recipient = $this->formatPhoneNumber($phone);
        $payload = [
            'recipient'    => [$recipient],
            'sender'       => $this->senderId ?: 'DataBundleHub',
            'message'      => $message,
            'is_schedule'  => false,
            'schedule_date'=> '',
        ];

        if (!empty($scheduledTime)) {
            $payload['is_schedule'] = true;
            $payload['schedule_date'] = date('Y-m-d H:i:s', strtotime($scheduledTime));
        }

        try {
            $response = $this->sendViaApi($this->smsEndpoint, $payload, 'POST');
            $this->logSmsNotification($userId, $recipient, $message, $purpose, $response);

            if ($response['success']) {
                return [
                    'success'           => true,
                    'message_id'        => $response['data']['sms_id'] ?? $response['data']['message_id'] ?? $response['data']['id'] ?? null,
                    'cost'              => $response['data']['cost'] ?? $response['data']['total_cost'] ?? null,
                    'status'            => $response['data']['status'] ?? 'queued',
                    'provider_response' => $response,
                    'message'           => 'SMS sent successfully',
                ];
            }

            return [
                'success'           => false,
                'error'             => $this->extractErrorMessage($response),
                'provider_response' => $response,
                'http_code'         => $response['http_code'] ?? null,
                'raw_response'      => $response['raw_response'] ?? null,
            ];
        } catch (Exception $e) {
            $this->logSmsNotification($userId, $recipient, $message, $purpose, [
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Send bulk SMS to multiple recipients.
     */
    public function sendBulkSMS($recipients, $message, $purpose = 'bulk', $userId = null) {
        if (!$this->apiKey) {
            throw new Exception('mNotify API key not configured');
        }

        if (!is_array($recipients)) {
            $recipients = array_map('trim', explode(',', $recipients));
        }

        $recipients = array_filter(array_map([$this, 'formatPhoneNumber'], $recipients));
        if (empty($recipients)) {
            throw new Exception('No valid recipients supplied');
        }

        $payload = [
            'recipient'    => $recipients,
            'sender'       => $this->senderId ?: 'DataBundleHub',
            'message'      => $message,
            'is_schedule'  => false,
            'schedule_date'=> '',
        ];

        try {
            $response = $this->sendViaApi($this->smsEndpoint, $payload, 'POST');

            foreach ($recipients as $phone) {
                $this->logSmsNotification($userId, $phone, $message, $purpose, $response);
            }

            if ($response['success']) {
                return [
                    'success'           => true,
                    'message_id'        => $response['data']['sms_id'] ?? $response['data']['message_id'] ?? null,
                    'details'           => $response['data'] ?? [],
                    'provider_response' => $response,
                ];
            }

            return [
                'success'           => false,
                'error'             => $this->extractErrorMessage($response),
                'provider_response' => $response,
                'http_code'         => $response['http_code'] ?? null,
                'raw_response'      => $response['raw_response'] ?? null,
            ];
        } catch (Exception $e) {
            foreach ($recipients as $phone) {
                $this->logSmsNotification($userId, $phone, $message, $purpose, [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ]);
            }
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Send OTP to a phone number.
     */
    public function sendOTP($phone, $purpose = 'verification', $userId = null, $expiryMinutes = 10) {
        $otp = random_int(100000, 999999);
        $message = "Your verification code is {$otp}. It expires in {$expiryMinutes} minutes.";

        $sendResult = $this->sendSMS($phone, $message, $purpose, $userId);
        if ($sendResult['success']) {
            $this->storeOtp($phone, $otp, $purpose, $userId, $expiryMinutes);
        }

        $sendResult['otp'] = $sendResult['success'] ? $otp : null;
        return $sendResult;
    }

    /**
     * Verify OTP code.
     */
    public function verifyOTP($phone, $otp, $purpose = 'verification') {
        global $db;

        $phone = $this->formatPhoneNumber($phone);
        $stmt = $db->prepare("SELECT id, expires_at, is_used FROM otp_verifications WHERE phone_number = ? AND otp_code = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            error_log('OTP verify lookup failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            return ['success' => false, 'message' => 'OTP verification unavailable'];
        }
        $stmt->bind_param('sss', $phone, $otp, $purpose);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        if (!$row) {
            return ['success' => false, 'message' => 'Invalid OTP code'];
        }

        if ($row['is_used']) {
            return ['success' => false, 'message' => 'OTP code has already been used'];
        }

        if (strtotime($row['expires_at']) < time()) {
            return ['success' => false, 'message' => 'OTP code has expired'];
        }

        $stmt = $db->prepare("UPDATE otp_verifications SET is_used = 1, verified_at = NOW() WHERE id = ?");
        if (!$stmt) {
            error_log('OTP verify update failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            return ['success' => false, 'message' => 'OTP verification unavailable'];
        }
        $stmt->bind_param('i', $row['id']);
        $stmt->execute();

        return ['success' => true, 'message' => 'OTP verified successfully'];
    }

    /**
     * Check SMS credit balance.
     */
    public function getBalance() {
        if (!$this->apiKey) {
            throw new Exception('mNotify API key not configured');
        }

        foreach ($this->getApiBaseUrls() as $base) {
            $endpoint = $this->appendApiKey(rtrim($base, '/') . $this->balanceEndpoint);
            $response = $this->makeRequest($endpoint, 'GET');
            if ($response['success']) {
                $balance = $response['data']['balance'] ?? $response['data']['sms_balance'] ?? $response['data']['wallet_balance'] ?? null;
                $units = $response['data']['units'] ?? $response['data']['credit_balance'] ?? null;

                return [
                    'success' => true,
                    'balance' => $balance,
                    'units'   => $units,
                    'data'    => $response['data'],
                ];
            }
        }

        return [
            'success' => false,
            'error'   => 'Unable to retrieve balance from mNotify API.',
        ];
    }

    /**
     * Persist OTP for later verification.
     */
    private function storeOtp($phone, $otp, $purpose, $userId, $expiryMinutes) {
        global $db;

        $phone = $this->formatPhoneNumber($phone);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

        try {
            $stmt = $db->prepare("INSERT INTO otp_verifications (phone_number, otp_code, purpose, user_id, expires_at) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                error_log('OTP Storage Error: ' . ($db->getConnection()->error ?? 'unknown database error'));
                return;
            }
            $stmt->bind_param('sssis', $phone, $otp, $purpose, $userId, $expiresAt);
            $stmt->execute();
        } catch (Exception $e) {
            error_log('OTP Storage Error: ' . $e->getMessage());
        }
    }

    /**
     * Log SMS notification in the database.
     */
    private function logSmsNotification($userId, $phone, $message, $purpose, $response) {
        global $db;

        try {
            $phone = $this->formatPhoneNumber($phone);
            $status = $response['success'] ? 'sent' : 'failed';
            $providerResponse = json_encode($response);
            $messageId = $response['data']['sms_id'] ?? $response['data']['message_id'] ?? null;
            $cost = $response['data']['cost'] ?? $response['data']['total_cost'] ?? 0;

            $stmt = $db->prepare("INSERT INTO sms_notifications (user_id, phone_number, message, purpose, status, provider_response, message_id, cost, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                error_log('SMS Log Error: ' . ($db->getConnection()->error ?? 'unknown database error'));
                return;
            }
            $stmt->bind_param('isssssds', $userId, $phone, $message, $purpose, $status, $providerResponse, $messageId, $cost);
            $stmt->execute();
        } catch (Exception $e) {
            error_log('SMS Log Error: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve SMS related setting with optional default.
     */
    private function getSmsSetting($key, $default = '') {
        global $db;

        try {
            if ($this->usesKeyValueSchema()) {
                $stmt = $db->prepare("SELECT setting_value FROM sms_settings WHERE setting_key = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $key);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        return $row['setting_value'];
                    }
                } else {
                    error_log('SMS settings lookup failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
                }
            } else {
                $row = $this->loadProviderRow();
                if ($row) {
                    $map = [
                        'mnotify_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                        'kivalo_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                        'mnotify_api_key' => $row['api_key'] ?? '',
                        'kivalo_api_key' => $row['api_key'] ?? '',
                        'mnotify_sender_id' => $row['sender_id'] ?? '',
                        'kivalo_sender_id' => $row['sender_id'] ?? '',
                        'sms_notifications_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                        'sms_otp_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                    ];
                    if (array_key_exists($key, $map)) {
                        return $map[$key];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('SMS Setting Error: ' . $e->getMessage());
        }

        return $default;
    }

    private function sendViaApi($path, array $payload = [], $method = 'POST') {
        $lastResponse = ['success' => false];

        foreach ($this->getApiBaseUrls() as $base) {
            $url = $this->appendApiKey(rtrim($base, '/') . $path);
            try {
                $response = $this->makeRequest($url, $method, $payload);
                $lastResponse = $response;
                if ($response['success']) {
                    return $response;
                }
            } catch (Exception $e) {
                $lastResponse = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $lastResponse;
    }

    private function getApiBaseUrls() {
        return [
            $this->primaryBase,
            $this->secondaryBase,
        ];
    }

    private function usesKeyValueSchema() {
        global $db;
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $res = $db->getConnection()->query("SHOW COLUMNS FROM sms_settings LIKE 'setting_key'");
            $cached = $res && $res->num_rows > 0;
        } catch (Exception $e) {
            $cached = false;
        }
        return $cached;
    }

    private function loadProviderRow() {
        global $db;
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $result = $db->query("SELECT provider, api_key, sender_id, is_active FROM sms_settings ORDER BY id DESC LIMIT 1");
            if ($result) {
                $cached = $result->fetch_assoc();
            }
        } catch (Exception $e) {
            $cached = null;
        }
        return $cached;
    }

    private function appendApiKey($url) {
        $token = preg_replace('/^Bearer\s+/i', '', trim($this->apiKey));
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . 'key=' . urlencode($token);
    }

    /**
     * Execute HTTP request against mNotify API.
     */
    private function makeRequest($url, $method = 'POST', array $payload = [], array $extraHeaders = []) {
        $token = preg_replace('/^Bearer\s+/i', '', trim($this->apiKey));
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'Expect:', // avoid 100-continue issues
            'Connection: close',
        ], $extraHeaders);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DataBundleHub/1.0');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $method = strtoupper($method);
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } elseif ($method === 'GET' && !empty($payload)) {
            $url = $url . '?' . http_build_query($payload);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            // Surface friendlier message for HTTP/2 stream closure issues
            if (stripos($error, 'HTTP/2') !== false) {
                throw new Exception('CURL Error: ' . $error . ' (try using HTTP/1.1; this client already forces HTTP/1.1 but the edge may still drop HTTP/2 connections)');
            }
            if (stripos($error, 'SSL') !== false) {
                throw new Exception('CURL Error: ' . $error . ' (SSL handshake). Tried URL: ' . $url);
            }
            throw new Exception('CURL Error: ' . $error);
        }

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
        }

        $success = $this->isSuccessfulResponse($decoded, $response, $httpCode);

        return [
            'success'      => $success,
            'http_code'    => $httpCode,
            'curl_errno'   => $curlErrNo,
            'data'         => $decoded,
            'raw_response' => $response,
        ];
    }

    /**
     * Determine if API response indicates success.
     */
    private function isSuccessfulResponse($decoded, $rawResponse, $httpCode) {
        if ($httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        if (is_array($decoded)) {
            $status = strtolower((string)($decoded['status'] ?? $decoded['Status'] ?? ''));
            $code = (string)($decoded['code'] ?? $decoded['Code'] ?? $decoded['status_code'] ?? '');

            if (in_array($status, ['success', 'queued', 'submitted', 'processing', 'ok'], true)) {
                return true;
            }

            if (in_array($code, ['200', '201', '202'], true)) {
                return true;
            }
        }

        return !empty($rawResponse);
    }

    /**
     * Extract error message from response.
     */
    private function extractErrorMessage(array $response) {
        $httpCode = $response['http_code'] ?? null;

        if (!empty($response['data']) && is_array($response['data'])) {
            foreach (['message', 'error', 'detail', 'status'] as $key) {
                if (!empty($response['data'][$key])) {
                    return is_array($response['data'][$key]) ? json_encode($response['data'][$key]) : $response['data'][$key];
                }
            }
        }

        if (!empty($response['raw_response'])) {
            $raw = is_string($response['raw_response']) ? trim($response['raw_response']) : json_encode($response['raw_response']);
            // If HTML error page, surface a concise message
            if (stripos($raw, '<html') !== false) {
                return 'HTTP ' . ($httpCode ?? '400') . ' from provider (HTML error page).';
            }
            return $raw;
        }

        if ($httpCode === 404) {
            return 'Endpoint not found (HTTP 404). Ensure base URL is reachable (api.mnotify.com) and path is /api/sms/quick.';
        }

        if ($httpCode === 0) {
            return 'No response from mNotify API (possible network/firewall issue).';
        }

        if ($httpCode) {
            return 'Empty response from mNotify API (HTTP ' . $httpCode . ').';
        }

        return 'Unknown error from mNotify API';
    }

    /**
     * Normalise phone number to international format (Ghana default).
     */
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/\D/', '', (string)$phone);

        if (strlen($phone) === 10 && $phone[0] === '0') {
            return '233' . substr($phone, 1);
        }

        if (strlen($phone) === 9) {
            return '233' . $phone;
        }

        return $phone;
    }
}

// Global helper functions
function sendSMS($phone, $message, $purpose = 'general', $userId = null) {
    static $service;
    if (!$service) {
        $service = new MnotifySmsService();
    }

    $primaryResult = $service->sendSMS($phone, $message, $purpose, $userId);
    if (!empty($primaryResult['success'])) {
        return $primaryResult;
    }

    // Fallback: try Arkesel if configured
    try {
        $arkesel = getArkeselSMS();
        if ($arkesel) {
            $fallback = $arkesel->sendSMS($phone, $message, $purpose);
            $fallbackSuccess = is_array($fallback) ? ($fallback['success'] ?? false) : (bool) $fallback;
            if ($fallbackSuccess) {
                $normalized = is_array($fallback) ? $fallback : ['success' => true, 'message' => 'Sent via Arkesel fallback'];
                $normalized['provider'] = 'arkesel';
                if (!isset($normalized['provider_response'])) {
                    $normalized['provider_response'] = $fallback;
                }
                return $normalized;
            }
        }
    } catch (Exception $fallbackError) {
        $primaryResult['fallback_error'] = $fallbackError->getMessage();
        error_log('SMS fallback (Arkesel) failed: ' . $fallbackError->getMessage());
    }

    return $primaryResult;
}

function sendOTP($phone, $purpose = 'verification', $userId = null, $expiryMinutes = 10) {
    $sms = new MnotifySmsService();
    return $sms->sendOTP($phone, $purpose, $userId, $expiryMinutes);
}

function verifyOTP($phone, $otp, $purpose = 'verification') {
    $sms = new MnotifySmsService();
    return $sms->verifyOTP($phone, $otp, $purpose);
}

function getSMSBalance() {
    $sms = new MnotifySmsService();
    return $sms->getBalance();
}

function sendBulkSMS($recipients, $message, $purpose = 'bulk') {
    $sms = new MnotifySmsService();
    return $sms->sendBulkSMS($recipients, $message, $purpose);
}
