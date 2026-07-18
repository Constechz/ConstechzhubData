<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/email.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Redirect if already logged in
if (isLoggedIn()) {
    $role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : '';
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
$email_sent = false;

// Handle forgot password form submission
if ($_POST) {
    $email = sanitize($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } else {
        $smtp_enabled = getSmtpSetting('smtp_enabled', 'false') === 'true';
        $smtp_host = getSmtpSetting('smtp_host');
        $smtp_username = getSmtpSetting('smtp_username');
        $smtp_password = getSmtpSetting('smtp_password');
        $smtp_from = getSmtpSetting('from_email');

        if (!$smtp_enabled) {
            $error = 'Email service is currently disabled. Please contact support.';
        } elseif (!$smtp_host || !$smtp_username || !$smtp_password || !$smtp_from) {
            $error = 'Email service is not configured. Please contact support.';
        } else {
        // Check if user exists
        $stmt = $db->prepare("SELECT id, username, email, full_name, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if ($user['status'] !== 'active') {
                $error = 'Your account has been suspended. Please contact support.';
            } else {
                // Generate password reset token
                $reset_token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                
                // Check if password_reset_tokens table exists, create if not
                $tableCheck = $db->query("SHOW TABLES LIKE 'password_reset_tokens'");
                if ($tableCheck->num_rows == 0) {
                    $createTableSQL = "CREATE TABLE `password_reset_tokens` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `user_id` int(11) NOT NULL,
                        `token` varchar(255) NOT NULL,
                        `expires_at` timestamp NOT NULL,
                        `used` tinyint(1) DEFAULT 0,
                        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `token` (`token`),
                        KEY `user_id` (`user_id`),
                        KEY `expires_at` (`expires_at`),
                        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    $db->query($createTableSQL);
                }
                
                // Store reset token
                $stmt = $db->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $user['id'], $reset_token, $expires_at);
                $stmt->execute();
                
                // Send password reset email
                try {
                    $result = sendPasswordResetEmail($user['email'], $user['full_name'], $reset_token);
                    
                    // Handle both array and boolean return types for compatibility
                    $email_success = false;
                    if (is_array($result) && isset($result['success'])) {
                        $email_success = $result['success'];
                    } elseif (is_bool($result)) {
                        $email_success = $result;
                    }
                    
                    if ($email_success) {
                        $success = 'Password reset instructions have been sent to your email address.';
                        $email_sent = true;
                    } else {
                        $error = 'Failed to send password reset email. Please try again later.';
                        $cleanup = $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token = ? LIMIT 1");
                        if ($cleanup) {
                            $cleanup->bind_param('is', $user['id'], $reset_token);
                            $cleanup->execute();
                        }
                    }
                } catch (Exception $e) {
                    error_log("Password reset email error: " . $e->getMessage());
                    $error = 'Failed to send password reset email. Please try again later.';
                    $cleanup = $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token = ? LIMIT 1");
                    if ($cleanup) {
                        $cleanup->bind_param('is', $user['id'], $reset_token);
                        $cleanup->execute();
                    }
                }
            }
        } else {
            // Don't reveal if email exists or not for security
            $success = 'If an account with that email address exists, you will receive password reset instructions.';
            $email_sent = true;
        }
        }
    }
}

// Get flash message
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php echo generateSeoMeta('Forgot Password', 'Reset your Constechzhub account password securely.'); ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#541388">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>"">
</head>
<body>
    <div class="login-container">
        <div class="card login-card">
            <div class="card-body">
                <div class="login-header">
                    <div class="login-logo"><?php echo htmlspecialchars(getSiteName()); ?></div>
                    <?php if ($email_sent): ?>
                        <p class="login-subtitle">Check your email for reset instructions</p>
                    <?php else: ?>
                        <p class="login-subtitle">Reset your password</p>
                    <?php endif; ?>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!$email_sent): ?>
                <form method="POST" action="" id="forgotPasswordForm">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input 
                            type="email" 
                            class="form-control" 
                            id="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($email ?? ''); ?>"
                            required 
                            autocomplete="email"
                            placeholder="Enter your email address"
                        >
                        <small class="form-help">Enter the email address associated with your account</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;" id="resetBtn">
                        <span id="resetText">Send Reset Instructions</span>
                        <span id="resetSpinner" class="spinner d-none"></span>
                    </button>
                </form>
                <?php else: ?>
                <div class="text-center">
                    <div class="email-sent-icon" style="font-size: 3rem; color: #2E294E; margin-bottom: 1rem;">
                        <i class="fas fa-envelope-circle-check"></i>
                    </div>
                    <p class="text-muted">
                        If your email address is registered with us, you'll receive password reset instructions within a few minutes.
                    </p>
                    <p class="text-muted">
                        <strong>Didn't receive the email?</strong><br>
                        Check your spam folder or <a href="forgot-password.php" class="text-primary">try again</a>.
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="text-center mt-4">
                    <p class="text-muted">
                        Remember your password? <a href="login.php" class="text-primary">Sign in</a>
                    </p>
                    <p class="text-muted">
                        Don't have an account? <a href="register.php" class="text-primary">Sign up</a>
                    </p>
                </div>
                
            </div>
        </div>
    </div>
    
    <script>
        // Theme detection and application
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            
            document.documentElement.setAttribute('data-theme', theme);
        }
        
        // Initialize theme on page load
        initTheme();
        
        // Form handling
        <?php if (!$email_sent): ?>
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const resetBtn = document.getElementById('resetBtn');
            const resetText = document.getElementById('resetText');
            const resetSpinner = document.getElementById('resetSpinner');
            
            // Show loading state
            resetBtn.disabled = true;
            resetText.classList.add('d-none');
            resetSpinner.classList.remove('d-none');
            
            // Form will submit normally, but we show loading state
            setTimeout(() => {
                // If still on same page after 10 seconds, re-enable button
                if (window.location.pathname.includes('forgot-password')) {
                    resetBtn.disabled = false;
                    resetText.classList.remove('d-none');
                    resetSpinner.classList.add('d-none');
                }
            }, 10000);
        });
        
        // Auto-focus email field
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (emailField) {
                emailField.focus();
            }
        });
        <?php endif; ?>
    </script>
    
    <!-- PWA Service Worker Registration -->
    <script>
        // PWA service worker registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
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

