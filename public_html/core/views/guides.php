<div class="wrap" style="padding-bottom:72px">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><span>Guides</span></nav>
    <h1>Medicine safety guides</h1>
    <p class="muted">Short, practical reads for Indian families. Written in plain language, with sources.</p>
  </header>
  <div class="articles">
    <?php foreach ($list as $slug => $g): ?>
    <a class="article-card" href="/guides/<?= e($slug) ?>">
      <span class="eyebrow"><?= e($g['tag']) ?> · <?= (int) $g['minutes'] ?> min read</span>
      <h2 style="font-size:1.3rem"><?= e($g['title']) ?></h2>
      <p class="muted"><?= e($g['desc']) ?></p>
    </a>
    <?php endforeach; ?>
  </div>
</div>
