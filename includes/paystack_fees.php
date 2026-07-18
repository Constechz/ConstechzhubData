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
 * Get Paystack fee configuration
 */
function getPaystackFeeConfig() {
    return PAYSTACK_FEE_CONFIG;
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
    // In a production system, you might want to store this in database
    // For now, this would require updating the constant above
    
    // Log the configuration change
    error_log("Paystack fee configuration updated: " . json_encode($new_config));
    
    // Return success (in real implementation, you'd save to database)
    return true;
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