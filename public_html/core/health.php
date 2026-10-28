<?php
/* Health monitor with self-healing: checks the site's moving parts, fixes what is safe to fix by itself,
   reconciles payments that got stuck, and tells search engines about pages (IndexNow). */
declare(strict_types=1);
require_once __DIR__ . '/ai.php';

function health_checks(): array
{
    $c = [];
    $add = function (string $key, string $label, string $status, string $detail, bool $fixable = false) use (&$c) { $c[] = compact('key', 'label', 'status', 'detail', 'fixable'); };
    try { q('SELECT 1'); $add('db', 'Database', 'ok', 'Connected.'); } catch (Throwable $e) { $add('db', 'Database', 'bad', 'Cannot connect.'); return $c; }

    $last = state_get('cron_last_run');
    $age = $last ? time() - strtotime($last) : null;
    $add('cron', 'Scheduled jobs (cron.php)', $age === null ? 'bad' : ($age < 900 ? 'ok' : ($age < 7200 ? 'warn' : 'bad')),
         $age === null ? 'Never ran. Add the cron job (every 5 minutes) — see docs/DEPLOY.md. Reminders and all automations need it.' : 'Last run ' . ($age < 120 ? 'just now' : round($age / 60) . ' minutes ago') . '.');

    $key = (string) config('cron_key');
    $weak = $key === '' || str_starts_with($key, 'change-me') || strlen($key) < 24;
    $add('cron_key', 'Cron password', $weak ? 'bad' : 'ok', $weak ? 'Missing, the example value or shorter than 24 characters. Anyone who guesses it can run your jobs. Make one with: php -r "echo bin2hex(random_bytes(24));"' : 'Long and random.');
    $url = (string) config('app_url');
    $local = $url === '' || preg_match('#//(localhost|127\.)#', $url);
    $add('https', 'HTTPS address', str_starts_with($url, 'https://') || $local ? 'ok' : 'warn', $local ? 'Local copy.' : (str_starts_with($url, 'https://') ? 'Uses HTTPS.' : 'app_url should start with https://. Camera, push and payments need HTTPS.'));
    if (config('payments_demo') && !$local) $add('pay_demo', 'Payment test mode', 'bad', 'payments_demo is on for a public site: anyone gets Premium for free. Turn it off in config.php.');
    if (is_file(dirname(__DIR__) . '/install.php') && is_file(APP_DIR . '/install.lock') && !$local) $add('installer', 'Installer file', 'warn', 'install.php is still on the server. Delete it now that the site is installed.');

    $add('mail', 'E-mail (SMTP)', mail_enabled() ? 'ok' : 'warn', mail_enabled() ? 'Configured.' : 'Not set up: no password reset, receipts, support acknowledgements or digests by e-mail.');
    $add('push', 'Phone notifications', push_enabled() ? 'ok' : 'warn', push_enabled() ? q('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() . ' devices subscribed.' : 'VAPID keys not set: no reminders when the app is closed.');

    $stuck = (int) q("SELECT COUNT(*) FROM payments WHERE status = 'created' AND rp_order_id NOT LIKE 'demo\\_%' AND created_at BETWEEN NOW() - INTERVAL 2 DAY AND NOW() - INTERVAL 15 MINUTE")->fetchColumn();
    $add('payments', 'Payments', $stuck ? 'warn' : 'ok', $stuck ? "$stuck checkout(s) not completed in the last 2 days. The autopilot asks Razorpay about each one every hour." : 'No stuck payments.', $stuck > 0 && razorpay_live());

    $over = (int) q('SELECT COUNT(*) FROM contact_messages WHERE handled = 0 AND due_at < NOW()')->fetchColumn();
    $untri = (int) q('SELECT COUNT(*) FROM contact_messages WHERE category IS NULL')->fetchColumn();
    $add('support', 'Support replies', $over ? 'warn' : 'ok', $over ? "$over message(s) past their reply target." : 'All within target.' . ($untri ? " $untri not sorted yet." : ''), $untri > 0);

    $free = @disk_free_space(APP_DIR);
    if ($free !== false) $add('disk', 'Disk space', $free < 100 * 1048576 ? 'bad' : ($free < 500 * 1048576 ? 'warn' : 'ok'), round($free / 1048576) . ' MB free.');
    $cache = APP_DIR . '/cache';
    $add('cache', 'Cache folder', is_dir($cache) && is_writable($cache) ? 'ok' : 'warn', is_dir($cache) && is_writable($cache) ? 'Writable.' : 'core/cache is missing or not writable.', true);

    $off = (int) q("SELECT COUNT(*) FROM devices WHERE token_hash IS NOT NULL AND last_seen < NOW() - INTERVAL 1 DAY")->fetchColumn();
    if ((int) q('SELECT COUNT(*) FROM devices WHERE token_hash IS NOT NULL')->fetchColumn()) $add('pillbox', 'Smart pillboxes', $off ? 'warn' : 'ok', $off ? "$off offline for more than a day (their families were told)." : 'All online.');

    $ai = ai_usage();
    if ($ai['configured']) $add('ai', 'AI assistant', !$ai['on'] ? 'warn' : ($ai['callsThisMonth'] >= $ai['limit'] * 0.9 ? 'warn' : 'ok'),
        !$ai['on'] ? 'Switched off in Autopilot settings.' : "{$ai['callsThisMonth']} of {$ai['limit']} calls this month" . ($ai['callsThisMonth'] && $ai['okThisMonth'] < $ai['callsThisMonth'] * 0.8 ? ', many failed: check the API key.' : '.'));
    return $c;
}

/* Safe repairs. Returns what was done. */
function self_heal(): array
{
    $done = [];
    if (function_exists('migrate')) { $m = migrate(); if ($m) $done[] = 'database updated: ' . implode(', ', $m); }
    $cache = APP_DIR . '/cache';
    if (!is_dir($cache) && @mkdir($cache, 0750, true)) $done[] = 'created the cache folder';
    $old = 0;
    foreach (glob($cache . '/*') ?: [] as $f) if (is_file($f) && basename($f) !== 'migrated.txt' && filemtime($f) < time() - 30 * 86400 && @unlink($f)) $old++;
    if ($old) $done[] = "removed $old old cache files";
    $n = q('DELETE FROM device_events WHERE happened_at < NOW() - INTERVAL 1 YEAR')->rowCount() + q('DELETE FROM ai_log WHERE created_at < NOW() - INTERVAL 1 YEAR')->rowCount()
       + q('DELETE FROM autopilot_log WHERE created_at < NOW() - INTERVAL 6 MONTH')->rowCount() + q('DELETE FROM insights WHERE day < CURDATE() - INTERVAL 6 MONTH')->rowCount();
    if ($n) $done[] = "cleared $n old log rows";
    // Link medicines saved before their brand name was known to an ingredient (enables safety checks and usual schedules).
    if (function_exists('primary_info_id')) {
        $linked = 0;
        foreach (q('SELECT id, name FROM cabinet_items WHERE info_id IS NULL ORDER BY id DESC LIMIT 500')->fetchAll() as $i)
            if ($iid = primary_info_id((string) $i['name'])) { q('UPDATE cabinet_items SET info_id = ? WHERE id = ?', [$iid, $i['id']]); $linked++; }
        if ($linked) $done[] = "recognised the ingredient of $linked medicines";
    }
    if (function_exists('support_backfill') && ($t = support_backfill())) $done[] = "sorted $t support messages";
    $r = reconcile_payments();
    if ($r['activated']) $done[] = "activated Premium for {$r['activated']} payment(s) that got stuck";
    foreach ($done as $d) autopilot_log('heal', ucfirst($d));
    return $done;
}

/* Asks Razorpay about checkouts that never came back (closed browser, lost webhook). */
function reconcile_payments(): array
{
    $out = ['checked' => 0, 'activated' => 0];
    if (!razorpay_live()) return $out;
    foreach (q("SELECT rp_order_id FROM payments WHERE status = 'created' AND rp_order_id NOT LIKE 'demo\\_%' AND created_at BETWEEN NOW() - INTERVAL 2 DAY AND NOW() - INTERVAL 15 MINUTE LIMIT 20")->fetchAll() as $p) {
        $out['checked']++;
        $ch = curl_init('https://api.razorpay.com/v1/orders/' . rawurlencode($p['rp_order_id']) . '/payments');
        curl_setopt_array($ch, [CURLOPT_USERPWD => config('razorpay_key_id') . ':' . config('razorpay_key_secret'), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $res = json_decode((string) curl_exec($ch), true); curl_close($ch);
        foreach (($res['items'] ?? []) as $pay) if (($pay['status'] ?? '') === 'captured' && mark_paid($p['rp_order_id'], (string) $pay['id'])) { $out['activated']++; break; }
    }
    return $out;
}

/* ---------- IndexNow: tell Bing, Yandex, Seznam, Naver… about our pages ---------- */
function indexnow_key(): string
{
    $k = state_get('indexnow_key');
    if (!$k) { $k = bin2hex(random_bytes(16)); state_set('indexnow_key', $k); }
    return $k;
}

function indexnow_submit(bool $force = false): array
{
    $base = rtrim((string) config('app_url'), '/');
    if (config('indexnow_on') === false || !str_starts_with($base, 'https://') || preg_match('#//(localhost|127\.)#', $base)) return ['skipped' => 'needs a public https app_url'];
    $last = state_get('indexnow_last');
    if (!$force && $last && strtotime($last) > time() - 7 * 86400) return ['skipped' => 'sent this week'];
    require_once APP_DIR . '/site.php';
    ob_start(); include APP_DIR . '/pages/sitemap.php'; $xml = (string) ob_get_clean();
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);
    $urls = array_values(array_unique(array_map('html_entity_decode', $m[1])));
    $key = indexnow_key();
    $sent = 0;
    foreach (array_chunk($urls, 10000) as $chunk) {
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode(['host' => parse_url($base, PHP_URL_HOST), 'key' => $key, 'keyLocation' => "$base/$key.txt", 'urlList' => $chunk])]);
        curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code >= 200 && $code < 300) $sent += count($chunk);
    }
    state_set('indexnow_last', date('Y-m-d H:i:s'));
    autopilot_log('seo', "IndexNow: submitted $sent of " . count($urls) . ' pages');
    return ['submitted' => $sent, 'total' => count($urls)];
}
