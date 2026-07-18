<?php
require_once __DIR__ . '/../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Get store slug
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
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.account_activation_status = 'active'
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

// Debug: store context loaded
error_log('[store/login] context: store_slug=' . $store_slug . ', store_id=' . ($store['id'] ?? 'n/a'));

// Already logged in? Redirect based on role
if (isLoggedIn()) {
    $role = normalizeUserRole($_SESSION['user_role'] ?? '');
    if ($role === 'admin') {
        $redirect = rtrim(SITE_URL, '/') . '/admin/dashboard.php';
    } elseif ($role === 'agent') {
        $redirect = rtrim(SITE_URL, '/') . '/agent/dashboard.php';
    } else {
        $redirect = $_GET['redirect'] ?? ("/customer/dashboard.php?store=" . $store_slug);
        if (strpos($redirect, 'http') !== 0) {
            $redirect = rtrim(SITE_URL, '/') . '/' . ltrim($redirect, '/');
        }
    }
    error_log('[store/login] already_logged_in redirect=' . $redirect);
    header('Location: ' . $redirect);
    exit();
}

$error = '';
$success = '';

if ($_POST) {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    $redirect = $_POST['redirect'] ?? ("/customer/dashboard.php?store=" . $store_slug);

    error_log('[store/login] POST attempt: email=' . $email . ', store_slug=' . $store_slug . ', redirect_in=' . $redirect);

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } else {
        $stmt = $db->prepare("SELECT id, username, email, password, full_name, role, status, email_verified FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            if ($user['status'] !== 'active') {
                $error = 'Your account has been suspended. Please contact support.';
                error_log('[store/login] suspended user id=' . $user['id']);
            } elseif (verifyPassword($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['full_name'] = $user['full_name'];
                setSessionUserRole($user['role']);

                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), '/');
                    $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $stmt->bind_param("si", $token, $user['id']);
                    $stmt->execute();
                }

                logActivity($user['id'], 'login', 'User logged in successfully via store');

                $user_role = normalizeUserRole($user['role'] ?? '');
                if (function_exists('isEmailVerificationEnabled') && isEmailVerificationEnabled()
                    && $user_role !== normalizeUserRole(defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin')
                    && $user_role !== normalizeUserRole(defined('ROLE_SUPER_ADMIN') ? ROLE_SUPER_ADMIN : 'super_admin')) {
                    if (function_exists('isUserEmailVerified') && !isUserEmailVerified($user['id'])) {
                        if (function_exists('sendEmailVerificationMessage')) {
                            $sendResult = sendEmailVerificationMessage($user['id'], $user['email'], $user['full_name']);
                            if (!empty($sendResult['message'])) {
                                setFlashMessage($sendResult['success'] ? 'success' : 'error', $sendResult['message']);
                            }
                        }
                        $verify_url = rtrim(SITE_URL, '/') . '/verify-email.php';
                        $redirect_hint = $redirect ?? ("/customer/dashboard.php?store=" . $store_slug);
                        if (!empty($redirect_hint) && filter_var($redirect_hint, FILTER_VALIDATE_URL) === false) {
                            if (strpos($redirect_hint, '/') === 0 && strpos($redirect_hint, '//') === false) {
                                $verify_url .= '?redirect=' . urlencode($redirect_hint);
                            }
                        }
                        header('Location: ' . $verify_url);
                        exit();
                    }
                }

                $role = normalizeUserRole($user['role']);
                if ($role === 'admin') {
                    $redirect = rtrim(SITE_URL, '/') . '/admin/dashboard.php';
                } elseif ($role === 'agent') {
                    $redirect = rtrim(SITE_URL, '/') . '/agent/dashboard.php';
                } else {
                    if (strpos($redirect, 'http') !== 0) {
                        $redirect = rtrim(SITE_URL, '/') . '/' . ltrim($redirect, '/');
                    }
                }
                error_log('[store/login] success: user_id=' . $user['id'] . ', role=' . $user['role'] . ', final_redirect=' . $redirect);
                header('Location: ' . $redirect);
                exit();
            } else {
                $error = 'Invalid email or password';
                error_log('[store/login] invalid_password for email=' . $email);
            }
        } else {
            $error = 'Invalid email or password';
            error_log('[store/login] email_not_found=' . $email);
        }
    }
}

$redirectParam = $_GET['redirect'] ?? ("/customer/dashboard.php?store=" . $store_slug);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Login</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
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

        <!-- Login Card -->
        <div class="card login-card">
            <div class="card-body">
                <div class="login-header">
                    <div class="login-logo">Customer Login</div>
                    <p class="login-subtitle">Sign in to continue at <?php echo htmlspecialchars($store['store_name']); ?></p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectParam); ?>">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" required placeholder="Enter your email">
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password">
                            <button type="button" class="password-toggle" data-target="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="d-flex align-items-center">
                            <input type="checkbox" name="remember" id="remember">
                            <label for="remember" class="mb-0" style="margin-left: 0.5rem;">Remember me</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Sign In</button>
                </form>

                <div class="text-center mt-4">
                    <p class="text-muted">Don't have an account? <a href="register.php?store=<?php echo urlencode($store_slug); ?>&redirect=<?php echo urlencode('/customer/dashboard.php?store=' . $store_slug); ?>" class="text-primary">Sign up</a></p>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Store-specific login page styles */
        .store-header-card {
            background: rgba(241, 233, 218, 0.95);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-lg);
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(241, 233, 218, 0.2);
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
        
        .store-info p {
            margin: 0.25rem 0 0 0;
            color: var(--text-secondary);
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        /* Dark theme support for store login */
        [data-theme="dark"] .store-header-card {
            background: rgba(46, 41, 78, 0.95);
            border-color: rgba(241, 233, 218, 0.1);
        }
        
        [data-theme="dark"] .store-info h1 {
            color: var(--text-primary);
        }
        
        [data-theme="dark"] .store-info p {
            color: var(--text-secondary);
        }
        
        /* Mobile responsiveness for store login */
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
            
            .store-info p {
                font-size: 0.8rem;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
    
    <!-- Theme Support -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
</body>
</html>
