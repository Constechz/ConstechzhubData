<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('dbh_table_exists')) {
    /**
     * Check if a given table exists in the current database.
     */
    function dbh_table_exists($tableName) {
        global $db;
        static $tableCache = [];

        if (empty($tableName) || !isset($db)) {
            return false;
        }
        $cacheKey = strtolower($tableName);
        if (array_key_exists($cacheKey, $tableCache)) {
            return $tableCache[$cacheKey];
        }

        try {
            $conn = $db->getConnection();
            if (!$conn instanceof mysqli) {
                $tableCache[$cacheKey] = false;
                return false;
            }

            $safeName = $conn->real_escape_string($tableName);
            $result = $conn->query("SHOW TABLES LIKE '{$safeName}'");
            if ($result) {
                $exists = $result->num_rows > 0;
                $result->free();
                $tableCache[$cacheKey] = $exists;
                return $exists;
            }
        } catch (Exception $e) {
            error_log('Table existence check failed for ' . $tableName . ': ' . $e->getMessage());
        }

        $tableCache[$cacheKey] = false;
        return false;
    }
}

if (!function_exists('dbh_table_has_column')) {
    /**
     * Check whether a specific table has a given column.
     */
    function dbh_table_has_column($tableName, $columnName) {
        global $db;
        static $columnCache = [];

        if (!dbh_table_exists($tableName) || empty($columnName)) {
            return false;
        }
        $cacheKey = strtolower($tableName . '.' . $columnName);
        if (array_key_exists($cacheKey, $columnCache)) {
            return $columnCache[$cacheKey];
        }

        try {
            $conn = $db->getConnection();
            if (!$conn instanceof mysqli) {
                $columnCache[$cacheKey] = false;
                return false;
            }
            $table = $conn->real_escape_string($tableName);
            $column = $conn->real_escape_string($columnName);
            $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            $exists = $result && $result->num_rows > 0;
            $columnCache[$cacheKey] = $exists;
            return $exists;
        } catch (Exception $e) {
            error_log("Column existence check failed for {$tableName}.{$columnName}: " . $e->getMessage());
            $columnCache[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('dbh_get_any_admin_id')) {
    /**
     * Return the ID of the first admin user found.
     */
    function dbh_get_any_admin_id() {
        global $db;

        static $cached_admin_id = null;
        if ($cached_admin_id !== null) {
            return $cached_admin_id;
        }

        $cached_admin_id = 0;

        if (!dbh_table_exists('users') || !dbh_table_has_column('users', 'role')) {
            return $cached_admin_id;
        }

        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $stmt->bind_result($admin_id);
                if ($stmt->fetch()) {
                    $cached_admin_id = (int) $admin_id;
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to fetch admin user ID: " . $e->getMessage());
        }

        return $cached_admin_id;
    }
}

if (!function_exists('recordOrderProfit')) {
    /**
     * Log bundle order profits into the agent_profits table.
     */
    function recordOrderProfit(array $data = []) {
        global $db;

        if (!dbh_table_exists('agent_profits')) {
            return false;
        }

        $defaults = [
            'agent_id' => 0,
            'order_id' => 0,
            'customer_id' => 0,
            'package_id' => 0,
            'customer_paid' => 0,
            'agent_cost' => 0,
            'agent_price' => 0,
            'reference' => '',
            'status' => 'earned'
        ];
        $payload = array_merge($defaults, $data);

        $order_id = (int) $payload['order_id'];
        $customer_id = (int) $payload['customer_id'];
        $package_id = (int) $payload['package_id'];

        if ($order_id <= 0 || $customer_id <= 0 || $package_id <= 0) {
            return false;
        }

        $agent_id = (int) $payload['agent_id'];
        if ($agent_id <= 0) {
            $agent_id = dbh_get_any_admin_id();
            if ($agent_id <= 0) {
                return false;
            }
        }

        $customer_paid = isset($payload['customer_paid']) ? (float) $payload['customer_paid'] : 0.0;
        $agent_cost = isset($payload['agent_cost']) ? (float) $payload['agent_cost'] : 0.0;
        $agent_price_value = isset($payload['agent_price']) ? (float) $payload['agent_price'] : $customer_paid;
        $profit_amount = round($customer_paid - $agent_cost, 2);
        $profit_percentage = 0.0;
        if ($agent_cost !== 0.0) {
            $profit_percentage = round(($profit_amount / $agent_cost) * 100, 2);
        }

        if ($order_id > 0 && dbh_table_has_column('agent_profits', 'order_id')) {
            try {
                $dupStmt = $db->prepare("SELECT id FROM agent_profits WHERE order_id = ? LIMIT 1");
                if ($dupStmt) {
                    $dupStmt->bind_param('i', $order_id);
                    $dupStmt->execute();
                    $dupStmt->store_result();
                    if ($dupStmt->num_rows > 0) {
                        $dupStmt->close();
                        return false;
                    }
                    $dupStmt->close();
                }
            } catch (Exception $e) {
                error_log('Failed to detect duplicate profit row: ' . $e->getMessage());
            }
        }

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];
        $addColumn = function($column, $type, $value) use (&$columns, &$placeholders, &$types, &$values) {
            $columns[] = $column;
            $placeholders[] = '?';
            $types .= $type;
            $values[] = $value;
        };

        $baseColumns = [
            'agent_id' => ['type' => 'i', 'value' => $agent_id],
            'order_id' => ['type' => 'i', 'value' => $order_id],
            'customer_id' => ['type' => 'i', 'value' => $customer_id],
            'package_id' => ['type' => 'i', 'value' => $package_id],
            'profit_amount' => ['type' => 'd', 'value' => $profit_amount],
        ];

        foreach ($baseColumns as $column => $spec) {
            if (!dbh_table_has_column('agent_profits', $column)) {
                return false;
            }
            $addColumn($column, $spec['type'], $spec['value']);
        }

        $revenue_column = null;
        foreach (['customer_paid', 'customer_payment'] as $candidate) {
            if (dbh_table_has_column('agent_profits', $candidate)) {
                $revenue_column = $candidate;
                break;
            }
        }
        if ($revenue_column) {
            $addColumn($revenue_column, 'd', $customer_paid);
        }

        if (dbh_table_has_column('agent_profits', 'agent_cost')) {
            $addColumn('agent_cost', 'd', $agent_cost);
        }

        if (dbh_table_has_column('agent_profits', 'agent_price')) {
            $addColumn('agent_price', 'd', $agent_price_value);
        }

        if (dbh_table_has_column('agent_profits', 'profit_percentage')) {
            $addColumn('profit_percentage', 'd', $profit_percentage);
        }

        if (dbh_table_has_column('agent_profits', 'status')) {
            $addColumn('status', 's', $payload['status']);
        }

        if (dbh_table_has_column('agent_profits', 'reference')) {
            $addColumn('reference', 's', $payload['reference']);
        }

        if (empty($columns)) {
            return false;
        }

        $sql = sprintf(
            "INSERT INTO agent_profits (%s) VALUES (%s)",
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }

            $bindParams = [$types];
            foreach ($values as $index => $value) {
                $bindParams[] = &$values[$index];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindParams);
            $executed = $stmt->execute();
            $stmt->close();
            return $executed;
        } catch (Exception $e) {
            error_log('Failed to record order profit: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('dbh_column_allows_null')) {
    /**
     * Determine if a column is nullable.
     */
    function dbh_column_allows_null($tableName, $columnName) {
        global $db;

        if (!dbh_table_has_column($tableName, $columnName)) {
            return false;
        }

        try {
            $conn = $db->getConnection();
            $table = $conn->real_escape_string($tableName);
            $column = $conn->real_escape_string($columnName);
            $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if ($result && ($row = $result->fetch_assoc())) {
                return strtolower((string) ($row['Null'] ?? '')) === 'yes';
            }
        } catch (Exception $e) {
            error_log("Nullability check failed for {$tableName}.{$columnName}: " . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('dbh_ensure_auto_increment')) {
    /**
     * Ensure the specified table column keeps its AUTO_INCREMENT attribute.
     */
    function dbh_ensure_auto_increment($tableName, $columnName = 'id') {
        global $db;

        static $checked = [];
        $cacheKey = strtolower($tableName . '.' . $columnName);

        if (isset($checked[$cacheKey])) {
            return $checked[$cacheKey];
        }

        if (!$tableName || !$columnName || !dbh_table_exists($tableName) || !dbh_table_has_column($tableName, $columnName)) {
            $checked[$cacheKey] = false;
            return false;
        }

        try {
            $conn = $db->getConnection();
            if (!$conn instanceof mysqli) {
                $checked[$cacheKey] = false;
                return false;
            }

            $table = $conn->real_escape_string($tableName);
            $column = $conn->real_escape_string($columnName);
            $columnType = 'int(11)';
            $hasAutoIncrement = false;

            $columnResult = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if ($columnResult && ($columnInfo = $columnResult->fetch_assoc())) {
                $columnType = $columnInfo['Type'] ?? $columnType;
                $extra = strtolower($columnInfo['Extra'] ?? '');
                $hasAutoIncrement = strpos($extra, 'auto_increment') !== false;
                $columnResult->free();
            }

            if (!$hasAutoIncrement) {
                $conn->query("ALTER TABLE `{$table}` MODIFY `{$column}` {$columnType} NOT NULL AUTO_INCREMENT");
                $columnResult = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                if ($columnResult && ($columnInfo = $columnResult->fetch_assoc())) {
                    $extra = strtolower($columnInfo['Extra'] ?? '');
                    $hasAutoIncrement = strpos($extra, 'auto_increment') !== false;
                    $columnResult->free();
                }
            }

            $primaryExists = false;
            $primaryResult = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if ($primaryResult) {
                $primaryExists = $primaryResult->num_rows > 0;
                $primaryResult->free();
            }
            if (!$primaryExists) {
                $conn->query("ALTER TABLE `{$table}` ADD PRIMARY KEY (`{$column}`)");
            }

            $checked[$cacheKey] = $hasAutoIncrement;
            return $hasAutoIncrement;
        } catch (Exception $e) {
            error_log("AUTO_INCREMENT check failed for {$tableName}.{$columnName}: " . $e->getMessage());
            $checked[$cacheKey] = false;
            return false;
        }
    }
}

if (!function_exists('dbh_generate_next_id')) {
    /**
     * Generate a sequential ID for tables that temporarily lost AUTO_INCREMENT.
     */
    function dbh_generate_next_id($tableName, $columnName = 'id') {
        global $db;
        if (!$tableName || !$columnName || !dbh_table_exists($tableName) || !dbh_table_has_column($tableName, $columnName)) {
            return 1;
        }

        try {
            $conn = $db->getConnection();
            if (!$conn instanceof mysqli) {
                return 1;
            }

            $table = $conn->real_escape_string($tableName);
            $column = $conn->real_escape_string($columnName);
            $result = $conn->query("SELECT MAX(`{$column}`) AS max_id FROM `{$table}`");
            $nextId = 1;
            if ($result && ($row = $result->fetch_assoc())) {
                $nextId = ((int) ($row['max_id'] ?? 0)) + 1;
                $result->free();
            }
            return max(1, $nextId);
        } catch (Exception $e) {
            error_log("Failed to generate fallback ID for {$tableName}.{$columnName}: " . $e->getMessage());
            return 1;
        }
    }
}

if (!function_exists('dbh_fix_auto_increment_tables')) {
    /**
     * Scan all tables and ensure their id columns keep AUTO_INCREMENT.
     */
    function dbh_fix_auto_increment_tables() {
        static $scanned = false;
        if ($scanned) {
            return;
        }
        $scanned = true;

        global $db;
        try {
            $conn = $db->getConnection();
            if (!$conn instanceof mysqli) {
                return;
            }

            $tablesResult = $conn->query('SHOW TABLES');
            if (!$tablesResult) {
                return;
            }

            while ($row = $tablesResult->fetch_array(MYSQLI_NUM)) {
                $tableName = $row[0] ?? null;
                if (!$tableName) {
                    continue;
                }
                dbh_ensure_auto_increment($tableName, 'id');
            }
            $tablesResult->free();
        } catch (Exception $e) {
            error_log('AUTO_INCREMENT repair scan failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dbh_asset')) {
    /**
     * Resolve a public asset URL regardless of the current directory depth.
     */
    function dbh_asset($path) {
        $normalized = ltrim($path, '/');
        if (defined('SITE_URL') && SITE_URL !== '') {
            $url = rtrim(SITE_URL, '/') . '/' . $normalized;
        } else {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if ($scriptName !== '') {
                $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
                if ($dir === '.' || $dir === '') {
                    $url = '/' . $normalized;
                } else {
                    $url = $dir . '/' . $normalized;
                }
            } else {
                $url = '/' . $normalized;
            }
        }

        static $assetVersions = [];
        if (!array_key_exists($normalized, $assetVersions)) {
            $assetVersions[$normalized] = '';
            $projectRoot = realpath(__DIR__ . '/..');
            if ($projectRoot) {
                $assetPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
                if (is_file($assetPath)) {
                    $assetVersions[$normalized] = (string) @filemtime($assetPath);
                }
            }
        }

        if ($assetVersions[$normalized] !== '') {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . 'v=' . $assetVersions[$normalized];
        }

        return $url;
    }
}

/**
 * Transfer wallet balance atomically between two users
 */
function transferWalletBalance($from_user_id, $to_user_id, $amount, $reference = '', $description = '') {
    global $db;
    if ($amount <= 0) return false;
    $walletTxnAuto = dbh_ensure_auto_increment('wallet_transactions');
    $conn = $db->getConnection();
    $conn->begin_transaction();
    try {
        // Debit sender
        $current_from = getWalletBalance($from_user_id);
        if ($current_from < $amount) throw new Exception('Insufficient balance');
        $new_from = $current_from - $amount;
        $stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet update prepare failed: ' . ($conn->error ?? 'unknown database error'));
        }
        $stmt->bind_param("di", $new_from, $from_user_id);
        $stmt->execute();
        // Sender wallet id
        $stmt = $db->prepare("SELECT id FROM wallets WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet lookup prepare failed: ' . ($conn->error ?? 'unknown database error'));
        }
        $stmt->bind_param("i", $from_user_id);
        $stmt->execute();
        $from_w = $stmt->get_result()->fetch_assoc();
        if (!$from_w || empty($from_w['id'])) {
            throw new Exception('Sender wallet not found.');
        }
        // Log sender txn
        $desc_from = $description ?: 'Transfer to user #' . $to_user_id;
        if ($walletTxnAuto) {
            $stmt = $db->prepare("INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($conn->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iidddss", $from_user_id, $from_w['id'], $amount, $current_from, $new_from, $reference, $desc_from);
        } else {
            $manual_txn_id = dbh_generate_next_id('wallet_transactions');
            $stmt = $db->prepare("INSERT INTO wallet_transactions (id, user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($conn->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iiidddss", $manual_txn_id, $from_user_id, $from_w['id'], $amount, $current_from, $new_from, $reference, $desc_from);
        }
        $stmt->execute();

        // Credit receiver
        $current_to = getWalletBalance($to_user_id);
        $new_to = $current_to + $amount;
        $stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet update prepare failed: ' . ($conn->error ?? 'unknown database error'));
        }
        $stmt->bind_param("di", $new_to, $to_user_id);
        $stmt->execute();
        // Receiver wallet id
        $stmt = $db->prepare("SELECT id FROM wallets WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet lookup prepare failed: ' . ($conn->error ?? 'unknown database error'));
        }
        $stmt->bind_param("i", $to_user_id);
        $stmt->execute();
        $to_w = $stmt->get_result()->fetch_assoc();
        if (!$to_w || empty($to_w['id'])) {
            throw new Exception('Receiver wallet not found.');
        }
        // Log receiver txn
        $desc_to = $description ?: 'Transfer from user #' . $from_user_id;
        if ($walletTxnAuto) {
            $stmt = $db->prepare("INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($conn->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iidddss", $to_user_id, $to_w['id'], $amount, $current_to, $new_to, $reference, $desc_to);
        } else {
            $manual_txn_id = dbh_generate_next_id('wallet_transactions');
            $stmt = $db->prepare("INSERT INTO wallet_transactions (id, user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($conn->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iiidddss", $manual_txn_id, $to_user_id, $to_w['id'], $amount, $current_to, $new_to, $reference, $desc_to);
        }
        $stmt->execute();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Wallet transfer failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if a customer is linked to an agent
 * Link is true if users.agent_id matches or there is a record in user_referrals(user_id, agent_id)
 */
function isCustomerLinkedToAgent($customer_id, $agent_id) {
    if (!$customer_id || !$agent_id) return false;
    global $db;
    // First, try direct users.agent_id
    $stmt = $db->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'customer' AND agent_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ii", $customer_id, $agent_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) return true;
    }
    // Fallback: referrals table if exists
    try {
        $check = $db->getConnection()->query("SHOW TABLES LIKE 'user_referrals'");
        if ($check && $check->num_rows > 0) {
            $stmt2 = $db->prepare("SELECT 1 FROM user_referrals WHERE user_id = ? AND agent_id = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param("ii", $customer_id, $agent_id);
                $stmt2->execute();
                if ($stmt2->get_result()->num_rows > 0) return true;
            }
        }
    } catch (Exception $e) { /* ignore */ }
    return false;
}

/**
 * Find user id by email or phone (returns null if not found)
 */
function dbh_get_users_phone_column() {
    static $column = null;
    if ($column !== null) {
        return $column;
    }
    $column = '';
    global $db;

    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'mobile' LIMIT 1");
    if ($stmt && $stmt->execute() && $stmt->get_result()->num_rows > 0) {
        $column = 'mobile';
        return $column;
    }

    $stmt = $db->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone' LIMIT 1");
    if ($stmt && $stmt->execute() && $stmt->get_result()->num_rows > 0) {
        $column = 'phone';
        return $column;
    }

    return $column;
}

/**
 * Find user id by email or phone (returns null if not found)
 */
function findUserIdByEmailOrPhone($identifier) {
    global $db;
    $id = null;
    // Normalize phone too
    $phoneNorm = formatPhone($identifier);
    $phoneColumn = dbh_get_users_phone_column();
    if ($phoneColumn === 'mobile') {
        $sql = "SELECT id FROM users WHERE email = ? OR mobile = ? LIMIT 1";
    } elseif ($phoneColumn === 'phone') {
        $sql = "SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1";
    } else {
        $sql = "SELECT id FROM users WHERE email = ? LIMIT 1";
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('findUserIdByEmailOrPhone prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        return null;
    }

    if ($phoneColumn === '') {
        $stmt->bind_param("s", $identifier);
    } else {
        $stmt->bind_param("ss", $identifier, $phoneNorm);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $id = (int)$row['id'];
    }

    return $id;
}

/**
 * Get a setting by key with default fallback
 */
function getSetting($key, $default = null) {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

/**
 * Update or insert a setting
 */
function updateSetting($key, $value, $description = '') {
    global $db;
    
    // Convert value to string for storage
    $value = (string)$value;
    
    $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return false;
    
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        return $stmt->execute();
    } else {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $key, $value, $description);
        return $stmt->execute();
    }
}

/**
 * Normalize pricing profile identifiers.
 */
function normalizePricingProfile($profile) {
    $profile = strtolower(trim((string) $profile));
    return in_array($profile, ['default', 'alternate'], true) ? $profile : 'default';
}

/**
 * Available pricing profile options.
 */
function getPricingProfileOptions() {
    return [
        'default' => 'Default',
        'alternate' => 'Alternate',
    ];
}

/**
 * Ensure pricing profile table + settings exist and seed default profile safely.
 */
function ensurePricingProfilesSchema() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!dbh_table_exists('package_pricing')) {
        return;
    }

    try {
        $db->query("
            CREATE TABLE IF NOT EXISTS `package_pricing_profiles` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `profile_key` ENUM('default','alternate') NOT NULL DEFAULT 'default',
                `package_id` INT NOT NULL,
                `user_type` ENUM('customer','agent') NOT NULL,
                `price` DECIMAL(8,2) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_profile_package_user` (`profile_key`,`package_id`,`user_type`),
                KEY `idx_pricing_profile_lookup` (`profile_key`,`package_id`,`user_type`),
                KEY `idx_pricing_profile_package` (`package_id`),
                CONSTRAINT `fk_pricing_profile_package` FOREIGN KEY (`package_id`) REFERENCES `data_packages` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        if (dbh_table_exists('settings') && dbh_table_has_column('settings', 'setting_key') && dbh_table_has_column('settings', 'setting_value')) {
            $db->query("
                INSERT INTO `settings` (`setting_key`, `setting_value`, `description`)
                VALUES ('active_pricing_profile', 'default', 'Active package pricing profile')
                ON DUPLICATE KEY UPDATE
                    `setting_value` = CASE
                        WHEN `setting_value` IN ('default','alternate') THEN `setting_value`
                        ELSE 'default'
                    END,
                    `description` = VALUES(`description`)
            ");
        }

        // Seed default profile only when the profile is empty.
        $seedDefault = true;
        $defaultCountResult = $db->query("SELECT COUNT(*) AS cnt FROM `package_pricing_profiles` WHERE `profile_key` = 'default'");
        if ($defaultCountResult && ($countRow = $defaultCountResult->fetch_assoc())) {
            $seedDefault = ((int) ($countRow['cnt'] ?? 0) === 0);
        }
        if ($seedDefault) {
            $db->query("
                INSERT INTO `package_pricing_profiles` (`profile_key`, `package_id`, `user_type`, `price`, `created_at`, `updated_at`)
                SELECT
                    'default',
                    pp.`package_id`,
                    pp.`user_type`,
                    MAX(pp.`price`) AS `price`,
                    NOW(),
                    NOW()
                FROM `package_pricing` pp
                LEFT JOIN `package_pricing_profiles` ppp
                    ON ppp.`profile_key` = 'default'
                   AND ppp.`package_id` = pp.`package_id`
                   AND ppp.`user_type` = pp.`user_type`
                WHERE ppp.`id` IS NULL
                GROUP BY pp.`package_id`, pp.`user_type`
            ");
        }
    } catch (Exception $e) {
        error_log('Pricing profile schema ensure failed: ' . $e->getMessage());
    }
}

/**
 * Read active pricing profile from settings.
 */
function getActivePricingProfile() {
    ensurePricingProfilesSchema();
    return normalizePricingProfile(getSetting('active_pricing_profile', 'default'));
}

/**
 * Persist active pricing profile in settings.
 */
function setActivePricingProfile($profile) {
    global $db;
    $profile = normalizePricingProfile($profile);

    if (!dbh_table_exists('settings')) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`)
            VALUES ('active_pricing_profile', ?, 'Active package pricing profile')
            ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $profile);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log('Failed to set active pricing profile: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get profile row count.
 */
function getPricingProfileRowCount($profile) {
    global $db;
    $profile = normalizePricingProfile($profile);
    ensurePricingProfilesSchema();

    if (!dbh_table_exists('package_pricing_profiles')) {
        return 0;
    }

    try {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM package_pricing_profiles WHERE profile_key = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $profile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    } catch (Exception $e) {
        error_log('Failed to count pricing profile rows: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Copy all live package_pricing rows into a profile (upsert).
 */
function syncProfileFromLivePackagePricing($profile) {
    global $db;
    $profile = normalizePricingProfile($profile);
    ensurePricingProfilesSchema();

    if (!dbh_table_exists('package_pricing_profiles') || !dbh_table_exists('package_pricing')) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `package_pricing_profiles` (`profile_key`, `package_id`, `user_type`, `price`, `created_at`, `updated_at`)
            SELECT ?, `package_id`, `user_type`, `price`, NOW(), NOW()
            FROM `package_pricing`
            ON DUPLICATE KEY UPDATE
                `price` = VALUES(`price`),
                `updated_at` = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $profile);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log('Failed syncing live package pricing into profile: ' . $e->getMessage());
        return false;
    }
}

/**
 * Copy all rows from one profile into another (upsert).
 */
function clonePricingProfileRows($sourceProfile, $targetProfile) {
    global $db;
    $sourceProfile = normalizePricingProfile($sourceProfile);
    $targetProfile = normalizePricingProfile($targetProfile);
    ensurePricingProfilesSchema();

    if ($sourceProfile === $targetProfile) {
        return true;
    }
    if (!dbh_table_exists('package_pricing_profiles')) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `package_pricing_profiles` (`profile_key`, `package_id`, `user_type`, `price`, `created_at`, `updated_at`)
            SELECT ?, `package_id`, `user_type`, `price`, NOW(), NOW()
            FROM `package_pricing_profiles`
            WHERE `profile_key` = ?
            ON DUPLICATE KEY UPDATE
                `price` = VALUES(`price`),
                `updated_at` = CURRENT_TIMESTAMP
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $targetProfile, $sourceProfile);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log('Failed cloning pricing profile rows: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ensure a profile has rows; if empty, clone from source profile (or current live pricing).
 */
function ensurePricingProfileSeeded($profile, $sourceProfile = 'default') {
    $profile = normalizePricingProfile($profile);
    $sourceProfile = normalizePricingProfile($sourceProfile);
    ensurePricingProfilesSchema();

    if (getPricingProfileRowCount($profile) > 0) {
        return true;
    }

    if (getPricingProfileRowCount($sourceProfile) > 0) {
        return clonePricingProfileRows($sourceProfile, $profile);
    }

    return syncProfileFromLivePackagePricing($profile);
}

/**
 * Replace live package_pricing rows from the selected profile.
 */
function syncLivePackagePricingFromProfile($profile) {
    global $db;
    $profile = normalizePricingProfile($profile);
    ensurePricingProfilesSchema();

    if (!dbh_table_exists('package_pricing_profiles') || !dbh_table_exists('package_pricing')) {
        return false;
    }

    if (!ensurePricingProfileSeeded($profile, 'default')) {
        return false;
    }

    try {
        $db->query("DELETE FROM `package_pricing`");

        $stmt = $db->prepare("
            INSERT INTO `package_pricing` (`package_id`, `user_type`, `price`)
            SELECT `package_id`, `user_type`, `price`
            FROM `package_pricing_profiles`
            WHERE `profile_key` = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $profile);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log('Failed syncing live package pricing from profile: ' . $e->getMessage());
        return false;
    }
}

/**
 * Switch the active profile and apply it to live package_pricing atomically.
 */
function switchActivePricingProfile($targetProfile) {
    global $db;
    $targetProfile = normalizePricingProfile($targetProfile);
    ensurePricingProfilesSchema();

    $currentProfile = getActivePricingProfile();
    if ($currentProfile === $targetProfile) {
        return true;
    }

    try {
        $conn = $db->getConnection();
        $conn->begin_transaction();

        if (!syncProfileFromLivePackagePricing($currentProfile)) {
            throw new Exception('Could not back up current pricing profile.');
        }

        if (!ensurePricingProfileSeeded($targetProfile, $currentProfile)) {
            throw new Exception('Target pricing profile is empty and could not be seeded.');
        }

        if (!syncLivePackagePricingFromProfile($targetProfile)) {
            throw new Exception('Could not apply selected pricing profile.');
        }

        if (!setActivePricingProfile($targetProfile)) {
            throw new Exception('Could not persist active pricing profile.');
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        try {
            $db->getConnection()->rollback();
        } catch (Exception $rollbackError) {
            error_log('Pricing profile switch rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('Switch pricing profile failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Upsert one package price into a profile.
 */
function upsertPricingProfilePrice($profile, $packageId, $userType, $price) {
    global $db;
    $profile = normalizePricingProfile($profile);
    $packageId = (int) $packageId;
    $userType = strtolower(trim((string) $userType));
    $price = (float) $price;

    if ($packageId <= 0 || !in_array($userType, ['customer', 'agent'], true)) {
        return false;
    }

    ensurePricingProfilesSchema();
    if (!dbh_table_exists('package_pricing_profiles')) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `package_pricing_profiles` (`profile_key`, `package_id`, `user_type`, `price`)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `price` = VALUES(`price`)
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sisd', $profile, $packageId, $userType, $price);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log('Failed to upsert pricing profile price: ' . $e->getMessage());
        return false;
    }
}

/**
 * Determine if email verification is enabled globally.
 */
function isEmailVerificationEnabled() {
    return getSetting('email_verification_enabled', '0') === '1';
}

/**
 * Determine which verification method is required: sms or email.
 */
function getVerificationMethod() {
    $method = strtolower(trim((string) getSetting('verification_method', 'sms')));
    return in_array($method, ['sms', 'email'], true) ? $method : 'sms';
}

/**
 * Determine if SMS OTP verification is enabled and configured.
 */
function isSmsOtpVerificationEnabled() {
    $providerEnabled = getSMSSetting('mnotify_enabled', getSMSSetting('kivalo_enabled', '0')) === '1';
    $otpEnabled = getSMSSetting('sms_otp_enabled', '0') === '1';
    return $providerEnabled && $otpEnabled;
}

/**
 * Ensure the email_verifications table exists.
 */
function ensureEmailVerificationTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!dbh_table_exists('users')) {
        return;
    }

    global $db;
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `token_hash` CHAR(64) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME DEFAULT NULL,
                KEY `idx_email_verifications_user` (`user_id`),
                KEY `idx_email_verifications_token` (`token_hash`),
                CONSTRAINT `fk_email_verifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Email verification table ensure failed: ' . $e->getMessage());
    }
}

/**
 * Ensure the otp_verifications table exists and has required columns.
 */
function ensureOtpVerificationTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!dbh_table_exists('users')) {
        return;
    }

    global $db;
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS `otp_verifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `phone_number` VARCHAR(20) NOT NULL,
                `otp_code` VARCHAR(6) NOT NULL,
                `purpose` VARCHAR(50) NOT NULL,
                `user_id` INT DEFAULT NULL,
                `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `is_used` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `verified_at` DATETIME DEFAULT NULL,
                KEY `idx_otp_phone_purpose` (`phone_number`, `purpose`),
                KEY `idx_otp_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $db->query($sql);

        $columns = [
            'is_verified' => "ALTER TABLE `otp_verifications` ADD COLUMN `is_verified` TINYINT(1) NOT NULL DEFAULT 0",
            'is_used' => "ALTER TABLE `otp_verifications` ADD COLUMN `is_used` TINYINT(1) NOT NULL DEFAULT 0",
            'verified_at' => "ALTER TABLE `otp_verifications` ADD COLUMN `verified_at` DATETIME DEFAULT NULL",
            'created_at' => "ALTER TABLE `otp_verifications` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'expires_at' => "ALTER TABLE `otp_verifications` ADD COLUMN `expires_at` DATETIME NOT NULL"
        ];
        foreach ($columns as $column => $alterSql) {
            if (!dbh_table_has_column('otp_verifications', $column)) {
                $db->query($alterSql);
            }
        }
    } catch (Exception $e) {
        error_log('OTP verification table ensure failed: ' . $e->getMessage());
    }
}

/**
 * Ensure the email_change_requests table exists.
 */
function ensureEmailChangeRequestsTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!dbh_table_exists('users')) {
        return;
    }

    global $db;
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS `email_change_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `current_email` VARCHAR(190) NOT NULL,
                `requested_email` VARCHAR(190) NOT NULL,
                `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reviewed_at` DATETIME DEFAULT NULL,
                `reviewed_by` INT DEFAULT NULL,
                `admin_note` TEXT DEFAULT NULL,
                KEY `idx_email_change_user_status` (`user_id`, `status`),
                KEY `idx_email_change_requested` (`requested_email`),
                CONSTRAINT `fk_email_change_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Email change requests table ensure failed: ' . $e->getMessage());
    }
}

/**
 * Create an email change request for a user.
 */
function createEmailChangeRequest($user_id, $requested_email) {
    $requested_email = trim((string) $requested_email);
    if ($requested_email === '' || !validateEmail($requested_email)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    ensureEmailChangeRequestsTable();

    global $db;
    $stmt = $db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to process request. Please try again.'];
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $current_email = $row['email'] ?? '';

    if ($current_email === '') {
        return ['success' => false, 'message' => 'Current email address not found.'];
    }

    if (strcasecmp($current_email, $requested_email) === 0) {
        return ['success' => false, 'message' => 'The new email matches your current email.'];
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('si', $requested_email, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'This email address is already in use.'];
        }
    }

    $stmt = $db->prepare("SELECT id, requested_email FROM email_change_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'message' => 'You already have a pending email change request.'];
        }
    }

    $stmt = $db->prepare("SELECT id FROM email_change_requests WHERE LOWER(requested_email) = LOWER(?) AND status = 'pending' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $requested_email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'This email address is already requested by another user.'];
        }
    }

    $stmt = $db->prepare("INSERT INTO email_change_requests (user_id, current_email, requested_email, status) VALUES (?, ?, ?, 'pending')");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to submit request. Please try again.'];
    }
    $stmt->bind_param('iss', $user_id, $current_email, $requested_email);
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Unable to submit request. Please try again.'];
    }

    return ['success' => true, 'message' => 'Email change request submitted. An admin will review it shortly.'];
}

/**
 * Check if a user has verified their email.
 */
function isUserEmailVerified($user_id) {
    if (!dbh_table_has_column('users', 'email_verified')) {
        return true;
    }
    global $db;
    $stmt = $db->prepare("SELECT email_verified FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (int) $row['email_verified'] === 1;
    }
    return true;
}

/**
 * Mark a user email as verified.
 */
function markUserEmailVerified($user_id) {
    if (!dbh_table_has_column('users', 'email_verified')) {
        return true;
    }
    global $db;
    $stmt = $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $user_id);
    return (bool) $stmt->execute();
}

/**
 * Create a new email verification token for a user.
 */
function createEmailVerificationToken($user_id, $ttl_minutes = 1440) {
    ensureEmailVerificationTable();

    global $db;
    $token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expires_at = date('Y-m-d H:i:s', time() + (int) $ttl_minutes * 60);

    try {
        $stmt = $db->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
        }

        $stmt = $db->prepare("INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iss', $user_id, $token_hash, $expires_at);
        if (!$stmt->execute()) {
            return null;
        }
    } catch (Exception $e) {
        error_log('Email verification token creation failed: ' . $e->getMessage());
        return null;
    }

    return $token;
}

/**
 * Send email verification message to a user.
 */
function sendEmailVerificationMessage($user_id, $email = null, $full_name = null, $force = false) {
    if (!isEmailVerificationEnabled()) {
        return ['success' => false, 'message' => 'Email verification is currently disabled.'];
    }

    if (isUserEmailVerified($user_id)) {
        return ['success' => true, 'message' => 'Email already verified.'];
    }

    ensureEmailVerificationTable();

    global $db;
    if (empty($email) || empty($full_name)) {
        $stmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $email = $email ?: $row['email'];
                $full_name = $full_name ?: $row['full_name'];
            }
        }
    }
    if (empty($email)) {
        return ['success' => false, 'message' => 'User email address is missing.'];
    }

    $cooldown_seconds = 30;
    if (!$force) {
        $stmt = $db->prepare("SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $last_sent = strtotime($row['created_at']);
                if ($last_sent && (time() - $last_sent) < $cooldown_seconds) {
                    return ['success' => false, 'message' => 'Verification email sent recently. Please wait a moment before resending.'];
                }
            }
        }
    }

    $token = createEmailVerificationToken($user_id);
    if (!$token) {
        return ['success' => false, 'message' => 'Could not generate verification link. Please try again.'];
    }

    if (!function_exists('sendEmail')) {
        require_once __DIR__ . '/email.php';
    }

    $site_name = function_exists('getSiteName') ? getSiteName() : 'Constechzhub';
    $verify_url = rtrim(SITE_URL, '/') . '/verify-email.php?token=' . urlencode($token);

    $name = trim((string) $full_name);
    if ($name === '') {
        $name = 'there';
    }

    $subject = $site_name . ' Email Verification';
    $body_html = '
        <p>Hi ' . htmlspecialchars($name) . ',</p>
        <p>Thanks for signing in to ' . htmlspecialchars($site_name) . '. Please verify your email address to secure your account.</p>
        <p><a href="' . htmlspecialchars($verify_url) . '">Verify my email</a></p>
        <p>If the button does not work, copy and paste this link into your browser:</p>
        <p>' . htmlspecialchars($verify_url) . '</p>
        <p>If you did not request this, you can ignore this email.</p>
    ';
    $body_text = "Hi {$name},\n\nPlease verify your email address for {$site_name}.\n\nVerify link: {$verify_url}\n\nIf you did not request this, you can ignore this email.";

    $sent = sendEmail($email, $subject, $body_html, $body_text, 'email_verification');
    if (!$sent) {
        return ['success' => false, 'message' => 'Failed to send verification email. Please contact support.'];
    }

    return ['success' => true, 'message' => 'Verification email sent. Please check your inbox.'];
}

