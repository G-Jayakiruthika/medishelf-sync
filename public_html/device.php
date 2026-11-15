<?php
/* API for the smart pillbox (hardware/firmware). No cookies: the box proves itself with its secret token.

   POST device.php?action=claim   {"code":"123456","hw":"A1B2C3D4E5F6","fw":"1.0.0"}       → {"token": "...", "name": "..."}
   GET  device.php?action=sync    Authorization: Bearer <token>                           → today's dose times per lid
   POST device.php?action=events  Authorization: Bearer <token>
                                  {"battery":87,"fw":"1.0.0","events":[{"type":"open","lid":1,"t":1767245100}]}
   Times are Unix seconds. The box keeps events while offline and sends them later, so old times are fine (up to 3 days). */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/doses.php';
require __DIR__ . '/core/pillbox.php';

header('X-Content-Type-Options: nosniff');
$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function device_limit(string $kind, int $max, int $minutes): void
{
    $n = (int) q('SELECT COUNT(*) FROM login_attempts WHERE created_at >= NOW() - INTERVAL ' . $minutes . ' MINUTE AND email = ? AND ip = ? AND success = 0', ['device:' . $kind, client_ip()])->fetchColumn();
    if ($n >= $max) fail('too_many', 'Too many attempts. Wait and try again.', 429);
}
function device_fail_log(string $kind): void { q('INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)', [client_ip(), 'device:' . $kind]); }

function bearer(): array
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(\S+)$/', $h, $m)) { $m = [1 => (string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '')]; }
    $d = device_by_token($m[1]);
    if (!$d) { device_limit('token', 30, 10); device_fail_log('token'); fail('bad_token', 'This box is not paired. Pair it again from the app.', 401); }
    return $d;
}

try {
    switch ($action) {
    case 'claim':
        if ($method !== 'POST') fail('method', 'Use POST.', 405);
        device_limit('claim', 10, 15);
        $in = input();
        $code = preg_replace('/\D/', '', (string) ($in['code'] ?? ''));
        $d = strlen($code) === 6 ? q('SELECT * FROM devices WHERE pair_code = ? AND pair_expires > NOW() AND token_hash IS NULL', [$code])->fetch() : null;
        if (!$d) { device_fail_log('claim'); fail('bad_code', 'This pairing code is wrong or has expired. Make a new one in the app.', 404); }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        q('UPDATE devices SET token_hash = ?, pair_code = NULL, pair_expires = NULL, hw_id = ?, firmware = ?, last_seen = NOW() WHERE id = ?',
          [hash('sha256', $token), substr(preg_replace('/[^A-Za-z0-9:-]/', '', (string) ($in['hw'] ?? '')), 0, 32) ?: null, substr((string) ($in['fw'] ?? ''), 0, 16) ?: null, $d['id']]);
        json_out(['ok' => true, 'token' => $token, 'name' => $d['name'], 'compartments' => (int) $d['compartments']]);

    case 'sync':
        $d = bearer();
        q('UPDATE devices SET last_seen = NOW() WHERE id = ?', [$d['id']]);
        json_out(box_schedule($d, new DateTime()));

    case 'events':
        if ($method !== 'POST') fail('method', 'Use POST.', 405);
        $d = bearer();
        $in = input();
        $bat = isset($in['battery']) && is_numeric($in['battery']) ? max(0, min(100, (int) $in['battery'])) : null;
        q('UPDATE devices SET last_seen = NOW(), battery = COALESCE(?, battery), firmware = COALESCE(?, firmware) WHERE id = ?',
          [$bat, isset($in['fw']) ? substr((string) $in['fw'], 0, 16) : null, $d['id']]);
        $results = [];
        $events = is_array($in['events'] ?? null) ? array_slice($in['events'], 0, 100) : [];
        usort($events, fn($a, $b) => ((int) ($a['t'] ?? 0)) <=> ((int) ($b['t'] ?? 0)));
        $life = ($d['kind'] ?? 'pillbox') === 'life';
        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? '');
            if ($life) {   // door, motion or power-use sensor: each event is a sign of life for watch-over (見守り)
                if (!in_array($type, ['door', 'motion', 'power', 'button', 'boot'], true)) { $results[] = 'ignored'; continue; }
                $t = (int) ($e['t'] ?? 0); if ($t < time() - 3 * 86400 || $t > time() + 300) $t = time();
                $last = q("SELECT MAX(happened_at) FROM device_events WHERE device_id = ? AND kind = 'activity'", [$d['id']])->fetchColumn();
                if (!$last || strtotime($last) < $t - 600) q("INSERT INTO device_events (device_id, kind, happened_at) VALUES (?, 'activity', ?)", [$d['id'], date('Y-m-d H:i:s', $t)]);   // one row per 10 minutes is enough
                $results[] = 'noted';
                continue;
            }
            if (!in_array($type, ['open', 'close', 'button', 'boot'], true)) { $results[] = 'ignored'; continue; }
            $t = (int) ($e['t'] ?? 0);
            if ($t < time() - 3 * 86400 || $t > time() + 300) $t = time();   // clock not set yet, or too old
            $lid = isset($e['lid']) ? max(1, min(4, (int) $e['lid'])) : null;
            $results[] = box_event($d, $type, $lid, (new DateTime())->setTimestamp($t));
        }
        json_out(['ok' => true, 'results' => $results] + ($life ? ['kind' => 'life'] : box_schedule($d, new DateTime())));

    default:
        fail('unknown_action', 'Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('device: ' . $e->getMessage());
    fail('server_error', 'Something went wrong.', 500);
}
