<?php
/* AushadhBox Pharmacy Network: shared start-up code. Configuration, database, sessions, security headers,
   encryption of personal data, rate limits, audit log, two-factor codes and distance maths. */
declare(strict_types=1);

const PH_DIR = __DIR__;
date_default_timezone_set('Asia/Kolkata');
const ROLES = ['owner', 'manager', 'pharmacist', 'cashier', 'rider'];
/* What each role may do. Riders only see their own deliveries; cashiers can't change stock or prices. */
const CAN = [
    'owner'      => ['*'],
    'manager'    => ['orders', 'stock', 'network', 'customers', 'khata', 'payments', 'delivery', 'staff', 'reports', 'devices', 'card'],
    'pharmacist' => ['orders', 'stock', 'network', 'customers', 'khata', 'payments', 'delivery', 'card', 'devices'],
    'cashier'    => ['orders', 'customers', 'khata', 'payments', 'card'],
    'rider'      => ['rides'],
];

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = PH_DIR . '/config.php';
        if (!is_file($file)) { http_response_code(503); header('Content-Type: application/json'); echo '{"error":"not_installed","message":"Copy core/config.example.php to core/config.php."}'; exit; }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = config('db_socket') ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', config('db_socket'), config('db_name'))
                                   : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db_host'), (int) (config('db_port') ?: 3306), config('db_name'));
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+05:30'");
    }
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement { $st = db()->prepare($sql); $st->execute($params); return $st; }
function tx(callable $fn) { db()->beginTransaction(); try { $r = $fn(); db()->commit(); return $r; } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; } }

function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
function base_url(): string
{
    $u = rtrim((string) config('app_url'), '/');
    return $u !== '' ? $u : (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('abph');
    session_set_cookie_params(['lifetime' => 60 * 60 * 12, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 12));
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    // Sign out after 2 hours without activity (shared counter computers in pharmacies).
    if (!empty($_SESSION['seen']) && time() - (int) $_SESSION['seen'] > 7200) { $_SESSION = ['csrf' => bin2hex(random_bytes(32))]; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}

function security_headers(bool $camera = true): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=' . ($camera ? '(self)' : '()') . ', microphone=(), geolocation=(self)');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $code, string $message, int $status = 400): void
{
    if (defined('FAIL_THROWS')) throw new RuntimeException($code, $status);
    json_out(['error' => $code, 'message' => $message], $status);
}
function input(): array
{
    static $data = null;
    if ($data === null) { $raw = file_get_contents('php://input') ?: ''; $data = $raw !== '' ? (json_decode($raw, true) ?: []) : []; }
    return $data;
}
function str_in(array $in, string $key, int $max, bool $required = false): ?string
{
    $v = isset($in[$key]) && is_scalar($in[$key]) ? trim((string) $in[$key]) : '';
    if ($v === '') { if ($required) fail('missing_' . $key, 'Please fill in ' . str_replace('_', ' ', $key) . '.'); return null; }
    if (mb_strlen($v) > $max) fail('too_long_' . $key, str_replace('_', ' ', $key) . " is too long (max $max characters).");
    return $v;
}
function num_in(array $in, string $key, float $min, float $max, ?float $default = null): ?float
{
    if (!isset($in[$key]) || $in[$key] === '' || $in[$key] === null) return $default;
    $v = (float) str_replace(',', '', (string) $in[$key]);
    if (!is_finite($v) || $v < $min || $v > $max) fail('bad_' . $key, str_replace('_', ' ', $key) . " must be between $min and $max.");
    return $v;
}
function client_ip(): string
{
    $h = (string) (config('ip_header') ?? '');
    $ip = $h !== '' && !empty($_SERVER[$h]) ? trim(explode(',', (string) $_SERVER[$h])[0]) : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0', 0, 45);
}
function require_csrf(): void
{
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) fail('csrf', 'Your session expired. Reload the page.', 419);
    // Defence in depth: a browser always sends Origin on POST; it must be this site (server-to-server calls carry no Origin and are signed).
    $o = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '' && strtolower((string) parse_url($o, PHP_URL_HOST)) !== strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0])) fail('bad_origin', 'This request came from another website and was blocked.', 403);
}

