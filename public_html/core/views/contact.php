<div class="wrap">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><span>Contact</span></nav>
    <h1>Contact us</h1>
    <p class="muted">Questions, billing, privacy requests or ideas. We read every message and reply within 2 working days.</p>
  </header>
  <div class="content-grid">
    <form class="form card" id="contactForm" novalidate>
      <div class="row">
        <div class="field"><label for="cName">Your name</label><input id="cName" name="name" required maxlength="80" autocomplete="name"></div>
        <div class="field"><label for="cEmail">E-mail</label><input id="cEmail" name="email" type="email" required maxlength="160" autocomplete="email"></div>
      </div>
      <div class="field"><label for="cTopic">Topic</label>
        <select id="cTopic" name="topic"><option value="help">Help using the app</option><option value="billing">Payment or refund</option><option value="privacy">Privacy or data request</option><option value="partnership">Partnership or business</option><option value="other">Something else</option></select></div>
      <div class="field"><label for="cMsg">Message</label><textarea id="cMsg" name="message" rows="6" required minlength="10" maxlength="4000"></textarea></div>
      <div class="field" style="position:absolute;left:-9999px" aria-hidden="true"><label for="cWeb">Leave this empty</label><input id="cWeb" name="website" tabindex="-1" autocomplete="off"></div>
      <p class="muted" style="font-size:.88rem">We use your name and e-mail only to reply. See the <a href="/privacy">privacy policy</a>.</p>
      <div><button class="btn btn-primary" type="submit">Send message</button></div>
      <p id="contactResult" class="alert" role="status" hidden></p>
    </form>
    <aside class="side">
      <div class="card"><h3>E-mail</h3><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php if ($phone): ?><h3>Phone</h3><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?></div>
      <div class="card"><h3>Address</h3><p><?= e($owner) ?><br><?= e($addr) ?></p></div>
      <div class="card"><h3>Medical emergency?</h3><p class="muted">We cannot give medical advice. Call <b>112</b>, or the PvPI side-effect helpline <b>1800-180-3024</b>.</p></div>
    </aside>
  </div>
</div>
