<?php
require_once __DIR__ . '/config/config.php';

if (!isMaintenanceModeEnabled()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduled Maintenance - Constechzhub</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(255, 255, 255, 0.03);
            --border-color: rgba(255, 255, 255, 0.08);
            --primary: #E63B2C;
            --primary-glow: rgba(230, 59, 44, 0.15);
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
            width: 400px;
            height: 400px;
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
            width: 80px;
            height: 80px;
            background: rgba(230, 59, 44, 0.1);
            border: 1px solid rgba(230, 59, 44, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            animation: pulse 2s infinite ease-in-out;
        }

        .icon-box svg {
            width: 40px;
            height: 40px;
            color: var(--primary);
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #ffffff 0%, #a5a5a5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p {
            font-size: 1.05rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 32px;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            width: 35%;
            background: linear-gradient(90deg, var(--primary), #ff6b5c);
            border-radius: 10px;
            animation: progressAnim 3s infinite ease-in-out;
            position: absolute;
        }

        .footer-info {
            font-size: 0.85rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
            padding-top: 24px;
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
                box-shadow: 0 0 0 0 rgba(230, 59, 44, 0.2);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 20px 4px rgba(230, 59, 44, 0.15);
            }
        }

        @keyframes progressAnim {
            0% {
                left: -35%;
            }
            50% {
                left: 100%;
            }
            100% {
                left: 100%;
            }
        }

        @media (max-width: 480px) {
            .card {
                padding: 36px 20px;
            }
            h1 {
                font-size: 1.6rem;
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
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l-4.64 4.64A3.535 3.535 0 011 14.823l5.782-5.782m4.638 6.13l-4.638-4.638M12.75 3.75a.75.75 0 00-1.5 0v2.25H3.75a.75.75 0 000 1.5h7.5V11.25a.75.75 0 001.5 0v-3.75h7.5a.75.75 0 000-1.5h-7.5V3.75z" />
                </svg>
            </div>
            <h1>System Maintenance</h1>
            <p>We are currently updating our systems to improve performance and security. We apologize for the inconvenience and will be back online shortly.</p>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
            <div class="footer-info">
                <span class="status-dot"></span>
                <span>Constechzhub is temporarily offline</span>
            </div>
        </div>
    </div>
</body>
</html>
