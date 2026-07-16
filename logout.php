<?php
require_once __DIR__ . '/config/config.php';

// Log activity if user is logged in
if (isLoggedIn()) {
    logActivity($_SESSION['user_id'], 'logout', 'User logged out');
}

// Clear remember me cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
    
    // Clear token from database
    if (isLoggedIn()) {
        $stmt = $db->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
    }
}

// Destroy session
session_destroy();

// Set success message for login page
session_start();
setFlashMessage('success', 'You have been logged out successfully.');

// Ensure session is written before redirect to prevent flash message timing issues
session_write_close();

// Redirect to login page
header('Location: ' . SITE_URL . '/login.php');
exit();
?>

