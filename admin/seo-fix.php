<?php
require_once '../config/config.php';
require_once '../includes/seo.php';

requireRole('admin');
$current_user = getCurrentUser();
$pageTitle = 'SEO Repair Tool';

$csrfToken = generateCSRF();
$flash = getFlashMessage();
$report = $_SESSION['seo_fix_report'] ?? null;
unset($_SESSION['seo_fix_report']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRF($token)) {
        setFlashMessage('error', 'Invalid CSRF token. Please refresh the page and try again.');
        header('Location: seo-fix.php');
        exit;
    }

    $result = runSeoRepair();
    $_SESSION['seo_fix_report'] = $result['report'];
    setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
    header('Location: seo-fix.php');
    exit;
}

include '../includes/admin_header.php';
?>

<div class="dashboard-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="page-title">
        <h1><i class="fas fa-tools"></i> SEO Repair Utility</h1>
        <p class="page-subtitle">
            Diagnose and automatically repair the <code>seo_settings</code> table. This tool recreates the table if it is missing,
            adds the required <code>description</code> column, migrates legacy keys, and seeds all default SEO values.
        </p>
    </div>

    <?php if ($report): ?>
        <div class="widget">
            <div class="widget-header">
                <h3 class="widget-title"><i class="fas fa-clipboard-check"></i> Last Repair Summary</h3>
            </div>
            <div class="widget-body">
                <div class="stats-grid" style="margin-bottom:1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(46, 41, 78, 0.12);color:#2E294E;">
                            <i class="fas fa-database"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $report['table_created'] ? 'Ensured' : 'Up to date'; ?></div>
                            <div class="stat-label">Table Status</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(84, 19, 136, 0.12);color:#541388;">
                            <i class="fas fa-columns"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $report['description_column_added'] ? 'Added' : 'Present'; ?></div>
                            <div class="stat-label"><code>description</code> column</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(255, 212, 0, 0.15);color:#FFD400;">
                            <i class="fas fa-sync"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo count($report['migrated'] ?? []); ?></div>
                            <div class="stat-label">Legacy Keys Migrated</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:rgba(84, 19, 136, 0.15);color:#541388;">
                            <i class="fas fa-seedling"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo count($report['seeded'] ?? []); ?></div>
                            <div class="stat-label">Defaults Seeded</div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($report['migrated'])): ?>
                    <div class="alert alert-info">
                        <strong>Legacy migrated:</strong>
                        <ul>
                            <?php foreach ($report['migrated'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($report['seeded'])): ?>
                    <div class="alert alert-success">
                        <strong>Defaults added/updated:</strong>
                        <ul>
                            <?php foreach ($report['seeded'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($report['skipped'])): ?>
                    <div class="alert alert-secondary">
                        <strong>Already healthy:</strong>
                        <ul>
                            <?php foreach ($report['skipped'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($report['errors'])): ?>
                    <div class="alert alert-danger">
                        <strong>Issues detected:</strong>
                        <ul>
                            <?php foreach ($report['errors'] as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="widget">
        <div class="widget-header">
            <h3 class="widget-title"><i class="fas fa-life-ring"></i> Run Repair</h3>
        </div>
        <div class="widget-body">
            <p>
                Use this tool whenever the SEO settings form fails to save or when the <code>seo_settings</code> table
                is missing required columns. The utility is safe to run multiple times&mdash;existing values will be preserved.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-wrench"></i> Run Automatic Repair
                </button>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>

<?php
function runSeoRepair()
{
    global $db;

    $report = [
        'table_created' => false,
        'description_column_added' => false,
        'migrated' => [],
        'seeded' => [],
        'skipped' => [],
        'errors' => []
    ];

    try {
        $conn = $db->getConnection();

        $tableSql = "
            CREATE TABLE IF NOT EXISTS `seo_settings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `setting_name` VARCHAR(100) NOT NULL,
                `setting_value` TEXT NULL,
                `description` TEXT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_setting_name` (`setting_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        if (!$conn->query($tableSql)) {
            throw new Exception('Failed to ensure seo_settings table exists: ' . $conn->error);
        }
        $report['table_created'] = true;

        if (!seoColumnExists($conn, 'seo_settings', 'description')) {
            $alterSql = "ALTER TABLE `seo_settings` ADD COLUMN `description` TEXT NULL AFTER `setting_value`";
            if (!$conn->query($alterSql)) {
                throw new Exception('Unable to add description column: ' . $conn->error);
            }
            $report['description_column_added'] = true;
        }

        $defaults = [
            'site_name' => [
                'value' => SITE_NAME,
                'description' => 'Primary brand name used in SEO tags.'
            ],
            'site_description' => [
                'value' => 'Your trusted partner for affordable data bundles.',
                'description' => 'Default description used for meta tags when custom copy is missing.'
            ],
            'site_keywords' => [
                'value' => 'data bundles, MTN, AT, Telecel, mobile data, Constechzhub',
                'description' => 'Comma-separated keywords for search indexing.'
            ],
            'seo_image' => [
                'value' => '/assets/images/seo-default.jpg',
                'description' => 'Fallback Open Graph and Twitter card image.'
            ],
            'site_url' => [
                'value' => SITE_URL,
                'description' => 'Canonical URL for this installation.'
            ],
            'facebook_app_id' => [
                'value' => '',
                'description' => 'Optional Facebook App ID for Open Graph.'
            ],
            'twitter_handle' => [
                'value' => '@databundlehub',
                'description' => 'Default Twitter username for cards.'
            ],
            'google_analytics_id' => [
                'value' => '',
                'description' => 'Measurement ID (e.g., G-XXXXXX).'
            ],
            'google_site_verification' => [
                'value' => '',
                'description' => 'Verification token for Google Search Console.'
            ],
            'favicon_url' => [
                'value' => '/favicon.ico',
                'description' => 'Default favicon reference.'
            ]
        ];

        $legacyMap = [
            'meta_description' => 'site_description',
            'meta_keywords' => 'site_keywords',
            'site_title' => 'site_name'
        ];

        foreach ($legacyMap as $legacy => $modern) {
            $legacyValue = rawSeoSetting($conn, $legacy);
            if ($legacyValue === null) {
                continue;
            }

            $modernValue = rawSeoSetting($conn, $modern);
            if ($modernValue === null || $modernValue === '') {
                if (updateSeoSetting($modern, $legacyValue, 'Migrated from ' . $legacy)) {
                    $report['migrated'][] = "{$legacy} Ã¢â€¡â€™ {$modern}";
                } else {
                    $report['errors'][] = "Failed migrating {$legacy} to {$modern}";
                }
            } else {
                $report['skipped'][] = "{$modern} already populated";
            }

            deleteSeoSetting($conn, $legacy);
        }

        foreach ($defaults as $name => $payload) {
            $existing = rawSeoSetting($conn, $name);
            if ($existing === null || $existing === '') {
                if (updateSeoSetting($name, $payload['value'], $payload['description'])) {
                    $report['seeded'][] = $name;
                } else {
                    $report['errors'][] = "Failed to set {$name}";
                }
            } else {
                $report['skipped'][] = "{$name} already set";
            }
        }

        $success = empty($report['errors']);
        $message = $success
            ? 'SEO settings table repaired successfully.'
            : 'SEO repair completed with some warnings. Review the report below.';

        return ['success' => $success, 'message' => $message, 'report' => $report];
    } catch (Exception $e) {
        $report['errors'][] = $e->getMessage();
        return [
            'success' => false,
            'message' => 'SEO repair failed: ' . $e->getMessage(),
            'report' => $report
        ];
    }
}

function seoColumnExists(mysqli $conn, $table, $column)
{
    $tableSafe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $columnSafe = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'";
    if (!$result = $conn->query($sql)) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function rawSeoSetting(mysqli $conn, $name)
{
    $stmt = $conn->prepare("SELECT setting_value FROM seo_settings WHERE setting_name = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = null;
    if ($row = $result->fetch_assoc()) {
        $value = $row['setting_value'];
    }
    $stmt->close();
    return $value;
}

function deleteSeoSetting(mysqli $conn, $name)
{
    $stmt = $conn->prepare("DELETE FROM seo_settings WHERE setting_name = ?");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
}

