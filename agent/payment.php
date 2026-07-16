<?php
require_once '../config/config.php';

requireLogin();
requireRole('agent');

$siteName = htmlspecialchars(getSiteName(), ENT_QUOTES, 'UTF-8');
$token = $_GET['token'] ?? '';
$reference = $_GET['ref'] ?? '';
$redirectUrl = '';
$error = '';

if ($token !== '') {
    $normalized = strtr($token, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($normalized, true);
    if ($decoded !== false) {
        $decoded = trim($decoded);
        if (filter_var($decoded, FILTER_VALIDATE_URL)) {
            $host = strtolower((string) parse_url($decoded, PHP_URL_HOST));
            $isPaystack = (bool) preg_match('/(^|\\.)paystack\\.com$/', $host);
            $isMoolre = $host === 'pos.moolre.com';
            if ($isPaystack || $isMoolre) {
                $redirectUrl = $decoded;
            } else {
                $error = 'Invalid payment host.';
            }
        } else {
            $error = 'Invalid payment link.';
        }
    } else {
        $error = 'Invalid payment token.';
    }
} else {
    $error = 'Missing payment token.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Payment - <?php echo $siteName; ?></title>
    <link rel="preconnect" href="https://pos.moolre.com">
    <link rel="dns-prefetch" href="https://pos.moolre.com">
    <style>
        :root {
            --bg: #f6f6fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #5f667d;
            --accent: #6c5ce7;
            --border: rgba(15, 23, 42, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            width: min(980px, 100%);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 2rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            text-align: center;
        }
        .brand {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 1.6rem;
            margin: 0 0 0.75rem;
        }
        p {
            margin: 0 0 1.5rem;
            color: var(--muted);
            line-height: 1.6;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.7rem 1.4rem;
            border-radius: 999px;
            border: 1px solid transparent;
            background: linear-gradient(135deg, var(--accent), #3b82f6);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        .btn-outline {
            background: transparent;
            color: var(--text);
            border-color: var(--border);
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
            margin-top: 1rem;
        }
        .frame-wrap {
            margin-top: 1.5rem;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: #fff;
        }
        .payment-frame {
            width: 100%;
            height: clamp(520px, 70vh, 760px);
            border: none;
        }
        .spinner {
            width: 28px;
            height: 28px;
            border: 3px solid rgba(108, 92, 231, 0.2);
            border-top-color: var(--accent);
            border-radius: 50%;
            margin: 0 auto 1rem;
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 640px) {
            body { padding: 1rem; }
            .card { padding: 1.5rem; }
            .payment-frame { height: 78vh; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand"><?php echo $siteName; ?></div>
        <?php if ($redirectUrl): ?>
            <div class="spinner"></div>
            <h1>Secure payment</h1>
            <p>We opened the payment securely inside this page. If it doesn't load, use the button below.</p>
            <div class="frame-wrap">
                <iframe class="payment-frame" src="<?php echo htmlspecialchars($redirectUrl); ?>" title="Secure Payment" allow="payment"></iframe>
            </div>
            <div class="actions">
                <a class="btn" href="<?php echo htmlspecialchars($redirectUrl); ?>" id="continueBtn" target="_blank" rel="noopener">Open in New Tab</a>
                <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/agent/wallet.php">Back to Wallet</a>
            </div>
            <?php if ($reference): ?>
                <p style="margin-top:1rem; font-size:0.9rem;">Waiting for payment confirmation...</p>
            <?php endif; ?>
        <?php else: ?>
            <h1>Unable to start payment</h1>
            <p><?php echo htmlspecialchars($error); ?> Please go back and try again.</p>
            <a class="btn btn-outline" href="<?php echo SITE_URL; ?>/agent/wallet.php">Back to Wallet</a>
        <?php endif; ?>
    </div>
    <?php if ($redirectUrl && $reference): ?>
    <script>
        (function () {
            const reference = <?php echo json_encode($reference); ?>;
            const apiUrl = <?php echo json_encode(SITE_URL . '/api/transaction_status.php'); ?> + '?reference=' + encodeURIComponent(reference);
            let attempts = 0;
            const maxAttempts = 180; // ~3 minutes at 1s
            const poll = async () => {
                attempts += 1;
                try {
                    const res = await fetch(apiUrl, { cache: 'no-store' });
                    const data = await res.json();
                    if (data && data.transaction_status === 'success' && data.redirect_path) {
                        window.location.href = <?php echo json_encode(SITE_URL); ?> + data.redirect_path;
                        return;
                    }
                    if (data && data.transaction_status === 'failed') {
                        window.location.href = <?php echo json_encode(SITE_URL . '/agent/wallet.php'); ?>;
                        return;
                    }
                } catch (e) { /* ignore */ }
                if (attempts < maxAttempts) {
                    setTimeout(poll, 1000);
                }
            };
            poll();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
