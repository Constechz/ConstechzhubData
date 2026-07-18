<?php
require_once '../config/config.php';
require_once '../includes/email.php';

// Require admin or super admin role
if (function_exists('requireAnyRole')) {
    requireAnyRole(['admin', 'super_admin']);
} else {
    requireRole('admin');
}

// Local helper in case generateStoreSlug() is not globally available
if (!function_exists('generateStoreSlug')) {
    function generateStoreSlug($store_name) {
        $slug = strtolower(trim($store_name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }
}

if (!function_exists('generateUniqueUsername')) {
    function generateUniqueUsername($seed) {
        global $db;
        
        $base = strtolower(trim($seed));
        if (strpos($base, '@') !== false) {
            $base = explode('@', $base)[0];
        }
        $base = preg_replace('/[^a-z0-9]/', '', $base);
        if ($base === '') {
            $base = 'user';
        }
        
        $candidate = $base;
        $counter = 1;
        
        while (true) {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows === 0) {
                return $candidate;
            }
            $candidate = $base . $counter;
            $counter++;
        }
        
        return $base . rand(100, 999);
    }
}

if (!function_exists('generateTemporaryPassword')) {
    function generateTemporaryPassword($length = 10) {
        $length = max(6, (int) $length);
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all = $upper . $lower . $digits;

        $password = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)]
        ];

        for ($i = count($password); $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($password);
        return implode('', $password);
    }
}

