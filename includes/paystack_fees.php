<?php
/**
 * Paystack Fee Configuration
 * 
 * This file contains configuration for dynamic Paystack fee calculation.
 * Adjust these values if Paystack changes their fee structure.
 */

// Paystack fee structures (as percentages and fixed amounts)
define('PAYSTACK_FEE_CONFIG', [
    // Fee structures for different payment methods
    'fee_structures' => [
        'local_card' => [
            'percentage' => 0.015,  // 1.5%
            'fixed' => 1.00        // Fixed fee in local currency
        ],
        'international_card' => [
            'percentage' => 0.039,  // 3.9%
            'fixed' => 1.00        // Fixed fee in local currency
        ],
        'bank_transfer' => [
            'percentage' => 0.0,    // No percentage
            'fixed' => 0.50        // Flat fee
        ],
        'ussd' => [
            'percentage' => 0.0,    // No percentage
            'fixed' => 0.50        // Flat fee
        ],
        'mobile_money' => [
            'percentage' => 0.02,   // 2%
            'fixed' => 0.50        // Fixed fee
        ]
    ],
    
    // Buffer settings for fee calculation
    'buffer' => [
        'percentage' => 0.01,       // 1% buffer for variations
        'minimum' => 0.50          // Minimum buffer amount
    ],
    
    // Tolerance settings
    'tolerance' => [
        'exact_match' => 0.02,     // Tolerance for exact amount matching
        'underpayment' => 0.02     // Allow small underpayments for rounding
    ]
]);

/**
 * Get the default Paystack fee configuration.
 */
function getDefaultPaystackFeeConfig() {
    return PAYSTACK_FEE_CONFIG;
}

/**
 * Normalize and validate Paystack fee configuration values.
 */
function normalizePaystackFeeConfig($config) {
    $defaults = getDefaultPaystackFeeConfig();

    if (!is_array($config)) {
        return $defaults;
    }

    $normalized = $defaults;

    if (isset($config['fee_structures']) && is_array($config['fee_structures'])) {
        foreach ($defaults['fee_structures'] as $method => $defaultStructure) {
            $structure = $config['fee_structures'][$method] ?? [];
            $percentage = isset($structure['percentage']) ? (float) $structure['percentage'] : (float) $defaultStructure['percentage'];
            $fixed = isset($structure['fixed']) ? (float) $structure['fixed'] : (float) $defaultStructure['fixed'];

            $normalized['fee_structures'][$method] = [
                'percentage' => max(0, min(1, $percentage)),
                'fixed' => max(0, $fixed),
            ];
        }
    }

    if (isset($config['buffer']) && is_array($config['buffer'])) {
        $normalized['buffer']['percentage'] = max(
            0,
            min(
                1,
                isset($config['buffer']['percentage'])
                    ? (float) $config['buffer']['percentage']
                    : (float) $defaults['buffer']['percentage']
            )
        );
        $normalized['buffer']['minimum'] = max(
            0,
            isset($config['buffer']['minimum'])
                ? (float) $config['buffer']['minimum']
                : (float) $defaults['buffer']['minimum']
        );
    }

    if (isset($config['tolerance']) && is_array($config['tolerance'])) {
        $normalized['tolerance']['exact_match'] = max(
            0,
            isset($config['tolerance']['exact_match'])
                ? (float) $config['tolerance']['exact_match']
                : (float) $defaults['tolerance']['exact_match']
        );
        $normalized['tolerance']['underpayment'] = max(
            0,
            isset($config['tolerance']['underpayment'])
                ? (float) $config['tolerance']['underpayment']
                : (float) $defaults['tolerance']['underpayment']
        );
    }

    return $normalized;
}

/**
 * Get Paystack fee configuration, optionally overridden from settings.
 */
function getPaystackFeeConfig() {
    $config = getDefaultPaystackFeeConfig();

    if (!function_exists('getSetting')) {
        return $config;
    }

    $storedConfig = getSetting('paystack_fee_config', '');
    if (!is_string($storedConfig) || trim($storedConfig) === '') {
        return $config;
    }

    $decoded = json_decode($storedConfig, true);
    if (!is_array($decoded)) {
        return $config;
    }

    return normalizePaystackFeeConfig($decoded);
}

/**
 * Calculate dynamic fee range for any amount
 */
function calculateDynamicPaystackFeeRange($amount) {
    $config = getPaystackFeeConfig();
    $fee_structures = $config['fee_structures'];
    
    $min_fee = PHP_FLOAT_MAX;
    $max_fee = 0;
    
    foreach ($fee_structures as $method => $structure) {
        $calculated_fee = ($amount * $structure['percentage']) + $structure['fixed'];
        $min_fee = min($min_fee, $calculated_fee);
        $max_fee = max($max_fee, $calculated_fee);
    }
    
    // Add buffer for fee variations
    $buffer = max(
        $config['buffer']['minimum'], 
        $amount * $config['buffer']['percentage']
    );
    
    return [
        'min_fee' => max(0, $min_fee),
        'max_fee' => $max_fee + $buffer,
        'estimated_fee' => ($min_fee + $max_fee) / 2,
        'buffer' => $buffer
    ];
}

/**
 * Validate Paystack payment amount
 */
function validatePaystackAmount($paid_amount, $expected_amount) {
    $config = getPaystackFeeConfig();
    $fee_range = calculateDynamicPaystackFeeRange($expected_amount);
    
    // Calculate acceptable range
    $min_acceptable = $expected_amount - $config['tolerance']['underpayment'];
    $max_acceptable = $expected_amount + $fee_range['max_fee'];
    
    $amount_difference = abs($paid_amount - $expected_amount);
    $is_exact_match = $amount_difference <= $config['tolerance']['exact_match'];
    $is_with_fees = ($paid_amount >= $min_acceptable && $paid_amount <= $max_acceptable);
    
    return [
        'is_valid' => ($is_exact_match || $is_with_fees),
        'is_exact_match' => $is_exact_match,
        'is_with_fees' => $is_with_fees,
        'amount_difference' => $amount_difference,
        'fee_range' => $fee_range,
        'acceptable_range' => [
            'min' => $min_acceptable,
            'max' => $max_acceptable
        ]
    ];
}

/**
 * Update Paystack fee configuration (for admin use)
 */
function updatePaystackFeeConfig($new_config) {
    global $db;

    if (!isset($db) || !function_exists('dbh_table_exists') || !dbh_table_exists('settings')) {
        return false;
    }

    $normalized = normalizePaystackFeeConfig($new_config);
    $payload = json_encode($normalized);
    if ($payload === false) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO `settings` (`setting_key`, `setting_value`, `description`)
            VALUES ('paystack_fee_config', ?, 'Paystack fee configuration')
            ON DUPLICATE KEY UPDATE
                `setting_value` = VALUES(`setting_value`),
                `description` = VALUES(`description`)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $payload);
        return $stmt->execute();
    } catch (Throwable $e) {
        error_log('Paystack fee configuration update failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get fee estimate for display to users
 */
function getPaystackFeeEstimate($amount) {
    $fee_range = calculateDynamicPaystackFeeRange($amount);
    
    return [
        'min_total' => $amount + $fee_range['min_fee'],
        'max_total' => $amount + $fee_range['max_fee'],
        'estimated_total' => $amount + $fee_range['estimated_fee'],
        'fee_range' => $fee_range
    ];
}
?>
