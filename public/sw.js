const STATIC_CACHE = 'checkout-static-v1';
const RUNTIME_CACHE = 'checkout-runtime-v1';
const CDN_CACHE = 'checkout-cdn-v1';

const PRECACHE_URLS = [
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/apple-touch-icon.png'
];

const SENSITIVE_PREFIXES = [
    '/login',
    '/logout',
    '/admin',
    '/employee',
    '/locataire/appartement'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const cacheNames = await caches.keys();
        await Promise.all(
            cacheNames
                .filter((name) => ![STATIC_CACHE, RUNTIME_CACHE, CDN_CACHE].includes(name))
                .map((name) => caches.delete(name))
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    const isSameOrigin = url.origin === self.location.origin;
    const isSensitive = isSameOrigin && SENSITIVE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
    const isNavigation = request.mode === 'navigate';

    if (isSensitive || isNavigation) {
        event.respondWith(fetch(request));
        return;
    }

    const isStaticAsset = isSameOrigin && (
        request.destination === 'script'
        || request.destination === 'style'
        || request.destination === 'font'
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/manifest.webmanifest'
        || url.pathname.startsWith('/assets/')
    );

    if (isStaticAsset) {
        event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
        return;
    }

    const isCdnAsset = !isSameOrigin && (
        request.destination === 'script'
        || request.destination === 'style'
        || request.destination === 'font'
    );

    if (isCdnAsset) {
        event.respondWith(staleWhileRevalidate(request, CDN_CACHE));
    }
});

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cachedResponse = await cache.match(request);

    const networkPromise = fetch(request)
        .then((response) => {
            if (response && response.ok) {
                cache.put(request, response.clone());
            }

            return response;
        })
        .catch(() => cachedResponse);

    return cachedResponse || networkPromise;
}
