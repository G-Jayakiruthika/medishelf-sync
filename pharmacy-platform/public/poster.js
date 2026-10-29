/* Printable QR poster: draws the shop's QR (its public page address) and wires the Print button. */
(function () {
  "use strict";
  const p = document.querySelector("[data-qr]"), cv = document.getElementById("qr");
  if (p && cv && window.qrcode) {
    const q = window.qrcode(0, "Q"); q.addData(p.dataset.qr); q.make();
    const n = q.getModuleCount(), size = cv.width, k = Math.floor(size / (n + 4)), off = Math.floor((size - k * n) / 2), c = cv.getContext("2d");
    c.fillStyle = "#fff"; c.fillRect(0, 0, size, size); c.fillStyle = "#000";
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect(off + col * k, off + r * k, k, k);
  }
  document.querySelectorAll("[data-print]").forEach(b => b.addEventListener("click", () => window.print()));
})();
