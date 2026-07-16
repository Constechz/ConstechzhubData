/**
 * Icon Status Checker - Add this script to any dashboard page to monitor icon loading
 * Shows a small indicator in the corner showing icon status
 */

(function() {
    'use strict';
    
    // Create status indicator
    function createStatusIndicator() {
        const indicator = document.createElement('div');
        indicator.id = 'icon-status-indicator';
        indicator.style.cssText = `
            position: fixed;
            top: 10px;
            right: 10px;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-family: monospace;
            z-index: 10000;
            opacity: 0.8;
            cursor: pointer;
            transition: all 0.3s ease;
        `;
        indicator.textContent = 'Icons: Loading...';
        document.body.appendChild(indicator);
        
        // Click to toggle visibility
        let hidden = false;
        indicator.addEventListener('click', function() {
            hidden = !hidden;
            indicator.style.opacity = hidden ? '0.2' : '0.8';
        });
        
        return indicator;
    }
    
    // Update status
    function updateStatus(status, color) {
        const indicator = document.getElementById('icon-status-indicator');
        if (indicator) {
            indicator.textContent = `Icons: ${status}`;
            indicator.style.backgroundColor = color;
        }
    }
    
    // Test Font Awesome
    function testFontAwesome() {
        const testElement = document.createElement('i');
        testElement.className = 'fas fa-home';
        testElement.style.cssText = 'position: absolute; left: -9999px; font-size: 16px;';
        
        document.body.appendChild(testElement);
        
        setTimeout(() => {
            const computedStyle = window.getComputedStyle(testElement, ':before');
            const fontFamily = computedStyle.getPropertyValue('font-family');
            const content = computedStyle.getPropertyValue('content');
            
            document.body.removeChild(testElement);
            
            const isFontAwesome = fontFamily.toLowerCase().includes('font awesome') || 
                                 fontFamily.toLowerCase().includes('fontawesome') ||
                                 (content && content !== 'none' && content !== '\"\"');
            
            if (isFontAwesome) {
                updateStatus('Working', '#28a745');
            } else {
                // Check if we're in fallback mode
                if (document.body.classList.contains('fa-fallback')) {
                    updateStatus('Fallback Mode', '#ffc107');
                } else {
                    updateStatus('Failed', '#dc3545');
                }
            }
        }, 100);
    }
    
    // Initialize when DOM is ready
    function init() {
        createStatusIndicator();
        
        // Test immediately
        testFontAwesome();
        
        // Listen for Font Awesome events
        document.addEventListener('fontAwesomeLoaded', function() {
            updateStatus('CDN Loaded', '#28a745');
        });
        
        document.addEventListener('fontAwesomeFallback', function() {
            updateStatus('Fallback Mode', '#ffc107');
        });
        
        // Retest after 3 seconds
        setTimeout(testFontAwesome, 3000);
    }
    
    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Make globally available for manual testing
    window.iconStatusChecker = {
        test: testFontAwesome,
        updateStatus: updateStatus
    };
})();