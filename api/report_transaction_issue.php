<?php
require_once '../config/config.php';
require_once '../includes/email.php';

if (!isLoggedIn()) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Unsupported method'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!validateCSRF($csrf)) {
    jsonResponse(['status' => 'error', 'message' => 'Invalid security token'], 419);
}

$transactionId = (int) ($payload['transaction_id'] ?? 0);
$message = trim($payload['message'] ?? '');

if ($transactionId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Missing transaction reference'], 422);
}

if (strlen($message) < 5) {
    jsonResponse(['status' => 'error', 'message' => 'Message must be at least 5 characters'], 422);
}

$sql = "
    SELECT 
        t.id,
        t.user_id,
        t.transaction_type,
        t.amount,
        t.status,
        t.reference,
        t.description,
        t.created_at,
        bo.id AS order_id,
        bo.beneficiary_number,
        bo.amount AS order_amount,
        dp.data_size,
        dp.name AS package_name,
        n.name AS network_name,
        u.full_name,
        u.email
    FROM transactions t
    LEFT JOIN bundle_orders bo ON (
        bo.transaction_id = t.id OR (
            t.user_id = bo.user_id AND (
                t.reference = bo.order_reference OR
                bo.order_reference = t.description OR
                t.reference = CONCAT('ORD', bo.id)
            )
        )
    )
    LEFT JOIN data_packages dp ON bo.package_id = dp.id
    LEFT JOIN networks n ON dp.network_id = n.id
    LEFT JOIN users u ON t.user_id = u.id
    WHERE t.id = ?
    LIMIT 1
";

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $transactionId);
$stmt->execute();
$transaction = $stmt->get_result()->fetch_assoc();

if (!$transaction) {
    jsonResponse(['status' => 'error', 'message' => 'Transaction not found'], 404);
}

$currentUser = getCurrentUser();
$recipientEmails = [];

$adminStmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND email <> '' ORDER BY id ASC LIMIT 1");
if ($adminStmt && $adminStmt->execute()) {
    $adminRow = $adminStmt->get_result()->fetch_assoc();
    if (!empty($adminRow['email'])) {
        $recipientEmails[] = $adminRow['email'];
    }
}

if (!empty($currentUser['email'])) {
    $recipientEmails[] = $currentUser['email'];
}

if (defined('ADMIN_EMAIL') && ADMIN_EMAIL) {
    $recipientEmails[] = ADMIN_EMAIL;
}

$recipientEmails = array_values(array_unique(array_filter($recipientEmails)));
if (empty($recipientEmails)) {
    jsonResponse(['status' => 'error', 'message' => 'No recipient configured for escalation'], 500);
}

$subject = sprintf('Transaction reported - #%06d', $transaction['id']);
$amount = number_format((float) ($transaction['amount'] ?? 0), 2);
$orderRef = $transaction['order_id'] ? sprintf('#%06d', $transaction['order_id']) : 'N/A';
$networkName = htmlspecialchars($transaction['network_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
$packageName = htmlspecialchars($transaction['package_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$msisdn = htmlspecialchars($transaction['beneficiary_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$statusLabel = htmlspecialchars($transaction['status'] ?? 'pending', ENT_QUOTES, 'UTF-8');
$reporterName = htmlspecialchars($currentUser['full_name'] ?? 'Unknown Admin', ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$body = "
    <h3>Transaction reported by {$reporterName}</h3>
    <p><strong>Transaction:</strong> #{$transaction['id']} ({$transaction['reference']})</p>
    <p><strong>Order:</strong> {$orderRef}</p>
    <p><strong>Network:</strong> {$networkName}</p>
    <p><strong>Package:</strong> {$packageName}</p>
    <p><strong>MSISDN:</strong> {$msisdn}</p>
    <p><strong>Amount:</strong> " . CURRENCY . "{$amount}</p>
    <p><strong>Status:</strong> {$statusLabel}</p>
    <p><strong>Reported message:</strong><br>{$safeMessage}</p>
    <p>Submitted on " . date('M j, Y g:i A') . ".</p>
";

foreach ($recipientEmails as $email) {
    try {
        sendEmail($email, $subject, $body, strip_tags($body), 'transaction_report');
    } catch (Exception $e) {
        error_log('Transaction report email failed: ' . $e->getMessage());
    }
}

jsonResponse([
    'status' => 'success',
    'message' => 'Report sent successfully.'
]);
