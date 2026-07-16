<?php
require_once '../config/config.php';

preventBrowserCaching();
requireRole('admin');
ensureAfaRegistrationTables();

if (!function_exists('resolveAfaImageUrl')) {
    function resolveAfaImageUrl($path) {
        $path = trim((string) $path);
        if ($path === '') return '';
        if (strpos($path, 'http') === 0) return $path;
        return SITE_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('getAfaWhatsappEscalationNumber')) {
    function getAfaWhatsappEscalationNumber() {
        return getSetting('order_report_whatsapp_number', '0249020304');
    }
}

if (!function_exists('normalizeWhatsappNumberForLink')) {
    function normalizeWhatsappNumberForLink($number) {
        $digits = preg_replace('/\D+/', '', (string)$number);
        if ($digits === '') {
            return '233249020304';
        }
        if (strpos($digits, '233') === 0) {
            return $digits;
        }
        if (strpos($digits, '0') === 0) {
            return '233' . substr($digits, 1);
        }
        return '233' . ltrim($digits, '0');
    }
}

if (!function_exists('buildAfaWhatsappSummary')) {
    function buildAfaWhatsappSummary($row) {
        $ref = $row['reference'] ?? '';
        $name = $row['beneficiary_name'] ?? '';
        $phone = $row['phone'] ?? '';
        $card = $row['ghana_card_number'] ?? '';
        $loc = $row['location'] ?? '';
        $occ = $row['occupation'] ?? '';
        $dob = $row['date_of_birth'] ?? '';
        
        return "AFA REGISTRATION DETAILS\n"
             . "-------------------------\n"
             . "Reference: {$ref}\n"
             . "Name: {$name}\n"
             . "Phone: {$phone}\n"
             . "Ghana Card: {$card}\n"
             . "Location: {$loc}\n"
             . "Occupation: {$occ}\n"
             . "DOB: {$dob}";
    }
}


$current_user = getCurrentUser();
$flash = getFlashMessage();
$csrf_token = generateCSRF();

$defaults = [
    'agent_price' => 0.00,
    'guest_price' => 0.00,
    'is_enabled' => 0,
    'allow_wallet_agent' => 1,
    'allow_gateway_agent' => 1,
    'allow_wallet_customer' => 1,
    'allow_gateway_customer' => 1,
    'allow_guest_paystack' => 1,
    'allow_guest_moolre' => 1,
];
$settings = $defaults;
$settings_id = 0;
$rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
if ($rs && ($row = $rs->fetch_assoc())) {
    $settings = array_merge($settings, $row);
    $settings_id = (int) ($row['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    $agent_price = max(0, (float) ($_POST['agent_price'] ?? 0));
    $guest_price = max(0, (float) ($_POST['guest_price'] ?? 0));
    $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
    $allow_wallet_agent = isset($_POST['allow_wallet_agent']) ? 1 : 0;
    $allow_gateway_agent = isset($_POST['allow_gateway_agent']) ? 1 : 0;
    $allow_wallet_customer = isset($_POST['allow_wallet_customer']) ? 1 : 0;
    $allow_gateway_customer = isset($_POST['allow_gateway_customer']) ? 1 : 0;
    $allow_guest_paystack = isset($_POST['allow_guest_paystack']) ? 1 : 0;
    $allow_guest_moolre = isset($_POST['allow_guest_moolre']) ? 1 : 0;

    if ($settings_id > 0) {
        $stmt = $db->prepare("UPDATE afa_registration_settings SET agent_price = ?, guest_price = ?, is_enabled = ?, allow_wallet_agent = ?, allow_gateway_agent = ?, allow_wallet_customer = ?, allow_gateway_customer = ?, allow_guest_paystack = ?, allow_guest_moolre = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ddiiiiiiii', $agent_price, $guest_price, $is_enabled, $allow_wallet_agent, $allow_gateway_agent, $allow_wallet_customer, $allow_gateway_customer, $allow_guest_paystack, $allow_guest_moolre, $settings_id);
            $ok = $stmt->execute();
            $stmt->close();
            setFlashMessage($ok ? 'success' : 'error', $ok ? 'AFA registration settings updated.' : 'Failed to update settings.');
        } else {
            setFlashMessage('error', 'Failed to update settings.');
        }
    } else {
        $stmt = $db->prepare("INSERT INTO afa_registration_settings (agent_price, guest_price, is_enabled, allow_wallet_agent, allow_gateway_agent, allow_wallet_customer, allow_gateway_customer, allow_guest_paystack, allow_guest_moolre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ddiiiiiii', $agent_price, $guest_price, $is_enabled, $allow_wallet_agent, $allow_gateway_agent, $allow_wallet_customer, $allow_gateway_customer, $allow_guest_paystack, $allow_guest_moolre);
            $ok = $stmt->execute();
            $stmt->close();
            setFlashMessage($ok ? 'success' : 'error', $ok ? 'AFA registration settings updated.' : 'Failed to update settings.');
        } else {
            setFlashMessage('error', 'Failed to update settings.');
        }
    }

    header('Location: afa-registration.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    $registration_id = (int) ($_POST['registration_id'] ?? 0);
    $new_status = strtolower(trim((string) ($_POST['status'] ?? 'pending')));
    if ($new_status === 'ongoing') {
        $new_status = 'processing';
    }
    $admin_notes = trim((string) ($_POST['admin_notes'] ?? ''));

    if (!in_array($new_status, ['pending', 'processing', 'success', 'failed', 'refunded'], true)) {
        setFlashMessage('error', 'Invalid registration status selected.');
        header('Location: afa-registration.php');
        exit();
    }

    if ($registration_id <= 0) {
        setFlashMessage('error', 'Invalid registration selected.');
        header('Location: afa-registration.php');
        exit();
    }

    $stmt = $db->prepare("SELECT id, reference, status FROM afa_registrations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        setFlashMessage('error', 'Could not load registration record.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('i', $registration_id);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$registration) {
        setFlashMessage('error', 'Registration not found.');
        header('Location: afa-registration.php');
        exit();
    }

    $reviewed_by = (int) ($current_user['id'] ?? 0);
    $stmt = $db->prepare("UPDATE afa_registrations SET status = ?, processing_at = IF(? = 'processing' AND processing_at IS NULL, NOW(), processing_at), admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    if (!$stmt) {
        setFlashMessage('error', 'Failed to update registration status.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('sssii', $new_status, $new_status, $admin_notes, $reviewed_by, $registration_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        setFlashMessage('error', 'Failed to update registration status.');
        header('Location: afa-registration.php');
        exit();
    }

    $old_status = strtolower(trim((string) ($registration['status'] ?? 'pending')));
    $reference = trim((string) ($registration['reference'] ?? ''));
    if ($reference !== '' && in_array($new_status, ['success', 'failed', 'refunded'], true) && $new_status !== $old_status) {
        if (function_exists('notifyAfaRegistrationStatusChange')) {
            notifyAfaRegistrationStatusChange($reference, $new_status, $admin_notes, true);
        }
    }

    setFlashMessage('success', 'AFA registration status updated successfully.');
    header('Location: afa-registration.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_registration') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    $registration_id = (int) ($_POST['registration_id'] ?? 0);
    if ($registration_id <= 0) {
        setFlashMessage('error', 'Invalid registration selected.');
        header('Location: afa-registration.php');
        exit();
    }

    $stmt = $db->prepare("DELETE FROM afa_registrations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        setFlashMessage('error', 'Failed to delete registration.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('i', $registration_id);
    $ok = $stmt->execute();
    $deleted = (int) $stmt->affected_rows;
    $stmt->close();

    if (!$ok || $deleted <= 0) {
        setFlashMessage('error', 'Registration not found or could not be deleted.');
        header('Location: afa-registration.php');
        exit();
    }

    setFlashMessage('success', 'AFA registration deleted successfully.');
    header('Location: afa-registration.php');
    exit();
}

$stats = [
    'total' => 0,
    'success' => 0,
    'pending' => 0,
    'processing' => 0,
    'today' => 0,
];
$stats_rs = $db->query("SELECT COUNT(*) AS total, SUM(status='success') AS success, SUM(status='pending') AS pending, SUM(status='processing') AS processing, SUM(DATE(created_at)=CURDATE()) AS today FROM afa_registrations");
if ($stats_rs && ($row = $stats_rs->fetch_assoc())) {
    $stats['total'] = (int) ($row['total'] ?? 0);
    $stats['success'] = (int) ($row['success'] ?? 0);
    $stats['pending'] = (int) ($row['pending'] ?? 0);
    $stats['processing'] = (int) ($row['processing'] ?? 0);
    $stats['today'] = (int) ($row['today'] ?? 0);
}

$recent = [];
$recent_rs = $db->query("
    SELECT
        ar.id,
        ar.reference,
        ar.beneficiary_name,
        ar.email,
        ar.phone,
        ar.ghana_card_number,
        ar.ghana_card_front_image,
        ar.ghana_card_back_image,
        ar.location,
        ar.occupation,
        ar.region,
        ar.amount,
        ar.payment_gateway,
        ar.status,
        ar.admin_notes,
        ar.created_at,
        ar.updated_at,
        u.full_name AS buyer_name
    FROM afa_registrations ar
    LEFT JOIN users u ON u.id = ar.user_id
    ORDER BY ar.id DESC
    LIMIT 20
");
if ($recent_rs) {
    while ($row = $recent_rs->fetch_assoc()) {
        $recent[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFA Registration - <?php echo htmlspecialchars(getSiteName()); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>?v=1.3">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>?v=1.3">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>?v=1.3">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script>
    function updateThemeIcon(theme) {
        const icon = document.getElementById('theme-icon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { updateThemeIcon(theme); });
        } else {
            updateThemeIcon(theme);
        }
    }
    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
    }
    initTheme();
    </script>
    <style>
        .dashboard-header .header-left h2 {
            margin: 0;
            font-size: 1.35rem;
            line-height: 1.2;
        }

        .header-actions.action-buttons {
            gap: 0.5rem;
        }

        .header-actions.action-buttons .btn {
            white-space: nowrap;
        }

        #theme-toggle {
            min-width: 44px;
            padding: 0.55rem 0.7rem;
        }

        .afa-shell {
            max-width: 1120px;
            margin: 0 auto;
            min-width: 0;
        }

        .dashboard-wrapper {
            overflow-x: hidden;
        }

        .main-content {
            min-width: 0;
            width: calc(100% - 250px);
        }

        .afa-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.8rem;
        }

        .stat-box {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
            padding: 0.75rem;
        }

        .stat-box strong {
            display: block;
            font-size: 1.1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 0.9rem;
        }

        .check-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 0.45rem 1rem;
            margin-top: 1rem;
        }

        .check-grid label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            font-weight: 500;
        }

        .table-wrap {
            overflow-x: hidden;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
        }

        .afa-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .afa-table th,
        .afa-table td {
            padding: 0.55rem;
            border-bottom: 1px solid #ecf0f3;
            text-align: left;
            font-size: 0.92rem;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .status-form {
            display: flex;
            gap: 0.35rem;
            align-items: center;
            flex-wrap: wrap;
            max-width: 100%;
        }

        .status-form .status-select {
            min-width: 110px;
            flex: 1 1 110px;
        }

        .status-form .status-notes {
            min-width: 140px;
            flex: 1 1 140px;
        }

        .status-form .btn {
            white-space: nowrap;
        }

        .registration-actions {
            display: grid;
            gap: 0.4rem;
            max-width: 100%;
        }

        .delete-registration-form {
            margin: 0;
        }

        .delete-registration-form .btn {
            width: 100%;
        }

        .afa-details-cell {
            white-space: normal !important;
            word-break: break-word;
            color: #111827;
        }

        .afa-details-cell pre {
            white-space: pre-wrap;
            overflow: auto;
            max-height: 260px;
        }

        .afa-details-cell a {
            color: #1d4ed8;
            font-weight: 600;
        }

        .afa-whatsapp-preview {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            margin-top: 6px;
            padding: 8px;
            color: #111827;
        }

        [data-theme="dark"] .afa-card {
            background: #111827;
            border-color: #374151;
        }

        [data-theme="dark"] .stat-box {
            background: #1f2937;
            border-color: #374151;
            color: #e5e7eb;
        }

        [data-theme="dark"] .afa-table th,
        [data-theme="dark"] .afa-table td {
            border-bottom-color: #2f3746;
            color: #e5e7eb;
        }

        [data-theme="dark"] .afa-details-cell {
            color: #f8fafc;
        }

        [data-theme="dark"] .afa-details-cell a {
            color: #93c5fd;
        }

        [data-theme="dark"] .afa-whatsapp-preview {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                padding: 0.65rem 0.75rem;
            }

            .dashboard-content {
                padding: 0.6rem;
            }

            .afa-shell {
                max-width: 100%;
            }

            .afa-card {
                padding: 0.8rem;
                border-radius: 10px;
            }

            .stats-grid,
            .form-grid,
            .check-grid {
                grid-template-columns: 1fr;
                gap: 0.6rem;
            }

            .header-actions.action-buttons {
                flex-direction: row !important;
                align-items: center;
                justify-content: flex-end;
                flex-wrap: nowrap !important;
                margin-bottom: 0 !important;
                gap: 0.35rem !important;
            }

            .dashboard-header .header-left h2 {
                font-size: 1.02rem;
            }

            .header-actions.action-buttons .btn {
                width: auto !important;
                padding: 0.42rem 0.6rem;
                font-size: 0.84rem;
                border-radius: 8px;
            }

            #theme-toggle {
                min-width: 38px;
                padding: 0.42rem 0.5rem;
            }

            .table-wrap {
                overflow: visible;
            }

            .afa-table,
            .afa-table thead,
            .afa-table tbody,
            .afa-table tr,
            .afa-table td {
                display: block;
                width: 100%;
                table-layout: auto;
            }

            .main-content {
                width: 100%;
            }

            .afa-table thead {
                display: none;
            }

            .afa-table tr {
                margin-bottom: 0.65rem;
            }

            .afa-table .afa-summary-row {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 0.55rem;
                background: #ffffff;
            }

            .afa-table .afa-summary-row td {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 0.75rem;
                padding: 0.4rem 0;
                border-bottom: 1px dashed #ecf0f3;
                white-space: normal;
                font-size: 0.88rem;
            }

            .afa-table .afa-summary-row td:last-child {
                border-bottom: none;
            }

            .afa-table .afa-summary-row td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #6b7280;
                flex: 0 0 40%;
            }

            .afa-table .afa-details-row {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                padding: 0.55rem;
                background: #fafafa;
            }

            .afa-table .afa-details-row td {
                padding: 0;
                border-bottom: none;
            }

            .status-form {
                display: grid;
                grid-template-columns: 1fr;
                gap: 0.45rem;
                width: 100%;
            }

            .status-form .form-control,
            .status-form .btn {
                width: 100%;
                min-width: 0 !important;
            }

            [data-theme="dark"] .afa-table .afa-summary-row {
                background: #111827;
                border-color: #374151;
            }

            [data-theme="dark"] .afa-table .afa-summary-row td {
                border-bottom-color: #374151;
            }

            [data-theme="dark"] .afa-table .afa-summary-row td::before {
                color: #9ca3af;
            }

            [data-theme="dark"] .afa-table .afa-details-row {
                background: #1f2937;
                border-color: #374151;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header .header-left h2 {
                font-size: 0.9rem;
            }

            .header-actions.action-buttons {
                gap: 0.28rem !important;
            }

            .header-actions.action-buttons .btn {
                padding: 0.35rem 0.46rem;
                font-size: 0.76rem;
            }

            .header-dash-btn span {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand"><h3>Admin</h3></div>
                    <?php renderAdminSidebar(); ?>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
                <h2>AFA Registration Settings</h2>
            </div>
            <div class="header-actions action-buttons">
                <button type="button" class="btn btn-outline" id="theme-toggle" onclick="toggleTheme()"><i id="theme-icon" class="fas fa-moon"></i></button>
                <a class="btn btn-primary header-dash-btn" href="dashboard.php"><i class="fas fa-home"></i><span> Dashboard</span></a>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="afa-shell">
                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
                <?php endif; ?>

                <div class="afa-card">
                    <div class="stats-grid">
                        <div class="stat-box"><span>Total</span><strong><?php echo number_format($stats['total']); ?></strong></div>
                        <div class="stat-box"><span>Success</span><strong><?php echo number_format($stats['success']); ?></strong></div>
                        <div class="stat-box"><span>Pending</span><strong><?php echo number_format($stats['pending']); ?></strong></div>
                        <div class="stat-box"><span>Ongoing</span><strong><?php echo number_format($stats['processing']); ?></strong></div>
                        <div class="stat-box"><span>Today</span><strong><?php echo number_format($stats['today']); ?></strong></div>
                    </div>
                </div>

                <div class="afa-card">
                    <h3 style="margin-top:0;">Pricing and Payment Controls</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="save_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="agent_price">Agent Price (GHS)</label>
                                <input id="agent_price" name="agent_price" type="number" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $settings['agent_price'], 2, '.', '')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="guest_price">Guest Price (GHS)</label>
                                <input id="guest_price" name="guest_price" type="number" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $settings['guest_price'], 2, '.', '')); ?>" required>
                            </div>
                        </div>

                        <div class="check-grid">
                            <label><input type="checkbox" name="is_enabled" <?php echo ((int) $settings['is_enabled'] === 1) ? 'checked' : ''; ?>> Enable AFA registration</label>
                            <label><input type="checkbox" name="allow_wallet_agent" <?php echo ((int) $settings['allow_wallet_agent'] === 1) ? 'checked' : ''; ?>> Agent can pay with wallet</label>
                            <label><input type="checkbox" name="allow_gateway_agent" <?php echo ((int) $settings['allow_gateway_agent'] === 1) ? 'checked' : ''; ?>> Agent can pay with gateway</label>
                            <label><input type="checkbox" name="allow_wallet_customer" <?php echo ((int) $settings['allow_wallet_customer'] === 1) ? 'checked' : ''; ?>> Customer can pay with wallet</label>
                            <label><input type="checkbox" name="allow_gateway_customer" <?php echo ((int) $settings['allow_gateway_customer'] === 1) ? 'checked' : ''; ?>> Customer can pay with gateway</label>
                            <label><input type="checkbox" name="allow_guest_paystack" <?php echo ((int) $settings['allow_guest_paystack'] === 1) ? 'checked' : ''; ?>> Guest Paystack enabled</label>
                            <label><input type="checkbox" name="allow_guest_moolre" <?php echo ((int) $settings['allow_guest_moolre'] === 1) ? 'checked' : ''; ?>> Guest Moolre enabled</label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top:1rem;"><i class="fas fa-save"></i> Save Settings</button>
                    </form>
                </div>

                <div class="afa-card">
                    <h3 style="margin-top:0;">Recent AFA Registrations</h3>
                    <div class="table-wrap">
                        <table class="afa-table">
                            <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Buyer</th>
                                <th>Beneficiary</th>
                                <th>Amount</th>
                                <th>Gateway</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent)): ?>
                                <tr><td colspan="8">No AFA registrations yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent as $row): ?>
                                    <?php
                                        $front_url = resolveAfaImageUrl((string) ($row['ghana_card_front_image'] ?? ''));
                                        $back_url = resolveAfaImageUrl((string) ($row['ghana_card_back_image'] ?? ''));
                                        $wa_number = getAfaWhatsappEscalationNumber();
                                        $wa_link_number = normalizeWhatsappNumberForLink($wa_number);
                                        $wa_summary = buildAfaWhatsappSummary($row);
                                        $wa_link = $wa_link_number !== '' ? ('https://wa.me/' . $wa_link_number . '?text=' . rawurlencode($wa_summary)) : '';
                                        $row_status = strtolower(trim((string) ($row['status'] ?? 'pending')));
                                        $status_label = $row_status === 'processing' ? 'Ongoing' : ucfirst($row_status);
                                    ?>
                                    <tr class="afa-summary-row">
                                        <td data-label="Reference"><?php echo htmlspecialchars($row['reference']); ?></td>
                                        <td data-label="Buyer"><?php echo htmlspecialchars($row['buyer_name'] ?? '-'); ?></td>
                                        <td data-label="Beneficiary"><?php echo htmlspecialchars($row['beneficiary_name'] ?? '-'); ?></td>
                                        <td data-label="Amount"><?php echo htmlspecialchars(formatCurrency((float) ($row['amount'] ?? 0), CURRENCY)); ?></td>
                                        <td data-label="Gateway"><?php echo htmlspecialchars(strtoupper((string) ($row['payment_gateway'] ?? '-'))); ?></td>
                                        <td data-label="Status"><?php echo htmlspecialchars($status_label); ?></td>
                                        <td data-label="Submitted"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($row['created_at'] ?? 'now')))); ?></td>
                                        <td data-label="Action">
                                            <div class="registration-actions">
                                                <form method="post" class="status-form">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="registration_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                                    <select name="status" class="form-control status-select">
                                                        <option value="pending" <?php echo (($row['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="processing" <?php echo (($row['status'] ?? '') === 'processing') ? 'selected' : ''; ?>>Ongoing</option>
                                                        <option value="success" <?php echo (($row['status'] ?? '') === 'success') ? 'selected' : ''; ?>>Success</option>
                                                        <option value="failed" <?php echo (($row['status'] ?? '') === 'failed') ? 'selected' : ''; ?>>Failed</option>
                                                        <option value="refunded" <?php echo (($row['status'] ?? '') === 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                                                    </select>
                                                    <input type="text" name="admin_notes" class="form-control status-notes" maxlength="500" placeholder="Admin note (optional)" value="<?php echo htmlspecialchars((string) ($row['admin_notes'] ?? '')); ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                                </form>
                                                <form method="post" class="delete-registration-form" onsubmit="return confirm('Delete this AFA registration? This cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_registration">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="registration_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr class="afa-details-row">
                                        <td colspan="8" class="afa-details-cell">
                                            <strong>Phone:</strong> <?php echo htmlspecialchars((string) ($row['phone'] ?? 'N/A')); ?> |
                                            <strong>Card No:</strong> <?php echo htmlspecialchars((string) ($row['ghana_card_number'] ?? 'N/A')); ?> |
                                            <strong>Occupation:</strong> <?php echo htmlspecialchars((string) ($row['occupation'] ?? 'N/A')); ?> |
                                            <strong>DOB:</strong> <?php echo htmlspecialchars((string) ($row['date_of_birth'] ?? 'N/A')); ?> |
                                            <strong>Location:</strong> <?php echo htmlspecialchars((string) ($row['location'] ?? 'N/A')); ?>
                                            <?php if ($wa_link !== ''): ?>
                                                | <a href="<?php echo htmlspecialchars($wa_link); ?>" target="_blank" rel="noopener">Send to WhatsApp (<?php echo htmlspecialchars($wa_number); ?>)</a>
                                            <?php endif; ?>
                                            <br>
                                            <strong>WhatsApp Copy Text:</strong>
                                            <pre class="afa-whatsapp-preview"><?php echo htmlspecialchars($wa_summary); ?></pre>
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
function initializePage() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('show');
                sidebar.classList.toggle('active');
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage);
} else {
    initializePage();
}
</script>
<script src="../immediate_icon_fix.js"></script>
</body>
</html>
