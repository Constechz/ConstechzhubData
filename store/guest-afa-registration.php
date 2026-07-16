<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureAfaRegistrationTables();

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

$current_user = isLoggedIn() ? getCurrentUser() : null;
$current_role = normalizeUserRole($current_user['role'] ?? ($_SESSION['user_role'] ?? ''));
if ($current_role !== '' && isset($_SESSION['user_role']) && normalizeUserRole($_SESSION['user_role']) !== $current_role) {
    setSessionUserRole($current_role);
}
$is_logged_customer = isCustomerAccountRole($current_role);

$stmt = $db->prepare("SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name, u.email AS agent_email FROM agent_stores ast JOIN users u ON ast.agent_id = u.id WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active' LIMIT 1");
$stmt->bind_param('s', $store_slug);
$stmt->execute();
$store = $stmt->get_result()->fetch_assoc();
if (!$store) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$settings = [
    'guest_price' => 0,
    'is_enabled' => 0,
    'allow_guest_paystack' => 1,
    'allow_guest_moolre' => 1,
];
$settings_rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && ($settings_row = $settings_rs->fetch_assoc())) {
    $settings = array_merge($settings, $settings_row);
}

$guest_price = round((float) ($settings['guest_price'] ?? 0), 2);
$service_enabled = ((int) ($settings['is_enabled'] ?? 0) === 1) || ($guest_price > 0);
$active_gateway = getActivePaymentGateway();
$gateway_labels = [
    'paystack' => 'Paystack',
    'moolre' => 'Moolre',
];
$guest_init_endpoints = [
    'paystack' => '../api/guest_afa_registration_paystack_init.php',
    'moolre' => '../api/guest_afa_registration_moolre_init.php',
];

$admin_enabled_gateways = getEnabledPaymentGateways();
$admin_enabled_gateways = array_values(array_filter($admin_enabled_gateways, function ($gateway) {
    return in_array($gateway, ['paystack', 'moolre'], true);
}));

$enabled_gateways = [];
foreach ($admin_enabled_gateways as $gateway) {
    if ($gateway === 'paystack' && (int) ($settings['allow_guest_paystack'] ?? 1) !== 1) {
        continue;
    }
    if ($gateway === 'moolre' && (int) ($settings['allow_guest_moolre'] ?? 1) !== 1) {
        continue;
    }
    $enabled_gateways[] = $gateway;
}

$gateway_allowed = !empty($enabled_gateways);
if ($gateway_allowed && !in_array($active_gateway, $enabled_gateways, true)) {
    $active_gateway = $enabled_gateways[0];
}
$has_gateway_choice = count($enabled_gateways) > 1;
$gateway_label = $gateway_labels[$active_gateway] ?? ucfirst($active_gateway);
?>
<?php
$page_title = 'Guest AFA Registration';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .shell {
        max-width: 620px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        padding: 0;
        overflow: hidden;
        box-shadow: var(--store-shadow-soft);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .title {
        background: var(--store-accent);
        color: #fff;
        margin: 0;
        padding: 1.25rem 1.5rem;
        border-radius: 0;
        font-weight: 700;
        font-size: 1.15rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--store-border);
    }
    .title a {
        color: #fff !important;
        opacity: 0.8;
        transition: opacity 0.2s ease;
    }
    .title a:hover {
        opacity: 1;
    }
    .meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        padding: 1.5rem;
        margin: 0;
    }
    .meta .chip {
        border: 1px solid var(--store-border);
        border-radius: 12px;
        padding: 0.65rem 0.85rem;
        background: var(--store-chip-bg);
        font-size: 0.88rem;
        color: var(--store-chip-text);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .meta .chip i {
        color: var(--store-accent);
    }
    #guestAfaForm {
        padding: 0 1.5rem 1.5rem;
        display: grid;
        gap: 1rem;
    }
    .form-group {
        margin-bottom: 0;
        display: grid;
        gap: 0.45rem;
    }
    .form-group label {
        display: block;
        margin-bottom: 0;
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--store-ink);
    }
    .hint {
        color: var(--store-muted);
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }
    .footer-link {
        margin-top: 1.5rem;
        text-align: center;
    }
    .footer-link a {
        color: var(--store-accent);
        text-decoration: none;
        font-weight: 600;
    }
    .footer-link a:hover {
        text-decoration: underline;
    }
