<?php
require_once '../config/config.php';
require_once '../includes/email.php';

ensureOrderIssueTables();

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
$payload = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : $_GET;
$action = $payload['action'] ?? 'report_issue';

if ($method !== 'POST' || $action !== 'report_issue') {
    jsonResponse(['status' => 'error', 'message' => 'Unsupported action'], 405);
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!validateCSRF($csrf)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid security token'], 419);
}

$orderId = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
$issueMessage = trim($payload['message'] ?? '');

if ($orderId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Missing order reference'], 422);
}

if (strlen($issueMessage) < 5) {
    jsonResponse(['status' => 'error', 'message' => 'Please provide a short description of the issue'], 422);
}

$currentUser = getCurrentUser();

$stmt = $db->prepare("
    SELECT bo.id, bo.user_id, bo.agent_id, bo.status, bo.created_at, bo.order_reference, bo.beneficiary_number, bo.amount,
           dp.name AS package_name, n.name AS network_name
    FROM bundle_orders bo
    JOIN data_packages dp ON dp.id = bo.package_id
    JOIN networks n ON n.id = dp.network_id
    WHERE bo.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    jsonResponse(['status' => 'error', 'message' => 'Order not found'], 404);
}

$canReport = false;
if ($currentUser['role'] === 'customer' && (int)$order['user_id'] === (int)$currentUser['id']) {
    $canReport = true;
} elseif ($currentUser['role'] === 'agent' && ((int)$order['user_id'] === (int)$currentUser['id'] || (int)$order['agent_id'] === (int)$currentUser['id'])) {
    $canReport = true;
}

if (!$canReport) {
    jsonResponse(['status' => 'error', 'message' => 'You do not have permission to report this order'], 403);
}

if (in_array($order['status'], ['failed', 'cancelled'], true)) {
    jsonResponse(['status' => 'error', 'message' => 'This order cannot be reported because it is already marked as ' . $order['status']], 422);
}

$delayMinutes = max(1, (int) getSetting('order_report_delay_minutes', 20));
$orderAgeMinutes = floor((time() - strtotime($order['created_at'])) / 60);
if ($orderAgeMinutes < $delayMinutes) {
    $minutesLeft = max(1, $delayMinutes - $orderAgeMinutes);
    jsonResponse(['status' => 'error', 'message' => "Please wait {$minutesLeft} more minute(s) before reporting this order."], 422);
}

$stmt = $db->prepare("SELECT id FROM order_issue_reports WHERE order_id = ? AND reporter_id = ? AND status IN ('open','in_progress') LIMIT 1");
$stmt->bind_param('ii', $orderId, $currentUser['id']);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    jsonResponse(['status' => 'error', 'message' => 'You already have an active report for this order.'], 409);
}

$reporterRole = $currentUser['role'] === 'agent' ? 'agent' : 'customer';
$stmt = $db->prepare("INSERT INTO order_issue_reports (order_id, reporter_id, reporter_role, issue_message) VALUES (?, ?, ?, ?)");
$stmt->bind_param('iiss', $orderId, $currentUser['id'], $reporterRole, $issueMessage);
$stmt->execute();
$reportId = $stmt->insert_id;

// Notify admin/super admin
$recipientEmail = '';
$adminStmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1");
if ($adminStmt && $adminStmt->execute()) {
    $adminRow = $adminStmt->get_result()->fetch_assoc();
    $recipientEmail = $adminRow['email'] ?? '';
}
if (!$recipientEmail && defined('ADMIN_EMAIL')) {
    $recipientEmail = ADMIN_EMAIL;
}

if ($recipientEmail) {
    $subject = 'Order escalation reported - #' . str_pad($order['id'], 6, '0', STR_PAD_LEFT);
    $reporterName = htmlspecialchars($currentUser['full_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
    $orderReference = htmlspecialchars($order['order_reference'] ?? '-', ENT_QUOTES, 'UTF-8');
    $networkName = htmlspecialchars($order['network_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $packageName = htmlspecialchars($order['package_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $beneficiaryNumber = htmlspecialchars($order['beneficiary_number'] ?? '-', ENT_QUOTES, 'UTF-8');
    $statusLabel = htmlspecialchars($order['status'] ?? '-', ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($issueMessage, ENT_QUOTES, 'UTF-8'));
    $body = "
        <h3>New order escalation</h3>
        <p><strong>Reporter:</strong> {$reporterName} ({$reporterRole})</p>
        <p><strong>Order ID:</strong> #{$order['id']} ({$orderReference})</p>
        <p><strong>Network:</strong> {$networkName}</p>
        <p><strong>Package:</strong> {$packageName}</p>
        <p><strong>Recipient:</strong> {$beneficiaryNumber}</p>
        <p><strong>Status:</strong> {$statusLabel}</p>
        <p><strong>Message:</strong><br>{$safeMessage}</p>
        <p>This report was filed " . date('M j, Y g:i A') . ".</p>
    ";
    try {
        sendEmail($recipientEmail, $subject, $body);
    } catch (Exception $e) {
        error_log('Order issue email failed: ' . $e->getMessage());
    }
}

jsonResponse([
    'status' => 'success',
    'message' => 'Thanks! We have notified support about this order.',
    'report_id' => $reportId
]);
