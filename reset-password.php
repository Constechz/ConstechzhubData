<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';

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
$valid_token = false;
$token = $_GET['token'] ?? '';

// Validate reset token
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    // Check if token is valid
    $stmt = $db->prepare("
        SELECT prt.*, u.email, u.full_name 
        FROM password_reset_tokens prt 
        JOIN users u ON prt.user_id = u.id 
        WHERE prt.token = ? AND prt.used = 0 AND prt.expires_at > NOW()
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($reset_data = $result->fetch_assoc()) {
        $valid_token = true;
        $user_id = $reset_data['user_id'];
        $user_email = $reset_data['email'];
        $user_name = $reset_data['full_name'];
    } else {
        $error = 'Invalid or expired reset token. Please request a new password reset.';
    }
}

// Handle password reset form submission
if ($_POST && $valid_token) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'Please fill in all password fields';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Update password
        $password_hash = hashPassword($new_password);
        
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $password_hash, $user_id);
        
        if ($stmt->execute()) {
            // Mark token as used
            $stmt = $db->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            
            // Log activity
            logActivity($user_id, 'password_reset', 'Password was reset successfully');
            
            setFlashMessage('success', 'Your password has been reset successfully. You can now sign in with your new password.');
            header('Location: ' . SITE_URL . '/login.php');
            exit();
        } else {
            $error = 'Failed to update password. Please try again.';
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
    <?php echo generateSeoMeta('Reset Password', 'Set a new password for your Constechzhub account.'); ?>
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
                    <p class="login-subtitle">Set your new password</p>
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
                
                <?php if ($valid_token): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Resetting password for: <strong><?php echo htmlspecialchars($user_email); ?></strong>
                </div>
                
                <form method="POST" action="" id="resetPasswordForm">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="password-input-wrapper">
                            <input 
                                type="password" 
                                class="form-control" 
                                id="new_password" 
                                name="new_password" 
                                required 
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Enter your new password"
                            >
                            <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="form-help">Password must be at least 8 characters long</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="password-input-wrapper">
                            <input 
                                type="password" 
                                class="form-control" 
                                id="confirm_password" 
                                name="confirm_password" 
                                required 
                                minlength="8"
                                autocomplete="new-password"
                                placeholder="Confirm your new password"
                            >
                            <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="password-strength" id="passwordStrength" style="display: none;">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;" id="resetBtn">
                        <span id="resetText">Reset Password</span>
                        <span id="resetSpinner" class="spinner d-none"></span>
                    </button>
                </form>
                
                <?php else: ?>
                <div class="text-center">
                    <div class="error-icon" style="font-size: 3rem; color: #D90368; margin-bottom: 1rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <p class="text-muted">The password reset link is invalid or has expired.</p>
                    <a href="forgot-password.php" class="btn btn-primary">Request New Reset Link</a>
                </div>
                <?php endif; ?>
                
                <div class="text-center mt-4">
                    <p class="text-muted">
                        Remember your password? <a href="login.php" class="text-primary">Sign in</a>
                    </p>
                </div>
                
            </div>
        </div>
    </div>
    
    <style>
        .password-input-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 5px;
        }
        
        .password-toggle:hover {
            color: var(--text-primary);
        }
        
        .password-strength {
            margin-top: 0.5rem;
        }
        
        .strength-bar {
            height: 4px;
            background: #F1E9DA;
            border-radius: 2px;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            transition: width 0.3s, background-color 0.3s;
            width: 0%;
        }
        
        .strength-text {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .strength-weak { background-color: #D90368; }
        .strength-fair { background-color: #FFD400; }
        .strength-good { background-color: #FFD400; }
        .strength-strong { background-color: #2E294E; }
    </style>
    
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
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
        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthElement = document.getElementById('passwordStrength');
            const fillElement = document.getElementById('strengthFill');
            const textElement = document.getElementById('strengthText');
            
            if (password.length === 0) {
                strengthElement.style.display = 'none';
                return;
            }
            
            strengthElement.style.display = 'block';
            
            let score = 0;
            let feedback = [];
            
            // Length check
            if (password.length >= 8) score += 1;
            else feedback.push('at least 8 characters');
            
            // Lowercase
            if (/[a-z]/.test(password)) score += 1;
            else feedback.push('lowercase letters');
            
            // Uppercase
            if (/[A-Z]/.test(password)) score += 1;
            else feedback.push('uppercase letters');
            
            // Numbers
            if (/\d/.test(password)) score += 1;
            else feedback.push('numbers');
            
            // Special characters
            if (/[^a-zA-Z\d]/.test(password)) score += 1;
            else feedback.push('special characters');
            
            // Update strength display
            const percentage = (score / 5) * 100;
            fillElement.style.width = percentage + '%';
            
            if (score <= 1) {
                fillElement.className = 'strength-fill strength-weak';
                textElement.textContent = 'Weak - Add ' + feedback.slice(0, 2).join(', ');
                textElement.style.color = '#D90368';
            } else if (score <= 2) {
                fillElement.className = 'strength-fill strength-fair';
                textElement.textContent = 'Fair - Add ' + feedback.slice(0, 2).join(', ');
                textElement.style.color = '#FFD400';
            } else if (score <= 3) {
                fillElement.className = 'strength-fill strength-good';
                textElement.textContent = 'Good - Add ' + feedback.join(', ');
                textElement.style.color = '#FFD400';
            } else {
                fillElement.className = 'strength-fill strength-strong';
                textElement.textContent = 'Strong password';
                textElement.style.color = '#2E294E';
            }
        }
        
        // Password confirmation validation
        function validatePasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const confirmInput = document.getElementById('confirm_password');
            
            if (confirmPassword && newPassword !== confirmPassword) {
                confirmInput.setCustomValidity('Passwords do not match');
            } else {
                confirmInput.setCustomValidity('');
            }
        }
        
        <?php if ($valid_token): ?>
        // Form handling
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
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
                if (window.location.pathname.includes('reset-password')) {
                    resetBtn.disabled = false;
                    resetText.classList.remove('d-none');
                    resetSpinner.classList.add('d-none');
                }
            }, 10000);
        });
        
        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordField = document.getElementById('new_password');
            const confirmPasswordField = document.getElementById('confirm_password');
            
            // Auto-focus new password field
            newPasswordField.focus();
            
            // Password strength checking
            newPasswordField.addEventListener('input', function() {
                checkPasswordStrength(this.value);
                validatePasswordMatch();
            });
            
            // Password confirmation validation
            confirmPasswordField.addEventListener('input', validatePasswordMatch);
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

