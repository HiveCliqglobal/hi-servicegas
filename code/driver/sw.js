/**
 * Hi-Service Driver — Service Worker
 *
 * Strategy: network-first for HTML, cache-first for assets, instant
 * offline fallback. Push handlers ready for v2.
 */
const CACHE = 'hs-driver-v1';
const ASSETS = [
  '/driver/today.php',
  '/driver/delivered.php',
  '/driver/profile.php',
  '/assets/css/app.css',
  '/assets/img/hi-service-logo.png',
  '/driver/icon-192.png',
  '/driver/icon-512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(() => {})));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;

  // Network-first for HTML / PHP (so order data stays live)
  if (req.headers.get('accept')?.includes('text/html') || req.url.endsWith('.php')) {
    e.respondWith(
      fetch(req).catch(() => caches.match(req).then(r => r || caches.match('/driver/today.php')))
    );
    return;
  }

  // Cache-first for static assets
  e.respondWith(
    caches.match(req).then(r => r || fetch(req).then(resp => {
      const copy = resp.clone();
      caches.open(CACHE).then(c => c.put(req, copy)).catch(() => {});
      return resp;
    }))
  );
});

// Push notification handler (ready for v2 when we wire VAPID)
self.addEventListener('push', e => {
  if (!e.data) return;
  const data = e.data.json();
  e.waitUntil(self.registration.showNotification(data.title || 'Hi-Service Driver', {
    body: data.body || '',
    icon: '/driver/icon-192.png',
    badge: '/driver/icon-192.png',
    vibrate: [200, 80, 200],
    tag: data.tag || 'hs-' + Date.now(),
    data: { url: data.url || '/driver/today.php' },
    actions: data.actions || [],
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/driver/today.php';
  e.waitUntil(self.clients.openWindow(url));
});
