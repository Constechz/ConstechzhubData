<?php
// Prevent direct access
if (!defined('SITE_URL')) {
    exit();
}

if (!isset($agent_support_phone)) {
    $agent_support_phone = trim((string) ($store['agent_phone'] ?? ''));
}
if (!isset($agent_support_tel)) {
    $agent_support_tel = preg_replace('/\D+/', '', $agent_support_phone);
    if ($agent_support_tel !== '' && strpos($agent_support_tel, '0') === 0) {
        $agent_support_tel = '233' . substr($agent_support_tel, 1);
    }
}
if (!isset($agent_email)) {
    $agent_email = trim((string) ($store['agent_email'] ?? ''));
}
?>
    <!-- Store Footer -->
    <footer class="store-footer">
        <div class="container">
            <div class="footer-content">
                <div class="store-contact">
                    <span class="footer-brand-mark">
                        <i class="fas fa-store"></i>
                    </span>
                    <div class="footer-brand-copy">
                        <h4><?php echo htmlspecialchars($store['store_name'] ?? ''); ?></h4>
                        <div class="footer-contact-links">
                            <?php if ($agent_support_phone !== ''): ?>
                                <a href="tel:<?php echo htmlspecialchars($agent_support_tel); ?>">
                                    <i class="fas fa-phone"></i>
                                    Support: <?php echo htmlspecialchars($agent_support_phone); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($agent_email !== ''): ?>
                                <a href="mailto:<?php echo htmlspecialchars($agent_email); ?>">
                                    <i class="fas fa-envelope"></i>
                                    <?php echo htmlspecialchars($agent_email); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="powered-by">
                    <span>Powered by</span>
                    <strong><?php echo htmlspecialchars(getSiteName()); ?></strong>
                </div>
            </div>
            
            <div class="footer-divider">
                <p>
                    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($store['store_name']); ?>. All rights reserved.
                </p>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            const themeToggle = document.getElementById('storeThemeToggle');
            const themeIcon = document.getElementById('storeThemeIcon');
            const themeText = document.getElementById('storeThemeText');

            function getPreferredTheme() {
                try {
                    const saved = localStorage.getItem('theme');
                    if (saved === 'dark' || saved === 'light') {
                        return saved;
                    }
                } catch (e) {}

                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    ? 'dark'
                    : 'light';
            }

            function applyStoreTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                if (themeIcon) {
                    themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                }
                if (themeText) {
                    themeText.textContent = theme === 'dark' ? 'Light' : 'Dark';
                }
                if (themeToggle) {
                    themeToggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
                }
            }

            applyStoreTheme(getPreferredTheme());

            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    const next = current === 'dark' ? 'light' : 'dark';
                    try {
                        localStorage.setItem('theme', next);
                    } catch (e) {}
                    applyStoreTheme(next);
                });
            }
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const toggle = document.querySelector('.nav-menu-toggle');
            const navActions = document.getElementById('storeNavActions');
            if (!toggle || !navActions) return;

            const syncMobileMenuState = function () {
                const isMobile = window.innerWidth <= 720;
                if (!isMobile && navActions.classList.contains('is-open')) {
                    navActions.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            };

            const closeMenu = function () {
                navActions.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            };

            toggle.addEventListener('click', function () {
                if (window.innerWidth > 720) {
                    return;
                }

                const nextOpen = !navActions.classList.contains('is-open');
                navActions.classList.toggle('is-open', nextOpen);
                toggle.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
            });

            navActions.querySelectorAll('.store-quick-link').forEach(function (link) {
                link.addEventListener('click', closeMenu);
            });

            window.addEventListener('resize', syncMobileMenuState);
            syncMobileMenuState();
        });
    </script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/store-custom.css')); ?>">
    <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/notifications.js')); ?>"></script>
</body>
</html>
