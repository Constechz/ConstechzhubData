// Service Worker Manager - Environment-based registration
// Automatically registers the appropriate service worker based on environment

(function() {
    'use strict';
    
    if (!('serviceWorker' in navigator)) {
        console.log('Service Worker not supported');
        return;
    }
    
    // Environment detection
    const isProduction = !(
        location.hostname === 'localhost' ||
        location.hostname === '127.0.0.1' ||
        location.hostname === '' ||
        location.port === '3000' ||
        location.port === '8080'
    );
    
    let basePath = '';
    const pathParts = window.location.pathname.split('/').filter(p => p !== '');
    if (pathParts.length > 0) {
        const firstSegment = pathParts[0];
        if (!firstSegment.endsWith('.php') && !['admin', 'agent', 'customer', 'vip'].includes(firstSegment)) {
            basePath = '/' + firstSegment;
        }
    }
    const swPath = basePath + (isProduction ? '/sw-production.js' : '/sw.js');
    const environment = isProduction ? 'production' : 'development';
    
    console.log(`🔧 SW Manager: Detected ${environment} environment`);
    console.log(`📋 SW Manager: Registering service worker: ${swPath}`);
    
    // Register appropriate service worker
    navigator.serviceWorker.register(swPath)
        .then(registration => {
            console.log(`✅ SW Manager: Service worker registered successfully (${environment})`);
            
            // Handle updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                console.log('🔄 SW Manager: New service worker installing');
                
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        console.log('🆕 SW Manager: New service worker installed, update available');
                        
                        // Show update notification (optional)
                        if (window.showUpdateNotification) {
                            window.showUpdateNotification();
                        }
                    }
                });
            });
        })
        .catch(error => {
            console.error('❌ SW Manager: Service worker registration failed:', error);
        });
    
    // Clean up old service workers if switching environments
    navigator.serviceWorker.getRegistrations().then(registrations => {
        registrations.forEach(registration => {
            if (registration.active && 
                registration.active.scriptURL !== location.origin + swPath) {
                console.log('🧹 SW Manager: Unregistering old service worker:', registration.active.scriptURL);
                registration.unregister();
            }
        });
    });
    
    // Global cache management functions
    window.clearServiceWorkerCache = function() {
        return navigator.serviceWorker.ready.then(registration => {
            if (registration.active) {
                const messageChannel = new MessageChannel();
                
                return new Promise((resolve, reject) => {
                    messageChannel.port1.onmessage = function(event) {
                        if (event.data && event.data.success) {
                            resolve();
                        } else {
                            reject(new Error('Cache clear failed'));
                        }
                    };
                    
                    registration.active.postMessage({
                        type: 'CLEAR_CACHE'
                    }, [messageChannel.port2]);
                });
            }
        });
    };
    
    window.getServiceWorkerStatus = function() {
        return navigator.serviceWorker.ready.then(registration => {
            if (registration.active) {
                const messageChannel = new MessageChannel();
                
                return new Promise((resolve) => {
                    messageChannel.port1.onmessage = function(event) {
                        resolve(event.data);
                    };
                    
                    registration.active.postMessage({
                        type: 'GET_STATUS'
                    }, [messageChannel.port2]);
                });
            }
        });
    };
    
    // Auto-refresh on SW updates (production only)
    if (isProduction) {
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            console.log('🔄 SW Manager: Service worker updated, refreshing page');
            window.location.reload();
        });
    }
    
    console.log(`🎯 SW Manager: Ready (${environment} mode)`);
})();