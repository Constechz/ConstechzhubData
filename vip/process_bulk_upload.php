<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/api_providers.php';

requireLogin();
requireRole('vip');

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
$current_user = getCurrentUser();

if (!$current_user) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}
ensureDataPackageStockStatusColumn();

// Get agent wallet balance using the correct wallets table
$wallet_balance = getWalletBalance($current_user['id']);

try {
    $file = $_FILES['bulk_file'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, ['csv', 'xlsx', 'xls'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Please upload CSV or Excel file.']);
        exit;
    }
    
    // Read file content - handle both CSV and Excel files
    $lines = [];
    
    if ($file_extension === 'csv') {
        $file_content = file_get_contents($file['tmp_name']);
        $lines = explode("\n", $file_content);
    } else {
        // For Excel files, we'll treat them as CSV for now
        // In production, you might want to use PHPSpreadsheet library
        $file_content = file_get_contents($file['tmp_name']);
        $lines = explode("\n", $file_content);
    }
    
    if (count($lines) < 2) {
        echo json_encode(['success' => false, 'message' => 'File must contain at least one data row besides header.']);
        exit;
    }
    
    $processed = 0;
    $errors = [];
    $total_cost = 0;
    
    // Get network packages for validation with proper network mapping
    $network_map = ['at' => 'AT', 'mtn' => 'MTN', 'telecel' => 'Telecel'];
    $network_name = $network_map[strtolower($network)] ?? 'AT';
    
    $stmt = $db->prepare("
        SELECT dp.id, dp.name, dp.data_size, dp.price,
               COALESCE(dp.stock_status, 'in_stock') AS stock_status,
               COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
        FROM data_packages dp
        LEFT JOIN networks n ON dp.network_id = n.id
        LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
        LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
        WHERE n.name = ? AND dp.status = 'active'
          AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ORDER BY dp.data_size
    ");
    $stmt->bind_param("s", $network_name);
    $stmt->execute();
    $packages_result = $stmt->get_result();
    $packages = [];
    while ($package = $packages_result->fetch_assoc()) {
        $packages[strtolower($package['data_size'])] = $package;
    }
    
    $bundle_orders_auto_increment = true;
    if (function_exists('dbh_ensure_auto_increment')) {
        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
    }

    $db->getConnection()->begin_transaction();
    
    // Skip header row
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        $data = str_getcsv($line);
        if (count($data) < 2) continue;
        
        $phone_number = trim($data[0]);
        $volume = strtolower(trim($data[1]));
        
        // Validate phone number
        if (!validatePhone($phone_number)) {
            $errors[] = "Row " . ($i + 1) . ": Invalid phone number - $phone_number";
            continue;
        }
        
        // Find matching package - improved matching logic
        $package = null;
        $volume_clean = strtolower(str_replace([' ', 'gb', 'mb'], '', $volume));
        
        foreach ($packages as $size => $pkg) {
            $size_clean = strtolower(str_replace([' ', 'gb', 'mb'], '', $size));
            if ($volume_clean === $size_clean || strpos($volume, $size) !== false || strpos($size, $volume_clean) !== false) {
                $package = $pkg;
                break;
            }
        }
        
        if (!$package) {
            $errors[] = "Row " . ($i + 1) . ": Package not found for volume - $volume";
            continue;
        }
        
        // Get agent pricing (using the same logic as the main forms)
        $stmt = $db->prepare("
            SELECT COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price,
                   acp.custom_price
            FROM data_packages dp
            LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
            LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
            LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
            WHERE dp.id = ?
              AND dp.status = 'active'
              AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ");
        $stmt->bind_param("ii", $current_user['id'], $package['id']);
        $stmt->execute();
        $pricing_result = $stmt->get_result();
        $pricing_data = $pricing_result->fetch_assoc();
        
        $price_to_use = $pricing_data['effective_price'] ?? $package['price'];
        $total_cost += $price_to_use;
        
        // Check if we have enough balance
        if ($total_cost > $wallet_balance) {
            $errors[] = "Insufficient wallet balance. Total cost: " . formatCurrency($total_cost) . ", Available: " . formatCurrency($wallet_balance);
            break;
        }
        
        // Create order
        $order_reference = generateReference(strtoupper($network));
        $formatted_phone = formatPhone($phone_number);
        
        if ($bundle_orders_auto_increment) {
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'processing', NOW())
            ");
            $stmt->bind_param("iisds", $current_user['id'], $package['id'], $formatted_phone, $price_to_use, $order_reference);
            $stmt->execute();
            $order_id = $db->insert_id;
        } else {
            $manual_order_id = dbh_generate_next_id('bundle_orders');
            $stmt = $db->prepare("
                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'processing', NOW())
            ");
            $stmt->bind_param("iiisds", $manual_order_id, $current_user['id'], $package['id'], $formatted_phone, $price_to_use, $order_reference);
            $stmt->execute();
            $order_id = $manual_order_id;
        }

        if ($order_id) {
            
            // Process with real API call for bulk uploads
            require_once '../includes/volume_converter.php';
            $volume_gb = extractVolumeGB($package['data_size']);
            
            // Map network names to IDs
            $network_id_map = ['MTN' => 1, 'AT' => 2, 'Telecel' => 4];
            $network_id = $network_id_map[$network_name] ?? 1;
            
            // Determine endpoint type
            $endpoint_type = detectEndpointTypeForPackage($package['name'] ?? '', $package['data_size'] ?? '');
            $availability = checkNetworkProviderAvailability($network_id, $endpoint_type);
            if (!$availability['available']) {
                $stmt_update = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                $api_response_json = json_encode(['success' => false, 'error' => $availability['message']]);
                $stmt_update->bind_param("si", $api_response_json, $order_id);
                $stmt_update->execute();
                $errors[] = "Row " . ($i + 1) . ": " . $availability['message'];
                continue;
            }
             
            // Call API provider to deliver the bundle
            try {
                $api_result = processBundlePurchase($order_id, $network_id, $formatted_phone, $volume_gb, $endpoint_type);
            } catch (Exception $e) {
                $api_result = ['success' => false, 'error' => $e->getMessage()];
            }
            
            if ($api_result['success']) {
                // Update order status to delivered
                $stmt_update = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_reference = ? WHERE id = ?");
                $api_response_json = json_encode($api_result);
                $provider_ref = $api_result['reference'] ?? '';
                $stmt_update->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                $stmt_update->execute();

                if (function_exists('applyMtnStatusPolicy')) {
                    applyMtnStatusPolicy($order_id, 'processing');
                }

                if (function_exists('recordAgentCommission')) {
                    $commission_amount = function_exists('calculateAgentDataCommissionAmount')
                        ? calculateAgentDataCommissionAmount($package['data_size'] ?? '', 1)
                        : 0.0;

                    if ($commission_amount > 0) {
                        recordAgentCommission([
                            'agent_id' => (int) $current_user['id'],
                            'source_type' => 'data',
                            'source_id' => (int) $order_id,
                            'source_reference' => (string) $order_reference,
                            'amount' => $commission_amount,
                            'quantity' => 1,
                            'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['data_rate_per_gb'] ?? 0) : null,
                            'notes' => ($package['network_name'] ?? 'Data') . ' ' . ($package['data_size'] ?? 'bundle') . ' for ' . $formatted_phone,
                        ]);
                    }
                }

            } else {
                // Update order status to failed
                $stmt_update = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                $api_response_json = json_encode($api_result);
                $stmt_update->bind_param("si", $api_response_json, $order_id);
                $stmt_update->execute();
                
                $errors[] = "Row " . ($i + 1) . ": API delivery failed for $phone_number - " . $api_result['error'];
                continue; // Don't count as processed if API failed
            }
            
            $processed++;
        } else {
            $errors[] = "Row " . ($i + 1) . ": Failed to create order for $phone_number";
        }
    }
    
    if ($processed > 0) {
        // Deduct from wallet using the correct updateWalletBalance function
        $deduction_reference = 'BULK_' . time();
        $description = "Bulk purchase - $processed orders";
        
        $wallet_updated = updateWalletBalance($current_user['id'], $total_cost, 'debit', $deduction_reference, $description);
        
        if (!$wallet_updated) {
            throw new Exception('Failed to update wallet balance');
        }
    }
    
    $db->getConnection()->commit();

    if ($processed > 0) {
        sendAdminDataOrderNotification([
            'order_reference' => generateReference('AGBULKUP'),
            'order_id' => 0,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => 'Multiple numbers',
            'network_name' => $network_name,
            'package_name' => "Agent Bulk Upload ({$processed} successful orders)",
            'amount' => (float) $total_cost,
            'payment_method' => 'wallet',
            'status' => 'processed',
            'agent_id' => (int) $current_user['id'],
            'source' => 'agent_bulk_upload'
        ]);

        sendUserOrderNotification([
            'order_type' => 'data',
            'order_reference' => generateReference('AGBULKUP'),
            'order_id' => 0,
            'user_id' => (int) $current_user['id'],
            'customer_name' => $current_user['full_name'] ?? '',
            'customer_email' => $current_user['email'] ?? '',
            'beneficiary_number' => 'Multiple numbers',
            'network_name' => $network_name,
            'package_name' => "Agent Bulk Upload ({$processed} successful orders)",
            'amount' => (float) $total_cost,
            'payment_method' => 'wallet',
            'status' => 'processed',
            'source' => 'agent_bulk_upload'
        ]);
    }
    
    $message = "Processed $processed orders successfully.";
    if (!empty($errors)) {
        $message .= " Errors: " . implode(', ', array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $message .= " and " . (count($errors) - 3) . " more...";
        }
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'processed' => $processed,
        'errors' => count($errors)
    ]);
    
} catch (Exception $e) {
    $db->getConnection()->rollback();
    error_log("Bulk upload error: " . $e->getMessage());
    $message = 'An error occurred while processing the upload.';
    if (stripos($e->getMessage(), 'Network is busy') !== false) {
        $message = $e->getMessage();
    }
    echo json_encode(['success' => false, 'message' => $message]);
}
?>