/**
 * Send SMS verification code to a user.
 */
function sendSmsVerificationMessage($user_id, $phone = null, $full_name = null, $force = false) {
    if (!isEmailVerificationEnabled()) {
        return ['success' => false, 'message' => 'Verification is currently disabled.'];
    }

    if (isUserEmailVerified($user_id)) {
        return ['success' => true, 'message' => 'Account already verified.'];
    }

    if (!isSmsOtpVerificationEnabled()) {
        return ['success' => false, 'message' => 'SMS verification is currently disabled.'];
    }

    ensureOtpVerificationTable();

    global $db;
    if (empty($phone) || empty($full_name)) {
        $phoneColumn = dbh_get_users_phone_column();
        $phoneSelect = $phoneColumn ? "{$phoneColumn} AS phone" : "NULL AS phone";
        $stmt = $db->prepare("SELECT {$phoneSelect}, full_name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $phone = $phone ?: ($row['phone'] ?? null);
                $full_name = $full_name ?: ($row['full_name'] ?? null);
            }
        }
    }

    if (empty($phone)) {
        return ['success' => false, 'message' => 'User phone number is missing.'];
    }

    $phone = formatPhone($phone);
    $purpose = 'phone_verification';
    $cooldown_seconds = 120;

    if (!$force) {
        $stmt = $db->prepare("SELECT created_at FROM otp_verifications WHERE phone_number = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ss', $phone, $purpose);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $last_sent = strtotime($row['created_at']);
                if ($last_sent && (time() - $last_sent) < $cooldown_seconds) {
                    return ['success' => false, 'message' => 'Verification code sent recently. Please wait a moment before resending.'];
                }
            }
        }
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry_minutes = 10;
    $expires_at = date('Y-m-d H:i:s', time() + ($expiry_minutes * 60));

    $stmt = $db->prepare("INSERT INTO otp_verifications (phone_number, otp_code, purpose, user_id, is_verified, is_used, expires_at, created_at) VALUES (?, ?, ?, ?, 0, 0, ?, NOW())");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Could not generate verification code. Please try again.'];
    }
    $stmt->bind_param('sssis', $phone, $otp, $purpose, $user_id, $expires_at);
    if (!$stmt->execute()) {
        return ['success' => false, 'message' => 'Could not generate verification code. Please try again.'];
    }

    if (!function_exists('sendSMS')) {
        require_once __DIR__ . '/mnotify_sms.php';
    }

    $site_name = function_exists('getSiteName') ? getSiteName() : 'Constechzhub';
    $message = "Your {$site_name} verification code is {$otp}. It expires in {$expiry_minutes} minutes.";

    $sent = sendSMS($phone, $message, 'phone_verification', $user_id);
    if (empty($sent['success'])) {
        $cleanup = $db->prepare("DELETE FROM otp_verifications WHERE phone_number = ? AND otp_code = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
        if ($cleanup) {
            $cleanup->bind_param('sss', $phone, $otp, $purpose);
            $cleanup->execute();
        }
        return ['success' => false, 'message' => 'Failed to send verification code. Please contact support.'];
    }

    return ['success' => true, 'message' => 'Verification code sent. Please check your phone.'];
}

/**
 * Verify a user email using a token.
 */
function verifyEmailWithToken($token) {
    $token = trim((string) $token);
    if ($token === '') {
        return ['success' => false, 'message' => 'Missing verification token.'];
    }

    ensureEmailVerificationTable();

    global $db;
    $token_hash = hash('sha256', $token);
    $stmt = $db->prepare("
        SELECT ev.id, ev.user_id, u.email_verified
        FROM email_verifications ev
        JOIN users u ON u.id = ev.user_id
        WHERE ev.token_hash = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
        LIMIT 1
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Verification is currently unavailable.'];
    }
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return ['success' => false, 'message' => 'Verification link is invalid or expired.'];
    }

    $user_id = (int) $row['user_id'];
    $already_verified = (int) $row['email_verified'] === 1;

    if (!$already_verified) {
        markUserEmailVerified($user_id);
    }

    $stmt = $db->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }

    return [
        'success' => true,
        'message' => $already_verified ? 'Email already verified.' : 'Your email has been verified successfully.',
        'user_id' => $user_id
    ];
}

/**
 * Verify SMS OTP for account verification.
 */
