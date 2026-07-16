<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require customer role
requireRole('customer');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
ensureDataPackageStockStatusColumn();
$customer_pricing_type = getCustomerPricingUserType($current_user);
$is_vip_portal = defined('VIP_PORTAL') && VIP_PORTAL;
$portal_role_label = $is_vip_portal ? 'VIP' : 'Customer';

$store_slug = $_GET['store'] ?? null;
$agent_store = null;
$agent_pricing = [];

if ($store_slug) {
    // Get agent store information
    $stmt = $db->prepare("
        SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
    ");
    $stmt->bind_param("s", $store_slug);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $agent_store = $row;

        $stmt = $db->prepare("
            SELECT package_id, custom_price 
            FROM agent_custom_pricing 
            WHERE agent_id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $agent_store['agent_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $agent_pricing[$row['package_id']] = $row['custom_price'];
        }
    }
}

// MTN packages only (customer pricing, agent pricing when available)
$packages_query = "
    SELECT dp.id, dp.name, 
           COALESCE(n.name, 'Unknown') AS network_name, 
           COALESCE(n.color, '#007bff') as network_color, 
           dp.package_type, dp.data_size, dp.validity_days,
           COALESCE(dp.stock_status, 'in_stock') AS stock_status,
           COALESCE(pp.price, pp_customer_fallback.price, dp.price, 0) as customer_price
    FROM data_packages dp
    LEFT JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = ?
    LEFT JOIN package_pricing pp_customer_fallback ON pp_customer_fallback.package_id = dp.id AND pp_customer_fallback.user_type = 'customer'
    WHERE n.name = 'MTN' AND (pp.price IS NOT NULL OR pp_customer_fallback.price IS NOT NULL OR dp.price > 0) AND dp.status = 'active'
      AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    GROUP BY dp.id, dp.name, COALESCE(n.name, 'Unknown'), COALESCE(n.color, '#007bff'),
             dp.package_type, dp.data_size, dp.validity_days, COALESCE(dp.stock_status, 'in_stock'), COALESCE(pp.price, pp_customer_fallback.price, dp.price, 0)
    ORDER BY COALESCE(pp.price, pp_customer_fallback.price, dp.price, 0) ASC
";

$packages_stmt = $db->prepare($packages_query);
$packages_stmt->bind_param('s', $customer_pricing_type);
$packages_stmt->execute();
$packages_rs = $packages_stmt->get_result();
$mtn_packages = [];
while ($row = $packages_rs->fetch_assoc()) {
    if ($customer_pricing_type !== 'vip' && $agent_store && isset($agent_pricing[$row['id']])) {
        $row['display_price'] = $agent_pricing[$row['id']];
        $row['is_agent_price'] = true;
    } else {
        $row['display_price'] = $row['customer_price'];
        $row['is_agent_price'] = false;
    }
    $mtn_packages[] = $row;
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk MTN Orders - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo $agent_store ? htmlspecialchars($agent_store['store_name']) : htmlspecialchars(getSiteName()); ?></h3>
            <?php if ($agent_store): ?>
                <small style="opacity: 0.7; font-size: 0.8rem;">by <?php echo htmlspecialchars($agent_store['agent_name']); ?></small>
            <?php endif; ?>
        </div>
        
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item">
                    <a href="dashboard.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Services</div>
                <div class="nav-item">
                    <a href="buy-data.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-mobile-alt"></i>
                        Buy Data
                    </a>
                </div>
                <div class="nav-item">
                    <a href="bulk-mtn.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link active">
                        <i class="fas fa-layer-group"></i>
                        Bulk MTN
                    </a>
                </div>
                <div class="nav-item">
                    <a href="result-checker.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-award"></i>
                        Result Checker
                    </a>
                </div>
                <div class="nav-item">
                    <a href="order-history.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-history"></i>
                        Order History
                    </a>
                </div>
                <div class="nav-item">
                    <a href="reference.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-search"></i>
                        Reference
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Account</div>
                <div class="nav-item">
                    <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-wallet"></i>
                        Wallet
                    </a>
                </div>
                <div class="nav-item">
                    <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-user"></i>
                        Profile
                    </a>
                </div>
                <div class="nav-item">
                    <a href="support.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="nav-link">
                        <i class="fas fa-life-ring"></i>
                        Support
                    </a>
                </div>
            </li>
            
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item">
                    <a href="../logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </li>
        </ul>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-layer-group"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Bulk MTN</div>
                </nav>
            </div>
            <div class="header-actions">
                <div class="wallet-balance">
                    <i class="fas fa-wallet"></i>
                    <span id="customerWalletBalanceText" data-balance="<?php echo htmlspecialchars((string) ((float) ($wallet_balance ?? 0))); ?>">
                        Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?>
                    </span>
                </div>
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($current_user['full_name'] ?? $_SESSION['username']); ?></div>
                            <div class="user-role"><?php echo htmlspecialchars($portal_role_label); ?></div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php<?php echo $store_slug ? '?store=' . urlencode($store_slug) : ''; ?>" class="dropdown-item">
                            <i class="fas fa-wallet"></i> Wallet
                        </a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('customers'); ?>


        <div class="dashboard-content">
            <div class="page-title">
                <h1>Bulk MTN Orders</h1>
                <p class="page-subtitle">Paste MTN numbers and bundle sizes, preview totals, then process.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="widget" style="margin-bottom: 1.5rem;">
                <div class="widget-header">
                    <h3 class="widget-title">Bulk MTN Help</h3>
                </div>
                <div class="widget-body" style="color: var(--text-muted);">
                    <ol style="margin: 0 0 0.75rem 1.25rem;">
                        <li>Enter one order per line: <code>0240000000 1</code>.</li>
                        <li>Use MTN numbers only (024/025/053/054/055/059) and 10 digits.</li>
                        <li>Click Preview Orders to validate and see total cost.</li>
                        <li>Click Process Orders to send. Only successful rows are charged.</li>
                    </ol>
                </div>
            </div>

            <div class="dashboard-grid" style="grid-template-columns: 1.3fr 1fr; gap: 1.5rem;">
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Bulk Text Orders</h3>
                    </div>
                    <div class="widget-body">
                        <div class="form-group">
                            <label class="form-label">Paste numbers and bundles</label>
                            <textarea id="customerBulkTextInput" class="form-control" rows="8" placeholder="0240000000 1&#10;0540000000 2"></textarea>
                            <small style="color: var(--text-muted);">One order per line. Format: phone and GB (space-separated). Example: 0240000000 1.</small>
                        </div>
                        <div class="form-group" style="display: flex; gap: 0.75rem; align-items: center;">
                            <button type="button" class="btn btn-outline" onclick="previewCustomerBulkTextOrders()" style="flex: 1;">
                                <i class="fas fa-eye"></i> Preview Orders
                            </button>
                            <button type="button" class="btn btn-primary" id="processCustomerBulkTextBtn" onclick="processCustomerBulkTextOrders()" style="flex: 1;" disabled>
                                <i class="fas fa-paper-plane"></i> Process Orders
                            </button>
                        </div>
                        <div id="customerBulkTextSummary" style="margin-top: 0.75rem; color: var(--text-muted);"></div>
                        <div id="customerBulkTextErrors" style="margin-top: 0.5rem; color: var(--accent-red);"></div>
                        <div id="customerBulkTextPreview" style="margin-top: 1rem; display: none;">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Phone</th>
                                            <th>Bundle</th>
                                            <th>Price</th>
                                        </tr>
                                    </thead>
                                    <tbody id="customerBulkTextPreviewBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Available Bundles</h3>
                    </div>
                    <div class="widget-body">
                        <?php if (empty($mtn_packages)): ?>
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No MTN bundles available.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Bundle</th>
                                            <th>Price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mtn_packages as $pkg): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($pkg['data_size']); ?></td>
                                                <td><?php echo CURRENCY . number_format((float)$pkg['display_price'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const bulkCurrency = <?php echo json_encode(CURRENCY); ?>;
    const customerBulkPackages = <?php echo json_encode($mtn_packages); ?>;
    const customerBulkPackageMap = {};
    const customerBulkSizeMap = {};
    customerBulkPackages.forEach(function(pkg) {
        const key = normalizeCustomerBulkVolumeKey(pkg.data_size || '');
        if (key) {
            customerBulkPackageMap[key] = pkg;
        }
        const sizeKey = normalizeCustomerBulkNumericKey(parseCustomerPackageSizeGb(pkg.data_size || ''));
        if (sizeKey) {
            if (!customerBulkSizeMap[sizeKey]) {
                customerBulkSizeMap[sizeKey] = pkg;
            } else {
                const currentPrice = parseFloat(customerBulkSizeMap[sizeKey].display_price || 0);
                const candidatePrice = parseFloat(pkg.display_price || 0);
                if (!isNaN(candidatePrice) && (isNaN(currentPrice) || candidatePrice < currentPrice)) {
                    customerBulkSizeMap[sizeKey] = pkg;
                }
            }
        }
    });

    const customerBulkState = {
        orders: [],
        hasErrors: true
    };

    const customerBulkContext = {
        agentId: <?php echo $agent_store ? (int)$agent_store['agent_id'] : 0; ?>,
        storeSlug: <?php echo json_encode($store_slug); ?>,
        csrfToken: <?php echo json_encode(generateCSRF()); ?>
    };

    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });

    function normalizeCustomerBulkVolumeKey(value) {
        const raw = String(value || '').trim().toLowerCase();
        if (/^\d+(\.\d+)?$/.test(raw)) {
            const parsed = parseFloat(raw);
            if (!isNaN(parsed)) {
                return parsed.toString() + 'g';
            }
        }
        return raw
            .replace(/\s+/g, '')
            .replace('gb', 'g')
            .replace('mb', 'm');
    }

    function parseCustomerPackageSizeGb(value) {
        const raw = String(value || '').trim().toLowerCase();
        const match = raw.match(/([\d.]+)\s*(gb|g|mb|m)?/);
        if (!match) return 0;
        const amount = parseFloat(match[1]);
        if (isNaN(amount)) return 0;
        const unit = match[2] || 'g';
        if (unit === 'mb' || unit === 'm') {
            return amount / 1024;
        }
        return amount;
    }

    function normalizeCustomerBulkNumericKey(value) {
        const parsed = parseFloat(value);
        if (isNaN(parsed) || parsed <= 0) return '';
        return parsed.toFixed(2).replace(/\.?0+$/, '');
    }

    function normalizeCustomerBulkLocalPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('233')) {
            return '0' + digits.slice(3);
        }
        return digits;
    }

    function isCustomerMtnLocalPhone(localPhone) {
        if (!/^\d{10}$/.test(localPhone)) return false;
        const prefix = localPhone.slice(0, 3);
        return ['024', '025', '053', '054', '055', '059'].indexOf(prefix) !== -1;
    }

    function previewCustomerBulkTextOrders() {
        const input = document.getElementById('customerBulkTextInput');
        const preview = document.getElementById('customerBulkTextPreview');
        const previewBody = document.getElementById('customerBulkTextPreviewBody');
        const summary = document.getElementById('customerBulkTextSummary');
        const errorsBox = document.getElementById('customerBulkTextErrors');
        const processBtn = document.getElementById('processCustomerBulkTextBtn');

        if (!input || !previewBody || !summary || !errorsBox || !processBtn) return;

        const lines = String(input.value || '').split(/\r?\n/);
        const orders = [];
        const errors = [];
        let totalCost = 0;

        lines.forEach(function(rawLine, index) {
            const line = rawLine.trim();
            if (!line) return;
            let phone = '';
            let volume = '';

            if (line.indexOf(',') !== -1) {
                const parts = line.split(',');
                phone = (parts[0] || '').trim();
                volume = parts.slice(1).join(',').trim();
            } else {
                const parts = line.split(/\s+/);
                phone = (parts[0] || '').trim();
                volume = parts.slice(1).join(' ').trim();
            }

            if (volume) {
                const numericVolume = volume.replace(/\s+/g, '');
                if (/^\d+(\.\d+)?$/.test(numericVolume)) {
                    const parsedVolume = parseFloat(numericVolume);
                    if (!isNaN(parsedVolume)) {
                        volume = parsedVolume.toFixed(2);
                    }
                }
            }

            const localPhone = normalizeCustomerBulkLocalPhone(phone);
            if (!isCustomerMtnLocalPhone(localPhone)) {
                errors.push('Row ' + (index + 1) + ': Invalid MTN number');
                return;
            }
            if (!volume) {
                errors.push('Row ' + (index + 1) + ': Missing bundle size');
                return;
            }

            const numericCandidate = volume.replace(/\s+/g, '');
            const isNumericInput = /^\d+(\.\d+)?$/.test(numericCandidate);
            const volumeKey = normalizeCustomerBulkVolumeKey(volume);
            let pkg = null;
            if (isNumericInput) {
                const numericKey = normalizeCustomerBulkNumericKey(numericCandidate);
                if (numericKey && customerBulkSizeMap[numericKey]) {
                    pkg = customerBulkSizeMap[numericKey];
                }
            } else {
                pkg = customerBulkPackageMap[volumeKey] || null;
                if (!pkg) {
                    Object.keys(customerBulkPackageMap).some(function(key) {
                        if (volumeKey.indexOf(key) !== -1 || key.indexOf(volumeKey) !== -1) {
                            pkg = customerBulkPackageMap[key];
                            return true;
                        }
                        return false;
                    });
                }
            }

            if (!pkg) {
                errors.push('Row ' + (index + 1) + ': Bundle not found for "' + volume + '"');
                return;
            }

            const price = parseFloat(pkg.display_price || 0);
            totalCost += price;
            orders.push({
                phone: localPhone,
                volume: volume,
                price: price
            });
        });

        previewBody.innerHTML = '';
        orders.forEach(function(order) {
            const row = document.createElement('tr');
            row.innerHTML = '<td>' + order.phone + '</td><td>' + order.volume + '</td><td>' +
                bulkCurrency + order.price.toFixed(2) + '</td>';
            previewBody.appendChild(row);
        });

        customerBulkState.orders = orders;
        customerBulkState.hasErrors = errors.length > 0 || orders.length === 0;

        preview.style.display = orders.length ? 'block' : 'none';
        summary.textContent = orders.length
            ? (orders.length + ' valid orders. Total: ' + bulkCurrency + totalCost.toFixed(2))
            : 'No valid orders to preview.';
        errorsBox.textContent = errors.length ? errors.slice(0, 3).join(' | ') : '';
        processBtn.disabled = customerBulkState.hasErrors;
    }

    function processCustomerBulkTextOrders() {
        const processBtn = document.getElementById('processCustomerBulkTextBtn');
        if (!processBtn || customerBulkState.hasErrors || customerBulkState.orders.length === 0) return;

        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        fetch('process_bulk_text.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                network: 'mtn',
                orders: customerBulkState.orders.map(function(order) {
                    return { phone: order.phone, volume: order.volume };
                }),
                agent_id: customerBulkContext.agentId,
                store_slug: customerBulkContext.storeSlug,
                csrf_token: customerBulkContext.csrfToken
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            alert(data.message || 'Bulk orders completed.');
            if (typeof data.wallet_balance !== 'undefined') {
                updateCustomerWalletBalanceDisplay(data.wallet_balance);
            }
            if (data.success) {
                document.getElementById('customerBulkTextInput').value = '';
                document.getElementById('customerBulkTextPreviewBody').innerHTML = '';
                document.getElementById('customerBulkTextPreview').style.display = 'none';
                document.getElementById('customerBulkTextSummary').textContent = '';
            }
        })
        .catch(function() {
            alert('Failed to process bulk orders. Please try again.');
        })
        .finally(function() {
            processBtn.disabled = false;
            processBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Process Orders';
        });
    }

    function updateCustomerWalletBalanceDisplay(balanceValue) {
        const balanceEl = document.getElementById('customerWalletBalanceText');
        if (!balanceEl) return;

        const numericBalance = parseFloat(balanceValue);
        if (isNaN(numericBalance)) return;

        balanceEl.dataset.balance = numericBalance.toString();
        balanceEl.textContent = 'Balance: ' + bulkCurrency + numericBalance.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Theme management
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

    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
    });
</script>

<style>
    .wallet-balance {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: var(--success-color);
        color: white;
        border-radius: var(--border-radius);
        font-weight: 600;
        margin-right: 1rem;
    }

    @media (max-width: 992px) {
        .dashboard-grid {
            grid-template-columns: 1fr !important;
        }
    }

    @media (max-width: 768px) {
        .wallet-balance {
            display: none;
        }

        .user-dropdown-toggle {
            min-width: 44px;
        }

        .user-avatar {
            flex-shrink: 0;
        }
    }
</style>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>

