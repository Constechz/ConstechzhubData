<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

function dbh_json_response($status, $message, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $data));
    exit();
}

function dbh_safe_redirect_path($path) {
    if (empty($path)) {
        return null;
    }
    // Reject absolute URLs to avoid open redirects
    if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
        return null;
    }
    $path = trim($path);
    if (strpos($path, '/') !== 0) {
        return null;
    }
    if (strpos($path, '//') !== false) {
        return null;
    }
    return $path;
}

function dbh_verify_google_token($idToken) {
    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ch = curl_init($verifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        throw new Exception('Could not verify Google token: ' . $err);
    }
    $payload = json_decode($resp, true);
    if (!$payload || $httpCode !== 200) {
        throw new Exception('Google token verification failed.');
    }
    return $payload;
}

function dbh_google_generate_username($email) {
    $base = explode('@', $email)[0];
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
    if (!$base) {
        $base = 'user';
    }
    return strtolower($base) . rand(100, 999);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dbh_json_response('error', 'Method not allowed', [], 405);
}

if (empty(GOOGLE_CLIENT_ID)) {
    dbh_json_response('error', 'Google Sign-In is not configured.', [], 400);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $idToken = trim($input['credential'] ?? $input['id_token'] ?? '');
    $requestedRedirect = trim($input['redirect'] ?? '');

    if (!$idToken) {
        dbh_json_response('error', 'Missing Google credential.', [], 400);
    }

    $payload = dbh_verify_google_token($idToken);

    // Validate audience and issuer
    $aud = $payload['aud'] ?? '';
    if ($aud !== GOOGLE_CLIENT_ID) {
        dbh_json_response('error', 'Invalid Google credential (audience mismatch).', [], 400);
    }
    $iss = $payload['iss'] ?? '';
    $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
    if (!in_array($iss, $validIssuers, true)) {
        dbh_json_response('error', 'Invalid Google credential (issuer mismatch).', [], 400);
    }

    $email = $payload['email'] ?? '';
    if (!$email) {
        dbh_json_response('error', 'Google account email is required.', [], 400);
    }
    $emailVerified = $payload['email_verified'] ?? null;
    if ($emailVerified !== null && !filter_var($emailVerified, FILTER_VALIDATE_BOOLEAN)) {
        dbh_json_response('error', 'Google email must be verified to sign in.', [], 400);
    }

    $fullName = trim($payload['name'] ?? '');
    if (!$fullName) {
        $fullName = trim(($payload['given_name'] ?? '') . ' ' . ($payload['family_name'] ?? ''));
    }
    if (!$fullName) {
        $fullName = 'Google User';
    }

    $role = 'customer';
    $redirectPath = dbh_safe_redirect_path($requestedRedirect);

    // See if the user already exists
    $stmt = $db->prepare("SELECT id, username, email, full_name, role, status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        if ($existing['status'] !== 'active') {
            dbh_json_response('error', 'Your account is not active. Please contact support.', [], 403);
        }

        if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'email_verified')) {
            $stmt = $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $existing['id']);
                $stmt->execute();
            }
        }

        $_SESSION['user_id'] = $existing['id'];
        $_SESSION['username'] = $existing['username'];
        $_SESSION['email'] = $existing['email'];
        $_SESSION['full_name'] = $existing['full_name'];
        setSessionUserRole($existing['role']);

        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->bind_param("i", $existing['id']);
        $stmt->execute();

        logActivity($existing['id'], 'login', 'Logged in with Google');

        $role = normalizeUserRole($existing['role']);
    } else {
        // Create a new customer account
        $username = dbh_google_generate_username($email);
        $passwordHash = hashPassword(bin2hex(random_bytes(12))); // random placeholder
        $phone = '';

        $conn = $db->getConnection();
        $conn->begin_transaction();
        try {
            if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'email_verified')) {
                $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, email_verified) VALUES (?, ?, ?, ?, ?, ?, 'active', 'active', 1)");
                $stmt->bind_param("ssssss", $username, $email, $passwordHash, $fullName, $phone, $role);
            } else {
                $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status) VALUES (?, ?, ?, ?, ?, ?, 'active', 'active')");
                $stmt->bind_param("ssssss", $username, $email, $passwordHash, $fullName, $phone, $role);
            }
            $stmt->execute();

            $userId = $conn->insert_id;

            $stmt = $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
            $stmt->bind_param("i", $userId);
            $stmt->execute();

            $conn->commit();

            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['full_name'] = $fullName;
            setSessionUserRole($role);

            logActivity($userId, 'registration_google', 'Account created via Google Sign-In');
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    // Build redirect URL
    if ($role === 'admin') {
        $redirectUrl = SITE_URL . '/admin/dashboard.php';
    } elseif ($role === 'agent') {
        $redirectUrl = SITE_URL . '/agent/dashboard.php';
    } else {
        $redirectUrl = SITE_URL . '/customer/dashboard.php';
        if ($redirectPath) {
            $redirectUrl = SITE_URL . $redirectPath;
        }
    }

    dbh_json_response('success', 'Login successful', ['redirect' => $redirectUrl]);
} catch (Exception $e) {
    error_log('Google login error: ' . $e->getMessage());
    dbh_json_response('error', 'Google login failed: ' . $e->getMessage(), [], 400);
}
