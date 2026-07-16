<?php
/**
 * API Providers Management System
 * Handles multiple data bundle providers with failover support
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Get active provider for a specific network
 */
function getNetworkProvider($network_code, $endpoint_type = 'regular') {
    global $db;

    if (function_exists('ensureHubnetProviderBootstrap')) {
        ensureHubnetProviderBootstrap();
    }
    if (function_exists('ensureDatawaxProviderBootstrap')) {
        ensureDatawaxProviderBootstrap();
    }
    if (function_exists('ensureCodeCraftProviderBootstrap')) {
        ensureCodeCraftProviderBootstrap();
    }
    if (function_exists('ensureTelecelProviderMapping')) {
        ensureTelecelProviderMapping();
    }

    $network_id = resolveProviderNetworkId($network_code);
    if ($network_id <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT primary_provider_id, backup_provider_id
        FROM network_providers
        WHERE network_id = ? AND is_active = 1
        ORDER BY priority_order ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $network_id);
    $stmt->execute();
    $mapping = $stmt->get_result()->fetch_assoc();
    if (!$mapping) {
        // Fallback: allow direct endpoint usage even when explicit
        // network_providers mapping has not been configured yet.
        $provider = getAnyActiveProviderEndpointByNetwork($network_id, $endpoint_type, null);
        if ($provider) {
            $provider['resolved_endpoint_type'] = $provider['endpoint_type'] ?? 'regular';
            $provider['requested_endpoint_type'] = $endpoint_type;
            error_log(
                'Provider mapping fallback selected endpoint: '
                . 'network_id=' . $network_id
                . ', provider_id=' . ($provider['provider_id'] ?? 0)
                . ', requested=' . $endpoint_type
                . ', resolved=' . ($provider['resolved_endpoint_type'] ?? 'regular')
            );
            return $provider;
        }
        return null;
    }

    $primary_provider_id = (int)($mapping['primary_provider_id'] ?? 0);
    $backup_provider_id = (int)($mapping['backup_provider_id'] ?? 0);
    $candidate_endpoint_types = array_values(array_unique([$endpoint_type, 'regular']));

    foreach ($candidate_endpoint_types as $candidate_endpoint_type) {
        if ($primary_provider_id > 0) {
            $provider = getProviderEndpointById($primary_provider_id, $network_id, $candidate_endpoint_type, $backup_provider_id);
            if ($provider) {
                $provider['resolved_endpoint_type'] = $candidate_endpoint_type;
                $provider['requested_endpoint_type'] = $endpoint_type;
                return $provider;
            }
        }

        if ($backup_provider_id > 0) {
            $provider = getProviderEndpointById($backup_provider_id, $network_id, $candidate_endpoint_type, $backup_provider_id);
            if ($provider) {
                $provider['resolved_endpoint_type'] = $candidate_endpoint_type;
                $provider['requested_endpoint_type'] = $endpoint_type;
                $provider['is_backup_selected'] = 1;
                return $provider;
            }
        }
    }

    // Relaxed fallback 1:
    // If strict endpoint match failed for configured providers, pick any active endpoint
    // for the mapped provider/network (prefer requested endpoint, then regular).
    if ($primary_provider_id > 0) {
        $provider = getProviderEndpointByPreference($primary_provider_id, $network_id, $endpoint_type, $backup_provider_id);
        if ($provider) {
            $provider['resolved_endpoint_type'] = $provider['endpoint_type'] ?? 'regular';
            $provider['requested_endpoint_type'] = $endpoint_type;
            error_log(
                'Provider relaxed fallback selected primary endpoint: '
                . 'network_id=' . $network_id
                . ', provider_id=' . $primary_provider_id
                . ', requested=' . $endpoint_type
                . ', resolved=' . ($provider['resolved_endpoint_type'] ?? 'regular')
            );
            return $provider;
        }
    }

    if ($backup_provider_id > 0) {
        $provider = getProviderEndpointByPreference($backup_provider_id, $network_id, $endpoint_type, $backup_provider_id);
        if ($provider) {
            $provider['resolved_endpoint_type'] = $provider['endpoint_type'] ?? 'regular';
            $provider['requested_endpoint_type'] = $endpoint_type;
            $provider['is_backup_selected'] = 1;
            error_log(
                'Provider relaxed fallback selected backup endpoint: '
                . 'network_id=' . $network_id
                . ', provider_id=' . $backup_provider_id
                . ', requested=' . $endpoint_type
                . ', resolved=' . ($provider['resolved_endpoint_type'] ?? 'regular')
            );
            return $provider;
        }
    }

    // Relaxed fallback 2:
    // If mapping exists but cannot resolve provider endpoint, pick any active provider endpoint
    // for this network to avoid false "network busy" during configuration drift.
    $provider = getAnyActiveProviderEndpointByNetwork($network_id, $endpoint_type, $backup_provider_id);
    if ($provider) {
        $provider['resolved_endpoint_type'] = $provider['endpoint_type'] ?? 'regular';
        $provider['requested_endpoint_type'] = $endpoint_type;
        error_log(
            'Provider global fallback selected endpoint: '
            . 'network_id=' . $network_id
            . ', provider_id=' . ($provider['provider_id'] ?? 0)
            . ', requested=' . $endpoint_type
            . ', resolved=' . ($provider['resolved_endpoint_type'] ?? 'regular')
        );
        return $provider;
    }

    return null;
}

/**
 * Check if a network has an active provider for the given endpoint type.
 */
function checkNetworkProviderAvailability($network_code, $endpoint_type = 'regular') {
    $provider = getNetworkProvider($network_code, $endpoint_type);
    if (!$provider) {
        $resolved_network_id = resolveProviderNetworkId($network_code);
        error_log(
            'Provider availability check failed: '
            . 'network_code=' . json_encode($network_code)
            . ', resolved_network_id=' . $resolved_network_id
            . ', endpoint_type=' . $endpoint_type
        );
        return [
            'available' => false,
            'message' => 'Network is busy, validation is ongoing'
        ];
    }
    return [
        'available' => true,
        'message' => ''
    ];
}

/**
 * Get backup provider for a network
 */
function getBackupProvider($network_id, $endpoint_type = 'regular') {
    global $db;

    $network_id = resolveProviderNetworkId($network_id);
    if ($network_id <= 0) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT backup_provider_id
        FROM network_providers
        WHERE network_id = ? AND is_active = 1 AND backup_provider_id IS NOT NULL
        ORDER BY priority_order ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $network_id);
    $stmt->execute();
    $mapping = $stmt->get_result()->fetch_assoc();
    if (!$mapping) {
        return null;
    }

    $backup_provider_id = (int)($mapping['backup_provider_id'] ?? 0);
    if ($backup_provider_id <= 0) {
        return null;
    }

    $candidate_endpoint_types = array_values(array_unique([$endpoint_type, 'regular']));
    foreach ($candidate_endpoint_types as $candidate_endpoint_type) {
        $provider = getProviderEndpointById($backup_provider_id, $network_id, $candidate_endpoint_type, $backup_provider_id);
        if ($provider) {
            $provider['resolved_endpoint_type'] = $candidate_endpoint_type;
            $provider['requested_endpoint_type'] = $endpoint_type;
            return $provider;
        }
    }

    return null;
}

/**
 * Process data bundle purchase through API providers
 */
