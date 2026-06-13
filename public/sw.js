const CACHE_NAME = 'zenith-v2';

// ── Install: skip waiting so new SW activates immediately ─────────────────────
self.addEventListener('install', (event) => {
  self.skipWaiting();
});

// ── Activate: claim clients, delete old caches ────────────────────────────────
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

// ── Fetch strategy ────────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Never cache API calls
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(fetch(request).catch(() => new Response('Offline', { status: 503 })));
    return;
  }

  // Never cache Tailwind CDN or Google Fonts
  if (
    url.hostname === 'cdn.tailwindcss.com' ||
    url.hostname.endsWith('.googleapis.com') ||
    url.hostname.endsWith('.gstatic.com')
  ) {
    event.respondWith(fetch(request).catch(() => new Response('Offline', { status: 503 })));
    return;
  }

  // Cache-first with stale-while-revalidate for built assets
  if (url.pathname.startsWith('/assets/') && (url.pathname.endsWith('.js') || url.pathname.endsWith('.css'))) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const fetchPromise = fetch(request).then((response) => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          }
          return response;
        });
        return cached || fetchPromise;
      })
    );
    return;
  }

  // Network-first with cache fallback for HTML and non-file-extension URLs
  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response.ok && url.origin === self.location.origin) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => {
        return caches.match(request).then((cached) => {
          if (cached) return cached;
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
  const banner = document.createElement('div');
  banner.id = 'sw-update-banner';
  banner.style.cssText = `
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #4f46e5; color: white;
    padding: 12px 20px; text-align: center;
    font-family: 'Outfit', sans-serif; font-size: 14px; z-index: 99999;
    display: flex; align-items: center; justify-content: center; gap: 12px;
    box-shadow: 0 -2px 12px rgba(0,0,0,0.15);
  `;
  banner.innerHTML = `
    <span>New version available</span>
    <button id="sw-reload-btn" style="
      background: white; color: #4f46e5; border: none;
      padding: 6px 16px; border-radius: 6px;
      font-weight: 600; cursor: pointer; font-family: inherit;
    ">Update & Reload</button>
  `;
  document.body.appendChild(banner);
  document.getElementById('sw-reload-btn')?.addEventListener('click', () => {
    window.location.reload();
  });
});

// ── Message: respond to SKIP_WAITING from the update banner ──────────────────
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
