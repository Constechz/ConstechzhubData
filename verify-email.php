<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

$success = '';
$error = '';

$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

$verification_method = function_exists('getVerificationMethod') ? getVerificationMethod() : 'sms';
if (!in_array($verification_method, ['sms', 'email'], true)) {
    $verification_method = 'sms';
}
$verification_label = $verification_method === 'email' ? 'Email' : 'Phone';
$verification_target = $verification_method === 'email' ? 'email address' : 'phone number';
$verification_action = $verification_method === 'email' ? 'verification email' : 'verification code';
$verification_resend_label = $verification_method === 'email' ? 'Resend Verification Email' : 'Resend Verification Code';

$redirect_url = SITE_URL . '/customer/dashboard.php';
$requested_redirect = trim((string) ($_GET['redirect'] ?? ''));
if ($requested_redirect !== '' && strpos($requested_redirect, '/') === 0 && strpos($requested_redirect, '//') === false) {
    $redirect_url = rtrim(SITE_URL, '/') . $requested_redirect;
}
if (isLoggedIn()) {
    $role = normalizeUserRole($_SESSION['user_role'] ?? '');
    if ($role === 'admin') {
        $redirect_url = SITE_URL . '/admin/dashboard.php';
    } elseif ($role === 'agent') {
        $redirect_url = SITE_URL . '/agent/dashboard.php';
    }
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token !== '') {
    $result = verifyEmailWithToken($token);
    if (!empty($result['success'])) {
        $success = $result['message'] ?? 'Your account has been verified.';
    } else {
        $error = $result['message'] ?? 'Verification failed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_sms']) && $verification_method === 'sms') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf)) {
        $error = 'Invalid session token. Please refresh and try again.';
    } elseif (!isLoggedIn()) {
        $error = 'Please log in to verify your phone number.';
    } else {
        $otp = trim((string) ($_POST['otp'] ?? ''));
        if ($otp === '') {
            $error = 'Please enter the verification code.';
        } else {
            $current_user = getCurrentUser();
            if (!$current_user) {
                $error = 'User not found. Please log in again.';
            } else {
                $result = verifySmsVerificationCode((int) $current_user['id'], $otp, $current_user['phone'] ?? null);
                if (!empty($result['success'])) {
                    $success = $result['message'] ?? 'Your phone number has been verified.';
                    if (!headers_sent()) {
                        session_write_close();
                        header('Location: ' . $redirect_url);
                        exit();
                    }
                } else {
                    $error = $result['message'] ?? 'Verification failed.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verification'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($csrf)) {
        $error = 'Invalid session token. Please refresh and try again.';
    } elseif (!isLoggedIn()) {
        $error = 'Please log in to resend the verification code.';
    } else {
        $current_user = getCurrentUser();
        if (!$current_user) {
            $error = 'User not found. Please log in again.';
        } elseif (!empty($current_user['email_verified'])) {
            $success = 'Your account is already verified.';
        } else {
            if ($verification_method === 'email') {
                $result = sendEmailVerificationMessage((int) $current_user['id'], $current_user['email'] ?? null, $current_user['full_name'], true);
                if (!empty($result['success'])) {
                    $success = $result['message'] ?? 'Verification email sent.';
                } else {
                    $error = $result['message'] ?? 'Failed to send verification email.';
                }
            } else {
                $result = sendSmsVerificationMessage((int) $current_user['id'], $current_user['phone'] ?? null, $current_user['full_name'], true);
                if (!empty($result['success'])) {
                    $success = $result['message'] ?? 'Verification code sent.';
                } else {
                    $error = $result['message'] ?? 'Failed to send verification code.';
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php echo generateSeoMeta('Verify ' . $verification_label, 'Verify your ' . $verification_target . ' to secure your account.'); ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#6366f1">
</head>
<body>
    <div class="login-container">
        <div class="card login-card" style="max-width: 520px;">
            <div class="card-body">
                <div class="login-header">
                    <div class="login-logo"><?php echo htmlspecialchars(getSiteName()); ?></div>
                    <p class="login-subtitle"><?php echo htmlspecialchars($verification_label); ?> Verification</p>
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

                <div style="margin-bottom: 1rem; color: var(--text-muted);">
                    We have sent a <?php echo htmlspecialchars($verification_action); ?> to your registered <?php echo htmlspecialchars($verification_target); ?>.
                    <?php if ($verification_method === 'sms'): ?>
                        Enter the 6-digit code below to complete verification.
                    <?php else: ?>
                        Please click the link in that email to complete verification.
                    <?php endif; ?>
                </div>

                <?php if (isLoggedIn()): ?>
                    <?php if ($verification_method === 'sms'): ?>
                        <form method="post" style="margin-bottom: 1rem;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRF()); ?>">
                            <div class="form-group" style="margin-bottom: 1rem;">
                                <label for="otp" class="form-label">Verification Code</label>
                                <input type="text" id="otp" name="otp" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                            </div>
                            <button type="submit" name="verify_sms" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-check-circle"></i> Verify Code
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="margin-bottom: 1rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRF()); ?>">
                        <button type="submit" name="resend_verification" class="btn btn-outline" style="width: 100%;">
                            <i class="fas fa-paper-plane"></i> <?php echo htmlspecialchars($verification_resend_label); ?>
                        </button>
                    </form>
                <?php endif; ?>

                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <?php if (isLoggedIn()): ?>
                        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn btn-primary" style="flex: 1; text-align: center;">
                            <i class="fas fa-arrow-right"></i> Continue
                        </a>
                        <a href="<?php echo htmlspecialchars(SITE_URL . '/logout.php'); ?>" class="btn btn-secondary" style="flex: 1; text-align: center;">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(SITE_URL . '/login.php'); ?>" class="btn btn-primary" style="width: 100%; text-align: center;">
                            <i class="fas fa-sign-in-alt"></i> Back to Login
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($verification_method === 'sms'): ?>
    <script>
        const otpInput = document.getElementById('otp');
        if (otpInput) {
            otpInput.addEventListener('input', () => {
                const cleaned = otpInput.value.replace(/\D/g, '');
                if (cleaned !== otpInput.value) {
                    otpInput.value = cleaned;
                }
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>