function processBundlePurchase($bundle_order_id, $network_id, $phone, $volume_gb, $endpoint_type = 'regular', $allow_retry = false) {
    global $db;

    $bundle_order_id = (int) $bundle_order_id;
    $network_id = resolveProviderNetworkId($network_id);
    $lock_name = $bundle_order_id > 0 ? ('bundle_order_send_' . $bundle_order_id) : '';
    $lock_acquired = false;
    $preferred_reference = null;

    try {
        // Hard idempotency lock: only one dispatch attempt may run per order at a time.
        if ($lock_name !== '') {
            $lock_stmt = $db->prepare("SELECT GET_LOCK(?, 30) AS lock_status");
            if ($lock_stmt) {
                $lock_stmt->bind_param('s', $lock_name);
                $lock_stmt->execute();
                $lock_row = $lock_stmt->get_result()->fetch_assoc();
                $lock_acquired = ((int) ($lock_row['lock_status'] ?? 0)) === 1;
            }
            if (!$lock_acquired) {
                throw new Exception('Order is already being processed. Please wait.');
            }
        }

        if ($bundle_order_id > 0) {
            $order_stmt = $db->prepare("
                SELECT status, api_response, provider_reference, order_reference
                FROM bundle_orders
                WHERE id = ?
                LIMIT 1
            ");
            if (!$order_stmt) {
                throw new Exception('Unable to verify order state before dispatch.');
            }
            $order_stmt->bind_param('i', $bundle_order_id);
            $order_stmt->execute();
            $order_row = $order_stmt->get_result()->fetch_assoc();
            if (!$order_row) {
                throw new Exception('Order not found for dispatch.');
            }

            $order_status = strtolower(trim((string) ($order_row['status'] ?? '')));
            if ($allow_retry) {
                $order_reference = trim((string) ($order_row['order_reference'] ?? ''));
                if ($order_reference !== '') {
                    $preferred_reference = $order_reference;
                }
            }
            if (in_array($order_status, ['delivered', 'success', 'completed'], true)) {
                $cached_response = null;
                if (!empty($order_row['api_response'])) {
                    $decoded = json_decode((string) $order_row['api_response'], true);
                    if (is_array($decoded)) {
                        $cached_response = $decoded;
                    }
                }
                return [
                    'success' => true,
                    'provider' => null,
                    'response' => $cached_response,
                    'error' => null,
                    'reference' => ($order_row['provider_reference'] ?? '') ?: extractProviderReference($cached_response)
                ];
            }

            if (function_exists('dbh_table_exists') && dbh_table_exists('api_transaction_logs')) {
                $log_stmt = $db->prepare("
                    SELECT is_successful, response_data, error_message
                    FROM api_transaction_logs
                    WHERE bundle_order_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                if ($log_stmt) {
                    $log_stmt->bind_param('i', $bundle_order_id);
                    $log_stmt->execute();
                    $log_row = $log_stmt->get_result()->fetch_assoc();
                    if ($log_row) {
                        $logged_response = null;
                        if (!empty($log_row['response_data'])) {
                            $decoded = json_decode((string) $log_row['response_data'], true);
                            if (is_array($decoded)) {
                                $logged_response = $decoded;
                            }
                        }
                        $logged_success = ((int) ($log_row['is_successful'] ?? 0)) === 1;

                        // Allow explicit retries for previously failed attempts only.
                        if ($allow_retry && !$logged_success) {
                            $log_row = null;
                        }

                        if ($log_row) {
                        $logged_reference = extractProviderReference($logged_response);
                        return [
                            'success' => $logged_success,
                            'provider' => null,
                            'response' => $logged_response,
                            'error' => $logged_success ? null : (($log_row['error_message'] ?? '') ?: 'Order already submitted once to API'),
                            'reference' => $logged_reference
                        ];
                        }
                    }
                }
            }
        }

        $provider = getNetworkProvider($network_id, $endpoint_type);
        if (!$provider) {
            error_log("Provider unavailable: No active provider found for network {$network_id} endpoint {$endpoint_type}");
            throw new Exception('Network is busy, validation is ongoing');
        }

        $resolved_endpoint_type = $provider['resolved_endpoint_type'] ?? $endpoint_type;

        $success = false;
        $response_data = null;
        $error_message = null;
        $reference = null;

        // Single dispatch only: do not failover to backup on the same order.
        try {
            $result = callProviderAPI($provider['provider_slug'], $network_id, $phone, $volume_gb, $bundle_order_id, $resolved_endpoint_type, $preferred_reference);
            $success = !empty($result['success']);
            $response_data = $result['response'] ?? null;
            $error_message = $result['error'] ?? null;
            $reference = $result['reference'] ?? extractProviderReference($response_data);

            logAPITransaction(
                $bundle_order_id,
                $provider['provider_id'],
                $network_id,
                $resolved_endpoint_type,
                $result['request_data'] ?? [],
                $response_data,
                $result['http_code'] ?? 0,
                $result['response_time'] ?? 0,
                $success ? 1 : 0,
                $error_message,
                0
            );
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            logAPITransaction(
                $bundle_order_id,
                $provider['provider_id'],
                $network_id,
                $resolved_endpoint_type,
                [],
                null,
                0,
                0,
                0,
                $error_message,
                0
            );
        }

        return [
            'success' => $success,
            'provider' => $provider,
            'response' => $response_data,
            'error' => $error_message,
            'reference' => $reference
        ];
    } finally {
        if ($lock_acquired && $lock_name !== '') {
            $unlock_stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
            if ($unlock_stmt) {
                $unlock_stmt->bind_param('s', $lock_name);
                $unlock_stmt->execute();
            }
        }
    }
}

/**
 * Call provider API with proper formatting
 */
function callProviderAPI($provider_slug, $network_id, $phone, $volume_gb, $bundle_order_id, $endpoint_type, $preferred_reference = null) {
    $start_time = microtime(true);
    
    // Get network name and provider data
    global $db;
    $stmt = $db->prepare("SELECT name FROM networks WHERE id = ?");
    $stmt->bind_param('i', $network_id);
    $stmt->execute();
    $network_name = $stmt->get_result()->fetch_assoc()['name'];
    
    // Get provider configuration
    $stmt = $db->prepare("
        SELECT 
            ap.id as provider_id,
            ap.name as provider_name,
            ap.slug as provider_slug,
            ap.base_url,
            ap.auth_type,
            ap.auth_token,
            ap.timeout_seconds,
            ap.retry_attempts,
            pe.endpoint_url,
            pe.request_format,
            pe.response_format
        FROM api_providers ap
        JOIN provider_endpoints pe ON ap.id = pe.provider_id
        WHERE ap.slug = ? AND pe.network_id = ? AND pe.endpoint_type = ?
          AND ap.is_active = 1
          AND pe.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('sis', $provider_slug, $network_id, $endpoint_type);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();
    
    if (!$provider) {
        return [
            'success' => false,
            'error' => "Provider configuration not found for $provider_slug network $network_id",
            'provider' => $provider_slug,
            'response' => null
        ];
    }
    
    // Prepare request data based on provider format
    $request_format = json_decode($provider['request_format'], true);
    if (!is_array($request_format)) {
        $request_format = [];
    }
    $response_format = json_decode($provider['response_format'], true);
    if (!is_array($response_format)) {
        $response_format = [];
    }
    
    // Format phone number based on provider - ALL APIs require 10-digit format
    $formatted_phone = formatPhone($phone);
    $normalized_network_name = normalizeProviderRequestNetwork($provider_slug, $network_name, $network_id);
    
    // Replace placeholders in request format
    $reference = '';
    if ($provider['provider_name'] === 'Hubnet Console') {
        $reference = 'HUB_' . time() . '_' . random_int(1000, 9999);
    } elseif (!empty($preferred_reference)) {
        $reference = (string) $preferred_reference;
    } else {
        $reference = generateReference('API');
    }
    $request_data = [];
    $hubnet_webhook = SITE_URL . '/api/hubnet_order_webhook.php';
    $hubnet_referrer = '0249020304'; // Default admin

    foreach ($request_format as $key => $value) {
        $request_data[$key] = replacePlaceholders($value, [
            'phone' => $formatted_phone,
            'reference' => $reference,
            'volume_gb' => $volume_gb,
            'volume_mb' => $volume_gb * 1000,
            'volume' => $volume_gb * 1000, // For Hubnet
            'api_key' => $provider['auth_token'],
            'client_email' => 'admin@' . parse_url(SITE_URL, PHP_URL_HOST),
            'customer_name' => 'Bundle Purchase',
            'customer_tel' => $formatted_phone,
            'network' => $normalized_network_name,
            'referrer' => $hubnet_referrer,
            'webhook' => $hubnet_webhook
        ]);
    }

    if (strpos(strtolower($provider_slug), 'hubnet') !== false) {
        // Hubnet requires volume in MB (e.g. 2000 for 2GB)
        $request_data['volume'] = (string)($volume_gb * 1000);
        
        // Force all common phone field variants to be the sanitized string to ensure leading zero is preserved
        $request_data['phone'] = (string)$formatted_phone;
        $request_data['msisdn'] = (string)$formatted_phone;
        $request_data['beneficiary'] = (string)$formatted_phone;
        $request_data['recipient'] = (string)$formatted_phone;
        $request_data['recipient_number'] = (string)$formatted_phone;
        $request_data['customer_tel'] = (string)$formatted_phone;
        
        // Force reference and its common variants to ensure they are always sent
        // Hubnet requires at least 6 characters, generateReference provides ~25+
        $request_data['reference'] = (string)$reference;
        $request_data['ref'] = (string)$reference;
        $request_data['external_id'] = (string)$reference;
        $request_data['request_id'] = (string)$reference;
        $request_data['client_reference'] = (string)$reference;
        $request_data['batch_id'] = (string)$reference;
        
        // Use a static referrer or fallback to admin phone if available
        $referrer = $hubnet_referrer; 
        if ($bundle_order_id > 0) {
            $stmt = $db->prepare("
                SELECT u.phone 
                FROM bundle_orders bo 
                JOIN users u ON bo.agent_id = u.id 
                WHERE bo.id = ? AND bo.agent_id IS NOT NULL 
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $bundle_order_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                if ($res && !empty($res['phone'])) {
                    $referrer = formatPhone($res['phone']);
                }
                $stmt->close();
            }
        }
        $request_data['referrer'] = $referrer;

        // Force the payload to match the selected network so Telecel orders are sent as Telecel.
        $request_data['network'] = $normalized_network_name;
        
        // Inject dynamic webhook URL for Hubnet real-time status updates
        $request_data['webhook'] = SITE_URL . '/api/hubnet_order_webhook.php';
    }

    if (($provider_slug === 'codecraft' || $provider_slug === 'etrubahub') && !isset($request_data['agent_api'])) {
        $request_data['agent_api'] = $provider['auth_token'];
    }
    if (!isset($request_data['reference_id'])) {
        $request_data['reference_id'] = $reference;
    }
    
    // Build full URL
    $full_url = rtrim($provider['base_url'], '/') . $provider['endpoint_url'];
    
    // Prepare cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($provider['timeout_seconds'] ?? 20),
        CURLOPT_HTTPHEADER => buildHeaders($provider),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $response_time = round((microtime(true) - $start_time) * 1000);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception('cURL error: ' . $curl_error);
    }
    
    // Parse response - handle both JSON and non-JSON responses
    $response_data = decodeProviderResponse($response);
    
    // If JSON parsing fails, check if it's a successful HTTP response
    if (!is_array($response_data)) {
        // For successful HTTP codes (200-299), treat as success even if not JSON
        if ($http_code >= 200 && $http_code < 300) {
            return [
                'success' => true,
                'response' => ['raw_response' => $response],
                'request_data' => $request_data,
                'http_code' => $http_code,
                'response_time' => $response_time,
                'error' => null,
                'reference' => $reference
            ];
        } else {
            return [
                'success' => false,
                'response' => null,
                'request_data' => $request_data,
                'http_code' => $http_code,
                'response_time' => $response_time,
                'error' => 'Invalid JSON response from provider',
                'reference' => $reference
            ];
        }
    }
    
    // Determine success based on response format
    $success = isResponseSuccessful($response_data, $response_format, $http_code);
    $error_message = $success ? null : extractErrorMessage($response_data, $response_format);

    // Some providers return transitional "validation ongoing" responses even when
    // the order is already accepted for processing. Treat these as accepted.
    if (!$success && isValidationOngoingResponse($error_message, $response_data)) {
        $success = true;
        if (!isset($response_data['delivery_state'])) {
            $response_data['delivery_state'] = 'processing';
        }
        if (!isset($response_data['delivery_note'])) {
            $response_data['delivery_note'] = (string)$error_message;
        }
        $error_message = null;
    }
    
    return [
        'success' => $success,
        'response' => $response_data,
        'request_data' => $request_data,
        'http_code' => $http_code,
        'response_time' => $response_time,
        'error' => $error_message,
        'reference' => extractProviderReference($response_data) ?: $reference
    ];
}

/**
 * Normalize provider-specific network payload values.
 */
function normalizeProviderRequestNetwork($provider_slug, $network_name, $network_id = 0) {
    $provider_slug = strtolower(trim((string) $provider_slug));
    $network_name = strtolower(trim((string) $network_name));
    $network_id = (int) $network_id;

    if ($provider_slug === 'datawax') {
        $datawax_network_map = [
            1 => 'MTN',
            2 => 'AT',
            4 => 'TEL',
        ];

        if (isset($datawax_network_map[$network_id])) {
            return $datawax_network_map[$network_id];
        }

        $datawax_aliases = [
            'mtn' => 'MTN',
            'at' => 'AT',
            'airteltigo' => 'AT',
            'airtel tigo' => 'AT',
            'telecel' => 'TEL',
            'vodafone' => 'TEL',
        ];

        return $datawax_aliases[$network_name] ?? strtoupper($network_name);
    }

    if ($provider_slug !== 'hubnet') {
        return $network_name;
    }

    $hubnet_network_map = [
        1 => 'mtn',
        2 => 'at',
        4 => 'telecel',
        5 => 'vodafone',
    ];

    if (isset($hubnet_network_map[$network_id])) {
        return $hubnet_network_map[$network_id];
    }

    $network_aliases = [
        'mtn' => 'mtn',
        'at' => 'at',
        'airteltigo' => 'at',
        'airtel tigo' => 'at',
        'telecel' => 'telecel',
        'telecel cash' => 'telecel',
        'vodafone' => 'vodafone',
        'vodafone cash' => 'vodafone',
    ];

    return $network_aliases[$network_name] ?? $network_name;
}

/**
 * Build HTTP headers for API request
 */
function buildHeaders($provider) {
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    $auth_token = trim((string)($provider['auth_token'] ?? ''));
    
    switch ($provider['auth_type']) {
        case 'bearer':
            if ($auth_token !== '') {
                $headers[] = 'Authorization: Bearer ' . $auth_token;
                $headers[] = 'token: Bearer ' . $auth_token;
            }
            break;
        case 'header':
            if ($auth_token !== '') {
                $headers[] = 'token: Bearer ' . $auth_token;
            }
            break;
        case 'api_key':
            if ($auth_token !== '') {
                $headers[] = 'X-API-KEY: ' . $auth_token;
                $headers[] = 'X-API-Key: ' . $auth_token;
                $headers[] = 'api-key: ' . $auth_token;
                $headers[] = 'Authorization: Bearer ' . $auth_token;
            }
            break;
    }
    
    return $headers;
}

/**
 * Resolve network code/id to canonical network ID.
 */
function resolveProviderNetworkId($network_code) {
    $network_mapping = [
        'mtn' => 1,
        'at' => 2,
        'telecel' => 4,
        'vodafone' => 5
    ];

    if (is_string($network_code)) {
        $normalized = strtolower(trim($network_code));
        if (isset($network_mapping[$normalized])) {
            return $network_mapping[$normalized];
        }
    }

    if (is_numeric($network_code)) {
        return (int)$network_code;
    }

    return 0;
}

/**
 * Load provider endpoint configuration by provider/network/endpoint type.
 */
function getProviderEndpointById($provider_id, $network_id, $endpoint_type, $backup_provider_id = null) {
    global $db;

    $stmt = $db->prepare("
        SELECT
            ap.id as provider_id,
            ap.name as provider_name,
            ap.slug as provider_slug,
            ap.base_url,
            ap.auth_type,
            ap.auth_token,
            ap.timeout_seconds,
            ap.retry_attempts,
            pe.endpoint_url,
            pe.request_format,
            pe.response_format
        FROM api_providers ap
        JOIN provider_endpoints pe ON pe.provider_id = ap.id
        WHERE ap.id = ?
          AND pe.network_id = ?
          AND pe.endpoint_type = ?
          AND ap.is_active = 1
          AND pe.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('iis', $provider_id, $network_id, $endpoint_type);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();

    if ($provider) {
        $provider['backup_provider_id'] = $backup_provider_id;
    }

    return $provider ?: null;
}

/**
 * Load any active endpoint for a mapped provider/network, preferring requested endpoint type.
 */
function getProviderEndpointByPreference($provider_id, $network_id, $preferred_endpoint_type = 'regular', $backup_provider_id = null) {
    global $db;

    $preferred_endpoint_type = strtolower(trim((string)$preferred_endpoint_type));
    if ($preferred_endpoint_type === '') {
        $preferred_endpoint_type = 'regular';
    }

    $stmt = $db->prepare("
        SELECT
            ap.id as provider_id,
            ap.name as provider_name,
            ap.slug as provider_slug,
            ap.base_url,
            ap.auth_type,
            ap.auth_token,
            ap.timeout_seconds,
            ap.retry_attempts,
            pe.endpoint_type,
            pe.endpoint_url,
            pe.request_format,
            pe.response_format
        FROM api_providers ap
        JOIN provider_endpoints pe ON pe.provider_id = ap.id
        WHERE ap.id = ?
          AND pe.network_id = ?
          AND ap.is_active = 1
          AND pe.is_active = 1
        ORDER BY
          CASE
            WHEN pe.endpoint_type = ? THEN 0
            WHEN pe.endpoint_type = 'regular' THEN 1
            WHEN pe.endpoint_type = 'bigtime' THEN 2
            WHEN pe.endpoint_type = 'special' THEN 3
            ELSE 4
          END,
          pe.id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iis', $provider_id, $network_id, $preferred_endpoint_type);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();

    if ($provider) {
        $provider['backup_provider_id'] = $backup_provider_id;
    }

    return $provider ?: null;
}

/**
 * Load any active provider endpoint for a network, preferring endpoint type.
 */
function getAnyActiveProviderEndpointByNetwork($network_id, $preferred_endpoint_type = 'regular', $backup_provider_id = null) {
    global $db;

    $preferred_endpoint_type = strtolower(trim((string)$preferred_endpoint_type));
    if ($preferred_endpoint_type === '') {
        $preferred_endpoint_type = 'regular';
    }

    $stmt = $db->prepare("
        SELECT
            ap.id as provider_id,
            ap.name as provider_name,
            ap.slug as provider_slug,
            ap.base_url,
            ap.auth_type,
            ap.auth_token,
            ap.timeout_seconds,
            ap.retry_attempts,
            pe.endpoint_type,
            pe.endpoint_url,
            pe.request_format,
            pe.response_format
        FROM provider_endpoints pe
        JOIN api_providers ap ON ap.id = pe.provider_id
        WHERE pe.network_id = ?
          AND ap.is_active = 1
          AND pe.is_active = 1
        ORDER BY
          CASE
            WHEN pe.endpoint_type = ? THEN 0
            WHEN pe.endpoint_type = 'regular' THEN 1
            WHEN pe.endpoint_type = 'bigtime' THEN 2
            WHEN pe.endpoint_type = 'special' THEN 3
            ELSE 4
          END,
          pe.id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $network_id, $preferred_endpoint_type);
    $stmt->execute();
    $provider = $stmt->get_result()->fetch_assoc();

    if ($provider) {
        $provider['backup_provider_id'] = $backup_provider_id;
    }

    return $provider ?: null;
}

/**
 * Detect endpoint type from package metadata.
 */
function detectEndpointTypeForPackage($package_name = '', $package_data_size = '', $package_type = '') {
    $needle = strtolower(trim((string)$package_name . ' ' . (string)$package_data_size . ' ' . (string)$package_type));
    if ($needle === '') {
        return 'regular';
    }
    if (strpos($needle, 'bigtime') !== false || strpos($needle, 'big time') !== false || strpos($needle, 'big-time') !== false) {
        return 'bigtime';
    }
    if (strpos($needle, 'ishare') !== false || strpos($needle, 'i share') !== false || strpos($needle, 'i-share') !== false) {
        return 'special';
    }
    if (strpos($needle, 'special') !== false) {
        return 'special';
    }
    return 'regular';
}

/**
 * Parse provider response and salvage embedded JSON when providers prepend warnings.
 */
function decodeProviderResponse($response) {
    $parsed = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
        return $parsed;
    }

    if (!is_string($response)) {
        return null;
    }

    $first_brace = strpos($response, '{');
    $last_brace = strrpos($response, '}');
    if ($first_brace === false || $last_brace === false || $last_brace <= $first_brace) {
        return null;
    }

    $json_fragment = substr($response, $first_brace, ($last_brace - $first_brace + 1));
    $parsed_fragment = json_decode($json_fragment, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_fragment)) {
        return $parsed_fragment;
    }

    return null;
}

/**
 * Extract reference identifier from provider response payload.
 */
function extractProviderReference($response_data) {
    if (!is_array($response_data)) {
        return null;
    }

    $candidate_paths = [
        ['reference_id'],
        ['reference'],
        ['externalref'],
        ['order_id'],
        ['txref'],
        ['batch_id'],
        ['data', 'reference_id'],
        ['data', 'reference'],
        ['data', 'externalref'],
        ['data', 'order_id'],
        ['data', 'txref'],
        ['data', 'batch_id']
    ];

    foreach ($candidate_paths as $path) {
        $current = $response_data;
        $found = true;
        foreach ($path as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $found = false;
                break;
            }
            $current = $current[$part];
        }
        if ($found && (is_scalar($current) || $current === null) && $current !== null && $current !== '') {
            return (string)$current;
        }
    }

    return null;
}

/**
 * Replace placeholders in template strings
 */
function replacePlaceholders($template, $data) {
    foreach ($data as $key => $value) {
        $template = str_replace('{{' . $key . '}}', $value, $template);
    }
    return $template;
}

/**
 * Check if API response indicates success
 */
function isResponseSuccessful($response_data, $response_format, $http_code) {
    // Handle non-JSON responses (raw_response from successful HTTP calls)
    if (isset($response_data['raw_response'])) {
        return $http_code >= 200 && $http_code < 300;
    }
    
    // Handle missing or invalid response format
    if (!is_array($response_format) || !isset($response_format['success_field'])) {
        // If no success field configured, use HTTP status code
        return $http_code >= 200 && $http_code < 300;
    }
    
    $success_field = $response_format['success_field'];
    $success_value = $response_format['success_value'];
    
    // Handle nested field access (e.g., "data.status")
    $field_parts = explode('.', $success_field);
    $current_data = $response_data;
    
    foreach ($field_parts as $part) {
        if (!isset($current_data[$part])) {
            // If field is missing, fallback to HTTP status code
            return $http_code >= 200 && $http_code < 300;
        }
        $current_data = $current_data[$part];
    }
    
    // Special case for providers that explicitly map success to HTTP code.
    if (($success_field === 'http_code' || $success_field === 'http_status_code') && is_numeric($success_value)) {
        return $http_code == $success_value;
    }

    // Hubnet specific success detection
    if (strpos(strtolower((string)$success_field), 'status') !== false) {
        // Handle Hubnet-like response structure
        $data_part = $response_data['data'] ?? $response_data;
        $code = strtolower(trim((string)($data_part['code'] ?? '')));
        $msg = strtolower(trim((string)($data_part['message'] ?? $response_data['message'] ?? '')));
        $status_val = $data_part['status'] ?? null;

        // Hubnet success markers
        $hubnet_success_markers = [
            '0000', 'success', 'initiated', 'processing', 'pending',
            'order received', 'order placed', 'accepted', 'queued', '200'
        ];

        // Check markers in code or message
        foreach ($hubnet_success_markers as $marker) {
            if ($code === $marker || $msg === $marker || strpos($msg, $marker) !== false || strpos($code, $marker) !== false) {
                return true;
            }
        }

        // Check if data.status is explicitly true (boolean)
        if ($status_val === true || $status_val === 1 || $status_val === 'true') {
            return true;
        }
    }
    
    // Fallback to strict value comparison
    return (string)$current_data === (string)$success_value;
}

/**
 * Detect provider responses that mean "accepted and queued" instead of hard failure.
 */
function isValidationOngoingResponse($error_message, $response_data = null) {
    $message = strtolower(trim((string)$error_message));
    if ($message === '') {
        return false;
    }

    $queued_markers = [
        'validation is ongoing',
        'validation in progress',
        'being validated',
        'queued',
        'processing',
        'initiated',
        'order received',
        'order placed',
        'accepted',
        'being processed',
        'pending'
    ];

    $has_queued_marker = false;
    foreach ($queued_markers as $marker) {
        if (strpos($message, $marker) !== false) {
            $has_queued_marker = true;
            break;
        }
    }

    if (!$has_queued_marker) {
        return false;
    }

    $hard_failure_markers = [
        'insufficient',
        'invalid',
        'failed',
        'rejected',
        'unauthorized',
        'forbidden',
        'not found'
    ];

    foreach ($hard_failure_markers as $marker) {
        if (strpos($message, $marker) !== false) {
            return false;
        }
    }

    if (is_array($response_data)) {
        $status_candidates = [
            $response_data['status'] ?? null,
            $response_data['data']['status'] ?? null
        ];

        foreach ($status_candidates as $status_value) {
            if (is_string($status_value)) {
                $normalized = strtolower(trim($status_value));
                if (in_array($normalized, ['failed', 'error', 'rejected'], true)) {
                    return false;
                }
            }
        }
    }

    return true;
}

/**
 * Extract error message from API response
 */
function extractErrorMessage($response_data, $response_format) {
    // Handle non-JSON responses (raw_response from successful HTTP calls)
    if (isset($response_data['raw_response'])) {
        return 'API call completed successfully';
    }

    $readNestedValue = static function ($data, $path) {
        $parts = explode('.', (string) $path);
        $current = $data;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    };

    $normalizeMessage = static function ($value) {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                } elseif (is_array($item)) {
                    foreach (['message', 'error', 'detail'] as $nestedKey) {
                        if (!empty($item[$nestedKey]) && is_string($item[$nestedKey])) {
                            $parts[] = trim($item[$nestedKey]);
                            break;
                        }
                    }
                }
            }
            if (!empty($parts)) {
                return implode('; ', array_unique($parts));
            }
        }
        return null;
    };
    
    // Prefer provider-configured error field when available
    if (is_array($response_format) && isset($response_format['error_field'])) {
        $current_data = $readNestedValue($response_data, $response_format['error_field']);
        $message = $normalizeMessage($current_data);
        if ($message !== null) {
            return $message;
        }
    }

    // Try common provider error/message fields before collapsing to a generic message.
    $common_error_fields = [
        'message',
        'error',
        'detail',
        'description',
        'response.message',
        'response.error',
        'data.message',
        'data.error',
        'data.detail',
        'data.description',
        'data.response.message',
        'data.response.error',
        'errors',
        'errors.0',
        'data.errors',
        'data.errors.0',
    ];

    foreach ($common_error_fields as $field) {
        $message = $normalizeMessage($readNestedValue($response_data, $field));
        if ($message !== null) {
            return $message;
        }
    }

    // Check for common error patterns that indicate insufficient balance
    if (is_array($response_data)) {
        $response_text = json_encode($response_data);
        
        // Check for insufficient balance indicators
        $balance_patterns = [
            'insufficient',
            'balance',
            'low balance',
            'not enough',
            'funds',
            'credit',
            'wallet'
        ];
        
        foreach ($balance_patterns as $pattern) {
            if (stripos($response_text, $pattern) !== false) {
                return 'Insufficient balance in provider wallet. Please contact administrator to top up the system wallet.';
            }
        }
        
        // Check for other common error patterns
        if (stripos($response_text, 'invalid') !== false) {
            return 'Invalid request parameters. Please try again or contact support.';
        }
        
        if (stripos($response_text, 'network') !== false) {
            return 'Network error occurred. Please try again later.';
        }

        // Final fallback: expose a compact provider response summary for debugging.
        $summary = substr(trim(preg_replace('/\s+/', ' ', (string) $response_text)), 0, 220);
        if ($summary !== '' && $summary !== 'null') {
            return 'Provider response: ' . $summary;
        }
    }
    
    if (!isset($response_format['error_field'])) {
        return 'Provider API error. Please try again or contact support.';
    }

    return 'Provider API error. Please try again or contact support.';
}

