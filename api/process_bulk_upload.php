<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Require agent role
requireRole('agent');

$current_user = getCurrentUser();
$agent_id = $current_user['id'];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$network = sanitize($_POST['network'] ?? '');
if (empty($network)) {
    echo json_encode(['success' => false, 'message' => 'Network not specified']);
    exit;
}

try {
    $file = $_FILES['bulk_file'];
    $filename = $file['tmp_name'];
    
    // Read CSV/Excel file
    $data = [];
    if (($handle = fopen($filename, "r")) !== FALSE) {
        $header = fgetcsv($handle); // Skip header row
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 2 && !empty($row[0]) && !empty($row[1])) {
                $data[] = [
                    'phone' => trim($row[0]),
                    'volume' => trim($row[1])
                ];
            }
        }
        fclose($handle);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not read uploaded file']);
        exit;
    }
    
    if (empty($data)) {
        echo json_encode(['success' => false, 'message' => 'No valid data found in file']);
        exit;
    }
    
    // Get available packages for the network
    $stmt = $db->prepare("
        SELECT dp.id, dp.data_size, dp.price, 
               COALESCE(pp.price, dp.price) as effective_price,
               acp.custom_price
        FROM data_packages dp
        LEFT JOIN networks n ON dp.network_id = n.id
        LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'agent'
        LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
        WHERE n.name = ? AND dp.status = 'active'
        ORDER BY dp.data_size
    ");
    $stmt->bind_param('is', $agent_id, $network);
    $stmt->execute();
    $packages_result = $stmt->get_result();
    
    $packages = [];
    while ($pkg = $packages_result->fetch_assoc()) {
        $final_price = $pkg['custom_price'] ?? $pkg['effective_price'];
        $packages[strtolower($pkg['data_size'])] = [
            'id' => $pkg['id'],
            'price' => $final_price,
            'data_size' => $pkg['data_size']
        ];
    }
    
    // Process each row
    $processed = [];
    $total_cost = 0;
    $errors = [];
    
    foreach ($data as $index => $row) {
        $phone = formatPhone($row['phone']);
        $volume = strtolower(trim($row['volume']));
        
        if (!isset($packages[$volume])) {
            $errors[] = "Row " . ($index + 2) . ": Volume '{$row['volume']}' not available";
            continue;
        }
        
        $package = $packages[$volume];
        $cost = floatval($package['price']);
        
        $processed[] = [
            'phone' => $phone,
            'package_id' => $package['id'],
            'volume' => $package['data_size'],
            'cost' => $cost
        ];
        
        $total_cost += $cost;
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => 'Validation errors', 'errors' => $errors]);
        exit;
    }
    
    // Check wallet balance
    $wallet_balance = getWalletBalance($agent_id);
    if ($wallet_balance < $total_cost) {
        echo json_encode([
            'success' => false, 
            'message' => 'Insufficient wallet balance. Required: ' . formatCurrency($total_cost) . ', Available: ' . formatCurrency($wallet_balance)
        ]);
        exit;
    }
    
    $bundle_orders_auto_increment = true;
    $transactions_auto_increment = true;
    if (function_exists('dbh_ensure_auto_increment')) {
        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
        $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
    }

    // Process all orders
    $db->getConnection()->begin_transaction();
    
    try {
        $success_count = 0;
        
        foreach ($processed as $order) {
            // Create bundle order
            $txn_ref = generateReference('BO');
            $description = $order['volume'] . ' bundle for ' . $order['phone'];
            
            if ($bundle_orders_auto_increment) {
                $stmt = $db->prepare("
                    INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, status, reference, description, created_at)
                    VALUES (?, ?, ?, ?, 'success', ?, ?, NOW())
                ");
                $stmt->bind_param('iisdss', $agent_id, $order['package_id'], $order['phone'], $order['cost'], $txn_ref, $description);
                $stmt->execute();
                $order_id = $db->lastInsertId();
            } else {
                $manual_order_id = dbh_generate_next_id('bundle_orders');
                $stmt = $db->prepare("
                    INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, status, reference, description, created_at)
                    VALUES (?, ?, ?, ?, ?, 'success', ?, ?, NOW())
                ");
                $stmt->bind_param('iiisdss', $manual_order_id, $agent_id, $order['package_id'], $order['phone'], $order['cost'], $txn_ref, $description);
                $stmt->execute();
                $order_id = $manual_order_id;
            }

            // Create transaction
            if ($transactions_auto_increment) {
                $stmt = $db->prepare("
                    INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, created_at)
                    VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?, NOW())
                ");
                $stmt->bind_param('idss', $agent_id, $order['cost'], $txn_ref, $description);
                $stmt->execute();
                $transaction_id = $db->lastInsertId();
            } else {
                $manual_transaction_id = dbh_generate_next_id('transactions');
                $stmt = $db->prepare("
                    INSERT INTO transactions (id, user_id, transaction_type, amount, status, reference, payment_method, description, created_at)
                    VALUES (?, ?, 'purchase', ?, 'success', ?, 'wallet', ?, NOW())
                ");
                $stmt->bind_param('iidss', $manual_transaction_id, $agent_id, $order['cost'], $txn_ref, $description);
                $stmt->execute();
                $transaction_id = $manual_transaction_id;
            }

            if (!empty($transaction_id) && !empty($order_id)) {
                $linkStmt = $db->prepare("UPDATE bundle_orders SET transaction_id = ? WHERE id = ?");
                $linkStmt->bind_param('ii', $transaction_id, $order_id);
                $linkStmt->execute();
            }

            $success_count++;
        }
        
        // Deduct total from wallet
        updateWalletBalance($agent_id, -$total_cost, 'Bulk upload: ' . $success_count . ' orders');
        
        $db->getConnection()->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully processed {$success_count} orders. Total cost: " . formatCurrency($total_cost),
            'processed_count' => $success_count,
            'total_cost' => $total_cost
        ]);
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        echo json_encode(['success' => false, 'message' => 'Error processing orders: ' . $e->getMessage()]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
