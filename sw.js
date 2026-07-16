// Development Service Worker for Constechzhub
// ALWAYS bypasses caching in development mode to ensure changes are seen immediately

const CACHE_NAME = 'dev-cache-v1';

console.log('🚀 Development Service Worker Loading');

// Install event - skip waiting to activate immediately
self.addEventListener('install', event => {
    console.log('📦 Dev SW: Installing');
    self.skipWaiting();
});

// Activate event - clean up all caches to ensure no stale content
self.addEventListener('activate', event => {
    console.log('🔄 Dev SW: Activating and cleaning all caches');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('🧼 Dev SW: Final cleanup of cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            console.log('✅ Dev SW: Activation complete - taking control');
            // Take control of all clients immediately
            return self.clients.claim();
        })
    );
});

// Fetch event - Always fetch from network, never cache
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Skip Chrome extension requests
    if (url.protocol === 'chrome-extension:') {
        return;
    }
    
    console.log('🌐 Dev SW: Fetching fresh from network:', event.request.url);
    
    const isSameOrigin = url.origin === self.location.origin;

    if (isSameOrigin) {
        // Always fetch from network with aggressive cache-busting for same-origin requests
        event.respondWith(
            fetch(event.request, {
                cache: 'no-store',  // Force no caching
                headers: {
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                }
            }).catch(error => {
                console.log('❌ Dev SW: Network fetch failed:', error);
                // Return a user-friendly error response
                if (event.request.mode === 'navigate') {
                    return new Response(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Network Error - Development Mode</title>
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <style>
                                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f8f9fa; }
                                .error-container { background: white; padding: 40px; border-radius: 10px; border: 2px solid #dc3545; max-width: 500px; margin: 0 auto; }
                                .error-icon { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
                                h1 { color: #dc3545; margin-bottom: 10px; }
                                p { color: #6c757d; margin-bottom: 20px; }
                                .retry-btn { background: #007bff; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 16px; margin: 5px; }
                                .retry-btn:hover { background: #0056b3; }
                                .dev-info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin-top: 20px; font-size: 14px; color: #0066cc; }
                            </style>
                        </head>
                        <body>
                            <div class="error-container">
                                <div class="error-icon">⚠️</div>
                                <h1>Network Error</h1>
                                <p>Unable to load the page. Please check your connection and try again.</p>
                                <button class="retry-btn" onclick="location.reload()">Retry</button>
                                <button class="retry-btn" onclick="history.back()">Go Back</button>
                                <div class="dev-info">
                                    <strong>Development Mode:</strong> Cache disabled for fresh content
                                </div>
                            </div>
                        </body>
                        </html>
                    `, {
                        headers: { 'Content-Type': 'text/html' },
                        status: 503
                    });
                }
                return new Response('Network Error - Development Mode', { 
                    status: 503, 
                    statusText: 'Service Unavailable' 
                });
            })
        );
    } else {
        // Cross-origin request (e.g. Google Fonts): Fetch as-is to avoid CORS preflight errors
        event.respondWith(
            fetch(event.request).catch(error => {
                console.log('❌ Dev SW: External fetch failed:', error);
                return new Response('External Resource Error - Development Mode', { 
                    status: 503, 
                    statusText: 'Service Unavailable' 
                });
            })
        );
    }
});

// Message event - Allow manual cache operations
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        console.log('🗑️ Dev SW: Manual cache clear requested');
        event.waitUntil(
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => caches.delete(cacheName))
                );
            }).then(() => {
                console.log('✅ Dev SW: Manual cache clear completed');
                if (event.ports[0]) {
                    event.ports[0].postMessage({ success: true });
                }
            })
        );
    }
    
    if (event.data && event.data.type === 'GET_STATUS') {
        console.log('ℹ️ Dev SW: Status check requested');
        if (event.ports[0]) {
            event.ports[0].postMessage({ 
                mode: 'development',
                caching: false,
                timestamp: new Date().toISOString()
            });
        }
    }
});

console.log('🎯 Development Service Worker ready - All requests will be fresh from network');
