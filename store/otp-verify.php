<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/arkesel.php';
require_once __DIR__ . '/../includes/mnotify_sms.php';
require_once __DIR__ . '/../includes/email.php';

// Get store context
$store_slug = $_GET['store'] ?? '';
$store = null;
if (!empty($store_slug)) {
    $stmt = $db->prepare("SELECT * FROM agent_stores WHERE slug = ?");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $store = $stmt->get_result()->fetch_assoc();
}

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
        error_log('[otp-verify] Welcome notification failed: ' . $e->getMessage());
    }
}

$phone = $_GET['phone'] ?? '';
$purpose = $_GET['purpose'] ?? 'signup';
$error = '';
$success = '';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $otp = sanitize($_POST['otp'] ?? '');
        
        if (empty($otp)) {
            $error = 'Please enter the verification code.';
        } else {
            try {
                $sms = new ArkeselSMS();
                $verified = $sms->verifyOTP($phone, $otp, $purpose);
                
                if ($verified) {
                    if ($purpose === 'signup' && isset($_SESSION['pending_registration'])) {
                        // Complete registration
                        $reg_data = $_SESSION['pending_registration'];
                        
                        $stmt = $db->prepare('INSERT INTO users (username, email, full_name, phone, password, role, agent_id, phone_verified) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)');
                        $stmt->bind_param('ssssssi', 
                            $reg_data['username'], 
                            $reg_data['email'], 
                            $reg_data['full_name'], 
                            $reg_data['phone'], 
                            $reg_data['password'], 
                            $reg_data['role'], 
                            $reg_data['agent_id']
                        );
                        
                        if ($stmt->execute()) {
                            $user_id = $db->lastInsertId();
                            
                            // Create wallet for the user
                            $stmt = $db->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)');
                            $stmt->bind_param('i', $user_id);
                            $stmt->execute();
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user_id;
                            $_SESSION['username'] = $reg_data['username'];
                            $_SESSION['email'] = $reg_data['email'];
                            $_SESSION['full_name'] = $reg_data['full_name'];
                            setSessionUserRole($reg_data['role']);
                            
                            // Clear pending registration
                            unset($_SESSION['pending_registration']);

                            // Welcome notifications
                            $welcomePhone = function_exists('formatPhone') ? formatPhone($reg_data['phone']) : $reg_data['phone'];
                            dbh_send_welcome_notifications(
                                $reg_data['full_name'],
                                $reg_data['email'],
                                $welcomePhone,
                                $store['store_name'] ?? SITE_NAME,
                                $reg_data['username'] ?? null,
                                $reg_data['plain_password'] ?? null
                            );
                            
                            // Redirect to dashboard
                            $redirect_url = $reg_data['redirect'] ?: '/customer/dashboard.php';
                            if (!empty($store_slug)) {
                                $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'store=' . urlencode($store_slug);
                            }
                            header('Location: ' . SITE_URL . $redirect_url);
                            exit();
                        } else {
                            $error = 'Registration failed. Please try again.';
                        }
                    } elseif ($purpose === 'password_reset') {
                        // Mark phone as verified for password reset
                        $_SESSION['phone_verified_for_reset'] = $phone;
                        header('Location: reset-password.php?phone=' . urlencode($phone) . 
                               (!empty($store_slug) ? '&store=' . urlencode($store_slug) : ''));
                        exit();
                    }
                } else {
                    $error = 'Invalid or expired verification code. Please try again.';
                }
            } catch (Exception $e) {
                error_log("OTP verification error: " . $e->getMessage());
                $error = 'Verification failed. Please try again.';
            }
        }
    }
}

