const CACHE = 'nebuless-static-v2';
const OFFLINE = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload', credentials: 'omit' }))));
});
self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('nebuless-static-') && key !== CACHE).map((key) => caches.delete(key)))));
});
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname === '/offline' || url.pathname.startsWith('/build/assets/')) {
        event.respondWith(caches.open(CACHE).then(async (cache) => {
            const cached = await cache.match(request);
            if (cached) return cached;
            const response = await fetch(request);
            if (response.ok) await cache.put(request, response.clone());
            return response;
        }));
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE)) || Response.error()));
    }
});
