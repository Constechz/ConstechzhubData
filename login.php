<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/seo.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

function getVipDashboardRedirectUrl() {
    $vip_dashboard_path = __DIR__ . '/vip/dashboard.php';
    if (is_file($vip_dashboard_path)) {
        return SITE_URL . '/vip/dashboard.php';
    }

    return SITE_URL . '/customer/dashboard.php';
}

// Redirect if already logged in
if (isLoggedIn()) {
    $role = normalizeUserRole($_SESSION['user_role'] ?? '');
    $redirect_url = $_GET['redirect'] ?? '';
    
    if ($role === 'admin') {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
    } elseif ($role === 'vip') {
        header('Location: ' . getVipDashboardRedirectUrl());
    } elseif ($role === 'agent') {
        header('Location: ' . SITE_URL . '/agent/dashboard.php');
    } else {
        // Customer role - check for redirect URL
        if (!empty($redirect_url) && filter_var($redirect_url, FILTER_VALIDATE_URL) === false) {
            // Relative URL - validate it's safe
            if (strpos($redirect_url, '/') === 0 && !strpos($redirect_url, '//')) {
                header('Location: ' . SITE_URL . $redirect_url);
                exit();
            }
        }
        header('Location: ' . SITE_URL . '/customer/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_POST) {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } else {
        // Check user credentials
        $stmt = $db->prepare("SELECT id, username, email, password, full_name, role, status, email_verified FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if ($user['status'] !== 'active') {
                $error = 'Your account has been suspended. Please contact support.';
            } elseif (verifyPassword($password, $user['password'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                setSessionUserRole($user['role']);
                
                // Update last login
                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                
                // Set remember me cookie if requested
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 days
                    
                    // Store token in database (you might want to create a remember_tokens table)
                    $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $stmt->bind_param("si", $token, $user['id']);
                    $stmt->execute();
                }
                
                // Log activity
                logActivity($user['id'], 'login', 'User logged in successfully');

                $user_role = normalizeUserRole($user['role'] ?? '');
                if (function_exists('isEmailVerificationEnabled') && isEmailVerificationEnabled()
                    && $user_role !== normalizeUserRole(defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin')
                    && $user_role !== normalizeUserRole(defined('ROLE_SUPER_ADMIN') ? ROLE_SUPER_ADMIN : 'super_admin')) {
                    if (function_exists('isUserEmailVerified') && !isUserEmailVerified($user['id'])) {
                        $verification_method = function_exists('getVerificationMethod') ? getVerificationMethod() : 'sms';
                        if ($verification_method === 'email') {
                            if (function_exists('sendEmailVerificationMessage')) {
                                $sendResult = sendEmailVerificationMessage($user['id'], $user['email'], $user['full_name']);
                                if (!empty($sendResult['message'])) {
                                    setFlashMessage($sendResult['success'] ? 'success' : 'error', $sendResult['message']);
                                }
                            }
                        } else {
                            if (function_exists('sendSmsVerificationMessage')) {
                                $sendResult = sendSmsVerificationMessage($user['id'], $user['phone'] ?? null, $user['full_name']);
                                if (!empty($sendResult['message'])) {
                                    setFlashMessage($sendResult['success'] ? 'success' : 'error', $sendResult['message']);
                                }
                            }
                        }
                        $verify_url = SITE_URL . '/verify-email.php';
                        $redirect_hint = $_GET['redirect'] ?? '';
                        if (!empty($redirect_hint) && filter_var($redirect_hint, FILTER_VALIDATE_URL) === false) {
                            if (strpos($redirect_hint, '/') === 0 && strpos($redirect_hint, '//') === false) {
                                $verify_url .= '?redirect=' . urlencode($redirect_hint);
                            }
                        }
                        header('Location: ' . $verify_url);
                        exit();
                    }
                }
                
                // Handle redirect URL for agent store customers
                $redirect_url = $_GET['redirect'] ?? '';
                
                // Redirect based on role
                $role = normalizeUserRole($user['role']);
                if ($role === 'admin') {
                    header('Location: ' . SITE_URL . '/admin/dashboard.php');
                } elseif ($role === 'vip') {
                    header('Location: ' . getVipDashboardRedirectUrl());
                } elseif ($role === 'agent') {
                    header('Location: ' . SITE_URL . '/agent/dashboard.php');
                } else {
                    // Customer role - check for redirect URL
                    if (!empty($redirect_url) && filter_var($redirect_url, FILTER_VALIDATE_URL) === false) {
                        // Relative URL - validate it's safe
                        if (strpos($redirect_url, '/') === 0 && !strpos($redirect_url, '//')) {
                            header('Location: ' . SITE_URL . $redirect_url);
                            exit();
                        }
                    }
                    header('Location: ' . SITE_URL . '/customer/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid email or password';
            }
        } else {
            $error = 'Invalid email or password';
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
    <?php echo generateSeoMeta('Login', 'Sign in to your Constechzhub account to manage your data bundles and services.'); ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="preload" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>"></noscript>
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#8B5CF6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(dbh_asset('assets/images/icon-192.png')); ?>">
    <style>
        .login-container {
            position: relative;
        }
        .login-action-buttons {
            position: fixed;
            left: 50%;
            bottom: 1.5rem;
            transform: translateX(-50%);
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid var(--border-color);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(10px);
        }
        [data-theme="dark"] .login-action-buttons {
            background: rgba(15, 23, 42, 0.65);
            border-color: rgba(148, 163, 184, 0.2);
        }
        .login-action-btn {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }
        .login-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.2);
            border-color: var(--brand-primary);
        }
        .login-action-btn:active {
            transform: translateY(0);
        }
        .login-whatsapp {
            background: #25D366;
            color: #ffffff;
            border: none;
        }
        .login-whatsapp:hover {
            background: #1ebe5d;
        }
        .login-home {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-secondary));
            color: #ffffff;
            border: none;
        }
        .login-home:hover {
            background: linear-gradient(135deg, var(--brand-secondary), var(--brand-primary));
        }
        @media (max-width: 576px) {
            .login-action-buttons {
                bottom: 1rem;
                gap: 0.2rem;
                padding: 0.2rem;
            }
            .login-action-btn {
                width: 30px;
                height: 30px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-action-buttons">
            <button class="theme-toggle login-theme-toggle login-action-btn" type="button" aria-label="Toggle theme">
                <i class="fas fa-moon" id="theme-icon"></i>
            </button>
            <a class="login-action-btn login-home" href="<?php echo SITE_URL; ?>/" aria-label="Go to homepage">
                <i class="fas fa-house"></i>
            </a>
            <a class="login-action-btn login-whatsapp" href="https://wa.me/233249020304" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
                <i class="fab fa-whatsapp"></i>
            </a>
        </div>
        <div class="card login-card">
            <div class="card-body">
                <div class="login-header">
                    <div class="login-logo"><?php echo htmlspecialchars(getSiteName()); ?></div>
                    <p class="login-subtitle">Sign in to your account</p>
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
                
                <form method="POST" action="" id="loginForm">
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
                            placeholder="Enter your email"
                        >
                    </div>
                    
                    <div class="form-group password-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-input-wrapper">
                            <input 
                                type="password" 
                                class="form-control" 
                                id="password" 
                                name="password" 
                                required 
                                autocomplete="current-password"
                                placeholder="Enter your password"
                            >
                            <button 
                                type="button" 
                                class="password-toggle" 
                                data-target="password" 
                                aria-label="Show password"
                            >
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <input type="checkbox" name="remember" id="remember">
                                <label for="remember" class="mb-0" style="margin-left: 0.5rem;">Remember me</label>
                            </div>
                            <a href="forgot-password.php" class="text-primary">Forgot password?</a>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;" id="loginBtn">
                        <span id="loginText">Sign In</span>
                        <span id="loginSpinner" class="spinner d-none"></span>
                    </button>
                </form>
                
                <div class="text-center mt-4">
                    <p class="text-muted">Don't have an account? <a href="register.php" class="text-primary">Sign up</a></p>
                </div>
                
            </div>
        </div>
    </div>
    
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>"></script>
    <script>
        // Theme detection and application
        function updateThemeIcon(theme) {
            const icon = document.getElementById('theme-icon');
            if (!icon) return;
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            
            document.documentElement.setAttribute('data-theme', theme);
            updateThemeIcon(theme);
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        }
        
        // Initialize theme on page load
        initTheme();
        
        // Login form handling
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginBtn = document.getElementById('loginBtn');
            const loginText = document.getElementById('loginText');
            const loginSpinner = document.getElementById('loginSpinner');
            
            // Show loading state
            loginBtn.disabled = true;
            loginText.classList.add('d-none');
            loginSpinner.classList.remove('d-none');
            
            // Form will submit normally, but we show loading state
            setTimeout(() => {
                if (!window.location.href.includes('dashboard')) {
                    // If still on login page after 5 seconds, re-enable button
                    loginBtn.disabled = false;
                    loginText.classList.remove('d-none');
                    loginSpinner.classList.add('d-none');
                }
            }, 5000);
        });
        
        // Auto-focus first empty field
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.querySelector('.login-theme-toggle');
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    toggleTheme();
                });
            }

            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            if (!emailField.value) {
                emailField.focus();
            } else {
                passwordField.focus();
            }
        });
    </script>

    <!-- PWA Service Worker Registration -->
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
    
    <!-- PWA Installation Manager -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/pwa-install.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/page-events-guard.js')); ?>"></script>
</body>
</html>
