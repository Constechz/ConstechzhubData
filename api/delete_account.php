<?php
/**
 * Account Deletion API
 * Handles secure account deletion with proper data cleanup
 */

require_once '../config/config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}

// Validate CSRF token
if (!validateCSRF()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

$current_user = getCurrentUser();
$input = json_decode(file_get_contents('php://input'), true);

$password = $input['password'] ?? '';
$confirmation = $input['confirmation'] ?? '';

// Validate inputs
if (empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Password is required to delete account']);
    exit;
}

if ($confirmation !== 'DELETE') {
    echo json_encode(['status' => 'error', 'message' => 'Please type DELETE to confirm account deletion']);
    exit;
}

// Verify password
if (!password_verify($password, $current_user['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Incorrect password']);
    exit;
}

// Prevent admin from deleting their own account if they're the only admin
if ($current_user['role'] === 'admin') {
    $stmt = $db->prepare("SELECT COUNT(*) as admin_count FROM users WHERE role = 'admin' AND id != ?");
    $stmt->bind_param("i", $current_user['id']);
    $stmt->execute();
    $admin_count = $stmt->get_result()->fetch_assoc()['admin_count'];
    
    if ($admin_count == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot delete the last admin account']);
        exit;
    }
}

try {
    $db->getConnection()->begin_transaction();
    
    $user_id = $current_user['id'];
    $user_role = $current_user['role'];
    
    // Handle role-specific cleanup
    if ($user_role === 'agent') {
        // Transfer or handle agent-specific data
        
        // Update customers to remove agent assignment
        $stmt = $db->prepare("UPDATE users SET agent_id = NULL WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Handle agent custom pricing
        $stmt = $db->prepare("DELETE FROM agent_custom_pricing WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Handle agent paystack settings
        $stmt = $db->prepare("DELETE FROM agent_paystack_settings WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Handle commissions (mark as orphaned rather than delete for audit)
        $stmt = $db->prepare("UPDATE commissions SET status = 'orphaned' WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
    } elseif ($user_role === 'customer') {
        // Handle customer-specific cleanup
        
        // Cancel pending orders
        $stmt = $db->prepare("UPDATE bundle_orders SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending', 'processing')");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    // Common cleanup for all users
    
    // Handle wallet - transfer remaining balance to admin or mark as unclaimed
    $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $wallet_result = $stmt->get_result();
    
    if ($wallet_result->num_rows > 0) {
        $wallet = $wallet_result->fetch_assoc();
        if ($wallet['balance'] > 0) {
            // Log the unclaimed balance
            $stmt = $db->prepare("
                INSERT INTO activity_logs (user_id, action, details, ip_address) 
                VALUES (?, 'account_deleted_with_balance', ?, ?)
            ");
            $details = "Account deleted with remaining balance: " . formatCurrency($wallet['balance']);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt->bind_param("iss", $user_id, $details, $ip);
            $stmt->execute();
        }
    }
    
    // Delete wallet transactions
    $stmt = $db->prepare("DELETE FROM wallet_transactions WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Delete wallet
    $stmt = $db->prepare("DELETE FROM wallets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Handle transactions (keep for audit but anonymize)
    $stmt = $db->prepare("UPDATE transactions SET user_id = NULL, description = CONCAT('DELETED_USER_', ?, '_', description) WHERE user_id = ?");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    
    // Handle bundle orders (keep for audit but anonymize)
    $stmt = $db->prepare("UPDATE bundle_orders SET user_id = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Delete support tickets and messages
    $stmt = $db->prepare("DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ?)");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    $stmt = $db->prepare("DELETE FROM support_tickets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Delete API applications (check if table exists first)
    $tableCheck = $db->query("SHOW TABLES LIKE 'api_applications'");
    if ($tableCheck->num_rows > 0) {
        $stmt = $db->prepare("DELETE FROM api_applications WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    // Delete agent API applications
    $agentTableCheck = $db->query("SHOW TABLES LIKE 'agent_api_applications'");
    if ($agentTableCheck->num_rows > 0) {
        $stmt = $db->prepare("DELETE FROM agent_api_applications WHERE agent_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    // Delete activity logs (optional - you might want to keep these for audit)
    $stmt = $db->prepare("DELETE FROM activity_logs WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Finally, delete the user account
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    $db->getConnection()->commit();
    
    // Log the deletion (system log)
    error_log("Account deleted: User ID $user_id, Role: $user_role, Email: {$current_user['email']}");
    
    // Clear session
    session_destroy();
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Account has been permanently deleted',
        'redirect' => '/login.php'
    ]);
    
} catch (Exception $e) {
    $db->getConnection()->rollback();
    error_log("Account deletion failed for user {$current_user['id']}: " . $e->getMessage());
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'Failed to delete account. Please try again or contact support.'
    ]);
}
?>