// Handle resend OTP
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    try {
        $sms = new ArkeselSMS();
        $response = $sms->sendOTP($phone, $purpose);
        
        if ($response['success']) {
            $success = 'Verification code sent successfully. Please check your phone.';
        } else {
            $error = 'Failed to send verification code. Please try again.';
        }
    } catch (Exception $e) {
        error_log("OTP resend error: " . $e->getMessage());
        $error = 'SMS service unavailable. Please try again later.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Phone Number - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <link rel="manifest" href="../manifest.php">
    <meta name="theme-color" content="#541388">
    <style>
        .otp-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            margin: 0 5px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        
        .otp-input:focus {
            border-color: var(--brand-primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(84, 19, 136, 0.25);
        }
        
        .otp-container {
            display: flex;
            justify-content: center;
            margin: 1rem 0;
        }
        
        .resend-timer {
            color: var(--text-muted);
            font-size: 0.875rem;
            text-align: center;
            margin-top: 1rem;
        }
        
        .auth-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            padding: var(--spacing-lg);
        }
        .store-header-card {
            background: rgba(241, 233, 218, 0.95);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(241, 233, 218, 0.2);
            width: 100%;
            max-width: 400px;
        }
        
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
            background: var(--brand-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #F1E9DA;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .store-info h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-primary);
            font-weight: 600;
        }
        
        /* Dark theme support */
        [data-theme="dark"] .store-header-card {
            background: rgba(46, 41, 78, 0.95);
            border-color: rgba(241, 233, 218, 0.1);
        }
        
        [data-theme="dark"] .store-info h1 {
            color: var(--text-primary);
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .store-header-card {
                padding: var(--spacing-md);
                margin: var(--spacing-md) var(--spacing-md) var(--spacing-lg) var(--spacing-md);
            }
            
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
            
            .otp-input {
                width: 45px;
                height: 45px;
                font-size: 1.25rem;
                margin: 0 3px;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
</head>
<body>
    <div class="auth-container">
        <?php if ($store): ?>
        <!-- Store Header Card -->
        <div class="store-header-card">
            <div class="store-brand">
                <?php if (!empty($store['agent_logo'])): ?>
                    <img src="../uploads/agent_logos/<?php echo htmlspecialchars($store['agent_logo']); ?>" alt="<?php echo htmlspecialchars($store['business_name']); ?>" class="store-logo">
                <?php else: ?>
                    <div class="store-logo-placeholder"><i class="fas fa-store"></i></div>
                <?php endif; ?>
                <div class="store-info">
                    <h1><?php echo htmlspecialchars($store['business_name']); ?></h1>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-title">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Verify Your Phone Number</h3>
                </div>
                
                <p class="auth-subtitle">
                    We've sent a 6-digit verification code to<br>
                    <strong><?php echo htmlspecialchars($phone); ?></strong>
                </p>
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

            <form method="post" class="auth-form" id="otpForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label class="form-label" for="otp-digit-0">Enter Verification Code</label>
                    <div class="otp-container">
                        <input type="text" class="otp-input" maxlength="1" data-index="0" id="otp-digit-0">
                        <input type="text" class="otp-input" maxlength="1" data-index="1">
                        <input type="text" class="otp-input" maxlength="1" data-index="2">
                        <input type="text" class="otp-input" maxlength="1" data-index="3">
                        <input type="text" class="otp-input" maxlength="1" data-index="4">
                        <input type="text" class="otp-input" maxlength="1" data-index="5">
                    </div>
                    <input type="hidden" name="otp" id="otpValue">
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-check"></i> Verify Code
                </button>
            </form>

            <div class="auth-footer">
                <div class="resend-timer">
                    <span id="resendText">Didn't receive the code?</span>
                    <a href="?phone=<?php echo urlencode($phone); ?>&purpose=<?php echo urlencode($purpose); ?>&resend=1<?php echo !empty($store_slug) ? '&store=' . urlencode($store_slug) : ''; ?>" 
                       id="resendLink" style="display: none;">Resend Code</a>
                    <span id="countdown"></span>
                </div>
                
                <div style="margin-top: 1rem; text-align: center;">
                    <a href="<?php echo !empty($store_slug) ? 'login.php?store=' . urlencode($store_slug) : '../login.php'; ?>" 
                       class="text-link">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
    <script>
        // Initialize theme
        initializeTheme();

        // OTP input handling
        const otpInputs = document.querySelectorAll('.otp-input');
        const otpValue = document.getElementById('otpValue');

        otpInputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                const value = e.target.value;
                
                // Only allow numbers
                if (!/^\d*$/.test(value)) {
                    e.target.value = '';
                    return;
                }
                
                // Move to next input
                if (value && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                
                updateOTPValue();
            });
            
            input.addEventListener('keydown', function(e) {
                // Handle backspace
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    otpInputs[index - 1].focus();
                }
                
                // Handle paste
                if (e.key === 'v' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    navigator.clipboard.readText().then(text => {
                        const digits = text.replace(/\D/g, '').slice(0, 6);
                        digits.split('').forEach((digit, i) => {
                            if (otpInputs[i]) {
                                otpInputs[i].value = digit;
                            }
                        });
                        updateOTPValue();
                        if (digits.length === 6) {
                            document.getElementById('otpForm').submit();
                        }
                    });
                }
            });
        });

        function updateOTPValue() {
            const otp = Array.from(otpInputs).map(input => input.value).join('');
            otpValue.value = otp;
            
            // Auto-submit when all 6 digits are entered
            if (otp.length === 6) {
                setTimeout(() => {
                    document.getElementById('otpForm').submit();
                }, 500);
            }
        }

        // Resend countdown
        let countdown = 60;
        const countdownElement = document.getElementById('countdown');
        const resendLink = document.getElementById('resendLink');
        const resendText = document.getElementById('resendText');

        function updateCountdown() {
            if (countdown > 0) {
                countdownElement.textContent = `Resend in ${countdown}s`;
                countdown--;
                setTimeout(updateCountdown, 1000);
            } else {
                countdownElement.style.display = 'none';
                resendText.style.display = 'none';
                resendLink.style.display = 'inline';
            }
        }

        // Start countdown
        updateCountdown();

        // Focus first input
        otpInputs[0].focus();
    </script>
</body>
</html>
