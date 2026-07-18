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
            --brand-primary: #541388;
            --brand-secondary: #D90368;
            --brand-deep: #541388;
            --brand-soft: #F1E9DA;
            --brand-ink: #2E294E;
            --admin-color: var(--brand-primary);
            --admin-dark: var(--brand-deep);
            --sidebar-bg: #2E294E;
            --sidebar-hover: #2E294E;
            --page-bg: #F1E9DA;
            --content-bg: #F1E9DA;
            --card-bg: #F1E9DA;
            --card-header-bg: #F1E9DA;
            --text-main: #2E294E;
            --text-muted: #541388;
            --border-color: #F1E9DA;
            --table-hover-bg: #F1E9DA;
            --tab-active-bg: #F1E9DA;
            --shadow-soft: 0 16px 40px rgba(46, 41, 78, 0.08);
            --ring: rgba(84, 19, 136, 0.22);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: radial-gradient(circle at 12% 10%, rgba(84, 19, 136, 0.1), transparent 38%), var(--page-bg);
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
            background: linear-gradient(180deg, #2E294E 0%, var(--sidebar-bg) 70%);
            z-index: 1040;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(241, 233, 218, 0.08);
            text-align: center;
            background: linear-gradient(180deg, rgba(84, 19, 136, 0.18) 0%, rgba(84, 19, 136, 0) 100%);
        }

        .sidebar-header img {
            max-height: 40px;
            margin-bottom: 0.5rem;
        }

        .sidebar-header h6 {
            color: #F1E9DA;
            font-size: 1.125rem;
            font-weight: 700;
            margin: 0;
        }

        .sidebar-header small {
            color: rgba(241, 233, 218, 0.6);
            font-size: 0.75rem;
        }

        .admin-badge {
            background: linear-gradient(135deg, var(--admin-color) 0%, var(--admin-dark) 100%);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.625rem;
            font-weight: 700;
            color: #F1E9DA;
            display: inline-block;
            margin-top: 0.25rem;
            letter-spacing: 1px;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .sidebar .nav-link {
            color: rgba(241, 233, 218, 0.8);
            padding: 0.75rem 1rem;
            margin: 0.125rem 0.5rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            font-size: 0.9375rem;
            transition: all 0.2s ease;
        }

        .sidebar .nav-link:hover {
            color: #F1E9DA;
            background: var(--sidebar-hover);
            transform: translateX(4px);
        }

        .sidebar .nav-link.active {
            color: #F1E9DA;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            box-shadow: 0 8px 18px rgba(84, 19, 136, 0.35);
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

        .content-area option,
        .dataTables_wrapper .dataTables_length select option {
            color: var(--text-main) !important;
            background-color: var(--card-bg) !important;
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
            color: #F1E9DA;
        }

        .content-area .btn-primary:hover,
        .content-area .btn-primary:focus {
            background-color: var(--brand-secondary);
            border-color: var(--brand-secondary);
            color: #F1E9DA;
        }

        .content-area .btn-danger {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #F1E9DA;
        }

        .content-area .btn-danger:hover,
        .content-area .btn-danger:focus {
            background-color: var(--brand-secondary);
            border-color: var(--brand-secondary);
            color: #F1E9DA;
        }

        .content-area .btn-secondary {
            background-color: #541388;
            border-color: #2E294E;
            color: #F1E9DA;
        }

        .content-area .btn-secondary:hover,
        .content-area .btn-secondary:focus {
            background-color: #2E294E;
            border-color: #2E294E;
            color: #F1E9DA;
        }

        .content-area .btn-outline-primary {
            color: var(--brand-primary);
            border-color: var(--brand-primary);
        }

        .content-area .btn-outline-primary:hover,
        .content-area .btn-outline-primary:focus {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #F1E9DA;
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
            color: #F1E9DA;
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-link {
            color: var(--text-main);
            border-color: var(--border-color);
            background-color: var(--content-bg);
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-link:hover {
            color: var(--text-main);
            background-color: #2E294E;
            border-color: var(--brand-secondary);
        }

        [data-bs-theme="dark"] .dataTables_wrapper .dataTables_paginate .page-item.active .page-link {
            background-color: var(--brand-primary);
            border-color: var(--brand-primary);
            color: #F1E9DA;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 0;
        }

        .top-header {
            background: linear-gradient(90deg, rgba(84, 19, 136, 0.1) 0%, rgba(241, 233, 218, 0.04) 100%), var(--card-header-bg);
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
            color: #F1E9DA;
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
            color: #F1E9DA;
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
            color: #F1E9DA;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-secondary) 100%);
            border-color: transparent;
            box-shadow: 0 10px 24px rgba(84, 19, 136, 0.25);
        }

        [data-bs-theme="dark"] .nav-tabs {
            background: #2E294E;
        }

        [data-bs-theme="dark"] .nav-tabs .nav-link:hover {
            background: #2E294E;
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
            --page-bg: #201b3c;
            --content-bg: #201b3c;
            --card-bg: #2d2654;
            --card-header-bg: #352c63;
            --text-main: #F1E9DA;
            --text-muted: rgba(241, 233, 218, 0.7);
            --border-color: rgba(241, 233, 218, 0.08);
            --table-hover-bg: rgba(241, 233, 218, 0.04);
            --tab-active-bg: #2d2654;
            --sidebar-bg: #151128;
            --sidebar-hover: #2d2654;
            --brand-ink: #F1E9DA;
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
                color: #F1E9DA;
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
                flex-wrap: nowrap;
                gap: 0.75rem;
            }

            .header-actions {
                flex-wrap: nowrap;
                justify-content: flex-end;
                width: auto;
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
            background: rgba(46, 41, 78, 0.5);
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
            border: 1px solid #F1E9DA;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(46, 41, 78, 0.1);
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
            background: #2E294E;
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

        /* Dark Mode High-Contrast Overrides */
        [data-bs-theme="dark"] .stat-title-bece i,
        [data-bs-theme="dark"] .stat-title-bece {
            color: var(--brand-secondary) !important;
        }

        [data-bs-theme="dark"] .stat-title-wassce i,
        [data-bs-theme="dark"] .stat-title-wassce {
            color: #38bdf8 !important;
        }

        [data-bs-theme="dark"] .nav-tabs {
            background: #1e1a3a !important; /* Visual container depth for tabs */
            border: 1px solid rgba(241, 233, 218, 0.06);
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
            background: rgba(241, 233, 218, 0.05);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(241, 233, 218, 0.2);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(241, 233, 218, 0.3);
        }

        /* Premium Mobile Layout Tweaks */
        @media (max-width: 767.98px) {
            .nav-tabs {
                display: flex !important;
                flex-wrap: nowrap !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
                padding: 0.35rem !important;
                gap: 0.35rem !important;
                border-radius: 0.75rem !important;
            }
            .nav-tabs::-webkit-scrollbar {
                display: none;
            }
            .nav-tabs {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            .nav-tabs .nav-item {
                flex: 0 0 auto !important;
            }
            .nav-tabs .nav-link {
                padding: 0.5rem 0.85rem !important;
                font-size: 0.85rem !important;
                white-space: nowrap !important;
            }
            
            /* Stats columns mobile stacking/wrapping logic to avoid squishing */
            .stat-card .row.text-center > div {
                padding: 0.25rem !important;
            }
            .stat-card h3 {
                font-size: 1.35rem !important;
            }
            .stat-card small {
                font-size: 0.725rem !important;
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
        
        <div class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-speedometer2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="afa-registration.php">
                        <i class="bi bi-person-check"></i>AFA Registration
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">
                        <i class="bi bi-people"></i>Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="packages.php">
                        <i class="bi bi-box-seam"></i>Bundles & APIs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transactions.php">
                        <i class="bi bi-cart-check"></i>Orders
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transactions.php">
                        <i class="bi bi-credit-card"></i>Transactions
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manual_topup.php">
                        <i class="bi bi-phone"></i>Manual Payments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="profit-withdrawals.php">
                        <i class="bi bi-cash-coin"></i>Profit Withdrawals
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="bi bi-gift"></i>Referral Codes
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="result-checker.php">
                        <i class="bi bi-award"></i>Result Checker
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="notifications.php">
                        <i class="bi bi-bell"></i>Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="api-providers.php">
                        <i class="bi bi-diagram-3"></i>API Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reports.php">
                        <i class="bi bi-flag"></i>Issue Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings.php">
                        <i class="bi bi-gear"></i>Settings
                    </a>
                </li>
            </ul>
        </div>
        <div class="nav-item">
            <a href="email-broadcast.php" class="nav-link">
                <i class="fas fa-paper-plane"></i>
                Email Broadcasts
            </a>
        </div>
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
        <div class="content-area">
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
                                        <td><?php echo (int) $card['id']; ?></td>
                                        <td><?php echo htmlspecialchars($card['pin']); ?></td>
                                        <td><?php echo htmlspecialchars($card['serial_number']); ?></td>
                                        <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($badge_label); ?></span></td>
                                        <td>
                                            <?php if (!empty($card['purchased_email'])): ?>
                                                <?php echo htmlspecialchars($card['purchased_email']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($created_at); ?></td>
                                        <td><span class="text-muted">-</span></td>
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
                                        <td><?php echo (int) $card['id']; ?></td>
                                        <td><?php echo htmlspecialchars($card['pin']); ?></td>
                                        <td><?php echo htmlspecialchars($card['serial_number']); ?></td>
                                        <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($badge_label); ?></span></td>
                                        <td>
                                            <?php if (!empty($card['purchased_email'])): ?>
                                                <?php echo htmlspecialchars($card['purchased_email']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($created_at); ?></td>
                                        <td><span class="text-muted">-</span></td>
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
                                        <td><?php echo htmlspecialchars($purchase['reference']); ?></td>
                                        <td><?php echo $user_display !== '' ? htmlspecialchars($user_display) : '<span class="text-muted">-</span>'; ?></td>
                                        <td><?php echo htmlspecialchars($purchase['card_type']); ?></td>
                                        <td><?php echo htmlspecialchars($purchase['pin'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($purchase['serial_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($amount_display); ?></td>
                                        <td><?php echo htmlspecialchars($gateway); ?></td>
                                        <td><?php echo htmlspecialchars($purchase_date); ?></td>
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

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTables
            $('#beceTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { emptyTable: 'No cards found' }
            });

            $('#wassceTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { emptyTable: 'No cards found' }
            });

            $('#purchasesTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 25,
                language: { emptyTable: 'No purchases found' }
            });

            // Adjust column sizing when tabs are changed
            $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
                $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            });
        });
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            if (mobileMenuBtn && sidebar && sidebarOverlay) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');
                });
                
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                });
                
                // Close sidebar when clicking on links on mobile
                const sidebarLinks = sidebar.querySelectorAll('.nav-link');
                sidebarLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth < 992) {
                            setTimeout(() => {
                                sidebar.classList.remove('show');
                                sidebarOverlay.classList.remove('show');
                            }, 200);
                        }
                    });
                });
            }
            
            // Theme toggle
            const themeToggle = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            
            // Get saved theme or default to light
            const currentTheme = localStorage.getItem('theme') || 'light';
            htmlElement.setAttribute('data-bs-theme', currentTheme);
            updateThemeIcon(currentTheme);
            
            themeToggle.addEventListener('click', function() {
                const theme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = theme === 'light' ? 'dark' : 'light';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
                // Theme stored locally only.
            });
            
            function updateThemeIcon(theme) {
                const icon = themeToggle.querySelector('i');
                if (theme === 'dark') {
                    icon.className = 'bi bi-sun-fill';
                } else {
                    icon.className = 'bi bi-moon-stars';
                }
            }
        });
    </script>
</body>
</html>