function verifySmsVerificationCode($user_id, $otp, $phone = null) {
    $otp = trim((string) $otp);
    if ($otp === '') {
        return ['success' => false, 'message' => 'Missing verification code.'];
    }

    ensureOtpVerificationTable();

    global $db;
    if (empty($phone)) {
        $phoneColumn = dbh_get_users_phone_column();
        $phoneSelect = $phoneColumn ? "{$phoneColumn} AS phone" : "NULL AS phone";
        $stmt = $db->prepare("SELECT {$phoneSelect} FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $phone = $row['phone'] ?? null;
            }
        }
    }

    if (empty($phone)) {
        return ['success' => false, 'message' => 'User phone number is missing.'];
    }

    $phone = formatPhone($phone);
    $purpose = 'phone_verification';

    $stmt = $db->prepare("SELECT id, expires_at, is_verified, is_used FROM otp_verifications WHERE phone_number = ? AND otp_code = ? AND purpose = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Verification is currently unavailable.'];
    }
    $stmt->bind_param('sss', $phone, $otp, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return ['success' => false, 'message' => 'Invalid verification code.'];
    }

    if (!empty($row['is_verified']) || !empty($row['is_used'])) {
        return ['success' => false, 'message' => 'Verification code has already been used.'];
    }

    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return ['success' => false, 'message' => 'Verification code has expired.'];
    }

    $update = $db->prepare("UPDATE otp_verifications SET is_verified = 1, is_used = 1, verified_at = NOW() WHERE id = ?");
    if ($update) {
        $update->bind_param('i', $row['id']);
        $update->execute();
    }

    if (dbh_table_has_column('users', 'phone_verified')) {
        $stmt = $db->prepare("UPDATE users SET phone_verified = 1 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
        }
    }

    if (dbh_table_has_column('users', 'email_verified')) {
        markUserEmailVerified($user_id);
    }

    return ['success' => true, 'message' => 'Your phone number has been verified successfully.'];
}

/**
 * Determine whether to treat the request as JSON/API.
 */
function isJsonRequest() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        return true;
    }
    if (stripos($accept, 'application/json') !== false) {
        return true;
    }
    return strtolower($xhr) === 'xmlhttprequest';
}

/**
 * Enforce email verification before allowing access.
 */
function enforceEmailVerificationGate() {
    if (!isEmailVerificationEnabled()) {
        return;
    }
    if (!isLoggedIn()) {
        return;
    }
    if (!dbh_table_has_column('users', 'email_verified')) {
        return;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $basename = $path ? basename($path) : '';
    $allowed = ['verify-email.php', 'logout.php'];
    if (in_array($basename, $allowed, true)) {
        return;
    }

    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    if (!$user) {
        return;
    }
    $role = normalizeUserRole($user['role'] ?? '');
    if ($role === normalizeUserRole(defined('ROLE_ADMIN') ? ROLE_ADMIN : 'admin')
        || $role === normalizeUserRole(defined('ROLE_SUPER_ADMIN') ? ROLE_SUPER_ADMIN : 'super_admin')) {
        return;
    }
    if (!empty($user['email_verified'])) {
        return;
    }

    $method = function_exists('getVerificationMethod') ? getVerificationMethod() : 'sms';
    if ($method === 'email') {
        sendEmailVerificationMessage((int) $user['id'], $user['email'] ?? null, $user['full_name'] ?? null);
        $message = 'Please verify your email to continue.';
    } else {
        sendSmsVerificationMessage((int) $user['id'], $user['phone'] ?? null, $user['full_name'] ?? null);
        $message = 'Please verify your phone number to continue.';
    }
    if (isJsonRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }

    setFlashMessage('error', $message);
    header('Location: ' . SITE_URL . '/verify-email.php');
    exit();
}

/**
 * Normalize payment gateway identifiers.
 */
function normalizePaymentGateway($gateway) {
    $gateway = strtolower(trim((string) $gateway));
    return in_array($gateway, ['paystack', 'moolre'], true) ? $gateway : '';
}

/**
 * Get the active payment gateway selection.
 */
function getActivePaymentGateway($default = null) {
    $fallback = $default;
    if ($fallback === null) {
        $fallback = defined('PAYMENT_GATEWAY_ACTIVE') ? PAYMENT_GATEWAY_ACTIVE : 'paystack';
    }
    $candidate = getSetting('payment_gateway_active', $fallback);
    $normalized = normalizePaymentGateway($candidate);
    if ($normalized === '') {
        $normalized = normalizePaymentGateway($fallback);
    }
    return $normalized ?: 'paystack';
}

/**
 * Get enabled payment gateways for checkout choices.
 */
if (!function_exists('getEnabledPaymentGateways')) {
function getEnabledPaymentGateways() {
    $active = getActivePaymentGateway('paystack');
    $enabled = [];

    if ($active !== '') {
        $enabled[] = $active;
    }

    $moolreConfig = function_exists('getMoolreConfig') ? getMoolreConfig() : [];
    if (function_exists('isMoolreConfigured') && isMoolreConfigured($moolreConfig)) {
        $enabled[] = 'moolre';
    }

    $paystackSecret = dbh_env('PAYSTACK_SECRET_KEY', defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '');
    if ($paystackSecret && stripos($paystackSecret, 'your_secret_key_here') === false) {
        $enabled[] = 'paystack';
    }

    $enabled = array_values(array_unique(array_filter(array_map('normalizePaymentGateway', $enabled))));
    return !empty($enabled) ? $enabled : ['paystack'];
}
}

/**
 * Check whether a payment gateway is available.
 */
if (!function_exists('isPaymentGatewayEnabled')) {
function isPaymentGatewayEnabled($gateway) {
    $gateway = normalizePaymentGateway($gateway);
    if ($gateway === '') {
        return false;
    }
    return in_array($gateway, getEnabledPaymentGateways(), true);
}
}

/**
 * Ensure the transactions.payment_method enum supports all gateways.
 */
function ensurePaymentGatewaySchema() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!dbh_table_exists('transactions')) {
        return;
    }

    try {
        $res = $db->query("SHOW COLUMNS FROM `transactions` LIKE 'payment_method'");
        if (!$res || $res->num_rows === 0) {
            return;
        }
        $row = $res->fetch_assoc();
        $type = $row['Type'] ?? '';
        if (stripos($type, 'enum(') !== 0 || stripos($type, "'moolre'") !== false) {
            return;
        }
        if (!preg_match('/^enum\\((.*)\\)$/i', $type, $matches)) {
            return;
        }
        $values = str_getcsv($matches[1], ',', "'");
        if (!in_array('moolre', $values, true)) {
            $values[] = 'moolre';
        }
        $escaped = array_map(function ($value) {
            return str_replace("'", "\\'", $value);
        }, $values);
        $enumSql = "enum('" . implode("','", $escaped) . "')";
        $db->query("ALTER TABLE `transactions` MODIFY `payment_method` {$enumSql} NOT NULL");
    } catch (Exception $e) {
        error_log('Payment gateway schema ensure failed: ' . $e->getMessage());
    }
}

/**
 * Ensure notifications table supports media + CTA columns used by dashboard sliders.
 */
function ensureNotificationsSchema() {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    global $db;
    if (!dbh_table_exists('notifications')) {
        return;
    }

    $columnMigrations = [
        'image_path' => "ALTER TABLE `notifications` ADD COLUMN `image_path` VARCHAR(255) NULL DEFAULT NULL",
        'link_url' => "ALTER TABLE `notifications` ADD COLUMN `link_url` VARCHAR(500) NULL DEFAULT NULL",
        'cta_text' => "ALTER TABLE `notifications` ADD COLUMN `cta_text` VARCHAR(120) NULL DEFAULT NULL",
    ];

    foreach ($columnMigrations as $column => $sql) {
        if (dbh_table_has_column('notifications', $column)) {
            continue;
        }

        try {
            $db->query($sql);
        } catch (Exception $e) {
            error_log('Notifications schema ensure failed for column ' . $column . ': ' . $e->getMessage());
        }
    }
}

/**
 * Fetch Moolre credentials with env override.
 */
function getMoolreConfig() {
    $user = dbh_env('MOOLRE_API_USER', defined('MOOLRE_API_USER') ? MOOLRE_API_USER : '');
    $key = dbh_env('MOOLRE_API_KEY', defined('MOOLRE_API_KEY') ? MOOLRE_API_KEY : '');
    $pub = dbh_env('MOOLRE_API_PUBKEY', defined('MOOLRE_API_PUBKEY') ? MOOLRE_API_PUBKEY : '');
    $vas = dbh_env('MOOLRE_API_VASKEY', defined('MOOLRE_API_VASKEY') ? MOOLRE_API_VASKEY : '');
    $account = dbh_env('MOOLRE_ACCOUNT_NUMBER', defined('MOOLRE_ACCOUNT_NUMBER') ? MOOLRE_ACCOUNT_NUMBER : '');
    $webhook_secret = dbh_env('MOOLRE_WEBHOOK_SECRET', defined('MOOLRE_WEBHOOK_SECRET') ? MOOLRE_WEBHOOK_SECRET : '');

    return [
        'user' => trim((string) $user),
        'key' => trim((string) $key),
        'pubkey' => trim((string) $pub),
        'vaskey' => trim((string) $vas),
        'account_number' => trim((string) $account),
        'webhook_secret' => trim((string) $webhook_secret),
    ];
}

/**
 * Verify that required Moolre config values exist.
 */
function isMoolreConfigured(array $config) {
    return ($config['user'] ?? '') !== '' && ($config['pubkey'] ?? '') !== '' && ($config['account_number'] ?? '') !== '';
}

/**
 * Build Moolre HTTP headers for API calls.
 */
function buildMoolreHeaders(array $config) {
    $headers = [
        'Content-Type: application/json',
        'X-API-USER: ' . ($config['user'] ?? ''),
        'X-API-PUBKEY: ' . ($config['pubkey'] ?? ''),
    ];

    if (!empty($config['key'])) {
        $headers[] = 'X-API-KEY: ' . $config['key'];
    }
    if (!empty($config['vaskey'])) {
        $headers[] = 'X-API-VASKEY: ' . $config['vaskey'];
    }

    return $headers;
}

/**
 * Send a POST request to Moolre API and return decoded JSON.
 */
function moolrePostJson($url, array $payload, array $config, &$error = null) {
    $error = null;
    if (!function_exists('curl_init')) {
        $error = 'cURL is not enabled on this server.';
        return null;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => buildMoolreHeaders($config),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $error = 'cURL error: ' . $err;
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $error = 'Invalid JSON response from Moolre.';
        return null;
    }

    return $decoded;
}

/**
 * Initiate a Moolre MoMo payout using configured payout endpoint.
 */
function requestMoolreMomoPayout($amount, $network, $phone, $name, $reference, &$error = null) {
    $error = null;
    $config = getMoolreConfig();
    if (!isMoolreConfigured($config)) {
        $error = 'Moolre keys are not configured.';
        return null;
    }

    $payout_url = dbh_env('MOOLRE_PAYOUT_URL', defined('MOOLRE_PAYOUT_URL') ? MOOLRE_PAYOUT_URL : '');
    $payout_url = trim((string) $payout_url);
    if ($payout_url === '') {
        $error = 'Moolre payout URL is not configured (MOOLRE_PAYOUT_URL).';
        return null;
    }

    if (function_exists('formatPhone')) {
        $formatted = formatPhone($phone);
        if ($formatted) {
            $phone = $formatted;
        }
    }

    $payload = [
        'amount' => round((float) $amount, 2),
        'currency' => defined('CURRENCY_CODE') ? CURRENCY_CODE : '',
        'accountnumber' => $config['account_number'] ?? '',
        'phone' => $phone,
        'network' => $network,
        'name' => $name,
        'reference' => $reference,
        'callback' => defined('MOOLRE_PAYOUT_CALLBACK_URL') ? MOOLRE_PAYOUT_CALLBACK_URL : (defined('SITE_URL') ? (SITE_URL . '/api/moolre_payout_webhook.php') : '')
    ];

    $result = moolrePostJson($payout_url, $payload, $config, $error);
    if (!$result) {
        return null;
    }

    $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
    if (!$status_ok) {
        $error = $result['message'] ?? 'Moolre payout failed.';
        return null;
    }

    return $result;
}

/**
 * Return whether the maintenance mode flag is currently enabled.
 */
function isMaintenanceModeEnabled() {
    return getSetting('maintenance_mode', '0') === '1';
}

/**
 * Return the current maintenance notice message, falling back to a friendly default.
 */
function getMaintenanceMessage($fallback = null) {
    $message = trim((string) getSetting('maintenance_message', ''));
    if ($message !== '') {
        return $message;
    }
    if ($fallback !== null) {
        return $fallback;
    }
    return 'Our storefront is undergoing maintenance. Please check back soon.';
}

/**
 * Decide whether the current request should bypass the maintenance notice.
 */
function shouldBypassMaintenanceMode() {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $scriptName = strtolower(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));
    $basename = basename($scriptName);
    $whitelist = [
        'maintenance.php',
        'login.php',
        'logout.php',
        'forgot-password.php',
        'reset-password.php'
    ];

    if ($basename && in_array($basename, $whitelist, true)) {
        return true;
    }

    foreach (['/api/', '/cron/', '/deployment/'] as $segment) {
        if ($segment !== '' && strpos($scriptName, $segment) !== false) {
            return true;
        }
    }

    if (isLoggedIn() && (hasRole(ROLE_ADMIN) || hasRole(ROLE_SUPER_ADMIN))) {
        return true;
    }

    return false;
}

/**
 * Output a stylized maintenance notice and stop further execution.
 */
function renderMaintenanceNotice() {
    $siteTitle = htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8');
    $siteUrl = SITE_URL;
    $message = nl2br(htmlspecialchars(getMaintenanceMessage(), ENT_QUOTES, 'UTF-8'));
    $whatsappRaw = trim((string) getSetting('site_whatsapp_number', ''));
    $whatsappContactLine = '';
    if ($whatsappRaw !== '') {
        $digits = preg_replace('/\\D+/', '', $whatsappRaw);
        if ($digits !== '') {
            $link = sprintf(
                '<a href="https://wa.me/%s" target="_blank" rel="noopener">Chat on WhatsApp</a>',
                htmlspecialchars($digits, ENT_QUOTES, 'UTF-8')
            );
            $whatsappContactLine = '<p class="contact-line">' . $link . '</p>';
        }
    }

    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode | {$siteTitle}</title>
    <style>
        :root {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: #2E294E;
            background: #2E294E;
        }
        body {
            margin: 0;
            background: linear-gradient(135deg, #2E294E, #2E294E);
            display: flex;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .maintenance-shell {
            text-align: center;
            padding: clamp(2rem, 5vw, 3rem);
            border-radius: 24px;
            background: rgba(46, 41, 78, 0.85);
            color: #F1E9DA;
            max-width: 520px;
            box-shadow: 0 25px 60px rgba(46, 41, 78, 0.35);
            border: 1px solid rgba(241, 233, 218, 0.05);
        }
        .maintenance-shell h1 {
            font-size: clamp(2rem, 3vw, 2.4rem);
            margin-bottom: 0.5rem;
        }
        .maintenance-shell p {
            margin-bottom: 1.25rem;
            color: rgba(241, 233, 218, 0.8);
            line-height: 1.6;
            font-size: 1rem;
        }
        .maintenance-shell .badge {
            text-transform: uppercase;
            letter-spacing: 0.25em;
            font-size: 0.75rem;
            color: #F1E9DA;
            margin-bottom: 1rem;
            display: inline-block;
        }
        .maintenance-shell .home-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.65rem 1.4rem;
            border-radius: 999px;
            border: 1px solid rgba(241, 233, 218, 0.25);
            color: #F1E9DA;
            text-decoration: none;
            font-weight: 600;
            background: rgba(241, 233, 218, 0.08);
        }
        .maintenance-shell .contact-line {
            margin-top: 0.75rem;
            color: rgba(241, 233, 218, 0.85);
        }
    </style>
</head>
<body>
    <main class="maintenance-shell">
        <span class="badge">Maintenance Mode</span>
        <h1>Weâ€™ll be back soon</h1>
        <p>{$message}</p>
        <a class="home-link" href="{$siteUrl}">Return to {$siteTitle}</a>
        {$whatsappContactLine}
    </main>
</body>
</html>
HTML;

    exit;
}

/**
 * Get agent_id for a user (nullable)
 */
function getUserAgentId($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT agent_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['agent_id'] ?? null;
    }
    return null;
}

/**
 * Get agent-specific minimum top-up for their customers
 */
function getAgentMinTopup($agent_id) {
    if (!$agent_id) return null;
    global $db;
    $stmt = $db->prepare("SELECT min_topup_agent_customer FROM agent_paystack_settings WHERE agent_id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("i", $agent_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['min_topup_agent_customer'] !== null ? (float)$row['min_topup_agent_customer'] : null;
    }
    return null;
}

/**
 * Compute effective min/max top-up limits for a user
 */
function getEffectiveTopupLimits($user_id, $role) {
    $global_max = (float) getSetting('max_topup_global', 1000);
    if ($role === 'agent') {
        $min = (float) getSetting('min_topup_admin_agent', 5);
        return [ 'min' => $min, 'max' => $global_max ];
    }
    // customer
    $admin_min = (float) getSetting('min_topup_admin_customer', 5);
    $agent_id = getUserAgentId($user_id);
    $agent_min = $agent_id ? getAgentMinTopup($agent_id) : null;
    $effective_min = $agent_min !== null ? max($admin_min, (float)$agent_min) : $admin_min;
    return [ 'min' => $effective_min, 'max' => $global_max ];
}

// Data Bundle Hub - Utility Functions

/**
 * Sanitize input data
 */
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate phone number (Ghana format)
 */
function validatePhone($phone) {
    // Remove spaces and special characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if it's a valid Ghana phone number
    if (preg_match('/^(0[2-9][0-9]{8}|233[2-9][0-9]{8})$/', $phone)) {
        return true;
    }
    return false;
}

/**
 * Normalize phone number to Ghana local format (0XXXXXXXXX).
 */
function normalizeGhanaLocalPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phone, '233') === 0) {
        $phone = '0' . substr($phone, 3);
    }
    return $phone;
}

/**
 * Validate MTN Ghana phone number (local 10-digit with MTN prefixes).
 */
function isMtnNumber($phone) {
    $local = normalizeGhanaLocalPhone($phone);
    if (!preg_match('/^0[0-9]{9}$/', $local)) {
        return false;
    }
    $prefix = substr($local, 0, 3);
    $mtn_prefixes = ['024', '025', '053', '054', '055', '059'];
    return in_array($prefix, $mtn_prefixes, true);
}

/**
 * Validate Telecel (Vodafone) Ghana phone number (local 10-digit with Telecel prefixes).
 */
function isTelecelNumber($phone) {
    $local = normalizeGhanaLocalPhone($phone);
    if (!preg_match('/^0[0-9]{9}$/', $local)) {
        return false;
    }
    $prefix = substr($local, 0, 3);
    $telecel_prefixes = ['020', '050'];
    return in_array($prefix, $telecel_prefixes, true);
}

/**
 * Validate AT (AirtelTigo) Ghana phone number (local 10-digit with AT prefixes).
 */
function isAtNumber($phone) {
    $local = normalizeGhanaLocalPhone($phone);
    if (!preg_match('/^0[0-9]{9}$/', $local)) {
        return false;
    }
    $prefix = substr($local, 0, 3);
    $at_prefixes = ['026', '027', '056', '057'];
    return in_array($prefix, $at_prefixes, true);
}

/**
 * Format phone number to standard format
 */
function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (substr($phone, 0, 3) === '233') {
        return $phone;
    } elseif (substr($phone, 0, 1) === '0') {
        return '233' . substr($phone, 1);
    }
    
    return $phone;
}

/**
 * Determine whether SMS notifications are enabled via sms_settings.
 */
function dbh_is_sms_notifications_enabled() {
    global $db;

    $envFlag = dbh_env('SMS_NOTIFICATIONS_ENABLED');
    if ($envFlag !== null && $envFlag !== '') {
        $normalized = strtolower(trim((string) $envFlag));
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
    }

    try {
        $conn = $db->getConnection();
        $tableCheck = $conn->query("SHOW TABLES LIKE 'sms_settings'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return true;
        }

        // Newer schema: is_active flag on sms_settings
        $hasIsActive = $conn->query("SHOW COLUMNS FROM sms_settings LIKE 'is_active'");
        if ($hasIsActive && $hasIsActive->num_rows > 0) {
            $stmt = $db->prepare("SELECT is_active FROM sms_settings ORDER BY id DESC LIMIT 1");
            if ($stmt && $stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && isset($row['is_active'])) {
                    return (bool) $row['is_active'];
                }
            }
        }

        // Legacy key/value schema: look for an enabled flag
        $hasSettingKey = $conn->query("SHOW COLUMNS FROM sms_settings LIKE 'setting_key'");
        if ($hasSettingKey && $hasSettingKey->num_rows > 0) {
            $stmt = $db->prepare("SELECT setting_value FROM sms_settings WHERE setting_key IN ('sms_active', 'sms_notifications_enabled') ORDER BY id DESC LIMIT 1");
            if ($stmt && $stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && isset($row['setting_value'])) {
                    $val = strtolower(trim((string) $row['setting_value']));
                    return in_array($val, ['1', 'true', 'yes', 'on'], true);
                }
            }
        }
    } catch (Exception $e) {
        error_log('SMS enabled check failed: ' . $e->getMessage());
        return true;
    }

    return true;
}

