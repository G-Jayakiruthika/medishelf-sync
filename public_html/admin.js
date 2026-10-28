/* Admin dashboard: business numbers, website analytics, payments, messages and medicine-list import. Access is checked on the server. */
(async function () {
  "use strict";
  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const num = (n) => Number(n || 0).toLocaleString("en-IN");
  const rupees = (n) => "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
  let csrf = "";
  async function call(action, body) {
    const opts = { credentials: "same-origin", method: body ? "POST" : "GET", headers: {} };
    if (body) { opts.headers["Content-Type"] = "application/json"; opts.headers["X-CSRF-Token"] = csrf; opts.body = JSON.stringify(body); }
    const res = await fetch("/api.php?action=" + action, opts);
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || "Request failed");
    return data;
  }
  function showError(m) { $("#err").textContent = m; $("#err").hidden = false; }
  const kpis = (el, list) => { $(el).innerHTML = list.map(([k, v, cls]) => '<div class="kpi ' + (cls || "") + '"><b>' + esc(v) + "</b><span>" + esc(k) + "</span></div>").join(""); };
  function days14(rows) {
    const map = {}; rows.forEach(r => { map[r.d] = Number(r.n); });
    const out = []; const d = new Date(); d.setDate(d.getDate() - 13);
    for (let i = 0; i < 14; i++) { const k = d.toISOString().slice(0, 10); out.push([k, map[k] || 0]); d.setDate(d.getDate() + 1); }
    return out;
  }
  function bars(el, lbl, rows) {
    const data = days14(rows), max = Math.max(1, ...data.map(x => x[1]));
    $(el).innerHTML = data.map(([d, n]) => '<div style="height:' + Math.round(100 * n / max) + '%" data-tip="' + esc(d.slice(5) + ": " + n) + '"></div>').join("");
    $(lbl).innerHTML = "<span>" + esc(data[0][0].slice(5)) + "</span><span>today</span>";
  }
  function rank(el, rows, key, empty) {
    const max = Math.max(1, ...rows.map(r => Number(r.n)));
    $(el).innerHTML = rows.length ? rows.map(r => '<li style="--w:' + Math.round(100 * r.n / max) + '%"><span>' + esc(r[key]) + "</span><b>" + num(r.n) + "</b></li>").join("") : '<li class="muted">' + esc(empty || "No data yet.") + "</li>";
  }

  async function load() {
    const s = await call("admin_stats");
    const conv = s.users ? Math.round(100 * s.premium_users / s.users) : 0;
    kpis("#kpiBiz", [["Revenue, 30 days", rupees(s.revenue_30d), "money"], ["Revenue, all time", rupees(s.revenue_total), "money"], ["Premium families", num(s.premium_users)],
      ["Conversion to Premium", conv + "%"], ["Total users", num(s.users)], ["New this week", num(s.new_users_7d)], ["Medicines tracked", num(s.items)]]);
    const c = s.consent || {}, acc = Number(c.accepted || 0), rej = Number(c.rejected || 0);
    kpis("#kpiWeb", [["Visitors", num(s.visitors_30d)], ["Page views", num(s.pageviews_30d)], ["Cookie accept rate", (acc + rej ? Math.round(100 * acc / (acc + rej)) : 0) + "%"], ["Open messages", num(s.messages_open)]]);
    bars("#chartSignups", "#lblSignups", s.signups_14d);
    bars("#chartVisits", "#lblVisits", s.visits_14d);
    rank("#topPages", s.top_pages, "path");
    rank("#topRefs", s.top_referrers, "src");
    rank("#ctas", s.cta_clicks_30d, "event", "No button clicks yet.");
    rank("#devices", s.devices, "device");
    $("#payments").innerHTML = s.payments_recent.length ? s.payments_recent.map(p => "<tr><td>" + esc(p.created_at.slice(0, 16)) + "</td><td>" + esc(p.name || "(deleted)") + "</td><td>" + esc(p.period) + "</td><td>" + rupees(p.amount) + "</td><td>" + esc(p.status) + (String(p.order_id).startsWith("demo_") ? " (test)" : "") + '</td><td class="mono">' + esc(p.order_id) + "</td></tr>").join("") : '<tr><td colspan="6" class="muted">No payments yet.</td></tr>';
    $("#sources").innerHTML = s.catalog.length ? s.catalog.map(r => "<tr><td>" + esc(r.source) + "</td><td>" + num(r.n) + "</td></tr>").join("") : '<tr><td colspan="2" class="muted">No medicine list imported yet.</td></tr>';
    const f = s.features;
    $("#features").innerHTML = [["E-mail (SMTP): password reset, receipts, weekly e-mails", f.mail], ["Phone push reminders (VAPID)", f.push], ["Bhashini voices", f.bhashini], ["Payments: " + f.payments, f.payments === "live"]]
      .map(([k, on]) => "<li>" + (on ? "✅ " : "⚪ ") + esc(k) + (on ? "" : " — not live") + "</li>").join("");
    loadMessages();
  }
  let mailOn = false;
  async function loadMessages() {
    const r = await call("admin_messages"); mailOn = r.mail;
    const open = r.messages.filter(m => !Number(m.handled)).length, late = r.messages.filter(m => m.overdue).length;
    $("#msgCount").textContent = open + " open" + (late ? " · " + late + " overdue" : "");
    $("#messages").innerHTML = r.messages.length ? r.messages.map(m => {
      const done = Number(m.handled);
      return '<div class="msg' + (done ? " done" : "") + '" data-id="' + m.id + '"><div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><b class="mono">' + esc(m.ticket) + "</b>" +
        (m.category ? '<span class="chip ' + esc(m.category) + '">' + esc(m.category) + "</span>" : "") + (m.priority ? '<span class="chip ' + esc(m.priority) + '">' + esc(m.priority) + " priority</span>" : "") +
        (m.sentiment && m.sentiment !== "neutral" ? '<span class="chip ' + esc(m.sentiment) + '">' + esc(m.sentiment) + "</span>" : "") + (m.overdue ? '<span class="chip overdue">overdue</span>' : "") +
        '<span class="muted" style="font-size:.85rem">' + esc(m.created_at.slice(0, 16)) + (m.due_at && !done ? " · reply by " + esc(m.due_at.slice(0, 16)) : "") + "</span></div>" +
        "<div><b>" + esc(m.name) + "</b> · " + esc(m.email) + " · " + esc(m.topic) + '</div><div style="white-space:pre-wrap">' + esc(m.message) + "</div>" +
        (done ? (m.reply ? '<details><summary class="muted">Reply sent ' + esc((m.replied_at || "").slice(0, 16)) + '</summary><div style="white-space:pre-wrap;font-size:.9rem">' + esc(m.reply) + "</div></details>" : "")
          : '<label class="muted" style="font-size:.85rem" for="rp' + m.id + '">Suggested reply (edit before sending)</label><textarea id="rp' + m.id + '">' + esc(m.suggested || "") + "</textarea>" +
            '<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" data-reply="' + m.id + '">' + (mailOn ? "Send reply" : "Reply by e-mail app") + '</button><button class="btn btn-ghost btn-sm" data-done="' + m.id + '">Mark as handled</button><button class="btn btn-ghost btn-sm" data-retriage="' + m.id + '">Sort again</button></div>') + "</div>";
    }).join("") : '<p class="muted">No messages yet.</p>';
  }
  $("#messages").addEventListener("click", async (e) => {
    const done = e.target.closest("[data-done]"), rep = e.target.closest("[data-reply]"), rt = e.target.closest("[data-retriage]");
    try {
      if (done) { await call("admin_message_done", { id: Number(done.dataset.done) }); loadMessages(); }
      if (rt) { await call("admin_message_retriage", { id: Number(rt.dataset.retriage) }); loadMessages(); }
      if (rep) { const id = Number(rep.dataset.reply); const r = await call("admin_message_reply", { id, reply: $("#rp" + id).value }); if (r.mailto) location.href = r.mailto; loadMessages(); }
    } catch (err) { showError(err.message); }
  });

  /* ---------- Autopilot ---------- */
  const SEG = { likely_upgrade: "Likely to upgrade", at_risk: "Premium at risk", not_activated: "Not started", dormant: "Gone quiet", champions: "Champions" };
  let ap = null, seg = "likely_upgrade";
  const SWITCH = { digest_on: "Daily digest at 9 am (e-mail and push to admins)", nudges_on: "Helpful reminders to families (first medicine, expiring medicines, near the limit)", ack_on: "Automatic ticket number and acknowledgement e-mail for support messages", autoheal_on: "Fix safe problems by itself every hour", ai_on: "Use AI for drafts, answers and summaries (when an API key is set)" };
  async function loadAutopilot() {
    ap = await call("admin_autopilot");
    $("#insights").innerHTML = ap.insights.length ? ap.insights.map(i => '<div class="insight ' + esc(i.severity) + '"><header><b>' + esc(i.title) + '</b><button class="link" data-dismiss="' + i.id + '" aria-label="Dismiss">✕</button></header>' + (i.body ? "<span>" + esc(i.body) + "</span>" : "") + "<small>" + esc(i.kind + " · " + i.day) + "</small></div>").join("")
      : '<p class="muted">Nothing unusual. The autopilot compares every number with its usual range each morning.</p>';
    $("#lastRun").textContent = ap.lastRun ? "Last full run: " + ap.lastRun : "The autopilot runs with the cron job.";
    $("#health").innerHTML = ap.health.map(h => '<li class="' + esc(h.status) + '"><span class="dot" aria-hidden="true"></span><span><b>' + esc(h.label) + "</b> — " + esc(h.status === "ok" ? "OK" : h.status === "warn" ? "check" : "problem") + "<small>" + esc(h.detail) + "</small></span></li>").join("");
    renderSeg();
    $("#switches").innerHTML = Object.keys(SWITCH).map(k => '<li><label class="switch"><input type="checkbox" data-sw="' + k + '"' + (ap.settings[k] ? " checked" : "") + (k === "ai_on" && !ap.ai.configured ? " disabled" : "") + '><span class="track" aria-hidden="true"></span><span>' + esc(SWITCH[k]) + "</span></label></li>").join("");
    const a = ap.ai;
    $("#aiUsage").textContent = a.configured ? "AI: " + a.provider + " · " + a.model + " · " + a.callsThisMonth + " of " + a.limit + " calls this month" : "AI is off (no API key). Everything still works with rules and templates; add ai_provider and ai_api_key in core/config.php for smarter drafts and answers.";
    $("#apLog").innerHTML = ap.log.length ? ap.log.map(l => "<li><time>" + esc(l.created_at.slice(5, 16)) + "</time><span>" + esc(l.title) + "</span></li>").join("") : '<li class="muted">Nothing yet.</li>';
    $("#audit").innerHTML = ap.audit.length ? ap.audit.map(l => "<li><time>" + esc(l.created_at.slice(5, 16)) + "</time><span>" + esc((l.name || "?") + ": " + l.action + (l.detail ? " — " + l.detail : "")) + "</span></li>").join("") : '<li class="muted">Nothing yet.</li>';
  }
  function renderSeg() {
    const g = ap.growth;
    $("#segTabs").innerHTML = Object.keys(SEG).map(k => '<button type="button" data-seg="' + k + '" aria-pressed="' + (k === seg) + '">' + esc(SEG[k]) + " · " + g.counts[k] + "</button>").join("");
    const rows = g.segments[seg] || [];
    $("#segRows").innerHTML = rows.length ? rows.map(r => "<tr><td>" + esc(r.name) + '<br><span class="muted" style="font-size:.8rem">' + esc(r.email) + "</span></td><td>" + esc(r.why || "") + "</td><td>" + r.score + "</td></tr>").join("") : '<tr><td colspan="3" class="muted">Nobody here right now.</td></tr>';
  }
  $("#segTabs").addEventListener("click", (e) => { const b = e.target.closest("[data-seg]"); if (b) { seg = b.dataset.seg; renderSeg(); } });
  $("#insights").addEventListener("click", async (e) => { const b = e.target.closest("[data-dismiss]"); if (!b) return; await call("admin_insight_dismiss", { id: Number(b.dataset.dismiss) }); b.closest(".insight").remove(); });
  $("#switches").addEventListener("change", async (e) => { const k = e.target.dataset.sw; if (!k) return; try { await call("admin_settings", { [k]: e.target.checked }); } catch (err) { showError(err.message); e.target.checked = !e.target.checked; } });
  $("#btnRun").addEventListener("click", async (e) => {
    e.target.disabled = true; e.target.textContent = "Running…";
    try { const r = await call("admin_run", {}); e.target.textContent = r.fixed.length + " fixes · " + r.anomalies + " unusual · " + r.tips + " tips"; await loadAutopilot(); }
    catch (err) { showError(err.message); e.target.textContent = "Run now"; }
    setTimeout(() => { e.target.disabled = false; e.target.textContent = "Run now"; }, 4000);
  });
  $("#btnDigest").addEventListener("click", async () => { const d = await call("admin_digest"); $("#digest").textContent = d.text; $("#digest").hidden = false; });
  $("#btnIndexNow").addEventListener("click", async (e) => { try { const r = await call("admin_indexnow", {}); e.target.textContent = r.submitted !== undefined ? "Sent " + r.submitted + " pages" : r.skipped; } catch (err) { showError(err.message); } });

  const QUESTIONS = ["revenue this week", "sign-ups last 30 days", "where do visitors come from", "doses taken this week", "conversion rate", "top pages", "failed payments this month"];
  $("#askChips").innerHTML = QUESTIONS.map(q => '<button type="button" data-q="' + esc(q) + '">' + esc(q) + "</button>").join("");
  $("#askChips").addEventListener("click", (e) => { const b = e.target.closest("[data-q]"); if (b) { $("#askInput").value = b.dataset.q; $("#askForm").requestSubmit(); } });
  $("#askForm").addEventListener("submit", async (e) => {
    e.preventDefault(); const q = $("#askInput").value.trim(); if (!q) return;
    const box = $("#askAnswer"); box.hidden = false; box.innerHTML = '<span class="muted" style="color:#fff">Thinking…</span>';
    try {
      const r = await call("admin_ask", { q });
      let html = "<div>" + esc(r.answer) + (r.ai ? ' <span class="tag-ai">AI</span>' : "") + "</div>";
      if (r.chart && r.chart.points.length > 1) {
        const pts = r.chart.points.slice(-31), max = Math.max(1, ...pts.map(p => p.n));
        html += '<div class="bars" style="height:90px">' + pts.map(p => '<div style="height:' + Math.round(100 * p.n / max) + '%" data-tip="' + esc(p.d.slice(5) + ": " + p.n) + '"></div>').join("") + '</div><div class="bar-labels"><span>' + esc(pts[0].d.slice(5)) + "</span><span>" + esc(pts[pts.length - 1].d.slice(5)) + "</span></div>";
      }
      if (r.table && r.table.length) html += '<ul class="rank">' + r.table.map(t => '<li style="--w:0"><span>' + esc(t.label) + "</span><b>" + num(t.n) + "</b></li>").join("") + "</ul>";
      box.innerHTML = html;
    } catch (err) { box.textContent = err.message; }
  });

  try {
    const session = await call("session");
    csrf = session.csrf;
    if (!session.user) { location.href = "/app#signin"; return; }
    if (!session.user.admin) { showError("This page is only for administrators."); return; }
    await Promise.all([load(), loadAutopilot().catch(e => showError(e.message))]);
  } catch (e) { showError(e.message); }

  $("#importForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const file = $("#file").files[0]; if (!file) return;
    if (file.size > 5 * 1024 * 1024) { $("#importMsg").textContent = "File is larger than 5 MB. Split it into parts."; return; }
    $("#importMsg").textContent = "Importing…";
    try {
      const r = await call("admin_import", { source: $("#source").value, content: await file.text() });
      $("#importMsg").textContent = "Imported " + r.imported + " medicines" + (r.skipped ? ", skipped " + r.skipped : "") + ".";
      load();
    } catch (err) { $("#importMsg").textContent = err.message; }
  });
})();
