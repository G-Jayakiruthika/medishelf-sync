<?php /* Plan cards, shared by the home and pricing pages. Needs $p = prices(). */ ?>
<div class="plans">
  <div class="plan">
    <h3><?= e(st('freeName')) ?></h3>
    <div><span class="price">₹0</span> <span class="per"><?= e(st('freePer')) ?></span></div>
    <ul><?php for ($i = 1; $i <= 4; $i++): ?><li><?= e(st("freeF$i")) ?></li><?php endfor; ?></ul>
    <a class="btn btn-ghost" href="/app#signup" data-track="plan_free"><?= e(st('freeCta')) ?></a>
  </div>
  <div class="plan featured">
    <span class="badge"><?= e(st('popular')) ?></span>
    <h3><?= e(st('premName')) ?></h3>
    <div><span class="price"><?= e(money($p['month'])) ?></span> <span class="per"><?= e(st('premPer')) ?></span></div>
    <p class="muted"><?= e(st('premYear', ['price' => number_format($p['year']), 'save' => $p['save']])) ?></p>
    <ul><?php for ($i = 1; $i <= 4; $i++): ?><li><?= e(st("premF$i")) ?></li><?php endfor; ?></ul>
    <a class="btn btn-primary" href="/app#upgrade" data-track="plan_premium"><?= e(st('premCta')) ?></a>
  </div>
</div>
