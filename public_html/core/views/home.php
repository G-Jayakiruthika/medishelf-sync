<?php
/* Home page view. Vars: $p (prices), $samples, $faq
   The hero is a 3D medicine cabinet (kusuri-dansu) built with CSS 3D transforms: no WebGL, no library.
   Its drawers slide out once on load; it tilts with the pointer on desktop. Both are skipped for reduced motion. */
$icons = [
    'scan' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg>',
    'family' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5M14.5 14.6c.8-.4 1.6-.6 2.5-.6 2.2 0 3.8 1.4 4.2 4"/></svg>',
    'bell' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15zM10 20.5a2 2 0 0 0 4 0"/></svg>',
];
$handles = ['var(--ok)', 'var(--ai-2)', 'var(--ai-2)', 'var(--warn)', 'var(--ok)', 'var(--ai-2)'];
$trust = array_map('trim', explode('·', st('heroTrust')));
// The nine drawers of the hero cabinet: label, status, how far it slides out (px), whether it carries the floating tag
$drawers = [
    ['Paracetamol', 'ok', 48, false], ['Telmisartan', 'ok', 0, false], ['Metformin', 'ok', 0, false],
    ['Cetirizine', 'ok', 0, false], ['Dolo 650', 'warn', 92, true], ['Vitamin D3', 'ok', 0, false],
    ['Pantoprazole', 'ok', 0, false], ['Amlodipine', 'ok', 0, false], ['Cough syrup', 'bad', 62, false],
];
?>
<section class="hero3d">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <h1><?= e(st('heroTitle')) ?></h1>
      <p class="lead"><?= e(st('heroSub')) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-light btn-lg" href="/app#signup" data-track="cta_hero"><?= e(st('heroCta')) ?></a>
        <a class="btn btn-outline-light btn-lg" href="#how"><?= e(st('heroSecondary')) ?></a>
      </div>
      <ul class="trust"><?php foreach ($trust as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
    </div>

    <div class="stage" id="stage" role="img" aria-label="A wooden medicine cabinet with nine drawers. The Dolo 650 drawer is open with an amber handle: it expires in 12 days. The cough syrup drawer has a red handle: expired.">
      <div class="cabinet" id="cabinet">
        <div class="cab-face cab-back"></div>
        <div class="cab-face cab-left"></div>
        <div class="cab-face cab-right"></div>
        <div class="cab-face cab-top"></div>
        <div class="cab-face cab-bottom"></div>
        <div class="cab-face cab-front"><?php for ($i = 0; $i < 9; $i++): ?><i class="hole" style="--c:<?= $i % 3 ?>;--r:<?= intdiv($i, 3) ?>"></i><?php endfor; ?></div>
        <?php foreach ($drawers as $i => [$label, $status, $out, $tag]): ?>
        <div class="dr" style="--c:<?= $i % 3 ?>;--r:<?= intdiv($i, 3) ?>;--out:<?= $out ?>px;--i:<?= $i ?>">
          <span class="dr-floor"><?php if ($out): ?><i class="strip strip-<?= $status ?>"></i><?php endif; ?></span>
          <span class="dr-side dr-l"></span><span class="dr-side dr-r"></span>
          <span class="dr-front"><span class="dr-label"><?= e($label) ?></span><span class="dr-handle h-<?= $status ?>"></span></span>
          <?php if ($tag): ?><span class="tag3d"><b>Dolo 650</b><span>Expires in 12 days</span></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="floor-shadow"></div>
    </div>
  </div>
</section>

<section id="how">
  <div class="wrap">
    <div class="section-head center"><h2><?= e(st('howTitle')) ?></h2></div>
    <canvas class="scene3d hero-3d" data-model="/assets/3d/hero.glb" aria-hidden="true"></canvas>
    <ol class="steps">
      <?php foreach ([['scan', 1], ['family', 2], ['bell', 3]] as [$ic, $n]): ?>
      <li class="step"><span class="ic"><?= $icons[$ic] ?></span><h3><?= e(st("step{$n}T")) ?></h3><p class="muted"><?= e(st("step{$n}D")) ?></p></li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section id="features" class="band">
  <div class="wrap">
    <div class="section-head"><h2><?= e(st('featTitle')) ?></h2></div>
    <div class="drawers">
      <?php for ($i = 1; $i <= 6; $i++): ?>
      <div class="drawer" style="--h:<?= $handles[$i - 1] ?>"><h3><?= e(st("f{$i}T")) ?></h3><p class="muted"><?= e(st("f{$i}D")) ?></p></div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section class="showcase">
  <div class="wrap split">
    <div class="phone-wrap">
      <div class="phone"><img src="/img/app-cabinet.png" width="390" height="800" loading="lazy" alt="The AushadhBox app on a phone, showing a family's medicines with green, amber and red status"></div>
    </div>
    <div class="section-head" style="margin:0">
      <h2><?= e(st('langsTitle')) ?></h2>
      <div class="langs"><?php foreach (SITE_LANGS as $code => [$name]): ?><a href="<?= e(url('', $code)) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"<?= $code === site_lang() ? ' aria-current="true"' : '' ?>><?= e($name) ?></a><?php endforeach; ?></div>
    </div>
  </div>
</section>

<section class="band">
  <div class="wrap split">
    <div class="section-head" style="margin:0">
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
      <a href="/medicines"><span><b>Browse all medicines</b></span></a>
    </div>
  </div>
</section>

<section id="pricing">
  <div class="wrap">
    <div class="section-head center"><h2><?= e(st('pricingTitle')) ?></h2><p class="muted"><?= e(st('pricingSub')) ?></p></div>
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
      <a class="btn btn-light btn-lg" href="/app#signup" data-track="cta_bottom"><?= e(st('heroCta')) ?></a>
    </div>
  </div>
</section>
