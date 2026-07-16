<?php
define('VIP_PORTAL', true);
require_once __DIR__ . '/../config/config.php';
requireRole('vip');

require __DIR__ . '/../customer/store-checkout.php';
