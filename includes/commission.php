<?php
/**
 * Commission calculation and management functions
 */

/**
 * Calculate commission for a transaction
 */
function calculateCommission($network_id, $amount, $package_type = 'data') {
    global $db;
    
    $stmt = $db->prepare("
        SELECT commission_rate, min_commission, max_commission, is_active 
        FROM commission_settings 
        WHERE network_id = ? AND package_type = ? AND is_active = TRUE
    ");
    $stmt->bind_param("is", $network_id, $package_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($settings = $result->fetch_assoc()) {
        $commission_rate = floatval($settings['commission_rate']);
        $min_commission = floatval($settings['min_commission']);
        $max_commission = $settings['max_commission'] ? floatval($settings['max_commission']) : null;
        
        // Calculate base commission
        $commission = ($amount * $commission_rate) / 100;
        
        // Apply minimum commission
        $commission = max($commission, $min_commission);
        
        // Apply maximum commission if set
        if ($max_commission !== null) {
            $commission = min($commission, $max_commission);
        }
        
        return round($commission, 2);
    }
    
    return 0.00;
}

/**
 * Record commission for a transaction
 */
function recordCommission($transaction_id, $agent_id, $network_id, $amount, $package_type = 'data') {
    global $db;
    
    $commission = calculateCommission($network_id, $amount, $package_type);
    
    if ($commission > 0) {
        $stmt = $db->prepare("
            UPDATE transactions 
            SET commission_earned = ?, commission_status = 'pending' 
            WHERE id = ?
        ");
        $stmt->bind_param("di", $commission, $transaction_id);
        $stmt->execute();
        
        return $commission;
    }
    
    return 0.00;
}

/**
 * Get agent's total pending commission
 */
function getAgentPendingCommission($agent_id) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(commission_earned), 0) as pending_commission
        FROM transactions 
        WHERE user_id = ? AND commission_status = 'pending' AND commission_earned > 0
    ");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    return floatval($data['pending_commission']);
}

/**
 * Get agent's total liquidated commission
 */
function getAgentLiquidatedCommission($agent_id) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(commission_earned), 0) as liquidated_commission
        FROM transactions 
        WHERE user_id = ? AND commission_status = 'liquidated' AND commission_earned > 0
    ");
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    return floatval($data['liquidated_commission']);
}

/**
 * Get agent's commission breakdown by network
 */
