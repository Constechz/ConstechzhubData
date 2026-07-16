<?php
require_once '../config/config.php';

requireRole('agent');

$page_csrf_token = generateCSRF();
$current_user = getCurrentUser();
$display_name = trim((string) ($current_user['full_name'] ?? ($_SESSION['username'] ?? 'Agent')));
if ($display_name === '') {
    $display_name = 'Agent';
}
$avatar_initial = strtoupper(substr($display_name, 0, 1));
$profile_image = trim((string) ($current_user['profile_image'] ?? ''));
$profile_image_url = '';
if ($profile_image !== '') {
    $profile_image_url = preg_match('/^https?:\/\//i', $profile_image)
        ? $profile_image
        : dbh_asset($profile_image);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paystack Order Recovery - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
    <style>
        .recovery-page .recovery-card {
            width: min(100%, 760px);
            max-width: 760px;
        }

        .recovery-page .recovery-steps {
            margin: 0;
            padding-left: 1.2rem;
            color: var(--text-secondary);
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        .recovery-page .recovery-result {
            display: none;
            margin-top: 1rem;
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .recovery-page .recovery-result.is-visible {
            display: block;
        }

        .recovery-page .result-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 0.85rem;
        }

        .recovery-page .dashboard-header {
            min-width: 0;
        }

        .recovery-page .header-left,
        .recovery-page .header-actions {
            min-width: 0;
        }

        .recovery-page .user-dropdown-toggle {
            min-width: 0;
            max-width: min(230px, 46vw);
        }

        .recovery-page .user-info {
            min-width: 0;
        }

        .recovery-page .user-name,
        .recovery-page .user-role {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .recovery-page .user-avatar,
        .recovery-page .user-avatar img,
        .recovery-page .theme-toggle img,
        .recovery-page .mobile-menu-toggle img {
            max-width: 100%;
            max-height: 100%;
        }

        .recovery-page .widget-header,
        .recovery-page .widget-content {
            min-width: 0;
        }

        .recovery-page .widget-content {
            padding: var(--spacing-lg);
        }

        .recovery-page .form-actions {
            flex-wrap: wrap;
        }

        .recovery-page code {
            white-space: normal;
            overflow-wrap: anywhere;
        }

        @media (max-width: 980px) {
            .recovery-page .dashboard-header {
                gap: var(--spacing-sm);
                padding-left: var(--spacing-md);
                padding-right: var(--spacing-md);
            }

            .recovery-page .breadcrumb-item:not(.active) {
                display: none;
            }

            .recovery-page .breadcrumb-item.active::before {
                display: none;
            }

            .recovery-page .header-actions {
                gap: var(--spacing-sm);
                margin-right: 0;
            }

            .recovery-page .theme-toggle {
                width: 42px;
                height: 42px;
                min-width: 42px;
                min-height: 42px;
            }

            .recovery-page .user-dropdown-toggle {
                max-width: min(210px, 52vw);
                padding: 0.45rem 0.75rem;
                gap: 0.6rem;
            }
        }

        @media (max-width: 768px) {
            .recovery-page .dashboard-content {
                padding: var(--spacing-md);
            }

            .recovery-page .page-title {
                margin-bottom: var(--spacing-lg);
            }

            .recovery-page .page-title h1 {
                font-size: 1.5rem;
                line-height: 1.2;
            }

            .recovery-page .page-subtitle,
            .recovery-page .widget-subtitle,
            .recovery-page .form-text {
                font-size: 0.9rem;
                line-height: 1.45;
            }

            .recovery-page .widget-header,
            .recovery-page .widget-content {
                padding: var(--spacing-md);
            }

            .recovery-page .form-actions,
            .recovery-page .result-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .recovery-page .form-actions .btn,
            .recovery-page .result-actions .btn {
                width: 100%;
                justify-content: center;
                white-space: normal;
            }

            .recovery-page .user-dropdown-toggle {
                width: 44px;
                min-width: 44px;
                max-width: 44px;
                height: 44px;
                padding: 0;
                justify-content: center;
                overflow: hidden;
            }

            .recovery-page .user-dropdown-toggle .user-info,
            .recovery-page .user-dropdown-toggle .dropdown-arrow {
                display: none !important;
            }

            .recovery-page .user-avatar {
                width: 34px;
                height: 34px;
            }
        }

        @media (max-width: 480px) {
            .recovery-page .dashboard-content {
                padding: var(--spacing-sm);
            }

            .recovery-page .recovery-steps {
                padding-left: 1rem;
                font-size: 0.92rem;
                line-height: 1.55;
            }

            .recovery-page .widget-header,
            .recovery-page .widget-content {
                padding: var(--spacing-sm);
            }

            .recovery-page .form-control {
                font-size: 1rem;
            }

            .recovery-page .dashboard-header {
                min-height: 64px;
                padding-left: var(--spacing-sm);
                padding-right: var(--spacing-sm);
                gap: var(--spacing-xs);
            }

            .recovery-page .theme-toggle,
            .recovery-page .user-dropdown-toggle {
                width: 40px;
                min-width: 40px;
                height: 40px;
                min-height: 40px;
            }

            .recovery-page .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body class="recovery-page">
<div class="dashboard-wrapper">
    <nav class="sidebar">
        <div class="sidebar-brand">
            <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
        </div>
        <?php renderAgentSidebar(); ?>
    </nav>

    <main class="main-content">
        <header class="dashboard-header">
            <div class="header-left">
                <button class="mobile-menu-toggle" type="button" aria-label="Toggle navigation menu"><i class="fas fa-bars"></i></button>
                <nav class="breadcrumb">
                    <div class="breadcrumb-item"><i class="fas fa-credit-card"></i></div>
                    <div class="breadcrumb-item">Transactions</div>
                    <div class="breadcrumb-item active">Paystack Recovery</div>
                </nav>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode">
                    <i class="fas fa-sun" id="theme-icon"></i>
                </button>
                <div class="user-dropdown">
                    <button class="user-dropdown-toggle" type="button" onclick="toggleUserDropdown()" aria-haspopup="true" aria-expanded="false">
                        <div class="user-avatar">
                            <?php if ($profile_image_url !== ''): ?>
                                <img src="<?php echo htmlspecialchars($profile_image_url); ?>" alt="<?php echo htmlspecialchars($display_name); ?>">
                            <?php else: ?>
                                <?php echo htmlspecialchars($avatar_initial); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                            <div class="user-role">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down dropdown-arrow" style="margin-left: 0.5rem;"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                        <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="dashboard-content">
            <div class="page-title">
                <h1>Paystack Order Recovery</h1>
                <p class="page-subtitle">Recover a paid data order when Paystack deducted money but your bundle order was not submitted.</p>
            </div>

            <div class="widget recovery-card">
                <div class="widget-header">
                    <div>
                        <h3 class="widget-title">Recover Missing Order</h3>
                        <p class="widget-subtitle">Paste the Paystack reference from your receipt or Paystack dashboard.</p>
                    </div>
                </div>
                <div class="widget-content">
                    <ol class="recovery-steps">
                        <li>The system verifies the reference directly with Paystack.</li>
                        <li>If Paystack confirms success, it checks whether the order already exists.</li>
                        <li>If missing, it creates and submits the bundle order for you, then links it to the transaction.</li>
                    </ol>

                    <form id="recoveryForm" class="form" style="margin-top: 1rem;">
                        <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($page_csrf_token); ?>">
                        <div class="form-group">
                            <label class="form-label" for="reference">Paystack Reference</label>
                            <input type="text" id="reference" class="form-control" placeholder="PAY_..." autocomplete="off" required>
                            <small class="form-text">Supports Paystack data bundle purchases generated by your dashboard.</small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" id="recoverBtn" class="btn btn-primary">
                                <i class="fas fa-rotate"></i> Verify and Recover
                            </button>
                            <a class="btn btn-secondary" href="transactions.php?search=PAY_">
                                <i class="fas fa-history"></i> Open Transactions
                            </a>
                        </div>
                    </form>

                    <div id="recoveryResult" class="alert recovery-result" role="alert"></div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    function updateRecoveryThemeIcon(theme) {
        const icon = document.getElementById('theme-icon');
        if (!icon) {
            return;
        }
        icon.className = (theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon') + ' theme-icon';
    }

    function initRecoveryTheme() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        updateRecoveryThemeIcon(theme);
    }

    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateRecoveryThemeIcon(newTheme);
    }

    function toggleUserDropdown() {
        const dropdown = document.getElementById('userDropdown');
        const toggle = document.querySelector('.user-dropdown-toggle');
        if (!dropdown || !toggle) {
            return;
        }

        const willShow = !dropdown.classList.contains('show');
        dropdown.classList.toggle('show', willShow);
        toggle.classList.toggle('open', willShow);
        toggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
    }

    function initRecoveryHeaderActions() {
        const menuToggle = document.querySelector('.mobile-menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        const wrapper = document.querySelector('.dashboard-wrapper');

        if (menuToggle && sidebar && wrapper) {
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                wrapper.appendChild(overlay);
            }

            menuToggle.addEventListener('click', function () {
                const shouldOpen = !sidebar.classList.contains('show') && !sidebar.classList.contains('active');
                sidebar.classList.toggle('show', shouldOpen);
                sidebar.classList.toggle('active', shouldOpen);
                overlay.classList.toggle('show', shouldOpen);
            });

            overlay.addEventListener('click', function () {
                sidebar.classList.remove('show', 'active');
                overlay.classList.remove('show');
            });
        }

        document.addEventListener('click', function (event) {
            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            if (!dropdown || !toggle) {
                return;
            }

            if (!dropdown.contains(event.target) && !toggle.contains(event.target)) {
                dropdown.classList.remove('show');
                toggle.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            const dropdown = document.getElementById('userDropdown');
            const toggle = document.querySelector('.user-dropdown-toggle');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');

            if (dropdown && toggle) {
                dropdown.classList.remove('show');
                toggle.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            }

            if (sidebar) {
                sidebar.classList.remove('show', 'active');
            }
            if (overlay) {
                overlay.classList.remove('show');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initRecoveryTheme();
        initRecoveryHeaderActions();
    });

    (function () {
        const form = document.getElementById('recoveryForm');
        const btn = document.getElementById('recoverBtn');
        const refInput = document.getElementById('reference');
        const result = document.getElementById('recoveryResult');
        const csrf = document.getElementById('csrfToken').value;

        function showResult(type, message, redirectPath) {
            result.className = 'alert recovery-result is-visible alert-' + type;
            const safeMessage = String(message || '').replace(/[&<>"']/g, function (char) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
            });
            let html = safeMessage;
            if (redirectPath) {
                html += '<div class="result-actions">'
                    + '<a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars(rtrim((string) SITE_URL, '/')); ?>' + encodeURI(String(redirectPath)) + '">'
                    + '<i class="fas fa-arrow-up-right-from-square"></i> Open Status</a>'
                    + '<a class="btn btn-sm btn-secondary" href="transactions.php?search=' + encodeURIComponent(refInput.value.trim()) + '">'
                    + '<i class="fas fa-search"></i> View Transaction</a>'
                    + '</div>';
            }
            result.innerHTML = html;
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const reference = refInput.value.trim();
            if (!reference) {
                showResult('danger', 'Enter a Paystack reference first.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Verifying...';
            result.className = 'alert recovery-result';
            result.textContent = '';

            try {
                const response = await fetch('../api/recover_guest_paystack_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({reference: reference})
                });
                const data = await response.json().catch(function () { return null; });
                if (!response.ok || !data) {
                    throw new Error(data && data.message ? data.message : 'Recovery request failed.');
                }

                const status = String(data.status || '').toLowerCase();
                const txStatus = String(data.transaction_status || '').toLowerCase();
                const type = status === 'success' && txStatus !== 'failed' ? 'success' : (txStatus === 'pending' ? 'warning' : 'danger');
                showResult(type, data.message || 'Recovery completed.', data.redirect_path || '');
            } catch (error) {
                showResult('danger', error && error.message ? error.message : 'Unable to recover this payment right now.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-rotate"></i> Verify and Recover';
            }
        });
    })();
</script>
</body>
</html>
