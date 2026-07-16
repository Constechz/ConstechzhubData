<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();
requireRole('agent');

$network = sanitize($_GET['network'] ?? 'at');
$network_name = strtoupper($network);

// Get actual available packages for the network
$network_map = ['at' => 'AT', 'mtn' => 'MTN', 'telecel' => 'Telecel'];
$network_db_name = $network_map[strtolower($network)] ?? 'AT';
ensureDataPackageStockStatusColumn();

$stmt = $db->prepare("
    SELECT DISTINCT dp.data_size
    FROM data_packages dp
    LEFT JOIN networks n ON dp.network_id = n.id
    WHERE n.name = ? AND dp.status = 'active'
      AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    ORDER BY CAST(SUBSTRING_INDEX(dp.data_size, 'GB', 1) AS DECIMAL) ASC
");
$stmt->bind_param("s", $network_db_name);
$stmt->execute();
$result = $stmt->get_result();

$sample_volumes = [];
while ($row = $result->fetch_assoc()) {
    $sample_volumes[] = $row['data_size'];
}

// If no packages found, use defaults
if (empty($sample_volumes)) {
    $sample_volumes = ['1GB', '2GB', '5GB'];
}

// Set headers for CSV download that Excel can open
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $network_name . '_bulk_template.csv"');
header('Cache-Control: max-age=0');

// Create CSV content with actual available volumes
$csv_content = "NUMBER,VOLUME\r\n";
$sample_phones = ['0245152060', '0201234567', '0501234567'];

for ($i = 0; $i < min(3, count($sample_volumes)); $i++) {
    $phone = $sample_phones[$i] ?? '024' . str_pad($i, 7, '0', STR_PAD_LEFT);
    $volume = $sample_volumes[$i];
    $csv_content .= "$phone,$volume\r\n";
}

// Output CSV content
echo $csv_content;
exit;
