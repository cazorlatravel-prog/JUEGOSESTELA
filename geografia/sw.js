// Geografía 1º ESO — permite instalar la app y jugar sin conexión (modos de un jugador).
const CACHE = "geografia-v1";
const BASICOS = ["./", "index.html", "manifest.webmanifest", "icon-192.png", "icon-512.png", "apple-touch-icon.png"];
self.addEventListener("install", (e) => { e.waitUntil(caches.open(CACHE).then((c) => c.addAll(BASICOS)).then(() => self.skipWaiting())); });
self.addEventListener("activate", (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.pathname.endsWith("api.php")) return; // lo online siempre va al servidor
  if (e.request.mode === "navigate") {
    // Primero la red (para recibir actualizaciones); sin conexión, la copia guardada
    e.respondWith(fetch(e.request).then((r) => { const copia = r.clone(); caches.open(CACHE).then((c) => c.put("index.html", copia)); return r; }).catch(() => caches.match("index.html")));
    return;
  }
  e.respondWith(caches.match(e.request).then((g) => g || fetch(e.request).then((r) => { if (r.ok && (url.origin === location.origin || /fonts\.(googleapis|gstatic)\.com|cdnjs/.test(url.host))) { const copia = r.clone(); caches.open(CACHE).then((c) => c.put(e.request, copia)); } return r; })));
});
