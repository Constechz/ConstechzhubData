<?php
require_once 'config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
</head>
<body>
    <div class="login-container">
        <div class="card login-card">
            <div class="card-body text-center">
                <div class="login-header">
                    <div class="login-logo"><?php echo htmlspecialchars(getSiteName()); ?></div>
                    <h2 class="text-danger">Access Denied</h2>
                </div>
                
                <div class="alert alert-danger">
                    You don't have permission to access this page.
                </div>
                
                <p class="text-muted mb-4">
                    Please contact your administrator if you believe this is an error.
                </p>
                
                <div class="d-flex justify-content-center" style="gap: 1rem;">
                    <a href="javascript:history.back()" class="btn btn-outline">Go Back</a>
                    <?php if (isLoggedIn()): ?>
                        <a href="index.php" class="btn btn-primary">Dashboard</a>
                        <a href="logout.php" class="btn btn-danger">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary">Login</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

