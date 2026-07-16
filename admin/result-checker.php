<?php
require_once '../config/config.php';

// Require admin role
requireRole('admin');

ensureResultCheckerTables();

$current_user = getCurrentUser();
$site_name = getSiteName();
$current_email = $current_user['email'] ?? $current_user['full_name'] ?? 'Admin';

if (isset($_GET['download_template'])) {
    $type = strtoupper(trim((string) $_GET['download_template']));
    if (in_array($type, ['BECE', 'WASSCE'], true)) {
        $filename = strtolower($type) . '_result_checker_template.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo "serial,pin\n";
        echo "SERIAL_SAMPLE,PIN_SAMPLE\n";
        exit();
    }
}

$flash = getFlashMessage();
$csrf_token = generateCSRF();

$active_tab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'settings';
$valid_tabs = ['settings', 'bece', 'wassce', 'purchases'];
if (!in_array($active_tab, $valid_tabs, true)) {
    $active_tab = 'settings';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_tab = isset($_POST['redirect_tab']) ? sanitize($_POST['redirect_tab']) : $active_tab;
    if (!in_array($redirect_tab, $valid_tabs, true)) {
        $redirect_tab = 'settings';
    }

    if ($action === 'update_settings') {
        $bece_price = isset($_POST['bece_price']) ? max(0, (float) $_POST['bece_price']) : 0;
        $wassce_price = isset($_POST['wassce_price']) ? max(0, (float) $_POST['wassce_price']) : 0;
        $bece_link = trim($_POST['bece_checker_link'] ?? '');
        $wassce_link = trim($_POST['wassce_checker_link'] ?? '');
        $bece_enabled = isset($_POST['bece_enabled']) ? 1 : 0;
        $wassce_enabled = isset($_POST['wassce_enabled']) ? 1 : 0;

        $settings_id = null;
        $settings_rs = $db->query("SELECT id FROM result_checker_settings ORDER BY id DESC LIMIT 1");
        if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
            $settings_id = (int) $settings_row['id'];
        }

        if ($settings_id) {
            $stmt = $db->prepare("
                UPDATE result_checker_settings
                SET bece_price = ?, wassce_price = ?, bece_checker_link = ?, wassce_checker_link = ?, bece_enabled = ?, wassce_enabled = ?
                WHERE id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('ddssiii', $bece_price, $wassce_price, $bece_link, $wassce_link, $bece_enabled, $wassce_enabled, $settings_id);
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Result checker settings updated.');
                } else {
                    setFlashMessage('error', 'Failed to update settings. Please try again.');
                }
            } else {
                setFlashMessage('error', 'Failed to update settings. Please try again.');
            }
        } else {
            $stmt = $db->prepare("
                INSERT INTO result_checker_settings
                    (bece_price, wassce_price, bece_checker_link, wassce_checker_link, bece_enabled, wassce_enabled)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param('ddssii', $bece_price, $wassce_price, $bece_link, $wassce_link, $bece_enabled, $wassce_enabled);
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Result checker settings updated.');
                } else {
                    setFlashMessage('error', 'Failed to update settings. Please try again.');
                }
            } else {
                setFlashMessage('error', 'Failed to update settings. Please try again.');
            }
        }
    }

    if ($action === 'add_card') {
        $card_type = strtoupper(trim($_POST['type'] ?? ''));
        $pin = trim($_POST['pin'] ?? '');
        $serial = trim($_POST['serial_number'] ?? '');

        if (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
            setFlashMessage('error', 'Invalid card type selected.');
        } elseif ($pin === '' || $serial === '') {
            setFlashMessage('warning', 'PIN and serial number are required.');
        } else {
            $check = $db->prepare("
                SELECT id FROM result_checker_cards
                WHERE card_type = ? AND pin = ? AND serial_number = ?
                LIMIT 1
            ");
            if ($check) {
                $check->bind_param('sss', $card_type, $pin, $serial);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
            } else {
                $exists = null;
            }

            if ($exists) {
                setFlashMessage('warning', 'This card already exists.');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO result_checker_cards
                        (card_type, pin, serial_number, status, created_by)
                    VALUES (?, ?, ?, 'available', ?)
                ");
                if ($stmt) {
                    $created_by = $current_user['id'] ?? null;
                    $stmt->bind_param('sssi', $card_type, $pin, $serial, $created_by);
                    if ($stmt->execute()) {
                        setFlashMessage('success', $card_type . ' card added successfully.');
                    } else {
                        setFlashMessage('error', 'Failed to add card. Please try again.');
                    }
                } else {
                    setFlashMessage('error', 'Failed to add card. Please try again.');
                }
            }
        }
    }

    if ($action === 'bulk_upload') {
        $card_type = strtoupper(trim($_POST['type'] ?? ''));
        $upload = $_FILES['bulk_file'] ?? null;

        if (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
            setFlashMessage('error', 'Invalid card type selected.');
        } elseif (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            setFlashMessage('error', 'Please upload a valid file.');
        } else {
            $content = file_get_contents($upload['tmp_name']);
            if ($content === false) {
                setFlashMessage('error', 'Unable to read the uploaded file.');
            } else {
                $lines = preg_split("/\r\n|\n|\r/", $content);
                $added = 0;
                $duplicates = 0;
                $invalid = 0;

                $stmt = $db->prepare("
                    INSERT IGNORE INTO result_checker_cards
                        (card_type, pin, serial_number, status, created_by)
                    VALUES (?, ?, ?, 'available', ?)
                ");
                if (!$stmt) {
                    setFlashMessage('error', 'Failed to prepare bulk upload. Please try again.');
                } else {
                    $created_by = $current_user['id'] ?? null;
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }
                        $lower = strtolower($line);
                        if (strpos($lower, 'pin') !== false && strpos($lower, 'serial') !== false) {
                            continue;
                        }

                        $parts = preg_split('/[,\t|]+/', $line);
                        if (count($parts) < 2) {
                            $parts = preg_split('/\s+/', $line);
                        }

                        $serial = trim($parts[0] ?? '');
                        $pin = trim($parts[1] ?? '');
                        if ($pin === '' || $serial === '') {
                            $invalid++;
                            continue;
                        }

                        $stmt->bind_param('sssi', $card_type, $pin, $serial, $created_by);
                        $stmt->execute();
                        if ($stmt->affected_rows > 0) {
                            $added++;
                        } else {
                            $duplicates++;
                        }
                    }

                    setFlashMessage('success', sprintf(
                        '%s bulk upload completed. Added %d, duplicates %d, invalid %d.',
                        $card_type,
                        $added,
                        $duplicates,
                        $invalid
                    ));
                }
            }
        }
    }

    if ($action === 'delete_card') {
        $csrf = $_POST['csrf_token'] ?? '';
        $card_id = isset($_POST['card_id']) ? (int) $_POST['card_id'] : 0;
        if (!validateCSRF($csrf)) {
            setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        } elseif ($card_id <= 0) {
            setFlashMessage('error', 'Invalid card selected.');
        } else {
            $stmt = $db->prepare("DELETE FROM result_checker_cards WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $card_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    setFlashMessage('success', 'Card deleted successfully.');
                } else {
                    setFlashMessage('warning', 'Card not found or already removed.');
                }
                $stmt->close();
            } else {
                setFlashMessage('error', 'Failed to delete card. Please try again.');
            }
        }
    }

    if ($action === 'delete_all_cards') {
        $csrf = $_POST['csrf_token'] ?? '';
        $card_type = strtoupper(trim($_POST['type'] ?? ''));
        if (!validateCSRF($csrf)) {
            setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        } elseif (!in_array($card_type, ['BECE', 'WASSCE'], true)) {
            setFlashMessage('error', 'Invalid card type selected.');
        } else {
            $stmt = $db->prepare("DELETE FROM result_checker_cards WHERE card_type = ?");
            if ($stmt) {
                $stmt->bind_param('s', $card_type);
                if ($stmt->execute()) {
                    $deleted = (int) $stmt->affected_rows;
                    setFlashMessage('success', sprintf('%s cards deleted: %d', $card_type, $deleted));
                } else {
                    setFlashMessage('error', 'Failed to delete cards. Please try again.');
                }
                $stmt->close();
            } else {
                setFlashMessage('error', 'Failed to delete cards. Please try again.');
            }
        }
    }

    header('Location: result-checker.php?tab=' . urlencode($redirect_tab));
    exit();
}

$settings = [
    'bece_price' => 17.00,
    'wassce_price' => 17.00,
    'bece_checker_link' => '',
    'wassce_checker_link' => 'https://ghana.waecdirect.org/',
    'bece_enabled' => 0,
    'wassce_enabled' => 0,
];

