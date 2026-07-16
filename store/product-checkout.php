<?php
require_once __DIR__ . '/../config/config.php';

preventBrowserCaching();
ensureProductOrderTables();

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
$product_id = (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

if ($store_slug === '' || $product_id <= 0) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$store = getStoreBySlug($store_slug);
$product = getDashboardProductById($product_id, true);

if (!$store || !$product) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

$current_user = function_exists('getCurrentUser') ? getCurrentUser() : null;
$flash = getFlashMessage();
$store_root_url = rtrim((string) SITE_URL, '/') . '/store/';
$back_url = $store_root_url . 'products.php?store=' . urlencode($store_slug);
$reference_url = $store_root_url . 'product-reference.php?store=' . urlencode($store_slug);

$product_name = trim((string) ($product['name'] ?? 'Product'));
$product_desc = trim((string) ($product['description'] ?? ''));
$size_label = trim((string) ($product['size_label'] ?? ''));
$current_price = round((float) ($product['current_price'] ?? 0), 2);
$old_price = isset($product['old_price']) && $product['old_price'] !== null ? round((float) $product['old_price'], 2) : null;
$rating = max(0, min(5, (int) ($product['rating'] ?? 5)));
$image_url = trim((string) ($product['image_path'] ?? '')) !== '' ? dbh_asset((string) $product['image_path']) : '';

$prefill_name = trim((string) ($current_user['full_name'] ?? ''));
$prefill_email = trim((string) ($current_user['email'] ?? ''));
$prefill_phone = trim((string) ($current_user['phone'] ?? ''));
?>
<?php
$page_title = 'Product Checkout';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    /* Local scoped overrides for product checkout */
    .product-checkout-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 2rem 1.5rem 3rem;
    }
    .product-checkout-topbar {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 2rem;
    }
    .product-checkout-topbar h1 {
        margin: 0;
        font-size: 2.2rem;
        color: var(--store-ink);
    }
    .product-checkout-topbar p {
        margin: 0.45rem 0 0;
        color: var(--store-muted);
    }
    .product-checkout-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
        gap: 1.5rem;
        align-items: start;
    }
    .checkout-panel,
    .summary-panel {
        background: var(--store-card);
        border: 1px solid var(--store-border);
        border-radius: 24px;
        box-shadow: var(--store-shadow-soft);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
    .checkout-panel {
        padding: 2rem;
    }
    .summary-panel {
        overflow: hidden;
        position: sticky;
        top: 100px; /* modified to clear navbar fixed height */
    }
    .checkout-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.25rem;
    }
    .checkout-grid .full-span {
        grid-column: 1 / -1;
    }
    .checkout-grid label {
        display: grid;
        gap: 0.45rem;
        color: var(--store-ink);
        font-weight: 600;
        font-size: 0.9rem;
    }
    .checkout-note {
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
        border-radius: 14px;
        background: rgba(59, 130, 246, 0.08);
        color: var(--store-accent);
        border: 1px solid rgba(59, 130, 246, 0.15);
        font-size: 0.9rem;
        line-height: 1.5;
    }
    .checkout-error {
        display: none;
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
        border-radius: 14px;
        background: rgba(239, 68, 68, 0.08);
        color: var(--store-accent-cool);
        border: 1px solid rgba(239, 68, 68, 0.15);
        font-size: 0.9rem;
    }
    .summary-media {
        background: rgba(148, 163, 184, 0.05);
        padding: 1.5rem;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 250px;
        border-bottom: 1px solid var(--store-border);
    }
    .summary-media img,
    .summary-media-placeholder {
        width: 100%;
        max-width: 200px;
        aspect-ratio: 1 / 1;
        object-fit: cover;
        border-radius: 20px;
    }
    .summary-media-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--store-chip-bg);
        color: var(--store-muted);
        font-size: 2.2rem;
    }
    .summary-body {
        padding: 1.5rem;
        display: grid;
        gap: 0.85rem;
    }
    .summary-body h2 {
        margin: 0;
        font-size: 1.35rem;
        color: var(--store-ink);
    }
    .summary-desc {
        color: var(--store-muted);
        line-height: 1.6;
        font-size: 0.92rem;
    }
    .summary-rating {
        color: #f59e0b;
        letter-spacing: 0.08em;
        font-size: 0.9rem;
    }
    .summary-meta,
    .summary-total-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: center;
    }
    .summary-label {
        color: var(--store-muted);
        font-size: 0.9rem;
    }
    .summary-price {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--store-ink);
        font-family: 'Outfit', sans-serif;
    }
    .summary-old {
        color: var(--store-muted);
        text-decoration: line-through;
        font-size: 0.9rem;
    }
    .checkout-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }
    .checkout-actions .btn {
        min-height: 48px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }
    .spinner {
        width: 1rem;
        height: 1rem;
        border-radius: 999px;
        border: 2px solid rgba(255, 255, 255, 0.45);
        border-top-color: #fff;
        display: inline-block;
        animation: productSpin 0.8s linear infinite;
        margin-right: 0.5rem;
    }
    @keyframes productSpin {
        to { transform: rotate(360deg); }
    }
    @media (max-width: 920px) {
        .product-checkout-grid {
            grid-template-columns: 1fr;
        }
        .summary-panel {
            position: static;
        }
    }
    @media (max-width: 640px) {
        .product-checkout-topbar h1 {
            font-size: 1.7rem;
        }
        .checkout-panel,
        .summary-body {
            padding: 1.25rem;
        }
        .checkout-grid {
            grid-template-columns: 1fr;
        }
        .checkout-actions .btn {
            width: 100%;
        }
    }
