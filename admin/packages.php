<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

// Handle package operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $return_network = isset($_POST['return_network']) ? sanitize($_POST['return_network']) : '';
    $return_type = isset($_POST['return_type']) ? sanitize($_POST['return_type']) : '';
    $redirectParams = [];
    if ($return_network !== '') {
        $redirectParams['network'] = $return_network;
    }
    if ($return_type !== '') {
        $redirectParams['type'] = $return_type;
    }
    $redirectUrl = 'packages.php' . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');
    
    if ($action === 'add') {
        $name = sanitize($_POST['name']);
        $network = sanitize($_POST['network']);
        $type = sanitize($_POST['type']);
        $size = sanitize($_POST['size']);
        $validity = intval($_POST['validity']);
        
        if ($name && $network && $type && $size && $validity) {
            // Get network ID
            $networkStmt = $db->prepare("SELECT id FROM networks WHERE name = ? LIMIT 1");
            $networkStmt->bind_param('s', $network);
            $networkStmt->execute();
            $networkResult = $networkStmt->get_result();
            
            if ($networkRow = $networkResult->fetch_assoc()) {
                $stmt = $db->prepare("INSERT INTO data_packages (name, network_id, package_type, data_size, validity_days) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sissi', $name, $networkRow['id'], $type, $size, $validity);
                
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Package added successfully');
                } else {
                    setFlashMessage('error', 'Failed to add package');
                }
            } else {
                setFlashMessage('error', 'Invalid network selected');
            }
        } else {
            setFlashMessage('error', 'All fields are required');
        }
        header('Location: ' . $redirectUrl);
        exit();
    }

    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $network = sanitize($_POST['network'] ?? '');
        $type = sanitize($_POST['type'] ?? '');
        $size = sanitize($_POST['size'] ?? '');
        $validity = intval($_POST['validity'] ?? 0);

        if ($id > 0 && $name !== '' && $network !== '' && $type !== '' && $size !== '' && $validity > 0) {
            $networkStmt = $db->prepare("SELECT id FROM networks WHERE name = ? LIMIT 1");
            $networkStmt->bind_param('s', $network);
            $networkStmt->execute();
            $networkResult = $networkStmt->get_result();

            if ($networkRow = $networkResult->fetch_assoc()) {
                $stmt = $db->prepare("UPDATE data_packages SET name = ?, network_id = ?, package_type = ?, data_size = ?, validity_days = ? WHERE id = ?");
                $networkId = (int) $networkRow['id'];
                $stmt->bind_param('sissii', $name, $networkId, $type, $size, $validity, $id);

                if ($stmt->execute()) {
                    setFlashMessage('success', 'Package updated successfully');
                } else {
                    setFlashMessage('error', 'Failed to update package');
                }
            } else {
                setFlashMessage('error', 'Invalid network selected');
            }
        } else {
            setFlashMessage('error', 'All fields are required and validity must be greater than 0');
        }
        header('Location: ' . $redirectUrl);
        exit();
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $db->prepare("DELETE FROM data_packages WHERE id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            setFlashMessage('success', 'Package deleted successfully');
        } else {
            setFlashMessage('error', 'Failed to delete package');
        }
        header('Location: ' . $redirectUrl);
        exit();
    }
}

// Fetch filters
$selected_network = isset($_GET['network']) ? sanitize($_GET['network']) : '';
$selected_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';

// Fetch networks
$networks = [];
$result = $db->query("SELECT name FROM networks WHERE is_active = 1 AND name != 'Vodafone' ORDER BY name ASC");
while ($row = $result->fetch_assoc()) { $networks[] = $row['name']; }

// Fetch packages
$query = "
    SELECT dp.id, dp.name, n.name AS network, dp.package_type, dp.data_size, dp.validity_days, dp.created_at,
           COUNT(bo.id) as total_orders
    FROM data_packages dp
    JOIN networks n ON n.id = dp.network_id AND n.is_active = 1 AND n.name != 'Vodafone'
    LEFT JOIN bundle_orders bo ON bo.package_id = dp.id
    WHERE 1=1
";

$params = [];
$types = '';

if ($selected_network !== '') {
    $query .= " AND n.name = ?";
    $params[] = $selected_network;
    $types .= 's';
}

if ($selected_type !== '') {
    $query .= " AND dp.package_type = ?";
    $params[] = $selected_type;
    $types .= 's';
}

$query .= " GROUP BY dp.id, dp.name, n.name, dp.package_type, dp.data_size, dp.validity_days, dp.created_at ORDER BY n.name, dp.package_type, dp.data_size";

if (!empty($params)) {
    $stmt = $db->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $packages_rs = $stmt->get_result();
} else {
    $packages_rs = $db->query($query);
}

