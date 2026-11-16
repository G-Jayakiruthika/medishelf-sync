<div class="wrap">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/medicines">Medicines</a><span aria-hidden="true">›</span><span><?= e(mb_strimwidth($m['generic_name'], 0, 60, '…')) ?></span></nav>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><span class="pill ja">Jan Aushadhi generic</span><?php if ($hasGuide): ?><span class="pill <?= $m['rx'] ? 'rx' : '' ?>"><?= $m['rx'] ? 'Prescription (Rx)' : 'Over the counter' ?></span><?php endif; ?></div>
    <h1 style="font-size:clamp(1.7rem,1.2rem + 2vw,2.6rem)"><?= e($m['generic_name']) ?></h1>
    <?php if ($hasGuide): ?><p class="muted" style="font-size:1.1rem"><?= e($m['use_text']) ?>.</p><?php endif; ?>
  </header>
  <div class="content-grid">
    <article class="prose">
      <div class="facts">
        <?php if ($hasGuide): ?><div class="fact"><span>Ingredient</span><b><a href="/ingredients/<?= e($m['info_slug']) ?>"><?= e($m['ingredient']) ?></a></b></div><?php endif; ?>
        <?php if ($m['unit_size']): ?><div class="fact"><span>Pack size</span><b><?= e($m['unit_size']) ?></b></div><?php endif; ?>
        <?php if ($m['dosage_form']): ?><div class="fact"><span>Form</span><b><?= e(ucfirst($m['dosage_form'])) ?></b></div><?php endif; ?>
        <?php if ($m['drug_code']): ?><div class="fact"><span>PMBJP code</span><b><?= e($m['drug_code']) ?></b></div><?php endif; ?>
      </div>
      <?php if ($hasGuide): ?>
      <h2>What it is used for</h2><p><?= e($m['use_text']) ?>. Use it only as your doctor or the label says.</p>
      <?php if ($m['note']): ?><p class="note"><b>Safety note:</b> <?= e($m['note']) ?></p><?php endif; ?>
      <h2>How to store it</h2><p><?= e($m['storage'] ?: 'Below 30 °C, in a dry place away from sunlight') ?>. Keep it in the original pack so the batch number and expiry date stay readable, and out of reach of children.</p>
      <?php else: ?>
      <h2>About this medicine</h2><p>This is a generic medicine from the Pradhan Mantri Bhartiya Janaushadhi Pariyojana (PMBJP) list, sold at Jan Aushadhi Kendras across India. Ask your doctor or pharmacist whether it suits you.</p>
      <h2>How to store it</h2><p>Follow the storage line printed on the pack. Most tablets and capsules should be kept below 30 °C in a dry place, away from sunlight, in the original strip.</p>
      <?php endif; ?>
      <h2>How to check its expiry</h2>
      <p>Look for <b>Exp.</b> or <b>Expiry</b> on the strip or bottle, usually next to <b>B.No.</b> (batch) and <b>Mfg.</b> (manufacturing date). A date like <b>03/2027</b> means the medicine is fine until the <b>end of March 2027</b>. Do not use it after that.</p>
      <p>Opened syrups, eye drops and creams often expire sooner than the printed date, typically 4 weeks after opening for eye drops. Check the label.</p>
      <h2>Buying it</h2>
      <p>Jan Aushadhi Kendras sell this generic for much less than most branded versions. Use the official Janaushadhi Sugam app or ask at your nearest Kendra for the current price.</p>
      <p class="muted" style="font-size:.88rem">General information from public sources, not medical advice. Always follow your doctor and the printed label.</p>
    </article>
    <aside class="side">
      <div class="card" style="border:2px solid var(--ai)">
        <h3>Track this medicine's expiry</h3>
        <p class="muted">Scan the strip once. We remind you before it expires, in your language.</p>
        <a class="btn btn-primary" href="/app#signup" data-track="cta_medicine">Start free</a>
      </div>
      <?php if ($related): ?>
      <div class="card"><h3>Other <?= e($m['ingredient']) ?> products</h3>
        <ul class="med-list" style="grid-template-columns:1fr"><?php foreach ($related as $r): ?><li><a href="/medicines/<?= e($r['slug']) ?>"><b style="font-weight:600;font-size:.94rem"><?= e($r['generic_name']) ?></b><?php if ($r['unit_size']): ?><small><?= e($r['unit_size']) ?></small><?php endif; ?></a></li><?php endforeach; ?></ul>
      </div>
      <?php endif; ?>
      <div class="card"><h3>Read next</h3><a href="/guides/how-to-read-expiry-date-on-medicine">How to read the expiry date</a><a href="/guides/how-to-dispose-of-expired-medicines-in-india">How to throw away old medicines</a></div>
    </aside>
  </div>
</div>
