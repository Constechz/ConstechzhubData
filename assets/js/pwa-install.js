// PWA Installation Manager
// Enhanced for Android and iOS compatibility
class PWAInstallManager {
    constructor() {
        this.deferredPrompt = null;
        this.isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        this.isAndroid = /Android/.test(navigator.userAgent);
        this.isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                          window.navigator.standalone === true;
        
        this.init();
    }
    
    init() {
        // Listen for the beforeinstallprompt event (Android)
        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('PWA: beforeinstallprompt fired');
            e.preventDefault();
            this.deferredPrompt = e;
            this.showInstallButton();
        });
        
        // Listen for app installation
        window.addEventListener('appinstalled', (e) => {
            console.log('PWA: App was installed');
            this.hideInstallButton();
            this.trackInstallation('success');
        });
        
        // Check if already installed
        if (this.isStandalone) {
            console.log('PWA: App is running in standalone mode');
            this.hideInstallButton();
        } else {
            // Show iOS install instructions if on iOS
            if (this.isIOS && !this.isStandalone) {
                this.showIOSInstructions();
            }
        }
        
        // Service worker registration
        this.registerServiceWorker();
    }
    
    async registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.register('/sw.js');
                console.log('PWA: ServiceWorker registered successfully:', registration.scope);
                
                // Check for updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            this.showUpdateNotification();
                        }
                    });
                });
            } catch (error) {
                console.log('PWA: ServiceWorker registration failed:', error);
            }
        }
    }
    
    showInstallButton() {
        let installButton = document.getElementById('pwa-install-button');
        
        if (!installButton) {
            installButton = this.createInstallButton();
            document.body.appendChild(installButton);
        }
        
        installButton.style.display = 'block';
        
        // Auto-show install prompt after 5 seconds on mobile
        if (this.isAndroid) {
            setTimeout(() => {
                if (!this.isStandalone && this.deferredPrompt) {
                    this.showInstallPrompt();
                }
            }, 5000);
        }
    }
    
    createInstallButton() {
        const button = document.createElement('button');
        button.id = 'pwa-install-button';
        button.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
            </svg>
            Install App
        `;
        button.className = 'pwa-install-btn';
        button.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #541388;
            color: #F1E9DA;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(84, 19, 136, 0.3);
            z-index: 1000;
            display: none;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-family: inherit;
        `;
        
        button.addEventListener('click', () => this.showInstallPrompt());
        button.addEventListener('mouseenter', () => {
            button.style.transform = 'translateY(-2px)';
            button.style.boxShadow = '0 6px 20px rgba(84, 19, 136, 0.4)';
        });
        button.addEventListener('mouseleave', () => {
            button.style.transform = 'translateY(0)';
            button.style.boxShadow = '0 4px 12px rgba(84, 19, 136, 0.3)';
        });
        
        return button;
    }
    
    async showInstallPrompt() {
        if (!this.deferredPrompt) {
            console.log('PWA: No install prompt available');
            return;
        }
        
        try {
            const result = await this.deferredPrompt.prompt();
            console.log('PWA: Install prompt result:', result.outcome);
            
            this.trackInstallation(result.outcome);
            
            if (result.outcome === 'accepted') {
                this.hideInstallButton();
            }
            
            this.deferredPrompt = null;
        } catch (error) {
            console.log('PWA: Install prompt error:', error);
        }
    }
    
    showIOSInstructions() {
        // Only show if not already shown in this session
        if (sessionStorage.getItem('ios-install-shown')) {
            return;
        }
        
        const isInBrowser = !this.isStandalone;
        if (!isInBrowser) return;
        
        setTimeout(() => {
            const modal = this.createIOSModal();
            document.body.appendChild(modal);
            modal.style.display = 'flex';
            sessionStorage.setItem('ios-install-shown', 'true');
        }, 3000);
    }
    
    createIOSModal() {
        const modal = document.createElement('div');
        modal.className = 'ios-install-modal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(46, 41, 78, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 20px;
        `;
        
        modal.innerHTML = `
            <div style="background: #F1E9DA; border-radius: 12px; padding: 24px; max-width: 350px; text-align: center;">
                <h3 style="margin: 0 0 16px 0; color: #2E294E;">Install Constechzhub</h3>
                <p style="color: #2E294E; margin: 0 0 20px 0; line-height: 1.4;">
                    To install this app on your iPhone, tap the Share button 
                    <svg width="16" height="16" style="vertical-align: middle; margin: 0 4px;" viewBox="0 0 24 24" fill="#541388">
                        <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.50-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92S19.61 16.08 18 16.08z"/>
                    </svg>
                    and then "Add to Home Screen"
                    <svg width="16" height="16" style="vertical-align: middle; margin: 0 4px;" viewBox="0 0 24 24" fill="#541388">
                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                    </svg>
                </p>
                <button onclick="this.parentElement.parentElement.remove()" 
                        style="background: #541388; color: #F1E9DA; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 500;">
                    Got it!
                </button>
            </div>
        `;
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
        
        return modal;
    }
    
    hideInstallButton() {
        const installButton = document.getElementById('pwa-install-button');
        if (installButton) {
            installButton.style.display = 'none';
        }
    }
    
    showUpdateNotification() {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2E294E;
            color: #F1E9DA;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(46, 41, 78, 0.15);
            z-index: 10000;
            max-width: 300px;
            font-size: 14px;
        `;
        
        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <div>App updated! Refresh to get the latest version.</div>
                <button onclick="window.location.reload()" 
                        style="background: none; border: 1px solid rgba(241, 233, 218, 0.5); color: #F1E9DA; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                    Refresh
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 10000);
    }
    
    trackInstallation(outcome) {
        // Track installation analytics
        if (typeof gtag !== 'undefined') {
            gtag('event', 'pwa_install', {
                'outcome': outcome,
                'platform': this.isIOS ? 'ios' : this.isAndroid ? 'android' : 'desktop'
            });
        }
        
        console.log('PWA: Installation tracked:', outcome);
    }
    
    // Public methods for manual control
    triggerInstall() {
        this.showInstallPrompt();
    }
    
    checkInstallability() {
        return {
            canInstall: !!this.deferredPrompt,
            isInstalled: this.isStandalone,
            platform: this.isIOS ? 'ios' : this.isAndroid ? 'android' : 'desktop'
        };
    }
}

// Initialize PWA Install Manager when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.pwaInstaller = new PWAInstallManager();
});

// Export for manual usage
window.PWAInstallManager = PWAInstallManager;

