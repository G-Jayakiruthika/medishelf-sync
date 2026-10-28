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
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
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
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
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
    json_out(['error' => $code, 'message' => $message], $status);
}

function input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input') ?: '';
        $data = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
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
        $user = q('SELECT id, name, email, phone, language, role, consent_version, plan, plan_until, household_id, weekly_email, onboarded, created_at, ai_opt_in, pharmacy_name, pharmacy_phone FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
        if (!$user) { $_SESSION = []; }
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
        fail('bad_csrf', 'Your session expired. Reload the page and try again.', 419);
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
