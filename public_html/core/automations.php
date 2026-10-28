<?php
/* In-app automations. Run by cron.php every 5 minutes; the calendar feed is served by the website router.
     f  Refill alert       stock lasts refill_days or fewer → "Buy more" (every 2 days until restocked)
     m  Missed-dose alert  a dose not ticked missed_after minutes after its time → every account in the family
     c  Course finished    the day after course_end → "Course finished", and its reminders have already stopped
     x  Monthly clean-up   1st of the month → list of expired medicines to dispose of
   Each person switches these on or off in Account → Automations (table automation_settings). */
declare(strict_types=1);

const AUTO_DEFAULTS = ['refill_on' => 1, 'refill_days' => 5, 'missed_on' => 1, 'missed_after' => 60, 'course_on' => 1, 'cleanup_on' => 1, 'ics_token' => null];

function auto_settings(int $uid): array
{
    $r = q('SELECT * FROM automation_settings WHERE user_id = ?', [$uid])->fetch();
    return $r ? $r : AUTO_DEFAULTS + ['user_id' => $uid];
}

function auto_save(int $uid, array $in): array
{
    $cur = auto_settings($uid);
    $v = [
        'refill_on' => !empty($in['refill_on']) ? 1 : 0,
        'refill_days' => max(1, min(30, (int) ($in['refill_days'] ?? $cur['refill_days']))),
        'missed_on' => !empty($in['missed_on']) ? 1 : 0,
        'missed_after' => in_array((int) ($in['missed_after'] ?? 0), [30, 60, 90, 120], true) ? (int) $in['missed_after'] : (int) $cur['missed_after'],
        'course_on' => !empty($in['course_on']) ? 1 : 0,
        'cleanup_on' => !empty($in['cleanup_on']) ? 1 : 0,
    ];
    q('INSERT INTO automation_settings (user_id, refill_on, refill_days, missed_on, missed_after, course_on, cleanup_on) VALUES (?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE refill_on=VALUES(refill_on), refill_days=VALUES(refill_days), missed_on=VALUES(missed_on),
       missed_after=VALUES(missed_after), course_on=VALUES(course_on), cleanup_on=VALUES(cleanup_on)',
      [$uid, $v['refill_on'], $v['refill_days'], $v['missed_on'], $v['missed_after'], $v['course_on'], $v['cleanup_on']]);
    return auto_settings($uid);
}

/* A new secret calendar address (the old one stops working). */
function auto_new_ics_token(int $uid): string
{
    auto_settings($uid);
    q('INSERT IGNORE INTO automation_settings (user_id) VALUES (?)', [$uid]);
    $t = bin2hex(random_bytes(16));
    q('UPDATE automation_settings SET ics_token = ? WHERE user_id = ?', [$t, $uid]);
    return $t;
}

/* How many days the counted stock lasts at the current dose times. null = not counting or no dose times. */
function stock_days(?int $stock, int $perDose, string $times): ?int
{
    if ($stock === null) return null;
    $perDay = count(array_filter(explode(',', $times))) * max(1, $perDose);
    return $perDay > 0 ? intdiv($stock, $perDay) : null;
}

function course_active(?string $courseEnd, string $today): bool
{
    return $courseEnd === null || $courseEnd === '' || $courseEnd >= $today;
}

