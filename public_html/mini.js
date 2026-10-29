/* Tiny helpers for server-made pages (the security policy blocks inline scripts):
   [data-print] buttons print the page; the "tap to tick" page posts and shows the answer. */
(function () {
  "use strict";
  document.querySelectorAll("[data-print]").forEach(function (b) { b.addEventListener("click", function () { window.print(); }); });
  var b = document.getElementById("tickBtn"), m = document.getElementById("tickMsg");
  if (!b) return;
  function go() {
    b.disabled = true; m.textContent = "…";
    fetch(location.pathname, { method: "POST" }).then(function (r) { return r.json(); })
      .then(function (d) { m.textContent = d.message || "Done"; b.textContent = "✓"; if (navigator.vibrate) navigator.vibrate(120); })
      .catch(function () { m.textContent = "No connection. Try again."; b.disabled = false; });
  }
  b.addEventListener("click", go);
  if (/[?&]auto=1/.test(location.search)) go();
})();
