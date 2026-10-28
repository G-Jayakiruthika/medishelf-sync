<?php
/* XML sitemap: localized pages with hreflang, legal pages, guides, ingredient guides, and medicine pages that have real content. */
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$b = base_url();
$today = date('Y-m-d');
$x = fn($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">', "\n";

foreach (LOCALIZED_PAGES as $pg) {
    foreach (SITE_LANGS as $code => [$n, $tag]) {
        echo '<url><loc>', $x($b . url($pg, $code)), '</loc><lastmod>', $today, '</lastmod><priority>', $pg === '' ? '1.0' : '0.8', '</priority>';
        foreach (SITE_LANGS as $c2 => [$n2, $t2]) echo '<xhtml:link rel="alternate" hreflang="', $t2, '" href="', $x($b . url($pg, $c2)), '"/>';
        echo '<xhtml:link rel="alternate" hreflang="x-default" href="', $x($b . url($pg, 'en')), '"/></url>', "\n";
    }
}
foreach (['about', 'contact', 'help', 'privacy', 'terms', 'refund-policy', 'cookie-policy', 'medicines', 'ingredients', 'guides'] as $p)
    echo '<url><loc>', $x("$b/$p"), '</loc><priority>', in_array($p, ['medicines', 'ingredients', 'guides'], true) ? '0.8' : '0.4', '</priority></url>', "\n";
foreach (guides() as $slug => $g) echo '<url><loc>', $x("$b/guides/$slug"), '</loc><lastmod>', $x($g['date']), '</lastmod><priority>0.7</priority></url>', "\n";
foreach (q('SELECT slug FROM medicine_info WHERE slug IS NOT NULL ORDER BY slug') as $r) echo '<url><loc>', $x("$b/ingredients/{$r['slug']}"), '</loc><priority>0.6</priority></url>', "\n";
foreach (q('SELECT slug FROM medicine_catalog WHERE info_id IS NOT NULL AND slug IS NOT NULL ORDER BY slug') as $r) echo '<url><loc>', $x("$b/medicines/{$r['slug']}"), '</loc><priority>0.5</priority></url>', "\n";
echo '</urlset>', "\n";
