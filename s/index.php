<?php
require_once __DIR__ . '/../config/config.php';

$store_slug = sanitize($_GET['store'] ?? '');

if ($store_slug === '') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/store/index.php');
    exit();
}

$_GET['store'] = $store_slug;
require __DIR__ . '/../store/index.php';
