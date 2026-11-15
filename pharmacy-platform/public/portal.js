/* AushadhBox Pharmacy portal (/app). One page app over api.php. Screens: Home, Counter, Orders, Stock, Network,
   Customers & khata, Payments, Delivery, Fridge sensors, Staff, Settings, Reports. What each person sees depends on their role. */
(function () {
  "use strict";
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s === null || s === undefined ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const rs = (n) => "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
  const when = (s) => { if (!s) return ""; try { return new Date(String(s).replace(" ", "T")).toLocaleString("en-IN", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }); } catch (e) { return s; } };
  const day = (s) => { if (!s) return ""; try { return new Date(String(s).slice(0, 10) + "T00:00:00").toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "2-digit" }); } catch (e) { return s; } };
  const SLOTS = [["m", "Morning"], ["a", "Afternoon"], ["e", "Evening"], ["b", "Bedtime"]];
  const ST = { new: ["New", "bad"], accepted: ["Accepted", "info"], packed: ["Packed", "info"], ready: ["Ready", "warn"], out: ["Out for delivery", "warn"], delivered: ["Delivered", ""], collected: ["Collected", ""], cancelled: ["Cancelled", "grey"] };
  const NEXT = { new: [["accepted", "Accept"]], accepted: [["packed", "Pack"]], packed: [["ready", "Ready for pickup"]], ready: [["collected", "Collected"]], out: [["ready", "Came back"]] };
  const METHOD = { upi_qr: "UPI (QR)", upi_phone: "UPI to our number", other_qr: "Other QR", cash: "Cash", card: "Card", points: "Points" };
  let S = null;   // session: staff, org, branch, can, branches

  function toast(m) { const t = $("#toast"); t.textContent = m; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, 3200); }
  const api = {
    csrf: "",
    async call(action, body, method, params) {
      const opts = { method, credentials: "same-origin", headers: {} };
      if (method === "POST") { opts.headers["Content-Type"] = "application/json"; opts.headers["X-CSRF-Token"] = api.csrf; opts.body = JSON.stringify(body || {}); }
      let res; try { res = await fetch("/api.php?action=" + action + (params ? "&" + new URLSearchParams(params) : ""), opts); } catch (e) { throw new Error("No internet connection. Your work is still on the screen: try again."); }
      const data = await res.json().catch(() => ({ message: "Unexpected answer from the server." }));
      if (res.status === 419 && !(body && body._r)) { await api.session(); return api.call(action, Object.assign({}, body, { _r: 1 }), method, params); }
      if (res.status === 401 && action !== "login" && action !== "login_2fa") { showAuth(); }
      if (!res.ok) { const e = new Error(data.message || "Something went wrong."); e.code = data.error; throw e; }
      return data;
    },
    get(a, p) { return api.call(a, null, "GET", p); },
    post(a, b) { return api.call(a, b || {}, "POST"); },
    async session() { const s = await api.get("session"); api.csrf = s.csrf; return s; }
  };
  const can = (w) => S && S.can.includes(w);
  function dialog(html, onReady) {
    const d = $("#dlg"); $("#dlgBody").innerHTML = html; if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    if (onReady) onReady($("#dlgBody"));
  }
  const close = () => { const d = $("#dlg"); if (d.open) d.close(); };
  document.addEventListener("click", (e) => { if (e.target.closest("[data-close]")) close(); });
  function qr(canvas, text) {
    if (!window.qrcode) return;
    const q = window.qrcode(0, "M"); q.addData(text); q.make();
    const n = q.getModuleCount(), size = canvas.width, k = Math.floor(size / (n + 4)), off = Math.floor((size - k * n) / 2), c = canvas.getContext("2d");
    c.fillStyle = "#fff"; c.fillRect(0, 0, size, size); c.fillStyle = "#000";
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect(off + col * k, off + r * k, k, k);
  }
  function errBox(m) { return '<p class="error">' + esc(m) + "</p>"; }
  async function run(btn, fn) { if (btn) btn.disabled = true; try { await fn(); } catch (e) { toast(e.message); } if (btn) btn.disabled = false; }

  /* ---------------- Sign in and register ---------------- */
  function showAuth() { $("#auth").hidden = false; $("#shell").hidden = true; $("#bottom").hidden = true; $("#btnOut").hidden = true; $("#branchSel").hidden = true; $("#who").textContent = "Pharmacy portal"; }
  function tab(up) { $("#tIn").setAttribute("aria-pressed", String(!up)); $("#tUp").setAttribute("aria-pressed", String(up)); $("#fIn").hidden = up; $("#fUp").hidden = !up; $("#f2fa").hidden = true; $("#authErr").hidden = true; }
  $("#tIn").addEventListener("click", () => tab(false)); $("#tUp").addEventListener("click", () => tab(true));
  const authErr = (m) => { $("#authErr").textContent = m; $("#authErr").hidden = !m; };
  $("#fIn").addEventListener("submit", async (e) => {
    e.preventDefault(); authErr("");
    try { const r = await api.post("login", { id: $("#inId").value.trim(), password: $("#inPw").value }); $("#inPw").value = "";
      if (r.twoFactor) { $("#fIn").hidden = true; $("#f2fa").hidden = false; $("#in2fa").focus(); return; } api.csrf = r.csrf; boot(); } catch (er) { authErr(er.message); }
  });
  $("#f2fa").addEventListener("submit", async (e) => { e.preventDefault(); authErr(""); try { const r = await api.post("login_2fa", { code: $("#in2fa").value }); api.csrf = r.csrf; boot(); } catch (er) { authErr(er.message); } });
  let upLL = null;
  $("#upLoc").addEventListener("click", () => {
    if (!navigator.geolocation) { $("#upLocMsg").textContent = "Location not available in this browser."; return; }
    $("#upLocMsg").textContent = "…";
    navigator.geolocation.getCurrentPosition(p => { upLL = { lat: +p.coords.latitude.toFixed(6), lng: +p.coords.longitude.toFixed(6) }; $("#upLocMsg").textContent = "✓ " + upLL.lat + ", " + upLL.lng; },
      () => { $("#upLocMsg").textContent = "Could not get the location. You can set it later in Settings."; }, { enableHighAccuracy: true, timeout: 12000 });
  });
  $("#fUp").addEventListener("submit", async (e) => {
    e.preventDefault(); authErr("");
    const v = (id) => $(id).value.trim();
    try {
      const r = await api.post("signup", { name: v("#upName"), licence_retail: v("#upLic"), village: v("#upVillage"), district: v("#upDistrict"), state: v("#upState"), pincode: v("#upPin"), area: v("#upArea"), kind: v("#upKind"),
        owner_name: v("#upOwner"), phone: v("#upPhone"), email: v("#upEmail"), password: $("#upPw").value, upi_vpa: v("#upUpi").includes("@") ? v("#upUpi") : null, gstin: v("#upGst") || null,
        agree: $("#upAgree").checked, lat: upLL ? upLL.lat : null, lng: upLL ? upLL.lng : null });
      api.csrf = r.csrf; boot();
    } catch (er) { authErr(er.message); }
  });
  $("#btnOut").addEventListener("click", async () => { try { const r = await api.post("logout"); api.csrf = r.csrf; } catch (e) { /* signed out anyway */ } stopCam(); showAuth(); tab(false); });
  $("#branchSel").addEventListener("change", async (e) => { try { const r = await api.post("branch_switch", { id: Number(e.target.value) }); S.branch = r.branch; $("#who").textContent = S.branch.name + " · " + S.staff.name; go(current); } catch (er) { toast(er.message); } });

  /* ---------------- Shell and navigation ---------------- */
  const ICON = {
    home: '<path d="M3 11l9-7 9 7v9H5v-9"/>', counter: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h10M12 16v4"/>', orders: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/>',
    stock: '<path d="M3 7l9-4 9 4-9 4zM3 7v10l9 4 9-4V7M12 11v10"/>', more: '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
    network: '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8 7l8 0M7 8l4 8M17 8l-4 8"/>', customers: '<circle cx="9" cy="8" r="3.5"/><path d="M3 20c1-4 3.5-6 6-6s5 2 6 6M16 4a3.5 3.5 0 0 1 0 7M18 14c2 .8 3 3 3.5 6"/>',
    payments: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h4"/>', delivery: '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-.01M17 19a2 2 0 1 0 0-.01"/>',
    devices: '<path d="M10 14V5a2 2 0 1 1 4 0v9a4 4 0 1 1-4 0z"/>', staff: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>', settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
    reports: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>', rides: '<path d="M5 17a2 2 0 1 0 0-.01M19 17a2 2 0 1 0 0-.01M5 17l4-8h5l3 8M9 9l-1-3H5"/>'
  };
  const SCREENS = [
    ["home", "Home", "orders"], ["counter", "Counter", "card"], ["orders", "Orders", "orders"], ["stock", "Stock", "stock"], ["network", "Network", "network"], ["customers", "Customers & khata", "customers"],
    ["payments", "Payments", "payments"], ["delivery", "Delivery", "delivery"], ["devices", "Fridge sensors", "devices"], ["staff", "Staff", "staff"], ["settings", "Settings", "settings"], ["reports", "Reports", "reports"], ["rides", "My deliveries", "rides"]
  ];
  let current = "home", badges = {};
  const icon = (k) => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICON[k] || "") + "</svg>";
  function navItems() { return SCREENS.filter(([id, , need]) => (id === "rides" ? S.staff.role === "rider" : can(need)) || (need === "settings" && ["owner", "rider"].includes(S.staff.role))); }
  function renderNav() {
    const items = navItems();
    $("#side").innerHTML = items.map(([id, label]) => '<button type="button" data-go="' + id + '"' + (id === current ? ' aria-current="page"' : "") + ">" + icon(id) + "<span>" + esc(label) + "</span>" + (badges[id] ? '<span class="badge">' + badges[id] + "</span>" : "") + "</button>").join("");
    const main = S.staff.role === "rider" ? ["rides", "settings"] : ["home", "counter", "orders", "stock"].filter(id => items.some(i => i[0] === id));
    $("#bottom").style.gridTemplateColumns = "repeat(" + (main.length + 1) + ", 1fr)";
    $("#bottom").innerHTML = main.map(id => { const it = SCREENS.find(s => s[0] === id); return '<button type="button" data-go="' + id + '"' + (id === current ? ' aria-current="page"' : "") + ">" + icon(id) + "<span>" + esc(it[1].split(" ")[0]) + "</span>" + (badges[id] ? '<span class="badge">' + badges[id] + "</span>" : "") + "</button>"; }).join("") +
      '<button type="button" data-go="more"' + (current === "more" ? ' aria-current="page"' : "") + ">" + icon("more") + "<span>More</span></button>";
  }
  document.addEventListener("click", (e) => { const b = e.target.closest("[data-go]"); if (b) go(b.dataset.go); });
  window.addEventListener("hashchange", () => { const h = location.hash.slice(1); if (h && h !== current) go(h); });
  function go(id) {
    if (id !== "more" && !navItems().some(i => i[0] === id)) id = S.staff.role === "rider" ? "rides" : "home";
    current = id; if (id !== "counter") stopCam();
    try { history.replaceState(null, "", "#" + id); } catch (e) { /* sandboxed */ }
    renderNav(); window.scrollTo({ top: 0 });
    const m = $("#main"); m.innerHTML = '<p class="muted">Loading…</p>';
    (VIEWS[id] || VIEWS.home)(m).then(() => add3d(m, id)).catch(e => { m.innerHTML = errBox(e.message); });
  }

  /* A 3D model beside each screen's title (lib/scene3d.js; skipped without WebGL or in low-data mode) */
  const MODEL3D = { home: "ph_home", counter: "ph_counter", orders: "ph_orders", stock: "ph_stock", network: "ph_network", customers: "card", payments: "ph_payments",
                    delivery: "ph_delivery", rides: "ph_delivery", devices: "ph_devices", staff: "ph_staff", settings: "ph_settings", reports: "ph_reports", more: "ph_settings" };
  function add3d(m, id) {
    const h = m.querySelector("h1"); if (!h || !MODEL3D[id] || h.querySelector(".scene3d")) return;
    h.classList.add("h3d"); h.insertAdjacentHTML("afterbegin", '<canvas class="scene3d" data-model="/assets/3d/' + MODEL3D[id] + '.glb" aria-hidden="true"></canvas>');
  }

  /* ---------------- Screens ---------------- */
  const VIEWS = {};

  VIEWS.more = async (m) => {
    m.innerHTML = '<h1>More</h1><div class="grid two">' + navItems().map(([id, label]) => '<button class="card btn" type="button" data-go="' + id + '" style="justify-content:flex-start;border-radius:16px">' + icon(id) + " " + esc(label) + "</button>").join("") + "</div>";
  };

  VIEWS.home = async (m) => {
    if (S.org.status !== "approved") { m.innerHTML = '<div class="card stack"><h1>Thank you for registering</h1><p>' + (S.org.status === "suspended" ? "This pharmacy is suspended. Contact AushadhBox support." : "We are checking your drug licence. You will be able to take orders as soon as it is approved, usually within one working day.") + '</p><p class="muted">Meanwhile, finish your shop details in Settings: location, opening hours, UPI ID, delivery rules.</p><div><button class="btn primary" data-go="settings" type="button">Open Settings</button></div></div>'; return; }
    const d = await api.get("dashboard");
    badges = { orders: d.newOrders || 0, payments: d.claims || 0, network: d.linkRequests + d.transfers || 0 }; renderNav();
    const max = Math.max(1, ...d.week.map(w => +w.v));
    const kinds = { callback: "📞", coldchain: "❄", reorder: "📦", expiry: "⏳", transfer: "🔁", demand: "📈" };
    m.innerHTML = '<div class="row between"><h1>' + esc(S.branch.name) + '</h1><div class="row"><button class="btn primary" type="button" data-go="counter">Scan a card</button><button class="btn" type="button" id="hNew">New order</button></div></div>' +
      '<div class="kpis">' +
      kpi(d.ordersToday, "orders today") + kpi(rs(d.salesToday), "sales today") + kpi(d.newOrders, "new orders", d.newOrders ? "alert" : "") + kpi(d.claims, "payments to confirm", d.claims ? "alert" : "") +
      kpi(rs(d.khataTotal), "on credit (khata)") + kpi(d.expiring, "batches expiring in 90 days", d.expiring ? "alert" : "") + kpi(d.expired, "expired batches on shelf", d.expired ? "alert" : "") + kpi(rs(d.networkEarned30), "network referral earnings, 30 days", "good") + "</div>" +
      '<div class="grid two"><div class="card stack"><h2>Needs attention</h2><ul class="list" id="ins">' + (d.insights.length ? d.insights.map(i => '<li><span>' + (kinds[i.kind] || "•") + " " + esc(i.title) + '<br><small class="muted">' + esc(when(i.created_at)) + '</small></span><span class="acts"><button class="btn sm" type="button" data-done="' + i.id + '">Done</button></span></li>').join("") : '<li class="muted">Nothing right now.</li>') +
      "</ul>" + (d.linkRequests ? '<p><button class="link" type="button" data-go="network">' + d.linkRequests + " pharmacy link request(s) waiting</button></p>" : "") + (d.complaints ? '<p><button class="link" type="button" data-go="reports">' + d.complaints + " open complaint(s)</button></p>" : "") + "</div>" +
      '<div class="card stack"><h2>Last 14 days</h2><div class="bars">' + d.week.map(w => '<div style="height:' + Math.round(100 * w.v / max) + '%" title="' + esc(w.d + ": " + w.n + " orders, " + rs(w.v)) + '"></div>').join("") + '</div>' +
      '<p class="muted small">Orders by channel (30 days): ' + (d.bySource.map(s => esc(s.source) + " " + s.n).join(" · ") || "none yet") + "</p>" +
      (d.devices.length ? "<h3>Fridges</h3>" + d.devices.map(v => '<p>' + esc(v.name) + ": <b>" + esc(v.temp ?? "—") + ' °C</b> <span class="pill ' + (v.state === "ok" ? "" : "bad") + '">' + esc(v.state) + "</span></p>").join("") : "") + "</div></div>";
    $("#hNew").addEventListener("click", () => newOrderDialog());
    $("#ins").addEventListener("click", async (e) => { const b = e.target.closest("[data-done]"); if (b) { await api.post("insight_done", { id: Number(b.dataset.done) }); b.closest("li").remove(); } });
  };
  const kpi = (v, label, cls) => '<div class="kpi ' + (cls || "") + '"><b>' + esc(v) + "</b><span>" + esc(label) + "</span></div>";

  /* ----- Counter: AushadhBox card and walk-in sales ----- */
  let stream = null, camTimer = null, patient = null, rows = [];
  function stopCam() { clearInterval(camTimer); camTimer = null; if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } }
  VIEWS.counter = async (m) => {
    m.innerHTML = '<h1>Counter</h1><div class="grid two">' +
      '<div class="card stack"><h2>Family\'s AushadhBox card</h2><p class="muted small">The customer shows the QR in their app or on paper. You see only what the family allows; every look is shown to them.</p>' +
        '<div class="cam" id="cam" hidden><video id="vid" playsinline muted></video></div><canvas id="cv" hidden></canvas>' +
        '<div class="row"><button class="btn primary" type="button" id="camGo">Scan with camera</button><button class="btn" type="button" id="camStop" hidden>Stop</button></div>' +
        '<form class="row end" id="codeF"><label class="field" style="flex:1 1 200px">Or type the 12-letter code<input id="codeIn" class="mono" maxlength="20" autocomplete="off" placeholder="XXXX-XXXX-XXXX" style="text-transform:uppercase"></label><button class="btn" type="submit">Open</button></form>' +
        '<div id="pat"></div></div>' +
      '<div class="card stack"><h2>Walk-in sale or phone order</h2><p class="muted small">For customers without the app: find them by phone (for khata and points) or sell without a name.</p>' +
        '<div class="row"><button class="btn primary" type="button" id="walkIn">New sale / order</button><button class="btn" type="button" data-go="customers">Customers & khata</button></div>' +
        '<h3>UPI for any amount</h3><p class="muted small">No printed QR? Show this one to the customer.</p><form class="row end" id="upiF"><label class="field" style="flex:1 1 120px">Amount (₹)<input id="upiAmt" inputmode="decimal" required></label><button class="btn" type="submit">Show QR</button></form><div class="qrbox" id="upiBox" hidden><canvas width="440" height="440" id="upiCv"></canvas><a class="small" id="upiLink" href="#">Open in UPI app</a></div></div></div>';
    $("#camGo").addEventListener("click", startCam); $("#camStop").addEventListener("click", () => { stopCam(); $("#cam").hidden = true; $("#camGo").hidden = false; $("#camStop").hidden = true; });
    $("#codeF").addEventListener("submit", (e) => { e.preventDefault(); openPatient($("#codeIn").value); });
    $("#walkIn").addEventListener("click", () => newOrderDialog());
    $("#upiF").addEventListener("submit", async (e) => { e.preventDefault(); try { const r = await api.post("upi_qr", { amount: Number($("#upiAmt").value), note: "counter" }); qr($("#upiCv"), r.link); $("#upiLink").href = r.link; $("#upiBox").hidden = false; } catch (er) { toast(er.message); } });
  };
  async function startCam() {
    try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false }); } catch (e) { toast("Camera not available. Allow camera access or type the code."); return; }
    const v = $("#vid"); v.srcObject = stream; await v.play().catch(() => {}); $("#cam").hidden = false; $("#camGo").hidden = true; $("#camStop").hidden = false;
    const cv = $("#cv"), ctx = cv.getContext("2d", { willReadFrequently: true });
    const det = ("BarcodeDetector" in window) ? new window.BarcodeDetector({ formats: ["qr_code"] }) : null;
    camTimer = setInterval(async () => {
      if (!v.videoWidth) return; let text = "";
      if (det) { try { const c = await det.detect(v); if (c.length) text = c[0].rawValue; } catch (e) { /* jsQR below */ } }
      if (!text && window.jsQR) { const k = Math.min(1, 640 / v.videoWidth); cv.width = v.videoWidth * k | 0; cv.height = v.videoHeight * k | 0; ctx.drawImage(v, 0, 0, cv.width, cv.height); const r = window.jsQR(ctx.getImageData(0, 0, cv.width, cv.height).data, cv.width, cv.height, { inversionAttempts: "dontInvert" }); if (r) text = r.data; }
      if (text && /AUSHADHBOX:|^[A-Z0-9]{4}-?[A-Z0-9]{4}-?[A-Z0-9]{4}$/i.test(text.trim())) { stopCam(); $("#cam").hidden = true; $("#camGo").hidden = false; $("#camStop").hidden = true; if (navigator.vibrate) navigator.vibrate(60); openPatient(text.trim()); }
    }, 300);
  }
  async function openPatient(code) {
    const box = $("#pat"); box.innerHTML = '<p class="muted">…</p>';
    try { patient = await api.post("card_patient", { code }); } catch (e) { box.innerHTML = errBox(e.message); return; }
    rows = [{ name: "", times: [], days: "", qty: "" }];
    const p = patient, ANS = { better: "feeling better", same: "no change", worse: "feeling worse", side: "side effects" };
    box.innerHTML = '<div class="row between"><h3>' + esc(p.name) + ' <span class="mono muted small">' + esc(p.code) + '</span></h3><button class="link" type="button" id="patX">Close</button></div>' +
      '<div class="chiprow"><span class="pill ' + (p.canAdd ? "" : "warn") + '">' + (p.canAdd ? "✓ may add medicines" : "adding is off") + '</span><span class="pill ' + (p.canView ? "" : "warn") + '">' + (p.canView ? "✓ may see medicines" : "medicine list private") + "</span></div>" +
      (p.canView ? (p.allergies ? '<p class="alerts"><p class="high">⚠ Allergies: ' + esc(p.allergies) + "</p></p>" : '<p class="muted small">No allergies recorded.</p>') + (p.conditions ? "<p><b>Conditions:</b> " + esc(p.conditions) + "</p>" : "") +
        "<h3>Current medicines (" + p.medicines.length + ')</h3><ul class="list">' + (p.medicines.map(x => "<li><span>" + esc(x.name) + (x.ingredient ? ' <small class="muted">' + esc(x.ingredient) + "</small>" : "") + (x.fromYou ? ' <span class="pill info">from you</span>' : "") + '</span><small class="muted">' + esc(x.times.map(t => SLOTS.find(s => s[0] === t)[1]).join(", ") || "as needed") + "</small></li>").join("") || '<li class="muted">None</li>') + "</ul>" +
        (p.followups && p.followups.length ? "<h3>Check-ins</h3><ul class=\"list\">" + p.followups.map(f => "<li><span>" + esc(f.name) + ": " + esc(ANS[f.answer] || f.answer) + (f.note ? " — " + esc(f.note) : "") + '</span><small class="muted">' + esc(f.d) + "</small></li>").join("") + "</ul>" : "")
        : '<p class="muted small">The family keeps their medicine list private. The safety check still runs against everything they take.</p>') +
      '<h3>Medicines you are giving</h3><div class="rows" id="rws"></div><button class="btn sm" type="button" id="addRow">+ Add a medicine</button>' +
      '<label class="check"><input type="checkbox" id="billToo" checked> <span>Also bill it here (takes stock, earns points)</span></label>' +
      '<div class="alerts" id="alr"></div><div class="row"><button class="btn" type="button" id="chk">Check safety</button><button class="btn primary" type="button" id="snd"' + (p.canAdd ? "" : " disabled") + ">Send to the family's app</button></div>";
    renderRows();
    $("#patX").addEventListener("click", () => { box.innerHTML = ""; patient = null; });
    $("#addRow").addEventListener("click", () => { rows.push({ name: "", times: [], days: "", qty: "" }); renderRows(); });
    $("#chk").addEventListener("click", (e) => run(e.currentTarget, precheck));
    $("#snd").addEventListener("click", (e) => run(e.currentTarget, async () => {
      const b = $("#snd");
      if (b.dataset.ok !== "1") { const al = await precheck(); if (al === null) return; if (al.some(a => a.level === "high")) { b.dataset.ok = "1"; b.textContent = "Send anyway (I have checked)"; return; } }
      const r = await api.post("card_dispense", { code: patient.code, items: items(), bill: $("#billToo").checked });
      toast("✓ " + r.added + " medicine(s) added to the family's app" + (r.order ? " · bill " + r.order.number : "")); box.innerHTML = ""; patient = null;
    }));
  }
  function items() { return rows.filter(r => r.name.trim().length >= 2).map(r => ({ name: r.name.trim(), times: r.times, days: Number(r.days) || 0, qty: r.qty === "" ? 1 : Number(r.qty) })); }
  function renderRows() {
    $("#rws").innerHTML = rows.map((r, i) => '<div class="stack" data-i="' + i + '" style="border:1px solid var(--line);border-radius:12px;padding:8px"><div class="irow"><input data-f="name" placeholder="Medicine and strength" value="' + esc(r.name) + '" aria-label="Medicine"><input data-f="qty" inputmode="numeric" placeholder="Qty" value="' + esc(r.qty) + '" aria-label="Quantity"><button class="btn sm" type="button" data-del aria-label="Remove">✕</button></div>' +
      '<div class="row"><span class="slots">' + SLOTS.map(([k, l]) => '<button type="button" data-slot="' + k + '" aria-pressed="' + r.times.includes(k) + '">' + l + "</button>").join("") + '</span><label class="small">Days <input data-f="days" inputmode="numeric" style="width:70px;min-height:32px;padding:4px 8px" value="' + esc(r.days) + '"></label></div></div>').join("");
  }
  document.addEventListener("input", (e) => { const row = e.target.closest("#rws [data-i]"); if (!row || !e.target.dataset.f) return; rows[+row.dataset.i][e.target.dataset.f] = e.target.value; });
  document.addEventListener("click", (e) => {
    const row = e.target.closest("#rws [data-i]"); if (!row) return; const r = rows[+row.dataset.i];
    if (e.target.closest("[data-del]")) { rows.splice(+row.dataset.i, 1); if (!rows.length) rows.push({ name: "", times: [], days: "", qty: "" }); renderRows(); return; }
    const s = e.target.closest("[data-slot]"); if (s) { r.times = r.times.includes(s.dataset.slot) ? r.times.filter(x => x !== s.dataset.slot) : r.times.concat(s.dataset.slot); s.setAttribute("aria-pressed", String(r.times.includes(s.dataset.slot))); }
  });
  const PAIR = { bleed: "{a} with {b} raises the risk of bleeding.", kidney: "{a} with {b} can weaken blood-pressure control and strain the kidneys.", clopi: "Omeprazole can make clopidogrel work less well ({a}, {b}).", breathing: "{a} with {b} can cause heavy sleepiness and slow breathing.", serotonin: "{a} with {b} can cause a serious reaction (serotonin syndrome).", drowsy: "{a} with {b} can make you very drowsy." };
  const fill = (s, p) => s.replace(/\{(\w+)\}/g, (_, k) => p[k] == null ? "" : p[k]);
  function alertText(a) {
    const p = Object.assign({}, a.p, { slot: a.p.slot ? (SLOTS.find(s => s[0] === a.p.slot) || ["", ""])[1].toLowerCase() : "" });
    const x = a.code === "same" ? fill("{a} and {b} are the same medicine ({ing}): a double dose.", p) : a.code === "kind" ? fill("{a} and {b} are the same kind of medicine. Check both are meant.", p)
      : a.code === "gap" ? fill("Take {a} and {b} at least {h} hours apart (both set for the {slot}).", p) : a.code === "allergy" ? fill("{who} is allergic to {allergy}, and {a} contains {ing}.", p) : fill(PAIR[p.why] || "{a} and {b}: check this combination.", p);
    return x.charAt(0).toUpperCase() + x.slice(1);
  }
  async function precheck() {
    const it = items(); if (!it.length) { toast("Add at least one medicine."); return null; }
    const r = await api.post("card_precheck", { code: patient.code, items: it });
    $("#alr").innerHTML = r.alerts.length ? r.alerts.map(a => '<p class="' + esc(a.level) + '">' + (a.level === "high" ? "⚠ " : "") + esc(alertText(a)) + "</p>").join("") : '<p class="clear">✓ No duplicates, risky pairs or allergies found.</p>';
    return r.alerts;
  }

  /* New order (walk-in, phone, ASHA) */
  function newOrderDialog(customer) {
    dialog('<h2>New sale or order</h2><form class="stack" id="noF">' +
      '<div class="row end"><label class="field" style="flex:1 1 200px">Customer phone (optional)<input id="noPhone" inputmode="tel" maxlength="13" value="' + esc(customer && customer.phoneFull || "") + '"></label><button class="btn sm" type="button" id="noFind">Find</button></div>' +
      '<p class="small" id="noCust">' + (customer ? "Customer: <b>" + esc(customer.name) + "</b>" : "") + '</p><input type="hidden" id="noCid" value="' + (customer ? customer.id : "") + '">' +
      '<div id="noNew" hidden class="grid two"><label class="field">Name<input id="noName" maxlength="80"></label><label class="field">Village<input id="noVillage" maxlength="80"></label><label class="check"><input type="checkbox" id="noSms" checked> <span>Customer agrees to SMS about orders and khata</span></label></div>' +
      '<label class="field">Medicines (one per line, "x 2" for quantity)<textarea id="noItems" rows="4" required placeholder="Dolo 650 x 2&#10;Glycomet 500"></textarea></label>' +
      '<div class="row"><label class="field">How<select id="noKind"><option value="pickup">At the counter / pickup</option><option value="delivery">Home delivery</option></select></label><label class="field">Source<select id="noSrc"><option value="walkin">Walk-in</option><option value="phone">Phone call</option><option value="asha">ASHA / health worker</option></select></label></div>' +
      '<div id="noDel" hidden class="grid two"><label class="field">Address<input id="noAddr" maxlength="200"></label><label class="field">Location (lat, lng)<input id="noLL" placeholder="11.29, 77.60"></label><label class="field">Exception reason (only if too far)<input id="noOv" maxlength="120"></label></div>' +
      '<label class="field">Prescription date (if seen now)<input type="date" id="noRx"></label>' +
      '<button class="btn primary" type="submit">Create order</button><p class="error" id="noErr" hidden></p></form>', (b) => {
      $("#noKind", b).addEventListener("change", () => { $("#noDel", b).hidden = $("#noKind", b).value !== "delivery"; });
      $("#noFind", b).addEventListener("click", async () => {
        const r = await api.get("customer_find", { q: $("#noPhone", b).value }); const c = r.customers[0];
        if (c) { $("#noCid", b).value = c.id; $("#noCust", b).innerHTML = "Customer: <b>" + esc(c.name) + "</b>" + (c.khata ? " · khata " + rs(c.khata) : "") + (c.points ? " · " + c.points + " points" : ""); $("#noNew", b).hidden = true; }
        else { $("#noCid", b).value = ""; $("#noCust", b).textContent = "New customer: add a name."; $("#noNew", b).hidden = false; }
      });
      $("#noF", b).addEventListener("submit", async (e) => {
        e.preventDefault(); const err = $("#noErr", b); err.hidden = true;
        const its = $("#noItems", b).value.split(/\n|,/).map(s => s.trim()).filter(s => s.length > 1).map(s => { const mm = s.match(/^(.*?)\s*[x×*]\s*(\d{1,3})$/i); return mm ? { name: mm[1], qty: +mm[2] } : { name: s, qty: 1 }; });
        const ll = ($("#noLL", b).value || "").split(",").map(Number);
        const body = { items: its, kind: $("#noKind", b).value, source: $("#noSrc", b).value, rx_date: $("#noRx", b).value || null, override: $("#noOv", b).value || null };
        if ($("#noCid", b).value) body.customer_id = Number($("#noCid", b).value);
        else if ($("#noPhone", b).value || $("#noName", b).value) body.customer = { phone: $("#noPhone", b).value, name: $("#noName", b).value || "Customer", village: $("#noVillage", b).value, sms_ok: $("#noSms", b).checked, district: S.branch.district,
          address: $("#noAddr", b).value || null, lat: ll.length === 2 && ll.every(isFinite) ? ll[0] : null, lng: ll.length === 2 && ll.every(isFinite) ? ll[1] : null };
        try { const r = await api.post("order_new", body); close(); toast("✓ Order " + r.order.number + " · " + rs(r.order.total) + (r.order.otp ? " · delivery code " + r.order.otp : "")); go("orders"); }
        catch (er) { err.textContent = er.message; err.hidden = false; }
      });
    });
  }

  /* ----- Orders ----- */
  let ofilter = "open";
  VIEWS.orders = async (m) => {
    const r = await api.get("orders", { filter: ofilter });
    m.innerHTML = '<div class="row between"><h1>Orders</h1><button class="btn primary" type="button" id="oNew">New order</button></div><div class="tabs">' +
      [["open", "Open"], ["new", "New"], ["done", "Done"], ["cancelled", "Cancelled"], ["all", "All"]].map(([k, l]) => '<button type="button" data-of="' + k + '" aria-pressed="' + (k === ofilter) + '">' + l + "</button>").join("") + "</div>" +
      '<div class="grid two" id="olist">' + (r.orders.map(orderCard).join("") || '<p class="muted">No orders here.</p>') + "</div>";
    $("#oNew").addEventListener("click", () => newOrderDialog());
    $$("[data-of]", m).forEach(b => b.addEventListener("click", () => { ofilter = b.dataset.of; go("orders"); }));
  };
  function orderCard(o) {
    const st = ST[o.status] || ["?", ""];
    return '<div class="order" data-oid="' + o.id + '"><div class="order-head"><b>' + esc(o.number) + ' <span class="pill ' + st[1] + '">' + st[0] + '</span></b><span class="muted small">' + esc(when(o.createdAt)) + " · " + esc(o.source) + " · " + esc(o.kind) + "</span></div>" +
      (o.customer ? '<p class="small">' + esc(o.customer.name) + (o.customer.phone ? ' · <a href="tel:+91' + esc(o.customer.phone) + '">' + esc(o.customer.phone) + "</a>" : "") + (o.customer.village ? " · " + esc(o.customer.village) : "") + (o.customer.app ? ' <span class="pill info">app</span>' : "") + "</p>" : "") +
      (o.askedPharmacy ? '<p class="small muted">Passed on by ' + esc(o.askedPharmacy) + "</p>" : "") +
      "<ul>" + o.items.filter(i => i.status !== "removed").map(i => '<li class="' + (i.status === "missing" ? "missing" : "") + '">' + esc(i.name) + " × " + i.qty + (i.price ? " · " + rs(i.price) : "") + (i.schedule !== "OTC" ? ' <small class="muted">' + esc(i.schedule) + "</small>" : "") + (i.batch ? ' <small class="muted">B.' + esc(i.batch) + "</small>" : "") + (i.status === "missing" ? " (not in stock)" : "") + "</li>").join("") + "</ul>" +
      (o.note ? '<p class="small">📝 ' + esc(o.note) + "</p>" : "") +
      (o.dosePack ? '<p class="small">💊 <b>Pack each dose time in its own pouch</b></p>' : "") +
      (o.pickupAt ? '<p class="small">🕒 Customer comes at <b>' + esc(when(o.pickupAt)) + "</b>: have it ready</p>" : "") +
      (o.service === "home_visit" ? '<p class="small">🏠 <b>Pharmacist home visit</b>: check the medicines at home, explain them, take back old or expired strips</p>' : o.service === "review" ? '<p class="small">🩺 <b>Medicine review</b> asked: look for duplicates, interactions and medicines that may no longer be needed</p>' : "") + (o.kind === "delivery" && o.address ? '<p class="small">📍 ' + esc(o.address) + (o.distanceKm ? " · " + o.distanceKm + " km" : "") + "</p>" : "") + (o.point ? '<p class="small">📍 ' + esc(o.point.village + " · " + o.point.name) + "</p>" : "") +
      (o.worth && o.worth.why ? '<p class="small muted">' + esc(o.worth.why.join(" ")) + "</p>" : "") +
      (o.rx.needed ? '<p class="small">Prescription: <span class="pill ' + (["ok", "seen"].includes(o.rx.status) ? "" : "warn") + '">' + esc(o.rx.status || "needed") + "</span>" + (o.rx.date ? " dated " + esc(day(o.rx.date)) : "") + "</p>" : "") +
      '<p><b>' + rs(o.total) + "</b>" + (o.deliveryFee ? ' <small class="muted">incl. delivery ' + rs(o.deliveryFee) + "</small>" : "") + ' · <span class="pill ' + (o.payStatus === "paid" ? "" : o.payStatus === "khata" ? "info" : "warn") + '">' + esc(o.payStatus) + "</span>" + (o.paid ? ' <small class="muted">paid ' + rs(o.paid) + "</small>" : "") + "</p>" +
      (o.delivery ? '<p class="small">🚚 ' + esc(o.delivery.mode + (o.delivery.partner ? " · " + o.delivery.partner : "") + (o.delivery.courier ? " · " + o.delivery.courier : "") + " · " + o.delivery.status) + (o.delivery.lastAt ? " · seen " + esc(when(o.delivery.lastAt)) : "") + "</p>" : "") +
      '<div class="row">' + orderButtons(o) + "</div></div>";
  }
  function orderButtons(o) {
    const b = [];
    if (o.rx.needed && !["ok", "seen"].includes(o.rx.status) && ["new", "accepted"].includes(o.status) && ["owner", "manager", "pharmacist"].includes(S.staff.role)) b.push('<button class="btn sm" data-act="rx">Check prescription</button>');
    (NEXT[o.status] || []).forEach(([to, label]) => { if (!(o.kind !== "pickup" && to === "ready" && o.status === "packed")) b.push('<button class="btn sm primary" data-act="st" data-to="' + to + '">' + label + "</button>"); });
    if (o.kind !== "pickup" && ["packed", "ready"].includes(o.status) && can("delivery")) b.push('<button class="btn sm primary" data-act="deliver">Send out</button>');
    if (o.kind !== "pickup" && o.status === "out" && can("delivery")) b.push('<button class="btn sm" data-act="handover">Handed over</button>');
    if (o.items.some(i => i.status === "missing") && ["new", "accepted"].includes(o.status) && can("network")) b.push('<button class="btn sm" data-act="pass">Find missing in network</button>');
    if (o.dosePack && !["cancelled"].includes(o.status)) b.push('<button class="btn sm" data-act="labels">Pouch labels</button>');
    if (o.total > o.paid && !["cancelled"].includes(o.status) && can("payments")) b.push('<button class="btn sm" data-act="pay">Payment</button>');
    if (!["delivered", "collected", "cancelled"].includes(o.status)) b.push('<button class="btn sm danger" data-act="cancel">Cancel</button>');
    return b.join("");
  }
  document.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-act]"); if (!btn) return; const card = btn.closest("[data-oid]"); if (!card) return;
    const id = Number(card.dataset.oid), act = btn.dataset.act;
    if (act === "st") return run(btn, async () => { await api.post("order_status", { id, status: btn.dataset.to }); toast("✓ Updated"); go(current); });
    if (act === "cancel") { if (btn.dataset.sure !== "1") { btn.dataset.sure = "1"; btn.textContent = "Sure? Cancel"; return; } return run(btn, async () => { await api.post("order_status", { id, status: "cancelled", reason: "cancelled by the shop" }); go(current); }); }
    const o = (await api.get("order_get", { id })).order;
    if (act === "rx") dialog('<h2>Prescription for ' + esc(o.number) + '</h2><p class="muted small">Check the original prescription (shown at the counter, by the rider, or e-mailed to your registered address). It must be dated within 30 days for long-term medicines, 7 days for a short illness.</p><form class="stack" id="rxF"><label class="field">Date on the prescription<input type="date" id="rxD" required></label><label class="check"><input type="checkbox" id="rxC" checked> <span>Long-term treatment (BP, sugar, thyroid…)</span></label><div class="row"><button class="btn primary" type="submit">Prescription is valid</button><button class="btn danger" type="button" id="rxNo">Reject</button></div></form>', (b) => {
      $("#rxF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("order_rx", { id, answer: "ok", date: $("#rxD", b).value, chronic: $("#rxC", b).checked }); close(); go(current); }); });
      $("#rxNo", b).addEventListener("click", () => run(null, async () => { await api.post("order_rx", { id, answer: "rejected" }); close(); go(current); }));
    });
    if (act === "pay") payDialog(o);
    if (act === "deliver") deliverDialog(o);
    if (act === "handover") dialog('<h2>Hand over ' + esc(o.number) + '</h2><form class="stack" id="hoF"><label class="field">Customer\'s 4-digit code<input id="hoOtp" inputmode="numeric" maxlength="4" required class="big-otp"></label><label class="field">Cash collected (₹)<input id="hoCash" inputmode="decimal" value="' + Math.max(0, o.total - o.paid) + '"></label><button class="btn primary" type="submit">Delivered</button></form>', (b) => {
      $("#hoF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("delivery_done", { order_id: id, otp: $("#hoOtp", b).value, cash: Number($("#hoCash", b).value) || 0 }); close(); toast("✓ Delivered"); go(current); }); });
    });
    if (act === "pass") passDialog(o);
    if (act === "labels") labelsDialog(o);
  });
  function payDialog(o) {
    const due = Math.max(0, o.total - o.paid);
    dialog('<h2>Payment for ' + esc(o.number) + '</h2><p>Due: <b>' + rs(due) + '</b></p><form class="stack" id="pyF"><div class="row"><label class="field">How<select id="pyM"><option value="cash">Cash</option><option value="upi_qr">UPI (our QR)</option><option value="upi_phone">UPI to our number</option><option value="other_qr">Someone else\'s QR</option><option value="card">Card</option><option value="voucher">AushadhBox health-points voucher</option>' + (o.customer ? '<option value="points">Reward points</option><option value="khata">Put on khata (credit)</option>' : "") + '</select></label>' +
      '<label class="field">Amount (₹)<input id="pyA" inputmode="decimal" value="' + due + '" required></label><label class="field">UPI reference or voucher code (optional)<input id="pyU" maxlength="22" autocapitalize="characters"></label></div>' +
      (o.payUpi ? '<div class="qrbox"><canvas width="440" height="440" id="pyQ"></canvas><small class="muted">The customer scans this with any UPI app</small></div>' : "") +
      '<button class="btn primary" type="submit">Record</button><p class="error" id="pyE" hidden></p></form>', (b) => {
      if (o.payUpi) qr($("#pyQ", b), o.payUpi);
      $("#pyF", b).addEventListener("submit", async (ev) => {
        ev.preventDefault(); const m = $("#pyM", b).value, a = Number($("#pyA", b).value);
        try {
          if (m === "voucher") { const code = ($("#pyU", b).value || "").trim(); if (!code) throw new Error("Type the 8-character voucher code in the reference box.");
            const r = await api.post("voucher_redeem", { code, order_id: o.id }); close(); toast("✓ Voucher accepted: " + rs(r.rupees) + ". The network pays this back in your monthly statement."); go(current); return; }
          if (m === "khata") await api.post("khata_credit", { customer_id: o.customer.id, amount: a, order_id: o.id });
          else await api.post("pay_record", { order_id: o.id, method: m, amount: a, utr: $("#pyU", b).value || null });
          close(); toast("✓ Recorded"); go(current);
        } catch (er) { $("#pyE", b).textContent = er.message; $("#pyE", b).hidden = false; }
      });
    });
  }
  /* 一包化: one printed label per pouch (day × dose time), cut and stick on each pouch */
  async function labelsDialog(o) {
    const from = new Date(Date.now() + 864e5).toISOString().slice(0, 10);
    dialog('<h2>Pouch labels · ' + esc(o.number) + '</h2><form class="row" id="lbF"><label class="field">From<input type="date" id="lbFrom" value="' + from + '"></label><label class="field">Days<input id="lbDays" type="number" min="1" max="31" value="7"></label><button class="btn primary" type="submit">Make labels</button></form><div id="lbOut"></div>', (b) => {
      $("#lbF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => {
        const r = await api.get("labels", { id: o.id, from: $("#lbFrom", b).value, days: $("#lbDays", b).value });
        const html = '<div class="labels">' + r.labels.map(l => '<div class="label lb-' + l.key + '"><b>' + esc(l.slot) + "</b><span>" + esc(l.date) + "</span><small>" + esc(r.for || "") + "</small><ul>" + l.meds.map(x => "<li>" + esc(x) + "</li>").join("") + "</ul><small>" + esc(r.pharmacy) + (r.phone ? " · " + esc(r.phone) : "") + "</small></div>").join("") + "</div>";
        $("#lbOut", b).innerHTML = '<p class="muted small">' + r.labels.length + ' labels.</p>' + html + '<button class="btn" type="button" id="lbPrint">Print</button>';
        $("#lbPrint", b).addEventListener("click", () => { const w = window.open("", "_blank"); if (!w) return toast("Allow pop-ups to print");
          w.document.write('<!doctype html><meta charset="utf-8"><title>Labels ' + esc(r.order) + '</title><link rel="stylesheet" href="ph.css"><body class="print-labels">' + html + "</body>"); w.document.close(); w.onload = () => w.print(); });
      }); });
    });
  }
  async function deliverDialog(o) {
    const d = await api.get("delivery_setup");
    dialog('<h2>Send out ' + esc(o.number) + '</h2>' + (o.address ? '<p class="small">📍 ' + esc(o.address) + (o.distanceKm ? " · " + o.distanceKm + " km" : "") + "</p>" : "") + '<form class="stack" id="dvF"><div class="tabs">' +
      '<label><input type="radio" name="dm" value="own" checked> Our delivery person</label> <label><input type="radio" name="dm" value="partner"> Local partner</label> <label><input type="radio" name="dm" value="courier"> Courier</label></div>' +
      '<label class="field" data-m="own">Who<select id="dvR">' + d.riders.map(r => '<option value="' + r.id + '">' + esc(r.name) + "</option>").join("") + "</select></label>" +
      '<div data-m="partner" hidden class="grid two"><label class="field">Partner (auto driver, bus parcel, India Post, ASHA…)<input id="dvP" maxlength="80" placeholder="Murugan auto"></label><label class="field">Partner phone<input id="dvPP" inputmode="tel" maxlength="13"></label></div>' +
      '<div data-m="courier" hidden class="grid two"><label class="field">Courier<select id="dvC">' + Object.entries(d.couriers).map(([k, v]) => '<option value="' + k + '">' + esc(v) + "</option>").join("") + '</select></label><label class="field">Tracking number (if booked by phone)<input id="dvT" maxlength="60"></label></div>' +
      '<p class="muted small">The customer gives the 4-digit code from their app or SMS to whoever hands over the medicines.</p><button class="btn primary" type="submit">Send out</button></form>', (b) => {
      b.addEventListener("change", (ev) => { if (ev.target.name === "dm") $$("[data-m]", b).forEach(x => { x.hidden = x.dataset.m !== ev.target.value; }); });
      $("#dvF", b).addEventListener("submit", (ev) => { ev.preventDefault(); const mode = b.querySelector("input[name=dm]:checked").value; run(null, async () => {
        await api.post("delivery_assign", { order_id: o.id, mode, rider_id: Number($("#dvR", b).value) || null, partner: $("#dvP", b).value, partner_phone: $("#dvPP", b).value, courier: $("#dvC", b).value, tracking: $("#dvT", b).value }); close(); toast("✓ Out for delivery"); go(current); }); });
    });
  }
  async function passDialog(o) {
    const missing = o.items.filter(i => i.status === "missing");
    dialog('<h2>Find in the network</h2><p class="muted small">Linked pharmacies show how many they have; others show only whether they have it. Passing an order on: that pharmacy sells and delivers, and you get the referral share.</p><div id="pdR"><p class="muted">Searching…</p></div>', async (b) => {
      const res = await Promise.all(missing.map(i => api.post("network_find", { name: i.name, qty: i.qty }).then(r => ({ i, r: r.results })).catch(() => ({ i, r: [] }))));
      $("#pdR", b).innerHTML = res.map(({ i, r }) => "<h3>" + esc(i.name) + " × " + i.qty + '</h3><ul class="list">' + (r.map(x => "<li><span><b>" + esc(x.name) + "</b> " + (x.linked ? '<span class="pill info">linked</span>' : "") + (x.sameBusiness ? ' <span class="pill">your shop</span>' : "") +
        "<br><small class=\"muted\">" + esc([x.village, x.distanceKm !== null ? x.distanceKm + " km" : null, x.count !== null ? x.count + " in stock" : x.status, x.openNow === false ? "closed now" : null].filter(Boolean).join(" · ")) + "</small>" + (x.phone ? ' <a class="small" href="tel:+91' + esc(x.phone) + '">call</a>' : "") +
        '</span><span class="acts">' + ((x.linked || x.sameBusiness) ? '<button class="btn sm primary" type="button" data-pass="' + x.branchId + '" data-item="' + i.id + '">Pass on</button>' : "") + (x.transfer.ok ? '<button class="btn sm" type="button" data-tr="' + x.branchId + '" data-name="' + esc(i.name) + '" data-qty="' + i.qty + '">Ask to send</button>' : "") + "</span></li>").join("") || '<li class="muted">Nobody nearby has it.</li>') + "</ul>").join("");
      b.addEventListener("click", (ev) => {
        const p = ev.target.closest("[data-pass]"); if (p) run(p, async () => { const r = await api.post("order_pass", { order_id: o.id, to_branch: Number(p.dataset.pass), item_ids: [Number(p.dataset.item)] }); close(); toast("✓ Passed on as " + r.order.number); go(current); });
        const t = ev.target.closest("[data-tr]"); if (t) run(t, async () => { const r = await api.post("transfer_ask", { from_branch: Number(t.dataset.tr), order_id: o.id, items: [{ name: t.dataset.name, qty: Number(t.dataset.qty) }] }); close(); toast("✓ Asked: " + r.number); });
      });
    });
  }

  /* ----- Stock ----- */
  let sfilter = "all", sq = "";
  VIEWS.stock = async (m) => {
    const r = await api.get("stock_list", { q: sq, filter: sfilter });
    m.innerHTML = '<div class="row between"><h1>Stock</h1><div class="row"><button class="btn primary" type="button" id="sAdd">Received stock</button><button class="btn" type="button" id="sImp">Import from billing software</button></div></div>' +
      '<p class="muted small">Stock value about ' + rs(r.value) + '. Customers see only "in stock / few left / not available", never your counts or purchase prices.</p>' +
      '<div class="row"><input id="sQ" placeholder="Search name, generic or barcode" value="' + esc(sq) + '" style="flex:1 1 220px"><div class="tabs">' + [["all", "All"], ["expiring", "Expiring (90 days)"], ["expired", "Expired"], ["out", "Out"], ["cold", "Cold chain"]].map(([k, l]) => '<button type="button" data-sf="' + k + '" aria-pressed="' + (k === sfilter) + '">' + l + "</button>").join("") + "</div></div>" +
      '<div class="card table-scroll"><table><thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Price / MRP</th><th></th></tr></thead><tbody>' +
      (r.stock.map(s => { const exp = s.exp ? new Date(s.exp) : null, soon = exp && exp - Date.now() < 90 * 864e5, gone = exp && exp < Date.now();
        return '<tr data-sid="' + s.stockId + '"><td><b>' + esc(s.name) + "</b>" + (s.cold ? " ❄" : "") + ' <small class="muted">' + esc(s.schedule) + "</small><br><small class=\"muted\">" + esc(s.generic || "") + "</small></td><td class=\"mono\">" + esc(s.batch || "—") + "</td><td>" + (exp ? '<span class="pill ' + (gone ? "bad" : soon ? "warn" : "") + '">' + esc(day(s.exp)) + "</span>" : "—") + "</td><td><b>" + s.qty + "</b></td><td>" + (s.price !== null ? rs(s.price) : "—") + ' <small class="muted">/ ' + (s.mrp !== null ? rs(s.mrp) : "—") + '</small></td><td class="row"><button class="btn sm" data-cnt>Count</button>' + (s.qty > 0 && (gone || true) ? '<button class="btn sm" data-rm>Remove</button>' : "") + "</td></tr>"; }).join("") || '<tr><td colspan="6" class="muted">Nothing here.</td></tr>') + "</tbody></table></div>" +
      '<div class="card stack"><h2>Billing software sync</h2><p class="muted small">Install the AushadhBox sync agent (Java, Windows/Linux) on the billing computer. It sends the closing-stock export from Marg, GoFrugal, Medeil, Tally and others every few minutes, so customers always see the right availability.</p><ul class="list" id="toks"></ul><div class="row"><button class="btn" type="button" id="tokNew">Make a sync key</button></div></div>';
    let timer; $("#sQ").addEventListener("input", (e) => { clearTimeout(timer); timer = setTimeout(() => { sq = e.target.value.trim(); go("stock"); }, 400); });
    $$("[data-sf]", m).forEach(b => b.addEventListener("click", () => { sfilter = b.dataset.sf; go("stock"); }));
    $("#sAdd").addEventListener("click", stockAddDialog); $("#sImp").addEventListener("click", importDialog);
    m.querySelector("tbody").addEventListener("click", (e) => {
      const tr = e.target.closest("[data-sid]"); if (!tr) return; const sid = Number(tr.dataset.sid);
      if (e.target.closest("[data-cnt]")) dialog('<h2>Count</h2><form class="stack" id="cF"><label class="field">Quantity on the shelf<input id="cQ" inputmode="numeric" required></label><label class="field">Selling price (₹, not above MRP)<input id="cP" inputmode="decimal"></label><button class="btn primary">Save</button></form>', (b) =>
        $("#cF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("stock_count", { stock_id: sid, qty: Number($("#cQ", b).value), price: $("#cP", b).value || null }); close(); go("stock"); }); }));
      if (e.target.closest("[data-rm]")) dialog('<h2>Remove from stock</h2><form class="stack" id="rmF"><label class="field">Why<select id="rmW"><option value="expire">Expired (return to distributor or dispose safely)</option><option value="damaged">Damaged</option></select></label><label class="field">Note<input id="rmN" maxlength="60" placeholder="Returned to distributor, debit note no."></label><button class="btn danger">Remove all of this batch</button></form>', (b) =>
        $("#rmF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("stock_expire", { stock_id: sid, why: $("#rmW", b).value, note: $("#rmN", b).value }); close(); go("stock"); }); }));
    });
    const t = await api.get("sync_tokens");
    $("#toks").innerHTML = t.tokens.map(k => "<li><span>" + esc(k.name) + '<br><small class="muted">' + (k.last_used ? "last sync " + esc(when(k.last_used)) + " · " + (k.last_rows ?? 0) + " rows" : "not used yet") + '</small></span><button class="btn sm danger" data-rev="' + k.id + '">Revoke</button></li>').join("") || '<li class="muted">No sync key yet.</li>';
    $("#toks").addEventListener("click", (e) => { const b = e.target.closest("[data-rev]"); if (b) run(b, async () => { await api.post("sync_token_revoke", { id: Number(b.dataset.rev) }); go("stock"); }); });
    $("#tokNew").addEventListener("click", () => run(null, async () => {
      const r = await api.post("sync_token_new", { name: "Billing computer" });
      dialog('<h2>Sync key</h2><p>Copy this into <span class="mono">agent.properties</span> on the billing computer. It is shown only once.</p><pre class="card mono small" style="white-space:pre-wrap;word-break:break-all">url=' + esc(r.url) + "\ntoken=" + esc(r.token) + "\nfolder=C:\\\\Marg\\\\Export\nfull=true</pre>");
    }));
  };
  function stockAddDialog() {
    dialog('<h2>Received stock</h2><form class="stack" id="saF"><div class="grid two"><label class="field">Medicine name and strength<input id="saN" required></label><label class="field">Generic / composition<input id="saG"></label><label class="field">Batch<input id="saB" required></label><label class="field">Expiry (MM/YY)<input id="saE" required placeholder="08/28"></label>' +
      '<label class="field">Quantity (strips / bottles)<input id="saQ" inputmode="numeric" required></label><label class="field">MRP (₹)<input id="saM" inputmode="decimal" required></label><label class="field">Selling price (₹)<input id="saP" inputmode="decimal"></label><label class="field">Schedule<select id="saS"><option value="">Guess for me</option><option>OTC</option><option>G</option><option>H</option><option>H1</option><option>X</option></select></label>' +
      '<label class="field">Barcode<input id="saBc" inputmode="numeric"></label><label class="field">Supplier invoice no.<input id="saI" maxlength="60"></label></div><label class="check"><input type="checkbox" id="saC"> <span>Keep at 2–8 °C (insulin, vaccines)</span></label><button class="btn primary">Add</button><p class="error" id="saErr" hidden></p></form>', (b) =>
      $("#saF", b).addEventListener("submit", async (ev) => { ev.preventDefault(); const v = (id) => $(id, b).value.trim();
        try { await api.post("stock_add", { name: v("#saN"), generic: v("#saG"), batch: v("#saB"), exp: v("#saE"), qty: Number(v("#saQ")), mrp: Number(v("#saM")), price: v("#saP") ? Number(v("#saP")) : null, schedule: v("#saS") || null, barcode: v("#saBc"), invoice: v("#saI"), cold: $("#saC", b).checked });
          close(); toast("✓ Added"); go("stock"); } catch (er) { $("#saErr", b).textContent = er.message; $("#saErr", b).hidden = false; } }));
  }
  function importDialog() {
    dialog('<h2>Import stock</h2><p class="muted small">Export "closing stock" or "stock with batch" as CSV from your billing software. Columns are found by name: item name, batch, expiry, quantity, MRP, rate, barcode, schedule. Prices above MRP are capped.</p><form class="stack" id="imF"><input type="file" id="imFile" accept=".csv,text/csv" required><label class="check"><input type="checkbox" id="imFull" checked> <span>This is the whole stock (batches not in the file become zero)</span></label><button class="btn primary">Import</button><p id="imMsg"></p></form>', (b) =>
      $("#imF", b).addEventListener("submit", async (ev) => { ev.preventDefault(); const f = $("#imFile", b).files[0]; if (!f) return;
        try { const r = await api.post("stock_import", { csv: await f.text(), full: $("#imFull", b).checked }); $("#imMsg", b).textContent = "✓ " + r.rows + " rows imported, " + r.skipped + " skipped, " + r.zeroed + " set to zero" + (r.priceCapped ? ", " + r.priceCapped + " prices capped at MRP" : "") + "."; }
        catch (er) { $("#imMsg", b).innerHTML = errBox(er.message); } }));
  }

  /* ----- Network ----- */
  VIEWS.network = async (m) => {
    const n = await api.get("network");
    m.innerHTML = '<h1>Pharmacy network</h1><p class="muted">Link with nearby pharmacies to help each other: when a customer asks for something you don\'t have, pass the order on (they sell and deliver; you get the referral share), or move stock between your own shops.</p>' +
      '<div class="grid two"><form class="card stack" id="nfF"><h2>Who has this medicine?</h2><div class="row end"><label class="field" style="flex:1 1 200px">Medicine<input id="nfN" required></label><label class="field" style="width:90px">Qty<input id="nfQ" inputmode="numeric" value="1"></label><button class="btn primary">Find</button></div><ul class="list" id="nfR"></ul></form>' +
      '<div class="card stack"><h2>Linked pharmacies</h2><ul class="list" id="lnk">' + (n.links.map(l => "<li><span><b>" + esc(l.pharmacy.name) + '</b> <span class="pill ' + (l.status === "active" ? "" : l.status === "pending" ? "warn" : "grey") + '">' + esc(l.status) + "</span><br><small class=\"muted\">" + esc([l.pharmacy.village, l.pharmacy.district, l.pharmacy.distanceKm !== null ? l.pharmacy.distanceKm + " km" : null, "referral " + l.referralPct + "%"].filter(Boolean).join(" · ")) + "</small>" +
        (l.pharmacy.phone ? ' <a class="small" href="tel:+91' + esc(l.pharmacy.phone) + '">call</a>' : "") + '</span><span class="acts">' + (l.status === "pending" && l.incoming ? '<button class="btn sm primary" data-lr="' + l.id + '" data-a="accept">Accept</button><button class="btn sm" data-lr="' + l.id + '" data-a="decline">Decline</button>' : "") +
        (l.status === "active" ? '<button class="btn sm" data-lr="' + l.id + '" data-a="end">End</button>' : "") + "</span></li>").join("") || '<li class="muted">No links yet.</li>') + "</ul>" +
        '<form class="row end" id="lnF"><label class="field" style="flex:1 1 160px">Link with shop code (on their QR poster)<input id="lnC" class="mono" maxlength="8" style="text-transform:uppercase" required></label><label class="field" style="width:110px">Referral %<input id="lnP" inputmode="decimal" value="2"></label><button class="btn">Ask</button></form></div>' +
      '<div class="card stack"><h2>Stock transfers</h2><ul class="list" id="trs">' + (n.transfers.map(t => "<li><span><b>" + esc(t.number) + "</b> " + esc(t.outgoing ? "to " + t.to : "from " + t.from) + ' <span class="pill ' + (t.status === "received" ? "" : t.status === "declined" ? "grey" : "warn") + '">' + esc(t.status) + "</span><br><small class=\"muted\">" + esc(t.items.map(i => i.name + " × " + i.qty).join(", ")) + (t.value ? " · " + rs(t.value) : "") + '</small></span><span class="acts">' +
        (t.outgoing && t.status === "asked" ? '<button class="btn sm primary" data-ts="' + t.id + '" data-step="send">Send</button><button class="btn sm" data-ts="' + t.id + '" data-step="decline">Decline</button>' : "") + (!t.outgoing && t.status === "sent" ? '<button class="btn sm primary" data-ts="' + t.id + '" data-step="received">Received</button>' : "") + "</span></li>").join("") || '<li class="muted">No transfers.</li>') + "</ul>" +
        '<p class="muted small">Between shops of your business, or from a pharmacy that holds a wholesale licence (Form 20B/21B). Otherwise pass the order on instead.</p></div>' +
      '<div class="card stack"><h2>Nearby pharmacies on AushadhBox</h2><ul class="list">' + (n.nearby.map(p => "<li><span><b>" + esc(p.name) + "</b><br><small class=\"muted\">" + esc([p.village, p.district, p.distanceKm !== null ? p.distanceKm + " km" : null].filter(Boolean).join(" · ")) + '</small></span><span class="mono small">' + esc(p.code) + "</span></li>").join("") || '<li class="muted">Set your shop\'s location in Settings to see nearby pharmacies.</li>') + "</ul></div></div>";
    $("#nfF").addEventListener("submit", async (e) => { e.preventDefault(); const r = await api.post("network_find", { name: $("#nfN").value, qty: Number($("#nfQ").value) || 1 });
      $("#nfR").innerHTML = r.results.map(x => "<li><span><b>" + esc(x.name) + "</b> " + (x.linked ? '<span class="pill info">linked</span>' : "") + "<br><small class=\"muted\">" + esc([x.matched, x.distanceKm !== null ? x.distanceKm + " km" : null, x.count !== null ? x.count + " in stock" : x.status, x.price ? rs(x.price) : null].filter(Boolean).join(" · ")) + "</small></span>" + (x.phone ? '<a class="btn sm" href="tel:+91' + esc(x.phone) + '">Call</a>' : "") + "</li>").join("") || '<li class="muted">Nobody nearby has it.</li>'; });
    $("#lnk").addEventListener("click", (e) => { const b = e.target.closest("[data-lr]"); if (b) run(b, async () => { await api.post("link_respond", { id: Number(b.dataset.lr), answer: b.dataset.a }); go("network"); }); });
    $("#lnF").addEventListener("submit", (e) => { e.preventDefault(); run(null, async () => { await api.post("link_request", { code: $("#lnC").value, share_stock: true, referral_pct: Number($("#lnP").value) }); toast("✓ Request sent"); go("network"); }); });
    $("#trs").addEventListener("click", (e) => { const b = e.target.closest("[data-ts]"); if (b) run(b, async () => { await api.post("transfer_step", { id: Number(b.dataset.ts), step: b.dataset.step }); toast("✓ Done"); go("network"); }); });
  };

  /* ----- Customers & khata ----- */
  VIEWS.customers = async (m) => {
    const k = await api.get("khata_list");
    m.innerHTML = '<div class="row between"><h1>Customers & khata</h1><button class="btn primary" type="button" id="cAdd">Add customer</button></div>' +
      '<form class="row end" id="cfF"><label class="field" style="flex:1 1 240px">Phone number, name, village or referral code<input id="cfQ" required></label><button class="btn">Find</button></form><ul class="list card" id="cfR" hidden></ul>' +
      '<div class="card stack"><h2>Credit book: who owes</h2><p class="muted small">Total ' + rs(k.khata.reduce((s, x) => s + x.balance, 0)) + '. Reminders go by app notification or SMS (with consent), at most every 3 days.</p><div class="table-scroll"><table><thead><tr><th>Customer</th><th>Owes</th><th>Limit</th><th>Since</th><th></th></tr></thead><tbody id="kh">' +
      (k.khata.map(x => '<tr><td><button class="link" data-cid="' + x.customerId + '">' + esc(x.name) + '</button><br><small class="muted">' + esc(x.village || "") + "</small></td><td><b>" + rs(x.balance) + "</b></td><td>" + rs(x.limit) + "</td><td>" + esc(day(x.since)) + '</td><td><button class="btn sm" data-rem="' + x.customerId + '">Remind</button></td></tr>').join("") || '<tr><td colspan="5" class="muted">Nobody owes anything.</td></tr>') + "</tbody></table></div></div>" +
      '<div class="card stack"><h2>Referrals</h2><p class="muted small">Customers earn your referral reward when a friend they brought pays for a first order.</p><ul class="list" id="refs"></ul></div>';
    $("#cAdd").addEventListener("click", () => customerAddDialog());
    $("#cfF").addEventListener("submit", async (e) => { e.preventDefault(); const r = await api.get("customer_find", { q: $("#cfQ").value }); const ul = $("#cfR"); ul.hidden = false;
      ul.innerHTML = r.customers.map(c => '<li><span><button class="link" data-cid="' + c.id + '">' + esc(c.name) + "</button> " + (c.app ? '<span class="pill info">app</span>' : "") + "<br><small class=\"muted\">" + esc([c.phone, c.village].filter(Boolean).join(" · ")) + "</small></span><span>" + (c.khata ? '<span class="pill warn">owes ' + rs(c.khata) + "</span> " : "") + (c.points ? '<span class="pill">' + c.points + " pts</span>" : "") + "</span></li>").join("") || '<li class="muted">Not found. Add them as a new customer.</li>'; });
    m.addEventListener("click", (e) => { const c = e.target.closest("[data-cid]"); if (c) customerDialog(Number(c.dataset.cid)); const r = e.target.closest("[data-rem]"); if (r) run(r, async () => { const x = await api.post("khata_remind", { customer_id: Number(r.dataset.rem) }); toast("✓ Reminder sent" + (x.sms ? " (SMS " + x.sms + ")" : "")); }); });
    const rf = await api.get("referrals");
    $("#refs").innerHTML = rf.referrals.map(r => "<li><span>" + esc(r.referrer) + " → " + esc(r.referred) + '</span><span class="acts"><span class="pill ' + (r.status === "earned" ? "warn" : r.status === "paid" ? "" : "grey") + '">' + esc(r.status) + (r.amount > 0 ? " " + rs(r.amount) + " " + esc(r.payout) : "") + "</span>" + (r.status === "earned" && r.payout === "cash" ? '<button class="btn sm" data-rp="' + r.id + '">Paid</button>' : "") + "</span></li>").join("") || '<li class="muted">None yet.</li>';
    $("#refs").addEventListener("click", (e) => { const b = e.target.closest("[data-rp]"); if (b) run(b, async () => { await api.post("referral_payout", { id: Number(b.dataset.rp) }); go("customers"); }); });
  };
  function customerAddDialog() {
    dialog('<h2>Add customer</h2><form class="stack" id="caF"><div class="grid two"><label class="field">Name<input id="caN" maxlength="80" required></label><label class="field">Mobile number<input id="caP" inputmode="tel" maxlength="13"></label><label class="field">Village / area<input id="caV" maxlength="80"></label><label class="field">Address (for delivery)<input id="caA" maxlength="200"></label><label class="field">Friend\'s referral code<input id="caR" maxlength="6" style="text-transform:uppercase"></label></div>' +
      '<label class="check"><input type="checkbox" id="caS"> <span>The customer agrees to SMS about orders and khata</span></label><button class="btn primary">Add</button><p class="error" id="caE" hidden></p></form>', (b) =>
      $("#caF", b).addEventListener("submit", async (ev) => { ev.preventDefault();
        try { const r = await api.post("customer_add", { name: $("#caN", b).value, phone: $("#caP", b).value, village: $("#caV", b).value, address: $("#caA", b).value || null, referral_code: $("#caR", b).value || null, sms_ok: $("#caS", b).checked }); close(); customerDialog(r.id); }
        catch (er) { $("#caE", b).textContent = er.message; $("#caE", b).hidden = false; } }));
  }
  async function customerDialog(id) {
    const r = await api.get("customer_get", { id }), c = r.customer, k = r.khata;
    dialog('<h2>' + esc(c.name) + '</h2><p class="muted small">' + esc([c.phone, c.village, c.app ? "uses the AushadhBox app" : null, "referral code " + c.refCode].filter(Boolean).join(" · ")) + "</p>" +
      '<div class="kpis">' + kpi(rs(k ? k.balance : 0), "owes (khata)", k && k.balance ? "alert" : "") + kpi(r.points, "reward points") + kpi(r.orders.length, "orders here") + "</div>" +
      '<div class="row"><button class="btn primary" type="button" id="cdSale">New sale</button><button class="btn" type="button" id="cdCredit">Add to khata</button><button class="btn" type="button" id="cdPay"' + (k && k.balance ? "" : " disabled") + '>Khata payment</button><button class="btn" type="button" id="cdBonus">Bonus points</button>' +
      (["owner", "manager"].includes(S.staff.role) ? '<button class="btn" type="button" id="cdLimit">Credit limit</button>' : "") + "</div>" +
      (k ? '<h3>Credit book</h3><ul class="list">' + k.entries.map(e => '<li><span>' + (e.kind === "credit" ? "＋ bought on credit " : "－ paid ") + "<b>" + rs(e.amount) + "</b>" + (e.order ? " · " + esc(e.order) : "") + (e.note ? ' <small class="muted">' + esc(e.note) + "</small>" : "") + '</span><small class="muted">' + esc(when(e.at)) + "</small></li>").join("") + "</ul>" : "") +
      "<h3>Orders</h3><ul class=\"list\">" + (r.orders.map(o => "<li><span>" + esc(o.number) + " · " + rs(o.total) + '</span><small class="muted">' + esc(o.status + " · " + when(o.at)) + "</small></li>").join("") || '<li class="muted">None</li>') + "</ul>", (b) => {
      $("#cdSale", b).addEventListener("click", () => newOrderDialog({ id: c.id, name: c.name, phoneFull: "" }));
      const amountDlg = (title, fields, fn) => dialog("<h2>" + esc(title) + '</h2><form class="stack" id="amF"><label class="field">Amount (₹)<input id="amA" inputmode="decimal" required></label>' + (fields || "") + '<button class="btn primary">Save</button><p class="error" id="amE" hidden></p></form>', (d) =>
        $("#amF", d).addEventListener("submit", async (ev) => { ev.preventDefault(); try { await fn(d, Number($("#amA", d).value)); customerDialog(id); } catch (er) { $("#amE", d).textContent = er.message; $("#amE", d).hidden = false; } }));
      $("#cdCredit", b).addEventListener("click", () => amountDlg("Add to khata", '<label class="field">Note<input id="amN" maxlength="200" placeholder="What was bought"></label>', (d, a) => api.post("khata_credit", { customer_id: id, amount: a, note: $("#amN", d).value })));
      $("#cdPay", b).addEventListener("click", () => amountDlg("Khata payment", '<label class="field">How<select id="amM"><option value="cash">Cash</option><option value="upi_phone">UPI to our number</option><option value="upi_qr">UPI (our QR)</option><option value="other_qr">Other QR</option></select></label><label class="field">UPI reference<input id="amU" maxlength="22"></label>',
        (d, a) => api.post("khata_pay", { customer_id: id, amount: a, method: $("#amM", d).value, utr: $("#amU", d).value || null })));
      $("#cdBonus", b).addEventListener("click", () => amountDlg("Bonus points (1 point = ₹1)", "", (d, a) => api.post("points_add", { customer_id: id, points: a })));
      const lim = $("#cdLimit", b); if (lim) lim.addEventListener("click", () => dialog('<h2>Credit limit</h2><form class="stack" id="lmF"><label class="field">Limit (₹)<input id="lmL" inputmode="decimal" value="' + (k ? k.limit : 2000) + '"></label><label class="field">Reminder day of the month (after harvest or salary)<input id="lmD" inputmode="numeric" value="' + (k ? k.remindDay : 5) + '"></label><label class="field">Status<select id="lmS"><option value="open">Open</option><option value="closed">Closed (no more credit)</option></select></label><button class="btn primary">Save</button></form>', (d) =>
        $("#lmF", d).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("khata_settings", { customer_id: id, limit: Number($("#lmL", d).value), remind_day: Number($("#lmD", d).value), status: $("#lmS", d).value }); customerDialog(id); }); })));
    });
  }

  /* ----- Payments ----- */
  VIEWS.payments = async (m) => {
    const r = await api.get("pay_claims");
    badges.payments = r.claims.length; renderNav();
    m.innerHTML = '<h1>Payments</h1><div class="kpis">' + (r.today.map(t => kpi(rs(t.v), (METHOD[t.method] || t.method) + " today (" + t.n + ")")).join("") || kpi(rs(0), "received today")) + "</div>" +
      '<div class="card stack"><h2>To confirm</h2><p class="muted small">Payments recorded by customers (UPI to your number, someone else\'s QR, cash) or by your delivery staff. Check your UPI app or cash box, then confirm. Only confirmed payments count in the books.</p><ul class="list" id="cl">' +
      (r.claims.map(c => "<li><span><b>" + rs(c.amount) + "</b> · " + esc(METHOD[c.method] || c.method) + (c.utr ? ' <span class="mono small">UTR ' + esc(c.utr) + "</span>" : "") + "<br><small class=\"muted\">" + esc([c.customer, c.order, (c.note || "").replace("[khata]", "khata payment"), "by " + c.by_whom, when(c.created_at)].filter(Boolean).join(" · ")) + '</small></span><span class="acts"><button class="btn sm primary" data-pd="' + c.id + '" data-ok="1">Received</button><button class="btn sm danger" data-pd="' + c.id + '" data-ok="0">Not received</button></span></li>').join("") || '<li class="muted">Nothing to confirm.</li>') + "</ul></div>" +
      '<div class="card stack"><h2>Your UPI</h2><p>' + (S.branch.upiVpa ? "Customers pay to <b>" + esc(S.branch.upiVpa) + "</b>. The app makes a QR with the exact amount for every order, so you don't need a printed QR." : 'Add your UPI ID (or your UPI phone number) in <button class="link" data-go="settings" type="button">Settings</button> so customers can pay by QR.') + "</p></div>";
    $("#cl").addEventListener("click", (e) => { const b = e.target.closest("[data-pd]"); if (b) run(b, async () => { await api.post("pay_decide", { id: Number(b.dataset.pd), ok: b.dataset.ok === "1" }); go("payments"); }); });
  };

  /* ----- Delivery ----- */
  VIEWS.delivery = async (m) => {
    const d = await api.get("delivery_setup");
    m.innerHTML = '<h1>Delivery</h1><div class="grid two"><div class="card stack"><h2>Out now</h2><ul class="list">' + (d.live.map(x => "<li><span><b>" + esc(x.number) + "</b> · " + esc(x.mode === "own" ? x.rider : x.partner || x.courier) + '<br><small class="muted">' + esc(x.status + (x.last_at ? " · seen " + when(x.last_at) : "")) + "</small></span>" + (x.last_lat ? '<a class="btn sm" target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + x.last_lat + "," + x.last_lng + '">Map</a>' : "") + "</li>").join("") || '<li class="muted">Nothing out for delivery.</li>') + "</ul></div>" +
      '<div class="card stack"><h2>Your delivery rules</h2><p>Normal area <b>' + S.branch.radiusKm + " km</b>, up to <b>" + S.branch.maxKm + " km</b> with a distance fee (" + rs(S.branch.feeBase) + " + " + rs(S.branch.feePerKm) + "/km, free above " + rs(S.branch.freeAbove) + ").</p>" +
        '<p class="muted small">Beyond ' + S.branch.maxKm + " km, a trip is made when it is worth it: the order's margin and fee cover the trip (" + rs(S.branch.costPerKm) + "/km both ways), or it's an essential medicine (insulin, anti-epileptics, inhalers…), or other deliveries go the same way, or the customer pays the full trip. You can always make an exception with a reason.</p>" +
        '<p class="muted small">Home delivery is only within your district, and never for Schedule H1, X or narcotic medicines.</p><button class="btn" type="button" data-go="settings">Change rules</button></div>' +
      '<div class="card stack"><h2>Delivery staff</h2><ul class="list">' + (d.riders.map(r => "<li><span>" + esc(r.name) + '</span><small class="muted">' + esc(r.phone || "") + "</small></li>").join("") || '<li class="muted">Add delivery staff in Staff (role: rider). They use the rider app at /rider.</li>') + "</ul></div>" +
      '<div class="card stack"><h2>Village pickup points</h2><p class="muted small">A kirana shop, the ASHA worker\'s home, the panchayat office or the bus stand where your rider drops orders on fixed days.</p><ul class="list">' + (d.points.map(p => "<li><span><b>" + esc(p.village) + "</b> · " + esc(p.name) + '<br><small class="muted">' + esc([p.kind, p.contact, p.days].filter(Boolean).join(" · ")) + "</small></span>" + (Number(p.active) ? "" : '<span class="pill grey">off</span>') + "</li>").join("") || '<li class="muted">None yet.</li>') + "</ul>" +
        '<form class="grid two" id="ppF"><label class="field">Village<input id="ppV" required></label><label class="field">Place<input id="ppN" required placeholder="Selvi (ASHA)"></label><label class="field">Kind<select id="ppK"><option value="shop">Shop</option><option value="asha">ASHA / health worker</option><option value="panchayat">Panchayat office</option><option value="school">School</option><option value="busstand">Bus stand</option><option value="post">Post office</option></select></label><label class="field">Days<input id="ppD" placeholder="Tue,Fri"></label><label class="field">Contact<input id="ppC"></label><label class="field">Phone<input id="ppP" inputmode="tel"></label><button class="btn">Add point</button></form></div></div>';
    $("#ppF").addEventListener("submit", (e) => { e.preventDefault(); run(null, async () => { await api.post("point_save", { village: $("#ppV").value, name: $("#ppN").value, kind: $("#ppK").value, days: $("#ppD").value, contact: $("#ppC").value, phone: $("#ppP").value }); go("delivery"); }); });
  };

  /* ----- Rider: my deliveries ----- */
  VIEWS.rides = async (m) => {
    const r = await api.get("rides");
    m.innerHTML = "<h1>My deliveries</h1>" + (r.rides.length ? r.rides.map((o, i) => '<div class="order" data-ride="' + o.id + '"><div class="order-head"><b>' + (i + 1) + ". " + esc(o.number) + "</b><span>" + rs(o.cod) + ' to collect</span></div><p>' + esc(o.customer ? o.customer.name : "") + (o.customer && o.customer.phone ? ' · <a href="tel:+91' + esc(o.customer.phone) + '">' + esc(o.customer.phone) + "</a>" : "") + "</p><p class=\"small\">📍 " + esc(o.address || (o.point ? o.point.village + " · " + o.point.name : "")) + "</p><ul>" + o.items.map(it => "<li>" + esc(it.name) + " × " + it.qty + "</li>").join("") + '</ul><div class="row">' + (o.mapUrl ? '<a class="btn" target="_blank" rel="noopener" href="' + esc(o.mapUrl) + '">Directions</a>' : "") + '<button class="btn primary" data-rd="1">Delivered</button><button class="btn danger" data-rf="1">Could not deliver</button></div></div>').join("") : '<p class="muted">No deliveries right now.</p>');
    m.addEventListener("click", (e) => {
      const c = e.target.closest("[data-ride]"); if (!c) return; const id = Number(c.dataset.ride), o = r.rides.find(x => x.id === id);
      if (e.target.closest("[data-rd]")) dialog('<h2>' + esc(o.number) + '</h2><form class="stack" id="rdF"><label class="field">Code from the customer<input id="rdO" class="big-otp" inputmode="numeric" maxlength="4" required></label><label class="field">Cash collected (₹)<input id="rdC" inputmode="decimal" value="' + o.cod + '"></label><button class="btn primary">Delivered</button></form>', (b) =>
        $("#rdF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("ride_done", { order_id: id, otp: $("#rdO", b).value, cash: Number($("#rdC", b).value) || 0 }); close(); toast("✓ Delivered"); go("rides"); }); }));
      if (e.target.closest("[data-rf]")) dialog('<h2>Could not deliver</h2><form class="stack" id="rfF"><label class="field">Why<select id="rfW"><option>Customer not at home</option><option>Wrong address</option><option>Customer refused</option><option>No prescription shown</option><option>Road blocked / rain</option></select></label><button class="btn danger">Bring back to the shop</button></form>', (b) =>
        $("#rfF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("ride_failed", { order_id: id, reason: $("#rfW", b).value }); close(); go("rides"); }); }));
    });
    if (r.rides.length && navigator.geolocation) navigator.geolocation.getCurrentPosition(p => r.rides.forEach(o => api.post("ride_ping", { order_id: o.id, lat: +p.coords.latitude.toFixed(5), lng: +p.coords.longitude.toFixed(5) }).catch(() => {})), () => {}, { timeout: 10000 });
  };

  /* ----- Fridge sensors ----- */
  VIEWS.devices = async (m) => {
    const r = await api.get("devices");
    m.innerHTML = '<div class="row between"><h1>Fridge sensors</h1><button class="btn primary" type="button" id="dvAdd">Add a sensor</button></div><p class="muted">Insulin and vaccines must stay at 2–8 °C. The sensor (ESP32 + DS18B20, about ₹600 of parts: see the build guide) reports every 5 minutes and beeps when the fridge is out of range. The daily log is your proof for inspections.</p>' +
      (r.devices.map(d => { const vals = d.today.map(x => +x.c), lo = Math.min(d.min - 1, ...vals), hi = Math.max(d.max + 1, ...vals), sp = hi - lo || 1, W = 300, H = 70;
        const pts = vals.map((v, i) => (i * W / Math.max(1, vals.length - 1)).toFixed(1) + "," + (H - (v - lo) * H / sp).toFixed(1)).join(" ");
        return '<div class="card stack"><div class="row between"><h2>' + esc(d.name) + '</h2><span class="pill ' + (d.state === "ok" ? "" : d.state === "unpaired" ? "grey" : "bad") + '">' + esc(d.state) + "</span></div>" +
          (d.pairCode ? '<p>Pairing code: <b class="mono">' + esc(d.pairCode) + "</b> (type it into the sensor's setup page within 30 minutes)</p>" : "") +
          "<p><b>" + (d.temp !== null ? d.temp + " °C" : "—") + "</b> · range " + d.min + "–" + d.max + " °C" + (d.battery !== null ? " · battery " + d.battery + "%" : "") + (d.lastSeen ? " · " + esc(when(d.lastSeen)) : "") + "</p>" +
          (vals.length > 1 ? '<svg class="spark" viewBox="0 0 300 70" preserveAspectRatio="none" role="img" aria-label="Last 24 hours"><rect x="0" y="0" width="300" height="' + Math.max(0, H - (d.max - lo) * H / sp).toFixed(1) + '"/><rect x="0" y="' + (H - (d.min - lo) * H / sp).toFixed(1) + '" width="300" height="70"/><polyline points="' + pts + '"/></svg>' : "") +
          '<details><summary>30-day log</summary><table><thead><tr><th>Day</th><th>Min</th><th>Max</th><th>Avg</th><th>Readings</th></tr></thead><tbody>' + d.log.map(l => "<tr><td>" + esc(day(l.d)) + '</td><td class="' + (l.lo < d.min ? "error" : "") + '">' + l.lo + '</td><td class="' + (l.hi > d.max ? "error" : "") + '">' + l.hi + "</td><td>" + l.avg + "</td><td>" + l.n + "</td></tr>").join("") + "</tbody></table></details></div>"; }).join("") || '<p class="muted">No sensors yet.</p>');
    $("#dvAdd").addEventListener("click", () => dialog('<h2>Add a fridge sensor</h2><form class="stack" id="daF"><label class="field">Name<input id="daN" required placeholder="Insulin fridge"></label><button class="btn primary">Get a pairing code</button></form>', (b) =>
      $("#daF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { const x = await api.post("device_add", { name: $("#daN", b).value }); $("#dlgBody").innerHTML = '<h2>Pairing code</h2><p class="big-otp">' + esc(x.pairCode) + "</p><p>Power the sensor, connect to its Wi-Fi \"AushadhBox-Fridge\", open 192.168.4.1 and type this code with your shop's Wi-Fi details.</p>"; }); })));
  };

  /* ----- Staff ----- */
  VIEWS.staff = async (m) => {
    const r = await api.get("staff_list");
    m.innerHTML = '<div class="row between"><h1>Staff</h1><button class="btn primary" type="button" id="stAdd">Add a person</button></div><p class="muted">Each person signs in with their own phone or e-mail, so every action is recorded with their name. Riders see only their deliveries; cashiers can sell and take payments but not change stock or prices.</p>' +
      '<div class="card"><ul class="list" id="stl">' + r.staff.map(s => "<li><span><b>" + esc(s.name) + '</b> <span class="pill info">' + esc(s.role) + "</span>" + (s.twoFactor ? ' <span class="pill">2-step</span>' : "") + (s.status !== "active" ? ' <span class="pill bad">blocked</span>' : "") + "<br><small class=\"muted\">" + esc([s.phone, s.email, s.lastLogin ? "last sign-in " + when(s.lastLogin) : "never signed in"].filter(Boolean).join(" · ")) + '</small></span><span class="acts">' +
        (s.role !== "owner" ? '<button class="btn sm" data-sr="' + s.id + '">Reset password</button><button class="btn sm ' + (s.status === "active" ? "danger" : "") + '" data-sb="' + s.id + '" data-st="' + (s.status === "active" ? "blocked" : "active") + '" data-role="' + s.role + '">' + (s.status === "active" ? "Block" : "Unblock") + "</button>" : "") + "</span></li>").join("") + "</ul></div>" +
      '<div class="card stack"><h2>Your sign-in</h2><p>Two-step sign-in: ' + (S.staff.twoFactor ? '<b>on</b> <button class="btn sm" type="button" id="tfOff">Turn off</button>' : '<b>off</b> <button class="btn sm primary" type="button" id="tfOn">Turn on</button> <span class="muted small">Recommended for owners and managers.</span>') + '</p>' +
        '<form class="row end" id="pwF"><label class="field">Current password<input type="password" id="pwC" required></label><label class="field">New password<input type="password" id="pwN" minlength="8" required></label><button class="btn">Change password</button></form></div>';
    $("#stAdd").addEventListener("click", () => dialog('<h2>Add a person</h2><form class="stack" id="saF2"><div class="grid two"><label class="field">Name<input id="pN" required></label><label class="field">Role<select id="pR"><option value="pharmacist">Pharmacist</option><option value="cashier">Cashier</option><option value="rider">Delivery (rider)</option>' + (S.staff.role === "owner" ? '<option value="manager">Manager</option>' : "") + '</select></label><label class="field">Mobile number<input id="pP" inputmode="tel"></label><label class="field">E-mail (optional)<input id="pE" type="email"></label></div><button class="btn primary">Add</button><p class="error" id="pErr" hidden></p></form>', (b) =>
      $("#saF2", b).addEventListener("submit", async (ev) => { ev.preventDefault(); try { const x = await api.post("staff_add", { name: $("#pN", b).value, role: $("#pR", b).value, phone: $("#pP", b).value, email: $("#pE", b).value || null });
        $("#dlgBody").innerHTML = "<h2>Added</h2><p>First password (shown once): <b class=\"mono\">" + esc(x.password) + "</b></p><p>They sign in at " + esc(location.origin) + (/rider/.test($("#pR", b).value) ? "/rider" : "/app") + " and should change it.</p>"; go("staff"); } catch (er) { $("#pErr", b).textContent = er.message; $("#pErr", b).hidden = false; } })));
    $("#stl").addEventListener("click", (e) => {
      const rb = e.target.closest("[data-sr]"); if (rb) run(rb, async () => { const x = await api.post("staff_update", { id: Number(rb.dataset.sr), reset: true, status: "active" }); dialog('<h2>New password</h2><p class="mono big-otp" style="font-size:1.3rem;letter-spacing:.1em">' + esc(x.password) + "</p><p>Shown once. Two-step sign-in was turned off for this person.</p>"); });
      const bb = e.target.closest("[data-sb]"); if (bb) run(bb, async () => { await api.post("staff_update", { id: Number(bb.dataset.sb), status: bb.dataset.st, role: bb.dataset.role }); go("staff"); });
    });
    const on = $("#tfOn"); if (on) on.addEventListener("click", () => run(on, async () => { const x = await api.post("totp_setup");
      dialog('<h2>Two-step sign-in</h2><p>Scan with Google Authenticator, Microsoft Authenticator or any authenticator app, then type the 6-digit code.</p><div class="qrbox"><canvas width="440" height="440" id="tq"></canvas><span class="mono small">' + esc(x.secret) + '</span></div><form class="row end" id="tfF"><label class="field">Code<input id="tfC" inputmode="numeric" maxlength="6" required></label><button class="btn primary">Turn on</button></form>', (b) => {
        qr($("#tq", b), x.uri); $("#tfF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("totp_enable", { code: $("#tfC", b).value }); close(); S.staff.twoFactor = true; toast("✓ Two-step sign-in is on"); go("staff"); }); }); }); }));
    const off = $("#tfOff"); if (off) off.addEventListener("click", () => dialog('<h2>Turn off two-step sign-in</h2><form class="stack" id="toF"><label class="field">Password<input type="password" id="toP" required></label><button class="btn danger">Turn off</button></form>', (b) =>
      $("#toF", b).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("totp_disable", { password: $("#toP", b).value }); close(); S.staff.twoFactor = false; go("staff"); }); })));
    $("#pwF").addEventListener("submit", (e) => { e.preventDefault(); run(null, async () => { await api.post("password_change", { current: $("#pwC").value, new: $("#pwN").value }); toast("✓ Password changed"); e.target.reset(); }); });
  };

  /* ----- Settings ----- */
  VIEWS.settings = async (m) => {
    const b = S.branch; if (!b) { m.innerHTML = errBox("No shop yet."); return; }
    if (S.staff.role === "rider") { m.innerHTML = '<h1>Settings</h1><div class="card stack"><form class="row end" id="pwF"><label class="field">Current password<input type="password" id="pwC" required></label><label class="field">New password<input type="password" id="pwN" minlength="8" required></label><button class="btn">Change password</button></form></div>';
      $("#pwF").addEventListener("submit", (e) => { e.preventDefault(); run(null, async () => { await api.post("password_change", { current: $("#pwC").value, new: $("#pwN").value }); toast("✓ Password changed"); e.target.reset(); }); }); return; }
    const f = (id, label, val, extra) => '<label class="field">' + label + '<input id="' + id + '" value="' + esc(val ?? "") + '" ' + (extra || "") + "></label>";
    const chk = (id, label, on) => '<label class="check"><input type="checkbox" id="' + id + '"' + (on ? " checked" : "") + "> <span>" + label + "</span></label>";
    m.innerHTML = '<h1>Settings</h1><div class="grid two"><div class="card stack"><h2>Your shop\'s QR</h2><p class="muted small">Customers scan it to pay by UPI, order, check stock, see their khata and points. Shop code <b class="mono">' + esc(b.code) + '</b>.</p><div class="qrbox"><canvas width="440" height="440" id="sq"></canvas></div><div class="row"><a class="btn primary" href="' + esc(b.posterUrl) + '" target="_blank" rel="noopener">Print A4 poster</a><a class="btn" href="' + esc(b.storeUrl) + '" target="_blank" rel="noopener">Open shop page</a></div></div>' +
      '<form class="card stack" id="bsF"><h2>Shop details</h2><div class="grid two">' + f("bName", "Name", b.name, "required") + f("bPhone", "Phone", b.phone) + f("bAddr", "Address", b.address) + f("bVillage", "Village / area", b.village) + f("bDistrict", "District", b.district, "required") + f("bPin", "PIN", b.pincode) +
        '<label class="field">Area<select id="bArea">' + [["urban", "City"], ["semiurban", "Town (semi-urban)"], ["rural", "Village (rural)"]].map(([k, l]) => '<option value="' + k + '"' + (b.area === k ? " selected" : "") + ">" + l + "</option>").join("") + "</select></label>" + f("bHours", "Opening hours", b.hours, 'placeholder="Mon-Sat 08:30-21:30; Sun 09:00-13:00"') +
        f("bPharm", "Pharmacist on duty", b.pharmacist) + f("bRxMail", "E-mail registered for prescriptions", b.rxEmail) + f("bWhole", "Wholesale licence (20B/21B), if any", b.licenceWhole) + f("bLL", "Location (lat, lng)", b.lat !== null ? b.lat + ", " + b.lng : "") + '</div><button class="btn sm" type="button" id="bLoc">Use my current location</button>' +
        "<h2>Payments</h2>" + f("bUpi", "UPI ID (or 10-digit UPI phone number)", b.upiVpa, 'placeholder="shopname@okaxis or 9876543210"') + f("bUpiName", "Name shown in UPI apps", b.upiName) + chk("bCash", "Accept cash", b.acceptsCash) + chk("bKhata", "Keep a credit book (khata)", b.khataOn) +
        '<div class="grid two">' + f("bLoy", "Reward points (% of each paid bill)", b.loyaltyPct, 'inputmode="decimal"') + f("bRef", "Referral reward (₹)", b.referralAmt, 'inputmode="decimal"') + "</div>" +
        "<h2>Delivery</h2>" + chk("bDel", "We deliver", b.delivery) + '<div class="grid two">' + f("bRad", "Normal area (km)", b.radiusKm, 'inputmode="decimal"') + f("bMax", "Furthest with a distance fee (km)", b.maxKm, 'inputmode="decimal"') + f("bFee", "Base fee (₹)", b.feeBase, 'inputmode="decimal"') + f("bPerKm", "Per km after 2 km (₹)", b.feePerKm, 'inputmode="decimal"') +
        f("bFree", "Free delivery above (₹)", b.freeAbove, 'inputmode="decimal"') + f("bCost", "What a km really costs you (₹, fuel and time)", b.costPerKm, 'inputmode="decimal"') + '</div><button class="btn primary">Save</button><p class="error" id="bsE" hidden></p></form>' +
      (S.staff.role === "owner" ? '<form class="card stack" id="baF"><h2>Add another shop</h2><p class="muted small">Each shop has its own licence, QR, stock and staff (or staff for all shops).</p><div class="grid two">' + f("nbName", "Name", "", "required") + f("nbLic", "Retail licence", "", "required") + f("nbVillage", "Village / area", "") + f("nbDistrict", "District", b.district, "required") + f("nbPhone", "Phone", "", "required") + "</div><button class=\"btn\">Add shop</button></form>" : "") + "</div>";
    qr($("#sq"), b.storeUrl);
    $("#bLoc").addEventListener("click", () => navigator.geolocation && navigator.geolocation.getCurrentPosition(p => { $("#bLL").value = p.coords.latitude.toFixed(6) + ", " + p.coords.longitude.toFixed(6); }, () => toast("Could not get the location."), { enableHighAccuracy: true, timeout: 12000 }));
    $("#bsF").addEventListener("submit", async (e) => {
      e.preventDefault(); const v = (id) => $(id).value.trim(), ll = v("#bLL").split(",").map(Number), upi = v("#bUpi");
      try { const r = await api.post("branch_save", { name: v("#bName"), phone: v("#bPhone"), address: v("#bAddr"), village: v("#bVillage"), district: v("#bDistrict"), pincode: v("#bPin"), area: v("#bArea"), hours: v("#bHours"), pharmacist: v("#bPharm"), rx_email: v("#bRxMail"), licence_whole: v("#bWhole"),
        lat: ll.length === 2 && ll.every(isFinite) ? ll[0] : null, lng: ll.length === 2 && ll.every(isFinite) ? ll[1] : null, upi_vpa: upi.includes("@") ? upi : null, upi_phone: upi.includes("@") ? null : upi, upi_name: v("#bUpiName"),
        accepts_cash: $("#bCash").checked, khata_on: $("#bKhata").checked, loyalty_pct: v("#bLoy"), referral_amt: v("#bRef"), delivery_on: $("#bDel").checked, radius_km: v("#bRad"), max_km: v("#bMax"), fee_base: v("#bFee"), fee_per_km: v("#bPerKm"), free_above: v("#bFree"), cost_per_km: v("#bCost") });
        S.branch = r.branch; toast("✓ Saved"); } catch (er) { $("#bsE").textContent = er.message; $("#bsE").hidden = false; }
    });
    const ba = $("#baF"); if (ba) ba.addEventListener("submit", (e) => { e.preventDefault(); run(null, async () => { await api.post("branch_add", { name: $("#nbName").value, licence_retail: $("#nbLic").value, village: $("#nbVillage").value, district: $("#nbDistrict").value, phone: $("#nbPhone").value }); boot(); }); });
  };

  /* ----- Reports: complaints and audit log ----- */
  VIEWS.reports = async (m) => {
    const month = (VIEWS.reports.month = VIEWS.reports.month || new Date().toISOString().slice(0, 7));
    const [c, a, f] = await Promise.all([api.get("complaints"), api.get("audit"), api.get("fees", { period: month }).catch(() => null)]);
    const fees = f ? '<div class="card stack" id="feeCard"><div class="row between"><h2>Network fees</h2><input type="month" id="feeMonth" value="' + esc(month) + '" aria-label="Month"></div>' +
      '<p class="muted small">Commission only on orders the network brings you (AushadhBox app, orders passed on by linked pharmacies): ' + f.rates.urban + "% in cities, " + f.rates.semiurban + "% in towns, " + f.rates.rural + "% in villages, at most " + rs(f.rates.cap) + " an order, plus " + f.rates.gst + "% GST. Your own walk-in, phone and SMS customers are free. Health-points vouchers you accept are paid back here.</p>" +
      '<div class="kpis">' + kpi(f.totals.orders, "network orders") + kpi(rs(f.totals.commission), "commission") + kpi(rs(f.totals.gst), "GST") + kpi(rs(-f.totals.voucher), "vouchers to pay back", "good") +
      kpi(f.net > 0 ? rs(f.net) : f.net < 0 ? rs(-f.net) : "₹0", f.net > 0 ? "you pay the network" : f.net < 0 ? "the network pays you" : "settled", f.net > 0 ? "alert" : "good") + "</div>" +
      '<p class="small">Invoice ' + esc(f.invoice) + (f.gstin ? " · GSTIN " + esc(f.gstin) : "") + "</p>" +
      (f.payTo ? '<div class="row"><a class="btn primary" href="' + esc(f.payTo) + '">Pay ' + rs(f.net) + ' by UPI</a><span class="muted small">Send the UPI reference to support; the month is marked settled.</span></div>' : "") +
      (f.lines.length ? '<div class="table-scroll"><table><thead><tr><th>Type</th><th>Order / code</th><th>Branch</th><th>On</th><th>Amount</th><th>GST</th></tr></thead><tbody>' +
        f.lines.map(l => "<tr><td>" + esc(l.kind) + "</td><td>" + esc(l.order || l.ref || "") + "</td><td>" + esc(l.branch || "") + "</td><td>" + (l.base != null ? rs(l.base) + " × " + l.rate + "%" : "") + "</td><td>" + (l.amount < 0 ? "−" + rs(-l.amount) : rs(l.amount)) + "</td><td>" + (l.gst ? rs(l.gst) : "") + "</td></tr>").join("") + "</tbody></table></div>" : '<p class="muted">Nothing this month.</p>') + "</div>" : "";
    m.innerHTML = '<h1>Reports</h1>' + fees + '<div class="card stack"><h2>Complaints</h2><ul class="list" id="cpl">' + (c.complaints.map(x => "<li><span><b>" + esc(x.topic) + '</b> <span class="pill ' + (x.status === "open" ? "bad" : "") + '">' + esc(x.status) + "</span><br>" + esc(x.body) + (x.reply ? '<br><small class="muted">Your reply: ' + esc(x.reply) + "</small>" : "") + '<br><small class="muted">' + esc(when(x.created_at)) + "</small></span>" + (x.status === "open" ? '<button class="btn sm" data-cr="' + x.id + '">Reply</button>' : "") + "</li>").join("") || '<li class="muted">No complaints.</li>') + "</ul></div>" +
      '<div class="card stack"><h2>Activity log</h2><p class="muted small">Every sign-in, sale, payment, stock change, card scan and setting change, with who did it.</p><div class="table-scroll"><table><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>' + a.log.map(l => "<tr><td>" + esc(when(l.created_at)) + "</td><td>" + esc(l.name || l.actor) + "</td><td><b>" + esc(l.action) + "</b> " + esc(l.detail || "") + "</td></tr>").join("") + "</tbody></table></div></div>";
    if ($("#feeMonth")) $("#feeMonth").addEventListener("change", (e) => { if (/^\d{4}-\d{2}$/.test(e.target.value)) { VIEWS.reports.month = e.target.value; go("reports"); } });
    $("#cpl").addEventListener("click", (e) => { const b = e.target.closest("[data-cr]"); if (b) dialog('<h2>Reply to the complaint</h2><form class="stack" id="crF"><textarea id="crT" rows="4" required maxlength="1000"></textarea><button class="btn primary">Send</button></form>', (d) =>
      $("#crF", d).addEventListener("submit", (ev) => { ev.preventDefault(); run(null, async () => { await api.post("complaint_reply", { id: Number(b.dataset.cr), reply: $("#crT", d).value }); close(); go("reports"); }); })); });
  };

  /* ---------------- Start ---------------- */
  async function boot() {
    let s; try { s = await api.session(); } catch (e) { showAuth(); toast(e.message); return; }
    if (!s.staff) { showAuth(); if (s.twoFactorPending) { $("#fIn").hidden = true; $("#f2fa").hidden = false; } return; }
    S = s; $("#auth").hidden = true; $("#shell").hidden = false; $("#bottom").hidden = false; $("#btnOut").hidden = false;
    $("#who").textContent = (S.branch ? S.branch.name : S.org.name) + " · " + S.staff.name;
    const bs = $("#branchSel"); bs.hidden = !(S.branches && S.branches.length > 1);
    if (!bs.hidden) bs.innerHTML = S.branches.map(b => '<option value="' + b.id + '"' + (S.branch && b.id === S.branch.id ? " selected" : "") + ">" + esc(b.name) + "</option>").join("");
    const h = location.hash.slice(1);
    go(h || (S.staff.role === "rider" ? "rides" : "home"));
  }
  // New orders and payments arrive without reloading.
  setInterval(async () => { if (!S || document.visibilityState !== "visible" || S.staff.role === "rider" || S.org.status !== "approved") return;
    try { const d = await api.get("dashboard"); const before = badges.orders || 0; badges = { orders: d.newOrders, payments: d.claims, network: d.linkRequests + d.transfers }; renderNav(); if (d.newOrders > before) { toast("🔔 New order"); if (navigator.vibrate) navigator.vibrate([80, 60, 80]); } } catch (e) { /* offline */ } }, 45000);
  boot();
})();
