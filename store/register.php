<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mnotify_sms.php';
require_once __DIR__ . '/../includes/email.php';

// Get store slug
if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = $_GET['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

// Fetch store + agent info for branding
$stmt = $db->prepare("
    SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.account_activation_status = 'active'
");
$stmt->bind_param("s", $store_slug);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}
$store = $res->fetch_assoc();

// Send welcome email + SMS after successful registration
function dbh_send_welcome_notifications($full_name, $email, $phone, $store_name, $username = null, $plain_password = null) {
    $brand = $store_name ?: SITE_NAME;
    try {
        sendRegistrationCredentialsNotification([
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'username' => $username,
            'plain_password' => $plain_password,
            'brand' => $brand
        ]);
    } catch (Exception $e) {
        error_log('[register] Welcome notification failed: ' . $e->getMessage());
    }
}

// If already logged in, send to customer dashboard within this store
if (isLoggedIn()) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/customer/dashboard.php?store=' . urlencode($store_slug));
    exit();
}

$error = '';
$success = '';

if ($_POST) {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$full_name || !$email || !$phone || !$password || !$confirm_password) {
        $error = 'Please fill in all required fields';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!validatePhone($phone)) {
        $error = 'Please enter a valid phone number';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Check if email exists
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists';
        } else {
            // Create customer user + wallet
            try {
                $conn = $db->getConnection();
                $conn->begin_transaction();

                $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0])) . rand(100, 999);
                $password_hash = hashPassword($password);
                $status = 'active';
                $role = 'customer';
                $activation_status = 'active';
                $phone_norm = formatPhone($phone);

                // Determine if users.agent_id exists; insert accordingly
                $agent_id = (int)$store['agent_id'];
                $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM users LIKE 'agent_id'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    // Column exists: link this customer to the store's agent via agent_id
                    $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('ssssssssi', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status, $agent_id);
                } else {
                    // Column missing: insert without agent_id (fallback). Referral will still be recorded below.
                    $stmt = $db->prepare('INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('ssssssss', $username, $email, $password_hash, $full_name, $phone_norm, $role, $status, $activation_status);
                }
                $stmt->execute();
                $user_id = $conn->insert_id;

                // Do not populate users.store_slug/store_name to avoid UNIQUE constraint collisions.

                $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
                $stmt->bind_param('i', $user_id);
                $stmt->execute();

                // Optional: record referral to this store/agent
                try {
                    $agent_id = (int)$store['agent_id'];
                    $stmt = $db->prepare('INSERT INTO user_referrals (user_id, agent_id, source, created_at) VALUES (?, ?, ?, NOW())');
                    $source = 'store_signup';
                    $stmt->bind_param('iis', $user_id, $agent_id, $source);
                    $stmt->execute();
                } catch (Exception $e) {
                    // table might not exist; ignore
                }

                // Check if OTP verification is required
                $otp_required = true; // Default to requiring OTP
                
                // Check system setting for OTP requirement
                $stmt = $db->prepare("SELECT COUNT(*) as has_sms FROM sms_settings WHERE provider = 'arkesel' AND is_active = TRUE");
                $stmt->execute();
                $sms_check = $stmt->get_result()->fetch_assoc();
                $otp_required = $sms_check['has_sms'] > 0;
                
                if ($otp_required) {
                    // Store registration data in session for OTP verification
                    $_SESSION['pending_registration'] = [
                        'username' => $username,
                        'email' => $email,
                        'full_name' => $full_name,
                        'phone' => $phone,
                        'password' => $password_hash,
                        'plain_password' => $password,
                        'role' => $role,
                        'agent_id' => $agent_id,
                        'store_slug' => $store_slug,
                        'redirect' => '/customer/dashboard.php?store=' . $store_slug
                    ];
                    
                    // Send OTP
                    require_once '../includes/arkesel.php';
                    try {
                        $sms = new ArkeselSMS();
                        $response = $sms->sendOTP($phone, 'signup');
                        
                        if ($response['success']) {
                            header('Location: otp-verify.php?phone=' . urlencode($phone) . '&purpose=signup' . 
                                   (!empty($store_slug) ? '&store=' . urlencode($store_slug) : ''));
                            exit();
                        } else {
                            $error = 'Failed to send verification code. Please try again.';
                        }
                    } catch (Exception $e) {
                        error_log("Registration OTP error: " . $e->getMessage());
                        $error = 'SMS service unavailable. Please try again later.';
                    }
                } else {
                    // OTP not required: finalize using the already-created user + wallet.
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['full_name'] = $full_name;
                    setSessionUserRole($role);

                    // Log activity
                    logActivity($user_id, 'registration', 'User registered via agent store: ' . $store_slug);

                    // Welcome notifications
                    dbh_send_welcome_notifications($full_name, $email, $phone_norm, $store['store_name'] ?? SITE_NAME, $username, $password);

                    $conn->commit();

                    // Redirect to dashboard with store context
                    header('Location: ' . SITE_URL . '/customer/dashboard.php?store=' . urlencode($store_slug));
                    exit();
                }
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                error_log('[store/register] Registration error: ' . $e->getMessage());
                $error = 'Could not complete registration. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('theme');
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-theme', savedTheme || (prefersDark ? 'dark' : 'light'));
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Register</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/store-custom.css')); ?>">
    <?php if (!empty($store['primary_color'])): ?>
        <style>
            :root {
                --store-accent: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --store-accent-strong: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --brand-primary: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
                --primary-color: <?php echo htmlspecialchars($store['primary_color']); ?> !important;
            }
        </style>
    <?php endif; ?>
</head>
<body class="store-page">
    <div class="login-container">
        <!-- Store Header Card -->
        <div class="store-header-card">
            <div class="store-brand">
                <?php if (!empty($store['store_logo'])): ?>
                    <img src="../uploads/<?php echo htmlspecialchars($store['store_logo']); ?>" alt="<?php echo htmlspecialchars($store['store_name']); ?>" class="store-logo">
                <?php else: ?>
                    <div class="store-logo-placeholder"><i class="fas fa-store"></i></div>
                <?php endif; ?>
                <div class="store-info">
                    <h1><?php echo htmlspecialchars($store['store_name']); ?></h1>
                    <?php if (!empty($store['store_description'])): ?>
                        <p><?php echo htmlspecialchars($store['store_description']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Register Card -->
        <div class="card login-card">
            <div class="card-body">
                <div class="login-header">
                    <div class="login-logo">Create Account</div>
                    <p class="login-subtitle">Sign up to buy data from <?php echo htmlspecialchars($store['store_name']); ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="full_name" class="form-label">Full Name *</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required placeholder="Enter your full name">
                    </div>
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="email" name="email" required placeholder="Enter your email">
                    </div>
                    <div class="form-group">
                        <label for="phone" class="form-label">Phone Number *</label>
                        <input type="tel" class="form-control" id="phone" name="phone" required placeholder="Enter Ghana phone number">
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password *</label>
                        <div class="password-input-wrapper">
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Create a strong password">
                            <button type="button" class="password-toggle" data-target="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <div class="password-input-wrapper">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Confirm your password">
                            <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Create Account</button>
                </form>

                <div class="text-center mt-4">
                    <p class="text-muted" style="color: var(--store-muted) !important;">Already have an account? <a href="login.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode('/customer/dashboard.php?store=' . $store_slug); ?>" class="text-primary" style="color: var(--store-accent) !important; font-weight: 600;">Log in</a></p>
                </div>
            </div>
        </div>
    </div>

    <style>
        .store-brand {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            text-align: left;
        }
        
        .store-logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .store-logo-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .store-info h1 {
            margin: 0;
            font-size: 1.5rem;
            line-height: 1.2;
        }
        
        .store-info p {
            margin: 0.25rem 0 0 0;
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        /* Mobile responsiveness layout help */
        @media (max-width: 768px) {
            .store-brand {
                gap: var(--spacing-sm);
            }
            
            .store-logo,
            .store-logo-placeholder {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
            
            .store-info h1 {
                font-size: 1.25rem;
            }
            
            .store-info p {
                font-size: 0.8rem;
            }
        }
    </style>
    
    <!-- Theme Support -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>"></script>
</body>
</html>