/**
 * Log API transaction for monitoring and debugging
 */
function logAPITransaction($bundle_order_id, $provider_id, $network_id, $endpoint_type, 
                          $request_data, $response_data, $http_code, $response_time, 
                          $is_successful, $error_message, $retry_count) {
    global $db;
    
    // Validate endpoint_type against ENUM values
    $valid_endpoints = ['regular', 'bigtime', 'special'];
    if (!in_array($endpoint_type, $valid_endpoints)) {
        $endpoint_type = 'regular'; // Default to regular if invalid
    }
    
    // Ensure is_successful is always an integer (0 or 1)
    $is_successful = empty($is_successful) ? 0 : (int)$is_successful;
    $is_successful = $is_successful ? 1 : 0;
    
    if (function_exists('dbh_ensure_auto_increment')) {
        if (!dbh_ensure_auto_increment('api_transaction_logs')) {
            error_log('API transaction log skipped: AUTO_INCREMENT missing on api_transaction_logs.id');
            return;
        }
    }
    
    $stmt = $db->prepare("
        INSERT INTO api_transaction_logs 
        (bundle_order_id, provider_id, network_id, endpoint_type, request_data, response_data, 
         http_status_code, response_time_ms, is_successful, error_message, retry_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $request_json = json_encode($request_data);
    $response_json = json_encode($response_data);
    
    $stmt->bind_param('iiisssiissi', $bundle_order_id, $provider_id, $network_id, $endpoint_type,
                     $request_json, $response_json, $http_code, $response_time, 
                     $is_successful, $error_message, $retry_count);
    $stmt->execute();
}

/**
 * Ensure Toppily provider exists so admin can configure/activate it from the dashboard.
 * Defaults are intentionally inactive until admin fills the exact API details.
 */
function ensureToppilyProviderBootstrap() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!isset($db)) {
        return;
    }

    if (function_exists('dbh_table_exists')) {
        if (!dbh_table_exists('api_providers') || !dbh_table_exists('provider_endpoints')) {
            return;
        }
    }

    try {
        $provider_id = 0;
        $slug = 'toppily';

        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $provider_id = (int) ($row['id'] ?? 0);
        }

        if ($provider_id <= 0) {
            $name = 'Toppily';
            $description = 'Toppily data provider (agent.toppily.com). Update endpoints and token from your Toppily API docs before activation.';
            $base_url = 'https://agent.toppily.com';
            $auth_type = 'bearer';
            $auth_token = '';
            $is_active = 0;
            $timeout_seconds = 25;
            $retry_attempts = 1;
            $priority = 1;

            $insert = $db->prepare("
                INSERT INTO api_providers
                    (name, slug, description, base_url, auth_type, auth_token, is_active, timeout_seconds, retry_attempts, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert) {
                return;
            }
            $insert->bind_param(
                'ssssssiiii',
                $name,
                $slug,
                $description,
                $base_url,
                $auth_type,
                $auth_token,
                $is_active,
                $timeout_seconds,
                $retry_attempts,
                $priority
            );
            if (!$insert->execute()) {
                return;
            }
            $provider_id = (int) $db->lastInsertId();
            if ($provider_id <= 0 && method_exists($db, 'getConnection')) {
                $provider_id = (int) $db->getConnection()->insert_id;
            }
        }

        if ($provider_id <= 0) {
            return;
        }

        $default_response_format = json_encode([
            'success_field' => 'status',
            'success_value' => 'success',
            'error_field' => 'message'
        ]);

        $endpoint_templates = [
            [1, 'regular', '/api/v1/data/purchase', ['phone' => '{{phone}}', 'network' => '{{network}}', 'capacity' => '{{volume_gb}}', 'reference_id' => '{{reference}}']],
            [2, 'regular', '/api/v1/data/purchase', ['phone' => '{{phone}}', 'network' => '{{network}}', 'capacity' => '{{volume_gb}}', 'reference_id' => '{{reference}}']],
            [4, 'regular', '/api/v1/data/purchase', ['phone' => '{{phone}}', 'network' => '{{network}}', 'capacity' => '{{volume_gb}}', 'reference_id' => '{{reference}}']],
            [1, 'bigtime', '/api/v1/data/purchase', ['phone' => '{{phone}}', 'network' => '{{network}}', 'capacity' => '{{volume_gb}}', 'reference_id' => '{{reference}}']],
            [2, 'bigtime', '/api/v1/data/purchase', ['phone' => '{{phone}}', 'network' => '{{network}}', 'capacity' => '{{volume_gb}}', 'reference_id' => '{{reference}}']]
        ];

        foreach ($endpoint_templates as $template) {
            $network_id = (int) $template[0];
            $endpoint_type = (string) $template[1];
            $endpoint_url = (string) $template[2];
            $request_format = json_encode($template[3]);
            $endpoint_active = 0;

            $check = $db->prepare("
                SELECT id
                FROM provider_endpoints
                WHERE provider_id = ? AND network_id = ? AND endpoint_type = ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param('iis', $provider_id, $network_id, $endpoint_type);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                if ($exists) {
                    continue;
                }
            }

            $insert_endpoint = $db->prepare("
                INSERT INTO provider_endpoints
                    (provider_id, network_id, endpoint_type, endpoint_url, request_format, response_format, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert_endpoint) {
                continue;
            }
            $insert_endpoint->bind_param(
                'iissssi',
                $provider_id,
                $network_id,
                $endpoint_type,
                $endpoint_url,
                $request_format,
                $default_response_format,
                $endpoint_active
            );
            $insert_endpoint->execute();
        }
    } catch (Exception $e) {
        error_log('Toppily provider bootstrap failed: ' . $e->getMessage());
    }
}

/**
 * Ensure Hubnet has endpoint rows for supported networks so admin can manage them.
 * Existing rows are preserved; only missing rows are inserted.
 */
function ensureHubnetProviderBootstrap() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!isset($db)) {
        return;
    }

    if (function_exists('dbh_table_exists')) {
        if (!dbh_table_exists('api_providers') || !dbh_table_exists('provider_endpoints')) {
            return;
        }
    }

    try {
        $provider_id = 0;
        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = 'hubnet' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $provider_id = (int) ($row['id'] ?? 0);
        }

        if ($provider_id <= 0) {
            return;
        }

        $default_response_format = json_encode([
            'success_field' => 'data.status',
            'success_value' => true,
            'error_field' => 'data.message'
        ]);

        $endpoint_templates = [
            [1, 'regular', '/mtn-new-transaction', ['phone' => '{{phone}}', 'reference' => '{{reference}}', 'volume' => '{{volume}}', 'network' => 'mtn']],
            [2, 'regular', '/at-new-transaction', ['phone' => '{{phone}}', 'reference' => '{{reference}}', 'volume' => '{{volume}}', 'network' => 'at']],
            [4, 'regular', '/telecel-new-transaction', ['phone' => '{{phone}}', 'reference' => '{{reference}}', 'volume' => '{{volume}}', 'network' => 'telecel']],
        ];

        foreach ($endpoint_templates as $template) {
            $network_id = (int) $template[0];
            $endpoint_type = (string) $template[1];
            $endpoint_url = (string) $template[2];
            $request_format = json_encode($template[3]);
            $endpoint_active = 1;

            $check = $db->prepare("
                SELECT id
                FROM provider_endpoints
                WHERE provider_id = ? AND network_id = ? AND endpoint_type = ?
                LIMIT 1
            ");
            if (!$check) {
                continue;
            }

            $check->bind_param('iis', $provider_id, $network_id, $endpoint_type);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if ($exists) {
                continue;
            }

            $insert_endpoint = $db->prepare("
                INSERT INTO provider_endpoints
                    (provider_id, network_id, endpoint_type, endpoint_url, request_format, response_format, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert_endpoint) {
                continue;
            }

            $insert_endpoint->bind_param(
                'iissssi',
                $provider_id,
                $network_id,
                $endpoint_type,
                $endpoint_url,
                $request_format,
                $default_response_format,
                $endpoint_active
            );
            $insert_endpoint->execute();
            $insert_endpoint->close();
        }
    } catch (Exception $e) {
        error_log('Hubnet provider bootstrap failed: ' . $e->getMessage());
    }
}

