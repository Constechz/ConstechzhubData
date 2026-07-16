// Production Service Worker for Constechzhub
// Implements smart caching strategy for better performance while ensuring data freshness

const CACHE_NAME = 'data-bundle-hub-v1.3';
const STATIC_CACHE = 'static-assets-v1.3';
const DYNAMIC_CACHE = 'dynamic-content-v1.3';

// Assets to cache immediately
const STATIC_ASSETS = [
    '/assets/css/style.css',
    '/assets/css/dashboard.css',
    '/assets/css/icon-fixes.css',
    '/assets/vendor/fontawesome/css/all.min.css',
    '/assets/js/theme.js',
    '/assets/js/theme-fallback.js',
    '/assets/js/font-awesome-loader.js',
    '/assets/js/sw-manager.js',
    '/assets/js/pwa-install.js',
    '/manifest.php',
    '/assets/images/icon-152.png',
    '/assets/images/icon-192.png'
];

// Routes that should never be cached (always fresh from server)
const NEVER_CACHE = [
    '/admin/dashboard.php',
    '/agent/dashboard.php', 
    '/customer/dashboard.php',
    '/agent/mtn-business.php',
    '/agent/at-business.php',
    '/agent/telecel-business.php',
    '/customer/buy-data.php',
    '/api/',
    '/logout.php',
    '/clear-cache.php'
];

// Routes that can be cached for short periods
const SHORT_CACHE = [
    '/',
    '/index.php',
    '/login.php',
    '/register.php'
];

console.log('🚀 Production Service Worker Loading');

// Install event - cache static assets
self.addEventListener('install', event => {
    console.log('📦 SW: Installing and caching static assets');
    event.waitUntil(
        caches.open(STATIC_CACHE).then(cache => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => {
            console.log('✅ SW: Static assets cached successfully');
            self.skipWaiting();
        }).catch(error => {
            console.error('❌ SW: Failed to cache static assets:', error);
        })
    );
});

// Activate event - cleanup old caches
self.addEventListener('activate', event => {
    console.log('🔄 SW: Activating and cleaning up old caches');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== STATIC_CACHE && 
                        cacheName !== DYNAMIC_CACHE && 
                        cacheName !== CACHE_NAME) {
                        console.log('🗑️ SW: Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => {
            console.log('✅ SW: Cache cleanup complete');
            return self.clients.claim();
        })
    );
});

// Fetch event - smart caching strategy
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }
    
    // Skip external requests
    if (url.origin !== location.origin) {
        return;
    }
    
    const pathname = url.pathname;
    
    // Never cache certain routes - always fetch fresh
    if (NEVER_CACHE.some(route => pathname.includes(route))) {
        console.log('🌐 SW: Fetching fresh (never cache):', pathname);
        event.respondWith(
            fetch(event.request, {
                cache: 'no-store'
            }).catch(() => {
                // Return offline page for critical routes
                return new Response(
                    '<html><body><h1>Offline</h1><p>Please check your connection and try again.</p></body></html>',
                    { headers: { 'Content-Type': 'text/html' } }
                );
            })
        );
        return;
    }
    
    // Static assets - cache first, then network
    if (STATIC_ASSETS.some(asset => pathname.includes(asset))) {
        console.log('💾 SW: Cache first for static asset:', pathname);
        event.respondWith(
            caches.match(event.request).then(response => {
                return response || fetch(event.request).then(fetchResponse => {
                    return caches.open(STATIC_CACHE).then(cache => {
                        cache.put(event.request, fetchResponse.clone());
                        return fetchResponse;
                    });
                });
            })
        );
        return;
    }
    
    // Short cache routes - network first, fallback to cache
    if (SHORT_CACHE.some(route => pathname.includes(route))) {
        console.log('⚡ SW: Network first with short cache:', pathname);
        event.respondWith(
            fetch(event.request).then(response => {
                if (response.ok) {
                    caches.open(DYNAMIC_CACHE).then(cache => {
                        cache.put(event.request, response.clone());
                    });
                }
                return response;
            }).catch(() => {
                return caches.match(event.request);
            })
        );
        return;
    }
    
    // Default: network first
    console.log('🌐 SW: Network first (default):', pathname);
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});

// Message handling for cache management
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'CLEAR_CACHE') {
        console.log('🗑️ SW: Manual cache clear requested');
        event.waitUntil(
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => caches.delete(cacheName))
                );
            }).then(() => {
                console.log('✅ SW: All caches cleared');
                if (event.ports[0]) {
                    event.ports[0].postMessage({ success: true });
                }
            })
        );
    }
    
    if (event.data && event.data.type === 'GET_STATUS') {
        if (event.ports[0]) {
            event.ports[0].postMessage({
                mode: 'production',
                caching: true,
                timestamp: new Date().toISOString()
            });
        }
    }
});

console.log('✅ Production Service Worker ready with smart caching');
