<?php
/* Shared start-up code: configuration, database, session, security headers and helpers. */
declare(strict_types=1);

const APP_DIR = __DIR__;
date_default_timezone_set('Asia/Kolkata');
const SLOTS = ['m', 'a', 'e', 'b'];
const LANGS = ['en', 'hi', 'bn', 'mr', 'te', 'ta', 'gu', 'kn', 'ml', 'pa'];

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_DIR . '/config.php';
        if (!is_file($file)) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not_installed', 'message' => 'Copy core/config.example.php to core/config.php and run install.php']);
            exit;
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db_host'), (int) config('db_port'), config('db_name'));
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5];
        // Under heavy load the database may refuse new connections ("too many connections"): try twice more with a short
        // pause, then answer 503 "busy, try again" instead of an error page. Nothing about the server is shown.
        for ($try = 0; ; $try++) {
            try { $pdo = new PDO($dsn, config('db_user'), config('db_pass'), $opts); break; }
            catch (PDOException $e) {
                if ($try < 2 && in_array((int) ($e->errorInfo[1] ?? 0), [1040, 1203, 2002, 2006, 2013], true)) { usleep(150000 * ($try + 1)); continue; }
                error_log('db connect: ' . $e->getMessage());
                if (PHP_SAPI === 'cli') throw $e;
                server_busy();
            }
        }
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}

/* 503 with Retry-After: browsers, the app and search engines know to try again shortly. */
function server_busy(): void
{
    if (!headers_sent()) { http_response_code(503); header('Retry-After: 30'); header('Cache-Control: no-store'); }
    $json = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'api') || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($json) { if (!headers_sent()) header('Content-Type: application/json'); echo '{"error":"busy","message":"Very busy right now. Please try again in a minute."}'; }
    else { if (!headers_sent()) header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Busy</title><body style="font:16px system-ui;padding:40px;max-width:560px"><h1>Very busy right now</h1><p>Please try again in a minute. Your data is safe.</p></body>'; }
    exit;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/* The site's address for links that leave the browser (QR stickers, doctor links, e-mails).
   Uses app_url from config.php, or the address this request came to when it is not set. */
function base_url(): string
{
    $u = rtrim((string) config('app_url'), '/');
    if ($u === '' || str_contains($u, 'example.com')) {
        $u = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $u;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('msync');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,   // stay signed in for 30 days
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function security_headers(): void
{
    header_remove('X-Powered-By');   // never announce the PHP version
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
    // Same policy as .htaccess, so it applies on any web server (Apache, Nginx, LiteSpeed). Keep the two in step.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval' blob: https://cdn.jsdelivr.net https://checkout.razorpay.com https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob: https://*.razorpay.com https://www.google-analytics.com https://www.googletagmanager.com; media-src 'self' blob:; connect-src 'self' https://cdn.jsdelivr.net https://tessdata.projectnaptha.com https://*.razorpay.com https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com; frame-src https://api.razorpay.com https://checkout.razorpay.com; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $code, string $message, int $status = 400): void
{
    if (defined('FAIL_THROWS')) throw new RuntimeException($code, $status);   // unit tests check refusals without exiting
    json_out(['error' => $code, 'message' => $message], $status);
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        // Bodies are capped (512 KB; the admin medicine-list import 7 MB) so one huge request cannot use up the server's memory.
        $max = in_array((string) ($_GET['action'] ?? ''), ['admin_import'], true) ? 7 * 1024 * 1024 : 512 * 1024;
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) fail('too_large', 'That request is too large.', 413);
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (strlen($raw) > $max) fail('too_large', 'That request is too large.', 413);
        $j = $raw !== '' ? json_decode($raw, true, 32) : [];
        $data = is_array($j) ? $j : [];   // JSON only: nothing is ever unserialised into PHP objects
    }
    return $data;
}

/* Read a string field, trimmed and length-limited. */
function str_in(array $in, string $key, int $max, bool $required = false): ?string
{
    $v = isset($in[$key]) && is_scalar($in[$key]) ? trim((string) $in[$key]) : '';
    if ($v === '') {
        if ($required) fail('missing_' . $key, "Please fill in $key.");
        return null;
    }
    if (mb_strlen($v) > $max) fail('too_long_' . $key, "$key is too long (max $max characters).");
    return $v;
}

/* On hosts behind a proxy/CDN (e.g. Cloudflare) set 'ip_header' => 'HTTP_CF_CONNECTING_IP' in config.php,
   otherwise every visitor looks like the same IP and rate limits would block everyone together. */
function client_ip(): string
{
    $h = (string) (config('ip_header') ?? '');
    $ip = $h !== '' && !empty($_SERVER[$h]) ? trim(explode(',', (string) $_SERVER[$h])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0', 0, 45);
}

/* Never show PHP error details to visitors; log them instead. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e) {
    error_log('Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['error' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.']);
});

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null) {
        $user = q('SELECT id, name, email, phone, language, role, consent_version, plan, plan_until, household_id, weekly_email, onboarded, created_at, ai_opt_in, pharmacy_name, pharmacy_phone, low_data, district, village, health_worker, auth_epoch, totp_on, adult_confirmed_at, marketing_ok FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
        // A password change or reset signs out every other device: their session carries an older epoch.
        if ($user && (int) ($_SESSION['ep'] ?? 0) !== (int) $user['auth_epoch']) { $user = null; }
        if (!$user) { $_SESSION = ['csrf' => $_SESSION['csrf'] ?? bin2hex(random_bytes(32))]; return null; }
    }
    return $user;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) fail('not_signed_in', 'Please sign in.', 401);
    return $u;
}

function require_csrf(): void
{
    start_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        security_event('csrf_fail', (string) ($_GET['action'] ?? ''));
        fail('bad_csrf', 'Your session expired. Reload the page and try again.', 419);
    }
    // Defence in depth: a browser always sends Origin on POST; it must be this site.
    if (!origin_ok()) {
        security_event('bad_origin', mb_substr((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 120));
        fail('bad_origin', 'This request came from another website and was blocked.', 403);
    }
}

/* "2027-03" → "2027-03-01" (null if invalid). */
function month_to_date(?string $ym): ?string
{
    if ($ym === null || $ym === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return null;
    $y = (int) $m[1]; $mo = (int) $m[2];
    if ($y < 2000 || $y > 2100 || $mo < 1 || $mo > 12) return null;
    return sprintf('%04d-%02d-01', $y, $mo);
}

function date_to_month(?string $d): ?string
{
    return $d ? substr($d, 0, 7) : null;
}

function valid_times(?string $s): string
{
    $parts = array_filter(explode(',', (string) $s), fn($x) => in_array($x, SLOTS, true));
    return implode(',', array_values(array_unique($parts)));
}

require_once APP_DIR . '/security.php';
