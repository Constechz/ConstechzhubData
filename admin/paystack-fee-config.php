<?php
require_once '../config/config.php';
require_once '../includes/paystack_fees.php';

requireRole('admin');

$pageTitle = 'Paystack Fee Config';
$current_user = getCurrentUser();
$csrf_token = generateCSRF();
$message = '';
$message_type = 'info';

$current_config = getPaystackFeeConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_config') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid session token. Refresh the page and try again.';
        $message_type = 'danger';
    } else {
        $submitted_config = [
            'fee_structures' => [],
            'buffer' => [
                'percentage' => ((float) ($_POST['buffer']['percentage'] ?? 0)) / 100,
                'minimum' => (float) ($_POST['buffer']['minimum'] ?? 0),
            ],
            'tolerance' => [
                'exact_match' => (float) ($_POST['tolerance']['exact_match'] ?? 0),
                'underpayment' => (float) ($_POST['tolerance']['underpayment'] ?? 0),
            ],
        ];

        foreach ($current_config['fee_structures'] as $method => $structure) {
            $submitted_config['fee_structures'][$method] = [
                'percentage' => ((float) ($_POST[$method]['percentage'] ?? 0)) / 100,
                'fixed' => (float) ($_POST[$method]['fixed'] ?? 0),
            ];
        }

        if (updatePaystackFeeConfig($submitted_config)) {
            $message = 'Paystack fee configuration updated successfully.';
            $message_type = 'success';
            $current_config = getPaystackFeeConfig();
        } else {
            $message = 'Unable to save Paystack fee configuration.';
            $message_type = 'danger';
        }
    }
}

$test_amounts = [5, 10, 20, 50, 100, 500, 1000];
$fee_examples = [];
foreach ($test_amounts as $amount) {
    $fee_examples[$amount] = getPaystackFeeEstimate($amount);
}

require_once '../includes/admin_header.php';
?>

