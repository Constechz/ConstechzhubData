<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureProductOrderTables();

$store_slug = $_GET['store'] ?? '';
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

$products = getDashboardProducts(true, null);
$store_root_url = rtrim((string) SITE_URL, '/') . '/store/';
$store_home_url = $store_root_url . 'index.php?store=' . urlencode($store_slug);
$product_reference_url = $store_root_url . 'product-reference.php?store=' . urlencode($store_slug);
?>
<?php
$page_title = 'Products';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* Local overrides & additions */
    .store-products-shell {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .store-products-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 2rem;
    }
    .store-products-title h1 {
        margin: 0;
        font-size: 2.2rem;
        color: var(--store-ink);
    }
    .store-products-title p {
        margin: 0.5rem 0 0;
        color: var(--store-muted);
        max-width: 720px;
    }
    .store-products-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .store-products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
    }
    .store-product-card {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        overflow: hidden;
        box-shadow: var(--store-shadow-soft);
        display: flex;
        flex-direction: column;
        transition: all 0.3s ease;
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .store-product-card:hover {
        transform: translateY(-6px);
        box-shadow: var(--store-shadow);
        border-color: var(--store-accent);
    }
    .store-product-media {
        background: rgba(148, 163, 184, 0.05);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 240px;
        border-bottom: 1px solid var(--store-border);
    }
    .store-product-media img,
    .store-product-placeholder {
        width: 100%;
        max-width: 200px;
        aspect-ratio: 1 / 1;
        object-fit: cover;
        border-radius: 18px;
    }
    .store-product-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--store-chip-bg);
        color: var(--store-muted);
        font-size: 2rem;
    }
    .store-product-body {
        padding: 1.5rem;
        display: grid;
        gap: 0.75rem;
        flex: 1;
    }
    .store-product-body h3 {
        margin: 0;
        font-size: 1.25rem;
        color: var(--store-ink);
    }
    .store-product-desc {
        color: var(--store-muted);
        line-height: 1.55;
        font-size: 0.92rem;
        min-height: 4.3em;
    }
    .store-product-meta {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        color: var(--store-muted);
        font-size: 0.88rem;
    }
    .store-product-rating {
        color: #f59e0b;
        font-size: 0.9rem;
        letter-spacing: 0.08em;
    }
    .store-product-price {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--store-ink);
        font-family: 'Outfit', sans-serif;
    }
    .store-product-old {
        font-size: 0.92rem;
        color: var(--store-muted);
        text-decoration: line-through;
    }
    .empty-products {
        background: var(--store-card);
        border: 1px dashed var(--store-border);
        border-radius: 24px;
        padding: 4rem 1.5rem;
        text-align: center;
        color: var(--store-muted);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        max-width: 600px;
        margin: 2rem auto;
    }
</style>
    <div class="store-products-shell">
        <div class="store-products-header">
            <div class="store-products-title">
                <h1>Products</h1>
                <p>Shop physical products from <?php echo htmlspecialchars($store['store_name']); ?> and continue to secure checkout for delivery details and payment.</p>
            </div>
            <div class="store-products-actions">
                <a href="<?php echo htmlspecialchars($store_home_url); ?>" class="btn btn-outline"><i class="fas fa-store"></i> Back to Store</a>
                <a href="<?php echo htmlspecialchars($product_reference_url); ?>" class="btn btn-primary"><i class="fas fa-search"></i> Track Order</a>
            </div>
        </div>

        <?php if (empty($products)): ?>
            <div class="empty-products">
                <div style="font-size: 2.2rem; margin-bottom: 0.75rem;"><i class="fas fa-box-open"></i></div>
                <div style="font-weight: 700; margin-bottom: 0.35rem;">No products available right now</div>
                <div>Please check back later or contact the store directly.</div>
            </div>
        <?php else: ?>
            <div class="store-products-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $productName = trim((string) ($product['name'] ?? 'Product'));
                    $productDesc = trim((string) ($product['description'] ?? ''));
                    $sizeLabel = trim((string) ($product['size_label'] ?? ''));
                    $currentPrice = (float) ($product['current_price'] ?? 0);
                    $oldPrice = isset($product['old_price']) && $product['old_price'] !== null ? (float) $product['old_price'] : null;
                    $rating = max(0, min(5, (int) ($product['rating'] ?? 5)));
                    $imagePath = trim((string) ($product['image_path'] ?? ''));
                    $imageUrl = $imagePath !== '' ? dbh_asset($imagePath) : '';
                    $checkoutUrl = $store_root_url . 'product-checkout.php?store=' . urlencode($store_slug) . '&product_id=' . (int) ($product['id'] ?? 0);
                    ?>
                    <article class="store-product-card">
                        <div class="store-product-media">
                            <?php if ($imageUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($productName); ?>">
                            <?php else: ?>
                                <div class="store-product-placeholder"><i class="fas fa-box"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="store-product-body">
                            <h3><?php echo htmlspecialchars($productName); ?></h3>
                            <div class="store-product-desc"><?php echo htmlspecialchars($productDesc !== '' ? $productDesc : 'Secure checkout available with delivery address capture and Paystack payment.'); ?></div>
                            <div class="store-product-meta">
                                <span><?php echo htmlspecialchars($sizeLabel !== '' ? 'Size: ' . $sizeLabel : 'Ready to order'); ?></span>
                                <span class="store-product-rating"><?php echo str_repeat('★', $rating) . str_repeat('☆', max(0, 5 - $rating)); ?></span>
                            </div>
                            <div>
                                <div class="store-product-price"><?php echo htmlspecialchars(formatCurrency($currentPrice)); ?></div>
                                <?php if ($oldPrice !== null && $oldPrice > $currentPrice): ?>
                                    <div class="store-product-old"><?php echo htmlspecialchars(formatCurrency($oldPrice)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="store-product-actions">
                                <a href="<?php echo htmlspecialchars($checkoutUrl); ?>" class="btn btn-primary"><i class="fas fa-shopping-cart"></i> Buy Now</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