$settings_rs = $db->query("SELECT * FROM result_checker_settings ORDER BY id DESC LIMIT 1");
if ($settings_rs && $settings_row = $settings_rs->fetch_assoc()) {
    $settings = array_merge($settings, $settings_row);
}

$stats = [
    'BECE' => ['total' => 0, 'available' => 0, 'purchased' => 0],
    'WASSCE' => ['total' => 0, 'available' => 0, 'purchased' => 0],
];
$stats_rs = $db->query("
    SELECT card_type, status, COUNT(*) AS total_count
    FROM result_checker_cards
    GROUP BY card_type, status
");
if ($stats_rs) {
    while ($row = $stats_rs->fetch_assoc()) {
        $type = $row['card_type'];
        $status = $row['status'];
        $count = (int) $row['total_count'];
        if (!isset($stats[$type])) {
            continue;
        }
        $stats[$type]['total'] += $count;
        if ($status === 'available') {
            $stats[$type]['available'] = $count;
        }
        if ($status === 'purchased') {
            $stats[$type]['purchased'] = $count;
        }
    }
}

$bece_cards = [];
$wassce_cards = [];
$purchases = [];

$card_stmt = $db->prepare("
    SELECT rc.*, u.email AS purchased_email
    FROM result_checker_cards rc
    LEFT JOIN users u ON u.id = rc.purchased_by
    WHERE rc.card_type = ?
    ORDER BY rc.id DESC
");
if ($card_stmt) {
    $card_type = 'BECE';
    $card_stmt->bind_param('s', $card_type);
    $card_stmt->execute();
    $bece_cards = $card_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $card_type = 'WASSCE';
    $card_stmt->bind_param('s', $card_type);
    $card_stmt->execute();
    $wassce_cards = $card_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$purchases_rs = $db->query("
    SELECT p.reference, p.card_type, p.pin, p.serial_number, p.amount, p.payment_gateway, p.created_at,
           u.email AS user_email, u.full_name AS user_name
    FROM result_checker_purchases p
    LEFT JOIN users u ON u.id = p.user_id
    ORDER BY p.created_at DESC
");
if ($purchases_rs) {
    $purchases = $purchases_rs->fetch_all(MYSQLI_ASSOC);
}

$total_available_stock = (int) ($stats['BECE']['available'] ?? 0) + (int) ($stats['WASSCE']['available'] ?? 0);
$total_purchased_cards = (int) ($stats['BECE']['purchased'] ?? 0) + (int) ($stats['WASSCE']['purchased'] ?? 0);
$enabled_checker_count = (!empty($settings['bece_enabled']) ? 1 : 0) + (!empty($settings['wassce_enabled']) ? 1 : 0);
$bece_price_display = function_exists('formatCurrency')
    ? formatCurrency((float) ($settings['bece_price'] ?? 0))
    : 'GHS ' . number_format((float) ($settings['bece_price'] ?? 0), 2);
$wassce_price_display = function_exists('formatCurrency')
    ? formatCurrency((float) ($settings['wassce_price'] ?? 0))
    : 'GHS ' . number_format((float) ($settings['wassce_price'] ?? 0), 2);
$pageTitle = 'Result Checker';

require_once '../includes/admin_header.php';
?>
<style>
    @import url('<?php echo htmlspecialchars(dbh_asset('assets/vendor/bootstrap-icons/bootstrap-icons.css')); ?>');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css');
    @import url('https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css');

    body {
        background: radial-gradient(circle at 12% 10%, rgba(139, 92, 246, 0.1), transparent 38%), var(--bg-secondary);
    }

    html,
    body,
    .dashboard-wrapper,
    .main-content,
    .dashboard-content {
        max-width: 100%;
        overflow-x: hidden;
    }

    .result-checker-page {
        width: 100%;
        max-width: 1320px;
        margin: 0 auto;
        overflow-x: hidden;
    }

    .result-checker-page .alert {
        margin-bottom: 1.25rem;
        border-radius: 1rem;
    }

    .result-checker-shell {
        display: grid;
        gap: 1.5rem;
        min-width: 0;
    }

    .result-checker-page,
    #resultCheckerTabContent,
    #resultCheckerTabContent .tab-pane,
    #resultCheckerTabContent .card,
    #resultCheckerTabContent .card-body,
    .table-responsive,
    .dataTables_wrapper {
        min-width: 0;
        max-width: 100%;
    }

    .rc-hero {
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.95fr);
        gap: 1.5rem;
        padding: 1.75rem;
        border-radius: 26px;
        border: 1px solid rgba(110, 118, 255, 0.24);
        background:
            radial-gradient(circle at top left, rgba(118, 128, 255, 0.18), transparent 34%),
            linear-gradient(135deg, #191a46 0%, #0b0d27 52%, #03050f 100%);
        box-shadow: 0 28px 60px rgba(5, 6, 20, 0.46);
        color: #fff;
    }

    .rc-hero::after {
        content: '';
        position: absolute;
        inset: auto -60px -90px auto;
        width: 220px;
        height: 220px;
        border-radius: 999px;
        background: rgba(105, 117, 255, 0.18);
        filter: blur(6px);
    }

    .rc-hero-copy,
    .rc-hero-metrics {
        position: relative;
        z-index: 1;
    }

    .rc-hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.4rem 0.85rem;
        margin-bottom: 1rem;
        border-radius: 999px;
        background: rgba(123, 133, 255, 0.16);
        color: rgba(255, 255, 255, 0.96);
        font-size: 0.83rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .rc-hero h2 {
        margin: 0 0 0.75rem;
        font-size: clamp(1.9rem, 2.4vw, 2.55rem);
        line-height: 1.08;
        font-weight: 800;
        color: #fff;
    }

    .rc-hero p {
        max-width: 620px;
        margin: 0;
        color: rgba(255, 255, 255, 0.82);
        font-size: 1rem;
    }

    .rc-hero-pricing,
    .rc-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .rc-hero-pricing {
        margin-top: 1.15rem;
    }

    .rc-hero-actions {
        margin-top: 1.4rem;
    }

    .rc-hero-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.7rem 0.95rem;
        border-radius: 16px;
        background: rgba(123, 133, 255, 0.16);
        color: #fff;
        font-weight: 600;
    }

    .rc-hero-actions .btn {
        border-radius: 14px;
        border-width: 0;
        font-weight: 700;
    }

    .rc-hero-actions .btn-light {
        color: #5b21b6;
        box-shadow: 0 18px 30px rgba(17, 24, 39, 0.16);
    }

    .rc-hero-actions .btn-outline-light {
        border: 1px solid rgba(141, 150, 255, 0.42);
    }

    .rc-hero-metrics {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.95rem;
        align-content: start;
    }

    .rc-metric {
        padding: 1rem 1.05rem;
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.16);
        background: rgba(10, 6, 27, 0.16);
        backdrop-filter: blur(10px);
    }

    .rc-metric-label {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin-bottom: 0.55rem;
        color: rgba(255, 255, 255, 0.78);
        font-size: 0.83rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .rc-metric-value {
        font-size: 1.55rem;
        font-weight: 800;
        line-height: 1.1;
        color: #fff;
    }

    .rc-metric-note {
        margin-top: 0.28rem;
        color: rgba(255, 255, 255, 0.7);
        font-size: 0.9rem;
    }

    .result-checker-page .container-fluid {
        padding: 0;
    }

    .stat-card,
    #resultCheckerTabContent .card {
        border: 1px solid var(--border-color) !important;
        border-radius: 24px;
        background: var(--bg-primary);
        box-shadow: var(--shadow);
        overflow: hidden;
    }

    .stat-card {
        position: relative;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, rgba(139, 92, 246, 0.9), rgba(236, 72, 153, 0.9));
    }

    .stat-card .card-body,
    #resultCheckerTabContent .card-body {
        padding: 1.35rem;
    }

    .stat-title {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .stat-title i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        border-radius: 16px;
        font-size: 1.15rem;
        color: #fff;
        box-shadow: 0 16px 24px rgba(139, 92, 246, 0.2);
    }

    .stat-title-bece i {
        background: linear-gradient(135deg, #7c3aed, #a855f7);
    }

    .stat-title-wassce i {
        background: linear-gradient(135deg, #ec4899, #f97316);
    }

    .stat-card h3 {
        font-weight: 800;
        color: var(--text-primary);
    }

    #resultCheckerTabs {
        gap: 0.6rem;
        padding: 0.35rem;
        border: 0;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.74);
        box-shadow: inset 0 0 0 1px rgba(139, 92, 246, 0.08);
    }

    #resultCheckerTabs .nav-link {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.82rem 1.05rem;
        border: 0;
        border-radius: 16px;
        color: var(--text-secondary);
        font-weight: 700;
        transition: all 0.2s ease;
    }

    #resultCheckerTabs .nav-link:hover {
        color: #5b21b6;
        background: rgba(139, 92, 246, 0.08);
    }

    #resultCheckerTabs .nav-link.active {
        color: #5b21b6;
        background: #fff;
        box-shadow: 0 10px 24px rgba(139, 92, 246, 0.12);
    }

    #resultCheckerTabContent .card-header {
        padding: 1.15rem 1.35rem;
        border-bottom: 1px solid var(--border-color);
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.05), rgba(255, 255, 255, 0.98)) !important;
    }

    #resultCheckerTabContent .card-header h5 {
        margin: 0;
        font-weight: 700;
        color: var(--text-primary);
    }

    #resultCheckerTabContent .card-header .d-flex.gap-2 {
        gap: 0.75rem !important;
    }

    .table {
        --bs-table-bg: transparent;
        --bs-table-hover-bg: rgba(139, 92, 246, 0.05);
        color: var(--text-primary);
    }

    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .table thead th {
        padding-top: 0.95rem;
        padding-bottom: 0.95rem;
        border-bottom-width: 1px;
        border-color: rgba(139, 92, 246, 0.12);
        color: var(--text-secondary);
        font-size: 0.76rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .table tbody td {
        padding-top: 1rem;
        padding-bottom: 1rem;
        border-color: rgba(139, 92, 246, 0.09);
        vertical-align: middle;
    }

    .badge {
        padding: 0.48rem 0.75rem;
        border-radius: 999px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .result-checker-page .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border-radius: 12px;
        font-weight: 700;
        transition: all 0.2s ease;
    }

    .result-checker-page .btn-primary {
        background: linear-gradient(135deg, #1d4ed8, #2563eb);
        border-color: #1d4ed8;
        color: #fff;
        box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
    }

    .result-checker-page .btn-outline-primary {
        color: #1e40af;
        border-color: #c7d2fe;
        background: #f8fafc;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.08);
    }

    .result-checker-page .btn-outline-danger {
        color: #b91c1c;
        border-color: #fecaca;
        background: #fff7f7;
        box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.08);
    }

    .result-checker-page .btn-primary:hover,
    .result-checker-page .btn-primary:focus {
        background: linear-gradient(135deg, #1e40af, #1d4ed8);
        border-color: #1e40af;
        color: #fff;
        transform: translateY(-1px);
    }

    .result-checker-page .btn-outline-primary:hover,
    .result-checker-page .btn-outline-primary:focus {
        color: #1e3a8a;
        border-color: #93c5fd;
        background: #eff6ff;
    }

    .result-checker-page .btn-outline-danger:hover,
    .result-checker-page .btn-outline-danger:focus {
        color: #991b1b;
        border-color: #fca5a5;
        background: #fef2f2;
    }

    .result-checker-page .form-control,
    .result-checker-page .form-select,
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        min-height: 48px;
        border-radius: 14px;
        border-color: rgba(139, 92, 246, 0.16);
        box-shadow: none;
    }

    .result-checker-page .form-control:focus,
    .result-checker-page .form-select:focus,
    .dataTables_wrapper .dataTables_filter input:focus,
    .dataTables_wrapper .dataTables_length select:focus {
        border-color: rgba(139, 92, 246, 0.55);
        box-shadow: 0 0 0 0.2rem rgba(139, 92, 246, 0.12);
    }

    .result-checker-page .form-check-input:checked {
        background-color: #7c3aed;
        border-color: #7c3aed;
    }

    .result-checker-page .form-check-input:focus {
        box-shadow: 0 0 0 0.2rem rgba(139, 92, 246, 0.12);
    }

    .modal-content {
        border: 1px solid rgba(139, 92, 246, 0.12);
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(31, 17, 71, 0.18);
    }

    .modal-header,
    .modal-footer {
        border-color: rgba(139, 92, 246, 0.1);
    }

    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        padding: 0.45rem 0.75rem;
    }

    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_paginate {
        margin-top: 0.85rem;
        color: var(--text-secondary);
    }

    .dropdown-item i,
    .dropdown-header i,
    .nav-link i,
    .btn i,
    .rc-hero-chip i,
    .rc-metric-label i {
        margin-right: 0;
        line-height: 1;
    }

    [data-theme="dark"] body {
        background: radial-gradient(circle at top left, rgba(139, 92, 246, 0.16), transparent 28%), #080211;
    }

    [data-theme="dark"] #resultCheckerTabs,
    [data-theme="dark"] .stat-card,
    [data-theme="dark"] #resultCheckerTabContent .card,
    [data-theme="dark"] .modal-content {
        background: rgba(17, 10, 31, 0.9) !important;
        border-color: rgba(167, 139, 250, 0.18) !important;
        box-shadow: 0 18px 38px rgba(0, 0, 0, 0.34);
    }

    [data-theme="dark"] .stat-card h3,
    [data-theme="dark"] #resultCheckerTabContent .card-header h5,
    [data-theme="dark"] .table,
    [data-theme="dark"] .result-checker-page .form-control,
    [data-theme="dark"] .result-checker-page .form-select,
    [data-theme="dark"] .dataTables_wrapper .dataTables_filter input,
    [data-theme="dark"] .dataTables_wrapper .dataTables_length select {
        color: #f5f3ff !important;
    }

    [data-theme="dark"] .text-muted,
    [data-theme="dark"] .table thead th,
    [data-theme="dark"] .dataTables_wrapper .dataTables_info,
    [data-theme="dark"] .dataTables_wrapper .dataTables_length,
    [data-theme="dark"] .dataTables_wrapper .dataTables_filter,
    [data-theme="dark"] .dataTables_wrapper .dataTables_paginate {
        color: rgba(226, 232, 240, 0.72) !important;
    }

    [data-theme="dark"] .table tbody td,
    [data-theme="dark"] .table thead th,
    [data-theme="dark"] .card-header,
    [data-theme="dark"] .modal-header,
    [data-theme="dark"] .modal-footer {
        border-color: rgba(167, 139, 250, 0.12) !important;
    }

    [data-theme="dark"] #resultCheckerTabContent .card-header {
        background: linear-gradient(180deg, rgba(37, 99, 235, 0.12), rgba(15, 23, 42, 0.96)) !important;
    }

    [data-theme="dark"] #resultCheckerTabs {
        background: rgba(17, 10, 31, 0.9);
    }

    [data-theme="dark"] #resultCheckerTabs .nav-link {
        color: rgba(226, 232, 240, 0.76);
    }

    [data-theme="dark"] #resultCheckerTabs .nav-link:hover,
    [data-theme="dark"] #resultCheckerTabs .nav-link.active {
        color: #fff;
        background: rgba(139, 92, 246, 0.2);
    }

    [data-theme="dark"] .result-checker-page .form-control,
    [data-theme="dark"] .result-checker-page .form-select,
    [data-theme="dark"] .dataTables_wrapper .dataTables_filter input,
    [data-theme="dark"] .dataTables_wrapper .dataTables_length select {
        background: #120a23;
        border-color: rgba(167, 139, 250, 0.16);
    }

    [data-theme="dark"] .result-checker-page .btn-outline-primary {
        background: rgba(30, 41, 59, 0.95);
        border-color: rgba(96, 165, 250, 0.3);
        color: #dbeafe;
    }

    [data-theme="dark"] .result-checker-page .btn-outline-danger {
        background: rgba(69, 10, 10, 0.5);
        border-color: rgba(248, 113, 113, 0.28);
        color: #fecaca;
    }

    @media (max-width: 991.98px) {
        .rc-hero {
            grid-template-columns: 1fr;
            padding: 1.35rem;
        }
    }

    @media (max-width: 767.98px) {
        html,
        body,
        .dashboard-wrapper,
        .main-content,
        .dashboard-content,
        .result-checker-page,
        .result-checker-shell,
        .rc-hero,
        #resultCheckerTabs,
        #resultCheckerTabContent,
        .tab-content,
        .tab-pane,
        .card,
        .card-body,
        .table-responsive,
        .table,
        .dataTables_wrapper {
            max-width: 100vw;
            overflow-x: hidden;
        }

        .dashboard-content {
            overflow-x: hidden;
        }

        .result-checker-page {
            width: 100%;
        }

        .rc-hero h2 {
            font-size: 1.65rem;
        }

        .rc-hero-metrics {
            grid-template-columns: 1fr;
        }

        .rc-hero,
        #resultCheckerTabContent .card-body,
        #resultCheckerTabContent .card-header,
        .stat-card .card-body {
            padding: 1rem;
        }

        #resultCheckerTabs {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            scrollbar-width: none;
        }

        #resultCheckerTabs .nav-link {
            white-space: nowrap;
        }

        #resultCheckerTabs::-webkit-scrollbar {
            display: none;
        }

        #resultCheckerTabContent .card-header.d-flex.justify-content-between.align-items-center {
            flex-direction: column;
            align-items: stretch !important;
            gap: 0.85rem;
        }

        #resultCheckerTabContent .card-header .d-flex.gap-2 {
            width: 100%;
            flex-direction: column;
        }

        #resultCheckerTabContent .card-header .d-flex.gap-2 > *,
        #resultCheckerTabContent .card-header .d-flex.gap-2 form,
        #resultCheckerTabContent .card-header .d-flex.gap-2 .btn {
            width: 100%;
        }

        #resultCheckerTabContent .card-header .btn {
            justify-content: center;
            min-height: 48px;
        }

        .table-responsive {
            overflow: visible;
        }

        #beceTable,
        #wassceTable,
        #purchasesTable {
            min-width: 0 !important;
        }

        #beceTable thead,
        #wassceTable thead,
        #purchasesTable thead {
            display: none;
        }

        #beceTable tbody,
        #wassceTable tbody,
        #purchasesTable tbody {
            display: grid;
            gap: 0.9rem;
        }

        #beceTable tbody tr,
        #wassceTable tbody tr,
        #purchasesTable tbody tr {
            display: block;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            background: var(--bg-primary);
            box-shadow: var(--shadow);
            padding: 0.8rem 0.9rem;
        }

        #beceTable tbody td,
        #wassceTable tbody td,
        #purchasesTable tbody td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            width: 100%;
            padding: 0.55rem 0;
            border: 0;
            text-align: right;
            white-space: normal;
        }

        #beceTable tbody td::before,
        #wassceTable tbody td::before,
        #purchasesTable tbody td::before {
            content: attr(data-label);
            flex: 0 0 42%;
            color: var(--text-secondary);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            text-align: left;
        }

        #beceTable tbody td form,
        #wassceTable tbody td form,
        #purchasesTable tbody td form {
            margin-left: auto;
        }

        #beceTable tbody td .btn,
        #wassceTable tbody td .btn,
        #purchasesTable tbody td .btn {
            margin-left: auto;
        }

        .dataTables_wrapper .row {
            row-gap: 0.75rem;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            width: 100%;
            margin-top: 0;
            text-align: left !important;
            float: none !important;
        }

        .dataTables_wrapper .dataTables_filter label,
        .dataTables_wrapper .dataTables_length label {
            display: block;
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            width: 100%;
            margin-left: 0 !important;
        }
    }