<style>
    .paystack-fee-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
        gap: 1.5rem;
        align-items: start;
    }

    .paystack-fee-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 1rem;
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .paystack-fee-card-header {
        padding: 1.25rem 1.5rem 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .paystack-fee-card-header h3,
    .paystack-fee-card-header h4 {
        margin: 0;
    }

    .paystack-fee-card-body {
        padding: 1.5rem;
    }

    .paystack-fee-stack {
        display: grid;
        gap: 1rem;
    }

    .fee-structure-card {
        border: 1px solid var(--border-color);
        border-radius: 0.9rem;
        padding: 1rem;
        background: var(--bg-secondary);
    }

    .fee-structure-card h5 {
        margin-bottom: 0.9rem;
        font-size: 1rem;
    }

    .paystack-fee-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .paystack-fee-form-group label {
        display: block;
        margin-bottom: 0.4rem;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .paystack-fee-form-group input {
        width: 100%;
        min-height: 44px;
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        background: var(--bg-primary);
        color: var(--text-primary);
        padding: 0.7rem 0.85rem;
    }

    .paystack-fee-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .paystack-fee-stat {
        border: 1px solid var(--border-color);
        border-radius: 0.9rem;
        padding: 1rem;
        background: var(--bg-secondary);
    }

    .paystack-fee-stat .label {
        display: block;
        color: var(--text-secondary);
        font-size: 0.8rem;
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .paystack-fee-stat .value {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .paystack-fee-table {
        width: 100%;
        border-collapse: collapse;
    }

    .paystack-fee-table th,
    .paystack-fee-table td {
        padding: 0.75rem;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
        font-size: 0.92rem;
    }

    .paystack-fee-note {
        color: var(--text-secondary);
        font-size: 0.92rem;
        margin-top: 1rem;
    }

    @media (max-width: 1100px) {
        .paystack-fee-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .paystack-fee-form-grid,
        .paystack-fee-meta {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="dashboard-content">
    <div class="page-title">
        <h1>Paystack Fee Configuration</h1>
        <p class="page-subtitle">Manage fee assumptions used when matching Paystack payments against expected amounts.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="paystack-fee-grid">
        <section class="paystack-fee-card">
            <div class="paystack-fee-card-header">
                <h3>Fee Structure</h3>
            </div>
            <div class="paystack-fee-card-body">
                <form method="post" class="paystack-fee-stack">
                    <input type="hidden" name="action" value="update_config">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                    <?php foreach ($current_config['fee_structures'] as $method => $structure): ?>
                        <div class="fee-structure-card">
                            <h5><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $method)), ENT_QUOTES, 'UTF-8'); ?></h5>
                            <div class="paystack-fee-form-grid">
                                <div class="paystack-fee-form-group">
                                    <label for="<?php echo htmlspecialchars($method . '-percentage', ENT_QUOTES, 'UTF-8'); ?>">Percentage (%)</label>
                                    <input
                                        id="<?php echo htmlspecialchars($method . '-percentage', ENT_QUOTES, 'UTF-8'); ?>"
                                        type="number"
                                        name="<?php echo htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>[percentage]"
                                        value="<?php echo htmlspecialchars(number_format(((float) $structure['percentage']) * 100, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                    >
                                </div>
                                <div class="paystack-fee-form-group">
                                    <label for="<?php echo htmlspecialchars($method . '-fixed', ENT_QUOTES, 'UTF-8'); ?>">Fixed Fee</label>
                                    <input
                                        id="<?php echo htmlspecialchars($method . '-fixed', ENT_QUOTES, 'UTF-8'); ?>"
                                        type="number"
                                        name="<?php echo htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>[fixed]"
                                        value="<?php echo htmlspecialchars(number_format((float) $structure['fixed'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        step="0.01"
                                        min="0"
                                    >
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="fee-structure-card">
                        <h5>Buffer</h5>
                        <div class="paystack-fee-form-grid">
                            <div class="paystack-fee-form-group">
                                <label for="buffer-percentage">Buffer Percentage (%)</label>
                                <input
                                    id="buffer-percentage"
                                    type="number"
                                    name="buffer[percentage]"
                                    value="<?php echo htmlspecialchars(number_format(((float) $current_config['buffer']['percentage']) * 100, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                >
                            </div>
                            <div class="paystack-fee-form-group">
                                <label for="buffer-minimum">Minimum Buffer</label>
                                <input
                                    id="buffer-minimum"
                                    type="number"
                                    name="buffer[minimum]"
                                    value="<?php echo htmlspecialchars(number_format((float) $current_config['buffer']['minimum'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    step="0.01"
                                    min="0"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="fee-structure-card">
                        <h5>Tolerance</h5>
                        <div class="paystack-fee-form-grid">
                            <div class="paystack-fee-form-group">
                                <label for="tolerance-exact">Exact Match Tolerance</label>
                                <input
                                    id="tolerance-exact"
                                    type="number"
                                    name="tolerance[exact_match]"
                                    value="<?php echo htmlspecialchars(number_format((float) $current_config['tolerance']['exact_match'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    step="0.01"
                                    min="0"
                                >
                            </div>
                            <div class="paystack-fee-form-group">
                                <label for="tolerance-underpayment">Underpayment Tolerance</label>
                                <input
                                    id="tolerance-underpayment"
                                    type="number"
                                    name="tolerance[underpayment]"
                                    value="<?php echo htmlspecialchars(number_format((float) $current_config['tolerance']['underpayment'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    step="0.01"
                                    min="0"
                                >
                            </div>
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary">Save Configuration</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="paystack-fee-card">
            <div class="paystack-fee-card-header">
                <h4>Preview</h4>
            </div>
            <div class="paystack-fee-card-body">
                <div class="paystack-fee-meta">
                    <div class="paystack-fee-stat">
                        <span class="label">Configured Methods</span>
                        <span class="value"><?php echo number_format(count($current_config['fee_structures'])); ?></span>
                    </div>
                    <div class="paystack-fee-stat">
                        <span class="label">Logged In User</span>
                        <span class="value"><?php echo htmlspecialchars($current_user['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="paystack-fee-stat">
                        <span class="label">Buffer</span>
                        <span class="value"><?php echo htmlspecialchars(number_format(((float) $current_config['buffer']['percentage']) * 100, 2), ENT_QUOTES, 'UTF-8'); ?>%</span>
                    </div>
                    <div class="paystack-fee-stat">
                        <span class="label">Min Buffer</span>
                        <span class="value"><?php echo htmlspecialchars(number_format((float) $current_config['buffer']['minimum'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <div class="table-responsive mt-4">
                    <table class="paystack-fee-table">
                        <thead>
                            <tr>
                                <th>Base Amount</th>
                                <th>Min Total</th>
                                <th>Estimated Total</th>
                                <th>Max Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fee_examples as $amount => $example): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(number_format((float) $amount, 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $example['min_total'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $example['estimated_total'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float) $example['max_total'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="paystack-fee-note">
                    These values are stored in the shared <code>settings</code> table under <code>paystack_fee_config</code>. Payment matching will use the saved config automatically.
                </div>
            </div>
        </section>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
