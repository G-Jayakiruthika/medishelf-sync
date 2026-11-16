/* AushadhBox: Privacy tab. What we keep (record of processing with this family's counts), choices, rights and requests,
   nominee, how it is protected, consent history. Talks to api.php?action=privacy and the privacy_* actions. */
(function () {
  "use strict";
  const A = window.AB; if (!A) return;
  const $ = (s, r = document) => r.querySelector(s);
  const { api, t, esc, toast } = A;
  let data = null;
  const day = (d) => { try { return new Date(String(d).replace(" ", "T")).toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" }); } catch (e) { return d; } };
  const STATUS = { open: "pvOpen", in_progress: "pvInProgress", done: "pvDone", declined: "pvDeclined" };
  const PILL = { open: "warn", in_progress: "ex", done: "", declined: "bad" };

  async function load() {
    try { data = await api.get("privacy"); } catch (e) { toast(e.message); return; }
    render();
  }

  function render() {
    const d = data;
    // What we keep: one row per kind of data, with how much this family has
    $("#pvRegister").innerHTML = d.register.map(r => {
      const n = d.counts[r.id];
      return '<details class="pv-row"><summary><b>' + esc(r.category) + "</b>" + (n ? ' <span class="pill ex">' + esc(t("pvCount", { n })) + "</span>" : "") +
        ' <span class="pill ' + (r.needed === "required" ? "" : "warn") + '">' + esc(t(r.needed === "required" ? "pvRequired" : "pvOptional")) + "</span></summary><dl>" +
        [["pvItems", r.items], ["pvPurpose", r.purpose], ["pvBasis", r.basis], ["pvRecipients", r.recipients], ["pvRetention", r.retention], ["pvLocation", r.location], ["pvProtection", r.protection]]
          .map(([k, v]) => "<dt>" + esc(t(k)) + "</dt><dd>" + esc(v) + "</dd>").join("") + "</dl></details>";
    }).join("");
    // Choices
    document.querySelectorAll("[data-pvchoice]").forEach(c => { c.checked = !!{ news: d.choices.news, weekly: d.choices.weeklyEmail, ai: d.choices.ai }[c.dataset.pvchoice]; });
    $("#pvPeople").innerHTML = d.choices.people.map(p => {
      const parts = [p.pharmacyAdd && t("pvMayAdd"), p.pharmacyView && t("pvMayView"), p.sms && t("pvSmsOn")].filter(Boolean);
      return "<li><span><b>" + esc(p.name) + "</b><br><small class=\"muted\">" + esc(parts.length ? parts.join(" · ") : t("pvNotShared")) + "</small>" +
        (p.child && !p.guardian ? '<br><small class="warn-note">' + esc(t("pvChildNeeds")) + "</small>" : "") + "</span></li>";
    }).join("");
    // Rights and requests
    $("#pvKind").innerHTML = Object.keys(d.kinds).map(k => '<option value="' + esc(k) + '">' + esc(t("pvK_" + k)) + "</option>").join("");
    $("#pvReplyNote").textContent = t("pvReplyNote", { d: d.replyDays });
    $("#pvRequests").innerHTML = d.requests.length ? d.requests.map(r => "<li><span><b>" + esc(r.ref) + "</b> · " + esc(t("pvK_" + r.kind)) + ' <span class="pill ' + (PILL[r.status] || "") + '">' + esc(t(STATUS[r.status] || "pvOpen")) + "</span>" +
      (r.details ? '<br><small class="muted">' + esc(r.details) + "</small>" : "") + (r.reply ? "<br><small>↳ " + esc(r.reply) + "</small>" : "") +
      (r.status === "open" || r.status === "in_progress" ? '<br><small class="muted">' + esc(t("pvDue", { d: day(r.due) })) + "</small>" : "") + "</span></li>").join("")
      : '<li class="muted">' + esc(t("pvNoReq")) + "</li>";
    // Nominee
    $("#pvNomName").value = d.nominee.name || ""; $("#pvNomContact").value = d.nominee.contact || "";
    // How we protect it
    $("#pvPrinciples").innerHTML = d.principles.map(p => "<li><span><b>" + esc(p.name) + '</b><br><small class="muted">' + esc(p.how) + "</small></span></li>").join("");
    $("#pvOfficer").textContent = d.officer.name || d.officer.email ? t("pvOfficer", { n: d.officer.name || "", e: d.officer.email || "" }) : "";
    // Consent history
    const PURPOSE = { privacy_notice: "Privacy notice", adult_18: "18 or older", marketing_email: "Product news e-mail", weekly_email: "Weekly e-mail", alert_email: "Alert e-mails", ai_helper: "AI helper",
                      nominee: "Nominee", guardian_child: "Guardian of a child" };
    $("#pvConsents").innerHTML = d.consents.length ? d.consents.map(c => "<li><span>" + esc(PURPOSE[c.purpose] || c.purpose) + ' <span class="pill ' + (Number(c.granted) ? "" : "warn") + '">' + esc(t(Number(c.granted) ? "pvGiven" : "pvWithdrawn")) + "</span>" +
      '<br><small class="muted">' + esc(day(c.created_at) + " · " + c.source) + "</small></span></li>").join("") : '<li class="muted">' + esc(t("pvNoHistory")) + "</li>";
  }

  document.addEventListener("change", async (e) => {
    const c = e.target.closest("[data-pvchoice]"); if (!c) return;
    try { await api.post("privacy_choice", { what: c.dataset.pvchoice, on: c.checked }); toast("✔ " + t("saved")); load(); }
    catch (err) { c.checked = !c.checked; toast(err.message); }
  });
  $("#pvReqForm").addEventListener("submit", async (e) => {
    e.preventDefault(); const err = $("#pvReqErr"); err.hidden = true;
    try {
      const r = await api.post("privacy_request", { kind: $("#pvKind").value, details: $("#pvDetails").value });
      $("#pvDetails").value = ""; toast("✔ " + t("pvSent", { r: r.request.ref, d: data.replyDays })); load();
    } catch (er) { err.textContent = er.message; err.hidden = false; }
  });
  $("#pvNomForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    try { await api.post("privacy_nominee", { name: $("#pvNomName").value, contact: $("#pvNomContact").value }); toast("✔ " + t("pvNomSaved")); load(); } catch (er) { toast(er.message); }
  });
  $("#pvExport").addEventListener("click", () => { const b = $("#btnExport"); if (b) b.click(); });

  window.ABHooks = Object.assign(window.ABHooks || {}, { privacy: load });
  if (A.current === "privacy" && A.state.user) load();
})();