</style>
<?php if (false): ?>
?>
<!DOCTYPE html>
<!-- views/layouts/admin.php -->
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Result Checker Management - <?php echo htmlspecialchars($site_name); ?></title>
    
        
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/bootstrap-icons/bootstrap-icons.css')); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --brand-primary: #8B5CF6;
            --brand-secondary: #A78BFA;
            --brand-deep: #6D28D9;
            --brand-soft: #f4f0ff;
            --brand-ink: #1f1147;
            --admin-color: var(--brand-primary);
            --admin-dark: var(--brand-deep);
            --sidebar-bg: #0B0017;
            --sidebar-hover: #1A0B2E;
            --page-bg: #f7f5ff;
            --content-bg: #ffffff;
            --card-bg: #ffffff;
            --card-header-bg: #faf7ff;
            --text-main: #1f1147;
            --text-muted: #6b5b8c;
            --border-color: #e3def5;
            --table-hover-bg: #f2edff;
            --tab-active-bg: #ffffff;
            --shadow-soft: 0 16px 40px rgba(22, 8, 40, 0.08);
            --ring: rgba(139, 92, 246, 0.22);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: radial-gradient(circle at 12% 10%, rgba(139, 92, 246, 0.10), transparent 38%), var(--page-bg);
            color: var(--text-main);
        }

        html,
        body {
            max-width: 100%;
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #16072d 0%, var(--sidebar-bg) 70%);
            z-index: 1040;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
            background: linear-gradient(180deg, rgba(139, 92, 246, 0.18) 0%, rgba(139, 92, 246, 0) 100%);
        }

        .sidebar-header img {
            max-height: 40px;
            margin-bottom: 0.5rem;
        }

        .sidebar-header h6 {
            color: white;
            font-size: 1.125rem;
            font-weight: 700;
            margin: 0;
        }

        .sidebar-header small {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.75rem;
        }

        .admin-badge {
            background: linear-gradient(135deg, var(--admin-color) 0%, var(--admin-dark) 100%);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.625rem;
            font-weight: 700;
            color: white;
            display: inline-block;
            margin-top: 0.25rem;
            letter-spacing: 1px;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 0.75rem 1rem;
            margin: 0.125rem 0.5rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:hover {
            color: white;
            background: var(--sidebar-hover);
            transform: translateX(4px);
        }

        .sidebar .nav-link.active {
            color: white;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            box-shadow: 0 8px 18px rgba(139, 92, 246, 0.35);
        }

        .sidebar .nav-link i {
            width: 1.5rem;
            text-align: center;
            margin-right: 0.75rem;
            font-size: 1.125rem;
        }

        .content-area .form-control,
        .content-area .form-select,
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            color: var(--text-main) !important;
            background-color: var(--content-bg) !important;
            border-color: var(--border-color) !important;
            caret-color: var(--text-main);
        }

        .content-area .form-control::placeholder,
        .dataTables_wrapper .dataTables_filter input::placeholder {
            color: var(--text-muted);
            opacity: 1;
        }

        .dataTables_wrapper .dataTables_filter label,
        .dataTables_wrapper .dataTables_length label {
            color: var(--text-main);
            font-weight: 500;
        }

        .content-area .form-control:focus,
        .content-area .form-select:focus,
        .dataTables_wrapper .dataTables_filter input:focus,
        .dataTables_wrapper .dataTables_length select:focus {
            border-color: var(--brand-primary) !important;
            box-shadow: 0 0 0 0.2rem var(--ring);
        }

        [data-bs-theme="dark"] .content-area .form-control,
        [data-bs-theme="dark"] .content-area .form-select,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter input,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_length select {
            color: var(--text-main) !important;
            background-color: var(--content-bg) !important;
            border-color: var(--border-color) !important;
            caret-color: var(--text-main);
        }

        [data-bs-theme="dark"] .content-area .form-control::placeholder,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter input::placeholder {
            color: var(--text-muted);
            opacity: 1;
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter label,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_length label {
            color: var(--text-main);
        }

        .content-area .btn-primary {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
        }

        .content-area .btn-primary:hover,
        .content-area .btn-primary:focus {
            background-color: var(--brand-secondary);
            border-color: var(--brand-secondary);
            color: #ffffff;
        }

        .content-area .btn-danger {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
        }

        .content-area .btn-danger:hover,
        .content-area .btn-danger:focus {
            background-color: var(--brand-secondary);
            border-color: var(--brand-secondary);
            color: #ffffff;
        }

        .content-area .btn-secondary {
            background-color: #4c3a74;
            border-color: #3a2a5e;
            color: #ffffff;
        }

        .content-area .btn-secondary:hover,
        .content-area .btn-secondary:focus {
            background-color: #3a2a5e;
            border-color: #2b1f4a;
            color: #ffffff;
        }

        .content-area .btn-outline-primary {
            color: var(--brand-primary);
            border-color: var(--brand-primary);
        }

        .content-area .btn-outline-primary:hover,
        .content-area .btn-outline-primary:focus {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
        }

        .dataTables_wrapper .dataTables_paginate .page-link {
            color: var(--text-main);
            border-color: var(--border-color);
            background-color: var(--content-bg);
        }

        .dataTables_wrapper .dataTables_paginate .page-link:hover {
            color: var(--text-main);
            background-color: var(--brand-soft);
            border-color: var(--brand-primary);
        }

        .dataTables_wrapper .dataTables_paginate .page-item.active .page-link {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-link {
            color: var(--text-main);
            border-color: var(--border-color);
            background-color: var(--content-bg);
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-link:hover {
            color: var(--text-main);
            background-color: #1c0f33;
            border-color: var(--brand-secondary);
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-item.active .page-link {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #ffffff;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 0;
        }

        .top-header {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.10) 0%, rgba(167, 139, 250, 0.04) 100%), var(--card-header-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1030;
            display: flex;
            align-items: center;
            justify-content: space-between;
            backdrop-filter: blur(6px);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--brand-ink);
            margin: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .admin-badge-header {
            background: linear-gradient(135deg, var(--admin-color) 0%, var(--admin-dark) 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .icon-btn {
            width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--content-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-muted);
            font-size: 1.125rem;
        }

        .icon-btn:hover {
            background: var(--brand-primary);
            color: white;
            border-color: var(--brand-primary);
            transform: translateY(-2px);
        }

        .icon-btn i {
            pointer-events: none;
        }

        .user-menu-btn {
            background: var(--content-bg);
            border: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .user-menu-btn:hover {
            background: var(--brand-soft);
            border-color: var(--brand-primary);
        }

        /* Content Area */
        .content-area {
            padding: 1.5rem;
        }

        .main-content,
        .content-area,
        .card,
        .card-body,
        .table-responsive,
        .dataTables_wrapper {
            max-width: 100%;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .dataTables_wrapper .row {
            margin-left: 0;
            margin-right: 0;
        }

        .dataTables_wrapper .row > * {
            padding-left: 0;
            padding-right: 0;
        }

        .card {
            background: var(--card-bg);
            color: var(--text-main);
            border-radius: 1rem;
            box-shadow: var(--shadow-soft);
        }

        .card-header {
            background: var(--card-header-bg) !important;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .card-title,
        .card-header h5,
        .card-header h6 {
            color: var(--text-main);
        }

        .nav-tabs {
            border-bottom: none;
            background: var(--card-bg);
            padding: 0.5rem;
            gap: 0.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-soft);
        }

        .nav-tabs .nav-link {
            color: var(--text-muted);
            border-color: transparent;
            border-radius: 0.75rem;
            padding: 0.6rem 1.1rem;
        }

        .nav-tabs .nav-link:hover {
            color: var(--text-main);
            background: var(--brand-soft);
        }

        .nav-tabs .nav-link.active {
            color: #ffffff;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            border-color: transparent;
            box-shadow: 0 10px 24px rgba(139, 92, 246, 0.25);
        }

        [data-bs-theme="dark"] .nav-tabs {
            background: #160b2b;
        }

        [data-bs-theme="dark"] .nav-tabs .nav-link:hover {
            background: #1f1037;
        }

        .table {
            color: var(--text-main);
        }

        .table > :not(caption) > * > * {
            border-color: var(--border-color);
        }

        .table-hover > tbody > tr:hover > * {
            background-color: var(--table-hover-bg);
            color: var(--text-main);
        }

        .text-muted {
            color: var(--text-muted) !important;
        }

        .dropdown-menu {
            background: var(--content-bg);
            border-color: var(--border-color);
        }

        .dropdown-item {
            color: var(--text-main);
        }

        .dropdown-item:hover {
            background: var(--table-hover-bg);
        }

        .modal-content {
            background: var(--card-bg);
            color: var(--text-main);
            border-color: var(--border-color);
        }

        .modal-header,
        .modal-footer {
            border-color: var(--border-color);
        }

        [data-bs-theme="dark"] {
            --page-bg: #0B0017;
            --content-bg: #120126;
            --card-bg: #160b2b;
            --card-header-bg: #140824;
            --text-main: #f8f9fa;
            --text-muted: #b8bcc8;
            --border-color: #2a134b;
            --table-hover-bg: #1f1037;
            --tab-active-bg: #160b2b;
            --sidebar-bg: #0B0017;
            --sidebar-hover: #1A0B2E;
            --brand-ink: #f6f1ff;
        }

        /* Mobile Styles */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 2.5rem;
                height: 2.5rem;
                background: var(--content-bg);
                border: 1px solid var(--border-color);
                border-radius: 0.5rem;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .mobile-menu-btn:hover {
                background: var(--admin-color);
                color: white;
                border-color: var(--admin-color);
            }

            .mobile-menu-btn i {
                font-size: 1.25rem;
            }

            .page-title {
                font-size: 1.25rem;
            }

            .admin-badge-header {
                font-size: 0.75rem;
                padding: 0.375rem 0.75rem;
            }

            .header-actions {
                gap: 0.5rem;
            }

            .content-area {
                padding: 1rem;
            }

            .top-header {
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .header-actions {
                flex-wrap: wrap;
                justify-content: flex-end;
                width: 100%;
            }
        }

        /* Sidebar Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1035;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.show {
            display: block;
            opacity: 1;
        }

        /* Alerts */
        .alert {
            border: none;
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
        }

        .alert-dismissible .btn-close {
            padding: 1.25rem 1rem;
        }

        /* Dropdown Menu */
        .dropdown-menu {
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            padding: 0.5rem;
        }

        .dropdown-item {
            border-radius: 0.5rem;
            padding: 0.625rem 1rem;
            font-size: 0.9375rem;
        }

        .dropdown-item:hover {
            background: var(--brand-soft);
        }

        [data-bs-theme="dark"] .dropdown-item:hover {
            background: #1f1037;
        }

        .dropdown-header {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--brand-primary);
            padding: 0.5rem 1rem;
        }

        .stat-card {
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--brand-primary), var(--brand-secondary));
        }

        .stat-title {
            font-weight: 700;
            letter-spacing: 0.2px;
            color: var(--brand-ink);
        }

        .stat-title-bece i,
        .stat-title-bece {
            color: var(--brand-primary);
        }

        .stat-title-wassce i,
        .stat-title-wassce {
            color: var(--brand-deep);
        }

        .card-header.bg-white {
            background: var(--card-header-bg) !important;
        }

        .text-primary {
            color: var(--brand-primary) !important;
        }

        /* Scrollbar */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .result-checker-page {
            padding: 1.5rem;
            background: transparent;
        }

        .result-checker-shell {
            max-width: 1320px;
            margin: 0 auto;
        }

        .rc-hero {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.95fr);
            gap: 1.5rem;
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            border-radius: 26px;
            border: 1px solid rgba(139, 92, 246, 0.16);
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.34), transparent 34%),
                linear-gradient(135deg, rgba(109, 40, 217, 0.98), rgba(124, 58, 237, 0.92) 55%, rgba(236, 72, 153, 0.82) 100%);
            box-shadow: 0 28px 60px rgba(88, 28, 135, 0.24);
            color: #fff;
        }

        .rc-hero::after {
            content: '';
            position: absolute;
            inset: auto -60px -90px auto;
            width: 220px;
            height: 220px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            filter: blur(6px);
        }

        .rc-hero-copy,
        .rc-hero-metrics {
            position: relative;
            z-index: 1;
        }

        .rc-hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.4rem 0.85rem;
            margin-bottom: 1rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            color: rgba(255, 255, 255, 0.96);
            font-size: 0.83rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .rc-hero h2 {
            margin: 0 0 0.75rem;
            font-size: clamp(1.9rem, 2.4vw, 2.55rem);
            line-height: 1.08;
            font-weight: 800;
            color: #fff;
        }

        .rc-hero p {
            max-width: 620px;
            margin: 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 1rem;
        }

        .rc-hero-pricing {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.15rem;
        }

        .rc-hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.7rem 0.95rem;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
            font-weight: 600;
        }

        .rc-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.4rem;
        }

        .rc-hero-actions .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.82rem 1.1rem;
            border-radius: 14px;
            border-width: 0;
            font-weight: 700;
        }

        .rc-hero-actions .btn-light {
            color: var(--brand-deep);
            box-shadow: 0 18px 30px rgba(17, 24, 39, 0.16);
        }

        .rc-hero-actions .btn-outline-light {
            border: 1px solid rgba(255, 255, 255, 0.42);
        }

        .rc-hero-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.95rem;
            align-content: start;
        }

        .rc-metric {
            padding: 1rem 1.05rem;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(10, 6, 27, 0.16);
            backdrop-filter: blur(10px);
        }

        .rc-metric-label {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-bottom: 0.55rem;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.83rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .rc-metric-value {
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1.1;
            color: #fff;
        }

        .rc-metric-note {
            margin-top: 0.28rem;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        .stat-card {
            border: 1px solid var(--border-color) !important;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 18px 40px rgba(31, 17, 71, 0.08);
            overflow: hidden;
        }

        .stat-card .card-body {
            padding: 1.45rem;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.9), rgba(236, 72, 153, 0.9));
        }

        .stat-title {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .stat-title i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            font-size: 1.15rem;
            color: #fff;
            box-shadow: 0 16px 24px rgba(139, 92, 246, 0.2);
        }

        .stat-title-bece i {
            background: linear-gradient(135deg, #7c3aed, #a855f7);
        }

        .stat-title-wassce i {
            background: linear-gradient(135deg, #ec4899, #f97316);
        }

        .stat-card h3 {
            font-weight: 800;
            color: var(--text-main);
        }

        .stat-card small {
            color: var(--text-muted) !important;
        }

        #resultCheckerTabs {
            gap: 0.6rem;
            padding: 0.35rem;
            border: 0;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.74);
            box-shadow: inset 0 0 0 1px rgba(139, 92, 246, 0.08);
        }

        #resultCheckerTabs .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.82rem 1.05rem;
            border: 0;
            border-radius: 16px;
            color: var(--text-muted);
            font-weight: 700;
            transition: all 0.2s ease;
        }

        #resultCheckerTabs .nav-link:hover {
            color: var(--brand-deep);
            background: rgba(139, 92, 246, 0.08);
        }

        #resultCheckerTabs .nav-link.active {
            color: var(--brand-deep);
            background: #fff;
            box-shadow: 0 10px 24px rgba(139, 92, 246, 0.12);
        }

        #resultCheckerTabContent .card {
            border: 1px solid var(--border-color) !important;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 18px 40px rgba(31, 17, 71, 0.08);
            overflow: hidden;
        }

        #resultCheckerTabContent .card-header {
            padding: 1.15rem 1.35rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(180deg, rgba(139, 92, 246, 0.08), rgba(255, 255, 255, 0.96)) !important;
        }

        #resultCheckerTabContent .card-header h5 {
            font-weight: 700;
            color: var(--text-main);
        }

        #resultCheckerTabContent .card-body {
            padding: 1.35rem;
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-hover-bg: rgba(139, 92, 246, 0.05);
            color: var(--text-main);
        }

        .table thead th {
            padding-top: 0.95rem;
            padding-bottom: 0.95rem;
            border-bottom-width: 1px;
            border-color: rgba(139, 92, 246, 0.12);
            color: var(--text-muted);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .table tbody td {
            padding-top: 1rem;
            padding-bottom: 1rem;
            border-color: rgba(139, 92, 246, 0.09);
            vertical-align: middle;
        }

        .badge {
            padding: 0.48rem 0.75rem;
            border-radius: 999px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border-radius: 14px;
            font-weight: 700;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed, #8b5cf6);
            border-color: transparent;
            box-shadow: 0 12px 24px rgba(124, 58, 237, 0.22);
        }

        .btn-outline-primary {
            color: var(--brand-deep);
            border-color: rgba(124, 58, 237, 0.24);
            background: rgba(124, 58, 237, 0.04);
        }

        .btn-outline-danger {
            color: #dc2626;
            border-color: rgba(220, 38, 38, 0.18);
            background: rgba(220, 38, 38, 0.04);
        }

        .form-control,
        .form-select {
            min-height: 48px;
            border-radius: 14px;
            border-color: rgba(139, 92, 246, 0.16);
            box-shadow: none;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: rgba(139, 92, 246, 0.55);
            box-shadow: 0 0 0 0.2rem rgba(139, 92, 246, 0.12);
        }

        .form-check-input:checked {
            background-color: #7c3aed;
            border-color: #7c3aed;
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(139, 92, 246, 0.12);
        }

        .modal-content {
            border: 1px solid rgba(139, 92, 246, 0.12);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(31, 17, 71, 0.18);
        }

        .modal-header,
        .modal-footer {
            border-color: rgba(139, 92, 246, 0.1);
        }

        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border-radius: 12px;
            border: 1px solid rgba(139, 92, 246, 0.16);
            padding: 0.45rem 0.75rem;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 0.85rem;
            color: var(--text-muted);
        }

        .top-header {
            margin: 1.5rem 1.5rem 0;
            padding: 1rem 1.25rem;
            border: 1px solid rgba(139, 92, 246, 0.12);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.76);
            backdrop-filter: blur(18px);
            box-shadow: 0 18px 35px rgba(31, 17, 71, 0.08);
        }

        .page-title {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-main);
        }

        .header-actions {
            gap: 0.9rem;
        }

        .icon-btn,
        .user-menu-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            min-height: 46px;
            border-radius: 999px;
            border: 1px solid rgba(139, 92, 246, 0.16);
            background: #fff;
            color: var(--text-main);
            box-shadow: 0 10px 24px rgba(31, 17, 71, 0.08);
        }

        .icon-btn {
            width: 46px;
            justify-content: center;
        }

        .user-menu-btn {
            padding: 0.45rem 0.7rem 0.45rem 0.45rem;
        }

        .user-menu-btn .bi-person-circle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            color: #fff;
            background: linear-gradient(135deg, #7c3aed, #8b5cf6);
        }

        .dropdown-menu {
            border: 1px solid rgba(139, 92, 246, 0.12);
            border-radius: 18px;
            padding: 0.55rem;
            box-shadow: 0 20px 45px rgba(31, 17, 71, 0.16);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            border-radius: 12px;
            padding: 0.7rem 0.8rem;
            font-weight: 600;
        }

        .dropdown-item i,
        .dropdown-header i,
        .nav-link i,
        .btn i,
        .rc-hero-chip i,
        .rc-metric-label i {
            margin-right: 0;
            line-height: 1;
        }

        [data-bs-theme="dark"] body {
            background: radial-gradient(circle at top left, rgba(139, 92, 246, 0.16), transparent 28%), #080211;
            color: #f5f3ff;
        }

        [data-bs-theme="dark"] .top-header,
        [data-bs-theme="dark"] #resultCheckerTabs,
        [data-bs-theme="dark"] .stat-card,
        [data-bs-theme="dark"] #resultCheckerTabContent .card,
        [data-bs-theme="dark"] .icon-btn,
        [data-bs-theme="dark"] .user-menu-btn,
        [data-bs-theme="dark"] .dropdown-menu {
            background: rgba(17, 10, 31, 0.9) !important;
            border-color: rgba(167, 139, 250, 0.18) !important;
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.34);
        }

        [data-bs-theme="dark"] .page-title,
        [data-bs-theme="dark"] .card-title,
        [data-bs-theme="dark"] #resultCheckerTabContent .card-header h5,
        [data-bs-theme="dark"] .table,
        [data-bs-theme="dark"] .stat-card h3,
        [data-bs-theme="dark"] .dropdown-item,
        [data-bs-theme="dark"] .dropdown-header,
        [data-bs-theme="dark"] .user-menu-btn {
            color: #f5f3ff !important;
        }

        [data-bs-theme="dark"] .stat-card small,
        [data-bs-theme="dark"] .table thead th,
        [data-bs-theme="dark"] .text-muted,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_info,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_length,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate,
        [data-bs-theme="dark"] .dropdown-item.text-danger {
            color: rgba(226, 232, 240, 0.72) !important;
        }

        [data-bs-theme="dark"] .table tbody td,
        [data-bs-theme="dark"] .table thead th,
        [data-bs-theme="dark"] .card-header,
        [data-bs-theme="dark"] .modal-header,
        [data-bs-theme="dark"] .modal-footer {
            border-color: rgba(167, 139, 250, 0.12) !important;
        }

        [data-bs-theme="dark"] #resultCheckerTabs .nav-link {
            color: rgba(226, 232, 240, 0.76);
        }

        [data-bs-theme="dark"] #resultCheckerTabs .nav-link:hover,
        [data-bs-theme="dark"] #resultCheckerTabs .nav-link.active {
            color: #fff;
            background: rgba(139, 92, 246, 0.2);
        }

        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_filter input,
        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_length select,
        [data-bs-theme="dark"] .modal-content {
            background: #120a23;
            color: #f5f3ff;
            border-color: rgba(167, 139, 250, 0.16);
        }

        [data-bs-theme="dark"] .btn-outline-primary {
            background: rgba(124, 58, 237, 0.12);
            border-color: rgba(167, 139, 250, 0.24);
            color: #ddd6fe;
        }

        [data-bs-theme="dark"] .btn-outline-danger {
            background: rgba(220, 38, 38, 0.12);
            border-color: rgba(248, 113, 113, 0.24);
            color: #fecaca;
        }

        @media (max-width: 991.98px) {
            .result-checker-page {
                padding: 1rem;
            }

            .top-header {
                margin: 1rem 1rem 0;
                padding: 0.9rem 1rem;
            }

            .rc-hero {
                grid-template-columns: 1fr;
                padding: 1.35rem;
            }
        }

        @media (max-width: 767.98px) {
            .page-title {
                font-size: 1.25rem;
            }

            .rc-hero h2 {
                font-size: 1.65rem;
            }

            .rc-hero-metrics {
                grid-template-columns: 1fr;
            }

            #resultCheckerTabs {
                display: flex;
                flex-wrap: nowrap;
                overflow-x: auto;
            }

            #resultCheckerTabs .nav-link {
                white-space: nowrap;
            }

            #resultCheckerTabContent .card-header {
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
                        <h6><?php echo htmlspecialchars($site_name); ?></h6>
            <small>Admin Panel</small>
            <div class="mt-2">
                <span class="admin-badge">ADMIN</span>
            </div>
        </div>
        
        <?php renderAdminSidebar(); ?>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="mobile-menu-btn d-lg-none" id="mobileMenuBtn" type="button">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="page-title d-none d-md-block">Result Checker Management</h1>
            </div>
            
            <div class="header-actions">
                <span class="admin-badge-header d-none d-sm-flex">
                    <i class="bi bi-shield-check"></i>
                    <span>ADMIN</span>
                </span>
                
                <!-- Theme Toggle -->
                <button class="icon-btn" id="themeToggle" type="button" title="Toggle theme">
                    <i class="bi bi-moon-stars"></i>
                </button>
                
                <div class="dropdown">
                    <button class="user-menu-btn" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline"><?php echo htmlspecialchars($current_email); ?></span>
                        <i class="bi bi-chevron-down d-none d-md-inline"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header">
                            <i class="bi bi-person-circle me-2"></i><?php echo htmlspecialchars($current_email); ?>                        </h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php">
                            <i class="bi bi-person me-2"></i>Profile
                        </a></li>
                        <li><a class="dropdown-item" href="#">
                            <i class="bi bi-gear me-2"></i>Settings
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Content Area -->
        <div class="content-area result-checker-page">