</style>
<div class="shell">
    <div class="card">
        <div class="title">
            <span>MTN AFA Bundle</span>
            <a href="index.php?store=<?php echo urlencode($store_slug); ?>" style="color:#111827;"><i class="fas fa-times"></i></a>
        </div>

        <?php if (!$service_enabled): ?>
            <div class="alert alert-warning">AFA registration is currently unavailable.</div>
        <?php elseif (!$gateway_allowed): ?>
            <div class="alert alert-warning">Guest online checkout is unavailable because no gateway is enabled for this service.</div>
        <?php elseif ($guest_price <= 0): ?>
            <div class="alert alert-warning">Guest price is not configured.</div>
        <?php endif; ?>
        <?php if ($is_logged_customer): ?>
            <div class="alert alert-info" style="margin-bottom:.8rem;">
                You are logged in as customer. You can continue here as guest or
                <a href="../customer/afa-registration.php?store=<?php echo urlencode($store_slug); ?>">open customer AFA page</a>.
            </div>
        <?php endif; ?>

        <div class="meta">
            <div class="chip"><i class="fas fa-store"></i> <?php echo htmlspecialchars($store['store_name']); ?></div>
            <div class="chip"><i class="fas fa-user"></i> <?php echo htmlspecialchars($store['agent_name']); ?></div>
            <div class="chip"><i class="fas fa-money-bill-wave"></i> <?php echo htmlspecialchars(formatCurrency($guest_price, CURRENCY)); ?></div>
            <div class="chip"><i class="fas fa-credit-card"></i> <?php echo $has_gateway_choice ? 'Choose Gateway' : htmlspecialchars($gateway_label); ?></div>
        </div>

        <form id="guestAfaForm" enctype="multipart/form-data">
            <input type="hidden" name="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
            <div class="form-group">
                <label>Beneficiary Fullname(required)*</label>
                <input class="form-control" name="beneficiary_name" required>
            </div>
            <div class="form-group">
                <label>Email*</label>
                <input class="form-control" type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Number For Registration(required)*</label>
                <input class="form-control" name="phone" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" required>
            </div>
            <div class="form-group">
                <label>Ghana Card Number*</label>
                <input class="form-control" name="ghana_card_number" maxlength="13" minlength="13" pattern="[A-Za-z0-9]{13}" style="text-transform:uppercase;" required>
            </div>
            <div class="form-group">
                <label>Location*</label>
                <input class="form-control" name="location" required>
            </div>
            <div class="form-group">
                <label>Region</label>
                <select class="form-control" name="region" required>
                    <option value="">Select Region</option>
                    <option>Ashanti</option><option>Greater Accra</option><option>Northern</option><option>Upper East</option><option>Upper West</option><option>Central</option><option>Eastern</option><option>Western</option><option>Western North</option><option>Volta</option><option>Oti</option><option>Bono</option><option>Bono East</option><option>Ahafo</option><option>North East</option><option>Savannah</option>
                </select>
            </div>
            <div class="form-group">
                <label>Occupation*</label>
                <input class="form-control" name="occupation" required>
            </div>
            <div class="form-group">
                <label>Date of Birth*</label>
                <input class="form-control" type="date" name="date_of_birth" required>
            </div>

            <?php if ($gateway_allowed): ?>
                <div class="form-group">
                    <label>Payment Gateway*</label>
                    <?php if ($has_gateway_choice): ?>
                        <select class="form-control" name="gateway" id="gatewaySelect" required>
                            <?php foreach ($enabled_gateways as $gateway): ?>
                                <option value="<?php echo htmlspecialchars($gateway); ?>" <?php echo $gateway === $active_gateway ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gateway_labels[$gateway] ?? ucfirst($gateway)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="gateway" id="gatewaySelect" value="<?php echo htmlspecialchars($active_gateway); ?>">
                        <input class="form-control" value="<?php echo htmlspecialchars($gateway_label); ?>" readonly>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-success btn-wide" <?php echo (!$service_enabled || !$gateway_allowed || $guest_price <= 0) ? 'disabled' : ''; ?>>Pay With <?php echo htmlspecialchars($gateway_label); ?></button>
            <div class="hint" id="statusBox"></div>
        </form>

        <div class="footer-link"><a href="index.php?store=<?php echo urlencode($store_slug); ?>">Back to store</a></div>
    </div>
