const CACHE = 'banjar-static-v2';
const STATIC = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png'];
self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(STATIC)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('banjar-static-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    // Navigation is never stored. Offline fallback contains no tenant or user data.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    } else if (!url.search && STATIC.includes(url.pathname)) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request)));
    }
});
self.addEventListener('push', event => {
    // Payload is always generic (no identity/amount) per server-side redaction; safe to show on a locked screen.
    let data = { title: 'Notifikasi Banjar', body: 'Ada tindakan baru menunggu Anda.', url: '/notifications' };
    try {
        if (event.data) data = { ...data, ...event.data.json() };
    } catch { /* keep generic fallback */ }
    event.waitUntil(self.registration.showNotification(data.title, { body: data.body, icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', data: { url: data.url } }));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url || '/notifications';
    event.waitUntil(self.clients.matchAll({ type: 'window' }).then(clients => {
        const existing = clients.find(c => c.url.endsWith(target));
        return existing ? existing.focus() : self.clients.openWindow(target);
    }));
});
