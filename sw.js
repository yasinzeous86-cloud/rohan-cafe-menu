/* Cafe Menu service worker — the online menu keeps working offline after the
 * first visit: the shell and assets are precached, the api.php payload is
 * fetched from the network and served from cache when the network is gone.
 * Only GET requests are ever intercepted; writes always reach the server.
 */

const VERSION = 'cafe-menu-v2';
const SHELL = [
  './',
  './index.html',
  './manifest.json',
  './assets/style.min.css',
  './assets/app.js',
  './assets/menu-bg.jpg',
  './assets/coffee-texture.jpg',
  // Local webfonts. Vazirmatn + Lalezar + Righteous ship with the menu and are
  // precached; the Dana / YekanBakh picker faces are caught by the cache-first
  // rule below as soon as someone picks them.
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
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

/* api.php answers with Cache-Control: no-store — store a clean copy so the
 * offline fallback is not rejected by the cache. */
async function cachePayload(request, response) {
  const cache = await caches.open(VERSION);
  const copy = new Response(await response.clone().text(), {
    status: response.status,
    statusText: response.statusText,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
  await cache.put(request, copy);
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
        .then(async (res) => {
          if (res.ok) await cachePayload(request, res);
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
          const copy = res.clone();
          caches.open(VERSION).then((cache) => cache.put('./index.html', copy));
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
          if (res.ok) caches.open(VERSION).then((cache) => cache.put(request, res.clone()));
          return res;
        })
        .catch(() => cached);
      return cached || network;
    }),
  );
});