/* Everything one person should hear about now. Returns a list of push/e-mail messages. */
function auto_messages_for(array $u, DateTime $now, array $owner_times): array
{
    $uid = (int) $u['id']; $hid = (int) ($u['household_id'] ?: $u['id']);
    $s = auto_settings($uid);
    $today = $now->format('Y-m-d');
    $nowMin = (int) $now->format('H') * 60 + (int) $now->format('i');
    [$mh, $mm] = array_map('intval', explode(':', $u['m_time'] ?? '08:00'));
    $morning = $nowMin - ($mh * 60 + $mm);
    $isMorning = $morning >= 0 && $morning <= 20;   // the person's own morning reminder window
    $out = [];
    $logged = fn(string $k, int $item, string $slot, string $since) => (bool) q('SELECT 1 FROM automation_log WHERE user_id = ? AND kind = ? AND item_id = ? AND slot = ? AND sent_on >= ?', [$uid, $k, $item, $slot, $since])->fetch();
    $log = fn(string $k, int $item, string $slot) => q('INSERT IGNORE INTO automation_log (user_id, kind, item_id, slot, sent_on) VALUES (?,?,?,?,?)', [$uid, $k, $item, $slot, $today]);
    $members = array_column(q('SELECT id, name FROM members WHERE user_id = ?', [$hid])->fetchAll(), 'name', 'id');
    $who = fn($mid) => $mid && isset($members[$mid]) ? $members[$mid] . ': ' : '';

    // f — refill
    if ($s['refill_on'] && $isMorning) {
        $twoDaysAgo = (clone $now)->modify('-1 day')->format('Y-m-d');
        foreach (q("SELECT id, name, member_id, stock_count, per_dose, dose_times, course_end FROM cabinet_items WHERE user_id = ? AND stock_count IS NOT NULL AND dose_times <> ''", [$hid])->fetchAll() as $i) {
            if (!course_active($i['course_end'], $today)) continue;
            $days = stock_days((int) $i['stock_count'], (int) $i['per_dose'], $i['dose_times']);
            if ($days === null || $days > (int) $s['refill_days'] || $logged('f', (int) $i['id'], '', $twoDaysAgo)) continue;
            $log('f', (int) $i['id'], '');
            $out[] = ['title' => $days <= 0 ? 'Out of ' . $i['name'] : 'Buy more ' . $i['name'],
                      'body' => $who($i['member_id']) . ((int) $i['stock_count']) . ' left, enough for ' . $days . ($days === 1 ? ' day' : ' days') . '. Tap to update the count after you buy.',
                      'tag' => 'refill-' . $i['id'], 'url' => '/app#cabinet', 'kind' => 'refill'];
        }
    }

    // m — missed dose (uses the cabinet owner's dose times, so the whole family is on one clock)
    if ($s['missed_on']) {
        foreach (SLOTS as $slot) {
            [$h, $m] = array_map('intval', explode(':', $owner_times[$slot] ?? '08:00'));
            $late = $nowMin - ($h * 60 + $m) - (int) $s['missed_after'];
            if ($late < 0 || $late > 20) continue;
            $due = q("SELECT c.id, c.name, c.member_id, c.course_end FROM cabinet_items c
                      LEFT JOIN dose_logs d ON d.item_id = c.id AND d.slot = ? AND d.taken_on = ?
                      WHERE c.user_id = ? AND FIND_IN_SET(?, c.dose_times) AND d.id IS NULL", [$slot, $today, $hid, $slot])->fetchAll();
            $due = array_values(array_filter($due, fn($i) => course_active($i['course_end'], $today) && !$logged('m', (int) $i['id'], $slot, $today)));
            if (!$due) continue;
            foreach ($due as $i) $log('m', (int) $i['id'], $slot);
            $labels = ['m' => 'morning', 'a' => 'afternoon', 'e' => 'evening', 'b' => 'bedtime'];
            $out[] = ['title' => 'Not ticked yet: ' . $labels[$slot] . ' dose',
                      'body' => implode(', ', array_map(fn($i) => $who($i['member_id']) . $i['name'], $due)) . '. Please check they were taken.',
                      'tag' => 'missed-' . $slot, 'url' => '/app#doses', 'kind' => 'missed'];
        }
    }

    // c — course finished (yesterday was the last day)
    if ($s['course_on'] && $isMorning) {
        $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
        foreach (q('SELECT id, name, member_id FROM cabinet_items WHERE user_id = ? AND course_end = ?', [$hid, $yesterday])->fetchAll() as $i) {
            if ($logged('c', (int) $i['id'], '', $yesterday)) continue;
            $log('c', (int) $i['id'], '');
            $out[] = ['title' => 'Course finished: ' . $i['name'],
                      'body' => $who($i['member_id']) . 'Reminders for this medicine have stopped. Return leftover tablets to a pharmacy; do not keep them for later.',
                      'tag' => 'course-' . $i['id'], 'url' => '/app#cabinet', 'kind' => 'course'];
        }
    }

    // x — monthly clean-up on the 1st
    if ($s['cleanup_on'] && $isMorning && $now->format('j') === '1' && !$logged('x', 0, '', $today)) {
        $expired = q('SELECT name FROM cabinet_items WHERE user_id = ? AND LAST_DAY(exp_month) < ? ORDER BY exp_month', [$hid, $today])->fetchAll();
        $log('x', 0, '');
        if ($expired) $out[] = ['title' => 'Medicine box clean-up: ' . count($expired) . ' expired',
                                'body' => implode(', ', array_slice(array_column($expired, 'name'), 0, 6)) . (count($expired) > 6 ? ' and more' : '') . '. Take them to a pharmacy take-back point.',
                                'tag' => 'cleanup', 'url' => '/app#cabinet', 'kind' => 'cleanup'];
    }
    return $out;
}

/* Called by cron.php. Sends by push where the person has a device, otherwise by e-mail if e-mail is set up. */
function run_automations(DateTime $now): array
{
    $sent = ['push' => 0, 'mail' => 0];
    $users = q('SELECT u.id, u.name, u.email, u.household_id, r.m_time FROM users u LEFT JOIN reminder_settings r ON r.user_id = u.id')->fetchAll();
    $ownerTimes = [];
    foreach ($users as $u) {
        $hid = (int) ($u['household_id'] ?: $u['id']);
        if (!isset($ownerTimes[$hid])) {
            $r = q('SELECT m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$hid])->fetch() ?: [];
            $ownerTimes[$hid] = ['m' => $r['m_time'] ?? '08:00', 'a' => $r['a_time'] ?? '13:30', 'e' => $r['e_time'] ?? '19:30', 'b' => $r['b_time'] ?? '22:00'];
        }
        $msgs = auto_messages_for($u, $now, $ownerTimes[$hid]);
        if (!$msgs) continue;
        $subs = push_enabled() ? q('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?', [$u['id']])->fetchAll() : [];
        foreach ($msgs as $msg) {
            if ($subs) {
                foreach ($subs as $sub) {
                    try {
                        $code = push_send($sub, $msg);
                        if ($code === 404 || $code === 410) q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
                        elseif ($code >= 200 && $code < 300) $sent['push']++;
                    } catch (Throwable $e) { error_log('automation push: ' . $e->getMessage()); }
                }
            } elseif ($msg['kind'] !== 'missed' && mail_enabled()) {   // missed-dose alerts are time-critical: push only
                $base = rtrim((string) config('app_url'), '/');
                if (send_mail($u['email'], $msg['title'], "Hello {$u['name']},\n\n{$msg['body']}\n\nOpen AushadhBox: $base{$msg['url']}\nChange automatic alerts: Account → Automations.\n")) $sent['mail']++;
            }
        }
    }
    return $sent;
}

/* ---------- Calendar feed (iCalendar) ---------- */
function ics_escape(string $s): string { return str_replace(["\r\n", "\n"], '\\n', addcslashes($s, ',;\\')); }

function ics_fold(string $line): string
{
    $out = ''; while (strlen($line) > 74) { $cut = 74; while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; $out .= substr($line, 0, $cut) . "\r\n "; $line = substr($line, $cut); }
    return $out . $line;
}

function calendar_feed(string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $u = q('SELECT u.id, u.household_id FROM automation_settings a JOIN users u ON u.id = a.user_id WHERE a.ics_token = ?', [$token])->fetch();
    if (!$u) return null;
    $hid = (int) ($u['household_id'] ?: $u['id']);
    $r = q('SELECT m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$hid])->fetch() ?: ['m_time' => '08:00', 'a_time' => '13:30', 'e_time' => '19:30', 'b_time' => '22:00'];
    $times = ['m' => $r['m_time'], 'a' => $r['a_time'], 'e' => $r['e_time'], 'b' => $r['b_time']];
    $labels = ['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening', 'b' => 'Bedtime'];
    $members = array_column(q('SELECT id, name FROM members WHERE user_id = ?', [$hid])->fetchAll(), 'name', 'id');
    $items = q("SELECT id, name, member_id, dose_times, exp_month, course_end, updated_at FROM cabinet_items WHERE user_id = ? AND dose_times <> ''", [$hid])->fetchAll();
    $site = (string) (config('site_name') ?: 'AushadhBox');
    $host = parse_url((string) config('app_url'), PHP_URL_HOST) ?: 'aushadhbox';
    $today = date('Ymd');
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//' . $site . '//Medicine doses//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
          'X-WR-CALNAME:' . ics_escape($site . ' doses'), 'X-WR-TIMEZONE:Asia/Kolkata', 'REFRESH-INTERVAL;VALUE=DURATION:PT6H', 'X-PUBLISHED-TTL:PT6H',
          'BEGIN:VTIMEZONE', 'TZID:Asia/Kolkata', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0530', 'TZOFFSETTO:+0530', 'TZNAME:IST', 'END:STANDARD', 'END:VTIMEZONE'];
    // One recurring event per dose time: the slot lists everyone's medicines for that time.
    foreach ($times as $slot => $hm) {
        $meds = [];
        $until = null;
        foreach ($items as $i) {
            if (!in_array($slot, explode(',', $i['dose_times']), true)) continue;
            $end = $i['course_end'] ?: date('Y-m-t', strtotime($i['exp_month']));
            if ($end < date('Y-m-d')) continue;
            $meds[] = ($i['member_id'] && isset($members[$i['member_id']]) ? $members[$i['member_id']] . ': ' : '') . $i['name'];
            $until = max($until ?? $end, $end);
        }
        if (!$meds) continue;
        [$h, $m] = explode(':', $hm);
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:' . $hid . '-' . $slot . '@' . $host;
        $L[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $L[] = 'DTSTART;TZID=Asia/Kolkata:' . $today . 'T' . sprintf('%02d%02d00', (int) $h, (int) $m);
        $L[] = 'DURATION:PT15M';
        $L[] = 'RRULE:FREQ=DAILY;UNTIL=' . str_replace('-', '', $until) . 'T235959Z';
        $L[] = ics_fold('SUMMARY:' . ics_escape($labels[$slot] . ' medicines (' . count($meds) . ')'));
        $L[] = ics_fold('DESCRIPTION:' . ics_escape(implode("\n", $meds) . "\n\nTick them in " . $site . ' after taking.'));
        $L[] = 'BEGIN:VALARM'; $L[] = 'ACTION:DISPLAY'; $L[] = ics_fold('DESCRIPTION:' . ics_escape($labels[$slot] . ' medicines')); $L[] = 'TRIGGER:PT0M'; $L[] = 'END:VALARM';
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    return implode("\r\n", $L) . "\r\n";
}