/**
 * Ensure Datawax provider exists so admin can configure and route bundles to it.
 * Existing provider/endpoint rows are preserved.
 */
function ensureDatawaxProviderBootstrap() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!isset($db)) {
        return;
    }

    if (function_exists('dbh_table_exists')) {
        if (!dbh_table_exists('api_providers') || !dbh_table_exists('provider_endpoints')) {
            return;
        }
    }

    try {
        $provider_id = 0;
        $slug = 'datawax';

        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $provider_id = (int) ($row['id'] ?? 0);
            $stmt->close();
        }

        if ($provider_id <= 0) {
            $name = 'Datawax';
            $description = 'Datawax REST API provider for MTN, AirtelTigo, Telecel, and BigTime bundles.';
            $base_url = 'https://datawax.site/wp-json/api/v1';
            $auth_type = 'api_key';
            $auth_token = function_exists('dbh_env') ? (string) dbh_env('DATAWAX_API_KEY', '') : '';
            $is_active = 0;
            $timeout_seconds = 30;
            $retry_attempts = 1;
            $priority = 1;

            $insert = $db->prepare("
                INSERT INTO api_providers
                    (name, slug, description, base_url, auth_type, auth_token, is_active, timeout_seconds, retry_attempts, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert) {
                return;
            }
            $insert->bind_param(
                'ssssssiiii',
                $name,
                $slug,
                $description,
                $base_url,
                $auth_type,
                $auth_token,
                $is_active,
                $timeout_seconds,
                $retry_attempts,
                $priority
            );
            if (!$insert->execute()) {
                $insert->close();
                return;
            }
            $provider_id = (int) $db->lastInsertId();
            if ($provider_id <= 0 && method_exists($db, 'getConnection')) {
                $provider_id = (int) $db->getConnection()->insert_id;
            }
            $insert->close();
        }

        if ($provider_id <= 0) {
            return;
        }

        $default_response_format = json_encode([
            'success_field' => 'status',
            'success_value' => 1,
            'error_field' => 'message'
        ]);

        $endpoint_templates = [
            [1, 'regular', '/place', ['network' => 'MTN', 'volume' => '{{volume_gb}}', 'customer_number' => '{{phone}}', 'externalref' => '{{reference}}']],
            [2, 'regular', '/place', ['network' => 'AT', 'volume' => '{{volume_gb}}', 'customer_number' => '{{phone}}', 'externalref' => '{{reference}}']],
            [4, 'regular', '/place', ['network' => 'TEL', 'volume' => '{{volume_gb}}', 'customer_number' => '{{phone}}', 'externalref' => '{{reference}}']],
            [2, 'bigtime', '/place', ['network' => 'BIG', 'volume' => '{{volume_gb}}', 'customer_number' => '{{phone}}', 'externalref' => '{{reference}}']],
        ];

        foreach ($endpoint_templates as $template) {
            $network_id = (int) $template[0];
            $endpoint_type = (string) $template[1];
            $endpoint_url = (string) $template[2];
            $request_format = json_encode($template[3]);
            $endpoint_active = 0;

            $check = $db->prepare("
                SELECT id
                FROM provider_endpoints
                WHERE provider_id = ? AND network_id = ? AND endpoint_type = ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param('iis', $provider_id, $network_id, $endpoint_type);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();
                if ($exists) {
                    continue;
                }
            }

            $insert_endpoint = $db->prepare("
                INSERT INTO provider_endpoints
                    (provider_id, network_id, endpoint_type, endpoint_url, request_format, response_format, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert_endpoint) {
                continue;
            }
            $insert_endpoint->bind_param(
                'iissssi',
                $provider_id,
                $network_id,
                $endpoint_type,
                $endpoint_url,
                $request_format,
                $default_response_format,
                $endpoint_active
            );
            $insert_endpoint->execute();
            $insert_endpoint->close();
        }
    } catch (Exception $e) {
        error_log('Datawax provider bootstrap failed: ' . $e->getMessage());
    }
}

