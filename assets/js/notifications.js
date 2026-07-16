// Enhanced Notification Slider JavaScript - Mobile Optimized
let currentNotificationIndex = 0;
let notificationInterval = null;
let totalNotifications = 0;
let isTransitioning = false;

// Initialize notification slider
function initNotificationSlider() {
    const slider = document.getElementById('notificationSlider');
    if (!slider) return;
    
    const slides = slider.querySelectorAll('.notification-slide');
    totalNotifications = slides.length;
    
    if (totalNotifications <= 1) return;
    
    // Auto-advance notifications every 6 seconds (slightly longer for better UX)
    startNotificationAutoAdvance();
    
    // Pause on hover/touch
    slider.addEventListener('mouseenter', stopNotificationAutoAdvance);
    slider.addEventListener('mouseleave', startNotificationAutoAdvance);
    
    // Touch events for mobile
    slider.addEventListener('touchstart', stopNotificationAutoAdvance);
    slider.addEventListener('touchend', () => {
        setTimeout(startNotificationAutoAdvance, 2000); // Resume after 2 seconds
    });
}

// Start auto-advance timer
function startNotificationAutoAdvance() {
    if (totalNotifications <= 1) return;
    
    stopNotificationAutoAdvance(); // Clear any existing interval
    notificationInterval = setInterval(() => {
        nextNotification();
    }, 6000); // 6 seconds for better mobile experience
}

// Stop auto-advance timer
function stopNotificationAutoAdvance() {
    if (notificationInterval) {
        clearInterval(notificationInterval);
        notificationInterval = null;
    }
}

// Go to next notification (smooth transition)
function nextNotification() {
    if (totalNotifications <= 1 || isTransitioning) return;
    
    currentNotificationIndex = (currentNotificationIndex + 1) % totalNotifications;
    showNotification(currentNotificationIndex);
}

// Remove previous notification function (no longer needed without arrows)

// Go to specific notification (enhanced with smooth transitions)
function goToNotification(index) {
    if (index < 0 || index >= totalNotifications || isTransitioning) return;
    
    currentNotificationIndex = index;
    showNotification(currentNotificationIndex);
    
    // Restart auto-advance timer
    startNotificationAutoAdvance();
}

// Enhanced show notification with smooth transitions
function showNotification(index) {
    const slider = document.getElementById('notificationSlider');
    if (!slider || isTransitioning) return;
    
    isTransitioning = true;
    
    const slides = slider.querySelectorAll('.notification-slide');
    const dots = slider.querySelectorAll('.notification-dot');
    const currentSlide = slides[currentNotificationIndex];
    const nextSlide = slides[index];
    
    // Smooth transition without interface shaking
    slides.forEach((slide, i) => {
        if (i === index) {
            slide.style.display = 'block';
            slide.style.opacity = '0';
            
            // Smooth fade in
            requestAnimationFrame(() => {
                slide.style.transition = 'opacity 0.4s ease-in-out';
                slide.style.opacity = '1';
                slide.classList.add('active');
            });
        } else {
            slide.classList.remove('active');
            slide.style.opacity = '0';
            
            setTimeout(() => {
                if (!slide.classList.contains('active')) {
                    slide.style.display = 'none';
                }
            }, 400);
        }
    });
    
    // Update indicators
    dots.forEach((dot, i) => {
        dot.classList.toggle('active', i === index);
    });
    
    // Reset transition flag
    setTimeout(() => {
        isTransitioning = false;
    }, 500);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initNotificationSlider();
});

// Re-initialize if content is dynamically loaded
function reinitNotificationSlider() {
    stopNotificationAutoAdvance();
    currentNotificationIndex = 0;
    initNotificationSlider();
}