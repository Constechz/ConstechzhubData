<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureProductOrderTables();

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if ($store_slug === '') {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$store = getStoreBySlug($store_slug);
if (!$store) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$lookup = trim((string) ($_POST['lookup'] ?? $_GET['lookup'] ?? ''));
$results = [];
$lookup_performed = false;

if ($lookup !== '') {
    $lookup_performed = true;
    $reference = strtoupper($lookup);
    $phone_lookup = null;
    $digits = preg_replace('/\D+/', '', $lookup);
    if (strlen($digits) >= 9) {
        $phone_lookup = formatPhone($digits);
    }

    $sql = "
        SELECT po.*, dp.name AS product_name, dp.image_path AS product_image
        FROM product_orders po
        JOIN dashboard_products dp ON dp.id = po.product_id
        WHERE po.store_slug = ? AND (po.order_reference = ?
    ";
    if ($phone_lookup !== null) {
        $sql .= " OR po.customer_phone = ?";
    }
    $sql .= ")
        ORDER BY po.created_at DESC
        LIMIT 30
    ";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        if ($phone_lookup !== null) {
            $stmt->bind_param('sss', $store_slug, $reference, $phone_lookup);
        } else {
            $stmt->bind_param('ss', $store_slug, $reference);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $results[] = $row;
        }
        $stmt->close();
    }
}

$flash = getFlashMessage();

$payment_status_classes = [
    'pending' => 'background:#fef3c7;color:#92400e;',
    'paid' => 'background:#dcfce7;color:#166534;',
    'failed' => 'background:#fee2e2;color:#991b1b;',
];

$order_status_classes = [
    'pending_payment' => 'background:#e2e8f0;color:#334155;',
    'processing' => 'background:#dbeafe;color:#1d4ed8;',
    'shipped' => 'background:#ede9fe;color:#6d28d9;',
    'delivered' => 'background:#dcfce7;color:#166534;',
    'cancelled' => 'background:#fee2e2;color:#991b1b;',
    'payment_failed' => 'background:#fee2e2;color:#991b1b;',
];
?>
<?php
$page_title = 'Product Order Lookup';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .product-status-shell {
        max-width: 920px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .product-status-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 2rem;
    }
    .product-status-header h1 {
        color: var(--store-ink);
        margin: 0;
    }
    .product-status-card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        padding: 2rem;
        box-shadow: var(--store-shadow-soft);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .product-status-form {
        display: grid;
        gap: 1.25rem;
    }
    .product-status-results {
        margin-top: 2rem;
        display: grid;
        gap: 1.25rem;
    }
    .order-result {
        border: 1px solid var(--store-border);
        border-radius: 20px;
        padding: 1.5rem;
        background: var(--store-card);
        box-shadow: var(--store-shadow-soft);
    }
    .order-result-top {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--store-border);
    }
    .order-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .order-pill {
        padding: 0.3rem 0.85rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .order-result-grid {
        display: grid;
        gap: 0.5rem;
        color: var(--store-muted);
        font-size: 0.92rem;
    }
    .order-result-grid strong {
        color: var(--store-ink);
    }
    @media (max-width: 640px) {
        .product-status-card {
            padding: 1.25rem;
        }
    }
</style>
    <div class="product-status-shell">
        <div class="product-status-header">
            <div>
                <h1 style="margin:0; font-size:2rem;">Product Order Lookup</h1>
                <div style="margin-top:0.35rem; color:rgba(248,250,252,0.72);">Track orders from <?php echo htmlspecialchars($store['store_name']); ?> using phone number or order reference.</div>
            </div>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <a href="products.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline"><i class="fas fa-box-open"></i> Back to Products</a>
                <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-primary"><i class="fas fa-store"></i> Store Home</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="product-status-card">
            <form method="post" class="product-status-form">
                <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_slug); ?>">
                <label class="form-label">Phone number or order reference</label>
                <input type="text" name="lookup" class="form-control" value="<?php echo htmlspecialchars($lookup); ?>" placeholder="PROD123456 or 0241234567">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search Orders</button>
            </form>

            <?php if ($lookup_performed): ?>
                <div class="product-status-results">
                    <?php if (empty($results)): ?>
                        <div class="order-result">
                            <div style="font-weight:700; margin-bottom:0.35rem;">No matching product orders found</div>
                            <div style="color:rgba(248,250,252,0.72);">Check the order reference, or use the same phone number entered during checkout.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($results as $order): ?>
                            <?php
                            $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? 'pending')));
                            $orderStatus = strtolower(trim((string) ($order['order_status'] ?? 'pending_payment')));
                            ?>
                            <article class="order-result">
                                <div class="order-result-top">
                                    <div>
                                        <div style="font-size:1.05rem; font-weight:700;"><?php echo htmlspecialchars((string) ($order['product_name'] ?? 'Product')); ?></div>
                                        <div style="color:rgba(248,250,252,0.68); margin-top:0.25rem;">Reference: <?php echo htmlspecialchars((string) ($order['order_reference'] ?? '')); ?></div>
                                    </div>
                                    <div class="order-pills">
                                        <span class="order-pill" style="<?php echo $payment_status_classes[$paymentStatus] ?? 'background:#e2e8f0;color:#334155;'; ?>">
                                            Payment: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $paymentStatus))); ?>
                                        </span>
                                        <span class="order-pill" style="<?php echo $order_status_classes[$orderStatus] ?? 'background:#e2e8f0;color:#334155;'; ?>">
                                            Status: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $orderStatus))); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="order-result-grid">
                                    <div><strong>Total:</strong> <?php echo htmlspecialchars(formatCurrency((float) ($order['total_amount'] ?? 0))); ?></div>
                                    <div><strong>Customer:</strong> <?php echo htmlspecialchars((string) ($order['customer_name'] ?? '')); ?></div>
                                    <div><strong>Phone:</strong> <?php echo htmlspecialchars((string) ($order['customer_phone'] ?? '')); ?></div>
                                    <div><strong>Address:</strong> <?php echo htmlspecialchars(trim((string) ($order['delivery_address'] ?? ''))); ?></div>
                                    <div><strong>City / Region:</strong> <?php echo htmlspecialchars(trim((string) (($order['city'] ?? '') . (trim((string) ($order['region_name'] ?? '')) !== '' ? ', ' . $order['region_name'] : '')))); ?></div>
                                    <?php if (!empty($order['landmark'])): ?>
                                        <div><strong>Landmark:</strong> <?php echo htmlspecialchars((string) $order['landmark']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['notes'])): ?>
                                        <div><strong>Notes:</strong> <?php echo htmlspecialchars((string) $order['notes']); ?></div>
                                    <?php endif; ?>
                                    <div><strong>Updated:</strong> <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($order['updated_at'] ?? 'now')))); ?></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
