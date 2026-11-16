<?php
/* SEO and health check. Run against your live site after deploying:
     php tests/seo_check.php https://your-domain.in
   Checks: status codes, titles, descriptions, canonical, one H1, JSON-LD validity, hreflang, robots, sitemap. */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$fails = 0; $checks = 0;
function fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'AushadhBox-SEO-Check']);
    $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr((string) $r, 0, $hs), substr((string) $r, $hs)];
}
function ok(bool $c, string $msg): void { global $fails, $checks; $checks++; if (!$c) { $fails++; echo "  ✗ $msg\n"; } }

$pages = ['/', '/hi/', '/ta/', '/pricing', '/ta/pricing', '/about', '/contact', '/help', '/privacy', '/terms', '/refund-policy', '/cookie-policy',
          '/medicines', '/medicines?letter=M', '/ingredients', '/ingredients/paracetamol', '/guides', '/guides/how-to-read-expiry-date-on-medicine'];
[$c, , $sm] = fetch("$base/sitemap.xml");
ok($c === 200, 'sitemap.xml returns 200');
preg_match_all('#<loc>([^<]+)</loc>#', $sm, $m);
$locs = $m[1];
echo 'Sitemap URLs: ' . count($locs) . "\n";
$meds = array_values(array_filter($locs, fn($u) => str_contains($u, '/medicines/')));
shuffle($meds);
foreach (array_slice($meds, 0, 8) as $u) $pages[] = parse_url($u, PHP_URL_PATH);

$titles = [];
foreach ($pages as $p) {
    [$code, $head, $html] = fetch($base . $p);
    echo "$code $p\n";
    ok($code === 200, "$p status $code");
    if ($code !== 200) continue;
    preg_match('#<title>(.*?)</title>#s', $html, $t); $title = html_entity_decode($t[1] ?? '');
    ok($title !== '' && mb_strlen($title) <= 75, "$p title length " . mb_strlen($title) . ": $title");
    $titles[$title][] = $p;
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d); $desc = html_entity_decode($d[1] ?? '');
    ok(mb_strlen($desc) >= 50 && mb_strlen($desc) <= 320, "$p description length " . mb_strlen($desc));
    ok((bool) preg_match('#<link rel="canonical" href="https?://#', $html), "$p has absolute canonical");
    ok(substr_count($html, '<h1') === 1, "$p has exactly one <h1> (found " . substr_count($html, '<h1') . ')');
    ok(str_contains($html, 'og:image'), "$p has og:image");
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
    foreach ($ld[1] as $j) ok(json_decode($j) !== null, "$p JSON-LD is valid JSON");
    if (in_array($p, ['/', '/hi/', '/ta/', '/pricing', '/ta/pricing'], true)) ok(substr_count($html, 'hreflang=') >= 11, "$p has hreflang alternates");
    preg_match('#<html lang="([^"]+)"#', $html, $l); ok(!empty($l[1]), "$p has html lang");
    preg_match_all('#<img [^>]*>#', $html, $imgs); foreach ($imgs[0] as $img) ok(str_contains($img, 'alt='), "$p image without alt: $img");
}
foreach ($titles as $t => $ps) ok(count($ps) === 1 || str_starts_with($ps[0], '/medicines?'), 'Duplicate title on ' . implode(', ', $ps));

[$c, , $robots] = fetch("$base/robots.txt");
ok($c === 200 && str_contains($robots, 'Sitemap:') && str_contains($robots, 'Disallow: /app'), 'robots.txt has sitemap and blocks /app');
[$c, $h] = fetch("$base/app"); ok(stripos($h, 'x-robots-tag: noindex') !== false, '/app is noindex');
[$c] = fetch("$base/this-page-does-not-exist"); ok($c === 404, 'unknown page returns 404');
[$c] = fetch("$base/core/config.php"); ok($c === 403 || $c === 404, 'core/config.php is not reachable');
[$c] = fetch("$base/core/database/schema.sql"); ok($c === 403 || $c === 404, 'schema.sql is not reachable');
[$c, $h] = fetch("$base/ta/about"); ok($c === 301, '/ta/about redirects to /about');

echo "\n$checks checks, " . ($fails ? "$fails FAILED" : 'all passed') . "\n";
exit($fails ? 1 : 0);
