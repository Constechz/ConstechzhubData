<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

setFlashMessage('info', 'Commission liquidations have been removed from the admin menu.');
header('Location: commission-settings.php');
exit;
