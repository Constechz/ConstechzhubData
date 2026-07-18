<?php
require_once __DIR__ . '/../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

if (isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer') {
    header('Location: ' . SITE_URL . '/customer/buy-data.php?store=' . urlencode($store_slug));
    exit();
}

// Fetch store + agent info for branding (cache in session for faster reloads)
$store = null;
if (isset($_SESSION['store_cache'][$store_slug])) {
    $cached = $_SESSION['store_cache'][$store_slug];
    if (is_array($cached) && !empty($cached['data']) && !empty($cached['ts']) && (time() - (int) $cached['ts']) < 300) {
        $store = $cached['data'];
    }
}

if (!$store) {
    $stmt = $db->prepare("
        SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        header('HTTP/1.0 404 Not Found');
        include '../404.php';
        exit();
    }
    $store = $res->fetch_assoc();
    if (!isset($_SESSION['store_cache'])) {
        $_SESSION['store_cache'] = [];
    }
    $_SESSION['store_cache'][$store_slug] = [
        'data' => $store,
        'ts' => time()
    ];
}
$agent_id = (int) $store['agent_id'];
$active_gateway = getActivePaymentGateway();
$gateway_label = $active_gateway === 'moolre' ? 'Moolre' : 'Paystack';
$guest_init_endpoint = $active_gateway === 'moolre' ? '../api/guest_moolre_init.php' : '../api/guest_paystack_init.php';

