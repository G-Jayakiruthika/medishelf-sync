/* AushadhBox service worker: offline app shell + push reminders. */
const CACHE = "aushadhbox-v3";
const SHELL = ["/app", "/styles.css?v=3", "/app.js?v=3", "/i18n.js?v=3", "/i18n-account.js?v=3", "/i18n-premium.js?v=3", "/consent.js?v=3", "/consent-i18n.js?v=3",
  "/lib/qrcode.js", "/lib/jsQR.js", "/icons/icon-192.png", "/icons/icon-512.png",
  "/assets/audio/dose-reminder.mp3", "/assets/audio/expiry-alert.mp3", "/assets/audio/saved.mp3"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", (e) => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin || url.pathname.endsWith(".php") || url.pathname === "/report" || url.pathname === "/admin.html") return;
  // Network first for the page and code (so updates arrive), cache as fallback when offline.
  e.respondWith(fetch(e.request).then(res => {
    if (res.ok && !url.pathname.includes("/assets/video/")) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); }
    return res;
  }).catch(() => caches.match(e.request).then(r => r || (e.request.mode === "navigate" && url.pathname === "/app" ? caches.match("/app") : undefined))));
});

self.addEventListener("push", (e) => {
  let data = { title: "AushadhBox", body: "Time to check your medicines." };
  try { data = Object.assign(data, e.data.json()); } catch (err) { /* plain text */ }
  e.waitUntil(self.registration.showNotification(data.title, {
    body: data.body, tag: data.tag || "aushadhbox", renotify: true, icon: "/icons/icon-192.png", badge: "/icons/badge-72.png",
    data: { url: data.url || "/app" }, vibrate: [120, 60, 120]
  }));
});
self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  const target = new URL(e.notification.data.url || "./", self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
    for (const c of list) if (c.url.startsWith(self.registration.scope)) { c.navigate(target); return c.focus(); }
    return self.clients.openWindow(target);
  }));
});
