/* Shop page (/s/{code}): pay any amount by UPI. On a phone the button opens the UPI app; on a computer a QR appears to scan. */
(function () {
  "use strict";
  const box = document.getElementById("payBox"); if (!box) return;
  const amt = document.getElementById("amt"), btn = document.getElementById("payBtn"), cv = document.getElementById("payQr");
  function link() {
    const a = Number((amt.value || "").replace(/[^\d.]/g, ""));
    return "upi://pay?pa=" + encodeURIComponent(box.dataset.vpa) + "&pn=" + encodeURIComponent(box.dataset.name) + (a > 0 ? "&am=" + a.toFixed(2) : "") + "&cu=INR&tn=" + encodeURIComponent("AushadhBox");
  }
  function draw(text) {
    if (!window.qrcode) return;
    const q = window.qrcode(0, "M"); q.addData(text); q.make();
    const n = q.getModuleCount(), size = cv.width, k = Math.floor(size / (n + 4)), off = Math.floor((size - k * n) / 2), c = cv.getContext("2d");
    c.fillStyle = "#fff"; c.fillRect(0, 0, size, size); c.fillStyle = "#000";
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) c.fillRect(off + col * k, off + r * k, k, k);
    cv.hidden = false;
  }
  btn.addEventListener("click", () => { const l = link(); btn.href = l; draw(l); });
})();
