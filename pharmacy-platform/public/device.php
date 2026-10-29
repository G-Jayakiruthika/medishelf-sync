<?php
/* API for fridge thermometers (iot/coldchain). No cookies: a 6-digit pairing code once, then a secret token.
   POST device.php?action=claim  {code}                      → {token, min, max}
   POST device.php?action=report {readings:[{t,c}], battery}  (header Authorization: Bearer <token>) → {state, buzz, min, max} */
declare(strict_types=1);
require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/devices.php';
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('method', 'POST only.', 405);
$in = input();
switch ((string) ($_GET['action'] ?? '')) {
case 'claim':
    limit('devclaim:' . client_ip(), 10, 30);
    $r = device_claim((string) ($in['code'] ?? '')); if (!$r) fail('bad_code', 'Wrong or expired pairing code.', 404);
    json_out($r);
case 'report':
    $tok = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $d = device_by_token($tok); if (!$d) { limit('devbad:' . client_ip(), 30, 10); fail('unauthorised', 'Unknown device.', 401); }
    limit('devrep:' . $d['id'], 60, 10);
    json_out(device_report($d, (array) ($in['readings'] ?? []), isset($in['battery']) ? max(0, min(100, (int) $in['battery'])) : null));
default: fail('unknown_action', 'Unknown action.', 404);
}