// Load packages for guest checkout
$packages = [];
$stmt = $db->prepare("
    SELECT dp.id, dp.name, dp.data_size,
           COALESCE(n.name, 'Unknown') AS network_name,
           COALESCE(acp.custom_price, pp.price, dp.price, 0) AS display_price
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'customer'
    WHERE dp.status = 'active'
    ORDER BY n.name, CAST(REGEXP_REPLACE(dp.data_size, '[^0-9.]', '') AS DECIMAL(10,2))
");
$stmt->bind_param("i", $agent_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $packages[] = $row;
}

$selected_package_id = (int) ($_GET['package_id'] ?? 0);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Please use the ' . $gateway_label . ' button to complete payment.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Guest Checkout</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        body {
            background: linear-gradient(135deg, #2E294E, #2E294E);
            color: #F1E9DA;
            min-height: 100vh;
        }
        .guest-shell {
            max-width: 720px;
            margin: 3rem auto;
            padding: 0 1rem;
        }
        .guest-card {
            background: rgba(46, 41, 78, 0.85);
            border: 1px solid rgba(241, 233, 218, 0.2);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(46, 41, 78, 0.35);
        }
        .guest-card h1 {
            margin: 0 0 0.75rem;
            font-size: 2rem;
        }
        .guest-card p {
            margin: 0 0 1.5rem;
            color: rgba(241, 233, 218, 0.72);
        }
        .guest-card,
        .guest-card h1 {
            color: #F1E9DA;
        }
        .guest-card .form-label {
            color: rgba(241, 233, 218, 0.85);
        }
        .guest-guide {
            margin: 1.25rem 0 1.75rem;
            padding: 1rem 1.25rem;
            border-radius: 14px;
            border: 1px solid rgba(241, 233, 218, 0.25);
            background: rgba(46, 41, 78, 0.55);
        }
        .guest-guide h3 {
            margin: 0 0 0.75rem;
            font-size: 1.05rem;
            color: #F1E9DA;
        }
        .guest-guide ol {
            margin: 0 0 0 1.1rem;
            padding: 0;
            color: rgba(241, 233, 218, 0.8);
            line-height: 1.6;
        }
        .guest-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            color: rgba(241, 233, 218, 0.7);
        }
        .guest-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .guest-form .form-group {
            margin-bottom: 1.25rem;
        }
        .guest-form .form-control,
        .guest-form select {
            width: 100%;
            background: rgba(46, 41, 78, 0.4);
            border: 1px solid rgba(241, 233, 218, 0.3);
            color: #F1E9DA;
        }
        .guest-form .form-control::placeholder {
            color: rgba(241, 233, 218, 0.65);
        }
        .guest-form select option {
            color: #2E294E;
        }
        .guest-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .guest-actions .btn.btn-outline {
            color: rgba(241, 233, 218, 0.9);
            border-color: rgba(241, 233, 218, 0.6);
            background: rgba(46, 41, 78, 0.15);
        }
        .guest-actions .btn.btn-outline:hover {
            color: #2E294E;
            background: rgba(241, 233, 218, 0.95);
        }
        .guest-actions .btn {
            flex: 1 1 180px;
            justify-content: center;
        }
        .guest-note {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: rgba(241, 233, 218, 0.65);
        }
        @media (max-width: 640px) {
            .guest-card {
                padding: 1.5rem;
            }
            .guest-card h1 {
                font-size: 1.6rem;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
</head>
<body>
    <div class="guest-shell">
        <div class="guest-card">
            <h1>Quick Guest Checkout</h1>
            <p>Skip full signup and start buying data from <?php echo htmlspecialchars($store['store_name']); ?> in minutes.</p>
            <div class="guest-meta">
                <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($store['store_name']); ?></span>
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($store['agent_name']); ?></span>
            </div>
            <div class="guest-guide">
                <h3>Quick Guide</h3>
                <ol>
                    <li>Enter your email and beneficiary number.</li>
                    <li>Select the data package you want.</li>
                    <li>Click â€œPay with <?php echo htmlspecialchars($gateway_label); ?>â€ and complete payment.</li>
                    <li>After payment, your order will be processed instantly.</li>
                </ol>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="alert alert-danger" id="guestPaystackError" style="display:none;"></div>

            <form method="post" class="guest-form" id="guestPaystackForm">
                <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="you@example.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Beneficiary Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="0241234567" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Select Data Package</label>
                    <select name="package_id" class="form-control" required>
                        <option value="">Choose a bundle</option>
                        <?php foreach ($packages as $package): ?>
                            <option value="<?php echo (int) $package['id']; ?>" <?php echo $selected_package_id === (int) $package['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($package['network_name']); ?> - <?php echo htmlspecialchars($package['data_size']); ?> (<?php echo formatCurrency((float) $package['display_price']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="guest-actions">
                    <button type="submit" class="btn btn-primary" id="guestPaystackBtn">
                        <i class="fas fa-credit-card"></i> Pay with <?php echo htmlspecialchars($gateway_label); ?>
                    </button>
                    <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Store
                    </a>
                </div>
                <div class="guest-note">
                    We create a lightweight account to track your order and send you a receipt. Login details will be sent to your email.
                </div>
            </form>
        </div>
    </div>
    <script>
        const guestForm = document.getElementById('guestPaystackForm');
        const guestBtn = document.getElementById('guestPaystackBtn');
        const guestError = document.getElementById('guestPaystackError');

        function ensureOrderConfirmModal() {
            if (window.__orderConfirmModalState) return window.__orderConfirmModalState;

            const styleId = 'order-confirm-modal-style';
            if (!document.getElementById(styleId)) {
                const style = document.createElement('style');
                style.id = styleId;
                style.textContent = `
                    .order-confirm-modal {
                        position: fixed;
                        inset: 0;
                        display: none;
                        align-items: center;
                        justify-content: center;
                        z-index: 12000;
                        padding: 1rem;
                    }
                    .order-confirm-modal.show { display: flex; }
                    .order-confirm-backdrop {
                        position: absolute;
                        inset: 0;
                        background: rgba(46, 41, 78, 0.55);
                    }
                    .order-confirm-dialog {
                        position: relative;
                        width: min(520px, 100%);
                        background: #F1E9DA;
                        border: 1px solid rgba(241, 233, 218, 0.35);
                        border-radius: 14px;
                        box-shadow: 0 20px 45px rgba(46, 41, 78, 0.25);
                        color: #2E294E;
                        overflow: hidden;
                    }
                    .order-confirm-header {
                        padding: 1rem 1.2rem 0.5rem;
                        font-weight: 700;
                        font-size: 1.05rem;
                    }
                    .order-confirm-subtitle {
                        padding: 0 1.2rem;
                        color: #541388;
                        font-size: 0.9rem;
                    }
                    .order-confirm-details {
                        margin: 0.9rem 1.2rem 0;
                        border: 1px solid #F1E9DA;
                        border-radius: 10px;
                        overflow: hidden;
                    }
                    .order-confirm-row {
                        display: flex;
                        justify-content: space-between;
                        gap: 1rem;
                        padding: 0.7rem 0.85rem;
                        border-bottom: 1px solid #F1E9DA;
                        font-size: 0.92rem;
                    }
                    .order-confirm-row:last-child { border-bottom: none; }
                    .order-confirm-row span:first-child { color: #541388; }
                    .order-confirm-row span:last-child { font-weight: 600; text-align: right; word-break: break-word; }
                    .order-confirm-actions {
                        display: flex;
                        gap: 0.75rem;
                        justify-content: flex-end;
                        padding: 1rem 1.2rem 1.1rem;
                    }
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-backdrop {
                        background: rgba(46, 41, 78, 0.72);
                    }
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-dialog {
                        background: #2E294E;
                        border-color: #2E294E;
                        color: #F1E9DA;
                    }
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-header,
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:last-child {
                        color: #F1E9DA;
                    }
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-subtitle,
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:first-child {
                        color: #F1E9DA;
                    }
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-details,
                    html[data-theme="dark"] .order-confirm-modal .order-confirm-row {
                        border-color: #2E294E;
                    }
                    html[data-theme="dark"] .order-confirm-modal .btn.btn-secondary,
                    html[data-theme="dark"] .order-confirm-modal .btn.btn-outline {
                        background: #2E294E;
                        border-color: #2E294E;
                        color: #F1E9DA;
                    }
                `;
                document.head.appendChild(style);
            }

            const modal = document.createElement('div');
            modal.className = 'order-confirm-modal';
            modal.setAttribute('aria-hidden', 'true');
            modal.innerHTML = `
                <div class="order-confirm-backdrop" data-close="1"></div>
                <div class="order-confirm-dialog" role="dialog" aria-modal="true" aria-label="Confirm order">
                    <div class="order-confirm-header" id="orderConfirmTitle">Confirm Order</div>
                    <div class="order-confirm-subtitle" id="orderConfirmSubtitle">Review details before continuing.</div>
                    <div class="order-confirm-details" id="orderConfirmDetails"></div>
                    <div class="order-confirm-actions">
                        <button type="button" class="btn btn-outline" id="orderConfirmCancelBtn">Cancel</button>
                        <button type="button" class="btn btn-primary" id="orderConfirmOkBtn">Continue</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            const state = {
                modal: modal,
                title: modal.querySelector('#orderConfirmTitle'),
                subtitle: modal.querySelector('#orderConfirmSubtitle'),
                details: modal.querySelector('#orderConfirmDetails'),
                cancelBtn: modal.querySelector('#orderConfirmCancelBtn'),
                okBtn: modal.querySelector('#orderConfirmOkBtn'),
                resolver: null
            };

            function close(result) {
                state.modal.classList.remove('show');
                state.modal.setAttribute('aria-hidden', 'true');
                if (state.resolver) {
                    const resolve = state.resolver;
                    state.resolver = null;
                    resolve(!!result);
                }
            }

            state.modal.addEventListener('click', function(event) {
                if (event.target && event.target.getAttribute('data-close') === '1') {
                    close(false);
                }
            });
            state.cancelBtn.addEventListener('click', function() { close(false); });
            state.okBtn.addEventListener('click', function() { close(true); });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && state.modal.classList.contains('show')) {
                    close(false);
                }
            });

            state.open = function(config) {
                if (state.resolver) {
                    const prev = state.resolver;
                    state.resolver = null;
                    prev(false);
                }
                state.title.textContent = config.title || 'Confirm Order';
                state.subtitle.textContent = config.subtitle || 'Review details before continuing.';
                state.okBtn.textContent = config.confirmText || 'Continue';
                state.cancelBtn.textContent = config.cancelText || 'Cancel';
                state.details.innerHTML = '';

                (config.details || []).forEach(function(item) {
                    const row = document.createElement('div');
                    row.className = 'order-confirm-row';
                    const label = document.createElement('span');
                    label.textContent = item.label || '';
                    const value = document.createElement('span');
                    value.textContent = item.value || '';
                    row.appendChild(label);
                    row.appendChild(value);
                    state.details.appendChild(row);
                });

                state.modal.classList.add('show');
                state.modal.setAttribute('aria-hidden', 'false');
                setTimeout(function() { state.okBtn.focus(); }, 0);
                return new Promise(function(resolve) {
                    state.resolver = resolve;
                });
            };

            window.__orderConfirmModalState = state;
            return state;
        }

        function openOrderConfirmModal(config) {
            return ensureOrderConfirmModal().open(config || {});
        }

        function showGuestError(message) {
            if (!guestError) return;
            guestError.textContent = message;
            guestError.style.display = 'block';
        }

        if (guestForm) {
            guestForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                if (!guestBtn) return;

                const formData = new FormData(guestForm);
                const payload = {
                    store_slug: '<?php echo htmlspecialchars($store_slug); ?>',
                    email: formData.get('email') || '',
                    phone: formData.get('phone') || '',
                    package_id: formData.get('package_id') || ''
                };

                if (!payload.email || !payload.phone || !payload.package_id) {
                    showGuestError('Please fill in all required fields.');
                    return;
                }

                const selectedPackageOption = guestForm.querySelector('select[name="package_id"] option:checked');
                const packageLabel = selectedPackageOption ? selectedPackageOption.textContent.trim() : 'Selected package';
                const confirmed = await openOrderConfirmModal({
                    title: 'Confirm Guest Order',
                    subtitle: 'Review order details before payment.',
                    confirmText: 'Continue to Payment',
                    details: [
                        { label: 'Email', value: payload.email },
                        { label: 'Recipient', value: payload.phone },
                        { label: 'Package', value: packageLabel }
                    ]
                });

                if (!confirmed) {
                    return;
                }

                guestBtn.disabled = true;
                guestBtn.innerHTML = '<span class="spinner"></span> Redirecting...';
                if (guestError) guestError.style.display = 'none';

                try {
                    const res = await fetch('<?php echo $guest_init_endpoint; ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();

                    if (data.status === 'success' && data.data && data.data.authorization_url) {
                        window.location.href = data.data.authorization_url;
                        return;
                    }

                    showGuestError(data.message || 'Failed to initialize payment.');
                } catch (err) {
                    showGuestError('Network error. Please try again.');
                } finally {
                    guestBtn.disabled = false;
                    guestBtn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with <?php echo htmlspecialchars($gateway_label); ?>';
                }
            });
        }
    </script>
</body>
</html>
