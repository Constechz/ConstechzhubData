<?php
require_once 'C:/xampp/htdocs/ConstechzhubData/config/config.php';

$agent_id = 54;
$test_profit = 10.00;

// Find a valid order_id
$res = $db->query("SELECT id FROM bundle_orders LIMIT 1");
$row = $res->fetch_assoc();
if (!$row) {
    die("No orders found in bundle_orders table.\n");
}
$order_id = $row['id'];

// Find a valid package_id
$res2 = $db->query("SELECT id FROM data_packages LIMIT 1");
$row2 = $res2->fetch_assoc();
if (!$row2) {
    die("No packages found in data_packages table.\n");
}
$package_id = $row2['id'];

echo "Referencing order ID: $order_id, package ID: $package_id\n";

// Insert a dummy profit record
$stmt = $db->prepare("
    INSERT INTO agent_profits 
        (agent_id, order_id, customer_id, package_id, customer_payment, agent_cost, profit_amount, status, reference)
    VALUES (?, ?, NULL, ?, ?, 0, ?, 'earned', 'TEST_PROFIT_ENTRY')
");

if ($stmt) {
    $stmt->bind_param('iiidd', $agent_id, $order_id, $package_id, $test_profit, $test_profit);
    if ($stmt->execute()) {
        echo "Successfully added $test_profit GHS of test profit to user 54.\n";
    } else {
        echo "Execute failed: " . $stmt->error . "\n";
    }
} else {
    echo "Prepare failed.\n";
}
