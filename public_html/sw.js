/* AushadhBox service worker: offline app shell + push reminders. */
const CACHE = "aushadhbox-v11";
const SHELL = ["/app", "/styles.css?v=12", "/app.js?v=11", "/i18n.js?v=3", "/i18n-account.js?v=3", "/i18n-premium.js?v=3", "/i18n-auto.js?v=4", "/i18n-smart.js?v=5", "/i18n-care.js?v=6", "/i18n-autopilot.js?v=7", "/i18n-notebook.js?v=8", "/i18n-household.js?v=9", "/i18n-connect.js?v=9", "/i18n-network.js?v=1", "/network.js?v=3", "/i18n-careplus.js?v=1", "/careplus.js?v=1", "/scene3d.js?v=1", "/rx-parse.js?v=6", "/ble-health.js?v=1", "/health-import.js?v=1", "/consent.js?v=3", "/consent-i18n.js?v=3",
  "/lib/qrcode.js", "/lib/jsQR.js", "/icons/icon-192.png", "/icons/icon-512.png",
  "/assets/audio/dose-reminder.mp3", "/assets/audio/expiry-alert.mp3", "/assets/audio/saved.mp3"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", (e) => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE && k !== "aushadhbox-share").map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  // A photo shared from WhatsApp or the gallery: keep it, then open the app, which reads it and scans it.
  if (e.request.method === "POST" && url.pathname === "/app/share") {
    e.respondWith((async () => {
      try {
        const form = await e.request.formData(), file = form.getAll("photo").find(f => f && f.type && f.type.startsWith("image/"));
        if (file) { const c = await caches.open("aushadhbox-share"); await c.put("/shared-image", new Response(file, { headers: { "Content-Type": file.type } })); }
      } catch (err) { /* nothing usable was shared */ }
      return Response.redirect("/app#shared", 303);
    })());
    return;
  }
  if (e.request.method !== "GET" || url.origin !== location.origin || url.pathname.endsWith(".php") || url.pathname === "/report" || url.pathname === "/admin.html" ||
      /^\/(tick|share)\//.test(url.pathname) || url.pathname.startsWith("/pharmacy")) return;   // secret links and the pharmacy portal are never kept offline
  // Network first for the page and code (so updates arrive), cache as fallback when offline.
  e.respondWith(fetch(e.request).then(res => {
    if (res.ok && !url.pathname.includes("/assets/video/")) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(e.request, copy)); }
    return res;
  }).catch(() => caches.match(e.request).then(r => r || (e.request.mode === "navigate" && url.pathname === "/app" ? caches.match("/app") : undefined))));
});

self.addEventListener("push", (e) => {
  let data = { title: "AushadhBox", body: "Time to check your medicines." };
  try { data = Object.assign(data, e.data.json()); } catch (err) { /* plain text */ }
  // Dose reminders carry their time slot, so the notification gets a "Taken" button that ticks them without opening the app.
  e.waitUntil(self.registration.showNotification(data.title, {
    body: data.body, tag: data.tag || "aushadhbox", renotify: true, icon: "/icons/icon-192.png", badge: "/icons/badge-72.png",
    data: { url: data.url || "/app", slot: data.slot || null }, vibrate: [120, 60, 120],
    // "Did you take it?" checks offer Skip (the app then asks why); ordinary reminders offer Open.
    actions: data.slot ? [{ action: "taken", title: "✓ Taken" }, data.ask ? { action: "skip", title: "Skip" } : { action: "open", title: "Open" }] : []
  }));
});
self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  if (e.action === "taken" && e.notification.data && e.notification.data.slot) {
    e.waitUntil(fetch("/api.php?action=session", { credentials: "include" }).then(r => r.json()).then(s =>
      fetch("/api.php?action=dose_slot_taken", { method: "POST", credentials: "include", headers: { "Content-Type": "application/json", "X-CSRF-Token": s.csrf }, body: JSON.stringify({ slot: e.notification.data.slot, source: "notif" }) })
    ).then(r => r.json()).then(d => self.registration.showNotification("Marked as taken", { body: (d.ticked || 0) + " doses ticked. Well done!", tag: "taken", icon: "/icons/icon-192.png", badge: "/icons/badge-72.png" }))
      .catch(() => self.clients.openWindow("/app#doses")));
    return;
  }
  const url = e.action === "skip" && e.notification.data.slot ? "/app#skip-" + e.notification.data.slot : (e.notification.data.url || "./");
  const target = new URL(url, self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(list => {
    for (const c of list) if (c.url.startsWith(self.registration.scope)) { c.navigate(target); return c.focus(); }
    return self.clients.openWindow(target);
  }));
});