/**
 * Get provider statistics for admin dashboard
 */
function getProviderStats($days = 7) {
    global $db;
    
    // Aggregate provider performance directly to avoid reliance on MySQL views with definer restrictions.
    $stmt = $db->prepare("
        SELECT 
            provider_name,
            network_name,
            SUM(total_requests) AS total_requests,
            SUM(successful_requests) AS successful_requests,
            SUM(failed_requests) AS failed_requests,
            ROUND(AVG(avg_response_time_ms), 2) AS avg_response_time_ms,
            ROUND(AVG(success_rate_percent), 2) AS success_rate_percent
        FROM (
            SELECT 
                ap.name AS provider_name,
                n.name AS network_name,
                COUNT(atl.id) AS total_requests,
                SUM(CASE WHEN atl.is_successful = 1 THEN 1 ELSE 0 END) AS successful_requests,
                SUM(CASE WHEN atl.is_successful = 0 THEN 1 ELSE 0 END) AS failed_requests,
                ROUND(AVG(atl.response_time_ms), 2) AS avg_response_time_ms,
                ROUND(
                    (SUM(CASE WHEN atl.is_successful = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(atl.id), 0)) * 100,
                    2
                ) AS success_rate_percent
            FROM api_providers ap
            JOIN api_transaction_logs atl 
                ON ap.id = atl.provider_id
                AND atl.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            LEFT JOIN networks n ON atl.network_id = n.id
            GROUP BY ap.id, n.id, DATE(atl.created_at)
        ) AS daily_stats
        GROUP BY provider_name, network_name
        ORDER BY provider_name, network_name
    ");
    
    $stmt->bind_param('i', $days);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Switch primary provider for a network
 */
function switchNetworkProvider($network_id, $new_primary_provider_id, $new_backup_provider_id = null) {
    global $db;
    
    $stmt = $db->prepare("
        UPDATE network_providers 
        SET primary_provider_id = ?, backup_provider_id = ?, updated_at = NOW()
        WHERE network_id = ?
    ");
    
    $stmt->bind_param('iii', $new_primary_provider_id, $new_backup_provider_id, $network_id);
    return $stmt->execute();
}

/**
 * Ensure CodeCraft provider exists with a Telecel endpoint and network_providers mapping.
 * Runs on every getNetworkProvider() call so misconfigured databases self-heal at runtime.
 */
function ensureCodeCraftProviderBootstrap() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!isset($db)) {
        return;
    }

    if (function_exists('dbh_table_exists')) {
        if (!dbh_table_exists('api_providers') || !dbh_table_exists('provider_endpoints') || !dbh_table_exists('network_providers')) {
            return;
        }
    }

    try {
        $provider_id = 0;
        $slug = 'codecraft';

        // 1. Ensure CodeCraft provider row exists
        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $provider_id = (int) ($row['id'] ?? 0);
            $stmt->close();
        }

        if ($provider_id <= 0) {
            $provider_config = [
                'name' => 'CodeCraft Network',
                'slug' => 'codecraft',
                'description' => 'Provider for all networks including Telecel',
                'base_url' => 'https://api.codecraftnetwork.com/api',
                'auth_type' => 'api_key',
                'auth_token' => function_exists('dbh_env') ? (string) dbh_env('CODECRAFT_API_KEY', '') : '',
                'is_active' => 1,
                'timeout_seconds' => 20,
                'retry_attempts' => 3,
                'priority' => 1,
            ];

            $insert = $db->prepare("
                INSERT INTO api_providers
                    (name, slug, description, base_url, auth_type, auth_token, is_active, timeout_seconds, retry_attempts, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insert) {
                return;
            }
            $insert->bind_param(
                'ssssssiiii',
                $provider_config['name'],
                $provider_config['slug'],
                $provider_config['description'],
                $provider_config['base_url'],
                $provider_config['auth_type'],
                $provider_config['auth_token'],
                $provider_config['is_active'],
                $provider_config['timeout_seconds'],
                $provider_config['retry_attempts'],
                $provider_config['priority']
            );
            if (!$insert->execute()) {
                $insert->close();
                return;
            }
            $provider_id = (int) $db->lastInsertId();
            if ($provider_id <= 0 && method_exists($db, 'getConnection')) {
                $provider_id = (int) $db->getConnection()->insert_id;
            }
            $insert->close();
        }

        if ($provider_id <= 0) {
            return;
        }

        // 2. Ensure Telecel endpoint exists for CodeCraft
        $endpoint_type = 'regular';
        $endpoint_url = '/initiate.php';
        $request_format = json_encode([
            'recipient_number' => '{{phone}}',
            'network' => 'TELECEL',
            'gig' => '{{volume_gb}}',
        ]);
        $response_format = json_encode([
            'success_field' => 'status',
            'success_value' => 200,
            'error_field' => 'message',
        ]);
        $network_id = 4;

        $check = $db->prepare("
            SELECT id
            FROM provider_endpoints
            WHERE provider_id = ? AND network_id = ? AND endpoint_type = ?
            LIMIT 1
        ");
        if ($check) {
            $check->bind_param('iis', $provider_id, $network_id, $endpoint_type);
            $check->execute();
            $endpoint_exists = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$endpoint_exists) {
                $insert_endpoint = $db->prepare("
                    INSERT INTO provider_endpoints
                        (provider_id, network_id, endpoint_type, endpoint_url, request_format, response_format, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                if ($insert_endpoint) {
                    $insert_endpoint->bind_param(
                        'iissss',
                        $provider_id,
                        $network_id,
                        $endpoint_type,
                        $endpoint_url,
                        $request_format,
                        $response_format
                    );
                    $insert_endpoint->execute();
                    $insert_endpoint->close();
                }
            }
        }

        // 3. Ensure network_providers mapping for Telecel (network_id=4 -> CodeCraft)
        $np_check = $db->prepare("
            SELECT id
            FROM network_providers
            WHERE network_id = ?
            LIMIT 1
        ");
        if ($np_check) {
            $np_check->bind_param('i', $network_id);
            $np_check->execute();
            $np_exists = $np_check->get_result()->fetch_assoc();
            $np_check->close();

            if (!$np_exists) {
                $insert_np = $db->prepare("
                    INSERT INTO network_providers
                        (network_id, primary_provider_id, backup_provider_id, is_active, priority_order)
                    VALUES (?, ?, ?, 1, 1)
                ");
                if ($insert_np) {
                    $insert_np->bind_param('iii', $network_id, $provider_id, $provider_id);
                    $insert_np->execute();
                    $insert_np->close();
                    error_log("CodeCraft bootstrap: Created network_providers mapping for Telecel (network_id={$network_id} -> provider_id={$provider_id})");
                }
            }
        }
    } catch (Exception $e) {
        error_log('CodeCraft provider bootstrap failed: ' . $e->getMessage());
    }
}

/**
 * Ensure Telecel (network_id=4) is routed through Hubnet as primary provider.
 * Only sets the mapping when:
 *   - No network_providers row exists for Telecel, OR
 *   - The current primary provider has NO active Telecel endpoint
 * Admin changes to a working provider are preserved.
 */
function ensureTelecelProviderMapping() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!isset($db)) {
        return;
    }

    if (function_exists('dbh_table_exists')) {
        if (!dbh_table_exists('api_providers') || !dbh_table_exists('provider_endpoints') || !dbh_table_exists('network_providers')) {
            return;
        }
    }

    try {
        $network_id = 4;

        $hubnet_id = 0;
        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = 'hubnet' AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $hubnet_id = (int) ($row['id'] ?? 0);
            $stmt->close();
        }

        if ($hubnet_id <= 0) {
            return;
        }

        $codecraft_id = 0;
        $stmt = $db->prepare("SELECT id FROM api_providers WHERE slug = 'codecraft' AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $codecraft_id = (int) ($row['id'] ?? 0);
            $stmt->close();
        }

        $stmt = $db->prepare("SELECT id, primary_provider_id FROM network_providers WHERE network_id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $network_id);
        $stmt->execute();
        $mapping = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($mapping) {
            $primary_id = (int) ($mapping['primary_provider_id'] ?? 0);
            if ($primary_id === $hubnet_id) {
                return;
            }

            $ep_check = $db->prepare("SELECT 1 FROM provider_endpoints WHERE provider_id = ? AND network_id = ? AND is_active = 1 LIMIT 1");
            if (!$ep_check) {
                return;
            }
            $ep_check->bind_param('ii', $primary_id, $network_id);
            $ep_check->execute();
            $has_endpoint = (bool) $ep_check->get_result()->fetch_assoc();
            $ep_check->close();

            if ($has_endpoint) {
                return;
            }

            $backup_id = $codecraft_id > 0 ? $codecraft_id : $hubnet_id;
            $update = $db->prepare("UPDATE network_providers SET primary_provider_id = ?, backup_provider_id = ?, is_active = 1 WHERE id = ?");
            if ($update) {
                $update->bind_param('iii', $hubnet_id, $backup_id, $mapping['id']);
                $update->execute();
                $update->close();
                error_log("Telecel mapping: switched primary from provider_id={$primary_id} to Hubnet (id={$hubnet_id}), backup={$backup_id}");
            }
        } else {
            $backup_id = $codecraft_id > 0 ? $codecraft_id : $hubnet_id;
            $insert = $db->prepare("INSERT INTO network_providers (network_id, primary_provider_id, backup_provider_id, is_active, priority_order) VALUES (?, ?, ?, 1, 1)");
            if ($insert) {
                $insert->bind_param('iii', $network_id, $hubnet_id, $backup_id);
                $insert->execute();
                $insert->close();
                error_log("Telecel mapping: created with Hubnet (id={$hubnet_id}) as primary, backup={$backup_id}");
            }
        }
    } catch (Exception $e) {
        error_log('Telecel provider mapping bootstrap failed: ' . $e->getMessage());
    }
}
?>
