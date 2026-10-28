<div class="wrap" style="padding-bottom:72px">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><span>Ingredient guides</span></nav>
    <h1>Medicine ingredient guides</h1>
    <p class="muted">Plain-language guides to <?= count($rows) ?> common ingredients in Indian medicines: what each is for, how to store it and what to watch out for.</p>
  </header>
  <ul class="med-list">
    <?php foreach ($rows as $r): ?><li><a href="/ingredients/<?= e($r['slug']) ?>"><b><?= e($r['ingredient']) ?> <?php if ($r['rx']): ?><span class="pill rx" style="font-size:.7rem;padding:1px 8px">Rx</span><?php endif; ?></b><small><?= e($r['use_text']) ?><?= $r['n'] ? ' · ' . (int) $r['n'] . ' generics' : '' ?></small></a></li><?php endforeach; ?>
  </ul>
</div>