// Handle user operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone_raw = sanitize($_POST['phone'] ?? '');
        $role = sanitize($_POST['role'] ?? 'customer');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $is_active = isset($_POST['is_active']) && (int)$_POST['is_active'] === 0 ? 0 : 1;
        $store_name = sanitize($_POST['store_name'] ?? '');
        $assigned_agent_id = isset($_POST['assigned_agent_id']) ? intval($_POST['assigned_agent_id']) : 0;
        
        $errors = [];
        $valid_roles = ['admin', 'agent', 'customer'];
        
        if ($full_name === '') {
            $errors[] = 'Full name is required.';
        }
        
        if ($email === '' || !validateEmail($email)) {
            $errors[] = 'A valid email address is required.';
        }
        
        if ($phone_raw === '' || !validatePhone($phone_raw)) {
            $errors[] = 'A valid phone number is required.';
        }
        
        if (!in_array($role, $valid_roles, true)) {
            $errors[] = 'Invalid role selected.';
        }
        
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        
        if ($password !== $confirm_password) {
            $errors[] = 'Password confirmation does not match.';
        }
        
        if ($role === 'agent' && $store_name === '') {
            $errors[] = 'Store name is required for agents.';
        }
        
        if (!empty($errors)) {
            setFlashMessage('error', implode(' ', $errors));
            header('Location: users.php');
            exit();
        }
        
        // Normalize inputs
        $formatted_phone = formatPhone($phone_raw);
        $store_slug = $role === 'agent' && $store_name !== '' ? generateStoreSlug($store_name) : null;
        
        // Verify uniqueness for email and phone
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            setFlashMessage('error', 'Email address is already registered.');
            header('Location: users.php');
            exit();
        }
        
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
        $stmt->bind_param('s', $formatted_phone);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            setFlashMessage('error', 'Phone number is already registered.');
            header('Location: users.php');
            exit();
        }
        
        if ($store_slug) {
            $stmt = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ? LIMIT 1");
            $stmt->bind_param('s', $store_slug);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                setFlashMessage('error', 'Store slug already exists. Please choose a different store name.');
                header('Location: users.php');
                exit();
            }
        }
        
        $agent_assignment_id = null;
        if ($role === 'customer' && $assigned_agent_id > 0) {
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' LIMIT 1");
            $stmt->bind_param('i', $assigned_agent_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                setFlashMessage('error', 'Selected agent does not exist.');
                header('Location: users.php');
                exit();
            }
            $agent_assignment_id = $assigned_agent_id;
        }
        
        try {
            $db->getConnection()->begin_transaction();
            
            $username = generateUniqueUsername($email !== '' ? $email : $full_name);
            $password_hash = hashPassword($password);
            $status_value = $is_active ? 'active' : 'inactive';
            $activation_status = $is_active ? 'active' : 'pending';
            
            $stmt = $db->prepare("
                INSERT INTO users (username, email, password, full_name, phone, role, status, account_activation_status, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception('Failed to prepare user insert statement.');
            }
            
            $stmt->bind_param(
                'ssssssssi',
                $username,
                $email,
                $password_hash,
                $full_name,
                $formatted_phone,
                $role,
                $status_value,
                $activation_status,
                $is_active
            );
            $stmt->execute();
            
            $new_user_id = $db->getConnection()->insert_id;
            
            if ($role === 'agent' && $store_name !== '') {
                $stmt = $db->prepare("UPDATE users SET store_name = ?, store_slug = ? WHERE id = ?");
                $stmt->bind_param('ssi', $store_name, $store_slug, $new_user_id);
                $stmt->execute();
                
                $stmt = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, is_active) VALUES (?, ?, ?, TRUE)");
                $stmt->bind_param('iss', $new_user_id, $store_name, $store_slug);
                $stmt->execute();
            }
            
            if ($role === 'customer' && $agent_assignment_id) {
                $stmt = $db->prepare("UPDATE users SET agent_id = ?, referring_agent_id = ? WHERE id = ?");
                $stmt->bind_param('iii', $agent_assignment_id, $agent_assignment_id, $new_user_id);
                $stmt->execute();
            }
            
            $stmt = $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
            $stmt->bind_param('i', $new_user_id);
            $stmt->execute();
            
            $details = sprintf('User %s (%s) created manually via admin panel.', $full_name, $email);
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'user_created_manual', ?, ?)");
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $stmt->bind_param('iss', $admin_user_id, $details, $ip_address);
            $stmt->execute();
            
            $db->getConnection()->commit();
            setFlashMessage('success', 'User created successfully.');
        } catch (Exception $e) {
            $db->getConnection()->rollback();
            error_log('Admin create user error: ' . $e->getMessage());
            setFlashMessage('error', 'Failed to create user. Please try again.');
        }
        
        header('Location: users.php');
        exit();
    }

    if ($action === 'update_user_contact') {
        $id = intval($_POST['id'] ?? 0);
        $email = sanitize($_POST['email'] ?? '');
        $phone_raw = sanitize($_POST['phone'] ?? '');

        if ($id <= 0) {
            setFlashMessage('error', 'Invalid user selected.');
            header('Location: users.php');
            exit();
        }

        if ($email === '' || !validateEmail($email)) {
            setFlashMessage('error', 'A valid email address is required.');
            header('Location: users.php');
            exit();
        }

        if ($phone_raw !== '' && !validatePhone($phone_raw)) {
            setFlashMessage('error', 'Please enter a valid phone number.');
            header('Location: users.php');
            exit();
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            setFlashMessage('error', 'Unable to update user.');
            header('Location: users.php');
            exit();
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            setFlashMessage('error', 'User not found.');
            header('Location: users.php');
            exit();
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $email, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                setFlashMessage('error', 'Email address is already registered.');
                header('Location: users.php');
                exit();
            }
        }

        $phone_value = '';
        if ($phone_raw !== '') {
            $phone_value = formatPhone($phone_raw);
            if (dbh_table_has_column('users', 'phone') && dbh_table_has_column('users', 'mobile')) {
                $stmt = $db->prepare("SELECT id FROM users WHERE (phone = ? OR mobile = ?) AND id <> ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ssi', $phone_value, $phone_value, $id);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        setFlashMessage('error', 'Phone number is already registered.');
                        header('Location: users.php');
                        exit();
                    }
                }
            } elseif (dbh_table_has_column('users', 'phone')) {
                $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $phone_value, $id);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        setFlashMessage('error', 'Phone number is already registered.');
                        header('Location: users.php');
                        exit();
                    }
                }
            } elseif (dbh_table_has_column('users', 'mobile')) {
                $stmt = $db->prepare("SELECT id FROM users WHERE mobile = ? AND id <> ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $phone_value, $id);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        setFlashMessage('error', 'Phone number is already registered.');
                        header('Location: users.php');
                        exit();
                    }
                }
            }
        }

        $sets = ['email = ?'];
        $params = [$email];
        $types = 's';

        if (dbh_table_has_column('users', 'phone')) {
            if ($phone_raw === '') {
                $sets[] = 'phone = NULL';
            } else {
                $sets[] = 'phone = ?';
                $params[] = $phone_value;
                $types .= 's';
            }
        }

        if (dbh_table_has_column('users', 'mobile')) {
            if ($phone_raw === '') {
                $sets[] = 'mobile = NULL';
            } else {
                $sets[] = 'mobile = ?';
                $params[] = $phone_value;
                $types .= 's';
            }
        }

        $params[] = $id;
        $types .= 'i';

        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            setFlashMessage('error', 'Failed to update user.');
            header('Location: users.php');
            exit();
        }

        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            ensureEmailChangeRequestsTable();
            $cleanup = $db->prepare("
                UPDATE email_change_requests
                SET status = CASE
                    WHEN LOWER(requested_email) = LOWER(?) THEN 'approved'
                    ELSE 'rejected'
                END,
                reviewed_at = NOW(),
                reviewed_by = ?
                WHERE user_id = ? AND status = 'pending'
            ");
            if ($cleanup) {
                $admin_id = $_SESSION['user_id'] ?? null;
                $cleanup->bind_param('sii', $email, $admin_id, $id);
                $cleanup->execute();
            }

            if (function_exists('logActivity')) {
                $details = sprintf('Admin updated contact info for user ID %d.', $id);
                $admin_id = $_SESSION['user_id'] ?? null;
                logActivity($admin_id, 'user_contact_update_admin', $details);
            }

            setFlashMessage('success', 'User details updated successfully.');
        } else {
            setFlashMessage('error', 'Failed to update user details.');
        }

        header('Location: users.php');
        exit();
    }
    
    if ($action === 'assign_agent') {
        $id = intval($_POST['id'] ?? 0);
        $agent_id_input = isset($_POST['agent_id']) && $_POST['agent_id'] !== '' ? intval($_POST['agent_id']) : 0;
        
        if ($id <= 0) {
            setFlashMessage('error', 'Invalid user selected.');
            header('Location: users.php');
            exit();
        }
        
        $agent_id = null;
        if ($agent_id_input > 0) {
            $agent_check = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' LIMIT 1");
            $agent_check->bind_param('i', $agent_id_input);
            $agent_check->execute();
            if ($agent_check->get_result()->num_rows === 0) {
                setFlashMessage('error', 'Selected agent not found.');
                header('Location: users.php');
                exit();
            }
            $agent_id = $agent_id_input;
        }
        
        if ($agent_id !== null) {
            $stmt = $db->prepare("UPDATE users SET agent_id = ?, referring_agent_id = ? WHERE id = ?");
            $stmt->bind_param('iii', $agent_id, $agent_id, $id);
        } else {
            $stmt = $db->prepare("UPDATE users SET agent_id = NULL, referring_agent_id = NULL WHERE id = ?");
            $stmt->bind_param('i', $id);
        }
        
        if ($stmt->execute()) {
            setFlashMessage('success', 'Agent assignment updated.');
        } else {
            setFlashMessage('error', 'Failed to update agent assignment.');
        }
        
        header('Location: users.php');
        exit();
    }
    
    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            setFlashMessage('success', 'User status updated successfully');
        } else {
            setFlashMessage('error', 'Failed to update user status');
        }
        header('Location: users.php');
        exit();
    }

    if ($action === 'reset_password') {
        $id = intval($_POST['id'] ?? 0);
        $send_email = isset($_POST['send_email']) ? (int) $_POST['send_email'] === 1 : true;

        if ($id <= 0) {
            setFlashMessage('error', 'Invalid user selected.');
            header('Location: users.php');
            exit();
        }

        $stmt = $db->prepare("SELECT id, full_name, username, email FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $target_user = $stmt->get_result()->fetch_assoc();

        if (!$target_user) {
            setFlashMessage('error', 'User not found.');
            header('Location: users.php');
            exit();
        }

        $temporary_password = generateTemporaryPassword(10);
        $password_hash = hashPassword($temporary_password);

        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param('si', $password_hash, $id);

        if ($stmt->execute()) {
            $email_sent = false;
            if ($send_email && !empty($target_user['email']) && validateEmail($target_user['email'])) {
                $email_result = sendAdminPasswordResetEmail(
                    $target_user['email'],
                    $target_user['full_name'] ?: $target_user['username'],
                    $temporary_password
                );
                $email_sent = is_array($email_result) ? !empty($email_result['success']) : (bool) $email_result;
            }

            $email_status = $send_email ? ($email_sent ? 'yes' : 'no') : 'not_requested';
            $details = sprintf(
                'Admin reset password for user %s (%s). Email sent: %s.',
                $target_user['full_name'] ?: $target_user['username'],
                $target_user['email'],
                $email_status
            );
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $log_stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'user_password_reset_admin', ?, ?)");
            if ($log_stmt) {
                $log_stmt->bind_param('iss', $admin_user_id, $details, $ip_address);
                $log_stmt->execute();
            }

            if ($send_email) {
                if ($email_sent) {
                    setFlashMessage('success', 'Password reset successfully and emailed to the user.');
                } else {
                    setFlashMessage('error', 'Password reset successfully, but email failed. Temporary password: ' . $temporary_password);
                }
            } else {
                setFlashMessage('success', 'Password reset successfully. Temporary password: ' . $temporary_password);
            }
        } else {
            setFlashMessage('error', 'Failed to reset password. Please try again.');
        }

        header('Location: users.php');
        exit();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        
        // Prevent admin from deleting their own account
        if ($id === $_SESSION['user_id']) {
            setFlashMessage('error', 'You cannot delete your own account');
            header('Location: users.php');
            exit();
        }
        
        // Get user details before deletion
        $stmt = $db->prepare("SELECT username, role FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $user_to_delete = $stmt->get_result()->fetch_assoc();
        
        if (!$user_to_delete) {
            setFlashMessage('error', 'User not found');
            header('Location: users.php');
            exit();
        }
        
        // Begin transaction for clean deletion
        $db->getConnection()->begin_transaction();
        
        try {
            // Handle role-specific cleanup
            if ($user_to_delete['role'] === 'agent') {
                // Update customers to remove agent assignment
                if (dbh_table_has_column('users', 'agent_id')) {
                    $stmt = $db->prepare("UPDATE users SET agent_id = NULL WHERE agent_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
                
                // Delete agent-specific data
                if (dbh_table_exists('agent_custom_pricing')) {
                    $stmt = $db->prepare("DELETE FROM agent_custom_pricing WHERE agent_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
                
                if (dbh_table_exists('agent_paystack_settings')) {
                    $stmt = $db->prepare("DELETE FROM agent_paystack_settings WHERE agent_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
                
                if (dbh_table_exists('agent_stores')) {
                    $stmt = $db->prepare("DELETE FROM agent_stores WHERE agent_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
                
                // Mark commissions as orphaned rather than delete for audit
                if (dbh_table_exists('commissions') && dbh_table_has_column('commissions', 'agent_id')) {
                    $stmt = $db->prepare("UPDATE commissions SET status = 'orphaned' WHERE agent_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
            }
            
            if ($user_to_delete['role'] === 'customer') {
                // Cancel pending orders
                if (dbh_table_has_column('bundle_orders', 'user_id')) {
                    $stmt = $db->prepare("UPDATE bundle_orders SET status = 'cancelled' WHERE user_id = ? AND status IN ('pending', 'processing')");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                }
            }
            
            // Handle wallet - keep balance record for audit
            if (dbh_table_exists('wallets')) {
                $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $wallet_result = $stmt->get_result();
                
                if ($wallet_result->num_rows > 0) {
                    $wallet = $wallet_result->fetch_assoc();
                    if ($wallet['balance'] > 0 && dbh_table_exists('activity_logs')) {
                        // Log the deletion with balance info
                        $stmt = $db->prepare("
                            INSERT INTO activity_logs (user_id, action, details, ip_address) 
                            VALUES (?, 'user_deleted_by_admin', ?, ?)
                        ");
                        $details = "User {$user_to_delete['username']} deleted by admin with remaining balance: " . CURRENCY . number_format($wallet['balance'], 2);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $stmt->bind_param("iss", $_SESSION['user_id'], $details, $ip);
                        $stmt->execute();
                    }
                }
            }
            
            // Delete wallet transactions
            if (dbh_table_exists('wallet_transactions')) {
                $stmt = $db->prepare("DELETE FROM wallet_transactions WHERE wallet_id IN (SELECT id FROM wallets WHERE user_id = ?)");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            // Delete wallet
            if (dbh_table_exists('wallets')) {
                $stmt = $db->prepare("DELETE FROM wallets WHERE user_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            // Anonymize transactions (keep for audit but remove user reference)
            if (dbh_table_has_column('transactions', 'user_id')) {
                if (dbh_column_allows_null('transactions', 'user_id')) {
                    $stmt = $db->prepare("UPDATE transactions SET user_id = NULL, description = CONCAT('DELETED_USER_', ?, '_', description) WHERE user_id = ?");
                    $stmt->bind_param("ii", $id, $id);
                } else {
                    $stmt = $db->prepare("UPDATE transactions SET description = CONCAT('DELETED_USER_', ?, '_', description) WHERE user_id = ?");
                    $stmt->bind_param("ii", $id, $id);
                }
                $stmt->execute();
            }
            
            // Anonymize bundle orders
            if (dbh_table_has_column('bundle_orders', 'user_id')) {
                if (dbh_column_allows_null('bundle_orders', 'user_id')) {
                    $stmt = $db->prepare("UPDATE bundle_orders SET user_id = NULL WHERE user_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                } else {
                    // Column is NOT NULL ??? rely on cascading deletes but tag records before removal
                    $stmt = $db->prepare("UPDATE bundle_orders SET order_reference = CONCAT('DELETED_USER_', ?, '_', order_reference) WHERE user_id = ?");
                    $stmt->bind_param("ii", $id, $id);
                    $stmt->execute();
                }
            }
            
            // Delete support tickets and messages
            if (dbh_table_exists('support_messages') && dbh_table_exists('support_tickets')) {
                $stmt = $db->prepare("DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ?)");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            if (dbh_table_exists('support_tickets')) {
                $stmt = $db->prepare("DELETE FROM support_tickets WHERE user_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            // Delete API applications (check if table exists first)
            if (dbh_table_exists('api_applications')) {
                $stmt = $db->prepare("DELETE FROM api_applications WHERE user_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            // Delete agent API applications
            if (dbh_table_exists('agent_api_applications')) {
                $stmt = $db->prepare("DELETE FROM agent_api_applications WHERE agent_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
            }
            
            // Finally, delete the user
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            $db->getConnection()->commit();
            setFlashMessage('success', "User '{$user_to_delete['username']}' deleted successfully with all associated data cleaned up.");
        } catch (Exception $e) {
            $db->getConnection()->rollback();
            setFlashMessage('error', 'Failed to delete user: ' . $e->getMessage());
        }
        
        header('Location: users.php');
        exit();
    }

    if ($action === 'convert_to_agent') {
        $id = intval($_POST['id'] ?? 0);
        $store_name = trim($_POST['store_name'] ?? '');
        if ($id <= 0 || $store_name === '') {
            setFlashMessage('error', 'User ID and Store Name are required.');
            header('Location: users.php');
            exit();
        }

        // Ensure user exists
        $stmt = $db->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $usr = $stmt->get_result()->fetch_assoc();
        if (!$usr) {
            setFlashMessage('error', 'User not found.');
            header('Location: users.php');
            exit();
        }

        // Begin transaction
        $conn = $db->getConnection();
        $conn->begin_transaction();
        try {
            // Promote to agent if not already
            if ($usr['role'] !== 'agent') {
                $stmt = $db->prepare("UPDATE users SET role = 'agent', is_active = 1 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
            }

            // Generate unique slug
            $base_slug = generateStoreSlug($store_name);
            $slug = $base_slug;
            $suffix = 1;
            $check = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ? LIMIT 1");
            do {
                $check->bind_param('s', $slug);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                if ($exists) { $slug = $base_slug . '-' . $suffix++; }
            } while ($exists);

            // Upsert agent store
            $stmt = $db->prepare("SELECT id FROM agent_stores WHERE agent_id = ? LIMIT 1");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $stmt = $db->prepare("UPDATE agent_stores SET store_name = ?, store_slug = ?, is_active = TRUE WHERE id = ?");
                $stmt->bind_param('ssi', $store_name, $slug, $row['id']);
                $stmt->execute();
            } else {
                $stmt = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, is_active) VALUES (?, ?, ?, TRUE)");
                $stmt->bind_param('iss', $id, $store_name, $slug);
                $stmt->execute();
            }

            $conn->commit();
            setFlashMessage('success', 'User converted to agent and store set successfully.');
        } catch (Exception $e) {
            $conn->rollback();
            setFlashMessage('error', 'Failed to convert to agent: ' . $e->getMessage());
        }

        header('Location: users.php');
        exit();
    }

    if ($action === 'set_store_name') {
        $id = intval($_POST['id'] ?? 0);
        $store_name = trim($_POST['store_name'] ?? '');
        if ($id <= 0 || $store_name === '') {
            setFlashMessage('error', 'User ID and Store Name are required.');
            header('Location: users.php');
            exit();
        }

        // Ensure user is agent
        $stmt = $db->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $usr = $stmt->get_result()->fetch_assoc();
        if (!$usr || $usr['role'] !== 'agent') {
            setFlashMessage('error', 'User must be an agent to set a store name.');
            header('Location: users.php');
            exit();
        }

        // Generate unique slug
        $base_slug = generateStoreSlug($store_name);
        $slug = $base_slug;
        $suffix = 1;
        $check = $db->prepare("SELECT id FROM agent_stores WHERE store_slug = ? AND agent_id <> ? LIMIT 1");
        do {
            $check->bind_param('si', $slug, $id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            if ($exists) { $slug = $base_slug . '-' . $suffix++; }
        } while ($exists);

        // Upsert
        $stmt = $db->prepare("SELECT id FROM agent_stores WHERE agent_id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $stmt = $db->prepare("UPDATE agent_stores SET store_name = ?, store_slug = ?, is_active = TRUE WHERE id = ?");
            $stmt->bind_param('ssi', $store_name, $slug, $row['id']);
            $ok = $stmt->execute();
        } else {
            $stmt = $db->prepare("INSERT INTO agent_stores (agent_id, store_name, store_slug, is_active) VALUES (?, ?, ?, TRUE)");
            $stmt->bind_param('iss', $id, $store_name, $slug);
            $ok = $stmt->execute();
        }

        if ($ok) {
            setFlashMessage('success', 'Store name updated successfully.');
        } else {
            setFlashMessage('error', 'Failed to update store name.');
        }
        header('Location: users.php');
        exit();
    }
}

// Fetch filters
$selected_role = isset($_GET['role']) ? sanitize($_GET['role']) : '';
$selected_status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$per_page = max(10, min(200, $per_page));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = 0;

// Fetch users with wallet info
$where = "WHERE 1=1";

$params = [];
$types = '';

if ($selected_role !== '') {
    $where .= " AND u.role = ?";
    $params[] = $selected_role;
    $types .= 's';
}

if ($selected_status !== '') {
    $is_active = $selected_status === 'active' ? 1 : 0;
    $where .= " AND u.is_active = ?";
    $params[] = $is_active;
    $types .= 'i';
}

if ($search !== '') {
    $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

// Count total users (for pagination) without expensive joins
$count_query = "SELECT COUNT(*) as total FROM users u {$where}";
$count_stmt = $db->prepare($count_query);
if ($count_stmt && $types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
if ($count_stmt) {
    $count_stmt->execute();
    $count_row = $count_stmt->get_result()->fetch_assoc();
    $total_users = (int)($count_row['total'] ?? 0);
} else {
    $total_users = 0;
}

$total_pages = $per_page > 0 ? (int)ceil(max(1, $total_users) / $per_page) : 1;
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$query = "
    SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at, u.agent_id,
           COALESCE(w.balance, 0) as wallet_balance,
           COALESCE(bo.total_orders, 0) as total_orders,
           COALESCE(t.total_transactions, 0) as total_transactions,
           ast.store_name, ast.store_slug
    FROM users u
    LEFT JOIN wallets w ON w.user_id = u.id
    LEFT JOIN (
        SELECT user_id, COUNT(*) as total_orders
        FROM bundle_orders
        GROUP BY user_id
    ) bo ON bo.user_id = u.id
    LEFT JOIN (
        SELECT user_id, COUNT(*) as total_transactions
        FROM transactions
        GROUP BY user_id
    ) t ON t.user_id = u.id
    LEFT JOIN agent_stores ast ON ast.agent_id = u.id
    {$where}
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
";

$params_with_limit = array_merge($params, [$per_page, $offset]);
$types_with_limit = $types . 'ii';
$stmt = $db->prepare($query);
$stmt->bind_param($types_with_limit, ...$params_with_limit);
$stmt->execute();
$users_rs = $stmt->get_result();

$users = [];
while ($row = $users_rs->fetch_assoc()) { $users[] = $row; }

$start_index = $total_users > 0 ? ($offset + 1) : 0;
$end_index = min($offset + $per_page, $total_users);

$agents = [];
$agent_stmt = $db->prepare("SELECT id, full_name, username FROM users WHERE role = 'agent' ORDER BY full_name ASC");
if ($agent_stmt && $agent_stmt->execute()) {
    $agent_result = $agent_stmt->get_result();
    while ($agent_row = $agent_result->fetch_assoc()) {
        $agents[] = $agent_row;
    }
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link active"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notification Settings</a></div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-users"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">User Management</div>
                </nav>
            </div>
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
                            </div>
                            <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                        </button>
                        
                        <div class="user-dropdown-menu" id="userDropdown">
                            <a href="profile.php" class="dropdown-item">
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="settings.php" class="dropdown-item">
                                <i class="fas fa-cog"></i> Settings
                            </a>
                            <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                            <a href="../logout.php" class="dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>User Management</h1>
                <p class="page-subtitle">Manage all system users, customers, and agents.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Users List -->
            <div class="widget">
                    <div class="widget-header stacked-header">
                    <div class="widget-header-main">
                        <h3 class="widget-title">All Users (<?php echo number_format($total_users); ?>)</h3>
                        <div class="widget-actions">
                            <button type="button" class="btn btn-primary" onclick="openUserModal()">
                                <i class="fas fa-user-plus"></i>
                                <span class="btn-label">Add User</span>
                            </button>
                        </div>
                    </div>
                    <form method="get" class="form-inline user-filter-form">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search users..." class="form-control">
                        <select name="role" class="form-control" onchange="this.form.submit()">
                            <option value="">All Roles</option>
                            <option value="customer" <?php echo $selected_role==='customer'?'selected':''; ?>>Customer</option>
                            <option value="agent" <?php echo $selected_role==='agent'?'selected':''; ?>>Agent</option>
                            <option value="admin" <?php echo $selected_role==='admin'?'selected':''; ?>>Admin</option>
                        </select>
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $selected_status==='active'?'selected':''; ?>>Active</option>
                            <option value="inactive" <?php echo $selected_status==='inactive'?'selected':''; ?>>Inactive</option>
                        </select>
                        <select name="per_page" class="form-control" onchange="this.form.submit()">
                            <?php foreach ([25, 50, 100, 200] as $size): ?>
                                <option value="<?php echo $size; ?>" <?php echo $per_page === $size ? 'selected' : ''; ?>>
                                    <?php echo $size; ?> per page
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                </div>
                <div class="widget-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Wallet</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No users found</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td data-label="ID"><?php echo $user['id']; ?></td>
                                        <td data-label="User">
                                            <div class="user-summary">
                                                <span class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
                                                <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Role">
                                            <span class="badge badge-<?php echo $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'agent' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="badge badge-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td data-label="Wallet"><?php echo CURRENCY . number_format($user['wallet_balance'], 2); ?></td>
                                        <td data-label="Actions">
                                            <div class="table-actions">
                                                <button type="button" class="btn btn-info btn-sm" title="View Details" onclick="openUserDetails(<?php echo $user['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn btn-<?php echo $user['is_active'] ? 'warning' : 'success'; ?> btn-sm" title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?> User">
                                                        <i class="fas fa-<?php echo $user['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                    </button>
                                                </form>
                                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                <form method="post" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-actions" style="justify-content: space-between; margin-top: 1rem;">
                        <div class="text-muted">
                            Showing <?php echo $start_index; ?>-<?php echo $end_index; ?> of <?php echo number_format($total_users); ?>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="btn-group">
                                <?php
                                    $query_params = $_GET;
                                    $prev_page = max(1, $page - 1);
                                    $next_page = min($total_pages, $page + 1);
                                ?>
                                <?php $query_params['page'] = $prev_page; ?>
                                <a class="btn btn-outline btn-sm" href="users.php?<?php echo http_build_query($query_params); ?>">Prev</a>
                                <span class="btn btn-sm btn-secondary" style="cursor: default;">
                                    Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                                </span>
                                <?php $query_params['page'] = $next_page; ?>
                                <a class="btn btn-outline btn-sm" href="users.php?<?php echo http_build_query($query_params); ?>">Next</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php foreach ($users as $user): ?>
    <div id="userDetailsModal_<?php echo $user['id']; ?>" class="modal" style="display: none;">
        <div class="modal-content modal-wide">
            <span class="close" onclick="closeUserDetails(<?php echo $user['id']; ?>)">&times;</span>
            <h2>User Details</h2>
            
            <div class="detail-grid">
                <div class="detail-card">
                    <h3>Profile</h3>
                    <dl class="detail-list">
                        <div><dt>User ID</dt><dd><?php echo $user['id']; ?></dd></div>
                        <div><dt>Full Name</dt><dd><?php echo htmlspecialchars($user['full_name'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Username</dt><dd><?php echo htmlspecialchars($user['username']); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo htmlspecialchars($user['email']); ?></dd></div>
                        <div><dt>Phone</dt><dd><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Role</dt><dd><?php echo ucfirst($user['role']); ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?></dd></div>
                        <div><dt>Joined</dt><dd><?php echo date('M j, Y H:i', strtotime($user['created_at'])); ?></dd></div>
                    </dl>
                </div>
                
                <div class="detail-card">
                    <h3>Performance</h3>
                    <dl class="detail-list">
                        <div><dt>Wallet Balance</dt><dd><?php echo CURRENCY . number_format($user['wallet_balance'], 2); ?></dd></div>
                        <div><dt>Total Orders</dt><dd><?php echo intval($user['total_orders']); ?></dd></div>
                        <div><dt>Total Transactions</dt><dd><?php echo intval($user['total_transactions']); ?></dd></div>
                        <div><dt>Store Name</dt><dd><?php echo htmlspecialchars($user['store_name'] ?? 'N/A'); ?></dd></div>
                        <div><dt>Store Slug</dt><dd><?php echo htmlspecialchars($user['store_slug'] ?? 'N/A'); ?></dd></div>
                    </dl>
                </div>
            </div>

            <div class="detail-actions">
                <div class="detail-form-card">
                    <h4>Update Contact</h4>
                    <form method="post" class="detail-form">
                        <input type="hidden" name="action" value="update_user_contact">
                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                        <label class="form-label" for="edit_email_<?php echo $user['id']; ?>">Email</label>
                        <input type="email" id="edit_email_<?php echo $user['id']; ?>" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        <label class="form-label" for="edit_phone_<?php echo $user['id']; ?>">Phone</label>
                        <input type="tel" id="edit_phone_<?php echo $user['id']; ?>" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="e.g. 0541234567">
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-primary">Save Contact</button>
                        </div>
                        <small class="form-help">Updating email/phone here bypasses user approval.</small>
                    </form>
                </div>

                <?php if ($user['role'] !== 'agent'): ?>
                    <div class="detail-form-card">
                        <h4>Convert to Agent</h4>
                        <form method="post" class="detail-form">
                            <input type="hidden" name="action" value="convert_to_agent">
                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                            <label class="form-label" for="convert_store_<?php echo $user['id']; ?>">Store Name</label>
                            <input type="text" id="convert_store_<?php echo $user['id']; ?>" name="store_name" class="form-control store-name-input" placeholder="Store Name" required>
                            <div class="form-actions compact">
                                <button type="submit" class="btn btn-info">Convert User</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="detail-form-card">
                        <h4>Update Store</h4>
                        <form method="post" class="detail-form">
                            <input type="hidden" name="action" value="set_store_name">
                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                            <label class="form-label" for="update_store_<?php echo $user['id']; ?>">Store Name</label>
                            <input type="text" id="update_store_<?php echo $user['id']; ?>" name="store_name" class="form-control store-name-input" value="<?php echo htmlspecialchars($user['store_name'] ?? ''); ?>" placeholder="Store Name" required>
                            <div class="form-actions compact">
                                <button type="submit" class="btn btn-primary">Save Store</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="detail-form-card">
                    <h4>Reset Password</h4>
                    <form method="post" class="detail-form" onsubmit="return confirm('Reset password for this user?');">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                        <label class="form-label">Delivery</label>
                        <label class="checkbox-inline">
                            <input type="checkbox" name="send_email" value="1" checked>
                            Email the temporary password to this user.
                        </label>
                        <div class="form-actions compact">
                            <button type="submit" class="btn btn-warning">Reset Password</button>
                        </div>
                    </form>
                    <small class="form-help">A temporary password will be generated. Ask the user to change it after login.</small>
                </div>
                
                <?php if ($user['role'] === 'customer' && !empty($agents)): ?>
                    <div class="detail-form-card">
                        <h4>Assign Agent</h4>
                        <form method="post" class="detail-form">
                            <input type="hidden" name="action" value="assign_agent">
                            <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                            <label class="form-label" for="assign_agent_<?php echo $user['id']; ?>">Select Agent</label>
                            <select id="assign_agent_<?php echo $user['id']; ?>" name="agent_id" class="form-control">
                                <option value="">No agent</option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo $agent['id']; ?>" <?php echo (isset($user['agent_id']) && $user['agent_id'] == $agent['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($agent['full_name'] ?? $agent['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-actions compact">
                                <button type="submit" class="btn btn-secondary">Update Assignment</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Create User Modal -->
<div id="userModal" class="modal" style="display: none;">
    <div class="modal-content modal-wide">
        <span class="close" onclick="closeUserModal()">&times;</span>
        <h2>Create New User</h2>
        <form method="POST" id="createUserForm" class="modal-form">
            <input type="hidden" name="action" value="create_user">
            <div class="form-grid two-columns">
                <div class="form-group">
                    <label class="form-label" for="user_full_name">Full Name</label>
                    <input type="text" id="user_full_name" name="full_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_email">Email</label>
                    <input type="email" id="user_email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_phone">Phone Number</label>
                    <input type="tel" id="user_phone" name="phone" class="form-control" placeholder="e.g. 0541234567" required>
                    <small class="form-help">Use Ghana format with or without leading zero.</small>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_role">Role</label>
                    <select id="user_role" name="role" class="form-control" required>
                        <option value="customer" selected>Customer</option>
                        <option value="agent">Agent</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="user_password" name="password" class="form-control" minlength="6" required>
                        <button type="button" class="password-toggle" data-target="user_password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_password_confirm">Confirm Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="user_password_confirm" name="confirm_password" class="form-control" minlength="6" required>
                        <button type="button" class="password-toggle" data-target="user_password_confirm" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="user_status">Account Status</label>
                    <select id="user_status" name="is_active" class="form-control">
                        <option value="1" selected>Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="form-group role-field role-customer" style="display: none;">
                    <label class="form-label" for="assigned_agent_id">Assign Agent (optional)</label>
                    <select id="assigned_agent_id" name="assigned_agent_id" class="form-control">
                        <option value="0">No agent</option>
                        <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>">
                                <?php echo htmlspecialchars($agent['full_name'] ?? $agent['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group role-field role-agent full-width" style="display: none;">
                    <label class="form-label" for="agent_store_name">Store Name</label>
                    <input type="text" id="agent_store_name" name="store_name" class="form-control" placeholder="Agent store name">
                    <small class="form-help">Used for storefront URL slug.</small>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });
    
    function refreshModalOpenState() {
        const openModal = Array.from(document.querySelectorAll('.modal')).some(modal => modal.style.display === 'block');
        if (openModal) {
            document.body.classList.add('modal-open');
        } else {
            document.body.classList.remove('modal-open');
        }
    }

    function openUserModal() {
        const modal = document.getElementById('userModal');
        if (modal) {
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
        const roleSelect = document.getElementById('user_role');
        if (roleSelect) {
            handleRoleFields(roleSelect.value);
        }
    }

    function closeUserModal() {
        const modal = document.getElementById('userModal');
        if (modal) {
            modal.style.display = 'none';
        }
        const form = document.getElementById('createUserForm');
        if (form) {
            form.reset();
        }
        handleRoleFields('customer');
        refreshModalOpenState();
    }

    function openUserDetails(userId) {
        const modal = document.getElementById(`userDetailsModal_${userId}`);
        if (modal) {
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    function closeUserDetails(userId) {
        const modal = document.getElementById(`userDetailsModal_${userId}`);
        if (modal) {
            modal.style.display = 'none';
            refreshModalOpenState();
        }
    }

    function handleRoleFields(role) {
        const roleFields = document.querySelectorAll('.role-field');
        roleFields.forEach(function(field) {
            const isMatch = field.classList.contains('role-' + role);
            field.style.display = isMatch ? '' : 'none';
            const input = field.querySelector('input, select, textarea');
            if (input) {
                if (field.classList.contains('role-agent')) {
                    input.required = isMatch;
                }
                if (!isMatch) {
                    if (input.tagName === 'SELECT') {
                        if (input.options.length > 0) {
                            input.selectedIndex = 0;
                        }
                    } else {
                        input.value = '';
                    }
                }
            }
        });
    }
    
    // Theme management - consistent across all pages
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        
        document.documentElement.setAttribute('data-theme', theme);
        updateThemeIcon(theme);
    }

    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
    }

    function updateThemeIcon(theme) {
        const icon = document.getElementById('theme-icon');
        if (icon) {
            // Show OPPOSITE icon: moon for light theme (to switch TO dark), sun for dark theme (to switch TO light)
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
    }

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
        
        const roleSelect = document.getElementById('user_role');
        if (roleSelect) {
            handleRoleFields(roleSelect.value);
            roleSelect.addEventListener('change', function() {
                handleRoleFields(this.value);
            });
        }
        
        window.addEventListener('click', function(event) {
            if (event.target.classList && event.target.classList.contains('modal')) {
                if (event.target.id === 'userModal') {
                    closeUserModal();
                } else if (event.target.id && event.target.id.startsWith('userDetailsModal_')) {
                    event.target.style.display = 'none';
                    refreshModalOpenState();
                }
            }
        });
    });
</script>

<style>
.stacked-header {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.stacked-header .widget-header-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.widget-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    white-space: nowrap;
}

.user-summary {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

/* Dark mode contrast fixes for Role/Status badges in users table */
[data-theme="dark"] .table .badge {
    border: 1px solid rgba(241, 233, 218, 0.22);
    font-weight: 600;
}

[data-theme="dark"] .table .badge-danger {
    background-color: rgba(217, 3, 104, 0.35);
    color: #ffe5f2;
}

[data-theme="dark"] .table .badge-warning {
    background-color: rgba(255, 212, 0, 0.36);
    color: #2e294e;
}

[data-theme="dark"] .table .badge-info {
    background-color: rgba(84, 19, 136, 0.52);
    color: #f1e9da;
}

[data-theme="dark"] .table .badge-success {
    background-color: rgba(24, 160, 88, 0.42);
    color: #e9fff3;
}

[data-theme="dark"] .table .badge-secondary {
    background-color: rgba(241, 233, 218, 0.24);
    color: #f1e9da;
}

.user-name {
    font-weight: 600;
    color: var(--text-color, #2E294E);
}

[data-theme="dark"] .user-name {
    color: #F1E9DA;
}

.user-email {
    font-size: 0.8rem;
    color: var(--text-muted, #541388);
}

.user-summary {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.table-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.table-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
}

.inline-form {
    display: inline;
}

.user-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    width: 100%;
}

.user-filter-form .form-control {
    min-width: 160px;
}

.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(46, 41, 78, 0.45);
    z-index: 10000;
    display: none;
    padding: 2rem 1rem;
    overflow-y: auto;
}

.modal-open {
    overflow: hidden;
}

.modal-content {
    background: var(--card-bg, #F1E9DA);
    border-radius: 10px;
    margin: 0 auto;
    padding: 24px;
    max-width: 580px;
    box-shadow: 0 10px 40px rgba(46, 41, 78, 0.2);
    position: relative;
}

.modal-content.modal-wide {
    max-width: 760px;
}

[data-theme="dark"] .modal-content {
    background: #2E294E;
    color: #F1E9DA;
}

.modal-content .close {
    position: absolute;
    top: 16px;
    right: 20px;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--text-muted, #541388);
}

[data-theme="dark"] .modal-content .close {
    color: #F1E9DA;
}

.modal-form h2 {
    margin-bottom: 1.5rem;
}

.modal-form .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.modal-form .form-group.full-width,
.modal-form .role-field.full-width {
    grid-column: 1 / -1;
}

.form-help {
    display: block;
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: var(--text-muted, #541388);
}

[data-theme="dark"] .form-help {
    color: #F1E9DA;
}

.modal-form .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color, #F1E9DA);
    position: sticky;
    bottom: 0;
    background: inherit;
}

[data-theme="dark"] .modal-form .form-actions {
    border-top-color: #2E294E;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.25rem;
    margin-bottom: 1.5rem;
}

.detail-card {
    background: var(--card-muted-bg, #F1E9DA);
    border: 1px solid var(--border-color, #F1E9DA);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

[data-theme="dark"] .detail-card {
    background: #2E294E;
    border-color: #2E294E;
}

.detail-card h3 {
    margin-top: 0;
    margin-bottom: 0.75rem;
}

.detail-list {
    margin: 0;
}

.detail-list div {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.detail-list dt {
    font-weight: 600;
    color: var(--text-muted, #541388);
}

.detail-list dd {
    margin: 0;
    text-align: right;
    color: var(--text-color, #2E294E);
}

[data-theme="dark"] .detail-list dd {
    color: #F1E9DA;
}

.detail-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.detail-form-card {
    border: 1px solid var(--border-color, #F1E9DA);
    border-radius: 8px;
    padding: 1rem;
    background: var(--card-bg, #F1E9DA);
}

[data-theme="dark"] .detail-form-card {
    border-color: #2E294E;
    background: #2E294E;
}

.detail-form-card h4 {
    margin: 0 0 0.75rem 0;
}

.detail-form-card .form-help {
    display: block;
    margin-top: 0.5rem;
    color: var(--text-muted, #541388);
    font-size: 0.85rem;
}

.checkbox-inline {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-muted, #541388);
    font-size: 0.9rem;
}

.checkbox-inline input {
    margin: 0;
}

.detail-form .form-actions.compact {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-color, #F1E9DA);
}

[data-theme="dark"] .detail-form .form-actions.compact {
    border-top-color: #2E294E;
}

.detail-form .form-actions.compact button {
    min-width: 0;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

@media (max-width: 992px) {
    .user-filter-form .form-control {
        min-width: 140px;
    }
}

@media (max-width: 768px) {
    .stacked-header .widget-header-main {
        align-items: stretch;
    }
    
    .widget-actions {
        width: 100%;
    }

    .user-filter-form {
        flex-direction: column;
        align-items: stretch;
    }

    .user-filter-form .form-control,
    .user-filter-form .btn {
        width: 100%;
        min-width: 0;
    }

    .table-responsive {
        overflow-x: visible;
    }

    .table-responsive .table {
        min-width: 0;
        width: 100%;
    }

    .table-responsive table,
    .table-responsive thead,
    .table-responsive tbody,
    .table-responsive th,
    .table-responsive td,
    .table-responsive tr {
        display: block;
    }

    .table-responsive thead {
        display: none;
    }

    .table-responsive tbody tr {
        margin-bottom: 1rem;
        border: 1px solid var(--border-color, #F1E9DA);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        background: var(--card-bg, #F1E9DA);
    }

    [data-theme="dark"] .table-responsive tbody tr {
        background: #2E294E;
        border-color: #2E294E;
    }

    .table-responsive tbody td {
        border: none;
        padding: 0.5rem 0;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.35rem;
        white-space: normal;
        word-break: break-word;
    }

    .table-responsive tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-muted, #541388);
    }

    .table-responsive tbody td[data-label="Actions"] {
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .modal-form .form-grid {
        grid-template-columns: 1fr;
    }

    .detail-list div {
        flex-direction: column;
        align-items: flex-start;
    }

    .detail-list dd {
        text-align: left;
    }
}

@media (max-width: 576px) {
    .modal {
        padding: 1rem 0.75rem;
    }

    .modal-content,
    .modal-content.modal-wide {
        max-width: 100%;
        padding: 1.25rem;
    }

    .detail-actions {
        grid-template-columns: 1fr;
    }

    .table-actions .btn {
        min-width: 40px;
        min-height: 40px;
    }
}

/* Store name input field fixes for both light and dark themes */
.store-name-input {
    color: var(--text-primary) !important;
    background-color: var(--bg-primary) !important;
    border: 1px solid var(--border-color) !important;
}

.store-name-input:focus {
    color: var(--text-primary) !important;
    background-color: var(--bg-primary) !important;
    border-color: var(--brand-primary) !important;
    box-shadow: 0 0 0 0.2rem rgba(84, 19, 136, 0.25) !important;
}

.store-name-input::placeholder {
    color: var(--text-muted) !important;
    opacity: 0.7;
}

/* Dark theme specific adjustments */
[data-theme="dark"] .store-name-input {
    color: var(--dark-text) !important;
    background-color: var(--dark-bg) !important;
    border-color: var(--border-color) !important;
}

[data-theme="dark"] .store-name-input:focus {
    color: var(--dark-text) !important;
    background-color: var(--dark-bg) !important;
}

[data-theme="dark"] .store-name-input::placeholder {
    color: var(--dark-text-muted) !important;
}
</style>
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/password-toggle.js')); ?>""></script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



