<?php
require_once '../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require vip role
requireRole('vip');

$current_user = getCurrentUser();
$vip_id = isset($current_user['id']) ? (int) $current_user['id'] : 0;
$agent_id = $vip_id; // Keep $agent_id for database compatibility with agent_custom_pricing table

// Handle save (bulk upsert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prices = $_POST['prices'] ?? [];

    if (!empty($prices) && is_array($prices)) {
        $validated_prices = [];
        $clear_prices = [];
        $validation_errors = [];

        $base_stmt = $db->prepare("
            SELECT COALESCE(pp.price, dp.price, 0) AS base_price
            FROM data_packages dp
            LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'vip'
            WHERE dp.id = ? AND dp.status = 'active'
            LIMIT 1
        ");
        if (!$base_stmt) {
            setFlashMessage('danger', 'Unable to validate package pricing right now.');
            header('Location: pricing.php' . (!empty($_GET['network']) ? ('?network=' . urlencode($_GET['network'])) : ''));
            exit();
        }
        
        foreach ($prices as $pkgId => $price) {
            $pkgId = intval($pkgId);
            if ($price !== '' && $price !== null) {
                $price = round((float) $price, 2);
                if ($price < 0) {
                    $validation_errors[] = "Package #{$pkgId}: price cannot be negative.";
                    continue;
                }

                $base_stmt->bind_param('i', $pkgId);
                $base_stmt->execute();
                $base_row = $base_stmt->get_result()->fetch_assoc();
                if (!$base_row) {
                    $validation_errors[] = "Package #{$pkgId}: package is not available.";
                    continue;
                }

                $base_price = round((float) ($base_row['base_price'] ?? 0), 2);
                if (($price + 0.00001) < $base_price) {
                    $validation_errors[] = "Package #{$pkgId}: your price must be at least " . formatCurrency($base_price) . ".";
                    continue;
                }

                $validated_prices[$pkgId] = $price;
            } else {
                $clear_prices[] = $pkgId;
            }
        }

        if (!empty($validation_errors)) {
            setFlashMessage('danger', implode(' ', array_slice($validation_errors, 0, 3)));
            header('Location: pricing.php' . (!empty($_GET['network']) ? ('?network=' . urlencode($_GET['network'])) : ''));
            exit();
        }

        // Prepare upsert statement for agent custom pricing
        $stmt = $db->prepare("INSERT INTO agent_custom_pricing (agent_id, package_id, custom_price, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price), is_active = 1");
        foreach ($validated_prices as $pkgId => $price) {
            $stmt->bind_param('iid', $agent_id, $pkgId, $price);
            $stmt->execute();
        }

        if (!empty($clear_prices)) {
            $delete_stmt = $db->prepare("UPDATE agent_custom_pricing SET is_active = 0 WHERE agent_id = ? AND package_id = ?");
            foreach ($clear_prices as $pkgId) {
                $delete_stmt->bind_param('ii', $agent_id, $pkgId);
                $delete_stmt->execute();
            }
        }

        setFlashMessage('success', 'Custom pricing updated successfully');
        header('Location: pricing.php' . (!empty($_GET['network']) ? ('?network=' . urlencode($_GET['network'])) : ''));
        exit();
    } else {
        setFlashMessage('warning', 'No pricing changes detected');
    }
}

// Filters
$selected_network = isset($_GET['network']) ? sanitize($_GET['network']) : '';

// Fetch dynamic list of networks
$networks = [];
$result = $db->query("SELECT name FROM networks WHERE is_active = 1 ORDER BY name ASC");
while ($row = $result->fetch_assoc()) { $networks[] = $row['name']; }

// Fetch packages with default agent pricing and custom pricing
$query = "
    SELECT dp.id, dp.name, n.name AS network, dp.package_type, dp.data_size, dp.validity_days,
           pp.price as default_agent_price,
           acp.custom_price,
           CASE WHEN acp.custom_price IS NOT NULL AND acp.is_active = 1 THEN 1 ELSE 0 END as has_custom_price
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id AND n.is_active = 1
    LEFT JOIN package_pricing pp ON pp.package_id = dp.id AND pp.user_type = 'vip'
    LEFT JOIN agent_custom_pricing acp ON acp.package_id = dp.id AND acp.agent_id = ? AND acp.is_active = 1
    WHERE dp.status = 'active'
    " . ($selected_network !== '' ? " AND n.name = ?" : "") . "
    GROUP BY dp.id, dp.name, n.name, dp.package_type, dp.data_size, dp.validity_days, pp.price, acp.custom_price
    ORDER BY n.name, dp.package_type, dp.data_size
