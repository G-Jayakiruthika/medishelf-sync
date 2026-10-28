<?php
/* Home page view. Vars: $p (prices), $samples, $faq */
$icons = [
    'scan' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg>',
    'family' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M14.5 14.6c.8-.4 1.6-.6 2.5-.6 2.2 0 3.8 1.4 4.2 4"/></svg>',
    'bell' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15zM10 20.5a2 2 0 0 0 4 0"/></svg>',
];
$handles = ['var(--ok)', 'var(--warn)', 'var(--bad)', 'var(--ai-2)', 'var(--ok)', 'var(--warn)'];
$tags = ['Expiry', 'Reminders', 'Notebook', 'Voice', 'Official data', 'Privacy'];
$trust = array_map('trim', explode('·', st('heroTrust')));
?>
<section class="hero dots">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <span class="eyebrow"><?= e(st('heroEyebrow')) ?></span>
      <h1><?= e(st('heroTitle')) ?></h1>
      <p class="lead"><?= e(st('heroSub')) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-primary btn-lg" href="/app#signup" data-track="cta_hero"><?= e(st('heroCta')) ?></a>
        <a class="btn btn-ghost btn-lg" href="#how"><?= e(st('heroSecondary')) ?></a>
      </div>
      <div class="trust"><?php foreach ($trust as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div>
    </div>
    <div class="hero-visual">
      <div class="phone"><img src="/img/app-cabinet.png" width="390" height="800" alt="The MediShelf-Sync app showing a family medicine cabinet with green, yellow and red expiry handles" fetchpriority="high"></div>
      <div class="float-chip chip-a"><small class="muted">Dolo 650</small><b>Expires in 12 days</b><span class="handle" style="background:var(--warn)"></span></div>
      <div class="float-chip chip-b"><small class="muted">Amma · BP tablets</small><b>Safe till Mar 2027</b><span class="handle" style="background:var(--ok)"></span></div>
    </div>
  </div>
</section>

<section id="how">
  <div class="wrap">
    <div class="section-head center"><span class="eyebrow">3 steps</span><h2><?= e(st('howTitle')) ?></h2></div>
    <div class="steps">
      <?php foreach ([['scan', 1], ['family', 2], ['bell', 3]] as [$ic, $n]): ?>
      <div class="step"><span class="ic"><?= $icons[$ic] ?></span><h3><?= e(st("step{$n}T")) ?></h3><p class="muted"><?= e(st("step{$n}D")) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="features" class="band">
  <div class="wrap">
    <div class="section-head"><span class="eyebrow">Features</span><h2><?= e(st('featTitle')) ?></h2></div>
    <div class="drawers">
      <?php for ($i = 1; $i <= 6; $i++): ?>
      <div class="drawer" style="--h:<?= $handles[$i - 1] ?>"><span class="tag"><?= e($tags[$i - 1]) ?></span><h3><?= e(st("f{$i}T")) ?></h3><p class="muted"><?= e(st("f{$i}D")) ?></p></div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="section-head center"><h2><?= e(st('langsTitle')) ?></h2></div>
    <div class="langs"><?php foreach (SITE_LANGS as $code => [$name]): ?><a href="<?= e(url('', $code)) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"><?= e($name) ?></a><?php endforeach; ?></div>
  </div>
</section>

<section class="band">
  <div class="wrap split">
    <div class="section-head" style="margin:0">
      <span class="eyebrow">Jan Aushadhi</span>
      <h2><?= e(st('saveTitle')) ?></h2>
      <p class="muted"><?= e(st('saveDesc')) ?></p>
      <form class="search-box" action="/medicines" method="get" role="search">
        <label class="skip" for="homeSearch">Search medicines</label>
        <input id="homeSearch" name="q" type="search" placeholder="Paracetamol, Metformin, Dolo…" autocomplete="off">
        <button class="btn btn-primary btn-sm" type="submit"><?= e(st('saveCta')) ?></button>
      </form>
    </div>
    <div class="sample-list">
      <?php foreach ($samples as $s): ?>
      <a href="/medicines/<?= e($s['slug']) ?>"><span><b><?= e($s['generic_name']) ?></b><br><small class="muted"><?= e($s['ingredient']) ?></small></span><span class="pill ja">Generic</span></a>
      <?php endforeach; ?>
      <a href="/medicines"><span><b>Browse all medicines</b></span><span aria-hidden="true">→</span></a>
    </div>
  </div>
</section>

<section id="pricing">
  <div class="wrap">
    <div class="section-head center"><span class="eyebrow"><?= e(st('navPricing')) ?></span><h2><?= e(st('pricingTitle')) ?></h2><p class="muted"><?= e(st('pricingSub')) ?></p></div>
    <?php require __DIR__ . '/_plans.php'; ?>
  </div>
</section>

<section class="band">
  <div class="wrap">
    <div class="section-head center"><h2><?= e(st('faqTitle')) ?></h2></div>
    <div class="faq">
      <?php foreach ($faq as [$q, $a]): ?><details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="cta-band">
      <h2><?= e(st('ctaTitle')) ?></h2>
      <p><?= e(st('ctaSub')) ?></p>
      <a class="btn btn-primary btn-lg" href="/app#signup" data-track="cta_bottom"><?= e(st('heroCta')) ?></a>
    </div>
  </div>
</section>
