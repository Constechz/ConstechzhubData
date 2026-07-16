<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_broadcast.php';

ensureEmailBroadcastTables();

$limit = 100;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $limit = (int) $argv[1];
}

$result = processEmailBroadcastQueue($limit);

$processed = $result['processed'] ?? 0;
$sent = $result['sent'] ?? 0;
$failed = $result['failed'] ?? 0;
$error = $result['error'] ?? null;

if ($error) {
    echo "Queue error: {$error}\n";
    exit(1);
}

echo "Queue processed: {$processed}, sent: {$sent}, failed: {$failed}\n";
