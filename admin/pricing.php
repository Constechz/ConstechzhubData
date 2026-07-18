<?php
require_once '../config/config.php';

// Allow admin and super admin
requireAnyRole(['admin', 'super_admin']);

ensurePricingProfilesSchema();
$profile_options = getPricingProfileOptions();
$active_profile = getActivePricingProfile();

$requested_profile = $_POST['pricing_profile'] ?? ($_POST['profile'] ?? ($_GET['profile'] ?? $active_profile));
$selected_profile = normalizePricingProfile($requested_profile);
if (!isset($profile_options[$selected_profile])) {
    $selected_profile = 'default';
}
ensurePricingProfileSeeded($selected_profile, $active_profile);

// Handle save (bulk upsert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_profile = normalizePricingProfile($_POST['pricing_profile'] ?? $selected_profile);
    if (!isset($profile_options[$selected_profile])) {
        $selected_profile = 'default';
    }

    $request_network = sanitize($_POST['network'] ?? ($_GET['network'] ?? ''));
    $request_page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : (isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1);

    if (!empty($_POST['switch_profile'])) {
        $target_profile = normalizePricingProfile($_POST['switch_profile']);
        if (!isset($profile_options[$target_profile])) {
            $target_profile = 'default';
        }

        if (switchActivePricingProfile($target_profile)) {
            $active_profile = $target_profile;
            setFlashMessage('success', ucfirst($profile_options[$target_profile]) . ' profile is now active.');
        } else {
            setFlashMessage('error', 'Failed to switch pricing profile. No changes were applied.');
        }

        $redirectParams = ['profile' => $target_profile];
        if ($request_network !== '') {
            $redirectParams['network'] = $request_network;
        }
        if ($request_page > 1) {
            $redirectParams['page'] = $request_page;
        }
        header('Location: pricing.php?' . http_build_query($redirectParams));
        exit();
    }

    ensurePricingProfileSeeded($selected_profile, $active_profile);

    $prices = $_POST['prices'] ?? [];
    $newPackages = $_POST['new_packages'] ?? [];
    $deletePackages = $_POST['delete_packages'] ?? [];
    $changes = 0;

    $sync_live_prices = ($selected_profile === $active_profile);
    $livePriceStmt = null;
    if ($sync_live_prices) {
        $livePriceStmt = $db->prepare("INSERT INTO package_pricing (package_id, user_type, price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
    }

    // Handle package deletions
    if (!empty($deletePackages) && is_array($deletePackages)) {
        $stmt = $db->prepare("DELETE FROM data_packages WHERE id = ?");
        foreach ($deletePackages as $pkgId) {
            $pkgId = intval($pkgId);
            $stmt->bind_param('i', $pkgId);
            if ($stmt->execute()) $changes++;
        }
    }

    // Handle new packages
    if (!empty($newPackages) && is_array($newPackages)) {
        foreach ($newPackages as $newPkg) {
            if (!empty($newPkg['network']) && !empty($newPkg['name']) && !empty($newPkg['type']) && !empty($newPkg['size']) && !empty($newPkg['validity'])) {
                // Get network ID
                $networkStmt = $db->prepare("SELECT id FROM networks WHERE name = ? LIMIT 1");
                $networkStmt->bind_param('s', $newPkg['network']);
                $networkStmt->execute();
                $networkResult = $networkStmt->get_result();
                
                if ($networkRow = $networkResult->fetch_assoc()) {
                    // Insert new package
                    $packageStmt = $db->prepare("INSERT INTO data_packages (name, network_id, package_type, data_size, validity_days) VALUES (?, ?, ?, ?, ?)");
                    $packageStmt->bind_param('sissi', $newPkg['name'], $networkRow['id'], $newPkg['type'], $newPkg['size'], $newPkg['validity']);
                    
                    if ($packageStmt->execute()) {
                        $newPackageId = $db->lastInsertId();
                        $changes++;
                        
                        // Add pricing if provided
                        if (!empty($newPkg['customer_price']) || !empty($newPkg['agent_price'])) {
                            if (!empty($newPkg['customer_price'])) {
                                $customerPrice = floatval($newPkg['customer_price']);
                                $userType = 'customer';
                                if (upsertPricingProfilePrice($selected_profile, $newPackageId, $userType, $customerPrice)) {
                                    $changes++;
                                }
                                if ($sync_live_prices && $livePriceStmt) {
                                    $livePriceStmt->bind_param('isd', $newPackageId, $userType, $customerPrice);
                                    $livePriceStmt->execute();
                                }
                            }
                            
                            if (!empty($newPkg['agent_price'])) {
                                $agentPrice = floatval($newPkg['agent_price']);
                                $userType = 'agent';
                                if (upsertPricingProfilePrice($selected_profile, $newPackageId, $userType, $agentPrice)) {
                                    $changes++;
                                }
                                if ($sync_live_prices && $livePriceStmt) {
                                    $livePriceStmt->bind_param('isd', $newPackageId, $userType, $agentPrice);
                                    $livePriceStmt->execute();
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Handle existing package pricing updates
    if (!empty($prices) && is_array($prices)) {
        foreach ($prices as $pkgId => $types) {
            $pkgId = intval($pkgId);
            
            // Verify package exists before inserting pricing
            $checkStmt = $db->prepare("SELECT id FROM data_packages WHERE id = ? LIMIT 1");
            $checkStmt->bind_param('i', $pkgId);
            $checkStmt->execute();
            $packageExists = $checkStmt->get_result()->fetch_assoc();
            
            if (!$packageExists) {
                error_log("Skipping pricing for non-existent package ID: $pkgId");
                continue;
            }
            
            foreach (['customer', 'agent'] as $userType) {
                if (isset($types[$userType]) && $types[$userType] !== '') {
                    $price = floatval($types[$userType]);
                    try {
                        if (upsertPricingProfilePrice($selected_profile, $pkgId, $userType, $price)) {
                            $changes++;
                        }
                        if ($sync_live_prices && $livePriceStmt) {
                            $livePriceStmt->bind_param('isd', $pkgId, $userType, $price);
                            $livePriceStmt->execute();
                        }
                    } catch (mysqli_sql_exception $e) {
                        error_log("Failed to insert pricing for package $pkgId: " . $e->getMessage());
                        setFlashMessage('error', "Failed to update pricing for package ID $pkgId. Package may not exist.");
                    }
                }
            }
        }
    }

    if ($changes > 0) {
        setFlashMessage('success', 'Changes saved successfully (' . $changes . ' updates)');
    } else {
        setFlashMessage('warning', 'No changes detected');
    }

    $redirectParams = [];
    $redirectParams['profile'] = $selected_profile;
    if ($request_network !== '') {
        $redirectParams['network'] = $request_network;
    }
    if ($request_page > 1) {
        $redirectParams['page'] = $request_page;
    }
    $redirectUrl = 'pricing.php' . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');

    header('Location: ' . $redirectUrl);
    exit();
}

// Filters
$selected_network = isset($_GET['network']) ? sanitize($_GET['network']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$selected_profile = normalizePricingProfile($_GET['profile'] ?? $selected_profile);
if (!isset($profile_options[$selected_profile])) {
    $selected_profile = 'default';
}
ensurePricingProfileSeeded($selected_profile, $active_profile);
$per_page = 50;

// Fetch dynamic list of networks from networks table (excluding Vodafone)
$networks = [];
$result = $db->query("SELECT name FROM networks WHERE is_active = 1 AND name != 'Vodafone' ORDER BY name ASC");
while ($row = $result->fetch_assoc()) { $networks[] = $row['name']; }

// Total count for pagination
$count_query = "
    SELECT COUNT(*) AS total
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id AND n.is_active = 1 AND n.name != 'Vodafone'
    " . ($selected_network !== '' ? " WHERE n.name = ?" : "") . "
";

if ($selected_network !== '') {
    $count_stmt = $db->prepare($count_query);
    $count_stmt->bind_param('s', $selected_network);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
} else {
    $count_result = $db->query($count_query);
}

$total_packages = 0;
if ($count_row = $count_result->fetch_assoc()) {
    $total_packages = (int)$count_row['total'];
}

$total_pages = max(1, (int)ceil($total_packages / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// Fetch packages for current page (pricing is fetched separately for speed)
$query = "
    SELECT dp.id, dp.name, n.name AS network, dp.package_type, dp.data_size, dp.validity_days
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id AND n.is_active = 1 AND n.name != 'Vodafone'
    " . ($selected_network !== '' ? " WHERE n.name = ?" : "") . "
    ORDER BY n.name, dp.package_type, dp.data_size
    LIMIT $per_page OFFSET $offset
";

if ($selected_network !== '') {
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $selected_network);
    $stmt->execute();
    $packages_rs = $stmt->get_result();
} else {
    $packages_rs = $db->query($query);
}

$packages = [];
$package_ids = [];
while ($row = $packages_rs->fetch_assoc()) {
    $row['customer_price'] = null;
    $row['agent_price'] = null;
    $packages[] = $row;
    $package_ids[] = (int)$row['id'];
}

// Fetch pricing for current page only (reduces load on large datasets)
if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    $price_query = "
        SELECT package_id,
               MAX(CASE WHEN user_type = 'customer' THEN price END) AS customer_price,
               MAX(CASE WHEN user_type = 'agent' THEN price END) AS agent_price
        FROM package_pricing_profiles
        WHERE profile_key = ? AND package_id IN ($placeholders)
        GROUP BY package_id
    ";
    $price_stmt = $db->prepare($price_query);
    $price_types = 's' . str_repeat('i', count($package_ids));
    $price_stmt->bind_param($price_types, $selected_profile, ...$package_ids);
    $price_stmt->execute();
    $prices_rs = $price_stmt->get_result();

    $prices_map = [];
    while ($price_row = $prices_rs->fetch_assoc()) {
        $prices_map[(int)$price_row['package_id']] = $price_row;
    }

    foreach ($packages as &$pkg) {
        $pkg_id = (int)$pkg['id'];
        if (isset($prices_map[$pkg_id])) {
            $pkg['customer_price'] = $prices_map[$pkg_id]['customer_price'];
            $pkg['agent_price'] = $prices_map[$pkg_id]['agent_price'];
        }
    }
    unset($pkg);
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Management - <?php echo SITE_NAME; ?></title>
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
        <ul class="sidebar-nav">
            <li class="nav-section">
                <div class="nav-section-title">Dashboard</div>
                <div class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Dashboard</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Management</div>
                <div class="nav-item"><a href="packages.php" class="nav-link"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link active"><i class="fas fa-tags"></i> Pricing</a></div>
                <div class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></div>
                <div class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i> Users</a></div>
                <div class="nav-item"><a href="agents.php" class="nav-link"><i class="fas fa-user-tie"></i> Agents</a></div>
            
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Analytics</div>
                <div class="nav-item"><a href="transactions.php" class="nav-link"><i class="fas fa-history"></i> Transactions</a></div>
                <div class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a></div>
                <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
            </li>
            <li class="nav-section">
                <div class="nav-section-title">Settings</div>
                <div class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> System Settings</a></div>
                <div class="nav-item"><a href="email-broadcast.php" class="nav-link"><i class="fas fa-paper-plane"></i> Email Broadcasts</a></div>
                <div class="nav-item"><a href="system-reset.php" class="nav-link"><i class="fas fa-broom"></i> System Reset</a></div>
            </li>
        </ul>
                <div class="nav-item"><a href="profit-withdrawals.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-tags"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Pricing</div>
                </nav>
            </div>
            
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['user_full_name'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['user_full_name'] ?? 'Admin'); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Admin</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
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
                <h1>Pricing Management</h1>
                <p class="page-subtitle">
                    Manage Customer and Agent prices for all packages.
                    Active profile: <strong><?php echo htmlspecialchars($profile_options[$active_profile] ?? ucfirst($active_profile)); ?></strong>
                </p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($selected_profile !== $active_profile): ?>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    You are editing <strong><?php echo htmlspecialchars($profile_options[$selected_profile] ?? ucfirst($selected_profile)); ?></strong>.
                    These prices will go live only after you click <strong>Make Active</strong>.
                </div>
            <?php endif; ?>

            <div class="widget">
                <div class="widget-header pricing-header">
                    <h3 class="widget-title">Packages</h3>
                    <div class="pricing-actions">
                        <form method="get" class="form-inline pricing-filter">
                            <label for="profile">Profile</label>
                            <select name="profile" id="profile" class="form-control" onchange="this.form.submit()">
                                <?php foreach ($profile_options as $profileKey => $profileLabel): ?>
                                    <option value="<?php echo htmlspecialchars($profileKey); ?>" <?php echo $selected_profile === $profileKey ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($profileLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="network">Network</label>
                            <select name="network" id="network" class="form-control" onchange="this.form.submit()">
                                <option value="">All</option>
                                <?php foreach ($networks as $net): ?>
                                    <option value="<?php echo htmlspecialchars($net); ?>" <?php echo $selected_network===$net?'selected':''; ?>><?php echo htmlspecialchars($net); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="page" value="1">
                        </form>
                        <form method="post" class="form-inline" style="display:flex; gap:.5rem; align-items:center;">
                            <input type="hidden" name="pricing_profile" value="<?php echo htmlspecialchars($selected_profile); ?>">
                            <input type="hidden" name="switch_profile" value="<?php echo htmlspecialchars($selected_profile); ?>">
                            <input type="hidden" name="network" value="<?php echo htmlspecialchars($selected_network); ?>">
                            <input type="hidden" name="page" value="<?php echo (int) $page; ?>">
                            <button type="submit" class="btn btn-outline-primary btn-sm" <?php echo $selected_profile === $active_profile ? 'disabled' : ''; ?>>
                                <i class="fas fa-toggle-on"></i> Make Active
                            </button>
                        </form>
                        <button type="button" class="btn btn-success btn-sm" onclick="addNewRow()">
                            <i class="fas fa-plus"></i> Add Row
                        </button>
                    </div>
                </div>
                <div class="widget-body">
                    <form method="post">
                        <input type="hidden" name="pricing_profile" value="<?php echo htmlspecialchars($selected_profile); ?>">
                        <input type="hidden" name="network" value="<?php echo htmlspecialchars($selected_network); ?>">
                        <input type="hidden" name="page" value="<?php echo (int) $page; ?>">
                        <div class="table-responsive">
                            <table class="table responsive-table-stack pricing-table">
                                <thead>
                                    <tr>
                                        <th>Network</th>
                                        <th>Package</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Validity</th>
                                        <th>Customer Price (<?php echo CURRENCY; ?>)</th>
                                        <th>Agent Price (<?php echo CURRENCY; ?>)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($packages)): ?>
                                    <tr><td colspan="8" class="text-center text-muted">No packages found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($packages as $pkg): ?>
                                        <tr class="pricing-row" data-package-id="<?php echo $pkg['id']; ?>">
                                            <td data-label="Network"><?php echo htmlspecialchars($pkg['network']); ?></td>
                                            <td data-label="Package"><?php echo htmlspecialchars($pkg['name']); ?></td>
                                            <td data-label="Type"><?php echo htmlspecialchars($pkg['package_type']); ?></td>
                                            <td data-label="Size"><?php echo htmlspecialchars($pkg['data_size']); ?></td>
                                            <td data-label="Validity"><?php echo intval($pkg['validity_days']); ?> days</td>
                                            <td data-label="Customer Price (<?php echo CURRENCY; ?>)" class="price-cell">
                                                <input type="number" step="0.01" min="0" name="prices[<?php echo $pkg['id']; ?>][customer]" value="<?php echo $pkg['customer_price'] !== null ? htmlspecialchars($pkg['customer_price']) : ''; ?>" class="form-control" placeholder="e.g. 10.00">
                                            </td>
                                            <td data-label="Agent Price (<?php echo CURRENCY; ?>)" class="price-cell">
                                                <input type="number" step="0.01" min="0" name="prices[<?php echo $pkg['id']; ?>][agent]" value="<?php echo $pkg['agent_price'] !== null ? htmlspecialchars($pkg['agent_price']) : ''; ?>" class="form-control" placeholder="e.g. 8.00">
                                            </td>
                                            <td data-label="Actions" class="actions-cell">
                                                <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)" title="Remove Row">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <div class="table-pagination">
                                <div class="pagination-meta">
                                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_packages; ?> packages)
                                </div>
                                <div class="pagination-links">
                                    <?php
                                        $baseParams = [];
                                        $baseParams['profile'] = $selected_profile;
                                        if ($selected_network !== '') {
                                            $baseParams['network'] = $selected_network;
                                        }
                                        $prevPage = max(1, $page - 1);
                                        $nextPage = min($total_pages, $page + 1);
                                    ?>
                                    <a class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('pricing.php?' . http_build_query(array_merge($baseParams, ['page' => $prevPage]))); ?>">Previous</a>
                                    <a class="pagination-link <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $page >= $total_pages ? '#' : ('pricing.php?' . http_build_query(array_merge($baseParams, ['page' => $nextPage]))); ?>">Next</a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex; justify-content:flex-end; margin-top:1rem;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Mobile menu toggle
    document.querySelector('.mobile-menu-toggle').addEventListener('click', function() {
        document.querySelector('.sidebar').classList.toggle('show');
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

    // Dynamic row management
    let newRowCounter = 0;
    
    function addNewRow() {
        const tbody = document.querySelector('.table tbody');
        const newRowId = 'new_' + (++newRowCounter);
        
        const newRow = document.createElement('tr');
        newRow.className = 'pricing-row new-row';
        newRow.setAttribute('data-package-id', newRowId);
        
        newRow.innerHTML = `
            <td data-label="Network">
                <select name="new_packages[${newRowId}][network]" class="form-control" required>
                    <option value="">Select Network</option>
                    <?php foreach ($networks as $net): ?>
                        <option value="<?php echo htmlspecialchars($net); ?>"><?php echo htmlspecialchars($net); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td data-label="Package"><input type="text" name="new_packages[${newRowId}][name]" class="form-control" placeholder="Package Name" required></td>
            <td data-label="Type">
                <select name="new_packages[${newRowId}][type]" class="form-control" required>
                    <option value="">Select Type</option>
                    <option value="data">Data</option>
                    <option value="voice">Voice</option>
                    <option value="sms">SMS</option>
                    <option value="combo">Combo</option>
                </select>
            </td>
            <td data-label="Size"><input type="text" name="new_packages[${newRowId}][size]" class="form-control" placeholder="e.g. 1GB" required></td>
            <td data-label="Validity"><input type="number" name="new_packages[${newRowId}][validity]" class="form-control" placeholder="Days" min="1" required></td>
            <td data-label="Customer Price (<?php echo CURRENCY; ?>)" class="price-cell"><input type="number" step="0.01" min="0" name="new_packages[${newRowId}][customer_price]" class="form-control" placeholder="e.g. 10.00"></td>
            <td data-label="Agent Price (<?php echo CURRENCY; ?>)" class="price-cell"><input type="number" step="0.01" min="0" name="new_packages[${newRowId}][agent_price]" class="form-control" placeholder="e.g. 8.00"></td>
            <td data-label="Actions" class="actions-cell">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)" title="Remove Row">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(newRow);
    }
    
    function removeRow(button) {
        const row = button.closest('tr');
        if (row.classList.contains('new-row')) {
            // Just remove new rows
            row.remove();
        } else {
            // For existing rows, confirm deletion
            if (confirm('Are you sure you want to remove this package? This action cannot be undone.')) {
                row.style.display = 'none';
                // Add hidden input to mark for deletion
                const packageId = row.getAttribute('data-package-id');
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'delete_packages[]';
                hiddenInput.value = packageId;
                row.appendChild(hiddenInput);
            }
        }
    }

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