";

if ($selected_network !== '') {
    $stmt = $db->prepare($query);
    $stmt->bind_param('is', $agent_id, $selected_network);
    $stmt->execute();
    $packages_rs = $stmt->get_result();
} else {
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $agent_id);
    $stmt->execute();
    $packages_rs = $stmt->get_result();
}

$packages = [];
while ($row = $packages_rs->fetch_assoc()) { $packages[] = $row; }

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Pricing - <?php echo SITE_NAME; ?></title>
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
                    <div class="breadcrumb-item"><i class="fas fa-tags"></i></div>
                    <div class="breadcrumb-item">Business</div>
                    <div class="breadcrumb-item active">Custom Pricing</div>
                </nav>
            </div>
            
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
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
                <h1>Custom Pricing</h1>
                <p class="page-subtitle">Set the storefront prices customers pay. Store profit is your price minus the agent base cost.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="widget">
                <div class="widget-header pricing-header" style="gap: 1rem;">
                    <h3 class="widget-title">Data Packages</h3>
                    <form method="get" class="form-inline pricing-filter" style="display:flex; gap: .5rem; align-items:center;">
                        <label for="network">Network</label>
                        <select name="network" id="network" class="form-control" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($networks as $net): ?>
                                <option value="<?php echo htmlspecialchars($net); ?>" <?php echo $selected_network===$net?'selected':''; ?>><?php echo htmlspecialchars($net); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="widget-body">
                    <form method="post">
                        <div class="table-responsive pricing-table-responsive">
                            <table class="table responsive-table-stack">
                                <thead>
                                    <tr>
                                        <th>Network</th>
                                        <th>Package</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Validity</th>
                                        <th>VIP Base Cost (<?php echo CURRENCY; ?>)</th>
                                        <th>Your Price (<?php echo CURRENCY; ?>)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($packages)): ?>
                                    <tr><td colspan="8" class="text-center text-muted" data-label="Notice">No packages found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($packages as $pkg): ?>
                                        <tr>
                                            <td data-label="Network"><?php echo htmlspecialchars($pkg['network']); ?></td>
                                            <td data-label="Package"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                            <td data-label="Type"><?php echo htmlspecialchars($pkg['package_type']); ?></td>
                                            <td data-label="Size"><?php echo htmlspecialchars($pkg['data_size']); ?></td>
                                            <td data-label="Validity"><?php echo intval($pkg['validity_days']); ?> days</td>
                                            <td data-label="VIP Base Cost (<?php echo htmlspecialchars(CURRENCY); ?>)">
                                                <?php echo $pkg['default_agent_price'] ? formatCurrency($pkg['default_agent_price']) : '<span class="text-muted">Not set</span>'; ?>
                                            </td>
                                            <td data-label="Your Price (<?php echo htmlspecialchars(CURRENCY); ?>)">
                                                <input type="number" step="0.01" min="<?php echo htmlspecialchars(number_format((float) ($pkg['default_agent_price'] ?? 0), 2, '.', '')); ?>" name="prices[<?php echo $pkg['id']; ?>]" 
                                                       value="<?php echo $pkg['custom_price'] !== null ? htmlspecialchars($pkg['custom_price']) : ''; ?>" 
                                                       class="form-control" placeholder="Leave blank for default">
                                            </td>
                                            <td data-label="Status">
                                                <?php if ($pkg['has_custom_price']): ?>
                                                    <span class="badge badge-success">Custom</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Default</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="pricing-actions" style="display:flex; justify-content:space-between; align-items:center; margin-top:1rem;">
                            <div class="text-muted">
                                <small><i class="fas fa-info-circle"></i> Custom prices are storefront prices. Leave blank to use the base cost; prices below base cost are not allowed.</small>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Custom Pricing</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Mobile menu toggle
    document.addEventListener('DOMContentLoaded', function() {
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
            });
        }
    });
    
    // Theme management - consistent across all pages
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

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


