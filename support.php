<?php
require_once __DIR__ . '/config/config.php';

preventBrowserCaching();

$query = $_GET;
$queryString = http_build_query($query);

if (!isLoggedIn()) {
    $redirectPath = '/support.php' . ($queryString !== '' ? '?' . $queryString : '');
    header('Location: ' . SITE_URL . '/login.php?redirect=' . urlencode($redirectPath));
    exit();
}

$role = normalizeUserRole($_SESSION['user_role'] ?? '');
if ($role === '') {
    $role = normalizeUserRole(refreshSessionUserRole() ?? '');
}

if ($role === 'admin' || $role === 'super_admin') {
    $target = '/admin/support.php';
} elseif ($role === 'agent') {
    $target = '/agent/support.php';
} else {
    $target = '/customer/support.php';
}

header('Location: ' . SITE_URL . $target . ($queryString !== '' ? '?' . $queryString : ''));
exit();