<?php endif; ?>
<div class="result-checker-page">
            <!-- Notifications -->
            <?php if ($flash): ?>
                <?php
                    $flash_type = $flash['type'] ?? 'info';
                    $flash_class = $flash_type === 'error' ? 'danger' : $flash_type;
                ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash_class); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['message'] ?? ''); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <!-- Page Content -->
            <div class="result-checker-shell">
                <section class="rc-hero">
                    <div class="rc-hero-copy">
                        <span class="rc-hero-eyebrow">
                            <i class="bi bi-stars"></i>
                            Result Checker Control Room
                        </span>
                        <h2>Manage pricing, stock, uploads, and purchases from one polished workspace.</h2>
                        <p>Everything for BECE and WASSCE cards is kept visible here so you can update prices quickly, monitor stock levels, and review purchases without jumping between pages.</p>
                        <div class="rc-hero-pricing">
                            <span class="rc-hero-chip">
                                <i class="bi bi-book"></i>
                                BECE: <?php echo htmlspecialchars($bece_price_display); ?>
                            </span>
                            <span class="rc-hero-chip">
                                <i class="bi bi-mortarboard"></i>
                                WASSCE: <?php echo htmlspecialchars($wassce_price_display); ?>
                            </span>
                        </div>
                        <div class="rc-hero-actions">
                            <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#bulkBeceModal">
                                <i class="bi bi-cloud-upload"></i>
                                Bulk Upload BECE
                            </button>
                            <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#bulkWassceModal">
                                <i class="bi bi-cloud-upload"></i>
                                Bulk Upload WASSCE
                            </button>
                            <a class="btn btn-outline-light" href="result-checker.php?tab=purchases">
                                <i class="bi bi-receipt-cutoff"></i>
                                Review Purchases
                            </a>
                        </div>
                    </div>
                    <div class="rc-hero-metrics">
                        <div class="rc-metric">
                            <div class="rc-metric-label">
                                <i class="bi bi-box-seam"></i>
                                Live Stock
                            </div>
                            <div class="rc-metric-value"><?php echo number_format($total_available_stock); ?></div>
                            <div class="rc-metric-note">Available cards across both exam types</div>
                        </div>
                        <div class="rc-metric">
                            <div class="rc-metric-label">
                                <i class="bi bi-check2-circle"></i>
                                Enabled Services
                            </div>
                            <div class="rc-metric-value"><?php echo number_format($enabled_checker_count); ?>/2</div>
                            <div class="rc-metric-note">Purchase switches currently active</div>
                        </div>
                        <div class="rc-metric">
                            <div class="rc-metric-label">
                                <i class="bi bi-bag-check"></i>
                                Purchases Logged
                            </div>
                            <div class="rc-metric-value"><?php echo number_format($total_purchased_cards); ?></div>
                            <div class="rc-metric-note">Cards already sold to customers</div>
                        </div>
                        <div class="rc-metric">
                            <div class="rc-metric-label">
                                <i class="bi bi-sliders"></i>
                                Active View
                            </div>
                            <div class="rc-metric-value"><?php echo strtoupper(htmlspecialchars($active_tab)); ?></div>
                            <div class="rc-metric-note">You can switch tabs below at any time</div>
                        </div>
                    </div>
                </section>
