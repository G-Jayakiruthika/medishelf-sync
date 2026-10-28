<?php
/* Sends dose reminders and weekly expiry alerts as push notifications.
   Call every 5 minutes: https://your-site/cron.php?key=YOUR_CRON_KEY
   (free schedulers such as cron-job.org can do this if your host has no cron). */
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/core/services.php';
require __DIR__ . '/core/plans.php';
require __DIR__ . '/core/automations.php';
require __DIR__ . '/core/doses.php';
require __DIR__ . '/core/catalog.php';
require __DIR__ . '/core/migrate.php';
header('Content-Type: text/plain; charset=utf-8');

$key = (string) ($_GET['key'] ?? ($argv[1] ?? ''));
if (!hash_equals((string) config('cron_key'), $key)) { http_response_code(403); exit("forbidden\n"); }
// After an update: create any new tables or columns (only does work when the code changed).
foreach (migrate_if_due() as $m) echo "migrate: $m\n";
// Housekeeping (runs even without push): keep security logs one year, then delete; clear old tokens and reminder marks.
q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 YEAR');
q('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY');
q('DELETE FROM reminder_log WHERE sent_on < CURDATE() - INTERVAL 30 DAY');
q('DELETE FROM analytics_events WHERE created_at < NOW() - INTERVAL 1 YEAR');
q('DELETE FROM cookie_consents WHERE created_at < NOW() - INTERVAL 13 MONTH');
q('DELETE FROM invites WHERE expires_at < NOW() - INTERVAL 30 DAY');
q('DELETE FROM automation_log WHERE sent_on < CURDATE() - INTERVAL 60 DAY');
q("DELETE FROM payments WHERE status = 'created' AND created_at < NOW() - INTERVAL 30 DAY");
q("UPDATE users SET plan = 'free' WHERE plan = 'premium' AND plan_until < CURDATE()");

// Tests can pretend it is another time from the command line: php cron.php KEY --now=2026-11-01T08:05
$fake = null;
if (PHP_SAPI === 'cli') foreach ($argv as $a) if (str_starts_with($a, '--now=')) $fake = substr($a, 6);
$now = new DateTime($fake ?? 'now', new DateTimeZone('Asia/Kolkata'));
$today = $now->format('Y-m-d');
$mailed = 0;

