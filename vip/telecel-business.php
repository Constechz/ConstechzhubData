<?php
require_once '../config/config.php';
require_once '../includes/api_providers.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

// Require agent role
requireRole('vip');

// Current user context
$current_user = getCurrentUser();
// Get agent ID
$agent_id = $current_user['id'];
// Wallet balance
$wallet_balance = getWalletBalance($current_user['id']);
ensureDataPackageStockStatusColumn();
$telecel_logo_png = dbh_asset('assets/images/telecel-logo.png');
$paystack_direct_enabled = isPaymentGatewayEnabled('paystack');
$agent_telecel_bundle_paystack_init_endpoint = '../api/agent_telecel_bundle_paystack_init.php';

// Fetch Telecel packages with vip pricing (allow multiple packages but prevent duplicates)
$telecel_packages = [];
$stmt = $db->prepare("
    SELECT dp.id, dp.name, dp.data_size, dp.validity_days, dp.package_type, dp.agent_commission, dp.description,
           COALESCE(dp.stock_status, 'in_stock') AS stock_status,
           COALESCE(pp_vip.price, pp_customer.price, dp.price) as effective_price
    FROM data_packages dp 
    LEFT JOIN networks n ON n.id = dp.network_id 
    LEFT JOIN package_pricing pp_vip ON pp_vip.package_id = dp.id AND pp_vip.user_type = 'vip'
    LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
    WHERE n.name = 'Telecel' AND dp.status = 'active'
      AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
    GROUP BY dp.id, dp.name, dp.data_size, dp.validity_days, dp.package_type, dp.agent_commission, dp.description,
             COALESCE(dp.stock_status, 'in_stock'), COALESCE(pp_vip.price, pp_customer.price, dp.price)
    ORDER BY COALESCE(pp_vip.price, pp_customer.price, dp.price) ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $telecel_packages[] = $row;
}

if (!function_exists('normalizeTelecelLocalPhone')) {
    function normalizeTelecelLocalPhone($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strpos($digits, '233') === 0) {
            return '0' . substr($digits, 3);
        }
        return $digits;
    }
}

if (!function_exists('isTelecelLocalPhone')) {
    function isTelecelLocalPhone($localPhone) {
        if (!preg_match('/^\d{10}$/', $localPhone)) {
            return false;
        }
        $prefix = substr($localPhone, 0, 3);
        return in_array($prefix, ['020', '050'], true);
    }
}

$error = '';
$success = '';

