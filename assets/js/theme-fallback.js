// Theme fallback for pages that do not load ThemeManager or inline handlers.
(function() {
  function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try {
      localStorage.setItem('theme', theme);
    } catch (err) {
      // Ignore storage errors (private mode, blocked storage, etc.)
    }
    updateIcon(theme);
  }

  function getInitialTheme() {
    let savedTheme = null;
    try {
      savedTheme = localStorage.getItem('theme');
    } catch (err) {
      savedTheme = null;
    }
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    return savedTheme || (prefersDark ? 'dark' : 'light');
  }

  function updateIcon(theme) {
    const icon = document.getElementById('theme-icon');
    if (icon) {
      icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
  }

  function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
    const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
    setTheme(nextTheme);
  }

  function bindToggle() {
    const toggle = document.querySelector('.theme-toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      if (window.themeManager && typeof window.themeManager.toggleTheme === 'function') {
        window.themeManager.toggleTheme();
        return;
      }
      toggleTheme();
    });
  }

  document.addEventListener('DOMContentLoaded', function() {
    if (!window.themeManager) {
      setTheme(getInitialTheme());
    } else if (typeof window.themeManager.updateThemeIcon === 'function') {
      const current = window.themeManager.getCurrentTheme ? window.themeManager.getCurrentTheme() : getInitialTheme();
      window.themeManager.updateThemeIcon(current);
    }
    bindToggle();
  });
})();
