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

    $agent_id = (int) $agent_id;
    if ($agent_id <= 0) {
        return 0.0;
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as pending_commission
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'earned' AND amount > 0
        ");
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param("i", $agent_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $pending = (float) ($data['pending_commission'] ?? 0);
        if (function_exists('dbh_table_exists') && dbh_table_exists('commission_liquidations')) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(liquidated_amount), 0) as reserved_commission
                FROM commission_liquidations
                WHERE agent_id = ? AND status IN ('pending', 'processing')
            ");
            if ($stmt) {
                $stmt->bind_param("i", $agent_id);
                $stmt->execute();
                $reserved = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $pending -= (float) ($reserved['reserved_commission'] ?? 0);
            }
        }

        return max(0.0, round($pending, 2));
    }

    if (function_exists('dbh_table_exists') && !dbh_table_exists('transactions')) {
        return 0.0;
    }
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(commission_earned), 0) as pending_commission
        FROM transactions 
        WHERE user_id = ? AND commission_status = 'pending' AND commission_earned > 0
    ");
    if (!$stmt) {
        return 0.0;
    }
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

    $agent_id = (int) $agent_id;
    if ($agent_id <= 0) {
        return 0.0;
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as liquidated_commission
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'liquidated' AND amount > 0
        ");
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param("i", $agent_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float) ($data['liquidated_commission'] ?? 0);
    }

    if (function_exists('dbh_table_exists') && !dbh_table_exists('transactions')) {
        return 0.0;
    }
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(commission_earned), 0) as liquidated_commission
        FROM transactions 
        WHERE user_id = ? AND commission_status = 'liquidated' AND commission_earned > 0
    ");
    if (!$stmt) {
        return 0.0;
    }
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

    if (function_exists('dbh_table_exists') && (!dbh_table_exists('bundle_orders') || !dbh_table_exists('data_packages') || !dbh_table_exists('networks'))) {
        return [];
    }

    if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
        $commissionStatus = strtolower(trim((string) $status)) === 'liquidated' ? 'liquidated' : 'earned';
        $stmt = $db->prepare("
            SELECT n.name as network_name, n.color,
                   COALESCE(SUM(ac.amount), 0) as total_commission,
                   COUNT(DISTINCT ac.id) as transaction_count
            FROM agent_commissions ac
            JOIN bundle_orders bo ON (
                (ac.source_id IS NOT NULL AND ac.source_id = bo.id)
                OR (ac.source_reference <> '' AND ac.source_reference = bo.order_reference)
            )
            JOIN data_packages dp ON bo.package_id = dp.id
            JOIN networks n ON dp.network_id = n.id
            WHERE ac.agent_id = ?
              AND ac.source_type = 'data'
              AND ac.status = ?
            GROUP BY n.id, n.name, n.color
            ORDER BY total_commission DESC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("is", $agent_id, $commissionStatus);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result->fetch_all(MYSQLI_ASSOC);
    }

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
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("is", $agent_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

if (!function_exists('ensureCommissionPayoutTables')) {
    function ensureCommissionPayoutTables() {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        global $db;

        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `commission_payout_settings` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `setting_name` VARCHAR(100) NOT NULL,
                    `setting_value` TEXT NOT NULL,
                    `description` VARCHAR(255) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_commission_payout_setting_name` (`setting_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('Commission payout settings table creation failed: ' . $e->getMessage());
        }

        try {
            $db->query("
                CREATE TABLE IF NOT EXISTS `commission_payouts` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `agent_id` INT NOT NULL,
                    `liquidation_id` INT DEFAULT NULL,
                    `commission_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `payout_method` VARCHAR(50) NOT NULL DEFAULT 'wallet_credit',
                    `payout_route` VARCHAR(50) DEFAULT NULL,
                    `payout_provider` VARCHAR(30) DEFAULT NULL,
                    `payout_network` VARCHAR(50) DEFAULT NULL,
                    `payout_name` VARCHAR(190) DEFAULT NULL,
                    `payout_number` VARCHAR(100) DEFAULT NULL,
                    `reference_number` VARCHAR(100) DEFAULT NULL,
                    `provider_bank_code` VARCHAR(50) DEFAULT NULL,
                    `provider_recipient_code` VARCHAR(100) DEFAULT NULL,
                    `provider_transfer_code` VARCHAR(100) DEFAULT NULL,
                    `provider_reference` VARCHAR(120) DEFAULT NULL,
                    `provider_status` VARCHAR(50) DEFAULT NULL,
                    `provider_response` LONGTEXT DEFAULT NULL,
                    `status` VARCHAR(40) NOT NULL DEFAULT 'completed',
                    `processed_by` INT DEFAULT NULL,
                    `notes` TEXT DEFAULT NULL,
                    `processed_at` TIMESTAMP NULL DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_commission_payouts_agent` (`agent_id`),
                    KEY `idx_commission_payouts_status` (`status`),
                    KEY `idx_commission_payouts_reference` (`reference_number`),
                    KEY `idx_commission_payouts_provider_reference` (`provider_reference`),
                    KEY `idx_commission_payouts_provider_transfer_code` (`provider_transfer_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('Commission payouts table creation failed: ' . $e->getMessage());
        }

        if (function_exists('dbh_table_exists') && function_exists('dbh_table_has_column') && dbh_table_exists('commission_payouts')) {
            $migrations = [
                'liquidation_id' => "ALTER TABLE `commission_payouts` ADD COLUMN `liquidation_id` INT DEFAULT NULL AFTER `agent_id`",
                'payout_route' => "ALTER TABLE `commission_payouts` ADD COLUMN `payout_route` VARCHAR(50) DEFAULT NULL AFTER `payout_method`",
                'payout_provider' => "ALTER TABLE `commission_payouts` ADD COLUMN `payout_provider` VARCHAR(30) DEFAULT NULL AFTER `payout_route`",
                'payout_network' => "ALTER TABLE `commission_payouts` ADD COLUMN `payout_network` VARCHAR(50) DEFAULT NULL AFTER `payout_provider`",
                'payout_name' => "ALTER TABLE `commission_payouts` ADD COLUMN `payout_name` VARCHAR(190) DEFAULT NULL AFTER `payout_network`",
                'payout_number' => "ALTER TABLE `commission_payouts` ADD COLUMN `payout_number` VARCHAR(100) DEFAULT NULL AFTER `payout_name`",
                'provider_bank_code' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_bank_code` VARCHAR(50) DEFAULT NULL AFTER `reference_number`",
                'provider_recipient_code' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_recipient_code` VARCHAR(100) DEFAULT NULL AFTER `provider_bank_code`",
                'provider_transfer_code' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_transfer_code` VARCHAR(100) DEFAULT NULL AFTER `provider_recipient_code`",
                'provider_reference' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_reference` VARCHAR(120) DEFAULT NULL AFTER `provider_transfer_code`",
                'provider_status' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_status` VARCHAR(50) DEFAULT NULL AFTER `provider_reference`",
                'provider_response' => "ALTER TABLE `commission_payouts` ADD COLUMN `provider_response` LONGTEXT DEFAULT NULL AFTER `provider_status`",
                'updated_at' => "ALTER TABLE `commission_payouts` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
            ];

            foreach ($migrations as $column => $sql) {
                try {
                    if (!dbh_table_has_column('commission_payouts', $column)) {
                        $db->query($sql);
                    }
                } catch (Exception $e) {
                    // Ignore non-critical migration failures so the page still loads.
                }
            }
        }

        $ensured = true;
    }
}

if (!function_exists('getCommissionPayoutSetting')) {
    function getCommissionPayoutSetting($name, $default = '') {
        global $db;

        ensureCommissionPayoutTables();

        $stmt = $db->prepare("SELECT setting_value FROM commission_payout_settings WHERE setting_name = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }

        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string) ($row['setting_value'] ?? $default) : $default;
    }
}

if (!function_exists('getAgentCommissionPayoutProfile')) {
    function getAgentCommissionPayoutProfile($agent_id) {
        global $db;

        $agent_id = (int) $agent_id;
        if ($agent_id <= 0) {
            return [
                'ready' => false,
                'network' => '',
                'name' => '',
                'number' => '',
                'issues' => ['Invalid agent selected.'],
            ];
        }

        $settings = [];
        if (function_exists('dbh_table_exists') && dbh_table_exists('topup_settings')) {
            $stmt = $db->prepare("
                SELECT setting_key, setting_value
                FROM topup_settings
                WHERE user_id = ?
                  AND setting_key IN (
                    'agent_topup_account_network',
                    'agent_topup_account_name',
                    'agent_topup_account_number'
                  )
            ");
            if ($stmt) {
                $stmt->bind_param('i', $agent_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
                $stmt->close();
            }
        }

        $phoneColumn = function_exists('dbh_get_users_phone_column') ? dbh_get_users_phone_column() : 'phone';
        $phoneSelect = $phoneColumn !== '' ? "{$phoneColumn} AS phone" : "NULL AS phone";
        $stmt = $db->prepare("SELECT full_name, {$phoneSelect} FROM users WHERE id = ? LIMIT 1");
        $fullName = '';
        $profilePhone = '';
        if ($stmt) {
            $stmt->bind_param('i', $agent_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $fullName = trim((string) ($row['full_name'] ?? ''));
            $profilePhone = trim((string) ($row['phone'] ?? ''));
        }

        $number = trim((string) ($settings['agent_topup_account_number'] ?? ''));
        if ($number === '') {
            $number = $profilePhone;
        }

        $name = trim((string) ($settings['agent_topup_account_name'] ?? ''));
        if ($name === '') {
            $name = $fullName;
        }

        $network = trim((string) ($settings['agent_topup_account_network'] ?? ''));
        $networkKey = strtolower($network);
        if ($networkKey !== '') {
            if (strpos($networkKey, 'mtn') !== false) {
                $network = 'MTN';
            } elseif (strpos($networkKey, 'telecel') !== false || strpos($networkKey, 'vodafone') !== false) {
                $network = 'Telecel';
            } elseif (strpos($networkKey, 'airtel') !== false || strpos($networkKey, 'tigo') !== false || $networkKey === 'at') {
                $network = 'AT';
            }
        }
        if (($network === '' || strtoupper($network) === 'N/A') && function_exists('detectGhanaNetworkLabel')) {
            $network = detectGhanaNetworkLabel('', $number, $profilePhone);
        }

        $issues = [];
        if ($name === '') {
            $issues[] = 'Missing account name';
        }
        if ($number === '') {
            $issues[] = 'Missing MoMo number';
        } elseif (function_exists('validatePhone') && !validatePhone($number)) {
            $issues[] = 'Invalid MoMo number';
        }
        if ($network === '' || strtoupper($network) === 'N/A') {
            $issues[] = 'Missing network';
        }

        return [
            'ready' => empty($issues),
            'network' => $network,
            'name' => $name,
            'number' => $number,
            'issues' => $issues,
        ];
    }
}

/**
 * Mark earned commission ledger rows as liquidated up to the requested amount.
 */
function liquidateAgentCommissionRows($agent_id, $amount) {
    global $db;

    $agent_id = (int) $agent_id;
    $amount = round((float) $amount, 2);
    if ($agent_id <= 0 || $amount <= 0 || !function_exists('dbh_table_exists') || !dbh_table_exists('agent_commissions')) {
        return 0.0;
    }

    $stmt = $db->prepare("
        SELECT id, source_type, source_id, source_reference, amount, quantity, rate_snapshot, notes, earned_at
        FROM agent_commissions
        WHERE agent_id = ? AND status = 'earned' AND amount > 0
        ORDER BY earned_at ASC, id ASC
    ");
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remaining = $amount;
    $ids = [];
    $markedAmount = 0.0;
    foreach ($rows as $row) {
        if ($remaining <= 0) {
            break;
        }
        $rowAmount = round((float) ($row['amount'] ?? 0), 2);
        if ($rowAmount <= 0) {
            continue;
        }

        if ($rowAmount > $remaining) {
            $liquidatedPart = round($remaining, 2);
            $earnedPart = round($rowAmount - $liquidatedPart, 2);
            $rowId = (int) $row['id'];

            $updateStmt = $db->prepare("UPDATE agent_commissions SET amount = ?, status = 'liquidated', updated_at = NOW() WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param('di', $liquidatedPart, $rowId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            if ($earnedPart > 0) {
                $sourceType = (string) ($row['source_type'] ?? '');
                $sourceId = isset($row['source_id']) ? (int) $row['source_id'] : null;
                $sourceReference = substr((string) ($row['source_reference'] ?? ('commission:' . $rowId)), 0, 85)
                    . ':remaining:' . $rowId;
                $quantity = max(1, (int) ($row['quantity'] ?? 1));
                $rateSnapshot = isset($row['rate_snapshot']) ? (float) $row['rate_snapshot'] : null;
                $notes = trim((string) ($row['notes'] ?? ''));
                $notes = trim($notes . "\nRemaining balance after partial liquidation.");
                $earnedAt = $row['earned_at'] ?? date('Y-m-d H:i:s');

                $insertStmt = $db->prepare("
                    INSERT INTO agent_commissions
                        (agent_id, source_type, source_id, source_reference, amount, quantity, rate_snapshot, notes, earned_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($insertStmt) {
                    $insertStmt->bind_param(
                        'isisdidss',
                        $agent_id,
                        $sourceType,
                        $sourceId,
                        $sourceReference,
                        $earnedPart,
                        $quantity,
                        $rateSnapshot,
                        $notes,
                        $earnedAt
                    );
                    $insertStmt->execute();
                    $insertStmt->close();
                }
            }

            $markedAmount += $liquidatedPart;
            $remaining = 0.0;
            break;
        }

        $ids[] = (int) $row['id'];
        $markedAmount += $rowAmount;
        $remaining = round($remaining - $rowAmount, 2);
    }

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $db->prepare("UPDATE agent_commissions SET status = 'liquidated', updated_at = NOW() WHERE id IN ({$placeholders})");
        if (!$stmt) {
            return 0.0;
        }
        $bindParams = [$types];
        foreach ($ids as $index => $id) {
            $bindParams[] = &$ids[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $stmt->close();
    }

    if (function_exists('purgeAnalyticsCache')) {
        purgeAnalyticsCache();
    }

    return round($markedAmount, 2);
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
            WHERE id = ? AND status IN ('pending', 'processing')
        ");
        $stmt->bind_param("i", $liquidation_id);
        $stmt->execute();
        $liquidation = $stmt->get_result()->fetch_assoc();
        
        if (!$liquidation) {
            throw new Exception('Liquidation not found or already processed');
        }
        
        if ($status === 'completed') {
            if (function_exists('dbh_table_exists') && dbh_table_exists('agent_commissions')) {
                liquidateAgentCommissionRows($liquidation['agent_id'], $liquidation['liquidated_amount']);
            } elseif (function_exists('dbh_table_exists') && dbh_table_exists('transactions')) {
                // Legacy commission rows stored on transactions.
                $stmt = $db->prepare("
                    UPDATE transactions
                    SET commission_status = 'liquidated'
                    WHERE user_id = ? AND commission_status = 'pending'
                    AND commission_earned > 0
                    ORDER BY created_at ASC
                    LIMIT ?
                ");

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
            }
            
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

if (!function_exists('updateCommissionLiquidationStatus')) {
    function updateCommissionLiquidationStatus($liquidation_id, $status, $admin_id = null, $notes = '') {
        global $db;

        $liquidation_id = (int) $liquidation_id;
        $status = trim((string) $status);
        $notes = trim((string) $notes);
        if ($liquidation_id <= 0 || $status === '') {
            return false;
        }

        $noteSuffix = $notes !== '' ? ("\n[" . date('Y-m-d H:i:s') . '] ' . $notes) : '';
        if ($admin_id !== null && $admin_id > 0) {
            $stmt = $db->prepare("
                UPDATE commission_liquidations
                SET status = ?, processed_by = ?, processed_at = NOW(), notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE id = ?
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('sisi', $status, $admin_id, $noteSuffix, $liquidation_id);
        } else {
            $stmt = $db->prepare("
                UPDATE commission_liquidations
                SET status = ?, processed_at = NOW(), notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE id = ?
            ");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('ssi', $status, $noteSuffix, $liquidation_id);
        }

        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        return $updated;
    }
}

if (!function_exists('submitCommissionAutomaticPayout')) {
    function submitCommissionAutomaticPayout($agent_id, $amount, $route, $admin_id, $notes = '') {
        global $db;

        ensureCommissionPayoutTables();

        $agent_id = (int) $agent_id;
        $admin_id = (int) $admin_id;
        $amount = round((float) $amount, 2);
        $route = trim((string) $route);
        $notes = trim((string) $notes);

        if ($agent_id <= 0 || $amount <= 0 || $route === '') {
            return ['success' => false, 'status' => 'failed', 'message' => 'Invalid automatic payout request.'];
        }

        $profile = getAgentCommissionPayoutProfile($agent_id);
        if (empty($profile['ready'])) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Agent payout details are incomplete: ' . implode(', ', (array) ($profile['issues'] ?? [])),
            ];
        }

        $pending = getAgentPendingCommission($agent_id);
        if ($amount > $pending) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Requested amount exceeds pending commission.'];
        }

        $liquidation = createCommissionLiquidation($agent_id, $amount, 'momo', $notes !== '' ? $notes : 'Automatic MoMo payout');
        if (empty($liquidation['success'])) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => (string) ($liquidation['message'] ?? 'Failed to reserve commission payout.'),
            ];
        }

        $liquidationId = (int) ($liquidation['liquidation_id'] ?? 0);
        $reference = trim((string) ($liquidation['reference'] ?? ('COMM_PAYOUT_' . strtoupper(uniqid()))));
        $provider = $route === 'paystack_auto' ? 'paystack' : 'moolre';
        $providerBankCode = '';
        $providerRecipientCode = '';
        $providerTransferCode = '';
        $providerReference = '';
        $providerStatus = '';
        $providerResponse = null;
        $payoutStatus = 'failed';
        $resultMessage = '';

        if ($route === 'paystack_auto') {
            $providerError = '';
            $recipient = createPaystackMobileMoneyRecipient($profile['name'], $profile['number'], $profile['network'], $providerError);
            if (!$recipient) {
                updateCommissionLiquidationStatus($liquidationId, 'failed', $admin_id, 'Paystack recipient creation failed: ' . ($providerError ?: 'Unknown error.'));
                $resultMessage = 'Paystack recipient creation failed: ' . ($providerError ?: 'Unknown error.');
            } else {
                $providerBankCode = (string) ($recipient['bank_code'] ?? '');
                $providerRecipientCode = (string) ($recipient['recipient_code'] ?? '');
                $transfer = initiatePaystackProfitTransfer(
                    $providerRecipientCode,
                    $amount,
                    $reference,
                    'Agent commission payout',
                    $providerError
                );

                if (!$transfer) {
                    updateCommissionLiquidationStatus($liquidationId, 'failed', $admin_id, 'Paystack transfer failed: ' . ($providerError ?: 'Unknown error.'));
                    $resultMessage = 'Paystack payout failed: ' . ($providerError ?: 'Unknown error.');
                } else {
                    $providerTransferCode = (string) ($transfer['transfer_code'] ?? '');
                    $providerReference = (string) ($transfer['provider_reference'] ?? $reference);
                    $providerStatus = strtolower(trim((string) ($transfer['status'] ?? 'processing')));
                    $providerResponse = $transfer['response'] ?? null;

                    if ($providerStatus === 'success') {
                        $processed = processCommissionLiquidation($liquidationId, $admin_id, 'completed', 'Automatic Paystack payout confirmed immediately.');
                        if (!empty($processed['success'])) {
                            $payoutStatus = 'completed';
                            $resultMessage = 'Commission payout completed via Paystack.';
                        } else {
                            updateCommissionLiquidationStatus($liquidationId, 'processing', $admin_id, 'Paystack transfer succeeded but local finalization needs follow-up.');
                            $payoutStatus = 'processing';
                            $resultMessage = 'Paystack accepted the payout, but local finalization is still pending.';
                        }
                    } else {
                        updateCommissionLiquidationStatus($liquidationId, 'processing', $admin_id, 'Awaiting Paystack transfer webhook confirmation.');
                        $payoutStatus = 'processing';
                        $resultMessage = 'Commission payout submitted to Paystack and is awaiting final confirmation.';
                    }
                }
            }
        } else {
            $providerError = '';
            $moolre = requestMoolreMomoPayout(
                $amount,
                $profile['network'],
                $profile['number'],
                $profile['name'],
                $reference,
                $providerError
            );
            if (!$moolre) {
                updateCommissionLiquidationStatus($liquidationId, 'failed', $admin_id, 'Moolre payout failed: ' . ($providerError ?: 'Unknown error.'));
                $providerStatus = 'failed';
                $resultMessage = 'Moolre payout failed: ' . ($providerError ?: 'Unknown error.');
            } else {
                $providerStatus = 'success';
                $providerReference = (string) (($moolre['data']['transactid'] ?? '') ?: ($moolre['data']['transaction_id'] ?? '') ?: $reference);
                $providerResponse = $moolre;
                $processed = processCommissionLiquidation($liquidationId, $admin_id, 'completed', 'Automatic Moolre payout completed.');
                if (!empty($processed['success'])) {
                    $payoutStatus = 'completed';
                    $resultMessage = 'Commission payout completed via Moolre.';
                } else {
                    updateCommissionLiquidationStatus($liquidationId, 'processing', $admin_id, 'Moolre payout succeeded but local finalization needs follow-up.');
                    $payoutStatus = 'processing';
                    $resultMessage = 'Moolre accepted the payout, but local finalization is still pending.';
                }
            }
        }

        $providerResponseJson = $providerResponse !== null ? json_encode($providerResponse, JSON_UNESCAPED_SLASHES) : null;
        $payoutNotes = trim($notes);
        if ($resultMessage !== '') {
            $payoutNotes = trim($payoutNotes . "\n" . $resultMessage);
        }

        $stmt = $db->prepare("
            INSERT INTO commission_payouts
                (agent_id, liquidation_id, commission_amount, payout_method, payout_route, payout_provider, payout_network, payout_name, payout_number,
                 reference_number, provider_bank_code, provider_recipient_code, provider_transfer_code, provider_reference, provider_status,
                 provider_response, status, processed_by, notes, processed_at)
            VALUES
                (?, ?, ?, 'momo', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if ($stmt) {
            $stmt->bind_param(
                'iidsssssssssssssis',
                $agent_id,
                $liquidationId,
                $amount,
                $route,
                $provider,
                $profile['network'],
                $profile['name'],
                $profile['number'],
                $reference,
                $providerBankCode,
                $providerRecipientCode,
                $providerTransferCode,
                $providerReference,
                $providerStatus,
                $providerResponseJson,
                $payoutStatus,
                $admin_id,
                $payoutNotes
            );
            $stmt->execute();
            $stmt->close();
        }

        return [
            'success' => in_array($payoutStatus, ['completed', 'processing'], true),
            'status' => $payoutStatus,
            'message' => $resultMessage !== '' ? $resultMessage : 'Commission payout processed.',
            'reference' => $reference,
            'liquidation_id' => $liquidationId,
            'profile' => $profile,
        ];
    }
}

/**
 * Get agent liquidation history
 */
function getAgentLiquidationHistory($agent_id, $limit = 10) {
    global $db;

    if (function_exists('dbh_table_exists') && (!dbh_table_exists('commission_liquidations') || !dbh_table_exists('users'))) {
        return [];
    }
    
    $stmt = $db->prepare("
        SELECT cl.*, u.full_name as processed_by_name
        FROM commission_liquidations cl
        LEFT JOIN users u ON cl.processed_by = u.id
        WHERE cl.agent_id = ?
        ORDER BY cl.created_at DESC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }
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
