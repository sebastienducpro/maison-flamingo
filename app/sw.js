// L'outil s'ouvre même sans réseau au fond d'un jardin : la coquille est gardée en cache.
// Les données, elles, ne passent jamais par ce cache : elles vont toujours au serveur.
const VERSION = 'flamingo-terrain-1';
const COQUILLE = ['./', 'index.html', 'app.css?v=1', 'commun.js?v=1', 'formulaires.js?v=1', 'app.js?v=1', 'icone.png', 'flamant.png', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(COQUILLE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((cles) => Promise.all(cles.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.endsWith('api.php') || url.pathname.includes('rapport')) return;
  // Réseau d'abord, pour toujours avoir la dernière version ; le cache seulement si le réseau manque.
  e.respondWith(
    fetch(e.request).then((r) => {
      // Une page d'erreur mise en cache resservirait l'erreur hors ligne : on ne garde que ce qui a réussi.
      if (r.ok) { const copie = r.clone(); caches.open(VERSION).then((c) => c.put(e.request, copie)); }
      return r;
    }).catch(() => caches.match(e.request).then((r) => r || caches.match('index.html')))
  );
});
