// Data Bundle Hub - Theme Management
class ThemeManager {
    constructor() {
        this.init();
        this.bindEvents();
    }
    
    init() {
        // Get saved theme or use system preference
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        const theme = savedTheme || (prefersDark ? 'dark' : 'light');
        
        this.setTheme(theme);
        this.updateThemeIcon(theme);
    }
    
    setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
        
        // Update meta theme-color for mobile browsers
        const metaThemeColor = document.querySelector('meta[name="theme-color"]');
        if (metaThemeColor) {
            metaThemeColor.setAttribute('content', theme === 'dark' ? '#0B0017' : '#ffffff');
        }
        
        // Dispatch theme change event
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme } }));
    }
    
    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        this.setTheme(newTheme);
        this.updateThemeIcon(newTheme);
        
        // Add smooth transition effect
        document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';
        setTimeout(() => {
            document.body.style.transition = '';
        }, 300);
    }
    
    updateThemeIcon(theme) {
        const icons = document.querySelectorAll('#theme-icon, .theme-icon');
        const iconClass = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        icons.forEach(icon => {
            // Show moon icon for light theme (to switch TO dark)
            // Show sun icon for dark theme (to switch TO light)
            icon.className = iconClass + ' theme-icon';
        });
    }
    
    bindEvents() {
        // Listen for system theme changes
        const mediaQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        if (mediaQuery) {
            const handleChange = (e) => {
                if (!localStorage.getItem('theme')) {
                    this.setTheme(e.matches ? 'dark' : 'light');
                    this.updateThemeIcon(e.matches ? 'dark' : 'light');
                }
            };
            if (typeof mediaQuery.addEventListener === 'function') {
                mediaQuery.addEventListener('change', handleChange);
            } else if (typeof mediaQuery.addListener === 'function') {
                mediaQuery.addListener(handleChange);
            }
        }
        
        // Bind theme toggle buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.theme-toggle')) {
                e.preventDefault();
                this.toggleTheme();
            }
        });
    }
    
    getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme');
    }
}

// Initialize theme manager when DOM is loaded
function insertAgentSmsNavLink() {
    const path = window.location.pathname || '';
    if (!/\/agent\//.test(path)) {
        return;
    }

    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;

    const sections = sidebar.querySelectorAll('.nav-section');
    let settingsSection = null;

    sections.forEach(section => {
        const title = section.querySelector('.nav-section-title');
        if (title && title.textContent.trim().toLowerCase().includes('settings')) {
            settingsSection = section;
        }
    });

    if (!settingsSection) return;
    if (settingsSection.querySelector('[data-nav-item="agent-sms-broadcast"]')) return;

    const navItem = document.createElement('div');
    navItem.className = 'nav-item';
    navItem.setAttribute('data-nav-item', 'agent-sms-broadcast');

    const link = document.createElement('a');
    link.href = 'settings.php#sms-center';
    link.className = 'nav-link';
    link.innerHTML = '<i class="fas fa-bullhorn"></i> SMS Broadcast';

    const isSmsSection =
        (path.endsWith('/agent/settings.php') && window.location.hash === '#sms-center') ||
        (path.endsWith('/agent/settings.php') && window.location.search.includes('sms=1'));

    if (isSmsSection) {
        link.classList.add('active');
    }

    navItem.appendChild(link);
    settingsSection.appendChild(navItem);
}

function removeSystemResetSidebarLink() {
    try {
        const selectors = [
            '.sidebar a[href*="system-reset.php"]',
            '.sidebar a[href*="system-reset.php/"]',
            '.sidebar a[href*="/admin/system-reset.php"]'
        ];

        const links = document.querySelectorAll(selectors.join(','));
        links.forEach((link) => {
            const navItem = link.closest('.nav-item') || link.closest('li');
            if (navItem) {
                navItem.remove();
            } else {
                link.remove();
            }
        });
    } catch (err) {
        console.log('System reset link cleanup skipped:', err);
    }
}

function ensureThemeManager() {
    if (!window.themeManager) {
        window.themeManager = new ThemeManager();
    }
}

function initializeTheme() {
    ensureThemeManager();
}

document.addEventListener('DOMContentLoaded', () => {
    ensureThemeManager();
    insertAgentSmsNavLink();
    removeSystemResetSidebarLink();

    // Catch late-rendered sidebars/templates.
    setTimeout(removeSystemResetSidebarLink, 300);
    setTimeout(removeSystemResetSidebarLink, 1200);
});

// Global theme functions for backward compatibility
function toggleTheme() {
    ensureThemeManager();
    if (window.themeManager) {
        window.themeManager.toggleTheme();
    }
}

function initTheme() {
    ensureThemeManager();
}

function updateThemeIcon(theme) {
    ensureThemeManager();
    if (window.themeManager) {
        window.themeManager.updateThemeIcon(theme);
    }
}
