// Service worker - caches static assets for faster loads
const CACHE_NAME = 'chatbot-v3';
const STATIC_ASSETS = [
    './',
    './index.html',
    './styles.css',
    './avatar.svg',
    './manifest.json',
    './css/variables.css',
    './css/base.css',
    './css/sidebar.css',
    './css/header.css',
    './css/chat.css',
    './css/input.css',
    './css/components.css',
    './css/responsive.css',
    './js/app.js',
    './js/api.js',
    './js/chat.js',
    './js/sidebar.js',
    './js/memory.js',
    './js/media.js',
    './js/ui.js',
    './js/state.js',
    './js/config.js',
    './js/shortcuts.js',
    './js/knowledge.js',
    './js/tasks.js',
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (e) => {
    // Skip API calls, non-GET requests, and cross-origin requests
    if (e.request.url.includes('.php') || e.request.method !== 'GET') {
        return;
    }
    if (new URL(e.request.url).origin !== self.location.origin) {
        return;
    }

    e.respondWith(
        caches.match(e.request).then(cached => {
            const fetchPromise = fetch(e.request).then(response => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
                }
                return response;
            }).catch(() => cached);

            return cached || fetchPromise;
        })
    );
});
