<section class="hero dots" style="padding-bottom:40px">
  <div class="wrap">
    <div class="section-head center"><span class="eyebrow"><?= e(st('navPricing')) ?></span><h1><?= e(st('pricingTitle')) ?></h1><p class="muted"><?= e(st('pricingSub')) ?></p></div>
    <?php require __DIR__ . '/_plans.php'; ?>
  </div>
</section>
<section class="band">
  <div class="wrap narrow">
    <div class="section-head"><h2>Compare plans</h2></div>
    <div class="table-scroll">
      <table class="compare">
        <thead><tr><th scope="col">Feature</th><th scope="col"><?= e(st('freeName')) ?></th><th scope="col"><?= e(st('premName')) ?></th></tr></thead>
        <tbody><?php foreach ($rows as [$f, $a, $b]): ?><tr><th scope="row" style="text-transform:none;letter-spacing:0;color:var(--ink);font-size:1rem"><?= e($f) ?></th><td><?= e($a) ?></td><td><b><?= e($b) ?></b></td></tr><?php endforeach; ?></tbody>
      </table>
    </div>
  </div>
</section>
<section>
  <div class="wrap">
    <div class="section-head center"><h2>Billing questions</h2></div>
    <div class="faq"><?php foreach ($billingFaq as [$q, $a]): ?><details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
    <p class="muted" style="text-align:center;margin-top:24px">Prices include GST where applicable. <a href="/refund-policy">Refund policy</a> · <a href="/terms">Terms</a></p>
  </div>
</section>
