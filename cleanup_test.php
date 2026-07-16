<?php
require_once 'C:/xampp/htdocs/ConstechzhubData/config/config.php';

echo "=== Cleaning Up Test Records ===\n<br>";

// 1. Delete test profits
$res1 = $db->query("DELETE FROM agent_profits WHERE reference = 'TEST_PROFIT_ENTRY'");
if ($res1) {
    echo "Deleted dummy agent profit entry.<br>\n";
}

// 2. Delete test withdrawals created during the test
$res2 = $db->query("DELETE FROM profit_withdrawals WHERE agent_id = 54 AND amount = 2.00");
if ($res2) {
    echo "Deleted any withdrawal requests of 2.00 created during the test.<br>\n";
}

echo "Cleanup complete! All test records have been deleted.\n";
