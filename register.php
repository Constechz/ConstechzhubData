<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['user_role'];
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($role === 'agent') {
        header('Location: agent/dashboard.php');
    } else {
        header('Location: customer/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';
$step = $_GET['step'] ?? '1';

// CSRF token for forms
$csrf_token = generateCSRF();

// Get registration fees
$registration_fees = [];
$result = $db->query("SELECT user_type, fee_amount FROM registration_fees WHERE is_active = TRUE");
while ($row = $result->fetch_assoc()) {
    $registration_fees[$row['user_type']] = $row['fee_amount'];
}
$active_gateway = getActivePaymentGateway();
$gateway_label = $active_gateway === 'moolre' ? 'Moolre' : 'Paystack';

// Get agent referral info from URL or session
$referring_agent_id = null;
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    // Get agent ID from store slug
    $store_slug = sanitize($_GET['ref']);
    $stmt = $db->prepare("SELECT agent_id FROM agent_stores WHERE store_slug = ? AND is_active = TRUE LIMIT 1");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $referring_agent_id = $row['agent_id'];
        $_SESSION['referring_agent_id'] = $referring_agent_id;
    }
} elseif (isset($_SESSION['referring_agent_id'])) {
    $referring_agent_id = $_SESSION['referring_agent_id'];
}

// Handle form submission
if ($_POST) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf)) {
        $error = 'Invalid or expired form token. Please try again.';
    } elseif ($step === '1') {
        // Step 1: Account type selection and basic info
        $account_type = sanitize($_POST['account_type'] ?? '');
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($account_type) || !in_array($account_type, ['customer', 'agent'])) {
            $error = 'Please select a valid account type';
        } elseif (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
            $error = 'Please fill in all required fields';
        } elseif (!validateEmail($email)) {
            $error = 'Please enter a valid email address';
        } elseif (!validatePhone($phone)) {
            $error = 'Please enter a valid phone number';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'An account with this email already exists';
            } else {
                // Store registration data in session
                $_SESSION['registration_data'] = [
                    'account_type' => $account_type,
                    'full_name' => $full_name,
                    'email' => $email,
                    'phone' => formatPhone($phone),
                    'plain_password' => $password,
                    'password' => hashPassword($password)
                ];
                
                // If customer (free), complete registration
                if ($account_type === 'customer') {
                    $success = completeRegistration();
                    if ($success) {
                        header('Location: ' . SITE_URL . '/login.php?registered=1');
                        exit();
                    }
                } else {
                    // Agent registration - proceed to store setup
                    header('Location: register.php?step=2');
                    exit();
                }
            }
        }
    } elseif ($step === '2') {
        // Step 2: Agent store setup and payment
        $store_name = sanitize($_POST['store_name'] ?? '');
        $store_description = sanitize($_POST['store_description'] ?? '');
        
        if (empty($store_name)) {
            $error = 'Please enter a store name';
        } else {
            // Generate store slug
            $store_slug = generateStoreSlug($store_name);
            
            // Check if slug is unique
            $stmt = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ?");
            $stmt->bind_param("s", $store_slug);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'A store with this name already exists. Please choose a different name.';
            } else {
                // Store additional data
                $_SESSION['registration_data']['store_name'] = $store_name;
                $_SESSION['registration_data']['store_slug'] = $store_slug;
                $_SESSION['registration_data']['store_description'] = $store_description;
                
                // Check if payment is required
                $agent_fee = $registration_fees['agent'] ?? 0;
                if ($agent_fee > 0) {
                    // Redirect to payment
                    header('Location: register.php?step=3');
                    exit();
                } else {
                    // Free registration - complete immediately
                    $success = completeRegistration();
                    if ($success) {
                        header('Location: ' . SITE_URL . '/login.php?registered=1');
                        exit();
                    }
                }
            }
        }
    }
}

