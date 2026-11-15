/* Network admin: approve pharmacies after checking the licence, suspend, complaints, activity, network health. */
(function () {
  "use strict";
  const $ = (s, r = document) => r.querySelector(s);
  const esc = (s) => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const rs = (n) => "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });
  let csrf = "";
  async function call(a, body) {
    const o = { credentials: "same-origin", method: body ? "POST" : "GET", headers: {} };
    if (body) { o.headers["Content-Type"] = "application/json"; o.headers["X-CSRF-Token"] = csrf; o.body = JSON.stringify(body); }
    const r = await fetch("/admin_api.php?action=" + a, o), d = await r.json().catch(() => ({}));
    if (!r.ok) { const e = new Error(d.message || "Request failed"); e.code = d.error; throw e; }
    return d;
  }
  function toast(m) { const t = $("#toast"); t.textContent = m; t.hidden = false; setTimeout(() => { t.hidden = true; }, 3000); }
  function qr(cv, text) { const q = window.qrcode(0, "M"); q.addData(text); q.make(); const n = q.getModuleCount(), s = cv.width, k = Math.floor(s / (n + 4)), off = Math.floor((s - k * n) / 2), c = cv.getContext("2d");
    c.fillStyle = "#fff"; c.fillRect(0, 0, s, s); c.fillStyle = "#000"; for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect(off + col * k, off + r * k, k, k); }

  $("#login").addEventListener("submit", async (e) => {
    e.preventDefault(); $("#err").hidden = true;
    try {
      const r = await call("login", { email: $("#em").value, password: $("#pw").value, code: $("#code").value || undefined });
      if (r.setup) { $("#setup").hidden = false; $("#sec").textContent = r.secret; qr($("#sq"), r.uri); return; }
      csrf = r.csrf; load();
    } catch (er) { $("#err").textContent = er.message; $("#err").hidden = false; }
  });
  $("#out").addEventListener("click", async () => { const r = await call("logout", {}); csrf = r.csrf; location.reload(); });

  async function load() {
    $("#login").hidden = true; $("#app").hidden = false; $("#out").hidden = false;
    const month = load.month || new Date().toISOString().slice(0, 7);
    const [ov, og, cp, au, fe] = await Promise.all([call("overview"), call("orgs"), call("complaints"), call("audit"), call("fees&period=" + month)]);
    const cnt = (k) => (ov.orgs.find(o => o.status === k) || { n: 0 }).n;
    $("#app").innerHTML = '<h1>Network</h1><div class="kpis">' +
      [[cnt("pending"), "waiting for approval", cnt("pending") ? "alert" : ""], [cnt("approved"), "approved businesses"], [ov.branches, "active shops"], [ov.links, "pharmacy links"], [ov.passedOn30, "orders passed on (30 days)"],
       [rs(ov.khataTotal), "on khata across the network"], [ov.coldAlerts, "fridges out of range", ov.coldAlerts ? "alert" : ""], [ov.complaintsOpen, "open complaints", ov.complaintsOpen ? "alert" : ""]]
        .map(([v, l, c]) => '<div class="kpi ' + (c || "") + '"><b>' + esc(v) + "</b><span>" + esc(l) + "</span></div>").join("") + "</div>" +
      '<div class="grid two"><div class="card stack"><h2>Orders by channel, 30 days</h2><ul class="list">' + ov.orders30.map(o => "<li><span>" + esc(o.source) + "</span><span>" + o.n + " · " + rs(o.v) + "</span></li>").join("") + '</ul><h3>Payments, 30 days</h3><ul class="list">' + ov.payMethods30.map(p => "<li><span>" + esc(p.method) + "</span><span>" + p.n + " · " + rs(p.v) + "</span></li>").join("") + "</ul></div>" +
      '<div class="card stack"><h2>Where</h2><p class="muted small">' + ov.byArea.map(a => esc(a.area) + " " + a.n).join(" · ") + '</p><ul class="list">' + ov.byDistrict.map(d => "<li><span>" + esc(d.district) + "</span><b>" + d.n + "</b></li>").join("") + "</ul></div></div>" +
      '<div class="card stack"><h2>Pharmacies</h2><p class="muted small">Before approving: check the retail licence number with the State Drugs Control Department (online register or a call), and that a registered pharmacist works there. Approval lets them take orders and scan family cards.</p><div class="table-scroll"><table><thead><tr><th>Business</th><th>Shops and licences</th><th>Owner</th><th>Activity</th><th>Status</th></tr></thead><tbody id="orgs">' +
      og.orgs.map(o => '<tr><td><b>' + esc(o.name) + "</b>" + (o.gstin ? '<br><small class="mono">GST ' + esc(o.gstin) + "</small>" : "") + '<br><small class="muted">since ' + esc(o.created_at.slice(0, 10)) + "</small></td><td>" + o.branches.map(b => esc(b.name) + '<br><small class="mono">' + esc(b.licence_retail) + (b.licence_whole ? " · W " + esc(b.licence_whole) : "") + '</small> <small class="muted">' + esc([b.village, b.district, b.area].filter(Boolean).join(", ")) + "</small>").join("<br>") + "</td>" +
        "<td>" + esc(o.owner ? o.owner.name : "") + '<br><small class="muted">' + esc(o.owner ? [o.owner.email, o.owner.phone].filter(Boolean).join(" · ") : "") + "</small></td><td>" + o.orders30 + " orders · " + o.complaints + ' complaints</td><td><span class="pill ' + (o.status === "approved" ? "" : o.status === "pending" ? "warn" : "bad") + '">' + esc(o.status) + "</span><br>" +
        (o.status !== "approved" ? '<label class="check small"><input type="checkbox" data-lc="' + o.id + '"> licence checked</label><button class="btn sm primary" data-org="' + o.id + '" data-st="approved">Approve</button>' : "") + (o.status !== "suspended" ? '<button class="btn sm danger" data-org="' + o.id + '" data-st="suspended">Suspend</button>' : "") + "</td></tr>").join("") + "</tbody></table></div></div>" +
      '<div class="card stack"><div class="row between"><h2>Network fees and vouchers</h2><input type="month" id="feM" value="' + esc(month) + '" aria-label="Month"></div>' +
      '<p class="muted small">Positive: the business pays the network (commission + GST). Negative: the network pays the business back for health-points vouchers. Record a payment with its UPI reference (UTR) once it shows in the bank.</p>' +
      '<p class="small">Last months: ' + (ov.fees || []).map(f => esc(f.period) + " " + rs(f.net)).join(" · ") + "</p>" +
      '<div class="table-scroll"><table><thead><tr><th>Business</th><th>Invoice</th><th>Orders</th><th>Commission</th><th>GST</th><th>Vouchers</th><th>Balance</th><th>Settle</th></tr></thead><tbody id="fees">' +
      (fe.orgs.map(o => "<tr><td><b>" + esc(o.name) + '</b></td><td class="mono small">' + esc(o.invoice) + "</td><td>" + o.totals.orders + "</td><td>" + rs(o.totals.commission) + "</td><td>" + rs(o.totals.gst) + "</td><td>" + (o.totals.voucher < 0 ? "−" + rs(-o.totals.voucher) : rs(0)) + "</td><td><b>" + (o.net > 0 ? rs(o.net) + " due" : o.net < 0 ? rs(-o.net) + " to pay out" : "settled") + "</b></td><td>" +
        (Math.abs(o.net) >= 0.01 ? '<form class="row" data-fs="' + o.id + '"><input name="utr" placeholder="UTR" maxlength="22" required style="width:9em"><input name="amt" inputmode="decimal" value="' + Math.abs(o.net) + '" style="width:6em" aria-label="Amount"><button class="btn sm primary">Record</button></form>' : "✓") + "</td></tr>").join("") || '<tr><td colspan="8" class="muted">No fees this month.</td></tr>') + "</tbody></table></div></div>" +
      '<div class="card stack"><h2>Complaints</h2><ul class="list" id="cps">' + (cp.complaints.map(c => "<li><span><b>" + esc(c.pharmacy) + "</b> · " + esc(c.topic) + ' <span class="pill ' + (c.status === "open" ? "bad" : "") + '">' + esc(c.status) + "</span><br>" + esc(c.body) + (c.reply ? '<br><small class="muted">Pharmacy replied: ' + esc(c.reply) + "</small>" : "") + "</span>" + (c.status !== "closed" ? '<button class="btn sm" data-cc="' + c.id + '">Close</button>' : "") + "</li>").join("") || '<li class="muted">None.</li>') + "</ul></div>" +
      '<div class="card stack"><h2>Activity</h2><div class="table-scroll"><table><tbody>' + au.log.slice(0, 120).map(l => "<tr><td><small>" + esc(l.created_at) + "</small></td><td>" + esc(l.actor) + "</td><td><b>" + esc(l.action) + "</b> " + esc(l.detail || "") + "</td></tr>").join("") + "</tbody></table></div></div>";
    $("#orgs").addEventListener("click", async (e) => {
      const b = e.target.closest("[data-org]"); if (!b) return;
      const lc = document.querySelector('[data-lc="' + b.dataset.org + '"]');
      if (b.dataset.st === "suspended" && b.dataset.sure !== "1") { b.dataset.sure = "1"; b.textContent = "Sure? Suspend"; return; }
      try { await call("org_status", { id: Number(b.dataset.org), status: b.dataset.st, licence_checked: lc ? lc.checked : false }); toast("✓ Updated"); load(); } catch (er) { toast(er.message); }
    });
    $("#feM").addEventListener("change", (e) => { if (/^\d{4}-\d{2}$/.test(e.target.value)) { load.month = e.target.value; load(); } });
    $("#fees").addEventListener("submit", async (e) => {
      const f = e.target.closest("[data-fs]"); if (!f) return; e.preventDefault();
      try { await call("fee_settle", { org_id: Number(f.dataset.fs), period: month, amount: Number(f.amt.value), utr: f.utr.value }); toast("✓ Recorded"); load(); } catch (er) { toast(er.message); }
    });
    $("#cps").addEventListener("click", async (e) => { const b = e.target.closest("[data-cc]"); if (b) { await call("complaint_close", { id: Number(b.dataset.cc) }); load(); } });
  }
  (async () => { const s = await call("session"); csrf = s.csrf; if (s.admin) load(); else $("#login").hidden = false; })();
})();
