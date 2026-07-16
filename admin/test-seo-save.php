<?php
require_once '../config/config.php';
require_once '../includes/seo.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload');
    }

    $testKey = 'test_setting';
    $testValue = $payload[$testKey] ?? null;
    if ($testValue === null) {
        $testValue = 'test_value_' . time();
    }

    if (!updateSeoSetting($testKey, $testValue, 'Temporary test key created by seo-settings test button')) {
        throw new RuntimeException('Unable to write test setting to database');
    }

    echo json_encode([
        'success' => true,
        'written_value' => $testValue,
        'timestamp' => time()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
