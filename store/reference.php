<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/order_status.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();

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
    WHERE ast.store_slug = ? AND ast.is_active = TRUE AND u.status = 'active'
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
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($store['store_name']); ?> - Order Status</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        body {
            background: radial-gradient(circle at top, rgba(84, 19, 136, 0.25), transparent 55%), #2E294E;
            color: #F1E9DA;
            min-height: 100vh;
        }
        .status-shell {
            max-width: 900px;
            margin: 3rem auto;
            padding: 0 1rem 3rem;
        }
        .status-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .status-header .btn.btn-outline {
            color: rgba(241, 233, 218, 0.9);
            border-color: rgba(241, 233, 218, 0.6);
            background: rgba(46, 41, 78, 0.2);
        }
        .status-header .btn.btn-outline:hover {
            color: #2E294E;
            background: rgba(241, 233, 218, 0.95);
        }
        .status-header h1 {
            margin: 0;
            font-size: 2rem;
        }
        .status-card {
            background: rgba(46, 41, 78, 0.9);
            border: 1px solid rgba(241, 233, 218, 0.2);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(46, 41, 78, 0.35);
        }
        .status-form {
            display: grid;
            gap: 1rem;
        }
        .status-form .form-control {
            background: rgba(46, 41, 78, 0.35);
            border: 1px solid rgba(241, 233, 218, 0.35);
            color: #F1E9DA;
        }
        .status-form .form-label {
            color: rgba(241, 233, 218, 0.85);
        }
        .status-form .form-control::placeholder {
            color: rgba(241, 233, 218, 0.65);
        }
        .status-results {
            margin-top: 2rem;
            display: grid;
            gap: 1rem;
        }
        .result-card {
            padding: 1.25rem;
            border-radius: 14px;
            background: rgba(46, 41, 78, 0.8);
            border: 1px solid rgba(241, 233, 218, 0.2);
        }
        .result-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .status-pill {
            padding: 0.2rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #2E294E;
        }
        .result-meta {
            display: grid;
            gap: 0.4rem;
            font-size: 0.9rem;
            color: rgba(241, 233, 218, 0.75);
        }
        .result-meta strong {
            color: #F1E9DA;
        }
        @media (max-width: 640px) {
            .status-card {
                padding: 1.5rem;
            }
            .status-header h1 {
                font-size: 1.6rem;
            }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/public-polish.css')); ?>">
</head>
<body>
    <div class="status-shell">
        <div class="status-header">
            <div>
                <h1>Order Status Lookup</h1>
                <div style="color: rgba(241, 233, 218, 0.7);">Store: <?php echo htmlspecialchars($store['store_name']); ?></div>
            </div>
            <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="btn btn-outline">
                    <i class="fas fa-store"></i> Back to Store
                </a>
            </div>
        </div>

        <div class="status-card">
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
                            <p style="margin: 0.5rem 0 0; color: rgba(241, 233, 218, 0.7);">Confirm the phone number or reference and try again.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($results as $order): ?>
                            <?php $status = getOrderStatusDisplay(strtolower($order['status'] ?? 'pending')); ?>
                            <div class="result-card">
                                <div class="result-header">
                                    <div>
                                        <strong><?php echo htmlspecialchars($order['package_name']); ?></strong>
                                        <div style="color: rgba(241, 233, 218, 0.7); font-size: 0.85rem;">
                                            <?php echo htmlspecialchars($order['network_name']); ?> â€¢ <?php echo htmlspecialchars($order['data_size']); ?>
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
</body>
</html>
