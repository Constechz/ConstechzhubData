<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/api_providers.php';

// Require admin role
requireRole('admin');

$current_user = getCurrentUser();

// Providers to hide from this admin screen.
$excluded_provider_terms = ['arkesel', 'etruba hub', 'etrubahub'];
$is_provider_excluded = static function ($name, $slug = '') use ($excluded_provider_terms) {
    $name = strtolower(trim((string) $name));
    $slug = strtolower(trim((string) $slug));

    foreach ($excluded_provider_terms as $term) {
        if (($name !== '' && strpos($name, $term) !== false) || ($slug !== '' && strpos($slug, $term) !== false)) {
            return true;
        }
    }

    return false;
};

$fetch_provider_by_id = static function ($provider_id) use ($db) {
    $provider_id = (int) $provider_id;
    if ($provider_id <= 0) {
        return null;
    }

    $stmt = $db->prepare("SELECT id, name, slug FROM api_providers WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $provider_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
};

$is_provider_allowed_id = static function ($provider_id) use ($fetch_provider_by_id, $is_provider_excluded) {
    $provider = $fetch_provider_by_id($provider_id);
    if (!$provider) {
        return false;
    }

    return !$is_provider_excluded($provider['name'] ?? '', $provider['slug'] ?? '');
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'switch_provider':
                $network_id = intval($_POST['network_id']);
                $primary_provider_id = intval($_POST['primary_provider_id']);
                $backup_provider_id = !empty($_POST['backup_provider_id']) ? intval($_POST['backup_provider_id']) : null;

                $primary_allowed = $is_provider_allowed_id($primary_provider_id);
                $backup_allowed = $backup_provider_id === null || $is_provider_allowed_id($backup_provider_id);

                if (!$primary_allowed || !$backup_allowed) {
                    setFlashMessage('error', 'Selected provider is not available for configuration.');
                    break;
                }

                if (switchNetworkProvider($network_id, $primary_provider_id, $backup_provider_id)) {
                    setFlashMessage('success', 'Provider configuration updated successfully');
                } else {
                    setFlashMessage('error', 'Failed to update provider configuration');
                }
                break;
                
            case 'toggle_provider':
                $provider_id = intval($_POST['provider_id']);
                $is_active = intval($_POST['is_active']);

                if (!$is_provider_allowed_id($provider_id)) {
                    setFlashMessage('error', 'This provider cannot be managed from this page.');
                    break;
                }

                $conn = $db->getConnection();
                $conn->begin_transaction();

                try {
                    $stmt = $db->prepare("UPDATE api_providers SET is_active = ? WHERE id = ?");
                    $stmt->bind_param('ii', $is_active, $provider_id);
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update provider status');
                    }

                    // When deactivated, block any network mappings and endpoints that reference this provider.
                    $endpoint_flag = $is_active ? 1 : 0;
                    $stmt = $db->prepare("UPDATE provider_endpoints SET is_active = ? WHERE provider_id = ?");
                    $stmt->bind_param('ii', $endpoint_flag, $provider_id);
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update provider endpoints');
                    }

                    $stmt = $db->prepare("
                        UPDATE network_providers
                        SET is_active = ?
                        WHERE primary_provider_id = ? OR backup_provider_id = ?
                    ");
                    $stmt->bind_param('iii', $endpoint_flag, $provider_id, $provider_id);
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to update network mappings');
                    }

                    $conn->commit();
                    $status = $is_active ? 'activated' : 'deactivated';
                    setFlashMessage('success', "Provider {$status} successfully");
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log('Provider toggle failed: ' . $e->getMessage());
                    setFlashMessage('error', 'Failed to update provider status');
                }
                break;

            case 'save_provider':
                $provider_id = intval($_POST['provider_id'] ?? 0);
                $base_url = trim($_POST['base_url'] ?? '');
                $auth_type = $_POST['auth_type'] ?? 'bearer';
                $auth_token = trim($_POST['auth_token'] ?? '');
                $timeout_seconds = intval($_POST['timeout_seconds'] ?? 20);
                $retry_attempts = intval($_POST['retry_attempts'] ?? 3);
                $description = isset($_POST['description']) ? trim($_POST['description']) : null;
                
                $valid_auth_types = ['bearer', 'api_key', 'header'];
                $timeout_seconds = $timeout_seconds > 0 ? $timeout_seconds : 20;
                $retry_attempts = $retry_attempts >= 0 ? $retry_attempts : 0;

                if (!$is_provider_allowed_id($provider_id)) {
                    setFlashMessage('error', 'This provider cannot be managed from this page.');
                    break;
                }

                if ($provider_id <= 0 || empty($base_url) || empty($auth_token) || !in_array($auth_type, $valid_auth_types, true)) {
                    setFlashMessage('error', 'Invalid provider details submitted. Please review and try again.');
                    break;
                }
                
                $stmt = $db->prepare("
                    UPDATE api_providers 
                    SET base_url = ?, auth_type = ?, auth_token = ?, timeout_seconds = ?, retry_attempts = ?, description = ? 
                    WHERE id = ?
                ");
                
                if (!$stmt) {
                    setFlashMessage('error', 'Failed to prepare provider update statement.');
                    break;
                }
                
                $stmt->bind_param(
                    'sssiisi',
                    $base_url,
                    $auth_type,
                    $auth_token,
                    $timeout_seconds,
                    $retry_attempts,
                    $description,
                    $provider_id
                );
                
                $update_success = $stmt->execute();
                
                $endpoint_success = true;
                if (!empty($_POST['endpoints']) && is_array($_POST['endpoints'])) {
                    foreach ($_POST['endpoints'] as $endpoint_data) {
                        $endpoint_id = intval($endpoint_data['id'] ?? 0);
                        $endpoint_type = $endpoint_data['endpoint_type'] ?? 'regular';
                        $endpoint_url = trim($endpoint_data['endpoint_url'] ?? '');
                        $request_format = trim($endpoint_data['request_format'] ?? '');
                        $response_format = trim($endpoint_data['response_format'] ?? '');
                        $is_active = isset($endpoint_data['is_active']) ? intval($endpoint_data['is_active']) : 0;
                        
                        $valid_endpoint_types = ['regular', 'bigtime', 'special'];
                        if (!in_array($endpoint_type, $valid_endpoint_types, true)) {
                            $endpoint_type = 'regular';
                        }
                        
                        if ($endpoint_id <= 0 || empty($endpoint_url)) {
                            $endpoint_success = false;
                            continue;
                        }
                        
                        $endpoint_stmt = $db->prepare("
                            UPDATE provider_endpoints
                            SET endpoint_type = ?, endpoint_url = ?, request_format = ?, response_format = ?, is_active = ?
                            WHERE id = ? AND provider_id = ?
                        ");
                        
                        if (!$endpoint_stmt) {
                            $endpoint_success = false;
                            continue;
                        }
                        
                        $endpoint_stmt->bind_param(
                            'ssssiii',
                            $endpoint_type,
                            $endpoint_url,
                            $request_format,
                            $response_format,
                            $is_active,
                            $endpoint_id,
                            $provider_id
                        );
                        
                        if (!$endpoint_stmt->execute()) {
                            $endpoint_success = false;
                        }
                        
                        $endpoint_stmt->close();
                    }
                }
                
                if ($update_success && $endpoint_success) {
                    setFlashMessage('success', 'Provider API details updated successfully.');
                } elseif ($update_success) {
                    setFlashMessage('warning', 'Provider details saved, but some endpoint updates may have failed.');
                } else {
                    setFlashMessage('error', 'Failed to update provider details.');
                }
                break;
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get all providers
$providers = [];
$result = $db->query("SELECT * FROM api_providers ORDER BY name");
while ($row = $result->fetch_assoc()) {
    if ($is_provider_excluded($row['name'] ?? '', $row['slug'] ?? '')) {
        continue;
    }
    $providers[] = $row;
}
$visible_provider_lookup = [];
foreach ($providers as $provider_row) {
    $visible_provider_lookup[(int)($provider_row['id'] ?? 0)] = true;
}

// Get all networks
$networks = [];
$result = $db->query("SELECT * FROM networks WHERE is_active = 1 ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $networks[] = $row;
}

// Get current network provider mappings
$network_mappings = [];
$result = $db->query("
    SELECT 
        np.*,
        n.name as network_name,
        pp.name as primary_provider_name,
        bp.name as backup_provider_name
    FROM network_providers np
    JOIN networks n ON np.network_id = n.id
    JOIN api_providers pp ON np.primary_provider_id = pp.id
    LEFT JOIN api_providers bp ON np.backup_provider_id = bp.id
    WHERE np.is_active = 1
    ORDER BY n.name
");
while ($row = $result->fetch_assoc()) {
    $primary_provider_id = (int)($row['primary_provider_id'] ?? 0);
    $backup_provider_id = (int)($row['backup_provider_id'] ?? 0);

    if (!isset($visible_provider_lookup[$primary_provider_id])) {
        continue;
    }

    if ($backup_provider_id > 0 && !isset($visible_provider_lookup[$backup_provider_id])) {
        $row['backup_provider_id'] = null;
        $row['backup_provider_name'] = null;
    }

    $network_mappings[] = $row;
}

// Get provider statistics
$provider_stats = getProviderStats(7);
if (!empty($provider_stats)) {
    $provider_stats = array_values(array_filter($provider_stats, static function ($stat) use ($is_provider_excluded) {
        return !$is_provider_excluded($stat['provider_name'] ?? '', '');
    }));
}

// Load provider-specific endpoint configurations
$provider_endpoints = [];
$endpoint_result = $db->query("
    SELECT 
        pe.*,
        n.name AS network_name
    FROM provider_endpoints pe
    JOIN networks n ON pe.network_id = n.id
    ORDER BY pe.provider_id, n.name, pe.endpoint_type
");

if ($endpoint_result) {
    while ($row = $endpoint_result->fetch_assoc()) {
        $provider_id = (int)$row['provider_id'];
        if (!isset($visible_provider_lookup[$provider_id])) {
            continue;
        }
        if (!isset($provider_endpoints[$provider_id])) {
            $provider_endpoints[$provider_id] = [];
        }
        $provider_endpoints[$provider_id][] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $current_user['theme'] ?? 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Providers - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <nav class="sidebar">
            <div class="sidebar-header">
                <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
            </div>
            <ul class="nav-menu">
                <li class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="fas fa-chart-line"></i>
                            Dashboard
                        </a>
                    </div>
                    <div class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></div>
                </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <div class="nav-item">
                        <a href="users.php" class="nav-link">
                            <i class="fas fa-users"></i>
                            Users
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="agents.php" class="nav-link">
                            <i class="fas fa-user-tie"></i>
                            Agents
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="packages.php" class="nav-link">
                            <i class="fas fa-box"></i>
                            Packages
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="pricing.php" class="nav-link">
                            <i class="fas fa-tags"></i>
                            Pricing
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="afa-registration.php" class="nav-link">
                            <i class="fas fa-user-check"></i>
                            AFA Registration
                        </a>
                    </div>
                
                <div class="nav-item"><a href="result-checker.php" class="nav-link"><i class="fas fa-award"></i> Result Checker</a></div>
            </li>
                
                <li class="nav-section">
                    <div class="nav-section-title">System</div>
                    <div class="nav-item">
                        <a href="api-providers.php" class="nav-link active">
                            <i class="fas fa-plug"></i>
                            API Providers
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="fas fa-cog"></i>
                            Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="email-broadcast.php" class="nav-link">
                            <i class="fas fa-paper-plane"></i>
                            Email Broadcasts
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="system-reset.php" class="nav-link">
                            <i class="fas fa-broom"></i>
                            System Reset
                        </a>
                    </div>
                </li>
            </ul>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="dashboard-header">
                <div class="header-left">
                    <button class="mobile-menu-toggle" type="button">
                        <i class="fas fa-bars"></i>
                    </button>
                    <nav class="breadcrumb">
                        <a href="dashboard.php">Dashboard</a>
                        <span class="separator">/</span>
                        <span class="current">API Providers</span>
                    </nav>
                </div>
                <div class="header-right">
                    <button id="theme-toggle" class="theme-toggle" title="Toggle theme">
                        <i class="fas fa-moon" id="theme-icon"></i>
                    </button>
                    <a href="../logout.php" class="logout-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </header>

            <div class="content-wrapper">
                <div class="page-header">
                    <div class="page-title">
                        <h1>API Providers Management</h1>
                        <p class="page-subtitle">Configure and monitor data bundle API providers</p>
                    </div>
                </div>

                <?php if (hasFlashMessage()): ?>
                    <?php $flash = getFlashMessage(); ?>
                    <?php if ($flash && isset($flash['type']) && isset($flash['message'])): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
                            <?php echo htmlspecialchars($flash['message']); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Provider Status Cards -->
                <div class="stats-grid">
                    <?php foreach ($providers as $provider): ?>
                        <div class="stat-card">
                            <div class="stat-icon <?php echo $provider['is_active'] ? 'success' : 'danger'; ?>">
                                <i class="fas fa-plug"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo htmlspecialchars($provider['name']); ?></h3>
                                <p><?php echo $provider['is_active'] ? 'Active' : 'Inactive'; ?></p>
                                <div class="stat-actions">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openProviderSettingsModal(<?php echo $provider['id']; ?>)">
                                        <i class="fas fa-sliders-h"></i> Manage API
                                    </button>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="toggle_provider">
                                        <input type="hidden" name="provider_id" value="<?php echo $provider['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $provider['is_active'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $provider['is_active'] ? 'btn-danger' : 'btn-success'; ?>">
                                            <?php echo $provider['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
        </div>
    </div>
</div>

<?php foreach ($providers as $provider): ?>
    <?php
        $provider_id = (int)($provider['id'] ?? 0);
        $provider_auth_type = $provider['auth_type'] ?? 'bearer';
        $provider_timeout = isset($provider['timeout_seconds']) ? (int)$provider['timeout_seconds'] : 20;
        $provider_retry = isset($provider['retry_attempts']) ? (int)$provider['retry_attempts'] : 3;
        $provider_description = $provider['description'] ?? '';
        $provider_endpoint_list = $provider_endpoints[$provider_id] ?? [];
    ?>
    <div id="providerModal_<?php echo $provider_id; ?>" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(46, 41, 78, 0.5); z-index: 9999;">
        <div class="modal-content modal-wide">
            <span class="close" onclick="closeProviderSettingsModal(<?php echo $provider_id; ?>)">&times;</span>
            <h2>Manage <?php echo htmlspecialchars($provider['name']); ?> API</h2>
            <form method="POST" class="provider-settings-form">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider_id" value="<?php echo $provider_id; ?>">
                
                <div class="form-grid two-columns">
                    <div class="form-group">
                        <label class="form-label">Provider Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($provider['name']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Provider Slug</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($provider['slug']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base URL</label>
                        <input type="text" class="form-control" name="base_url" value="<?php echo htmlspecialchars($provider['base_url']); ?>" required>
                        <small class="form-help">Example: https://api.example.com/v1</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Authentication Type</label>
                        <select name="auth_type" class="form-control" required>
                            <?php
                                $auth_options = [
                                    'bearer' => 'Bearer Token (Authorization header)',
                                    'api_key' => 'API Key (X-API-Key header)',
                                    'header' => 'Custom Header Token'
                                ];
                            ?>
                            <?php foreach ($auth_options as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $provider_auth_type === $key ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Authentication Token / Key</label>
                        <textarea name="auth_token" class="form-control" rows="3" required><?php echo htmlspecialchars($provider['auth_token']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Request Timeout (seconds)</label>
                        <input type="number" name="timeout_seconds" class="form-control" min="5" max="120" value="<?php echo $provider_timeout; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Retry Attempts</label>
                        <input type="number" name="retry_attempts" class="form-control" min="0" max="5" value="<?php echo $provider_retry; ?>" required>
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Description (optional)</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Short note to describe this integration"><?php echo htmlspecialchars($provider_description); ?></textarea>
                    </div>
                </div>

                <div class="endpoint-sections">
                    <h3>Endpoint Configuration</h3>
                    <?php if (!empty($provider_endpoint_list)): ?>
                        <?php foreach ($provider_endpoint_list as $endpoint): ?>
                            <div class="endpoint-card">
                                <input type="hidden" name="endpoints[<?php echo $endpoint['id']; ?>][id]" value="<?php echo (int)$endpoint['id']; ?>">
                                <input type="hidden" name="endpoints[<?php echo $endpoint['id']; ?>][network_id]" value="<?php echo (int)$endpoint['network_id']; ?>">
                                
                                <div class="endpoint-header">
                                    <div>
                                        <h4><?php echo htmlspecialchars($endpoint['network_name']); ?></h4>
                                        <span class="endpoint-type-label"><?php echo strtoupper(htmlspecialchars($endpoint['endpoint_type'])); ?> endpoint</span>
                                    </div>
                                    <div class="endpoint-status">
                                        <label class="form-label">Status</label>
                                        <select name="endpoints[<?php echo $endpoint['id']; ?>][is_active]" class="form-control">
                                            <option value="1" <?php echo !empty($endpoint['is_active']) ? 'selected' : ''; ?>>Active</option>
                                            <option value="0" <?php echo empty($endpoint['is_active']) ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-grid two-columns compact-grid">
                                    <div class="form-group">
                                        <label class="form-label">Endpoint Type</label>
                                        <select name="endpoints[<?php echo $endpoint['id']; ?>][endpoint_type]" class="form-control">
                                            <option value="regular" <?php echo $endpoint['endpoint_type'] === 'regular' ? 'selected' : ''; ?>>Regular</option>
                                            <option value="bigtime" <?php echo $endpoint['endpoint_type'] === 'bigtime' ? 'selected' : ''; ?>>Big Time</option>
                                            <option value="special" <?php echo $endpoint['endpoint_type'] === 'special' ? 'selected' : ''; ?>>Special</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Endpoint Path</label>
                                        <input type="text" class="form-control" name="endpoints[<?php echo $endpoint['id']; ?>][endpoint_url]" value="<?php echo htmlspecialchars($endpoint['endpoint_url']); ?>" required>
                                    </div>
                                    <div class="form-group full-width">
                                        <label class="form-label">Request Format (JSON template)</label>
                                        <textarea class="form-control code-block" name="endpoints[<?php echo $endpoint['id']; ?>][request_format]" rows="4" required><?php echo htmlspecialchars($endpoint['request_format']); ?></textarea>
                                    </div>
                                    <div class="form-group full-width">
                                        <label class="form-label">Response Format (JSON template)</label>
                                        <textarea class="form-control code-block" name="endpoints[<?php echo $endpoint['id']; ?>][response_format]" rows="4" required><?php echo htmlspecialchars($endpoint['response_format']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-endpoints">
                            <p>No endpoints configured for this provider yet.</p>
                            <p class="text-muted">Add endpoints directly in the database or extend this panel to support new endpoint creation.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeProviderSettingsModal(<?php echo $provider_id; ?>)">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Network Provider Configuration -->
                <div class="widget">
                    <div class="widget-header">
                        <h3 class="widget-title">Network Provider Configuration</h3>
                    </div>
                    <div class="widget-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Network</th>
                                        <th>Primary Provider</th>
                                        <th>Backup Provider</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($network_mappings as $mapping): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($mapping['network_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo htmlspecialchars($mapping['primary_provider_name']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($mapping['backup_provider_name']): ?>
                                                    <span class="badge badge-secondary">
                                                        <?php echo htmlspecialchars($mapping['backup_provider_name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="openConfigModal(<?php echo $mapping['network_id']; ?>, '<?php echo htmlspecialchars($mapping['network_name']); ?>', <?php echo $mapping['primary_provider_id']; ?>, <?php echo $mapping['backup_provider_id'] ?: 'null'; ?>)">
                                                    <i class="fas fa-edit"></i> Configure
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Provider Statistics -->
                <?php if (!empty($provider_stats)): ?>
                    <div class="widget">
                        <div class="widget-header">
                            <h3 class="widget-title">Provider Performance (Last 7 Days)</h3>
                        </div>
                        <div class="widget-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Provider</th>
                                            <th>Network</th>
                                            <th>Total Requests</th>
                                            <th>Success Rate</th>
                                            <th>Avg Response Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($provider_stats as $stat): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($stat['provider_name']); ?></td>
                                                <td><?php echo htmlspecialchars($stat['network_name']); ?></td>
                                                <td><?php echo number_format($stat['total_requests']); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $stat['success_rate_percent'] >= 95 ? 'badge-success' : ($stat['success_rate_percent'] >= 80 ? 'badge-warning' : 'badge-danger'); ?>">
                                                        <?php echo number_format($stat['success_rate_percent'], 1); ?>%
                                                    </span>
                                                </td>
                                                <td><?php echo number_format($stat['avg_response_time_ms']); ?>ms</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Configuration Modal -->
    <div id="configModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(46, 41, 78, 0.5); z-index: 9999;">
        <div class="modal-content">
            <span class="close" onclick="closeConfigModal()">&times;</span>
            <h2>Configure Network Provider</h2>
            <form method="POST" id="configForm">
                <input type="hidden" name="action" value="switch_provider">
                <input type="hidden" name="network_id" id="modal_network_id">
                
                <div class="form-group">
                    <label class="form-label">Network</label>
                    <input type="text" id="modal_network_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Primary Provider</label>
                    <select name="primary_provider_id" id="modal_primary_provider" class="form-control" required>
                        <?php foreach ($providers as $provider): ?>
                            <?php if ($provider['is_active']): ?>
                                <option value="<?php echo $provider['id']; ?>"><?php echo htmlspecialchars($provider['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Backup Provider (Optional)</label>
                    <select name="backup_provider_id" id="modal_backup_provider" class="form-control">
                        <option value="">None</option>
                        <?php foreach ($providers as $provider): ?>
                            <?php if ($provider['is_active']): ?>
                                <option value="<?php echo $provider['id']; ?>"><?php echo htmlspecialchars($provider['name']); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeConfigModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Configuration</button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
    .modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(46, 41, 78, 0.5);
    }
    
    .modal-content {
        background-color: var(--card-bg, #F1E9DA);
        margin: 5% auto;
        padding: 20px;
        border-radius: 8px;
        width: 90%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
        box-shadow: 0 4px 20px rgba(46, 41, 78, 0.3);
    }
    
    [data-theme="dark"] .modal-content {
        background-color: #2E294E;
        color: #F1E9DA;
    }
    
    .modal .close {
        color: var(--text-color, #F1E9DA);
        float: right;
        font-size: 28px;
        font-weight: bold;
        position: absolute;
        top: 10px;
        right: 15px;
        cursor: pointer;
    }
    
    [data-theme="dark"] .modal .close {
        color: #F1E9DA;
    }
    
    .modal .close:hover,
    .modal .close:focus {
        color: var(--primary-color, #541388);
        text-decoration: none;
    }
    
    .modal h2 {
        margin-top: 0;
        margin-bottom: 20px;
        color: var(--text-color, #2E294E);
    }
    
    [data-theme="dark"] .modal h2 {
        color: #F1E9DA;
    }
    
    .modal .form-group {
        margin-bottom: 20px;
    }
    
    .modal .form-label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
        color: var(--text-color, #2E294E);
    }
    
    [data-theme="dark"] .modal .form-label {
        color: #F1E9DA;
    }
    
    .modal .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border-color, #F1E9DA);
        border-radius: 4px;
        background: var(--input-bg, #F1E9DA);
        color: var(--text-color, #2E294E);
        font-size: 14px;
    }
    
    [data-theme="dark"] .modal .form-control {
        background: #2E294E;
        border-color: #541388;
        color: #F1E9DA;
    }
    
    .modal .form-control:focus {
        outline: none;
        border-color: var(--primary-color, #541388);
        box-shadow: 0 0 0 2px rgba(84, 19, 136, 0.25);
    }
    
    .modal .form-control[readonly] {
        background-color: var(--input-disabled-bg, #F1E9DA);
    }
    
    [data-theme="dark"] .modal .form-control[readonly] {
        background-color: #2E294E;
    }
    
    .modal .form-actions {
        text-align: right;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid var(--border-color, #F1E9DA);
    }
    
    [data-theme="dark"] .modal .form-actions {
        border-top-color: #2E294E;
    }
    
    .modal .btn {
        padding: 10px 20px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        margin-left: 10px;
        border: none;
    }
    
    .modal .btn-secondary {
        background: var(--bg-secondary, #541388);
        color: #F1E9DA;
    }
    
    [data-theme="dark"] .modal .btn-secondary {
        background: #2E294E;
        color: #F1E9DA;
    }
    
    .modal .btn-primary {
        background: var(--primary-color, #541388);
        color: #F1E9DA;
    }
    
    .modal .btn:hover {
        opacity: 0.9;
    }

    .modal-content.modal-wide {
        max-width: 920px;
    }

    .provider-settings-form .two-columns {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
    }

    .provider-settings-form .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-help {
        display: block;
        margin-top: 6px;
        font-size: 12px;
        color: var(--text-muted, #541388);
    }

    [data-theme="dark"] .form-help {
        color: #F1E9DA;
    }

    .endpoint-sections {
        margin-top: 30px;
    }

    .endpoint-sections h3 {
        margin-bottom: 15px;
    }

    .endpoint-card {
        background: var(--card-muted-bg, #F1E9DA);
        border: 1px solid var(--border-color, #F1E9DA);
        border-radius: 6px;
        padding: 18px;
        margin-bottom: 20px;
    }

    [data-theme="dark"] .endpoint-card {
        background: #2E294E;
        border-color: #2E294E;
    }

    .endpoint-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
        gap: 16px;
    }

    .endpoint-header h4 {
        margin: 0;
    }

    .endpoint-type-label {
        display: inline-block;
        margin-top: 6px;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted, #541388);
    }

    [data-theme="dark"] .endpoint-type-label {
        color: #F1E9DA;
    }

    .endpoint-status {
        min-width: 160px;
    }

    .compact-grid {
        gap: 16px;
    }

    .code-block {
        font-family: "Courier New", Courier, monospace;
    }

    .empty-endpoints {
        padding: 20px;
        border: 1px dashed var(--border-color, #F1E9DA);
        border-radius: 6px;
        background: rgba(84, 19, 136, 0.05);
    }

    [data-theme="dark"] .empty-endpoints {
        background: rgba(84, 19, 136, 0.1);
        border-color: #2E294E;
    }

    .stat-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 8px;
        flex-wrap: wrap;
    }

    .stat-actions .btn-secondary {
        background: var(--card-muted-bg, #F1E9DA);
        color: var(--text-color, #2E294E);
    }

    [data-theme="dark"] .stat-actions .btn-secondary {
        background: #2E294E;
        color: #F1E9DA;
    }

    .provider-settings-form .form-actions {
        position: sticky;
        bottom: 0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 30px;
        padding-top: 16px;
        padding-bottom: 10px;
        border-top: 1px solid var(--border-color, #F1E9DA);
        background: var(--card-bg, #F1E9DA);
    }

    [data-theme="dark"] .provider-settings-form .form-actions {
        border-top-color: #2E294E;
        background: #2E294E;
    }

    .header-right {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .logout-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.55rem 0.9rem;
        border-radius: 999px;
        border: 1px solid var(--border-color, #F1E9DA);
        background: var(--bg-secondary, #F1E9DA);
        color: var(--text-primary, #2E294E);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
    }

    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .dashboard-wrapper,
    .main-content,
    .content-wrapper,
    .widget,
    .widget-body,
    .table-responsive {
        max-width: 100%;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .provider-settings-form .two-columns {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    @media (max-width: 991px) {
        .provider-settings-form .two-columns {
            grid-template-columns: 1fr;
        }

        .endpoint-header {
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .header-right {
            flex-wrap: wrap;
            justify-content: flex-end;
        }
    }

    @media (max-width: 768px) {
        .modal-content.modal-wide {
            max-width: 95vw;
        }

        .provider-settings-form .form-actions {
            flex-wrap: wrap;
        }
    }

    .logout-btn:hover {
        background: var(--bg-tertiary, #F1E9DA);
        border-color: var(--brand-primary, #541388);
        color: var(--brand-primary, #541388);
        box-shadow: 0 10px 22px rgba(84, 19, 136, 0.18);
    }

    [data-theme="dark"] .logout-btn {
        background: rgba(241, 233, 218, 0.06);
        border-color: rgba(241, 233, 218, 0.12);
        color: #F1E9DA;
    }

    [data-theme="dark"] .logout-btn:hover {
        background: rgba(241, 233, 218, 0.12);
        border-color: var(--brand-primary, #541388);
        color: var(--brand-primary, #541388);
    }

    @media (max-width: 1024px) {
        .modal-content.modal-wide {
            max-width: 95%;
        }
    }

    @media (max-width: 900px) {
        .provider-settings-form .two-columns {
            grid-template-columns: 1fr;
        }

        .endpoint-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .endpoint-status {
            min-width: 0;
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .modal-content {
            width: 94%;
            margin: 8% auto;
            padding: 16px;
        }

        .modal .form-actions {
            text-align: left;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .modal .btn {
            width: 100%;
            margin-left: 0;
        }

        .provider-settings-form .form-actions {
            position: static;
        }

        .endpoint-card {
            padding: 14px;
        }

        .stat-actions {
            align-items: stretch;
        }

        .stat-actions form,
        .stat-actions .btn {
            width: 100%;
        }

        .header-right {
            width: 100%;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 8px;
        }
    }

    @media (max-width: 640px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .stat-card {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
        }

        .stat-icon {
            margin-right: 0;
        }

        .stat-content {
            width: 100%;
        }

        .stat-content h3 {
            word-break: break-word;
            font-size: 1.4rem;
        }
    }

    @media (max-width: 520px) {
        .modal h2 {
            font-size: 1.2rem;
        }

        .modal .form-control {
            font-size: 13px;
        }
    }
    </style>

    <script>
        // Modal functions
        function openConfigModal(networkId, networkName, primaryProviderId, backupProviderId) {
            document.getElementById('modal_network_id').value = networkId;
            document.getElementById('modal_network_name').value = networkName;
            document.getElementById('modal_primary_provider').value = primaryProviderId;
            document.getElementById('modal_backup_provider').value = backupProviderId || '';
            document.getElementById('configModal').style.display = 'block';
        }

        function closeConfigModal() {
            document.getElementById('configModal').style.display = 'none';
        }

        function openProviderSettingsModal(providerId) {
            const modal = document.getElementById(`providerModal_${providerId}`);
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeProviderSettingsModal(providerId) {
            const modal = document.getElementById(`providerModal_${providerId}`);
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function toggleMobileMenu() {
            const sidebar = document.querySelector('.sidebar');
            const dashboardWrapper = document.querySelector('.dashboard-wrapper');
            if (!sidebar || !dashboardWrapper) {
                return;
            }

            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                dashboardWrapper.appendChild(overlay);
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                        sidebar.classList.remove('show');
                        overlay.classList.remove('show');
                        document.body.classList.remove('sidebar-open');
                    }
                });
            }

            const shouldShow = !sidebar.classList.contains('show');
            sidebar.classList.toggle('show', shouldShow);
            overlay.classList.toggle('show', shouldShow);
            document.body.classList.toggle('sidebar-open', shouldShow);
        }

        function bindMobileMenuToggle() {
            const mobileToggle = document.querySelector('.mobile-menu-toggle');
            if (!mobileToggle || mobileToggle.dataset.bound === '1') {
                return;
            }
            mobileToggle.dataset.bound = '1';

            let lastToggleAt = 0;
            const handler = (e) => {
                if (e && typeof e.preventDefault === 'function') {
                    e.preventDefault();
                }
                if (e && typeof e.stopPropagation === 'function') {
                    e.stopPropagation();
                }

                const now = Date.now();
                if (now - lastToggleAt < 300) {
                    return;
                }
                lastToggleAt = now;
                toggleMobileMenu();
            };

            mobileToggle.addEventListener('click', handler);
            mobileToggle.addEventListener('touchend', handler, { passive: false });
            mobileToggle.addEventListener('pointerup', handler);
        }

        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            bindMobileMenuToggle();
            
            // Close modal when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target.classList && e.target.classList.contains('modal')) {
                    if (e.target.id === 'configModal') {
                        closeConfigModal();
                    } else if (e.target.id && e.target.id.startsWith('providerModal_')) {
                        e.target.style.display = 'none';
                    }
                }
            });
        });
    </script>
    
    <!-- Theme Script -->
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/theme.js')); ?>""></script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>




