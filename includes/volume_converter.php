<?php
/**
 * Volume conversion utilities for API providers
 */

/**
 * Extract volume in GB from data size string
 */
function extractVolumeGB($data_size) {
    // Remove spaces and convert to lowercase
    $data_size = strtolower(str_replace(' ', '', $data_size));
    
    // Extract numeric value
    preg_match('/(\d+(?:\.\d+)?)/', $data_size, $matches);
    $numeric_value = isset($matches[1]) ? floatval($matches[1]) : 0;
    
    if ($numeric_value == 0) {
        return 0;
    }
    
    // Check for unit
    if (strpos($data_size, 'gb') !== false) {
        return $numeric_value;
    } elseif (strpos($data_size, 'mb') !== false) {
        return $numeric_value / 1000; // Convert MB to GB
    } elseif (strpos($data_size, 'tb') !== false) {
        return $numeric_value * 1000; // Convert TB to GB
    }
    
    // Default assumption is GB if no unit specified
    return $numeric_value;
}

/**
 * Convert GB to MB for providers that need MB
 */
function convertGBtoMB($volume_gb) {
    return intval($volume_gb * 1000);
}

/**
 * Format volume for display
 */
function formatVolumeDisplay($volume_gb) {
    if ($volume_gb >= 1000) {
        return number_format($volume_gb / 1000, 1) . 'TB';
    } elseif ($volume_gb >= 1) {
        return number_format($volume_gb, 1) . 'GB';
    } else {
        return number_format($volume_gb * 1000) . 'MB';
    }
}

/**
 * Validate volume format
 */
function isValidVolume($data_size) {
    $volume_gb = extractVolumeGB($data_size);
    return $volume_gb > 0;
}
?>