</style>
    <div class="product-checkout-shell">
        <div class="product-checkout-topbar">
            <div>
                <h1>Product Checkout</h1>
                <p>Complete your delivery details for secure Paystack payment at <?php echo htmlspecialchars($store['store_name']); ?>.</p>
            </div>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Products</a>
                <a href="<?php echo htmlspecialchars($reference_url); ?>" class="btn btn-primary"><i class="fas fa-search"></i> Track Order</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>" style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="product-checkout-grid">
            <div class="checkout-panel">
                <div class="checkout-note">Your order is recorded against a unique reference immediately after payment starts, so you can track delivery later even if you leave the page.</div>
                <div id="productCheckoutError" class="checkout-error"></div>

                <form id="productCheckoutForm">
                    <input type="hidden" name="store_slug" value="<?php echo htmlspecialchars($store_slug); ?>">
                    <input type="hidden" name="product_id" value="<?php echo (int) $product_id; ?>">

                    <div class="checkout-grid">
                        <label>
                            Full Name
                            <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($prefill_name); ?>" placeholder="Enter recipient full name">
                        </label>
                        <label>
                            Email Address
                            <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($prefill_email); ?>" placeholder="you@example.com">
                        </label>
                        <label>
                            Phone Number
                            <input type="tel" name="phone" class="form-control" required value="<?php echo htmlspecialchars($prefill_phone); ?>" placeholder="0241234567">
                        </label>
                        <label>
                            City / Town
                            <input type="text" name="city" class="form-control" required placeholder="Accra">
                        </label>
                        <label class="full-span">
                            Delivery Address
                            <textarea name="delivery_address" class="form-control" rows="4" required placeholder="House number, street name, area, and any important delivery direction"></textarea>
                        </label>
                        <label>
                            Region
                            <input type="text" name="region_name" class="form-control" placeholder="Greater Accra">
                        </label>
                        <label>
                            Landmark
                            <input type="text" name="landmark" class="form-control" placeholder="Near the main junction">
                        </label>
                        <label class="full-span">
                            Order Notes
                            <textarea name="notes" class="form-control" rows="3" placeholder="Extra delivery or contact instructions"></textarea>
                        </label>
                    </div>

                    <div class="checkout-actions">
                        <button type="submit" id="productCheckoutBtn" class="btn btn-primary"><i class="fas fa-lock"></i> Pay with Paystack</button>
                        <a href="<?php echo htmlspecialchars($reference_url); ?>" class="btn btn-outline"><i class="fas fa-receipt"></i> Already Paid? Track Order</a>
                    </div>
                </form>
            </div>

            <aside class="summary-panel">
                <div class="summary-media">
                    <?php if ($image_url !== ''): ?>
                        <img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($product_name); ?>">
                    <?php else: ?>
                        <div class="summary-media-placeholder"><i class="fas fa-box"></i></div>
                    <?php endif; ?>
                </div>
                <div class="summary-body">
                    <h2><?php echo htmlspecialchars($product_name); ?></h2>
                    <div class="summary-desc"><?php echo htmlspecialchars($product_desc !== '' ? $product_desc : 'This product is available for secure online payment and delivery coordination after checkout.'); ?></div>
                    <div class="summary-rating"><?php echo str_repeat('★', $rating) . str_repeat('☆', max(0, 5 - $rating)); ?></div>
                    <div class="summary-meta">
                        <span class="summary-label"><?php echo htmlspecialchars($size_label !== '' ? 'Size: ' . $size_label : 'Standard listing'); ?></span>
                        <span class="summary-label">Quantity: 1</span>
                    </div>
                    <div class="summary-total-row">
                        <span class="summary-label">Total</span>
                        <span class="summary-price"><?php echo htmlspecialchars(formatCurrency($current_price)); ?></span>
                    </div>
                    <?php if ($old_price !== null && $old_price > $current_price): ?>
                        <div class="summary-old"><?php echo htmlspecialchars(formatCurrency($old_price)); ?></div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
    <script>
        (function() {
            const form = document.getElementById('productCheckoutForm');
            const button = document.getElementById('productCheckoutBtn');
            const errorBox = document.getElementById('productCheckoutError');
            if (!form || !button || !errorBox) {
                return;
            }

            const showError = function(message) {
                errorBox.textContent = message || 'Unable to start checkout right now.';
                errorBox.style.display = 'block';
            };

            const clearError = function() {
                errorBox.textContent = '';
                errorBox.style.display = 'none';
            };

            form.addEventListener('submit', async function(event) {
                event.preventDefault();
                clearError();

                const formData = new FormData(form);
                const payload = {
                    store_slug: String(formData.get('store_slug') || '').trim(),
                    product_id: String(formData.get('product_id') || '').trim(),
                    full_name: String(formData.get('full_name') || '').trim(),
                    email: String(formData.get('email') || '').trim(),
                    phone: String(formData.get('phone') || '').trim(),
                    delivery_address: String(formData.get('delivery_address') || '').trim(),
                    city: String(formData.get('city') || '').trim(),
                    region_name: String(formData.get('region_name') || '').trim(),
                    landmark: String(formData.get('landmark') || '').trim(),
                    notes: String(formData.get('notes') || '').trim()
                };

                if (!payload.store_slug || !payload.product_id || !payload.full_name || !payload.email || !payload.phone || !payload.delivery_address || !payload.city) {
                    showError('Please complete the required checkout fields.');
                    return;
                }

                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>Redirecting...';

                try {
                    const response = await fetch('<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/') . '/api/product_paystack_init.php'); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });

                    const raw = await response.text();
                    let data = null;
                    try {
                        data = raw ? JSON.parse(raw) : {};
                    } catch (parseError) {
                        throw new Error(raw && raw.trim() ? raw.trim() : 'Unexpected server response.');
                    }

                    if (!response.ok || !data || data.status !== 'success' || !data.data || !data.data.authorization_url) {
                        throw new Error(data && data.message ? data.message : 'Unable to start payment right now.');
                    }

                    window.location.href = String(data.data.authorization_url);
                } catch (error) {
                    showError(error && error.message ? error.message : 'Network error. Please try again.');
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-lock"></i> Pay with Paystack';
                }
            });
        })();
    </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
