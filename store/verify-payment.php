<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureGuestCheckoutSchema();

if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = sanitize($_GET['store'] ?? $_POST['store'] ?? '');
if ($store_slug === '') {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$stmt = $db->prepare("
    SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
    LIMIT 1
");
$stmt->bind_param('s', $store_slug);
$stmt->execute();
$store = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$store) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$csrf = generateCSRF();
$store_home_url = SITE_URL . '/store/index.php?store=' . urlencode($store_slug);
$store_status_url = SITE_URL . '/store/reference.php?store=' . urlencode($store_slug);
?>
<?php
$page_title = 'Verify Payment';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .verify-shell {
        width: min(560px, calc(100% - 2rem));
        margin: 0 auto;
        padding: 2rem 0 3rem;
    }
    .verify-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .verify-link {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        color: var(--store-chip-text);
        font-weight: 700;
        text-decoration: none;
    }
    .verify-card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        box-shadow: var(--store-shadow-soft);
        overflow: hidden;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .verify-head {
        padding: 2rem;
        border-bottom: 1px solid var(--store-border);
    }
    .verify-kicker {
        color: var(--store-accent);
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }
    .verify-head h1 {
        margin: 0.35rem 0;
        font-size: 1.85rem;
        line-height: 1.1;
        color: var(--store-ink);
    }
    .verify-head p {
        margin: 0;
        color: var(--store-muted);
        line-height: 1.6;
        font-size: 0.95rem;
    }
    .verify-form {
        display: grid;
        gap: 1.25rem;
        padding: 2rem;
    }
    .verify-form label {
        display: block;
        margin-bottom: 0.45rem;
        font-weight: 700;
        color: var(--store-ink);
        font-size: 0.9rem;
    }
    .verify-actions {
        display: grid;
        gap: 0.7rem;
        margin-top: 0.75rem;
    }
    .verify-status {
        display: none;
        padding: 1rem 1.25rem;
        border-radius: 14px;
        font-size: 0.9rem;
        line-height: 1.5;
        border: 1px solid transparent;
    }
    .verify-status.info { 
        display: block; 
        background: rgba(59, 130, 246, 0.08); 
        color: var(--store-accent); 
        border-color: rgba(59, 130, 246, 0.15);
    }
    .verify-status.success { 
        display: block; 
        background: rgba(16, 185, 129, 0.08); 
        color: var(--success-color); 
        border-color: rgba(16, 185, 129, 0.15);
    }
    .verify-status.error { 
        display: block; 
        background: rgba(239, 68, 68, 0.08); 
        color: var(--store-accent-cool); 
        border-color: rgba(239, 68, 68, 0.15);
    }
    .spinner {
        width: 1rem;
        height: 1rem;
        border-radius: 999px;
        border: 2px solid rgba(255, 255, 255, 0.45);
        border-top-color: #fff;
        display: inline-block;
        animation: verifySpin 0.8s linear infinite;
        margin-right: 0.5rem;
    }
    @keyframes verifySpin {
        to { transform: rotate(360deg); }
    }
</style>
    <main class="verify-shell">
        <div class="verify-top">
            <a class="verify-link" href="<?php echo htmlspecialchars($store_home_url); ?>"><i class="fas fa-arrow-left"></i> Store</a>
            <a class="verify-link" href="<?php echo htmlspecialchars($store_status_url); ?>"><i class="fas fa-search"></i> Check Status</a>
        </div>

        <section class="verify-card">
            <div class="verify-head">
                <div class="verify-kicker"><?php echo htmlspecialchars($store['store_name']); ?></div>
                <h1>Verify Payment</h1>
                <p>Use this only when Paystack deducted your money but the order did not go through. The email is matched to your stored payment before Paystack is asked to confirm it.</p>
            </div>

            <form class="verify-form" id="verifyPaymentForm">
                <div id="verifyPaymentStatus" class="verify-status"></div>

                <div>
                    <label for="verifyEmail">Email Address</label>
                    <input type="email" class="form-control" id="verifyEmail" autocomplete="email" placeholder="Email used during payment" required>
                    <small class="field-help">This must be the same email entered at checkout.</small>
                </div>

                <div>
                    <label for="verifyPhone">Recipient Number</label>
                    <input type="tel" class="form-control" id="verifyPhone" autocomplete="tel" placeholder="0241234567" required>
                </div>

                <div>
                    <label for="verifyReference">Paystack Reference <span style="color:var(--store-muted);font-weight:600;">optional</span></label>
                    <input type="text" class="form-control" id="verifyReference" placeholder="PAY_...">
                    <small class="field-help">Leave this blank if you do not have the reference. If more than one payment matches, you will be asked to enter it.</small>
                </div>

                <div class="verify-actions">
                    <button type="submit" class="btn btn-primary" id="verifyPaymentBtn">
                        <i class="fas fa-sync-alt"></i> Verify Payment
                    </button>
                </div>
            </form>
        </section>
    </main>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script>
        const verifyForm = document.getElementById('verifyPaymentForm');
        const verifyBtn = document.getElementById('verifyPaymentBtn');
        const verifyStatus = document.getElementById('verifyPaymentStatus');
        const verifyStorageKey = 'guestPendingCheckout:' + <?php echo json_encode($store_slug); ?>;

        function showVerifyStatus(message, type) {
            verifyStatus.textContent = message || '';
            verifyStatus.className = 'verify-status ' + (type || 'info');
        }

        try {
            const saved = JSON.parse(sessionStorage.getItem(verifyStorageKey) || '{}');
            if (saved.email) document.getElementById('verifyEmail').value = saved.email;
            if (saved.phone) document.getElementById('verifyPhone').value = saved.phone;
            if (saved.reference) document.getElementById('verifyReference').value = saved.reference;
        } catch (error) {}

        verifyForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const payload = {
                store_slug: <?php echo json_encode($store_slug); ?>,
                email: document.getElementById('verifyEmail').value.trim(),
                phone: document.getElementById('verifyPhone').value.trim(),
                reference: document.getElementById('verifyReference').value.trim()
            };

            if (!payload.email || !payload.phone) {
                showVerifyStatus('Enter the email address and recipient number used for payment.', 'error');
                return;
            }

            verifyBtn.disabled = true;
            verifyBtn.innerHTML = '<span class="spinner"></span> Verifying...';
            showVerifyStatus('Checking Paystack payment status...', 'info');

            try {
                sessionStorage.setItem(verifyStorageKey, JSON.stringify(payload));
                const res = await fetch('../api/recover_guest_paystack_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': <?php echo json_encode($csrf); ?>
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (!res.ok || !data || data.status !== 'success') {
                    throw new Error(data && data.message ? data.message : 'Unable to verify this payment right now.');
                }

                if (data.redirect_path) {
                    showVerifyStatus(data.message || 'Payment verified. Opening order status...', 'success');
                    window.location.href = <?php echo json_encode(SITE_URL); ?> + data.redirect_path;
                    return;
                }

                showVerifyStatus(data.message || 'Verification completed.', data.transaction_status === 'failed' ? 'error' : 'info');
            } catch (error) {
                showVerifyStatus(error && error.message ? error.message : 'Unable to verify this payment right now.', 'error');
            } finally {
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Verify Payment';
            }
        });
    </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
