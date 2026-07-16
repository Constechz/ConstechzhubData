<?php
require_once '../config/config.php';

requireAnyRole(['admin', 'super_admin']);
ensurePricingProfilesSchema();

$pageTitle = 'Profit Stats';
$page_csrf_token = generateCSRF();

if (!function_exists('profitStatsNormalizeProviderKey')) {
    function profitStatsNormalizeProviderKey($value) {
        $value = strtolower(trim((string) $value));
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }
}

if (!function_exists('profitStatsEnsureTables')) {
    function profitStatsEnsureTables() {
        global $db;

        $db->query("
            CREATE TABLE IF NOT EXISTS `provider_package_costs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `provider_id` INT NOT NULL,
                `package_id` INT NOT NULL,
                `cost_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_package_cost` (`provider_id`, `package_id`),
                KEY `idx_provider_package_cost_provider` (`provider_id`),
                KEY `idx_provider_package_cost_package` (`package_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `provider_service_costs` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `service_key` VARCHAR(60) NOT NULL,
                `provider_id` INT NOT NULL,
                `cost_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_service_cost` (`service_key`, `provider_id`),
                KEY `idx_provider_service_cost_service` (`service_key`),
                KEY `idx_provider_service_cost_provider` (`provider_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('profitStatsBuildRedirectUrl')) {
    function profitStatsBuildRedirectUrl(array $params = []) {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $clean[$key] = $value;
        }
        return 'profit-stats.php' . (!empty($clean) ? ('?' . http_build_query($clean)) : '');
    }
}

if (!function_exists('profitStatsParseProviderFromPayload')) {
    function profitStatsParseProviderFromPayload($rawPayload, array $providersById, array $providersBySlug, array $providersByKey) {
        $providerId = 0;
        $providerSlug = '';
        $providerName = '';

        $decoded = null;
        if (is_string($rawPayload) && trim($rawPayload) !== '') {
            $decoded = json_decode($rawPayload, true);
        }

        if (is_array($decoded)) {
            $providerNode = [];
            if (isset($decoded['provider']) && is_array($decoded['provider'])) {
                $providerNode = $decoded['provider'];
            } else {
                $providerNode = $decoded;
            }

            $providerId = (int) ($providerNode['provider_id'] ?? $decoded['provider_id'] ?? 0);
            $providerSlug = (string) ($providerNode['provider_slug'] ?? $providerNode['slug'] ?? $decoded['provider_slug'] ?? '');
            $providerName = (string) ($providerNode['provider_name'] ?? $providerNode['name'] ?? $decoded['provider_name'] ?? '');
        }

        if ($providerId > 0 && isset($providersById[$providerId])) {
            return $providersById[$providerId];
        }

        $normalizedSlug = profitStatsNormalizeProviderKey($providerSlug);
        if ($normalizedSlug !== '' && isset($providersBySlug[$normalizedSlug])) {
            return $providersBySlug[$normalizedSlug];
        }

        $normalizedName = profitStatsNormalizeProviderKey($providerName);
        if ($normalizedName !== '' && isset($providersByKey[$normalizedName])) {
            return $providersByKey[$normalizedName];
        }

        if (is_string($rawPayload) && $rawPayload !== '') {
            if (preg_match('/"provider_id"\s*:\s*(\d+)/i', $rawPayload, $matches)) {
                $providerId = (int) ($matches[1] ?? 0);
                if ($providerId > 0 && isset($providersById[$providerId])) {
                    return $providersById[$providerId];
                }
            }

            if (preg_match('/"provider_slug"\s*:\s*"([^"]+)"/i', $rawPayload, $matches)) {
                $normalizedSlug = profitStatsNormalizeProviderKey($matches[1] ?? '');
                if ($normalizedSlug !== '' && isset($providersBySlug[$normalizedSlug])) {
                    return $providersBySlug[$normalizedSlug];
                }
            }

            if (preg_match('/"provider_name"\s*:\s*"([^"]+)"/i', $rawPayload, $matches)) {
                $normalizedName = profitStatsNormalizeProviderKey($matches[1] ?? '');
                if ($normalizedName !== '' && isset($providersByKey[$normalizedName])) {
                    return $providersByKey[$normalizedName];
                }
            }
        }

        return null;
    }
}

if (!function_exists('profitStatsExtractSizeValue')) {
    function profitStatsExtractSizeValue($value) {
        $value = strtolower(str_replace(' ', '', (string) $value));
        if ($value === '') {
            return 0.0;
        }

        preg_match('/(\d+(?:\.\d+)?)/', $value, $matches);
        $numericValue = isset($matches[1]) ? (float) $matches[1] : 0.0;
        if ($numericValue <= 0) {
            return 0.0;
        }

        if (strpos($value, 'tb') !== false) {
            return $numericValue * 1000;
        }
        if (strpos($value, 'mb') !== false) {
            return $numericValue / 1000;
        }

        return $numericValue;
    }
}

profitStatsEnsureTables();

$result_checker_service_keys = [
    'BECE' => 'result_checker_bece',
    'WASSCE' => 'result_checker_wassce',
];

$selected_provider_id = isset($_GET['provider_id']) ? (int) $_GET['provider_id'] : 0;
$selected_network = sanitize($_GET['network'] ?? '');
$date_from = sanitize($_GET['date_from'] ?? date('Y-m-d'));
$date_to = sanitize($_GET['date_to'] ?? date('Y-m-d'));

$providers = [];
$providerResult = $db->query("SELECT id, name, slug, is_active FROM api_providers ORDER BY is_active DESC, name ASC");
if ($providerResult) {
    while ($row = $providerResult->fetch_assoc()) {
        $providers[] = $row;
    }
}

$providersById = [];
$providersBySlug = [];
$providersByKey = [];
foreach ($providers as $provider) {
    $providerId = (int) ($provider['id'] ?? 0);
    if ($providerId <= 0) {
        continue;
    }

    $providersById[$providerId] = $provider;

    $slugKey = profitStatsNormalizeProviderKey($provider['slug'] ?? '');
    if ($slugKey !== '') {
        $providersBySlug[$slugKey] = $provider;
    }

    $nameKey = profitStatsNormalizeProviderKey($provider['name'] ?? '');
    if ($nameKey !== '') {
        $providersByKey[$nameKey] = $provider;
    }
}

if ($selected_provider_id <= 0 && !empty($providers)) {
    // Priority 1: Find Hubnet
    foreach ($providers as $p) {
        $name = strtolower($p['name'] ?? '');
        if (strpos($name, 'hubnet') !== false) {
            $selected_provider_id = (int)$p['id'];
            break;
        }
    }
    // Priority 2: Use the first active provider
    if ($selected_provider_id <= 0) {
        $selected_provider_id = (int) ($providers[0]['id'] ?? 0);
    }
}
if ($selected_provider_id > 0 && !isset($providersById[$selected_provider_id]) && !empty($providers)) {
    $selected_provider_id = (int) ($providers[0]['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $redirect_params = [
        'provider_id' => (int) ($_POST['redirect_provider_id'] ?? $selected_provider_id),
        'network' => sanitize($_POST['redirect_network'] ?? $selected_network),
        'date_from' => sanitize($_POST['redirect_date_from'] ?? $date_from),
        'date_to' => sanitize($_POST['redirect_date_to'] ?? $date_to),
    ];

    if (!validateCSRF($csrf_token)) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: ' . profitStatsBuildRedirectUrl($redirect_params));
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_package_costs') {
        $provider_id = (int) ($_POST['provider_id'] ?? 0);
        $costs = $_POST['costs'] ?? [];

        if ($provider_id <= 0 || !isset($providersById[$provider_id])) {
            setFlashMessage('error', 'Please choose a valid provider before saving package costs.');
            header('Location: ' . profitStatsBuildRedirectUrl($redirect_params));
            exit();
        }

        $upsertStmt = $db->prepare("
            INSERT INTO provider_package_costs (provider_id, package_id, cost_amount)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE cost_amount = VALUES(cost_amount), updated_at = CURRENT_TIMESTAMP
        ");
        $deleteStmt = $db->prepare("DELETE FROM provider_package_costs WHERE provider_id = ? AND package_id = ?");

        $saved = 0;
        $removed = 0;
        foreach ((array) $costs as $package_id => $raw_cost) {
            $package_id = (int) $package_id;
            $raw_cost = trim((string) $raw_cost);
            if ($package_id <= 0) {
                continue;
            }

            if ($raw_cost === '') {
                if ($deleteStmt) {
                    $deleteStmt->bind_param('ii', $provider_id, $package_id);
                    if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                        $removed++;
                    }
                }
                continue;
            }

            $cost_amount = max(0, (float) $raw_cost);
            if ($upsertStmt) {
                $upsertStmt->bind_param('iid', $provider_id, $package_id, $cost_amount);
                if ($upsertStmt->execute()) {
                    $saved++;
                }
            }
        }

        setFlashMessage('success', "Package cost mappings updated. Saved {$saved}, removed {$removed}.");
        header('Location: ' . profitStatsBuildRedirectUrl($redirect_params));
        exit();
    }

    if ($action === 'save_afa_costs') {
        $service_key = 'afa_registration';
        $default_provider_id = (int) ($_POST['afa_default_provider_id'] ?? 0);
        $afa_costs = $_POST['afa_costs'] ?? [];

        $db->query("UPDATE provider_service_costs SET is_default = 0 WHERE service_key = '" . $db->real_escape_string($service_key) . "'");

        $upsertStmt = $db->prepare("
            INSERT INTO provider_service_costs (service_key, provider_id, cost_amount, is_default)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cost_amount = VALUES(cost_amount), is_default = VALUES(is_default), updated_at = CURRENT_TIMESTAMP
        ");
        $deleteStmt = $db->prepare("DELETE FROM provider_service_costs WHERE service_key = ? AND provider_id = ?");

        $saved = 0;
        $removed = 0;
        $defaultApplied = false;

        foreach ($providers as $provider) {
            $provider_id = (int) ($provider['id'] ?? 0);
            if ($provider_id <= 0) {
                continue;
            }

            $raw_cost = trim((string) ($afa_costs[$provider_id] ?? ''));
            if ($raw_cost === '') {
                if ($deleteStmt) {
                    $deleteStmt->bind_param('si', $service_key, $provider_id);
                    if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                        $removed++;
                    }
                }
                continue;
            }

            $cost_amount = max(0, (float) $raw_cost);
            $is_default = ($default_provider_id > 0 && $default_provider_id === $provider_id) ? 1 : 0;
            if ($is_default === 1) {
                $defaultApplied = true;
            }

            if ($upsertStmt) {
                $upsertStmt->bind_param('sidi', $service_key, $provider_id, $cost_amount, $is_default);
                if ($upsertStmt->execute()) {
                    $saved++;
                }
            }
        }

        if ($default_provider_id > 0 && !$defaultApplied) {
            setFlashMessage('warning', "AFA costs updated. Saved {$saved}, removed {$removed}. The selected default provider was not applied because its cost is blank.");
        } else {
            setFlashMessage('success', "AFA provider costs updated. Saved {$saved}, removed {$removed}.");
        }

        header('Location: ' . profitStatsBuildRedirectUrl($redirect_params));
        exit();
    }

    if ($action === 'save_result_checker_costs') {
        $result_checker_costs = $_POST['result_checker_costs'] ?? [];
        $default_provider_ids = [
            'BECE' => (int) ($_POST['result_checker_default_provider_bece_id'] ?? 0),
            'WASSCE' => (int) ($_POST['result_checker_default_provider_wassce_id'] ?? 0),
        ];

        $upsertStmt = $db->prepare("
            INSERT INTO provider_service_costs (service_key, provider_id, cost_amount, is_default)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE cost_amount = VALUES(cost_amount), is_default = VALUES(is_default), updated_at = CURRENT_TIMESTAMP
        ");
        $deleteStmt = $db->prepare("DELETE FROM provider_service_costs WHERE service_key = ? AND provider_id = ?");

        $saved = 0;
        $removed = 0;
        $defaultWarnings = [];

        foreach ($result_checker_service_keys as $cardType => $serviceKey) {
            $selectedDefaultProviderId = $default_provider_ids[$cardType] ?? 0;
            $defaultApplied = false;

            $db->query("UPDATE provider_service_costs SET is_default = 0 WHERE service_key = '" . $db->real_escape_string($serviceKey) . "'");

            foreach ($providers as $provider) {
                $provider_id = (int) ($provider['id'] ?? 0);
                if ($provider_id <= 0) {
                    continue;
                }

                $raw_cost = trim((string) ($result_checker_costs[$cardType][$provider_id] ?? ''));
                if ($raw_cost === '') {
                    if ($deleteStmt) {
                        $deleteStmt->bind_param('si', $serviceKey, $provider_id);
                        if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                            $removed++;
                        }
                    }
                    continue;
                }

                $cost_amount = max(0, (float) $raw_cost);
                $is_default = ($selectedDefaultProviderId > 0 && $selectedDefaultProviderId === $provider_id) ? 1 : 0;
                if ($is_default === 1) {
                    $defaultApplied = true;
                }

                if ($upsertStmt) {
                    $upsertStmt->bind_param('sidi', $serviceKey, $provider_id, $cost_amount, $is_default);
                    if ($upsertStmt->execute()) {
                        $saved++;
                    }
                }
            }

            if ($selectedDefaultProviderId > 0 && !$defaultApplied) {
                $defaultWarnings[] = $cardType;
            }
        }

        if (!empty($defaultWarnings)) {
            setFlashMessage(
                'warning',
                'Result checker costs updated. Saved ' . $saved . ', removed ' . $removed . '. Default provider not applied for: ' . implode(', ', $defaultWarnings) . ' because the selected cost is blank.'
            );
        } else {
            setFlashMessage('success', "Result checker costs updated. Saved {$saved}, removed {$removed}.");
        }

        header('Location: ' . profitStatsBuildRedirectUrl($redirect_params));
        exit();
    }
}

$active_profile = getActivePricingProfile();
$networks = [];
$networkResult = $db->query("SELECT name FROM networks WHERE is_active = 1 ORDER BY name ASC");
if ($networkResult) {
    while ($row = $networkResult->fetch_assoc()) {
        $networks[] = $row['name'];
    }
}

$packages = [];
$package_ids = [];
$packageQuery = "
    SELECT
        dp.id,
        dp.name,
        dp.package_type,
        dp.data_size,
        dp.validity_days,
        n.name AS network_name
    FROM data_packages dp
    INNER JOIN networks n ON n.id = dp.network_id
    WHERE n.is_active = 1
";
$packageParams = [];
$packageTypes = '';
if ($selected_network !== '') {
    $packageQuery .= " AND n.name = ?";
    $packageParams[] = $selected_network;
    $packageTypes .= 's';
}
$packageQuery .= " ORDER BY n.name ASC, dp.package_type ASC, dp.name ASC";

if ($packageTypes !== '') {
    $packageStmt = $db->prepare($packageQuery);
    if ($packageStmt) {
        $packageStmt->bind_param($packageTypes, ...$packageParams);
        $packageStmt->execute();
        $packageResult = $packageStmt->get_result();
    } else {
        $packageResult = false;
    }
} else {
    $packageResult = $db->query($packageQuery);
}

if ($packageResult) {
    while ($row = $packageResult->fetch_assoc()) {
        $row['customer_price'] = null;
        $row['agent_price'] = null;
        $packages[] = $row;
        $package_ids[] = (int) $row['id'];
    }
}

if (!empty($packages)) {
    usort($packages, function ($left, $right) {
        $networkCompare = strcmp((string) ($left['network_name'] ?? ''), (string) ($right['network_name'] ?? ''));
        if ($networkCompare !== 0) {
            return $networkCompare;
        }

        $typeCompare = strcmp((string) ($left['package_type'] ?? ''), (string) ($right['package_type'] ?? ''));
        if ($typeCompare !== 0) {
            return $typeCompare;
        }

        $leftSize = profitStatsExtractSizeValue($left['data_size'] ?? $left['name'] ?? '');
        $rightSize = profitStatsExtractSizeValue($right['data_size'] ?? $right['name'] ?? '');
        if ($leftSize < $rightSize) {
            return -1;
        }
        if ($leftSize > $rightSize) {
            return 1;
        }

        $leftValidity = (int) ($left['validity_days'] ?? 0);
        $rightValidity = (int) ($right['validity_days'] ?? 0);
        if ($leftValidity !== $rightValidity) {
            return $leftValidity <=> $rightValidity;
        }

        return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });
}

if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    $pricingSql = "
        SELECT
            package_id,
            MAX(CASE WHEN user_type = 'customer' THEN price END) AS customer_price,
            MAX(CASE WHEN user_type = 'agent' THEN price END) AS agent_price
        FROM package_pricing_profiles
        WHERE profile_key = ?
          AND package_id IN ({$placeholders})
        GROUP BY package_id
    ";
    $pricingStmt = $db->prepare($pricingSql);
    if ($pricingStmt) {
        $pricingTypes = 's' . str_repeat('i', count($package_ids));
        $pricingValues = array_merge([$active_profile], $package_ids);
        $pricingStmt->bind_param($pricingTypes, ...$pricingValues);
        $pricingStmt->execute();
        $pricingResult = $pricingStmt->get_result();
        $pricingMap = [];
        while ($priceRow = $pricingResult->fetch_assoc()) {
            $pricingMap[(int) $priceRow['package_id']] = $priceRow;
        }

        foreach ($packages as &$package) {
            $packageId = (int) $package['id'];
            if (isset($pricingMap[$packageId])) {
                $package['customer_price'] = $pricingMap[$packageId]['customer_price'] !== null ? (float) $pricingMap[$packageId]['customer_price'] : null;
                $package['agent_price'] = $pricingMap[$packageId]['agent_price'] !== null ? (float) $pricingMap[$packageId]['agent_price'] : null;
            }
        }
        unset($package);
    }
}

$selected_provider_costs = [];
if ($selected_provider_id > 0) {
    $providerCostStmt = $db->prepare("SELECT package_id, cost_amount FROM provider_package_costs WHERE provider_id = ?");
    if ($providerCostStmt) {
        $providerCostStmt->bind_param('i', $selected_provider_id);
        $providerCostStmt->execute();
        $providerCostResult = $providerCostStmt->get_result();
        while ($row = $providerCostResult->fetch_assoc()) {
            $selected_provider_costs[(int) $row['package_id']] = (float) $row['cost_amount'];
        }
    }
}

$package_margin_summary = [
    'mapped_packages' => 0,
    'total_margin' => 0.0,
    'average_margin' => 0.0,
];

foreach ($packages as $package) {
    $packageId = (int) ($package['id'] ?? 0);
    $mappedCost = $selected_provider_costs[$packageId] ?? null;
    $agentPrice = $package['agent_price'] !== null ? (float) $package['agent_price'] : null;

    if ($mappedCost === null || $agentPrice === null) {
        continue;
    }

    $package_margin_summary['mapped_packages']++;
    $package_margin_summary['total_margin'] += round($agentPrice - (float) $mappedCost, 2);
}

if ($package_margin_summary['mapped_packages'] > 0) {
    $package_margin_summary['average_margin'] = round(
        $package_margin_summary['total_margin'] / $package_margin_summary['mapped_packages'],
        2
    );
}

$all_provider_costs = [];
$allCostsResult = $db->query("SELECT provider_id, package_id, cost_amount FROM provider_package_costs");
if ($allCostsResult) {
    while ($row = $allCostsResult->fetch_assoc()) {
        $providerId = (int) ($row['provider_id'] ?? 0);
        $packageId = (int) ($row['package_id'] ?? 0);
        if ($providerId > 0 && $packageId > 0) {
            if (!isset($all_provider_costs[$providerId])) {
                $all_provider_costs[$providerId] = [];
            }
            $all_provider_costs[$providerId][$packageId] = (float) ($row['cost_amount'] ?? 0);
        }
    }
}

$afa_cost_rows = [];
$afa_default_provider_id = 0;
$afa_default_cost = null;
$afa_default_provider_name = 'Unconfigured';
$afa_system_price = 0.0;
if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registration_settings')) {
    $afaSettingsResult = $db->query("SELECT agent_price FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
    if ($afaSettingsResult && ($row = $afaSettingsResult->fetch_assoc())) {
        $afa_system_price = (float) ($row['agent_price'] ?? 0);
    }
}
$afaCostResult = $db->query("
    SELECT
        ap.id AS provider_id,
        ap.name,
        ap.slug,
        ap.is_active,
        psc.cost_amount,
        psc.is_default
    FROM api_providers ap
    LEFT JOIN provider_service_costs psc
        ON psc.provider_id = ap.id
       AND psc.service_key = 'afa_registration'
    ORDER BY ap.is_active DESC, ap.name ASC
");
if ($afaCostResult) {
    while ($row = $afaCostResult->fetch_assoc()) {
        $providerId = (int) ($row['provider_id'] ?? 0);
        $costAmount = $row['cost_amount'] !== null ? (float) $row['cost_amount'] : null;
        $afa_cost_rows[] = [
            'provider_id' => $providerId,
            'name' => $row['name'],
            'slug' => $row['slug'],
            'is_active' => (int) ($row['is_active'] ?? 0),
            'cost_amount' => $costAmount,
            'is_default' => (int) ($row['is_default'] ?? 0),
        ];

        if ((int) ($row['is_default'] ?? 0) === 1) {
            $afa_default_provider_id = $providerId;
            $afa_default_cost = $costAmount;
            $afa_default_provider_name = (string) ($row['name'] ?? 'Unconfigured');
        }
    }
}

$result_checker_settings = [
    'bece_price' => 0.0,
    'wassce_price' => 0.0,
];
if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_settings')) {
    $resultCheckerSettingsResult = $db->query("SELECT bece_price, wassce_price FROM result_checker_settings ORDER BY id DESC LIMIT 1");
    if ($resultCheckerSettingsResult && ($row = $resultCheckerSettingsResult->fetch_assoc())) {
        $result_checker_settings['bece_price'] = (float) ($row['bece_price'] ?? 0);
        $result_checker_settings['wassce_price'] = (float) ($row['wassce_price'] ?? 0);
    }
}

$result_checker_cost_rows = [];
$result_checker_defaults = [
    'BECE' => [
        'provider_id' => 0,
        'cost' => null,
        'provider_name' => 'Unconfigured',
    ],
    'WASSCE' => [
        'provider_id' => 0,
        'cost' => null,
        'provider_name' => 'Unconfigured',
    ],
];
$resultCheckerCostResult = $db->query("
    SELECT
        ap.id AS provider_id,
        ap.name,
        ap.slug,
        ap.is_active,
        bece.cost_amount AS bece_cost_amount,
        bece.is_default AS bece_is_default,
        wassce.cost_amount AS wassce_cost_amount,
        wassce.is_default AS wassce_is_default
    FROM api_providers ap
    LEFT JOIN provider_service_costs bece
        ON bece.provider_id = ap.id
       AND bece.service_key = 'result_checker_bece'
    LEFT JOIN provider_service_costs wassce
        ON wassce.provider_id = ap.id
       AND wassce.service_key = 'result_checker_wassce'
    ORDER BY ap.is_active DESC, ap.name ASC
");
if ($resultCheckerCostResult) {
    while ($row = $resultCheckerCostResult->fetch_assoc()) {
        $providerId = (int) ($row['provider_id'] ?? 0);
        $beceCost = $row['bece_cost_amount'] !== null ? (float) $row['bece_cost_amount'] : null;
        $wassceCost = $row['wassce_cost_amount'] !== null ? (float) $row['wassce_cost_amount'] : null;

        $result_checker_cost_rows[] = [
            'provider_id' => $providerId,
            'name' => $row['name'],
            'slug' => $row['slug'],
            'is_active' => (int) ($row['is_active'] ?? 0),
            'bece_cost_amount' => $beceCost,
            'bece_is_default' => (int) ($row['bece_is_default'] ?? 0),
            'wassce_cost_amount' => $wassceCost,
            'wassce_is_default' => (int) ($row['wassce_is_default'] ?? 0),
        ];

        if ((int) ($row['bece_is_default'] ?? 0) === 1) {
            $result_checker_defaults['BECE'] = [
                'provider_id' => $providerId,
                'cost' => $beceCost,
                'provider_name' => (string) ($row['name'] ?? 'Unconfigured'),
            ];
        }

        if ((int) ($row['wassce_is_default'] ?? 0) === 1) {
            $result_checker_defaults['WASSCE'] = [
                'provider_id' => $providerId,
                'cost' => $wassceCost,
                'provider_name' => (string) ($row['name'] ?? 'Unconfigured'),
            ];
        }
    }
}

$summary = [
    'bundle_revenue' => 0.0,
    'bundle_retail_revenue' => 0.0,
    'bundle_cost' => 0.0,
    'bundle_profit' => 0.0,
    'bundle_orders' => 0,
    'bundle_orders_with_frozen_cost' => 0,
    'bundle_orders_with_mapping' => 0,
    'bundle_retail_revenue' => 0.0,
    'afa_revenue' => 0.0,
    'afa_retail_revenue' => 0.0,
    'afa_cost' => 0.0,
    'afa_profit' => 0.0,
    'afa_orders' => 0,
    'afa_orders_unmapped' => 0,
    'checker_revenue' => 0.0,
    'checker_retail_revenue' => 0.0,
    'checker_cost' => 0.0,
    'checker_profit' => 0.0,
    'checker_orders' => 0,
    'checker_orders_unmapped' => 0,
];
$breakdown_rows = [];
$unmapped_bundle_orders = [];

$bundleSql = "
    SELECT
        bo.id,
        bo.package_id,
        bo.amount,
        bo.commission,
        bo.agent_cost,
        bo.api_response,
        bo.status,
        bo.created_at,
        bo.updated_at,
        bo.delivered_at,
        dp.name AS package_name,
        dp.package_type,
        dp.data_size,
        n.name AS network_name,
        (SELECT provider_id FROM api_transaction_logs WHERE bundle_order_id = bo.id AND is_successful = 1 ORDER BY id DESC LIMIT 1) as log_provider_id
    FROM bundle_orders bo
    INNER JOIN data_packages dp ON dp.id = bo.package_id
    INNER JOIN networks n ON n.id = dp.network_id
    WHERE LOWER(bo.status) IN ('delivered', 'success', 'completed')
      AND DATE(bo.created_at) BETWEEN ? AND ?
";
$bundleParams = [$date_from, $date_to];
$bundleTypes = 'ss';
if ($selected_network !== '') {
    $bundleSql .= " AND n.name = ?";
    $bundleTypes .= 's';
    $bundleParams[] = $selected_network;
}
$bundleSql .= " ORDER BY bo.created_at DESC";
$bundleStmt = $db->prepare($bundleSql);
if ($bundleStmt) {
    $bundleStmt->bind_param($bundleTypes, ...$bundleParams);
    $bundleStmt->execute();
    $bundleResult = $bundleStmt->get_result();

    while ($row = $bundleResult->fetch_assoc()) {
        $retailRevenue = (float) ($row['amount'] ?? 0);
        $orderCommission = isset($row['commission']) ? (float) $row['commission'] : 0.0;
        $frozenCost = $row['agent_cost'] !== null ? (float) $row['agent_cost'] : null;
        
        $provider = profitStatsParseProviderFromPayload($row['api_response'] ?? '', $providersById, $providersBySlug, $providersByKey);
        $providerId = (int) ($provider['id'] ?? 0);
        
        // Fallback to log_provider_id if payload parsing failed
        if ($providerId <= 0 && isset($row['log_provider_id']) && (int)$row['log_provider_id'] > 0) {
            $providerId = (int)$row['log_provider_id'];
            if (isset($providersById[$providerId])) {
                $provider = $providersById[$providerId];
            }
        }
        
        $providerName = $provider['name'] ?? 'Unknown Provider';

        $costSource = '';
        $costAmount = null;
        $revenue = $retailRevenue;

        // Find provider cost mapping for this package
        $mappedProviderCost = ($providerId > 0 && isset($all_provider_costs[$providerId][(int) $row['package_id']]))
            ? (float) $all_provider_costs[$providerId][(int) $row['package_id']]
            : null;

        if ($frozenCost !== null && $frozenCost > 0) {
            // This is an agent store order.
            // Admin Revenue = Wholesale Price (frozenCost).
            // Admin Cost = Provider Cost.
            $revenue = $frozenCost; 
            $costAmount = $mappedProviderCost;
            $costSource = 'Frozen + Mapped';
            $summary['bundle_orders_with_frozen_cost']++;
        } else {
            // Direct purchase (by agent or customer)
            // Admin Revenue = Retail Price - Commission.
            // Admin Cost = Provider Cost.
            $revenue = $retailRevenue - $orderCommission;
            $costAmount = $mappedProviderCost;
            $costSource = 'Mapped';
            $summary['bundle_orders_with_mapping']++;
        }

        $summary['bundle_retail_revenue'] += $retailRevenue;
        $summary['bundle_revenue'] += $revenue;
        $summary['bundle_orders']++;

        if ($costAmount === null && $selected_provider_id > 0) {
            // Final fallback: Use the currently selected/filtered provider's cost
            if (isset($all_provider_costs[$selected_provider_id][(int) $row['package_id']])) {
                $costAmount = (float) $all_provider_costs[$selected_provider_id][(int) $row['package_id']];
                $costSource = 'Selected Provider Fallback';
            }
        }

        if ($costAmount === null) {
            $summary['bundle_orders_unmapped']++;
            if (count($unmapped_bundle_orders) < 20) {
                $unmapped_bundle_orders[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'provider_name' => $providerName,
                    'network_name' => $row['network_name'] ?? 'Unknown',
                    'package_name' => $row['package_name'] ?? 'Unknown',
                    'data_size' => $row['data_size'] ?? '',
                    'amount' => $retailRevenue,
                    'event_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
            continue;
        }

        $profitAmount = round($revenue - $costAmount, 2);
        $summary['bundle_cost'] += $costAmount;
        $summary['bundle_profit'] += $profitAmount;

        $eventAt = (string) ($row['created_at'] ?? '');
        $eventDate = $eventAt !== '' ? date('Y-m-d', strtotime($eventAt)) : $date_from;
        $breakdownKey = implode('|', ['bundle', $eventDate, $providerId ?: 0, $costSource]);

        if (!isset($breakdown_rows[$breakdownKey])) {
            $breakdown_rows[$breakdownKey] = [
                'event_date' => $eventDate,
                'channel' => 'Bundle',
                'provider_name' => $providerName,
                'cost_source' => $costSource,
                'orders' => 0,
                'revenue' => 0.0,
                'cost' => 0.0,
                'profit' => 0.0,
            ];
        }

        $breakdown_rows[$breakdownKey]['orders']++;
        $breakdown_rows[$breakdownKey]['revenue'] += $revenue;
        $breakdown_rows[$breakdownKey]['cost'] += $costAmount;
        $breakdown_rows[$breakdownKey]['profit'] += $profitAmount;
    }
}

if (function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations')) {
    $afaStmt = $db->prepare("
        SELECT
            id,
            reference,
            amount,
            admin_price,
            created_at,
            updated_at,
            reviewed_at
        FROM afa_registrations
        WHERE status IN ('processing', 'success')
          AND DATE(COALESCE(reviewed_at, updated_at, created_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(reviewed_at, updated_at, created_at) DESC
    ");
    if ($afaStmt) {
        $afaStmt->bind_param('ss', $date_from, $date_to);
        $afaStmt->execute();
        $afaResult = $afaStmt->get_result();

        while ($row = $afaResult->fetch_assoc()) {
            $summary['afa_orders']++;
            $retailRevenue = (float) ($row['amount'] ?? 0);
            $summary['afa_retail_revenue'] += $retailRevenue;
            $revenue = (isset($row['admin_price']) && (float)$row['admin_price'] > 0) 
                ? (float)$row['admin_price'] 
                : $retailRevenue;
            
            $summary['afa_revenue'] += $revenue;

            $costSource = 'Default Mapping';
            $costAmount = null;
            $providerName = $afa_default_provider_name;

            if ($afa_default_cost !== null && $afa_default_cost > 0) {
                $costAmount = (float) $afa_default_cost;
            }

            if ($costAmount === null) {
                $summary['afa_orders_unmapped']++;
                continue;
            }

            $profitAmount = round($revenue - $costAmount, 2);
            $summary['afa_cost'] += $costAmount;
            $summary['afa_profit'] += $profitAmount;

            $eventAt = (string) ($row['reviewed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
            $eventDate = $eventAt !== '' ? date('Y-m-d', strtotime($eventAt)) : $date_from;
            $breakdownKey = implode('|', ['afa', $eventDate, $afa_default_provider_id ?: 0, $costSource]);

            if (!isset($breakdown_rows[$breakdownKey])) {
                $breakdown_rows[$breakdownKey] = [
                    'event_date' => $eventDate,
                    'channel' => 'AFA Registration',
                    'provider_name' => $providerName,
                    'cost_source' => $costSource,
                    'orders' => 0,
                    'revenue' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $breakdown_rows[$breakdownKey]['orders']++;
            $breakdown_rows[$breakdownKey]['revenue'] += $revenue;
            $breakdown_rows[$breakdownKey]['cost'] += $costAmount;
            $breakdown_rows[$breakdownKey]['profit'] += $profitAmount;
        }
    }
}

if (function_exists('dbh_table_exists') && dbh_table_exists('result_checker_purchases')) {
    $checkerStmt = $db->prepare("
        SELECT
            id,
            card_type,
            amount,
            admin_price,
            created_at
        FROM result_checker_purchases
        WHERE status = 'success'
          AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC
    ");
    if ($checkerStmt) {
        $checkerStmt->bind_param('ss', $date_from, $date_to);
        $checkerStmt->execute();
        $checkerResult = $checkerStmt->get_result();

        while ($row = $checkerResult->fetch_assoc()) {
            $summary['checker_orders']++;
            $retailRevenue = (float) ($row['amount'] ?? 0);
            $summary['checker_retail_revenue'] += $retailRevenue;
            $revenue = (isset($row['admin_price']) && (float)$row['admin_price'] > 0) 
                ? (float)$row['admin_price'] 
                : $retailRevenue;
                
            $summary['checker_revenue'] += $revenue;

            $cardType = strtoupper((string) ($row['card_type'] ?? ''));
            $defaultMapping = $result_checker_defaults[$cardType] ?? null;
            $costAmount = $defaultMapping['cost'] ?? null;
            if ($costAmount === null || $costAmount <= 0) {
                $summary['checker_orders_unmapped']++;
                continue;
            }

            $providerName = (string) ($defaultMapping['provider_name'] ?? 'Unconfigured');
            $profitAmount = round($revenue - (float) $costAmount, 2);
            $summary['checker_cost'] += (float) $costAmount;
            $summary['checker_profit'] += $profitAmount;

            $eventAt = (string) ($row['created_at'] ?? '');
            $eventDate = $eventAt !== '' ? date('Y-m-d', strtotime($eventAt)) : $date_from;
            $breakdownKey = implode('|', ['checker', $eventDate, $cardType, (int) ($defaultMapping['provider_id'] ?? 0)]);

            if (!isset($breakdown_rows[$breakdownKey])) {
                $breakdown_rows[$breakdownKey] = [
                    'event_date' => $eventDate,
                    'channel' => 'Result Checker (' . ($cardType !== '' ? $cardType : 'Unknown') . ')',
                    'provider_name' => $providerName,
                    'cost_source' => 'Default Mapping',
                    'orders' => 0,
                    'revenue' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                ];
            }

            $breakdown_rows[$breakdownKey]['orders']++;
            $breakdown_rows[$breakdownKey]['revenue'] += $revenue;
            $breakdown_rows[$breakdownKey]['cost'] += (float) $costAmount;
            $breakdown_rows[$breakdownKey]['profit'] += $profitAmount;
        }
    }
}

$tracked_profit = $summary['bundle_profit'] + $summary['afa_profit'] + $summary['checker_profit'];
$total_revenue = $summary['bundle_revenue'] + $summary['afa_revenue'] + $summary['checker_revenue'];
$total_retail_revenue = $summary['bundle_retail_revenue'] + $summary['afa_retail_revenue'] + $summary['checker_retail_revenue'];
$tracked_cost = $summary['bundle_cost'] + $summary['afa_cost'] + $summary['checker_cost'];
$breakdown_rows = array_values($breakdown_rows);
usort($breakdown_rows, function ($left, $right) {
    $dateCompare = strcmp((string) ($right['event_date'] ?? ''), (string) ($left['event_date'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    $channelCompare = strcmp((string) ($left['channel'] ?? ''), (string) ($right['channel'] ?? ''));
    if ($channelCompare !== 0) {
        return $channelCompare;
    }

    return strcmp((string) ($left['provider_name'] ?? ''), (string) ($right['provider_name'] ?? ''));
});

$flash = getFlashMessage();
$selectedProvider = $selected_provider_id > 0 && isset($providersById[$selected_provider_id]) ? $providersById[$selected_provider_id] : null;

require_once '../includes/admin_header.php';
?>
<style>
.profit-stats-page {
    min-width: 0;
    max-width: 100%;
    overflow-x: hidden;
}

.profit-stats-page .widget,
.profit-stats-page .widget-body,
.profit-stats-page .stat-card,
.profit-stats-page .stat-content,
.profit-stats-page .table-responsive {
    min-width: 0;
    max-width: 100%;
}

.profit-stats-page .widget-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.profit-stats-page .page-intro {
    margin-bottom: 1.5rem;
    padding: 1rem 1.25rem;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(16, 185, 129, 0.08));
    color: var(--text-secondary);
}

.profit-stats-page .filters-form,
.profit-stats-page .mapping-toolbar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    align-items: center;
}

.profit-stats-page .filters-form .form-control,
.profit-stats-page .mapping-toolbar .form-control {
    min-width: 0;
    width: 100%;
}

.profit-stats-page .mapping-toolbar {
    grid-template-columns: max-content minmax(220px, 1fr);
}

.profit-stats-page .mapping-toolbar .muted-note {
    align-self: center;
}

.profit-stats-page .muted-note {
    font-size: 0.86rem;
    color: var(--text-muted);
    overflow-wrap: anywhere;
}

.profit-stats-page .table td,
.profit-stats-page .table th {
    vertical-align: middle;
    white-space: normal;
    overflow-wrap: anywhere;
}

.profit-stats-page .cost-input {
    min-width: 100px;
    width: 120px;
    max-width: 100%;
}

.profit-stats-page .summary-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: rgba(59, 130, 246, 0.08);
    color: var(--text-secondary);
    font-size: 0.85rem;
    max-width: 100%;
    white-space: normal;
    overflow-wrap: anywhere;
}

.profit-stats-page .summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.profit-stats-page .inline-stack {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.profit-stats-page .table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.profit-stats-page .table-responsive table {
    width: 100%;
    min-width: 760px;
}

.profit-stats-page .table-responsive table th,
.profit-stats-page .table-responsive table td {
    min-width: 0;
}

.profit-stats-page .table-responsive table th:last-child,
.profit-stats-page .table-responsive table td:last-child {
    padding-right: 1rem;
}

.profit-stats-page .form-actions-bottom {
    display: flex;
    justify-content: flex-end;
    margin-top: 1rem;
}

@media (max-width: 1024px) {
    .profit-stats-page .summary-grid {
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    }

    .profit-stats-page .widget-header > * {
        min-width: 0;
    }
}

@media (max-width: 768px) {
    .profit-stats-page .page-intro {
        padding: 0.9rem;
        border-radius: 8px;
    }

    .profit-stats-page .widget-header {
        flex-direction: column;
        align-items: stretch;
    }

    .profit-stats-page .summary-grid {
        grid-template-columns: 1fr;
    }

    .profit-stats-page .stat-card {
        align-items: flex-start;
    }

    .profit-stats-page .filters-form,
    .profit-stats-page .mapping-toolbar {
        grid-template-columns: 1fr;
    }

    .profit-stats-page .filters-form .form-control,
    .profit-stats-page .mapping-toolbar .form-control,
    .profit-stats-page .filters-form .btn,
    .profit-stats-page .mapping-toolbar .btn {
        width: 100%;
        min-width: 0;
    }

    .profit-stats-page .summary-pill {
        border-radius: 8px;
        justify-content: flex-start;
    }

    .profit-stats-page .table-responsive {
        overflow-x: visible;
    }

    .profit-stats-page .table-responsive table,
    .profit-stats-page .table-responsive thead,
    .profit-stats-page .table-responsive tbody,
    .profit-stats-page .table-responsive tr,
    .profit-stats-page .table-responsive td {
        display: block;
        width: 100%;
        min-width: 0;
    }

    .profit-stats-page .table-responsive table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .profit-stats-page .table-responsive thead {
        display: none;
    }

    .profit-stats-page .table-responsive tbody tr {
        margin-bottom: 0.9rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--card-bg);
        overflow: hidden;
    }

    .profit-stats-page .table-responsive tbody tr:last-child {
        margin-bottom: 0;
    }

    .profit-stats-page .table-responsive tbody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        border-bottom: 1px solid var(--border-color);
        padding: 0.75rem 0.85rem;
        text-align: right;
    }

    .profit-stats-page .table-responsive tbody td:last-child {
        border-bottom: 0;
    }

    .profit-stats-page .table-responsive tbody td::before {
        content: attr(data-label);
        flex: 0 0 42%;
        max-width: 42%;
        color: var(--text-muted);
        font-weight: 600;
        text-align: left;
        overflow-wrap: anywhere;
    }

    .profit-stats-page .table-responsive tbody td[colspan] {
        display: block;
        text-align: center;
    }

    .profit-stats-page .table-responsive tbody td[colspan]::before {
        display: none;
    }

    .profit-stats-page .table-responsive .inline-stack {
        align-items: flex-end;
        text-align: right;
    }

    .profit-stats-page .cost-input {
        width: min(160px, 100%);
    }

    .profit-stats-page .form-actions-bottom {
        justify-content: stretch;
    }

    .profit-stats-page .form-actions-bottom .btn {
        width: 100%;
    }
}

@media (max-width: 420px) {
    .profit-stats-page .table-responsive tbody td {
        flex-direction: column;
        align-items: stretch;
        text-align: left;
        gap: 0.4rem;
    }

    .profit-stats-page .table-responsive tbody td::before {
        flex-basis: auto;
        max-width: 100%;
    }

    .profit-stats-page .table-responsive .inline-stack {
        align-items: flex-start;
        text-align: left;
    }

    .profit-stats-page .cost-input {
        width: 100%;
    }
}
</style>

<div class="profit-stats-page">
    <div class="page-title">
        <h1>Profit Stats</h1>
        <p class="page-subtitle">Map supplier costs against your system prices, then track daily profit for bundles, AFA registrations, and result checkers.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>


    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <h3 class="widget-title">Filters</h3>
            <div class="summary-pill">
                <i class="fas fa-layer-group"></i>
                Active pricing profile: <?php echo htmlspecialchars(ucfirst((string) $active_profile)); ?>
            </div>
        </div>
        <div class="widget-body">
            <form method="get" class="filters-form">
                <select name="provider_id" class="form-control">
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?php echo (int) $provider['id']; ?>" <?php echo ((int) $provider['id'] === $selected_provider_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($provider['name']); ?><?php echo ((int) ($provider['is_active'] ?? 0) === 1) ? '' : ' (Inactive)'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="network" class="form-control">
                    <option value="">All Networks</option>
                    <?php foreach ($networks as $networkName): ?>
                        <option value="<?php echo htmlspecialchars($networkName); ?>" <?php echo ($selected_network === $networkName) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($networkName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </form>
        </div>
    </div>

    <div class="summary-grid">
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-chart-line"></i></div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($tracked_profit)); ?></h3>
                <p>Tracked Profit</p>
                <p class="muted-note">Total Sales <?php echo htmlspecialchars(formatCurrency($total_retail_revenue)); ?></p>
                <p class="muted-note">Admin Revenue <?php echo htmlspecialchars(formatCurrency($total_revenue)); ?> | Supplier Cost <?php echo htmlspecialchars(formatCurrency($tracked_cost)); ?></p>
                <p class="muted-note">Bundles <?php echo htmlspecialchars(formatCurrency($summary['bundle_profit'])); ?> | AFA <?php echo htmlspecialchars(formatCurrency($summary['afa_profit'])); ?> | Checker <?php echo htmlspecialchars(formatCurrency($summary['checker_profit'])); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-wifi"></i></div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['bundle_profit'])); ?></h3>
                <p>Bundle Profit</p>
                <p class="muted-note"><?php echo number_format($summary['bundle_orders']); ?> delivered bundle orders in range</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon secondary"><i class="fas fa-tags"></i></div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($package_margin_summary['average_margin'])); ?></h3>
                <p>Average Configured Package Margin</p>
                <p class="muted-note">Based on Agent Price - Supplier Cost for <?php echo number_format($package_margin_summary['mapped_packages']); ?> mapped packages</p>
                <p class="muted-note">Total configured catalog margin <?php echo htmlspecialchars(formatCurrency($package_margin_summary['total_margin'])); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="fas fa-id-card"></i></div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['afa_profit'])); ?></h3>
                <p>AFA Profit</p>
                <p class="muted-note"><?php echo number_format($summary['afa_orders']); ?> successful registrations in range</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-credit-card"></i></div>
            <div class="stat-content">
                <h3><?php echo htmlspecialchars(formatCurrency($summary['checker_profit'])); ?></h3>
                <p>Result Checker Profit</p>
                <p class="muted-note"><?php echo number_format($summary['checker_orders']); ?> successful checker sales in range</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon secondary"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-content">
                <h3><?php echo number_format($summary['bundle_orders_unmapped'] + $summary['afa_orders_unmapped'] + $summary['checker_orders_unmapped']); ?></h3>
                <p>Needs Mapping</p>
                <p class="muted-note">Bundle <?php echo number_format($summary['bundle_orders_unmapped']); ?> | AFA <?php echo number_format($summary['afa_orders_unmapped']); ?> | Checker <?php echo number_format($summary['checker_orders_unmapped']); ?></p>
            </div>
        </div>
    </div>

    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <div>
                <h3 class="widget-title">Package Cost Mapping</h3>
                <p class="widget-subtitle">Provider: <?php echo htmlspecialchars($selectedProvider['name'] ?? 'None selected'); ?></p>
            </div>
            <div class="summary-pill">
                <i class="fas fa-link"></i>
                <?php echo count($selected_provider_costs); ?> mapped package costs
            </div>
        </div>
        <div class="widget-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                <input type="hidden" name="action" value="save_package_costs">
                <input type="hidden" name="provider_id" value="<?php echo (int) $selected_provider_id; ?>">
                <input type="hidden" name="redirect_provider_id" value="<?php echo (int) $selected_provider_id; ?>">
                <input type="hidden" name="redirect_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                <input type="hidden" name="redirect_date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="redirect_date_to" value="<?php echo htmlspecialchars($date_to); ?>">

                <div class="mapping-toolbar" style="margin-bottom: 1rem;">
                    <button type="submit" class="btn btn-primary">Save Package Costs</button>
                    <span class="muted-note">Leave a cost blank to remove that provider-package mapping.</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Network</th>
                                <th>Package</th>
                                <th>Type</th>
                                <th>Validity</th>
                                <th>Customer Price</th>
                                <th>Agent Price</th>
                                <th>Cost</th>
                                <th>System Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No packages found for the selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($packages as $package): ?>
                                    <?php
                                    $packageId = (int) $package['id'];
                                    $mappedCost = $selected_provider_costs[$packageId] ?? null;
                                    $systemMargin = ($package['agent_price'] !== null && $mappedCost !== null)
                                        ? ((float) $package['agent_price'] - (float) $mappedCost)
                                        : null;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($package['network_name']); ?></td>
                                        <td>
                                            <div class="inline-stack">
                                                <strong><?php echo htmlspecialchars($package['name']); ?></strong>
                                                <span class="muted-note"><?php echo htmlspecialchars($package['data_size']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $package['package_type']))); ?></td>
                                        <td><?php echo (int) $package['validity_days']; ?> day(s)</td>
                                        <td><?php echo $package['customer_price'] !== null ? htmlspecialchars(formatCurrency($package['customer_price'])) : '<span class="text-muted">Not set</span>'; ?></td>
                                        <td><?php echo $package['agent_price'] !== null ? htmlspecialchars(formatCurrency($package['agent_price'])) : '<span class="text-muted">Not set</span>'; ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                class="form-control cost-input"
                                                name="costs[<?php echo $packageId; ?>]"
                                                min="0"
                                                step="0.01"
                                                value="<?php echo $mappedCost !== null ? htmlspecialchars(number_format((float) $mappedCost, 2, '.', '')) : ''; ?>"
                                                placeholder="0.00"
                                            >
                                        </td>
                                        <td><?php echo $systemMargin !== null ? htmlspecialchars(formatCurrency($systemMargin)) : '<span class="text-muted">N/A</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions-bottom">
                    <button type="submit" class="btn btn-primary">Save Package Costs</button>
                </div>
            </form>
        </div>
    </div>

    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <div>
                <h3 class="widget-title">AFA Cost Mapping</h3>
                <p class="widget-subtitle">Set provider cost for AFA registrations and choose the default fallback provider.</p>
            </div>
            <div class="summary-pill">
                <i class="fas fa-id-badge"></i>
                System price <?php echo htmlspecialchars(formatCurrency($afa_system_price)); ?> | Default provider: <?php echo htmlspecialchars($afa_default_provider_name); ?>
            </div>
        </div>
        <div class="widget-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                <input type="hidden" name="action" value="save_afa_costs">
                <input type="hidden" name="redirect_provider_id" value="<?php echo (int) $selected_provider_id; ?>">
                <input type="hidden" name="redirect_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                <input type="hidden" name="redirect_date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="redirect_date_to" value="<?php echo htmlspecialchars($date_to); ?>">

                <div class="mapping-toolbar" style="margin-bottom: 1rem;">
                    <button type="submit" class="btn btn-primary">Save AFA Costs</button>
                    <span class="muted-note">AFA profit is calculated as order amount minus the default supplier cost you choose here.</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Default</th>
                                <th>Provider</th>
                                <th>Slug</th>
                                <th>Status</th>
                                <th>AFA Cost</th>
                                <th>AFA Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($afa_cost_rows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No API providers found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($afa_cost_rows as $row): ?>
                                    <?php $afaMargin = $row['cost_amount'] !== null ? ($afa_system_price - (float) $row['cost_amount']) : null; ?>
                                    <tr>
                                        <td><input type="radio" name="afa_default_provider_id" value="<?php echo (int) $row['provider_id']; ?>" <?php echo ((int) $row['provider_id'] === $afa_default_provider_id) ? 'checked' : ''; ?>></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['slug']); ?></td>
                                        <td><?php echo ((int) $row['is_active'] === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                class="form-control cost-input"
                                                name="afa_costs[<?php echo (int) $row['provider_id']; ?>]"
                                                min="0"
                                                step="0.01"
                                                value="<?php echo $row['cost_amount'] !== null ? htmlspecialchars(number_format((float) $row['cost_amount'], 2, '.', '')) : ''; ?>"
                                                placeholder="0.00"
                                            >
                                        </td>
                                        <td><?php echo $afaMargin !== null ? htmlspecialchars(formatCurrency($afaMargin)) : '<span class="text-muted">N/A</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions-bottom">
                    <button type="submit" class="btn btn-primary">Save AFA Costs</button>
                </div>
            </form>
        </div>
    </div>

    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <div>
                <h3 class="widget-title">Result Checker Cost Mapping</h3>
                <p class="widget-subtitle">Set supplier cost for each checker type and choose the default provider used for profit tracking.</p>
            </div>
            <div class="summary-pill">
                <i class="fas fa-ticket-alt"></i>
                System prices: BECE <?php echo htmlspecialchars(formatCurrency($result_checker_settings['bece_price'])); ?> | WASSCE <?php echo htmlspecialchars(formatCurrency($result_checker_settings['wassce_price'])); ?>
            </div>
        </div>
        <div class="widget-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                <input type="hidden" name="action" value="save_result_checker_costs">
                <input type="hidden" name="redirect_provider_id" value="<?php echo (int) $selected_provider_id; ?>">
                <input type="hidden" name="redirect_network" value="<?php echo htmlspecialchars($selected_network); ?>">
                <input type="hidden" name="redirect_date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="redirect_date_to" value="<?php echo htmlspecialchars($date_to); ?>">

                <div class="mapping-toolbar" style="margin-bottom: 1rem;">
                    <button type="submit" class="btn btn-primary">Save Result Checker Costs</button>
                    <span class="muted-note">Profit is calculated from the recorded sale amount minus the default supplier cost for BECE or WASSCE.</span>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>BECE Default</th>
                                <th>WASSCE Default</th>
                                <th>Provider</th>
                                <th>Slug</th>
                                <th>Status</th>
                                <th>BECE Cost</th>
                                <th>BECE Margin</th>
                                <th>WASSCE Cost</th>
                                <th>WASSCE Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($result_checker_cost_rows)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No API providers found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($result_checker_cost_rows as $row): ?>
                                    <?php
                                    $beceMargin = $row['bece_cost_amount'] !== null ? ($result_checker_settings['bece_price'] - (float) $row['bece_cost_amount']) : null;
                                    $wassceMargin = $row['wassce_cost_amount'] !== null ? ($result_checker_settings['wassce_price'] - (float) $row['wassce_cost_amount']) : null;
                                    ?>
                                    <tr>
                                        <td><input type="radio" name="result_checker_default_provider_bece_id" value="<?php echo (int) $row['provider_id']; ?>" <?php echo ((int) $row['provider_id'] === (int) ($result_checker_defaults['BECE']['provider_id'] ?? 0)) ? 'checked' : ''; ?>></td>
                                        <td><input type="radio" name="result_checker_default_provider_wassce_id" value="<?php echo (int) $row['provider_id']; ?>" <?php echo ((int) $row['provider_id'] === (int) ($result_checker_defaults['WASSCE']['provider_id'] ?? 0)) ? 'checked' : ''; ?>></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['slug']); ?></td>
                                        <td><?php echo ((int) $row['is_active'] === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                class="form-control cost-input"
                                                name="result_checker_costs[BECE][<?php echo (int) $row['provider_id']; ?>]"
                                                min="0"
                                                step="0.01"
                                                value="<?php echo $row['bece_cost_amount'] !== null ? htmlspecialchars(number_format((float) $row['bece_cost_amount'], 2, '.', '')) : ''; ?>"
                                                placeholder="0.00"
                                            >
                                        </td>
                                        <td><?php echo $beceMargin !== null ? htmlspecialchars(formatCurrency($beceMargin)) : '<span class="text-muted">N/A</span>'; ?></td>
                                        <td>
                                            <input
                                                type="number"
                                                class="form-control cost-input"
                                                name="result_checker_costs[WASSCE][<?php echo (int) $row['provider_id']; ?>]"
                                                min="0"
                                                step="0.01"
                                                value="<?php echo $row['wassce_cost_amount'] !== null ? htmlspecialchars(number_format((float) $row['wassce_cost_amount'], 2, '.', '')) : ''; ?>"
                                                placeholder="0.00"
                                            >
                                        </td>
                                        <td><?php echo $wassceMargin !== null ? htmlspecialchars(formatCurrency($wassceMargin)) : '<span class="text-muted">N/A</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions-bottom">
                    <button type="submit" class="btn btn-primary">Save Result Checker Costs</button>
                </div>
            </form>
        </div>
    </div>

    <div class="widget" style="margin-bottom: 1.5rem;">
        <div class="widget-header">
            <h3 class="widget-title">Profit Breakdown</h3>
            <p class="widget-subtitle">Daily tracked profit using frozen bundle cost first, then your supplier mappings for the other services.</p>
        </div>
        <div class="widget-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Channel</th>
                            <th>Provider</th>
                            <th>Source</th>
                            <th>Orders</th>
                            <th>Revenue</th>
                            <th>Cost</th>
                            <th>Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($breakdown_rows)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">No tracked profit rows were found for the selected date range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($breakdown_rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $row['event_date']))); ?></td>
                                    <td><?php echo htmlspecialchars($row['channel']); ?></td>
                                    <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['cost_source']); ?></td>
                                    <td><?php echo number_format((int) $row['orders']); ?></td>
                                    <td><?php echo htmlspecialchars(formatCurrency($row['revenue'])); ?></td>
                                    <td><?php echo htmlspecialchars(formatCurrency($row['cost'])); ?></td>
                                    <td><?php echo htmlspecialchars(formatCurrency($row['profit'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="widget">
        <div class="widget-header">
            <div>
                <h3 class="widget-title">Unmapped Bundle Orders</h3>
                <p class="widget-subtitle">These delivered orders could not be priced because neither `agent_cost` nor a matching provider-package cost was available.</p>
            </div>
            <div class="summary-pill">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo number_format($summary['bundle_orders_unmapped']); ?> unmapped bundle orders
            </div>
        </div>
        <div class="widget-body">
            <div class="muted-note" style="margin-bottom: 1rem;">
                Recommendation: for long-term accuracy, store `provider_id` directly on `bundle_orders` when the API purchase is created. Right now this page infers provider from `api_response`.
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order ID</th>
                            <th>Provider</th>
                            <th>Network</th>
                            <th>Package</th>
                            <th>Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($unmapped_bundle_orders)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No unmapped bundle orders in the selected range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($unmapped_bundle_orders as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) $row['event_at']))); ?></td>
                                    <td>#<?php echo (int) $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['provider_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['network_name']); ?></td>
                                    <td><?php echo htmlspecialchars(trim(($row['package_name'] ?? '') . ' ' . ($row['data_size'] ?? ''))); ?></td>
                                    <td><?php echo htmlspecialchars(formatCurrency($row['amount'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.profit-stats-page .table-responsive table').forEach(function(table) {
        var headers = Array.from(table.querySelectorAll('thead th')).map(function(header) {
            return header.textContent.trim();
        });

        table.querySelectorAll('tbody tr').forEach(function(row) {
            Array.from(row.children).forEach(function(cell, index) {
                if (!cell.hasAttribute('data-label') && headers[index]) {
                    cell.setAttribute('data-label', headers[index]);
                }
            });
        });
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
