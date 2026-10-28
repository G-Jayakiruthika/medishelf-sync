<?php
/* Shared layout for every public page. Expects $content and $meta from render(). */
$lang = site_lang();
$title = $meta['title'] ?? site_name();
$desc = $meta['description'] ?? st('heroSub');
$canonical = $meta['canonical'] ?? (base_url() . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
$robots = $meta['robots'] ?? 'index, follow, max-image-preview:large';
$ogImage = $meta['og_image'] ?? (base_url() . '/img/og-default.png');
$page = $meta['page'] ?? '';
$alternates = !empty($meta['alternates']);
$navItems = [['/#features', 'navFeatures'], [url('pricing'), 'navPricing'], ['/medicines', 'navMedicines'], ['/guides', 'navGuides'], ['/help', 'navHelp']];
if ($lang !== 'en') $navItems[0][0] = '/' . $lang . '/#features';
?><!DOCTYPE html>
<html lang="<?= e(SITE_LANGS[$lang][1]) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= e($robots) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php if ($alternates): foreach (SITE_LANGS as $code => [$n, $tag]): ?>
<link rel="alternate" hreflang="<?= e($tag) ?>" href="<?= e(base_url() . url($page, $code)) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= e(base_url() . url($page, 'en')) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= e($meta['og_type'] ?? 'website') ?>">
<meta property="og:site_name" content="<?= e(site_name()) ?>">
<meta property="og:title" content="<?= e($meta['og_title'] ?? $title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:locale" content="<?= e(str_replace('-', '_', SITE_LANGS[$lang][1])) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php if (config('google_site_verification')): ?><meta name="google-site-verification" content="<?= e(config('google_site_verification')) ?>"><?php endif; ?>
<meta name="theme-color" content="#1F3358">
<link rel="icon" href="/icons/icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Noto+Sans:wght@400;500;600;700<?= $lang !== 'en' ? '&family=' . e(['hi' => 'Noto+Sans+Devanagari', 'mr' => 'Noto+Sans+Devanagari', 'bn' => 'Noto+Sans+Bengali', 'te' => 'Noto+Sans+Telugu', 'ta' => 'Noto+Sans+Tamil', 'gu' => 'Noto+Sans+Gujarati', 'kn' => 'Noto+Sans+Kannada', 'ml' => 'Noto+Sans+Malayalam', 'pa' => 'Noto+Sans+Gurmukhi'][$lang]) . ':wght@400;600;700' : '' ?>&display=swap">
<link rel="stylesheet" href="/site.css?v=4">
<?php foreach (($meta['jsonld'] ?? []) as $ld) echo jsonld($ld), "\n"; ?>
</head>
<body class="page-<?= e($meta['body_class'] ?? 'default') ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="nav" id="top">
  <div class="wrap nav-inner">
    <a class="logo" href="<?= e(url('')) ?>" aria-label="<?= e(site_name()) ?> home">
      <svg width="34" height="34" viewBox="0 0 40 40" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="9" fill="#1F3358"/><g fill="#fff"><rect x="8" y="9" width="11" height="9" rx="2"/><rect x="21" y="9" width="11" height="9" rx="2"/><rect x="8" y="21" width="11" height="9" rx="2"/><rect x="21" y="21" width="11" height="9" rx="2"/></g><rect x="25" y="26" width="3" height="2" rx="1" fill="#E7B24E"/><rect x="12" y="14" width="3" height="2" rx="1" fill="#78C084"/></svg>
      <span><?= e(site_name()) ?></span>
    </a>
    <button class="menu-btn" id="menuBtn" aria-expanded="false" aria-controls="navLinks" aria-label="Menu"><span></span><span></span><span></span></button>
    <nav class="nav-links" id="navLinks" aria-label="Main">
      <?php foreach ($navItems as [$href, $key]): ?><a href="<?= e($href) ?>"<?= ($meta['nav'] ?? '') === $key ? ' aria-current="page"' : '' ?>><?= e(st($key)) ?></a><?php endforeach; ?>
      <details class="lang-pick">
        <summary aria-label="Language"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h9M8.5 3v2M6 5c.5 3 2.5 6 6 8M11 5c-.8 3.5-3.5 7-7 9"/><path d="M13 21l4-9 4 9M14.5 18h5"/></svg><?= e(SITE_LANGS[$lang][0]) ?></summary>
        <div class="lang-menu">
          <?php foreach (SITE_LANGS as $code => [$n]): ?><a href="<?= e(url(in_array($page, LOCALIZED_PAGES, true) ? $page : '', $code)) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><?= e($n) ?></a><?php endforeach; ?>
        </div>
      </details>
      <a class="btn btn-ghost btn-sm" href="/app#signin"><?= e(st('signIn')) ?></a>
      <a class="btn btn-primary btn-sm" href="/app#signup"><?= e(st('startFree')) ?></a>
    </nav>
  </div>
</header>

<main id="main">
<?= $content ?>
</main>

<footer class="footer">
  <div class="wrap foot-grid">
    <div class="foot-brand">
      <a class="logo" href="<?= e(url('')) ?>"><svg width="30" height="30" viewBox="0 0 40 40" aria-hidden="true"><rect x="2" y="2" width="36" height="36" rx="9" fill="#fff"/><g fill="#1F3358"><rect x="8" y="9" width="11" height="9" rx="2"/><rect x="21" y="9" width="11" height="9" rx="2"/><rect x="8" y="21" width="11" height="9" rx="2"/><rect x="21" y="21" width="11" height="9" rx="2"/></g></svg><span><?= e(site_name()) ?></span></a>
      <p><?= e(st('disclaimer')) ?></p>
      <p class="foot-small">PvPI side-effect helpline: 1800-180-3024 · Emergency: 112</p>
    </div>
    <div><h2><?= e(st('footProduct')) ?></h2><a href="<?= e(url('')) ?>#features"><?= e(st('navFeatures')) ?></a><a href="<?= e(url('pricing')) ?>"><?= e(st('navPricing')) ?></a><a href="/medicines"><?= e(st('navMedicines')) ?></a><a href="/ingredients">Ingredient guides</a><a href="/guides"><?= e(st('navGuides')) ?></a></div>
    <div><h2><?= e(st('footCompany')) ?></h2><a href="/about"><?= e(st('about')) ?></a><a href="/contact"><?= e(st('contact')) ?></a><a href="/help"><?= e(st('navHelp')) ?></a></div>
    <div><h2><?= e(st('footLegal')) ?></h2><a href="/privacy"><?= e(st('privacy')) ?></a><a href="/terms"><?= e(st('terms')) ?></a><a href="/refund-policy"><?= e(st('refund')) ?></a><a href="/cookie-policy"><?= e(st('cookiesPolicy')) ?></a><button type="button" class="linklike" data-cookie-settings><?= e(st('cookieSettings')) ?></button></div>
  </div>
  <div class="wrap foot-bottom">© <?= date('Y') ?> <?= e(config('business_name') ?: site_name()) ?> · Made in India</div>
</footer>
<script src="/site.js?v=4" defer></script>
<script src="/consent-i18n.js?v=3" defer></script>
<script src="/consent.js?v=3" defer data-lang="<?= e($lang) ?>" data-ga="<?= e((string) config('ga4_id')) ?>"></script>
</body>
</html>
