/* AushadhBox Pharmacy portal (/pharmacy): scan a customer's card, see what the family allows, add the medicines you give,
   check safety first, answer refill requests and see how patients are doing. Talks to pharmacy_api.php. */
(function () {
  "use strict";
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s === null || s === undefined ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const SLOTS = [["m", "Morning"], ["a", "Afternoon"], ["e", "Evening"], ["b", "Bedtime"]];
  const slotName = (id) => (SLOTS.find(s => s[0] === id) || ["", id])[1];
  const today = () => new Date().toISOString().slice(0, 10);
  function toast(msg) { const el = $("#toast"); el.textContent = msg; el.hidden = false; clearTimeout(toast._t); toast._t = setTimeout(() => { el.hidden = true; }, 3000); }
  function showErr(el, msg) { el.textContent = msg || ""; el.hidden = !msg; }
  const fmtTime = (s) => { try { return new Date(String(s).replace(" ", "T")).toLocaleString("en-IN", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }); } catch (e) { return s; } };

  const api = {
    csrf: "",
    async call(action, body, method) {
      const opts = { method, credentials: "same-origin", headers: {} };
      if (method === "POST") { opts.headers["Content-Type"] = "application/json"; opts.headers["X-CSRF-Token"] = api.csrf; opts.body = JSON.stringify(body || {}); }
      let res;
      try { res = await fetch("/pharmacy_api.php?action=" + action, opts); } catch (e) { throw { code: "offline", message: "No internet connection. Try again." }; }
      const data = await res.json().catch(() => ({ error: "bad_response", message: "Unexpected server response." }));
      if (res.status === 419 && !body?._retried) { await api.session(); return api.call(action, Object.assign({}, body, { _retried: true }), method); }
      if (!res.ok) throw { code: data.error, message: data.message || "Something went wrong.", status: res.status };
      return data;
    },
    get(action, params) { return api.call(action + (params ? "&" + new URLSearchParams(params) : ""), null, "GET"); },
    post(action, body) { return api.call(action, body || {}, "POST"); },
    async session() { const s = await api.get("session"); api.csrf = s.csrf; return s; }
  };

  let me = null;
  function show(view) {
    ["authView", "pendingView", "dashView"].forEach(id => { $("#" + id).hidden = id !== view; });
    $("#btnOut").hidden = view === "authView";
    $("#phWho").textContent = me ? me.name + (me.city ? " · " + me.city : "") : "For chemists and pharmacies";
  }
  async function enter(p) {
    me = p;
    if (!me) { show("authView"); return; }
    if (me.status !== "approved") {
      $("#pendingMsg").textContent = me.status === "suspended" ? "This pharmacy account is suspended. Please contact support." : "We are checking your drug licence (" + me.licenseNo + "). You will get an e-mail at " + me.email + " when you can start scanning cards.";
      show("pendingView"); return;
    }
    show("dashView");
    $("#profInfo").textContent = me.name + " · Licence " + me.licenseNo + " · " + me.phone + " · " + me.email;
    $("#profAddr").value = me.address || ""; $("#profCity").value = me.city || "";
    loadStats(); loadRefills(); loadFollowups();
  }

  /* ---------- Sign in / register ---------- */
  function authTab(up) { $("#tabIn").setAttribute("aria-pressed", String(!up)); $("#tabUp").setAttribute("aria-pressed", String(up)); $("#inForm").hidden = up; $("#upForm").hidden = !up; showErr($("#authErr"), ""); }
  $("#tabIn").addEventListener("click", () => authTab(false));
  $("#tabUp").addEventListener("click", () => authTab(true));
  $("#inForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#authErr"), "");
    try { const r = await api.post("login", { email: $("#inEmail").value.trim(), password: $("#inPass").value }); $("#inPass").value = ""; await api.session(); enter(r.pharmacy); }
    catch (err) { showErr($("#authErr"), err.message); }
  });
  $("#upForm").addEventListener("submit", async (e) => {
    e.preventDefault(); showErr($("#authErr"), "");
    try {
      const r = await api.post("signup", { name: $("#upName").value, license_no: $("#upLic").value, city: $("#upCity").value, phone: $("#upPhone").value, address: $("#upAddr").value,
        email: $("#upEmail").value.trim(), password: $("#upPass").value, agree: $("#upAgree").checked });
      $("#upPass").value = ""; await api.session(); enter(r.pharmacy);
    } catch (err) { showErr($("#authErr"), err.message); }
  });
  $("#btnOut").addEventListener("click", async () => { try { const r = await api.post("logout"); api.csrf = r.csrf || api.csrf; } catch (e) { /* signed out anyway */ } stopCam(); closePatient(); enter(null); });
  $("#btnRecheck").addEventListener("click", async () => { const s = await api.session(); enter(s.pharmacy); });

  /* ---------- Dashboard numbers ---------- */
  async function loadStats() {
    try {
      const s = await api.get("stats");
      $("#stats").innerHTML = [["dispensed30", "medicines added in 30 days"], ["patients", "families linked"], ["openRefills", "refill requests waiting"], ["needsCall", "patients to call"]]
        .map(([k, label]) => '<div class="ph-stat' + ((k === "needsCall" || k === "openRefills") && s[k] ? " alert" : "") + '"><b>' + esc(s[k]) + "</b><small>" + esc(label) + "</small></div>").join("");
    } catch (e) { /* shown elsewhere */ }
  }

  /* ---------- Scan the card ---------- */
  let stream = null, camTimer = null;
  const detector = (() => { try { return "BarcodeDetector" in window ? new window.BarcodeDetector({ formats: ["qr_code"] }) : null; } catch (e) { return null; } })();
  async function startCam() {
    showErr($("#scanErr"), "");
    try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false }); }
    catch (e) { showErr($("#scanErr"), "The camera could not start. Allow camera access, or type the code."); return; }
    const v = $("#camVideo"); v.srcObject = stream; await v.play().catch(() => {});
    $("#camBox").hidden = false; $("#btnCam").hidden = true; $("#btnCamStop").hidden = false;
    const cv = $("#camCanvas"), ctx = cv.getContext("2d", { willReadFrequently: true });
    camTimer = setInterval(async () => {
      if (!v.videoWidth) return;
      let text = "";
      if (detector) { try { const c = await detector.detect(v); if (c.length) text = c[0].rawValue; } catch (e) { /* fall back to jsQR */ } }
      if (!text && window.jsQR) {
        const k = Math.min(1, 640 / v.videoWidth); cv.width = Math.round(v.videoWidth * k); cv.height = Math.round(v.videoHeight * k);
        ctx.drawImage(v, 0, 0, cv.width, cv.height);
        const r = window.jsQR(ctx.getImageData(0, 0, cv.width, cv.height).data, cv.width, cv.height, { inversionAttempts: "dontInvert" });
        if (r) text = r.data;
      }
      if (text && /AUSHADHBOX:|^[A-Z0-9]{4}-?[A-Z0-9]{4}-?[A-Z0-9]{4}$/i.test(text.trim())) { stopCam(); openPatient(text.trim()); }
    }, 300);
  }
  function stopCam() {
    clearInterval(camTimer); camTimer = null;
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    $("#camBox").hidden = true; $("#btnCam").hidden = false; $("#btnCamStop").hidden = true;
  }
  $("#btnCam").addEventListener("click", startCam);
  $("#btnCamStop").addEventListener("click", stopCam);
  $("#codeForm").addEventListener("submit", (e) => { e.preventDefault(); const c = $("#codeIn").value.trim(); if (c) openPatient(c); });

  /* ---------- The patient ---------- */
  let patient = null, rows = [];
  async function openPatient(code) {
    showErr($("#scanErr"), "");
    try { patient = await api.post("patient", { code }); }
    catch (err) { showErr($("#scanErr"), err.message); return; }
    $("#codeIn").value = "";
    $("#patName").textContent = patient.name; $("#patCode").textContent = patient.code;
    $("#patConsent").innerHTML = '<span class="pill ' + (patient.canAdd ? "" : "warn") + '">' + (patient.canAdd ? "✓ You may add medicines" : "Adding medicines is off") + "</span>" +
      '<span class="pill ' + (patient.canView ? "" : "warn") + '">' + (patient.canView ? "✓ You may see their medicines" : "Medicine list is private") + "</span>";
    const v = patient.canView;
    $("#patView").innerHTML = v ? '<div class="ph-pat-grid">' +
      "<div>" + (patient.allergies ? '<p class="ph-allergy">⚠ Allergies: ' + esc(patient.allergies) + "</p>" : '<p class="muted">No allergies recorded.</p>') +
        (patient.conditions ? "<p><b>Conditions:</b> " + esc(patient.conditions) + "</p>" : "") + (patient.bloodGroup ? "<p><b>Blood group:</b> " + esc(patient.bloodGroup) + "</p>" : "") + "</div>" +
      "<div><h3>Current medicines (" + patient.medicines.length + ")</h3><ul class=\"ap-list\">" + (patient.medicines.length ? patient.medicines.map(m => "<li><span><b>" + esc(m.name) + "</b>" + (m.ingredient ? ' <small class="muted">' + esc(m.ingredient) + "</small>" : "") +
        (m.fromYou ? ' <span class="pill ex">from you</span>' : "") + '</span><small class="muted">' + esc(m.times.map(slotName).join(", ") || "as needed") + "</small></li>").join("") : '<li class="muted">None</li>') + "</ul></div>" +
      (patient.followups && patient.followups.length ? "<div><h3>Recent check-ins</h3><ul class=\"ap-list\">" + patient.followups.map(f => "<li><span>" + esc(f.name) + ": <b>" + esc(ANSWERS[f.answer] || f.answer) + "</b>" + (f.note ? " — " + esc(f.note) : "") + '</span><small class="muted">' + esc(f.d) + "</small></li>").join("") + "</ul></div>" : "") +
      "</div>" : '<p class="muted">The family keeps their medicine list private. The safety check still runs against everything they take; names of their other medicines stay hidden.</p>';
    $("#patCard").hidden = false; $("#addCard").hidden = false;
    rows = rows.length ? rows : [newRow()]; renderRows(); $("#alertBox").hidden = true;
    $("#btnSend").disabled = !patient.canAdd;
    $("#sendNote").textContent = patient.canAdd ? "The family gets a notification with your pharmacy's name. Reminders and refill alerts start by themselves." : "The family allows viewing only. You can still run the safety check and give advice.";
    $("#patCard").scrollIntoView({ behavior: "smooth", block: "start" });
    loadStats();
  }
  const ANSWERS = { better: "feeling better", same: "no change", worse: "feeling worse", side: "side effects" };
  function closePatient() { patient = null; rows = []; $("#patCard").hidden = true; $("#addCard").hidden = true; showErr($("#addErr"), ""); }
  $("#btnPatClose").addEventListener("click", closePatient);

  /* ---------- Medicines being given ---------- */
  function newRow(x) { return Object.assign({ name: "", catalog_id: null, times: [], days: "", qty: "", exp: "", batch: "", notes: "" }, x || {}); }
  function renderRows() {
    $("#rows").innerHTML = rows.map((r, i) => '<div class="ph-row" data-i="' + i + '">' +
      '<div class="ph-row-top"><input type="text" data-f="name" list="catList" placeholder="Medicine name and strength" aria-label="Medicine" value="' + esc(r.name) + '"><button type="button" class="link" data-del aria-label="Remove">✕</button></div>' +
      '<div class="ph-row-meta"><span class="slot-chips" style="width:auto">' + SLOTS.map(([id, label]) => '<button type="button" data-slot="' + id + '" aria-pressed="' + r.times.includes(id) + '">' + label + "</button>").join("") + "</span>" +
      '<label>Days <input type="number" min="1" max="365" data-f="days" value="' + esc(r.days) + '"></label>' +
      '<label>Qty <input type="number" min="0" max="9999" data-f="qty" value="' + esc(r.qty) + '"></label>' +
      '<label>Expiry <input type="month" data-f="exp" value="' + esc(r.exp) + '"></label>' +
      '<label>Batch <input type="text" maxlength="40" data-f="batch" value="' + esc(r.batch) + '"></label>' +
      '<label>Note <input type="text" class="wide" maxlength="200" data-f="notes" placeholder="after food" value="' + esc(r.notes) + '"></label></div></div>').join("");
  }
  let catResults = [], catTimer = null;
  $("#rows").addEventListener("input", (e) => {
    const row = e.target.closest("[data-i]"); if (!row) return;
    const r = rows[Number(row.dataset.i)], f = e.target.dataset.f; if (!f) return;
    r[f] = e.target.value;
    if (f === "name") {
      r.catalog_id = null;
      const hit = catResults.find(c => c.name === r.name);
      if (hit) {   // picked from the official list: fill the usual schedule if the pharmacist has not chosen one
        r.catalog_id = hit.id;
        if (!r.times.length && hit.usualTimes.length) { r.times = hit.usualTimes.slice(); if (!r.days && hit.courseDays) r.days = hit.courseDays; renderRows(); const el = $('[data-i="' + row.dataset.i + '"] input[data-f="name"]'); if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }
        return;
      }
      clearTimeout(catTimer);
      const q = r.name.trim();
      catTimer = setTimeout(async () => {
        if (q.length < 2) return;
        try { catResults = (await api.get("catalog", { q })).results; $("#catList").innerHTML = catResults.map(c => '<option value="' + esc(c.name) + '">' + esc(c.unit || "") + "</option>").join(""); } catch (err) { /* typing still works */ }
      }, 250);
    }
  });
  $("#rows").addEventListener("click", (e) => {
    const row = e.target.closest("[data-i]"); if (!row) return; const i = Number(row.dataset.i);
    if (e.target.closest("[data-del]")) { rows.splice(i, 1); if (!rows.length) rows.push(newRow()); renderRows(); return; }
    const b = e.target.closest("[data-slot]"); if (!b) return;
    const r = rows[i], s = b.dataset.slot;
    r.times = r.times.includes(s) ? r.times.filter(x => x !== s) : r.times.concat(s).sort((x, y) => "maeb".indexOf(x) - "maeb".indexOf(y));
    b.setAttribute("aria-pressed", String(r.times.includes(s)));
  });
  $("#btnRow").addEventListener("click", () => { rows.push(newRow()); renderRows(); const all = document.querySelectorAll('#rows input[data-f="name"]'); all[all.length - 1].focus(); });
  $("#btnPaste").addEventListener("click", () => {
    const text = $("#pasteText").value.trim(); if (!text || !window.RxParse) return;
    const doc = window.RxParse.parse(text, today());
    if (!doc.items.length) { toast("No medicines found in that text."); return; }
    rows = rows.filter(r => r.name.trim()).concat(doc.items.map(i => newRow({ name: i.name, times: (i.times || []).slice(), days: i.days || "", qty: i.stock || "", exp: i.exp || "", batch: i.batch || "", notes: i.notes || "" })));
    $("#pasteText").value = ""; renderRows(); toast(doc.items.length + " medicines read. Check the times before sending.");
  });
  function payload() { return rows.filter(r => r.name.trim().length >= 2).map(r => ({ name: r.name.trim(), catalog_id: r.catalog_id, times: r.times, days: Number(r.days) || 0, qty: r.qty === "" ? null : Number(r.qty), exp: r.exp, batch: r.batch, notes: r.notes })); }

  /* Same wording as the family app's safety check */
  const PAIR = { bleed: "{a} with {b} raises the risk of bleeding.", kidney: "{a} with {b} can weaken blood-pressure control and strain the kidneys.", clopi: "Omeprazole can make clopidogrel work less well ({a}, {b}).",
    breathing: "{a} with {b} can cause heavy sleepiness and slow breathing.", serotonin: "{a} with {b} can cause a serious reaction (serotonin syndrome).", drowsy: "{a} with {b} can make you very drowsy." };
  const fill = (s, p) => s.replace(/\{(\w+)\}/g, (_, k) => p[k] === undefined || p[k] === null ? "" : p[k]);
  function alertText(a) { const x = alertRaw(a); return x.charAt(0).toUpperCase() + x.slice(1); }
  function alertRaw(a) {
    const p = Object.assign({}, a.p, { who: "The patient", slot: a.p.slot ? slotName(a.p.slot).toLowerCase() : "" });
    if (a.code === "same") return fill("{a} and {b} are the same medicine ({ing}). Taking both means a double dose.", p);
    if (a.code === "kind") return fill("{a} and {b} are the same kind of medicine. Check that both are meant.", p);
    if (a.code === "gap") return fill("Take {a} and {b} at least {h} hours apart. Both are set for the {slot}.", p);
    if (a.code === "allergy") return fill("{who} is allergic to {allergy}, and {a} contains {ing}.", p);
    return fill(PAIR[p.why] || "{a} and {b}: check this combination.", p);
  }
  async function precheck() {
    const items = payload(); if (!items.length) { showErr($("#addErr"), "Add at least one medicine."); return null; }
    showErr($("#addErr"), "");
    const r = await api.post("precheck", { code: patient.code, items });
    $("#alertBox").innerHTML = r.alerts.length ? r.alerts.map(a => '<p class="' + esc(a.level) + '">' + (a.level === "high" ? "⚠ " : "") + esc(alertText(a)) + "</p>").join("") : '<p class="clear">✓ No duplicates, risky pairs or allergies found.</p>';
    $("#alertBox").hidden = false;
    return r.alerts;
  }
  $("#btnCheck").addEventListener("click", async () => { if (!patient) return; try { await precheck(); } catch (err) { showErr($("#addErr"), err.message); } });
  $("#btnSend").addEventListener("click", async () => {
    if (!patient || !patient.canAdd) return;
    const btn = $("#btnSend");
    try {
      if (btn.dataset.confirm !== "1") {   // always show the safety result first; a high alert needs a second tap
        const alerts = await precheck(); if (alerts === null) return;
        if (alerts.some(a => a.level === "high")) { btn.dataset.confirm = "1"; btn.textContent = "Send anyway (I have checked)"; return; }
      }
      btn.disabled = true;
      const r = await api.post("dispense", { code: patient.code, items: payload() });
      toast("✓ Sent: " + r.added + " medicine" + (r.added > 1 ? "s" : "") + " added to " + patient.name + "'s app.");
      closePatient(); loadStats();
    } catch (err) { showErr($("#addErr"), err.message); }
    btn.disabled = false; btn.dataset.confirm = ""; btn.textContent = "Send to the family's app";
  });

  /* ---------- Refill requests ---------- */
  const RF = { sent: ["New", "warn"], ready: ["Ready", ""], collected: ["Collected", "ex"], cancelled: ["Could not prepare", "bad"] };
  async function loadRefills() {
    try {
      const list = (await api.get("refills")).refills;
      $("#refillList").innerHTML = list.length ? list.map(r => "<li><span><b>" + esc(r.for) + "</b> · " + esc(r.items.map(i => i.name + (i.qty ? " ×" + i.qty : "")).join(", ")) +
        (r.note ? '<br><small class="muted">' + esc(r.note) + "</small>" : "") + '<br><small class="muted">' + esc(fmtTime(r.at)) + (r.phone ? ' · <a href="tel:+91' + esc(r.phone) + '">Call</a>' : "") + "</small></span>" +
        '<span class="acts"><span class="pill ' + RF[r.status][1] + '">' + RF[r.status][0] + "</span>" +
        (r.status === "sent" ? '<button class="btn btn-primary btn-sm" type="button" data-rf="' + r.id + '" data-st="ready">Ready</button><button class="link" type="button" data-rf="' + r.id + '" data-st="cancelled">Cannot</button>' : "") +
        (r.status === "ready" ? '<button class="btn btn-ghost btn-sm" type="button" data-rf="' + r.id + '" data-st="collected">Collected</button>' : "") + "</span></li>").join("")
        : '<li class="muted">No requests yet.</li>';
    } catch (e) { /* not approved or offline */ }
  }
  $("#refillList").addEventListener("click", async (e) => {
    const b = e.target.closest("[data-rf]"); if (!b) return;
    try { await api.post("refill_status", { id: Number(b.dataset.rf), status: b.dataset.st }); toast(b.dataset.st === "ready" ? "✓ The family has been told it is ready." : "✓ Updated"); loadRefills(); loadStats(); }
    catch (err) { toast(err.message); }
  });

  /* ---------- Follow-ups ---------- */
  async function loadFollowups() {
    try {
      const list = (await api.get("followups")).followups;
      $("#fuList").innerHTML = list.length ? list.map(f => "<li><span><b>" + esc(f.medicine) + "</b> · " + esc(f.for) + ' <small class="muted mono">' + esc(f.code || "") + "</small>" +
        (f.note ? '<br><small>' + esc(f.note) + "</small>" : "") + '</span><span class="acts"><span class="pill ' + (f.answer === "side" || f.answer === "worse" ? "bad" : f.answer === "same" ? "warn" : "") + '">' + esc(ANSWERS[f.answer] || f.answer) + "</span>" +
        '<small class="muted">day ' + f.day + "</small></span></li>").join("") : '<li class="muted">No check-in answers yet.</li>';
    } catch (e) { /* not approved */ }
  }

  $("#profForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { const r = await api.post("profile_save", { address: $("#profAddr").value, city: $("#profCity").value }); me = r.pharmacy; show("dashView"); toast("✓ Saved"); } catch (err) { toast(err.message); }
  });
  // New refill requests show up without reloading.
  setInterval(() => { if (me && me.status === "approved" && document.visibilityState === "visible") { loadRefills(); loadStats(); } }, 60000);

  (async function boot() {
    try { const s = await api.session(); enter(s.pharmacy); } catch (e) { show("authView"); toast(e.message || "Could not connect."); }
  })();
})();