// Handle payment completion callback (Step 3 -> complete)
if (($_GET['step'] ?? '') === 'complete') {
    $reference = sanitize($_GET['reference'] ?? '');
    if (!$reference && !empty($_SESSION['registration_reference'])) {
        $reference = sanitize($_SESSION['registration_reference']);
    }
    $gateway = sanitize($_GET['gateway'] ?? '');
    $gateway = normalizePaymentGateway($gateway);
    if (!$gateway) {
        $gateway = $active_gateway;
    }

    if (!$reference) {
        setFlashMessage('error', 'Missing payment reference.');
        
        // Ensure session is written before redirect
        session_write_close();
        
        header('Location: register.php?step=3');
        exit();
    }

    try {
        $data = [];
        $paid_amount = 0.0;
        $expected = floatval($registration_fees['agent'] ?? 0);
        $gateway_reference = $reference;

        if ($gateway === 'moolre') {
            $config = getMoolreConfig();
            if (!isMoolreConfigured($config)) {
                throw new Exception('Moolre keys are not configured.');
            }

            $payload = [
                'type' => 1,
                'idtype' => 1,
                'id' => $reference,
                'accountnumber' => $config['account_number']
            ];
            $error = null;
            $result = moolrePostJson('https://api.moolre.com/open/transact/status', $payload, $config, $error);
            if (!$result) {
                throw new Exception($error ?: 'Failed to verify transaction.');
            }

            $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
            if (!$status_ok && empty($result['data'])) {
                throw new Exception($result['message'] ?? 'Failed to verify transaction.');
            }

            $gateway_data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $gateway_status = $gateway_data['status'] ?? $gateway_data['txstatus'] ?? ($result['txstatus'] ?? $result['status'] ?? null);
            $gateway_status = strtolower(trim((string) $gateway_status));
            $is_success = false;
            if (is_numeric($gateway_status)) {
                $is_success = ((int) $gateway_status) === 1;
            } else {
                $is_success = ($gateway_status !== '' && (strpos($gateway_status, 'success') !== false || strpos($gateway_status, 'paid') !== false || strpos($gateway_status, 'complete') !== false || strpos($gateway_status, 'approved') !== false));
            }

            if (!$is_success) {
                setFlashMessage('error', 'Payment not successful.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }

            $paid_amount = (float) ($gateway_data['amount'] ?? $gateway_data['amount_paid'] ?? $gateway_data['paid_amount'] ?? 0);
            $currency = $gateway_data['currency'] ?? '';
            if ($currency && strtoupper($currency) !== strtoupper(CURRENCY_CODE)) {
                setFlashMessage('error', 'Currency mismatch during verification.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }
            if ($paid_amount > 0 && abs($paid_amount - $expected) > 0.01) {
                setFlashMessage('error', 'Amount mismatch during verification.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }

            $paid_amount = $paid_amount > 0 ? $paid_amount : $expected;
            $gateway_reference = $gateway_data['transactid'] ?? $gateway_data['transaction_id'] ?? $gateway_data['reference'] ?? $reference;
            $data = $gateway_data;
        } else {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.paystack.co/transaction/verify/' . $reference,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
                    'Cache-Control: no-cache'
                ),
            ));
            $resp = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if ($err) { throw new Exception('cURL error: ' . $err); }
            $result = json_decode($resp, true);
            if (isset($_GET['debug'])) {
                header('Content-Type: text/plain');
                echo "=== DEBUG: Paystack Verify Raw Response ===\n";
                echo $resp . "\n\n";
            }
            if (!$result || !($result['status'] ?? false)) {
                if (isset($_GET['debug'])) {
                    echo "Verification decode failed or status=false\n";
                    echo "Reference: $reference\n";
                    exit();
                }
                throw new Exception('Failed to verify transaction.');
            }

            $data = $result['data'];
            if (($data['status'] ?? '') !== 'success') {
                if (isset($_GET['debug'])) {
                    echo "Status: " . ($data['status'] ?? 'undefined') . "\n";
                    echo "Gateway response: " . ($data['gateway_response'] ?? '') . "\n";
                    exit();
                }
                setFlashMessage('error', 'Payment not successful.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }

            $paid_amount = ($data['requested_amount'] ?? ($data['amount'] ?? 0)) / 100.0;
            $currency = $data['currency'] ?? '';
            if (strtoupper($currency) !== strtoupper(CURRENCY_CODE)) {
                if (isset($_GET['debug'])) {
                    echo "Currency mismatch: got $currency expected " . CURRENCY_CODE . "\n";
                    exit();
                }
                setFlashMessage('error', 'Currency mismatch during verification.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }
            if (abs($paid_amount - $expected) > 0.01) {
                if (isset($_GET['debug'])) {
                    echo "Paid (requested_amount): $paid_amount\nExpected: $expected\nCurrency: $currency\nRaw amount (incl fees): " . (($data['amount'] ?? 0) / 100.0) . "\n";
                    exit();
                }
                setFlashMessage('error', 'Amount mismatch during verification.');
                session_write_close();
                header('Location: register.php?step=3');
                exit();
            }
        }

        // If session registration data is missing, try reconstructing from metadata
        if (empty($_SESSION['registration_data'])) {
            $md = $data['metadata'] ?? [];
            if (($md['purpose'] ?? '') === 'agent_registration') {
                $_SESSION['registration_data'] = [
                    'account_type' => $md['account_type'] ?? 'agent',
                    'full_name' => $md['full_name'] ?? '',
                    'email' => $md['email'] ?? ($data['customer']['email'] ?? ''),
                    'phone' => $md['phone'] ?? '',
                    'plain_password' => $md['plain_password'] ?? null,
                    // password is assumed hashed already as stored during init
                    'password' => $md['password'] ?? '',
                    'store_name' => $md['store_name'] ?? null,
                    'store_slug' => $md['store_slug'] ?? null,
                    'store_description' => $md['store_description'] ?? ''
                ];
                if (isset($_GET['debug'])) {
                    echo "Reconstructed session from metadata.\n";
                    print_r($_SESSION['registration_data']);
                    echo "\n";
                }
            } else {
                if (isset($_GET['debug'])) {
                    echo "Missing registration_data and metadata not usable.\n";
                    print_r($md);
                    exit();
                }
                setFlashMessage('error', 'Session expired and could not reconstruct registration. Please start again.');
                
                // Ensure session is written before redirect
                session_write_close();
                
                header('Location: register.php');
                exit();
            }
        }

        // Cache registration data before completion (function clears session)
        $reg_cache = $_SESSION['registration_data'] ?? null;

        // Complete registration (create user, wallet, store)
        if (isset($_GET['debug'])) {
            echo "About to completeRegistration()...\n";
        }
        $reg_ok = completeRegistration();
        if (!$reg_ok) {
            if (isset($_GET['debug'])) {
                echo "completeRegistration() returned false.\n";
                exit();
            }
            setFlashMessage('error', 'Could not finalize your registration.');
            
            // Ensure session is written before redirect
            session_write_close();
            
            header('Location: register.php?step=1');
            exit();
        }

        // Fetch the newly created user by email using cached data
        $email = $reg_cache['email'] ?? null;
        // If missing, still proceed
        if (!$email) {
            // Fallback: get last inserted by email stored before completeRegistration
            // In practice, we stored email above; if missing, redirect with success anyway
            setFlashMessage('success', 'Registration complete. You can now sign in.');
            header('Location: ' . SITE_URL . '/login.php?registered=1');
            exit();
        }

        // Get user id
        $stmt = $db->prepare('SELECT id, role FROM users WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        // Optionally activate agent immediately after successful payment
        if ($user && $user['role'] === 'agent') {
            // If an account_activation_status column exists, activate it; ignore if not
            try {
                $db->query("UPDATE users SET account_activation_status = 'active' WHERE id = " . intval($user['id']));
            } catch (Exception $e) {
                // Column may not exist; continue silently
            }

            // Record the registration fee as a transaction
            $tx_ref = generateReference('REG');
            $description = 'Agent registration fee via ' . $gateway_label;
            $payment_method = $gateway;
            $tx_type = 'purchase';
            $status = 'success';

            $stmt = $db->prepare('INSERT INTO transactions (user_id, transaction_type, amount, currency, status, reference, payment_method, paystack_reference, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $currency = 'GHS';
            $stmt->bind_param('isdssssss', $user['id'], $tx_type, $paid_amount, $currency, $status, $tx_ref, $payment_method, $gateway_reference, $description);
            $stmt->execute();

            // Log activity
            logActivity($user['id'], 'registration_payment_success', $gateway_label . ' ref ' . $gateway_reference);
        }

        // Clear stored reference on success
        unset($_SESSION['registration_reference']);
        if (isset($_GET['debug'])) {
            echo "Success. Redirecting to login...\n";
            exit();
        }
        setFlashMessage('success', 'Registration complete. You can now sign in.');
        
        // Ensure session is written before redirect
        session_write_close();
        
        header('Location: ' . SITE_URL . '/login.php?registered=1');
        exit();

    } catch (Exception $ex) {
        error_log('Registration verify error: ' . $ex->getMessage());
        if (isset($_GET['debug'])) {
            header('Content-Type: text/plain');
            echo "Exception: " . $ex->getMessage() . "\n";
            echo isset($resp) ? $resp : '';
            exit();
        }
        setFlashMessage('error', 'Payment verification failed: ' . $ex->getMessage());
        
        // Ensure session is written before redirect
        session_write_close();
        
        header('Location: register.php?step=3');
        exit();
    }
}

function completeRegistration() {
    global $db;
    
    if (!isset($_SESSION['registration_data'])) {
        return false;
    }
    
    $data = $_SESSION['registration_data'];
    
    try {
        $db->getConnection()->begin_transaction();
        
        // Create user account
        $username = generateUsername($data['email']);
        $activation_status = ($data['account_type'] === 'customer') ? 'active' : 'pending';
        
        $store_name = $data['store_name'] ?? null;
        $store_slug = $data['store_slug'] ?? null;
        $email_val = $data['email'];
        $password_hash = $data['password'];
        $full_name_val = $data['full_name'];
        $phone_val = $data['phone'];
        $role_val = $data['account_type'];

        $referring_agent_id = $_SESSION['referring_agent_id'] ?? null;
        
        $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, store_name, store_slug, referring_agent_id) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssi",
            $username,
            $email_val,
            $password_hash,
            $full_name_val,
            $phone_val,
            $role_val,
            $activation_status,
            $store_name,
            $store_slug,
            $referring_agent_id
        );
        $stmt->execute();
        
        $user_id = $db->getConnection()->insert_id;
        
        // Create wallet
        $stmt = $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // If agent, create store
        if ($data['account_type'] === 'agent' && isset($data['store_name'])) {
            $store_description = $data['store_description'] ?? '';
            $stmt = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, store_description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $store_name, $store_slug, $store_description);
            $stmt->execute();
        }
        
        $db->getConnection()->commit();

        // Notify user via SMS/email with their credentials (when enabled)
        try {
            $brandName = $data['store_name'] ?? getSiteName();
            sendRegistrationCredentialsNotification([
                'full_name' => $full_name_val,
                'email' => $email_val,
                'phone' => $phone_val,
                'username' => $username,
                'plain_password' => $data['plain_password'] ?? '',
                'brand' => $brandName
            ], $user_id);
        } catch (Exception $e) {
            error_log('Registration notification failed: ' . $e->getMessage());
        }

        unset($_SESSION['registration_data']);
        return true;
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        return false;
    }
}

function generateUsername($email) {
    $base = explode('@', $email)[0];
    $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
    return strtolower($base) . rand(100, 999);
}

function generateStoreSlug($store_name) {
    $slug = strtolower(trim($store_name));
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    return trim($slug, '-');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php echo generateSeoMeta('Register', 'Create your Constechzhub account to start purchasing affordable data bundles and mobile services.'); ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Work+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#6366f1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
</head>
<body class="register-body">
    <div class="register-shell">
        <main class="register-panel">
            <div class="register-card">
                <div class="register-topbar">
                    <span class="eyebrow">Get started</span>
                    <button type="button" class="theme-toggle-btn" onclick="toggleRegisterTheme()" aria-label="Toggle theme">
                        <i id="theme-icon" class="fas fa-moon"></i>
                    </button>
                </div>
                <div class="card-header">
                    <h2>
                        <?php if ($step === '1'): ?>
                            Create your account
                        <?php elseif ($step === '2'): ?>
                            Set up your store
                        <?php else: ?>
                            Confirm payment
                        <?php endif; ?>
                    </h2>
                    <p class="subtitle">
                        <?php if ($step === '1'): ?>
                            Choose the account type that fits your journey.
                        <?php elseif ($step === '2'): ?>
                            Give your store a name customers will remember.
                        <?php else: ?>
                            Complete your registration and go live.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="stepper">
                    <div class="stepper-item <?php echo $step >= '1' ? 'active' : ''; ?>">
                        <span>1</span> Account
                    </div>
                    <div class="stepper-item <?php echo $step >= '2' ? 'active' : ''; ?>">
                        <span>2</span> Store
                    </div>
                    <div class="stepper-item <?php echo $step >= '3' ? 'active' : ''; ?>">
                        <span>3</span> Payment
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($step === '1'): ?>
                    <form method="POST" action="" class="register-form">
                        <div class="form-section">
                            <p class="form-label">Account Type *</p>
                            <div class="account-type-selection">
                                <div class="account-option">
                                    <input type="radio" id="customer" name="account_type" value="customer" required>
                                    <label for="customer" class="account-card">
                                        <div class="account-header">
                                            <span class="account-icon"><i class="fas fa-user"></i></span>
                                            <span class="account-title">Customer</span>
                                        </div>
                                        <p>Buy data bundles for personal use.</p>
                                        <div class="price">FREE</div>
                                    </label>
                                </div>
                                
                                <div class="account-option">
                                    <input type="radio" id="agent" name="account_type" value="agent" required>
                                    <label for="agent" class="account-card highlight">
                                        <div class="account-header">
                                            <span class="account-icon"><i class="fas fa-store"></i></span>
                                            <span class="account-title">Agent</span>
                                        </div>
                                        <p>Sell bundles with your own branded storefront.</p>
                                        <div class="price"><?php echo formatCurrency($registration_fees['agent'] ?? 0); ?></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" required>
                            </div>
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="phone" class="form-label">Phone Number *</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required>
                            </div>
                            <div class="form-group">
                                <label for="password" class="form-label">Password *</label>
                                <div class="password-input-wrapper">
                                    <input type="password" class="form-control" id="password" name="password" required>
                                    <button type="button" class="password-toggle" data-target="password" aria-label="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="form-label">Confirm Password *</label>
                            <div class="password-input-wrapper">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            Continue
                        </button>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <p class="helper-text">By continuing, you agree to our terms and privacy policy.</p>
                    </form>

                <?php elseif ($step === '2'): ?>
                    <form method="POST" action="" class="register-form">
                        <div class="form-group">
                            <label for="store_name" class="form-label">Store Name *</label>
                            <input type="text" class="form-control" id="store_name" name="store_name" required>
                            <small class="form-text">This will be used for your store URL.</small>
                        </div>

                        <div class="form-group">
                            <label for="store_description" class="form-label">Store Description</label>
                            <textarea class="form-control" id="store_description" name="store_description" rows="3"></textarea>
                        </div>

                        <div class="store-link-card">
                            <div class="store-link-header">
                                <div>
                                    <div class="preview-label">Your store link</div>
                                    <div class="preview-title">Ready to share</div>
                                </div>
                                <span class="preview-pill">Auto-generated</span>
                            </div>
                            <div class="store-link-body">
                                <div class="store-link">
                                    <span class="link-base"><?php echo SITE_URL; ?>/store/</span>
                                    <span id="store-slug-preview" class="link-slug">your-store-name</span>
                                </div>
                                <button type="button" class="btn btn-outline btn-link-copy" onclick="copyStoreLink()">Copy link</button>
                            </div>
                            <div class="store-link-status" id="store-link-status">Your link updates automatically as you type.</div>
                        </div>

                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <button type="submit" class="btn btn-primary">
                            Continue to Payment
                        </button>
                    </form>
                    
                <?php else: ?>
                    <div class="payment-summary">
                        <div class="summary-title">Payment Summary</div>
                        <div class="summary-item">
                            <span>Agent Registration Fee</span>
                            <span><?php echo formatCurrency($registration_fees['agent'] ?? 0); ?></span>
                        </div>
                        <div class="summary-total">
                            <span>Total</span>
                            <span><?php echo formatCurrency($registration_fees['agent'] ?? 0); ?></span>
                        </div>
                    </div>
                    
                    <?php if (($registration_fees['agent'] ?? 0) > 0): ?>
                        <button type="button" class="btn btn-primary" onclick="initiatePayment()">
                            Pay with <?php echo htmlspecialchars($gateway_label); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" onclick="initiatePayment()">
                            Complete Registration
                        </button>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['registration_reference'])): ?>
                        <div class="text-center" style="margin-top: .75rem;">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='register.php?step=complete&gateway=<?php echo htmlspecialchars($active_gateway); ?>&reference=<?php echo urlencode($_SESSION['registration_reference']); ?>'">
                                I've completed payment - finalize registration
                            </button>
                            <div style="margin-top:.5rem;">
                                <a href="register.php?step=complete&gateway=<?php echo htmlspecialchars($active_gateway); ?>&reference=<?php echo urlencode($_SESSION['registration_reference']); ?>" class="text-primary">Click here to finish</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-top: 1rem;">
                        <label for="manual_ref" class="form-label">Enter <?php echo htmlspecialchars($gateway_label); ?> Reference (fallback)</label>
                        <div class="inline-field">
                            <input type="text" id="manual_ref" class="form-control" placeholder="e.g. REG_XXXXXXXX">
                            <button type="button" class="btn btn-outline" onclick="manualComplete()">Finish</button>
                        </div>
                        <small class="form-text">Use this if you have the reference from <?php echo htmlspecialchars($gateway_label); ?> but the page did not redirect.</small>
                    </div>
                <?php endif; ?>
                
                <div class="footer-note">
                    Already have an account? <a href="login.php" class="text-primary">Sign in</a>
                </div>
            </div>
        </main>
    </div>
    
    <?php if ($active_gateway === 'paystack'): ?>
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <?php endif; ?>
    <script>
        function updateRegisterThemeIcon(theme) {
            var icon = document.getElementById('theme-icon');
            if (!icon) {
                return;
            }
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }

        function initRegisterTheme() {
            var savedTheme = localStorage.getItem('theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = savedTheme || (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
            updateRegisterThemeIcon(theme);
        }

        function toggleRegisterTheme() {
            var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', nextTheme);
            localStorage.setItem('theme', nextTheme);
            updateRegisterThemeIcon(nextTheme);
        }

        initRegisterTheme();

        // Store name to URL preview
        function setStorePreviewSlug(slug) {
            var slugPreview = document.getElementById('store-slug-preview');
            if (slugPreview) {
                slugPreview.textContent = slug || 'your-store-name';
            }
        }

        document.getElementById('store_name')?.addEventListener('input', function() {
            var storeName = this.value.toLowerCase()
                .replace(/[^a-z0-9]/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
            setStorePreviewSlug(storeName);
        });

        function copyStoreLink() {
            var base = '<?php echo SITE_URL; ?>/store/';
            var slugPreview = document.getElementById('store-slug-preview');
            var slug = slugPreview ? slugPreview.textContent.trim() : 'your-store-name';
            var fullLink = base + (slug || 'your-store-name');
            var status = document.getElementById('store-link-status');

            var showStatus = function(message) {
                if (!status) {
                    return;
                }
                status.textContent = message;
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(fullLink).then(function() {
                    showStatus('Link copied to clipboard.');
                }).catch(function() {
                    showStatus('Copy failed. Select the link and copy.');
                });
            } else {
                var temp = document.createElement('input');
                temp.value = fullLink;
                document.body.appendChild(temp);
                temp.select();
                try {
                    document.execCommand('copy');
                    showStatus('Link copied to clipboard.');
                } catch (err) {
                    showStatus('Copy failed. Select the link and copy.');
                }
                document.body.removeChild(temp);
            }
        }
        
        // Payment integration (server-side init for reliable redirect)
        function initiatePayment() {
            fetch('api/register_init.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.data?.authorization_url) {
                        // Redirect to gateway for payment
                        window.location.href = data.data.authorization_url;
                    } else if (data.data?.redirect_url) {
                        // Free registration - redirect to login
                        window.location.href = data.data.redirect_url;
                    } else {
                        alert('Unexpected response format');
                    }
                } else {
                    alert('Failed to process registration: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error processing registration: ' + err);
            });
        }

        function manualComplete() {
            var ref = document.getElementById('manual_ref').value.trim();
            if (!ref) { alert('Enter a reference'); return; }
            window.location.href = 'register.php?step=complete&gateway=<?php echo htmlspecialchars($active_gateway); ?>&reference=' + encodeURIComponent(ref) + '&debug=1';
        }
        
        // PWA service worker registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo htmlspecialchars(dbh_asset('sw.js'), ENT_QUOTES, 'UTF-8'); ?>')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch(function(err) {
                        console.log('ServiceWorker registration failed: ', err);
                    });
            });
        }
    </script>
    
    <!-- PWA Installation Manager -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/pwa-install.js')); ?>"></script>
    
    <style>
        :root {
            --reg-ink: var(--text-primary);
            --reg-muted: var(--text-secondary);
            --reg-border: var(--border-color);
            --reg-accent: var(--brand-primary);
            --reg-accent-dark: var(--brand-secondary);
            --reg-accent-soft: rgba(139, 92, 246, 0.12);
            --reg-bg: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            --reg-card: var(--bg-primary);
            --reg-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
            --reg-bg-sheen-one: rgba(255, 255, 255, 0.16);
            --reg-bg-sheen-two: rgba(255, 255, 255, 0.08);
        }

        .register-body {
            font-family: "Work Sans", "Segoe UI", sans-serif;
            color: var(--reg-ink);
            background: radial-gradient(circle at top right, var(--reg-bg-sheen-one), transparent 55%),
                radial-gradient(circle at 20% 20%, var(--reg-bg-sheen-two), transparent 48%),
                var(--reg-bg);
            min-height: 100vh;
            margin: 0;
        }

        [data-theme="dark"] {
            --reg-ink: var(--text-primary);
            --reg-muted: var(--text-secondary);
            --reg-border: var(--border-color);
            --reg-accent: var(--brand-primary);
            --reg-accent-dark: var(--brand-secondary);
            --reg-accent-soft: rgba(139, 92, 246, 0.2);
            --reg-bg: linear-gradient(135deg, var(--brand-primary) 0%, #4338ca 100%);
            --reg-card: var(--bg-secondary);
            --reg-shadow: 0 24px 60px rgba(2, 6, 23, 0.6);
            --reg-bg-sheen-one: rgba(255, 255, 255, 0.08);
            --reg-bg-sheen-two: rgba(255, 255, 255, 0.05);
        }

        .register-shell {
            width: min(100%, 640px);
            margin: 0 auto;
            display: block;
            padding: clamp(1.5rem, 4vw, 3.5rem);
            min-height: 100vh;
            box-sizing: border-box;
        }

        .brand-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-family: "Space Grotesk", "Work Sans", sans-serif;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .register-panel {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .register-card {
            width: min(100%, 520px);
            background: var(--reg-card);
            border-radius: 24px;
            padding: clamp(1.8rem, 4vw, 2.6rem);
            box-shadow: var(--reg-shadow);
            border: 1px solid var(--reg-border);
            animation: riseIn 0.6s ease;
        }

        .register-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .theme-toggle-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid var(--reg-border);
            background: var(--bg-secondary);
            color: var(--reg-ink);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .theme-toggle-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        }

        [data-theme="dark"] .theme-toggle-btn {
            background: var(--bg-secondary);
            box-shadow: none;
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-header h2 {
            font-family: "Space Grotesk", "Work Sans", sans-serif;
            margin: 0.4rem 0 0.4rem;
        }

        .eyebrow {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.2rem;
            color: var(--reg-muted);
            font-weight: 600;
        }

        .subtitle {
            margin: 0;
            color: var(--reg-muted);
        }

        .stepper {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.5rem;
            margin: 1.4rem 0 1.2rem;
        }

        .stepper-item {
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.4rem 0.6rem;
            border-radius: 999px;
            background: var(--bg-secondary);
            color: var(--reg-muted);
            font-weight: 600;
        }

        .stepper-item span {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            display: grid;
            place-items: center;
            font-size: 0.75rem;
        }

        [data-theme="dark"] .stepper-item {
            background: var(--bg-tertiary);
            color: var(--reg-muted);
        }

        [data-theme="dark"] .stepper-item span {
            background: var(--bg-primary);
        }

        .stepper-item.active {
            background: var(--reg-accent-soft);
            color: var(--reg-accent-dark);
        }

        .stepper-item.active span {
            background: var(--reg-accent);
            color: #fff;
        }

        .register-form {
            display: grid;
            gap: 1.1rem;
        }

        .form-grid {
            display: grid;
            gap: 1rem;
        }

        .form-grid.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .form-group {
            display: grid;
            gap: 0.4rem;
        }

        .form-label {
            font-weight: 600;
        }

        .register-panel .form-control,
        .register-panel textarea {
            border-radius: 12px;
            border: 1px solid var(--reg-border);
            padding: 0.75rem 0.9rem;
            background: var(--bg-primary);
            color: var(--reg-ink);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .register-panel .form-control:focus,
        .register-panel textarea:focus {
            border-color: var(--reg-accent);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
            outline: none;
            background: var(--bg-primary);
        }

        [data-theme="dark"] .register-panel .form-control,
        [data-theme="dark"] .register-panel textarea {
            background: var(--bg-tertiary);
            color: var(--reg-ink);
            border-color: var(--reg-border);
        }

        [data-theme="dark"] .register-panel .form-control::placeholder,
        [data-theme="dark"] .register-panel textarea::placeholder {
            color: #6b7280;
        }

        [data-theme="dark"] .register-panel .form-control:focus,
        [data-theme="dark"] .register-panel textarea:focus {
            background: var(--bg-tertiary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.25);
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-input-wrapper .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--reg-muted);
            cursor: pointer;
        }

        .account-type-selection {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .account-option input[type="radio"] {
            display: none;
        }

        .account-card {
            display: grid;
            gap: 0.75rem;
            border-radius: 18px;
            border: 1px solid var(--reg-border);
            padding: 1.1rem;
            background: var(--bg-primary);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }

        .account-card.highlight {
            background: linear-gradient(160deg, rgba(139, 92, 246, 0.14), rgba(255, 255, 255, 0.78));
        }

        [data-theme="dark"] .account-card {
            background: var(--bg-tertiary);
        }

        [data-theme="dark"] .account-card.highlight {
            background: linear-gradient(160deg, rgba(139, 92, 246, 0.24), rgba(15, 23, 42, 0.88));
        }

        .account-card:hover,
        .account-option input[type="radio"]:checked + .account-card {
            border-color: var(--reg-accent);
            box-shadow: 0 14px 30px rgba(139, 92, 246, 0.16);
            transform: translateY(-2px);
        }

        .account-header {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .account-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: var(--reg-accent-soft);
            color: var(--reg-accent);
        }

        .account-title {
            font-weight: 700;
            font-size: 1rem;
        }

        .account-card p {
            margin: 0;
            color: var(--reg-muted);
            font-size: 0.9rem;
        }

        .price {
            font-weight: 700;
            color: var(--reg-accent-dark);
            font-size: 1rem;
        }

        .store-link-card {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.08), rgba(167, 139, 250, 0.12));
            border-radius: 18px;
            padding: 1.1rem;
            display: grid;
            gap: 0.75rem;
            border: 1px solid var(--reg-border);
        }

        .store-link-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .preview-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12rem;
            color: var(--reg-muted);
            font-weight: 600;
        }

        .preview-title {
            font-weight: 700;
            font-size: 1rem;
            margin-top: 0.25rem;
        }

        .preview-pill {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: var(--reg-accent-soft);
            color: var(--reg-accent-dark);
            text-transform: uppercase;
            letter-spacing: 0.08rem;
        }

        .store-link-body {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .store-link {
            font-family: "Space Grotesk", "Work Sans", sans-serif;
            background: var(--bg-primary);
            border-radius: 12px;
            border: 1px dashed var(--reg-border);
            padding: 0.65rem 0.9rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.25rem;
            flex: 1 1 240px;
            min-width: 0;
        }

        .link-base {
            color: var(--reg-muted);
            font-size: 0.95rem;
        }

        .link-slug {
            padding: 0.1rem 0.4rem;
            border-radius: 8px;
            background: var(--reg-accent-soft);
            color: var(--reg-accent-dark);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .btn-link-copy {
            width: auto;
            min-width: 140px;
            border-radius: 12px;
            white-space: nowrap;
        }

        .store-link-status {
            font-size: 0.82rem;
            color: var(--reg-muted);
        }

        [data-theme="dark"] .store-link-card {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.16), rgba(167, 139, 250, 0.14));
        }

        [data-theme="dark"] .store-link {
            background: var(--bg-primary);
        }

        .payment-summary {
            background: var(--bg-secondary);
            padding: 1.4rem;
            border-radius: 16px;
            border: 1px solid var(--reg-border);
            display: grid;
            gap: 0.6rem;
        }

        [data-theme="dark"] .payment-summary {
            background: var(--bg-tertiary);
        }

        .summary-title {
            font-weight: 700;
        }

        .summary-item,
        .summary-total {
            display: flex;
            justify-content: space-between;
        }

        .summary-total {
            border-top: 1px solid var(--reg-border);
            padding-top: 0.6rem;
            font-weight: 700;
        }

        .inline-field {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 0.6rem;
            align-items: center;
        }

        .helper-text {
            font-size: 0.82rem;
            color: var(--reg-muted);
            margin: 0;
        }

        .footer-note {
            margin-top: 1.4rem;
            text-align: center;
            color: var(--reg-muted);
        }

        .register-panel .btn {
            width: 100%;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .register-panel .btn:hover {
            transform: translateY(-1px);
        }

        .register-panel .btn-primary {
            background: var(--brand-primary);
            color: #fff;
            box-shadow: 0 14px 26px rgba(139, 92, 246, 0.22);
        }

        .register-panel .btn-primary:hover {
            background: var(--brand-secondary);
        }

        .register-panel .btn-secondary {
            background: var(--bg-secondary);
            color: var(--reg-ink);
        }

        .register-panel .btn-outline {
            background: transparent;
            border: 1px solid var(--reg-border);
            color: var(--reg-ink);
        }

        [data-theme="dark"] .register-panel .btn-secondary {
            background: var(--bg-tertiary);
        }

        .text-primary {
            color: var(--reg-accent);
        }

        @media (max-width: 980px) {
            .register-shell {
                width: min(100%, 640px);
            }
        }

        @media (max-width: 720px) {
            .account-type-selection {
                grid-template-columns: 1fr;
            }

            .form-grid.two {
                grid-template-columns: 1fr;
            }

            .inline-field {
                grid-template-columns: 1fr;
            }

            .store-link-body {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-link-copy {
                width: 100%;
            }
        }
    </style>
    
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo htmlspecialchars(dbh_asset('sw.js'), ENT_QUOTES, 'UTF-8'); ?>')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch(function(err) {
                        console.log('ServiceWorker registration failed: ', err);
                    });
            });
        }
    </script>
</body>
</html>