// Handle form submission (wallet purchase)
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'purchase') {
    $phone_number = sanitize($_POST['phone_number'] ?? '');
    $package_id = intval($_POST['package_id'] ?? 0);
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    
    // Debug: Log payment method received
    error_log('Telecel Purchase Debug - Payment method received: ' . $payment_method);
    error_log('Telecel Purchase Debug - POST data: ' . print_r($_POST, true));
    
    if (empty($phone_number)) {
        $error = 'Please enter beneficiary phone number';
    } elseif (!validatePhone($phone_number)) {
        $error = 'Please enter a valid phone number';
    } elseif (!isTelecelLocalPhone(normalizeTelecelLocalPhone($phone_number))) {
        $error = 'Use Telecel numbers only (020/050) and 10 digits.';
    } elseif (empty($package_id)) {
        $error = 'Please select a data package';
    } else {
        // Get package details with pricing
        $stmt = $db->prepare("
            SELECT dp.*, 
                   COALESCE(pp_vip.price, pp_customer.price, dp.price) as effective_price
            FROM data_packages dp
            LEFT JOIN networks n ON n.id = dp.network_id
            LEFT JOIN package_pricing pp_vip ON pp_vip.package_id = dp.id AND pp_vip.user_type = 'vip'
            LEFT JOIN package_pricing pp_customer ON pp_customer.package_id = dp.id AND pp_customer.user_type = 'customer'
            WHERE dp.id = ? AND n.name = 'Telecel' AND dp.status = 'active'
              AND COALESCE(dp.stock_status, 'in_stock') = 'in_stock'
        ");
        $stmt->bind_param("i", $package_id);
        $stmt->execute();
        $package_result = $stmt->get_result();
        
        if ($package = $package_result->fetch_assoc()) {
            if ($payment_method === 'wallet') {
                // Check wallet balance
                $effective_price = $package['effective_price'];
                if ($wallet_balance < $effective_price) {
                    $error = 'Insufficient wallet balance. Please top up your wallet.';
                } else {
                    $network_id = 4; // Telecel network ID
                    $endpoint_type = detectEndpointTypeForPackage(
                        $package['name'] ?? '',
                        $package['data_size'] ?? '',
                        $package['package_type'] ?? ''
                    );
                    $availability = checkNetworkProviderAvailability($network_id, $endpoint_type);
                    if (!$availability['available']) {
                        $error = $availability['message'];
                        $wallet_balance = getWalletBalance($current_user['id']);
                    } else {
                    // Process purchase
                    $order_reference = generateReference('TCL');
                    $formatted_phone = formatPhone($phone_number);
                    $duplicate_order = findRecentDuplicateBundleOrder(
                        (int) $current_user['id'],
                        (int) $package_id,
                        $formatted_phone,
                        (float) $effective_price,
                        180
                    );

                    if ($duplicate_order) {
                        $dup_ref = $duplicate_order['order_reference'] ?? ('#' . (int) ($duplicate_order['id'] ?? 0));
                        $success = 'Similar order already received recently (Ref: ' . $dup_ref . '). Please wait before retrying.';
                        $wallet_balance = getWalletBalance($current_user['id']);
                    } else {
                    $bundle_orders_auto_increment = true;
                    $transactions_auto_increment = true;
                    $commissions_auto_increment = true;
                    if (function_exists('dbh_ensure_auto_increment')) {
                        $bundle_orders_auto_increment = dbh_ensure_auto_increment('bundle_orders');
                        $transactions_auto_increment = dbh_ensure_auto_increment('transactions');
                        $commissions_auto_increment = dbh_ensure_auto_increment('commissions');
                    }
                    
                    $db->getConnection()->begin_transaction();
                    
                    try {
                        // Create bundle order
                        if ($bundle_orders_auto_increment) {
                            $stmt = $db->prepare("
                                INSERT INTO bundle_orders (user_id, package_id, beneficiary_number, amount, order_reference, status) 
                                VALUES (?, ?, ?, ?, ?, 'processing')
                            ");
                            $stmt->bind_param("iisds", $current_user['id'], $package_id, $formatted_phone, $effective_price, $order_reference);
                            $stmt->execute();
                            $order_id = $db->lastInsertId();
                        } else {
                            $manual_order_id = dbh_generate_next_id('bundle_orders');
                            $stmt = $db->prepare("
                                INSERT INTO bundle_orders (id, user_id, package_id, beneficiary_number, amount, order_reference, status) 
                                VALUES (?, ?, ?, ?, ?, ?, 'processing')
                            ");
                            $stmt->bind_param("iiisds", $manual_order_id, $current_user['id'], $package_id, $formatted_phone, $effective_price, $order_reference);
                            $stmt->execute();
                            $order_id = $manual_order_id;
                        }

                        $commission_amount = function_exists('calculateAgentDataCommissionAmount')
                            ? calculateAgentDataCommissionAmount($package['data_size'] ?? '', 1)
                            : 0.0;
                        
                        // Create transaction
                        $transaction_ref = generateReference('TXN');
                        $description = "Telecel " . $package['data_size'] . " bundle purchase for " . $formatted_phone;
                        if ($transactions_auto_increment) {
                            $stmt = $db->prepare("
                                INSERT INTO transactions (user_id, transaction_type, amount, status, reference, payment_method, description) 
                                VALUES (?, 'purchase', ?, 'success', ?, 'wallet', ?)
                            ");
                            $stmt->bind_param("idss", $current_user['id'], $effective_price, $transaction_ref, $description);
                            $stmt->execute();
                            $transaction_id = $db->lastInsertId();
                        } else {
                            $manual_transaction_id = dbh_generate_next_id('transactions');
                            $stmt = $db->prepare("
                                INSERT INTO transactions (id, user_id, transaction_type, amount, status, reference, payment_method, description) 
                                VALUES (?, ?, 'purchase', ?, 'success', ?, 'wallet', ?)
                            ");
                            $stmt->bind_param("iidss", $manual_transaction_id, $current_user['id'], $effective_price, $transaction_ref, $description);
                            $stmt->execute();
                            $transaction_id = $manual_transaction_id;
                        }
                        
                        // Update order with transaction ID and set to processing
                        $stmt = $db->prepare("UPDATE bundle_orders SET transaction_id = ?, status = 'processing' WHERE id = ?");
                        $stmt->bind_param("ii", $transaction_id, $order_id);
                        $stmt->execute();
                        
                        // Deduct from wallet
                        if (!updateWalletBalance($current_user['id'], $effective_price, 'debit', $transaction_ref, $description)) {
                            throw new Exception('Insufficient wallet balance');
                        }
                        
                        // Call API provider to deliver the bundle
                        require_once '../includes/api_providers.php';
                        
                        // Convert data size to GB for API call
                        // Convert data size to GB for API call
                        require_once '../includes/volume_converter.php';
                        $volume_gb = extractVolumeGB($package['data_size']);
                        $network_id = 4; // Telecel network ID
                        
                        // Determine endpoint type
                        $endpoint_type = detectEndpointTypeForPackage(
                            $package['name'] ?? '',
                            $package['data_size'] ?? '',
                            $package['package_type'] ?? ''
                        );
                        
                        $api_result = processBundlePurchase($order_id, $network_id, $formatted_phone, $volume_gb, $endpoint_type);
                        
                        if ($api_result['success']) {
                            // Update order status to processing
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'processing', api_response = ?, provider_reference = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $provider_ref = $api_result['reference'] ?? '';
                            $stmt->bind_param("ssi", $api_response_json, $provider_ref, $order_id);
                            $stmt->execute();
                        } else {
                            // Update order status to failed
                            $stmt = $db->prepare("UPDATE bundle_orders SET status = 'failed', api_response = ? WHERE id = ?");
                            $api_response_json = json_encode($api_result);
                            $stmt->bind_param("si", $api_response_json, $order_id);
                            $stmt->execute();
                            
                            // Refund wallet
                            updateWalletBalance($current_user['id'], $effective_price, 'credit', $transaction_ref . '_REFUND', 'Refund: ' . $api_result['error']);
                            throw new Exception('API delivery failed: ' . $api_result['error']);
                        }

                        if ($commission_amount > 0 && function_exists('recordAgentCommission')) {
                            recordAgentCommission([
                                'agent_id' => (int) $current_user['id'],
                                'source_type' => 'data',
                                'source_id' => (int) $order_id,
                                'source_reference' => (string) $order_reference,
                                'amount' => $commission_amount,
                                'quantity' => 1,
                                'rate_snapshot' => function_exists('getAgentCommissionSettings') ? (float) (getAgentCommissionSettings()['data_rate_per_gb'] ?? 0) : null,
                                'notes' => 'Telecel ' . ($package['data_size'] ?? 'bundle') . ' for ' . $formatted_phone,
                            ]);
                        }
                        
                        if ($commission_amount > 0) {
                            if ($commissions_auto_increment) {
                                $stmt = $db->prepare("
                                    INSERT INTO commissions (agent_id, order_id, amount, status) 
                                    VALUES (?, ?, ?, 'pending')
                                ");
                                $stmt->bind_param("iid", $current_user['id'], $order_id, $commission_amount);
                                $stmt->execute();
                            } else {
                                $manual_commission_id = dbh_generate_next_id('commissions');
                                $stmt = $db->prepare("
                                    INSERT INTO commissions (id, agent_id, order_id, amount, status) 
                                    VALUES (?, ?, ?, ?, 'pending')
                                ");
                                $stmt->bind_param("iiid", $manual_commission_id, $current_user['id'], $order_id, $commission_amount);
                                $stmt->execute();
                            }
                        }
                        
                        $db->getConnection()->commit();

                        sendAdminDataOrderNotification([
                            'order_reference' => $order_reference,
                            'order_id' => $order_id,
                            'user_id' => (int) $current_user['id'],
                            'customer_name' => $current_user['full_name'] ?? '',
                            'customer_email' => $current_user['email'] ?? '',
                            'beneficiary_number' => $formatted_phone,
                            'network_name' => 'Telecel',
                            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                            'amount' => $effective_price,
                            'payment_method' => 'wallet',
                            'status' => 'processing',
                            'agent_id' => (int) $current_user['id'],
                            'source' => 'agent_dashboard_telecel'
                        ]);

                        // Refresh wallet balances for notification
                        $buyer_previous_balance = getWalletBalance($current_user['id']) + $effective_price;
                        $buyer_current_balance = getWalletBalance($current_user['id']);

                        sendUserOrderNotification([
                            'order_type' => 'data',
                            'order_reference' => $order_reference,
                            'order_id' => $order_id,
                            'user_id' => (int) $current_user['id'],
                            'customer_name' => $current_user['full_name'] ?? '',
                            'customer_email' => $current_user['email'] ?? '',
                            'beneficiary_number' => $formatted_phone,
                            'network_name' => 'Telecel',
                            'package_name' => $package['data_size'] . ' - ' . ($package['validity_days'] ? $package['validity_days'] . ' days' : 'N/A'),
                            'amount' => $effective_price,
                            'payment_method' => 'wallet',
                            'status' => 'processing',
                            'previous_balance' => $buyer_previous_balance,
                            'current_balance' => $buyer_current_balance,
                            'source' => 'agent_dashboard_telecel'
                        ]);
                        
                        // Log activity
                        logActivity($current_user['id'], 'bundle_purchase', "Purchased Telecel {$package['data_size']} bundle for {$formatted_phone}");
                        
                        // Display phone in user-friendly local format
                        $display_phone = (strlen($formatted_phone) == 12 && substr($formatted_phone, 0, 3) == '233') 
                            ? '0' . substr($formatted_phone, 3) 
                            : $formatted_phone;
                        $success = 'Order submitted successfully and is now processing. It will update automatically once it confirms delivery.';

                        // Clear form fields after a successful order for a fresh entry
                        $_POST = [];
                        
                        // Refresh wallet balance for display
                        $wallet_balance = getWalletBalance($current_user['id']);
                        
                    } catch (Exception $e) {
                        $db->getConnection()->rollback();
                        $error = stripos($e->getMessage(), 'Network is busy') !== false
                            ? $e->getMessage()
                            : 'Purchase failed: ' . $e->getMessage();
                        error_log('Telecel Bundle Purchase Error: ' . $e->getMessage());
                    }
                    }
                    }
                }
            } else {
                // Ensure proper payment method validation - no Paystack references
                if (empty($payment_method)) {
                    $error = 'Payment method is required. Please select "Pay with Wallet".';
                } else {
                    $error = 'Invalid payment method selected. Only wallet payment is supported.';
                }
            }
        } else {
            $error = 'Invalid package selected';
        }
    }
}

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telecel Business - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <script>
        // Initialize theme immediately before body loads to prevent flicker
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <style>
        .telecel-business-page {
            background: #f3f4f6;
        }

        .telecel-business-page .dashboard-content {
            padding: 1.5rem;
        }

        .telecel-business-page .telecel-business-shell {
            max-width: 1140px;
            margin: 0 auto;
        }

        .telecel-business-page .telecel-business-header {
            margin-bottom: 1.5rem;
        }

        .telecel-business-page .telecel-business-header h1 {
            margin: 0 0 0.35rem;
            color: #1f2937;
            font-size: clamp(1.75rem, 3vw, 2.4rem);
        }

        .telecel-business-page .telecel-business-header p {
            margin: 0;
            color: #6b7280;
        }

        .telecel-business-page .telecel-package-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.4rem;
            margin-bottom: 1.75rem;
        }

        .telecel-business-page .telecel-package-card {
            background: #ffffff;
            border-radius: 24px;
            padding: 1.25rem 1.3rem 1.35rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(15, 23, 42, 0.04);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            text-align: center;
        }

        .telecel-business-page .telecel-package-card:hover,
        .telecel-business-page .telecel-package-card.is-selected {
            transform: translateY(-2px);
            box-shadow: 0 22px 46px rgba(15, 23, 42, 0.12);
            border-color: rgba(220, 38, 38, 0.28);
        }

        .telecel-business-page .telecel-package-card-top {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.9rem;
            margin-bottom: 1.15rem;
            text-align: center;
        }

        .telecel-business-page .telecel-package-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
            flex: 0 0 auto;
        }

        .telecel-business-page .telecel-package-copy {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 0;
            text-align: center;
            width: 100%;
        }

        .telecel-business-page .telecel-package-size {
            margin: 0;
            color: #1f2937;
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 800;
            line-height: 1;
        }

        .telecel-business-page .telecel-package-price {
            margin-top: 0.35rem;
            color: #b91c1c;
            font-size: 1.05rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .telecel-business-page .telecel-package-select {
            width: 100%;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 14px;
            background: #8B5CF6;
            color: #ffffff;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.18s ease;
            text-align: center;
        }

        .telecel-business-page .telecel-package-select:hover {
            background: #7C3AED;
        }

        .telecel-business-page .telecel-package-select:focus-visible {
            outline: 3px solid rgba(139, 92, 246, 0.28);
            outline-offset: 2px;
        }

        .telecel-business-page .telecel-purchase-panel {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }

        .telecel-business-page .telecel-purchase-panel .widget,
        .telecel-business-page .telecel-purchase-panel .widget-header,
        .telecel-business-page .telecel-purchase-panel .widget-body {
            background: transparent;
            border: none;
            box-shadow: none;
        }

        .telecel-business-page .telecel-purchase-panel .widget-header {
            padding: 1.2rem 1.3rem 0.75rem;
        }

        .telecel-business-page .telecel-purchase-panel .widget-body {
            padding: 0 1.3rem 1.3rem;
        }

        .telecel-business-page .telecel-selected-package {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.9rem 1rem;
            border-radius: 18px;
            background: #fee2e2;
            border: 1px solid rgba(220, 38, 38, 0.16);
            margin-bottom: 1rem;
        }

        .telecel-business-page .telecel-selected-package img {
            width: 42px;
            height: 42px;
            object-fit: contain;
        }

        .telecel-business-page .telecel-selected-package strong,
        .telecel-business-page .telecel-selected-package span {
            display: block;
        }

        .telecel-business-page .telecel-selected-package strong {
            color: #1f2937;
            font-size: 1rem;
        }

        .telecel-business-page .telecel-selected-package span {
            color: #b91c1c;
            font-weight: 700;
        }

        .telecel-business-page .telecel-hidden-select {
            display: none;
        }

        .telecel-business-page.telecel-checkout-modal-open {
            overflow: hidden;
        }

        .telecel-business-page .telecel-checkout-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1200;
        }

        .telecel-business-page .telecel-checkout-modal.is-open {
            display: flex;
        }

        .telecel-business-page .telecel-checkout-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.62);
        }

        .telecel-business-page .telecel-checkout-modal__dialog {
            position: relative;
            width: min(560px, 100%);
            max-height: calc(100vh - 2rem);
            overflow: auto;
            z-index: 1;
        }

        .telecel-business-page .telecel-checkout-modal__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.9rem;
            color: var(--text-primary);
        }

        .telecel-business-page .telecel-checkout-modal__eyebrow {
            margin-bottom: 0.25rem;
            color: #b91c1c;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .telecel-business-page .telecel-checkout-modal__header h2 {
            margin: 0;
            color: var(--text-primary);
            font-size: 1.3rem;
        }

        .telecel-business-page .telecel-checkout-modal__header p {
            margin: 0.2rem 0 0;
            color: var(--text-secondary);
            font-size: 0.92rem;
        }

        .telecel-business-page .telecel-checkout-modal__close {
            border: none;
            background: rgba(220, 38, 38, 0.12);
            color: #b91c1c;
            width: 40px;
            height: 40px;
            border-radius: 999px;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
        }

        .telecel-business-page .telecel-checkout-status {
            display: none;
            margin-bottom: 1rem;
        }

        html[data-theme="dark"] body.agent-telecel-page .widget,
        html[data-theme="dark"] body.agent-telecel-page .modal-content {
            background: #0f172a;
            border-color: #334155;
            box-shadow: 0 22px 48px rgba(2, 6, 23, 0.55);
        }

        html[data-theme="dark"] body.agent-telecel-page .widget-title,
        html[data-theme="dark"] body.agent-telecel-page .form-label,
        html[data-theme="dark"] body.agent-telecel-page .widget-body p {
            color: #f8fafc;
        }

        html[data-theme="dark"] body.agent-telecel-page .form-help,
        html[data-theme="dark"] body.agent-telecel-page small,
        html[data-theme="dark"] body.agent-telecel-page .widget-body div[style*='var(--text-muted)'] {
            color: #cbd5e1 !important;
        }

        html[data-theme="dark"] body.agent-telecel-page .form-control {
            background: #1e293b;
            border-color: #475569;
            color: #f8fafc;
        }

        html[data-theme="dark"] body.agent-telecel-page .form-control::placeholder {
            color: #94a3b8;
        }

        html[data-theme="dark"] body.agent-telecel-page .btn.btn-outline {
            background: #1e293b;
            border-color: #475569;
            color: #f8fafc;
        }

        html[data-theme="dark"] body.agent-telecel-page .btn.btn-outline:hover {
            background: #334155;
            color: #f8fafc;
        }

        html[data-theme="dark"] .telecel-business-page .telecel-checkout-modal__dialog,
        html[data-theme="dark"] .telecel-business-page .telecel-purchase-panel {
            background: #0f172a;
            border-color: #334155;
            box-shadow: 0 22px 48px rgba(2, 6, 23, 0.55);
        }

        html[data-theme="dark"] .telecel-business-page .telecel-checkout-modal__header h2,
        html[data-theme="dark"] .telecel-business-page .telecel-business-header h1,
        html[data-theme="dark"] .telecel-business-page .telecel-selected-package strong,
        html[data-theme="dark"] .telecel-business-page .widget-title,
        html[data-theme="dark"] .telecel-business-page .form-label,
        html[data-theme="dark"] .telecel-business-page .widget-body p {
            color: #f8fafc;
        }

        html[data-theme="dark"] .telecel-business-page .telecel-business-header p,
        html[data-theme="dark"] .telecel-business-page .telecel-checkout-modal__header p,
        html[data-theme="dark"] .telecel-business-page .form-help {
            color: #cbd5e1;
        }

        html[data-theme="dark"] .telecel-business-page .telecel-selected-package {
            background: #3f1015;
            border-color: rgba(248, 113, 113, 0.25);
        }

        html[data-theme="dark"] .telecel-business-page .telecel-selected-package span {
            color: #fca5a5;
        }

        html[data-theme="dark"] .telecel-business-page .telecel-checkout-modal__close {
            background: rgba(248, 113, 113, 0.18);
            color: #fecaca;
        }

        @media (max-width: 640px) {
            .telecel-business-page .dashboard-content {
                padding: 1rem;
            }

            .telecel-business-page .telecel-package-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }
    </style>
</head>
<body class="telecel-business-page agent-telecel-page">
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
            <?php renderAgentSidebar(); ?>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-signal"></i></div>
                    <div class="breadcrumb-item">Services</div>
                    <div class="breadcrumb-item active">Telecel Business</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;">&nbsp;<?php echo htmlspecialchars($current_user['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php" class="dropdown-item">
                            <i class="fas fa-wallet"></i> Wallet
                        </a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

<?php echo renderNotificationSlides('agents'); ?>


        <div class="dashboard-content">
            <div class="telecel-business-shell">
                <div class="telecel-business-header">
                    <h1>Telecel Bundles</h1>
                    <p>Click Buy Now on any package to open the checkout popup and complete the purchase.</p>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success" style="margin-bottom: 1rem;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="telecel-package-grid" id="telecelPackageGrid">
                    <?php foreach ($telecel_packages as $package): ?>
                        <?php
                            $isSelectedPackage = (isset($_POST['package_id']) && (int) $_POST['package_id'] === (int) $package['id']);
                            $displayPackageSize = formatBundleDisplaySize($package['data_size'] ?? $package['name']);
                            $packageLabel = 'Telecel ' . $displayPackageSize;
                        ?>
                        <article class="telecel-package-card <?php echo $isSelectedPackage ? 'is-selected' : ''; ?>" data-package-id="<?php echo (int) $package['id']; ?>" data-package-price="<?php echo htmlspecialchars((string) $package['effective_price']); ?>" data-package-label="<?php echo htmlspecialchars($packageLabel); ?>">
                            <div class="telecel-package-card-top">
                                <img class="telecel-package-logo" src="<?php echo htmlspecialchars($telecel_logo_png); ?>" alt="Telecel logo">
                                <div class="telecel-package-copy">
                                    <h2 class="telecel-package-size"><?php echo htmlspecialchars($displayPackageSize); ?></h2>
                                    <div class="telecel-package-price"><?php echo formatCurrency($package['effective_price']); ?></div>
                                </div>
                            </div>
                            <button type="button" class="telecel-package-select" data-select-package="<?php echo (int) $package['id']; ?>">Buy Now</button>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div id="purchaseModal" class="telecel-checkout-modal<?php echo ($error && !empty($_POST['package_id'])) ? ' is-open' : ''; ?>" aria-hidden="<?php echo ($error && !empty($_POST['package_id'])) ? 'false' : 'true'; ?>">
                    <div class="telecel-checkout-modal__backdrop" data-close-purchase-modal="1"></div>
                    <div class="telecel-checkout-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="telecelCheckoutTitle">
                        <div class="telecel-checkout-modal__header">
                            <div>
                                <div class="telecel-checkout-modal__eyebrow">Telecel Checkout</div>
                                <h2 id="telecelCheckoutTitle">Complete Purchase</h2>
                                <p>Enter the beneficiary number and choose how you want to pay.</p>
                            </div>
                            <button type="button" class="telecel-checkout-modal__close" id="purchaseModalCloseBtn" aria-label="Close checkout">&times;</button>
                        </div>
                        <div class="telecel-purchase-panel">
                            <div class="widget">
                                <div class="widget-header" style="display: flex; justify-content: space-between; align-items: center;">
                                    <h3 class="widget-title">BUY TELECEL BUNDLES</h3>
                                    <button class="btn btn-danger" style="padding: 0.5rem 1rem; font-size: 0.875rem;" onclick="openBulkUploadModal(); return false;">
                                        Upload Excel
                                    </button>
                                </div>
                                <div class="widget-body">
                                    <?php if ($error): ?>
                                        <div class="alert alert-danger">
                                            <?php echo htmlspecialchars($error); ?>
                                        </div>
                                    <?php endif; ?>

                                    <form method="POST" action="" id="purchaseForm">
                                        <input type="hidden" name="action" value="purchase">
                                        <input type="hidden" name="payment_method" id="payment_method" value="wallet">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRF()); ?>">
                                        <div class="telecel-selected-package" id="selectedPackageSummary" <?php echo empty($_POST['package_id']) ? 'style="display:none;"' : ''; ?>>
                                            <img src="<?php echo htmlspecialchars($telecel_logo_png); ?>" alt="Telecel logo">
                                            <div>
                                                <strong id="selectedPackageLabel"><?php
                                                    $selectedPackageLabel = 'Choose a package above';
                                                    $selectedPackagePrice = '';
                                                    foreach ($telecel_packages as $package) {
                                                        if (isset($_POST['package_id']) && (int) $_POST['package_id'] === (int) $package['id']) {
                                                            $selectedPackageLabel = 'Telecel ' . formatBundleDisplaySize($package['data_size'] ?? $package['name']);
                                                            $selectedPackagePrice = formatCurrency($package['effective_price']);
                                                            break;
                                                        }
                                                    }
                                                    echo htmlspecialchars($selectedPackageLabel);
                                                ?></strong>
                                                <span id="selectedPackagePrice"><?php echo htmlspecialchars($selectedPackagePrice); ?></span>
                                            </div>
                                        </div>

                                        <div id="telecelCheckoutClientError" class="alert alert-danger telecel-checkout-status"></div>

                                        <div class="form-group">
                                            <label for="phone_number" class="form-label">
                                                PHONE NUMBER <span style="color: var(--accent-red);">*</span>
                                            </label>
                                            <input
                                                type="tel"
                                                class="form-control"
                                                id="phone_number"
                                                name="phone_number"
                                                placeholder="Beneficiary Phone Number"
                                                value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>"
                                                required
                                            >
                                            <small class="form-help">Use Telecel numbers only (020/050) and 10 digits.</small>
                                        </div>

                                        <div class="form-group">
                                            <label for="package_id" class="form-label">
                                                SELECT MENU <span style="color: var(--accent-red);">*</span>
                                            </label>
                                            <select class="form-control form-select telecel-hidden-select" id="package_id" name="package_id" required>
                                                <option value="">Select package</option>
                                                <?php foreach ($telecel_packages as $package): ?>
                                                    <option
                                                        value="<?php echo $package['id']; ?>"
                                                        data-price="<?php echo $package['effective_price']; ?>"
                                                        <?php echo (isset($_POST['package_id']) && $_POST['package_id'] == $package['id']) ? 'selected' : ''; ?>
                                                    >
                                                        Telecel <?php echo htmlspecialchars(formatBundleDisplaySize($package['data_size'] ?? $package['name'])); ?> - <?php echo formatCurrency($package['effective_price']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <p style="margin-bottom: 1rem;">
                                                Available Balance:
                                                <span style="color: var(--accent-green); font-weight: 600;">
                                                    <?php echo formatCurrency($wallet_balance); ?>
                                                </span>
                                            </p>
                                        </div>

                                        <div class="form-group">
                                            <button
                                                type="submit"
                                                class="btn btn-primary"
                                                style="width: 100%;"
                                                id="payWithWalletBtn"
                                            >
                                                Pay with Wallet
                                            </button>
                                            <?php if ($paystack_direct_enabled): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline"
                                                    style="width: 100%; margin-top: 0.75rem;"
                                                    id="payWithPaystackBtn"
                                                >
                                                    Pay with Paystack
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Upload Modal -->
                <div id="bulkUploadModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                    <div class="modal-content" style="max-width: 600px; margin: 5% auto; background: var(--card-bg); border-radius: 12px; padding: 2rem; position: relative;">
                        <span class="close" onclick="closeBulkUploadModal()" style="position: absolute; top: 1rem; right: 1.5rem; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</span>
                        
                        <div style="text-align: center; margin-bottom: 2rem;">
                            <h2 style="color: var(--text-primary); margin-bottom: 0.5rem;">Telecel Bulk Uploads</h2>
                            <p style="color: var(--text-secondary);">Share with your loved ones. Huge data volumes for downloads and live streaming. Advanced bundles for your business.</p>
                            <button class="btn btn-outline" style="padding: 0.5rem 1.5rem; margin-top: 1rem;">Read More</button>
                        </div>
                        
                        <div class="widget">
                            <div class="widget-header">
                                <h3 class="widget-title">UPLOAD TELECEL BULK BUNDLES</h3>
                            </div>
                            <div class="widget-body">
                                <form id="bulkUploadForm" enctype="multipart/form-data" onsubmit="handleBulkUpload(event)">
                                    <div class="form-group">
                                        <label class="form-label">UPLOAD YOUR FILE <span style="color: var(--accent-red);">*</span></label>
                                        <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                                            <input type="file" id="bulkFile" name="bulk_file" accept=".xlsx,.xls,.csv" style="display: none;">
                                        <input type="hidden" name="network" value="telecel">
                                            <button type="button" class="btn btn-outline" onclick="document.getElementById('bulkFile').click()" style="flex: 1;">
                                                <i class="fas fa-upload"></i> Choose File
                                            </button>
                                            <span id="fileName" style="color: var(--text-muted); font-size: 0.875rem;">No file chosen</span>
                                            <a href="download_template.php?network=telecel" class="btn btn-link" style="color: var(--accent-red); text-decoration: none; font-size: 0.875rem;">View Template</a>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group" style="display: flex; gap: 1rem;">
                                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                                            <i class="fas fa-upload"></i> Upload Excel File
                                        </button>
                                        <button type="button" class="btn btn-success" style="flex: 1;" onclick="topupWallet()">
                                            <i class="fas fa-wallet"></i> Topup Wallet
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal removed to match AT/MTN simple purchase flow -->

<script>
    const agentCurrency = <?php echo json_encode(CURRENCY); ?>;
    const agentTelecelBundlePaystackEnabled = <?php echo $paystack_direct_enabled ? 'true' : 'false'; ?>;
    const agentTelecelBundlePaystackInitEndpoint = <?php echo json_encode($agent_telecel_bundle_paystack_init_endpoint); ?>;

    function ensureOrderConfirmModal() {
        if (window.__orderConfirmModalState) return window.__orderConfirmModalState;

        const styleId = 'order-confirm-modal-style';
        if (!document.getElementById(styleId)) {
            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `
                .order-confirm-modal {
                    position: fixed;
                    inset: 0;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    z-index: 12000;
                    padding: 1rem;
                }
                .order-confirm-modal.show { display: flex; }
                .order-confirm-backdrop {
                    position: absolute;
                    inset: 0;
                    background: rgba(15, 23, 42, 0.55);
                }
                .order-confirm-dialog {
                    position: relative;
                    width: min(520px, 100%);
                    background: var(--card-bg, #fff);
                    border: 1px solid var(--border-color, #d1d5db);
                    border-radius: 14px;
                    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.25);
                    color: var(--text-primary, #111827);
                    overflow: hidden;
                }
                .order-confirm-header {
                    padding: 1rem 1.2rem 0.5rem;
                    font-weight: 700;
                    font-size: 1.05rem;
                }
                .order-confirm-subtitle {
                    padding: 0 1.2rem;
                    color: var(--text-muted, #6b7280);
                    font-size: 0.9rem;
                }
                .order-confirm-details {
                    margin: 0.9rem 1.2rem 0;
                    border: 1px solid var(--border-color, #e5e7eb);
                    border-radius: 10px;
                    overflow: hidden;
                }
                .order-confirm-row {
                    display: flex;
                    justify-content: space-between;
                    gap: 1rem;
                    padding: 0.7rem 0.85rem;
                    border-bottom: 1px solid var(--border-color, #e5e7eb);
                    font-size: 0.92rem;
                }
                .order-confirm-row:last-child { border-bottom: none; }
                .order-confirm-row span:first-child { color: var(--text-muted, #6b7280); }
                .order-confirm-row span:last-child { font-weight: 600; text-align: right; word-break: break-word; }
                .order-confirm-actions {
                    display: flex;
                    gap: 0.75rem;
                    justify-content: flex-end;
                    padding: 1rem 1.2rem 1.1rem;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-backdrop {
                    background: rgba(2, 6, 23, 0.72);
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-dialog {
                    background: #0f172a;
                    border-color: #334155;
                    color: #f8fafc;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-header,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:last-child {
                    color: #f8fafc;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-subtitle,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row span:first-child {
                    color: #cbd5e1;
                }
                html[data-theme="dark"] .order-confirm-modal .order-confirm-details,
                html[data-theme="dark"] .order-confirm-modal .order-confirm-row {
                    border-color: #334155;
                }
                html[data-theme="dark"] .order-confirm-modal .btn.btn-secondary,
                html[data-theme="dark"] .order-confirm-modal .btn.btn-outline {
                    background: #1e293b;
                    border-color: #475569;
                    color: #f8fafc;
                }
            `;
            document.head.appendChild(style);
        }

        const modal = document.createElement('div');
        modal.className = 'order-confirm-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="order-confirm-backdrop" data-close="1"></div>
            <div class="order-confirm-dialog" role="dialog" aria-modal="true" aria-label="Confirm order">
                <div class="order-confirm-header" id="orderConfirmTitle">Confirm Order</div>
                <div class="order-confirm-subtitle" id="orderConfirmSubtitle">Review details before submitting.</div>
                <div class="order-confirm-details" id="orderConfirmDetails"></div>
                <div class="order-confirm-actions">
                    <button type="button" class="btn btn-secondary btn-sm" id="orderConfirmCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="orderConfirmOkBtn">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const state = {
            modal: modal,
            title: modal.querySelector('#orderConfirmTitle'),
            subtitle: modal.querySelector('#orderConfirmSubtitle'),
            details: modal.querySelector('#orderConfirmDetails'),
            cancelBtn: modal.querySelector('#orderConfirmCancelBtn'),
            okBtn: modal.querySelector('#orderConfirmOkBtn'),
            resolver: null
        };

        function close(result) {
            if (document.activeElement && state.modal.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            state.modal.classList.remove('show');
            state.modal.setAttribute('aria-hidden', 'true');
            if (state.resolver) {
                const resolve = state.resolver;
                state.resolver = null;
                resolve(!!result);
            }
        }

        state.modal.addEventListener('click', function(event) {
            if (event.target && event.target.getAttribute('data-close') === '1') {
                close(false);
            }
        });
        state.cancelBtn.addEventListener('click', function() { close(false); });
        state.okBtn.addEventListener('click', function() { close(true); });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && state.modal.classList.contains('show')) {
                close(false);
            }
        });

        state.open = function(config) {
            if (state.resolver) {
                const prev = state.resolver;
                state.resolver = null;
                prev(false);
            }
            state.title.textContent = config.title || 'Confirm Order';
            state.subtitle.textContent = config.subtitle || 'Review details before submitting.';
            state.okBtn.textContent = config.confirmText || 'Confirm';
            state.cancelBtn.textContent = config.cancelText || 'Cancel';
            state.details.innerHTML = '';

            (config.details || []).forEach(function(item) {
                const row = document.createElement('div');
                row.className = 'order-confirm-row';
                const label = document.createElement('span');
                label.textContent = item.label || '';
                const value = document.createElement('span');
                value.textContent = item.value || '';
                row.appendChild(label);
                row.appendChild(value);
                state.details.appendChild(row);
            });

            state.modal.classList.add('show');
            state.modal.setAttribute('aria-hidden', 'false');
            setTimeout(function() { 
                if (state.modal.classList.contains('show')) {
                    state.okBtn.focus(); 
                }
            }, 50);
            return new Promise(function(resolve) {
                state.resolver = resolve;
            });
        };

        window.__orderConfirmModalState = state;
        return state;
    }

    function openOrderConfirmModal(config) {
        return ensureOrderConfirmModal().open(config || {});
    }

    // All DOM-dependent functionality in one consolidated listener
    document.addEventListener('DOMContentLoaded', function() {
        // Mobile menu toggle
        const mobileToggle = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', function() {
                console.log('Hamburger menu clicked'); // Debug log
                sidebar.classList.toggle('show');
            });
        } else {
            console.log('Mobile toggle or sidebar not found:', { mobileToggle, sidebar }); // Debug log
        }
        
        // Checkout modal and form handling
        const purchaseForm = document.getElementById('purchaseForm');
        const packageSelect = document.getElementById('package_id');
        const packageCards = Array.from(document.querySelectorAll('.telecel-package-card'));
        const selectedPackageSummary = document.getElementById('selectedPackageSummary');
        const selectedPackageLabelEl = document.getElementById('selectedPackageLabel');
        const selectedPackagePriceEl = document.getElementById('selectedPackagePrice');
        const purchaseModal = document.getElementById('purchaseModal');
        const purchaseModalCloseBtn = document.getElementById('purchaseModalCloseBtn');
        const purchaseModalBackdrop = purchaseModal ? purchaseModal.querySelector('[data-close-purchase-modal="1"]') : null;
        const phoneInput = document.getElementById('phone_number');
        const payWithWalletBtn = document.getElementById('payWithWalletBtn');
        const payWithPaystackBtn = document.getElementById('payWithPaystackBtn');
        const paymentMethodInput = document.getElementById('payment_method');
        const checkoutClientError = document.getElementById('telecelCheckoutClientError');
        const walletBalance = <?php echo json_encode((float) $wallet_balance); ?>;
        const reopenCheckoutOnLoad = <?php echo ($error && !empty($_POST['package_id'])) ? 'true' : 'false'; ?>;
        const submittedPackageId = <?php echo (int) ($_POST['package_id'] ?? 0); ?>;

        function setTelecelCheckoutError(message) {
            if (!checkoutClientError) return;
            if (!message) {
                checkoutClientError.style.display = 'none';
                checkoutClientError.textContent = '';
                checkoutClientError.className = 'alert alert-danger telecel-checkout-status';
                return;
            }
            checkoutClientError.style.display = '';
            checkoutClientError.textContent = message;
            checkoutClientError.className = 'alert alert-danger telecel-checkout-status';
        }

        function setCheckoutButtonsLoading(isLoading, activeButton) {
            [payWithWalletBtn, payWithPaystackBtn].forEach(function(button) {
                if (!button) return;
                if (!button.dataset.defaultText) {
                    button.dataset.defaultText = button.innerHTML;
                }
                button.disabled = !!isLoading;
                if (isLoading && activeButton === button) {
                    button.innerHTML = '<span class="spinner"></span> Processing...';
                } else if (!isLoading) {
                    button.innerHTML = button.dataset.defaultText;
                }
            });
        }

        function syncSelectedPackageCard(packageId) {
            packageCards.forEach(function(card) {
                card.classList.toggle('is-selected', String(card.dataset.packageId) === String(packageId));
            });

            if (!packageSelect) return;
            const selectedOption = packageSelect.options[packageSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                if (selectedPackageSummary) selectedPackageSummary.style.display = 'none';
                return;
            }

            if (selectedPackageSummary) selectedPackageSummary.style.display = '';
            if (selectedPackageLabelEl) {
                selectedPackageLabelEl.textContent = selectedOption.textContent.split(' - ')[0].trim();
            }
            if (selectedPackagePriceEl) {
                selectedPackagePriceEl.textContent = agentCurrency + Number(selectedOption.dataset.price || 0).toFixed(2);
            }
        }

        function openPurchaseModal(packageId, options) {
            if (!purchaseModal) return;
            const config = options || {};

            if (packageSelect && packageId) {
                packageSelect.value = String(packageId);
            }
            syncSelectedPackageCard(packageSelect ? packageSelect.value : packageId);

            if (paymentMethodInput) {
                paymentMethodInput.value = 'wallet';
            }
            if (config.clearError !== false) {
                setTelecelCheckoutError('');
            }

            purchaseModal.classList.add('is-open');
            purchaseModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('telecel-checkout-modal-open');

            if (phoneInput && config.focus !== false) {
                window.setTimeout(function() {
                    if (purchaseModal.classList.contains('is-open')) {
                        phoneInput.focus();
                        phoneInput.select();
                    }
                }, 50);
            }
        }

        function closePurchaseModal() {
            if (!purchaseModal) return;
            purchaseModal.classList.remove('is-open');
            purchaseModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('telecel-checkout-modal-open');
            setCheckoutButtonsLoading(false);
        }

        function getSelectedTelecelCheckoutDetails(requireWalletFunds) {
            const phoneNumber = phoneInput ? phoneInput.value : '';
            const packageId = packageSelect ? packageSelect.value : '';

            if (!phoneNumber || !packageId) {
                setTelecelCheckoutError('Please fill in all required fields.');
                return null;
            }

            const localPhone = normalizeTelecelLocalPhone(phoneNumber);
            if (!isTelecelLocalPhone(localPhone)) {
                setTelecelCheckoutError('Use Telecel numbers only (020/050) and 10 digits.');
                if (phoneInput) {
                    phoneInput.focus();
                    phoneInput.select();
                }
                return null;
            }

            const selectedOption = document.querySelector('#package_id option:checked');
            const packagePrice = parseFloat(selectedOption?.dataset.price || 0);
            if (!selectedOption || !selectedOption.value || Number.isNaN(packagePrice) || packagePrice <= 0) {
                setTelecelCheckoutError('Please select a valid package before continuing.');
                return null;
            }

            if (requireWalletFunds && packagePrice > walletBalance) {
                setTelecelCheckoutError('Insufficient wallet balance. Please top up your wallet.');
                return null;
            }

            return {
                localPhone: localPhone,
                packageId: packageId,
                packageLabel: selectedOption ? selectedOption.textContent.trim() : 'Selected package',
                packagePrice: packagePrice
            };
        }

        async function startAgentTelecelPaystackCheckout() {
            if (!agentTelecelBundlePaystackEnabled) {
                setTelecelCheckoutError('Paystack checkout is currently unavailable.');
                return;
            }
            if (!purchaseForm || !paymentMethodInput) return;

            setTelecelCheckoutError('');
            paymentMethodInput.value = 'paystack';
            const checkout = getSelectedTelecelCheckoutDetails(false);
            if (!checkout) {
                paymentMethodInput.value = 'wallet';
                return;
            }

            const confirmed = await openOrderConfirmModal({
                title: 'Continue to Paystack',
                subtitle: 'You will be redirected to Paystack to complete this order.',
                confirmText: 'Continue to Payment',
                details: [
                    { label: 'Network', value: 'Telecel' },
                    { label: 'Package', value: checkout.packageLabel },
                    { label: 'Recipient', value: checkout.localPhone },
                    { label: 'Amount', value: agentCurrency + checkout.packagePrice.toFixed(2) }
                ]
            });

            if (!confirmed) {
                paymentMethodInput.value = 'wallet';
                return;
            }

            setCheckoutButtonsLoading(true, payWithPaystackBtn);

            try {
                const response = await fetch(agentTelecelBundlePaystackInitEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        package_id: parseInt(checkout.packageId, 10),
                        beneficiary_number: checkout.localPhone,
                        csrf_token: (purchaseForm.querySelector('input[name="csrf_token"]') || {}).value || '',
                        gateway: 'paystack'
                    })
                });

                const result = await response.json().catch(function() {
                    return null;
                });

                if (!response.ok || !result || result.status !== 'success' || !result.data || !result.data.authorization_url) {
                    const message = result && result.message ? result.message : 'Unable to initialize Paystack checkout right now.';
                    throw new Error(message);
                }

                window.location.href = result.data.authorization_url;
            } catch (error) {
                setCheckoutButtonsLoading(false);
                paymentMethodInput.value = 'wallet';
                setTelecelCheckoutError(error.message || 'Unable to initialize Paystack checkout right now.');
            }
        }

        packageCards.forEach(function(card) {
            const trigger = card.querySelector('[data-select-package]');
            if (!trigger || !packageSelect) return;

            trigger.addEventListener('click', function() {
                openPurchaseModal(this.dataset.selectPackage);
            });
        });

        if (packageSelect) {
            packageSelect.addEventListener('change', function() {
                syncSelectedPackageCard(this.value);
            });
            syncSelectedPackageCard(packageSelect.value);
        }

        if (purchaseModalCloseBtn) {
            purchaseModalCloseBtn.addEventListener('click', closePurchaseModal);
        }
        if (purchaseModalBackdrop) {
            purchaseModalBackdrop.addEventListener('click', closePurchaseModal);
        }
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && purchaseModal && purchaseModal.classList.contains('is-open')) {
                closePurchaseModal();
            }
        });

        if (purchaseForm) {
            purchaseForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (paymentMethodInput) {
                    paymentMethodInput.value = 'wallet';
                }
                setTelecelCheckoutError('');

                const checkout = getSelectedTelecelCheckoutDetails(true);
                if (!checkout) {
                    return;
                }

                openOrderConfirmModal({
                    title: 'Confirm Telecel Purchase',
                    subtitle: 'Review the order details before submitting.',
                    confirmText: 'Submit Order',
                    details: [
                        { label: 'Network', value: 'Telecel' },
                        { label: 'Package', value: checkout.packageLabel },
                        { label: 'Recipient', value: checkout.localPhone },
                        { label: 'Amount', value: agentCurrency + checkout.packagePrice.toFixed(2) }
                    ]
                }).then(function(confirmed) {
                    if (!confirmed) return;
                    setCheckoutButtonsLoading(true, payWithWalletBtn);
                    purchaseForm.submit();
                });
            });
        }

        if (payWithPaystackBtn) {
            payWithPaystackBtn.addEventListener('click', function() {
                startAgentTelecelPaystackCheckout();
            });
        }

        if (reopenCheckoutOnLoad && submittedPackageId) {
            openPurchaseModal(submittedPackageId, { clearError: false, focus: false });
        }
        
        // Phone number formatting
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
                
                // Keep local Ghana format (starting with 0)
                if (value.length > 0) {
                    if (value.startsWith('233')) {
                        // Convert international to local format for display
                        e.target.value = '0' + value.substring(3);
                    } else if (!value.startsWith('0') && value.length > 0) {
                        // Add leading 0 if missing
                        e.target.value = '0' + value;
                    }
                }
            });
        }

        function normalizeTelecelLocalPhone(value) {
            const digits = String(value || '').replace(/\D/g, '');
            if (digits.startsWith('233')) {
                return '0' + digits.slice(3);
            }
            return digits;
        }

        function isTelecelLocalPhone(localPhone) {
            if (!/^\d{10}$/.test(localPhone)) return false;
            const prefix = localPhone.slice(0, 3);
            return ['020', '050'].indexOf(prefix) !== -1;
        }
        
        // File selection display
        const fileInput = document.getElementById('bulkFile');
        const fileName = document.getElementById('fileName');
        if (fileInput && fileName) {
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    fileName.textContent = e.target.files[0].name;
                } else {
                    fileName.textContent = 'No file chosen';
                }
            });
        }
        
        // Bulk upload form submission
        const bulkUploadForm = document.getElementById('bulkUploadForm');
        if (bulkUploadForm) {
            bulkUploadForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const fileInput = document.getElementById('bulkFile');
                
                if (!fileInput.files[0]) {
                    alert('Please select an Excel file to upload.');
                    return;
                }
                
                const formData = new FormData();
                formData.append('bulk_file', fileInput.files[0]);
                formData.append('network', 'telecel');
                formData.append('action', 'bulk_upload');
                
                // Show loading state
                const submitBtn = e.target.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
                
                fetch('process_bulk_upload.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Bulk upload processed successfully! ' + data.message);
                        closeBulkUploadModal();
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while processing the upload.');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
        }
        
        // Modal click outside to close
        const bulkUploadModal = document.getElementById('bulkUploadModal');
        if (bulkUploadModal) {
            bulkUploadModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeBulkUploadModal();
                }
            });
        }
        
        // Initialize theme
        initTheme();
        
        // Ensure icon is updated after DOM is ready
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        updateThemeIcon(theme);
    });
    
    // Theme management - consistent across all pages
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        
        document.documentElement.setAttribute('data-theme', theme);
        updateThemeIcon(theme);
    }

    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateThemeIcon(newTheme);
    }

    function updateThemeIcon(theme) {
        const icon = document.getElementById('theme-icon');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    // User dropdown
    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        dropdown.classList.toggle('show');
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        
        if (dropdown && toggle && !toggle.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });



    // Define modal functions in global scope immediately
    window.openBulkUploadModal = function() {
        console.log('Excel button clicked - opening modal');
        try {
            const modal = document.getElementById('bulkUploadModal');
            console.log('Modal element:', modal);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                console.log('Modal displayed successfully');
            } else {
                console.error('bulkUploadModal element not found in DOM');
            }
        } catch (error) {
            console.error('Error opening modal:', error);
        }
    };
    
    window.closeBulkUploadModal = function() {
        console.log('Closing modal');
        try {
            const modal = document.getElementById('bulkUploadModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                console.log('Modal closed successfully');
            }
        } catch (error) {
            console.error('Error closing modal:', error);
        }
    };
    
    window.topupWallet = function() {
        window.location.href = 'wallet.php';
    };
    
    // Handle bulk upload form submission
    window.handleBulkUpload = function(event) {
        event.preventDefault();
        
        const fileInput = document.getElementById('bulkFile');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file to upload');
            return;
        }
        
        const formData = new FormData();
        formData.append('bulk_file', file);
        formData.append('network', 'Telecel');
        
        const submitBtn = event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        submitBtn.disabled = true;
        
        fetch('process_bulk_upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                closeBulkUploadModal();
                location.reload(); // Refresh to update wallet balance
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            alert('Upload failed. Please try again.');
        })
        .finally(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    };
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>

<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/phone-paste.js')); ?>"></script>
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>


ications.js')); ?>"></script>
</body>
</html>


