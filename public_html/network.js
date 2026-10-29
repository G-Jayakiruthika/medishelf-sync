/* AushadhBox: the Pharmacies screen. Talks to the family app's api.php (net_* actions), which signs and forwards to
   AushadhBox Pharmacy Network. Find medicines in stock nearby, scan a shop's QR, order for pickup / delivery / a village
   pickup point, pay by UPI (a QR made from the shop's UPI ID), record cash or other payments, credit book (khata),
   reward points and referral code, complaints. */
(function () {
  "use strict";
  const A = window.AB; if (!A) return;
  const $ = (s, r = document) => r.querySelector(s);
  const { api, t, esc, toast } = A;
  const ST = { new: "osNew", accepted: "osAccepted", packed: "osPacked", ready: "osReady", out: "osOut", delivered: "osDelivered", collected: "osCollected", cancelled: "osCancelled" };
  const AV = { in: ["stIn", "ok"], low: ["stLow", "warn"], some: ["stSome", "warn"], out: ["stOut", "bad"] };
  let net = { on: false }, here = null, store = null;

  const rupees = (n) => "₹" + Number(n || 0).toLocaleString("en-IN", { maximumFractionDigits: 2 });
  function lines(text) { return text.split(/\n|,|;/).map(s => s.trim()).filter(s => s.length >= 2).slice(0, 15).map(s => { const m = s.match(/^(.*?)\s*[x×*]\s*(\d{1,3})$/i); return m ? { name: m[1], qty: Number(m[2]) } : { name: s, qty: 1 }; }); }
  function locate() {
    return new Promise((ok) => {
      if (!navigator.geolocation) return ok(null);
      navigator.geolocation.getCurrentPosition(p => { here = { lat: +p.coords.latitude.toFixed(5), lng: +p.coords.longitude.toFixed(5) }; ok(here); }, () => ok(null), { enableHighAccuracy: false, timeout: 9000, maximumAge: 600000 });
    });
  }
  function upiLink(vpa, name, amount, note) {
    return "upi://pay?pa=" + encodeURIComponent(vpa) + "&pn=" + encodeURIComponent(name) + (amount ? "&am=" + Number(amount).toFixed(2) : "") + "&cu=INR&tn=" + encodeURIComponent(note || "AushadhBox");
  }

  async function load() {
    try { net = await api.get("net_status"); } catch (e) { net = { on: false }; }
    $("#shOff").hidden = !!net.on;
    ["#shFind", "#shWalletCard"].forEach(s => { $(s).style.opacity = net.on ? "" : ".55"; });
    if (net.district && !$("#shDistrict").value) $("#shDistrict").value = net.district;
    $("#shMine").innerHTML = (net.mine || []).length ? net.mine.map(m => '<li><span><b>' + esc(m.name) + "</b>" + (m.village ? ' <small class="muted">' + esc(m.village) + "</small>" : "") +
      '</span><span><button class="btn btn-ghost btn-sm" type="button" data-store="' + esc(m.code) + '">' + esc(t("shView")) + '</button> <button class="link" type="button" data-forget="' + esc(m.code) + '" aria-label="remove">✕</button></span></li>').join("")
      : '<li class="muted">' + esc(t("shMineNone")) + "</li>";
    if (!net.on) return;
    loadOrders(); loadWallet();
  }

  /* ---------- Find medicines nearby ---------- */
  $("#shFromList").addEventListener("click", () => { $("#shItems").value = A.shoppingList().map(x => x.m.name).join("\n"); });
  $("#shLoc").addEventListener("click", async () => {
    const m = $("#shLocMsg"); m.hidden = false; m.textContent = "…";
    const p = await locate(); m.textContent = p ? "✓ " + p.lat + ", " + p.lng : t("btNone").split(".")[0];
  });
  $("#shFind").addEventListener("submit", async (e) => {
    e.preventDefault();
    const items = lines($("#shItems").value); if (!items.length) { $("#shItems").focus(); return; }
    const box = $("#shResults"); box.innerHTML = '<p class="muted">…</p>';
    try {
      const r = await api.post("net_find", { items, lat: here ? here.lat : null, lng: here ? here.lng : null, district: $("#shDistrict").value.trim() || null });
      box.innerHTML = r.pharmacies.length ? r.pharmacies.map(p => '<div class="sh-res"><div class="sh-res-top"><b>' + esc(p.name) + "</b>" +
        (p.distanceKm !== null ? '<span class="muted">' + esc(t("shKm", { n: p.distanceKm })) + "</span>" : "") +
        (p.openNow === true ? '<span class="pill">' + esc(t("shOpen")) + "</span>" : p.openNow === false ? '<span class="pill warn">' + esc(t("shClosed")) + "</span>" : "") +
        (p.janAushadhi ? '<span class="pill ex">Jan Aushadhi</span>' : "") + "</div>" +
        '<small class="muted">' + esc([p.village, p.district].filter(Boolean).join(", ")) + " · " + esc(t("shFound", { n: p.found, m: p.of })) + "</small>" +
        '<ul class="sh-items">' + p.items.map(i => "<li><span>" + esc(i.matched || i.name) + (i.schedule && i.schedule !== "OTC" ? ' <small class="muted">Rx</small>' : "") + '</span><span><span class="pill ' + AV[i.status][1] + '">' + esc(t(AV[i.status][0])) + "</span>" +
          (i.price !== null && i.status !== "out" ? " " + esc(rupees(i.price)) : "") + "</span></li>").join("") + "</ul>" +
        '<small class="muted">' + esc(!p.delivery ? t("shNoDel") : !p.quote ? t("shDelivery") : p.quote.possible ? (p.quote.fee ? t("shDelFee", { n: p.quote.fee }) : t("shDelFree")) : t("shTooFar")) + "</small>" +
        '<div><button class="btn btn-primary btn-sm" type="button" data-store="' + esc(p.code) + '" data-items="' + esc(JSON.stringify(items)) + '">' + esc(t("shOrder")) + "</button></div></div>").join("")
        : '<p class="muted">' + esc(t("shNone")) + "</p>";
    } catch (err) { box.innerHTML = '<p class="error">' + esc(err.message) + "</p>"; }
  });
  document.addEventListener("click", (e) => {
    const b = e.target.closest("[data-store]"); if (b && b.closest("#view-shops, #storeDialog")) { openStore(b.dataset.store, b.dataset.items ? JSON.parse(b.dataset.items) : null); return; }
    const f = e.target.closest("[data-forget]"); if (f) api.post("net_forget_store", { code: f.dataset.forget }).then(load);
  });
  $("#shScanBtn").addEventListener("click", () => { A.go("scan"); toast(t("shScan")); });
  const shopFind = $("#shopFind");
  if (shopFind) shopFind.addEventListener("click", () => { $("#shopDialog").close(); $("#shItems").value = A.shoppingList().map(x => x.m.name).join("\n"); A.go("shops"); });

  /* ---------- A shop: details, pay, order, complain ---------- */
  async function openStore(code, items) {
    const d = $("#storeDialog");
    $("#stBody").innerHTML = '<p class="muted">…</p>'; if (!d.open) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    try { store = await api.post("net_store", { code, lat: here ? here.lat : null, lng: here ? here.lng : null }); }
    catch (err) { $("#stBody").innerHTML = '<p class="error">' + esc(err.message) + "</p>"; return; }
    const s = store, mine = s.mine || {};
    const kh = mine.khata && mine.khata.balance > 0 ? '<p class="sh-owe">' + esc(t("shOwe", { n: rupees(mine.khata.balance), s: s.name })) + "</p>" : "";
    $("#stBody").innerHTML =
      '<h2 id="stName">' + esc(s.name) + "</h2><p class=\"muted\">" + esc([s.address, s.village, s.district].filter(Boolean).join(", ")) + "</p>" +
      "<p>" + (s.openNow === true ? '<span class="pill">' + esc(t("shOpen")) + "</span> " : s.openNow === false ? '<span class="pill warn">' + esc(t("shClosed")) + "</span> " : "") + esc(s.hours || "") +
      ' · <a href="tel:+91' + esc(s.phone) + '">' + esc(s.phone) + "</a>" + (mine.points ? " · " + esc(t("shPoints", { n: mine.points, s: "" })) : "") + "</p>" + kh +
      // Pay
      (s.pay.upi && s.upiLink ? '<details class="sh-box" id="stPay"><summary><b>' + esc(t("shPay")) + "</b></summary>" +
        '<div class="ph-code"><div class="field"><label for="stAmt">' + esc(t("shAmount")) + '</label><input id="stAmt" inputmode="decimal" maxlength="9"' + (mine.khata && mine.khata.balance > 0 ? ' value="' + mine.khata.balance + '"' : "") + '></div><a class="btn btn-primary btn-sm" id="stUpi" href="#">' + esc(t("shPay")) + "</a></div>" +
        '<canvas id="stQr" width="220" height="220" hidden aria-label="UPI QR"></canvas>' + (mine.khata && mine.khata.balance > 0 ? claimForm("stClaim", "") : "") + "</details>" : "") +
      // Order
      '<details class="sh-box" id="stOrder"' + (items ? " open" : "") + '><summary><b>' + esc(t("shOrder")) + "</b></summary><form class=\"stack\" id=\"stOrderForm\">" +
        '<div class="field"><label for="stItems">' + esc(t("shItems")) + '</label><textarea id="stItems" rows="3" maxlength="1200">' + esc((items || []).map(i => i.name + (i.qty > 1 ? " x " + i.qty : "")).join("\n")) + "</textarea></div>" +
        '<div class="chips" role="radiogroup">' + [["pickup", "shPickup"], ["delivery", "shDelivery"], ["point", "shPoint"]].filter(([k]) => k !== "delivery" || s.delivery).filter(([k]) => k !== "point" || (s.points || []).length)
          .map(([k, key], i) => '<label class="chip"><input type="radio" name="stKind" value="' + k + '"' + (i === 0 ? " checked" : "") + "> " + esc(t(key)) + "</label>").join("") + "</div>" +
        '<div id="stDel" hidden class="stack"><div class="field"><label for="stAddr">' + esc(t("shAddress")) + '</label><input id="stAddr" maxlength="200"></div><button class="btn btn-ghost btn-sm" type="button" id="stLoc">' + esc(t("shUseLoc")) + '</button><p class="muted small" id="stQuote"></p>' +
          '<label class="check"><input type="checkbox" id="stPayFull"> <span>' + esc(t("shPayFull")) + "</span></label></div>" +
        '<div id="stPt" hidden class="field"><select id="stPoint">' + (s.points || []).map(p => '<option value="' + p.id + '">' + esc(p.village + " · " + p.name + (p.days ? " (" + p.days + ")" : "")) + "</option>").join("") + "</select></div>" +
        '<div class="field"><label for="stPhone">' + esc(t("shYourPhone")) + '</label><input id="stPhone" inputmode="tel" maxlength="13" value="' + esc((A.state.user && A.state.user.phone) || "") + '"></div>' +
        '<div class="field"><label for="stNote">' + esc(t("shNote")) + '</label><input id="stNote" maxlength="300"></div>' +
        '<div class="field"><label for="stRef">' + esc(t("shRefCode")) + '</label><input id="stRef" maxlength="6" style="text-transform:uppercase"></div>' +
        '<label class="check"><input type="checkbox" id="stShare" required> <span>' + esc(t("shShareOk")) + "</span></label>" +
        '<button class="btn btn-primary" type="submit">' + esc(t("shPlace")) + '</button><p class="error" id="stErr" hidden></p></form></details>' +
      '<details class="sh-box"><summary>' + esc(t("shComplain")) + '</summary><form class="stack" id="stComplain"><select id="stTopic">' +
        [["overcharge", "tpOver"], ["wrong", "tpWrong"], ["late", "tpLate"], ["behaviour", "tpBehav"], ["quality", "tpQual"], ["privacy", "tpPriv"], ["other", "tpOther"]].map(([k, key]) => '<option value="' + k + '">' + esc(t(key)) + "</option>").join("") +
        '</select><textarea id="stCBody" rows="3" maxlength="1000" required></textarea><button class="btn btn-ghost btn-sm" type="submit">' + esc(t("shSendComplaint")) + "</button></form></details>";
    wireStore();
  }
  function claimForm(id, number) {
    return '<form class="stack sh-claim" id="' + id + '" data-number="' + esc(number) + '"><b>' + esc(t("shPaid")) + '</b><select name="method"><option value="upi_qr">' + esc(t("pmUpi")) + '</option><option value="upi_phone">' + esc(t("pmPhone")) +
      '</option><option value="other_qr">' + esc(t("pmOther")) + '</option><option value="cash">' + esc(t("pmCash")) + "</option></select>" +
      '<input name="utr" inputmode="numeric" maxlength="22" placeholder="' + esc(t("shUtr")) + '"><button class="btn btn-ghost btn-sm" type="submit">' + esc(t("shPaid")) + "</button></form>";
  }
  function wireStore() {
    const s = store, f = $("#stOrderForm");
    const upi = $("#stUpi");
    if (upi) upi.addEventListener("click", (e) => {
      const amt = Number(($("#stAmt").value || "").replace(/[^\d.]/g, ""));
      const qp = new URLSearchParams(s.upiLink.split("?")[1] || ""), link = upiLink(qp.get("pa") || "", qp.get("pn") || s.name, amt || null, "AushadhBox");
      upi.href = link; const cv = $("#stQr"); cv.hidden = false; A.drawQr(cv, link);   // on a phone the link opens the UPI app; on a computer, scan the QR
    });
    const claim = $("#stClaim");
    if (claim) claim.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(claim), amt = Number(($("#stAmt").value || "").replace(/[^\d.]/g, ""));
      try {
        await api.post("net_khata_pay", { code: s.code, method: fd.get("method"), utr: fd.get("utr"), amount: amt });
        toast("✔ " + t("shClaimSent")); loadWallet();
      } catch (err) { toast(err.message); }
    });
    const kindChange = () => {
      const k = (f.querySelector("input[name=stKind]:checked") || {}).value;
      $("#stDel").hidden = k !== "delivery"; $("#stPt").hidden = k !== "point";
    };
    f.addEventListener("change", (e) => { if (e.target.name === "stKind") kindChange(); });
    const quote = async () => {
      if (!here) return;
      try { const q = await api.post("net_quote", { code: s.code, lat: here.lat, lng: here.lng, items: lines($("#stItems").value), customer_pays_full: $("#stPayFull").checked });
        $("#stQuote").textContent = (q.possible ? (q.fee ? t("shDelFee", { n: q.fee }) : t("shDelFree")) : t("shTooFar")) + " · " + q.why.join(" "); } catch (err) { $("#stQuote").textContent = err.message; }
    };
    $("#stLoc").addEventListener("click", async () => { await locate(); quote(); });
    $("#stPayFull").addEventListener("change", quote);
    f.addEventListener("submit", async (e) => {
      e.preventDefault(); const err = $("#stErr"); err.hidden = true;
      const kind = (f.querySelector("input[name=stKind]:checked") || {}).value || "pickup";
      if (kind === "delivery" && !here) { await locate(); if (!here) { err.textContent = t("shUseLoc"); err.hidden = false; return; } }
      try {
        const r = await api.post("net_order", { code: s.code, items: lines($("#stItems").value), kind, pickup_point_id: kind === "point" ? Number($("#stPoint").value) : null, note: $("#stNote").value,
          referral_code: $("#stRef").value.trim() || null, customer_pays_full: $("#stPayFull").checked, share_ok: $("#stShare").checked,
          customer: { phone: $("#stPhone").value, address: $("#stAddr").value, lat: here ? here.lat : null, lng: here ? here.lng : null, district: $("#shDistrict").value.trim() || null, lang: A.state.lang, sms_ok: true } });
        A.sound("saved"); toast("✔ " + t("shPlaced", { n: r.number, s: s.name }) + (r.rules && r.rules.rx ? " " + t("shRxNote") : ""));
        $("#storeDialog").close(); loadOrders();
      } catch (er) { err.textContent = er.message; err.hidden = false; }
    });
    $("#stComplain").addEventListener("submit", async (e) => {
      e.preventDefault();
      try { await api.post("net_complaint", { code: s.code, topic: $("#stTopic").value, body: $("#stCBody").value }); toast("✔ " + t("shComplaintSent")); e.target.reset(); } catch (err) { toast(err.message); }
    });
  }

  /* ---------- My orders ---------- */
  async function loadOrders() {
    try {
      const r = await api.get("net_orders");
      $("#shOrders").innerHTML = r.orders.length ? r.orders.slice(0, 15).map(o => "<li><span><b>" + esc(o.number) + "</b> · " + esc(o.pharmacy.name) +
        '<br><small class="muted">' + esc(o.items.map(i => i.name + (i.qty > 1 ? " ×" + i.qty : "")).join(", ")) + " · " + esc(rupees(o.total)) + (o.payStatus !== "paid" && o.total > 0 ? " · " + esc(o.payStatus) : "") + "</small>" +
        (o.otp ? '<br><span class="sh-otp">' + esc(t("shCode", { c: o.otp })) + "</span>" : "") +
        (o.askedPharmacy ? '<br><small class="muted">↪ ' + esc(o.askedPharmacy) + "</small>" : "") + '</span><span class="acts"><span class="pill ' + (o.status === "cancelled" ? "bad" : ["ready", "out"].includes(o.status) ? "warn" : "") + '">' + esc(t(ST[o.status] || "osNew")) + "</span>" +
        (["new", "accepted"].includes(o.status) ? '<button class="link" type="button" data-cancel="' + esc(o.number) + '">' + esc(t("shCancel")) + "</button>" : "") +
        (o.payUpi && !["cancelled"].includes(o.status) ? '<a class="btn btn-ghost btn-sm" href="' + esc(o.payUpi) + '">' + esc(t("shPay")) + "</a>" : "") +
        (o.total > o.paid && !["cancelled"].includes(o.status) ? '<button class="link" type="button" data-paid="' + esc(o.number) + '" data-amount="' + (o.total - o.paid) + '">' + esc(t("shPaid")) + "</button>" : "") + "</span></li>").join("")
        : '<li class="muted">' + esc(t("shOrdersNone")) + "</li>";
    } catch (e) { $("#shOrders").innerHTML = '<li class="muted">' + esc(e.message) + "</li>"; }
  }
  $("#shOrders").addEventListener("submit", async (e) => {   // "I paid" for an order: the pharmacy confirms it
    const f = e.target.closest(".sh-claim"); if (!f) return; e.preventDefault();
    const fd = new FormData(f);
    try { await api.post("net_pay_claim", { number: f.dataset.number, method: fd.get("method"), utr: fd.get("utr"), amount: Number(f.dataset.amount) }); toast("✔ " + t("shClaimSent")); f.remove(); }
    catch (err) { toast(err.message); }
  });
  $("#shOrders").addEventListener("click", async (e) => {
    const pd = e.target.closest("[data-paid]");
    if (pd) { if (!pd.closest("li").querySelector(".sh-claim")) { pd.closest("li").insertAdjacentHTML("beforeend", claimForm("", pd.dataset.paid)); pd.closest("li").querySelector(".sh-claim").dataset.amount = pd.dataset.amount; } return; }
    const b = e.target.closest("[data-cancel]"); if (!b) return;
    if (b.dataset.confirm !== "1") { b.dataset.confirm = "1"; b.textContent = t("tapAgain"); return; }
    try { await api.post("net_cancel", { number: b.dataset.cancel }); loadOrders(); } catch (err) { toast(err.message); }
  });

  /* ---------- Wallet: khata, points, referrals ---------- */
  async function loadWallet() {
    try {
      const w = await api.get("net_wallet");
      $("#shWallet").innerHTML =
        (w.khata.filter(k => k.balance > 0).map(k => '<div class="sh-kh"><p class="sh-owe">' + esc(t("shOwe", { n: rupees(k.balance), s: k.pharmacy })) + "</p>" +
          '<ul class="ap-list">' + k.entries.slice(0, 5).map(en => "<li><span>" + esc(en.kind === "credit" ? "+ " : "− ") + esc(rupees(en.amount)) + (en.note ? ' <small class="muted">' + esc(en.note) + "</small>" : "") + '</span><small class="muted">' + esc(A.fmtSeen(en.at)) + "</small></li>").join("") + "</ul>" +
          (k.upiLink ? '<a class="btn btn-primary btn-sm" href="' + esc(k.upiLink) + '">' + esc(t("shPay")) + "</a> " : "") + '<button class="btn btn-ghost btn-sm" type="button" data-store="' + esc(k.code) + '">' + esc(t("shView")) + "</button></div>").join("") || '<p class="muted">' + esc(t("shNoKhata")) + "</p>") +
        (w.points.length ? "<p>" + w.points.map(p => esc(t("shPoints", { n: p.points, s: p.business }))).join("<br>") + "</p>" : "") +
        '<p class="sh-ref">' + esc(t("shRef", { c: w.refCode })) + ' <button class="btn btn-ghost btn-sm" type="button" id="shRefShare">' + esc(t("shRefShare")) + '</button><br><small class="muted">' + esc(t("shRefDesc")) + "</small></p>" +
        (w.claims.length ? '<ul class="ap-list">' + w.claims.slice(0, 5).map(c => "<li><span>" + esc(c.pharmacy) + " · " + esc(rupees(c.amount)) + '</span><span class="pill ' + (c.status === "confirmed" ? "" : c.status === "rejected" ? "bad" : "warn") + '">' + esc(c.status) + "</span></li>").join("") + "</ul>" : "");
      $("#shRefShare").addEventListener("click", async () => {
        const text = "AushadhBox: use my code " + w.refCode + " on your first order at the pharmacy and we both get reward points.";
        if (navigator.share) { try { await navigator.share({ text }); return; } catch (e) { /* cancelled */ } }
        A.fallbackCopy(text, () => toast("✔ " + t("calCopied")));
      });
      document.querySelector(".sh-link").hidden = !!w.linkedPhone;
    } catch (e) { $("#shWallet").innerHTML = '<p class="muted">' + esc(e.message) + "</p>"; }
  }
  $("#shLinkSend").addEventListener("click", async () => {
    try { const r = await api.post("net_link_start", { phone: $("#shLinkPhone").value }); $("#shLinkStep").hidden = false; toast("✔ " + t("shCodeSent") + (r.devCode ? " (" + r.devCode + ")" : "")); } catch (err) { toast(err.message); }
  });
  $("#shLinkOk").addEventListener("click", async () => {
    try { await api.post("net_link_confirm", { phone: $("#shLinkPhone").value, code: $("#shLinkCode").value }); toast("✔ " + t("shLinked")); loadWallet(); } catch (err) { toast(err.message); }
  });

  window.ABHooks = Object.assign(window.ABHooks || {}, { shops: load, store: (code) => { load(); openStore(code, null); } });
})();
