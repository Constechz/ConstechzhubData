<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureResultCheckerTables();

if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

if (isLoggedIn() && isset($_SESSION['user_role']) && isCustomerAccountRole($_SESSION['user_role'])) {
    header('Location: ' . SITE_URL . '/customer/result-checker.php?store=' . urlencode($store_slug));
    exit();
}

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
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
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
$enabled_gateways = getEnabledPaymentGateways();
$enabled_gateways = array_values(array_filter($enabled_gateways, function ($gateway) {
    return in_array($gateway, ['paystack', 'moolre'], true);
}));
if (empty($enabled_gateways)) {
    $enabled_gateways = ['paystack'];
}
if (!in_array($active_gateway, $enabled_gateways, true)) {
    $active_gateway = $enabled_gateways[0];
}
$gateway_labels = [
    'paystack' => 'Paystack',
    'moolre' => 'Moolre',
];
$guest_init_endpoints = [
    'paystack' => '../api/guest_result_checker_paystack_init.php',
    'moolre' => '../api/guest_result_checker_moolre_init.php',
];
$has_gateway_choice = count($enabled_gateways) > 1;
$gateway_label = $gateway_labels[$active_gateway] ?? ucfirst($active_gateway);

$settings = [
    'bece_price' => 17.00,
    'wassce_price' => 17.00,
    'bece_enabled' => 0,
    'wassce_enabled' => 0
];
$settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
    $settings = array_merge($settings, $settings_row);
}

$bece_enabled = ((int) $settings['bece_enabled'] === 1) || ((float) $settings['bece_price'] > 0);
$wassce_enabled = ((int) $settings['wassce_enabled'] === 1) || ((float) $settings['wassce_price'] > 0);

$bece_admin_price = (float) $settings['bece_price'];
$wassce_admin_price = (float) $settings['wassce_price'];
$bece_price = $bece_admin_price;
$wassce_price = $wassce_admin_price;

if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('agent_result_checker_pricing')) {
    $has_is_active = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'is_active');
    $has_updated_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'updated_at');
    $has_created_at = function_exists('dbh_table_has_column') && dbh_table_has_column('agent_result_checker_pricing', 'created_at');

    $where = "WHERE agent_id = ?";
    if ($has_is_active) {
        $where .= " AND is_active = 1";
    }
    $orderBy = '';
    if ($has_updated_at) {
        $orderBy = ' ORDER BY updated_at DESC';
    } elseif ($has_created_at) {
        $orderBy = ' ORDER BY created_at DESC';
    }

    $stmt = $db->prepare("SELECT card_type, custom_price FROM agent_result_checker_pricing {$where}{$orderBy}");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = strtoupper((string) ($row['card_type'] ?? ''));
            $custom_price = (float) ($row['custom_price'] ?? 0);
            if ($type === 'BECE' && $custom_price >= $bece_admin_price) {
                $bece_price = $custom_price;
            } elseif ($type === 'WASSCE' && $custom_price >= $wassce_admin_price) {
                $wassce_price = $custom_price;
            }
        }
        $stmt->close();
    }
}

$available_counts = ['BECE' => 0, 'WASSCE' => 0];
$count_rs = $db->query("
    SELECT card_type, COUNT(*) AS total_count
    FROM result_checker_cards
    WHERE status = 'available'
    GROUP BY card_type
");
if ($count_rs) {
    while ($row = $count_rs->fetch_assoc()) {
        $type = strtoupper((string) ($row['card_type'] ?? ''));
        if (isset($available_counts[$type])) {
            $available_counts[$type] = (int) ($row['total_count'] ?? 0);
        }
    }
}
?>
<?php
$page_title = 'Result Checker Checkout';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .guest-shell {
        max-width: 760px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .guest-card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        padding: 2rem;
        box-shadow: var(--store-shadow-soft);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .guest-card h1 {
        margin: 0 0 0.6rem;
        font-size: 2.2rem;
        color: var(--store-ink);
    }
    .guest-card p {
        margin: 0 0 1.5rem;
        color: var(--store-muted);
        line-height: 1.55;
    }
    .guest-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
        color: var(--store-muted);
    }
    .guest-meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
    }
    .price-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin: 1.25rem 0 1.5rem;
    }
    .price-card {
        border: 1px solid var(--store-border);
        border-radius: 16px;
        padding: 1rem;
        background: var(--store-chip-bg);
        box-shadow: var(--store-shadow-soft);
    }
    .price-card strong {
        display: block;
        margin-bottom: 0.25rem;
        color: var(--store-ink);
        font-size: 1.1rem;
    }
    .price-card span {
        color: var(--success-color);
        font-weight: 750;
        font-size: 1.25rem;
        font-family: 'Outfit', sans-serif;
    }
    .guest-form .form-group {
        margin-bottom: 1.25rem;
    }
    .guest-form .form-label {
        color: var(--store-ink);
        font-weight: 600;
        font-size: 0.9rem;
    }
    .checkout-total {
        margin-top: 1rem;
        border: 1px solid var(--store-border);
        border-radius: 14px;
        padding: 1rem 1.25rem;
        background: var(--store-chip-bg);
        color: var(--success-color);
        font-weight: 700;
        font-size: 1.1rem;
    }
    .guest-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }
    .guest-actions .btn {
        flex: 1 1 190px;
        justify-content: center;
        min-height: 48px;
        border-radius: 14px;
    }
    .guest-note {
        margin-top: 1.25rem;
        font-size: 0.88rem;
        color: var(--store-muted);
        line-height: 1.5;
    }
    .spinner {
        width: 1rem;
        height: 1rem;
        border-radius: 999px;
        border: 2px solid rgba(255, 255, 255, 0.45);
        border-top-color: #fff;
        display: inline-block;
        animation: cardSpin 0.8s linear infinite;
        margin-right: 0.5rem;
    }
    @keyframes cardSpin {
        to { transform: rotate(360deg); }
    }
    @media (max-width: 640px) {
        .guest-card { padding: 1.25rem; }
    }
