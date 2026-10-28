<?php
/* Public website: routing, layout, SEO tags. Pages are rendered on the server so search engines can read them. */
declare(strict_types=1);

const SITE_LANGS = [
    'en' => ['English', 'en-IN'], 'hi' => ['हिन्दी', 'hi-IN'], 'bn' => ['বাংলা', 'bn-IN'], 'mr' => ['मराठी', 'mr-IN'], 'te' => ['తెలుగు', 'te-IN'],
    'ta' => ['தமிழ்', 'ta-IN'], 'gu' => ['ગુજરાતી', 'gu-IN'], 'kn' => ['ಕನ್ನಡ', 'kn-IN'], 'ml' => ['മലയാളം', 'ml-IN'], 'pa' => ['ਪੰਜਾਬੀ', 'pa-IN'],
];
const LOCALIZED_PAGES = ['', 'pricing'];   // pages available in every language

function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function site_lang(?string $set = null): string { static $l = 'en'; if ($set !== null) $l = $set; return $l; }

function st(string $key, array $vars = []): string
{
    static $all = null;
    if ($all === null) $all = require APP_DIR . '/i18n_site.php';
    $s = $all[site_lang()][$key] ?? $all['en'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}

function base_url(): string
{
    $u = rtrim((string) config('app_url'), '/');
    if ($u === '' || str_contains($u, 'example.com')) {
        $u = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $u;
}

/* Link to a page in the current language when that page is localized. */
function url(string $path = '', ?string $lang = null): string
{
    $lang = $lang ?? site_lang();
    $path = ltrim($path, '/');
    $prefix = ($lang !== 'en' && in_array(explode('?', $path)[0], LOCALIZED_PAGES, true)) ? $lang . '/' : '';
    return '/' . $prefix . $path;
}

function site_name(): string { return (string) (config('site_name') ?: 'AushadhBox'); }

function money(int $rupees): string { return '₹' . number_format($rupees); }

/* Render a view inside the site layout. $meta: title, description, canonical, robots, jsonld[], alternates (bool), og_image */
function render(string $view, array $vars = [], array $meta = []): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require APP_DIR . '/views/layout.php';
}

/* Wrap already-rendered HTML in the layout. */
function render_raw(string $content, array $meta = []): void
{
    require APP_DIR . '/views/layout.php';
}

function not_found(): void
{
    http_response_code(404);
    render('404', [], ['title' => 'Page not found · ' . site_name(), 'description' => 'This page does not exist.', 'robots' => 'noindex']);
}

function catalog_by_slug(string $slug): ?array
{
    $r = q('SELECT c.*, i.ingredient, i.use_text, i.rx, i.storage, i.note, i.slug AS info_slug FROM medicine_catalog c LEFT JOIN medicine_info i ON i.id = c.info_id WHERE c.slug = ?', [$slug])->fetch();
    return $r ?: null;
}

function info_by_slug(string $slug): ?array
{
    $r = q('SELECT * FROM medicine_info WHERE slug = ?', [$slug])->fetch();
    return $r ?: null;
}

function guides(): array
{
    static $g = null;
    if ($g === null) $g = require APP_DIR . '/content/guides.php';
    return $g;
}

function prices(): array
{
    $m = (int) (config('price_month') ?: 49); $y = (int) (config('price_year') ?: 399);
    return ['month' => $m, 'year' => $y, 'save' => max(0, (int) round(100 - $y * 100 / max(1, $m * 12)))];
}

function payments_enabled(): bool
{
    return ((string) config('razorpay_key_id') !== '' && (string) config('razorpay_key_secret') !== '') || (bool) config('payments_demo');
}

/* ---------------- Router ---------------- */
function route_site(string $path): void
{
    $path = trim($path, '/');
    $parts = $path === '' ? [] : explode('/', $path);
    if ($parts && isset(SITE_LANGS[$parts[0]]) && $parts[0] !== 'en') { site_lang(array_shift($parts)); }
    $page = implode('/', $parts);
    if (site_lang() !== 'en' && !in_array($page, LOCALIZED_PAGES, true)) { header('Location: /' . $page, true, 301); return; }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    switch (true) {
        case $page === '':               require APP_DIR . '/pages/home.php'; return;
        case $page === 'pricing':        require APP_DIR . '/pages/pricing.php'; return;
        case in_array($page, ['about', 'contact', 'help', 'privacy', 'terms', 'refund-policy', 'cookie-policy'], true):
            require APP_DIR . '/pages/static.php'; static_page($page); return;
        case $page === 'medicines':      require APP_DIR . '/pages/medicines.php'; medicines_index(); return;
        case preg_match('#^medicines/([a-z0-9-]{3,240})$#', $page, $m) === 1:
            require APP_DIR . '/pages/medicines.php'; medicine_page($m[1]); return;
        case $page === 'ingredients':    require APP_DIR . '/pages/medicines.php'; ingredients_index(); return;
        case preg_match('#^ingredients/([a-z0-9-]{2,100})$#', $page, $m) === 1:
            require APP_DIR . '/pages/medicines.php'; ingredient_page($m[1]); return;
        case $page === 'guides':         require APP_DIR . '/pages/guides.php'; guides_index(); return;
        case preg_match('#^guides/([a-z0-9-]{3,120})$#', $page, $m) === 1:
            require APP_DIR . '/pages/guides.php'; guide_page($m[1]); return;
        case $page === 'sitemap.xml':    require APP_DIR . '/pages/sitemap.php'; return;
        case $page === 'robots.txt':     robots(); return;
        case preg_match('#^calendar/([a-f0-9]{32})\.ics$#', $page, $m) === 1: calendar_out($m[1]); return;
        case $page === 'app':            serve_app(); return;
        // Share target when the service worker isn't running yet: just open the scanner.
        case $page === 'app/share':      header('Location: /app#scan', true, 303); return;
        case $page === 'report':         require APP_DIR . '/pages/report.php'; return;
        default: not_found();
    }
}

function robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /app\nDisallow: /api.php\nDisallow: /admin.html\nDisallow: /core/\nDisallow: /install.php\nDisallow: /cron.php\nDisallow: /webhook.php\nDisallow: /report\nDisallow: /calendar/\n\nSitemap: " . base_url() . "/sitemap.xml\n";
}

/* Private calendar feed of a family's dose times (subscribed from Google Calendar or the iPhone Calendar app). */
function calendar_out(string $token): void
{
    require_once APP_DIR . '/automations.php';
    $ics = calendar_feed($token);
    if ($ics === null) { http_response_code(404); header('Content-Type: text/plain'); echo "Calendar not found. Create a new link in AushadhBox: Account → Automations.\n"; return; }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="aushadhbox-doses.ics"');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, max-age=900');
    echo $ics;
}

function serve_app(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-cache');
    readfile(dirname(APP_DIR) . '/app.html');
}

/* JSON-LD helpers */
function jsonld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
}

function breadcrumbs(array $items): array
{
    $list = [];
    foreach ($items as $i => [$name, $path]) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => base_url() . $path];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}
