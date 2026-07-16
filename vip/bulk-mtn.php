<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require agent role
requireRole('vip');

$current_user = getCurrentUser();
$wallet_balance = getWalletBalance($current_user['id']);
ensureDataPackageStockStatusColumn();
$agent_display_name = (string)($current_user['full_name'] ?? $_SESSION['username'] ?? 'Agent');
$agent_initial = strtoupper(substr(trim($agent_display_name) !== '' ? trim($agent_display_name) : 'A', 0, 1));

// Get MTN packages with agent pricing (allow multiple packages but prevent duplicates)
$mtn_packages = [];
$stmt = $db->prepare("
    SELECT dp.id, dp.name, dp.data_size, dp.validity_days,
           COALESCE(dp.stock_status, 'in_stock') AS stock_status,
           COALESCE(pp_agent.price, pp_customer.price, dp.price) as effective_price
    FROM data_packages dp 
    LEFT JOIN networks n ON n.id = dp.network_id 
    LEFT JOIN package_pricing pp_agent ON pp_agent.package_id = dp.id AND pp_agent.user_type = 'agent'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    WHERE n.name = 'MTN' AND dp.status = 'active'
      AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    GROUP BY dp.id, dp.name, dp.data_size, dp.validity_days,
             COALESCE(dp.stock_status, 'in_stock'), COALESCE(pp_agent.price, pp_customer.price, dp.price)
    ORDER BY COALESCE(pp_agent.price, pp_customer.price, dp.price) ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
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
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        
        <?php renderAgentSidebar(); ?>
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
                    <span>Balance: <?php echo CURRENCY . number_format((float)($wallet_balance ?? 0), 2); ?></span>
                </div>
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo htmlspecialchars($agent_initial); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($agent_display_name); ?></div>
                            <div class="user-role">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php" class="dropdown-item">
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

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Bulk MTN Orders</h1>
                <p class="page-subtitle">Paste MTN numbers with bundle sizes to process multiple orders at once.</p>
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
                            <textarea id="bulkTextInput" class="form-control" rows="8" placeholder="0240000000 1&#10;0540000000 2"></textarea>
                            <small style="color: var(--text-muted);">One order per line. Format: phone and GB (space-separated). Example: 0240000000 1.</small>
                        </div>
                        <div class="form-group" style="display: flex; gap: 0.75rem; align-items: center;">
                            <button type="button" class="btn btn-outline" onclick="previewBulkTextOrders()" style="flex: 1;">
                                <i class="fas fa-eye"></i> Preview Orders
                            </button>
                            <button type="button" class="btn btn-primary" id="processBulkTextBtn" onclick="processBulkTextOrders()" style="flex: 1;" disabled>
                                <i class="fas fa-paper-plane"></i> Process Orders
                            </button>
                        </div>
                        <div id="bulkTextSummary" style="margin-top: 0.75rem; color: var(--text-muted);"></div>
                        <div id="bulkTextErrors" style="margin-top: 0.5rem; color: var(--accent-red);"></div>
                        <div id="bulkTextPreview" style="margin-top: 1rem; display: none;">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Phone</th>
                                            <th>Bundle</th>
                                            <th>Price</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bulkTextPreviewBody"></tbody>
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
                                                <td><?php echo formatCurrency((float)$pkg['effective_price']); ?></td>
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
    const bulkMtnPackages = <?php echo json_encode($mtn_packages); ?>;
    const bulkMtnPackageMap = {};
    const bulkMtnSizeMap = {};
    bulkMtnPackages.forEach(function(pkg) {
        const key = normalizeBulkVolumeKey(pkg.data_size || '');
        if (key) {
            bulkMtnPackageMap[key] = pkg;
        }
        const sizeKey = normalizeBulkNumericKey(parsePackageSizeGb(pkg.data_size || ''));
        if (sizeKey) {
            if (!bulkMtnSizeMap[sizeKey]) {
                bulkMtnSizeMap[sizeKey] = pkg;
            } else {
                const currentPrice = parseFloat(bulkMtnSizeMap[sizeKey].effective_price || 0);
                const candidatePrice = parseFloat(pkg.effective_price || 0);
                if (!isNaN(candidatePrice) && (isNaN(currentPrice) || candidatePrice < currentPrice)) {
                    bulkMtnSizeMap[sizeKey] = pkg;
                }
            }
        }
    });

    const bulkTextState = {
        orders: [],
        hasErrors: true
    };

    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
    });

    function normalizeBulkVolumeKey(value) {
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

    function parsePackageSizeGb(value) {
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

    function normalizeBulkNumericKey(value) {
        const parsed = parseFloat(value);
        if (isNaN(parsed) || parsed <= 0) return '';
        return parsed.toFixed(2).replace(/\.?0+$/, '');
    }

    function normalizeBulkLocalPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('233')) {
            return '0' + digits.slice(3);
        }
        return digits;
    }

    function isMtnLocalPhone(localPhone) {
        if (!/^\d{10}$/.test(localPhone)) return false;
        const prefix = localPhone.slice(0, 3);
        return ['024', '025', '053', '054', '055', '059'].indexOf(prefix) !== -1;
    }

    function previewBulkTextOrders() {
        const input = document.getElementById('bulkTextInput');
        const preview = document.getElementById('bulkTextPreview');
        const previewBody = document.getElementById('bulkTextPreviewBody');
        const summary = document.getElementById('bulkTextSummary');
        const errorsBox = document.getElementById('bulkTextErrors');
        const processBtn = document.getElementById('processBulkTextBtn');

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

            const localPhone = normalizeBulkLocalPhone(phone);
            if (!isMtnLocalPhone(localPhone)) {
                errors.push('Row ' + (index + 1) + ': Invalid MTN number');
                return;
            }
            if (!volume) {
                errors.push('Row ' + (index + 1) + ': Missing bundle size');
                return;
            }

            const numericCandidate = volume.replace(/\s+/g, '');
            const isNumericInput = /^\d+(\.\d+)?$/.test(numericCandidate);
            const volumeKey = normalizeBulkVolumeKey(volume);
            let pkg = null;
            if (isNumericInput) {
                const numericKey = normalizeBulkNumericKey(numericCandidate);
                if (numericKey && bulkMtnSizeMap[numericKey]) {
                    pkg = bulkMtnSizeMap[numericKey];
                }
            } else {
                pkg = bulkMtnPackageMap[volumeKey] || null;
                if (!pkg) {
                    Object.keys(bulkMtnPackageMap).some(function(key) {
                        if (volumeKey.indexOf(key) !== -1 || key.indexOf(volumeKey) !== -1) {
                            pkg = bulkMtnPackageMap[key];
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

            const price = parseFloat(pkg.effective_price || 0);
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

        bulkTextState.orders = orders;
        bulkTextState.hasErrors = errors.length > 0 || orders.length === 0;

        preview.style.display = orders.length ? 'block' : 'none';
        summary.textContent = orders.length
            ? (orders.length + ' valid orders. Total: ' + bulkCurrency + totalCost.toFixed(2))
            : 'No valid orders to preview.';
        errorsBox.textContent = errors.length ? errors.slice(0, 3).join(' | ') : '';
        processBtn.disabled = bulkTextState.hasErrors;
    }

    function processBulkTextOrders() {
        const processBtn = document.getElementById('processBulkTextBtn');
        if (!processBtn || bulkTextState.hasErrors || bulkTextState.orders.length === 0) return;

        processBtn.disabled = true;
        processBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        fetch('process_bulk_text.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                network: 'mtn',
                orders: bulkTextState.orders.map(function(order) {
                    return { phone: order.phone, volume: order.volume };
                })
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            alert(data.message || 'Bulk orders completed.');
            if (data.success) {
                document.getElementById('bulkTextInput').value = '';
                document.getElementById('bulkTextPreviewBody').innerHTML = '';
                document.getElementById('bulkTextPreview').style.display = 'none';
                document.getElementById('bulkTextSummary').textContent = '';
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
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
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
</body>
</html>


