/* Cookie consent (DPDP Act 2023 friendly: opt-in, granular, can be changed any time).
   - Necessary cookies (sign-in session, CSRF, this choice) always work.
   - Analytics (our own first-party stats, and Google Analytics if configured) run ONLY after "Accept".
   - Marketing is off unless the visitor turns it on.
   Cookie: ms_consent = "1.<analytics 0|1>.<marketing 0|1>.<random id>"  (180 days) */
(function () {
  "use strict";
  var script = document.currentScript || document.querySelector("script[src*='consent.js']");
  var lang = (script && script.dataset.lang) || (function () { try { return localStorage.getItem("ms-lang") || "en"; } catch (e) { return "en"; } })();
  var gaId = (script && script.dataset.ga) || "";
  var VERSION = "1";
  var DICT = (window.MS_CONSENT_I18N || {});
  function t(k) { return (DICT[lang] && DICT[lang][k]) || (DICT.en && DICT.en[k]) || k; }

  function readCookie() {
    var m = document.cookie.match(/(?:^|; )ms_consent=([^;]+)/);
    if (!m) return null;
    var p = decodeURIComponent(m[1]).split(".");
    if (p[0] !== VERSION || p.length < 4) return null;
    return { analytics: p[1] === "1", marketing: p[2] === "1", ref: p[3] };
  }
  function randomRef() {
    var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
  }
  function writeCookie(c) {
    var v = [VERSION, c.analytics ? 1 : 0, c.marketing ? 1 : 0, c.ref].join(".");
    document.cookie = "ms_consent=" + v + "; Max-Age=" + (180 * 86400) + "; Path=/; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
  }
  function clearOptionalCookies() {
    document.cookie.split("; ").forEach(function (kv) {
      var name = kv.split("=")[0];
      if (/^_ga|^_gid|^_gat/.test(name)) {
        document.cookie = name + "=; Max-Age=0; Path=/";
        document.cookie = name + "=; Max-Age=0; Path=/; Domain=." + location.hostname.replace(/^www\./, "");
      }
    });
  }
  function post(action, data) {
    try {
      return fetch("/api.php?action=" + action, { method: "POST", credentials: "same-origin", keepalive: true, headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) }).catch(function () {});
    } catch (e) { return null; }
  }

  var state = readCookie();

  /* ---------- Analytics (only with consent) ---------- */
  function device() { var w = window.innerWidth; return w < 640 ? "mobile" : w < 1024 ? "tablet" : "desktop"; }
  function track(event, extra) {
    if (!state || !state.analytics) return;
    var q = new URLSearchParams(location.search);
    var ref = "";
    try { if (document.referrer) { var h = new URL(document.referrer).hostname; if (h !== location.hostname) ref = h; } } catch (e) { /* */ }
    post("track", Object.assign({ ref: state.ref, event: event || "pageview", path: location.pathname.slice(0, 200), referrer: ref,
      utm_source: q.get("utm_source") || "", utm_medium: q.get("utm_medium") || "", utm_campaign: q.get("utm_campaign") || "",
      lang: document.documentElement.lang.slice(0, 2), device: device() }, extra || {}));
    if (window.gtag && event && event !== "pageview") window.gtag("event", event, extra || {});
  }
  function loadGA() {
    if (!gaId || !state || !state.analytics || window.gtag) return;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag("consent", "default", { analytics_storage: "granted", ad_storage: state.marketing ? "granted" : "denied", ad_user_data: state.marketing ? "granted" : "denied", ad_personalization: "denied" });
    window.gtag("js", new Date());
    window.gtag("config", gaId, { anonymize_ip: true });
    var s = document.createElement("script"); s.async = true; s.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(gaId);
    document.head.appendChild(s);
  }

  /* ---------- Banner ---------- */
  var box = null;
  function build() {
    box = document.createElement("div");
    box.className = "consent"; box.setAttribute("role", "dialog"); box.setAttribute("aria-live", "polite"); box.setAttribute("aria-label", t("cTitle"));
    box.innerHTML =
      '<h2></h2><p class="c-text"></p>' +
      '<div class="c-opts" hidden>' +
        '<label class="opt"><input type="checkbox" checked disabled><span><b class="c-nec"></b><small class="c-necd"></small></span></label>' +
        '<label class="opt"><input type="checkbox" id="cAna"><span><b class="c-ana"></b><small class="c-anad"></small></span></label>' +
        '<label class="opt"><input type="checkbox" id="cMkt"><span><b class="c-mkt"></b><small class="c-mktd"></small></span></label>' +
      '</div>' +
      '<div class="row"><button type="button" class="btn btn-ghost c-reject"></button><button type="button" class="btn btn-ghost c-choose"></button><button type="button" class="btn btn-primary c-accept"></button></div>' +
      '<a class="c-policy" href="/cookie-policy"></a>';
    var set = function (sel, key) { box.querySelector(sel).textContent = t(key); };
    set("h2", "cTitle"); set(".c-text", "cText"); set(".c-nec", "cNecessary"); set(".c-necd", "cNecessaryD"); set(".c-ana", "cAnalytics"); set(".c-anad", "cAnalyticsD");
    set(".c-mkt", "cMarketing"); set(".c-mktd", "cMarketingD"); set(".c-reject", "cReject"); set(".c-choose", "cChoose"); set(".c-accept", "cAccept"); set(".c-policy", "cookiesPolicy");
    box.querySelector(".c-accept").addEventListener("click", function () { decide(true, true); });
    box.querySelector(".c-reject").addEventListener("click", function () { decide(false, false); });
    box.querySelector(".c-choose").addEventListener("click", function () {
      var opts = box.querySelector(".c-opts");
      if (opts.hidden) { opts.hidden = false; this.textContent = t("cSave"); box.querySelector("#cAna").checked = !!(state && state.analytics); box.querySelector("#cMkt").checked = !!(state && state.marketing); }
      else decide(box.querySelector("#cAna").checked, box.querySelector("#cMkt").checked);
    });
    document.body.appendChild(box);
  }
  function open() { if (!box) build(); box.hidden = false; var b = box.querySelector(".c-accept"); if (b) b.focus(); }
  function decide(analytics, marketing) {
    var hadAnalytics = !!(state && state.analytics);
    state = { analytics: analytics, marketing: marketing, ref: (state && state.ref) || randomRef() };
    writeCookie(state);
    post("consent_log", { ref: state.ref, analytics: analytics ? 1 : 0, marketing: marketing ? 1 : 0 });
    if (box) box.hidden = true;
    if (!analytics) { clearOptionalCookies(); if (hadAnalytics && window.gtag) location.reload(); return; }
    loadGA(); track("pageview");
  }

  /* ---------- Start ---------- */
  window.msConsent = { open: open, track: track, get: function () { return state; } };
  document.addEventListener("click", function (e) { if (e.target.closest("[data-cookie-settings]")) { e.preventDefault(); open(); } });
  function start() {
    if (!state) open();
    else { loadGA(); track("pageview"); }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();
