<?php
require_once '../config/config.php';

requireRole('admin');
$current_user = getCurrentUser();
$pageTitle = 'System Reset';
$csrf_token = generateCSRF();
$flash = getFlashMessage();
$stats = getSystemResetStats();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($token)) {
        setFlashMessage('error', 'Invalid CSRF token');
        header('Location: system-reset.php');
        exit();
    }

    $confirmation = strtoupper(trim($_POST['confirmation_phrase'] ?? ''));
    if ($confirmation !== 'RESET SYSTEM') {
        setFlashMessage('error', 'Confirmation phrase does not match. Please type "RESET SYSTEM" exactly.');
        header('Location: system-reset.php');
        exit();
    }

    $result = resetSystemData($current_user['id'] ?? null);
    if ($result['success']) {
        setFlashMessage('success', 'System reset completed successfully. All selected data has been cleared.');
    } else {
        setFlashMessage('error', 'System reset failed: ' . $result['error']);
    }

    header('Location: system-reset.php');
    exit();
}

$stats = getSystemResetStats();
include '../includes/admin_header.php';
?>

<div class="dashboard-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1><i class="fas fa-broom"></i> System Reset</h1>
        <p class="page-subtitle">Danger zone: permanently removes agents, customers, transactions, data history and data packages. This action cannot be undone.</p>
    </div>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title text-danger"><i class="fas fa-exclamation-triangle"></i> Irreversible Action</h3>
        </div>
        <div class="widget-body">
            <div class="alert alert-danger" role="alert">
                <strong>Warning:</strong> This will permanently delete:
                <ul>
                    <li>All data packages, pricing configurations and agent custom pricing</li>
                    <li>All agents &amp; customers, including their wallets and linked integrations</li>
                    <li>All transactions, wallet histories, top-up requests and SMS notifications</li>
                    <li>All data histories (bundle orders) and related commission records</li>
                    <li>All delivery issue reports submitted by agents or customers</li>
                </ul>
                Administrative accounts and configuration settings will be preserved.
            </div>

            <div class="stats-grid" style="margin-bottom:1.5rem;">
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(84, 19, 136, 0.12);color:#541388;">
                        <i class="fas fa-database"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['data_packages']); ?></div>
                        <div class="stat-label">Data Packages</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(46, 41, 78, 0.12);color:#2E294E;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['agent_users']); ?></div>
                        <div class="stat-label">Agents</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(241, 233, 218, 0.12);color:#F1E9DA;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['customer_users']); ?></div>
                        <div class="stat-label">Customers</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(255, 212, 0, 0.12);color:#FFD400;">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['transactions']); ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(84, 19, 136, 0.12);color:#541388;">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['bundle_orders']); ?></div>
                        <div class="stat-label">Data Histories</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(255, 212, 0, 0.12);color:#FFD400;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['sms_notifications']); ?></div>
                        <div class="stat-label">SMS Notifications</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(217, 3, 104, 0.12);color:#D90368;">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['order_issue_reports']); ?></div>
                        <div class="stat-label">Issue Reports</div>
                    </div>
                </div>
            </div>

            <form method="post" class="danger-form" onsubmit="return confirmSystemReset();">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="confirmation_phrase"><strong>Confirmation</strong></label>
                    <p class="text-muted mb-2">Type <code>RESET SYSTEM</code> in all caps to confirm you understand this operation.</p>
                    <input type="text" id="confirmation_phrase" name="confirmation_phrase" class="form-control" placeholder="Type RESET SYSTEM to confirm" autocomplete="off" required>
                </div>
                <button type="submit" class="btn btn-danger" id="systemResetButton" disabled>
                    <i class="fas fa-broom"></i> Permanently Reset System
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const confirmationField = document.getElementById('confirmation_phrase');
const resetButton = document.getElementById('systemResetButton');

function updateResetButtonState() {
    if (!confirmationField || !resetButton) return;
    const value = confirmationField.value.trim().toUpperCase();
    if (value === 'RESET SYSTEM') {
        resetButton.disabled = false;
        resetButton.classList.remove('btn-secondary');
        resetButton.classList.add('btn-danger');
    } else {
        resetButton.disabled = true;
    }
}

function confirmSystemReset() {
    return confirm('This will permanently delete data packages, agents, customers, transactions, and data histories. This action cannot be undone. Continue?');
}

if (confirmationField) {
    confirmationField.addEventListener('input', updateResetButtonState);
}
</script>

<?php include '../includes/admin_footer.php'; ?>

