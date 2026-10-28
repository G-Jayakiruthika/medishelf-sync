<div class="wrap" style="padding-bottom:72px">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><span>Medicines</span></nav>
    <h1>Jan Aushadhi medicine list</h1>
    <p class="muted"><?= number_format($all) ?> quality generic medicines sold at Pradhan Mantri Bhartiya Janaushadhi Kendras. Search by name or ingredient, or browse by letter. Also see the <a href="/ingredients">ingredient guides</a>.</p>
    <form class="search-box" action="/medicines" method="get" role="search" style="max-width:560px">
      <label class="skip" for="medSearch">Search medicines</label>
      <input id="medSearch" name="q" type="search" value="<?= e($qText) ?>" placeholder="Paracetamol, Metformin, Amlodipine…">
      <button class="btn btn-primary btn-sm" type="submit">Search</button>
    </form>
    <nav class="letters" aria-label="Browse by letter">
      <?php foreach (array_merge(range('A', 'Z'), ['#']) as $L): ?><a href="/medicines?letter=<?= urlencode($L) ?>"<?= $L === $letter ? ' aria-current="true"' : '' ?>><?= $L ?></a><?php endforeach; ?>
    </nav>
  </header>
  <?php if ($qText !== ''): ?><p><b><?= number_format($total) ?></b> results for “<?= e($qText) ?>”. <a href="/medicines">Clear</a></p><?php endif; ?>
  <?php if (!$rows): ?>
    <div class="card"><h2>No medicines found</h2><p class="muted">Try a shorter name, or the ingredient (for example “paracetamol” instead of a brand name).</p></div>
  <?php else: ?>
  <ul class="med-list">
    <?php foreach ($rows as $r): ?><li><a href="/medicines/<?= e($r['slug']) ?>"><b><?= e($r['generic_name']) ?></b><small><?= e(implode(' · ', array_filter([$r['ingredient'], $r['unit_size']]))) ?></small></a></li><?php endforeach; ?>
  </ul>
  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php if ($pg > 1): ?><a class="btn btn-ghost btn-sm" rel="prev" href="<?= e($qs($pg - 1)) ?>">← Previous</a><?php endif; ?>
    <span class="muted">Page <?= $pg ?> of <?= $pages ?></span>
    <?php if ($pg < $pages): ?><a class="btn btn-ghost btn-sm" rel="next" href="<?= e($qs($pg + 1)) ?>">Next →</a><?php endif; ?>
  </nav>
  <?php endif; endif; ?>
  <p class="muted" style="margin-top:28px;font-size:.88rem">Source: PMBJP product list, December 2025. Prices and availability vary by Kendra. This list is for information and is not medical advice.</p>
</div>
