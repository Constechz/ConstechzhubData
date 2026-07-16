<?php
require_once '../config/config.php';

requireRole('admin');

$pageTitle = 'System Health';
$current_user = getCurrentUser();
$checked_at = date('M j, Y g:i A');
$csrf_token = generateCSRF();

$curl_loaded = extension_loaded('curl');
$config = getMoolreConfig();
$moolre_configured = isMoolreConfigured($config);

$missing = [];
if (empty($config['user'])) {
    $missing[] = 'MOOLRE_API_USER';
}
if (empty($config['pubkey'])) {
    $missing[] = 'MOOLRE_API_PUBKEY';
}
if (empty($config['account_number'])) {
    $missing[] = 'MOOLRE_ACCOUNT_NUMBER';
}

$optional_missing = [];
if (empty($config['key'])) {
    $optional_missing[] = 'MOOLRE_API_KEY';
}
if (empty($config['vaskey'])) {
    $optional_missing[] = 'MOOLRE_API_VASKEY';
}
if (empty($config['webhook_secret'])) {
    $optional_missing[] = 'MOOLRE_WEBHOOK_SECRET';
}

$ping_message = '';
$ping_type = '';
$ping_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ping_moolre') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $ping_message = 'Invalid session token. Please refresh and try again.';
        $ping_type = 'danger';
    } elseif (!$curl_loaded) {
        $ping_message = 'Cannot run Moolre ping because cURL is not enabled.';
        $ping_type = 'danger';
    } elseif (!$moolre_configured) {
        $ping_message = 'Cannot run Moolre ping because required credentials are missing.';
        $ping_type = 'warning';
    } else {
        $ping_email = $current_user['email'] ?? (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
        if ($ping_email === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $ping_email = 'admin@' . preg_replace('/^www\./', '', $host);
        }

        $reference = 'HEALTH_' . time();
        $payload = [
            'type' => 1,
            'amount' => 1,
            'email' => $ping_email,
            'externalref' => $reference,
            'callback' => defined('MOOLRE_CALLBACK_URL') ? MOOLRE_CALLBACK_URL : (SITE_URL . '/api/moolre_webhook.php'),
            'redirect' => SITE_URL . '/admin/health-check.php',
            'reusable' => '0',
            'currency' => CURRENCY_CODE,
            'accountnumber' => $config['account_number'],
            'metadata' => [
                'type' => 'health_check',
                'requested_by' => $current_user['id'] ?? null
            ]
        ];

        $error = null;
        $result = moolrePostJson('https://api.moolre.com/embed/link', $payload, $config, $error);
        if (!$result) {
            $ping_message = $error ?: 'Moolre API ping failed.';
            $ping_type = 'danger';
        } else {
            $status_ok = isset($result['status']) && ((int) $result['status'] === 1 || $result['status'] === true);
            if ($status_ok) {
                $ping_message = 'Moolre API responded successfully.';
                $ping_type = 'success';
                $ping_link = $result['data']['authorization_url'] ?? '';
            } else {
                $ping_message = $result['message'] ?? 'Moolre API returned an error.';
                $ping_type = 'danger';
            }
        }
    }
}

require_once '../includes/admin_header.php';
?>

<style>
    .health-grid {
        display: grid;
        gap: 1.5rem;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        margin-bottom: 2rem;
    }

    .health-card {
        border-radius: 1rem;
        border: 1px solid var(--border-color);
        background: var(--bg-primary);
        padding: 1.25rem 1.5rem;
        box-shadow: var(--shadow);
    }

    .health-card h4 {
        margin-bottom: 0.5rem;
        font-size: 1rem;
    }

    .health-status {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }

    .health-status.ok {
        background: rgba(34, 197, 94, 0.15);
        color: #15803d;
    }

    .health-status.fail {
        background: rgba(239, 68, 68, 0.15);
        color: #b91c1c;
    }

    .health-status.warn {
        background: rgba(234, 179, 8, 0.15);
        color: #a16207;
    }

    .health-list {
        margin-top: 0.75rem;
        font-size: 0.9rem;
        color: var(--text-secondary);
    }

    .health-list code {
        background: var(--bg-secondary);
        padding: 0.1rem 0.35rem;
        border-radius: 0.35rem;
    }
</style>

<div class="dashboard-content">
    <div class="page-title">
        <h1>System Health Check</h1>
        <p class="page-subtitle">Quick verification for payment dependencies (cURL + Moolre).</p>
    </div>

    <?php if ($ping_message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($ping_type ?: 'info'); ?>">
            <?php echo htmlspecialchars($ping_message); ?>
            <?php if ($ping_link): ?>
                <div class="small mt-2">
                    Test URL:
                    <a href="<?php echo htmlspecialchars($ping_link); ?>" target="_blank" rel="noopener">
                        Open Moolre Link
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="health-grid">
        <div class="health-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h4>PHP cURL Extension</h4>
                    <div class="text-muted small">Required for Paystack and Moolre API calls.</div>
                </div>
                <span class="health-status <?php echo $curl_loaded ? 'ok' : 'fail'; ?>">
                    <?php echo $curl_loaded ? 'OK' : 'Missing'; ?>
                </span>
            </div>
            <div class="health-list">
                <?php if (!$curl_loaded): ?>
                    Enable <code>php_curl</code> in your server PHP configuration.
                <?php else: ?>
                    cURL is loaded and ready.
                <?php endif; ?>
            </div>
        </div>

        <div class="health-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h4>Moolre Core Credentials</h4>
                    <div class="text-muted small">User, Pubkey, and Account Number.</div>
                </div>
                <span class="health-status <?php echo $moolre_configured ? 'ok' : 'fail'; ?>">
                    <?php echo $moolre_configured ? 'OK' : 'Missing'; ?>
                </span>
            </div>
            <div class="health-list">
                <?php if ($moolre_configured): ?>
                    Core Moolre keys are configured.
                <?php else: ?>
                    Missing: <?php echo htmlspecialchars(implode(', ', $missing)); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="health-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h4>Moolre Optional Keys</h4>
                    <div class="text-muted small">Used for advanced payouts/webhooks.</div>
                </div>
                <span class="health-status <?php echo empty($optional_missing) ? 'ok' : 'warn'; ?>">
                    <?php echo empty($optional_missing) ? 'OK' : 'Optional'; ?>
                </span>
            </div>
            <div class="health-list">
                <?php if (empty($optional_missing)): ?>
                    All optional Moolre keys are present.
                <?php else: ?>
                    Missing: <?php echo htmlspecialchars(implode(', ', $optional_missing)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Summary</h5>
            <span class="text-muted small">Checked at <?php echo htmlspecialchars($checked_at); ?></span>
        </div>
        <div class="card-body">
            <p class="mb-2">
                <?php if ($curl_loaded && $moolre_configured): ?>
                    Everything required for Moolre checkout is ready.
                <?php else: ?>
                    Fix the items above to enable Moolre checkout.
                <?php endif; ?>
            </p>
            <a class="btn btn-outline" href="health-check.php">
                <i class="fas fa-sync-alt me-1"></i> Re-run Health Check
            </a>
            <a class="btn btn-primary ms-2" href="settings.php">
                <i class="fas fa-cog me-1"></i> Open Settings
            </a>
            <form method="post" class="d-inline-block ms-2">
                <input type="hidden" name="action" value="ping_moolre">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button class="btn btn-outline" type="submit">
                    <i class="fas fa-satellite-dish me-1"></i> Test Moolre API
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>
