<?php
/* Front controller: every public page (home, pricing, medicines, guides, legal, sitemap) goes through here. */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Local development with `php -S localhost:8000 index.php`: let real files through, protect the private folder.
if (PHP_SAPI === 'cli-server') {
    if (str_starts_with($path, '/core/')) { http_response_code(403); exit('Forbidden'); }
    if ($path !== '/' && is_file(__DIR__ . $path)) return false;
}

require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/catalog.php';
require __DIR__ . '/core/site.php';

set_exception_handler(function (Throwable $e) {
    error_log('Site error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title><body style="font:16px system-ui;padding:40px"><h1>Something went wrong</h1><p>Please try again in a minute. <a href="/">Go to the home page</a></p>';
});

security_headers();
route_site(rawurldecode($path));
