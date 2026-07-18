        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile menu toggle with overlay
function initMobileMenu() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const dashboardWrapper = document.querySelector('.dashboard-wrapper');
    
    if (!mobileToggle || !sidebar) return;
    
    // Create overlay element
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        dashboardWrapper.appendChild(overlay);
    }
    
    // Toggle mobile menu
    mobileToggle.addEventListener('click', function() {
        const isOpen = sidebar.classList.contains('show');
        
        if (isOpen) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        } else {
            sidebar.classList.add('show');
            overlay.classList.add('show');
        }
    });
    
    // Close menu when clicking overlay
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    });
    
    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        }
    });
}

// Initialize mobile menu on page load
document.addEventListener('DOMContentLoaded', initMobileMenu);

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
    const icons = document.querySelectorAll('#theme-icon, .theme-icon');
    const iconClass = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    icons.forEach(icon => {
        icon.className = iconClass + ' theme-icon';
    });
}

function toggleUserDropdown() {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    if (!dropdown || !toggle) return;
    
    const willShow = !dropdown.classList.contains('show');
    dropdown.classList.toggle('show', willShow);
    toggle.classList.toggle('open', willShow);
    toggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const toggle = document.querySelector('.user-dropdown-toggle');
    
    if (!dropdown || !toggle) return;
    
    if (!dropdown.contains(event.target) && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    }
});

function enhanceTopNavigation() {
    const themeIcon = document.getElementById('theme-icon');
    if (themeIcon) {
        themeIcon.classList.add('theme-icon');
    }

    const toggle = document.querySelector('.user-dropdown-toggle');
    if (!toggle) return;

    toggle.setAttribute('type', 'button');
    toggle.setAttribute('aria-haspopup', 'true');
    if (!toggle.hasAttribute('aria-expanded')) {
        toggle.setAttribute('aria-expanded', 'false');
    }

    const avatar = toggle.querySelector('.user-avatar');
    if (avatar && !avatar.style.boxShadow) {
        avatar.style.boxShadow = '0 6px 12px rgba(84, 19, 136, 0.25)';
    }

    let info = toggle.querySelector('.user-info');
    if (!info) {
        const candidates = Array.from(toggle.children).filter(child => child !== avatar && child.tagName === 'DIV');
        if (candidates.length) {
            info = candidates[0];
            info.classList.add('user-info');
        }
    }

    if (info) {
        const name = info.firstElementChild;
        const role = name ? name.nextElementSibling : null;
        if (name) {
            name.classList.add('user-name');
            name.removeAttribute('style');
        }
        if (role) {
            role.classList.add('user-role');
            role.removeAttribute('style');
        }
    }

    let arrowWrapper = toggle.querySelector('.dropdown-arrow');
    const chevron = toggle.querySelector('i.fas.fa-chevron-down');
    if (!arrowWrapper && chevron) {
        arrowWrapper = document.createElement('span');
        arrowWrapper.className = 'dropdown-arrow';
        chevron.replaceWith(arrowWrapper);
        arrowWrapper.appendChild(chevron);
    }

    const dropdown = document.getElementById('userDropdown');
    if (dropdown) {
        dropdown.setAttribute('role', 'menu');
        dropdown.querySelectorAll('hr').forEach(hr => {
            const divider = document.createElement('div');
            divider.className = 'dropdown-divider';
            hr.replaceWith(divider);
        });
    }
}

function positionProfitWithdrawalsNavItem() {
    const sidebarNav = document.querySelector('.sidebar .sidebar-nav');
    if (!sidebarNav) return;

    const systemResetLink = sidebarNav.querySelector('a[href*="system-reset.php"]');
    const profitWithdrawalsLink = sidebarNav.querySelector('a[href*="profit-withdrawals.php"]');
    if (!systemResetLink || !profitWithdrawalsLink || systemResetLink === profitWithdrawalsLink) {
        return;
    }

    const systemResetItem = systemResetLink.closest('.nav-item, li.nav-item');
    const profitWithdrawalsItem = profitWithdrawalsLink.closest('.nav-item, li.nav-item');
    if (!systemResetItem || !profitWithdrawalsItem || systemResetItem === profitWithdrawalsItem) {
        return;
    }

    const originalSection = profitWithdrawalsItem.closest('.nav-section');
    if (systemResetItem.nextElementSibling !== profitWithdrawalsItem) {
        systemResetItem.insertAdjacentElement('afterend', profitWithdrawalsItem);
    }

    if (originalSection) {
        const hasAnyItems = originalSection.querySelector('.nav-item, li.nav-item');
        if (!hasAnyItems) {
            originalSection.remove();
        }
    }
}

document.addEventListener('DOMContentLoaded', function(){ 
    initTheme(); 
    enhanceTopNavigation();
    positionProfitWithdrawalsNavItem();
});
</script>

<!-- PWA Installation Manager -->
<script src="<?php echo htmlspecialchars(dbh_asset('assets/js/pwa-install.js')); ?>""></script>
</body>
</html>
