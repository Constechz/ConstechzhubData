<?php
/**
 * Email Helper Functions
 * Handles SMTP configuration and email sending
 */

require_once __DIR__ . '/../config/config.php';

// Try to include SEO functions, but don't fail if not available
if (file_exists(__DIR__ . '/seo.php')) {
    require_once __DIR__ . '/seo.php';
}

// Fallback function for getSeoSetting if not available
if (!function_exists('getSeoSetting')) {
    function getSeoSetting($setting_name, $default = '') {
        // Simple fallbacks for common settings
        switch ($setting_name) {
            case 'site_url':
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                return $protocol . '://' . $host;
            case 'site_name':
                return defined('SITE_NAME') ? SITE_NAME : 'Constechzhub';
            default:
                return $default;
        }
    }
}

/**
 * Get SMTP setting value
 */
function getSmtpSetting($setting_name, $default = '') {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT setting_value, is_encrypted FROM smtp_settings WHERE setting_name = ?");
        $stmt->bind_param("s", $setting_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $value = $row['setting_value'];
            
            // Decrypt if encrypted
            if ($row['is_encrypted'] && $value) {
                $value = decryptSetting($value);
            }
            
            return $value;
        }
        
        return $default;
    } catch (Exception $e) {
        error_log("SMTP Setting Error: " . $e->getMessage());
        return $default;
    }
}

/**
 * Update SMTP setting
 */
