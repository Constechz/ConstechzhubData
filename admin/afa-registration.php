<?php
// Register a robust shutdown function to capture any fatal errors during rendering
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clear any previous output buffers if possible so the error is clean
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo "<div style='padding: 20px; background: #f8d7da; color: #721c24; border: 3px solid #dc3545; margin: 20px; font-family: monospace; font-size: 16px; z-index: 99999; position: relative; border-radius: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.15);'>";
        echo "<h3 style='margin-top: 0; color: #bd2130; font-size: 20px; border-bottom: 2px solid #f5c6cb; padding-bottom: 10px;'>PHP Fatal Error Detected</h3>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . "</p>";
        echo "<p><strong>Line:</strong> " . htmlspecialchars($error['line']) . "</p>";
        echo "</div>";
    }
});

// Register an exception handler for uncaught exceptions
set_exception_handler(function($exception) {
    // Clear any previous output buffers if possible
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo "<div style='padding: 20px; background: #fff3cd; color: #856404; border: 3px solid #ffeeba; margin: 20px; font-family: monospace; font-size: 16px; z-index: 99999; position: relative; border-radius: 5px; box-shadow: 0 4px 15px rgba(0,0,0,0.15);'>";
    echo "<h3 style='margin-top: 0; color: #856404; font-size: 20px; border-bottom: 2px solid #ffeeba; padding-bottom: 10px;'>Uncaught Exception</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
    echo "<p><strong>Line:</strong> " . htmlspecialchars($exception->getLine()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p><pre style='background: #fff; padding: 10px; border: 1px solid #ffeeba; border-radius: 4px; overflow-x: auto;'>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
    echo "</div>";
});

// Standard error handler for non-fatal warnings/notices
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    echo "<div style='padding: 10px; background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; margin: 10px; font-family: monospace; font-size: 14px; border-radius: 4px;'>";
    echo "<strong>Warning/Notice ($errno):</strong> $errstr in <em>$errfile</em> on line <em>$errline</em>";
    echo "</div>";
    return false;
});

require_once '../config/config.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

preventBrowserCaching();
requireRole('admin');
ensureAfaRegistrationTables();

$current_admin = getCurrentUser();
$pageTitle = 'AFA Registration Settings';
$flash = getFlashMessage();
$csrf_token = generateCSRF();
$settings_table_ready = function_exists('dbh_table_exists') && dbh_table_exists('afa_registration_settings');
$registrations_table_ready = function_exists('dbh_table_exists') && dbh_table_exists('afa_registrations');

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

