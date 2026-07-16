<?php
/**
 * Cron Job for Order Status Updates
 * Run this script periodically to update order statuses
 * 
 * Usage: php cron/status_updater.php
 * Recommended: Run every 2-5 minutes via cron
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/order_status.php';

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

echo "Starting order status update process...\n";

try {
    // Update order statuses
    $updated_count = batchUpdateOrderStatuses();
    
    echo "Successfully updated $updated_count orders\n";
    
    // Log the update
    $log_message = "Cron job updated $updated_count order statuses";
    error_log($log_message);
    
    // Optional: Clean up old completed orders (older than 30 days)
    $cleanup_stmt = $db->prepare("
        UPDATE bundle_orders 
        SET api_response = NULL 
        WHERE status = 'delivered' 
        AND delivered_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND api_response IS NOT NULL
    ");
    $cleanup_stmt->execute();
    $cleaned = $cleanup_stmt->affected_rows;
    
    if ($cleaned > 0) {
        echo "Cleaned up $cleaned old order responses\n";
    }
    
} catch (Exception $e) {
    echo "Error updating order statuses: " . $e->getMessage() . "\n";
    error_log("Order status update cron failed: " . $e->getMessage());
    exit(1);
}

echo "Order status update process completed\n";
?>