</style>
    <div class="guest-shell">
        <div class="guest-card">
            <h1>Guest Result Checker Checkout</h1>
            <p>Buy BECE or WASSCE checker cards in minutes from <?php echo htmlspecialchars($store['store_name']); ?>.</p>

            <div class="guest-meta">
                <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($store['store_name']); ?></span>
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($store['agent_name']); ?></span>
                <span><i class="fas fa-credit-card"></i> Secure gateway checkout</span>
            </div>

            <div class="price-grid">
                <div class="price-card">
                    <strong>BECE</strong>
                    <span><?php echo CURRENCY . ' ' . number_format($bece_price, 2); ?></span>
                    <div class="checkout-meta"><?php echo (int) $available_counts['BECE']; ?> available</div>
                </div>
                <div class="price-card">
                    <strong>WASSCE</strong>
                    <span><?php echo CURRENCY . ' ' . number_format($wassce_price, 2); ?></span>
                    <div class="checkout-meta"><?php echo (int) $available_counts['WASSCE']; ?> available</div>
                </div>
            </div>

            <div class="alert alert-danger" id="guestCheckerError" style="display:none;"></div>

            <form method="post" class="guest-form" id="guestCheckerForm">
                <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">

                <div class="form-group">
                    <label class="form-label" for="guestEmail">Email Address</label>
                    <input id="guestEmail" type="email" name="email" class="form-control" placeholder="you@example.com" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="guestPhone">SMS Phone Number</label>
                    <input id="guestPhone" type="tel" name="phone" class="form-control" placeholder="0241234567" required>
                    <div class="checkout-meta">PIN/serial details are sent to this phone and your email.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="guestCardType">Card Type</label>
                    <select id="guestCardType" name="card_type" class="form-control" required>
                        <option value="">Choose card type</option>
                        <?php if ($bece_enabled): ?>
                            <option value="BECE">BECE (<?php echo (int) $available_counts['BECE']; ?> available)</option>
                        <?php endif; ?>
                        <?php if ($wassce_enabled): ?>
                            <option value="WASSCE">WASSCE (<?php echo (int) $available_counts['WASSCE']; ?> available)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="guestQuantity">Quantity</label>
                    <input id="guestQuantity" type="number" min="1" step="1" value="1" name="quantity" class="form-control" required>
                    <div class="checkout-meta" id="guestStockHint">Enter the number of cards you want to buy.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="guestGatewaySelect">Payment Gateway</label>
                    <select id="guestGatewaySelect" name="gateway" class="form-control" required>
                        <?php foreach ($enabled_gateways as $gateway): ?>
                            <option value="<?php echo htmlspecialchars($gateway); ?>" <?php echo $gateway === $active_gateway ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gateway_labels[$gateway] ?? ucfirst($gateway)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($has_gateway_choice): ?>
                        <div class="checkout-meta">Choose your preferred payment provider.</div>
                    <?php else: ?>
                        <div class="checkout-meta">Only one gateway is currently enabled by admin settings.</div>
                    <?php endif; ?>
                </div>

                <div class="checkout-total" id="guestCheckerTotal">Estimated total: <?php echo CURRENCY; ?> 0.00</div>

                <div class="guest-actions">
                    <button type="submit" class="btn btn-primary" id="guestCheckerBtn">
                        <i class="fas fa-credit-card"></i> Pay with <?php echo htmlspecialchars($gateway_label); ?>
                    </button>
                    <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Store
                    </a>
                </div>
                <div class="guest-note">
                    A customer account is auto-created for first-time guests. Login details are sent to your email.
                </div>
            </form>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script>
        const checkerPrices = {
            BECE: <?php echo json_encode($bece_price); ?>,
            WASSCE: <?php echo json_encode($wassce_price); ?>
        };
        const checkerStock = {
            BECE: <?php echo (int) $available_counts['BECE']; ?>,
            WASSCE: <?php echo (int) $available_counts['WASSCE']; ?>
        };

        const guestForm = document.getElementById('guestCheckerForm');
        const guestBtn = document.getElementById('guestCheckerBtn');
        const guestErr = document.getElementById('guestCheckerError');
        const cardTypeEl = document.getElementById('guestCardType');
        const qtyEl = document.getElementById('guestQuantity');
        const gatewayEl = document.getElementById('guestGatewaySelect');
        const totalEl = document.getElementById('guestCheckerTotal');
        const stockHintEl = document.getElementById('guestStockHint');
        const gatewayLabels = <?php echo json_encode($gateway_labels); ?>;
        const gatewayEndpoints = <?php echo json_encode($guest_init_endpoints); ?>;
        const defaultGateway = <?php echo json_encode($active_gateway); ?>;

        function showError(msg) {
            if (!guestErr) return;
            guestErr.textContent = msg;
            guestErr.style.display = 'block';
        }

        function clearError() {
            if (!guestErr) return;
            guestErr.style.display = 'none';
        }

        function getSelectedGateway() {
            if (gatewayEl && gatewayEl.value) {
                return String(gatewayEl.value);
            }
            return String(defaultGateway || 'paystack');
        }

        function getGatewayLabel(gateway) {
            return gatewayLabels[gateway] || gateway || 'Gateway';
        }

        function updateGatewayButtonLabel() {
            if (!guestBtn) return;
            const selectedGateway = getSelectedGateway();
            guestBtn.innerHTML = '<i class="fas fa-credit-card"></i> Pay with ' + getGatewayLabel(selectedGateway);
        }

        function updateEstimate() {
            const type = cardTypeEl.value;
            const qty = parseInt(qtyEl.value, 10) || 1;
            const price = checkerPrices[type] || 0;
            const total = price * Math.max(1, qty);
            totalEl.textContent = 'Estimated total: ' + <?php echo json_encode(CURRENCY . ' '); ?> + total.toFixed(2);

            if (type) {
                const stock = checkerStock[type] || 0;
                if (qty > stock) {
                    stockHintEl.textContent = 'Only ' + stock + ' ' + type + ' cards available.';
                } else {
                    stockHintEl.textContent = stock + ' ' + type + ' cards available.';
                }
            } else {
                stockHintEl.textContent = 'Enter the number of cards you want to buy.';
            }
        }

        cardTypeEl.addEventListener('change', updateEstimate);
        qtyEl.addEventListener('input', updateEstimate);
        if (gatewayEl) {
            gatewayEl.addEventListener('change', function() {
                clearError();
                updateGatewayButtonLabel();
            });
        }
        updateEstimate();
        updateGatewayButtonLabel();

        guestForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            clearError();

            const formData = new FormData(guestForm);
            const payload = {
                store_slug: <?php echo json_encode($store_slug); ?>,
                email: (formData.get('email') || '').toString().trim(),
                phone: (formData.get('phone') || '').toString().trim(),
                card_type: (formData.get('card_type') || '').toString().trim(),
                quantity: parseInt((formData.get('quantity') || '1').toString(), 10) || 1,
                gateway: getSelectedGateway()
            };

            if (!payload.email || !payload.phone || !payload.card_type) {
                showError('Please fill in all required fields.');
                return;
            }
            if (payload.quantity < 1) {
                showError('Quantity must be at least 1.');
                return;
            }

            const stock = checkerStock[payload.card_type] || 0;
            if (payload.quantity > stock) {
                showError('Only ' + stock + ' cards are currently available for ' + payload.card_type + '.');
                return;
            }

            guestBtn.disabled = true;
            guestBtn.innerHTML = '<span class="spinner"></span> Redirecting...';

            try {
                const endpoint = gatewayEndpoints[payload.gateway] || gatewayEndpoints[defaultGateway];
                if (!endpoint) {
                    throw new Error('No payment endpoint configured for selected gateway.');
                }

                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success' && data.data && data.data.authorization_url) {
                    window.location.href = data.data.authorization_url;
                    return;
                }
                showError(data.message || 'Failed to initialize payment.');
            } catch (err) {
                showError('Network error. Please try again.');
            } finally {
                guestBtn.disabled = false;
                updateGatewayButtonLabel();
            }
        });
    </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
