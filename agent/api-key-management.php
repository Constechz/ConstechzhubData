<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and is an agent
if (!isLoggedIn() || $_SESSION['user_role'] !== 'agent') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$current_user = getCurrentUser();
$agent_id = $current_user['id'];

// Handle API key generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_key'])) {
    $key_name = trim($_POST['key_name']);
    $application_id = (int)$_POST['application_id'];
    
    if (empty($key_name)) {
        setFlashMessage('error', 'Key name is required.');
    } else {
        // Verify agent has approved application
        $app_stmt = $db->prepare("SELECT id FROM agent_api_applications WHERE id = ? AND agent_id = ? AND status = 'approved'");
        $app_stmt->bind_param('ii', $application_id, $agent_id);
        $app_stmt->execute();
        $app_result = $app_stmt->get_result()->fetch_assoc();
        
        if ($app_result) {
            // Generate API key and secret
            $api_key = 'dbh_' . bin2hex(random_bytes(24));
            $api_secret = bin2hex(random_bytes(32));
            
            $stmt = $db->prepare("INSERT INTO agent_api_keys (agent_id, application_id, api_key, api_secret, key_name) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisss', $agent_id, $application_id, $api_key, $api_secret, $key_name);
            
            if ($stmt->execute()) {
                setFlashMessage('success', 'API key generated successfully.');
            } else {
                setFlashMessage('error', 'Failed to generate API key. Please try again.');
            }
        } else {
            setFlashMessage('error', 'No approved application found.');
        }
    }
    
    header('Location: api-access.php');
    exit();
}

// Handle key deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_key'])) {
    $key_id = (int)$_POST['key_id'];
    
    $stmt = $db->prepare("UPDATE agent_api_keys SET is_active = 0 WHERE id = ? AND agent_id = ?");
    $stmt->bind_param('ii', $key_id, $agent_id);
    
    if ($stmt->execute()) {
        setFlashMessage('success', 'API key deactivated successfully.');
    } else {
        setFlashMessage('error', 'Failed to deactivate API key.');
    }
    
    header('Location: api-access.php');
    exit();
}

// This file handles AJAX requests for key management
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_key_stats':
            $key_id = (int)$_GET['key_id'];
            
            // Get key usage statistics
            $stats_stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN response_code = 200 THEN 1 END) as successful_requests,
                    COUNT(CASE WHEN response_code != 200 THEN 1 END) as failed_requests,
                    AVG(processing_time_ms) as avg_processing_time,
                    MAX(created_at) as last_request_at,
                    DATE(created_at) as request_date,
                    COUNT(*) as daily_count
                FROM agent_api_usage_logs 
                WHERE api_key_id = ? AND agent_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY request_date DESC
                LIMIT 30
            ");
            $stats_stmt->bind_param('ii', $key_id, $agent_id);
            $stats_stmt->execute();
            $daily_stats = $stats_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Get overall stats
            $overall_stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_requests,
                    COUNT(CASE WHEN response_code = 200 THEN 1 END) as successful_requests,
                    COUNT(CASE WHEN response_code != 200 THEN 1 END) as failed_requests,
                    AVG(processing_time_ms) as avg_processing_time,
                    MAX(created_at) as last_request_at
                FROM agent_api_usage_logs 
                WHERE api_key_id = ? AND agent_id = ?
            ");
            $overall_stmt->bind_param('ii', $key_id, $agent_id);
            $overall_stmt->execute();
            $overall_stats = $overall_stmt->get_result()->fetch_assoc();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'overall' => $overall_stats,
                    'daily' => $daily_stats
                ]
            ]);
            break;
            
        case 'get_recent_requests':
            $key_id = (int)$_GET['key_id'];
            $limit = min((int)($_GET['limit'] ?? 20), 50);
            
            $requests_stmt = $db->prepare("
                SELECT endpoint, method, response_code, processing_time_ms, created_at, ip_address
                FROM agent_api_usage_logs 
                WHERE api_key_id = ? AND agent_id = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $requests_stmt->bind_param('iii', $key_id, $agent_id, $limit);
            $requests_stmt->execute();
            $recent_requests = $requests_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $recent_requests
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit();
}

// If we get here, redirect to main API access page
header('Location: api-access.php');
exit();
?>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

