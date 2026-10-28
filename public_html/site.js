/* Public website behaviour: mobile menu, print button. */
(function () {
  "use strict";
  var btn = document.getElementById("menuBtn"), links = document.getElementById("navLinks");
  if (btn && links) {
    btn.addEventListener("click", function () {
      var open = links.classList.toggle("open");
      btn.setAttribute("aria-expanded", String(open));
    });
    links.addEventListener("click", function (e) { if (e.target.closest("a")) { links.classList.remove("open"); btn.setAttribute("aria-expanded", "false"); } });
  }
  document.addEventListener("click", function (e) {
    var p = e.target.closest("[data-print]");
    if (p) window.print();
    var lp = document.querySelector(".lang-pick[open]");
    if (lp && !e.target.closest(".lang-pick")) lp.removeAttribute("open");
  });
  // Contact form → JSON API
  var form = document.getElementById("contactForm");
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var out = document.getElementById("contactResult"), b = form.querySelector("button[type=submit]");
      b.disabled = true;
      fetch("/api.php?action=session", { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (s) {
        return fetch("/api.php?action=contact_send", {
          method: "POST", credentials: "same-origin",
          headers: { "Content-Type": "application/json", "X-CSRF-Token": s.csrf },
          body: JSON.stringify({ name: form.name.value, email: form.email.value, topic: form.topic.value, message: form.message.value, website: form.website.value })
        });
      }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (res) {
        out.hidden = false;
        out.className = "alert " + (res.ok ? "ok" : "bad");
        out.textContent = res.ok ? "Thank you. We have your message and will reply by e-mail within 2 working days." : (res.d.message || "Could not send. Please e-mail us instead.");
        if (res.ok) form.reset();
        b.disabled = false;
      }).catch(function () { out.hidden = false; out.className = "alert bad"; out.textContent = "You seem to be offline. Please try again."; b.disabled = false; });
    });
  }
})();
/* Count clicks on important buttons (only sent if the visitor accepted analytics; see consent.js). */
document.addEventListener("click", function (e) {
  var el = e.target.closest("[data-track]");
  if (el && window.msConsent) window.msConsent.track(el.getAttribute("data-track"));
});
document.addEventListener("change", function (e) {
  if (e.target.matches("[data-autosubmit]") && e.target.form) e.target.form.submit();
});
/* Hero cabinet follows the pointer a little (desktop only, never with reduced motion). */
(function () {
  var cab = document.getElementById("cabinet"), hero = cab && cab.closest(".hero3d");
  if (!cab || !window.matchMedia("(hover: hover) and (pointer: fine)").matches || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var raf = 0;
  hero.addEventListener("pointermove", function (e) {
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = 0;
      var r = hero.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      cab.style.setProperty("--ry", (-30 + x * 22).toFixed(1) + "deg");
      cab.style.setProperty("--rx", (-15 - y * 12).toFixed(1) + "deg");
    });
  });
  hero.addEventListener("pointerleave", function () { cab.style.removeProperty("--ry"); cab.style.removeProperty("--rx"); });
})();