/**
 * Send result checker details via SMS.
 */
function sendResultCheckerSms($phone, $cardType, $pin, $serial, $link = '', $userId = null) {
    if (empty($phone) || empty($pin) || empty($serial)) {
        return ['success' => false, 'error' => 'Missing SMS phone or card details'];
    }

    if (!dbh_is_sms_notifications_enabled()) {
        return ['success' => false, 'error' => 'SMS notifications disabled'];
    }

    require_once __DIR__ . '/mnotify_sms.php';

    $cardType = strtoupper(trim((string) $cardType));
    $linkText = $link ? " Link: {$link}" : '';
    $message = "Result Checker {$cardType} PIN: {$pin}, Serial: {$serial}.{$linkText}";

    try {
        return sendSMS($phone, $message, 'result_checker', $userId);
    } catch (Exception $e) {
        error_log('Result checker SMS failed: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send result checker details via Email.
 */
function sendResultCheckerEmail($email, $cardType, $pin, $serial, $link = '', $recipientName = '') {
    if (empty($email) || empty($pin) || empty($serial)) {
        return ['success' => false, 'message' => 'Missing email or card details'];
    }

    if (!function_exists('sendEmail')) {
        require_once __DIR__ . '/email.php';
    }

    $siteName = function_exists('getSiteName') ? getSiteName() : (defined('SITE_NAME') ? SITE_NAME : 'Constechzhub');
    $cardType = strtoupper(trim((string) $cardType));
    $recipientName = trim((string) $recipientName);
    $greetingName = $recipientName !== '' ? $recipientName : 'there';
    $linkText = $link ? "<p>Checker link: <a href=\"" . htmlspecialchars($link) . "\">" . htmlspecialchars($link) . "</a></p>" : '';

    $subject = "{$siteName} Result Checker Details";
    $body_html = "
        <p>Hi {$greetingName},</p>
        <p>Your {$cardType} result checker details are ready:</p>
        <ul>
            <li><strong>PIN:</strong> {$pin}</li>
            <li><strong>Serial:</strong> {$serial}</li>
        </ul>
        {$linkText}
        <p>Thank you,<br>{$siteName}</p>
    ";
    $body_text = "Hi {$greetingName},\n\n"
        . "Your {$cardType} result checker details are ready:\n"
        . "PIN: {$pin}\n"
        . "Serial: {$serial}\n"
        . ($link ? "Checker link: {$link}\n" : '')
        . "\nThank you,\n{$siteName}";

    $sent = sendEmail($email, $subject, $body_html, $body_text, 'result_checker');
    return [
        'success' => (bool) $sent,
        'message' => $sent ? 'Email sent' : 'Failed to send email'
    ];
}

/**
 * Resolve admin/super-admin email recipients for operational alerts.
 */
function getAdminOrderNotificationRecipients() {
    global $db;

    $recipients = [];
    try {
        $statusFilter = '';
        if (function_exists('dbh_table_has_column') && dbh_table_has_column('users', 'status')) {
            $statusFilter = " AND status = 'active'";
        }

        $stmt = $db->prepare("
            SELECT email
            FROM users
            WHERE role IN ('admin', 'super_admin')
              AND email IS NOT NULL
              AND email <> ''
              {$statusFilter}
            ORDER BY id ASC
        ");

        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '' && validateEmail($email)) {
                    $recipients[$email] = true;
                }
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log('Admin recipient lookup failed: ' . $e->getMessage());
    }

    if (empty($recipients) && defined('ADMIN_EMAIL') && validateEmail(ADMIN_EMAIL)) {
        $recipients[ADMIN_EMAIL] = true;
    }

    return array_keys($recipients);
}

/**
 * Email admin recipients about a newly placed data order.
 */
function sendAdminDataOrderNotification(array $payload) {
    if (!function_exists('sendEmail')) {
        require_once __DIR__ . '/email.php';
    }

    $recipients = getAdminOrderNotificationRecipients();
    if (empty($recipients)) {
        return false;
    }

    $siteName = function_exists('getSiteName') ? getSiteName() : (defined('SITE_NAME') ? SITE_NAME : 'Constechzhub');
    $reference = trim((string) ($payload['order_reference'] ?? $payload['reference'] ?? 'N/A'));
    $orderId = (int) ($payload['order_id'] ?? 0);
    $userId = (int) ($payload['user_id'] ?? 0);
    $customerName = trim((string) ($payload['customer_name'] ?? $payload['buyer_name'] ?? ''));
    $customerEmail = trim((string) ($payload['customer_email'] ?? ''));
    $beneficiary = trim((string) ($payload['beneficiary_number'] ?? ''));
    $network = trim((string) ($payload['network_name'] ?? ''));
    $packageName = trim((string) ($payload['package_name'] ?? ''));
    $paymentMethod = strtoupper(trim((string) ($payload['payment_method'] ?? 'wallet')));
    $status = strtoupper(trim((string) ($payload['status'] ?? 'placed')));
    $source = trim((string) ($payload['source'] ?? 'system'));
    $agentId = (int) ($payload['agent_id'] ?? 0);
    $amount = (float) ($payload['amount'] ?? 0);
    $amountText = (defined('CURRENCY') ? CURRENCY : 'GHS ') . number_format($amount, 2);

    if ($customerName === '' && $userId > 0) {
        try {
            global $db;
            $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                if ($row = $stmt->get_result()->fetch_assoc()) {
                    if ($customerName === '') {
                        $customerName = trim((string) ($row['full_name'] ?? ''));
                    }
                    if ($customerEmail === '') {
                        $customerEmail = trim((string) ($row['email'] ?? ''));
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Data order notification user lookup failed: ' . $e->getMessage());
        }
    }

    $subject = "{$siteName} Admin Alert: Data Order {$reference}";
    $bodyHtml = "
        <p>Hello Admin,</p>
        <p>A new data order was placed.</p>
        <ul>
            <li><strong>Reference:</strong> " . htmlspecialchars($reference) . "</li>
            <li><strong>Order ID:</strong> " . ($orderId > 0 ? $orderId : 'N/A') . "</li>
            <li><strong>Customer:</strong> " . htmlspecialchars($customerName !== '' ? $customerName : 'N/A') . "</li>
            <li><strong>Customer Email:</strong> " . htmlspecialchars($customerEmail !== '' ? $customerEmail : 'N/A') . "</li>
            <li><strong>Beneficiary:</strong> " . htmlspecialchars($beneficiary !== '' ? $beneficiary : 'N/A') . "</li>
            <li><strong>Network:</strong> " . htmlspecialchars($network !== '' ? $network : 'N/A') . "</li>
            <li><strong>Package:</strong> " . htmlspecialchars($packageName !== '' ? $packageName : 'N/A') . "</li>
            <li><strong>Amount:</strong> " . htmlspecialchars($amountText) . "</li>
            <li><strong>Payment Method:</strong> " . htmlspecialchars($paymentMethod) . "</li>
            <li><strong>Status:</strong> " . htmlspecialchars($status) . "</li>
            <li><strong>Agent ID:</strong> " . ($agentId > 0 ? $agentId : 'N/A') . "</li>
            <li><strong>Source:</strong> " . htmlspecialchars($source) . "</li>
            <li><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</li>
        </ul>
        <p>{$siteName}</p>
    ";
    $bodyText = "Hello Admin,\n\nA new data order was placed.\n"
        . "Reference: {$reference}\n"
        . "Order ID: " . ($orderId > 0 ? $orderId : 'N/A') . "\n"
        . "Customer: " . ($customerName !== '' ? $customerName : 'N/A') . "\n"
        . "Customer Email: " . ($customerEmail !== '' ? $customerEmail : 'N/A') . "\n"
        . "Beneficiary: " . ($beneficiary !== '' ? $beneficiary : 'N/A') . "\n"
        . "Network: " . ($network !== '' ? $network : 'N/A') . "\n"
        . "Package: " . ($packageName !== '' ? $packageName : 'N/A') . "\n"
        . "Amount: {$amountText}\n"
        . "Payment Method: {$paymentMethod}\n"
        . "Status: {$status}\n"
        . "Agent ID: " . ($agentId > 0 ? $agentId : 'N/A') . "\n"
        . "Source: {$source}\n"
        . "Time: " . date('Y-m-d H:i:s') . "\n";

    $sentAny = false;
    foreach ($recipients as $toEmail) {
        try {
            $sent = sendEmail($toEmail, $subject, $bodyHtml, $bodyText, 'admin_data_order_alert');
            $sentAny = $sentAny || (bool) $sent;
        } catch (Exception $e) {
            error_log('Admin data order alert failed (' . $toEmail . '): ' . $e->getMessage());
        }
    }

    return $sentAny;
}

/**
 * Email admin recipients about a result checker purchase.
 */
function sendAdminResultCheckerOrderNotification(array $payload) {
    if (!function_exists('sendEmail')) {
        require_once __DIR__ . '/email.php';
    }

    $recipients = getAdminOrderNotificationRecipients();
    if (empty($recipients)) {
        return false;
    }

    $siteName = function_exists('getSiteName') ? getSiteName() : (defined('SITE_NAME') ? SITE_NAME : 'Constechzhub');
    $reference = trim((string) ($payload['reference'] ?? $payload['order_reference'] ?? 'N/A'));
    $userId = (int) ($payload['user_id'] ?? 0);
    $buyerName = trim((string) ($payload['buyer_name'] ?? $payload['customer_name'] ?? ''));
    $buyerEmail = trim((string) ($payload['buyer_email'] ?? $payload['customer_email'] ?? ''));
    $cardType = strtoupper(trim((string) ($payload['card_type'] ?? '')));
    $paymentMethod = strtoupper(trim((string) ($payload['payment_method'] ?? 'wallet')));
    $status = strtoupper(trim((string) ($payload['status'] ?? 'success')));
    $source = trim((string) ($payload['source'] ?? 'system'));
    $agentId = (int) ($payload['agent_id'] ?? 0);
    $amount = (float) ($payload['amount'] ?? 0);
    $adminPrice = (float) ($payload['admin_price'] ?? 0);
    $profitAmount = (float) ($payload['profit_amount'] ?? max(0, $amount - $adminPrice));
    $amountText = (defined('CURRENCY') ? CURRENCY : 'GHS ') . number_format($amount, 2);
    $adminPriceText = (defined('CURRENCY') ? CURRENCY : 'GHS ') . number_format($adminPrice, 2);
    $profitText = (defined('CURRENCY') ? CURRENCY : 'GHS ') . number_format($profitAmount, 2);

    if (($buyerName === '' || $buyerEmail === '') && $userId > 0) {
        try {
            global $db;
            $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                if ($row = $stmt->get_result()->fetch_assoc()) {
                    if ($buyerName === '') {
                        $buyerName = trim((string) ($row['full_name'] ?? ''));
                    }
                    if ($buyerEmail === '') {
                        $buyerEmail = trim((string) ($row['email'] ?? ''));
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Result checker admin alert user lookup failed: ' . $e->getMessage());
        }
    }

    $subject = "{$siteName} Admin Alert: Result Checker {$reference}";
    $bodyHtml = "
        <p>Hello Admin,</p>
        <p>A result checker purchase was completed.</p>
        <ul>
            <li><strong>Reference:</strong> " . htmlspecialchars($reference) . "</li>
            <li><strong>Buyer:</strong> " . htmlspecialchars($buyerName !== '' ? $buyerName : 'N/A') . "</li>
            <li><strong>Buyer Email:</strong> " . htmlspecialchars($buyerEmail !== '' ? $buyerEmail : 'N/A') . "</li>
            <li><strong>Card Type:</strong> " . htmlspecialchars($cardType !== '' ? $cardType : 'N/A') . "</li>
            <li><strong>Amount:</strong> " . htmlspecialchars($amountText) . "</li>
            <li><strong>Admin Price:</strong> " . htmlspecialchars($adminPriceText) . "</li>
            <li><strong>Profit:</strong> " . htmlspecialchars($profitText) . "</li>
            <li><strong>Payment Method:</strong> " . htmlspecialchars($paymentMethod) . "</li>
            <li><strong>Status:</strong> " . htmlspecialchars($status) . "</li>
            <li><strong>Agent ID:</strong> " . ($agentId > 0 ? $agentId : 'N/A') . "</li>
            <li><strong>Source:</strong> " . htmlspecialchars($source) . "</li>
            <li><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</li>
        </ul>
        <p>{$siteName}</p>
    ";
    $bodyText = "Hello Admin,\n\nA result checker purchase was completed.\n"
        . "Reference: {$reference}\n"
        . "Buyer: " . ($buyerName !== '' ? $buyerName : 'N/A') . "\n"
        . "Buyer Email: " . ($buyerEmail !== '' ? $buyerEmail : 'N/A') . "\n"
        . "Card Type: " . ($cardType !== '' ? $cardType : 'N/A') . "\n"
        . "Amount: {$amountText}\n"
        . "Admin Price: {$adminPriceText}\n"
        . "Profit: {$profitText}\n"
        . "Payment Method: {$paymentMethod}\n"
        . "Status: {$status}\n"
        . "Agent ID: " . ($agentId > 0 ? $agentId : 'N/A') . "\n"
        . "Source: {$source}\n"
        . "Time: " . date('Y-m-d H:i:s') . "\n";

    $sentAny = false;
    foreach ($recipients as $toEmail) {
        try {
            $sent = sendEmail($toEmail, $subject, $bodyHtml, $bodyText, 'admin_result_checker_alert');
            $sentAny = $sentAny || (bool) $sent;
        } catch (Exception $e) {
            error_log('Admin result checker alert failed (' . $toEmail . '): ' . $e->getMessage());
        }
    }

    return $sentAny;
}

/**
 * Generate unique reference
 */
function generateReference($prefix = 'DBH', $format = 'string') {
    if ($format === 'integer' || $prefix === 'HUBNET') {
        // For Hubnet API compatibility - use integer format like WordPress
        return rand(1000000000, 9999999999);
    }
    return $prefix . '_' . time() . '_' . rand(1000, 9999);
}

/**
 * Find a very recent bundle order with the same payload.
 * Helps prevent accidental duplicate submissions on flaky networks.
 */
function findRecentDuplicateBundleOrder($user_id, $package_id, $beneficiary_number, $amount, $lookback_seconds = 180) {
    global $db;

    $user_id = (int) $user_id;
    $package_id = (int) $package_id;
    $amount = (float) $amount;
    $lookback_seconds = (int) $lookback_seconds;

    if (!isset($db) || $user_id <= 0 || $package_id <= 0 || $amount <= 0) {
        return null;
    }

    $normalized_phone = formatPhone((string) $beneficiary_number);
    if ($normalized_phone === '') {
        return null;
    }

    if ($lookback_seconds < 30) {
        $lookback_seconds = 30;
    } elseif ($lookback_seconds > 900) {
        $lookback_seconds = 900;
    }

    $cutoff = date('Y-m-d H:i:s', time() - $lookback_seconds);
    $min_amount = $amount - 0.01;
    $max_amount = $amount + 0.01;

    try {
        $stmt = $db->prepare("
            SELECT id, order_reference, status, created_at
            FROM bundle_orders
            WHERE user_id = ?
              AND package_id = ?
              AND beneficiary_number = ?
              AND amount BETWEEN ? AND ?
              AND status IN ('pending', 'processing', 'delivered')
              AND created_at >= ?
            ORDER BY id DESC
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('iisdds', $user_id, $package_id, $normalized_phone, $min_amount, $max_amount, $cutoff);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row ?: null;
    } catch (Exception $e) {
        error_log('Duplicate bundle order lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Find a recent guest bundle payment transaction for the same payload.
 */
function findRecentGuestBundleTransaction($user_id, $package_id, $beneficiary_number, $amount, $lookback_seconds = 180) {
    global $db;

    $user_id = (int) $user_id;
    $package_id = (int) $package_id;
    $amount = (float) $amount;
    $lookback_seconds = (int) $lookback_seconds;

    if (!isset($db) || $user_id <= 0 || $package_id <= 0 || $amount <= 0) {
        return null;
    }

    if (function_exists('dbh_table_has_column') && !dbh_table_has_column('transactions', 'metadata')) {
        return null;
    }

    $normalized_phone = formatPhone((string) $beneficiary_number);
    if ($normalized_phone === '') {
        return null;
    }

    if ($lookback_seconds < 30) {
        $lookback_seconds = 30;
    } elseif ($lookback_seconds > 1800) {
        $lookback_seconds = 1800;
    }

    $cutoff = date('Y-m-d H:i:s', time() - $lookback_seconds);
    $min_amount = $amount - 0.01;
    $max_amount = $amount + 0.01;

    try {
        $stmt = $db->prepare("
            SELECT id, reference, status, metadata, created_at
            FROM transactions
            WHERE user_id = ?
              AND transaction_type = 'purchase'
              AND amount BETWEEN ? AND ?
              AND status IN ('pending', 'processing', 'success')
              AND created_at >= ?
            ORDER BY id DESC
            LIMIT 20
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('idds', $user_id, $min_amount, $max_amount, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            if (!is_array($metadata)) {
                continue;
            }

            if (($metadata['type'] ?? '') !== 'guest_bundle_purchase') {
                continue;
            }

            $meta_package_id = (int) ($metadata['package_id'] ?? 0);
            $meta_phone = formatPhone((string) ($metadata['beneficiary_number'] ?? ''));
            if ($meta_package_id === $package_id && $meta_phone === $normalized_phone) {
                return $row;
            }
        }
    } catch (Exception $e) {
        error_log('Guest duplicate transaction lookup failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Format currency
 */
function formatCurrency($amount, $currency = CURRENCY) {
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Resolve session idle timeout in seconds.
 * Defaults to 30 minutes when not configured.
 */
function getSessionIdleTimeoutSeconds() {
    if (defined('SESSION_IDLE_TIMEOUT')) {
        $value = (int) SESSION_IDLE_TIMEOUT;
        if ($value > 0) {
            return $value;
        }
    }

    if (defined('SESSION_TIMEOUT_MINUTES')) {
        $value = (int) SESSION_TIMEOUT_MINUTES;
        if ($value > 0) {
            return $value * 60;
        }
    }

    if (function_exists('dbh_env')) {
        $seconds = dbh_env('SESSION_IDLE_TIMEOUT', null);
        if ($seconds !== null && $seconds !== '') {
            $value = (int) $seconds;
            if ($value > 0) {
                return $value;
            }
        }

        $minutes = dbh_env('SESSION_TIMEOUT_MINUTES', null);
        if ($minutes !== null && $minutes !== '') {
            $value = (int) $minutes;
            if ($value > 0) {
                return $value * 60;
            }
        }
    }

    return 1800;
}

/**
 * Enforce idle session timeout.
 */
function enforceSessionTimeout() {
    if (!isLoggedIn()) {
        return;
    }

    $timeout = getSessionIdleTimeoutSeconds();
    if ($timeout <= 0) {
        return;
    }

    $now = time();
    $lastActivity = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : $now;

    if (($now - $lastActivity) > $timeout) {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            session_destroy();
        }

        header('Location: ' . SITE_URL . '/login.php?session=expired');
        exit();
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Check if user has specific role
 */
function normalizeUserRole($role) {
    $role = trim((string) $role);
    return $role === '' ? '' : strtolower($role);
}

function setSessionUserRole($role) {
    $_SESSION['user_role'] = normalizeUserRole($role);
}

function hasRole($role) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }

    return normalizeUserRole($_SESSION['user_role']) === normalizeUserRole($role);
}

function refreshSessionUserRole($expectedRole = null) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return null;
    }

    $currentRole = normalizeUserRole($_SESSION['user_role'] ?? '');
    $expectedRole = $expectedRole !== null ? normalizeUserRole($expectedRole) : null;

    if ($expectedRole !== null && $currentRole === $expectedRole) {
        return $currentRole;
    }

    if ($expectedRole === null && $currentRole !== '') {
        return $currentRole;
    }

    global $db;
    if (!isset($db)) {
        return $currentRole ?: null;
    }

    try {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return $currentRole ?: null;
        }
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $dbRole = normalizeUserRole($row['role'] ?? '');
            if ($dbRole !== '') {
                setSessionUserRole($dbRole);
                return $dbRole;
            }
        }
    } catch (Exception $e) {
        error_log('Session role refresh failed: ' . $e->getMessage());
    }

    return $currentRole ?: null;
}

/**
 * Redirect to login if not authenticated
 */
function requireLogin() {
    enforceSessionTimeout();
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
    if (function_exists('enforceEmailVerificationGate')) {
        enforceEmailVerificationGate();
    }
}

/**
 * Require specific role
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        refreshSessionUserRole($role);
        if (!hasRole($role)) {
            header('Location: ' . SITE_URL . '/unauthorized.php');
            exit();
        }
    }
}

/**
 * Require that the authenticated user has one of the permitted roles.
 */
function requireAnyRole(array $roles) {
    requireLogin();
    $normalizedRoles = array_map(function ($role) {
        return normalizeUserRole($role);
    }, $roles);

    $currentRole = normalizeUserRole($_SESSION['user_role'] ?? null);
    if ($currentRole === '' || !in_array($currentRole, $normalizedRoles, true)) {
        $currentRole = refreshSessionUserRole();
    }

    if ($currentRole === '' || !in_array($currentRole, $normalizedRoles, true)) {
        header('Location: ' . SITE_URL . '/unauthorized.php');
        exit();
    }
}

// getCurrentUser function is already defined in config/config.php

/**
 * Send wallet credit notifications (SMS + email) to a user.
 */
function sendWalletCreditNotification($user_id, $amount, $new_balance = null, $context = 'wallet top-up', $channel = 'wallet_topup') {
    global $db;

    // Lazy-load dependencies to avoid loading when not needed
    require_once __DIR__ . '/mnotify_sms.php';
    require_once __DIR__ . '/email.php';

    try {
        $phoneColumn = dbh_get_users_phone_column();
        $phoneSelect = 'NULL AS phone';
        if ($phoneColumn === 'mobile') {
            $phoneSelect = 'mobile AS phone';
        } elseif ($phoneColumn === 'phone') {
            $phoneSelect = 'phone AS phone';
        }

        $stmt = $db->prepare("SELECT {$phoneSelect}, full_name, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            error_log("Wallet credit notification lookup failed: " . ($db->getConnection()->error ?? 'unknown database error'));
            return;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) return;

        $balance = $new_balance !== null ? $new_balance : getWalletBalance($user_id);
        $amountStr = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format($amount, 2);
        $balanceStr = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format($balance, 2);

        // Make the channel explicit in the SMS so customers know how the top-up was applied
        $channelText = '';
        switch ($channel) {
            case 'paystack':
                $channelText = ' via Paystack';
                break;
            case 'moolre':
                $channelText = ' via Moolre';
                break;
            case 'manual_agent_topup':
                $channelText = ' by your agent';
                break;
            case 'manual_admin_topup':
                $channelText = ' manually';
                break;
            case 'wallet_topup':
            case '':
                $channelText = '';
                break;
            default:
                $channelText = $channel ? ' via ' . str_replace('_', ' ', $channel) : '';
                break;
        }

        $smsMessage = "Hi {$user['full_name']}, {$amountStr} has been loaded to your wallet{$channelText}. Total balance: {$balanceStr}. - " . SITE_NAME;
        $smsResult = sendSMS($user['phone'], $smsMessage, 'wallet_topup', $user_id);
        if (!$smsResult['success']) {
            error_log("SMS notification failed for wallet credit: " . ($smsResult['error'] ?? 'Unknown error'));
        }

        if (!empty($user['email'])) {
            $subject = 'Wallet Top-up Successful';
            $body_html = "<p>Hello {$user['full_name']},</p><p>Your wallet has been credited with <strong>{$amountStr}</strong>.</p><p>New balance: <strong>{$balanceStr}</strong>.</p><p>Reference: {$context}</p><p>Thank you,<br>" . SITE_NAME . "</p>";
            $body_text = "Hello {$user['full_name']},\n\nYour wallet has been credited with {$amountStr}.\nNew balance: {$balanceStr}.\nReference: {$context}\n\nThank you,\n" . SITE_NAME;
            sendEmail($user['email'], $subject, $body_html, $body_text, 'wallet_topup');
        }
    } catch (Exception $e) {
        error_log("Wallet credit notification error: " . $e->getMessage());
    }
}

/**
 * Send wallet debit notifications (SMS + email) to a user.
 */
function sendWalletDebitNotification($user_id, $amount, $new_balance = null, $context = 'wallet deduction') {
    global $db;

    require_once __DIR__ . '/mnotify_sms.php';
    require_once __DIR__ . '/email.php';

    try {
        $phoneColumn = dbh_get_users_phone_column();
        $phoneSelect = 'NULL AS phone';
        if ($phoneColumn === 'mobile') {
            $phoneSelect = 'mobile AS phone';
        } elseif ($phoneColumn === 'phone') {
            $phoneSelect = 'phone AS phone';
        }

        $stmt = $db->prepare("SELECT {$phoneSelect}, full_name, email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            error_log("Wallet debit notification lookup failed: " . ($db->getConnection()->error ?? 'unknown database error'));
            return;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) return;

        $balance = $new_balance !== null ? $new_balance : getWalletBalance($user_id);
        $amountStr = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format($amount, 2);
        $balanceStr = (defined('CURRENCY') ? CURRENCY . ' ' : '') . number_format($balance, 2);

        $smsMessage = "Hi {$user['full_name']}, your wallet has been debited by {$amountStr}. New balance: {$balanceStr}. - " . SITE_NAME;
        $smsResult = sendSMS($user['phone'], $smsMessage, 'wallet_debit', $user_id);
        if (!$smsResult['success']) {
            error_log("SMS notification failed for wallet debit: " . ($smsResult['error'] ?? 'Unknown error'));
        }

        if (!empty($user['email'])) {
            $subject = 'Wallet Deduction Notice';
            $body_html = "<p>Hello {$user['full_name']},</p><p>Your wallet has been debited by <strong>{$amountStr}</strong>.</p><p>New balance: <strong>{$balanceStr}</strong>.</p><p>Reference: {$context}</p><p>Thank you,<br>" . SITE_NAME . "</p>";
            $body_text = "Hello {$user['full_name']},\n\nYour wallet has been debited by {$amountStr}.\nNew balance: {$balanceStr}.\nReference: {$context}\n\nThank you,\n" . SITE_NAME;
            sendEmail($user['email'], $subject, $body_html, $body_text, 'wallet_debit');
        }
    } catch (Exception $e) {
        error_log("Wallet debit notification error: " . $e->getMessage());
    }
}

/**
 * Send welcome notifications (SMS + email) with credentials after registration.
 */
function sendRegistrationCredentialsNotification(array $payload, $userId = null) {
    require_once __DIR__ . '/mnotify_sms.php';
    require_once __DIR__ . '/email.php';

    $fullName = trim($payload['full_name'] ?? '');
    $email = $payload['email'] ?? '';
    $phone = $payload['phone'] ?? '';
    $username = $payload['username'] ?? '';
    $plainPassword = $payload['plain_password'] ?? '';
    $brand = $payload['brand'] ?? (function_exists('getSiteName') ? getSiteName() : (defined('SITE_NAME') ? SITE_NAME : 'Constechzhub'));

    // Prefer email as the login identifier; fall back to username if email is missing.
    $loginId = $email ?: $username;
    $passwordDisplay = $plainPassword ?: 'Use the password you set during signup';

    // SMS notification (only when SMS is enabled and a phone exists)
    if (!empty($phone) && dbh_is_sms_notifications_enabled()) {
        $smsMessage = ($fullName ? "Hi {$fullName}, " : "Hi, ") . "welcome to {$brand}.";
        if ($loginId) {
            $smsMessage .= " Login: {$loginId}.";
        } elseif ($username) {
            $smsMessage .= " Username: {$username}.";
        }
        $smsMessage .= " Password: {$passwordDisplay}. Keep this safe.";

        try {
            sendSMS($phone, $smsMessage, 'registration', $userId);
        } catch (Exception $e) {
            error_log('Registration SMS failed: ' . $e->getMessage());
        }
    }

    // Email notification
    if (!empty($email)) {
        $subject = "Welcome to {$brand}";
        $emailLineHtml = $email ? "<strong>Email:</strong> {$email}<br>" : '';
        $usernameLineHtml = $username ? "<strong>Username:</strong> {$username}<br>" : '';
        $body_html = "<p>Hello " . ($fullName ?: 'there') . ",</p>"
            . "<p>Your {$brand} account is ready.</p>"
            . "<p>{$emailLineHtml}{$usernameLineHtml}<strong>Password:</strong> {$passwordDisplay}</p>"
            . "<p>You can sign in at <a href=\"" . SITE_URL . "/login.php\">" . SITE_URL . "/login.php</a>.</p>"
            . "<p>Please keep these details safe.</p>"
            . "<p>Thanks,<br>{$brand}</p>";

        $emailLineText = $email ? "Email: {$email}\n" : '';
        $usernameLineText = $username ? "Username: {$username}\n" : '';
        $body_text = "Hello " . ($fullName ?: 'there') . ",\n\n"
            . "Your {$brand} account is ready.\n\n"
            . "{$emailLineText}{$usernameLineText}Password: {$passwordDisplay}\n\n"
            . "Sign in: " . SITE_URL . "/login.php\n\n"
            . "Please keep these details safe.\n\n"
            . "Thanks,\n{$brand}";

        try {
            sendEmail($email, $subject, $body_html, $body_text, 'welcome_signup');
        } catch (Exception $e) {
            error_log('Registration email failed: ' . $e->getMessage());
        }
    }
}

/**
 * Update wallet balance and send notification for credits.
 */
function updateWalletBalanceWithSMS($user_id, $amount, $type, $reference = '', $description = '', $channel = 'wallet_topup', &$error = null) {
    $result = updateWalletBalance($user_id, $amount, $type, $reference, $description, $error);
    
    if ($result && $type === 'credit') {
        $new_balance = getWalletBalance($user_id);
        $context = $description ?: ($reference ?: 'wallet top-up');
        sendWalletCreditNotification($user_id, $amount, $new_balance, $context, $channel);
    }
    
    return $result;
}

/**
 * Set flash message
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Check if flash message exists
 */
function hasFlashMessage() {
    return isset($_SESSION['flash_message']);
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Log activity
 */
function logActivity($user_id, $action, $details = '') {
    global $db;
    
    try {
        if (!dbh_ensure_auto_increment('activity_logs')) {
            error_log("Activity log skipped because AUTO_INCREMENT is missing on activity_logs.id");
            return;
        }
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Activity logging failed: " . ($db->getConnection()->error ?? 'unknown database error'));
            return;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bind_param("issss", $user_id, $action, $details, $ip, $user_agent);
        $stmt->execute();
    } catch (Exception $e) {
        // Log the error but don't break the application
        error_log("Activity logging failed: " . $e->getMessage());
        
        // If it's an AUTO_INCREMENT issue, try to fix the table structure
        if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
            error_log("Activity logs table may need AUTO_INCREMENT fix. Please run: database/fix_activity_logs.sql");
        }
    }
}

/**
 * Get active notifications for a specific user role
 */
function getActiveNotifications($user_role = 'all') {
    global $db;
    
    try {
        ensureNotificationsSchema();

        if (!dbh_table_exists('notifications')) {
            return [];
        }

        $current_time = date('Y-m-d H:i:s');

        $queryVariants = [
            // Newest schema (supports media + CTA fields)
            "
                SELECT id, title, message, notification_type, display_order, image_path, link_url, cta_text
                FROM notifications
                WHERE is_active = 1
                AND (target_audience = ? OR target_audience = 'all')
                AND (starts_at IS NULL OR starts_at <= ?)
                AND (expires_at IS NULL OR expires_at >= ?)
                ORDER BY display_order ASC, id DESC
            ",
            // Legacy schema without media/CTA fields
            "
                SELECT id, title, message, notification_type, display_order,
                       NULL AS image_path, NULL AS link_url, NULL AS cta_text
                FROM notifications
                WHERE is_active = 1
                AND (target_audience = ? OR target_audience = 'all')
                AND (starts_at IS NULL OR starts_at <= ?)
                AND (expires_at IS NULL OR expires_at >= ?)
                ORDER BY display_order ASC, id DESC
            ",
            // Very old schema fallback
            "
                SELECT id, title, message, notification_type, display_order,
                       NULL AS image_path, NULL AS link_url, NULL AS cta_text
                FROM notifications
                WHERE is_active = 1
                ORDER BY display_order ASC, id DESC
            ",
            // Minimal fallback: keep dashboards rendering even if optional columns are missing
            "
                SELECT id, title, message,
                       'info' AS notification_type, 0 AS display_order,
                       NULL AS image_path, NULL AS link_url, NULL AS cta_text
                FROM notifications
                ORDER BY id DESC
            ",
        ];

        foreach ($queryVariants as $index => $sql) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                continue;
            }

            $usesAudienceAndDateFilters = $index <= 1;
            if ($usesAudienceAndDateFilters) {
                $stmt->bind_param("sss", $user_role, $current_time, $current_time);
            }

            if (!$stmt->execute()) {
                $stmt->close();
                continue;
            }

            $result = $stmt->get_result();
            if (!$result) {
                $stmt->close();
                continue;
            }

            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }

            $stmt->close();
            return $notifications;
        }

        error_log("Error fetching notifications: notifications query variants failed. Last DB error: " . ($db->getConnection()->error ?? 'unknown database error'));
        return [];
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Render notification slides HTML for dashboard display
 */
function renderNotificationSlides($user_role = 'all') {
    $notifications = getActiveNotifications($user_role);
    
    if (empty($notifications)) {
        return '';
    }
    
    $html = '<div class="notification-slider mb-4" id="notificationSlider">';
    
    foreach ($notifications as $index => $notification) {
        $type_class = 'alert-' . $notification['notification_type'];
        $active_class = $index === 0 ? 'active' : '';
        
        $html .= '<div class="notification-slide ' . $active_class . '" data-slide="' . $index . '">';
        $image_url = '';
        if (!empty($notification['image_path'])) {
            if (preg_match('/^https?:\\/\\//i', $notification['image_path'])) {
                $image_url = $notification['image_path'];
            } else {
                $image_url = dbh_asset($notification['image_path']);
            }
        }
        $link_url = trim((string) ($notification['link_url'] ?? ''));
        $cta_text = trim((string) ($notification['cta_text'] ?? ''));
        if ($link_url !== '' && $cta_text === '') {
            $cta_text = 'Learn more';
        }

        $html .= '<div class="alert ' . $type_class . ' mb-0 d-flex align-items-center justify-content-between">';
        if ($image_url !== '') {
            $html .= '<div class="notification-media"><img src="' . htmlspecialchars($image_url) . '" alt="' . htmlspecialchars($notification['title']) . '"></div>';
        }
        $html .= '<div class="notification-content">';
        $html .= '<h6 class="alert-heading mb-1">' . htmlspecialchars($notification['title']) . '</h6>';
        $html .= '<p class="mb-0">' . htmlspecialchars($notification['message']) . '</p>';
        if ($link_url !== '') {
            $html .= '<a class="notification-cta btn btn-sm" href="' . htmlspecialchars($link_url) . '" target="_blank" rel="noopener">'
                . htmlspecialchars($cta_text) . '</a>';
        }
        $html .= '</div>';
        
        // Removed side arrow controls - notifications now auto-advance with dots for manual control
        
        $html .= '</div>';
        $html .= '</div>';
    }
    
    if (count($notifications) > 1) {
        $html .= '<div class="notification-indicators text-center mt-2">';
        for ($i = 0; $i < count($notifications); $i++) {
            $active_class = $i === 0 ? 'active' : '';
            $html .= '<span class="notification-dot ' . $active_class . '" onclick="goToNotification(' . $i . ')"></span>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Get notification count for a specific user role
 */
function getNotificationCount($user_role = 'all') {
    $notifications = getActiveNotifications($user_role);
    return count($notifications);
}

/**
 * Check if there are any active notifications for a user role
 */
function hasActiveNotifications($user_role = 'all') {
    return getNotificationCount($user_role) > 0;
}

/**
 * Get user wallet balance
 */
function getWalletBalance($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    if (!$stmt) {
        $dbError = $db->getConnection()->error ?? 'unknown database error';
        error_log('Wallet balance lookup failed: ' . $dbError);
        return 0.00;
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        $dbError = $db->getConnection()->error ?? 'unknown database error';
        error_log('Wallet balance query failed: ' . $dbError);
        return 0.00;
    }
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $balance = (string) $row['balance'];
        $balance = str_replace(',', '', $balance);
        $balance = preg_replace('/[^0-9\.\-]/', '', $balance);
        if ($balance === '' || $balance === '.' || $balance === '-' || $balance === '-.') {
            return 0.00;
        }
        return (float) $balance;
    }
    
    return 0.00;
}

/**
 * Update wallet balance
 */
function updateWalletBalance($user_id, $amount, $type = 'credit', $reference = '', $description = '', &$error = null) {
    global $db;
    
    $walletTxnAuto = dbh_ensure_auto_increment('wallet_transactions');
    $db->getConnection()->begin_transaction();
    
    try {
        // Get current balance
        $current_balance = getWalletBalance($user_id);
        
        // Calculate new balance
        if ($type === 'credit') {
            $new_balance = $current_balance + $amount;
        } else {
            // For debit operations, check balance first
            if ($current_balance < $amount) {
                throw new Exception("Insufficient wallet balance. Current: â‚µ" . number_format($current_balance, 2) . ", Required: â‚µ" . number_format($amount, 2));
            }
            $new_balance = $current_balance - $amount;
        }
        
        // Update wallet
        $stmt = $db->prepare("UPDATE wallets SET balance = ? WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet update prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        }
        $stmt->bind_param("di", $new_balance, $user_id);
        $stmt->execute();
        
        // Get wallet ID
        $stmt = $db->prepare("SELECT id FROM wallets WHERE user_id = ?");
        if (!$stmt) {
            throw new Exception('Wallet lookup prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $wallet_result = $stmt->get_result();
        $wallet = $wallet_result->fetch_assoc();
        if (!$wallet || empty($wallet['id'])) {
            // Auto-create wallet record if it is missing
            $stmt_create = $db->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, ?)");
            if (!$stmt_create) {
                throw new Exception('Wallet creation prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            }
            $stmt_create->bind_param("id", $user_id, $new_balance);
            $stmt_create->execute();
            $wallet_id = $db->lastInsertId();
            $stmt_create->close();
            $wallet = ['id' => $wallet_id];
        }
        
        // Record transaction
        if ($walletTxnAuto) {
            $stmt = $db->prepare("INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iisdddss", $user_id, $wallet['id'], $type, $amount, $current_balance, $new_balance, $reference, $description);
        } else {
            $manual_txn_id = dbh_generate_next_id('wallet_transactions');
            $stmt = $db->prepare("INSERT INTO wallet_transactions (id, user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Wallet transaction prepare failed: ' . ($db->getConnection()->error ?? 'unknown database error'));
            }
            $stmt->bind_param("iiisdddss", $manual_txn_id, $user_id, $wallet['id'], $type, $amount, $current_balance, $new_balance, $reference, $description);
        }
        $stmt->execute();
        
        // Update remaining_balance in transactions table if a matching reference exists and column exists
        if (!empty($reference) && dbh_table_has_column('transactions', 'remaining_balance')) {
            $stmt_tx_update = $db->prepare("UPDATE transactions SET remaining_balance = ? WHERE reference = ?");
            if ($stmt_tx_update) {
                $stmt_tx_update->bind_param("ds", $new_balance, $reference);
                $stmt_tx_update->execute();
                $stmt_tx_update->close();
            }
        }
        
        $db->getConnection()->commit();
        return true;
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        $error = $e->getMessage();
        error_log('Wallet update failed: ' . $error);
        return false;
    }
}

/**
 * Get summary stats for wallets to help admins understand reset impact.
 */
function getWalletResetStats() {
    global $db;

    $stats = [
        'total_wallets' => 0,
        'wallets_with_balance' => 0,
        'total_balance' => 0.0,
        'positive_balance' => 0.0,
        'negative_balance' => 0.0,
        'last_reset_at' => null,
        'last_reset_details' => null
    ];

    try {
        $result = $db->query("
            SELECT
                COUNT(*) AS total_wallets,
                SUM(CASE WHEN balance <> 0 THEN 1 ELSE 0 END) AS wallets_with_balance,
                COALESCE(SUM(balance), 0) AS total_balance,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS positive_balance,
                COALESCE(SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END), 0) AS negative_balance
            FROM wallets
        ");

        if ($result) {
            $row = $result->fetch_assoc();
            if ($row) {
                $stats['total_wallets'] = (int) ($row['total_wallets'] ?? 0);
                $stats['wallets_with_balance'] = (int) ($row['wallets_with_balance'] ?? 0);
                $stats['total_balance'] = (float) ($row['total_balance'] ?? 0);
                $stats['positive_balance'] = (float) ($row['positive_balance'] ?? 0);
                $stats['negative_balance'] = (float) ($row['negative_balance'] ?? 0);
            }
            $result->free();
        }

        $logStmt = $db->prepare("SELECT details, created_at FROM activity_logs WHERE action = 'wallet_reset' ORDER BY created_at DESC LIMIT 1");
        if ($logStmt) {
            $logStmt->execute();
            $logResult = $logStmt->get_result();
            if ($logResult && ($lastLog = $logResult->fetch_assoc())) {
                $stats['last_reset_at'] = $lastLog['created_at'];
                $stats['last_reset_details'] = $lastLog['details'];
            }
            if ($logResult) {
                $logResult->free();
            }
            $logStmt->close();
        }
    } catch (Exception $e) {
        error_log('Wallet stats error: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Reset all user wallet balances to zero with audit logs.
 */
function resetAllWalletBalances($performedBy = null, $reason = '') {
    global $db;

    $conn = $db->getConnection();
    $conn->begin_transaction();

    $summary = [
        'wallets_reset' => 0,
        'skipped_wallets' => 0,
        'total_debited' => 0.0,
        'total_credited' => 0.0
    ];

    $description = $reason ?: 'Wallet balance reset by administrator';

    try {
        $walletsResult = $conn->query("SELECT id, user_id, balance FROM wallets FOR UPDATE");
        if (!$walletsResult) {
            throw new Exception('Failed to fetch wallets: ' . $conn->error);
        }

        $updateStmt = $conn->prepare("UPDATE wallets SET balance = 0 WHERE id = ?");
        if (!$updateStmt) {
            throw new Exception('Failed to prepare wallet update statement: ' . $conn->error);
        }

        $transactionStmt = $conn->prepare("
            INSERT INTO wallet_transactions (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$transactionStmt) {
            throw new Exception('Failed to prepare wallet transaction statement: ' . $conn->error);
        }

        while ($wallet = $walletsResult->fetch_assoc()) {
            $walletId = (int) $wallet['id'];
            $userId = (int) $wallet['user_id'];
            $balanceBefore = isset($wallet['balance']) ? (float) $wallet['balance'] : 0.0;

            if (abs($balanceBefore) < 0.0001) {
                $summary['skipped_wallets']++;
                continue;
            }

            $transactionType = $balanceBefore >= 0 ? 'debit' : 'credit';
            $amount = abs($balanceBefore);
            $balanceAfter = 0.00;
            $reference = generateReference('WRESET');

            $updateStmt->bind_param("i", $walletId);
            $updateStmt->execute();

            $transactionStmt->bind_param(
                "iisdddss",
                $userId,
                $walletId,
                $transactionType,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $reference,
                $description
            );
            $transactionStmt->execute();

            if ($transactionType === 'debit') {
                $summary['total_debited'] += $amount;
            } else {
                $summary['total_credited'] += $amount;
            }

            $summary['wallets_reset']++;
        }

        $updateStmt->close();
        $transactionStmt->close();
        $walletsResult->free();

        $conn->commit();

        // Now that balances are committed, clear cached analytics and sales aggregates.
        purgeAnalyticsCache();
        resetSalesMetrics();

        if ($performedBy) {
            $logDetails = sprintf(
                'Wallet reset executed on %d wallets. Total debited: %s, total credited: %s. Reason: %s',
                $summary['wallets_reset'],
                formatCurrency($summary['total_debited']),
                formatCurrency($summary['total_credited']),
                $reason ?: 'not provided'
            );
            logActivity($performedBy, 'wallet_reset', $logDetails);
        }

        return array_merge(['success' => true], $summary);
    } catch (Exception $e) {
        try {
            $conn->rollback();
        } catch (Exception $rollbackError) {
            error_log('Wallet reset rollback error: ' . $rollbackError->getMessage());
        }
        error_log('Wallet reset error: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Clear analytics cache so dashboards recompute values like current balance/sales.
 */
function purgeAnalyticsCache() {
    global $db;

    try {
        $result = $db->query("TRUNCATE TABLE analytics_cache");
        if ($result === false) {
            // Some shared hosts block TRUNCATE, fall back to DELETE
            $db->query("DELETE FROM analytics_cache");
        }
    } catch (Exception $e) {
        error_log('Analytics cache purge failed: ' . $e->getMessage());
        try {
            $db->query("DELETE FROM analytics_cache");
        } catch (Exception $inner) {
            error_log('Analytics cache delete fallback failed: ' . $inner->getMessage());
        }
    }
}

/**
 * Reset sales-related aggregates so dashboard totals drop to zero.
 */
function resetSalesMetrics() {
    global $db;
    $tables = ['agent_profits', 'commissions', 'bundle_orders', 'transactions'];

    try {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            try {
                $result = $db->query("TRUNCATE TABLE `$table`");
                if ($result === false) {
                    $db->query("DELETE FROM `$table`");
                }
            } catch (Exception $e) {
                error_log("Failed to reset {$table}: " . $e->getMessage());
                try {
                    $db->query("DELETE FROM `$table`");
                } catch (Exception $inner) {
                    error_log("Fallback delete failed for {$table}: " . $inner->getMessage());
                }
            }
        }
    } finally {
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}

/**
 * Send JSON response
 */
function jsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Validate CSRF token
 */
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF token
 */
function generateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Reset all data bundle packages and related data
 * This function safely removes packages while respecting foreign key constraints
 */
function resetDataBundlePackages() {
    global $db;
    
    $db->getConnection()->begin_transaction();
    
    try {
        // Get statistics before reset
        $stats_query = "
            SELECT 
                COUNT(dp.id) as total_packages,
                COUNT(DISTINCT dp.network_id) as networks_affected,
                COUNT(pp.id) as pricing_records,
                COUNT(ppp.id) as profile_pricing_records,
                COUNT(acp.id) as custom_pricing_records,
                COUNT(bo.id) as bundle_orders,
                COUNT(c.id) as commissions
            FROM data_packages dp
            LEFT JOIN package_pricing pp ON pp.package_id = dp.id
            LEFT JOIN package_pricing_profiles ppp ON ppp.package_id = dp.id
            LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id
            LEFT JOIN bundle_orders bo ON bo.package_id = dp.id
            LEFT JOIN commissions c ON c.order_id = bo.id
        ";
        
        $stats_result = $db->query($stats_query);
        $stats = $stats_result->fetch_assoc();
        
        // Step 1: Delete commissions related to bundle orders (if any)
        $db->query("
            DELETE c FROM commissions c 
            INNER JOIN bundle_orders bo ON c.order_id = bo.id
        ");
        
        // Step 2: Delete bundle orders
        $db->query("DELETE FROM bundle_orders");
        
        // Step 3: Delete agent custom pricing
        $db->query("DELETE FROM agent_custom_pricing");
        
        // Step 4: Delete package pricing
        $db->query("DELETE FROM package_pricing");

        // Step 4b: Delete profile pricing
        if (dbh_table_exists('package_pricing_profiles')) {
            $db->query("DELETE FROM package_pricing_profiles");
        }
        
        // Step 5: Finally delete data packages
        $db->query("DELETE FROM data_packages");
        
        // Reset auto increment IDs to start fresh
        $db->query("ALTER TABLE data_packages AUTO_INCREMENT = 1");
        $db->query("ALTER TABLE package_pricing AUTO_INCREMENT = 1");
        if (dbh_table_exists('package_pricing_profiles')) {
            $db->query("ALTER TABLE package_pricing_profiles AUTO_INCREMENT = 1");
        }
        $db->query("ALTER TABLE agent_custom_pricing AUTO_INCREMENT = 1");
        $db->query("ALTER TABLE bundle_orders AUTO_INCREMENT = 1");
        $db->query("ALTER TABLE commissions AUTO_INCREMENT = 1");
        
        $db->getConnection()->commit();
        
        return [
            'success' => true,
            'stats' => $stats
        ];
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get counts for entities impacted by a full system reset.
 */
function getSystemResetStats() {
    global $db;

    $stats = [
        'data_packages' => 0,
        'package_pricing' => 0,
        'package_pricing_profiles' => 0,
        'agent_custom_pricing' => 0,
        'transactions' => 0,
        'wallet_transactions' => 0,
        'bundle_orders' => 0,
        'topup_requests' => 0,
        'topup_request_notifications' => 0,
        'sms_notifications' => 0,
        'commissions' => 0,
        'commission_liquidations' => 0,
        'commission_payouts' => 0,
        'agent_users' => 0,
        'customer_users' => 0,
        'agent_paystack_settings' => 0,
        'agent_payment_settings' => 0,
        'agent_api_keys' => 0,
        'agent_api_applications' => 0,
        'agent_api_usage_logs' => 0,
        'agent_api_rate_limits' => 0,
        'agent_profits' => 0,
        'agent_stores' => 0,
        'api_transaction_logs' => 0,
        'analytics_cache' => 0,
        'order_issue_reports' => 0,
    ];

    try {
        $tables = [
            'data_packages' => ['table' => 'data_packages'],
            'package_pricing' => ['table' => 'package_pricing'],
            'package_pricing_profiles' => ['table' => 'package_pricing_profiles'],
            'agent_custom_pricing' => ['table' => 'agent_custom_pricing'],
            'transactions' => ['table' => 'transactions'],
            'wallet_transactions' => ['table' => 'wallet_transactions'],
            'bundle_orders' => ['table' => 'bundle_orders'],
            'topup_requests' => ['table' => 'topup_requests'],
            'topup_request_notifications' => ['table' => 'topup_request_notifications'],
            'sms_notifications' => ['table' => 'sms_notifications'],
            'commissions' => ['table' => 'commissions'],
            'commission_liquidations' => ['table' => 'commission_liquidations'],
            'commission_payouts' => ['table' => 'commission_payouts'],
            'agent_users' => ['table' => 'users', 'where' => "role = 'agent'"],
            'customer_users' => ['table' => 'users', 'where' => "role = 'customer'"],
            'agent_paystack_settings' => ['table' => 'agent_paystack_settings'],
            'agent_payment_settings' => ['table' => 'agent_payment_settings'],
            'agent_api_keys' => ['table' => 'agent_api_keys'],
            'agent_api_applications' => ['table' => 'agent_api_applications'],
            'agent_api_usage_logs' => ['table' => 'agent_api_usage_logs'],
            'agent_api_rate_limits' => ['table' => 'agent_api_rate_limits'],
            'agent_profits' => ['table' => 'agent_profits'],
            'agent_stores' => ['table' => 'agent_stores'],
            'api_transaction_logs' => ['table' => 'api_transaction_logs'],
            'analytics_cache' => ['table' => 'analytics_cache'],
            'order_issue_reports' => ['table' => 'order_issue_reports'],
        ];

        foreach ($tables as $key => $meta) {
            $tableName = $meta['table'];
            if (!dbh_table_exists($tableName)) {
                $stats[$key] = 0;
                continue;
            }

            $sql = sprintf('SELECT COUNT(*) AS cnt FROM `%s`', $tableName);
            if (!empty($meta['where'])) {
                $sql .= ' WHERE ' . $meta['where'];
            }

            $result = $db->query($sql);
            if ($result) {
                $row = $result->fetch_assoc();
                if (isset($row['cnt'])) {
                    $stats[$key] = (int) $row['cnt'];
                }
                $result->free();
            }
        }
    } catch (Exception $e) {
        error_log('System reset stats error: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Completely reset system data (packages, agents, customers, transactions, histories).
 */
function resetSystemData($performedBy = null) {
    global $db;
    $conn = $db->getConnection();
    $stats = getSystemResetStats();

    $truncateTables = [
        'bundle_orders',
        'transactions',
        'wallet_transactions',
        'commissions',
        'commission_liquidations',
        'commission_payouts',
        'agent_custom_pricing',
        'agent_paystack_settings',
        'agent_payment_settings',
        'agent_api_keys',
        'agent_api_applications',
        'agent_api_usage_logs',
        'agent_api_rate_limits',
        'agent_profits',
        'agent_stores',
        'package_pricing',
        'package_pricing_profiles',
        'data_packages',
        'topup_requests',
        'topup_request_notifications',
        'sms_notifications',
        'order_issue_reports',
        'api_transaction_logs',
        'analytics_cache'
    ];

    $availableTables = [];
    foreach ($truncateTables as $table) {
        if (dbh_table_exists($table)) {
            $availableTables[] = $table;
        } else {
            error_log('System reset: skipping missing table ' . $table);
        }
    }
    $truncateTables = $availableTables;

    try {
        $conn->begin_transaction();
        $conn->query('SET FOREIGN_KEY_CHECKS=0');

        foreach ($truncateTables as $table) {
            if ($conn->query("TRUNCATE TABLE `$table`") === false) {
                throw new Exception("Failed to truncate {$table}: " . $conn->error);
            }
        }

        // Remove agent and customer accounts (cascades will clear dependent rows like wallets)
        if ($conn->query("DELETE FROM users WHERE role IN ('agent','customer')") === false) {
            throw new Exception('Failed to delete agent/customer accounts: ' . $conn->error);
        }

        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->commit();

        if ($performedBy) {
            $summary = sprintf(
                'System reset executed (packages: %d, agents: %d, customers: %d, transactions: %d, orders: %d).',
                $stats['data_packages'],
                $stats['agent_users'],
                $stats['customer_users'],
                $stats['transactions'],
                $stats['bundle_orders']
            );
            logActivity($performedBy, 'system_reset', $summary);
        }

        return [
            'success' => true,
            'stats' => $stats
        ];
    } catch (Exception $e) {
        try {
            $conn->rollback();
        } catch (Exception $rollbackError) {
            error_log('System reset rollback error: ' . $rollbackError->getMessage());
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get data packages statistics
 */
function getDataPackagesStats() {
    global $db;
    
    try {
        $stats_query = "
            SELECT 
                COUNT(dp.id) as total_packages,
                COUNT(DISTINCT dp.network_id) as networks_with_packages,
                COUNT(pp.id) as pricing_records,
                COUNT(ppp.id) as profile_pricing_records,
                COUNT(acp.id) as custom_pricing_records,
                COUNT(bo.id) as bundle_orders,
                COUNT(c.id) as commissions
            FROM data_packages dp
            LEFT JOIN package_pricing pp ON pp.package_id = dp.id
            LEFT JOIN package_pricing_profiles ppp ON ppp.package_id = dp.id
            LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id
            LEFT JOIN bundle_orders bo ON bo.package_id = dp.id
            LEFT JOIN commissions c ON c.order_id = bo.id
        ";
        
        $stats_result = $db->query($stats_query);
        return $stats_result->fetch_assoc();
        
    } catch (Exception $e) {
        error_log("Stats query error: " . $e->getMessage());
        return null;
    }
}

/**
 * Time ago function
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

/**
 * Determine if the sms_settings table uses the key/value schema.
 */
function smsSettingsUsesKeyValueSchema() {
    global $db;

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $res = $db->getConnection()->query("SHOW COLUMNS FROM sms_settings LIKE 'setting_key'");
        $cached = $res && $res->num_rows > 0;
    } catch (Exception $e) {
        $cached = false;
    }

    return $cached;
}

/**
 * Get a SMS setting regardless of the underlying schema shape.
 */
function getSMSSetting($key, $default = '') {
    global $db;

    try {
        if (smsSettingsUsesKeyValueSchema()) {
            $stmt = $db->prepare("SELECT setting_value FROM sms_settings WHERE setting_key = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    return $row['setting_value'];
                }
            }
        } else {
            $result = $db->query("SELECT provider, api_key, sender_id, is_active FROM sms_settings ORDER BY id DESC LIMIT 1");
            if ($result && $row = $result->fetch_assoc()) {
                $map = [
                    'mnotify_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                    'kivalo_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                    'mnotify_api_key' => $row['api_key'] ?? '',
                    'kivalo_api_key' => $row['api_key'] ?? '',
                    'mnotify_sender_id' => $row['sender_id'] ?? '',
                    'kivalo_sender_id' => $row['sender_id'] ?? '',
                    'sms_notifications_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                    'sms_otp_enabled' => ($row['is_active'] ?? 0) ? '1' : '0',
                ];
                if (array_key_exists($key, $map)) {
                    return $map[$key];
                }
            }
        }
    } catch (Exception $e) {
        error_log('SMS Setting Error: ' . $e->getMessage());
    }

    return $default;
}

/**
 * Check if SMS notifications are fully configured and enabled.
 */
function isSMSFeatureEnabled() {
    $providerEnabled = getSMSSetting('mnotify_enabled', getSMSSetting('kivalo_enabled', '0')) === '1';
    $notificationsEnabled = getSMSSetting('sms_notifications_enabled', '0') === '1';
    return $providerEnabled && $notificationsEnabled;
}

/**
 * Ensure helper tables for SMS management are present.
 */
function ensureSmsSupportTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $broadcastSql = "
        CREATE TABLE IF NOT EXISTS `sms_broadcasts` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `owner_id` INT NOT NULL,
            `owner_role` ENUM('admin','agent','system') NOT NULL DEFAULT 'admin',
            `title` VARCHAR(190) DEFAULT NULL,
            `message` TEXT NOT NULL,
            `target_audience` VARCHAR(50) NOT NULL DEFAULT 'all',
            `total_recipients` INT NOT NULL DEFAULT 0,
            `successful_recipients` INT NOT NULL DEFAULT 0,
            `failed_recipients` INT NOT NULL DEFAULT 0,
            `status` ENUM('pending','completed','failed','partial') NOT NULL DEFAULT 'pending',
            `meta_json` TEXT,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_owner_role` (`owner_role`),
            KEY `idx_owner_id` (`owner_id`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_sms_broadcasts_owner` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $agentSettingsSql = "
        CREATE TABLE IF NOT EXISTS `agent_sms_settings` (
            `agent_id` INT NOT NULL,
            `sender_label` VARCHAR(11) DEFAULT NULL,
            `default_signature` VARCHAR(80) DEFAULT NULL,
            `default_message` TEXT DEFAULT NULL,
            `include_customer_name` TINYINT(1) NOT NULL DEFAULT 1,
            `mnotify_api_key` TEXT DEFAULT NULL,
            `mnotify_sender_id` VARCHAR(20) DEFAULT NULL,
            `mnotify_is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`agent_id`),
            CONSTRAINT `fk_agent_sms_settings_agent` FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($broadcastSql);
        $db->query($agentSettingsSql);
    } catch (Exception $e) {
        error_log('SMS helper table creation failed: ' . $e->getMessage());
    }

    $agentColumnMigrations = [
        "ALTER TABLE `agent_sms_settings` ADD COLUMN IF NOT EXISTS `mnotify_api_key` TEXT DEFAULT NULL",
        "ALTER TABLE `agent_sms_settings` ADD COLUMN IF NOT EXISTS `mnotify_sender_id` VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE `agent_sms_settings` ADD COLUMN IF NOT EXISTS `mnotify_is_active` TINYINT(1) NOT NULL DEFAULT 0",
    ];

    foreach ($agentColumnMigrations as $migration) {
        try {
            $db->query($migration);
        } catch (Exception $e) {
            // Ignore failures (likely column already exists or insufficient privileges)
        }
    }

    $ensured = true;
}

/**
 * Ensure helper tables for Email broadcasts are present.
 */
function ensureEmailBroadcastTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $broadcastSql = "
        CREATE TABLE IF NOT EXISTS `email_broadcasts` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `owner_id` INT NOT NULL,
            `owner_role` ENUM('admin','agent','system') NOT NULL DEFAULT 'admin',
            `subject` VARCHAR(190) NOT NULL,
            `message` TEXT NOT NULL,
            `allow_html` TINYINT(1) NOT NULL DEFAULT 0,
            `target_audience` VARCHAR(50) NOT NULL DEFAULT 'all',
            `total_recipients` INT NOT NULL DEFAULT 0,
            `successful_recipients` INT NOT NULL DEFAULT 0,
            `failed_recipients` INT NOT NULL DEFAULT 0,
            `status` ENUM('scheduled','pending','processing','completed','failed','partial') NOT NULL DEFAULT 'pending',
            `scheduled_at` DATETIME DEFAULT NULL,
            `processed_at` DATETIME DEFAULT NULL,
            `meta_json` TEXT,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_owner_role` (`owner_role`),
            KEY `idx_owner_id` (`owner_id`),
            KEY `idx_status` (`status`),
            KEY `idx_scheduled_at` (`scheduled_at`),
            CONSTRAINT `fk_email_broadcasts_owner` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($broadcastSql);
    } catch (Exception $e) {
        error_log('Email broadcast table creation failed: ' . $e->getMessage());
    }

    $jobsSql = "
        CREATE TABLE IF NOT EXISTS `email_broadcast_jobs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `broadcast_id` INT NOT NULL,
            `user_id` INT DEFAULT NULL,
            `recipient_email` VARCHAR(190) NOT NULL,
            `recipient_name` VARCHAR(190) DEFAULT NULL,
            `recipient_role` VARCHAR(50) DEFAULT NULL,
            `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `attempts` INT NOT NULL DEFAULT 0,
            `last_error` TEXT DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_broadcast_id` (`broadcast_id`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_email_broadcast_jobs_broadcast`
                FOREIGN KEY (`broadcast_id`) REFERENCES `email_broadcasts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($jobsSql);
    } catch (Exception $e) {
        error_log('Email broadcast jobs table creation failed: ' . $e->getMessage());
    }

    $migrations = [
        "ALTER TABLE `email_broadcasts` ADD COLUMN IF NOT EXISTS `allow_html` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `email_broadcasts` ADD COLUMN IF NOT EXISTS `scheduled_at` DATETIME DEFAULT NULL",
        "ALTER TABLE `email_broadcasts` ADD COLUMN IF NOT EXISTS `processed_at` DATETIME DEFAULT NULL",
        "ALTER TABLE `email_broadcasts` MODIFY `status` ENUM('scheduled','pending','processing','completed','failed','partial') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE `email_broadcasts` ADD INDEX `idx_scheduled_at` (`scheduled_at`)"
    ];

    foreach ($migrations as $migration) {
        try {
            $db->query($migration);
        } catch (Exception $e) {
            // Ignore failures (likely already applied or insufficient privileges)
        }
    }

    $ensured = true;
}

/**
 * Ensure agent payment settings table exists before usage.
 */
function ensureAgentPaymentSettingsTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $sql = "
        CREATE TABLE IF NOT EXISTS `agent_payment_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `agent_id` INT NOT NULL,
            `allow_paystack` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_topup_request` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_agent_payment_settings_agent_id` (`agent_id`),
            CONSTRAINT `fk_agent_payment_settings_agent`
                FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Agent payment settings table creation failed: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * Ensure the topup_settings table exists so payment accounts can be managed from the UI.
 * Also seed sensible admin defaults when table is empty.
 */
function ensureTopupSettingsTable() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $sql = "
        CREATE TABLE IF NOT EXISTS `topup_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT DEFAULT NULL COMMENT 'Agent ID, NULL for admin settings',
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_setting` (`user_id`, `setting_key`),
            CONSTRAINT `fk_topup_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Topup settings table creation failed: ' . $e->getMessage());
        return;
    }

    // Seed default admin payment instructions if nothing exists yet
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Data Bundle Hub';
    $defaults = [
        'admin_topup_account_network' => 'MTN MOMO',
        'admin_topup_account_name' => $siteName . ' Admin',
        'admin_topup_account_number' => '0245152060',
        'admin_topup_instructions' => 'Please send payment to the account details above and submit the topup request form with your payment details.',
    ];

    foreach ($defaults as $key => $value) {
        try {
            $stmt = $db->prepare("SELECT id FROM topup_settings WHERE user_id IS NULL AND setting_key = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            if ($exists) {
                continue;
            }

            $insert = $db->prepare("INSERT INTO topup_settings (user_id, setting_key, setting_value) VALUES (NULL, ?, ?)");
            if ($insert) {
                $insert->bind_param('ss', $key, $value);
                $insert->execute();
            }
        } catch (Exception $e) {
            error_log('Topup settings seed failed for ' . $key . ': ' . $e->getMessage());
        }
    }

    $ensured = true;
}

/**
 * Ensure the core topup request tables exist (requests and notifications).
 * This prevents runtime SQL errors on fresh installs where migrations haven't run.
 */
function ensureTopupRequestTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $requestsSql = "
        CREATE TABLE IF NOT EXISTS `topup_requests` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `request_id` VARCHAR(32) NOT NULL,
            `requester_id` INT NOT NULL,
            `requester_type` ENUM('customer','agent','admin') NOT NULL DEFAULT 'customer',
            `target_type` ENUM('admin','agent') NOT NULL DEFAULT 'admin',
            `target_agent_id` INT DEFAULT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `user_email` VARCHAR(190) NOT NULL,
            `network` VARCHAR(50) DEFAULT NULL,
            `wallet_name` VARCHAR(190) DEFAULT NULL,
            `wallet_number` VARCHAR(100) DEFAULT NULL,
            `payment_reference` VARCHAR(100) DEFAULT NULL,
            `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            `admin_notes` TEXT,
            `processed_by` INT DEFAULT NULL,
            `processed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `sms_notification_sent` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_request_id` (`request_id`),
            KEY `idx_requester_id` (`requester_id`),
            KEY `idx_target_agent` (`target_agent_id`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_topup_requests_requester` FOREIGN KEY (`requester_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_topup_requests_target_agent` FOREIGN KEY (`target_agent_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_topup_requests_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $notificationsSql = "
        CREATE TABLE IF NOT EXISTS `topup_request_notifications` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `request_id` VARCHAR(32) NOT NULL,
            `notification_type` ENUM('email','sms') NOT NULL DEFAULT 'email',
            `recipient_email` VARCHAR(190) DEFAULT NULL,
            `recipient_phone` VARCHAR(30) DEFAULT NULL,
            `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            `error_message` TEXT,
            `sms_sent` TINYINT(1) NOT NULL DEFAULT 0,
            `sms_message_id` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notification_request` (`request_id`),
            CONSTRAINT `fk_topup_notification_request` FOREIGN KEY (`request_id`) REFERENCES `topup_requests`(`request_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($requestsSql);
        $db->query($notificationsSql);
    } catch (Exception $e) {
        error_log('Topup request tables creation failed: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * Ensure result checker tables exist for card inventory, settings, and purchases.
 */
function ensureResultCheckerTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $settingsSql = "
        CREATE TABLE IF NOT EXISTS `result_checker_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `bece_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `wassce_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `bece_checker_link` TEXT DEFAULT NULL,
            `wassce_checker_link` TEXT DEFAULT NULL,
            `bece_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `wassce_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $cardsSql = "
        CREATE TABLE IF NOT EXISTS `result_checker_cards` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `card_type` ENUM('BECE','WASSCE') NOT NULL,
            `pin` VARCHAR(50) NOT NULL,
            `serial_number` VARCHAR(50) NOT NULL,
            `status` ENUM('available','purchased','disabled') NOT NULL DEFAULT 'available',
            `purchased_by` INT DEFAULT NULL,
            `purchased_at` TIMESTAMP NULL DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_result_checker_card` (`card_type`, `pin`, `serial_number`),
            KEY `idx_result_checker_card_type` (`card_type`),
            KEY `idx_result_checker_status` (`status`),
            CONSTRAINT `fk_result_checker_cards_purchased_by` FOREIGN KEY (`purchased_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_result_checker_cards_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $purchasesSql = "
        CREATE TABLE IF NOT EXISTS `result_checker_purchases` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `card_id` INT DEFAULT NULL,
            `agent_id` INT DEFAULT NULL,
            `card_type` ENUM('BECE','WASSCE') NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `admin_price` DECIMAL(10,2) DEFAULT NULL,
            `profit_amount` DECIMAL(10,2) DEFAULT NULL,
            `payment_gateway` VARCHAR(50) DEFAULT NULL,
            `reference` VARCHAR(100) NOT NULL,
            `status` ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'success',
            `pin` VARCHAR(50) DEFAULT NULL,
            `serial_number` VARCHAR(50) DEFAULT NULL,
            `sms_phone` VARCHAR(20) DEFAULT NULL,
            `notification_email` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_result_checker_reference` (`reference`),
            KEY `idx_result_checker_purchase_user` (`user_id`),
            KEY `idx_result_checker_purchase_card` (`card_id`),
            CONSTRAINT `fk_result_checker_purchase_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_result_checker_purchase_card` FOREIGN KEY (`card_id`) REFERENCES `result_checker_cards`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $agentPricingSql = "
        CREATE TABLE IF NOT EXISTS `agent_result_checker_pricing` (
            `agent_id` INT NOT NULL,
            `card_type` ENUM('BECE','WASSCE') NOT NULL,
            `custom_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`agent_id`, `card_type`),
            CONSTRAINT `fk_agent_result_checker_pricing_agent`
                FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($settingsSql);
        $db->query($cardsSql);
        $db->query($purchasesSql);
        $db->query($agentPricingSql);
    } catch (Exception $e) {
        error_log('Result checker table creation failed: ' . $e->getMessage());
    }

    $purchaseColumnMigrations = [
        'agent_id' => "ALTER TABLE `result_checker_purchases` ADD COLUMN `agent_id` INT DEFAULT NULL",
        'sms_phone' => "ALTER TABLE `result_checker_purchases` ADD COLUMN `sms_phone` VARCHAR(20) DEFAULT NULL",
        'admin_price' => "ALTER TABLE `result_checker_purchases` ADD COLUMN `admin_price` DECIMAL(10,2) DEFAULT NULL",
        'profit_amount' => "ALTER TABLE `result_checker_purchases` ADD COLUMN `profit_amount` DECIMAL(10,2) DEFAULT NULL",
        'notification_email' => "ALTER TABLE `result_checker_purchases` ADD COLUMN `notification_email` VARCHAR(255) DEFAULT NULL",
    ];

    if (function_exists('dbh_table_exists') && function_exists('dbh_table_has_column') && dbh_table_exists('result_checker_purchases')) {
        foreach ($purchaseColumnMigrations as $column => $migration) {
            try {
                if (!dbh_table_has_column('result_checker_purchases', $column)) {
                    $db->query($migration);
                }
            } catch (Exception $e) {
                // Ignore failures (likely insufficient privileges)
            }
        }
    }

    $pricingColumnMigrations = [
        'agent_id' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `agent_id` INT NOT NULL",
        'card_type' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `card_type` ENUM('BECE','WASSCE') NOT NULL",
        'custom_price' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `custom_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'is_active' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1",
        'created_at' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE `agent_result_checker_pricing` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];

    if (function_exists('dbh_table_exists') && function_exists('dbh_table_has_column') && dbh_table_exists('agent_result_checker_pricing')) {
        foreach ($pricingColumnMigrations as $column => $migration) {
            try {
                if (!dbh_table_has_column('agent_result_checker_pricing', $column)) {
                    $db->query($migration);
                }
            } catch (Exception $e) {
                // Ignore failures (likely insufficient privileges)
            }
        }
    }

    try {
        $result = $db->query("SELECT id FROM result_checker_settings LIMIT 1");
        if ($result && $result->num_rows === 0) {
            $defaultBecePrice = 17.00;
            $defaultWasscePrice = 17.00;
            $defaultBeceLink = '';
            $defaultWassceLink = 'https://ghana.waecdirect.org/';
            $insert = $db->prepare("
                INSERT INTO result_checker_settings
                    (bece_price, wassce_price, bece_checker_link, wassce_checker_link, bece_enabled, wassce_enabled)
                VALUES (?, ?, ?, ?, 0, 0)
            ");
            if ($insert) {
                $insert->bind_param('ddss', $defaultBecePrice, $defaultWasscePrice, $defaultBeceLink, $defaultWassceLink);
                $insert->execute();
            }
        }
    } catch (Exception $e) {
        error_log('Result checker default settings seed failed: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * Default profit withdrawal processing fee schedule (tiered).
 */
function defaultProfitWithdrawalFeeSchedule() {
    return [
        ['min' => 0.00, 'max' => 49.99, 'fee' => 1.00, 'label' => '<50'],
        ['min' => 50.00, 'max' => 99.99, 'fee' => 1.50, 'label' => '50-99.99'],
        ['min' => 100.00, 'max' => 199.99, 'fee' => 4.00, 'label' => '100-199.99'],
        ['min' => 200.00, 'max' => 299.99, 'fee' => 8.00, 'label' => '200-299.99'],
        ['min' => 300.00, 'max' => 399.99, 'fee' => 12.00, 'label' => '300-399.99'],
        ['min' => 400.00, 'max' => null,  'fee' => 16.00, 'label' => '400+'],
    ];
}

/**
 * Parse a fee schedule text (one rule per line) into structured bands.
 * Supported formats: "<50=1", "50-99.99=1.5", "100+=4"
 */
function parseProfitWithdrawalFeeScheduleText($text, &$error = null) {
    $error = null;
    $text = trim((string) $text);
    if ($text === '') {
        return defaultProfitWithdrawalFeeSchedule();
    }

    $lines = preg_split('/\r?\n/', $text);
    $schedule = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            $error = 'Invalid fee rule (missing "="): ' . $line;
            return null;
        }

        $range = trim($parts[0]);
        $fee = (float) trim($parts[1]);
        if ($fee <= 0) {
            $error = 'Fee must be greater than 0: ' . $line;
            return null;
        }

        $min = 0.0;
        $max = null;
        $label = $range;

        if (strpos($range, '<') === 0) {
            $value = (float) trim(substr($range, 1));
            if ($value <= 0) {
                $error = 'Invalid "<" range value: ' . $line;
                return null;
            }
            $min = 0.0;
            $max = round($value - 0.01, 2);
            if ($max < 0) {
                $max = 0.0;
            }
        } elseif (strpos($range, '+') !== false) {
            $min = (float) trim(str_replace('+', '', $range));
            if ($min < 0) {
                $error = 'Invalid "+" range value: ' . $line;
                return null;
            }
            $max = null;
        } elseif (strpos($range, '-') !== false) {
            $rangeParts = array_map('trim', explode('-', $range, 2));
            if (count($rangeParts) !== 2) {
                $error = 'Invalid "-" range format: ' . $line;
                return null;
            }
            $min = (float) $rangeParts[0];
            $max = (float) $rangeParts[1];
            if ($max < $min) {
                $error = 'Range max cannot be less than min: ' . $line;
                return null;
            }
        } else {
            $error = 'Unsupported range format: ' . $line;
            return null;
        }

        $schedule[] = [
            'min' => round($min, 2),
            'max' => $max === null ? null : round($max, 2),
            'fee' => round($fee, 2),
            'label' => $label,
        ];
    }

    if (empty($schedule)) {
        $error = 'Fee schedule cannot be empty.';
        return null;
    }

    usort($schedule, function ($a, $b) {
        return $a['min'] <=> $b['min'];
    });

    $prevMax = null;
    $totalBands = count($schedule);
    foreach ($schedule as $index => $band) {
        if ($prevMax !== null && $band['min'] <= $prevMax) {
            $error = 'Fee ranges overlap or are not in order.';
            return null;
        }
        if ($band['max'] === null && $index < ($totalBands - 1)) {
            $error = 'Open-ended range must be the last rule.';
            return null;
        }
        $prevMax = $band['max'];
    }

    return $schedule;
}

function getProfitWithdrawalFeeSchedule() {
    $raw = getSetting('profit_withdrawal_fee_schedule', '');
    $error = null;
    $schedule = parseProfitWithdrawalFeeScheduleText($raw, $error);
    if (!$schedule || $error) {
        return defaultProfitWithdrawalFeeSchedule();
    }
    return $schedule;
}

function formatProfitWithdrawalFeeScheduleText($schedule) {
    if (!is_array($schedule)) {
        $schedule = defaultProfitWithdrawalFeeSchedule();
    }
    $lines = [];
    foreach ($schedule as $band) {
        $label = $band['label'] ?? '';
        if ($label === '') {
            if (($band['max'] ?? null) === null) {
                $label = number_format((float) $band['min'], 2, '.', '') . '+';
            } elseif ((float) $band['min'] <= 0) {
                $label = '<' . number_format((float) $band['max'] + 0.01, 2, '.', '');
            } else {
                $label = number_format((float) $band['min'], 2, '.', '') . '-' . number_format((float) $band['max'], 2, '.', '');
            }
        }
        $lines[] = $label . '=' . number_format((float) $band['fee'], 2, '.', '');
    }
    return implode("\n", $lines);
}

function formatProfitWithdrawalFeeScheduleLabel($schedule) {
    if (!is_array($schedule)) {
        $schedule = defaultProfitWithdrawalFeeSchedule();
    }
    $parts = [];
    foreach ($schedule as $band) {
        $label = $band['label'] ?? '';
        if ($label === '') {
            if (($band['max'] ?? null) === null) {
                $label = number_format((float) $band['min'], 2, '.', '') . '+';
            } elseif ((float) $band['min'] <= 0) {
                $label = '<' . number_format((float) $band['max'] + 0.01, 2, '.', '');
            } else {
                $label = number_format((float) $band['min'], 2, '.', '') . '-' . number_format((float) $band['max'], 2, '.', '');
            }
        }
        $parts[] = $label . ' = ' . CURRENCY . number_format((float) $band['fee'], 2);
    }
    return 'MoMo fee: ' . implode(', ', $parts) . '. MoMo payout = Amount - Fee.';
}

/**
 * Calculate profit withdrawal processing fee (tiered schedule).
 * Note: payout = amount - fee (wallet is debited by the full amount).
 */
function calculateProfitWithdrawalFee($amount, $schedule = null) {
    $amount = (float) $amount;
    if ($amount <= 0) {
        return 0.0;
    }

    if ($schedule === null) {
        $schedule = getProfitWithdrawalFeeSchedule();
    }

    foreach ($schedule as $band) {
        $min = (float) ($band['min'] ?? 0);
        $max = $band['max'] ?? null;
        $fee = (float) ($band['fee'] ?? 0);

        if ($amount >= $min && ($max === null || $amount <= (float) $max)) {
            return round($fee, 2);
        }
    }

    return 0.0;
}

function calculateProfitWithdrawalTotalDebit($amount) {
    $amount = (float) $amount;
    if ($amount <= 0) {
        return 0.0;
    }
    return round($amount, 2);
}

function calculateProfitWithdrawalMaxAmount($limit) {
    $limit = (float) $limit;
    if ($limit <= 0) {
        return 0.0;
    }
    return floor($limit * 100) / 100;
}

function ensureProfitWithdrawalTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $sql = "
        CREATE TABLE IF NOT EXISTS `profit_withdrawals` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `agent_id` INT NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `fee_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_debit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payout_method` ENUM('wallet','momo') NOT NULL DEFAULT 'momo',
            `payout_network` VARCHAR(50) DEFAULT NULL,
            `payout_name` VARCHAR(190) DEFAULT NULL,
            `payout_number` VARCHAR(100) DEFAULT NULL,
            `status` ENUM('pending','approved','rejected','paid') NOT NULL DEFAULT 'pending',
            `reference` VARCHAR(60) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `admin_notes` TEXT DEFAULT NULL,
            `processed_by` INT DEFAULT NULL,
            `processed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_profit_withdrawals_agent` (`agent_id`),
            KEY `idx_profit_withdrawals_status` (`status`),
            CONSTRAINT `fk_profit_withdrawals_agent`
                FOREIGN KEY (`agent_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_profit_withdrawals_processed_by`
                FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Profit withdrawals table creation failed: ' . $e->getMessage());
    }

    $withdrawalMigrations = [
        "ALTER TABLE `profit_withdrawals` ADD COLUMN IF NOT EXISTS `payout_method` ENUM('wallet','momo') NOT NULL DEFAULT 'momo'",
        "ALTER TABLE `profit_withdrawals` ADD COLUMN IF NOT EXISTS `fee_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE `profit_withdrawals` ADD COLUMN IF NOT EXISTS `total_debit` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    ];

    foreach ($withdrawalMigrations as $migration) {
        try {
            $db->query($migration);
        } catch (Exception $e) {
            // Ignore failures (likely column already exists or insufficient privileges)
        }
    }

    $ensured = true;
}

/**
 * Resolve the linked agent ID for a customer (users.agent_id or latest referral).
 */
function getLinkedAgentId($customer_id) {
    global $db;
    $customer_id = (int) $customer_id;
    if ($customer_id <= 0) {
        return 0;
    }

    $agent_id = 0;

    // Prefer users.agent_id if available
    if (dbh_table_has_column('users', 'agent_id')) {
        $stmt = $db->prepare("SELECT agent_id FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $agent_id = (int) ($row['agent_id'] ?? 0);
            }
        }
    }

    if ($agent_id <= 0 && dbh_table_exists('user_referrals')) {
        $stmt = $db->prepare("SELECT agent_id FROM user_referrals WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $customer_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $agent_id = (int) ($row['agent_id'] ?? 0);
            }
        }
    }

    if ($agent_id > 0) {
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $agent_id);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $agent_id = 0;
            }
        } else {
            $agent_id = 0;
        }
    }

    return $agent_id;
}

/**
 * Ensure order issue reporting table exists.
 */
function ensureOrderIssueTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $sql = "
        CREATE TABLE IF NOT EXISTS `order_issue_reports` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `order_id` INT NOT NULL,
            `reporter_id` INT NOT NULL,
            `reporter_role` ENUM('customer','agent','admin') NOT NULL DEFAULT 'customer',
            `issue_type` ENUM('not_delivered','other') NOT NULL DEFAULT 'not_delivered',
            `issue_message` TEXT,
            `status` ENUM('open','in_progress','resolved','dismissed') NOT NULL DEFAULT 'open',
            `admin_notes` TEXT,
            `reported_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `resolved_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_order_issue_order` (`order_id`),
            KEY `idx_order_issue_reporter` (`reporter_id`),
            CONSTRAINT `fk_order_issue_order` FOREIGN KEY (`order_id`) REFERENCES `bundle_orders`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_order_issue_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($sql);
    } catch (Exception $e) {
        error_log('Order issue table creation failed: ' . $e->getMessage());
    }

    $ensured = true;
}

/**
 * Parse a free-form list of phone numbers into normalized unique entries.
 */
function parseSmsPhoneList($input) {
    if (!is_string($input) || trim($input) === '') {
        return [];
    }

    $parts = preg_split('/[\s,;|]+/', $input);
    $numbers = [];

    foreach ($parts as $raw) {
        $clean = preg_replace('/[^0-9]/', '', (string) $raw);
        if ($clean === '') {
            continue;
        }

        // Attempt to normalize into international format
        $normalized = formatPhone($clean);
        if (!$normalized) {
            continue;
        }

        if (!preg_match('/^233[0-9]{9}$/', $normalized)) {
            continue;
        }

        $numbers[$normalized] = true;
    }

    return array_keys($numbers);
}

/**
 * Resolve a public URL for an uploaded AFA image path.
 */
if (!function_exists('resolveAfaImageUrl')) {
function resolveAfaImageUrl($path) {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return rtrim((string) SITE_URL, '/') . '/' . ltrim($path, '/');
}
}

/**
 * Normalize WhatsApp number into wa.me compatible digits.
 */
if (!function_exists('normalizeWhatsappNumberForLink')) {
function normalizeWhatsappNumberForLink($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 10 && strpos($digits, '0') === 0) {
        return '233' . substr($digits, 1);
    }

    return $digits;
}
}

/**
 * Preferred WhatsApp escalation number for AFA submissions.
 */
if (!function_exists('getAfaWhatsappEscalationNumber')) {
function getAfaWhatsappEscalationNumber() {
    $configured = trim((string) getSetting('order_report_whatsapp_number', '0249020304'));
    return $configured !== '' ? $configured : '0249020304';
}
}

/**
 * Build a compact WhatsApp-ready AFA summary text.
 */
if (!function_exists('buildAfaWhatsappSummary')) {
function buildAfaWhatsappSummary(array $registration) {
    $reference = trim((string) ($registration['reference'] ?? 'N/A'));
    $beneficiary = trim((string) ($registration['beneficiary_name'] ?? 'N/A'));
    $phone = trim((string) ($registration['phone'] ?? ($registration['phone_number'] ?? 'N/A')));
    $ghanaCardNumber = trim((string) ($registration['ghana_card_number'] ?? 'N/A'));
    $location = trim((string) ($registration['location'] ?? 'N/A'));
    $occupation = trim((string) ($registration['occupation'] ?? 'N/A'));
    $region = trim((string) ($registration['region'] ?? 'N/A'));
    $status = strtoupper(trim((string) ($registration['status'] ?? 'pending')));
    $gateway = strtoupper(trim((string) ($registration['payment_gateway'] ?? 'wallet')));
    $amount = (float) ($registration['amount'] ?? 0);
    $amountText = (defined('CURRENCY') ? CURRENCY : 'GHS ') . number_format($amount, 2);

    return implode("\n", [
        'AFA Registration Alert',
        'Reference: ' . ($reference !== '' ? $reference : 'N/A'),
        'Beneficiary: ' . ($beneficiary !== '' ? $beneficiary : 'N/A'),
        'Phone: ' . ($phone !== '' ? $phone : 'N/A'),
        'Ghana Card No: ' . ($ghanaCardNumber !== '' ? $ghanaCardNumber : 'N/A'),
        'DOB: ' . ($registration['date_of_birth'] ?? 'N/A'),
        'Region: ' . ($region !== '' ? $region : 'N/A'),
        'Location: ' . ($location !== '' ? $location : 'N/A'),
        'Occupation: ' . ($occupation !== '' ? $occupation : 'N/A'),
        'Amount: ' . $amountText,
        'Gateway: ' . ($gateway !== '' ? $gateway : 'N/A'),
        'Status: ' . ($status !== '' ? $status : 'PENDING'),
    ]);
}
}

/**
 * Ensure AFA registration tables and newer columns exist.
 */
if (!function_exists('ensureAfaRegistrationTables')) {
function ensureAfaRegistrationTables() {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    global $db;

    $settingsSql = "
        CREATE TABLE IF NOT EXISTS `afa_registration_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `agent_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `guest_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `allow_wallet_agent` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_gateway_agent` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_wallet_customer` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_gateway_customer` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_guest_paystack` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_guest_moolre` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $agentPricingSql = "
        CREATE TABLE IF NOT EXISTS `agent_afa_registration_pricing` (
            `agent_id` INT NOT NULL,
            `custom_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`agent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $registrationsSql = "
        CREATE TABLE IF NOT EXISTS `afa_registrations` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `agent_id` INT DEFAULT NULL,
            `beneficiary_name` VARCHAR(190) NOT NULL DEFAULT '',
            `email` VARCHAR(190) NOT NULL DEFAULT '',
            `phone` VARCHAR(30) NOT NULL DEFAULT '',
            `phone_number` VARCHAR(30) DEFAULT NULL,
            `ghana_card_number` VARCHAR(80) DEFAULT NULL,
            `ghana_card_front_image` VARCHAR(255) DEFAULT NULL,
            `ghana_card_back_image` VARCHAR(255) DEFAULT NULL,
            `location` VARCHAR(190) DEFAULT NULL,
            `occupation` VARCHAR(190) DEFAULT NULL,
            `region` VARCHAR(120) DEFAULT NULL,
            `date_of_birth` DATE DEFAULT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `admin_price` DECIMAL(10,2) DEFAULT NULL,
            `profit_amount` DECIMAL(10,2) DEFAULT NULL,
            `payment_gateway` VARCHAR(50) DEFAULT NULL,
            `reference` VARCHAR(100) NOT NULL,
            `status` ENUM('pending','processing','completed','delivered','success','failed','refunded') NOT NULL DEFAULT 'pending',
            `processing_at` TIMESTAMP NULL DEFAULT NULL,
            `admin_note` TEXT DEFAULT NULL,
            `admin_notes` TEXT DEFAULT NULL,
            `reviewed_by` INT DEFAULT NULL,
            `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
            `submission_notified_at` TIMESTAMP NULL DEFAULT NULL,
            `completion_notified_at` TIMESTAMP NULL DEFAULT NULL,
            `completion_notified_status` VARCHAR(30) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_afa_registration_reference` (`reference`),
            KEY `idx_afa_registration_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $db->query($settingsSql);
        $db->query($agentPricingSql);
        $db->query($registrationsSql);
    } catch (Exception $e) {
        error_log('AFA registration table creation failed: ' . $e->getMessage());
    }

    if (function_exists('dbh_table_exists') && function_exists('dbh_table_has_column')) {
        $settingsMigrations = [
            'agent_price' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `agent_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'guest_price' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `guest_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'is_enabled' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `is_enabled` TINYINT(1) NOT NULL DEFAULT 0",
            'allow_wallet_agent' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_wallet_agent` TINYINT(1) NOT NULL DEFAULT 1",
            'allow_gateway_agent' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_gateway_agent` TINYINT(1) NOT NULL DEFAULT 1",
            'allow_wallet_customer' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_wallet_customer` TINYINT(1) NOT NULL DEFAULT 1",
            'allow_gateway_customer' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_gateway_customer` TINYINT(1) NOT NULL DEFAULT 1",
            'allow_guest_paystack' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_guest_paystack` TINYINT(1) NOT NULL DEFAULT 1",
            'allow_guest_moolre' => "ALTER TABLE `afa_registration_settings` ADD COLUMN `allow_guest_moolre` TINYINT(1) NOT NULL DEFAULT 1",
        ];
        if (dbh_table_exists('afa_registration_settings')) {
            foreach ($settingsMigrations as $column => $sql) {
                try {
                    if (!dbh_table_has_column('afa_registration_settings', $column)) {
                        $db->query($sql);
                    }
                } catch (Exception $e) {
                    error_log('AFA settings migration skipped: ' . $e->getMessage());
                }
            }
        }

        $pricingMigrations = [
            'agent_id' => "ALTER TABLE `agent_afa_registration_pricing` ADD COLUMN `agent_id` INT NOT NULL",
            'custom_price' => "ALTER TABLE `agent_afa_registration_pricing` ADD COLUMN `custom_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00",
            'is_active' => "ALTER TABLE `agent_afa_registration_pricing` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1",
            'created_at' => "ALTER TABLE `agent_afa_registration_pricing` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "ALTER TABLE `agent_afa_registration_pricing` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        if (dbh_table_exists('agent_afa_registration_pricing')) {
            foreach ($pricingMigrations as $column => $sql) {
                try {
                    if (!dbh_table_has_column('agent_afa_registration_pricing', $column)) {
                        $db->query($sql);
                    }
                } catch (Exception $e) {
                    error_log('AFA pricing migration skipped: ' . $e->getMessage());
                }
            }
        }

        $registrationMigrations = [
            'agent_id' => "ALTER TABLE `afa_registrations` ADD COLUMN `agent_id` INT DEFAULT NULL",
            'beneficiary_name' => "ALTER TABLE `afa_registrations` ADD COLUMN `beneficiary_name` VARCHAR(190) NOT NULL DEFAULT ''",
            'email' => "ALTER TABLE `afa_registrations` ADD COLUMN `email` VARCHAR(190) NOT NULL DEFAULT ''",
            'phone' => "ALTER TABLE `afa_registrations` ADD COLUMN `phone` VARCHAR(30) NOT NULL DEFAULT ''",
            'phone_number' => "ALTER TABLE `afa_registrations` ADD COLUMN `phone_number` VARCHAR(30) DEFAULT NULL",
            'ghana_card_number' => "ALTER TABLE `afa_registrations` ADD COLUMN `ghana_card_number` VARCHAR(80) DEFAULT NULL",
            'ghana_card_front_image' => "ALTER TABLE `afa_registrations` ADD COLUMN `ghana_card_front_image` VARCHAR(255) DEFAULT NULL",
            'ghana_card_back_image' => "ALTER TABLE `afa_registrations` ADD COLUMN `ghana_card_back_image` VARCHAR(255) DEFAULT NULL",
            'location' => "ALTER TABLE `afa_registrations` ADD COLUMN `location` VARCHAR(190) DEFAULT NULL",
            'occupation' => "ALTER TABLE `afa_registrations` ADD COLUMN `occupation` VARCHAR(190) DEFAULT NULL",
            'region' => "ALTER TABLE `afa_registrations` ADD COLUMN `region` VARCHAR(120) DEFAULT NULL",
            'date_of_birth' => "ALTER TABLE `afa_registrations` ADD COLUMN `date_of_birth` DATE DEFAULT NULL",
            'admin_price' => "ALTER TABLE `afa_registrations` ADD COLUMN `admin_price` DECIMAL(10,2) DEFAULT NULL",
            'profit_amount' => "ALTER TABLE `afa_registrations` ADD COLUMN `profit_amount` DECIMAL(10,2) DEFAULT NULL",
            'payment_gateway' => "ALTER TABLE `afa_registrations` ADD COLUMN `payment_gateway` VARCHAR(50) DEFAULT NULL",
            'admin_note' => "ALTER TABLE `afa_registrations` ADD COLUMN `admin_note` TEXT DEFAULT NULL",
            'admin_notes' => "ALTER TABLE `afa_registrations` ADD COLUMN `admin_notes` TEXT DEFAULT NULL",
            'processing_at' => "ALTER TABLE `afa_registrations` ADD COLUMN `processing_at` TIMESTAMP NULL DEFAULT NULL",
            'reviewed_by' => "ALTER TABLE `afa_registrations` ADD COLUMN `reviewed_by` INT DEFAULT NULL",
            'reviewed_at' => "ALTER TABLE `afa_registrations` ADD COLUMN `reviewed_at` TIMESTAMP NULL DEFAULT NULL",
            'submission_notified_at' => "ALTER TABLE `afa_registrations` ADD COLUMN `submission_notified_at` TIMESTAMP NULL DEFAULT NULL",
            'completion_notified_at' => "ALTER TABLE `afa_registrations` ADD COLUMN `completion_notified_at` TIMESTAMP NULL DEFAULT NULL",
            'completion_notified_status' => "ALTER TABLE `afa_registrations` ADD COLUMN `completion_notified_status` VARCHAR(30) DEFAULT NULL",
            'updated_at' => "ALTER TABLE `afa_registrations` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        if (dbh_table_exists('afa_registrations')) {
            foreach ($registrationMigrations as $column => $sql) {
                try {
                    if (!dbh_table_has_column('afa_registrations', $column)) {
                        $db->query($sql);
                    }
                } catch (Exception $e) {
                    error_log('AFA registration migration skipped: ' . $e->getMessage());
                }
            }

            try {
                $statusColumn = $db->query("SHOW COLUMNS FROM `afa_registrations` LIKE 'status'");
                $statusRow = $statusColumn ? $statusColumn->fetch_assoc() : null;
                $statusType = strtolower((string) ($statusRow['Type'] ?? ''));
                if ($statusType !== '' && (strpos($statusType, "'success'") === false || strpos($statusType, "'refunded'") === false)) {
                    $db->query("ALTER TABLE `afa_registrations` MODIFY COLUMN `status` ENUM('pending','processing','completed','delivered','success','failed','refunded') NOT NULL DEFAULT 'pending'");
                }
            } catch (Exception $e) {
                error_log('AFA registration status enum migration failed: ' . $e->getMessage());
            }
        }
    }

    try {
        $result = $db->query("SELECT id FROM afa_registration_settings LIMIT 1");
        if ($result && $result->num_rows === 0) {
            $db->query("
                INSERT INTO afa_registration_settings
                    (agent_price, guest_price, is_enabled, allow_wallet_agent, allow_gateway_agent, allow_wallet_customer, allow_gateway_customer, allow_guest_paystack, allow_guest_moolre)
                VALUES (0.00, 0.00, 0, 1, 1, 1, 1, 1, 1)
            ");
        }
    } catch (Exception $e) {
        error_log('AFA registration default settings seed failed: ' . $e->getMessage());
    }

    $ensured = true;
}
}

/**
 * Render the shared agent sidebar used by standalone agent pages.
 */
if (!function_exists('renderAgentSidebar')) {
function renderAgentSidebar() {
    $current = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    ?>
    <ul class="sidebar-nav">
        <li class="nav-section">
            <div class="nav-section-title">Dashboard</div>
            <div class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo $current === 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </li>
        <li class="nav-section">
            <div class="nav-section-title">Services</div>
            <div class="nav-item">
                <a href="mtn-business.php" class="nav-link <?php echo $current === 'mtn-business.php' ? 'active' : ''; ?>">
                    <i class="fas fa-mobile-alt"></i> MTN Business
                </a>
            </div>
            <div class="nav-item">
                <a href="afa-registration.php" class="nav-link <?php echo $current === 'afa-registration.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i> AFA Registration
                </a>
            </div>
            <div class="nav-item">
                <a href="at-business.php" class="nav-link <?php echo $current === 'at-business.php' ? 'active' : ''; ?>">
                    <i class="fas fa-mobile-alt"></i> AT Business
                </a>
            </div>
            <div class="nav-item">
                <a href="telecel-business.php" class="nav-link <?php echo $current === 'telecel-business.php' ? 'active' : ''; ?>">
                    <i class="fas fa-signal"></i> Telecel Business
                </a>
            </div>
        </li>
        <li class="nav-section">
            <div class="nav-section-title">Transaction</div>
            <div class="nav-item">
                <a href="transactions.php" class="nav-link <?php echo $current === 'transactions.php' ? 'active' : ''; ?>">
                    <i class="fas fa-money-bill-wave"></i> Transactions
                </a>
            </div>
            <div class="nav-item">
                <a href="histories.php" class="nav-link <?php echo $current === 'histories.php' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i> Histories
                </a>
            </div>
        </li>
    </ul>
    <?php
}
}

/**
 * Initialize gateway payment for data bundle purchase (Paystack / Moolre).
 */
if (!function_exists('initializeGatewayBundlePurchase')) {
function initializeGatewayBundlePurchase($user_id, $email, $package_id, $formatted_phone, $price_to_charge_customer, $agent_wholesale_price, $agent_id, $store_slug, $payment_method, &$error) {
    global $db;
    $error = '';
    
    $reference = generateReference('PAY');
    $description = 'Bundle purchase: ' . $formatted_phone;
    
    $metadata = [
        'type' => 'guest_bundle_purchase',
        'store_slug' => $store_slug,
        'agent_id' => $agent_id,
        'package_id' => $package_id,
        'beneficiary_number' => $formatted_phone,
        'customer_price' => $price_to_charge_customer,
        'agent_cost' => $agent_wholesale_price,
        'user_id' => $user_id,
        'email' => $email
    ];
    $metadata_json = json_encode($metadata);
    
    // Create pending transaction
    $stmt_txn = $db->prepare("
        INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description, metadata)
        VALUES (?, 'purchase', ?, 'pending', ?, ?, ?, ?)
    ");
    if (!$stmt_txn) {
        $error = 'Failed to create transaction record.';
        return false;
    }
    $stmt_txn->bind_param('idssss', $user_id, $price_to_charge_customer, $reference, $payment_method, $description, $metadata_json);
    if (!$stmt_txn->execute()) {
        $error = 'Failed to execute transaction record creation.';
        $stmt_txn->close();
        return false;
    }
    $stmt_txn->close();
    
    if ($payment_method === 'paystack') {
        $paystack_secret_key = dbh_env('PAYSTACK_SECRET_KEY');
        $isInvalidPaystackKey = function ($key) {
            $key = trim((string) $key);
            return $key === '' || stripos($key, 'your_secret_key_here') !== false;
        };
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            $paystack_secret_key = PAYSTACK_SECRET_KEY;
        }
        if ($isInvalidPaystackKey($paystack_secret_key)) {
            $error = 'Paystack keys are not configured.';
            return false;
        }
        
        $postfields = json_encode([
            'email' => $email,
            'amount' => $price_to_charge_customer * 100,
            'currency' => CURRENCY_CODE,
            'reference' => $reference,
            'callback_url' => PAYSTACK_CALLBACK_URL,
            'metadata' => [
                'type' => 'guest_bundle_purchase',
                'store_slug' => $store_slug,
                'package_id' => $package_id,
                'beneficiary_number' => $formatted_phone,
                'user_id' => $user_id
            ]
        ]);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $paystack_secret_key,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        
        if ($err) {
            $error = 'Paystack cURL error: ' . $err;
            return false;
        }
        
        $result = json_decode($response, true);
        if ($result && !empty($result['status']) && !empty($result['data']['authorization_url'])) {
            return $result['data']['authorization_url'];
        }
        $error = $result['message'] ?? 'Failed to initialize Paystack payment.';
        return false;
    } elseif ($payment_method === 'moolre') {
        $config = getMoolreConfig();
        if (!isMoolreConfigured($config)) {
            $error = 'Moolre keys are not configured.';
            return false;
        }
        
        $redirectUrl = SITE_URL . '/api/moolre_callback.php?reference=' . urlencode($reference);
        $gateway_payload = [
            'type' => 1,
            'amount' => round($price_to_charge_customer, 2),
            'email' => $email,
            'externalref' => $reference,
            'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
            'redirect' => $redirectUrl,
            'redirecturl' => $redirectUrl,
            'redirect_url' => $redirectUrl,
            'reusable' => '0',
            'currency' => CURRENCY_CODE,
            'accountnumber' => $config['account_number'],
            'metadata' => $metadata
        ];
        
        $moolre_err = null;
        $result = moolrePostJson('https://api.moolre.com/embed/link', $gateway_payload, $config, $moolre_err);
        if ($result && isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true) && !empty($result['data']['authorization_url'])) {
            return $result['data']['authorization_url'];
        }
        $error = $moolre_err ?: 'Failed to initialize Moolre payment.';
        return false;
    }
    
    $error = 'Invalid payment method.';
    return false;
}
}

?>
