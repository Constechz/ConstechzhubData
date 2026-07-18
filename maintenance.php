<?php
require_once __DIR__ . '/config/config.php';

if (!isMaintenanceModeEnabled()) {
    header('Location: index.php');
    exit;
}

renderMaintenanceNotice();
