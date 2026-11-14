const CACHE = "amir-shell-v2.5.0-96a16449";
const ASSETS = [
  "/assets/Vazirmatn.woff2",
  "/offline.html",
  "/assets/style.2.5.0.fdb9b3be.min.css",
  "/assets/app.2.5.0.96a16449.min.js",
  "/images/placeholder.svg",
  "/images/icons/icon-192.png",
];
self.addEventListener("install", (event) =>
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS))),
);
self.addEventListener("activate", (event) =>
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((k) => k.startsWith("amir-shell-") && k !== CACHE)
            .map((k) => caches.delete(k)),
        ),
      )
      .then(() => self.clients.claim()),
  ),
);
self.addEventListener("message", (e) => {
  if (e.data === "ACTIVATE_UPDATE") self.skipWaiting();
});
self.addEventListener("fetch", (event) => {
  const r = event.request,
    u = new URL(r.url);
  if (
    r.method !== "GET" ||
    u.origin !== location.origin ||
    u.pathname.startsWith("/api/") ||
    u.pathname.startsWith("/payment/") ||
    u.pathname.startsWith("/admin") ||
    u.pathname.startsWith("/profile") ||
    u.pathname.startsWith("/order") ||
    u.pathname.startsWith("/invoice")
  )
    return;
  if (r.mode === "navigate") {
    event.respondWith(fetch(r).catch(() => caches.match("/offline.html")));
    return;
  }
  if (ASSETS.includes(u.pathname) || u.pathname.startsWith("/images/"))
    event.respondWith(
      caches.match(r).then(
        (cached) =>
          cached ||
          fetch(r)
            .then((response) => {
              if (response.ok) {
                const copy = response.clone();
                caches.open(CACHE).then((c) => c.put(r, copy));
              }
              return response;
            })
            .catch(() => r.destination === "image" ? caches.match("/images/placeholder.svg") : Response.error()),
      ),
    );
});