/* E-mails (once a day, from 9 am India time): weekly expiry summary for Premium families, and a Premium renewal reminder. */
if (mail_enabled() && (int) $now->format('H') >= 9) {
    $site = site_title();
    $base = rtrim((string) config('app_url'), '/');
    // Renewal reminder 3 days before Premium ends (the plan never renews by itself).
    foreach (q("SELECT id, name, email, plan_until FROM users WHERE plan = 'premium' AND plan_until = CURDATE() + INTERVAL 3 DAY")->fetchAll() as $u) {
        if (q("SELECT 1 FROM reminder_log WHERE user_id = ? AND slot = 'r' AND sent_on = ?", [$u['id'], $today])->fetch()) continue;
        q("INSERT IGNORE INTO reminder_log (user_id, slot, sent_on) VALUES (?, 'r', ?)", [$u['id'], $today]);
        if (send_mail($u['email'], "Your $site Premium ends on {$u['plan_until']}", "Hello {$u['name']},\n\nYour Family Premium ends on {$u['plan_until']}. It does not renew automatically.\nTo keep family sharing, reports and weekly e-mails, renew here: $base/app#upgrade\n\nIf you do nothing, your account moves to the Free plan. Nothing is deleted.\n\n$site")) $mailed++;
    }
    // Weekly expiry e-mail on Mondays, for every account in a Premium household that has not switched it off.
    if ($now->format('N') === '1') {
        $rows = q("SELECT u.id, u.name, u.email, COALESCE(u.household_id, u.id) AS hid FROM users u
                   JOIN users o ON o.id = COALESCE(u.household_id, u.id)
                   WHERE u.weekly_email = 1 AND o.plan = 'premium' AND o.plan_until >= CURDATE()")->fetchAll();
        foreach ($rows as $u) {
            if (q("SELECT 1 FROM reminder_log WHERE user_id = ? AND slot = 'w' AND sent_on = ?", [$u['id'], $today])->fetch()) continue;
            q("INSERT IGNORE INTO reminder_log (user_id, slot, sent_on) VALUES (?, 'w', ?)", [$u['id'], $today]);
            $items = q("SELECT c.name, DATE_FORMAT(c.exp_month, '%b %Y') AS exp, m.name AS member, LAST_DAY(c.exp_month) < CURDATE() AS expired
                        FROM cabinet_items c LEFT JOIN members m ON m.id = c.member_id
                        WHERE c.user_id = ? AND LAST_DAY(c.exp_month) < CURDATE() + INTERVAL 60 DAY ORDER BY c.exp_month", [$u['hid']])->fetchAll();
            if (!$items) continue;
            $lines = array_map(fn($i) => ($i['expired'] ? '[EXPIRED] ' : '[SOON]    ') . $i['name'] . ' — ' . $i['exp'] . ($i['member'] ? ' (' . $i['member'] . ')' : ''), $items);
            if (send_mail($u['email'], count($items) . " medicines need attention this week · $site",
                "Hello {$u['name']},\n\nThese medicines in your family cabinet have expired or expire within 60 days:\n\n" . implode("\n", $lines) .
                "\n\nOpen your cabinet: $base/app#cabinet\nHow to dispose of old medicines: $base/guides/how-to-dispose-of-expired-medicines-in-india\n\nTo stop these e-mails: Account → Weekly e-mail.\n\n$site")) $mailed++;
        }
    }
}

/* Automations: refill, missed-dose, course finished, monthly clean-up (push, or e-mail when the person has no device). */
$auto = run_automations($now);
if (!push_enabled()) exit("housekeeping done; mailed=$mailed; automations push={$auto['push']} mail={$auto['mail']}; push not configured\n");

$nowMin = (int) $now->format('H') * 60 + (int) $now->format('i');
$labels = ['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening', 'b' => 'Bedtime'];
$sent = 0; $removed = 0;

$users = q('SELECT r.user_id, COALESCE(u.household_id, u.id) AS hid, r.m_time, r.a_time, r.e_time, r.b_time FROM reminder_settings r
            JOIN users u ON u.id = r.user_id
            WHERE r.enabled = 1 AND EXISTS (SELECT 1 FROM push_subscriptions p WHERE p.user_id = r.user_id)')->fetchAll();

foreach ($users as $u) {
    $subs = q('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?', [$u['user_id']])->fetchAll();
    $messages = [];

    // 1. Dose reminders: within 20 minutes after the chosen time, once per day per slot.
    foreach (SLOTS as $s) {
        [$h, $m] = array_map('intval', explode(':', $u[$s . '_time']));
        $diff = $nowMin - ($h * 60 + $m);
        if ($diff < 0 || $diff > 20) continue;
        if (q('SELECT 1 FROM reminder_log WHERE user_id = ? AND slot = ? AND sent_on = ?', [$u['user_id'], $s, $today])->fetch()) continue;
        $due = q("SELECT c.name FROM cabinet_items c LEFT JOIN dose_logs d ON d.item_id = c.id AND d.slot = ? AND d.taken_on = ?
                  WHERE c.user_id = ? AND FIND_IN_SET(?, c.dose_times) AND d.id IS NULL AND (c.course_end IS NULL OR c.course_end >= ?)", [$s, $today, $u['hid'], $s, $today])->fetchAll();
        q('INSERT IGNORE INTO reminder_log (user_id, slot, sent_on) VALUES (?,?,?)', [$u['user_id'], $s, $today]);
        if ($due) $messages[] = ['title' => $labels[$s] . ' medicines', 'body' => implode(', ', array_column($due, 'name')), 'tag' => 'dose-' . $s, 'url' => '/app#doses', 'slot' => $s];
    }

    // 2. Expiry alert: at most once a week, at the morning time.
    [$h, $m] = array_map('intval', explode(':', $u['m_time']));
    $diff = $nowMin - ($h * 60 + $m);
    $weekStart = (clone $now)->modify('monday this week')->format('Y-m-d');
    if ($diff >= 0 && $diff <= 20 && !q('SELECT 1 FROM reminder_log WHERE user_id = ? AND slot = ? AND sent_on = ?', [$u['user_id'], 'x', $weekStart])->fetch()) {
        $soon = q('SELECT name, exp_month FROM cabinet_items WHERE user_id = ? AND LAST_DAY(exp_month) < CURDATE() + INTERVAL 30 DAY', [$u['hid']])->fetchAll();
        q('INSERT IGNORE INTO reminder_log (user_id, slot, sent_on) VALUES (?,?,?)', [$u['user_id'], 'x', $weekStart]);
        if ($soon) $messages[] = ['title' => count($soon) . ' medicines expired or expiring this month', 'body' => implode(', ', array_column($soon, 'name')), 'tag' => 'expiry', 'url' => '/app#cabinet'];
    }

    foreach ($messages as $msg) {
        foreach ($subs as $sub) {
            try {
                $code = push_send($sub, $msg);
                if ($code === 404 || $code === 410) { q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]); $removed++; }
                elseif ($code >= 200 && $code < 300) $sent++;
            } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
        }
    }
}
echo "ok mailed=$mailed sent=$sent removed=$removed automations push={$auto['push']} mail={$auto['mail']} at " . $now->format('c') . "\n";
