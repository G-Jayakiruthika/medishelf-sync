/* AushadhBox — browser app. Talks to api.php; all data lives in the MySQL database. */
(function () {
  "use strict";

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const DAY = 86400000, SOON_DAYS = 90;
  const SLOTS = [{ id: "m", key: "tMorning" }, { id: "a", key: "tAfternoon" }, { id: "e", key: "tEvening" }, { id: "b", key: "tBedtime" }];
  const VIEWS = [
    { id: "cabinet", key: "navCabinet", icon: '<path d="M4 4h16v16H4zM4 12h16M12 4v16"/>' },
    { id: "scan", key: "navScan", icon: '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10"/>' },
    { id: "doses", key: "navDoses", icon: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>' },
    { id: "notebook", key: "navNotebook", icon: '<path d="M6 3h11a2 2 0 0 1 2 2v16H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM8 3v18M11 8h5M11 12h5"/>' },
    { id: "safety", key: "navSafety", icon: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>' },
    { id: "guide", key: "navGuide", icon: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3z"/>' },
    { id: "account", key: "navAccount", icon: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>' }
  ];
  const KIT = [
    ["supply", "7-day supply of every daily medicine"], ["rx", "Copies of prescriptions and this notebook"], ["ors", "ORS sachets and paracetamol"],
    ["firstaid", "Antiseptic, bandages and cotton"], ["thermo", "Thermometer and spare spectacles"], ["cool", "Cool pack for insulin, if anyone uses it"],
    ["numbers", "Emergency numbers: 108 ambulance, 112 emergency"]
  ];

  /* ---------------- State ---------------- */
  const state = {
    user: null, features: {}, members: [], items: [], taken: new Set(), kit: new Set(),
    reminders: { enabled: false, m: "08:00", a: "13:30", e: "19:30", b: "22:00" },
    lang: guessLang(), filter: "all", nbMember: null, editingId: null, billing: null, accounts: [], auto: null, calUrl: "", today: ""
  };
  function guessLang() {
    try { const s = localStorage.getItem("ms-lang"); if (s && I18N[s]) return s; } catch (e) { /* storage blocked */ }
    const n = (navigator.language || "en").slice(0, 2);
    return I18N[n] ? n : "en";
  }

  /* ---------------- API ---------------- */
  const api = {
    csrf: "",
    async call(action, body, method) {
      const opts = { method: method || (body ? "POST" : "GET"), credentials: "same-origin", headers: {} };
      if (opts.method === "POST") { opts.headers["Content-Type"] = "application/json"; opts.headers["X-CSRF-Token"] = api.csrf; opts.body = JSON.stringify(body || {}); }
      let res;
      try { res = await fetch("/api.php?action=" + action + (opts.method === "GET" && body === undefined ? "" : ""), opts); }
      catch (e) { showOffline(true); throw { code: "offline", message: t("offline") }; }
      showOffline(false);
      const data = await res.json().catch(() => ({ error: "bad_response", message: "Unexpected server response." }));
      if (res.status === 419) { await api.refresh(); return api.call(action, body, method); }
      if (res.status === 401 && action !== "login") { showAuth(); }
      if (res.status === 402) openUpgrade(data.message);
      if (!res.ok) throw { code: data.error, message: data.message || "Something went wrong." };
      return data;
    },
    get(action, params) { return api.call(action + (params ? "&" + new URLSearchParams(params) : ""), undefined, "GET"); },
    post(action, body) { return api.call(action, body || {}, "POST"); },
    async refresh() { const s = await api.get("session"); api.csrf = s.csrf; return s; }
  };
  function showOffline(on) { const b = $("#offlineBanner"); if (b) b.hidden = !on; }
  window.addEventListener("online", () => showOffline(false));
  window.addEventListener("offline", () => showOffline(true));

  /* ---------------- i18n ---------------- */
  const t = (k, vars) => {
    let s = (I18N[state.lang] && I18N[state.lang][k]) || I18N.en[k] || k;
    if (vars) for (const v in vars) s = s.replace("{" + v + "}", vars[v]);
    return s;
  };
  const langInfo = () => LANGS.find(l => l.code === state.lang) || LANGS[0];
  const locale = () => (state.lang === "en" ? "en-IN" : state.lang + "-IN");
  function applyI18n() {
    document.documentElement.lang = state.lang;
    $$("[data-i18n]").forEach(el => { el.textContent = t(el.dataset.i18n); });
    $$("[data-i18n-ph]").forEach(el => { el.placeholder = t(el.dataset.i18nPh); });
    $$("#langSelect, #authLang").forEach(s => { s.value = state.lang; });
    $("#voiceGuide").src = "/assets/audio/voice/guide-" + state.lang + ".mp3";
    $("#voiceGuideLang").textContent = langInfo().native + " · " + langInfo().name;
    if (state.user) { renderNav(); renderAll(); }
  }
  async function setLang(code) {
    state.lang = code;
    try { localStorage.setItem("ms-lang", code); } catch (e) { /* ignore */ }
    applyI18n();
    if (state.user) api.post("language_save", { language: code }).catch(() => {});
  }

  /* ---------------- Dates ---------------- */
  const today = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
  const expEnd = (ym) => { const [y, m] = ym.split("-").map(Number); return new Date(y, m, 0); };
  const daysLeft = (ym) => ym ? Math.round((expEnd(ym) - today()) / DAY) : Infinity;
  const statusOf = (ym) => { if (!ym) return "unk"; const d = daysLeft(ym); return d < 0 ? "bad" : d <= SOON_DAYS ? "warn" : "ok"; };
  function fmtYM(ym) { if (!ym) return "—"; const [y, m] = ym.split("-").map(Number); try { return new Date(y, m - 1, 1).toLocaleDateString(locale(), { month: "short", year: "numeric" }); } catch (e) { return ym; } }
  function daysText(ym) { if (!ym) return t("addExpiry"); return daysTextKnown(ym); }
  function daysTextKnown(ym) { const d = daysLeft(ym); if (d < 0) return t("expiredAgo", { d: -d }); if (d > 120) return t("monthsLeft", { m: Math.floor(d / 30) }); return t("daysLeft", { d }); }

  /* ---------------- Helpers ---------------- */
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  /* Automation helpers: course still running? how long does the counted stock last? */
  const courseActive = (m) => !m.courseEnd || !state.today || m.courseEnd >= state.today;
  function stockInfo(m) {
    if (m.stock === null || m.stock === undefined) return null;
    const perDay = (m.times || []).length * Math.max(1, m.perDose || 1);
    const days = perDay ? Math.floor(m.stock / perDay) : null;
    const limit = state.auto ? state.auto.refillDays : 5;
    // A short course with enough tablets to finish it is not "running low".
    const courseLeft = m.courseEnd ? Math.round((new Date(m.courseEnd + "T00:00:00") - today()) / DAY) + 1 : null;
    const covers = courseLeft !== null && days !== null && days >= courseLeft - 1;   // one day of slack: today may already be ticked
    return { text: days === null ? t("stockLeftOnly", { n: m.stock }) : t("stockLeft", { n: m.stock, d: days }), cls: m.stock <= 0 ? (courseLeft !== null && courseLeft <= 0 ? "" : "out") : (days !== null && days <= limit && !covers ? "low" : "") };
  }
  function fmtShort(d) { try { return new Date(d + "T00:00:00").toLocaleDateString(locale(), { day: "numeric", month: "short" }); } catch (e) { return d; } }
  function toast(msg) { const el = $("#toast"); el.textContent = msg; el.hidden = false; clearTimeout(toast._t); toast._t = setTimeout(() => { el.hidden = true; }, 2800); }
  function sound(name) { try { new Audio("/assets/audio/" + name + ".mp3").play().catch(() => {}); } catch (e) { /* no audio */ } }
  function showErr(el, msg) { el.textContent = msg; el.hidden = !msg; }
  const memberName = (id) => { if (!id) return t("familyAll"); const m = state.members.find(x => x.id === id); return m ? m.name : "—"; };
  const debounce = (fn, ms) => { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; };

  /* ---------------- Auth ---------------- */
  function showAuth(mode) {
    state.user = null;
    $("#appView").hidden = true; $("#authView").hidden = false;
    authMode(mode || "login");
  }
  function authMode(mode) {
    $("#loginForm").hidden = mode !== "login"; $("#signupForm").hidden = mode !== "signup";
    $("#forgotForm").hidden = mode !== "forgot"; $("#resetForm").hidden = mode !== "reset";
    $(".auth-tabs").hidden = mode === "forgot" || mode === "reset";
    $("#tabLogin").setAttribute("aria-selected", String(mode === "login")); $("#tabSignup").setAttribute("aria-selected", String(mode === "signup"));
  }
  $$("[data-auth]").forEach(b => b.addEventListener("click", () => authMode(b.dataset.auth)));
  $("#btnForgot").addEventListener("click", () => { $("#fgEmail").value = $("#liEmail").value; authMode("forgot"); });

  $("#loginForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#loginErr"), "");
    try { await api.post("login", { email: $("#liEmail").value, password: $("#liPass").value }); $("#liPass").value = ""; await enterApp(); }
    catch (err) { showErr($("#loginErr"), err.message); }
  });
  $("#signupForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#signupErr"), "");
    try {
      await api.post("signup", { name: $("#suName").value, email: $("#suEmail").value, phone: $("#suPhone").value, password: $("#suPass").value, consent: $("#suConsent").checked, language: state.lang });
      $("#suPass").value = ""; track("signup"); await enterApp();
    } catch (err) { showErr($("#signupErr"), err.message); }
  });
  $("#forgotForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { await api.post("forgot", { email: $("#fgEmail").value }); $("#forgotMsg").textContent = t("resetSent"); }
    catch (err) { $("#forgotMsg").textContent = err.message; }
    $("#forgotMsg").hidden = false;
  });
  $("#resetForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#resetErr"), "");
    const token = (location.hash.match(/reset=([a-f0-9]{64})/) || [])[1];
    try { await api.post("reset", { token, password: $("#rsPass").value }); history.replaceState(null, "", location.pathname); await enterApp(); }
    catch (err) { showErr($("#resetErr"), err.message); }
  });

  async function enterApp() {
    const s = await api.refresh();
    if (!s.user) { showAuth(); return; }
    state.features = s.features || {};
    const data = await api.get("bootstrap");
    state.user = data.user;
    state.members = data.members; state.items = data.items;
    state.taken = new Set(data.taken); state.doseLog = data.doseLog || {}; state.alerts = data.alerts || []; state.followups = data.followups || []; state.appointments = data.appointments || []; state.pharmacy = data.pharmacy || {}; state.kit = new Set(data.kit); state.reminders = data.reminders; state.billing = data.billing; state.today = data.today;
    if (!state.nbMember || !state.members.some(m => m.id === state.nbMember)) state.nbMember = (state.members.find(m => m.self) || state.members[0] || {}).id;
    if (data.user.language && data.user.language !== state.lang && I18N[data.user.language]) { state.lang = data.user.language; try { localStorage.setItem("ms-lang", state.lang); } catch (e) { /* */ } }
    $("#authView").hidden = true; $("#appView").hidden = false;
    $("#consentBanner").hidden = !!state.user.consentCurrent;
    $("#adminLink").hidden = !state.user.admin;
    applyI18n();
    const h = (location.hash || "").replace("#", "");
    go(VIEWS.some(v => v.id === h) ? h : (h === "upgrade" ? "account" : "cabinet"));
    if (state.reminders.enabled) ensurePush(false);
    let pendingJoin = null; try { pendingJoin = sessionStorage.getItem("ms-join"); } catch (e) { /* */ }
    if (pendingJoin) { try { sessionStorage.removeItem("ms-join"); } catch (e) { /* */ } go("account"); $("#joinCode").value = pendingJoin; }
    else if (h === "upgrade") openUpgrade();
    else if (h === "shared") openSharedPhoto();
    else if (/^skip-[maeb]$/.test(h)) { go("doses"); openSkip(h.slice(5), null); }
    else if (h === "emergency") openEmergency();
    else if (!state.user.onboarded) openTour();
  }
  $("#btnConsent").addEventListener("click", async () => { await api.post("consent_renew"); state.user.consentCurrent = true; $("#consentBanner").hidden = true; });

  /* ---------------- Navigation ---------------- */
  function renderNav() {
    const icon = (v) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' + v.icon + "</svg>";
    $("#tabs").innerHTML = VIEWS.map(v => '<button data-go="' + v.id + '">' + esc(t(v.key)) + "</button>").join("");
    $("#bottomnav").innerHTML = VIEWS.map(v => '<button data-go="' + v.id + '">' + icon(v) + "<span>" + esc(t(v.key)) + "</span></button>").join("");
    markNav();
  }
  let current = "cabinet";
  function go(id) {
    if (!VIEWS.some(v => v.id === id)) id = "cabinet";
    current = id;
    $$(".view").forEach(s => { s.hidden = s.dataset.view !== id; });
    markNav();
    if (id !== "scan") stopCamera();
    if (id === "account" && state.user) { refreshBilling(); loadAutomations(); loadBoxes(); }
    if (id === "doses" && state.user) loadAdherence();
    if (id === "notebook" && state.user) loadVitals();
    try { history.replaceState(null, "", "#" + id); } catch (e) { /* sandboxed */ }
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  function markNav() { $$("#tabs [data-go], #bottomnav [data-go]").forEach(b => b.setAttribute("aria-current", b.dataset.go === current ? "page" : "false")); }
  document.addEventListener("click", (e) => { const b = e.target.closest("[data-go]"); if (b) go(b.dataset.go); });
  window.addEventListener("hashchange", () => {
    const h = (location.hash || "").replace("#", "");
    if (!state.user) { if (h === "signup" || h === "signin") authMode(h === "signup" ? "signup" : "login"); return; }
    if (VIEWS.some(v => v.id === h)) { if (h !== current) go(h); }
    else if (h === "upgrade") { go("account"); openUpgrade(); }
    else if (/^skip-[maeb]$/.test(h)) { go("doses"); openSkip(h.slice(5), null); }
    else if (h === "emergency") openEmergency();
  });

  /* ---------------- Cabinet ---------------- */
  function counts() { const c = { ok: 0, warn: 0, bad: 0, unk: 0 }; state.items.forEach(m => c[statusOf(m.exp)]++); return c; }
  function renderSummary() {
    const c = counts();
    $("#statTotal").textContent = state.items.length; $("#statSafe").textContent = c.ok; $("#statSoon").textContent = c.warn; $("#statExpired").textContent = c.bad;
    const n = c.warn + c.bad;
    $("#heroTitle").innerHTML = n ? '<span class="count">' + n + "</span> " + esc(t("heroAttention")) : esc(state.items.length ? t("heroAllGood") : t("emptyCabinet"));
    try { $("#todayLabel").textContent = new Date().toLocaleDateString(locale(), { weekday: "long", day: "numeric", month: "long", year: "numeric" }); } catch (e) { /* */ }
    $("#hello").textContent = state.user ? state.user.name : "";
  }
  function renderFilter() {
    const opts = [{ id: "all", name: t("filterAll") }, { id: "family", name: t("familyAll") }].concat(state.members.map(m => ({ id: String(m.id), name: m.name })));
    $("#memberFilter").innerHTML = opts.map(o => '<button class="chip" data-member="' + o.id + '" aria-pressed="' + (state.filter === o.id) + '">' + esc(o.name) + "</button>").join("");
  }
  $("#memberFilter").addEventListener("click", (e) => { const b = e.target.closest("[data-member]"); if (!b) return; state.filter = b.dataset.member; renderFilter(); renderCabinet(); });

  function renderCabinet() {
    const list = state.items.filter(m => state.filter === "all" || (state.filter === "family" ? !m.member_id : String(m.member_id) === state.filter))
      .sort((a, b) => daysLeft(a.exp) - daysLeft(b.exp));
    if (!list.length) { $("#cabinet").innerHTML = '<div class="empty">' + esc(t("emptyCabinet")) + '</div>'; return; }
    $("#cabinet").innerHTML = list.map(m => {
      const st = statusOf(m.exp), info = m.info;
      const stLabel = st === "bad" ? t("statExpired") : st === "warn" ? t("statSoon") : st === "unk" ? t("expUnknown") : t("statSafe");
      return '<article class="drawer ' + st + '" data-edit="' + m.id + '" tabindex="0" aria-label="' + esc(t("edit") + " " + m.name) + '">' +
        '<button class="del" data-del="' + m.id + '" aria-label="' + esc(t("remove") + " " + m.name) + '">✕</button>' +
        '<div class="top"><span class="pill ' + st + '">' + esc(stLabel) + "</span>" + (info && info.rx ? '<span class="pill rx">Rx</span>' : "") + "</div>" +
        '<div class="tag"><b>' + esc(m.name) + "</b><small>" + esc(info ? info.ingredient + " · " + info.use : "") + "</small></div>" +
        '<div class="row"><span>' + esc(t("expLabel")) + '</span><span class="mono">' + esc(fmtYM(m.exp)) + "</span></div>" +
        '<div class="row"><span>' + esc(t("fBatch")) + '</span><span class="mono">' + esc(m.batch || "—") + "</span></div>" +
        '<div class="row"><span>' + esc(t("fFor")) + "</span><span>" + esc(memberName(m.member_id)) + "</span></div>" +
        (stockInfo(m) ? '<div class="row"><span>' + esc(t("fStock")) + '</span><span class="stock ' + stockInfo(m).cls + '">' + esc(stockInfo(m).text) + "</span></div>" : "") +
        (m.courseEnd ? '<div class="row"><span></span><span class="muted">' + esc(courseActive(m) ? t("courseEnds", { d: fmtShort(m.courseEnd) }) : t("courseDone")) + "</span></div>" : "") +
        '<div class="row"><span></span><strong style="color:var(--' + st + ')">' + esc(daysText(m.exp)) + "</strong></div>" +
        '<span class="handle" aria-hidden="true"></span></article>';
    }).join("");
  }
  $("#cabinet").addEventListener("click", async (e) => {
    const del = e.target.closest("[data-del]");
    if (del) {
      e.stopPropagation();
      if (del.dataset.confirm !== "1") { del.dataset.confirm = "1"; del.textContent = t("confirmRemove"); del.style.width = "auto"; del.style.borderRadius = "999px"; del.style.padding = "0 8px"; del.style.color = "var(--bad)"; setTimeout(() => { if (del.isConnected) { del.dataset.confirm = ""; del.textContent = "✕"; del.removeAttribute("style"); } }, 4000); return; }
      const id = Number(del.dataset.del);
      try { await api.post("item_delete", { id }); state.items = state.items.filter(m => m.id !== id); renderAll(); } catch (err) { toast(err.message); }
      return;
    }
    const card = e.target.closest("[data-edit]");
    if (card) editItem(Number(card.dataset.edit));
  });
  $("#cabinet").addEventListener("keydown", (e) => { if (e.key === "Enter" && e.target.dataset.edit) editItem(Number(e.target.dataset.edit)); });

  /* ---------------- Add / edit form ---------------- */
  function fillMemberSelect() {
    $("#fFor").innerHTML = '<option value="">' + esc(t("familyAll")) + "</option>" + state.members.map(m => '<option value="' + m.id + '">' + esc(m.name) + "</option>").join("");
  }
  function resetForm() {
    state.editingId = null; $("#medForm").reset(); $("#medForm").dataset.catalogId = ""; $("#medForm").dataset.generic = "";
    $("#matchBox").hidden = true; $("#matchList").hidden = true; $("#rawText").textContent = "—"; setStatus("");
    $("#btnCancelEdit").hidden = true; $("#formTitle").textContent = t("scanFound"); showErr($("#formErr"), "");
    const self = state.members.find(m => m.self); $("#fFor").value = self ? String(self.id) : "";
  }
  function editItem(id) {
    const m = state.items.find(x => x.id === id); if (!m) return;
    resetForm(); go("scan");
    state.editingId = id; $("#formTitle").textContent = t("edit") + ": " + m.name; $("#btnCancelEdit").hidden = false;
    $("#fName").value = m.name; $("#fBatch").value = m.batch || ""; $("#fMfg").value = m.mfg || ""; $("#fExp").value = m.exp;
    $("#fQty").value = m.quantity || ""; $("#fNotes").value = m.notes || ""; $("#fFor").value = m.member_id ? String(m.member_id) : "";
    $("#fStock").value = m.stock === null || m.stock === undefined ? "" : String(m.stock); $("#fPerDose").value = String(m.perDose || 1); $("#fCourseEnd").value = m.courseEnd || "";
    $("#medForm").dataset.catalogId = m.catalog_id || "";
    $$("#fTimes input").forEach(i => { i.checked = (m.times || []).includes(i.value); });
    if (m.info) showInfo(m.info, m.exp);
  }
  $("#btnCancelEdit").addEventListener("click", () => { resetForm(); go("cabinet"); });
  $("#btnAddHand").addEventListener("click", () => { resetForm(); go("scan"); setTimeout(() => $("#fName").focus(), 300); });
  $("#btnScanHero").addEventListener("click", () => { resetForm(); go("scan"); });

  $("#medForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#formErr"), "");
    const body = {
      id: state.editingId || undefined, name: $("#fName").value.trim(), exp: $("#fExp").value, mfg: $("#fMfg").value, batch: $("#fBatch").value.trim(),
      member_id: $("#fFor").value ? Number($("#fFor").value) : null, catalog_id: Number($("#medForm").dataset.catalogId) || null,
      generic: $("#medForm").dataset.generic || "", quantity: $("#fQty").value.trim(), notes: $("#fNotes").value.trim(),
      times: $$("#fTimes input:checked").map(i => i.value),
      stock: $("#fStock").value === "" ? null : Number($("#fStock").value), per_dose: Number($("#fPerDose").value) || 1, course_end: $("#fCourseEnd").value || ""
    };
    const btn = $("#btnSave"); btn.disabled = true;
    try {
      const r = await api.post("item_save", body);
      state.items = state.items.filter(x => x.id !== r.item.id).concat([r.item]);
      sound("saved"); toast("✔ " + r.item.name); resetForm(); renderAll(); go("cabinet"); refreshSafety([r.item.id]);
    } catch (err) { showErr($("#formErr"), err.message); }
    btn.disabled = false;
  });

  /* Name autocomplete from the medicine database */
  const suggest = debounce(async (q) => {
    if (q.length < 3) { $("#nameSuggest").hidden = true; return; }
    try {
      const r = await api.get("catalog_search", { q });
      if (!r.results.length) { $("#nameSuggest").hidden = true; return; }
      $("#nameSuggest").innerHTML = r.results.slice(0, 8).map(x => '<li><button type="button" data-cat="' + x.id + '" data-name="' + esc(x.name) + '">' + esc(x.name) + (x.unit ? ' <span class="muted">· ' + esc(x.unit) + "</span>" : "") + "</button></li>").join("");
      $("#nameSuggest").hidden = false;
      suggest.last = r.results;
    } catch (e) { /* offline: ignore */ }
  }, 250);
  $("#fName").addEventListener("input", (e) => { $("#medForm").dataset.catalogId = ""; suggest(e.target.value.trim()); });
  $("#fName").addEventListener("blur", () => setTimeout(() => { $("#nameSuggest").hidden = true; }, 200));
  $("#nameSuggest").addEventListener("click", (e) => {
    const b = e.target.closest("[data-cat]"); if (!b) return;
    $("#fName").value = b.dataset.name; $("#medForm").dataset.catalogId = b.dataset.cat; $("#nameSuggest").hidden = true;
    const hit = (suggest.last || []).find(x => String(x.id) === b.dataset.cat);
    if (hit && hit.info) showInfo(hit.info, $("#fExp").value || null);
  });

  function showInfo(info, exp) {
    const box = $("#matchBox");
    const st = exp ? statusOf(exp) : null;
    box.hidden = false;
    box.innerHTML = (info ? "<b>" + esc(info.ingredient) + "</b><span>" + esc(info.use) + "</span>" +
      '<span class="muted" style="font-size:.86rem">' + esc(info.storage || "") + (info.note ? " · " + esc(info.note) : "") + "</span>" : "") +
      (st ? '<span><span class="pill ' + st + '">' + esc(st === "bad" ? t("statExpired") : st === "warn" ? t("statSoon") : t("statSafe")) + '</span> <span class="mono">' + esc(fmtYM(exp)) + "</span> · " + esc(daysText(exp)) + "</span>" : "") +
      (info && info.rx ? '<span class="pill rx" style="justify-self:start">Rx · ' + esc(t("safeRx")) + "</span>" : "");
  }

  /* ---------------- Label parsing (Indian packs + GS1) ---------------- */
  const MONTHS = { JAN: 1, FEB: 2, MAR: 3, APR: 4, MAY: 5, JUN: 6, JUL: 7, AUG: 8, SEP: 9, OCT: 10, NOV: 11, DEC: 12 };
  function toYM(y, m) { y = Number(y); m = Number(m); if (y < 100) y += 2000; if (!(m >= 1 && m <= 12) || y < 2000 || y > 2100) return null; return y + "-" + String(m).padStart(2, "0"); }
  function parseDate(s) {
    if (!s) return null; s = String(s).toUpperCase().trim(); let r;
    if ((r = s.match(/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})\b/))) return toYM(r[3], r[2]);
    if ((r = s.match(/\b(\d{4})[\/.\-](\d{1,2})\b/))) return toYM(r[1], r[2]);
    if ((r = s.match(/\b(\d{1,2})\s*[\/.\-\s]\s*(\d{4})\b/))) return toYM(r[2], r[1]);
    if ((r = s.match(/\b(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*[\s.,\/\-']*(\d{4}|\d{2})\b/))) return toYM(r[2], MONTHS[r[1]]);
    if ((r = s.match(/\b(\d{1,2})[\/.\-](\d{2})\b/))) return toYM(r[2], r[1]);
    return null;
  }
  function parseLabel(raw) {
    const out = {}; if (!raw) return out;
    const text = String(raw), U = text.toUpperCase(); let g, r;
    if ((g = U.match(/\(17\)\s?(\d{2})(\d{2})\d{2}/)) || (g = U.match(/(?:^|\x1D|\])(?:[A-Z]\d)?01\d{14}17(\d{2})(\d{2})\d{2}/))) out.exp = toYM(g[1], g[2]);
    if ((g = U.match(/\(11\)\s?(\d{2})(\d{2})\d{2}/))) out.mfg = toYM(g[1], g[2]);
    if ((g = text.match(/\(10\)\s?([A-Za-z0-9\-\/]{2,20})/)) || (g = U.match(/01\d{14}17\d{6}10([A-Z0-9\-\/]{2,20})/))) out.batch = g[1];
    text.split(/[\n|;]+/).forEach(part => {
      const kv = part.match(/^\s*([A-Za-z .]+?)\s*[:=]\s*(.+)$/); if (!kv) return;
      const k = kv[1].toLowerCase().replace(/[^a-z]/g, ""), v = kv[2].trim();
      if (/^(brand|brandname|name|product)$/.test(k)) out.name = out.name || v;
      else if (/^(generic|genericname|propername|composition)$/.test(k)) out.generic = v;
      else if (/^(batch|batchno|bno|lot|lotno)$/.test(k)) out.batch = out.batch || v;
      else if (/^(mfg|mfgdate|mfd|dom|manufactured|dateofmanufacture)$/.test(k)) out.mfg = out.mfg || parseDate(v);
      else if (/^(exp|expiry|expirydate|expdate|usebefore|dateofexpiry)$/.test(k)) out.exp = out.exp || parseDate(v);
    });
    if (!out.batch && (r = U.match(/(?:B\.?\s?NO\.?|BATCH\s*(?:NO\.?)?|LOT\s*(?:NO\.?)?)\s*[:\-.]?\s*([A-Z0-9][A-Z0-9\-\/]{2,15})/))) out.batch = r[1];
    if (!out.exp && (r = U.match(/(?:EXP(?:IRY)?\.?\s*(?:DATE)?|USE\s*BEFORE|EXPIRES)\s*[:\-.]?\s*([A-Z0-9.\/\-\s']{4,14})/))) out.exp = parseDate(r[1]);
    if (!out.mfg && (r = U.match(/(?:MFG\.?\s*(?:DATE)?|MFD\.?|MANUFACTURED\s*(?:ON)?|DOM)\s*[:\-.]?\s*([A-Z0-9.\/\-\s']{4,14})/))) out.mfg = parseDate(r[1]);
    return out;
  }

  /* ---------------- Scanner ---------------- */
  const canvas = $("#scanCanvas"), video = $("#camVideo");
  let stream = null, liveTimer = null, detector = null;
  try { if ("BarcodeDetector" in window) detector = new window.BarcodeDetector({ formats: ["qr_code", "data_matrix", "ean_13", "code_128"] }); } catch (e) { detector = null; }
  function setStatus(html) { $("#scanStatus").innerHTML = html; }
  function showCanvas() { $("#viewerPlaceholder").hidden = true; video.hidden = true; canvas.hidden = false; }
  async function decodeCodes(cv) {
    if (detector) { try { const codes = await detector.detect(cv); if (codes && codes.length) return codes.map(c => c.rawValue).join("\n"); } catch (e) { /* fall back */ } }
    if (window.jsQR) {
      const ctx = cv.getContext("2d", { willReadFrequently: true }), img = ctx.getImageData(0, 0, cv.width, cv.height);
      const res = window.jsQR(img.data, img.width, img.height, { inversionAttempts: "attemptBoth" });
      if (res && res.data) return res.data;
    }
    return "";
  }
  function loadScript(src) { return new Promise((ok, fail) => { const s = document.createElement("script"); s.src = src; s.onload = ok; s.onerror = fail; document.head.appendChild(s); }); }
  async function tryOCR(cv) {
    try {
      if (!window.Tesseract) await loadScript("https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js");
      const res = await Promise.race([window.Tesseract.recognize(cv, "eng"), new Promise((_, no) => setTimeout(() => no(new Error("timeout")), 40000))]);
      return (res && res.data && res.data.text) || "";
    } catch (e) { return ""; }
  }
  async function processCanvas(cv, preDecoded) {
    showCanvas(); $("#scanBeam").hidden = false;
    setStatus('<span class="spinner"></span>' + esc(t("scanReading")));
    let raw = preDecoded || await decodeCodes(cv);
    let info = parseLabel(raw);
    if (!info.exp || !raw) { const ocr = await tryOCR(cv); if (ocr) { raw = (raw ? raw + "\n" : "") + ocr; info = Object.assign(parseLabel(ocr), info); } }
    $("#scanBeam").hidden = true;
    // A prescription or pharmacy bill? Read every medicine on it and open the review list instead of the single-pack form.
    if (window.RxParse && raw && !state.editingId && !info.exp) {
      const doc = window.RxParse.parse(raw, state.today);
      if ((doc.kind === "prescription" || doc.kind === "bill") && doc.items.length) { setStatus(""); openReview(doc); return; }
    } else if (window.RxParse && raw && !state.editingId) {
      const doc = window.RxParse.parse(raw, state.today);
      if (doc.kind === "bill" && doc.items.length >= 2) { setStatus(""); openReview(doc); return; }
    }
    $("#fName").value = info.name || ""; $("#fBatch").value = info.batch || ""; $("#fMfg").value = info.mfg || ""; $("#fExp").value = info.exp || "";
    $("#rawText").textContent = raw || "—"; $("#medForm").dataset.generic = info.generic || "";
    await showMatches(raw, info);
    if (info.exp) { setStatus("✔ " + esc(t("scanFound"))); sound(statusOf(info.exp) === "ok" ? "saved" : "expiry-alert"); }
    else setStatus('<span style="color:var(--warn)">' + esc(t("scanNotFound")) + "</span>");
  }
  async function showMatches(raw, info) {
    const list = $("#matchList");
    list.hidden = true; list.innerHTML = "";
    if (!raw) return;
    try {
      const r = await api.post("catalog_match", { text: [info.generic, info.name, raw].filter(Boolean).join("\n") });
      showInfo(r.info, info.exp);
      if (!r.info && !info.exp) $("#matchBox").hidden = true;
      if (r.info && !state.editingId) applyUsual(r.info, r.matches[0] && r.matches[0].unit);
      if (r.matches.length) {
        list.innerHTML = '<span class="muted" style="font-size:.84rem">' + esc(t("matches")) + "</span>" + r.matches.slice(0, 4).map((m, i) =>
          '<label><input type="radio" name="match" value="' + m.id + '"' + (i === 0 ? " checked" : "") + ' data-name="' + esc(m.name) + '"><span>' + esc(m.name) + (m.unit ? ' <span class="muted">· ' + esc(m.unit) + "</span>" : "") + "</span></label>").join("");
        list.hidden = false;
        $("#medForm").dataset.catalogId = r.matches[0].id;
        if (!$("#fName").value) $("#fName").value = r.matches[0].name;
      } else if (raw) {
        list.innerHTML = '<span class="muted" style="font-size:.84rem">' + esc(t("noMatch")) + "</span>"; list.hidden = false;
      }
    } catch (e) { /* offline: user can still type */ }
  }
  $("#matchList").addEventListener("change", (e) => { if (e.target.name === "match") $("#medForm").dataset.catalogId = e.target.value; });
  function drawToCanvas(src, w, h) { const k = Math.min(1, 1600 / Math.max(w, h)); canvas.width = Math.round(w * k); canvas.height = Math.round(h * k); canvas.getContext("2d", { willReadFrequently: true }).drawImage(src, 0, 0, canvas.width, canvas.height); }
  $("#photoInput").addEventListener("change", (e) => {
    const f = e.target.files && e.target.files[0]; if (!f) return;
    stopCamera(); if (!state.editingId) resetForm();
    const img = new Image();
    img.onload = () => { drawToCanvas(img, img.naturalWidth, img.naturalHeight); URL.revokeObjectURL(img.src); processCanvas(canvas); };
    img.onerror = () => setStatus("That file isn't a photo we can open. Try a JPG or PNG.");
    img.src = URL.createObjectURL(f); e.target.value = "";
  });
  async function startCamera() {
    try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment", width: { ideal: 1280 } }, audio: false }); }
    catch (e) { setStatus('<span style="color:var(--warn)">The camera is not available. Allow camera access in your browser, or use "' + esc(t("scanPhoto")) + '".</span>'); return; }
    video.srcObject = stream; video.hidden = false; canvas.hidden = true; $("#viewerPlaceholder").hidden = true;
    $("#scanFrame").hidden = false; $("#scanBeam").hidden = false; $("#btnCapture").hidden = false;
    await video.play().catch(() => {});
    setStatus('<span class="spinner"></span>' + esc(t("scanReading")));
    liveTimer = setInterval(async () => { if (!video.videoWidth) return; drawToCanvas(video, video.videoWidth, video.videoHeight); const code = await decodeCodes(canvas); if (code) { stopCamera(); processCanvas(canvas, code); } }, 500);
  }
  function stopCamera() { clearInterval(liveTimer); liveTimer = null; if (stream) { stream.getTracks().forEach(tr => tr.stop()); stream = null; } video.hidden = true; $("#scanFrame").hidden = true; $("#btnCapture").hidden = true; }
  $("#btnCamera").addEventListener("click", () => { if (stream) stopCamera(); else { if (!state.editingId) resetForm(); startCamera(); } });
  $("#btnCapture").addEventListener("click", () => { if (!video.videoWidth) return; drawToCanvas(video, video.videoWidth, video.videoHeight); stopCamera(); processCanvas(canvas); });

  const SAMPLE_PACKS = [
    { brand: "CETRIA-10", generic: "Cetirizine Hydrochloride Tablets IP 10 mg", mfr: "Sample Pharma Ltd, Baddi, HP", batch: "CTZ25088", mfg: "01/2025", exp: "12/2026", mrp: "24.50", lic: "MNB/12/345", redLine: false, colour: "#2E6E9E" },
    { brand: "FEVEREX 650", generic: "Paracetamol Tablets IP 650 mg", mfr: "Demo Lifesciences, Sikkim", batch: "FVX24311", mfg: "09/2024", exp: "08/2027", mrp: "30.90", lic: "SK/25/118", redLine: false, colour: "#C2412F" },
    { brand: "AZIMAX-500", generic: "Azithromycin Tablets IP 500 mg", mfr: "Example Remedies, Ahmedabad", batch: "AZX23097", mfg: "10/2023", exp: "09/2026", mrp: "71.20", lic: "G/28/1406", redLine: true, colour: "#4F8A57" }
  ];
  let sampleIdx = 0;
  function drawSample(p) {
    canvas.width = 960; canvas.height = 600;
    const c = canvas.getContext("2d", { willReadFrequently: true });
    c.fillStyle = "#F7F8F6"; c.fillRect(0, 0, 960, 600); c.fillStyle = p.colour; c.fillRect(0, 0, 960, 120);
    c.fillStyle = "#fff"; c.font = "800 54px 'Zen Maru Gothic', sans-serif"; c.fillText(p.brand, 40, 82);
    c.fillStyle = "#16202E"; c.font = "600 26px 'Noto Sans', sans-serif"; c.fillText(p.generic, 40, 175);
    c.font = "400 20px 'Noto Sans', sans-serif"; c.fillStyle = "#566274"; c.fillText("10 x 10 Tablets · Mfd. by " + p.mfr, 40, 210);
    if (p.redLine) { c.fillStyle = "#B3382A"; c.fillRect(40, 236, 520, 10); c.font = "800 34px sans-serif"; c.fillText("Rx", 584, 254); }
    c.fillStyle = "#16202E"; c.font = "600 26px 'IBM Plex Mono', monospace";
    c.fillText("B.No.  " + p.batch, 40, 320); c.fillText("Mfg.   " + p.mfg, 40, 362); c.fillText("Exp.   " + p.exp, 40, 404); c.fillText("M.L.   " + p.lic, 40, 446); c.fillText("MRP ₹" + p.mrp + " incl. of all taxes", 40, 488);
    c.font = "400 16px 'Noto Sans', sans-serif"; c.fillStyle = "#566274";
    c.fillText("Store below 30 °C. Protect from light and moisture. Keep out of reach of children.", 40, 548); c.fillText("SAMPLE PACK · fictional brand for demonstration", 40, 576);
    const payload = ["UPIC:89" + p.batch.replace(/\D/g, "").padEnd(11, "0"), "Generic:" + p.generic, "Brand:" + p.brand, "Mfr:" + p.mfr, "Batch:" + p.batch, "Mfg:" + p.mfg, "Exp:" + p.exp, "Lic:" + p.lic].join("|");
    if (window.qrcode) {
      const q = window.qrcode(0, "M"); q.addData(payload); q.make();
      const n = q.getModuleCount(), cell = Math.floor(300 / (n + 8)), ox = 620, oy = 160;
      c.fillStyle = "#fff"; c.fillRect(ox - 4 * cell, oy - 4 * cell, (n + 8) * cell, (n + 8) * cell); c.fillStyle = "#000";
      for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) if (q.isDark(y, x)) c.fillRect(ox + x * cell, oy + y * cell, cell, cell);
    }
    return payload;
  }
  $("#btnSample").addEventListener("click", async () => {
    stopCamera(); resetForm();
    const p = SAMPLE_PACKS[sampleIdx++ % SAMPLE_PACKS.length];
    try { await document.fonts.ready; } catch (e) { /* */ }
    const payload = drawSample(p); showCanvas();
    processCanvas(canvas, (await decodeCodes(canvas)) || payload);
  });

  /* ---------------- Doses + reminders ---------------- */
  const takenKey = (id, s) => id + ":" + s;
  const isSkipped = (k) => state.doseLog && state.doseLog[k] && state.doseLog[k].status === "skipped";
  const REASON_KEY = { fine: "skipFine", side: "skipSide", out: "skipOut", doctor: "skipDoctor", other: "skipOther" };
  function renderDoses() {
    $("#slots").innerHTML = SLOTS.map(s => {
      const meds = state.items.filter(m => (m.times || []).includes(s.id) && courseActive(m));
      const items = meds.length ? meds.map(m => {
        const k = takenKey(m.id, s.id), done = state.taken.has(k), skipped = !done && isSkipped(k), log = state.doseLog[k], st = statusOf(m.exp);
        const extra = (done && log && log.source === "box" ? ' · <span class="by-box">' + esc(t("byBox", { t: log.at || "" })) + "</span>" : "") +
          (skipped ? ' · <span class="skip-tag">' + esc(t("skipped", { r: t(REASON_KEY[log.reason] || "skipOther") })) + "</span>" : "");
        return '<li class="dose-row"><label class="packet' + (done ? " done" : "") + (skipped ? " skipped" : "") + '"><input type="checkbox" data-take="' + k + '"' + (done ? " checked" : "") + "><span>" + esc(m.name) + "</span><small>" + esc(memberName(m.member_id)) +
          (stockInfo(m) ? ' · <span class="stock ' + stockInfo(m).cls + '">' + esc(stockInfo(m).text) + "</span>" : "") + (st === "bad" ? ' · <b style="color:var(--bad)">' + esc(t("statExpired")) + "</b>" : "") + extra + "</small></label>" +
          (done || skipped ? "" : '<button type="button" class="link skip-btn" data-skip="' + k + '">' + esc(t("skip")) + "</button>") + "</li>";
      }).join("") : '<li class="muted" style="padding:8px 4px">—</li>';
      const pending = meds.filter(m => !state.taken.has(takenKey(m.id, s.id)) && !isSkipped(takenKey(m.id, s.id))).length;
      return '<div class="slot" data-slot="' + s.id + '"><header><span>' + esc(t(s.key)) + '</span>' + (pending > 1 ? '<button type="button" class="btn btn-ghost slot-all" data-all="' + s.id + '">' + esc(t("takenAll")) + "</button>" : "") + '<input type="time" aria-label="' + esc(t(s.key)) + '" data-time="' + s.id + '" value="' + state.reminders[s.id] + '"></header><ul>' + items + "</ul></div>";
    }).join("");
    $("#remindLabel").textContent = state.reminders.enabled ? t("remindOn") : t("remindOff");
    $("#btnRemind").setAttribute("aria-pressed", String(state.reminders.enabled));
  }

  /* Skip with a reason (one medicine, or everything still due in a time slot) */
  let skipTarget = null;
  function openSkip(slot, itemId) {
    skipTarget = { slot, itemId };
    const slotName = t((SLOTS.find(x => x.id === slot) || SLOTS[0]).key);
    const names = itemId ? [(state.items.find(m => m.id === itemId) || {}).name] : state.items.filter(m => (m.times || []).includes(slot) && courseActive(m) && !state.taken.has(takenKey(m.id, slot))).map(m => m.name);
    $("#skipWhat").textContent = slotName + ": " + names.filter(Boolean).join(", ");
    const d = $("#skipDialog"); if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
  }
  $("#skipReasons").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-reason]"); if (!b || !skipTarget) return;
    const reason = b.dataset.reason, { slot, itemId } = skipTarget;
    $$("#skipReasons button").forEach(x => { x.disabled = true; });
    try {
      const r = await api.post("dose_skip", { slot, reason, item_id: itemId || undefined });
      r.items.forEach(it => {
        const k = takenKey(it.id, slot); state.taken.delete(k); state.doseLog[k] = { status: "skipped", reason, source: "app" };
        const i = state.items.findIndex(x => x.id === it.id); if (i >= 0) state.items[i] = it;
      });
      $("#skipDialog").close();
      toast(t("skipDone") + " " + (reason === "out" ? t("skipOutDone") : reason === "doctor" ? t("skipDoctorDone") : reason === "side" ? t("skipSideDone") : ""));
      renderAll();
    } catch (err) { toast(err.message); }
    $$("#skipReasons button").forEach(x => { x.disabled = false; });
  });

  /* Last 7 days: taken / skipped / not confirmed per person */
  let adhData = null;
  async function loadAdherence() {
    try { adhData = await api.get("adherence", { days: 7 }); renderAdherence(); } catch (e) { /* the card stays empty */ }
  }
  function renderAdherence() {
    if (!adhData) return;
    const slotName = (id) => t((SLOTS.find(x => x.id === id) || SLOTS[0]).key);
    $("#adhList").innerHTML = adhData.people.length ? adhData.people.map(p => {
      const cls = p.percent >= 90 ? "ok" : p.percent >= 70 ? "warn" : "bad";
      const reasons = Object.keys(p.reasons || {}).map(r => t(REASON_KEY[r] || "skipOther") + " ×" + p.reasons[r]).join(", ");
      return '<div class="adh-row"><div class="adh-top"><b>' + esc(p.name) + '</b><span class="adh-pct ' + cls + '">' + p.percent + '%</span></div>' +
        '<div class="adh-bar" role="img" aria-label="' + p.percent + '%"><span class="t" style="width:' + (100 * p.taken / p.expected) + '%"></span><span class="s" style="width:' + (100 * p.skipped / p.expected) + '%"></span></div>' +
        '<small>' + esc([t("adhTaken", { n: p.taken }), t("adhSkipped", { n: p.skipped }), t("adhMissed", { n: p.missed })].join(" · ")) +
        (p.box ? " · " + esc(t("adhBox", { n: p.box })) : "") + (p.worstSlot ? "<br>" + esc(t("adhWorst", { slot: slotName(p.worstSlot) })) : "") + (reasons ? "<br>" + esc(reasons) : "") + "</small>" +
        (p.patterns || []).map(x => '<small class="pattern">💡 ' + esc(x.code === "weekend" ? t("pWeekend", { pct: x.pct }) : x.code === "late" ? t("pLate", { slot: slotName(x.slot).toLowerCase(), min: x.min }) : t("pBox")) + "</small>").join("") + "</div>";
    }).join("") : '<p class="muted">' + esc(t("adhNone")) + "</p>";
  }
  function adherenceText() {
    if (!adhData) return "";
    return "Doses " + adhData.from + " to " + adhData.to + " (AushadhBox)\n" + adhData.people.map(p =>
      "- " + p.name + ": " + p.taken + "/" + p.expected + " taken (" + p.percent + "%), " + p.skipped + " skipped, " + p.missed + " not confirmed" +
      (p.worstSlot ? "; most missed: " + p.worstSlot.replace("m", "morning").replace("a", "afternoon").replace("e", "evening").replace("b", "bedtime") : "") +
      (Object.keys(p.reasons || {}).length ? "; skip reasons: " + Object.keys(p.reasons).map(r => r + " x" + p.reasons[r]).join(", ") : "") +
      "\n" + p.medicines.map(m => "    " + m.name + " " + m.taken + "/" + m.expected).join("\n")).join("\n");
  }
  $("#adhCopy").addEventListener("click", () => { const tx = adherenceText(); const done = () => toast("✔ " + t("calCopied")); if (navigator.clipboard) navigator.clipboard.writeText(tx).then(done).catch(() => fallbackCopy(tx, done)); else fallbackCopy(tx, done); });

  const saveReminders = debounce(() => api.post("reminders_save", Object.assign({}, state.reminders)).catch(err => toast(err.message)), 500);
  $("#slots").addEventListener("click", async (e) => {
    const sk = e.target.closest("[data-skip]");
    if (sk) { const [id, slot] = sk.dataset.skip.split(":"); openSkip(slot, Number(id)); return; }
    const b = e.target.closest("[data-all]"); if (!b) return;
    const slot = b.dataset.all; b.disabled = true;
    try {
      await api.post("dose_slot_taken", { slot });
      state.items.filter(m => (m.times || []).includes(slot) && courseActive(m) && !state.taken.has(takenKey(m.id, slot)) && !isSkipped(takenKey(m.id, slot))).forEach(m => {
        state.taken.add(takenKey(m.id, slot)); state.doseLog[takenKey(m.id, slot)] = { status: "taken", source: "app" }; if (m.stock !== null && m.stock !== undefined) m.stock = Math.max(0, m.stock - (m.perDose || 1));
      });
      sound("saved"); renderDoses(); renderCabinet();
    } catch (err) { toast(err.message); b.disabled = false; }
  });
  $("#slots").addEventListener("change", async (e) => {
    const tk = e.target.dataset.take, tm = e.target.dataset.time;
    if (tk) {
      const [id, slot] = tk.split(":"), on = e.target.checked;
      on ? state.taken.add(tk) : state.taken.delete(tk);
      if (on) state.doseLog[tk] = { status: "taken", source: "app" }; else delete state.doseLog[tk];
      renderDoses();
      try {
        const r = await api.post("dose_toggle", { item_id: Number(id), slot, taken: on });
        const it = state.items.find(x => x.id === Number(id));
        if (it && r.stock !== undefined && r.stock !== it.stock) { it.stock = r.stock; renderDoses(); renderCabinet(); }
      }
      catch (err) { on ? state.taken.delete(tk) : state.taken.add(tk); renderDoses(); toast(err.message); }
    }
    if (tm) { state.reminders[tm] = e.target.value; saveReminders(); }
  });
  function remainingDoses() {
    const out = [];
    SLOTS.forEach(s => state.items.filter(m => (m.times || []).includes(s.id) && courseActive(m) && !state.taken.has(takenKey(m.id, s.id)) && !isSkipped(takenKey(m.id, s.id))).forEach(m => out.push(m.name + " (" + t(s.key) + ")")));
    return out;
  }
  $("#btnRemind").addEventListener("click", async () => {
    state.reminders.enabled = !state.reminders.enabled; renderDoses(); saveReminders();
    if (state.reminders.enabled) { sound("dose-reminder"); await ensurePush(true); }
  });

  /* Push subscription: lets the server remind you when the app is closed. */
  const urlB64ToBytes = (b) => { const s = atob((b + "=".repeat((4 - b.length % 4) % 4)).replace(/-/g, "+").replace(/_/g, "/")); return Uint8Array.from(s, c => c.charCodeAt(0)); };
  async function ensurePush(ask) {
    const out = $("#pushState");
    try {
      if (!state.features.push || !("serviceWorker" in navigator) || !("PushManager" in window)) return;
      if (Notification.permission === "default" && ask) await Notification.requestPermission();
      if (Notification.permission !== "granted") return;
      const reg = await navigator.serviceWorker.ready;
      let sub = await reg.pushManager.getSubscription();
      if (!sub) { const k = await api.get("push_key"); if (!k.key) return; sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToBytes(k.key) }); }
      await api.post("push_subscribe", sub.toJSON());
      out.textContent = "🔔 " + t("pushOn") + " · " + t("pushHint"); out.hidden = false;
    } catch (e) { /* push not available on this device */ }
  }
  /* While the app is open, also remind in the page itself. */
  const fired = {};
  setInterval(() => {
    if (!state.user || !state.reminders.enabled) return;
    const now = new Date(), hm = String(now.getHours()).padStart(2, "0") + ":" + String(now.getMinutes()).padStart(2, "0"), day = now.toDateString();
    SLOTS.forEach(s => {
      if (state.reminders[s.id] !== hm || fired[day + s.id]) return;
      fired[day + s.id] = true;
      const due = state.items.filter(m => (m.times || []).includes(s.id) && courseActive(m) && !state.taken.has(takenKey(m.id, s.id)) && !isSkipped(takenKey(m.id, s.id))).map(m => m.name);
      if (!due.length) return;
      const msg = t(s.key) + ": " + due.join(", ");
      sound("dose-reminder"); toast("⏰ " + msg); speak(msg);
    });
  }, 20000);

  /* ---------------- Notebook + kit ---------------- */
  function renderNotebook() {
    const who = state.nbMember, member = state.members.find(m => m.id === who);
    $("#nbMembers").innerHTML = state.members.map(m => '<button class="chip" data-nb="' + m.id + '" aria-pressed="' + (who === m.id) + '">' + esc(m.name) + "</button>").join("");
    $("#nbWho").textContent = member ? member.name : "";
    const meds = state.items.filter(m => m.member_id === who || !m.member_id);
    $("#nbRows").innerHTML = meds.length ? meds.map(m => {
      const st = statusOf(m.exp), when = (m.times || []).map(s => t(SLOTS.find(x => x.id === s).key)).join(", ") || "—";
      return "<tr><td><b>" + esc(m.name) + "</b>" + (m.info ? '<br><span class="muted" style="font-size:.82rem">' + esc(m.info.ingredient) + "</span>" : "") + "</td><td>" + esc(when) + '</td><td><span class="pill ' + st + '">' + esc(fmtYM(m.exp)) + "</span></td></tr>";
    }).join("") : '<tr><td colspan="3" class="muted">—</td></tr>';
    $("#nbAllergy").value = member && member.allergies ? member.allergies : "";
  }
  $("#nbMembers").addEventListener("click", (e) => { const b = e.target.closest("[data-nb]"); if (!b) return; state.nbMember = Number(b.dataset.nb); renderNotebook(); renderProfile(); loadVitals(); });
  const saveAllergy = debounce(async () => {
    const m = state.members.find(x => x.id === state.nbMember); if (!m) return;
    m.allergies = $("#nbAllergy").value;
    try { await api.post("member_save", { id: m.id, name: m.name, relation: m.relation, allergies: m.allergies }); refreshSafety([]); } catch (err) { toast(err.message); }
  }, 700);
  $("#nbAllergy").addEventListener("input", saveAllergy);
  $("#btnCopyNb").addEventListener("click", () => {
    const m = state.members.find(x => x.id === state.nbMember); if (!m) return;
    const lines = ["Medicine notebook: " + m.name, "Date: " + new Date().toLocaleDateString("en-IN"), ""];
    state.items.filter(x => x.member_id === m.id || !x.member_id).forEach(x => {
      lines.push("- " + x.name + (x.info ? " (" + x.info.ingredient + ")" : "") + " | " + ((x.times || []).map(s => t(SLOTS.find(y => y.id === s).key)).join(", ") || "as needed") + " | Exp " + (x.exp || "—") + (x.batch ? " | B.No. " + x.batch : ""));
    });
    lines.push("", "Allergies: " + (m.allergies || "none recorded"));
    const text = lines.join("\n"), done = () => toast("✔ " + t("nbCopied"));
    try { navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done)); } catch (e) { fallbackCopy(text, done); }
  });
  function fallbackCopy(text, done) { const ta = document.createElement("textarea"); ta.value = text; ta.style.position = "fixed"; ta.style.opacity = "0"; document.body.appendChild(ta); ta.select(); try { document.execCommand("copy"); done(); } catch (e) { toast("Select the text and copy it"); } ta.remove(); }
  function renderKit() { $("#kit").innerHTML = KIT.map(([k, label]) => '<li><label><input type="checkbox" data-kit="' + k + '"' + (state.kit.has(k) ? " checked" : "") + "><span>" + esc(label) + "</span></label></li>").join(""); }
  $("#kit").addEventListener("change", async (e) => {
    const k = e.target.dataset.kit; if (!k) return;
    e.target.checked ? state.kit.add(k) : state.kit.delete(k);
    try { await api.post("kit_toggle", { key: k, checked: e.target.checked }); } catch (err) { toast(err.message); }
  });

  /* ---------------- Medicine lookup ---------------- */
  const lookup = debounce(async (q) => {
    const ul = $("#lookupList");
    if (q.length < 2) { ul.innerHTML = ""; return; }
    try {
      const r = await api.get("catalog_search", { q });
      ul.innerHTML = r.results.length ? r.results.map(m => '<li><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><b>' + esc(m.name) + "</b>" +
        (m.info ? (m.info.rx ? '<span class="pill rx">Rx</span>' : '<span class="pill">OTC</span>') : "") + (m.source && m.source.indexOf("janaushadhi") === 0 ? '<span class="pill ex">Jan Aushadhi</span>' : "") + "</div>" +
        (m.unit ? '<span class="muted" style="font-size:.85rem">' + esc(m.unit) + "</span>" : "") +
        (m.info ? "<span>" + esc(m.info.use) + '</span><span class="muted" style="font-size:.85rem">' + esc(m.info.storage || "") + (m.info.note ? " · " + esc(m.info.note) : "") + "</span>" : "") + "</li>").join("")
        : '<li class="muted">No match. Check the generic name printed under the brand.</li>';
    } catch (err) { ul.innerHTML = '<li class="muted">' + esc(err.message) + "</li>"; }
  }, 250);
  $("#lookupInput").addEventListener("input", (e) => lookup(e.target.value.trim()));

  /* ---------------- Account ---------------- */
  function renderAccount() {
    if (!state.user) return;
    $("#pfName").value = state.user.name; $("#pfEmail").value = state.user.email; $("#pfPhone").value = state.user.phone || "";
    $("#memberList").innerHTML = state.members.map(m => '<form class="member-row" data-mid="' + m.id + '">' +
      '<div class="field"><label for="mn' + m.id + '">' + esc(t("personName")) + '</label><input id="mn' + m.id + '" name="name" maxlength="60" required value="' + esc(m.name) + '"></div>' +
      '<div class="field"><label for="mr' + m.id + '">' + esc(t("relation")) + '</label><input id="mr' + m.id + '" name="relation" maxlength="30" value="' + esc(m.relation || "") + '"></div>' +
      '<div class="actions-cell" style="display:flex;gap:6px"><button class="btn btn-ghost btn-sm" type="submit">' + esc(t("saveChanges")) + "</button>" + (m.self ? "" : '<button class="btn btn-ghost btn-sm" type="button" data-mdel="' + m.id + '">✕</button>') + "</div></form>").join("");
  }
  $("#memberList").addEventListener("submit", async (e) => {
    e.preventDefault();
    const f = e.target, id = Number(f.dataset.mid), m = state.members.find(x => x.id === id);
    try { await api.post("member_save", { id, name: f.name.value, relation: f.relation.value, allergies: m.allergies }); m.name = f.name.value; m.relation = f.relation.value; toast("✔ " + t("saved")); renderAll(); }
    catch (err) { toast(err.message); }
  });
  $("#memberList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-mdel]"); if (!b) return;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("confirmRemove"); return; }
    const id = Number(b.dataset.mdel);
    try { await api.post("member_delete", { id }); state.members = state.members.filter(m => m.id !== id); state.items.forEach(i => { if (i.member_id === id) i.member_id = null; }); renderAll(); }
    catch (err) { toast(err.message); }
  });
  $("#memberAdd").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { const r = await api.post("member_save", { name: $("#maName").value, relation: $("#maRel").value }); state.members.push({ id: r.id, name: $("#maName").value, relation: $("#maRel").value, allergies: null, self: false }); e.target.reset(); renderAll(); toast("✔ " + t("saved")); }
    catch (err) { toast(err.message); }
  });
  $("#profileForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { await api.post("settings_save", { name: $("#pfName").value, phone: $("#pfPhone").value, language: state.lang, weekly_email: $("#pfWeekly").checked }); state.user.name = $("#pfName").value; state.user.phone = $("#pfPhone").value; state.user.weeklyEmail = $("#pfWeekly").checked; renderSummary(); toast("✔ " + t("saved")); }
    catch (err) { toast(err.message); }
  });
  $("#pwForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#pwErr"), "");
    try { await api.post("password_change", { current: $("#pwCur").value, new: $("#pwNew").value }); e.target.reset(); toast("✔ " + t("saved")); }
    catch (err) { showErr($("#pwErr"), err.message); }
  });
  $("#btnExport").addEventListener("click", async () => {
    try {
      const res = await fetch("/api.php?action=export", { credentials: "same-origin" });
      const blob = await res.blob(), a = document.createElement("a");
      a.href = URL.createObjectURL(blob); a.download = "aushadhbox-my-data.json"; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    } catch (err) { toast(t("offline")); }
  });
  $("#deleteForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#delErr"), "");
    try { await api.post("account_delete", { password: $("#delPass").value }); state.items = []; state.members = []; await api.refresh(); showAuth("signup"); }
    catch (err) { showErr($("#delErr"), err.message); }
  });
  $("#btnLogout").addEventListener("click", async () => {
    try { const r = await api.post("logout"); api.csrf = r.csrf; } catch (e) { /* */ }
    state.items = []; state.members = []; showAuth();
  });


  /* ---------------- Plan, payments and family sharing ---------------- */
  const money = (n) => "₹" + Number(n).toLocaleString("en-IN");
  function fmtDay(d) { try { return new Date(d + "T00:00:00").toLocaleDateString(locale(), { day: "numeric", month: "short", year: "numeric" }); } catch (e) { return d; } }
  function track(ev) { try { if (window.msConsent) window.msConsent.track(ev); } catch (e) { /* analytics is optional */ } }
  async function refreshBilling() {
    try { const r = await api.get("billing_status"); state.billing = r; state.accounts = r.accounts || []; renderPlan(); renderShare(); } catch (e) { /* shown elsewhere */ }
  }
  function renderPlan() {
    const b = state.billing; if (!b || !state.user) return;
    const prem = b.plan === "premium";
    const badge = $("#planBadge"); badge.textContent = t(prem ? "planPremium" : "planFree"); badge.classList.toggle("premium", prem);
    $("#planUntil").textContent = prem ? t("planUntil", { d: fmtDay(b.until) }) : (b.expiredOn ? t("planEnded", { d: fmtDay(b.expiredOn) }) : "");
    const big = b.limits.items >= 1000;
    $("#planUsage").textContent = t("planUsage", { n: b.usage.items, max: big ? "∞" : b.limits.items, m: b.usage.members, mmax: b.limits.members });
    const pct = big ? Math.min(100, b.usage.items / 2) : Math.round(100 * b.usage.items / b.limits.items);
    const meter = $("#planMeter"); meter.style.width = Math.max(3, Math.min(100, pct)) + "%"; meter.className = pct >= 100 ? "bad" : pct >= 80 ? "warn" : "";
    $("#btnUpgrade").hidden = !b.owner || b.payments === "off";
    $("#btnUpgrade").textContent = t(prem ? "renew" : "upgrade");
    $("#btnReport").hidden = !prem;
    $("#planOwnerNote").hidden = b.owner; $("#planOwnerNote").textContent = b.owner ? "" : t("ownerPays", { name: b.ownerName });
    $("#pfWeekly").checked = !!state.user.weeklyEmail; $("#pfWeekly").disabled = !prem;
  }
  function renderShare() {
    const b = state.billing; if (!b) return;
    const accts = state.accounts || [];
    $("#acctList").innerHTML = accts.map(a => "<li><span><b>" + esc(a.name) + "</b><small>" + esc(a.email) + "</small></span><span>" +
      (a.owner ? '<span class="pill ex">' + esc(t("owner")) + "</span>" : "") + (a.you ? ' <span class="pill">' + esc(t("you")) + "</span>" : "") +
      (b.owner && !a.owner ? ' <button class="btn btn-ghost btn-sm" type="button" data-rm="' + a.id + '">' + esc(t("remove")) + "</button>" : "") + "</span></li>").join("");
    $("#acctList").hidden = accts.length < 2;
    $("#shareDesc").textContent = b.owner ? t("shareDesc") : t("sharedBy", { name: b.ownerName });
    $("#btnInvite").hidden = !b.owner;
    $("#btnLeave").hidden = b.owner;
    $("#joinForm").hidden = !(b.owner && accts.length <= 1);
  }
  $("#btnUpgrade").addEventListener("click", () => openUpgrade());
  $("#btnInvite").addEventListener("click", async () => {
    if (state.billing && state.billing.plan !== "premium") { openUpgrade(t("premiumOnly")); return; }
    try {
      const r = await api.post("invite_create");
      $("#inviteCode").textContent = r.code; $("#inviteBox").hidden = false; $("#inviteBox").dataset.link = r.link;
      track("invite_created");
    } catch (err) { if (err.code !== "premium_only") toast(err.message); }
  });
  $("#btnCopyCode").addEventListener("click", () => {
    const code = $("#inviteCode").textContent, link = $("#inviteBox").dataset.link || "";
    const text = code + (link ? "\n" + link : "");
    if (navigator.share) { navigator.share({ title: "AushadhBox", text: t("joinCode") + ": " + code, url: link }).catch(() => {}); return; }
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(() => toast("✔ " + t("copyCode"))).catch(() => fallbackCopy(text, () => toast("✔")));
    else fallbackCopy(text, () => toast("✔"));
  });
  $("#joinForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { const r = await api.post("invite_join", { code: $("#joinCode").value }); toast("✔ " + t("joined", { name: r.owner })); track("invite_joined"); $("#joinCode").value = ""; await enterApp(); go("account"); }
    catch (err) { toast(err.message); }
  });
  $("#btnLeave").addEventListener("click", async (e) => {
    const b = e.currentTarget;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("confirmRemove"); return; }
    try { await api.post("household_leave"); b.dataset.confirm = ""; b.textContent = t("leave"); await enterApp(); go("account"); } catch (err) { toast(err.message); }
  });
  $("#acctList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-rm]"); if (!b) return;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("confirmRemove"); return; }
    try { await api.post("household_remove", { user_id: Number(b.dataset.rm) }); refreshBilling(); } catch (err) { toast(err.message); }
  });

  function openUpgrade(reason) {
    const d = $("#upgradeDialog"); if (!d || !state.user) return;
    const b = state.billing || { prices: { month: 49, year: 399 }, owner: true, payments: "off" };
    if (!b.owner) { toast(t("ownerPays", { name: b.ownerName })); return; }
    const save = Math.max(0, Math.round(100 - b.prices.year * 100 / (b.prices.month * 12)));
    $("#upMonth").textContent = t("perMonth", { p: money(b.prices.month) });
    $("#upYear").textContent = t("perYear", { p: money(b.prices.year) });
    $("#upSave").textContent = t("savePct", { s: save }); $("#upSave").hidden = !save;
    $("#upReason").textContent = reason || ""; $("#upReason").hidden = !reason;
    $("#upDemo").hidden = b.payments !== "demo";
    showErr($("#upErr"), b.payments === "off" ? "Payments are not set up yet." : "");
    $("#btnPay").disabled = b.payments === "off";
    if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    track("upgrade_open");
  }
  function loadRazorpay() { return window.Razorpay ? Promise.resolve() : loadScript("https://checkout.razorpay.com/v1/checkout.js"); }
  async function paid(orderId, extra) {
    const r = await api.post("billing_verify", Object.assign({ order_id: orderId }, extra || {}));
    state.billing = Object.assign(state.billing || {}, r.billing);
    $("#upgradeDialog").close(); sound("saved"); toast("★ " + t("payDone")); track("purchase");
    await refreshBilling(); renderAll();
  }
  $("#btnPay").addEventListener("click", async () => {
    const btn = $("#btnPay"); btn.disabled = true; showErr($("#upErr"), "");
    const period = ($("#upgradeDialog input[name=period]:checked") || {}).value || "year";
    try {
      const o = await api.post("billing_order", { period });
      if (o.demo) { await paid(o.order_id); return; }
      await loadRazorpay();
      const rz = new window.Razorpay({
        key: o.key, order_id: o.order_id, amount: o.amount, currency: o.currency, name: o.name, description: o.description, prefill: o.prefill,
        theme: { color: "#1F3358" },
        handler: (resp) => { paid(o.order_id, { payment_id: resp.razorpay_payment_id, signature: resp.razorpay_signature }).catch(err => showErr($("#upErr"), err.message)); },
        modal: { ondismiss: () => { btn.disabled = false; toast(t("payCancelled")); } }
      });
      rz.on("payment.failed", (r) => { showErr($("#upErr"), (r.error && r.error.description) || t("payCancelled")); btn.disabled = false; });
      rz.open();
    } catch (err) { showErr($("#upErr"), err.message || "Could not start the payment."); }
    finally { if (!window.Razorpay) btn.disabled = false; else setTimeout(() => { btn.disabled = false; }, 1500); }
  });

  /* ---------------- Welcome tour (first sign-in) ---------------- */
  const TOUR_ART = [
    '<svg width="220" height="120" viewBox="0 0 220 120"><rect x="10" y="6" width="200" height="108" rx="12" fill="var(--surface)" stroke="var(--line)" stroke-width="2"/>' +
      [0, 1, 2].map(r => [0, 1, 2].map(c => '<rect x="' + (20 + c * 64) + '" y="' + (14 + r * 33) + '" width="56" height="27" rx="5" fill="var(--paper)" stroke="var(--line)"/><rect x="' + (38 + c * 64) + '" y="' + (33 + r * 33) + '" width="20" height="4" rx="2" fill="' + ["var(--ok)", "var(--warn)", "var(--bad)", "var(--ok)", "var(--ok)", "var(--warn)", "var(--ok)", "var(--bad)", "var(--ok)"][r * 3 + c] + '"/>').join("")).join("") + "</svg>",
    '<svg width="220" height="120" viewBox="0 0 220 120"><rect x="70" y="30" width="80" height="60" rx="8" fill="var(--paper)" stroke="var(--line)" stroke-width="2"/><text x="80" y="55" font-size="9" font-family="monospace" fill="var(--ink)">B.No. AB241</text><text x="80" y="70" font-size="9" font-family="monospace" fill="var(--ink)">Exp. 03/2027</text><path d="M55 30V18h12M153 18h12v12M165 90v12h-12M67 102H55V90" stroke="var(--ai)" stroke-width="4" fill="none" stroke-linecap="round"/><rect x="60" y="58" width="100" height="3" fill="var(--bad)" opacity=".7"/></svg>',
    '<svg width="220" height="120" viewBox="0 0 220 120" fill="none" stroke="var(--ai)" stroke-width="4" stroke-linecap="round"><circle cx="70" cy="45" r="16"/><path d="M42 98c3-18 15-28 28-28s25 10 28 28"/><circle cx="130" cy="50" r="13"/><path d="M108 98c3-14 12-22 22-22s19 8 22 22"/><circle cx="170" cy="62" r="9" stroke="var(--ok)"/><path d="M156 98c2-10 8-15 14-15s12 5 14 15" stroke="var(--ok)"/></svg>',
    '<svg width="220" height="120" viewBox="0 0 220 120" fill="none" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M80 80V58a30 30 0 0 1 60 0v22l8 10H72z" stroke="var(--ai)"/><path d="M100 98a10 10 0 0 0 20 0" stroke="var(--ai)"/><circle cx="150" cy="38" r="12" fill="var(--warn)" stroke="none"/><path d="M40 60l8 8 16-16" stroke="var(--ok)"/></svg>'
  ];
  let tourStep = 0;
  function openTour() {
    const d = $("#tourDialog"); if (!d) return; tourStep = 0; showTourStep();
    try { d.showModal(); } catch (e) { d.setAttribute("open", ""); }
  }
  function showTourStep() {
    const n = tourStep + 1;
    $("#tourArt").innerHTML = TOUR_ART[tourStep];
    $("#tourTitle").textContent = t("tour" + n + "T"); $("#tourText").textContent = t("tour" + n + "D");
    $("#tourDots").innerHTML = TOUR_ART.map((_, i) => '<i class="' + (i === tourStep ? "on" : "") + '"></i>').join("");
    $("#tourNext").textContent = t(n === TOUR_ART.length ? "tourDone" : "tourNext");
  }
  async function endTour(goScan) {
    $("#tourDialog").close();
    if (state.user) { state.user.onboarded = true; api.post("onboarded").catch(() => {}); }
    if (goScan) go("scan");
  }
  $("#tourNext").addEventListener("click", () => { if (tourStep < TOUR_ART.length - 1) { tourStep++; showTourStep(); } else endTour(true); });
  $("#tourSkip").addEventListener("click", () => endTour(false));
  $("#tourDialog").addEventListener("cancel", () => endTour(false));



  /* ---------------- Smart adding: usual schedules, prescriptions, bills, quick add ---------------- */
  const SLOT_KEYS = { m: "tMorning", a: "tAfternoon", e: "tEvening", b: "tBedtime" };
  const addDaysISO = (n) => { const d = new Date((state.today || new Date().toISOString().slice(0, 10)) + "T00:00:00"); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
  function applyUsual(info, unit) {
    if (!info) return;
    const anyTime = $$("#fTimes input").some(i => i.checked);
    let filled = false;
    if (!anyTime && info.usualTimes && info.usualTimes.length) { $$("#fTimes input").forEach(i => { i.checked = info.usualTimes.includes(i.value); }); filled = true; }
    if (!$("#fCourseEnd").value && info.courseDays && filled) $("#fCourseEnd").value = addDaysISO(info.courseDays - 1);
    const pack = window.RxParse ? window.RxParse.packCount(unit) : null;
    if ($("#fStock").value === "" && pack) $("#fStock").value = String(pack);
    if (info.food && !$("#fNotes").value) $("#fNotes").value = info.food;
    $("#usualNote").hidden = !filled;
  }
  $("#usualClear").addEventListener("click", () => { $$("#fTimes input").forEach(i => { i.checked = false; }); $("#fCourseEnd").value = ""; $("#usualNote").hidden = true; });

  let rx = { kind: "", rows: [] };
  function openReview(doc) {
    rx = { kind: doc.kind, rows: doc.items.map(i => ({ on: true, name: i.name, times: (i.times || []).slice(), days: i.days, stock: i.stock, exp: i.exp || "", batch: i.batch || "",
      notes: i.notes || "", courseEnd: i.courseEnd || null, perDose: i.perDose || 1, catalog_id: null, usual: false, sos: !!i.sos })) };
    $("#rxTitle").textContent = t(doc.kind === "bill" ? "rxTitleBill" : "rxTitlePres") + " (" + rx.rows.length + ")";
    $("#rxFor").innerHTML = '<option value="">' + esc(t("familyAll")) + "</option>" + state.members.map(m => '<option value="' + m.id + '">' + esc(m.name) + "</option>").join("");
    const self = state.members.find(m => m.self); $("#rxFor").value = self ? String(self.id) : "";
    showErr($("#rxErr"), ""); renderReview();
    const d = $("#rxDialog"); if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    // Match each name to the official medicine list; fill usual schedules and pack sizes where the paper didn't say.
    api.post("catalog_match_many", { names: rx.rows.map(r => r.name) }).then(res => {
      res.results.forEach((m, i) => {
        const r = rx.rows[i]; if (!r || !m.match) return;
        r.catalog_id = m.match.id;
        const info = m.info;
        if (info && !r.times.length && !r.sos && info.usualTimes && info.usualTimes.length) {
          r.times = info.usualTimes.slice(); r.usual = true;
          if (!r.days && info.courseDays) { r.days = info.courseDays; r.courseEnd = addDaysISO(info.courseDays - 1); }
          if (info.food && !r.notes) r.notes = info.food;
        }
        if (r.stock === null || r.stock === undefined) {
          const pack = window.RxParse.packCount(m.match.unit);
          if (r.days && r.times.length) r.stock = r.days * r.times.length * (r.perDose || 1); else if (pack) r.stock = pack;
        }
      });
      renderReview();
    }).catch(() => { /* offline: the list still works without matches */ });
  }
  function renderReview() {
    const n = rx.rows.filter(r => r.on).length;
    $("#rxList").innerHTML = rx.rows.length ? rx.rows.map((r, i) => '<div class="rx-row' + (r.on ? "" : " off") + '" data-i="' + i + '">' +
      '<div class="rx-top"><input type="checkbox" data-f="on" aria-label="' + esc(r.name) + '"' + (r.on ? " checked" : "") + '><input type="text" data-f="name" value="' + esc(r.name) + '" aria-label="' + esc(t("fName")) + '">' + (r.usual ? '<span class="rx-tag">' + esc(t("usualTag")) + "</span>" : "") + "</div>" +
      '<div class="rx-meta"><span class="slot-chips">' + ["m", "a", "e", "b"].map(sl => '<button type="button" data-slot="' + sl + '" aria-pressed="' + r.times.includes(sl) + '">' + esc(t(SLOT_KEYS[sl])) + "</button>").join("") + "</span>" +
      '<label>' + esc(t("days")) + ' <input type="number" min="1" max="365" data-f="days" value="' + (r.days || "") + '"></label>' +
      '<label>' + esc(t("stockShort")) + ' <input type="number" min="0" max="9999" data-f="stock" value="' + (r.stock === null || r.stock === undefined ? "" : r.stock) + '"></label>' +
      '<label>' + esc(t("expShort")) + ' <input type="month" data-f="exp" value="' + esc(r.exp) + '"></label></div>' +
      (r.notes ? '<span class="rx-note">' + esc(r.notes) + "</span>" : "") + "</div>").join("") : '<p class="muted">' + esc(t("rxNone")) + "</p>";
    $("#rxSave").textContent = t("rxSave", { n }); $("#rxSave").disabled = !n;
  }
  $("#rxList").addEventListener("input", (e) => {
    const row = e.target.closest("[data-i]"); if (!row) return; const r = rx.rows[Number(row.dataset.i)], f = e.target.dataset.f;
    if (f === "on") { r.on = e.target.checked; renderReview(); return; }
    if (f === "name") r.name = e.target.value;
    if (f === "days") { r.days = Number(e.target.value) || null; r.courseEnd = r.days ? addDaysISO(r.days - 1) : null; }
    if (f === "stock") r.stock = e.target.value === "" ? null : Number(e.target.value);
    if (f === "exp") r.exp = e.target.value;
  });
  $("#rxList").addEventListener("click", (e) => {
    const b = e.target.closest("[data-slot]"); if (!b) return;
    const r = rx.rows[Number(b.closest("[data-i]").dataset.i)], sl = b.dataset.slot;
    r.times = r.times.includes(sl) ? r.times.filter(x => x !== sl) : r.times.concat(sl).sort((x, y) => "maeb".indexOf(x) - "maeb".indexOf(y));
    b.setAttribute("aria-pressed", String(r.times.includes(sl)));
  });
  $("#rxSave").addEventListener("click", async () => {
    const member = $("#rxFor").value ? Number($("#rxFor").value) : null;
    const items = rx.rows.filter(r => r.on && r.name.trim()).map(r => ({ name: r.name.trim(), catalog_id: r.catalog_id, times: r.times, stock: r.stock, per_dose: r.perDose,
      course_end: r.courseEnd || "", exp: r.exp || "", batch: r.batch || "", notes: r.notes, member_id: member }));
    if (!items.length) return;
    $("#rxSave").disabled = true; showErr($("#rxErr"), "");
    try {
      const res = await api.post("items_bulk_save", { items });
      state.items = state.items.concat(res.items); $("#rxDialog").close(); sound("saved");
      toast("✔ " + t("rxSave", { n: res.items.length }).replace(/^\S+\s/, "") ); renderAll(); go("cabinet"); track("rx_saved"); refreshSafety(res.items.map(x => x.id));
    } catch (err) { showErr($("#rxErr"), err.message); }
    $("#rxSave").disabled = false;
  });
  $("#rxPasteBtn").addEventListener("click", () => {
    const text = $("#rxPasteText").value.trim(); if (!text) return;
    const doc = window.RxParse.parse(text, state.today);
    if (!doc.items.length) { toast(t("rxNone")); return; }
    if (doc.kind === "label") doc.kind = "prescription";
    openReview(doc); $("#rxPasteText").value = "";
  });

  /* Quick add from a typed or spoken sentence. Returns a message for the voice assistant too. */
  async function quickAddText(text) {
    const it = window.RxParse.sentence(text, state.members, state.today);
    if (!it || !it.name || it.name.length < 3) throw new Error(t("quickNotUnderstood"));
    let catalogId = null;
    try {
      const m = (await api.post("catalog_match_many", { names: [it.name] })).results[0];
      if (m && m.match) {
        catalogId = m.match.id;
        if (!it.times.length && !it.sos && m.info && m.info.usualTimes && m.info.usualTimes.length) {
          it.times = m.info.usualTimes.slice();
          if (!it.days && m.info.courseDays) { it.days = m.info.courseDays; it.courseEnd = addDaysISO(m.info.courseDays - 1); }
          if (m.info.food && !it.notes) it.notes = m.info.food;
        }
        if (it.stock === null) { const pack = window.RxParse.packCount(m.match.unit); it.stock = it.days && it.times.length ? it.days * it.times.length * it.perDose : pack; }
      }
    } catch (e) { /* offline: save what we understood */ }
    const self = state.members.find(m => m.self);
    const r = await api.post("item_save", { name: it.name, catalog_id: catalogId, times: it.times, stock: it.stock, per_dose: it.perDose, course_end: it.courseEnd || "", notes: it.notes,
      member_id: it.member ? it.member.id : (self ? self.id : null) });
    state.items = state.items.filter(x => x.id !== r.item.id).concat([r.item]); renderAll(); sound("saved"); track("quick_add"); refreshSafety([r.item.id]);
    return t("quickDone", { name: r.item.name });
  }
  $("#quickForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#quickErr"), "");
    const text = $("#quickInput").value.trim(); if (!text) return;
    try { toast("✔ " + await quickAddText(text)); $("#quickInput").value = ""; } catch (err) { showErr($("#quickErr"), err.message); }
  });
  $("#quickMic").addEventListener("click", () => {
    const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Rec) { showErr($("#quickErr"), t("voiceNoMic")); return; }
    const r = new Rec(); r.lang = langInfo().speech; r.interimResults = false;
    r.onresult = (ev) => { $("#quickInput").value = ev.results[0][0].transcript; $("#quickForm").requestSubmit(); };
    r.onerror = () => showErr($("#quickErr"), t("voiceNoMic"));
    try { r.start(); } catch (err) { showErr($("#quickErr"), t("voiceNoMic")); }
  });

  /* Shopping list: what is running out, finished or expired while still in use, with the cheaper generic. */
  function shoppingList() {
    const out = [];
    state.items.forEach(m => {
      const si = stockInfo(m), st = statusOf(m.exp), inUse = (m.times || []).length && courseActive(m);
      let why = null;
      if (si && si.cls === "out") why = t("shopOut"); else if (si && si.cls === "low") why = t("shopLow") + " (" + si.text + ")";
      else if (st === "bad" && (inUse || m.stock !== null)) why = t("shopExpired");
      if (why) out.push({ m, why, generic: m.info ? m.info.ingredient : "" });
    });
    return out;
  }
  $("#btnShop").addEventListener("click", () => {
    const list = shoppingList();
    $("#shopList").innerHTML = list.length ? list.map(x => "<li><b>" + esc(x.m.name) + "</b><small>" + esc(memberName(x.m.member_id) + " · " + x.why) + "</small>" +
      (x.generic ? "<small>" + esc(t("shopGeneric", { g: x.generic + " (Jan Aushadhi)" })) + "</small>" : "") + "</li>").join("") : '<li class="muted">' + esc(t("shopEmpty")) + "</li>";
    const text = t("shopTitle") + " (AushadhBox)\n" + list.map(x => "• " + x.m.name + " — " + x.why + (x.generic ? " — " + x.generic : "")).join("\n");
    $("#shopWa").href = "https://wa.me/?text=" + encodeURIComponent(text); $("#shopWa").hidden = !list.length; $("#shopCopy").dataset.text = text;
    const ph = state.pharmacy || {};
    $("#shopPharm").hidden = !list.length || !ph.phone;
    if (ph.phone) $("#shopPharm").href = "https://wa.me/91" + ph.phone + "?text=" + encodeURIComponent((ph.name ? "Hello " + ph.name + ",\n" : "") + text + "\n\nPlease keep these ready. Thank you.");
    const d = $("#shopDialog"); try { d.showModal(); } catch (e) { d.setAttribute("open", ""); }
  });
  $("#shopCopy").addEventListener("click", (e) => { const tx = e.currentTarget.dataset.text || ""; const done = () => toast("✔ " + t("calCopied")); if (navigator.clipboard) navigator.clipboard.writeText(tx).then(done).catch(() => fallbackCopy(tx, done)); else fallbackCopy(tx, done); });

  /* Shared from WhatsApp or the gallery (Android "Share" → AushadhBox): the service worker keeps the photo for us. */
  async function openSharedPhoto() {
    try {
      const cache = await caches.open("aushadhbox-share"), res = await cache.match("/shared-image"); if (!res) return;
      const blob = await res.blob(); await cache.delete("/shared-image");
      resetForm(); go("scan");
      const img = new Image();
      img.onload = () => { drawToCanvas(img, img.naturalWidth, img.naturalHeight); URL.revokeObjectURL(img.src); processCanvas(canvas); };
      img.src = URL.createObjectURL(blob);
    } catch (e) { /* nothing shared */ }
  }



  /* ---------------- Safety check (server checks duplicates, risky pairs, timing gaps and allergies) ---------------- */
  const PAIR_KEY = { bleed: "aPairBleed", kidney: "aPairKidney", clopi: "aPairClopi", breathing: "aPairBreathing", serotonin: "aPairSerotonin", drowsy: "aPairDrowsy" };
  function alertText(a) {
    const p = Object.assign({}, a.p, { who: a.p.who || t("familyAll"), slot: a.p.slot ? t((SLOTS.find(x => x.id === a.p.slot) || SLOTS[0]).key).toLowerCase() : "" });
    const key = a.code === "same" ? "aSame" : a.code === "kind" ? "aKind" : a.code === "gap" ? "aGap" : a.code === "allergy" ? "aAllergy" : PAIR_KEY[a.p.why] || "aAsk";
    return t(key, p);
  }
  function renderSafety() {
    const list = state.alerts || [];
    $("#safetyCard").hidden = !list.length;
    $("#safetyCard").style.borderLeftColor = list.some(a => a.level === "high") ? "var(--bad)" : "var(--warn)";
    $("#safetyList").innerHTML = list.map(a => '<li class="' + esc(a.level) + '"><span>' + (a.level === "high" ? "⚠ " : "") + esc(alertText(a)) + "</span><small>" + esc(memberName(a.memberId) + " · " + t("aAsk")) + "</small></li>").join("");
  }
  async function refreshSafety(newIds) {
    try {
      const r = await api.get("safety_check"); state.alerts = r.alerts; renderSafety();
      const hit = r.alerts.find(a => a.level !== "low" && a.items.some(id => newIds.includes(id)));
      if (hit) { toast("⚠ " + alertText(hit)); sound("expiry-alert"); }
    } catch (e) { /* offline */ }
  }

  /* ---------------- AI helper switch ---------------- */
  function renderAi() {
    if (!state.user) return;
    const avail = !!(state.features && state.features.ai);
    $("#aiOptIn").checked = !!state.user.aiOptIn; $("#aiOptIn").disabled = !avail && !state.user.aiOptIn; $("#aiOffNote").hidden = avail;
  }
  $("#aiOptIn").addEventListener("change", async (e) => {
    try { const r = await api.post("ai_opt_in", { on: e.target.checked }); state.user.aiOptIn = r.aiOptIn; toast("✔ " + t("saved")); }
    catch (err) { e.target.checked = !e.target.checked; toast(err.message); }
  });


  /* ======== Ideas from Japanese medication-notebook apps (お薬手帳) ======== */
  const slotLabel = (id) => t((SLOTS.find(x => x.id === id) || SLOTS[0]).key);

  /* Follow-up check-ins after a new medicine (day 3 and day 10) */
  function renderFollowups() {
    const list = state.followups || [];
    $("#fuCard").hidden = !list.length;
    $("#fuList").innerHTML = list.map(f => '<div class="fu-item" data-fu="' + f.id + '"><b>' + esc(t("fuAsk", { who: memberName(f.memberId), med: f.name })) + '</b><div class="fu-btns">' +
      ["better", "same", "worse", "side"].map(a => '<button type="button" class="btn btn-ghost btn-sm" data-ans="' + a + '">' + esc(t({ better: "fuBetter", same: "fuSame", worse: "fuWorse", side: "fuSide" }[a])) + "</button>").join("") +
      '</div><input maxlength="300" placeholder="' + esc(t("fuNote")) + '" aria-label="' + esc(t("fuNote")) + '"></div>').join("");
  }
  $("#fuList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-ans]"); if (!b) return;
    const box = b.closest("[data-fu]"), id = Number(box.dataset.fu), answer = b.dataset.ans;
    try {
      await api.post("followup_answer", { id, answer, note: box.querySelector("input").value });
      state.followups = state.followups.filter(f => f.id !== id); renderFollowups();
      toast(answer === "side" || answer === "worse" ? t("fuSideMsg") : "✔ " + t("fuThanks")); sound("saved");
    } catch (err) { toast(err.message); }
  });

  /* Printable weekly medicine calendar (お薬カレンダー): cut out and keep next to the pill box */
  $("#btnCalPrint").addEventListener("click", () => {
    const days = []; for (let i = 0; i < 7; i++) { const d = new Date(); d.setDate(d.getDate() + i); days.push(d); }
    const iso = (d) => d.toISOString().slice(0, 10);
    const cell = (slot, d) => state.items.filter(m => (m.times || []).includes(slot) && (!m.courseEnd || m.courseEnd >= iso(d))).map(m => "<div>☐ " + esc(m.name) + (m.member_id ? " <small>(" + esc(memberName(m.member_id)) + ")</small>" : "") + "</div>").join("") || "<span style='color:#999'>—</span>";
    const html = "<!doctype html><html lang='" + esc(state.lang) + "'><head><meta charset='utf-8'><title>" + esc(t("calWeek")) + "</title><style>body{font:14px/1.4 system-ui,sans-serif;margin:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #999;padding:6px;vertical-align:top}th{background:#eee}td div{margin:2px 0}small{color:#555}@media print{button{display:none}}</style></head><body>" +
      "<h2>" + esc(t("calWeek")) + " · AushadhBox</h2><table><tr><th></th>" + days.map(d => "<th>" + esc(d.toLocaleDateString(locale(), { weekday: "short", day: "numeric", month: "short" })) + "</th>").join("") + "</tr>" +
      SLOTS.map(s => "<tr><th>" + esc(t(s.key)) + "<br><small>" + esc(state.reminders[s.id] || "") + "</small></th>" + days.map(d => "<td>" + cell(s.id, d) + "</td>").join("") + "</tr>").join("") +
      "</table><p><button onclick='print()'>🖨 " + esc(t("shPrint")) + "</button></p></body></html>";
    openPrintable(html);
  });
  function openPrintable(html) {
    const w = window.open("", "_blank");
    if (w) { w.document.open(); w.document.write(html); w.document.close(); }
    else { const blob = new Blob([html], { type: "text/html" }); location.href = URL.createObjectURL(blob); }
  }

  /* Emergency details on each person, and the offline emergency card with a QR code */
  function renderProfile() {
    const m = state.members.find(x => x.id === state.nbMember); if (!m) return;
    $("#pfBlood").value = m.bloodGroup || ""; $("#pfCond").value = m.conditions || ""; $("#pfContact").value = m.emergencyContact || "";
  }
  const saveProfile = debounce(async () => {
    const m = state.members.find(x => x.id === state.nbMember); if (!m) return;
    m.bloodGroup = $("#pfBlood").value || null; m.conditions = $("#pfCond").value.trim() || null; m.emergencyContact = $("#pfContact").value.trim() || null;
    try { await api.post("member_save", { id: m.id, name: m.name, relation: m.relation, allergies: m.allergies, blood_group: m.bloodGroup || "", conditions: m.conditions || "", emergency_contact: m.emergencyContact || "" }); saveEmergencySnapshot(); }
    catch (err) { toast(err.message); }
  }, 700);
  ["#pfBlood", "#pfCond", "#pfContact"].forEach(sel => $(sel).addEventListener(sel === "#pfBlood" ? "change" : "input", saveProfile));
  function emergencyData() {
    return { saved: new Date().toISOString().slice(0, 16).replace("T", " "), people: state.members.map(m => ({
      name: m.name, blood: m.bloodGroup || "", conditions: m.conditions || "", allergies: m.allergies || "", contact: m.emergencyContact || "",
      meds: state.items.filter(i => i.member_id === m.id && (i.times || []).length && courseActive(i)).map(i => i.name + " (" + i.times.map(slotLabel).join(", ") + ")")
    })) };
  }
  function saveEmergencySnapshot() { if (!state.user) return; try { localStorage.setItem("ab-emergency", JSON.stringify(emergencyData())); } catch (e) { /* private window */ } }
  function emergencyText(p) {
    return "EMERGENCY · " + p.name + (p.blood ? " · Blood " + p.blood : "") + "\nAllergies: " + (p.allergies || "none recorded") + "\nConditions: " + (p.conditions || "-") +
      "\nMedicines: " + (p.meds.join("; ") || "-") + (p.contact ? "\nCall: " + p.contact : "");
  }
  function openEmergency() {
    let data = null;
    if (state.user) data = emergencyData();
    else try { data = JSON.parse(localStorage.getItem("ab-emergency") || "null"); } catch (e) { data = null; }
    if (!data || !data.people.length) return;
    const people = state.user && state.nbMember ? data.people.slice().sort((a, b) => (b.name === memberName(state.nbMember)) - (a.name === memberName(state.nbMember))) : data.people;
    $("#emBody").innerHTML = '<div class="stack">' + people.map(p => '<div class="em-person"><b class="big">' + esc(p.name) + (p.blood ? ' <span class="blood">' + esc(p.blood) + "</span>" : "") + "</b>" +
      "<span><b>" + esc(t("nbAllergy")) + ":</b> " + esc(p.allergies || t("emNone")) + "</span><span><b>" + esc(t("emCond")) + ":</b> " + esc(p.conditions || t("emNone")) + "</span>" +
      "<span><b>" + esc(t("emMeds")) + ":</b> " + esc(p.meds.join("; ") || t("emNone")) + "</span>" + (p.contact ? "<span><b>☎</b> " + esc(p.contact) + "</span>" : "") + "</div>").join("") +
      '</div><div class="em-qr"><canvas id="emQr" width="200" height="200" aria-label="QR"></canvas><small class="muted">' + esc(people[0].name) + "</small></div>";
    drawQr($("#emQr"), emergencyText(people[0]));
    $("#emDialog").dataset.text = emergencyText(people[0]);
    const d = $("#emDialog"); if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
  }
  function drawQr(cv, text) {
    if (!window.qrcode) return;
    try { window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs["UTF-8"]; } catch (e) { /* older library */ }
    const q = window.qrcode(0, "M"); q.addData(text); q.make();
    const n = q.getModuleCount(), size = cv.width, k = Math.floor(size / (n + 4)), off = Math.floor((size - k * n) / 2), c = cv.getContext("2d");
    c.fillStyle = "#fff"; c.fillRect(0, 0, size, size); c.fillStyle = "#000";
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect(off + col * k, off + r * k, k, k);
  }
  $("#btnEmergency").addEventListener("click", openEmergency);
  $("#emImage").addEventListener("click", () => {
    const text = $("#emDialog").dataset.text || "", lines = text.split("\n"), cv = document.createElement("canvas"); cv.width = 1080; cv.height = 1920;
    const c = cv.getContext("2d"); c.fillStyle = "#fff"; c.fillRect(0, 0, 1080, 1920); c.fillStyle = "#B3382A"; c.fillRect(0, 0, 1080, 160);
    c.fillStyle = "#fff"; c.font = "bold 64px sans-serif"; c.fillText("✚ " + t("emTitle"), 60, 105);
    c.fillStyle = "#111"; c.font = "44px sans-serif"; let y = 260;
    lines.forEach(l => { const words = l.split(" "); let line = ""; words.forEach(w => { if (c.measureText(line + w).width > 960) { c.fillText(line, 60, y); y += 60; line = ""; } line += w + " "; }); c.fillText(line, 60, y); y += 76; });
    const qr = document.createElement("canvas"); qr.width = 600; qr.height = 600; drawQr(qr, text); c.drawImage(qr, 240, Math.min(y + 20, 1260), 600, 600);
    const a = document.createElement("a"); a.download = "emergency-card.png"; a.href = cv.toDataURL("image/png"); a.click();
  });

  /* Health diary (血圧・血糖 records): readings with safe-range flags and a small chart */
  const VK = { bp: ["kBp", "mmHg"], sugar: ["kSugar", "mg/dL"], weight: ["kWeight", "kg"], temp: ["kTemp", "°C"], spo2: ["kSpo2", "%"], pulse: ["kPulse", "/min"] };
  let vitals = [];
  function vitalKindChanged() {
    const k = $("#hdKind").value;
    $("#hdV2").hidden = $("#hdSlash").hidden = k !== "bp"; $("#hdV2").required = k === "bp"; $("#hdUnit").textContent = VK[k][1]; $("#hdCtxWrap").hidden = k !== "sugar";
    $("#hdV1").placeholder = { bp: "120", sugar: "110", weight: "62", temp: "37.0", spo2: "98", pulse: "72" }[k];
    renderVitals();
  }
  async function loadVitals() {
    if (!$("#hdKind").options.length) { $("#hdKind").innerHTML = Object.keys(VK).map(k => '<option value="' + k + '">' + esc(t(VK[k][0])) + "</option>").join(""); vitalKindChanged(); }
    try { vitals = (await api.get("vitals", { member_id: state.nbMember || "" })).vitals; renderVitals(); } catch (e) { /* offline */ }
  }
  const fmtV = (v) => v.kind === "bp" ? Math.round(v.v1) + "/" + Math.round(v.v2) : String(v.v1).replace(/\.0$/, "");
  const flagText = (f) => t({ ok: "fOk", low: "fLow", high: "fHigh", urgent: "fUrgent" }[f] || "fOk");
  function renderVitals() {
    const k = $("#hdKind").value, list = vitals.filter(v => v.kind === k), pts = list.slice(0, 20).reverse();
    $("#hdList").innerHTML = list.length ? list.slice(0, 8).map(v => '<li><span><span class="dot ' + esc(v.flag) + '"></span><b>' + esc(fmtV(v)) + "</b> " + esc(VK[v.kind][1]) + (v.context ? " · " + esc(t({ fasting: "hdFasting", after_meal: "hdAfter", random: "hdRandom" }[v.context])) : "") +
      '</span><small class="muted">' + esc(new Date(v.at.replace(" ", "T")).toLocaleString(locale(), { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })) + "</small></li>").join("") : '<li class="muted">' + esc(t("hdNone")) + "</li>";
    if (pts.length < 2) { $("#hdChart").innerHTML = ""; return; }
    const vals = pts.flatMap(v => [v.v1].concat(v.v2 !== null ? [v.v2] : [])), lo = Math.min(...vals), hi = Math.max(...vals), span = Math.max(1, hi - lo), W = 300, H = 110;
    const line = (key) => pts.map((v, i) => (i * W / (pts.length - 1)).toFixed(1) + "," + (H - 8 - (v[key] - lo) * (H - 16) / span).toFixed(1)).join(" ");
    $("#hdChart").innerHTML = '<svg viewBox="0 0 300 110" preserveAspectRatio="none" role="img" aria-label="' + esc(t(VK[k][0])) + '"><polyline fill="none" stroke="var(--ai)" stroke-width="2.5" points="' + line("v1") + '"/>' +
      (k === "bp" ? '<polyline fill="none" stroke="var(--warn)" stroke-width="2.5" points="' + line("v2") + '"/>' : "") + "</svg>";
  }
  $("#hdKind").addEventListener("change", vitalKindChanged);
  $("#hdForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const k = $("#hdKind").value;
    try {
      const r = await api.post("vital_save", { kind: k, v1: Number($("#hdV1").value.replace(",", ".")), v2: k === "bp" ? Number($("#hdV2").value) : null, context: k === "sugar" ? $("#hdCtx").value : null, member_id: state.nbMember || null });
      $("#hdFlag").className = "hd-flag " + r.flag; $("#hdFlag").textContent = (r.flag === "urgent" ? "⚠ " : "") + fmtV({ kind: k, v1: r.v1, v2: r.v2 }) + " " + VK[k][1] + " · " + flagText(r.flag); $("#hdFlag").hidden = false;
      if (r.flag === "urgent") sound("expiry-alert"); else sound("saved");
      $("#hdV1").value = ""; $("#hdV2").value = ""; loadVitals();
    } catch (err) { toast(err.message); }
  });

  /* Doctor visits and the visit sheet (medicines, tablets left, doses, side effects, readings, questions) */
  function renderAppointments() {
    const list = (state.appointments || []).filter(a => a.at >= new Date(Date.now() - 864e5).toISOString().slice(0, 10));
    $("#apList").innerHTML = list.length ? list.map(a => "<li><span><b>" + esc(new Date(a.at.replace(" ", "T")).toLocaleString(locale(), { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })) + "</b> · " + esc(a.doctor) +
      (a.place ? " · " + esc(a.place) : "") + ' <small class="muted">' + esc(memberName(a.memberId)) + '</small></span><button type="button" class="link" data-apdel="' + a.id + '" aria-label="' + esc(t("boxRemove")) + '">✕</button></li>').join("") : '<li class="muted">' + esc(t("apNone")) + "</li>";
  }
  $("#apForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const r = await api.post("appointment_save", { doctor: $("#apDoctor").value, place: $("#apPlace").value, at: $("#apAt").value, questions: $("#apQ").value, member_id: state.nbMember || null });
      state.appointments = r.appointments; e.target.reset(); renderAppointments(); toast("✔ " + t("saved"));
    } catch (err) { toast(err.message); }
  });
  $("#apList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-apdel]"); if (!b) return;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("confirmRemove"); return; }
    try { state.appointments = (await api.post("appointment_delete", { id: Number(b.dataset.apdel) })).appointments; renderAppointments(); } catch (err) { toast(err.message); }
  });
  let sheetText = "", sheetHtml = "";
  $("#btnSheet").addEventListener("click", async () => {
    try {
      const s = await api.get("visit_sheet", { member_id: state.nbMember || "" });
      const P = s.person || {}, who = P.name || t("familyAll"), ansKey = { better: "fuBetter", same: "fuSame", worse: "fuWorse", side: "fuSide" };
      const reasonKey = { fine: "skipFine", side: "skipSide", out: "skipOut", doctor: "skipDoctor", other: "skipOther" };
      const sec = (title, body) => body ? "<section><h4>" + esc(title) + "</h4>" + body + "</section>" : "";
      const adh = s.adherence[0];
      sheetHtml = "<p><b>" + esc(who) + "</b>" + (P.bloodGroup ? " · " + esc(P.bloodGroup) : "") + " · " + esc(s.date) + "</p>" +
        sec(t("nbAllergy"), "<p>" + esc(P.allergies || t("emNone")) + "</p>") + (P.conditions ? sec(t("emCond"), "<p>" + esc(P.conditions) + "</p>") : "") +
        sec(t("shMeds"), s.medicines.length ? "<table>" + s.medicines.map(m => "<tr><td><b>" + esc(m.name) + "</b>" + (m.ingredient ? "<br><small>" + esc(m.ingredient) + "</small>" : "") + "</td><td>" + esc(m.times.map(slotLabel).join(", ")) + (m.perDose > 1 ? " ×" + m.perDose : "") +
          "</td><td>" + (m.left !== null ? esc(t("shLeft", { n: m.left, d: m.daysLeft === null ? "—" : m.daysLeft })) : "—") + "</td></tr>").join("") + "</table>" : "<p>—</p>") +
        (adh ? sec(t("shAdh"), "<p>" + esc(adh.taken + " / " + adh.expected + " (" + adh.percent + "%)" + (adh.worstSlot ? " · " + t("adhWorst", { slot: slotLabel(adh.worstSlot) }) : "")) + "</p>") : "") +
        (s.followups.length ? sec(t("shFu"), "<ul>" + s.followups.map(f => "<li>" + esc(f.name + ": " + t(ansKey[f.answer]) + (f.note ? " — " + f.note : "") + " (" + f.d + ")") + "</li>").join("") + "</ul>") : "") +
        (s.skips.length ? sec(t("shSkips"), "<ul>" + s.skips.map(k => "<li>" + esc(k.name + ": " + t(reasonKey[k.reason] || "skipOther") + " ×" + k.n) + "</li>").join("") + "</ul>") : "") +
        (s.vitals.length ? sec(t("shVitals"), "<table>" + s.vitals.map(v => "<tr><td>" + esc(t(VK[v.kind][0])) + "</td><td>" + esc(fmtV({ kind: v.kind, v1: v.latest.v1, v2: v.latest.v2 }) + " " + VK[v.kind][1]) + "</td><td><small>avg " +
          esc(v.kind === "bp" ? Math.round(v.avg1) + "/" + Math.round(v.avg2) : v.avg1) + " · " + v.count + "×" + (v.outOfRange ? " · ⚠ " + v.outOfRange : "") + "</small></td></tr>").join("") + "</table>") : "") +
        (s.alerts.length ? sec(t("safetyTitle"), "<ul>" + s.alerts.map(a => "<li>" + esc(alertText(a)) + "</li>").join("") + "</ul>") : "") +
        (s.next && s.next.questions ? sec(t("shAsk"), "<p>" + esc(s.next.questions) + "</p>") : "");
      $("#sheetBody").innerHTML = sheetHtml;
      sheetText = $("#sheetBody").innerText;
      const d = $("#sheetDialog"); if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    } catch (err) { toast(err.message); }
  });
  $("#sheetPrint").addEventListener("click", () => openPrintable("<!doctype html><html><head><meta charset='utf-8'><title>" + esc(t("shTitle")) + "</title><style>body{font:14px/1.45 system-ui,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}td{border-bottom:1px solid #ccc;padding:5px;vertical-align:top}h4{margin:14px 0 4px}</style></head><body><h2>" +
    esc(t("shTitle")) + " · AushadhBox</h2>" + sheetHtml + "<script>print()<\/script></body></html>"));
  $("#sheetShare").addEventListener("click", async () => {
    const text = t("shTitle") + "\n\n" + sheetText;
    if (navigator.share) { try { await navigator.share({ title: t("shTitle"), text }); return; } catch (e) { /* cancelled */ } }
    const done = () => toast("✔ " + t("nbCopied")); if (navigator.clipboard) navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done)); else fallbackCopy(text, done);
  });

  /* My pharmacy (処方箋送信): send the shopping list straight to the pharmacy's WhatsApp */
  function renderPharmacy() {
    const ph = state.pharmacy || {};
    $("#phName").value = ph.name || ""; $("#phPhone").value = ph.phone || "";
  }
  $("#phForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { const r = await api.post("pharmacy_save", { name: $("#phName").value, phone: $("#phPhone").value }); state.pharmacy = r.pharmacy; renderPharmacy(); toast("✔ " + t("saved")); }
    catch (err) { toast(err.message); }
  });

  /* ---------------- Smart pillbox ---------------- */
  let boxes = { devices: [], events: [] };
  async function refreshData() {
    try {
      const data = await api.get("bootstrap");
      state.items = data.items; state.taken = new Set(data.taken); state.doseLog = data.doseLog || {};
      renderAll();
    } catch (e) { /* offline */ }
  }
  // Pillbox openings and ticks from other phones show up without reloading.
  setInterval(() => { if (state.user && document.visibilityState === "visible" && (current === "doses" || current === "cabinet")) refreshData(); }, 60000);
  async function loadBoxes() {
    try { boxes = await api.get("device_list"); renderBoxes(); } catch (e) { /* shown elsewhere */ }
  }
  function fmtSeen(dt) { try { return new Date(dt.replace(" ", "T")).toLocaleString(locale(), { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }); } catch (e) { return dt; } }
  function renderBoxes() {
    $("#bxFor").innerHTML = '<option value="">' + esc(t("familyAll")) + "</option>" + state.members.map(m => '<option value="' + m.id + '">' + esc(m.name) + "</option>").join("");
    const slotName = (id) => t((SLOTS.find(x => x.id === id) || SLOTS[0]).key);
    const OUT = { taken: "outTaken", again: "outAgain", outside: "outOutside", already: "outAlready", nodose: "outNodose" };
    $("#boxList").innerHTML = boxes.devices.map(d => {
      const status = !d.paired ? "" : d.online ? '<span class="pill ok">' + esc(t("boxOnline")) + "</span>" : '<span class="pill warn">' + esc(t("boxOffline")) + "</span>";
      const lids = d.compartments >= 4 ? SLOTS.map((s, i) => '<button type="button" class="btn btn-ghost btn-sm" data-boxtest="' + d.id + ":" + (i + 1) + '">' + esc(t(s.key)) + "</button>").join("")
        : '<button type="button" class="btn btn-ghost btn-sm" data-boxtest="' + d.id + ':1">' + esc(t("boxTest").split(":").pop().trim()) + "</button>";
      return '<div class="box-item"><div class="box-top"><b>' + esc(d.name) + "</b>" + status + "</div>" +
        "<small>" + esc(memberName(d.memberId)) + " · " + esc(d.compartments >= 4 ? t("boxLids4") : t("boxLids1")) + "</small>" +
        (d.paired ? "<small>" + esc(d.lastSeen ? t("boxSeen", { t: fmtSeen(d.lastSeen) }) : t("boxNever")) + (d.battery !== null ? " · " + esc(t("boxBattery", { n: d.battery })) : "") + (d.firmware ? " · v" + esc(d.firmware) : "") + "</small>"
          : '<div class="pair"><span class="muted">' + esc(t("boxPairCode")) + '</span><span class="code mono">' + esc(d.pairCode || "——") + '</span><small class="muted">' + esc(t("boxPairHint")) + "</small></div>") +
        '<div class="box-test"><small class="muted">' + esc(t("boxTest")) + '</small><div class="box-lids">' + lids + "</div></div>" +
        '<div class="box-actions"><button type="button" class="link" data-boxcode="' + d.id + '">' + esc(t("boxNewCode")) + '</button><button type="button" class="link" data-boxdel="' + d.id + '">' + esc(t("boxRemove")) + "</button></div></div>";
    }).join("") + (boxes.devices.length ? '<div class="box-events"><h4>' + esc(t("boxRecent")) + "</h4>" + (boxes.events.length ? "<ul>" + boxes.events.map(e => {
      const dev = boxes.devices.find(d => d.id === Number(e.deviceId));
      return '<li class="ev ' + esc(e.outcome || "") + '"><span class="mono">' + esc(fmtSeen(e.at)) + "</span> " + esc((dev ? dev.name : "") + (e.slot ? " · " + slotName(e.slot) : "")) + " — " + esc(t(OUT[e.outcome] || "outOutside")) + "</li>";
    }).join("") + "</ul>" : '<p class="muted">' + esc(t("boxNoEvents")) + "</p>") + "</div>" : "");
  }
  $("#boxAdd").addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      await api.post("device_add", { name: $("#bxName").value.trim(), member_id: $("#bxFor").value ? Number($("#bxFor").value) : null, compartments: Number($("#bxLids").value) });
      $("#bxName").value = ""; await loadBoxes(); sound("saved");
    } catch (err) { toast(err.message); }
  });
  $("#boxList").addEventListener("click", async (e) => {
    const test = e.target.closest("[data-boxtest]"), code = e.target.closest("[data-boxcode]"), del = e.target.closest("[data-boxdel]");
    try {
      if (test) {
        const [id, lid] = test.dataset.boxtest.split(":").map(Number);
        const r = await api.post("device_test", { id, lid });
        toast({ taken: "✔ " + t("outTaken"), again: "⚠ " + t("outAgain"), outside: t("outOutside"), already: t("outAlready"), nodose: t("outNodose") }[r.outcome] || r.outcome);
        await Promise.all([loadBoxes(), refreshData()]);
      } else if (code) {
        await api.post("device_code", { id: Number(code.dataset.boxcode) }); await loadBoxes();
      } else if (del) {
        if (del.dataset.confirm !== "1") { del.dataset.confirm = "1"; del.textContent = t("confirmRemove"); return; }
        await api.post("device_delete", { id: Number(del.dataset.boxdel) }); await loadBoxes();
      }
    } catch (err) { toast(err.message); }
  });

  /* ---------------- Automations panel ---------------- */
  async function loadAutomations() {
    try {
      state.auto = await api.get("automation_get");
      renderAutomations();
      const c = await api.post("calendar_link", {}); state.calUrl = c.url; renderCalendar();
    } catch (e) { /* shown elsewhere */ }
  }
  function renderAutomations() {
    const a = state.auto; if (!a) return;
    $("#auRefill").checked = a.refill; $("#auMissed").checked = a.missed; $("#auCourse").checked = a.course; $("#auCleanup").checked = a.cleanup; $("#auTiming").checked = a.timing !== false;
    $("#auRefillDays").innerHTML = [3, 5, 7, 10].map(d => '<option value="' + d + '"' + (d === a.refillDays ? " selected" : "") + ">" + esc(t("daysBefore", { d })) + "</option>").join("");
    $("#auMissedAfter").innerHTML = [30, 60, 90, 120].map(m => '<option value="' + m + '"' + (m === a.missedAfter ? " selected" : "") + ">" + esc(t("minutesAfter", { m })) + "</option>").join("");
    $("#auRefillDays").disabled = !a.refill; $("#auMissedAfter").disabled = !a.missed;
    $("#auWatch").innerHTML = [0, 12, 24, 48].map(h => '<option value="' + h + '"' + (h === (a.watchHours === undefined ? 24 : a.watchHours) ? " selected" : "") + ">" + esc(h ? t("hoursN", { h }) : t("offLbl")) + "</option>").join("");
  }
  function renderCalendar() {
    if (!state.calUrl) return;
    const webcal = state.calUrl.replace(/^https?:/, "webcal:");
    $("#calGoogle").href = "https://calendar.google.com/calendar/render?cid=" + encodeURIComponent(webcal);
    $("#calApple").href = webcal;
  }
  const saveAutomations = debounce(async () => {
    try {
      state.auto = await api.post("automation_save", { refill_on: $("#auRefill").checked, refill_days: Number($("#auRefillDays").value), missed_on: $("#auMissed").checked,
        missed_after: Number($("#auMissedAfter").value), course_on: $("#auCourse").checked, cleanup_on: $("#auCleanup").checked, timing_on: $("#auTiming").checked, watch_hours: Number($("#auWatch").value) });
      renderAutomations(); renderCabinet(); toast("✔ " + t("saved"));
    } catch (err) { toast(err.message); }
  }, 400);
  $("#autoCard").addEventListener("change", (e) => { if (e.target.matches("input, select")) { $("#auRefillDays").disabled = !$("#auRefill").checked; $("#auMissedAfter").disabled = !$("#auMissed").checked; saveAutomations(); } });
  $("#calCopy").addEventListener("click", () => {
    if (!state.calUrl) return;
    const done = () => toast("✔ " + t("calCopied"));
    if (navigator.clipboard) navigator.clipboard.writeText(state.calUrl).then(done).catch(() => fallbackCopy(state.calUrl, done)); else fallbackCopy(state.calUrl, done);
  });
  $("#calRenew").addEventListener("click", async () => {
    try { const c = await api.post("calendar_link", { renew: 1 }); state.calUrl = c.url; renderCalendar(); toast("✔ " + t("saved")); } catch (err) { toast(err.message); }
  });

  /* ---------------- Voice assistant ---------------- */
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  let rec = null, listening = false, voiceAudio = null;
  function bubble(text, who) { const b = document.createElement("div"); b.className = "bubble " + who; b.textContent = text; $("#bubbles").appendChild(b); $("#bubbles").scrollTop = 1e6; return b; }
  function browserVoice(text) {
    if (!("speechSynthesis" in window)) return false;
    const code = langInfo().speech, voices = speechSynthesis.getVoices();
    const v = voices.find(x => x.lang === code) || voices.find(x => x.lang && x.lang.toLowerCase().startsWith(state.lang));
    if (!v && voices.length && state.lang !== "en") return false;
    speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text); u.lang = code; u.rate = 0.95; if (v) u.voice = v;
    speechSynthesis.speak(u); return true;
  }
  async function speak(text) {
    try {
      // Prefer Bhashini (server) when it is configured: it speaks every Indian language on every phone.
      if (state.features.bhashini) {
        const res = await fetch("/api.php?action=tts", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF-Token": api.csrf }, body: JSON.stringify({ text, lang: state.lang }) });
        if (res.ok) { const blob = await res.blob(); if (voiceAudio) voiceAudio.pause(); voiceAudio = new Audio(URL.createObjectURL(blob)); await voiceAudio.play(); return; }
      }
      if (!browserVoice(text)) bubble("(No " + langInfo().name + " voice on this device. The recorded guide in the Guide tab speaks " + langInfo().name + ".)", "bot");
    } catch (e) { /* speech not allowed */ }
  }
  function detectIntent(q) { q = q.toLowerCase(); for (const intent of ["scan", "expiring", "doses", "help"]) if (INTENTS[intent].some(w => q.includes(w.toLowerCase()))) return intent; return null; }
  function answer(q) {
    bubble(q, "me");
    if (/^\s*(please\s+)?(add|remind me|new medicine|i take)\b/i.test(q) || (window.RxParse && window.RxParse.schedule(q).matched.length && /[a-z]{4,}/i.test(q) && !detectIntent(q))) {
      quickAddText(q).then(msg => { bubble(msg, "bot"); speak(msg); }).catch(err => { bubble(err.message, "bot"); speak(err.message); });
      return;
    }
    const intent = detectIntent(q); let reply;
    if (intent === "expiring") {
      const soon = state.items.filter(m => statusOf(m.exp) === "warn"), bad = state.items.filter(m => statusOf(m.exp) === "bad"), parts = [];
      if (bad.length) parts.push(t("vaExpired", { n: bad.length, list: bad.map(m => m.name).join(", ") }));
      if (soon.length) parts.push(t("vaExpiring", { n: soon.length, list: soon.map(m => m.name + " (" + daysText(m.exp) + ")").join(", ") }));
      reply = parts.join(" ") || t("vaNone"); if (bad.length) sound("expiry-alert");
    } else if (intent === "doses") { const left = remainingDoses(); reply = left.length ? t("vaDoses", { n: left.length, list: left.join(", ") }) : t("vaDosesDone"); }
    else if (intent === "scan") { reply = t("vaScan"); setTimeout(() => { resetForm(); go("scan"); }, 600); }
    else if (intent === "help") reply = t("vaHelp");
    else {
      // Not one of the built-in questions: ask the server (medicine information, or the AI helper when switched on).
      const wait = bubble("…", "bot");
      api.post("assistant_ask", { q }).then(r => { const a = r.answer || t("vaUnknown"); if (wait) wait.textContent = a; else bubble(a, "bot"); speak(a); })
        .catch(() => { if (wait) wait.textContent = t("vaUnknown"); speak(t("vaUnknown")); });
      return;
    }
    bubble(reply, "bot"); speak(reply);
  }
  function openAssistant(listen) { $("#assistant").hidden = false; $("#fab").setAttribute("aria-expanded", "true"); if (!$("#bubbles").children.length) bubble(t("vaHelp"), "bot"); if (listen) startListening(); }
  function startListening() {
    if (!SR) { bubble(t("voiceNoMic"), "bot"); $("#askInput").focus(); return; }
    if (listening) { try { rec.stop(); } catch (e) { /* */ } return; }
    rec = new SR(); rec.lang = langInfo().speech; rec.interimResults = false; rec.maxAlternatives = 1;
    rec.onstart = () => { listening = true; $("#orb").classList.add("listening"); $("#vaHint").textContent = t("voiceListening"); };
    rec.onend = () => { listening = false; $("#orb").classList.remove("listening"); $("#vaHint").textContent = t("voiceHint"); };
    rec.onerror = () => bubble(t("voiceNoMic"), "bot");
    rec.onresult = (ev) => { const q = ev.results[0][0].transcript; if (q) answer(q); };
    try { rec.start(); } catch (e) { bubble(t("voiceNoMic"), "bot"); }
  }
  $("#fab").addEventListener("click", () => { if ($("#assistant").hidden) openAssistant(false); else { $("#assistant").hidden = true; $("#fab").setAttribute("aria-expanded", "false"); } });
  $("#vaClose").addEventListener("click", () => { $("#assistant").hidden = true; $("#fab").setAttribute("aria-expanded", "false"); });
  $("#btnVoiceHero").addEventListener("click", () => openAssistant(true));
  $("#orb").addEventListener("click", startListening);
  $("#askForm").addEventListener("submit", (e) => { e.preventDefault(); const q = $("#askInput").value.trim(); if (q) { answer(q); $("#askInput").value = ""; } });
  try { if ("speechSynthesis" in window) speechSynthesis.getVoices(); } catch (e) { /* */ }
  $$("[data-sound]").forEach(b => b.addEventListener("click", () => sound(b.dataset.sound)));

  /* ---------------- Boot ---------------- */
  function renderAll() { renderSummary(); renderFilter(); renderCabinet(); fillMemberSelect(); renderDoses(); renderNotebook(); renderKit(); renderAccount(); renderPlan(); renderShare(); renderAdherence(); renderBoxes(); renderSafety(); renderAi(); renderFollowups(); renderProfile(); renderAppointments(); renderPharmacy(); saveEmergencySnapshot(); }
  const langOptions = LANGS.map(l => '<option value="' + l.code + '">' + l.native + (l.code === "en" ? "" : " · " + l.name) + "</option>").join("");
  $$("#langSelect, #authLang").forEach(s => { s.innerHTML = langOptions; s.addEventListener("change", (e) => setLang(e.target.value)); });
  applyI18n();
  if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js").catch(() => {});

  (async function boot() {
    if (location.hash === "#emergency") openEmergency();   // works before sign-in and without internet
    try {
      const s = await api.refresh();
      state.features = s.features || {};
      if (/reset=[a-f0-9]{64}/.test(location.hash)) { showAuth("reset"); return; }
      const jm = location.hash.match(/join=([A-Za-z0-9]{8})/);
      if (jm) { try { sessionStorage.setItem("ms-join", jm[1].toUpperCase()); } catch (e) { /* */ } history.replaceState(null, "", location.pathname); }
      if (s.user) await enterApp(); else showAuth(/signup|join/.test(location.hash) || jm ? "signup" : "login");
    } catch (e) {
      showAuth();
      showErr($("#loginErr"), e.message || "Cannot reach the server.");
    }
  })();
})();
