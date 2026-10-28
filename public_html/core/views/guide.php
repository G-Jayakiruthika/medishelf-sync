<div class="wrap">
  <header class="page-head">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><span aria-hidden="true">›</span><a href="/guides">Guides</a><span aria-hidden="true">›</span><span><?= e($g['tag']) ?></span></nav>
    <span class="eyebrow"><?= e($g['tag']) ?> · <?= (int) $g['minutes'] ?> min read · <time datetime="<?= e($g['date']) ?>"><?= e(date('j M Y', strtotime($g['date']))) ?></time></span>
    <h1 style="font-size:clamp(1.8rem,1.2rem + 2.4vw,3rem);max-width:22ch"><?= e($g['title']) ?></h1>
    <p class="muted" style="font-size:1.1rem;max-width:60ch"><?= e($g['desc']) ?></p>
  </header>
  <div class="content-grid">
    <article class="prose"><?= $html ?></article>
    <aside class="side">
      <div class="card" style="border:2px solid var(--ai)"><h3>Your family cabinet, in your language</h3><p class="muted">Scan medicines, get expiry alerts and dose reminders. Free.</p><a class="btn btn-primary" href="/app#signup" data-track="cta_guide">Start free</a></div>
      <div class="card"><h3>More guides</h3><?php foreach ($more as $s => $m): ?><a href="/guides/<?= e($s) ?>"><?= e($m['title']) ?></a><?php endforeach; ?></div>
    </aside>
  </div>
</div>
