<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/order_status.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

// Fetch store + agent info for branding
$stmt = $db->prepare("
    SELECT ast.*, u.full_name AS agent_name, u.email AS agent_email
    FROM agent_stores ast
    JOIN users u ON ast.agent_id = u.id
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
    LIMIT 1
");
$stmt->bind_param("s", $store_slug);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}
$store = $res->fetch_assoc();
$agent_id = (int) $store['agent_id'];

$lookup = trim($_POST['lookup'] ?? $_GET['lookup'] ?? '');
$results = [];
$lookup_performed = false;

if ($lookup !== '') {
    $lookup_performed = true;
    $digits = preg_replace('/\D+/', '', $lookup);
    $reference = strtoupper($lookup);

    $filters = [];
    $phone_lookup = null;

    if (strlen($digits) >= 9) {
        $filters[] = "bo.beneficiary_number = ?";
        $phone_lookup = formatPhone($digits);
    }

    $filters[] = "bo.order_reference = ?";

    $where = "bo.agent_id = ? AND (" . implode(" OR ", $filters) . ")";

    $query = "
        SELECT bo.order_reference, bo.beneficiary_number,
               COALESCE(t.amount, bo.amount) AS display_amount,
               bo.status, bo.created_at,
               dp.name AS package_name, dp.data_size, n.name AS network_name
        FROM bundle_orders bo
        JOIN data_packages dp ON dp.id = bo.package_id
        JOIN networks n ON n.id = dp.network_id
        LEFT JOIN transactions t ON t.id = bo.transaction_id
        WHERE {$where}
        ORDER BY bo.created_at DESC
        LIMIT 30
    ";
    $stmt = $db->prepare($query);
    if ($phone_lookup !== null) {
        $stmt->bind_param("iss", $agent_id, $phone_lookup, $reference);
    } else {
        $stmt->bind_param("is", $agent_id, $reference);
    }
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        $results[] = $row;
    }
}

function dbh_reference_phone_display($phone) {
    $local = normalizeGhanaLocalPhone($phone);
    return $local ?: $phone;
}
?>
<?php
$page_title = 'Order Status Lookup';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .status-shell {
        max-width: 900px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .status-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .status-header h1 {
        color: var(--store-ink);
        margin: 0;
    }
    .status-card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        padding: 2rem;
        box-shadow: var(--store-shadow-soft);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .status-form {
        display: grid;
        gap: 1.25rem;
    }
    .status-tip {
        margin-bottom: 1rem;
        padding: 1rem 1.25rem;
        border-radius: 14px;
        border: 1px solid rgba(14, 165, 233, 0.15);
        background: rgba(14, 165, 233, 0.08);
        color: var(--store-ink);
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .status-results {
        margin-top: 2rem;
        display: grid;
        gap: 1.25rem;
    }
    .result-card {
        padding: 1.5rem;
        border-radius: 20px;
        background: var(--store-card);
        border: 1px solid var(--store-border);
        box-shadow: var(--store-shadow-soft);
    }
    .result-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--store-border);
    }
    .status-pill {
        padding: 0.3rem 0.85rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .result-meta {
        display: grid;
        gap: 0.5rem;
        font-size: 0.92rem;
        color: var(--store-muted);
    }
    .result-meta strong {
        color: var(--store-ink);
    }
    @media (max-width: 640px) {
        .status-card {
            padding: 1.25rem;
        }
    }
</style>

    <div class="status-shell">
        <?php
        $flash = getFlashMessage();
        if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" style="margin-bottom: 2rem; border-radius: 12px; padding: 1rem 1.25rem; border: 1px solid rgba(148, 163, 184, 0.2); background: rgba(15, 23, 42, 0.85); color: #f8fafc;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : ($flash['type'] === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?>" style="color: <?php echo $flash['type'] === 'success' ? '#22c55e' : ($flash['type'] === 'error' ? '#ef4444' : '#3b82f6'); ?>; font-size: 1.25rem;"></i>
                    <div style="font-weight: 500;"><?php echo htmlspecialchars($flash['message']); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="status-card">
            <div class="status-header">
                <h1>Check Order Status</h1>
                <a class="service-selector-reset" href="index.php?store=<?php echo urlencode($store_slug); ?>">
                    <i class="fas fa-store"></i> Store Home
                </a>
            </div>

            <div class="status-tip">
                <i class="fas fa-info-circle"></i> Enter the phone number that received the data bundle or the order reference code (e.g. ORD123456) to look up your order status.
            </div>

            <form method="post" class="status-form">
                <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                <label class="form-label">Enter phone number or order reference</label>
                <input type="text" name="lookup" class="form-control" placeholder="0241234567 or ORD123456" value="<?php echo htmlspecialchars($lookup); ?>" required>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Check Status
                </button>
            </form>

            <?php if ($lookup_performed): ?>
                <div class="status-results">
                    <?php if (empty($results)): ?>
                        <div class="result-card">
                            <strong>No matching orders found.</strong>
                            <p style="margin: 0.5rem 0 0; color: rgba(248, 250, 252, 0.7);">Confirm the phone number or reference and try again.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($results as $order): ?>
                            <?php $status = getOrderStatusDisplay(strtolower($order['status'] ?? 'pending')); ?>
                            <div class="result-card">
                                <div class="result-header">
                                    <div>
                                        <strong><?php echo htmlspecialchars($order['package_name']); ?></strong>
                                        <div style="color: rgba(248, 250, 252, 0.7); font-size: 0.85rem;">
                                            <?php echo htmlspecialchars($order['network_name']); ?> &bull; <?php echo htmlspecialchars($order['data_size']); ?>
                                        </div>
                                    </div>
                                    <span class="status-pill" style="background: <?php echo htmlspecialchars($status['color']); ?>;">
                                        <i class="fas <?php echo htmlspecialchars($status['icon']); ?>"></i>
                                        <?php echo htmlspecialchars($status['label']); ?>
                                    </span>
                                </div>
                                <div class="result-meta">
                                    <div><strong>Reference:</strong> <?php echo htmlspecialchars($order['order_reference']); ?></div>
                                    <div><strong>Phone:</strong> <?php echo htmlspecialchars(dbh_reference_phone_display($order['beneficiary_number'])); ?></div>
                                    <div><strong>Amount:</strong> <?php echo formatCurrency((float) $order['display_amount']); ?></div>
                                    <div><strong>Date:</strong> <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($order['created_at']))); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
