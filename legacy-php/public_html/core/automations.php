<?php
/* In-app automations. Run by cron.php every 5 minutes; the calendar feed is served by the website router.
     f  Refill alert       stock lasts refill_days or fewer → "Buy more" (every 2 days until restocked)
     m  Missed-dose alert  a dose not ticked missed_after minutes after its time → every account in the family
     c  Course finished    the day after course_end → "Course finished", and its reminders have already stopped
     x  Monthly clean-up   1st of the month → list of expired medicines to dispose of
   Each person switches these on or off in Account → Automations (table automation_settings). */
declare(strict_types=1);

const AUTO_DEFAULTS = ['refill_on' => 1, 'refill_days' => 5, 'missed_on' => 1, 'missed_after' => 60, 'course_on' => 1, 'cleanup_on' => 1, 'timing_on' => 1, 'watch_hours' => 24, 'ics_token' => null];

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
        'timing_on' => array_key_exists('timing_on', $in) ? (!empty($in['timing_on']) ? 1 : 0) : (int) $cur['timing_on'],
        'watch_hours' => array_key_exists('watch_hours', $in) && in_array((int) $in['watch_hours'], [0, 12, 24, 48], true) ? (int) $in['watch_hours'] : (int) ($cur['watch_hours'] ?? 24),
    ];
    q('INSERT INTO automation_settings (user_id, refill_on, refill_days, missed_on, missed_after, course_on, cleanup_on, timing_on, watch_hours) VALUES (?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE refill_on=VALUES(refill_on), refill_days=VALUES(refill_days), missed_on=VALUES(missed_on),
       missed_after=VALUES(missed_after), course_on=VALUES(course_on), cleanup_on=VALUES(cleanup_on), timing_on=VALUES(timing_on), watch_hours=VALUES(watch_hours)',
      [$uid, $v['refill_on'], $v['refill_days'], $v['missed_on'], $v['missed_after'], $v['course_on'], $v['cleanup_on'], $v['timing_on'], $v['watch_hours']]);
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
            // A short course with enough tablets left to finish it needs no refill.
            if ($i['course_end'] && $days >= (int) (new DateTime($today))->diff(new DateTime($i['course_end']))->days + 1) continue;
            $log('f', (int) $i['id'], '');
            $out[] = ['title' => $days <= 0 ? 'Out of ' . $i['name'] : 'Buy more ' . $i['name'],
                      'body' => $who($i['member_id']) . ((int) $i['stock_count']) . ' left, enough for ' . $days . ($days === 1 ? ' day' : ' days') . '. Tap to update the count after you buy.',
                      'tag' => 'refill-' . $i['id'], 'url' => '/app#cabinet', 'kind' => 'refill'];
        }
    }

    // n — "Did you take it?" sent first to the person whose medicine it is (if they have their own login),
    //     halfway to the family alert. Their notification has Taken / Skip buttons.
    if ($s['missed_on']) {
        $ask = max(15, intdiv((int) $s['missed_after'], 2));
        foreach (SLOTS as $slot) {
            $late = $nowMin - hm_minutes($owner_times[$slot] ?? '08:00') - $ask;
            if ($late < 0 || $late > 20 || $logged('n', 0, $slot, $today)) continue;
            $mine = q("SELECT c.id, c.name FROM cabinet_items c JOIN members mb ON mb.id = c.member_id
                       LEFT JOIN dose_logs d ON d.item_id = c.id AND d.slot = ? AND d.taken_on = ?
                       WHERE c.user_id = ? AND mb.account_id = ? AND FIND_IN_SET(?, c.dose_times) AND d.id IS NULL AND (c.course_end IS NULL OR c.course_end >= ?)",
                      [$slot, $today, $hid, $uid, $slot, $today])->fetchAll();
            if (!$mine) continue;
            $log('n', 0, $slot);
            $out[] = ['title' => 'Did you take your ' . SLOT_LABELS[$slot] . ' medicines?',
                      'body' => implode(', ', array_column($mine, 'name')) . '. Tap Taken, or Skip and tell us why. Your family is told if there is no answer.',
                      'tag' => 'ask-' . $slot, 'url' => '/app#doses', 'kind' => 'ask', 'slot' => $slot, 'ask' => true];
        }
    }

    // s — a dose skipped because of side effects: the family hears at once, so someone can call the doctor
    foreach (q("SELECT c.id, c.name, c.member_id, d.slot FROM dose_logs d JOIN cabinet_items c ON c.id = d.item_id
                WHERE d.user_id = ? AND d.taken_on = ? AND d.status = 'skipped' AND d.reason = 'side'", [$hid, $today])->fetchAll() as $i) {
        if ($logged('s', (int) $i['id'], $i['slot'], $today)) continue;
        $log('s', (int) $i['id'], $i['slot']);
        $out[] = ['title' => 'Skipped because of side effects: ' . $i['name'],
                  'body' => $who($i['member_id']) . 'The ' . SLOT_LABELS[$i['slot']] . ' dose was skipped because of side effects. Please talk to the doctor before stopping a medicine.',
                  'tag' => 'side-' . $i['id'], 'url' => '/app#doses', 'kind' => 'side'];
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
                      'body' => implode(', ', array_map(fn($i) => $who($i['member_id']) . $i['name'], $due)) . '. No tick, no skip and no pillbox opening. Please check they were taken.',
                      'tag' => 'missed-' . $slot, 'url' => '/app#doses', 'kind' => 'missed', 'slot' => $slot];
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
    // w — weekly report, Sunday morning: how many doses each person took last week
    if ($isMorning && $now->format('w') === '0' && !$logged('w', 0, '', $today)) {
        $log('w', 0, '');
        $rep = adherence($hid, (clone $now)->modify('-7 day')->format('Y-m-d'), (clone $now)->modify('-1 day')->format('Y-m-d'));
        if ($rep['people']) {
            $lines = array_map(fn($p) => $p['name'] . ' ' . $p['taken'] . '/' . $p['expected'] . ' (' . $p['percent'] . '%)'
                                . ($p['worstSlot'] ? ', misses ' . SLOT_LABELS[$p['worstSlot']] : ''), $rep['people']);
            $out[] = ['title' => 'Last week\'s doses', 'body' => implode('; ', $lines) . '.', 'tag' => 'weekly', 'url' => '/app#doses', 'kind' => 'weekly'];
        }
    }

    // Pillbox: opened twice (d), not heard from for a day or battery low (o), fill it for the week (r)
    foreach (q('SELECT * FROM devices WHERE user_id = ? AND token_hash IS NOT NULL', [$hid])->fetchAll() as $dev) {
        foreach (q("SELECT slot, MAX(happened_at) at FROM device_events WHERE device_id = ? AND outcome = 'again' AND DATE(happened_at) = ? GROUP BY slot", [$dev['id'], $today])->fetchAll() as $e) {
            if ($logged('d', (int) $dev['id'], $e['slot'], $today)) continue;
            $log('d', (int) $dev['id'], $e['slot']);
            $out[] = ['title' => $dev['name'] . ': ' . SLOT_LABELS[$e['slot']] . ' lid opened again',
                      'body' => 'It was opened again at ' . substr($e['at'], 11, 5) . ' after this dose was already taken. Please check a second dose was not taken by mistake.',
                      'tag' => 'again-' . $dev['id'], 'url' => '/app#account', 'kind' => 'pillbox'];
        }
        if ($isMorning && $dev['last_seen'] && strtotime($dev['last_seen']) < $now->getTimestamp() - 86400 && !$logged('o', (int) $dev['id'], '', $today)) {
            $log('o', (int) $dev['id'], '');
            $out[] = ['title' => $dev['name'] . ' is offline', 'body' => 'The pillbox has not connected since ' . date('j M, H:i', strtotime($dev['last_seen'])) . '. Check its power and Wi-Fi. Doses are not detected while it is offline.',
                      'tag' => 'offline-' . $dev['id'], 'url' => '/app#account', 'kind' => 'pillbox'];
        } elseif ($isMorning && $dev['battery'] !== null && (int) $dev['battery'] <= 15 && !$logged('o', (int) $dev['id'], 'b', $today)) {
            $log('o', (int) $dev['id'], 'b');
            $out[] = ['title' => 'Charge ' . $dev['name'], 'body' => 'Battery is at ' . (int) $dev['battery'] . '%.', 'tag' => 'battery-' . $dev['id'], 'url' => '/app#account', 'kind' => 'pillbox'];
        }
        if ($now->format('w') === '0' && (int) $now->format('H') === 18 && (int) $now->format('i') < 20 && !$logged('r', (int) $dev['id'], '', $today)) {
            $log('r', (int) $dev['id'], '');
            $out[] = ['title' => 'Fill ' . $dev['name'] . ' for the week', 'body' => 'Put next week\'s tablets in each lid. Openings while you fill it are not counted as doses.',
                      'tag' => 'fill-' . $dev['id'], 'url' => '/app#doses', 'kind' => 'pillbox'];
        }
    }

    // ---- Ideas from Japanese medication-notebook apps ----
    // q — follow-up check-in after a new medicine (day 3 and day 10), asked with the morning reminder
    if ($isMorning && function_exists('followups_due')) foreach (followups_due($hid) as $f) {
        if ($f['due'] !== $today || $logged('q', $f['id'], '', $today)) continue;
        $acct = $f['memberId'] ? q('SELECT account_id FROM members WHERE id = ?', [$f['memberId']])->fetchColumn() : null;
        if ($acct && (int) $acct !== $uid) continue;   // the person answers on their own phone
        $log('q', $f['id'], '');
        $out[] = ['title' => 'How is it going with ' . $f['name'] . '?', 'body' => $who($f['memberId']) . 'Tap to answer: better, same, worse or a side effect. It goes into the visit sheet for the doctor.',
                  'tag' => 'fu-' . $f['id'], 'url' => '/app#doses', 'kind' => 'followup'];
    }
    // y — a follow-up answered "worse" or "side effect": the rest of the family hears
    foreach (q("SELECT f.id, f.answer, f.note, c.name, c.member_id FROM followups f JOIN cabinet_items c ON c.id = f.item_id WHERE f.user_id = ? AND f.answer IN ('worse','side') AND f.answered_at >= NOW() - INTERVAL 1 DAY", [$hid])->fetchAll() as $f) {
        if ($logged('y', (int) $f['id'], '', date('Y-m-d', strtotime('-1 day')))) continue;
        $log('y', (int) $f['id'], '');
        $out[] = ['title' => ($f['answer'] === 'side' ? 'Side effect reported: ' : 'Feeling worse on ') . $f['name'], 'body' => $who($f['member_id']) . ($f['note'] ? '"' . mb_substr($f['note'], 0, 120) . '" ' : '') . 'Please talk to the doctor or pharmacist; do not stop a medicine without asking.',
                  'tag' => 'fu-bad-' . $f['id'], 'url' => '/app#notebook', 'kind' => 'followup'];
    }
    // h — a very high or very low health reading in the last hour
    foreach (q("SELECT id, member_id, kind, v1, v2 FROM vitals WHERE user_id = ? AND flag = 'urgent' AND taken_at >= NOW() - INTERVAL 1 HOUR", [$hid])->fetchAll() as $v) {
        if ($logged('h', (int) $v['id'], '', $today)) continue;
        $log('h', (int) $v['id'], '');
        $lab = ['bp' => 'Blood pressure', 'sugar' => 'Blood sugar', 'temp' => 'Temperature', 'spo2' => 'Oxygen', 'pulse' => 'Pulse', 'weight' => 'Weight'][$v['kind']];
        $out[] = ['title' => $lab . ' needs attention', 'body' => $who($v['member_id']) . rtrim(rtrim((string) $v['v1'], '0'), '.') . ($v['v2'] !== null ? '/' . rtrim(rtrim((string) $v['v2'], '0'), '.') : '') .
                  '. Call the doctor now. If they feel very unwell, call 112.', 'tag' => 'vital-' . $v['id'], 'url' => '/app#notebook', 'kind' => 'vital'];
    }
    // p — doctor visit: the evening before (7 pm) and 2 hours before
    foreach (q('SELECT id, member_id, doctor, place, at FROM appointments WHERE user_id = ? AND at BETWEEN NOW() - INTERVAL 1 HOUR AND NOW() + INTERVAL 2 DAY', [$hid])->fetchAll() as $ap) {
        $mins = (int) round((strtotime($ap['at']) - $now->getTimestamp()) / 60);
        $slot = null;
        if ((int) $now->format('H') === 19 && (int) $now->format('i') < 20 && substr($ap['at'], 0, 10) === date('Y-m-d', strtotime("$today +1 day"))) $slot = 'd';
        elseif ($mins <= 120 && $mins > 95) $slot = 'h';
        if (!$slot || $logged('p', (int) $ap['id'], $slot, $today)) continue;
        $log('p', (int) $ap['id'], $slot);
        $out[] = ['title' => ($slot === 'd' ? 'Doctor visit tomorrow ' : 'Doctor visit at ') . substr($ap['at'], 11, 5) . ': ' . $ap['doctor'],
                  'body' => $who($ap['member_id']) . ($ap['place'] ? $ap['place'] . '. ' : '') . 'The visit sheet (medicines, tablets left, readings, side effects) is ready in the Notebook. Take the medicine strips along.',
                  'tag' => 'appt-' . $ap['id'], 'url' => '/app#notebook', 'kind' => 'appointment'];
    }
    // i — vaccination due today or in 3 days (made automatically from a child's date of birth)
    if ($isMorning) foreach (q("SELECT v.id, v.name, v.due_on, m.name who FROM vaccinations v JOIN members m ON m.id = v.member_id WHERE v.user_id = ? AND v.given_on IS NULL AND v.due_on IN (?, ? + INTERVAL 3 DAY)", [$hid, $today, $today])->fetchAll() as $vx) {
        $slot = $vx['due_on'] === $today ? 't' : 'u';
        if ($logged('i', (int) $vx['id'], $slot, date('Y-m-d', strtotime("$today -30 day")))) continue;
        $log('i', (int) $vx['id'], $slot);
        $out[] = ['title' => 'Vaccination ' . ($slot === 't' ? 'due today' : 'in 3 days') . ': ' . $vx['name'], 'body' => $vx['who'] . ' · ' . date('D j M', strtotime($vx['due_on'])) . '. Take the vaccination card to the health centre or your doctor, then mark it as given in the Notebook.',
                  'tag' => 'vax-' . $vx['id'], 'url' => '/app#notebook', 'kind' => 'vaccine'];
    }

    // v — watch-over: someone with their own phone or a pillbox has shown no sign of life for a while
    $watch = (int) ($s['watch_hours'] ?? 24);
    if ($watch > 0 && function_exists('last_sign_of_life')) {
        foreach (q("SELECT m.id, m.name, m.account_id, m.created_at FROM members m WHERE m.user_id = ? AND (m.account_id IS NULL OR m.account_id <> ?) AND (m.account_id IS NOT NULL OR EXISTS (SELECT 1 FROM devices v WHERE v.member_id = m.id AND v.token_hash IS NOT NULL))", [$hid, $uid])->fetchAll() as $mb) {
            if (!q("SELECT 1 FROM cabinet_items WHERE user_id = ? AND member_id = ? AND dose_times <> '' AND (course_end IS NULL OR course_end >= ?)", [$hid, $mb['id'], $today])->fetch()) continue;
            $last = last_sign_of_life($hid, $mb);
            $since = $last ?? $mb['created_at'];
            if (strtotime($since) > $now->getTimestamp() - $watch * 3600 || $logged('v', (int) $mb['id'], '', $today)) continue;
            $log('v', (int) $mb['id'], '');
            $out[] = ['title' => 'No sign from ' . $mb['name'] . ' since ' . date('D j M, g:i a', strtotime($since)), 'body' => 'No doses ticked, no pillbox opening and the app not opened for over ' . $watch . ' hours. Please give them a call.',
                      'tag' => 'watch-' . $mb['id'], 'url' => '/app#doses', 'kind' => 'watch'];
        }
    }

    // t — reminder times learn the family's routine (checked once a day, in the evening window)
    if ((int) ($s['timing_on'] ?? 1) && (int) $now->format('H') === 21 && (int) $now->format('i') < 20 && !$logged('t', 0, '', $today)) {
        $log('t', 0, '');
        $own = q('SELECT m_time, a_time, e_time, b_time FROM reminder_settings WHERE user_id = ?', [$uid])->fetch();
        $labels = ['m' => 'morning', 'a' => 'afternoon', 'e' => 'evening', 'b' => 'bedtime'];
        $weekAgo = (clone $now)->modify('-7 day')->format('Y-m-d');
        foreach ($own ? SLOTS : [] as $slot) {
            $mins = array_map(fn($r) => (int) $r['h'] * 60 + (int) $r['m'], q('SELECT HOUR(MIN(taken_at)) h, MINUTE(MIN(taken_at)) m FROM dose_logs
                     WHERE user_id = ? AND slot = ? AND taken_on >= ? AND taken_on < ? AND status = \'taken\' GROUP BY taken_on', [$hid, $slot, $weekAgo, $today])->fetchAll());
            if (count($mins) < 4) continue;
            sort($mins);
            $median = $mins[intdiv(count($mins), 2)];
            [$h, $m] = array_map('intval', explode(':', $own[$slot . '_time']));
            $cur = $h * 60 + $m;
            $target = (int) (round($median / 15) * 15);
            // Only follow a steady habit: at least 30 minutes away, and the ticks agree with each other within an hour.
            if (abs($target - $cur) < 30 || ($mins[count($mins) - 1] - $mins[0]) > 90 || $target < 5 * 60 || $target > 23 * 60 + 30) continue;
            $new = sprintf('%02d:%02d', intdiv($target, 60), $target % 60);
            q("UPDATE reminder_settings SET {$slot}_time = ? WHERE user_id = ?", [$new, $uid]);
            $out[] = ['title' => 'Reminder time adjusted', 'body' => 'Your ' . $labels[$slot] . ' reminder now rings at ' . $new . ', the time you usually take these medicines. Change it any time under Doses.',
                      'tag' => 'timing-' . $slot, 'url' => '/app#doses', 'kind' => 'timing'];
        }
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
            } elseif ($msg['kind'] !== 'missed' && mail_enabled() && !q('SELECT 1 FROM system_state WHERE k = ?', ['noalertmail:' . $u['id']])->fetch()) {   // missed-dose alerts: push only; e-mail stops after unsubscribe
                $base = rtrim((string) config('app_url'), '/');
                if (send_mail($u['email'], $msg['title'], "Hello {$u['name']},\n\n{$msg['body']}\n\nOpen AushadhBox: $base{$msg['url']}\nChange automatic alerts: Account → Automations.\n", [(int) $u['id'], 'alerts'])) $sent['mail']++;
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
