/* Cafe Menu service worker — the online menu keeps working offline after the
 * first visit: the shell and assets are precached, the api.php payload is
 * fetched from the network and served from cache when the network is gone.
 * Only GET requests are ever intercepted; writes always reach the server.
 */

const CACHE_VERSION = 'v1.5-runtime-fix';
const VERSION = CACHE_VERSION;
const SHELL = [
  './',
  './index.html',
  './manifest.json',
  './assets/style.min.css',
  './assets/app.js',
  './assets/menu-bg.jpg',
  './assets/coffee-texture.jpg',
  './assets/fonts/Vazirmatn-Variable.woff2',
  './assets/fonts/Lalezar-Arabic.woff2',
  './assets/fonts/Lalezar-Latin.woff2',
  './assets/fonts/Righteous-Latin.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION).then((cache) =>
      // one missing file must not fail the whole install
      Promise.all(SHELL.map((url) => cache.add(url).catch(() => null))),
    ).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== VERSION).map((k) => {
          console.log('[SW] Purging old cache:', k);
          return caches.delete(k);
        })
      ))
      .then(() => self.clients.claim()),
  );
});

/* api.php answers with Cache-Control: no-store — store a clean copy so the
 * offline fallback is not rejected by the cache. */
async function cachePayload(request, clonedResponse) {
  try {
    const cache = await caches.open(VERSION);
    const text = await clonedResponse.text();
    const copy = new Response(text, {
      status: clonedResponse.status,
      statusText: clonedResponse.statusText,
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
    });
    await cache.put(request, copy);
  } catch (err) {
    console.warn('[SW] Failed to cache api payload:', err);
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // fonts/CDN/photos: normal

  // Menu payload: network first, cache as the offline fallback.
  if (url.pathname.endsWith('api.php')) {
    event.respondWith(
      fetch(request)
        .then((res) => {
          if (res && res.ok) {
            // Synchronously clone BEFORE returning or consuming
            const copy = res.clone();
            cachePayload(request, copy).catch(() => {});
          }
          return res;
        })
        .catch(async () => (await caches.match(request)) || Response.error()),
    );
    return;
  }

  // Page navigations: network first, cached shell when offline.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(VERSION).then((cache) => cache.put('./index.html', copy)).catch(() => {});
          }
          return res;
        })
        .catch(async () => (await caches.match('./index.html')) || (await caches.match('./')) || Response.error()),
    );
    return;
  }

  // Everything else on this origin: cache first, refreshed in the background.
  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request)
        .then((res) => {
          if (res && res.status === 200 && res.type === 'basic') {
            // CRITICAL: Synchronously clone immediately before any async chain or body consumption
            const copy = res.clone();
            caches.open(VERSION).then((cache) => cache.put(request, copy)).catch(() => {});
          }
          return res;
        })
        .catch(() => cached);
      return cached || network;
    }),
  );
});