/* Rate limit: at most $max events for key $k in $minutes. */
function limit(string $k, int $max, int $minutes): void
{
    $k = substr($k, 0, 80);
    if ((int) q('SELECT COUNT(*) FROM hits WHERE k = ? AND created_at >= NOW() - INTERVAL ' . $minutes . ' MINUTE', [$k])->fetchColumn() >= $max) fail('too_many', "Too many attempts. Please wait $minutes minutes.", 429);
    q('INSERT INTO hits (k) VALUES (?)', [$k]);
}

function audit(string $actor, ?int $actorId, ?int $orgId, ?int $branchId, string $action, string $detail = ''): void
{
    q('INSERT INTO audit_log (actor, actor_id, org_id, branch_id, action, detail, ip) VALUES (?,?,?,?,?,?,?)', [$actor, $actorId, $orgId, $branchId, $action, mb_substr($detail, 0, 400), PHP_SAPI === 'cli' ? null : client_ip()]);
}

/* ---------- Personal data at rest: phone numbers and addresses are encrypted (libsodium secretbox) ---------- */
function data_key(): string
{
    $k = base64_decode((string) config('data_key'), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Set data_key in config.php (php tools/make_keys.php)');
    return $k;
}
function enc(?string $plain): ?string
{
    if ($plain === null || $plain === '') return null;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, data_key()));
}
function dec(?string $box): ?string
{
    if ($box === null || $box === '' || !str_starts_with($box, 'v1:')) return null;
    $raw = base64_decode(substr($box, 3), true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), data_key());
    return $p === false ? null : $p;
}
/* Indian mobile number: 10 digits starting 6-9 (accepts +91, 0 and spaces). Returns '' if not valid. */
function phone_norm(?string $p): string
{
    $d = preg_replace('/\D/', '', (string) $p);
    if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
    if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}
function phone_hash(string $tenDigits): string { return hash_hmac('sha256', $tenDigits, data_key()); }
function phone_mask(?string $p): string { return $p ? substr($p, 0, 2) . 'xxxxx' . substr($p, -3) : ''; }

/* Short readable codes without confusing letters (no 0/O, 1/I/L). */
function code(int $len): string { $a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $s = ''; for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)]; return $s; }

/* Great-circle distance in km (good enough for delivery fees; roads are about 1.3x longer, see road_km). */
function km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0; $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}
function road_km(float $straight, string $area = 'urban'): float { return round($straight * ($area === 'rural' ? 1.45 : 1.3), 1); }

/* ---------- Two-factor sign-in codes (TOTP, RFC 6238), for owners, managers and admins ---------- */
function b32_decode(string $s): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = ''; $out = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function b32_secret(): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = ''; for ($i = 0; $i < 32; $i++) $s .= $a[random_int(0, 31)]; return $s; }
function totp(string $secret, ?int $t = null): string
{
    $counter = pack('N*', 0, intdiv($t ?? time(), 30));
    $h = hash_hmac('sha1', $counter, b32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}
function totp_ok(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    foreach ([-1, 0, 1] as $w) if (hash_equals(totp($secret, time() + $w * 30), $code)) return true;
    return false;
}

function money(float $n): string { return '₹' . number_format($n, $n == floor($n) ? 0 : 2); }

ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(function (Throwable $e) {
    error_log('Unhandled: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
    if (!headers_sent()) json_out(['error' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.'], 500);
});

/* Passwords: Argon2id (OWASP settings: 19 MiB memory, 2 passes, 1 thread). Older bcrypt hashes are upgraded at the next sign-in. */
function pw_hash(string $plain): string
{
    return defined('PASSWORD_ARGON2ID') ? password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]) : password_hash($plain, PASSWORD_DEFAULT);
}
function pw_needs_rehash(string $hash): bool
{
    return defined('PASSWORD_ARGON2ID') ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]) : password_needs_rehash($hash, PASSWORD_DEFAULT);
}
/* Checked against when an account is not found, so the answer takes as long either way. */
const PW_DUMMY = '$argon2id$v=19$m=19456,t=2,p=1$bXg3VzVqS3JHb1guS2NlZQ$qkH20vPkPyiP2c7e/kEMq3CIaT/6ISbyIeWyHBoZBoM';