$packages = [];
while ($row = $packages_rs->fetch_assoc()) { $packages[] = $row; }

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Packages - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
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
                <div class="nav-item"><a href="packages.php" class="nav-link active"><i class="fas fa-box"></i> Data Packages</a></div>
                <div class="nav-item"><a href="pricing.php" class="nav-link"><i class="fas fa-tags"></i> Pricing</a></div>
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
                <div class="nav-item"><a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notification Settings</a></div>
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
                    <div class="breadcrumb-item"><i class="fas fa-box"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Data Packages</div>
                </nav>
            </div>
                <div class="header-actions">
                    <button class="theme-toggle" onclick="toggleTheme()">
                        <i class="fas fa-sun" id="theme-icon"></i>
                    </button>
                    
                    <div class="user-dropdown">
                        <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
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
                <h1>Data Packages</h1>
                <p class="page-subtitle">Manage data packages across all networks.</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom:1rem;">
                    <?php echo htmlspecialchars($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- Add Package Form -->
            <div class="widget" style="margin-bottom: 2rem;">
                <div class="widget-header">
                    <h3 class="widget-title">Add New Package</h3>
                </div>
                <div class="widget-body">
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="return_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                        <input type="hidden" name="return_type" value="<?php echo htmlspecialchars($selected_type); ?>">
                        <div class="form-group">
                            <label for="name">Package Name</label>
                            <input type="text" id="name" name="name" class="form-control" required placeholder="e.g. MTN 1GB Daily">
                        </div>
                        <div class="form-group">
                            <label for="network">Network</label>
                            <select id="network" name="network" class="form-control" required>
                                <option value="">Select Network</option>
                                <?php foreach ($networks as $net): ?>
                                    <option value="<?php echo htmlspecialchars($net); ?>"><?php echo htmlspecialchars($net); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="type">Package Type</label>
                            <select id="type" name="type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="data">Data</option>
                                <option value="voice">Voice</option>
                                <option value="sms">SMS</option>
                                <option value="combo">Combo</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="size">Data Size</label>
                            <input type="text" id="size" name="size" class="form-control" required placeholder="e.g. 1GB, 500MB">
                        </div>
                        <div class="form-group">
                            <label for="validity">Validity (Days)</label>
                            <input type="number" id="validity" name="validity" class="form-control" required min="1" placeholder="e.g. 30">
                        </div>
                        <div class="form-group form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add Package
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Packages List -->
            <div class="widget">
                <div class="widget-header packages-header">
                    <div class="packages-toolbar">
                        <h3 class="widget-title">All Packages (<?php echo count($packages); ?>)</h3>
                        <form method="get" class="form-inline packages-filter">
                            <select name="network" class="form-control" onchange="this.form.submit()">
                                <option value="">All Networks</option>
                                <?php foreach ($networks as $net): ?>
                                    <option value="<?php echo htmlspecialchars($net); ?>" <?php echo $selected_network===$net?'selected':''; ?>><?php echo htmlspecialchars($net); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="type" class="form-control" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <option value="data" <?php echo $selected_type==='data'?'selected':''; ?>>Data</option>
                                <option value="voice" <?php echo $selected_type==='voice'?'selected':''; ?>>Voice</option>
                                <option value="sms" <?php echo $selected_type==='sms'?'selected':''; ?>>SMS</option>
                                <option value="combo" <?php echo $selected_type==='combo'?'selected':''; ?>>Combo</option>
                            </select>
                        </form>
                    </div>
                    <?php if (count($packages) > 0): ?>
                        <div>
                            <a href="reset_packages.php" class="btn btn-danger btn-sm" onclick="return confirm('This will take you to the reset page where you can safely reset all packages. Continue?')">
                                <i class="fas fa-trash-alt"></i> Reset All Packages
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="widget-body">
                    <div class="table-responsive">
                        <table class="table responsive-table-stack packages-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Network</th>
                                    <th>Package Name</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Validity</th>
                                    <th>Orders</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($packages)): ?>
                                <tr><td colspan="9" class="text-center text-muted">No packages found</td></tr>
                            <?php else: ?>
                                <?php foreach ($packages as $pkg): ?>
                                    <?php $updateFormId = 'update-package-' . intval($pkg['id']); ?>
                                    <tr>
                                        <td data-label="ID">
                                            <?php echo $pkg['id']; ?>
                                            <form id="<?php echo $updateFormId; ?>" method="post" style="display:none;">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                                <input type="hidden" name="return_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                                                <input type="hidden" name="return_type" value="<?php echo htmlspecialchars($selected_type); ?>">
                                            </form>
                                        </td>
                                        <td data-label="Network">
                                            <select name="network" class="form-control" required form="<?php echo $updateFormId; ?>">
                                                <?php foreach ($networks as $net): ?>
                                                    <option value="<?php echo htmlspecialchars($net); ?>" <?php echo $pkg['network'] === $net ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($net); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td data-label="Package Name">
                                            <input type="text" name="name" class="form-control" required form="<?php echo $updateFormId; ?>" value="<?php echo htmlspecialchars($pkg['name']); ?>">
                                        </td>
                                        <td data-label="Type">
                                            <select name="type" class="form-control" required form="<?php echo $updateFormId; ?>">
                                                <option value="data" <?php echo $pkg['package_type']==='data' ? 'selected' : ''; ?>>Data</option>
                                                <option value="voice" <?php echo $pkg['package_type']==='voice' ? 'selected' : ''; ?>>Voice</option>
                                                <option value="sms" <?php echo $pkg['package_type']==='sms' ? 'selected' : ''; ?>>SMS</option>
                                                <option value="combo" <?php echo $pkg['package_type']==='combo' ? 'selected' : ''; ?>>Combo</option>
                                            </select>
                                        </td>
                                        <td data-label="Size">
                                            <input type="text" name="size" class="form-control" required form="<?php echo $updateFormId; ?>" value="<?php echo htmlspecialchars($pkg['data_size']); ?>">
                                        </td>
                                        <td data-label="Validity">
                                            <input type="number" name="validity" class="form-control" min="1" required form="<?php echo $updateFormId; ?>" value="<?php echo intval($pkg['validity_days']); ?>">
                                        </td>
                                        <td data-label="Orders"><?php echo intval($pkg['total_orders']); ?></td>
                                        <td data-label="Created"><?php echo date('M j, Y', strtotime($pkg['created_at'])); ?></td>
                                        <td data-label="Actions" style="white-space: nowrap;">
                                            <button type="submit" form="<?php echo $updateFormId; ?>" class="btn btn-primary btn-sm" title="Save Package">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this package?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                                <input type="hidden" name="return_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                                                <input type="hidden" name="return_type" value="<?php echo htmlspecialchars($selected_type); ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete Package">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();
    });
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>



