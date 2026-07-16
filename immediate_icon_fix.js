(function () {
    'use strict';

    const markIconsReady = () => document.body.classList.add('fa-ready');
    const setupSidebarClose = () => {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) {
            return;
        }

        const brand = sidebar.querySelector('.sidebar-brand');
        if (!brand || brand.querySelector('.sidebar-close')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sidebar-close';
        button.setAttribute('aria-label', 'Close menu');
        button.innerHTML = '<i class="fas fa-times"></i>';
        brand.appendChild(button);

        const closeSidebar = () => {
            sidebar.classList.remove('show', 'active');
            document.body.classList.remove('sidebar-open');
            const overlays = document.querySelectorAll('.sidebar-overlay, .mobile-overlay');
            overlays.forEach((overlay) => {
                overlay.classList.remove('show', 'active');
                if (overlay.style && overlay.style.display === 'block') {
                    overlay.style.display = '';
                }
            });
        };

        button.addEventListener('click', closeSidebar);
    };

    if ('fonts' in document && typeof document.fonts.ready === 'object') {
        document.fonts.ready.then(markIconsReady).catch(markIconsReady);
    } else {
        window.addEventListener('load', markIconsReady);
    }

    // If the loader flags a fallback, ensure we still have a class for CSS hooks
    setTimeout(() => {
        if (!document.body.classList.contains('fa-ready')) {
            document.body.classList.add('fa-fallback');
        }
    }, 4000);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupSidebarClose);
    } else {
        setupSidebarClose();
    }
})();
