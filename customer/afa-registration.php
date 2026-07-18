<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

$store_slug = sanitize($_GET['store'] ?? '');

if (!isLoggedIn()) {
    if ($store_slug !== '') {
        header('Location: ' . SITE_URL . '/store/guest-afa-registration.php?store=' . urlencode($store_slug));
    } else {
        header('Location: ' . SITE_URL . '/login.php');
    }
    exit();
}

$current_user = getCurrentUser();
if (!$current_user) {
    if ($store_slug !== '') {
        header('Location: ' . SITE_URL . '/store/guest-afa-registration.php?store=' . urlencode($store_slug));
    } else {
        header('Location: ' . SITE_URL . '/login.php');
    }
    exit();
}

$current_role = normalizeUserRole($current_user['role'] ?? ($_SESSION['user_role'] ?? ''));
if ($current_role === '' && function_exists('refreshSessionUserRole')) {
    $current_role = normalizeUserRole(refreshSessionUserRole('customer'));
}
if ($current_role === 'user') {
    $current_role = 'customer';
}

if ($current_role === '') {
    // Legacy accounts may have empty role; treat as customer for customer routes.
    $current_role = 'customer';
}

if ($current_role !== '') {
    setSessionUserRole($current_role);
}

ensureAfaRegistrationTables();

$wallet_balance = getWalletBalance($current_user['id']);
$csrf_token = generateCSRF();
$flash = getFlashMessage();

$agent_id = 0;
$store_name = '';
$agent_name = '';

if ($store_slug !== '') {
    $stmt = $db->prepare("SELECT ast.agent_id, ast.store_name, u.full_name AS agent_name FROM agent_stores ast JOIN users u ON ast.agent_id = u.id WHERE ast.store_slug = ? AND ast.is_active = 1 AND u.status = 'active' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $store_slug);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $agent_id = (int) ($row['agent_id'] ?? 0);
            $store_name = (string) ($row['store_name'] ?? '');
            $agent_name = (string) ($row['agent_name'] ?? '');
        }
        $stmt->close();
    }
}
if ($agent_id <= 0) {
    $agent_id = (int) getLinkedAgentId($current_user['id']);
}

$settings = [
    'agent_price' => 0,
    'is_enabled' => 0,
    'allow_wallet_customer' => 1,
    'allow_gateway_customer' => 1,
];
$settings_rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && ($settings_row = $settings_rs->fetch_assoc())) {
    $settings = array_merge($settings, $settings_row);
}

$agent_base_price = round((float) ($settings['agent_price'] ?? 0), 2);
$service_enabled = ((int) ($settings['is_enabled'] ?? 0) === 1) || ($agent_base_price > 0);
$allow_wallet = ((int) ($settings['allow_wallet_customer'] ?? 1) === 1);
$allow_gateway = ((int) ($settings['allow_gateway_customer'] ?? 1) === 1);

$display_price = $agent_base_price;
if ($agent_id > 0 && function_exists('dbh_table_exists') && dbh_table_exists('agent_afa_registration_pricing')) {
    $stmt = $db->prepare("SELECT custom_price FROM agent_afa_registration_pricing WHERE agent_id = ? AND is_active = 1 ORDER BY updated_at DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $agent_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $candidate = (float) ($row['custom_price'] ?? 0);
            if ($candidate >= $agent_base_price) {
                $display_price = $candidate;
            }
        }
        $stmt->close();
    }
}

