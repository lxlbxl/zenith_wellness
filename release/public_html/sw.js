const CACHE_VERSION = 'v1';
const CACHE_NAME = `zenith-${CACHE_VERSION}`;
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/index.tsx',
];

// ── Install: pre-cache shell assets ──────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

// ── Activate: claim clients immediately, purge old caches ─────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith('zenith-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: network-first with cache fallback ──────────────────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Network-first for same-origin GET requests — freshness matters
  if (request.method !== 'GET') return;

  event.respondWith(
    fetch(request)
      .then((response) => {
        if (!response.ok) return response;

        // Cache successful same-origin responses for offline use
        if (url.origin === self.location.origin) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => {
        // Fallback: serve from cache on network failure
        return caches.match(request).then((cached) => {
          if (cached) return cached;
          // For navigation requests, serve the cached shell
          if (request.mode === 'navigate') {
            return caches.match('/') || new Response('Offline', { status: 503 });
          }
          return new Response('Offline', { status: 503 });
        });
      })
  );
});

// ── Update notification: reload page when new SW takes control ────────────────
self.addEventListener('controllerchange', () => {
  window.location.reload();
});

// ── Message: respond to SKIP_WAITING from the update banner ──────────────────
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
