<?php
/**
 * Store Service Offline Page
 * Returns a premium, user-friendly Service Unavailable notification with 503 HTTP status.
 */

// Set 503 Service Unavailable response header
http_response_code(503);
header('Retry-After: 3600'); // Suggest retry in 1 hour

$site_url = defined('SITE_URL') ? SITE_URL : '/';
$site_name = function_exists('getSiteName') ? getSiteName() : 'Constechzhub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Offline - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('dbh_asset') ? dbh_asset('assets/vendor/fontawesome/css/all.min.css') : 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'); ?>">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(255, 255, 255, 0.03);
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #f97316; /* Warm Orange Accent for stores */
            --primary-glow: rgba(249, 115, 22, 0.15);
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            overflow: hidden;
            position: relative;
        }

        /* Ambient background glow */
        .glow {
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            pointer-events: none;
        }

        .container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 540px;
            text-align: center;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 48px 32px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .icon-box {
            width: 90px;
            height: 90px;
            background: rgba(249, 115, 22, 0.1);
            border: 1px solid rgba(249, 115, 22, 0.25);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            animation: pulse 2.5s infinite ease-in-out;
        }

        .icon-box i {
            font-size: 40px;
            color: var(--primary);
        }

        h1 {
            font-size: 1.85rem;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #ffffff 0%, #d1d5db 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p {
            font-size: 1.02rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 12px 28px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(249, 115, 22, 0.4);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.55);
            filter: brightness(1.1);
        }

        .btn:active {
            transform: translateY(0);
        }

        .footer-info {
            font-size: 0.85rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            padding-top: 24px;
            margin-top: 32px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: var(--primary);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px var(--primary);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.2);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 20px 4px rgba(249, 115, 22, 0.15);
            }
        }

        @media (max-width: 480px) {
            .card {
                padding: 36px 20px;
            }
            h1 {
                font-size: 1.5rem;
            }
            p {
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>
    <div class="glow"></div>
    <div class="container">
        <div class="card">
            <div class="icon-box">
                <i class="fas fa-store-slash"></i>
            </div>
            <h1>Store Service Offline</h1>
            <p>The agent storefront service is temporarily disabled. If you need to make purchases or access other platform services, please return to the main homepage.</p>
            
            <a href="<?php echo htmlspecialchars($site_url); ?>" class="btn">
                <i class="fas fa-arrow-left"></i>
                Go to Homepage
            </a>

            <div class="footer-info">
                <span class="status-dot"></span>
                <span><?php echo htmlspecialchars($site_name); ?> - Stores Offline</span>
            </div>
        </div>
    </div>
</body>
</html>
