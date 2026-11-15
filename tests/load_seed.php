<?php
/* Creates N test families for the load test (each with 3 members and 8 medicines), or removes them.
     php tests/load_seed.php 200        → prints nothing but "ok"; accounts loadNNN@load.test / password "load test password"
     php tests/load_seed.php clean      → removes every @load.test account
   Use only on a TEST database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../public_html/core/bootstrap.php';
if (($argv[1] ?? '') === 'clean') { $n = q("DELETE FROM users WHERE email LIKE '%@load.test'")->rowCount(); echo "removed $n\n"; exit; }
$n = max(1, min(2000, (int) ($argv[1] ?? 100)));
$hash = pw_hash('load test password');
$infos = q('SELECT id FROM medicine_info ORDER BY id LIMIT 40')->fetchAll(PDO::FETCH_COLUMN);
for ($i = 1; $i <= $n; $i++) {
    $email = sprintf('load%03d@load.test', $i);
    if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) continue;
    q("INSERT INTO users (name, email, password_hash, language, consent_version, consent_at, adult_confirmed_at) VALUES (?,?,?,'en',?,NOW(),NOW())", ['Load ' . $i, $email, $hash, config('consent_version')]);
    $uid = (int) db()->lastInsertId();
    q('INSERT INTO reminder_settings (user_id) VALUES (?)', [$uid]);
    $mids = [];
    foreach (['Self', 'Mother', 'Father'] as $k => $rel) { q('INSERT INTO members (user_id, name, relation, is_self, account_id) VALUES (?,?,?,?,?)', [$uid, $rel . ' ' . $i, $rel, $k === 0 ? 1 : 0, $k === 0 ? $uid : null]); $mids[] = (int) db()->lastInsertId(); }
    for ($j = 0; $j < 8; $j++)
        q("INSERT INTO cabinet_items (user_id, member_id, name, info_id, exp_month, dose_times, stock_count) VALUES (?,?,?,?, CURDATE() + INTERVAL ? MONTH - INTERVAL DAY(CURDATE()) - 1 DAY,'m,e',30)",
          [$uid, $mids[$j % 3], 'Medicine ' . $j, $infos ? $infos[($i + $j) % count($infos)] : null, 2 + $j]);
}
echo "ok\n";
