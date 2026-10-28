<div class="wrap">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/ingredients">Ingredient guides</a><span aria-hidden="true">›</span><span><?= e($i['ingredient']) ?></span></nav>
    <div><span class="pill <?= $i['rx'] ? 'rx' : '' ?>"><?= $i['rx'] ? 'Prescription (Rx)' : 'Usually over the counter' ?></span></div>
    <h1><?= e($i['ingredient']) ?></h1>
    <p class="muted" style="font-size:1.1rem"><?= e($i['use_text']) ?>.</p>
  </header>
  <div class="content-grid">
    <article class="prose">
      <?php if ($i['note']): ?><p class="note"><b>Safety note:</b> <?= e($i['note']) ?></p><?php endif; ?>
      <h2>Storage</h2><p><?= e($i['storage'] ?: 'Below 30 °C, in a dry place away from sunlight') ?>. Indian summers can push room temperature above 40 °C, so keep medicines away from windows, kitchens and car dashboards.</p>
      <h2>Common questions</h2>
      <div class="faq" style="margin:0"><?php foreach ($faq as [$q, $a]): ?><details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
      <?php if ($meds): ?>
      <h2>Jan Aushadhi generics with <?= e($i['ingredient']) ?> (<?= count($meds) ?>)</h2>
      <ul class="med-list"><?php foreach ($meds as $r): ?><li><a href="/medicines/<?= e($r['slug']) ?>"><b style="font-weight:600"><?= e($r['generic_name']) ?></b><?php if ($r['unit_size']): ?><small><?= e($r['unit_size']) ?></small><?php endif; ?></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <p class="muted" style="font-size:.88rem">General information, not medical advice. Ask your doctor or pharmacist before starting, stopping or combining medicines. Side effects can be reported to the PvPI helpline 1800-180-3024.</p>
    </article>
    <aside class="side">
      <div class="card" style="border:2px solid var(--ai)"><h3>Never miss an expiry</h3><p class="muted">Keep every <?= e($i['ingredient']) ?> strip in your family cabinet. Free, in 10 languages.</p><a class="btn btn-primary" href="/app#signup" data-track="cta_ingredient">Start free</a></div>
      <div class="card"><h3>More guides</h3><?php foreach ($others as $o): ?><a href="/ingredients/<?= e($o['slug']) ?>"><?= e($o['ingredient']) ?></a><?php endforeach; ?></div>
    </aside>
  </div>
</div>
