// Development Service Worker - No Caching for Constechzhub
// This replaces the production service worker temporarily to solve cache issues
// Users will get fresh content every time without cache interference

console.log('Ã°Å¸Å¡â‚¬ Development Service Worker Active - No Caching Mode');

// Install event - Clear all existing caches immediately
self.addEventListener('install', event => {
    console.log('Ã°Å¸Â§Â¹ Dev SW: Installing - clearing all existing caches');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('Ã°Å¸â€”â€˜Ã¯Â¸Â Dev SW: Deleting cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            console.log('Ã¢Å“â€¦ Dev SW: All caches cleared successfully');
            // Force activation immediately
            self.skipWaiting();
        })
    );
});

// Activate event - Ensure complete cache cleanup and take control
self.addEventListener('activate', event => {
    console.log('Ã°Å¸â€â€ž Dev SW: Activating - final cache cleanup');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    console.log('Ã°Å¸Â§Â¼ Dev SW: Final cleanup of cache:', cacheName);
                    return caches.delete(cacheName);
                })
            );
        }).then(() => {
            console.log('Ã¢Å“â€¦ Dev SW: Activation complete - taking control');
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
    
    console.log('Ã°Å¸Å’Â Dev SW: Fetching fresh from network:', event.request.url);
    
    // Always fetch from network with aggressive cache-busting
    event.respondWith(
        fetch(event.request, {
            cache: 'no-store',  // Force no caching
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache'
            }
        }).catch(error => {
            console.log('Ã¢ÂÅ’ Dev SW: Network fetch failed:', error);
            // Return a user-friendly error response
            if (event.request.mode === 'navigate') {
                return new Response(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Network Error - Development Mode</title>
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <style>
                            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #F1E9DA; }
                            .error-container { background: #F1E9DA; padding: 40px; border-radius: 10px; border: 2px solid #D90368; max-width: 500px; margin: 0 auto; }
                            .error-icon { font-size: 48px; color: #D90368; margin-bottom: 20px; }
                            h1 { color: #D90368; margin-bottom: 10px; }
                            p { color: #541388; margin-bottom: 20px; }
                            .retry-btn { background: #541388; color: #F1E9DA; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 16px; margin: 5px; }
                            .retry-btn:hover { background: #541388; }
                            .dev-info { background: #F1E9DA; padding: 15px; border-radius: 5px; margin-top: 20px; font-size: 14px; color: #541388; }
                        </style>
                    </head>
                    <body>
                        <div class="error-container">
                            <div class="error-icon">Ã¢Å¡Â Ã¯Â¸Â</div>
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
});

// Message event - Allow manual cache operations
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        console.log('Ã°Å¸â€”â€˜Ã¯Â¸Â Dev SW: Manual cache clear requested');
        event.waitUntil(
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => caches.delete(cacheName))
                );
            }).then(() => {
                console.log('Ã¢Å“â€¦ Dev SW: Manual cache clear completed');
                if (event.ports[0]) {
                    event.ports[0].postMessage({ success: true });
                }
            })
        );
    }
    
    if (event.data && event.data.type === 'GET_STATUS') {
        console.log('Ã¢â€žÂ¹Ã¯Â¸Â Dev SW: Status check requested');
        if (event.ports[0]) {
            event.ports[0].postMessage({ 
                mode: 'development',
                caching: false,
                timestamp: new Date().toISOString()
            });
        }
    }
});

console.log('Ã°Å¸Å½Â¯ Development Service Worker ready - All requests will be fresh from network');
