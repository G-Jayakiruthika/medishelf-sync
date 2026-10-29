<?php
/* Scheduled jobs for AushadhBox Pharmacy Network. Run every 15 minutes:
     php /path/to/pharmacy-platform/cron.php
   - fridge sensors that stopped reporting → warning on the dashboard
   - khata reminders on each customer's reminder day (app notification or SMS), once a month
   - expiring stock → a weekly "move or return" suggestion (Python analytics adds smarter ones)
   - nightly at 02:00: Python analytics (demand forecast, reorder, expiry redistribution), if Python is installed
   - clean-up of old rate-limit rows and nonces */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/core/bootstrap.php';
foreach (['network', 'orders', 'sms', 'devices', 'family'] as $f) require_once __DIR__ . "/core/$f.php";
$log = fn(string $m) => print(date('H:i') . " $m\n");

// 1. Fridges
foreach (q('SELECT * FROM devices WHERE token_hash IS NOT NULL')->fetchAll() as $d) { $r = device_check($d); if ($r['state'] !== 'ok') $log("device {$d['id']}: {$r['state']}"); }

// 2. Khata reminders (between 10:00 and 10:15 on the customer's day, at most once a month per book)
if (date('H') === '10' && (int) date('i') < 15) {
    foreach (q("SELECT k.*, c.customer_ref, c.sms_ok, c.phone_enc, b.name bname, b.upi_vpa FROM khata k JOIN customers c ON c.id = k.customer_id JOIN branches b ON b.id = k.branch_id
                WHERE k.balance > 0 AND k.status = 'open' AND k.remind_day = ?", [(int) date('j')])->fetchAll() as $k) {
        if (q("SELECT 1 FROM hits WHERE k = ? AND created_at >= NOW() - INTERVAL 25 DAY", ['khatamonth:' . $k['id']])->fetch()) continue;
        q('INSERT INTO hits (k) VALUES (?)', ['khatamonth:' . $k['id']]);
        if ($k['customer_ref']) family_notify($k['customer_ref'], 'Credit book at ' . $k['bname'], 'Your balance is ' . money((float) $k['balance']) . '. Pay at the shop or by UPI.', '/app#shops');
        elseif ($k['sms_ok'] && ($ph = dec($k['phone_enc']))) sms_send($ph, 'khata_remind', [$k['bname'], number_format((float) $k['balance'], 0), $k['upi_vpa'] ?: 'cash'], (int) $k['branch_id']);
        $log("khata reminder #{$k['id']}");
    }
}

// 3. Weekly expiry check (Monday 08:00): batches expiring within 60 days, worth moving to a shop that sells them faster or returning
if (date('N') === '1' && date('H') === '08' && (int) date('i') < 15) {
    foreach (q("SELECT s.branch_id, COUNT(*) n, SUM(s.qty * COALESCE(s.price, p.mrp, 0)) v FROM stock s JOIN products p ON p.id = s.product_id
                WHERE s.qty > 0 AND s.exp BETWEEN CURDATE() AND CURDATE() + INTERVAL 60 DAY GROUP BY s.branch_id")->fetchAll() as $r)
        q("INSERT INTO insights (branch_id, kind, title, data) VALUES (?, 'expiry', ?, ?)", [$r['branch_id'], $r['n'] . ' batches (' . money((float) $r['v']) . ') expire within 60 days: sell first, move to a linked shop, or return to the distributor.', json_encode($r)]);
}

// 4. Python analytics at night
if (date('H') === '02' && (int) date('i') < 15) {
    $py = trim((string) shell_exec('command -v python3'));
    if ($py !== '') { $out = shell_exec(escapeshellcmd($py) . ' ' . escapeshellarg(__DIR__ . '/analytics/run_all.py') . ' 2>&1'); $log('analytics: ' . trim((string) $out)); }
}

// 5. Clean-up
q('DELETE FROM hits WHERE created_at < NOW() - INTERVAL 40 DAY');
q('DELETE FROM nonces WHERE created_at < NOW() - INTERVAL 1 DAY');
q('DELETE FROM readings WHERE at < NOW() - INTERVAL 400 DAY');   // a year of fridge records for inspections
$log('done');
