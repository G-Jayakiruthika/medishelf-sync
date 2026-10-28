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
  async function loadMessages() {
    const r = await call("admin_messages");
    const open = r.messages.filter(m => !Number(m.handled)).length;
    $("#msgCount").textContent = open + " open";
    $("#messages").innerHTML = r.messages.length ? r.messages.map(m => '<div class="msg' + (Number(m.handled) ? " done" : "") + '"><div><b>' + esc(m.name) + '</b> · <a href="mailto:' + esc(m.email) + '?subject=' + encodeURIComponent("Re: your message to MediShelf-Sync") + '">' + esc(m.email) + "</a> · " + esc(m.topic) + ' · <span class="muted">' + esc(m.created_at) + '</span></div><div style="white-space:pre-wrap">' + esc(m.message) + "</div>" + (Number(m.handled) ? "" : '<div><button class="btn btn-ghost btn-sm" data-done="' + m.id + '">Mark as handled</button></div>') + "</div>").join("") : '<p class="muted">No messages yet.</p>';
  }
  $("#messages").addEventListener("click", async (e) => { const b = e.target.closest("[data-done]"); if (!b) return; await call("admin_message_done", { id: Number(b.dataset.done) }); loadMessages(); });

  try {
    const session = await call("session");
    csrf = session.csrf;
    if (!session.user) { location.href = "/app#signin"; return; }
    if (!session.user.admin) { showError("This page is only for administrators."); return; }
    await load();
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