<div class="container-fluid">
    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <h5 class="card-title stat-title stat-title-bece mb-3">
                        <i class="bi bi-book"></i> BECE Statistics
                    </h5>
                    <div class="row text-center">
                        <div class="col-4">
                            <h3 class="mb-0"><?php echo number_format($stats['BECE']['total']); ?></h3>
                            <small class="text-muted">Total Cards</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['BECE']['available']); ?></h3>
                            <small class="text-muted">Available</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0 text-info"><?php echo number_format($stats['BECE']['purchased']); ?></h3>
                            <small class="text-muted">Purchased</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <h5 class="card-title stat-title stat-title-wassce mb-3">
                        <i class="bi bi-mortarboard"></i> WASSCE Statistics
                    </h5>
                    <div class="row text-center">
                        <div class="col-4">
                            <h3 class="mb-0"><?php echo number_format($stats['WASSCE']['total']); ?></h3>
                            <small class="text-muted">Total Cards</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['WASSCE']['available']); ?></h3>
                            <small class="text-muted">Available</small>
                        </div>
                        <div class="col-4">
                            <h3 class="mb-0 text-info"><?php echo number_format($stats['WASSCE']['purchased']); ?></h3>
                            <small class="text-muted">Purchased</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="resultCheckerTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" aria-selected="<?php echo $active_tab === 'settings' ? 'true' : 'false'; ?>">
                <i class="bi bi-gear"></i> Settings
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'bece' ? 'active' : ''; ?>" id="bece-tab" data-bs-toggle="tab" data-bs-target="#bece" type="button" aria-selected="<?php echo $active_tab === 'bece' ? 'true' : 'false'; ?>">
                <i class="bi bi-book"></i> BECE Cards
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'wassce' ? 'active' : ''; ?>" id="wassce-tab" data-bs-toggle="tab" data-bs-target="#wassce" type="button" aria-selected="<?php echo $active_tab === 'wassce' ? 'true' : 'false'; ?>">
                <i class="bi bi-mortarboard"></i> WASSCE Cards
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'purchases' ? 'active' : ''; ?>" id="purchases-tab" data-bs-toggle="tab" data-bs-target="#purchases" type="button" aria-selected="<?php echo $active_tab === 'purchases' ? 'true' : 'false'; ?>">
                <i class="bi bi-cart-check"></i> Purchases
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="resultCheckerTabContent">
        
        <!-- Settings Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'settings' ? 'show active' : ''; ?>" id="settings" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Result Checker Settings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="result-checker.php">
                        <input type="hidden" name="action" value="update_settings">
                        <input type="hidden" name="redirect_tab" value="settings">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">BECE Price (GHS)</label>
                                <input type="number" step="0.01" name="bece_price" class="form-control" 
                                       value="<?php echo htmlspecialchars(number_format((float) $settings['bece_price'], 2, '.', '')); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">WASSCE Price (GHS)</label>
                                <input type="number" step="0.01" name="wassce_price" class="form-control" 
                                       value="<?php echo htmlspecialchars(number_format((float) $settings['wassce_price'], 2, '.', '')); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">BECE Checker Link</label>
                                <input type="url" name="bece_checker_link" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['bece_checker_link'] ?? ''); ?>" 
                                       placeholder="https://example.com/bece-check">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">WASSCE Checker Link</label>
                                <input type="url" name="wassce_checker_link" class="form-control" 
                                       value="<?php echo htmlspecialchars($settings['wassce_checker_link'] ?? ''); ?>" 
                                       placeholder="https://example.com/wassce-check">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="bece_enabled" 
                                           id="beceEnabled" <?php echo !empty($settings['bece_enabled']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="beceEnabled">Enable BECE Purchase</label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" name="wassce_enabled" 
                                           id="wassceEnabled" <?php echo !empty($settings['wassce_enabled']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="wassceEnabled">Enable WASSCE Purchase</label>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- BECE Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'bece' ? 'show active' : ''; ?>" id="bece" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">BECE Cards (Stock: <?php echo number_format($stats['BECE']['available']); ?>)</h5>
                    <div class="d-flex gap-2">
                        <form method="POST" action="result-checker.php" onsubmit="return confirm('Delete ALL BECE checker cards? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_all_cards">
                            <input type="hidden" name="type" value="BECE">
                            <input type="hidden" name="redirect_tab" value="bece">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash3"></i> Delete All
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkBeceModal">
                            <i class="bi bi-upload"></i> Bulk Upload
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBeceModal">
                            <i class="bi bi-plus-lg"></i> Add Card
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="beceTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>PIN</th>
                                    <th>Serial Number</th>
                                    <th>Status</th>
                                    <th>Purchased By</th>
                                    <th>Date Added</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bece_cards as $card): ?>
                                    <?php
                                        $status = $card['status'] ?? 'available';
                                        $badge_class = 'secondary';
                                        $badge_label = ucfirst($status);
                                        if ($status === 'available') {
                                            $badge_class = 'success';
                                            $badge_label = 'Available';
                                        } elseif ($status === 'purchased') {
                                            $badge_class = 'info';
                                            $badge_label = 'Purchased';
                                        } elseif ($status === 'disabled') {
                                            $badge_class = 'secondary';
                                            $badge_label = 'Disabled';
                                        }
                                        $created_at = !empty($card['created_at']) ? date('M j, Y', strtotime($card['created_at'])) : '-';
                                    ?>
                                    <tr>
                                        <td data-label="ID"><?php echo (int) $card['id']; ?></td>
                                        <td data-label="PIN"><?php echo htmlspecialchars($card['pin']); ?></td>
                                        <td data-label="Serial Number"><?php echo htmlspecialchars($card['serial_number']); ?></td>
                                        <td data-label="Status"><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($badge_label); ?></span></td>
                                        <td data-label="Purchased By">
                                            <?php if (!empty($card['purchased_email'])): ?>
                                                <?php echo htmlspecialchars($card['purchased_email']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date Added"><?php echo htmlspecialchars($created_at); ?></td>
                                        <td data-label="Actions">
                                            <form method="POST" action="result-checker.php" onsubmit="return confirm('Delete this checker card?');" class="d-inline">
                                                <input type="hidden" name="action" value="delete_card">
                                                <input type="hidden" name="card_id" value="<?php echo (int) $card['id']; ?>">
                                                <input type="hidden" name="redirect_tab" value="bece">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- WASSCE Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'wassce' ? 'show active' : ''; ?>" id="wassce" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">WASSCE Cards (Stock: <?php echo number_format($stats['WASSCE']['available']); ?>)</h5>
                    <div class="d-flex gap-2">
                        <form method="POST" action="result-checker.php" onsubmit="return confirm('Delete ALL WASSCE checker cards? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_all_cards">
                            <input type="hidden" name="type" value="WASSCE">
                            <input type="hidden" name="redirect_tab" value="wassce">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash3"></i> Delete All
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bulkWassceModal">
                            <i class="bi bi-upload"></i> Bulk Upload
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addWassceModal">
                            <i class="bi bi-plus-lg"></i> Add Card
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="wassceTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>PIN</th>
                                    <th>Serial Number</th>
                                    <th>Status</th>
                                    <th>Purchased By</th>
                                    <th>Date Added</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($wassce_cards as $card): ?>
                                    <?php
                                        $status = $card['status'] ?? 'available';
                                        $badge_class = 'secondary';
                                        $badge_label = ucfirst($status);
                                        if ($status === 'available') {
                                            $badge_class = 'success';
                                            $badge_label = 'Available';
                                        } elseif ($status === 'purchased') {
                                            $badge_class = 'info';
                                            $badge_label = 'Purchased';
                                        } elseif ($status === 'disabled') {
                                            $badge_class = 'secondary';
                                            $badge_label = 'Disabled';
                                        }
                                        $created_at = !empty($card['created_at']) ? date('M j, Y', strtotime($card['created_at'])) : '-';
                                    ?>
                                    <tr>
                                        <td data-label="ID"><?php echo (int) $card['id']; ?></td>
                                        <td data-label="PIN"><?php echo htmlspecialchars($card['pin']); ?></td>
                                        <td data-label="Serial Number"><?php echo htmlspecialchars($card['serial_number']); ?></td>
                                        <td data-label="Status"><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($badge_label); ?></span></td>
                                        <td data-label="Purchased By">
                                            <?php if (!empty($card['purchased_email'])): ?>
                                                <?php echo htmlspecialchars($card['purchased_email']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Date Added"><?php echo htmlspecialchars($created_at); ?></td>
                                        <td data-label="Actions">
                                            <form method="POST" action="result-checker.php" onsubmit="return confirm('Delete this checker card?');" class="d-inline">
                                                <input type="hidden" name="action" value="delete_card">
                                                <input type="hidden" name="card_id" value="<?php echo (int) $card['id']; ?>">
                                                <input type="hidden" name="redirect_tab" value="wassce">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchases Tab -->
        <div class="tab-pane fade <?php echo $active_tab === 'purchases' ? 'show active' : ''; ?>" id="purchases" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Recent Purchases</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="purchasesTable">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>PIN</th>
                                    <th>Serial</th>
                                    <th>Amount</th>
                                    <th>Gateway</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchases as $purchase): ?>
                                    <?php
                                        $user_display = $purchase['user_email'] ?: ($purchase['user_name'] ?? '');
                                        $purchase_date = !empty($purchase['created_at']) ? date('M j, Y H:i', strtotime($purchase['created_at'])) : '-';
                                        $amount_value = isset($purchase['amount']) ? (float) $purchase['amount'] : 0.0;
                                        $amount_display = function_exists('formatCurrency') ? formatCurrency($amount_value) : number_format($amount_value, 2);
                                        $gateway = $purchase['payment_gateway'] ? ucfirst($purchase['payment_gateway']) : '-';
                                    ?>
                                    <tr>
                                        <td data-label="Reference"><?php echo htmlspecialchars($purchase['reference']); ?></td>
                                        <td data-label="User"><?php echo $user_display !== '' ? htmlspecialchars($user_display) : '<span class="text-muted">-</span>'; ?></td>
                                        <td data-label="Type"><?php echo htmlspecialchars($purchase['card_type']); ?></td>
                                        <td data-label="PIN"><?php echo htmlspecialchars($purchase['pin'] ?? '-'); ?></td>
                                        <td data-label="Serial"><?php echo htmlspecialchars($purchase['serial_number'] ?? '-'); ?></td>
                                        <td data-label="Amount"><?php echo htmlspecialchars($amount_display); ?></td>
                                        <td data-label="Gateway"><?php echo htmlspecialchars($gateway); ?></td>
                                        <td data-label="Date"><?php echo htmlspecialchars($purchase_date); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
</div>

<!-- Add BECE Card Modal -->
<div class="modal fade" id="addBeceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="result-checker.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add BECE Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_card">
                    <input type="hidden" name="type" value="BECE">
                    <input type="hidden" name="redirect_tab" value="bece">
                    
                    <div class="mb-3">
                        <label class="form-label">PIN *</label>
                        <input type="text" name="pin" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number *</label>
                        <input type="text" name="serial_number" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Card</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add WASSCE Card Modal -->
<div class="modal fade" id="addWassceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="result-checker.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add WASSCE Card</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_card">
                    <input type="hidden" name="type" value="WASSCE">
                    <input type="hidden" name="redirect_tab" value="wassce">
                    
                    <div class="mb-3">
                        <label class="form-label">PIN *</label>
                        <input type="text" name="pin" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number *</label>
                        <input type="text" name="serial_number" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Card</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Upload BECE Modal -->
<div class="modal fade" id="bulkBeceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="result-checker.php" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Upload BECE Cards</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_upload">
                    <input type="hidden" name="type" value="BECE">
                    <input type="hidden" name="redirect_tab" value="bece">

                    <div class="mb-3">
                        <label class="form-label">Upload File (CSV/TXT) *</label>
                        <input type="file" name="bulk_file" class="form-control" accept=".csv,.txt" required>
                        <small class="text-muted d-block mt-2">
                            Format: SERIAL, PIN per line. Example: <code>ABCDEF1234, 123456789</code>
                        </small>
                        <a class="btn btn-link px-0 mt-2" href="result-checker.php?tab=bece&download_template=BECE">
                            <i class="bi bi-download"></i> Download BECE Template
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Upload WASSCE Modal -->
<div class="modal fade" id="bulkWassceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="result-checker.php" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Upload WASSCE Cards</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_upload">
                    <input type="hidden" name="type" value="WASSCE">
                    <input type="hidden" name="redirect_tab" value="wassce">

                    <div class="mb-3">
                        <label class="form-label">Upload File (CSV/TXT) *</label>
                        <input type="file" name="bulk_file" class="form-control" accept=".csv,.txt" required>
                        <small class="text-muted d-block mt-2">
                            Format: SERIAL, PIN per line. Example: <code>ABCDEF1234, 123456789</code>
                        </small>
                        <a class="btn btn-link px-0 mt-2" href="result-checker.php?tab=wassce&download_template=WASSCE">
                            <i class="bi bi-download"></i> Download WASSCE Template
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        if (window.matchMedia('(max-width: 767.98px)').matches) {
            return;
        }

        $('#beceTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            autoWidth: false,
            language: { emptyTable: 'No cards found' }
        });

        $('#wassceTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            autoWidth: false,
            language: { emptyTable: 'No cards found' }
        });

        $('#purchasesTable').DataTable({
            order: [[0, 'desc']],
            pageLength: 25,
            autoWidth: false,
            language: { emptyTable: 'No purchases found' }
        });
    });
</script>
<?php require_once '../includes/admin_footer.php'; ?>

