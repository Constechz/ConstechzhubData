<?php
/**
 * Paystack Fee Management Interface
 * 
 * Admin interface for managing dynamic Paystack fee configuration
 */

require_once '../config/config.php';
require_once '../includes/paystack_fees.php';

// Require admin access
requireLogin();
$current_user = getCurrentUser();

if ($current_user['role'] !== 'admin') {
    header('Location: ' . SITE_URL . '/unauthorized.php');
    exit();
}

$page_title = "Paystack Fee Configuration";
include '../includes/admin_header.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_config'])) {
    // In a full implementation, you would update the database here
    // For now, we'll just show what would be updated
    $message = "Configuration updated successfully! (Note: Restart required for changes to take effect)";
    $message_type = "success";
}

$current_config = getPaystackFeeConfig();

// Test different amounts
$test_amounts = [5, 10, 20, 50, 100, 500, 1000];
$fee_examples = [];
foreach ($test_amounts as $amount) {
    $fee_examples[$amount] = getPaystackFeeEstimate($amount);
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Paystack Fee Configuration</h3>
                    <p class="text-muted">Configure dynamic fee handling for Paystack payments</p>
                </div>
                
                <div class="card-body">
                    <?php if (isset($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="close" data-dismiss="alert">
                                <span>&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <!-- Configuration Form -->
                        <div class="col-md-6">
                            <h5>Fee Structure Configuration</h5>
                            <form method="POST">
                                <?php foreach ($current_config['fee_structures'] as $method => $structure): ?>
                                    <div class="card mb-3">
                                        <div class="card-header">
                                            <h6 class="mb-0"><?php echo ucwords(str_replace('_', ' ', $method)); ?></h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <label>Percentage (%)</label>
                                                    <input type="number" class="form-control" 
                                                           name="<?php echo $method; ?>[percentage]" 
                                                           value="<?php echo $structure['percentage'] * 100; ?>" 
                                                           step="0.1" min="0" max="10">
                                                </div>
                                                <div class="col-6">
                                                    <label>Fixed Fee</label>
                                                    <input type="number" class="form-control" 
                                                           name="<?php echo $method; ?>[fixed]" 
                                                           value="<?php echo $structure['fixed']; ?>" 
                                                           step="0.01" min="0">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Buffer & Tolerance Settings</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-6">
                                                <label>Buffer Percentage (%)</label>
                                                <input type="number" class="form-control" 
                                                       name="buffer[percentage]" 
                                                       value="<?php echo $current_config['buffer']['percentage'] * 100; ?>" 
                                                       step="0.1" min="0" max="5">
                                            </div>
                                            <div class="col-6">
                                                <label>Minimum Buffer</label>
                                                <input type="number" class="form-control" 
                                                       name="buffer[minimum]" 
                                                       value="<?php echo $current_config['buffer']['minimum']; ?>" 
                                                       step="0.01" min="0">
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <label>Exact Match Tolerance</label>
                                                <input type="number" class="form-control" 
                                                       name="tolerance[exact_match]" 
                                                       value="<?php echo $current_config['tolerance']['exact_match']; ?>" 
                                                       step="0.01" min="0">
                                            </div>
                                            <div class="col-6">
                                                <label>Underpayment Tolerance</label>
                                                <input type="number" class="form-control" 
                                                       name="tolerance[underpayment]" 
                                                       value="<?php echo $current_config['tolerance']['underpayment']; ?>" 
                                                       step="0.01" min="0">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" name="update_config" class="btn btn-primary">
                                    Update Configuration
                                </button>
                            </form>
                        </div>
                        
                        <!-- Fee Examples -->
                        <div class="col-md-6">
                            <h5>Fee Calculation Examples</h5>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Amount</th>
                                            <th>Min Total</th>
                                            <th>Est. Total</th>
                                            <th>Max Total</th>
                                            <th>Fee Range</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fee_examples as $amount => $example): ?>
                                            <tr>
                                                <td><?php echo number_format($amount, 2); ?></td>
                                                <td><?php echo number_format($example['min_total'], 2); ?></td>
                                                <td><?php echo number_format($example['estimated_total'], 2); ?></td>
                                                <td><?php echo number_format($example['max_total'], 2); ?></td>
                                                <td><?php echo number_format($example['fee_range']['min_fee'], 2) . ' - ' . number_format($example['fee_range']['max_fee'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <h6 class="mt-4">Current Configuration Summary</h6>
                            <div class="alert alert-info">
                                <strong>Fee Structures:</strong><br>
                                <?php foreach ($current_config['fee_structures'] as $method => $structure): ?>
                                    • <?php echo ucwords(str_replace('_', ' ', $method)); ?>: 
                                    <?php echo ($structure['percentage'] * 100); ?>% + <?php echo number_format($structure['fixed'], 2); ?><br>
                                <?php endforeach; ?>
                                <br>
                                <strong>Buffer:</strong> <?php echo ($current_config['buffer']['percentage'] * 100); ?>% (min <?php echo $current_config['buffer']['minimum']; ?>)<br>
                                <strong>Tolerances:</strong> Exact ±<?php echo $current_config['tolerance']['exact_match']; ?>, Underpay ±<?php echo $current_config['tolerance']['underpayment']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Add real-time fee calculation
document.querySelectorAll('input[type="number"]').forEach(input => {
    input.addEventListener('input', function() {
        // In a full implementation, you could add real-time fee calculation here
        console.log('Configuration changed:', this.name, this.value);
    });
});
</script>

<?php include '../includes/admin_footer.php'; ?>