function getAgentCommissionByNetwork($agent_id, $status = 'pending') {
    global $db;
    
    $stmt = $db->prepare("
        SELECT n.name as network_name, n.color, 
               COALESCE(SUM(t.commission_earned), 0) as total_commission,
               COUNT(t.id) as transaction_count
        FROM transactions t
        JOIN bundle_orders bo ON t.reference = CONCAT('ORDER_', bo.id)
        JOIN data_packages dp ON bo.package_id = dp.id
        JOIN networks n ON dp.network_id = n.id
        WHERE t.user_id = ? AND t.commission_status = ? AND t.commission_earned > 0
        GROUP BY n.id, n.name, n.color
        ORDER BY total_commission DESC
    ");
    $stmt->bind_param("is", $agent_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Create commission liquidation request
 */
function createCommissionLiquidation($agent_id, $amount, $method = 'wallet_credit', $notes = '') {
    global $db;
    
    $pending_commission = getAgentPendingCommission($agent_id);
    
    if ($amount > $pending_commission) {
        return ['success' => false, 'message' => 'Insufficient pending commission'];
    }
    
    if ($amount < 1.00) {
        return ['success' => false, 'message' => 'Minimum liquidation amount is ₵1.00'];
    }
    
    $reference = 'LIQ_' . time() . '_' . $agent_id;
    $remaining = $pending_commission - $amount;
    
    $stmt = $db->prepare("
        INSERT INTO commission_liquidations 
        (agent_id, total_commission, liquidated_amount, remaining_commission, 
         liquidation_method, reference_number, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("idddsss", $agent_id, $pending_commission, $amount, $remaining, $method, $reference, $notes);
    
    if ($stmt->execute()) {
        return [
            'success' => true, 
            'message' => 'Liquidation request created successfully',
            'reference' => $reference,
            'liquidation_id' => $db->lastInsertId()
        ];
    }
    
    return ['success' => false, 'message' => 'Failed to create liquidation request'];
}

/**
 * Process commission liquidation (Admin only)
 */
function processCommissionLiquidation($liquidation_id, $admin_id, $status = 'completed', $notes = '') {
    global $db;
    
    $db->getConnection()->begin_transaction();
    
    try {
        // Get liquidation details
        $stmt = $db->prepare("
            SELECT * FROM commission_liquidations 
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->bind_param("i", $liquidation_id);
        $stmt->execute();
        $liquidation = $stmt->get_result()->fetch_assoc();
        
        if (!$liquidation) {
            throw new Exception('Liquidation not found or already processed');
        }
        
        if ($status === 'completed') {
            // Mark transactions as liquidated
            $stmt = $db->prepare("
                UPDATE transactions 
                SET commission_status = 'liquidated' 
                WHERE user_id = ? AND commission_status = 'pending' 
                AND commission_earned > 0
                ORDER BY created_at ASC
                LIMIT ?
            ");
            
            // Calculate how many transactions to mark as liquidated
            $stmt2 = $db->prepare("
                SELECT id, commission_earned FROM transactions 
                WHERE user_id = ? AND commission_status = 'pending' 
                AND commission_earned > 0
                ORDER BY created_at ASC
            ");
            $stmt2->bind_param("i", $liquidation['agent_id']);
            $stmt2->execute();
            $transactions = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $remaining_amount = $liquidation['liquidated_amount'];
            $transaction_count = 0;
            
            foreach ($transactions as $transaction) {
                if ($remaining_amount <= 0) break;
                $remaining_amount -= $transaction['commission_earned'];
                $transaction_count++;
            }
            
            $stmt->bind_param("ii", $liquidation['agent_id'], $transaction_count);
            $stmt->execute();
            
            // If liquidation method is wallet_credit, add to agent's wallet
            if ($liquidation['liquidation_method'] === 'wallet_credit') {
                updateWalletBalance(
                    $liquidation['agent_id'], 
                    $liquidation['liquidated_amount'], 
                    'credit', 
                    $liquidation['reference_number'], 
                    'Commission liquidation'
                );
            }
        }
        
        // Update liquidation status
        $stmt = $db->prepare("
            UPDATE commission_liquidations 
            SET status = ?, processed_by = ?, processed_at = NOW(), notes = CONCAT(COALESCE(notes, ''), ?, '\n')
            WHERE id = ?
        ");
        $admin_notes = "\n[" . date('Y-m-d H:i:s') . "] Processed by admin: " . $notes;
        $stmt->bind_param("sisi", $status, $admin_id, $admin_notes, $liquidation_id);
        $stmt->execute();
        
        $db->getConnection()->commit();
        
        return ['success' => true, 'message' => 'Liquidation processed successfully'];
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Get agent liquidation history
 */
function getAgentLiquidationHistory($agent_id, $limit = 10) {
    global $db;
    
    $stmt = $db->prepare("
        SELECT cl.*, u.full_name as processed_by_name
        FROM commission_liquidations cl
        LEFT JOIN users u ON cl.processed_by = u.id
        WHERE cl.agent_id = ?
        ORDER BY cl.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $agent_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get all pending liquidations (Admin)
 */
function getPendingLiquidations() {
    global $db;
    
    $stmt = $db->prepare("
        SELECT cl.*, u.full_name as agent_name, u.email as agent_email
        FROM commission_liquidations cl
        JOIN users u ON cl.agent_id = u.id
        WHERE cl.status = 'pending'
        ORDER BY cl.created_at ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}
?>
