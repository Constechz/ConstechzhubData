/**
 * Data Bundle Hub - Mobile Enhancement Utilities
 * Enhanced mobile responsiveness features
 */

class MobileEnhancements {
    constructor() {
        this.init();
    }

    init() {
        this.initMobileTableEnhancements();
        this.initTouchFeedback();
        this.initMobileSidebarEnhancements();
    }

    /**
     * Initialize mobile table enhancements
     */
    initMobileTableEnhancements() {
        // Add mobile table scroll indicators
        const tables = document.querySelectorAll('.table-responsive');
        tables.forEach(table => {
            this.addScrollIndicators(table);
        });

        // Add mobile-friendly table alternative for small screens
        this.createMobileTableViews();
    }

    /**
     * Add scroll indicators for mobile tables
     */
    addScrollIndicators(tableContainer) {
        const table = tableContainer.querySelector('table');
        if (!table) return;

        const leftIndicator = document.createElement('div');
        leftIndicator.className = 'table-scroll-indicator left';
        leftIndicator.innerHTML = '<i class="fas fa-chevron-left"></i>';

        const rightIndicator = document.createElement('div');
        rightIndicator.className = 'table-scroll-indicator right';
        rightIndicator.innerHTML = '<i class="fas fa-chevron-right"></i>';

        tableContainer.appendChild(leftIndicator);
        tableContainer.appendChild(rightIndicator);

        // Show/hide indicators based on scroll position
        tableContainer.addEventListener('scroll', () => {
            const scrollLeft = tableContainer.scrollLeft;
            const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;

            leftIndicator.style.opacity = scrollLeft > 0 ? '1' : '0';
            rightIndicator.style.opacity = scrollLeft < maxScroll ? '1' : '0';
        });

        // Initial state
        const maxScroll = tableContainer.scrollWidth - tableContainer.clientWidth;
        rightIndicator.style.opacity = maxScroll > 0 ? '1' : '0';
    }

    /**
     * Create mobile-friendly card views for tables
     */
    createMobileTableViews() {
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            if (table.classList.contains('mobile-cards-enabled')) {
                this.createMobileCardView(table);
            }
        });
    }

    /**
     * Create mobile card view for a specific table
     */
    createMobileCardView(table) {
        const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
        const rows = table.querySelectorAll('tbody tr');

        const mobileContainer = document.createElement('div');
        mobileContainer.className = 'table-mobile-cards d-md-none';

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const card = document.createElement('div');
            card.className = 'mobile-card';

            // Add card header if first cell has important data
            if (cells.length > 0) {
                const header = document.createElement('div');
                header.className = 'mobile-card-header';
                header.textContent = cells[0].textContent.trim();
                card.appendChild(header);
            }

            // Add card rows for each data cell
            cells.forEach((cell, index) => {
                if (index === 0) return; // Skip first cell as it's used in header

                const cardRow = document.createElement('div');
                cardRow.className = 'mobile-card-row';

                const label = document.createElement('div');
                label.className = 'mobile-card-label';
                label.textContent = headers[index] || `Field ${index}`;

                const value = document.createElement('div');
                value.className = 'mobile-card-value';
                value.innerHTML = cell.innerHTML;

                cardRow.appendChild(label);
                cardRow.appendChild(value);
                card.appendChild(cardRow);
            });

            mobileContainer.appendChild(card);
        });

        table.parentNode.insertBefore(mobileContainer, table.nextSibling);
    }

    /**
     * Initialize touch feedback for interactive elements
     */
    initTouchFeedback() {
        // Add touch feedback for buttons and interactive elements
        const interactiveElements = document.querySelectorAll('.btn, .nav-link, .dropdown-item, .stat-card');
        
        interactiveElements.forEach(element => {
            element.addEventListener('touchstart', () => {
                element.style.transform = 'scale(0.98)';
            });

            element.addEventListener('touchend', () => {
                setTimeout(() => {
                    element.style.transform = '';
                }, 150);
            });

            element.addEventListener('touchcancel', () => {
                element.style.transform = '';
            });
        });
    }

    /**
     * Enhanced mobile sidebar functionality
     */
    initMobileSidebarEnhancements() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        
        if (!sidebar) return;

        // Add swipe to close functionality
        let startX = 0;
        let currentX = 0;
        let isDragging = false;

        sidebar.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            isDragging = true;
        });

        sidebar.addEventListener('touchmove', (e) => {
            if (!isDragging) return;
            currentX = e.touches[0].clientX;
            const deltaX = currentX - startX;

            // Only allow swiping left to close
            if (deltaX < 0) {
                const translateX = Math.max(deltaX, -280);
                sidebar.style.transform = `translateX(${translateX}px)`;
            }
        });

        sidebar.addEventListener('touchend', () => {
            if (!isDragging) return;
            isDragging = false;

            const deltaX = currentX - startX;
            
            // If swiped more than 100px left, close the sidebar
            if (deltaX < -100) {
                sidebar.classList.remove('show');
                if (overlay) overlay.classList.remove('show');
            }

            // Reset transform
            sidebar.style.transform = '';
        });

        // Prevent body scroll when sidebar is open on mobile
        const toggleBodyScroll = (disable) => {
            if (window.innerWidth <= 768) {
                document.body.style.overflow = disable ? 'hidden' : '';
            }
        };

        // Watch for sidebar open/close
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.target.classList.contains('show')) {
                    toggleBodyScroll(true);
                } else {
                    toggleBodyScroll(false);
                }
            });
        });

        observer.observe(sidebar, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    /**
     * Utility method to check if device is mobile
     */
    static isMobile() {
        return window.innerWidth <= 768;
    }

    /**
     * Utility method to check if device is touch-enabled
     */
    static isTouchDevice() {
        return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.mobileEnhancements = new MobileEnhancements();
});

// Add CSS for scroll indicators and mobile enhancements
const style = document.createElement('style');
style.textContent = `
    .table-scroll-indicator {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 0.5rem;
        border-radius: 50%;
        font-size: 0.8rem;
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
        z-index: 10;
    }
    
    .table-scroll-indicator.left {
        left: 0.5rem;
    }
    
    .table-scroll-indicator.right {
        right: 0.5rem;
    }
    
    @media (max-width: 768px) {
        .table-responsive {
            position: relative;
        }
        
        .table-scroll-indicator {
            display: block;
        }
    }
    
    @media (min-width: 769px) {
        .table-scroll-indicator {
            display: none;
        }
    }
`;
document.head.appendChild(style);