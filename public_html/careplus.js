/* AushadhBox: Care+ screen (repeat prescriptions, medicine review, food and drink, mother and child, health points,
   phones without internet) and the small additions around the app: one-time pharmacy code, village and data settings,
   door/motion sensors. Talks to api.php (careplus and related actions). */
(function () {
  "use strict";
  const A = window.AB; if (!A) return;
  const $ = (s, r = document) => r.querySelector(s);
  const { api, t, esc, toast } = A;
  let data = null;
  const fmt = (d) => { try { return new Date(d + "T00:00:00").toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" }); } catch (e) { return d; } };
  const name = (id) => A.memberName(id) || "";
  const people = (sel, filter) => { $(sel).innerHTML = A.state.members.filter(filter || (() => true)).map(m => '<option value="' + m.id + '">' + esc(m.name) + "</option>").join(""); };

  async function load() {
    try { data = await api.get("careplus"); } catch (e) { toast(e.message); return; }
    A.state.repeats = data.repeats;
    render();
  }

  function render() {
    if (!data) return;
    // Repeat prescriptions
    people("#rpFor");
    const today = new Date().toISOString().slice(0, 10);
    if (!$("#rpIssued").value) $("#rpIssued").value = today;
    if (!$("#rpUntil").value) { const d = new Date(); d.setMonth(d.getMonth() + 3); $("#rpUntil").value = d.toISOString().slice(0, 10); }
    const due = data.repeatDue.map(d => '<li class="warn-note">⏳ ' + esc(t("rpDue", { name: d.name, d: d.daysLeft })) + "</li>").join("");
    $("#rpList").innerHTML = due + (data.repeats.length ? data.repeats.map(r => "<li><span><b>" + esc(r.doctor) + "</b> · " + esc(name(r.memberId)) + "<br><small>" + esc(r.items.join(", ")) + "</small><br><small class=\"" + (r.active ? "muted" : "warn-note") + "\">" +
      esc(r.active ? t("rpLeft", { n: r.allowed - r.used, m: r.allowed, d: fmt(r.until) }) : t("rpUsedUp")) + "</small></span><span class=\"acts\">" +
      (r.active ? '<button class="btn btn-primary btn-sm" type="button" data-rporder="' + r.id + '">' + esc(t("rpOrder")) + "</button>" : "") +
      '<button class="link" type="button" data-rpdel="' + r.id + '" aria-label="remove">✕</button></span></li>').join("") : '<li class="muted">' + esc(t("rpNone")) + "</li>");
    // Medicine review
    $("#rvList").innerHTML = data.review.length ? data.review.map(r => '<div class="rv-person"><b>' + esc(r.name) + (r.age ? " (" + r.age + ")" : "") + "</b>" +
      (r.many ? "<p>" + esc(t("rvMany", { name: r.name, n: r.count })) + "</p>" : "") +
      r.flagged.map(f => "<p>⚠ " + esc(t("rvFlag", { name: r.name, med: f.name, why: f.why })) + "</p>").join("") + "</div>").join("") +
      '<p class="muted small">' + esc(t("rvAdvice")) + '</p><div><button class="btn btn-ghost btn-sm" type="button" data-go="shops">' + esc(t("rvAsk")) + "</button></div>"
      : '<p class="muted">' + esc(t("rvNone")) + "</p>";
    // Food and drink
    $("#fdList").innerHTML = data.food.length ? data.food.map(f => "<li><span>🍽 " + esc(f.text) + (f.memberId ? ' <small class="muted">· ' + esc(name(f.memberId)) + "</small>" : "") + "</span></li>").join("")
      : '<li class="muted">' + esc(t("fdNone")) + "</li>";
    // Mother and child
    people("#mcWho", m => !data.pregnancies.some(p => p.memberId === m.id));
    $("#mcList").innerHTML = data.pregnancies.map(p => {
      const pct = Math.min(100, Math.round((p.weeks * 7 + p.days) / 280 * 100));
      return '<div class="mc-preg"><div class="mc-head"><b>' + esc(name(p.memberId)) + "</b> · " + esc(t("mcWeeks", { w: p.weeks, d: p.days, t: p.trimester })) + "</div>" +
        '<div class="meter" aria-hidden="true"><span style="width:' + pct + '%"></span></div><p class="muted small">' + esc(t("mcEdd", { d: fmt(p.edd) })) + "</p>" +
        "<h4>" + esc(t("mcAnc")) + '</h4><ul class="ap-list">' + p.visits.map(v => '<li><label class="check"><input type="checkbox" data-anc="' + p.id + ":" + v.n + '"' + (v.done ? " checked" : "") + "> <span>" + esc(v.label) + "</span></label>" +
          '<span class="pill ' + ({ done: "", now: "warn", missed: "bad", later: "ex" }[v.state]) + '">' + esc(t({ done: "ancDone", now: "ancNow", missed: "ancMissed", later: "ancLater" }[v.state])) + "</span></li>").join("") + "</ul>" +
        (p.pmsma ? '<p class="small">🏥 ' + esc(t("mcPmsma", { d: fmt(p.pmsma) })) + "</p>" : "") +
        '<p class="small">💊 ' + esc(t("mcIfa", { d: fmt(p.ifaFrom) })) + ' <button class="link" type="button" data-ifa="' + p.memberId + ":" + p.ifaFrom + '">' + esc(t("mcAddIfa")) + "</button></p>" +
        '<p class="danger-box small">🚨 ' + esc(t("mcDanger")) + "</p><p class=\"muted small\">" + esc(t("mcSchemes")) + "</p>" +
        '<button class="link" type="button" data-pend="' + p.id + '">' + esc(t("mcEnd")) + "</button></div>";
    }).join("");
    $("#mcForm").hidden = !$("#mcWho").options.length;
    $("#mcGrowth").innerHTML = data.growth.length ? data.growth.map(g => "<li><span><b>" + esc(g.name) + "</b> · " + g.months + " m · " + esc(g.weights.slice(-3).map(w => w.kg + " kg").join(" → ") || "—") + "</span>" +
      '<span class="pill ' + (g.flag === "down" ? "bad" : g.flag === "flat" ? "warn" : "") + '">' + esc(t(g.flag === "down" ? "mcDown" : g.flag === "flat" ? "mcFlat" : "mcOk")) + "</span></li>").join("")
      : '<li class="muted">' + esc(t("mcGrowthNone")) + "</li>";
    // Points
    const p = data.points;
    $("#ptBal").textContent = t("ptBalance", { n: p.balance, r: (p.balance * p.valuePaise / 100).toFixed(0) });
    $("#ptAmount").min = p.min; $("#ptAmount").max = Math.max(p.min, Math.min(2000, p.balance));
    $("#ptVouchers").innerHTML = p.vouchers.filter(v => v.status === "open").map(v => "<li><span>🎟 " + esc(t("ptVoucher", { c: v.code, r: (v.points * p.valuePaise / 100).toFixed(0) })) +
      '</span><button class="link" type="button" data-vcancel="' + esc(v.code) + '">' + esc(t("ptCancel")) + "</button></li>").join("");
    // Phones without internet
    const nums = data.numbers || {};
    $("#pnList").innerHTML = A.state.members.map(m => {
      const ph = data.phones.find(x => x.memberId === m.id) || {};
      return '<form class="pn-row" data-pn="' + m.id + '"><b>' + esc(m.name) + '</b><input name="phone" inputmode="tel" maxlength="14" placeholder="98765 43210" aria-label="' + esc(t("pnPhone")) + '" value="' + esc(ph.phone || "") + '">' +
        '<label class="check"><input type="checkbox" name="sms"' + (ph.sms ? " checked" : "") + "> <span>" + esc(t("pnSms")) + "</span></label>" +
        '<button class="btn btn-ghost btn-sm" type="submit">' + esc(t("pnSave")) + "</button>" +
        (ph.phone ? '<small class="' + (ph.verified ? "ok-note" : "muted") + '">' + esc(ph.verified ? "✓ " + t("pnVerified") : nums.verify ? t("pnVerify", { n: nums.verify }) : "") + "</small>" : "") + "</form>";
    }).join("");
    $("#pnNumbers").textContent = nums.dose ? t("pnDose", { n: nums.dose }) : t("pnNoNumbers");
  }

  /* ---------- Actions ---------- */
  $("#rpForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const r = await api.post("rx_repeat_save", { member_id: Number($("#rpFor").value) || null, doctor: $("#rpDoctor").value, issued: $("#rpIssued").value, until: $("#rpUntil").value,
        allowed: Number($("#rpAllowed").value), items: $("#rpItems").value.split(/\n|,/).map(x => x.trim()).filter(Boolean) });
      data.repeats = r.repeats; A.state.repeats = r.repeats; e.target.reset(); e.target.closest("details").open = false; render(); A.sound("saved");
    } catch (err) { toast(err.message); }
  });
  document.addEventListener("click", async (e) => {
    const b = e.target.closest("[data-rpdel],[data-rporder],[data-pend],[data-ifa],[data-vcancel]"); if (!b) return;
    try {
      if (b.dataset.rpdel) { if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("confirmRemove"); return; } data.repeats = (await api.post("rx_repeat_delete", { id: Number(b.dataset.rpdel) })).repeats; render(); }
      if (b.dataset.rporder) {   // order the refill from a linked pharmacy: the Pharmacies screen opens with the medicines and this prescription
        const r = data.repeats.find(x => x.id === Number(b.dataset.rporder));
        window.ABRepeat = r; A.go("shops");
        const box = $("#shItems"); if (box) box.value = r.items.join("\n");
      }
      if (b.dataset.pend) { data.pregnancies = (await api.post("pregnancy_end", { id: Number(b.dataset.pend) })).pregnancies; render(); }
      if (b.dataset.ifa) {
        const [mid, from] = b.dataset.ifa.split(":"), end = new Date(from + "T00:00:00"); end.setDate(end.getDate() + 179);
        await api.post("item_save", { name: "IFA tablet (iron + folic acid)", times: ["e"], member_id: Number(mid), course_end: end.toISOString().slice(0, 10), notes: "After food, not with tea or milk. From " + from });
        toast("✔ " + t("saved")); A.sound("saved");
      }
      if (b.dataset.vcancel) { data.points = Object.assign(data.points, await api.post("voucher_cancel", { code: b.dataset.vcancel })); render(); }
    } catch (err) { toast(err.message); }
  });
  document.addEventListener("change", async (e) => {
    const c = e.target.closest("[data-anc]"); if (!c) return;
    const [id, n] = c.dataset.anc.split(":");
    try { data.pregnancies = (await api.post("pregnancy_anc", { id: Number(id), n: Number(n), done: c.checked })).pregnancies; render(); if (c.checked) toast("🎉 " + t("ptEarned", { n: 10 })); } catch (err) { toast(err.message); }
  });
  $("#mcForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { await api.post("pregnancy_start", { member_id: Number($("#mcWho").value), lmp: $("#mcLmp").value }); e.target.reset(); load(); A.sound("saved"); } catch (err) { toast(err.message); }
  });
  $("#ptForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const n = Number($("#ptAmount").value);
    if (n > data.points.balance) { toast(t("ptNotEnough", { n })); return; }
    try { const r = await api.post("voucher_make", { points: n }); data.points = r; render(); toast("🎟 " + t("ptVoucher", { c: r.voucher.code, r: r.voucher.rupees })); } catch (err) { toast(err.message); }
  });
  $("#pnList").addEventListener("submit", async (e) => {
    const f = e.target.closest("[data-pn]"); if (!f) return; e.preventDefault();
    try { await api.post("member_phone", { member_id: Number(f.dataset.pn), phone: f.phone.value, sms: f.sms.checked }); load(); toast("✔ " + t("saved")); } catch (err) { toast(err.message); }
  });

  /* ---------- One-time code for a pharmacy (on the card screen) ---------- */
  $("#scBtn").addEventListener("click", async () => {
    const chip = document.querySelector("#cdMembers [aria-pressed=true]");
    const mid = chip ? Number(chip.dataset.cd) : (A.state.members[0] || {}).id;
    try {
      const r = await api.post("share_code", { member_id: mid, add: $("#cdAdd").checked || !$("#cdView").checked, view: $("#cdView").checked || !$("#cdAdd").checked });
      const out = $("#scOut"); out.innerHTML = esc(t("scShow", { c: "" })).replace(/\s*\.?\s*$/, "") + ' <b class="mono sc-code">' + esc(r.code.slice(0, 3) + " " + r.code.slice(3)) + "</b>"; out.hidden = false;
      setTimeout(() => { out.hidden = true; }, r.minutes * 60000);
    } catch (err) { toast(err.message); }
  });

  /* ---------- Village and data (Account) ---------- */
  function fillRural() {
    const u = A.state.user || {};
    $("#ruLow").checked = !!u.lowData; $("#ruDistrict").value = u.district || ""; $("#ruVillage").value = u.village || ""; $("#ruAsha").checked = !!u.healthWorker;
  }
  $("#ruForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try {
      const r = await api.post("rural_save", { low_data: $("#ruLow").checked, district: $("#ruDistrict").value, village: $("#ruVillage").value, health_worker: $("#ruAsha").checked });
      Object.assign(A.state.user, r.user); document.body.classList.toggle("lite", !!r.user.lowData); toast("✔ " + t("saved"));
    } catch (err) { toast(err.message); }
  });
  $("#bxKind").addEventListener("change", () => { $("#bxLids").closest(".field").hidden = $("#bxKind").value === "life"; });

  const prevAccount = (window.ABHooks || {}).account;
  window.ABHooks = Object.assign(window.ABHooks || {}, { care: load, account: () => { if (prevAccount) prevAccount(); fillRural(); } });
  if (A.current === "care" && A.state.user) load();
})();
