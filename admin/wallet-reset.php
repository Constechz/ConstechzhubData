<?php
require_once '../config/config.php';

requireAnyRole([ROLE_ADMIN, ROLE_SUPER_ADMIN]);
$current_user = getCurrentUser();
$pageTitle = 'Wallet Reset';
$csrf_token = generateCSRF();
$flash = getFlashMessage();
$stats = getWalletResetStats();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($token)) {
        setFlashMessage('error', 'Invalid CSRF token. Please try again.');
        header('Location: wallet-reset.php');
        exit();
    }

    $confirmation = strtoupper(trim($_POST['confirmation_phrase'] ?? ''));
    if ($confirmation !== 'RESET WALLETS') {
        setFlashMessage('error', 'Confirmation phrase mismatch. Type "RESET WALLETS" exactly to proceed.');
        header('Location: wallet-reset.php');
        exit();
    }

    $reason = trim($_POST['reason'] ?? '');
    if (strlen($reason) > 190) {
        $reason = substr($reason, 0, 190);
    }
    $reason = preg_replace('/\s+/', ' ', $reason);

    $result = resetAllWalletBalances($current_user['id'] ?? null, $reason);

    if (!empty($result['success'])) {
        $messageParts = [];
        $messageParts[] = number_format($result['wallets_reset']) . ' wallet(s) reset';
        if (!empty($result['total_debited'])) {
            $messageParts[] = 'debited ' . formatCurrency($result['total_debited']);
        }
        if (!empty($result['total_credited'])) {
            $messageParts[] = 'credited ' . formatCurrency($result['total_credited']);
        }
        if (!empty($reason)) {
            $messageParts[] = 'reason: ' . htmlspecialchars($reason);
        }
        setFlashMessage('success', 'Wallet reset completed successfully Ã¢â‚¬â€ ' . implode(', ', $messageParts) . '.');
    } else {
        setFlashMessage('error', 'Wallet reset failed: ' . ($result['error'] ?? 'Unknown error'));
    }

    header('Location: wallet-reset.php');
    exit();
}

include '../includes/admin_header.php';
?>

<div class="dashboard-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1><i class="fas fa-shield-alt"></i> Wallet Reset</h1>
        <p class="page-subtitle">Set every user wallet back to zero. Only super admin or admin accounts should perform this irreversible operation.</p>
    </div>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title text-danger"><i class="fas fa-exclamation-circle"></i> Critical Warning</h3>
        </div>
        <div class="widget-body">
            <div class="alert alert-danger">
                <strong>This action cannot be undone.</strong> All wallet balances (agents, customers, and admins) will be set to <strong><?php echo formatCurrency(0); ?></strong>.
                A reversal requires manual top-ups per account.
                <ul class="mt-3 mb-0">
                    <li>Wallet balances are zeroed instantly.</li>
                    <li>Audit entries are recorded for every affected wallet.</li>
                    <li>No SMS/email notifications are sent automatically.</li>
                </ul>
            </div>

            <div class="stats-grid" style="margin-bottom:1.5rem;">
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(84, 19, 136, 0.12);color:#541388;">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['total_wallets']); ?></div>
                        <div class="stat-label">Total Wallets</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(255, 212, 0, 0.12);color:#FFD400;">
                        <i class="fas fa-balance-scale-left"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats['wallets_with_balance']); ?></div>
                        <div class="stat-label">Wallets Holding Funds</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(46, 41, 78, 0.12);color:#2E294E;">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo formatCurrency($stats['positive_balance']); ?></div>
                        <div class="stat-label">Positive Balances</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(217, 3, 104, 0.12);color:#D90368;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo formatCurrency($stats['negative_balance']); ?></div>
                        <div class="stat-label">Negative Balances</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(84, 19, 136, 0.12);color:#541388;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo formatCurrency($stats['total_balance']); ?></div>
                        <div class="stat-label">Net Wallet Exposure</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($stats['last_reset_at'])): ?>
                <div class="alert alert-warning">
                    <strong>Last reset:</strong> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($stats['last_reset_at']))); ?>
                    <?php if (!empty($stats['last_reset_details'])): ?>
                        <br><?php echo htmlspecialchars($stats['last_reset_details']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="danger-form" onsubmit="return confirmWalletReset();">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label for="reason"><strong>Reason (optional but recommended)</strong></label>
                    <textarea id="reason" name="reason" class="form-control" rows="2" placeholder="e.g. Resetting demo environment before presentation"><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                    <small class="text-muted">Shown in audit logs for accountability.</small>
                </div>

                <div class="form-group">
                    <label for="confirmation_phrase"><strong>Confirmation</strong></label>
                    <p class="text-muted mb-2">Type <code>RESET WALLETS</code> to confirm you understand that every user's wallet will be cleared.</p>
                    <input type="text" id="confirmation_phrase" name="confirmation_phrase" class="form-control" placeholder="Type RESET WALLETS to confirm" autocomplete="off" required>
                </div>
                <button type="submit" class="btn btn-danger" id="walletResetButton" disabled>
                    <i class="fas fa-broom"></i> Reset All Wallet Balances
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const walletConfirmationField = document.getElementById('confirmation_phrase');
const walletResetButton = document.getElementById('walletResetButton');

function updateWalletResetButtonState() {
    if (!walletConfirmationField || !walletResetButton) return;
    const value = walletConfirmationField.value.trim().toUpperCase();
    walletResetButton.disabled = value !== 'RESET WALLETS';
}

function confirmWalletReset() {
    return confirm('This will permanently set every wallet balance to zero. Continue?');
}

if (walletConfirmationField) {
    walletConfirmationField.addEventListener('input', updateWalletResetButtonState);
}
</script>

<?php include '../includes/admin_footer.php'; ?>