if ($settings_table_ready) {
    try {
        $rs = $db->query("SELECT * FROM afa_registration_settings ORDER BY id DESC LIMIT 1");
        if ($rs && ($row = $rs->fetch_assoc())) {
            $settings = array_merge($settings, $row);
            $settings_id = (int) ($row['id'] ?? 0);
        }
    } catch (Exception $e) {
        error_log('AFA settings load failed: ' . $e->getMessage());
        $settings_table_ready = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    if (!$settings_table_ready) {
        setFlashMessage('danger', 'AFA settings table is not available. Please run the database migration.');
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
            setFlashMessage($ok ? 'success' : 'danger', $ok ? 'AFA registration settings updated.' : 'Failed to update settings.');
        }
    } else {
        $stmt = $db->prepare("INSERT INTO afa_registration_settings (agent_price, guest_price, is_enabled, allow_wallet_agent, allow_gateway_agent, allow_wallet_customer, allow_gateway_customer, allow_guest_paystack, allow_guest_moolre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('ddiiiiiii', $agent_price, $guest_price, $is_enabled, $allow_wallet_agent, $allow_gateway_agent, $allow_wallet_customer, $allow_gateway_customer, $allow_guest_paystack, $allow_guest_moolre);
            $ok = $stmt->execute();
            $stmt->close();
            setFlashMessage($ok ? 'success' : 'danger', $ok ? 'AFA registration settings updated.' : 'Failed to update settings.');
        }
    }

    updateSetting('afa_registration_fee', $guest_price);
    header('Location: afa-registration.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    if (!$registrations_table_ready) {
        setFlashMessage('danger', 'AFA registrations table is not available. Please run the database migration.');
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
        setFlashMessage('danger', 'Invalid registration status selected.');
        header('Location: afa-registration.php');
        exit();
    }

    $stmt = $db->prepare("SELECT id, reference, status FROM afa_registrations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        setFlashMessage('danger', 'Could not load registration record.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('i', $registration_id);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$registration) {
        setFlashMessage('danger', 'Registration not found.');
        header('Location: afa-registration.php');
        exit();
    }

    $reviewed_by = (int) ($current_admin['id'] ?? 0);
    $stmt = $db->prepare("UPDATE afa_registrations SET status = ?, processing_at = IF(? = 'processing' AND processing_at IS NULL, NOW(), processing_at), admin_notes = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    if (!$stmt) {
        setFlashMessage('danger', 'Failed to update registration status.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('ssssii', $new_status, $new_status, $admin_notes, $admin_notes, $reviewed_by, $registration_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        setFlashMessage('danger', 'Failed to update registration status.');
        header('Location: afa-registration.php');
        exit();
    }

    $old_status = strtolower(trim((string) ($registration['status'] ?? 'pending')));
    $reference = trim((string) ($registration['reference'] ?? ''));
    if ($reference !== '' && in_array($new_status, ['success', 'failed', 'refunded'], true) && $new_status !== $old_status && function_exists('notifyAfaRegistrationStatusChange')) {
        notifyAfaRegistrationStatusChange($reference, $new_status, $admin_notes, true);
    }

    setFlashMessage('success', 'AFA registration status updated successfully.');
    header('Location: afa-registration.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_registration') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid session token. Please refresh and try again.');
        header('Location: afa-registration.php');
        exit();
    }

    if (!$registrations_table_ready) {
        setFlashMessage('danger', 'AFA registrations table is not available. Please run the database migration.');
        header('Location: afa-registration.php');
        exit();
    }

    $registration_id = (int) ($_POST['registration_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM afa_registrations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        setFlashMessage('danger', 'Failed to delete registration.');
        header('Location: afa-registration.php');
        exit();
    }
    $stmt->bind_param('i', $registration_id);
    $ok = $stmt->execute();
    $deleted = (int) $stmt->affected_rows;
    $stmt->close();

    setFlashMessage(($ok && $deleted > 0) ? 'success' : 'danger', ($ok && $deleted > 0) ? 'AFA registration deleted successfully.' : 'Registration not found or could not be deleted.');
    header('Location: afa-registration.php');
    exit();
}

$stats = [
    'total' => 0,
    'success' => 0,
    'pending' => 0,
    'processing' => 0,
    'today' => 0,
    'revenue' => 0.00,
];
if ($registrations_table_ready) {
    try {
        $stats_rs = $db->query("SELECT COUNT(*) AS total, SUM(status IN ('success','completed','delivered')) AS success, SUM(status='pending') AS pending, SUM(status='processing') AS processing, SUM(DATE(created_at)=CURDATE()) AS today, SUM(CASE WHEN status NOT IN ('failed','refunded') THEN amount ELSE 0 END) AS revenue FROM afa_registrations");
        if ($stats_rs && ($row = $stats_rs->fetch_assoc())) {
            $stats['total'] = (int) ($row['total'] ?? 0);
            $stats['success'] = (int) ($row['success'] ?? 0);
            $stats['pending'] = (int) ($row['pending'] ?? 0);
            $stats['processing'] = (int) ($row['processing'] ?? 0);
            $stats['today'] = (int) ($row['today'] ?? 0);
            $stats['revenue'] = (float) ($row['revenue'] ?? 0);
        }
    } catch (Exception $e) {
        error_log('AFA stats load failed: ' . $e->getMessage());
        $registrations_table_ready = false;
    }
}

$recent = [];
if ($registrations_table_ready) {
    try {
        $recent_rs = $db->query("
            SELECT
                ar.id,
                ar.reference,
                IFNULL(ar.beneficiary_name, '') AS beneficiary_name,
                IFNULL(ar.email, '') AS email,
                COALESCE(NULLIF(ar.phone, ''), ar.phone_number, '') AS phone,
                ar.ghana_card_number,
                ar.ghana_card_front_image,
                ar.ghana_card_back_image,
                ar.location,
                ar.occupation,
                ar.region,
                ar.date_of_birth,
                ar.amount,
                ar.payment_gateway,
                ar.status,
                COALESCE(NULLIF(ar.admin_notes, ''), ar.admin_note, '') AS admin_notes,
                ar.created_at,
                ar.updated_at,
                u.full_name AS buyer_name,
                u.username AS buyer_username
            FROM afa_registrations ar
            LEFT JOIN users u ON u.id = ar.user_id
            ORDER BY ar.id DESC
            LIMIT 50
        ");
        if ($recent_rs) {
            while ($row = $recent_rs->fetch_assoc()) {
                $recent[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log('AFA recent load failed: ' . $e->getMessage());
        $recent = [];
    }
}

require_once '../includes/admin_header.php';
?>

<style>
    /* Prevent wide content from stretching the dashboard layout wrapper */
    .main-content {
        min-width: 0;
    }

    .afa-shell {
        width: 100%;
        max-width: 100%;
    }

    .afa-card {
        background: var(--card-bg, #fff);
        border: 1px solid rgba(148, 163, 184, 0.25);
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.8rem;
    }

    .stat-box {
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-radius: 8px;
        background: rgba(248, 250, 252, 0.75);
        padding: 0.85rem;
    }

    .stat-box span {
        color: #64748b;
        font-size: 0.85rem;
    }

    .stat-box strong {
        display: block;
        font-size: 1.2rem;
        line-height: 1.35;
    }

    .afa-form-grid,
    .afa-check-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.9rem;
    }

    .afa-check-grid {
        gap: 0.45rem 1rem;
        margin-top: 1rem;
    }

    .afa-check-grid label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
        font-weight: 500;
    }

    .afa-table {
        min-width: 1280px;
    }

    .afa-table tr.details-row {
        background: rgba(248, 250, 252, 0.4);
    }

    [data-theme="dark"] .afa-table tr.details-row {
        background: rgba(15, 23, 42, 0.4);
    }

    .status-form {
        display: grid;
        grid-template-columns: minmax(120px, 0.7fr) minmax(180px, 1fr) auto;
        gap: 0.4rem;
        align-items: center;
    }

    .registration-actions {
        display: grid;
        gap: 0.45rem;
        min-width: 360px;
    }

    .afa-details {
        color: #111827;
        font-size: 0.92rem;
        white-space: normal;
        word-break: break-all;
        overflow-wrap: break-word;
    }

    .cell-content {
        display: inline-block;
        word-break: break-all;
        overflow-wrap: break-word;
    }

    .afa-details a {
        color: #1d4ed8;
        font-weight: 600;
    }

    .afa-whatsapp-preview {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        color: #111827;
        margin: 0.5rem 0 0;
        max-height: 220px;
        overflow: auto;
        padding: 0.65rem;
        white-space: pre-wrap;
    }

    [data-theme="dark"] .afa-card {
        background: #111827;
        border-color: #374151;
    }

    [data-theme="dark"] .stat-box,
    [data-theme="dark"] .afa-whatsapp-preview {
        background: #0f172a;
        border-color: #334155;
        color: #f8fafc;
    }

    [data-theme="dark"] .stat-box span {
        color: #cbd5e1;
    }

    [data-theme="dark"] .afa-details {
        color: #e5e7eb;
    }

    [data-theme="dark"] .afa-details a {
        color: #93c5fd;
    }

    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    @media (max-width: 992px) {
        .afa-card {
            padding: 0.85rem;
        }

        .afa-form-grid,
        .afa-check-grid {
            grid-template-columns: 1fr;
        }

        .status-form {
            grid-template-columns: 1fr;
        }

        .registration-actions {
            min-width: 0;
        }

        /* Responsive Table folding layout */
        .table-responsive {
            border: none;
            overflow-x: visible !important;
        }

        .afa-table {
            min-width: 0 !important;
            width: 100% !important;
            border: none !important;
        }

        .afa-table thead {
            display: none;
        }

        .afa-table tbody,
        .afa-table tr {
            display: block;
            width: 100%;
        }

        .afa-table tr.main-row {
            background: var(--card-bg, #fff);
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 8px 8px 0 0;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .afa-table tr.details-row {
            background: rgba(248, 250, 252, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-top: none;
            border-radius: 0 0 8px 8px;
            margin-bottom: 1rem;
            padding: 1rem;
            display: block;
        }

        [data-theme="dark"] .afa-table tr.main-row {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .afa-table tr.details-row {
            background: #0f172a;
            border-color: #334155;
        }

        .afa-table tr.main-row td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.15);
            font-size: 0.95rem;
            text-align: right;
        }

        .afa-table tr.main-row td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #64748b;
            margin-right: 1rem;
            flex-shrink: 0;
            text-align: left;
        }

        [data-theme="dark"] .afa-table tr.main-row td::before {
            color: #94a3b8;
        }

        .afa-table tr.main-row td .cell-content {
            text-align: right;
            display: block;
            width: auto;
        }

        .afa-table tr.main-row td[data-label="Action"] {
            display: block;
            width: 100%;
            border-bottom: none;
            padding-top: 0.75rem;
            text-align: left;
        }

        .afa-table tr.main-row td[data-label="Action"]::before {
            display: block;
            margin-bottom: 0.5rem;
        }

        .afa-table tr.details-row td {
            display: block;
            padding: 0 !important;
            width: 100% !important;
            border: none !important;
        }
    }
</style>

<div class="afa-shell">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="page-title mb-0">AFA Registration Settings</h2>
        <a class="btn btn-outline-primary" href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="afa-card">
        <div class="stats-grid">
            <div class="stat-box"><span>Total</span><strong><?php echo number_format($stats['total']); ?></strong></div>
            <div class="stat-box"><span>Success</span><strong><?php echo number_format($stats['success']); ?></strong></div>
            <div class="stat-box"><span>Pending</span><strong><?php echo number_format($stats['pending']); ?></strong></div>
            <div class="stat-box"><span>Ongoing</span><strong><?php echo number_format($stats['processing']); ?></strong></div>
            <div class="stat-box"><span>Today</span><strong><?php echo number_format($stats['today']); ?></strong></div>
            <div class="stat-box"><span>Revenue</span><strong><?php echo htmlspecialchars(formatCurrency($stats['revenue'], CURRENCY)); ?></strong></div>
        </div>
    </div>

    <div class="afa-card">
        <h5 class="mb-3">Pricing and Payment Controls</h5>
        <form method="post">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="afa-form-grid">
                <div class="form-group">
                    <label class="form-label" for="agent_price">Agent Price (<?php echo htmlspecialchars(CURRENCY); ?>)</label>
                    <input id="agent_price" name="agent_price" type="number" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $settings['agent_price'], 2, '.', '')); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="guest_price">Guest Price (<?php echo htmlspecialchars(CURRENCY); ?>)</label>
                    <input id="guest_price" name="guest_price" type="number" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars(number_format((float) $settings['guest_price'], 2, '.', '')); ?>" required>
                </div>
            </div>

            <div class="afa-check-grid">
                <label><input type="checkbox" name="is_enabled" <?php echo ((int) $settings['is_enabled'] === 1) ? 'checked' : ''; ?>> Enable AFA registration</label>
                <label><input type="checkbox" name="allow_wallet_agent" <?php echo ((int) $settings['allow_wallet_agent'] === 1) ? 'checked' : ''; ?>> Agent can pay with wallet</label>
                <label><input type="checkbox" name="allow_gateway_agent" <?php echo ((int) $settings['allow_gateway_agent'] === 1) ? 'checked' : ''; ?>> Agent can pay with gateway</label>
                <label><input type="checkbox" name="allow_wallet_customer" <?php echo ((int) $settings['allow_wallet_customer'] === 1) ? 'checked' : ''; ?>> Customer can pay with wallet</label>
                <label><input type="checkbox" name="allow_gateway_customer" <?php echo ((int) $settings['allow_gateway_customer'] === 1) ? 'checked' : ''; ?>> Customer can pay with gateway</label>
                <label><input type="checkbox" name="allow_guest_paystack" <?php echo ((int) $settings['allow_guest_paystack'] === 1) ? 'checked' : ''; ?>> Guest Paystack enabled</label>
                <label><input type="checkbox" name="allow_guest_moolre" <?php echo ((int) $settings['allow_guest_moolre'] === 1) ? 'checked' : ''; ?>> Guest Moolre enabled</label>
            </div>

            <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Save Settings</button>
        </form>
    </div>

    <div class="afa-card">
        <h5 class="mb-3">Recent AFA Registrations</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle afa-table">
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
                    <tr><td colspan="8" class="text-center py-4 text-muted">No AFA registrations yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent as $row): ?>
                        <?php
                            $wa_number = getAfaWhatsappEscalationNumber();
                            $wa_link_number = normalizeWhatsappNumberForLink($wa_number);
                            $wa_summary = buildAfaWhatsappSummary($row);
                            $wa_link = $wa_link_number !== '' ? ('https://wa.me/' . $wa_link_number . '?text=' . rawurlencode($wa_summary)) : '';
                            $row_status = strtolower(trim((string) ($row['status'] ?? 'pending')));
                            $status_label = $row_status === 'processing' ? 'Ongoing' : ucfirst($row_status);
                            if (in_array($row_status, ['completed', 'delivered'], true)) {
                                $status_label = 'Success';
                            }
                            $status_class = 'bg-secondary';
                            if ($row_status === 'pending') {
                                $status_class = 'bg-warning text-dark';
                            } elseif ($row_status === 'processing') {
                                $status_class = 'bg-info text-dark';
                            } elseif (in_array($row_status, ['success', 'completed', 'delivered'], true)) {
                                $status_class = 'bg-success';
                            } elseif ($row_status === 'failed') {
                                $status_class = 'bg-danger';
                            } elseif ($row_status === 'refunded') {
                                $status_class = 'bg-dark';
                            }
                        ?>
                        <tr class="main-row">
                            <td data-label="Reference"><span class="cell-content"><?php echo htmlspecialchars((string) $row['reference']); ?></span></td>
                            <td data-label="Buyer">
                                <div class="cell-content">
                                    <div class="fw-bold"><?php echo htmlspecialchars((string) ($row['buyer_name'] ?? '-')); ?></div>
                                    <?php if (!empty($row['buyer_username'])): ?>
                                        <small class="text-muted">@<?php echo htmlspecialchars((string) $row['buyer_username']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Beneficiary"><span class="cell-content"><?php echo htmlspecialchars((string) ($row['beneficiary_name'] ?? '-')); ?></span></td>
                            <td data-label="Amount"><span class="cell-content"><?php echo htmlspecialchars(formatCurrency((float) ($row['amount'] ?? 0), CURRENCY)); ?></span></td>
                            <td data-label="Gateway"><span class="cell-content"><?php echo htmlspecialchars(strtoupper((string) ($row['payment_gateway'] ?? '-'))); ?></span></td>
                            <td data-label="Status"><span class="cell-content"><span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status_label); ?></span></span></td>
                            <td data-label="Submitted"><span class="cell-content"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($row['created_at'] ?? 'now')))); ?></span></td>
                            <td data-label="Action">
                                <div class="registration-actions">
                                    <form method="post" class="status-form">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="registration_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                        <select name="status" class="form-select">
                                            <option value="pending" <?php echo ($row_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                            <option value="processing" <?php echo ($row_status === 'processing') ? 'selected' : ''; ?>>Ongoing</option>
                                            <option value="success" <?php echo in_array($row_status, ['success', 'completed', 'delivered'], true) ? 'selected' : ''; ?>>Success</option>
                                            <option value="failed" <?php echo ($row_status === 'failed') ? 'selected' : ''; ?>>Failed</option>
                                            <option value="refunded" <?php echo ($row_status === 'refunded') ? 'selected' : ''; ?>>Refunded</option>
                                        </select>
                                        <input type="text" name="admin_notes" class="form-control" maxlength="500" placeholder="Admin note" value="<?php echo htmlspecialchars((string) ($row['admin_notes'] ?? '')); ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Delete this AFA registration? This cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_registration">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="registration_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr class="details-row">
                            <td colspan="8" class="afa-details">
                                <strong>Email:</strong> <?php echo htmlspecialchars((string) ($row['email'] ?? 'N/A')); ?> |
                                <strong>Phone:</strong> <?php echo htmlspecialchars((string) ($row['phone'] ?? 'N/A')); ?> |
                                <strong>Card No:</strong> <?php echo htmlspecialchars((string) ($row['ghana_card_number'] ?? 'N/A')); ?> |
                                <strong>Occupation:</strong> <?php echo htmlspecialchars((string) ($row['occupation'] ?? 'N/A')); ?> |
                                <strong>DOB:</strong> <?php echo htmlspecialchars((string) ($row['date_of_birth'] ?? 'N/A')); ?> |
                                <strong>Region:</strong> <?php echo htmlspecialchars((string) ($row['region'] ?? 'N/A')); ?> |
                                <strong>Location:</strong> <?php echo htmlspecialchars((string) ($row['location'] ?? 'N/A')); ?>
                                <?php if ($wa_link !== ''): ?>
                                    | <a href="<?php echo htmlspecialchars($wa_link); ?>" target="_blank" rel="noopener">Send to WhatsApp (<?php echo htmlspecialchars($wa_number); ?>)</a>
                                <?php endif; ?>

                                <?php if (!empty($row['ghana_card_front_image']) || !empty($row['ghana_card_back_image'])): ?>
                                    <div class="mt-2 d-flex flex-wrap gap-3 card-images-container">
                                        <?php if (!empty($row['ghana_card_front_image'])): ?>
                                            <div>
                                                <strong>Ghana Card Front:</strong><br>
                                                <a href="../<?php echo htmlspecialchars($row['ghana_card_front_image']); ?>" target="_blank" rel="noopener">
                                                    <img src="../<?php echo htmlspecialchars($row['ghana_card_front_image']); ?>" alt="Ghana Card Front" style="max-height: 100px; border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 6px; padding: 0.15rem; background: #fff; margin-top: 0.25rem;" />
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['ghana_card_back_image'])): ?>
                                            <div>
                                                <strong>Ghana Card Back:</strong><br>
                                                <a href="../<?php echo htmlspecialchars($row['ghana_card_back_image']); ?>" target="_blank" rel="noopener">
                                                    <img src="../<?php echo htmlspecialchars($row['ghana_card_back_image']); ?>" alt="Ghana Card Back" style="max-height: 100px; border: 1px solid rgba(148, 163, 184, 0.3); border-radius: 6px; padding: 0.15rem; background: #fff; margin-top: 0.25rem;" />
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

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

<?php require_once '../includes/admin_footer.php'; ?>