</div>

<script>
const form = document.getElementById('guestAfaForm');
const statusBox = document.getElementById('statusBox');
const gatewaySelect = document.getElementById('gatewaySelect');
const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
const gatewayLabels = <?php echo json_encode($gateway_labels); ?>;
const gatewayEndpoints = <?php echo json_encode($guest_init_endpoints); ?>;
const defaultGateway = <?php echo json_encode($active_gateway); ?>;

function getSelectedGateway() {
    if (gatewaySelect && gatewaySelect.value) {
        return String(gatewaySelect.value);
    }
    return String(defaultGateway || 'paystack');
}

function getGatewayLabel(gateway) {
    return gatewayLabels[gateway] || gateway || 'Gateway';
}

function updateSubmitButtonLabel() {
    if (!submitBtn) {
        return;
    }
    submitBtn.textContent = 'Pay With ' + getGatewayLabel(getSelectedGateway());
}

if (form) {
    const phoneInput = form.querySelector('input[name="phone"]');
    const cardInput = form.querySelector('input[name="ghana_card_number"]');

    const normalizePhone = () => {
        if (!phoneInput) {
            return;
        }
        phoneInput.value = phoneInput.value.replace(/\D+/g, '').slice(0, 15);
    };

    const normalizeCard = () => {
        if (!cardInput) {
            return;
        }
        cardInput.value = cardInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 13);
    };

    if (phoneInput) {
        phoneInput.addEventListener('input', normalizePhone);
    }
    if (cardInput) {
        cardInput.addEventListener('input', normalizeCard);
    }
    if (gatewaySelect) {
        gatewaySelect.addEventListener('change', () => {
            statusBox.textContent = '';
            updateSubmitButtonLabel();
        });
    }
    updateSubmitButtonLabel();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        statusBox.textContent = 'Initializing payment...';
        statusBox.style.color = '#e2e8f0';

        normalizePhone();
        normalizeCard();
        if (!/^[0-9]{10,15}$/.test(phoneInput ? phoneInput.value : '')) {
            statusBox.textContent = 'Number for registration must contain digits only (10-15 digits).';
            statusBox.style.color = '#fecaca';
            return;
        }
        if (!/^[A-Z0-9]{13}$/.test(cardInput ? cardInput.value : '')) {
            statusBox.textContent = 'Ghana Card number must be exactly 13 alphanumeric characters.';
            statusBox.style.color = '#fecaca';
            return;
        }

        const payload = new FormData(form);
        const selectedGateway = getSelectedGateway();
        payload.set('gateway', selectedGateway);
        try {
            const endpoint = gatewayEndpoints[selectedGateway] || gatewayEndpoints[defaultGateway];
            if (!endpoint) {
                throw new Error('No payment endpoint configured for selected gateway.');
            }

            const res = await fetch(endpoint, {
                method: 'POST',
                body: payload
            });
            const data = await res.json();
            if (!res.ok || data.status !== 'success') {
                throw new Error(data.message || 'Failed to initialize payment.');
            }
            const authUrl = data.data && data.data.authorization_url ? data.data.authorization_url : '';
            if (!authUrl) {
                throw new Error('Missing authorization URL from gateway.');
            }
            window.location.href = authUrl;
        } catch (err) {
            statusBox.textContent = err.message || 'Failed to initialize payment.';
            statusBox.style.color = '#fecaca';
        } finally {
            updateSubmitButtonLabel();
        }
    });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