$recent = [];
$stmt = $db->prepare("SELECT reference, beneficiary_name, phone, ghana_card_number, occupation, date_of_birth, region, location, amount, status, payment_gateway, created_at FROM afa_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 10");
if ($stmt) {
    $stmt->bind_param('i', $current_user['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }
    $stmt->close();
}

$gateway = getActivePaymentGateway();
$gateway_label = $gateway === 'moolre' ? 'Moolre' : 'Paystack';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFA Registration - <?php echo htmlspecialchars(getSiteName()); ?></title>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    
    <style>
        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .afa-shell {
            width: 100%;
            max-width: 100%;
        }

        .afa-shell {
            max-width: 1080px;
            margin: 0 auto;
        }

        .afa-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .metric {
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 10px;
            padding: 1rem;
            background: var(--bg-primary, #fafafa);
        }

        .metric span {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .metric strong {
            display: block;
            font-size: 1.25rem;
            color: var(--text-primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }

        .form-grid label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.4rem;
            color: var(--text-primary);
        }

        .note {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .afa-table-wrap {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color, #edf2f7);
        }

        .afa-table {
            width: 100%;
            border-collapse: collapse;
        }

        .afa-table th,
        .afa-table td {
            text-align: left;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border-color, #edf2f7);
            font-size: 0.9rem;
            color: var(--text-primary);
            white-space: nowrap;
        }

        .afa-table th {
            font-weight: 600;
            background: var(--bg-primary, #fafafa);
            color: var(--text-muted);
        }

        .afa-table .afa-details-row {
            background: var(--bg-primary, #fafafa);
        }
        
        .afa-table .afa-details-cell {
            white-space: normal !important;
            font-size: 0.85rem;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color, #edf2f7);
            padding: 0.75rem 1rem;
        }

        @media (max-width: 768px) {
            .metrics,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .afa-table-wrap {
                border: none;
                overflow: visible;
            }

            .afa-table,
            .afa-table thead,
            .afa-table tbody,
            .afa-table tr,
            .afa-table th,
            .afa-table td {
                display: block;
                width: 100%;
            }

            .afa-table thead {
                display: none;
            }

            .afa-table tr {
                border: 1px solid var(--border-color, #e5e7eb);
                border-radius: 12px;
                padding: 0.5rem;
                margin-bottom: 0.75rem;
                background: var(--card-bg, #fff);
            }

            .afa-table td {
                border: none;
                padding: 0.5rem;
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.6rem;
                white-space: normal;
                overflow-wrap: anywhere;
                word-break: break-word;
                text-align: right;
            }

            .afa-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-muted);
                text-align: left;
                flex: 0 0 44%;
                max-width: 44%;
            }

            .afa-table .empty-row td {
                display: block;
                text-align: center;
                padding: 1rem;
            }

            .afa-table .empty-row td::before {
                content: none;
            }
            
            .afa-table .afa-details-row {
                background: transparent;
                border: 1px solid var(--border-color, #e5e7eb);
                margin-top: -0.5rem;
                border-top: none;
                border-radius: 0 0 12px 12px;
            }

            .afa-table .afa-details-cell {
                display: block;
                text-align: left;
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php require_once '../includes/customer_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-user-check"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">AFA Registration</div>
                </nav>
            </div>
            
            <div class="header-actions">
                <div class="wallet-balance">
                    <i class="fas fa-wallet"></i>
                    <span>Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?></span>
                </div>
                <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="btn btn-sm btn-primary header-action-btn topup-btn">
                    <i class="fas fa-plus-circle"></i> Top Up
                </a>
                <button class="theme-toggle" type="button" onclick="toggleTheme()"><i class="fas fa-sun" id="theme-icon"></i></button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'] ?? $_SESSION['username'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Customer</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item"><i class="fas fa-wallet"></i> Wallet</a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="afa-shell">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1.2rem;"><?php echo htmlspecialchars($flash['message']); ?></div>
                <?php endif; ?>

                <?php if (!$service_enabled): ?>
                    <div class="alert alert-warning" style="margin-bottom:1.2rem;">AFA registration is disabled by admin.</div>
                <?php endif; ?>

                <div class="afa-card">
                    <div class="metrics">
                        <div class="metric"><span>Price</span><strong><?php echo htmlspecialchars(formatCurrency($display_price, CURRENCY)); ?></strong></div>
                        <div class="metric"><span>Your Wallet</span><strong><?php echo htmlspecialchars(formatCurrency($wallet_balance, CURRENCY)); ?></strong></div>
                        <div class="metric"><span>Gateway</span><strong><?php echo htmlspecialchars($gateway_label); ?></strong></div>
                        <div class="metric"><span>Store</span><strong><?php echo htmlspecialchars($store_name !== '' ? $store_name : 'Default'); ?></strong></div>
                    </div>
                </div>

                <div class="afa-card">
                    <h3 style="margin-top:0; margin-bottom: 1.2rem; color: var(--text-primary);">AFA Registration Form</h3>
                    <form id="afaForm" enctype="multipart/form-data">
                        <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" id="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
                        <div class="form-grid">
                            <div>
                                <label>Beneficiary Fullname*</label>
                                <input class="form-control" name="beneficiary_name" placeholder="e.g. John Doe" required>
                            </div>
                            <div>
                                <label>Number For Registration*</label>
                                <input class="form-control" name="phone" placeholder="e.g. 0241234567" inputmode="numeric" pattern="[0-9]{10,15}" minlength="10" maxlength="15" value="<?php echo htmlspecialchars($current_user['phone'] ?? ''); ?>" required>
                            </div>
                            <div>
                                <label>Ghana Card Number*</label>
                                <input class="form-control" name="ghana_card_number" placeholder="e.g. GHA-123456789-0" maxlength="13" minlength="13" pattern="[A-Za-z0-9]{13}" style="text-transform:uppercase;" required>
                            </div>
                            <div>
                                <label>Location*</label>
                                <input class="form-control" name="location" placeholder="e.g. Accra" required>
                            </div>
                            <div>
                                <label>Occupation*</label>
                                <input class="form-control" name="occupation" placeholder="e.g. Trader" required>
                            </div>
                            <div>
                                <label>Date of Birth*</label>
                                <input class="form-control" type="date" name="date_of_birth" required>
                            </div>
                            <div>
                                <label>Payment Method</label>
                                <select class="form-control" name="payment_method">
                                    <?php if ($allow_wallet): ?><option value="wallet">Wallet</option><?php endif; ?>
                                    <?php if ($allow_gateway): ?><option value="gateway">Gateway (<?php echo htmlspecialchars($gateway_label); ?>)</option><?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top:.5rem; width:100%;" <?php echo (!$service_enabled || (!$allow_wallet && !$allow_gateway)) ? 'disabled' : ''; ?>>Submit AFA Registration</button>
                        <small class="note" id="afaMsg" style="display:block; margin-top:.75rem; text-align: center; font-weight: 500;"></small>
                    </form>
                </div>

                <div class="afa-card">
                    <h3 style="margin-top:0; margin-bottom: 1.2rem; color: var(--text-primary);">Recent AFA Registrations</h3>
                    <div class="afa-table-wrap">
                        <table class="afa-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Beneficiary</th>
                                    <th>Amount</th>
                                    <th>Gateway</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent)): ?>
                                <tr class="empty-row">
                                    <td colspan="6" style="text-align: center; color: var(--text-muted);">No AFA registrations yet.</td>
                                </tr>
                            <?php else: foreach ($recent as $row): ?>
                                <tr>
                                    <td data-label="Reference"><?php echo htmlspecialchars($row['reference']); ?></td>
                                    <td data-label="Beneficiary"><?php echo htmlspecialchars($row['beneficiary_name']); ?></td>
                                    <td data-label="Amount"><?php echo htmlspecialchars(formatCurrency((float) $row['amount'], CURRENCY)); ?></td>
                                    <td data-label="Gateway"><?php echo htmlspecialchars(strtoupper((string) ($row['payment_gateway'] ?? '-'))); ?></td>
                                    <td data-label="Status">
                                        <?php
                                        $status_class = 'bg-secondary';
                                        $status_val = strtolower($row['status'] ?? 'pending');
                                        if ($status_val === 'pending') $status_class = 'badge-warning text-dark';
                                        elseif (in_array($status_val, ['completed', 'delivered', 'success'], true)) $status_class = 'badge-success';
                                        elseif ($status_val === 'processing') $status_class = 'badge-info';
                                        elseif (in_array($status_val, ['failed', 'refunded'], true)) $status_class = 'badge-danger';
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars(ucfirst((string) $row['status'])); ?></span>
                                    </td>
                                    <td data-label="Date"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $row['created_at']))); ?></td>
                                </tr>
                                <tr class="afa-details-row">
                                    <td colspan="6" class="afa-details-cell">
                                        <strong>Phone:</strong> <?php echo htmlspecialchars((string) ($row['phone'] ?? 'N/A')); ?> |
                                        <strong>Card No:</strong> <?php echo htmlspecialchars((string) ($row['ghana_card_number'] ?? 'N/A')); ?> |
                                        <strong>Occupation:</strong> <?php echo htmlspecialchars((string) ($row['occupation'] ?? 'N/A')); ?> |
                                        <strong>DOB:</strong> <?php echo htmlspecialchars((string) ($row['date_of_birth'] ?? 'N/A')); ?> |
                                        <strong>Location:</strong> <?php echo htmlspecialchars((string) ($row['location'] ?? 'N/A')); ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = savedTheme || (prefersDark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
    updateThemeIcon(theme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (icon) {
        icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// User dropdown menu toggle
function toggleUserDropdown() {
    document.getElementById('userDropdown').classList.toggle('show');
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    if (dropdown && toggle && !toggle.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

const form = document.getElementById('afaForm');
const msg = document.getElementById('afaMsg');
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
        normalizePhone();
    }

    if (cardInput) {
        cardInput.addEventListener('input', normalizeCard);
        normalizeCard();
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        msg.textContent = 'Processing, please wait...';
        msg.style.color = 'var(--text-primary)';

        normalizePhone();
        normalizeCard();
        if (!/^[0-9]{10,15}$/.test(phoneInput ? phoneInput.value : '')) {
            msg.textContent = 'Number for registration must contain digits only (10-15 digits).';
            msg.style.color = '#b91c1c';
            return;
        }
        if (!/^[A-Z0-9]{13}$/.test(cardInput ? cardInput.value : '')) {
            msg.textContent = 'Ghana Card number must be exactly 13 alphanumeric characters.';
            msg.style.color = '#b91c1c';
            return;
        }

        const formData = new FormData(form);
        formData.set('csrf_token', document.getElementById('csrf_token').value);
        formData.set('store_slug', document.getElementById('store_slug').value);

        try {
            const res = await fetch('../api/afa_registration_purchase.php', {
                method: 'POST',
                body: formData
            });
            const payload = await res.json();
            if (payload.status !== 'success') {
                throw new Error(payload.message || 'Request failed');
            }
            if (payload.data && payload.data.authorization_url) {
                window.location.href = payload.data.authorization_url;
                return;
            }
            msg.textContent = payload.message || 'AFA registration successful.';
            msg.style.color = '#065f46';
            form.reset();
            setTimeout(() => window.location.reload(), 1200);
        } catch (err) {
            msg.textContent = err.message || 'Failed to submit registration.';
            msg.style.color = '#b91c1c';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initTheme();

    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
        });
    }
});
</script>
<script src="../immediate_icon_fix.js"></script>
</body>
</html>
