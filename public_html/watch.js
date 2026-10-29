/* The watch page (/watch): what is due now, one big "Taken" button, and the day at a glance.
   Uses the family sign-in of this browser (Wear OS / Galaxy watch browser, or the phone). Refreshes every minute. */
(function () {
  "use strict";
  const $ = (s) => document.querySelector(s);
  let lang = "en"; try { const s = localStorage.getItem("ms-lang"); if (s && window.I18N && I18N[s]) lang = s; } catch (e) { /* storage blocked */ }
  if (lang === "en") { const n = (navigator.language || "en").slice(0, 2); if (window.I18N && I18N[n]) lang = n; }
  document.documentElement.lang = lang;
  const t = (k, p) => { let s = ((window.I18N || {})[lang] || {})[k] || ((window.I18N || {}).en || {})[k] || k; if (p) Object.keys(p).forEach(x => { s = s.split("{" + x + "}").join(p[x]); }); return s; };
  const SLOT = { m: "tMorning", a: "tAfternoon", e: "tEvening", b: "tBedtime" };
  let csrf = "", status = null;

  async function call(action, post) {
    const opts = { credentials: "same-origin", method: post ? "POST" : "GET", headers: {} };
    if (post) { opts.headers["Content-Type"] = "application/json"; opts.headers["X-CSRF-Token"] = csrf; opts.body = "{}"; }
    const res = await fetch("/api.php?action=" + action, opts);
    if (res.status === 401) throw { signin: true };
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw { message: data.message || "Error" };
    return data;
  }
  function signin() {
    $("#wSlot").textContent = "AushadhBox"; $("#wMeds").innerHTML = ""; $("#wBtn").hidden = true; $("#wDay").innerHTML = "";
    $("#wMsg").textContent = ""; $("#wSignin").textContent = t("wSignIn"); $("#wSignin").hidden = false;
  }
  function render() {
    const s = status, now = s.slots.find(x => x.slot === s.now);
    $("#wSignin").hidden = true;
    if (now) {
      $("#wSlot").textContent = t(SLOT[now.slot]) + " · " + now.time;
      const max = innerWidth < 320 ? 3 : 8, left = now.meds.filter(m => !m.status), show = left.concat(now.meds.filter(m => m.status));   // not yet taken first
      $("#wMeds").innerHTML = show.slice(0, show.length > max ? max - 1 : max).map(m => { const li = document.createElement("li"); li.textContent = m.name.replace(/\s*\(.*\)$/, ""); if (m.status) li.className = "done"; return li.outerHTML; }).join("") +
        (show.length > max ? "<li>+" + (show.length - max + 1) + "</li>" : "");
      $("#wBtn").hidden = false; $("#wBtn").textContent = t("wTaken");
    } else {
      $("#wSlot").textContent = t("wAllDone"); $("#wMeds").innerHTML = ""; $("#wBtn").hidden = true;
      const hm = new Date().toTimeString().slice(0, 5), next = s.slots.find(x => !x.done && x.time > hm);
      $("#wMsg").textContent = next ? t("wNext", { slot: t(SLOT[next.slot]), t: next.time }) : "";
    }
    $("#wDay").innerHTML = s.slots.map(x => '<li class="' + (x.done ? "done" : x.slot === s.now ? "now" : "") + '">' + (x.done ? "✓ " : "") + t(SLOT[x.slot]) + "</li>").join("");
  }
  async function load() {
    try { const ses = await call("session"); csrf = ses.csrf; if (!ses.user) { signin(); return; } status = await call("watch_status"); render(); }
    catch (e) { if (e && e.signin) signin(); else $("#wMsg").textContent = navigator.onLine ? (e.message || "") : "Offline"; }
  }
  $("#wBtn").addEventListener("click", async () => {
    const b = $("#wBtn"); b.disabled = true;
    try {
      const r = await call("watch_tick", true);
      $("#wMsg").textContent = r.ticked ? t("wTicked", { n: r.ticked }) : t("wAlready");
      if (navigator.vibrate) navigator.vibrate(r.ticked ? [60, 40, 60] : 30);
      await load(); if (r.ticked) $("#wMsg").textContent = t("wTicked", { n: r.ticked });
    } catch (e) { if (e && e.signin) signin(); else $("#wMsg").textContent = e.message || "Error"; }
    b.disabled = false;
  });
  load();
  setInterval(() => { if (document.visibilityState === "visible") load(); }, 60000);
  document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible") load(); });
})();