function updateSmtpSetting($setting_name, $setting_value, $is_encrypted = false, $description = null) {
    global $db;
    
    try {
        // Skip update if password is masked or empty
        if ($setting_name === 'smtp_password' && ($setting_value === '••••••••' || empty($setting_value))) {
            return true; // Return true to indicate "no error" but no update needed
        }
        
        // Encrypt sensitive data
        if ($is_encrypted && $setting_value) {
            $setting_value = encryptSetting($setting_value);
        }
        
        // Check if setting exists
        $check_stmt = $db->prepare("SELECT id FROM smtp_settings WHERE setting_name = ?");
        $check_stmt->bind_param("s", $setting_name);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $exists = $result->num_rows > 0;
        $check_stmt->close();
        
        if ($exists) {
            // Update existing setting
            if ($description) {
                $stmt = $db->prepare("UPDATE smtp_settings SET setting_value = ?, is_encrypted = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_name = ?");
                $stmt->bind_param("siss", $setting_value, $is_encrypted, $description, $setting_name);
            } else {
                $stmt = $db->prepare("UPDATE smtp_settings SET setting_value = ?, is_encrypted = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_name = ?");
                $stmt->bind_param("sis", $setting_value, $is_encrypted, $setting_name);
            }
        } else {
            // Insert new setting
            if ($description) {
                $stmt = $db->prepare("INSERT INTO smtp_settings (setting_name, setting_value, is_encrypted, description) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssis", $setting_name, $setting_value, $is_encrypted, $description);
            } else {
                $stmt = $db->prepare("INSERT INTO smtp_settings (setting_name, setting_value, is_encrypted) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $setting_name, $setting_value, $is_encrypted);
            }
        }
        
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("SMTP Update Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Normalize and encode HTML email body to prevent line splitting issues.
 */
function encodeEmailHtmlBody($body_html) {
    $body = (string) $body_html;
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);

    if (function_exists('quoted_printable_encode')) {
        $body = quoted_printable_encode($body);
    }

    // Dot-stuff lines starting with a dot to avoid premature SMTP termination.
    $body = preg_replace('/^\./m', '..', $body);

    return $body;
}

/**
 * Simple encryption for sensitive settings
 */
function encryptSetting($value) {
    $key = hash('sha256', 'data_bundle_hub_secret_key_2025');
    $iv = substr(hash('sha256', 'data_bundle_hub_iv'), 0, 16);
    return base64_encode(openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv));
}

/**
 * Simple decryption for sensitive settings
 */
function decryptSetting($encrypted_value) {
    $key = hash('sha256', 'data_bundle_hub_secret_key_2025');
    $iv = substr(hash('sha256', 'data_bundle_hub_iv'), 0, 16);
    return openssl_decrypt(base64_decode($encrypted_value), 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Get email template
 */
function getEmailTemplate($template_name) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT * FROM email_templates WHERE template_name = ? AND is_active = 1");
        $stmt->bind_param("s", $template_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Email Template Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Process email template variables
 */
function processEmailTemplate($template, $variables = []) {
    if (!$template) return null;
    
    $subject = $template['subject'];
    $body_html = $template['body_html'];
    $body_text = $template['body_text'];
    
    // Add default variables
    $default_vars = [
        'site_name' => function_exists('getSeoSetting') ? getSeoSetting('site_name', 'Constechzhub') : 'Constechzhub',
        'current_year' => date('Y'),
        'site_url' => function_exists('getSeoSetting') ? getSeoSetting('site_url', 'https://yourdomain.com') : 'https://yourdomain.com'
    ];
    
    $variables = array_merge($default_vars, $variables);
    
    // Replace variables in subject and body
    foreach ($variables as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        $subject = str_replace($placeholder, $value, $subject);
        $body_html = str_replace($placeholder, $value, $body_html);
        $body_text = str_replace($placeholder, $value, $body_text);
    }
    
    return [
        'subject' => $subject,
        'body_html' => $body_html,
        'body_text' => $body_text
    ];
}

/**
 * Send email using SMTP
 */
function generateEmailMessageId($from_email = null) {
    $domain = '';
    if (!empty($from_email) && strpos($from_email, '@') !== false) {
        $parts = explode('@', $from_email);
        $domain = trim($parts[1] ?? '');
    }
    if ($domain === '') {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    $domain = preg_replace('/[^A-Za-z0-9\\-\\.]/', '', $domain);
    $random = bin2hex(random_bytes(8));
    return '<' . $random . '.' . time() . '@' . $domain . '>';
}

function sendEmail($to_email, $subject, $body_html, $body_text = '', $template_name = null) {
    // Check if SMTP is enabled
    if (getSmtpSetting('smtp_enabled', 'false') !== 'true') {
        logEmail($to_email, $subject, $template_name, 'failed', 'SMTP is disabled');
        return false;
    }
    
    // Get SMTP settings
    $smtp_host = getSmtpSetting('smtp_host');
    $smtp_port = getSmtpSetting('smtp_port', '587');
    $smtp_encryption = getSmtpSetting('smtp_encryption', 'tls');
    $smtp_username = getSmtpSetting('smtp_username');
    $smtp_password = getSmtpSetting('smtp_password');
    $from_email = getSmtpSetting('from_email');
    $from_name = getSmtpSetting('from_name', getSiteName());
    $reply_to = getSmtpSetting('reply_to_email');
    
    if (!$smtp_host || !$smtp_username || !$smtp_password || !$from_email) {
        logEmail($to_email, $subject, $template_name, 'failed', 'SMTP configuration incomplete');
        return false;
    }
    
    try {
        // Try custom SMTP implementation first
        $success = false;
        $attempt_errors = [];
        $smtp_response = null;
        $message_id = generateEmailMessageId($from_email);
        
        // Only attempt SMTP if host is configured
        if ($smtp_host && $smtp_username && $smtp_password && $from_email) {
            try {
                $success = sendSmtpEmail($to_email, $subject, $body_html, $body_text, [
                    'host' => $smtp_host,
                    'port' => intval($smtp_port),
                    'encryption' => $smtp_encryption,
                    'username' => $smtp_username,
                    'password' => $smtp_password,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'reply_to' => $reply_to ?: $from_email,
                    'message_id' => $message_id
                ], $smtp_response);
                
                if ($success) {
                    error_log('SMTP email sent successfully to: ' . $to_email);
                }
            } catch (Exception $smtp_e) {
                $attempt_errors[] = 'SMTP socket: ' . $smtp_e->getMessage();
                error_log('SMTP attempt failed: ' . $smtp_e->getMessage());
                $success = false;
            } catch (Error $smtp_err) {
                $attempt_errors[] = 'SMTP socket fatal: ' . $smtp_err->getMessage();
                error_log('SMTP attempt error (resource/fatal): ' . $smtp_err->getMessage());
                $success = false;
            }
        }
        
        // If SMTP fails or is not configured, try fallback method with ini_set
        if (!$success && $smtp_host && $smtp_username && $smtp_password && $from_email) {
            try {
                $fallback_error = null;
                $success = sendEmailFallback($to_email, $subject, $body_html, $body_text, [
                    'host' => $smtp_host,
                    'port' => intval($smtp_port),
                    'encryption' => $smtp_encryption,
                    'username' => $smtp_username,
                    'password' => $smtp_password,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'reply_to' => $reply_to ?: $from_email,
                    'message_id' => $message_id
                ], $fallback_error);
                
                if (!$success && $fallback_error) {
                    $attempt_errors[] = 'PHP mail fallback: ' . $fallback_error;
                }
            } catch (Exception $fallback_e) {
                $attempt_errors[] = 'PHP mail fallback exception: ' . $fallback_e->getMessage();
                error_log('SMTP fallback failed: ' . $fallback_e->getMessage());
                $success = false;
            }
        }
        
        // If all SMTP methods fail, use basic PHP mail() as last resort
        if (!$success) {
            try {
                error_log('Attempting basic PHP mail() as last resort for: ' . $to_email);
                
                $headers = [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . ($from_name ?: 'Constechzhub') . ' <' . ($from_email ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>',
                    'Reply-To: ' . ($reply_to ?: $from_email ?: 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
                    'Message-ID: ' . $message_id,
                    'X-Mailer: Constechzhub'
                ];
                
                $success = @mail($to_email, $subject, $body_html, implode("\r\n", $headers));
                
                if ($success) {
                    error_log('Basic PHP mail() succeeded for: ' . $to_email);
                } else {
                    $last_error = error_get_last();
                    $attempt_errors[] = 'Basic mail() failed: ' . (($last_error['message'] ?? 'unknown error') . ' (host=' . ($smtp_host ?: 'not set') . ', port=' . $smtp_port . ')');
                    error_log('All email methods failed for: ' . $to_email);
                }
            } catch (Exception $mail_e) {
                $attempt_errors[] = 'Basic mail() exception: ' . $mail_e->getMessage();
                error_log('Basic mail() failed: ' . $mail_e->getMessage());
                $success = false;
            }
        }
        
        if ($success) {
            logEmail($to_email, $subject, $template_name, 'sent', null, $message_id, $from_email, $smtp_response);
            return true;
        } else {
            $failure_message = 'All SMTP methods failed';
            if (!empty($attempt_errors)) {
                $failure_message .= ' - ' . implode(' | ', $attempt_errors);
            }
            logEmail($to_email, $subject, $template_name, 'failed', $failure_message, $message_id, $from_email, $smtp_response);
            return false;
        }
        
    } catch (Exception $e) {
        logEmail($to_email, $subject, $template_name, 'failed', $e->getMessage(), $message_id ?? null, $from_email ?? null, $smtp_response ?? null);
        return false;
    }
}

/**
 * Fallback email method using PHP ini_set for SMTP configuration
 */
function sendEmailFallback($to_email, $subject, $body_html, $body_text, $config, &$error_out = null) {
    try {
        // Temporarily configure PHP's mail settings
        $original_smtp = ini_get('SMTP');
        $original_smtp_port = ini_get('smtp_port');
        $original_sendmail_from = ini_get('sendmail_from');
        
        // Set SMTP configuration
        ini_set('SMTP', $config['host']);
        ini_set('smtp_port', $config['port']);
        ini_set('sendmail_from', $config['from_email']);
        
        $encoded_body = encodeEmailHtmlBody($body_html);
        // Create headers
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
            'Reply-To: ' . $config['reply_to'],
            'Message-ID: ' . ($config['message_id'] ?? generateEmailMessageId($config['from_email'] ?? null)),
            'X-Mailer: ' . getSiteName()
        ];
        
        // Send email using PHP's mail function
        $success = @mail($to_email, $subject, $encoded_body, implode("\r\n", $headers));
        
        if (!$success) {
            $last_error = error_get_last();
            $error_out = ($last_error['message'] ?? 'PHP mail() failed') . " (host={$config['host']}, port={$config['port']})";
            error_log('SMTP Fallback mail() failed: ' . $error_out);
        }
        
        // Restore original settings
        ini_set('SMTP', $original_smtp);
        ini_set('smtp_port', $original_smtp_port);
        ini_set('sendmail_from', $original_sendmail_from);
        
        return $success;
        
    } catch (Exception $e) {
        error_log('SMTP Fallback Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email via SMTP using socket connection
 */
function sendSmtpEmail($to_email, $subject, $body_html, $body_text, $config) {
    $socket = null;
    
    try {
        // Create socket connection with improved error handling and timeout management
        $errno = 0;
        $errstr = '';
        
        // Set connection timeout (optimized for reliability)
        $timeout = 30;
        
        // Create appropriate socket connection with better validation
        if ($config['encryption'] === 'ssl') {
            $socket = @fsockopen('ssl://' . $config['host'], $config['port'], $errno, $errstr, $timeout);
        } else {
            $socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, $timeout);
        }
        
        if (!$socket || !is_resource($socket)) {
            throw new Exception("Could not connect to SMTP server {$config['host']}:{$config['port']} - $errstr ($errno)");
        }
        
        // Set socket timeout for operations
        stream_set_timeout($socket, 30);
        
        // Validate initial connection
        if (feof($socket)) {
            throw new Exception('Socket connection closed immediately after creation');
        }
        
        // Read initial response
        $response = fgets($socket, 512);
        if ($response === false || substr($response, 0, 3) !== '220') {
            throw new Exception('Invalid SMTP response: ' . trim($response ?: 'No response'));
        }
        
        // Send EHLO
        fputs($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
        
        // Read all EHLO responses (multi-line response)
        $ehlo_responses = [];
        do {
            $response = fgets($socket, 512);
            $ehlo_responses[] = trim($response);
        } while (substr($response, 3, 1) === '-');
        
        // Check if last response is successful
        $last_response = end($ehlo_responses);
        if (substr($last_response, 0, 3) !== '250') {
            throw new Exception('EHLO failed: ' . implode(' | ', $ehlo_responses));
        }
        
        // Start TLS if required
        if ($config['encryption'] === 'tls') {
            // Validate socket is still valid before STARTTLS
            if (!is_resource($socket) || feof($socket)) {
                throw new Exception('Socket connection lost before STARTTLS');
            }
            
            fputs($socket, "STARTTLS\r\n");
            $response = fgets($socket, 512);
            if (substr($response, 0, 3) !== '220') {
                throw new Exception('STARTTLS failed: ' . trim($response));
            }
            
            // Wait a moment for server to prepare for TLS
            usleep(250000); // 0.25 seconds
            
            // Validate socket is still valid before enabling crypto
            if (!is_resource($socket) || feof($socket)) {
                throw new Exception('Socket connection lost before enabling crypto');
            }
            
            // Enable crypto with improved error handling and multiple fallback strategies
            $crypto_enabled = false;
            $last_error = '';
            
            // Strategy 1: Try with minimal context for maximum compatibility
            if (!$crypto_enabled) {
                try {
                    if (is_resource($socket) && !feof($socket)) {
                        $minimal_context = stream_context_create([
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true
                            ]
                        ]);
                        
                        $crypto_result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT, $minimal_context);
                        
                        if ($crypto_result === true) {
                            $crypto_enabled = true;
                            error_log('TLS encryption enabled with minimal context');
                        } else {
                            $last_error = 'TLS with minimal context failed';
                        }
                    }
                } catch (Exception $e) {
                    $last_error = 'Minimal context attempt: ' . $e->getMessage();
                } catch (Error $e) {
                    $last_error = 'Minimal context error: ' . $e->getMessage();
                }
            }
            
            // Strategy 2: Try without any context
            if (!$crypto_enabled) {
                try {
                    if (is_resource($socket) && !feof($socket)) {
                        $crypto_result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                        
                        if ($crypto_result === true) {
                            $crypto_enabled = true;
                            error_log('TLS encryption enabled without context');
                        } else {
                            $last_error = 'TLS without context failed';
                        }
                    }
                } catch (Exception $e) {
                    $last_error = 'No context attempt: ' . $e->getMessage();
                } catch (Error $e) {
                    $last_error = 'No context error: ' . $e->getMessage();
                }
            }
            
            // Strategy 3: Try with alternative TLS method
            if (!$crypto_enabled) {
                try {
                    if (is_resource($socket) && !feof($socket)) {
                        $crypto_result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
                        
                        if ($crypto_result === true) {
                            $crypto_enabled = true;
                            error_log('TLS encryption enabled with ANY_CLIENT method');
                        } else {
                            $last_error = 'TLS with ANY_CLIENT failed';
                        }
                    }
                } catch (Exception $e) {
                    $last_error = 'ANY_CLIENT attempt: ' . $e->getMessage();
                } catch (Error $e) {
                    $last_error = 'ANY_CLIENT error: ' . $e->getMessage();
                }
            }
            
            // If all TLS strategies failed, clean up and throw exception
            if (!$crypto_enabled) {
                error_log('TLS encryption failed without context, final error: ' . $last_error);
                
                // Close the socket before throwing exception
                if (is_resource($socket)) {
                    @fclose($socket);
                }
                
                throw new Exception('SMTP Error: Failed to enable TLS encryption on socket. Socket may have been closed by server or became invalid.');
            }
            
            // Send EHLO again after TLS
            fputs($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
            
            // Read all EHLO responses again
            $ehlo_responses = [];
            do {
                $response = fgets($socket, 512);
                $ehlo_responses[] = trim($response);
            } while (substr($response, 3, 1) === '-');
            
            $last_response = end($ehlo_responses);
            if (substr($last_response, 0, 3) !== '250') {
                throw new Exception('EHLO after TLS failed: ' . implode(' | ', $ehlo_responses));
            }
        }
        
        // Authenticate
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '334') {
            throw new Exception('AUTH LOGIN failed: ' . trim($response));
        }
        
        fputs($socket, base64_encode($config['username']) . "\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '334') {
            throw new Exception('Username authentication failed: ' . trim($response));
        }
        
        fputs($socket, base64_encode($config['password']) . "\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '235') {
            throw new Exception('Password authentication failed: ' . trim($response));
        }
        
        // Send email
        fputs($socket, "MAIL FROM: <" . $config['from_email'] . ">\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception('MAIL FROM failed: ' . trim($response));
        }
        
        fputs($socket, "RCPT TO: <$to_email>\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception('RCPT TO failed: ' . trim($response));
        }
        
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '354') {
            throw new Exception('DATA command failed: ' . trim($response));
        }
        
        $encoded_body = encodeEmailHtmlBody($body_html);
        // Compose message
        $message = "From: " . $config['from_name'] . " <" . $config['from_email'] . ">\r\n";
        $message .= "To: $to_email\r\n";
        $message .= "Reply-To: " . $config['reply_to'] . "\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n";
        if (!empty($config['message_id'])) {
            $message .= "Message-ID: " . $config['message_id'] . "\r\n";
        }
        $message .= "X-Mailer: " . getSiteName() . "\r\n";
        $message .= "\r\n";
        $message .= $encoded_body;
        $message .= "\r\n.\r\n";
        
        fputs($socket, $message);
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception('Message send failed: ' . trim($response));
        }
        
        // Quit and close connection properly with improved error handling
        if (is_resource($socket)) {
            @fputs($socket, "QUIT\r\n");
            // Wait for server to acknowledge quit command
            $quit_response = @fgets($socket, 512);
            
            // Give server additional time to close gracefully
            usleep(200000); // 0.2 seconds
            
            // Close the socket
            @fclose($socket);
            $socket = null; // Clear reference
        }
        
        return true;
        
    } catch (Exception $e) {
        // Clean up socket if it exists and is still a valid resource
        if (isset($socket) && is_resource($socket)) {
            @fputs($socket, "QUIT\r\n");
            // Wait briefly for server response
            usleep(100000);
            @fclose($socket);
        }
        
        $error_msg = 'SMTP Error: ' . $e->getMessage();
        error_log($error_msg);
        
        // Log specific error details for debugging
        error_log("SMTP Connection Details: Host={$config['host']}, Port={$config['port']}, Encryption={$config['encryption']}");
        
        return false;
    } catch (Error $e) {
        // Handle fatal errors (like resource-related errors)
        if (isset($socket) && is_resource($socket)) {
            @fclose($socket);
        }
        
        $error_msg = 'SMTP Fatal Error: ' . $e->getMessage();
        error_log($error_msg);
        
        // Log specific error details for debugging
        error_log("SMTP Connection Details: Host={$config['host']}, Port={$config['port']}, Encryption={$config['encryption']}");
        
        return false;
    } finally {
        // Final cleanup - ensure socket is properly closed
        if (isset($socket) && is_resource($socket)) {
            @fclose($socket);
        }
    }
}

/**
 * Send templated email
 */
function sendTemplatedEmail($to_email, $template_name, $variables = []) {
    $template = getEmailTemplate($template_name);
    if (!$template) {
        error_log("Email template not found: " . $template_name);
        return false;
    }
    
    $processed = processEmailTemplate($template, $variables);
    if (!$processed) {
        error_log("Failed to process email template: " . $template_name);
        return false;
    }
    
    return sendEmail(
        $to_email,
        $processed['subject'],
        $processed['body_html'],
        $processed['body_text'],
        $template_name
    );
}

/**
 * Log email activity
 */
function logEmail($recipient_email, $subject, $template_name, $status, $error_message = null) {
    global $db;
    
    try {
        $sent_at = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
        
        // Check if email_logs table exists and has AUTO_INCREMENT
        $check_table = $db->query("SHOW CREATE TABLE email_logs");
        if (!$check_table) {
            // Create table if it doesn't exist
            $create_sql = "
                CREATE TABLE IF NOT EXISTS email_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    recipient_email VARCHAR(255) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    template_name VARCHAR(100) NULL,
                    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
                    error_message TEXT NULL,
                    sent_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $db->query($create_sql);
        } else {
            // Check if id column has AUTO_INCREMENT
            $table_info = $check_table->fetch_assoc();
            if (strpos($table_info['Create Table'], 'AUTO_INCREMENT') === false) {
                // Fix the table structure
                $db->query("ALTER TABLE email_logs MODIFY id INT AUTO_INCREMENT PRIMARY KEY");
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO email_logs (recipient_email, subject, template_name, status, error_message, sent_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssss", $recipient_email, $subject, $template_name, $status, $error_message, $sent_at);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Email Log Error: " . $e->getMessage());
        // Try to fix the issue by ensuring proper table structure
        try {
            $db->query("ALTER TABLE email_logs MODIFY id INT AUTO_INCREMENT PRIMARY KEY");
        } catch (Exception $fix_e) {
            error_log("Failed to fix email_logs table: " . $fix_e->getMessage());
        }
    }
}

/**
 * Test SMTP configuration
 */
function testSmtpConnection($test_email = null) {
    $test_email = $test_email ?: getSmtpSetting('test_email');
    
    if (!$test_email) {
        return ['success' => false, 'message' => 'No test email address configured'];
    }
    
    $subject = 'SMTP Test - ' . getSiteName();
    $body = '<h2>SMTP Test Successful!</h2><p>Your email configuration is working correctly.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>';
    
    $result = sendEmail($test_email, $subject, $body, '', 'smtp_test');
    
    return [
        'success' => $result,
        'message' => $result ? 'Test email sent successfully!' : 'Failed to send test email. Check your SMTP settings.'
    ];
}

/**
 * Get all SMTP settings for admin interface
 */
function getAllSmtpSettings($maskSensitive = true) {
    global $db;
    
    try {
        $result = $db->query("SELECT setting_name, setting_value, is_encrypted, description FROM smtp_settings ORDER BY setting_name");
        $settings = [];
        
        while ($row = $result->fetch_assoc()) {
            // Don't decrypt passwords for display
            if ($row['is_encrypted'] && $row['setting_name'] === 'smtp_password') {
                if ($maskSensitive) {
                    $row['setting_value'] = $row['setting_value'] ? '••••••••' : '';
                } else {
                    $row['setting_value'] = $row['setting_value'] ? decryptSetting($row['setting_value']) : '';
                }
            } elseif ($row['is_encrypted'] && $row['setting_value']) {
                $row['setting_value'] = decryptSetting($row['setting_value']);
            }
            
            $settings[$row['setting_name']] = $row;
        }
        
        return $settings;
    } catch (Exception $e) {
        error_log("SMTP Settings Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Send password reset email - Simplified version using basic PHP mail
 */
function sendPasswordResetEmail($user_email, $user_name, $reset_token) {
    $site_url = function_exists('getSeoSetting') ? getSeoSetting('site_url', 'https://yourdomain.com') : 
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $reset_link = rtrim($site_url, '/') . '/reset-password.php?token=' . $reset_token;
    
    $subject = 'Password Reset - ' . (function_exists('getSiteName') ? getSiteName() : 'Constechzhub');
    $body_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Password Reset</title>
    </head>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #2E294E;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #541388;">Password Reset Request</h2>
            <p>Hello ' . htmlspecialchars($user_name) . ',</p>
            <p>You have requested to reset your password for your ' . (function_exists('getSiteName') ? getSiteName() : 'Constechzhub') . ' account.</p>
            <p>Please click the link below to reset your password:</p>
            <p style="margin: 20px 0;">
                <a href="' . $reset_link . '" 
                   style="background-color: #541388; color: #F1E9DA; padding: 10px 20px; 
                          text-decoration: none; border-radius: 5px; display: inline-block;">
                    Reset My Password
                </a>
            </p>
            <p><strong>This link will expire in 30 minutes.</strong></p>
            <p>If you did not request this password reset, please ignore this email.</p>
            <p>Best regards,<br>' . (function_exists('getSiteName') ? getSiteName() : 'Constechzhub') . ' Team</p>
        </div>
    </body>
    </html>';
    
    $body_text = "Hello {$user_name},\n\nYou requested a password reset for your " . (function_exists('getSiteName') ? getSiteName() : 'Constechzhub') . " account.\n\nReset link: {$reset_link}\n\nThis link will expire in 30 minutes.\nIf you did not request this, you can ignore this email.";
    
    $result = sendEmail($user_email, $subject, $body_html, $body_text, 'password_reset');
    
    if ($result) {
        error_log('Password reset email sent successfully via SMTP/mail chain to: ' . $user_email);
    } else {
        error_log('Password reset email failed to: ' . $user_email);
    }
    
    // Return array format expected by forgot-password.php
    return [
        'success' => $result,
        'message' => $result ? 'Password reset email sent successfully' : 'Failed to send password reset email'
    ];
}

/**
 * Send admin-generated temporary password email
 */
function sendAdminPasswordResetEmail($user_email, $user_name, $temporary_password) {
    $site_url = function_exists('getSeoSetting') ? getSeoSetting('site_url', 'https://yourdomain.com') :
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $login_link = rtrim($site_url, '/') . '/login.php';
    $site_name = function_exists('getSiteName') ? getSiteName() : 'Constechzhub';

    $subject = 'Temporary Password - ' . $site_name;
    $body_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Temporary Password</title>
    </head>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #2E294E;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2 style="color: #541388;">Your Temporary Password</h2>
            <p>Hello ' . htmlspecialchars($user_name) . ',</p>
            <p>An administrator has reset the password for your ' . $site_name . ' account.</p>
            <p>Your temporary password is:</p>
            <p style="margin: 16px 0; padding: 12px; background: #F1E9DA; border-radius: 6px; font-size: 18px; letter-spacing: 1px;">
                <strong>' . htmlspecialchars($temporary_password) . '</strong>
            </p>
            <p>Please sign in using this password and change it immediately from your profile.</p>
            <p style="margin: 20px 0;">
                <a href="' . $login_link . '"
                   style="background-color: #541388; color: #F1E9DA; padding: 10px 20px;
                          text-decoration: none; border-radius: 5px; display: inline-block;">
                    Sign In
                </a>
            </p>
            <p>If you did not request this, please contact support right away.</p>
            <p>Best regards,<br>' . $site_name . ' Team</p>
        </div>
    </body>
    </html>';

    $body_text = "Hello {$user_name},\n\nAn administrator reset the password for your {$site_name} account.\n\nTemporary password: {$temporary_password}\n\nSign in here: {$login_link}\n\nPlease change your password immediately after signing in.\nIf you did not request this, contact support.";

    $result = sendEmail($user_email, $subject, $body_html, $body_text, 'admin_password_reset');

    return [
        'success' => $result,
        'message' => $result ? 'Temporary password email sent successfully' : 'Failed to send temporary password email'
    ];
}


/**
 * Send order confirmation email
 */
function sendOrderConfirmationEmail($customer_email, $customer_name, $order_data) {
    $variables = [
        'customer_name' => $customer_name,
        'order_id' => $order_data['order_id'],
        'network_name' => $order_data['network_name'],
        'package_name' => $order_data['package_name'],
        'phone_number' => $order_data['phone_number'],
        'amount' => number_format($order_data['amount'], 2),
        'status' => $order_data['status']
    ];
    
    return sendTemplatedEmail($customer_email, 'order_confirmation', $variables);
}

/**
 * Send support ticket notification email
 */
function sendSupportTicketEmail($customer_email, $customer_name, $ticket_data) {
    $variables = [
        'customer_name' => $customer_name,
        'ticket_id' => $ticket_data['ticket_id'],
        'subject' => $ticket_data['subject'],
        'status' => $ticket_data['status'],
        'priority' => $ticket_data['priority'],
        'updated_at' => $ticket_data['updated_at'],
        'response_message' => $ticket_data['response_message']
    ];
    
    return sendTemplatedEmail($customer_email, 'support_ticket', $variables);
}
?>
