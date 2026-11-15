<?php
/* Missed-call webhook (like 99DOTS in India's TB programme): no smartphone, no internet, no cost to the caller.
   Two numbers are rented from a telephony provider (Exotel, Knowlarity, MyOperator...) and set in config.php:
     'telephony' => ['secret' => 'long-random', 'verify_number' => '08047xxxxxx', 'dose_number' => '08047yyyyyy']
   - A missed call to the verify number confirms that a family member's phone is theirs.
   - A missed call to the dose number, from a confirmed phone, ticks that person's medicines due now.
   The provider calls  https://yourdomain/telephony.php?key=SECRET&from=...&to=...
   (Exotel sends CallFrom/CallTo, Knowlarity caller_id/dispnumber: both are understood.) The call is never answered,
   so the caller pays nothing. */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
foreach (['doses', 'watch', 'careplus'] as $f) require_once __DIR__ . "/core/$f.php";
header('X-Content-Type-Options: nosniff');
$cfg = (array) (config('telephony') ?? []);
$key = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_TELEPHONY_KEY'] ?? '');
if (empty($cfg['secret']) || !hash_equals((string) $cfg['secret'], $key)) {
    q("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'telephony', 0)", [client_ip()]);
    fail('unauthorised', 'Unknown caller system.', 401);
}
$p = $_GET + $_POST;
$from = (string) ($p['from'] ?? $p['CallFrom'] ?? $p['caller_id'] ?? $p['From'] ?? '');
$to = (string) ($p['to'] ?? $p['CallTo'] ?? $p['dispnumber'] ?? $p['To'] ?? $p['called_number'] ?? '');
$r = missed_call($from, $to);
q('INSERT INTO sms_out (phone_hash, template, ref, body, status) VALUES (?,?,?,?,?)', [hash('sha256', (string) phone_norm($from)), 'missedcall', mb_substr($to, -10), $r['result'], 'logged']);
json_out(['ok' => true] + $r);
